<?php

namespace Dropplets;

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

    /** Hash the client IP so raw addresses are never stored on disk. */
    private static function throttleKey(): string
    {
        return hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
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

        session_name('dropplets_sess');
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

    public static function isAuthenticated(): bool
    {
        return !empty($_SESSION['isAuthenticated']);
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
