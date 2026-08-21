<?php

namespace Fieldnote;

/**
 * ActivityPub plumbing (docs/activitypub-spec.md, phase AP-1): the actor
 * keypair, the follower list, draft-cavage HTTP signatures in both
 * directions, and a TTL'd cache of remote actor documents.
 *
 * State lives under data/activitypub/ with the same lifecycle as the other
 * secrets: outside the web root, deletable over SSH.
 */
final class Federation
{
    private const ACTOR_CACHE_TTL = 86400;
    /** Clock skew tolerance for inbound Date headers (Mastodon-compatible). */
    private const DATE_WINDOW = 43200;

    private string $dir;

    public function __construct(private string $dataDir, private string $actorUrl)
    {
        $this->dir = rtrim($dataDir, '/') . '/activitypub';
    }

    /**
     * The actor's key id. The '#main-key' fragment was written out at the
     * signing site here and in the actor document, and undone by a strtok in
     * the verifier — three copies of one format decision across two files.
     */
    public function keyId(): string
    {
        return $this->actorUrl . '#main-key';
    }

    /**
     * The actor a keyId belongs to. The inverse of keyId(), kept beside it so
     * the two cannot drift. A keyId with no fragment is its own owner.
     */
    public static function ownerOfKeyId(string $keyId): string
    {
        $hash = strpos($keyId, '#');
        return $hash === false ? $keyId : substr($keyId, 0, $hash);
    }

    // ---------------------------------------------------------------- keys --

    /** @return array{private:string,public:string} generated on first use */
    public function keys(): array
    {
        $file = $this->dir . '/keys.json';
        if (is_file($file)) {
            $keys = json_decode((string) file_get_contents($file), true);
            if (is_array($keys) && !empty($keys['private']) && !empty($keys['public'])) {
                return $keys;
            }
        }
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $private);
        $public = (string) (openssl_pkey_get_details($resource)['key'] ?? '');
        $keys   = ['private' => (string) $private, 'public' => $public];
        $this->atomicWrite($file, json_encode($keys, JSON_PRETTY_PRINT));
        return $keys;
    }

    // ----------------------------------------------------------- followers --

    /** @return list<array{id:string,inbox:string,sharedInbox:string,addedAt:int}> */
    public function followers(): array
    {
        $file = $this->dir . '/followers.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return array_values((array) ($data['followers'] ?? []));
    }

    public function addFollower(string $id, string $inbox, string $sharedInbox): bool
    {
        $followers = array_values(array_filter(
            $this->followers(),
            static fn (array $f): bool => $f['id'] !== $id
        ));
        $followers[] = ['id' => $id, 'inbox' => $inbox, 'sharedInbox' => $sharedInbox, 'addedAt' => time()];
        return $this->saveFollowers($followers);
    }

    public function removeFollower(string $id): bool
    {
        return $this->saveFollowers(array_values(array_filter(
            $this->followers(),
            static fn (array $f): bool => $f['id'] !== $id
        )));
    }

    // ----------------------------------------------------------- signatures --

    /**
     * Curl headers for an outbound signed request: (request-target) host
     * date, plus digest and content-type when a body is present.
     *
     * @return string[]
     */
    public function signedHeaders(string $method, string $url, ?string $body): array
    {
        $parts = parse_url($url);
        $path  = (string) ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $host  = (string) ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $date  = gmdate('D, d M Y H:i:s \G\M\T');

        $signed  = '(request-target) host date';
        $signing = '(request-target): ' . strtolower($method) . ' ' . $path . "\nhost: " . $host . "\ndate: " . $date;
        $headers = ['Host: ' . $host, 'Date: ' . $date, 'Accept: application/activity+json'];

        if ($body !== null) {
            $digest   = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
            $signed  .= ' digest';
            $signing .= "\ndigest: " . $digest;
            $headers[] = 'Digest: ' . $digest;
            $headers[] = 'Content-Type: application/activity+json';
        }

        openssl_sign($signing, $signature, $this->keys()['private'], OPENSSL_ALGO_SHA256);
        $headers[] = 'Signature: keyId="' . $this->keyId() . '",algorithm="rsa-sha256"'
            . ',headers="' . $signed . '",signature="' . base64_encode($signature) . '"';
        return $headers;
    }

    /**
     * Verify the current request (superglobals) as a signed inbox delivery.
     * Returns the decoded activity, or null — callers answer 401 on null
     * with no detail about which check failed.
     *
     * @return array<string,mixed>|null
     */
    public function verifyInbox(string $body): ?array
    {
        $activity = json_decode($body, true);
        if (!is_array($activity) || !is_string($activity['actor'] ?? null)) {
            return null;
        }

        // Parse Signature header parameters.
        preg_match_all('/(\w+)="([^"]*)"/', (string) ($_SERVER['HTTP_SIGNATURE'] ?? ''), $m, PREG_SET_ORDER);
        $params = [];
        foreach ($m as $pair) {
            $params[strtolower($pair[1])] = $pair[2];
        }
        if (empty($params['keyid']) || empty($params['headers']) || empty($params['signature'])) {
            return null;
        }

        // The body must be the thing that was signed.
        $expectedDigest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
        if (!hash_equals($expectedDigest, (string) ($_SERVER['HTTP_DIGEST'] ?? ''))) {
            return null;
        }

        // Freshness window against replays.
        $date = strtotime((string) ($_SERVER['HTTP_DATE'] ?? ''));
        if ($date === false || abs(time() - $date) > self::DATE_WINDOW) {
            return null;
        }

        // Rebuild the signing string from the declared header list.
        $signedHeaders = explode(' ', strtolower($params['headers']));
        foreach (['(request-target)', 'date', 'digest'] as $required) {
            if (!in_array($required, $signedHeaders, true)) {
                return null;
            }
        }
        $lines = [];
        foreach ($signedHeaders as $name) {
            $value = match ($name) {
                '(request-target)' => 'post ' . (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
                'host'             => (string) ($_SERVER['HTTP_HOST'] ?? ''),
                'date'             => (string) ($_SERVER['HTTP_DATE'] ?? ''),
                'digest'           => (string) ($_SERVER['HTTP_DIGEST'] ?? ''),
                'content-type'     => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
                default            => (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? ''),
            };
            if ($value === '') {
                return null;
            }
            $lines[] = $name . ': ' . $value;
        }
        $signingString = implode("\n", $lines);
        $signature     = base64_decode($params['signature'], true);
        if ($signature === false) {
            return null;
        }

        // Key confusion guard: the key must belong to the actor the
        // activity claims to be from.
        $keyOwner = self::ownerOfKeyId((string) $params['keyid']);
        if ($keyOwner !== $activity['actor']) {
            return null;
        }

        // Verify against the cached actor key; one fresh refetch on failure
        // covers key rotation.
        // Try the cached key, then at most one fresh fetch — which is what
        // "one refetch covers key rotation" was always meant to mean. The old
        // [false, true] loop asked fetchActor twice without ever learning
        // whether the first answer came from cache, so a cold cache produced
        // two identical network requests milliseconds apart, each up to the
        // SafeHttp timeout, against a host named by the request body.
        $cached = $this->cachedActor($keyOwner);
        if ($cached !== null && self::verifiedBy($cached, $signingString, $signature)) {
            return $activity;
        }
        $fresh = $this->fetchActor($keyOwner, true);
        if ($fresh !== null && self::verifiedBy($fresh, $signingString, $signature)) {
            return $activity;
        }
        return null;
    }

    // -------------------------------------------------------- remote actors --

    /**
     * Fetch (and cache) a remote actor document via SafeHttp, with a signed
     * GET so authorized-fetch instances answer.
     *
     * @return array<string,mixed>|null
     */
    /** A live cached actor document, or null when there is no usable one. */
    private function cachedActor(string $id): ?array
    {
        $cacheFile = $this->dir . '/actors/' . sha1($id) . '.json';
        if (!is_file($cacheFile) || time() - (int) filemtime($cacheFile) >= self::ACTOR_CACHE_TTL) {
            return null;
        }
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        return is_array($cached) ? $cached : null;
    }

    /** Does this actor document's key verify the signature? */
    private static function verifiedBy(array $actor, string $signingString, string $signature): bool
    {
        $pem = (string) ($actor['publicKey']['publicKeyPem'] ?? '');
        return $pem !== '' && openssl_verify($signingString, $signature, $pem, OPENSSL_ALGO_SHA256) === 1;
    }
    public function fetchActor(string $id, bool $fresh = false): ?array
    {
        $cacheFile = $this->dir . '/actors/' . sha1($id) . '.json';
        if (!$fresh) {
            $cached = $this->cachedActor($id);
            if ($cached !== null) {
                return $cached;
            }
        }
        $response = SafeHttp::get($id, $this->signedHeaders('get', $id, null), 8, 262144);
        if ($response === null || $response[0] < 200 || $response[0] >= 300) {
            return null;
        }
        $actor = json_decode($response[1], true);
        if (!is_array($actor)) {
            return null;
        }
        $this->atomicWrite($cacheFile, json_encode($actor));
        return $actor;
    }

    /** Synchronous Accept back to a new follower's inbox (best effort). */
    public function deliverAccept(array $followActivity, string $inbox): void
    {
        $accept = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $this->actorUrl . '#accept-' . bin2hex(random_bytes(8)),
            'type'     => 'Accept',
            'actor'    => $this->actorUrl,
            'object'   => $followActivity,
        ];
        $body = (string) json_encode($accept, JSON_UNESCAPED_SLASHES);
        SafeHttp::post($inbox, $body, $this->signedHeaders('post', $inbox, $body));
    }

    // ---------------------------------------------------------------- misc --

    /** @param list<array<string,mixed>> $followers */
    private function saveFollowers(array $followers): bool
    {
        return $this->atomicWrite(
            $this->dir . '/followers.json',
            json_encode(['followers' => $followers], JSON_PRETTY_PRINT)
        );
    }

    private function atomicWrite(string $file, string $content): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !(mkdir($dir, 0750, true) || is_dir($dir))) {
            return false;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0640);
        return rename($tmp, $file);
    }
}
