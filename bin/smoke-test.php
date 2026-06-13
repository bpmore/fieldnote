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
    'federationEnabled' => true,
    'apHandle' => 'smoke',
]);
file_put_contents($tmp . '/data/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");

// 2FA enabled() is just "totp.json exists" — enough to render the verify page.
file_put_contents($tmp . '/data/totp.json', json_encode([
    'secret' => 'JBSWY3DPEHPK3PXP', 'lastCounter' => 0, 'recovery' => [],
]));

// A fixture passkey so the login button and options endpoints activate.
// (A real assertion can't be driven by curl; the verify failure path is.)
file_put_contents($tmp . '/data/passkeys.json', json_encode([
    'credentials' => [[
        'id'        => 'ZmFrZS1jcmVkZW50aWFs',
        'publicKey' => "-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE\n-----END PUBLIC KEY-----",
        'signCount' => 0,
        'label'     => 'Fixture key',
        'createdAt' => time(),
    ]],
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
// Past-due, but its body fails the a11y check: the scheduler must hold it as a
// draft and flag it, not push it live. (Id 5; the runtime 'Lint Me' is id 6.)
$blog->insert([
    'title' => 'Bad Schedule', 'slug' => 'bad-schedule', 'author' => 'Tester',
    'date' => time(), 'draft' => true, 'scheduledFor' => time() - 30,
    'content' => "## Two\n\n#### Four — skips a level", 'password' => '', 'tags' => [],
]);

// ----------------------------------------------------------------- server --

$port = random_int(49152, 60000);
$base = "http://127.0.0.1:$port";
// FN_AP_ALLOW_PRIVATE lets the federation checks talk to loopback;
// CLI_SERVER_WORKERS keeps the self-delivered Accept from deadlocking
// the single-threaded built-in server.
$pid  = (int) shell_exec(sprintf(
    'FN_AP_ALLOW_PRIVATE=1 PHP_CLI_SERVER_WORKERS=4 FN_DATA_DIR=%s FN_UPLOAD_DIR=%s php -S 127.0.0.1:%d -t %s %s > %s 2>&1 & echo $!',
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

[, $h] = req('GET', "$base/");
check('public pages carry strict CSP', str_contains($h['content-security-policy'] ?? '', "script-src 'none'"));

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

// Accessibility badge: off by default, opt-in via config, themed into the
// footer, linking to the statement. Flip the fixture config on, then revert.
$cfgFile = $tmp . '/data/config.php';
[, , $b] = req('GET', "$base/");
check('a11y badge hidden by default', !str_contains($b, 'a11y-badge'));
file_put_contents($cfgFile, "<?php\nreturn " . var_export(array_merge($config, ['accessibilityBadge' => true]), true) . ";\n");
[, , $b] = req('GET', "$base/");
check('a11y badge shows when enabled and links to the statement', str_contains($b, 'class="a11y-badge"') && str_contains($b, 'href="/accessibility"') && str_contains($b, 'WCAG'));
file_put_contents($cfgFile, "<?php\nreturn " . var_export($config, true) . ";\n");

// Footer copyright + curated social links: off by default, opt-in via config.
[, , $b] = req('GET', "$base/");
check('footer copyright + social hidden by default', !str_contains($b, 'footer-copyright') && !str_contains($b, 'social-links'));
file_put_contents($cfgFile, "<?php\nreturn " . var_export(array_merge($config, [
    'copyright' => 'author',
    'copyrightStartYear' => '2021',
    'social' => ['mastodon' => 'https://mastodon.social/@fieldnote', 'github' => 'https://github.com/bpmore/fieldnote'],
]), true) . ";\n");
[, , $b] = req('GET', "$base/");
check('footer copyright renders name and a year range', str_contains($b, 'footer-copyright') && str_contains($b, 'Tester') && str_contains($b, '2021') && str_contains($b, date('Y')));
check('footer social links render rel=me with labelled icons', str_contains($b, 'class="social-links"') && str_contains($b, 'rel="me"') && str_contains($b, 'mastodon.social/@fieldnote') && str_contains($b, '>Mastodon</span>') && str_contains($b, '>GitHub</span>'));
file_put_contents($cfgFile, "<?php\nreturn " . var_export($config, true) . ";\n");

// Inline owner controls on a public post: invisible to visitors, present for
// the authenticated owner; delete routes through a server-rendered confirm.
[, $h] = req('GET', "$base/post/1");
$postPath = parse_url($h['location'] ?? '/hello-world', PHP_URL_PATH);
[, , $b] = req('GET', $base . $postPath);
check('post controls hidden from visitors', !str_contains($b, 'post-admin'));
[$s, , $b] = req('GET', $base . $postPath, $authed);
check('owner sees edit + hide controls on a published post', $s === 200 && str_contains($b, 'class="post-admin"') && str_contains($b, '/edit') && str_contains($b, '>Hide</button>'), "status $s");
[$s, , $b] = req('GET', "$base/post/1/delete", $authed);
check('owner delete opens a confirm page, not a one-click delete', $s === 200 && str_contains($b, 'Delete permanently') && str_contains($b, 'csrf_token'), "status $s");
[$s, $h] = req('GET', "$base/post/1/delete");
check('delete confirm requires auth', $s === 302 && str_contains($h['location'] ?? '', '/login'), "status $s");

// ----------------------------------------------------------------- search --

[$s, , $b] = req('GET', "$base/search?q=published");
check('search finds body matches', $s === 200 && str_contains($b, 'Hello World'), "status $s");
[, , $b] = req('GET', "$base/search?q=SENTINEL-PROTECTED-BODY");
check('search never reads protected bodies', !str_contains($b, 'Locked Post'));
[, , $b] = req('GET', "$base/search?q=Locked");
check('search matches protected titles', str_contains($b, 'Locked Post'));
[, , $b] = req('GET', "$base/search?q=x");
check('single-char query returns nothing', !str_contains($b, 'Hello World'));
[, , $b] = req('GET', "$base/search?q=Hello");
check('search shows a result count', str_contains($b, 'class="search-status"') && str_contains($b, 'result') && str_contains($b, 'Hello'));
[, , $b] = req('GET', "$base/search?q=zzznomatchzzz");
check('search shows a no-results message', str_contains($b, 'class="search-status"') && str_contains($b, 'No results for') && str_contains($b, 'zzznomatchzzz'));
[, , $b] = req('GET', "$base/");
check('search status never appears off the search page', !str_contains($b, 'search-status'));

// The search box is surfaced in the header on every page when enabled, so
// /search is never a blank page and search is reachable from the home page.
[, , $b] = req('GET', "$base/");
check('search box appears in the header when enabled', str_contains($b, 'role="search"'));
[, , $b] = req('GET', "$base/search");
check('the search page shows the box with no query', str_contains($b, 'role="search"'));
file_put_contents($cfgFile, "<?php\nreturn " . var_export(array_merge($config, ['searchEnabled' => false]), true) . ";\n");
[, , $b] = req('GET', "$base/");
check('search box hidden when search is disabled', !str_contains($b, 'role="search"'));
[$s] = req('GET', "$base/search");
check('disabled search route 404s', $s === 404, "status $s");
file_put_contents($cfgFile, "<?php\nreturn " . var_export($config, true) . ";\n");

// --------------------------------------------------------- profile page --
[$s] = req('GET', "$base/about");
check('profile route 404s when off', $s === 404, "status $s");
[, , $b] = req('GET', "$base/");
check('no profile nav link when off', !str_contains($b, 'profile-link'));

file_put_contents($cfgFile, "<?php\nreturn " . var_export(array_merge($config, ['profilePage' => 'about']), true) . ";\n");
[, , $b] = req('GET', "$base/");
check('profile nav link appears in the header when enabled', str_contains($b, 'class="profile-link"') && str_contains($b, '>About</a>'));
[$s, , $b] = req('GET', "$base/admin/profile", $authed);
check('profile editor renders for the owner', $s === 200 && str_contains($b, 'Save profile page'), "status $s");
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s, , $b] = req('POST', "$base/admin/profile", $authed + ['body' => http_build_query(['csrf_token' => $m[1], 'pageContent' => "## A\n\n#### skips a level"])]);
check('profile save is blocked when it fails the a11y check', $s === 200 && str_contains($b, 'accessibility issues'), "status $s");
[, , $b] = req('GET', "$base/admin/profile", $authed);
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s] = req('POST', "$base/admin/profile", $authed + ['body' => http_build_query(['csrf_token' => $m[1], 'pageContent' => "## About me\n\nSENTINEL-PROFILE-BODY"])]);
check('a clean profile save succeeds', $s === 302, "status $s");
[$s, , $b] = req('GET', "$base/about");
check('profile page renders saved content through the theme', $s === 200 && str_contains($b, 'SENTINEL-PROFILE-BODY'), "status $s");
[, , $b] = req('GET', "$base/");
check('profile content stays out of the homepage', !str_contains($b, 'SENTINEL-PROFILE-BODY'));
[, , $b] = req('GET', "$base/feed");
check('profile content stays out of the feed', !str_contains($b, 'SENTINEL-PROFILE-BODY'));
file_put_contents($cfgFile, "<?php\nreturn " . var_export($config, true) . ";\n");

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

// ------------------------------------------------------------------ stats --

// curl's default/empty UA is filtered as a non-reader, so views need a
// browser-looking one. Same UA twice = one view; a second UA = two.
$postPath = parse_url($h2['location'] ?? '/', PHP_URL_PATH); // locked post — counted only when unlocked, so use post 1
[, $h1b] = req('GET', "$base/post/1");
$postPath = parse_url($h1b['location'] ?? '/', PHP_URL_PATH);
req('GET', $base . $postPath, ['headers' => ['User-Agent: Mozilla/5.0 (SmokeTest A)']]);
req('GET', $base . $postPath, ['headers' => ['User-Agent: Mozilla/5.0 (SmokeTest A)']]);
req('GET', $base . $postPath, ['headers' => ['User-Agent: Mozilla/5.0 (SmokeTest B)']]);
$statFiles = glob($tmp . '/data/stats/[0-9]*.json') ?: [];
$views = $statFiles ? (array) json_decode((string) file_get_contents($statFiles[0]), true) : [];
check('views dedupe per visitor per day', ($views['hello-world'] ?? 0) === 2, 'got ' . var_export($views, true));
$leaks = (string) shell_exec('grep -rl "127.0.0.1\|SmokeTest" ' . escapeshellarg($tmp . '/data/stats') . ' 2>/dev/null');
check('no IP or UA ever written to stats', trim($leaks) === '');
[, , $b] = req('GET', "$base/dashboard", $authed);
check('dashboard shows view counts', str_contains($b, 'cookie-less') && str_contains($b, 'Hello World'));

// ----------------------------------------- draft share links + revisions --

[, , $b] = req('GET', "$base/dashboard", $authed);
preg_match('#(/draft/\d+/\d+/[a-f0-9]{32})#', $b, $m);
check('dashboard offers a draft share link', isset($m[1]));
if (isset($m[1])) {
    [$s, , $b] = req('GET', $base . $m[1]); // logged out on purpose
    check('share link renders the draft logged-out', $s === 200 && str_contains($b, 'SENTINEL-DRAFT-BODY'), "status $s");
    [$s] = req('GET', $base . substr($m[1], 0, -1) . '0');
    check('tampered share token 404s', $s === 404, "status $s");
    [$s] = req('GET', $base . preg_replace('#/(\d+)/([a-f0-9]{32})$#', '/1111111111/$2', $m[1]));
    check('tampered share expiry 404s', $s === 404, "status $s");
}

// Revisions: edit post 1's content, then restore the original.
[, , $b] = req('GET', "$base/post/1/edit", $authed);
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s] = req('POST', "$base/post/1/edit", $authed + ['body' => http_build_query([
    'csrf_token'      => $m[1],
    'blogPostTitle'   => 'Hello World',
    'blogPostAuthor'  => 'Tester',
    'blogPostContent' => 'Rewritten body.',
    'blogPostTags'    => 'notes, testing',
])]);
[, , $b] = req('GET', "$base/post/1/edit", $authed);
check('edit creates a revision', $s === 302 && str_contains($b, 'Revisions'), "status $s");
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s] = req('POST', "$base/post/1/restore", $authed + ['body' => 'revision=0&csrf_token=' . $m[1]]);
[, , $b] = req('GET', "$base/post/1/edit", $authed);
check('restore brings the original text back', $s === 302 && str_contains($b, 'A **published** fixture post.'), "status $s");

// ----------------------------------------------------------- content lint --

[, , $b] = req('GET', "$base/dashboard", $authed);
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s] = req('POST', "$base/write", $authed + ['body' => http_build_query([
    'csrf_token'      => $m[1],
    'blogPostTitle'   => 'Lint Me',
    'blogPostAuthor'  => 'Tester',
    'blogPostContent' => "Intro.\n\n#### Skipped levels\n\nSee [click here](https://example.com) or [learn more](https://example.com).",
])]);
[, , $b] = req('GET', "$base/dashboard", $authed);
check('content lint flashes suggestions after save', $s === 302 && str_contains($b, 'Accessibility suggestions') && str_contains($b, 'click here'), "status $s");
check('lint flags "learn more" like other vague link text', str_contains($b, 'learn more'));
[, , $b] = req('GET', "$base/dashboard", $authed);
check('lint flash shows exactly once', !str_contains($b, 'Accessibility suggestions'));

// ------------------------------------------------- accessibility gate --------
// Drafts save freely (the 'Lint Me' draft above kept its skipped heading); the
// gate fires at the public boundary — publishing, and editing a live post.

// Editing a published post with a failing body is refused: the editor comes
// back with the specific fix and nothing is persisted.
[, , $b] = req('GET', "$base/post/1/edit", $authed);
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s, , $b] = req('POST', "$base/post/1/edit", $authed + ['body' => http_build_query([
    'csrf_token'      => $m[1],
    'blogPostTitle'   => 'Hello World',
    'blogPostAuthor'  => 'Tester',
    'blogPostContent' => "## Section\n\n#### Skips a level",
])]);
check('editing a live post is blocked when it fails the a11y check', $s === 200 && str_contains($b, 'accessibility issues') && str_contains($b, 'Heading levels jump'), "status $s");
[, , $b] = req('GET', "$base/post/1/edit", $authed);
check('the blocked edit did not persist', str_contains($b, 'A **published** fixture post.'));

// A clean edit to a live post still saves (no-op content, stays published).
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s] = req('POST', "$base/post/1/edit", $authed + ['body' => http_build_query([
    'csrf_token'      => $m[1],
    'blogPostTitle'   => 'Hello World',
    'blogPostAuthor'  => 'Tester',
    'blogPostContent' => 'A **published** fixture post.',
    'blogPostTags'    => 'notes, testing',
])]);
check('a clean edit to a live post still saves', $s === 302, "status $s");

// Publishing the failing 'Lint Me' draft is refused and routed to the editor;
// the post stays a draft (still hidden from the public homepage).
[, , $b] = req('GET', "$base/dashboard", $authed);
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s, $h] = req('POST', "$base/post/6/publish", $authed + ['body' => 'csrf_token=' . $m[1]]);
check('publishing a failing draft is blocked and sent to the editor', $s === 302 && str_contains($h['location'] ?? '', '/edit'), "status $s -> " . ($h['location'] ?? ''));
[, , $b] = req('GET', "$base/");
check('the blocked publish kept the post a draft', !str_contains($b, 'Lint Me'));

// Scheduled auto-publish runs the same gate: a past-due but inaccessible draft
// is held, not pushed live (the scheduler already ran on the first request).
[, , $b] = req('GET', "$base/");
check('scheduler does not publish a failing draft', !str_contains($b, 'Bad Schedule'));
[, , $b] = req('GET', "$base/dashboard", $authed);
check('dashboard flags the held scheduled post', str_contains($b, 'Bad Schedule') && str_contains($b, 'scheduled publish held'));

// --------------------------------------------------------------- passkeys --

// A logged-out visitor session (cookie reuse matters: the CSRF token and
// WebAuthn challenge both live in it, exactly like the real JS flow).
$visitorSid = 'fnsmoke' . bin2hex(random_bytes(8));
file_put_contents("$sessionDir/sess_$visitorSid", '');
$visitor = ['cookie' => 'fieldnote_sess=' . $visitorSid];

[$s, , $b] = req('GET', "$base/login", $visitor);
check('login page offers passkey sign-in', $s === 200 && str_contains($b, 'id="passkeyLogin"') && str_contains($b, 'passkeys.js'), "status $s");
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);

[$s, , $b] = req('POST', "$base/login/passkey/options", $visitor + ['body' => 'csrf_token=' . $m[1]]);
$json = json_decode($b, true);
check('passkey login options are well-formed', $s === 200 && is_string($json['publicKey']['challenge'] ?? null) && ($json['publicKey']['rpId'] ?? '') === '127.0.0.1', "status $s");

[$s, , $b] = req('POST', "$base/login/passkey/verify", $visitor + ['body' => http_build_query([
    'csrf_token' => $m[1], 'id' => 'bm9wZQ', 'clientDataJSON' => 'AAAA', 'authenticatorData' => 'AAAA', 'signature' => 'AAAA',
])]);
check('garbage passkey assertion fails closed', $s === 400 && str_contains($b, 'Passkey sign-in failed'), "status $s");
[$s] = req('POST', "$base/login/passkey/verify", $visitor + ['body' => http_build_query([
    'csrf_token' => $m[1], 'id' => 'bm9wZQ', 'clientDataJSON' => 'AAAA', 'authenticatorData' => 'AAAA', 'signature' => 'AAAA',
])]);
check('replayed challenge is rejected', $s === 400, "status $s");
@unlink("$sessionDir/sess_$visitorSid");

[, , $b] = req('GET', "$base/settings", $authed);
check('settings shows passkey management', str_contains($b, 'id="passkeySection"') && str_contains($b, 'Fixture key'));
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s, , $b] = req('POST', "$base/settings/passkeys/options", $authed + ['body' => 'csrf_token=' . $m[1]]);
$json = json_decode($b, true);
check('passkey create options exclude existing credential', $s === 200 && count($json['publicKey']['excludeCredentials'] ?? []) === 1 && ($json['publicKey']['authenticatorSelection']['requireResidentKey'] ?? false) === true, "status $s");

// ----------------------------------------------------- federation (AP-1) --

$apHost = '127.0.0.1:' . $port;
[$s, , $b] = req('GET', "$base/.well-known/webfinger?resource=" . urlencode("acct:smoke@$apHost"));
$json = json_decode($b, true);
check('webfinger resolves the handle', $s === 200 && ($json['links'][0]['href'] ?? '') === "$base/ap/actor", "status $s");
[$s] = req('GET', "$base/.well-known/webfinger?resource=" . urlencode("acct:wrong@$apHost"));
check('webfinger rejects unknown handles', $s === 404, "status $s");

[$s, $h, $b] = req('GET', "$base/ap/actor");
$actor = json_decode($b, true);
check('actor document is well-formed', $s === 200
    && ($actor['type'] ?? '') === 'Person'
    && ($actor['preferredUsername'] ?? '') === 'smoke'
    && str_contains((string) ($actor['publicKey']['publicKeyPem'] ?? ''), 'BEGIN PUBLIC KEY')
    && str_contains($h['content-type'] ?? '', 'activity+json'), "status $s");

[$s] = req('POST', "$base/ap/inbox", [
    'headers' => ['Content-Type: application/activity+json'],
    'body'    => json_encode(['type' => 'Follow', 'actor' => 'https://elsewhere.example/u/x', 'object' => "$base/ap/actor"]),
]);
check('unsigned follow rejected', $s === 401, "status $s");
[$s] = req('POST', "$base/ap/inbox", [
    'headers' => ['Content-Type: application/activity+json'],
    'body'    => str_repeat('a', 70000),
]);
check('oversized inbox payload rejected', $s === 413, "status $s");

// A correctly signed Follow — signed with the blog's OWN key (keyId points
// at our own actor, fetched over loopback thanks to FN_AP_ALLOW_PRIVATE),
// which exercises signature verification, actor fetch + cache, follower
// storage, and the signed Accept delivery end to end.
$apKeys = json_decode((string) file_get_contents($tmp . '/data/activitypub/keys.json'), true);
$signedApPost = static function (array $activity) use ($base, $apHost, $apKeys): array {
    $body   = (string) json_encode($activity, JSON_UNESCAPED_SLASHES);
    $date   = gmdate('D, d M Y H:i:s \G\M\T');
    $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
    $signing = "(request-target): post /ap/inbox\nhost: $apHost\ndate: $date\ndigest: $digest";
    openssl_sign($signing, $sig, $apKeys['private'], OPENSSL_ALGO_SHA256);
    return req('POST', "$base/ap/inbox", ['headers' => [
        'Content-Type: application/activity+json',
        "Date: $date",
        "Digest: $digest",
        'Signature: keyId="' . $base . '/ap/actor#main-key",algorithm="rsa-sha256"'
            . ',headers="(request-target) host date digest",signature="' . base64_encode($sig) . '"',
    ], 'body' => $body]);
};

[$s] = $signedApPost(['@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => "$base/ap/actor#test-follow", 'type' => 'Follow',
    'actor' => "$base/ap/actor", 'object' => "$base/ap/actor"]);
[, , $b] = req('GET', "$base/ap/followers");
$json = json_decode($b, true);
check('signed follow accepted and counted', $s === 202 && ($json['totalItems'] ?? -1) === 1, "inbox $s, totalItems " . var_export($json['totalItems'] ?? null, true));

[$s] = $signedApPost(['@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => "$base/ap/actor#test-undo", 'type' => 'Undo', 'actor' => "$base/ap/actor",
    'object' => ['type' => 'Follow', 'actor' => "$base/ap/actor", 'object' => "$base/ap/actor"]]);
[, , $b] = req('GET', "$base/ap/followers");
$json = json_decode($b, true);
check('undo removes the follower', $s === 202 && ($json['totalItems'] ?? -1) === 0, "inbox $s");

[$s, , $b] = req('GET', "$base/ap/outbox");
check('outbox is a valid empty collection (AP-1)', $s === 200 && (json_decode($b, true)['totalItems'] ?? -1) === 0, "status $s");

// --------------------------------------------------------- export / import --

if (!class_exists(ZipArchive::class)) {
    echo "! ext-zip missing — export/import checks skipped\n";
} else {
    [$s, , $b] = req('GET', "$base/admin/export", $authed);
    file_put_contents("$tmp/export.zip", $b);
    $zipRead = new ZipArchive();
    $names   = [];
    if ($zipRead->open("$tmp/export.zip") === true) {
        for ($i = 0; $i < $zipRead->numFiles; $i++) {
            $names[] = (string) $zipRead->getNameIndex($i);
        }
    }
    check('export is a zip with posts and site.yaml', $s === 200 && in_array('site.yaml', $names, true) && count(preg_grep('#^posts/.+\.md$#', $names)) >= 4, "status $s");
    $helloEntry = array_values(preg_grep('#hello-world\.md$#', $names));
    $helloMd    = $helloEntry ? (string) $zipRead->getFromName($helloEntry[0]) : '';
    check('export frontmatter round-trips title and tags', str_contains($helloMd, 'title: "Hello World"') && str_contains($helloMd, '"notes"'));
    $zipRead->close();

    // A foreign, Jekyll-shaped archive with a featured image.
    $jekyllZip = "$tmp/jekyll.zip";
    $zipWrite  = new ZipArchive();
    $zipWrite->open($jekyllZip, ZipArchive::CREATE);
    $zipWrite->addFromString(
        '_posts/2024-03-05-imported-note.md',
        "---\ntitle: Imported Note\ntags:\n  - imported\nimage: /images/pic.png\n---\n\nBody from another platform.\n"
    );
    $im = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($im);
    $zipWrite->addFromString('images/pic.png', (string) ob_get_clean());
    $zipWrite->close();

    [, , $b] = req('GET', "$base/dashboard", $authed);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s, , $b] = req('POST', "$base/admin/import", $authed + ['body' => [
        'csrf_token' => $m[1],
        'importZip'  => new CURLFile($jekyllZip, 'application/zip', 'jekyll.zip'),
    ]]);
    check('import dry-run inspects without writing', $s === 200 && str_contains($b, 'imported-note') && str_contains($b, 'Nothing has been written'), "status $s");
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s] = req('POST', "$base/admin/import/confirm", $authed + ['body' => 'csrf_token=' . $m[1]]);
    [$s2, , $b2] = req('GET', "$base/2024/03/imported-note");
    check('confirmed import publishes the post with its image', $s === 302 && $s2 === 200 && str_contains($b2, 'Body from another platform') && str_contains($b2, '/uploads/'), "post page $s2");
    [, , $b2] = req('GET', "$base/tag/imported");
    check('imported tags work', str_contains($b2, 'Imported Note'));

    // Same archive again: collision = skip, never duplicate.
    [, , $b] = req('GET', "$base/dashboard", $authed);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s, , $b] = req('POST', "$base/admin/import", $authed + ['body' => [
        'csrf_token' => $m[1],
        'importZip'  => new CURLFile($jekyllZip, 'application/zip', 'jekyll.zip'),
    ]]);
    check('re-import skips existing slugs', $s === 200 && str_contains($b, 'skip — exists'), "status $s");

    // WordPress (WXR): HTML body -> Markdown, accessibility report on the
    // dry-run, posts land as drafts. No remote images, so no network in CI.
    $wxr = "$tmp/wp.xml";
    file_put_contents($wxr,
        '<?xml version="1.0"?><rss version="2.0"'
        . ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
        . ' xmlns:wp="http://wordpress.org/export/1.2/"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><item>'
        . '<title>WP Imported</title><dc:creator>brent</dc:creator>'
        . '<pubDate>Mon, 02 Jun 2025 10:00:00 +0000</pubDate>'
        . '<content:encoded><![CDATA[<h2>Section</h2><p>Imported from <strong>WordPress</strong>. See <a href="https://e.com">read more</a>.</p>]]></content:encoded>'
        . '<category domain="post_tag">News</category>'
        . '<wp:post_name>wp-imported</wp:post_name><wp:post_type>post</wp:post_type>'
        . '<wp:status>publish</wp:status><wp:post_date_gmt>2025-06-02 10:00:00</wp:post_date_gmt>'
        . '</item></channel></rss>');
    [, , $b] = req('GET', "$base/admin/import", $authed);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s, , $b] = req('POST', "$base/admin/import", $authed + ['body' => [
        'csrf_token'   => $m[1],
        'importSource' => 'wordpress',
        'importZip'    => new CURLFile($wxr, 'text/xml', 'wp.xml'),
    ]]);
    check('wordpress dry-run flags accessibility and writes nothing', $s === 200 && str_contains($b, 'wp-imported') && str_contains($b, 'to fix') && str_contains($b, 'Nothing has been written'), "status $s");
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s] = req('POST', "$base/admin/import/confirm", $authed + ['body' => 'csrf_token=' . $m[1]]);
    check('wordpress import creates the post', $s === 302, "status $s");
    [, , $b] = req('GET', "$base/");
    check('wordpress import lands as a draft (hidden from the public)', !str_contains($b, 'WP Imported'));
    [, , $b] = req('GET', "$base/dashboard", $authed);
    check('imported draft appears on the dashboard', str_contains($b, 'WP Imported'));
    [$s, , $b] = req('GET', "$base/2025/06/wp-imported", $authed);
    check('wordpress HTML body converted to markdown and rendered', $s === 200 && str_contains($b, 'Imported from') && str_contains($b, '<strong>WordPress</strong>'), "status $s");

    // Generic RSS: a feed file imports as a draft, with the same a11y report.
    $rssFile = "$tmp/feed.xml";
    file_put_contents($rssFile,
        '<?xml version="1.0"?><rss version="2.0"><channel><title>Feed</title><item>'
        . '<title>RSS Imported</title><link>https://blog.test/rss-imported/</link>'
        . '<pubDate>Sat, 03 May 2025 09:00:00 +0000</pubDate><category>News</category>'
        . '<description><![CDATA[<p>From a <strong>feed</strong>. See <a href="https://e.com">read more</a>.</p>]]></description>'
        . '</item></channel></rss>');
    [, , $b] = req('GET', "$base/admin/import", $authed);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s, , $b] = req('POST', "$base/admin/import", $authed + ['body' => [
        'csrf_token'   => $m[1],
        'importSource' => 'rss',
        'importZip'    => new CURLFile($rssFile, 'application/xml', 'feed.xml'),
    ]]);
    check('rss dry-run flags accessibility and writes nothing', $s === 200 && str_contains($b, 'rss-imported') && str_contains($b, 'to fix') && str_contains($b, 'Nothing has been written'), "status $s");
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s] = req('POST', "$base/admin/import/confirm", $authed + ['body' => 'csrf_token=' . $m[1]]);
    check('rss import creates a draft', $s === 302, "status $s");
    [$s, , $b] = req('GET', "$base/2025/05/rss-imported", $authed);
    check('rss body converted to markdown and rendered', $s === 200 && str_contains($b, 'From a') && str_contains($b, '<strong>feed</strong>'), "status $s");

    // RSS by URL: fetch the instance's own feed (loopback allowed in the test
    // harness) — every post already exists, so all are deduped.
    [, , $b] = req('GET', "$base/admin/import", $authed);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s, , $b] = req('POST', "$base/admin/import", $authed + ['body' => http_build_query([
        'csrf_token'   => $m[1],
        'importSource' => 'rss',
        'importUrl'    => "$base/feed",
    ])]);
    check('rss import fetches a feed URL and dedupes existing posts', $s === 200 && str_contains($b, 'skip — exists'), "status $s");
}

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
[, , $css] = req('GET', "$base/palette.css");
check('suggested palette saves and renders', $s === 302 && str_contains($b2, 'palette.css?v=') && str_contains($css, '@media (prefers-color-scheme: light){:root{--text:'), "status $s");

// Reset restores stock rendering.
[, , $b] = req('GET', "$base/admin/palette", $authed);
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s] = req('POST', "$base/admin/palette", $authed + ['body' => 'paletteAction=reset&csrf_token=' . $m[1]]);
[, , $b2] = req('GET', "$base/");
check('palette reset clears overrides', $s === 302 && !str_contains($b2, 'palette.css?v='), "status $s");

// ----------------------------------------- session epoch (password change) --

// Changing the password must log out every OTHER session while the session
// that changed it stays in. Run last: it rewrites the fixture config.
[, , $b] = req('GET', "$base/settings", $authed);
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s] = req('POST', "$base/settings", $authed + ['body' => http_build_query([
    'csrf_token'   => $m[1],
    'blogName'     => 'Smoke',
    'blogInfo'     => 'Smoke-test fixture',
    'blogDomain'   => $base, // the test server itself, so canonical-host enforcement keeps matching
    'blogTemplate' => 'gazette',
    'blogTimezone' => 'UTC',
    'blogI18N'     => 'en_US',
    'blogPostsPerPage' => '6',
    'blogSearchEnabled' => '1',
    'blogStatsEnabled'  => '1',
    'blogPassword' => 'rotated-password',
])]);
[$s2] = req('GET', "$base/dashboard", $authed);
check('password change keeps the changing session', $s === 302 && $s2 === 200, "save $s, dashboard $s2");

$staleSid = 'fnsmoke' . bin2hex(random_bytes(8));
file_put_contents("$sessionDir/sess_$staleSid", 'isAuthenticated|b:1;');
[$s, $h] = req('GET', "$base/dashboard", ['cookie' => 'fieldnote_sess=' . $staleSid]);
@unlink("$sessionDir/sess_$staleSid");
check('password change logs out other sessions', $s === 302 && str_contains($h['location'] ?? '', '/login'), "status $s");

// ----------------------------------------------------- canonical host 301 --

// The domain is now configured (set just above), so a request arriving on
// any other host must 301 to the canonical address, path intact.
[$s, $h] = req('GET', "$base/tag/notes", ['headers' => ['Host: old-address.example']]);
check('non-canonical host 301s to the domain', $s === 301 && ($h['location'] ?? '') === "$base/tag/notes", "status $s -> " . ($h['location'] ?? ''));
[$s] = req('GET', "$base/", ['headers' => ['Host: ' . parse_url($base, PHP_URL_HOST) . ':' . parse_url($base, PHP_URL_PORT)]]);
check('canonical host serves normally', $s === 200, "status $s");
[$s] = req('POST', "$base/logout", ['headers' => ['Host: old-address.example']]);
check('non-GET on wrong host is not blind-redirected', $s === 419, "status $s");

// The settings save above did not tick federation, so it is now OFF:
// every ActivityPub endpoint must 404.
foreach (["/.well-known/webfinger?resource=acct:smoke@127.0.0.1:$port", '/ap/actor', '/ap/outbox', '/ap/followers'] as $apPath) {
    [$s] = req('GET', $base . $apPath);
    if ($s !== 404) {
        check("federation off: $apPath 404s", false, "status $s");
    }
}
[$s] = req('POST', "$base/ap/inbox", ['body' => '{}', 'headers' => ['Content-Type: application/activity+json']]);
check('federation off: all AP endpoints 404', $s === 404, "inbox status $s");

// ---------------------------------------------------------------- summary --

echo "\n" . ($failures === 0 ? 'All checks passed.' : "$failures check(s) FAILED.") . "\n";
exit($failures > 0 ? 1 : 0);
