<?php

/**
 * End-to-end smoke test. CLI only.
 *
 *   php bin/smoke-test.php
 *
 * Boots a disposable Fieldnote instance — fixture config, posts, and 2FA
 * state in a temp directory via the FN_DATA_DIR / FN_UPLOAD_DIR overrides —
 * on the PHP built-in server, then asserts the route matrix: public pages
 * render, drafts and protected bodies never leak, admin pages are
 * auth-gated, CSRF-less POSTs are rejected, traversal 404s, the feed
 * honors conditional GET, and theme previews force schemes.
 *
 * Auth is simulated by writing session files directly (isAuthenticated /
 * pending_2fa), so no password round-trip is needed.
 *
 * Exit code 0 = all green, 1 = failures.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use SleekDB\Store;

// ---------------------------------------------------------------- fixture --

$tmp = sys_get_temp_dir() . '/fn-smoke-' . bin2hex(random_bytes(4));
mkdir($tmp . '/data', 0750, true);
mkdir($tmp . '/uploads', 0755, true);

$config = array_merge(Fieldnote\Config::DEFAULTS, [
    'name'     => 'Smoke',
    'info'     => 'Smoke-test fixture',
    'author'   => 'Tester',
    'domain'   => '',
    'password' => password_hash('smoke-pass', PASSWORD_DEFAULT),
    'template' => 'gazette',
    'timezone' => 'UTC',
]);
file_put_contents($tmp . '/data/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");

// 2FA enabled() is just "totp.json exists" — enough to render the verify page.
file_put_contents($tmp . '/data/totp.json', json_encode([
    'secret' => 'JBSWY3DPEHPK3PXP', 'lastCounter' => 0, 'recovery' => [],
]));

// Migration markers: fixtures are already current-format.
foreach (['.slugs-v1', '.pubdate-v1', '.imgrel-v1'] as $marker) {
    touch($tmp . '/data/' . $marker);
}

$blog = new Store('blog', $tmp . '/data/siteDatabase', ['timeout' => false]);
$blog->insert([
    'title' => 'Hello World', 'slug' => 'hello-world', 'author' => 'Tester',
    'date' => time() - 86400, 'publishedAt' => time() - 86400, 'draft' => false,
    'content' => 'A **published** fixture post.', 'password' => '', 'tags' => ['notes', 'testing'],
]);
$blog->insert([
    'title' => 'Locked Post', 'slug' => 'locked-post', 'author' => 'Tester',
    'date' => time() - 3600, 'publishedAt' => time() - 3600, 'draft' => false,
    'content' => 'SENTINEL-PROTECTED-BODY', 'password' => password_hash('post-pass', PASSWORD_DEFAULT),
]);
$blog->insert([
    'title' => 'Unfinished Draft', 'slug' => 'unfinished-draft', 'author' => 'Tester',
    'date' => time(), 'draft' => true,
    'content' => 'SENTINEL-DRAFT-BODY', 'password' => '',
]);
$blog->insert([
    'title' => 'Scheduled Post', 'slug' => 'scheduled-post', 'author' => 'Tester',
    'date' => time(), 'draft' => true, 'scheduledFor' => time() - 30,
    'content' => 'Came from the scheduler.', 'password' => '',
]);

// ----------------------------------------------------------------- server --

$port = random_int(49152, 60000);
$base = "http://127.0.0.1:$port";
$pid  = (int) shell_exec(sprintf(
    'FN_DATA_DIR=%s FN_UPLOAD_DIR=%s php -S 127.0.0.1:%d -t %s %s > %s 2>&1 & echo $!',
    escapeshellarg($tmp . '/data'),
    escapeshellarg($tmp . '/uploads'),
    $port,
    escapeshellarg($root . '/public'),
    escapeshellarg($root . '/public/index.php'),
    escapeshellarg($tmp . '/server.log')
));

$sessionDir = session_save_path() ?: sys_get_temp_dir();
$authedSid  = 'fnsmoke' . bin2hex(random_bytes(8));
$pendingSid = 'fnsmoke' . bin2hex(random_bytes(8));
file_put_contents("$sessionDir/sess_$authedSid", 'isAuthenticated|b:1;');
file_put_contents("$sessionDir/sess_$pendingSid", 'pending_2fa|i:' . time() . ';');

register_shutdown_function(static function () use ($pid, $tmp, $sessionDir, $authedSid, $pendingSid): void {
    if ($pid > 0) {
        posix_kill($pid, SIGTERM) || shell_exec('kill ' . $pid . ' 2>/dev/null');
    }
    @unlink("$sessionDir/sess_$authedSid");
    @unlink("$sessionDir/sess_$pendingSid");
    shell_exec('rm -rf ' . escapeshellarg($tmp));
});

// Wait for readiness.
$up = false;
for ($i = 0; $i < 50; $i++) {
    $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($sock) {
        fclose($sock);
        $up = true;
        break;
    }
    usleep(100_000);
}
if (!$up) {
    fwrite(STDERR, "Server failed to start on :$port\n" . (string) @file_get_contents($tmp . '/server.log'));
    exit(1);
}

// ---------------------------------------------------------------- helpers --

/** @return array{0:int,1:array<string,string>,2:string} [status, headers, body] */
function req(string $method, string $url, array $opts = []): array
{
    $headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    if (isset($opts['cookie'])) {
        curl_setopt($ch, CURLOPT_COOKIE, $opts['cookie']);
    }
    if (isset($opts['headers'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
    }
    if (isset($opts['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
    }
    if (!empty($opts['pathAsIs'])) {
        curl_setopt($ch, CURLOPT_PATH_AS_IS, true);
    }
    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $headers, $body];
}

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "\u{2713} $name\n";
    } else {
        $failures++;
        echo "\u{2717} $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

$authed  = ['cookie' => 'fieldnote_sess=' . $authedSid];
$pending = ['cookie' => 'fieldnote_sess=' . $pendingSid];

// ----------------------------------------------------------------- public --

[$s, , $b] = req('GET', "$base/");
check('homepage renders', $s === 200 && str_contains($b, 'Hello World'), "status $s");
check('homepage hides drafts', !str_contains($b, 'Unfinished Draft') && !str_contains($b, 'SENTINEL-DRAFT-BODY'));

[$s, $h] = req('GET', "$base/post/1");
check('legacy id URL 301s to dated slug', $s === 301 && str_contains($h['location'] ?? '', '/hello-world'), "status $s -> " . ($h['location'] ?? ''));

[$s, , $b] = req('GET', $base . parse_url($h['location'] ?? '/', PHP_URL_PATH));
check('post page renders', $s === 200 && str_contains($b, '<strong>published</strong>'), "status $s");

[$s, $h2] = req('GET', "$base/post/2");
[$s, , $b] = req('GET', $base . parse_url($h2['location'] ?? '/', PHP_URL_PATH));
check('protected post shows form, not body', $s === 200 && str_contains($b, 'password') && !str_contains($b, 'SENTINEL-PROTECTED-BODY'), "status $s");

[$s] = req('GET', "$base/no-such-page");
check('unknown URL 404s', $s === 404, "status $s");

// The lazy publisher must have flipped the past-due scheduled draft on the
// first request of this run.
[, , $b] = req('GET', "$base/");
check('past-due scheduled draft auto-published', str_contains($b, 'Scheduled Post'));

[$s, , $b] = req('GET', "$base/accessibility");
check('accessibility statement renders from Wcag constants', $s === 200 && str_contains($b, '4.5:1') && str_contains($b, 'prefers-reduced-motion'), "status $s");

// ----------------------------------------------------------------- search --

[$s, , $b] = req('GET', "$base/search?q=published");
check('search finds body matches', $s === 200 && str_contains($b, 'Hello World'), "status $s");
[, , $b] = req('GET', "$base/search?q=SENTINEL-PROTECTED-BODY");
check('search never reads protected bodies', !str_contains($b, 'Locked Post'));
[, , $b] = req('GET', "$base/search?q=Locked");
check('search matches protected titles', str_contains($b, 'Locked Post'));
[, , $b] = req('GET', "$base/search?q=x");
check('single-char query returns nothing', !str_contains($b, 'Hello World'));

// ------------------------------------------------------------------- feed --

[$s, $h, $b] = req('GET', "$base/feed");
check('feed renders with validators', $s === 200 && isset($h['etag'], $h['last-modified']) && str_contains($b, '<rss'), "status $s");
check('feed excludes protected bodies', !str_contains($b, 'SENTINEL-PROTECTED-BODY'));
check('feed carries tag categories', str_contains($b, '<category>notes</category>'));
[$s, , $b] = req('GET', "$base/feed", ['headers' => ['If-None-Match: ' . ($h['etag'] ?? '')]]);
check('feed conditional GET 304s', $s === 304 && $b === '', "status $s");

// ----------------------------------------------------- tags + syndication --

[$s, , $b] = req('GET', "$base/tag/notes");
check('tag page lists tagged post', $s === 200 && str_contains($b, 'Hello World'), "status $s");
[$s] = req('GET', "$base/tag/never-used");
check('unknown tag 404s', $s === 404, "status $s");

[$s, , $b] = req('GET', "$base/feed.json");
$json = json_decode($b, true);
check('JSON feed valid with items', $s === 200 && ($json['version'] ?? '') === 'https://jsonfeed.org/version/1.1' && count($json['items'] ?? []) >= 1, "status $s");
$taggedItem = array_values(array_filter($json['items'] ?? [], static fn (array $i): bool => str_contains($i['url'] ?? '', 'hello-world')));
check('JSON feed excludes protected, includes tags', !str_contains($b, 'SENTINEL-PROTECTED-BODY') && in_array('notes', $taggedItem[0]['tags'] ?? [], true));

[$s, , $b] = req('GET', "$base/sitemap.xml");
check('sitemap lists posts, omits protected', $s === 200 && str_contains($b, 'hello-world') && !str_contains($b, 'locked-post'), "status $s");

[$s, , $b] = req('GET', "$base/robots.txt");
check('robots.txt points at sitemap', $s === 200 && str_contains($b, 'Sitemap:') && str_contains($b, '/sitemap.xml'), "status $s");

// ------------------------------------------------------------ auth gating --

foreach (['/dashboard', '/admin/themes', '/write'] as $path) {
    [$s, $h] = req('GET', "$base$path");
    check("logged out $path redirects to login", $s === 302 && str_contains($h['location'] ?? '', '/login'), "status $s");
}

[$s, , $b] = req('GET', "$base/dashboard", $authed);
check('authed dashboard renders', $s === 200 && str_contains($b, 'Hello World'), "status $s");

// The verify2fa regression (un-captured $siteConfig): page must render
// clean for a pending-2FA session.
[$s, , $b] = req('GET', "$base/login/verify", $pending);
check('2FA verify page renders', $s === 200 && str_contains($b, 'Two-Factor Verification'), "status $s");
check('2FA verify page free of PHP warnings', !str_contains($b, 'Undefined variable') && !str_contains($b, 'Warning:'));

// ----------------------------------------------------------- content lint --

[, , $b] = req('GET', "$base/dashboard", $authed);
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s] = req('POST', "$base/write", $authed + ['body' => http_build_query([
    'csrf_token'      => $m[1],
    'blogPostTitle'   => 'Lint Me',
    'blogPostAuthor'  => 'Tester',
    'blogPostContent' => "Intro.\n\n#### Skipped levels\n\nSee [click here](https://example.com).",
])]);
[, , $b] = req('GET', "$base/dashboard", $authed);
check('content lint flashes suggestions after save', $s === 302 && str_contains($b, 'Accessibility suggestions') && str_contains($b, 'click here'), "status $s");
[, , $b] = req('GET', "$base/dashboard", $authed);
check('lint flash shows exactly once', !str_contains($b, 'Accessibility suggestions'));

// ---------------------------------------------------------- theme gallery --

[$s, , $b] = req('GET', "$base/admin/themes", $authed);
check('gallery lists themes', $s === 200 && substr_count($b, 'theme-card') >= 70, "status $s");

[$s, $h, $b] = req('GET', "$base/admin/themes/preview/terminal?scheme=light", $authed);
check('preview forces scheme', $s === 200 && str_contains($b, '<style>:root{'), "status $s");
check('preview sends noindex + frame-ancestors', ($h['x-robots-tag'] ?? '') === 'noindex' && str_contains($h['content-security-policy'] ?? '', 'frame-ancestors'));

[$s] = req('GET', "$base/admin/themes/preview/doesnotexist", $authed);
check('unknown theme 404s', $s === 404, "status $s");

[$s] = req('GET', "$base/admin/themes/preview/../../etc", $authed + ['pathAsIs' => true]);
check('traversal 404s', $s === 404, "status $s");

// ------------------------------------------------------------------- CSRF --

[$s] = req('POST', "$base/admin/themes/apply", $authed + ['body' => 'theme=gazette']);
check('CSRF-less POST rejected', $s === 419, "status $s");

// Full CSRF round trip: token from the dashboard, then a mutating POST.
[, , $b] = req('GET', "$base/admin/themes", $authed);
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
check('CSRF token present in forms', isset($m[1]));
if (isset($m[1])) {
    [$s, $h] = req('POST', "$base/admin/themes/apply", $authed + ['body' => 'theme=zen&csrf_token=' . $m[1]]);
    [$s2, , $b2] = req('GET', "$base/", []);
    check('tokened apply switches theme', $s === 302 && $s2 === 200 && str_contains($b2, '/themes/zen/theme.css'), "status $s");
}

// ---------------------------------------------------------------- palette --

// The fixture still uses gazette here (apply-theme test above runs after
// re-reading; order matters) — switch back explicitly to be self-contained.
[, , $b] = req('GET', "$base/admin/themes", $authed);
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
req('POST', "$base/admin/themes/apply", $authed + ['body' => 'theme=gazette&csrf_token=' . $m[1]]);

/** @return array<string,array<string,string>> scheme => token => hex */
function formColors(string $html, string $type): array
{
    $values = [];
    preg_match_all(
        '/<input type="' . $type . '"[^>]*name="tok\[(\w+)\]\[(--[a-z-]+)\]"[^>]*value="(#[0-9a-f]{6})"/i',
        $html,
        $m,
        PREG_SET_ORDER
    );
    foreach ($m as $hit) {
        $values[$hit[1]][$hit[2]] = $hit[3];
    }
    return $values;
}

[$s, , $b] = req('GET', "$base/admin/palette", $authed);
$colors = formColors($b, 'color');
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
check('palette page renders all token inputs', $s === 200 && count($colors['light'] ?? []) === 8 && count($colors['dark'] ?? []) === 8, "status $s");

// Submit a failing palette: light gray body text on gazette's light paper.
$colors['light']['--text'] = '#aaaaaa';
[$s, , $b] = req('POST', "$base/admin/palette", $authed + [
    'body' => http_build_query(['csrf_token' => $m[1], 'tok' => $colors]),
]);
check('failing palette is rejected with suggestions', $s === 200 && str_contains($b, 'Not saved') && str_contains($b, 'Apply suggested fixes'), "status $s");

// Apply the server's own suggested fixes — must save.
$suggested = formColors($b, 'hidden');
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s] = req('POST', "$base/admin/palette", $authed + [
    'body' => http_build_query(['csrf_token' => $m[1], 'tok' => $suggested]),
]);
[$s2, , $b2] = req('GET', "$base/");
check('suggested palette saves and renders', $s === 302 && str_contains($b2, '@media (prefers-color-scheme: light){:root{--text:'), "status $s");

// Reset restores stock rendering.
[, , $b] = req('GET', "$base/admin/palette", $authed);
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s] = req('POST', "$base/admin/palette", $authed + ['body' => 'paletteAction=reset&csrf_token=' . $m[1]]);
[, , $b2] = req('GET', "$base/");
check('palette reset clears overrides', $s === 302 && !str_contains($b2, 'prefers-color-scheme: light){:root{--text:'), "status $s");

// ---------------------------------------------------------------- summary --

echo "\n" . ($failures === 0 ? 'All checks passed.' : "$failures check(s) FAILED.") . "\n";
exit($failures > 0 ? 1 : 0);
