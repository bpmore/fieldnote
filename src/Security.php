<?php

namespace Fieldnote;

/**
 * Centralizes session hardening, CSRF protection, and output escaping.
 *
 * Replaces the original code's bare session_start() (called after routing,
 * with default cookie params) and its complete absence of CSRF tokens.
 */
final class Security
{
    /** Failed logins allowed per window before lockout. */
    private const LOGIN_MAX_FAILURES = 5;
    /** Window and lockout length, seconds. */
    private const LOGIN_WINDOW = 900;

    /**
     * Baseline security headers for every PHP response. The .htaccess copy
     * only helps on Apache; nginx (Herd) and the PHP built-in server need
     * these sent from code.
     */
    public static function sendBaseHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }

    /**
     * Strict CSP for internal (admin) pages, where all markup and scripts are
     * ours and self-hosted. Public template pages do NOT get a CSP because
     * headerInject may legitimately carry inline analytics snippets.
     */
    public static function sendAdminCsp(): void
    {
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "script-src 'self'; style-src 'self'; "
            . "img-src * data:; font-src 'self' data:; "
            . "connect-src 'self'; form-action 'self'; "
            . "frame-ancestors 'self'; base-uri 'self'"
        );
    }

    /**
     * Strict CSP for public pages. Possible because public pages carry no
     * JavaScript and no inline styles (the a11y baseline and palette
     * overrides are linked stylesheets). Sent only while headerInject is
     * empty — an injected analytics snippet would be the thing it blocks.
     */
    public static function sendPublicCsp(): void
    {
        header(
            "Content-Security-Policy: default-src 'self'; script-src 'none'; "
            . "style-src 'self'; img-src * data:; font-src 'self'; "
            . "form-action 'self'; frame-ancestors 'self'; base-uri 'self'"
        );
    }

    /**
     * Seconds until the calling IP may attempt another login, or 0 if it may
     * try now. File-based so it works without a database and survives the
     * attacker discarding cookies.
     */
    public static function loginLockedFor(string $dataDir): int
    {
        $entry = self::throttleEntry($dataDir);
        if ($entry === null) {
            return 0;
        }
        if ($entry['count'] >= self::LOGIN_MAX_FAILURES) {
            $remaining = ($entry['first'] + self::LOGIN_WINDOW) - time();
            return max(0, $remaining);
        }
        return 0;
    }

    public static function recordLoginFailure(string $dataDir): void
    {
        $all = self::throttleRead($dataDir);
        $key = self::throttleKey();
        $now = time();
        $entry = $all[$key] ?? ['count' => 0, 'first' => $now];
        if ($now - $entry['first'] > self::LOGIN_WINDOW) {
            $entry = ['count' => 0, 'first' => $now];
        }
        $entry['count']++;
        $all[$key] = $entry;
        // Prune stale entries so the file cannot grow without bound.
        foreach ($all as $k => $e) {
            if ($now - ($e['first'] ?? 0) > self::LOGIN_WINDOW) {
                unset($all[$k]);
            }
        }
        self::throttleWrite($dataDir, $all);
    }

    public static function clearLoginFailures(string $dataDir): void
    {
        $all = self::throttleRead($dataDir);
        unset($all[self::throttleKey()]);
        self::throttleWrite($dataDir, $all);
    }

    /** @return array{count:int,first:int}|null */
    private static function throttleEntry(string $dataDir): ?array
    {
        $all = self::throttleRead($dataDir);
        $entry = $all[self::throttleKey()] ?? null;
        if ($entry === null || time() - ($entry['first'] ?? 0) > self::LOGIN_WINDOW) {
            return null;
        }
        return $entry;
    }

    /** @return array<string,array{count:int,first:int}> */
    private static function throttleRead(string $dataDir): array
    {
        $file = rtrim($dataDir, '/') . '/login_throttle.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string,array{count:int,first:int}> $all */
    private static function throttleWrite(string $dataDir, array $all): void
    {
        $file = rtrim($dataDir, '/') . '/login_throttle.json';
        @file_put_contents($file, json_encode($all), LOCK_EX);
        @chmod($file, 0640);
    }

    /** Trusted proxy CIDRs/IPs, set from config at bootstrap. */
    private static array $trustedProxies = [];

    /** @param string[] $cidrs */
    public static function setTrustedProxies(array $cidrs): void
    {
        self::$trustedProxies = $cidrs;
    }

    /** Hash the client IP so raw addresses are never stored on disk. */
    private static function throttleKey(): string
    {
        return hash('sha256', self::clientIp());
    }

    /**
     * The address the throttle should key on. Without trusted proxies this
     * is REMOTE_ADDR, full stop — X-Forwarded-For is attacker-controlled.
     * When REMOTE_ADDR is a configured proxy, walk X-Forwarded-For from the
     * right past trusted hops; the first untrusted address is the client.
     * (Otherwise everyone behind Cloudflare shares one throttle bucket:
     * five failed logins by anyone lock out the real admin.)
     */
    private static function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (self::$trustedProxies === [] || !self::ipMatchesAny($remote, self::$trustedProxies)) {
            return $remote;
        }
        $hops = array_reverse(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($hops as $hop) {
            if ($hop !== '' && filter_var($hop, FILTER_VALIDATE_IP) && !self::ipMatchesAny($hop, self::$trustedProxies)) {
                return $hop;
            }
        }
        return $remote;
    }

    /** @param string[] $cidrs */
    private static function ipMatchesAny(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /** Bare IPs compare exactly; v4 and v6 CIDRs both supported. */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits   = (int) $bits;
        $ipBin  = @inet_pton($ip);
        $subBin = @inet_pton($subnet);
        if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
            return false;
        }
        if ($rem > 0) {
            $mask = (0xFF << (8 - $rem)) & 0xFF;
            if ((ord($ipBin[$bytes]) & $mask) !== (ord($subBin[$bytes]) & $mask)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Start the session with hardened cookie parameters.
     * Must run before any output and before routing.
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443)
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name('fieldnote_sess');
        session_start();
    }

    /**
     * Regenerate the session ID. Call immediately after a successful login
     * to defeat session fixation.
     */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** Current session epoch from config, set at bootstrap. */
    private static string $sessionEpoch = '';

    public static function setSessionEpoch(string $epoch): void
    {
        self::$sessionEpoch = $epoch;
    }

    /** Stamp this session as belonging to the current epoch (call at login). */
    public static function stampSessionEpoch(): void
    {
        $_SESSION['sessionEpoch'] = self::$sessionEpoch;
    }

    /**
     * A session authenticates only if it carries the current epoch. The
     * epoch rotates whenever the admin password changes, so rotating the
     * password really does log out every other device — without this,
     * a stolen session outlives the credential it was minted with.
     * Empty epoch (pre-feature installs) enforces nothing until the first
     * password change generates one.
     */
    public static function isAuthenticated(): bool
    {
        if (empty($_SESSION['isAuthenticated'])) {
            return false;
        }
        return self::$sessionEpoch === ''
            || hash_equals(self::$sessionEpoch, (string) ($_SESSION['sessionEpoch'] ?? ''));
    }

    /**
     * Return the current CSRF token, creating one if needed.
     */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify a submitted CSRF token in constant time.
     */
    public static function verifyCsrf(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Abort the request unless this POST carries a valid CSRF token.
     */
    public static function requireValidCsrf(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        if (!self::verifyCsrf($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Invalid or expired security token. Please reload the form and try again.');
        }
    }
}

/**
 * Escape a value for safe output in HTML body or attribute context.
 * Use everywhere user-controlled data is printed.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Render a hidden CSRF input for embedding inside a <form>.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . e(Security::csrfToken()) . '">';
}
