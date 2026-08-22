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
     * @return string|null Path relative to the uploads dir, or null on failure.
     */
    public function storeUpload(array $file): ?string
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
     * @return string|null Path relative to the uploads dir, or null on failure.
     */
    public function storeLocalFile(string $path): ?string
    {
        if (!is_file($path) || (int) filesize($path) > self::MAX_BYTES) {
            return null;
        }
        return $this->ingest($path);
    }

    /**
     * Safely download a remote image and store it.
     *
     * @return string|null Path relative to the uploads dir, or null on failure.
     */
    public function storeFromUrl(string $url): ?string
    {
        $pinned = SafeHttp::resolveTarget($url);
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
    private function ingest(string $sourcePath): ?string
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

        // Relative to the uploads dir, and only that. The store must survive
        // domain changes and project-folder moves; absolute values rot the
        // moment either happens. A URL used to come back alongside it, but a
        // URL is publicUrl() of this path — the one caller that needs one asks
        // for it, rather than three others carrying a value they discard.
        return ltrim(substr($path, strlen($this->uploadDir)), '/');
    }

    /**
     * The public URL for a stored path.
     *
     * A record's `url` is this function of its `path`, and always was — the
     * two were written together and could disagree afterwards. The migration
     * that made them relative rewrote each with its own condition, so an
     * install with FN_UPLOAD_DIR outside public/uploads got a relativized URL
     * and an untouched absolute path. A basePath change rots every stored URL
     * while leaving every path correct.
     *
     * Deriving it at render time is how the codebase already treats the domain
     * one level up, in fn_render_head.
     */
    public function publicUrl(string $storedPath): string
    {
        $storedPath = (string) $storedPath;
        if ($storedPath === '') {
            return '';
        }
        // A pre-migration record holds an absolute disk path. Only one under
        // the uploads dir can be expressed as a URL at all.
        if (str_starts_with($storedPath, '/')) {
            $inside = $this->relativeToUploads($storedPath);
            if ($inside === null) {
                return '';
            }
            $storedPath = $inside;
        }
        return $this->publicBase . '/' . $storedPath;
    }

    /**
     * The disk path for a stored path, or null when it names nothing this
     * install owns. Three call sites used to re-decide the relative/absolute
     * question and reached three different answers — the export skipped an
     * absolute path while deletion and pruning happily resolved it, so a
     * legacy record's image was silently dropped from an export zip.
     */
    public function absolutePath(string $storedPath): ?string
    {
        $storedPath = (string) $storedPath;
        if ($storedPath === '') {
            return null;
        }
        if (!str_starts_with($storedPath, '/')) {
            return $this->uploadDir . '/' . $storedPath;
        }
        return $this->relativeToUploads($storedPath) === null
            ? null
            : $storedPath;
    }

    /** Strip the uploads prefix, or null when the path sits outside it. */
    private function relativeToUploads(string $absolute): ?string
    {
        $prefix = rtrim($this->uploadDir, '/') . '/';
        return str_starts_with($absolute, $prefix)
            ? substr($absolute, strlen($prefix))
            : null;
    }

    // Target validation lives in Fieldnote\SafeHttp, shared with the
    // ActivityPub fetcher so both SSRF surfaces use one implementation.
}
