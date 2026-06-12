<?php

namespace Fieldnote;

/**
 * Handles featured images for posts: validated file uploads and SSRF-safe
 * remote downloads.
 *
 * Replaces the bundled, abandoned ImageCache library. The original passed a
 * user-supplied URL straight to file_get_contents() with no validation, which
 * allowed Server-Side Request Forgery (fetching cloud metadata, internal
 * hosts, or file:// paths). This version:
 *   - accepts only http/https URLs,
 *   - resolves the host and rejects private, loopback, and reserved IPs,
 *   - downloads with cURL, redirects disabled, with size and type caps,
 *   - re-encodes every image through GD, which strips embedded payloads
 *     (polyglots) and enforces that the bytes really are an image.
 */
final class ImageHandler
{
    public const MAX_BYTES = 10 * 1024 * 1024; // 10 MB app-level cap
    private const ALLOWED   = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'gif' => 'gif'];

    private string $uploadDir;
    private string $publicBase;

    /**
     * @param string $uploadDir  Absolute filesystem path to the uploads dir.
     * @param string $publicBase Site-relative URL prefix that maps to
     *                           $uploadDir (for example "/uploads" or
     *                           "/blog/uploads"). Kept relative on purpose:
     *                           absolute stored URLs broke every existing
     *                           image whenever the domain changed.
     */
    public function __construct(string $uploadDir, string $publicBase)
    {
        $this->uploadDir  = rtrim($uploadDir, '/');
        $this->publicBase = rtrim($publicBase, '/');
    }

    /**
     * Validate and store an uploaded file.
     *
     * @param array{name:string,tmp_name:string,size:int,error:int} $file
     * @return array{0:string,1:string}|null [siteRelativeUrl, uploadRelativePath] or null on failure
     */
    public function storeUpload(array $file): ?array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        if (!is_uploaded_file($file['tmp_name']) || $file['size'] > self::MAX_BYTES) {
            return null;
        }
        return $this->ingest($file['tmp_name']);
    }

    /**
     * Store an image from a local file (the import path). Same pipeline as
     * uploads: everything is re-encoded through GD, so a zip can't smuggle
     * polyglot bytes into uploads/.
     *
     * @return array{0:string,1:string}|null [siteRelativeUrl, uploadRelativePath] or null on failure
     */
    public function storeLocalFile(string $path): ?array
    {
        if (!is_file($path) || (int) filesize($path) > self::MAX_BYTES) {
            return null;
        }
        return $this->ingest($path);
    }

    /**
     * Safely download a remote image and store it.
     *
     * @return array{0:string,1:string}|null [siteRelativeUrl, uploadRelativePath] or null on failure
     */
    public function storeFromUrl(string $url): ?array
    {
        $pinned = $this->safeResolvedTarget($url);
        if ($pinned === null) {
            return null;
        }
        [$host, $port, $ip] = $pinned;

        $ch = curl_init($url);
        $tmp = tempnam(sys_get_temp_dir(), 'dpl');
        $fh  = fopen($tmp, 'wb');
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => false,   // no redirects: stops redirect-to-internal SSRF
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            // Pin the connection to the IP we just validated, so a DNS record
            // that changes between our check and curl's own lookup (DNS
            // rebinding) cannot redirect the request to an internal host.
            CURLOPT_RESOLVE        => ["{$host}:{$port}:{$ip}"],
            // MAXFILESIZE only honors Content-Length; the progress callback
            // enforces the cap even on chunked/streaming responses.
            CURLOPT_MAXFILESIZE    => self::MAX_BYTES,
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($c, $dlTotal, $dlNow) => ($dlNow > self::MAX_BYTES) ? 1 : 0,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Fieldnote/3.0 image fetcher',
        ]);
        $ok   = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);

        if ($ok === false || $code < 200 || $code >= 300 || filesize($tmp) > self::MAX_BYTES) {
            @unlink($tmp);
            return null;
        }

        $result = $this->ingest($tmp);
        @unlink($tmp);
        return $result;
    }

    /**
     * Re-encode the image at $sourcePath through GD and write it to the
     * organized uploads tree. Returns null if the bytes are not a valid
     * JPEG, PNG, or GIF.
     *
     * @return array{0:string,1:string}|null
     */
    private function ingest(string $sourcePath): ?array
    {
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return null;
        }

        [$ext, $image] = match ($info[2]) {
            IMAGETYPE_JPEG => ['jpg', @imagecreatefromjpeg($sourcePath)],
            IMAGETYPE_PNG  => ['png', @imagecreatefrompng($sourcePath)],
            IMAGETYPE_GIF  => ['gif', @imagecreatefromgif($sourcePath)],
            default        => [null, false],
        };
        if ($ext === null || $image === false) {
            return null;
        }

        $yearDir  = $this->uploadDir . '/' . date('Y');
        $monthDir = $yearDir . '/' . date('m');
        foreach ([$this->uploadDir, $yearDir, $monthDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                imagedestroy($image);
                return null;
            }
        }

        // Random, collision-proof, filesystem-safe name (the original used
        // a "Y-m-d-H:i:s" name that could collide and contained colons).
        $name = date('Y-m-d') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $path = $monthDir . '/' . $name;

        $written = match ($ext) {
            'jpg' => imagejpeg($image, $path, 85),
            'png' => imagepng($image, $path, 6),
            'gif' => imagegif($image, $path),
        };
        imagedestroy($image);

        if (!$written) {
            return null;
        }
        @chmod($path, 0644);

        // Return both halves relative: URL relative to the site root, path
        // relative to the uploads dir. The store must survive domain changes
        // and project-folder moves; absolute values rot the moment either
        // happens (callers re-anchor the path against FN_UPLOAD_DIR).
        $relative = substr($path, strlen($this->uploadDir));
        return [$this->publicBase . $relative, ltrim($relative, '/')];
    }

    /**
     * Validate the URL (http/https, host resolves only to public addresses)
     * and return [host, port, ip] so the caller can pin curl to the exact
     * address that passed validation.
     *
     * @return array{0:string,1:int,2:string}|null
     */
    private function safeResolvedTarget(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = $parts['host'];
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        // Resolve to IPs. A literal IP host is checked directly.
        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_merge(
                gethostbynamel($host) ?: [],
                $this->resolveAaaa($host)
            );

        if (empty($ips)) {
            return null;
        }

        foreach ($ips as $ip) {
            if (!filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )) {
                return null; // any private/reserved address fails the whole URL
            }
        }
        return [$host, $port, $ips[0]];
    }

    /** @return string[] */
    private function resolveAaaa(string $host): array
    {
        $records = @dns_get_record($host, DNS_AAAA) ?: [];
        return array_column($records, 'ipv6');
    }
}
