<?php

namespace Fieldnote;

/**
 * Passkey (WebAuthn) credential storage. See docs/passkeys-spec.md.
 *
 * Mirrors TwoFactor's shape and lifecycle: JSON in data/passkeys.json,
 * outside the web root, rewritten on every login (the sign-count is replay
 * protection), and deleting the file over SSH disables passkey login — the
 * lost-device escape hatch. Password (+ TOTP) login is always a fallback.
 */
final class Passkeys
{
    private string $file;

    public function __construct(string $dataDir)
    {
        $this->file = rtrim($dataDir, '/') . '/passkeys.json';
    }

    public function enabled(): bool
    {
        return $this->list() !== [];
    }

    /**
     * Credentials keyed by id, which is what the id actually is: the primary
     * key. Three methods used to walk the list looking for it, each re-reading
     * and re-parsing the file, and each with its own idea of what "the
     * credential with this id" meant when two shared one — find() took the
     * first, updateSignCount() updated the first, remove() deleted both. add()
     * appended unconditionally, so reaching that state took nothing more than
     * registering the same authenticator twice.
     *
     * @return array<string, array{id:string,publicKey:string,signCount:int,label:string,createdAt:int}>
     */
    private function all(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $data  = json_decode((string) file_get_contents($this->file), true);
        $keyed = [];
        foreach ((array) ($data['credentials'] ?? []) as $credential) {
            // Tolerate a hand-edited file the way the old scan did: a record
            // with no usable id is skipped rather than fatal.
            if (is_array($credential) && is_string($credential['id'] ?? null) && $credential['id'] !== '') {
                $keyed[$credential['id']] = $credential;
            }
        }
        return $keyed;
    }

    /** @return list<array{id:string,publicKey:string,signCount:int,label:string,createdAt:int}> */
    public function list(): array
    {
        // Insertion order preserved, so the settings page renders unchanged.
        return array_values($this->all());
    }

    /** @return array{id:string,publicKey:string,signCount:int,label:string,createdAt:int}|null */
    public function find(string $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    public function add(string $id, string $publicKeyPem, int $signCount, string $label): bool
    {
        $credentials      = $this->all();
        $credentials[$id] = [
            'id'        => $id,
            'publicKey' => $publicKeyPem,
            'signCount' => $signCount,
            'label'     => $label !== '' ? $label : 'Passkey',
            'createdAt' => time(),
        ];
        return $this->save(array_values($credentials));
    }

    public function remove(string $id): bool
    {
        $credentials = $this->all();
        unset($credentials[$id]);
        if ($credentials === []) {
            return !is_file($this->file) || unlink($this->file);
        }
        return $this->save(array_values($credentials));
    }

    public function updateSignCount(string $id, int $count): bool
    {
        $credentials = $this->all();
        if (!isset($credentials[$id])) {
            return false;
        }
        $credentials[$id]['signCount'] = $count;
        return $this->save(array_values($credentials));
    }

    public static function b64uEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $encoded): string
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/'), false);
    }

    /** @param list<array<string,mixed>> $credentials */
    private function save(array $credentials): bool
    {
        $data = [
            '_comment' => 'Fieldnote passkeys. Delete this file to disable '
                . 'passkey login (lost-device recovery); password login is unaffected.',
            'credentials' => $credentials,
        ];
        $tmp = $this->file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0640);
        return rename($tmp, $this->file);
    }
}
