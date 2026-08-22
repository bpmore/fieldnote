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
// The server reads data/config.php on every request while these checks rewrite
// it. file_put_contents() truncates before it writes, so a worker that requires
// the file inside that window sees zero bytes, Config::load() falls back to
// DEFAULTS, and an unrelated check fails somewhere downstream. Write through a
// temp file and rename instead — the same guarantee Config::save() gives the
// app, so a reader always sees either the old config or the new one.
function fn_smoke_write_cfg(string $path, array $cfg): void
{
    $tmpFile = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    file_put_contents($tmpFile, "<?php\nreturn " . var_export($cfg, true) . ";\n", LOCK_EX);
    rename($tmpFile, $path);
}
fn_smoke_write_cfg($tmp . '/data/config.php', $config);

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

// Migration watermarks, seeded at the OLD path on purpose: fixtures are
// already current-format, and this exercises the one-boot legacy honour.
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
$scheduledAt = time() - 30;
$blog->insert([
    'title' => 'Scheduled Post', 'slug' => 'scheduled-post', 'author' => 'Tester',
    'date' => time(), 'draft' => true, 'scheduledFor' => $scheduledAt,
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
// Sessions live inside the fixture, not the system temp dir. Pinning
// save_path on the server makes the harness and the process under test agree
// by construction — they used to agree only by accident, because this CLI
// process and the server happened to read the same ini. Any host that set
// session.save_path for CLI only would have turned every authenticated check
// into a 302-to-login, sixty failures pointing at sixty unrelated routes
// instead of at the one broken fixture. It also means `rm -rf $tmp` is the
// whole cleanup: nothing of ours survives a crash outside that directory.
$sessionDir = $tmp . '/sessions';
mkdir($sessionDir, 0700, true);

$pid  = (int) shell_exec(sprintf(
    'FN_AP_ALLOW_PRIVATE=1 PHP_CLI_SERVER_WORKERS=4 FN_DATA_DIR=%s FN_UPLOAD_DIR=%s php -d session.save_path=%s -d session.serialize_handler=php -S 127.0.0.1:%d -t %s %s > %s 2>&1 & echo $!',
    escapeshellarg($tmp . '/data'),
    escapeshellarg($tmp . '/uploads'),
    escapeshellarg($sessionDir),
    $port,
    escapeshellarg($root . '/public'),
    escapeshellarg($root . '/public/index.php'),
    escapeshellarg($tmp . '/server.log')
));

// Auth is faked by writing the session file the server will read. Returns the
// cookie option array, so a sid can never exist without the cookie that uses
// it. The payload format is pinned by serialize_handler above.
$session = static function (string $payload = '') use ($sessionDir): array {
    $sid = 'fnsmoke' . bin2hex(random_bytes(8));
    file_put_contents("$sessionDir/sess_$sid", $payload);
    return ['cookie' => 'fieldnote_sess=' . $sid];
};
$browser = static function () use ($tmp): array {
    return ['jar' => tempnam($tmp, 'jar')];
};
$authed  = $session('isAuthenticated|b:1;');
$pending = $session('pending_2fa|i:' . time() . ';');

register_shutdown_function(static function () use ($pid, $tmp): void {
    if ($pid > 0) {
        posix_kill($pid, SIGTERM) || shell_exec('kill ' . $pid . ' 2>/dev/null');
    }
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
    if (isset($opts['jar'])) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $opts['jar']);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $opts['jar']);
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

// It must be stamped with the instant it was scheduled FOR, not the instant a
// visitor happened to arrive. The permalink embeds that date, so using request
// time would make a post URL depend on traffic. Read through a fresh store so
// the harness sees what the server actually wrote.
$schedRecord = (new Store('blog', $tmp . '/data/siteDatabase', ['timeout' => false]))
    ->findOneBy(['slug', '=', 'scheduled-post']);
check(
    'scheduler stamps the scheduled instant, not request time',
    (int) ($schedRecord['publishedAt'] ?? 0) === $scheduledAt && (int) ($schedRecord['date'] ?? 0) === $scheduledAt,
    'publishedAt ' . ($schedRecord['publishedAt'] ?? 'null') . ', date ' . ($schedRecord['date'] ?? 'null') . ', expected ' . $scheduledAt
);

[$s, , $b] = req('GET', "$base/accessibility");
check('accessibility statement renders from Wcag constants', $s === 200 && str_contains($b, '4.5:1') && str_contains($b, 'prefers-reduced-motion'), "status $s");

// Accessibility badge: off by default, opt-in via config, themed into the
// footer, linking to the statement. Flip the fixture config on, then revert.
$cfgFile = $tmp . '/data/config.php';
[, , $b] = req('GET', "$base/");
check('a11y badge hidden by default', !str_contains($b, 'a11y-badge'));
fn_smoke_write_cfg($cfgFile, array_merge($config, ['accessibilityBadge' => true]));
[, , $b] = req('GET', "$base/");
check('a11y badge shows when enabled and links to the statement', str_contains($b, 'class="a11y-badge"') && str_contains($b, 'href="/accessibility"') && str_contains($b, 'WCAG'));
fn_smoke_write_cfg($cfgFile, $config);

// Footer copyright + curated social links: off by default, opt-in via config.
[, , $b] = req('GET', "$base/");
check('footer copyright + social hidden by default', !str_contains($b, 'footer-copyright') && !str_contains($b, 'social-links'));
fn_smoke_write_cfg($cfgFile, array_merge($config, [
    'copyright' => 'author',
    'copyrightStartYear' => '2021',
    'social' => ['mastodon' => 'https://mastodon.social/@fieldnote', 'github' => 'https://github.com/bpmore/fieldnote'],
]));
[, , $b] = req('GET', "$base/");
check('footer copyright renders name and a year range', str_contains($b, 'footer-copyright') && str_contains($b, 'Tester') && str_contains($b, '2021') && str_contains($b, date('Y')));
check('footer social links render rel=me with labelled icons', str_contains($b, 'class="social-links"') && str_contains($b, 'rel="me"') && str_contains($b, 'mastodon.social/@fieldnote') && str_contains($b, '>Mastodon</span>') && str_contains($b, '>GitHub</span>'));
fn_smoke_write_cfg($cfgFile, $config);

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
fn_smoke_write_cfg($cfgFile, array_merge($config, ['searchEnabled' => false]));
[, , $b] = req('GET', "$base/");
check('search box hidden when search is disabled', !str_contains($b, 'role="search"'));
[$s] = req('GET', "$base/search");
check('disabled search route 404s', $s === 404, "status $s");
fn_smoke_write_cfg($cfgFile, $config);

// With both bar items switched off the wrapper must not render at all. An
// empty labelled landmark is its own accessibility failure, and nothing
// asserted its absence — which is how the wrapper came to decide on config
// rather than on whether it had anything to put inside.
$patchCfgEarly = static function (array $over) use ($tmp): void {
    $path = $tmp . '/data/config.php';
    fn_smoke_write_cfg($path, array_merge((array) (require $path), $over));
};
$patchCfgEarly(['profilePage' => 'off', 'searchEnabled' => false]);
[, , $b] = req('GET', "$base/");
check(
    'the utility bar is absent when it would be empty',
    !str_contains($b, 'fn-utility'),
    'empty landmark rendered'
);
$patchCfgEarly(['searchEnabled' => true]);
[, , $b] = req('GET', "$base/");
check('the utility bar returns when one item is enabled', str_contains($b, 'fn-utility-inner') && str_contains($b, 'search-form'));

// --------------------------------------------------------- profile page --
[$s] = req('GET', "$base/about");
check('profile route 404s when off', $s === 404, "status $s");
[, , $b] = req('GET', "$base/");
check('no profile nav link when off', !str_contains($b, 'profile-link'));

fn_smoke_write_cfg($cfgFile, array_merge($config, ['profilePage' => 'about']));
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
fn_smoke_write_cfg($cfgFile, $config);

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

// The salt and the dedup set are one fact with one lifetime, so they live in
// one file. They used to be two, with two prune arms that did not match.
$statsDir = $tmp . '/data/stats';
$dayFile  = $statsDir . '/.day-' . date('Y-m-d') . '.json';
$dayState = is_file($dayFile) ? (array) json_decode((string) file_get_contents($dayFile), true) : [];
check(
    'one file holds the day salt and its dedup set',
    ($dayState['salt'] ?? '') !== '' && ($dayState['seen'] ?? []) !== [],
    'day file holds: ' . implode(', ', array_keys($dayState))
);

// Minting a new salt sweeps every older day file — including the two legacy
// shapes, whose salts this class no longer reads and must not leave lying
// around. Unconditional, so a mid-day rollover cannot strand one.
$stale = [
    $statsDir . '/.day-' . date('Y-m-d', time() - 86400) . '.json',
    $statsDir . '/.salt-' . date('Y-m-d', time() - 86400),
    $statsDir . '/.seen-' . date('Y-m-d', time() - 86400) . '.json',
    $statsDir . '/.salt-' . date('Y-m-d'),
    $statsDir . '/.seen-' . date('Y-m-d') . '.json',
];
foreach ($stale as $f) {
    file_put_contents($f, '{}');
}
unlink($dayFile); // the next view has no salt, so it mints one and prunes
req('GET', $base . $postPath, ['headers' => ['User-Agent: Mozilla/5.0 (SmokeTest C)']]);
$left = array_map('basename', array_values(array_filter($stale, 'is_file')));
check('minting a new salt sweeps every older day file', $left === [], 'left behind: ' . implode(', ', $left));
[, , $b] = req('GET', "$base/dashboard", $authed);
check('dashboard shows view counts', str_contains($b, 'cookie-less') && str_contains($b, 'Hello World'));

// ----------------------------------------- draft share links + revisions --

[, , $b] = req('GET', "$base/dashboard", $authed);
preg_match('#(/draft/\d+/\d+/[a-f0-9]{32})#', $b, $m);
check('dashboard offers a draft share link', isset($m[1]));
// Kept for the palette-pinning checks far below; $m is reused constantly.
$draftUrl = isset($m[1]) ? $base . $m[1] : '';
if (isset($m[1])) {
    [$s, , $b] = req('GET', $base . $m[1]); // logged out on purpose
    check('share link renders the draft logged-out', $s === 200 && str_contains($b, 'SENTINEL-DRAFT-BODY'), "status $s");
    // Flip the final character to one it is not: a token that already ends
    // in 0 would otherwise make this the untampered URL, and the check a coin
    // flip that passes fifteen runs in sixteen.
    [$s] = req('GET', $base . substr($m[1], 0, -1) . (str_ends_with($m[1], '0') ? '1' : '0'));
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

// A restore is a save to an already-public post, so it clears the same
// accessibility gate as publish and edit. Build a genuinely failing revision
// the only way the app allows: hide the post (drafts save freely), save a
// failing body under a recognisable title, then save clean text again so the
// failing version becomes the snapshot. Republish, and that revision is a
// loaded gun pointed at the public boundary.
$csrfFor = static function (string $path) use ($base, $authed): string {
    [, , $page] = req('GET', $base . $path, $authed);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $page, $cm);
    return $cm[1] ?? '';
};
req('POST', "$base/post/1/hide", $authed + ['body' => 'csrf_token=' . $csrfFor('/dashboard')]);
req('POST', "$base/post/1/edit", $authed + ['body' => http_build_query([
    'csrf_token'      => $csrfFor('/post/1/edit'),
    'blogPostTitle'   => 'Bad Revision Source',
    'blogPostAuthor'  => 'Tester',
    'blogPostContent' => "## Section\n\n#### Skips a level",
])]);
req('POST', "$base/post/1/edit", $authed + ['body' => http_build_query([
    'csrf_token'      => $csrfFor('/post/1/edit'),
    'blogPostTitle'   => 'Hello World',
    'blogPostAuthor'  => 'Tester',
    'blogPostContent' => 'A **published** fixture post.',
    'blogPostTags'    => 'notes, testing',
])]);
req('POST', "$base/post/1/publish", $authed + ['body' => 'csrf_token=' . $csrfFor('/dashboard')]);

[, , $b] = req('GET', "$base/post/1/edit", $authed);
preg_match('#Bad Revision Source.*?name="revision" value="(\d+)"#s', $b, $rm);
$badRev = $rm[1] ?? '';
check('a failing revision is available to restore', $badRev !== '' && str_contains($b, 'A **published** fixture post.'), 'index ' . ($badRev !== '' ? $badRev : '(none)'));

if ($badRev !== '') {
    // Both outcomes redirect to the editor, so the status cannot tell them
    // apart — the post's own text is the only honest evidence of a refusal.
    [$s] = req('POST', "$base/post/1/restore", $authed + ['body' => "revision=$badRev&csrf_token=" . $csrfFor('/post/1/edit')]);
    [, , $b] = req('GET', "$base/post/1/edit", $authed);
    check(
        'restoring a failing revision onto a live post is refused',
        $s === 302 && str_contains($b, 'A **published** fixture post.') && !str_contains($b, 'Skips a level'),
        "status $s, live text " . (str_contains($b, 'Skips a level') ? 'REPLACED' : 'intact')
    );
    check('the refused restore says why', str_contains($b, 'accessibility issues') && str_contains($b, 'Heading levels jump'));

    // The gate is the public boundary, not the restore button: the identical
    // restore succeeds once the post is a draft again.
    req('POST', "$base/post/1/hide", $authed + ['body' => 'csrf_token=' . $csrfFor('/dashboard')]);
    [$s] = req('POST', "$base/post/1/restore", $authed + ['body' => "revision=$badRev&csrf_token=" . $csrfFor('/post/1/edit')]);
    [, , $b] = req('GET', "$base/post/1/edit", $authed);
    check('the same restore is allowed while the post is a draft', $s === 302 && str_contains($b, 'Skips a level'), "status $s");

    // Restoring a revision whose text is already current must not snapshot a
    // no-op. Ten of those would push the real history out through the cap.
    req('POST', "$base/post/1/restore", $authed + ['body' => 'revision=0&csrf_token=' . $csrfFor('/post/1/edit')]);
    [, , $b] = req('GET', "$base/post/1/edit", $authed);
    $revCount = substr_count($b, 'name="revision"');
    req('POST', "$base/post/1/restore", $authed + ['body' => 'revision=0&csrf_token=' . $csrfFor('/post/1/edit')]);
    [, , $b] = req('GET', "$base/post/1/edit", $authed);
    check('restoring an already-current revision does not churn history', substr_count($b, 'name="revision"') === $revCount, "before $revCount, after " . substr_count($b, 'name="revision"'));

    // Leave post 1 exactly as the later checks expect it.
    req('POST', "$base/post/1/edit", $authed + ['body' => http_build_query([
        'csrf_token'      => $csrfFor('/post/1/edit'),
        'blogPostTitle'   => 'Hello World',
        'blogPostAuthor'  => 'Tester',
        'blogPostContent' => 'A **published** fixture post.',
        'blogPostTags'    => 'notes, testing',
    ])]);
    req('POST', "$base/post/1/publish", $authed + ['body' => 'csrf_token=' . $csrfFor('/dashboard')]);
}

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

// Same refusal, on a post that has a featured image: the editor must still show
// the current-image thumbnail. It is the one screen where the writer is being
// asked to fix the post, so losing sight of its image there is worst.
$imgs = new Store('images', $tmp . '/data/siteDatabase', ['timeout' => false]);
$imgRec = $imgs->insert(['url' => '/uploads/2025/01/fixture.jpg', 'path' => '2025/01/fixture.jpg']);
$p1 = $blog->findById(1);
$p1['image'] = $imgRec['_id'];
$blog->update($p1);
[, , $b] = req('GET', "$base/post/1/edit", $authed);
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s, , $b] = req('POST', "$base/post/1/edit", $authed + ['body' => http_build_query([
    'csrf_token'      => $m[1],
    'blogPostTitle'   => 'Hello World',
    'blogPostAuthor'  => 'Tester',
    'blogPostContent' => "## Section\n\n#### Skips a level",
    'blogPostTags'    => 'notes, testing',
])]);
check(
    'the refused editor still shows the current featured image',
    $s === 200 && str_contains($b, 'class="write-current-image"')
        && str_contains($b, '/uploads/2025/01/fixture.jpg'),
    "status $s, thumbnail " . (str_contains($b, 'write-current-image') ? 'present' : 'MISSING')
);
unset($p1['image']);
$blog->update($p1);
$imgs->deleteById($imgRec['_id']);
[, , $b] = req('GET', "$base/post/1/edit", $authed);

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
$visitor = $session();

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

[, , $b] = req('GET', "$base/settings", $authed);
check('settings shows passkey management', str_contains($b, 'id="passkeySection"') && str_contains($b, 'Fixture key'));
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s, , $b] = req('POST', "$base/settings/passkeys/options", $authed + ['body' => 'csrf_token=' . $m[1]]);
$json = json_decode($b, true);
check('passkey create options exclude existing credential', $s === 200 && count($json['publicKey']['excludeCredentials'] ?? []) === 1 && ($json['publicKey']['authenticatorSelection']['requireResidentKey'] ?? false) === true, "status $s");

// The credential store, exercised directly: add, remove and sign-count updates
// are only reachable behind a real WebAuthn ceremony, so nothing covered them.
// A scratch file keeps this clear of the fixture the routes above read.
$pkDir = $tmp . '/pk-scratch';
mkdir($pkDir, 0700, true);
$pk     = new Fieldnote\Passkeys($pkDir);
$pkFile = $pkDir . '/passkeys.json';
$pk->add('cred-one', 'PEM-ONE', 3, 'Laptop');
$pk->add('cred-two', 'PEM-TWO', 7, 'Phone');
check('two credentials are both stored', count($pk->list()) === 2, 'count ' . count($pk->list()));
check('credentials are returned in the order they were added', array_column($pk->list(), 'id') === ['cred-one', 'cred-two'], implode(',', array_column($pk->list(), 'id')));

// Registering the same authenticator twice used to append a second record,
// after which find/update/remove each disagreed about which one was "it".
$pk->add('cred-one', 'PEM-ONE-AGAIN', 4, 'Laptop again');
// Checked against the FILE, not the in-memory view: keying on read would
// collapse a duplicate anyway, so only the stored form proves add() replaced.
$pkStored = (array) (json_decode((string) file_get_contents($pkFile), true)['credentials'] ?? []);
check('re-adding the same id replaces rather than duplicates', count($pkStored) === 2, 'stored ' . count($pkStored));
check('the replacement is the record that is found', ($pk->find('cred-one')['publicKey'] ?? '') === 'PEM-ONE-AGAIN', 'got ' . ($pk->find('cred-one')['publicKey'] ?? 'null'));

check('the sign count updates on the named credential', $pk->updateSignCount('cred-two', 99) && ($pk->find('cred-two')['signCount'] ?? 0) === 99, 'got ' . ($pk->find('cred-two')['signCount'] ?? 'null'));
check('the other credential is left alone', ($pk->find('cred-one')['signCount'] ?? 0) === 4, 'got ' . ($pk->find('cred-one')['signCount'] ?? 'null'));
check('updating an unknown credential reports failure', $pk->updateSignCount('no-such-cred', 1) === false);

$pk->remove('cred-one');
check('removing one credential keeps the other', array_column($pk->list(), 'id') === ['cred-two'], implode(',', array_column($pk->list(), 'id')));
$pk->remove('cred-two');
check('removing the last credential clears the store', $pk->list() === [] && !$pk->enabled() && !is_file($pkFile), 'file still present: ' . var_export(is_file($pkFile), true));

// A pre-migration image record holds an absolute disk path. Three call sites
// used to re-decide what to do with one and reached three different answers:
// the export skipped it — silently dropping the featured image from the zip
// and from any re-import — while deletion and pruning resolved it happily.
$imgHandler = new Fieldnote\ImageHandler($tmp . '/uploads', '/uploads');
check(
    'a relative path resolves to a URL and a disk path',
    $imgHandler->publicUrl('2025/01/a.jpg') === '/uploads/2025/01/a.jpg'
        && $imgHandler->absolutePath('2025/01/a.jpg') === $tmp . '/uploads/2025/01/a.jpg',
    'got ' . $imgHandler->publicUrl('2025/01/a.jpg')
);
check(
    'a legacy absolute path inside uploads resolves the same way',
    $imgHandler->publicUrl($tmp . '/uploads/2025/01/a.jpg') === '/uploads/2025/01/a.jpg'
        && $imgHandler->absolutePath($tmp . '/uploads/2025/01/a.jpg') === $tmp . '/uploads/2025/01/a.jpg',
    'got ' . $imgHandler->publicUrl($tmp . '/uploads/2025/01/a.jpg')
);
check(
    'a path outside uploads resolves to nothing at all',
    $imgHandler->publicUrl('/etc/passwd') === '' && $imgHandler->absolutePath('/etc/passwd') === null,
    'a path outside the uploads dir was resolved'
);

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

// Every URL in the document has to agree with the one the actor is published
// at. They were built two different ways — 'url' through the router, the rest
// by concatenating a literal path — so under a non-empty basePath the actor
// advertised an id and an inbox that 404, and follows were dropped because the
// inbox compares a Follow's object against the id.
check(
    'the actor agrees with itself about where it lives',
    ($actor['id'] ?? '') === "$base/ap/actor"
        && ($actor['inbox'] ?? '') === "$base/ap/inbox"
        && ($actor['outbox'] ?? '') === "$base/ap/outbox"
        && ($actor['followers'] ?? '') === "$base/ap/followers"
        && ($actor['publicKey']['owner'] ?? '') === "$base/ap/actor"
        && ($actor['publicKey']['id'] ?? '') === "$base/ap/actor#main-key",
    'id ' . ($actor['id'] ?? '?') . ', inbox ' . ($actor['inbox'] ?? '?') . ', key ' . ($actor['publicKey']['id'] ?? '?')
);
// And webfinger has to point at that same id, or a remote server looking the
// handle up never reaches the actor at all.
check(
    'webfinger points at the actor id',
    ($json['links'][0]['href'] ?? '') === ($actor['id'] ?? 'x'),
    'webfinger ' . ($json['links'][0]['href'] ?? '?') . ' vs id ' . ($actor['id'] ?? '?')
);

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

    // The form and the dispatch come from one table, so an id can never be
    // offered without a converter behind it, nor handled without being
    // offered. Every option the form shows must be one the route accepts.
    [, , $b] = req('GET', "$base/admin/import", $authed);
    preg_match_all('/<option value="([a-z-]+)"/', $b, $optMatch);
    $offered = $optMatch[1] ?? [];
    $handled = array_filter(
        $offered,
        static fn (string $id): bool => $id === 'auto' || Fieldnote\Importers::isKnown($id)
    );
    check(
        'every source the import form offers is one the registry handles',
        count($offered) === 13 && count($handled) === count($offered),
        count($offered) . ' offered, ' . count($handled) . ' handled'
    );

    // An id the registry does not know degrades to sniffing rather than
    // failing: the form only offers known ones, so anything else is stale.
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s, , $b] = req('POST', "$base/admin/import", $authed + ['body' => [
        'csrf_token'   => $m[1],
        'importSource' => 'no-such-platform',
        'importZip'    => new CURLFile("$tmp/export.zip", 'application/zip', 'export.zip'),
    ]]);
    check('an unknown source falls back to auto-detect', $s === 200 && str_contains($b, 'Nothing has been written'), "status $s");

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

    // The budget has to count POSTS, not archive members. exportZip writes an
    // image member before each post that has one, so on a blog with cover
    // images the old member-index ceiling was reached at roughly half the post
    // count and everything past it vanished — no error, no "nothing found",
    // just fewer posts than you exported. Padding the front of the archive
    // pushes the posts past that ceiling and makes the same defect exact.
    $padZip = "$tmp/padded.zip";
    $z = new ZipArchive();
    $z->open($padZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    for ($i = 0; $i < 2010; $i++) {
        $z->addFromString(sprintf('uploads/pad-%04d.bin', $i), 'x');
    }
    for ($i = 0; $i < 5; $i++) {
        $z->addFromString(
            sprintf('posts/2024-05-%02d-padded-%d.md', $i + 1, $i),
            "---\ntitle: Padded $i\n---\n\nPast the old ceiling.\n"
        );
    }
    $z->close();

    [, , $b] = req('GET', "$base/dashboard", $authed);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s, , $b] = req('POST', "$base/admin/import", $authed + ['body' => [
        'csrf_token' => $m[1],
        'importZip'  => new CURLFile($padZip, 'application/zip', 'padded.zip'),
    ]]);
    check(
        'posts past the member ceiling are still found',
        $s === 200 && substr_count($b, 'padded-') === 5,
        'found ' . substr_count($b, 'padded-') . ' of 5'
    );
    // And the dry run must promise exactly what the import delivers.
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s] = req('POST', "$base/admin/import/confirm", $authed + ['body' => 'csrf_token=' . $m[1]]);
    [, , $b] = req('GET', "$base/dashboard", $authed);
    check(
        'the dry run promised exactly what the import created',
        $s === 302 && str_contains($b, 'Import finished: 5 created'),
        'dashboard did not report 5 created'
    );

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

    // Substack: a zip of posts.csv + posts/<id>.<slug>.html, auto-detected.
    $ssZip = "$tmp/substack.zip";
    $z = new ZipArchive();
    $z->open($ssZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('posts.csv', "post_id,post_date,is_published,title,subtitle\n101,2025-02-10,true,\"My First, Newsletter\",\"A short deck\"\n102,2025-03-01,true,Second Post,\n");
    $z->addFromString('posts/101.my-first-newsletter.html', '<h2>Intro</h2><p>Hello from <strong>Substack</strong>.</p>');
    $z->addFromString('posts/102.second-post.html', '<p>Second body. <a href="https://e.com">read more</a></p>');
    $z->close();
    [, , $b] = req('GET', "$base/admin/import", $authed);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s, , $b] = req('POST', "$base/admin/import", $authed + ['body' => [
        'csrf_token'   => $m[1],
        'importSource' => 'auto',
        'importZip'    => new CURLFile($ssZip, 'application/zip', 'substack.zip'),
    ]]);
    check('substack zip auto-detected; dry-run flags a11y and writes nothing', $s === 200 && str_contains($b, 'my-first-newsletter') && str_contains($b, 'second-post') && str_contains($b, 'to fix') && str_contains($b, 'Nothing has been written'), "status $s");
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s] = req('POST', "$base/admin/import/confirm", $authed + ['body' => 'csrf_token=' . $m[1]]);
    check('substack import creates drafts', $s === 302, "status $s");
    [$s, , $b] = req('GET', "$base/2025/02/my-first-newsletter", $authed);
    check('substack body and subtitle deck rendered', $s === 200 && str_contains($b, 'A short deck') && str_contains($b, '<strong>Substack</strong>'), "status $s");

    // Ghost: a single JSON export, auto-detected; tags + author joined by id.
    // An entry with no title: the dry run listed it blank while the import
    // titled the post with its slug, because each pass read the entry with its
    // own defaults. Whatever the dry run promises, the import has to deliver.
    $untitledJson = "$tmp/ghost-untitled.json";
    file_put_contents($untitledJson, json_encode(['db' => [['meta' => ['exported_on' => 1700000000000, 'version' => '5.0'], 'data' => [
        'posts' => [
            ['id' => '9', 'slug' => 'untitled-entry', 'type' => 'post', 'status' => 'published',
             'published_at' => '2025-02-02T08:00:00.000Z', 'html' => '<p>No title on this one.</p>'],
        ],
    ]]]]));
    [, , $b] = req('GET', "$base/admin/import", $authed);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s, , $b] = req('POST', "$base/admin/import", $authed + ['body' => [
        'csrf_token'   => $m[1],
        'importSource' => 'ghost',
        'importZip'    => new CURLFile($untitledJson, 'application/json', 'ghost-untitled.json'),
    ]]);
    // The TITLE cell, not the slug cell — the slug column shows the same
    // string either way, so matching the page anywhere proves nothing.
    preg_match('#<td>([^<]*)<span class="badge#', $b, $dryTitle);
    check(
        'an untitled entry gets its slug as a title in the dry run',
        $s === 200 && trim($dryTitle[1] ?? '') === 'untitled-entry',
        'dry-run title cell was "' . trim($dryTitle[1] ?? '') . '"'
    );
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    req('POST', "$base/admin/import/confirm", $authed + ['body' => 'csrf_token=' . $m[1]]);
    [, , $b] = req('GET', "$base/dashboard", $authed);
    check(
        'the import titles it the same way the dry run did',
        str_contains($b, trim($dryTitle[1] ?? '')) && trim($dryTitle[1] ?? '') !== '',
        'dashboard does not list the created post as "' . trim($dryTitle[1] ?? '') . '"'
    );
    $ghostJson = "$tmp/ghost.json";
    file_put_contents($ghostJson, json_encode(['db' => [['meta' => ['exported_on' => 1700000000000, 'version' => '5.0'], 'data' => [
        'posts' => [
            ['id' => '1', 'title' => 'Ghost Post', 'slug' => 'ghost-post', 'type' => 'post', 'status' => 'published', 'published_at' => '2025-01-15T08:00:00.000Z',
             'html' => '<h2>Hi</h2><p>From <strong>Ghost</strong>. See <a href="https://e.com">read more</a>.</p>'],
            ['id' => '2', 'title' => 'A Page', 'slug' => 'a-page', 'type' => 'page', 'html' => '<p>page</p>'],
        ],
        'tags'          => [['id' => 't1', 'name' => 'Tech']],
        'posts_tags'    => [['post_id' => '1', 'tag_id' => 't1']],
        'users'         => [['id' => 'u1', 'name' => 'Brent']],
        'posts_authors' => [['post_id' => '1', 'author_id' => 'u1']],
    ]]]]));
    [, , $b] = req('GET', "$base/admin/import", $authed);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s, , $b] = req('POST', "$base/admin/import", $authed + ['body' => [
        'csrf_token'   => $m[1],
        'importSource' => 'auto',
        'importZip'    => new CURLFile($ghostJson, 'application/json', 'ghost.json'),
    ]]);
    check('ghost json auto-detected; dry-run flags a11y, writes nothing, skips pages', $s === 200 && str_contains($b, 'ghost-post') && !str_contains($b, 'a-page') && str_contains($b, 'to fix') && str_contains($b, 'Nothing has been written'), "status $s");
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s] = req('POST', "$base/admin/import/confirm", $authed + ['body' => 'csrf_token=' . $m[1]]);
    check('ghost import creates a draft', $s === 302, "status $s");
    [$s, , $b] = req('GET', "$base/2025/01/ghost-post", $authed);
    check('ghost body converted to markdown and rendered', $s === 200 && str_contains($b, 'From') && str_contains($b, '<strong>Ghost</strong>'), "status $s");

    // WriteFreely: JSON export, markdown-native body, inline #hashtags as tags.
    $wfJson = "$tmp/wf.json";
    file_put_contents($wfJson, json_encode([
        ['id' => 'a1', 'slug' => 'wf-post', 'title' => 'WF Post', 'appearance' => 'norm', 'rtl' => false,
         'created' => '2025-07-01T00:00:00Z', 'body' => "## Hi\n\nFrom **WriteFreely**. See [read more](https://e.com). #fediverse"],
    ]));
    [, , $b] = req('GET', "$base/admin/import", $authed);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s, , $b] = req('POST', "$base/admin/import", $authed + ['body' => [
        'csrf_token'   => $m[1],
        'importSource' => 'auto',
        'importZip'    => new CURLFile($wfJson, 'application/json', 'wf.json'),
    ]]);
    check('writefreely json auto-detected; dry-run flags a11y, writes nothing', $s === 200 && str_contains($b, 'wf-post') && str_contains($b, 'to fix') && str_contains($b, 'Nothing has been written'), "status $s");
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s] = req('POST', "$base/admin/import/confirm", $authed + ['body' => 'csrf_token=' . $m[1]]);
    check('writefreely import creates a draft', $s === 302, "status $s");
    [$s, , $b] = req('GET', "$base/2025/07/wf-post", $authed);
    check('writefreely markdown body rendered + hashtag tags', $s === 200 && str_contains($b, '<strong>WriteFreely</strong>') && str_contains($b, 'fediverse'), "status $s");

    // Medium: zip of posts/*.html h-entry microformats, auto-detected.
    $mdZip = "$tmp/medium.zip";
    $z = new ZipArchive();
    $z->open($mdZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('posts/2025-08-10_My-Medium-Post-abc123def456.html',
        '<!DOCTYPE html><html><head><title>My Medium Post – Medium</title></head><body>'
        . '<article class="h-entry"><h1 class="p-name">My Medium Post</h1>'
        . '<section data-field="subtitle" class="p-summary">A deck</section>'
        . '<section data-field="body" class="e-content"><p>From <strong>Medium</strong>. <a href="https://e.com">read more</a></p></section>'
        . '<footer><a href="https://medium.com/@b/my-medium-post-abc123def456" class="p-canonical">c</a>'
        . '<time class="dt-published" datetime="2025-08-10T12:00:00.000Z">x</time></footer></article></body></html>');
    $z->close();
    [, , $b] = req('GET', "$base/admin/import", $authed);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s, , $b] = req('POST', "$base/admin/import", $authed + ['body' => [
        'csrf_token'   => $m[1],
        'importSource' => 'auto',
        'importZip'    => new CURLFile($mdZip, 'application/zip', 'medium.zip'),
    ]]);
    check('medium zip auto-detected; dry-run flags a11y, writes nothing', $s === 200 && str_contains($b, 'my-medium-post') && str_contains($b, 'to fix') && str_contains($b, 'Nothing has been written'), "status $s");
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s] = req('POST', "$base/admin/import/confirm", $authed + ['body' => 'csrf_token=' . $m[1]]);
    check('medium import creates a draft', $s === 302, "status $s");
    [$s, , $b] = req('GET', "$base/2025/08/my-medium-post", $authed);
    check('medium body + subtitle deck converted and rendered', $s === 200 && str_contains($b, 'A deck') && str_contains($b, '<strong>Medium</strong>'), "status $s");

    // Blogger: Atom export, kind-filtered (posts only), labels as tags.
    $bgFile = "$tmp/blogger.xml";
    file_put_contents($bgFile,
        '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom">'
        . '<generator uri="http://www.blogger.com">Blogger</generator><entry>'
        . '<category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/blogger/2008/kind#post"/>'
        . '<category scheme="http://www.blogger.com/atom/ns#" term="travel"/>'
        . '<published>2025-09-05T10:00:00.000-07:00</published><title type="text">Blogger Post</title>'
        . '<content type="html">&lt;p&gt;From &lt;b&gt;Blogger&lt;/b&gt;. &lt;a href="https://e.com"&gt;read more&lt;/a&gt;&lt;/p&gt;</content>'
        . '<link rel="alternate" type="text/html" href="https://x.blogspot.com/2025/09/blogger-post.html"/>'
        . '<author><name>Brent</name></author></entry><entry>'
        . '<category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/blogger/2008/kind#comment"/>'
        . '<title>a comment</title><content type="html">spam</content></entry></feed>');
    [, , $b] = req('GET', "$base/admin/import", $authed);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s, , $b] = req('POST', "$base/admin/import", $authed + ['body' => [
        'csrf_token'   => $m[1],
        'importSource' => 'auto',
        'importZip'    => new CURLFile($bgFile, 'text/xml', 'blogger.xml'),
    ]]);
    check('blogger atom auto-detected; dry-run flags a11y, writes nothing, skips comments', $s === 200 && str_contains($b, 'blogger-post') && !str_contains($b, 'a comment') && str_contains($b, 'to fix') && str_contains($b, 'Nothing has been written'), "status $s");
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s] = req('POST', "$base/admin/import/confirm", $authed + ['body' => 'csrf_token=' . $m[1]]);
    check('blogger import creates a draft', $s === 302, "status $s");
    [$s, , $b] = req('GET', "$base/2025/09/blogger-post", $authed);
    check('blogger body converted and tag mapped', $s === 200 && str_contains($b, '<strong>Blogger</strong>') && str_contains($b, 'travel'), "status $s");

    // Notion: Markdown zip; title line stripped, property block -> tags/date.
    $ntZip = "$tmp/notion.zip";
    $z = new ZipArchive();
    $z->open($ntZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('Export-abc/My Notion Post 1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d.md',
        "# My Notion Post\n\nTags: Tech, Life\nPublished: January 5, 2025\n\nFrom **Notion**. See [read more](https://e.com).\n");
    $z->close();
    [, , $b] = req('GET', "$base/admin/import", $authed);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s, , $b] = req('POST', "$base/admin/import", $authed + ['body' => [
        'csrf_token'   => $m[1],
        'importSource' => 'auto',
        'importZip'    => new CURLFile($ntZip, 'application/zip', 'notion.zip'),
    ]]);
    check('notion zip auto-detected; dry-run flags a11y, writes nothing', $s === 200 && str_contains($b, 'my-notion-post') && str_contains($b, 'to fix') && str_contains($b, 'Nothing has been written'), "status $s");
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s] = req('POST', "$base/admin/import/confirm", $authed + ['body' => 'csrf_token=' . $m[1]]);
    check('notion import creates a draft', $s === 302, "status $s");
    [$s, , $b] = req('GET', "$base/2025/01/my-notion-post", $authed);
    check('notion body rendered + property tags lifted', $s === 200 && str_contains($b, '<strong>Notion</strong>') && str_contains($b, '/tag/tech'), "status $s");

    // DEV (dev.to): markdown-native JSON, tag_list -> tags.
    $dtJson = "$tmp/devto.json";
    file_put_contents($dtJson, json_encode([
        ['title' => 'Dev Post', 'slug' => 'dev-post-abc', 'tag_list' => ['webdev', 'php'],
         'published_at' => '2025-10-01T00:00:00Z', 'user' => ['name' => 'Brent'],
         'body_markdown' => "## Hi\n\nFrom **dev.to**. See [read more](https://e.com)."],
    ]));
    [, , $b] = req('GET', "$base/admin/import", $authed);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s, , $b] = req('POST', "$base/admin/import", $authed + ['body' => [
        'csrf_token'   => $m[1],
        'importSource' => 'auto',
        'importZip'    => new CURLFile($dtJson, 'application/json', 'devto.json'),
    ]]);
    check('devto json auto-detected; dry-run flags a11y, writes nothing', $s === 200 && str_contains($b, 'dev-post-abc') && str_contains($b, 'to fix') && str_contains($b, 'Nothing has been written'), "status $s");
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s] = req('POST', "$base/admin/import/confirm", $authed + ['body' => 'csrf_token=' . $m[1]]);
    check('devto import creates a draft', $s === 302, "status $s");
    [$s, , $b] = req('GET', "$base/2025/10/dev-post-abc", $authed);
    check('devto markdown body rendered + tag_list mapped', $s === 200 && str_contains($b, '<strong>dev.to</strong>') && str_contains($b, '/tag/webdev'), "status $s");

    // Hashnode: markdown-native JSON, tag objects -> tags, brief deck.
    $hnJson = "$tmp/hashnode.json";
    file_put_contents($hnJson, json_encode([
        ['title' => 'Hashnode Post', 'slug' => 'hashnode-post', 'brief' => 'A short brief',
         'tags' => [['name' => 'Tech', 'slug' => 'tech'], ['name' => 'Web', 'slug' => 'web']],
         'publishedAt' => '2025-11-01T00:00:00Z', 'author' => ['name' => 'Brent'],
         'contentMarkdown' => "## Hi\n\nFrom **Hashnode**. See [read more](https://e.com)."],
    ]));
    [, , $b] = req('GET', "$base/admin/import", $authed);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s, , $b] = req('POST', "$base/admin/import", $authed + ['body' => [
        'csrf_token'   => $m[1],
        'importSource' => 'auto',
        'importZip'    => new CURLFile($hnJson, 'application/json', 'hashnode.json'),
    ]]);
    check('hashnode json auto-detected; dry-run flags a11y, writes nothing', $s === 200 && str_contains($b, 'hashnode-post') && str_contains($b, 'to fix') && str_contains($b, 'Nothing has been written'), "status $s");
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s] = req('POST', "$base/admin/import/confirm", $authed + ['body' => 'csrf_token=' . $m[1]]);
    check('hashnode import creates a draft', $s === 302, "status $s");
    [$s, , $b] = req('GET', "$base/2025/11/hashnode-post", $authed);
    check('hashnode body + brief deck rendered, tags mapped', $s === 200 && str_contains($b, 'A short brief') && str_contains($b, '<strong>Hashnode</strong>') && str_contains($b, '/tag/tech'), "status $s");

    // Squarespace exports WordPress WXR — the "squarespace" pick routes there.
    $sqFile = "$tmp/squarespace.xml";
    file_put_contents($sqFile,
        '<?xml version="1.0"?><rss version="2.0"'
        . ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
        . ' xmlns:wp="http://wordpress.org/export/1.2/"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><item>'
        . '<title>Square Post</title><content:encoded><![CDATA[<p>From <strong>Squarespace</strong>.</p>]]></content:encoded>'
        . '<wp:post_name>sq-imported</wp:post_name><wp:post_type>post</wp:post_type>'
        . '<wp:status>publish</wp:status><wp:post_date_gmt>2025-12-01 10:00:00</wp:post_date_gmt>'
        . '</item></channel></rss>');
    [, , $b] = req('GET', "$base/admin/import", $authed);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
    [$s, , $b] = req('POST', "$base/admin/import", $authed + ['body' => [
        'csrf_token'   => $m[1],
        'importSource' => 'squarespace',
        'importZip'    => new CURLFile($sqFile, 'text/xml', 'squarespace.xml'),
    ]]);
    check('squarespace pick routes through the WordPress WXR converter', $s === 200 && str_contains($b, 'sq-imported') && str_contains($b, 'Nothing has been written'), "status $s");
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

// ------------------------------------------------------- theme of the day --
// Rewrite the served config in place; the next request reads it fresh.
// Patch the config that is actually on disk. The two closures this replaces
// merged over two different in-memory snapshots, so whether a key written by
// the app survived a harness write depended on which one you called -- and one
// of them silently reset paletteOverrides, which the checks below depend on.
$patchCfg = static function (array $over) use ($tmp): void {
    $path = $tmp . '/data/config.php';
    fn_smoke_write_cfg($path, array_merge((array) (require $path), $over));
};
// Expected daily pick, computed independently of the helper: sorted installed
// themes, indexed by the UTC epoch-day (matches fn_theme_of_day for tz=UTC).
$tNames = [];
foreach (glob($root . '/templates/*', GLOB_ONLYDIR) ?: [] as $d) {
    if (is_file("$d/home.php") && is_file("$d/post.php")) {
        $tNames[] = basename($d);
    }
}
sort($tNames);
// The pick is a function of the UTC day: computed here, rendered by the server
// a moment later. A run that straddles midnight compares one day's expectation
// against the next day's page — a red suite that says nothing about the code,
// and one that only ever appears in the minutes after 00:00 UTC. Pair each
// expectation with the request it describes, and retry if the day moves
// underneath. Two seconds either side of a boundary really does change the
// answer: gazette to gothic, zen to mono.
$sameDay = static function (callable $fn) {
    $result = null;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $dayBefore = (int) floor(time() / 86400);
        $result    = $fn();
        if ((int) floor(time() / 86400) === $dayBefore) {
            return $result;
        }
    }
    return $result;
};

$patchCfg(['template' => 'gazette', 'themeOfDay' => false, 'timezone' => 'UTC']);
[$s, , $b] = req('GET', "$base/", []);
check('themeOfDay off uses the fixed template', $s === 200 && str_contains($b, '/themes/gazette/theme.css'), "status $s");

$patchCfg(['template' => 'gazette', 'themeOfDay' => true, 'timezone' => 'UTC']);
[$s, $b, $s2, $b2, $today] = $sameDay(static function () use ($base, $tNames): array {
    $today = $tNames[((int) floor(time() / 86400)) % count($tNames)];
    [$s, , $b]   = req('GET', "$base/", []);
    [$s2, , $b2] = req('GET', "$base/", []);
    return [$s, $b, $s2, $b2, $today];
});
check('themeOfDay on renders the daily theme', $s === 200 && str_contains($b, "/themes/$today/theme.css"), "today=$today status $s");
check('themeOfDay is stable within the day', $s2 === 200 && str_contains($b2, "/themes/$today/theme.css"));

// Independent expected-pick for a given pool + cadence (mirrors fn_theme_of_day, tz=UTC).
$rotPick = function (array $pool, int $days) use ($tNames): string {
    $set = $pool ? array_values(array_intersect($tNames, $pool)) : $tNames;
    $period = (int) floor(((int) floor(time() / 86400)) / max(1, $days));
    return $set[$period % count($set)];
};

// Curated pool: rotation is restricted to the selected themes.
$pool = ['mono', 'zen'];
$patchCfg(['template' => 'gazette', 'themeOfDay' => true, 'timezone' => 'UTC', 'themePool' => $pool, 'themeRotateDays' => 1]);
[$s, $b, $expPool] = $sameDay(static function () use ($base, $rotPick, $pool): array {
    $expPool = $rotPick($pool, 1);
    [$s, , $b] = req('GET', "$base/", []);
    return [$s, $b, $expPool];
});
check('themeOfDay pool restricts to selected themes', $s === 200 && in_array($expPool, $pool, true) && str_contains($b, "/themes/$expPool/theme.css"), "exp=$expPool status $s");

// Weekly cadence: the pick follows the 7-day period, not the day.
$patchCfg(['template' => 'gazette', 'themeOfDay' => true, 'timezone' => 'UTC', 'themePool' => $pool, 'themeRotateDays' => 7]);
[$s, $b, $expWk] = $sameDay(static function () use ($base, $rotPick, $pool): array {
    $expWk = $rotPick($pool, 7);
    [$s, , $b] = req('GET', "$base/", []);
    return [$s, $b, $expWk];
});
check('themeOfDay weekly cadence picks the period theme', $s === 200 && str_contains($b, "/themes/$expWk/theme.css"), "exp=$expWk status $s");

// Restore the fixture default so later sections are unaffected.
$patchCfg(['template' => 'gazette', 'themeOfDay' => false, 'timezone' => 'UTC', 'themePool' => [], 'themeRotateDays' => 1]);

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
[, , $css] = req('GET', "$base/palette.css?t=gazette");
// The stored palette is the whole validated set now, so the light block no
// longer happens to begin at --text. Assert the tokens are there rather
// than the order they were written in.
check(
    'suggested palette saves and renders',
    $s === 302
        && str_contains($b2, 'palette.css?')
        && str_contains($css, '@media (prefers-color-scheme: light){:root{')
        && str_contains($css, '--text:'),
    "status $s"
);
// Every required token is persisted, which is what makes the stored palette
// the same one the contrast matrix passed.
$storedPalette = (array) ((require $tmp . '/data/config.php')['paletteOverrides'] ?? []);
check(
    'the whole validated palette is stored, not a diff',
    count($storedPalette['light'] ?? []) === count(Fieldnote\Wcag::REQUIRED_TOKENS)
        && count($storedPalette['dark'] ?? []) === count(Fieldnote\Wcag::REQUIRED_TOKENS),
    'light ' . count($storedPalette['light'] ?? []) . ', dark ' . count($storedPalette['dark'] ?? []) . ' of ' . count(Fieldnote\Wcag::REQUIRED_TOKENS)
);

// Rotation + palette: overrides are keyed to the theme they were authored
// for (gazette, above). On a rotation day showing a DIFFERENT theme they
// must not leak in; on a day showing the authored theme they still apply.
// A single-theme pool makes the day's pick deterministic.

$patchCfg(['themeOfDay' => true, 'themePool' => ['mono'], 'themeRotateDays' => 1]);
[, , $b2] = req('GET', "$base/");
check('rotation onto another theme suppresses palette overrides', str_contains($b2, '/themes/mono/theme.css') && !str_contains($b2, 'palette.css?'));
$patchCfg(['themeOfDay' => true, 'themePool' => ['gazette'], 'themeRotateDays' => 1]);
[, , $b2] = req('GET', "$base/");
check('rotation onto the authored theme keeps palette overrides', str_contains($b2, '/themes/gazette/theme.css') && str_contains($b2, 'palette.css?'));
// The stylesheet must be keyed to the theme that linked it, not re-resolved
// against the clock — otherwise a page rendered either side of a rotation
// boundary can link a sheet that disagrees with it.
$patchCfg(['themeOfDay' => true, 'themePool' => ['gazette'], 'themeRotateDays' => 1]);
[, , $b2] = req('GET', "$base/");
preg_match('#palette\.css\?t=([a-z0-9-]+)&amp;v=#', $b2, $pm);
check('palette link names the theme it was rendered for', ($pm[1] ?? '') === 'gazette', 'got ' . ($pm[1] ?? '(none)'));
[, , $cssMine]  = req('GET', "$base/palette.css?t=gazette");
[, , $cssOther] = req('GET', "$base/palette.css?t=mono");
check('palette stylesheet honours the requested theme', $cssMine !== '' && $cssOther === '', 'mine ' . strlen($cssMine) . 'b, other ' . strlen($cssOther) . 'b');

// A draft share deliberately renders the STORED theme, ignoring the rotation.
// Its palette must follow the theme on the page, not the day's pick.
$patchCfg(['themeOfDay' => true, 'themePool' => ['mono'], 'themeRotateDays' => 1]);
[, , $b2] = req('GET', "$base/");
check('public page rotated away from the authored theme', str_contains($b2, '/themes/mono/theme.css'));
[, , $bDraft] = req('GET', $draftUrl);
check(
    'draft share keeps the authored palette while rotation points elsewhere',
    str_contains($bDraft, '/themes/gazette/theme.css') && str_contains($bDraft, 'palette.css?t=gazette'),
    'draft head did not pair the stored theme with its palette'
);

// Rotation off, overrides left intact for the reset check below. Spelled out
// because a patch only changes the keys it names.
$patchCfg(['themeOfDay' => false, 'themePool' => [], 'themeRotateDays' => 1]);

// Reset restores stock rendering.
[, , $b] = req('GET', "$base/admin/palette", $authed);
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s] = req('POST', "$base/admin/palette", $authed + ['body' => 'paletteAction=reset&csrf_token=' . $m[1]]);
[, , $b2] = req('GET', "$base/");
check('palette reset clears overrides', $s === 302 && !str_contains($b2, 'palette.css?'), "status $s");

// ------------------------------------------------- settings validation --

// The gate has to run on what gets STORED, not on what was posted: the
// normalizers between the two can empty or rewrite a value. A rejected save
// redirects to /settings and a good one to /dashboard, so the target is the
// only honest signal — and the stored config is checked either way.
$settingsBody = static function (array $over) use ($csrfFor): array {
    return ['body' => http_build_query(array_merge([
        'csrf_token'   => $csrfFor('/settings'),
        'blogName'     => 'Smoke',
        'blogInfo'     => 'Smoke-test fixture',
        'blogDomain'   => $base, // the test server itself: a real domain would 301 every later request away
        'blogTemplate' => 'gazette',
        'blogTimezone' => 'UTC',
        'blogI18N'     => 'en_US',
        'blogPostsPerPage' => '6',
    ], $over))];
};
foreach ([
    'whitespace-only name'   => ['blogName' => '   '],
    'malformed domain'       => ['blogDomain' => 'not a url'],
    'unknown template'       => ['blogTemplate' => 'no-such-theme'],
    'unknown timezone'       => ['blogTimezone' => 'Mars/Phobos'],
] as $label => $over) {
    [$s, $h] = req('POST', "$base/settings", $authed + $settingsBody($over));
    check(
        "settings save rejects a $label",
        $s === 302 && str_contains($h['location'] ?? '', '/settings'),
        "status $s -> " . ($h['location'] ?? '(none)')
    );
}
// The admin must still be able to reach 2FA and passkeys — the whitespace-name
// save, had it landed, would have blanked the site name and hidden both.
[, , $b] = req('GET', "$base/settings", $authed);
check('a rejected save left the site configured', str_contains($b, 'Two-factor') && str_contains($b, 'Passkeys'), 'settings page fell back to first-run');

// ----------------------------------------- session epoch (password change) --

// Changing the password must log out every OTHER session while the session
// that changed it stays in. Run last: it rewrites the fixture config.
// Syndication validators must move when a settings change alters what the
// feeds render. The save below sets `domain`, which rewrites every <link>,
// <guid>, <loc> and feed_url — so an unchanged ETag here means subscribers
// would hold a feed pointing at the old address indefinitely.
$feedEtagBefore = [];
foreach (['/feed', '/feed.json', '/sitemap.xml'] as $synPath) {
    [, $hSyn] = req('GET', $base . $synPath);
    $feedEtagBefore[$synPath] = $hSyn['etag'] ?? '';
}

[, , $b] = req('GET', "$base/settings", $authed);
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s, $hSave] = req('POST', "$base/settings", $authed + ['body' => http_build_query([
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
check(
    'password change keeps the changing session',
    $s === 302 && str_contains($hSave['location'] ?? '', '/dashboard') && $s2 === 200,
    "save $s -> " . ($hSave['location'] ?? '(none)') . ", dashboard $s2"
);

foreach ($feedEtagBefore as $synPath => $etagBefore) {
    [$sSyn, $hSyn] = req('GET', $base . $synPath);
    $etagAfter = $hSyn['etag'] ?? '';
    check(
        "settings change busts the $synPath validator",
        $sSyn === 200 && $etagBefore !== '' && $etagAfter !== '' && $etagAfter !== $etagBefore,
        "status $sSyn, before " . ($etagBefore ?: '(none)') . ", after " . ($etagAfter ?: '(none)')
    );
    // And the stale validator must no longer satisfy a revalidating reader.
    [$sSyn] = req('GET', $base . $synPath, ['headers' => ['If-None-Match: ' . $etagBefore]]);
    check("stale $synPath validator revalidates to 200", $sSyn === 200, "status $sSyn");
}

[$s, $h] = req('GET', "$base/dashboard", $session('isAuthenticated|b:1;'));
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

// --------------------------------------------------------- pagination --
// The published count drives $numPages, and it used to come from a cache
// file that six call sites had to remember to delete. Nothing tested that
// pagination tracked reality, which is exactly what would break if one of
// them was ever missed. One post per page makes the boundary the count.
$patchCfg(['postsPerPage' => 1]);
$publishedNow = static function () use ($tmp): int {
    return count((new Store('blog', $tmp . '/data/siteDatabase', ['timeout' => false]))
        ->findBy(['draft', '=', false]));
};
$lastPage = $publishedNow();
[$s] = req('GET', "$base/$lastPage");
check('pagination reaches the last page', $s === 200, "page $lastPage status $s");
[$s] = req('GET', $base . '/' . ($lastPage + 1));
check('pagination stops after the last page', $s === 404, 'page ' . ($lastPage + 1) . " status $s");

// Hiding a post has to move the boundary on the very NEXT request.
req('POST', "$base/post/1/hide", $authed + ['body' => 'csrf_token=' . $csrfFor('/dashboard')]);
[$s] = req('GET', "$base/$lastPage");
check('hiding a post shrinks pagination immediately', $s === 404, "page $lastPage status $s");
req('POST', "$base/post/1/publish", $authed + ['body' => 'csrf_token=' . $csrfFor('/dashboard')]);
[$s] = req('GET', "$base/$lastPage");
check('publishing a post grows pagination immediately', $s === 200, "page $lastPage status $s");
$patchCfg(['postsPerPage' => 6]);

// --------------------------------------------------- migration watermarks --

// A watermark states a fact about the store, so it lives with the store. Kept
// a directory up, restoring siteDatabase/ from an older backup left the
// watermarks behind and the un-migrated store was never migrated.
$stillOld = [];
$moved    = [];
foreach (['.slugs-v1', '.pubdate-v1', '.imgrel-v1'] as $marker) {
    if (is_file($tmp . '/data/' . $marker)) {
        $stillOld[] = $marker;
    }
    if (is_file($tmp . '/data/siteDatabase/' . $marker)) {
        $moved[] = $marker;
    }
}
check('watermarks live with the store they describe', count($moved) === 3, 'in siteDatabase: ' . implode(', ', $moved));
check('the old watermark is honoured once, then removed', $stillOld === [], 'left at the old path: ' . implode(', ', $stillOld));

// And the store's own watermark is what gates the migration: remove it, as
// restoring an older siteDatabase/ would, and the migration runs again.
$rec = $blog->insert([
    'title' => 'Unslugged Legacy Post', 'slug' => '', 'author' => 'Tester',
    'date' => time(), 'publishedAt' => 0, 'draft' => true,
    'content' => 'A pre-3.1 fixture.', 'password' => '', 'tags' => [],
]);
unlink($tmp . '/data/siteDatabase/.slugs-v1');
req('GET', "$base/");
$migrated = (string) ($blog->findById((int) $rec['_id'])['slug'] ?? '');
check('a store restored without its watermark is migrated again', $migrated === 'unslugged-legacy-post', "slug is '$migrated'");
$blog->deleteById((int) $rec['_id']);

// ------------------------------------------------------- first-run setup --
// Runs last: it takes the config away, so nothing after it can rely on one.
// /settings is reachable unauthenticated while no config exists, so a bare
// POST that omitted blogPassword used to store password_hash(''), a valid
// hash that no input can satisfy — /login refuses an empty submission before
// it verifies. The site was then permanently unloggable, recoverable only by
// deleting data/config.php. The form's `required` attribute was the only
// thing standing in the way, and curl does not read HTML.
$cfgPath = $tmp . '/data/config.php';
$cfgHeld = (string) file_get_contents($cfgPath);
@unlink($cfgPath);

$firstRunFields = [
    'blogName'     => 'Fresh',
    'blogDomain'   => $base, // the test server itself: a real domain would 301 every later request away
    'blogTemplate' => 'gazette',
    'blogTimezone' => 'UTC',
    'blogI18N'     => 'en_US',
    'blogPostsPerPage' => '6',
];
// A CSRF token only holds inside the session that issued it, and these
// requests are anonymous — mint one session and keep it for the whole flow.
$setup = $session();
[, , $b] = req('GET', "$base/settings", $setup);
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s, $h] = req('POST', "$base/settings", $setup + ['body' => http_build_query($firstRunFields + ['csrf_token' => $m[1] ?? ''])]);
check(
    'first-run setup refuses to create a passwordless site',
    $s === 302 && str_contains($h['location'] ?? '', '/settings') && !is_file($cfgPath),
    "status $s -> " . ($h['location'] ?? '(none)') . ', config ' . (is_file($cfgPath) ? 'CREATED' : 'absent')
);

[, , $b] = req('GET', "$base/settings", $setup);
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s, $h] = req('POST', "$base/settings", $setup + ['body' => http_build_query($firstRunFields + [
    'csrf_token'   => $m[1] ?? '',
    'blogPassword' => 'chosen-at-setup',
])]);
check('first-run setup with a password creates the site', $s === 302 && str_contains($h['location'] ?? '', '/dashboard') && is_file($cfgPath), "status $s -> " . ($h['location'] ?? '(none)'));

// The credential chosen at setup must actually work.
$loginAs = $session();
[, , $b] = req('GET', "$base/login", $loginAs);
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $b, $m);
[$s, $h] = req('POST', "$base/login", $loginAs + ['body' => http_build_query(['csrf_token' => $m[1] ?? '', 'blogPassword' => 'chosen-at-setup'])]);
// 2FA is on in the fixture, so a correct password advances to the second
// factor; a wrong one would land back on /login with an error. Either way the
// point is that the credential chosen during setup is usable at all, which is
// what storing password_hash('') used to make impossible.
check(
    'the password chosen at setup is accepted',
    $s === 302 && str_contains($h['location'] ?? '', '/login/verify'),
    "status $s -> " . ($h['location'] ?? '(none)')
);

// Put the original config back, byte for byte, via the same temp-and-rename
// the rest of the harness uses.
$cfgRestore = $cfgPath . '.restore.tmp';
file_put_contents($cfgRestore, $cfgHeld, LOCK_EX);
rename($cfgRestore, $cfgPath);

// ------------------------------------------------- login throttle + 2FA --
// Runs late, like the password rotation above it: driving real logins moves
// shared state (the throttle file, the TOTP counter, session ids) that earlier
// checks read. The password here is the ROTATED one, set by the settings save.
//
// None of this had coverage before. The throttle window, the replay guard on
// the TOTP counter, and recovery-code consumption could each drift with
// nothing going red.

$attemptLogin = static function (array $client, string $password) use ($base): array {
    [, , $page] = req('GET', "$base/login", $client);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $page, $lm);
    return req('POST', "$base/login", $client + ['body' => http_build_query([
        'csrf_token'   => $lm[1] ?? '',
        'blogPassword' => $password,
    ])]);
};
$clearThrottle = static function () use ($tmp): void {
    @unlink($tmp . '/data/login_throttle.json');
};

$clearThrottle();
$lockClient = $browser();
for ($i = 0; $i < 6; $i++) {
    $attemptLogin($lockClient, 'not-the-password');
}
[, , $b] = req('GET', "$base/login", $lockClient);
check('repeated failed logins lock the address out', str_contains($b, 'Too many failed attempts'), 'no lockout message');
// The lockout has to hold against the RIGHT password too, or it only
// inconveniences someone who already knows it.
$attemptLogin($lockClient, 'rotated-password');
[$s] = req('GET', "$base/dashboard", $lockClient);
check('the lockout refuses the correct password too', $s === 302, "dashboard status $s");
// Pacing the failures must not shorten the lockout. Anchored on the first
// failure it did: five spread across the window left a one-second lockout,
// so an attacker who waited got no throttle at all. Written against the
// stored entry, since the wall-clock version would need a 15-minute test.
$clearThrottle();
$pacedClient = $browser();
for ($i = 0; $i < 6; $i++) {
    $attemptLogin($pacedClient, 'not-the-password');
}
$throttleFile = $tmp . '/data/login_throttle.json';
$throttleAll  = (array) (json_decode((string) file_get_contents($throttleFile), true) ?: []);
$throttleKey  = (string) array_key_first($throttleAll);
check('a failed login records a throttle entry', $throttleKey !== '' && isset($throttleAll[$throttleKey]['until']), 'entry ' . json_encode($throttleAll));

// Age the record so it looks like the failures began almost a full window ago
// — which is what an attacker who paces their attempts produces. Anchored on
// the FIRST failure the next one leaves about a second of lockout; anchored on
// the LAST it starts the window again. Faking the record beats a 15-minute test.
// One below the threshold, so the next attempt is recorded rather than
// refused early — a locked-out client is turned away before it can add one.
$throttleAll[$throttleKey]['count'] = 4;
// Comfortably live, but far short of a full window: anchored on the first
// failure the lockout stays at this, anchored on the last it starts over.
$throttleAll[$throttleKey]['until'] = time() + 60;
file_put_contents($throttleFile, json_encode($throttleAll));
$attemptLogin($browser(), 'not-the-password');
$throttleAged = (array) (json_decode((string) file_get_contents($throttleFile), true) ?: []);
$agedUntil    = (int) ($throttleAged[$throttleKey]['until'] ?? 0);
check(
    'a paced attack does not shorten the lockout',
    ($agedUntil - time()) > 800,
    'lockout would be ' . max(0, $agedUntil - time()) . 's'
);
// A stale record must not survive a read, so nothing downstream re-filters.
file_put_contents($throttleFile, json_encode(['stale-key' => ['count' => 9, 'until' => time() - 1]]));
check('an expired throttle record is dropped on read', Fieldnote\Security::loginLockedFor($tmp . '/data') === 0, 'still locked');

// Left set, this leaks into the first-run checks below.
$clearThrottle();

// Second factor. The fixture secret is known, so a real current code can be
// computed rather than faked.
$totpSecret = 'JBSWY3DPEHPK3PXP';
// Enrol through the real path rather than hand-building the file: enable()
// owns generating, normalizing and hashing the codes, and hands back the
// plain ones exactly once — which is the only chance anyone gets to see them.
$enrolled = (new Fieldnote\TwoFactor($tmp . '/data'))->enable($totpSecret, 1);
check('enabling 2FA returns the recovery codes to show once', is_array($enrolled) && count($enrolled) === 1, 'got ' . var_export($enrolled, true));
$recoveryCode = $enrolled[0] ?? 'unusable';

// Password then code, on a client that follows its own cookies — the login
// regenerates the session id, which a pinned cookie cannot follow.
$fullLogin = static function (string $code) use ($base, $browser, $attemptLogin): array {
    $client = $browser();
    $attemptLogin($client, 'rotated-password');
    [, , $vp] = req('GET', "$base/login/verify", $client);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $vp, $vm);
    [$st, $hd] = req('POST', "$base/login/verify", $client + ['body' => http_build_query([
        'csrf_token' => $vm[1] ?? '',
        'code'       => $code,
    ])]);
    return [$st, $hd, $client];
};

$firstFactor = $browser();
[$s, $h] = $attemptLogin($firstFactor, 'rotated-password');
check('a correct password with 2FA on stops at the second factor', $s === 302 && str_contains($h['location'] ?? '', '/login/verify'), "status $s -> " . ($h['location'] ?? '(none)'));
[$s] = req('GET', "$base/dashboard", $firstFactor);
check('the password alone does not authenticate', $s === 302, "dashboard status $s");

$goodCode = (string) Fieldnote\Totp::hotp($totpSecret, (int) floor(time() / 30));
[$s, $h] = $fullLogin('000000');
check('a wrong second-factor code is refused', $s === 302 && !str_contains($h['location'] ?? '', '/dashboard'), "status $s -> " . ($h['location'] ?? '(none)'));

[$s, $h, $verified] = $fullLogin($goodCode);
check('a correct second-factor code completes the login', $s === 302 && str_contains($h['location'] ?? '', '/dashboard'), "status $s -> " . ($h['location'] ?? '(none)'));
[$s] = req('GET', "$base/dashboard", $verified);
check('the second factor authenticated the session', $s === 200, "dashboard status $s");

// lastCounter is persisted precisely so a code cannot be presented twice.
[$s, $h] = $fullLogin($goodCode);
check('the same second-factor code cannot be replayed', $s === 302 && !str_contains($h['location'] ?? '', '/dashboard'), "status $s -> " . ($h['location'] ?? '(none)'));

// A recovery code works once, and using it spends it.
[$s, $h] = $fullLogin($recoveryCode);
check('a recovery code completes the login', $s === 302 && str_contains($h['location'] ?? '', '/dashboard'), "status $s -> " . ($h['location'] ?? '(none)'));
[$s, $h] = $fullLogin($recoveryCode);
check('a spent recovery code is refused', $s === 302 && !str_contains($h['location'] ?? '', '/dashboard'), "status $s -> " . ($h['location'] ?? '(none)'));

$clearThrottle();

// ---------------------------------------------------------------- summary --

echo "\n" . ($failures === 0 ? 'All checks passed.' : "$failures check(s) FAILED.") . "\n";
exit($failures > 0 ? 1 : 0);
