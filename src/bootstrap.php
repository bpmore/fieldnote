<?php

namespace Fieldnote;

use SleekDB\Store;

/**
 * Application bootstrap. Loaded once by public/index.php.
 *
 * Responsibilities, in order:
 *   1. Define paths (data and templates live OUTSIDE the web root).
 *   2. Autoload via Composer.
 *   3. Start the hardened session BEFORE routing (the original started it
 *      after matching routes).
 *   4. Load config and open the flat-file stores.
 */

// SleekDB uses implicitly-nullable parameters, deprecated as of PHP 8.4;
// keep those notices out of rendered pages without silencing real errors.
error_reporting(E_ALL & ~E_DEPRECATED);

define('FN_ROOT', dirname(__DIR__));
// Env overrides exist for the smoke test (bin/smoke-test.php), which boots
// a disposable instance against a temp directory instead of real data.
define('FN_DATA_DIR', getenv('FN_DATA_DIR') ?: FN_ROOT . '/data');
define('FN_DB_DIR', FN_DATA_DIR . '/siteDatabase');
define('FN_TEMPLATES_DIR', FN_ROOT . '/templates');
define('FN_INTERNAL_DIR', FN_ROOT . '/internal');
define('FN_UPLOAD_DIR', getenv('FN_UPLOAD_DIR') ?: FN_ROOT . '/public/uploads');

require FN_ROOT . '/vendor/autoload.php';

Security::startSession();
Security::sendBaseHeaders();

$configStore = new Config(FN_DATA_DIR);
$siteConfig  = $configStore->load();

date_default_timezone_set($siteConfig['timezone'] ?: 'America/New_York');

Security::setTrustedProxies(array_values(array_filter(array_map(
    'trim',
    explode(',', (string) ($siteConfig['trustedProxies'] ?? ''))
))));
Security::setSessionEpoch((string) ($siteConfig['sessionEpoch'] ?? ''));

$dbOptions = ['timeout' => false];
$blogStore  = new Store('blog', FN_DB_DIR, $dbOptions);
$imageStore = new Store('images', FN_DB_DIR, $dbOptions);

// One-time migration: give pre-3.1 posts a URL slug. Marker file keeps this
// from scanning the store on every request.
$slugMarker = FN_DATA_DIR . '/.slugs-v1';
if (!is_file($slugMarker) && is_dir(FN_DB_DIR . '/blog')) {
    foreach ($blogStore->findAll() as $existingPost) {
        if (empty($existingPost['slug'])) {
            $blogStore->updateById(
                (int) $existingPost['_id'],
                ['slug' => fn_unique_slug($blogStore, (string) ($existingPost['title'] ?? ''))]
            );
        }
    }
    @touch($slugMarker);
}

// One-time migration: posts published before publishedAt existed keep their
// current date as the publish date, so hide/re-publish never moves their URL.
$pubMarker = FN_DATA_DIR . '/.pubdate-v1';
if (!is_file($pubMarker) && is_dir(FN_DB_DIR . '/blog')) {
    foreach ($blogStore->findBy(['draft', '=', false]) as $existingPost) {
        if (empty($existingPost['publishedAt'])) {
            $blogStore->updateById(
                (int) $existingPost['_id'],
                ['publishedAt' => (int) ($existingPost['date'] ?? 0)]
            );
        }
    }
    @touch($pubMarker);
}

// Scheduled publishing without cron: drafts whose scheduledFor time has
// passed are published by the first request to arrive afterward. The marker
// file caps the draft scan at once per minute; everything between scans is
// a single stat() call.
$schedMarker = FN_DATA_DIR . '/.schedule-check';
if ((!is_file($schedMarker) || (int) filemtime($schedMarker) < time() - 60) && is_dir(FN_DB_DIR . '/blog')) {
    @touch($schedMarker);
    foreach ($blogStore->findBy(['draft', '=', true]) as $scheduledPost) {
        $at = (int) ($scheduledPost['scheduledFor'] ?? 0);
        if ($at > 0 && $at <= time()) {
            $update = ['draft' => false, 'scheduledFor' => 0];
            // Same first-publish stamping as the manual publish route: the
            // permalink embeds the date, so it is set once and never moves.
            if (empty($scheduledPost['publishedAt'])) {
                $update['date']        = $at;
                $update['publishedAt'] = $at;
            }
            $blogStore->updateById((int) $scheduledPost['_id'], $update);
            fn_invalidate_published_count();
        }
    }
}

// One-time migration: image records used to store the absolute public URL
// (embedding the configured domain) and the absolute disk path (embedding
// the project folder), so renaming either silently broke every existing
// image. Re-store both relative: URL relative to the site root, path
// relative to public/uploads/.
$imgMarker = FN_DATA_DIR . '/.imgrel-v1';
if (!is_file($imgMarker) && is_dir(FN_DB_DIR . '/images')) {
    foreach ($imageStore->findAll() as $imageRecord) {
        $update = [];
        $url = (string) ($imageRecord['url'] ?? '');
        if (preg_match('#^https?://#i', $url)) {
            $update['url'] = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        }
        $path = (string) ($imageRecord['path'] ?? '');
        $pos  = strpos($path, '/public/uploads/');
        if ($pos !== false) {
            $update['path'] = substr($path, $pos + strlen('/public/uploads/'));
        }
        if ($update !== []) {
            $imageStore->updateById((int) $imageRecord['_id'], $update);
        }
    }
    @touch($imgMarker);
}

/**
 * Parse a php.ini shorthand size ("2M", "512K") into bytes.
 */
function fn_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return PHP_INT_MAX;
    }
    $unit  = strtoupper(substr($value, -1));
    $num   = (float) $value;
    return (int) match ($unit) {
        'G' => $num * 1024 ** 3,
        'M' => $num * 1024 ** 2,
        'K' => $num * 1024,
        default => $num,
    };
}

/**
 * The upload cap the server will actually honor: the app's own limit bounded
 * by PHP's per-request limits. Shown in the editor UI and enforced client-side
 * so a too-large file fails with a clear message instead of a blank error.
 */
function fn_effective_upload_limit(): int
{
    return min(
        ImageHandler::MAX_BYTES,
        fn_ini_bytes((string) ini_get('upload_max_filesize')),
        fn_ini_bytes((string) ini_get('post_max_size'))
    );
}

/**
 * Build a URL-safe slug from a post title. Never empty and never purely
 * numeric (numeric paths are reserved for the legacy /post/{id} redirects).
 */
function fn_slugify(string $title): string
{
    $slug = strtolower(trim($title));
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
    if (is_string($ascii) && $ascii !== '') {
        $slug = strtolower($ascii);
    }
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim(substr(trim($slug, '-'), 0, 80), '-');
    if ($slug === '') {
        return 'post';
    }
    if (ctype_digit($slug)) {
        return 'post-' . $slug;
    }
    return $slug;
}

/**
 * Normalize a comma-separated tag string into unique slugs, capped at 8.
 * Tags share the post slug alphabet so tag URLs are always clean.
 *
 * @return string[]
 */
function fn_parse_tags(string $input): array
{
    $tags = [];
    foreach (explode(',', $input) as $raw) {
        if (trim($raw) === '') {
            continue;
        }
        $slug = fn_slugify($raw);
        if (!in_array($slug, $tags, true)) {
            $tags[] = $slug;
        }
        if (count($tags) === 8) {
            break;
        }
    }
    return $tags;
}

/**
 * Tag list for a post: an aria-labelled nav of links to tag pages. Themes
 * opt in by calling it (the fn_pagination pattern) — no contract change;
 * layout plumbing for .tag-list lives in static/a11y.css.
 *
 * @param array<string,mixed> $post
 */
function fn_tag_links(\AltoRouter $router, array $post): void
{
    $tags = array_filter(array_map('strval', (array) ($post['tags'] ?? [])));
    if ($tags === []) {
        return;
    }
    echo '<nav class="tags" aria-label="Tags"><ul class="tag-list">' . "\n";
    foreach ($tags as $tag) {
        echo '<li><a href="' . e($router->generate('tag', ['tag' => $tag])) . '">' . e($tag) . '</a></li>' . "\n";
    }
    echo '</ul></nav>' . "\n";
}

/**
 * App-level signing secret (draft share links). Created on first use,
 * outside the web root. Deleting the file rotates the secret, which
 * invalidates every link ever issued from it.
 */
function fn_app_secret(): string
{
    $file = FN_DATA_DIR . '/secret';
    $secret = is_file($file) ? trim((string) file_get_contents($file)) : '';
    if ($secret === '') {
        $secret = bin2hex(random_bytes(32));
        @file_put_contents($file, $secret, LOCK_EX);
        @chmod($file, 0640);
    }
    return $secret;
}

/**
 * MAC for a draft share link. The expiry rides in the URL and is covered by
 * the MAC, so tampering with either id or expiry kills the link.
 */
function fn_draft_token(int $id, int $exp): string
{
    return substr(hash_hmac('sha256', $id . '|' . $exp, fn_app_secret()), 0, 32);
}

/**
 * Run the content accessibility lint on a just-saved post and flash any
 * suggestions for the next dashboard render. Suggestions, not gates: the
 * post is already saved when this runs.
 */
function fn_flash_content_lint(string $title, string $markdown): void
{
    $warnings = ContentLint::check($markdown);
    if ($warnings === []) {
        unset($_SESSION['content_lint']);
        return;
    }
    $_SESSION['content_lint'] = ['title' => $title, 'warnings' => $warnings];
}

/**
 * Scheme + host for building absolute URLs when no domain is configured,
 * honoring X-Forwarded-Proto the same way the session cookie does — behind
 * a TLS-terminating proxy the fallback must not emit http:// URLs.
 */
function fn_request_base(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/**
 * Visitor search form (GET, zero-JS). Themes opt in by calling it wherever
 * fits their layout; the sr-only label keeps it accessible unstyled.
 */
function fn_search_form(\AltoRouter $router, string $value = ''): void
{
    echo '<form role="search" method="get" action="' . e($router->generate('search')) . '" class="search-form">' . "\n"
        . '<label class="sr-only" for="fn-search-q">Search</label>' . "\n"
        . '<input type="search" id="fn-search-q" name="q" value="' . e($value) . '" placeholder="Search&hellip;">' . "\n"
        . '<button type="submit">Search</button>' . "\n"
        . '</form>' . "\n";
}

/**
 * A slug unique across the blog store, appending -2, -3, ... on collision.
 * $excludeId lets a post keep its own slug when re-saved.
 */
function fn_unique_slug(Store $blogStore, string $title, ?int $excludeId = null): string
{
    $base = fn_slugify($title);
    $slug = $base;
    $n = 2;
    while (true) {
        $existing = $blogStore->findOneBy(['slug', '=', $slug]);
        if ($existing === null || ($excludeId !== null && (int) $existing['_id'] === $excludeId)) {
            return $slug;
        }
        $slug = $base . '-' . $n++;
    }
}

/**
 * Skip-to-content link. Themes call this immediately after <body> and give
 * their main element id="main". Styling lives in static/a11y.css.
 */
function fn_skip_link(string $label = 'Skip to content'): void
{
    echo '<a class="skip-link" href="#main">' . e($label) . '</a>' . "\n";
}

/**
 * Optional footer badge linking to the /accessibility statement. Off by
 * default (config 'accessibilityBadge'); when on, every theme that calls
 * this in its footer shows it. The mark is inline SVG using currentColor,
 * so it inherits the footer's own text color — which already passes the
 * contrast gate in every theme — and the badge can never introduce an
 * inaccessible element. Self-hosted (no external request); styled by
 * .a11y-badge in the shared a11y.css baseline.
 *
 * @param array<string,mixed> $siteConfig
 */
function fn_a11y_badge(\AltoRouter $router, array $siteConfig): void
{
    if (empty($siteConfig['accessibilityBadge'])) {
        return;
    }
    echo '<a class="a11y-badge" href="' . e($router->generate('accessibility'))
        . '" aria-label="Accessibility: machine-checked to WCAG 2.2 AA. Read the statement.">' . "\n"
        . '<svg class="a11y-badge-mark" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false">'
        . '<path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" d="M3.5 8.5l3 3 6-7"/>'
        . '</svg>'
        . '<span>WCAG&nbsp;2.2&nbsp;AA</span>'
        . '</a>' . "\n";
}

/**
 * Alt text for the un-linked post hero image. List/card images stay alt=""
 * (decorative — the adjacent title link already names the destination);
 * the hero is content, so it gets the post title. One function so the
 * policy can change once, everywhere.
 *
 * @param array<string,mixed> $post
 */
function fn_image_alt(array $post): string
{
    return (string) ($post['title'] ?? '');
}

/**
 * Shared pagination block: aria-label, aria-current on the active page,
 * rel prev/next. Themes style it via the .pagination / .current classes;
 * the 24px minimum target size comes from static/a11y.css.
 */
function fn_pagination(\AltoRouter $router, int $page, int $numPages): void
{
    if ($numPages <= 1) {
        return;
    }
    echo '<nav aria-label="Pages">' . "\n" . '<ul class="pagination">' . "\n";
    if ($page > 1) {
        $prev = $page === 2 ? $router->generate('home') : $router->generate('posts', ['page' => $page - 1]);
        echo '<li><a href="' . e($prev) . '" rel="prev" aria-label="Previous page">&larr;</a></li>' . "\n";
    }
    for ($p = 1; $p <= $numPages; $p++) {
        if ($p === $page) {
            echo '<li><span class="current" aria-current="page">' . $p . '</span></li>' . "\n";
        } else {
            $href = $p === 1 ? $router->generate('home') : $router->generate('posts', ['page' => $p]);
            echo '<li><a href="' . e($href) . '">' . $p . '</a></li>' . "\n";
        }
    }
    if ($page < $numPages) {
        echo '<li><a href="' . e($router->generate('posts', ['page' => $page + 1])) . '" rel="next" aria-label="Next page">&rarr;</a></li>' . "\n";
    }
    echo '</ul>' . "\n" . '</nav>' . "\n";
}

/**
 * Admin palette overrides as CSS, or '' when none apply. Overrides are tied
 * to the theme they were authored for (template name stored alongside), so
 * switching themes silently retires them instead of corrupting the new theme.
 * Both schemes are emitted media-scoped — polarity-independent: light
 * overrides apply in light mode whether the theme is light- or dark-default.
 *
 * @param array<string,mixed> $siteConfig
 */
function fn_palette_css(array $siteConfig): string
{
    $overrides = $siteConfig['paletteOverrides'] ?? [];
    if (!is_array($overrides) || ($overrides['theme'] ?? '') !== $siteConfig['template']) {
        return '';
    }
    $block = static function (array $tokens): string {
        $css = '';
        foreach ($tokens as $name => $value) {
            // Both halves were validated at save time; the regex here is
            // belt-and-suspenders against a hand-edited config.
            if (preg_match('/^--[a-z0-9-]+$/', (string) $name) && preg_match('/^#[0-9a-f]{6}$/i', (string) $value)) {
                $css .= $name . ':' . $value . ';';
            }
        }
        return $css;
    };
    $css = '';
    foreach (['light', 'dark'] as $scheme) {
        $body = $block((array) ($overrides[$scheme] ?? []));
        if ($body !== '') {
            $css .= '@media (prefers-color-scheme: ' . $scheme . '){:root{' . $body . '}}';
        }
    }
    return $css;
}

/**
 * Emit the shared <head> content every theme needs: charset, viewport, title,
 * theme stylesheet, favicon, RSS discovery, canonical URL, OpenGraph/Twitter
 * cards, and the admin's headerInject snippet. Themes call this from their
 * header.php so 14 themes don't carry 14 copies of the meta logic.
 *
 * @param array<string,mixed>      $siteConfig
 * @param array<string,mixed>|null $post Current post on single-post pages.
 */
function fn_render_head(array $siteConfig, \AltoRouter $router, string $pageTitle, ?array $post, string $themeCssHref, ?string $schemeOverrideCss = null): void
{
    $siteName  = $siteConfig['name'] !== '' ? $siteConfig['name'] : 'Fieldnote';
    $fullTitle = $siteName . ' | ' . $pageTitle;

    $canonical = '';
    if ($siteConfig['domain'] !== '') {
        $path      = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $canonical = rtrim((string) $siteConfig['domain'], '/') . $path;
    }

    $socialImage = (isset($post['imageUrl']) && $post['imageUrl'] !== '' && empty($post['password']))
        ? (string) $post['imageUrl']
        : (string) $siteConfig['OGImage'];
    // Upload URLs are stored site-relative; social scrapers want og:image
    // absolute, so qualify it with the configured domain when there is one.
    if (
        str_starts_with($socialImage, '/') && !str_starts_with($socialImage, '//')
        && $siteConfig['domain'] !== ''
    ) {
        $socialImage = rtrim((string) $siteConfig['domain'], '/') . $socialImage;
    }
    $ogType = isset($post['title']) ? 'article' : 'website';
    $base   = e((string) $siteConfig['basePath']);

    echo '<meta charset="utf-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n";
    echo '<meta name="robots" content="index, follow">' . "\n";
    echo '<title>' . e($fullTitle) . '</title>' . "\n";
    echo '<link rel="stylesheet" href="' . $base . '/static/a11y.css?v=' . (int) @filemtime(FN_ROOT . '/public/static/a11y.css') . '">' . "\n";
    echo '<link rel="stylesheet" href="' . e($themeCssHref) . '">' . "\n";
    // Palette overrides authored in /admin/palette — WCAG-validated at save
    // time. A linked stylesheet (not inline) so the strict public CSP holds;
    // the version hash busts browser caches the moment the palette changes.
    // Linked before any preview scheme-force style so a forced preview wins.
    $paletteCss = fn_palette_css($siteConfig);
    if ($paletteCss !== '') {
        echo '<link rel="stylesheet" href="' . e($router->generate('paletteCss')) . '?v=' . substr(sha1($paletteCss), 0, 8) . '">' . "\n";
    }
    // Admin theme previews force a color scheme by re-declaring the token
    // block AFTER the stylesheet, where it outranks the theme's own
    // prefers-color-scheme override. Themes don't pass this parameter; the
    // preview route hands it over via $GLOBALS so 70 headers stay untouched.
    // Content comes only from templates/<name>/assets/theme.css (admin-
    // controlled files already served verbatim), never from user input.
    $schemeOverrideCss ??= $GLOBALS['fnSchemeOverrideCss'] ?? null;
    if ($schemeOverrideCss !== null && $schemeOverrideCss !== '') {
        echo '<style>' . $schemeOverrideCss . '</style>' . "\n";
    }
    echo '<link rel="icon" href="' . $base . '/logo.svg?v=' . (int) @filemtime(FN_ROOT . '/public/logo.svg') . '" type="image/svg+xml">' . "\n";
    echo '<link rel="alternate" type="application/rss+xml" title="' . e($siteName) . '" href="' . e($router->generate('feed')) . '">' . "\n";
    echo '<link rel="alternate" type="application/feed+json" title="' . e($siteName) . '" href="' . e($router->generate('jsonFeed')) . '">' . "\n";
    if ($canonical !== '') {
        echo '<link rel="canonical" href="' . e($canonical) . '">' . "\n";
        echo '<meta property="og:url" content="' . e($canonical) . '">' . "\n";
    }
    if ($siteConfig['info'] !== '') {
        echo '<meta name="description" content="' . e((string) $siteConfig['info']) . '">' . "\n";
        echo '<meta property="og:description" content="' . e((string) $siteConfig['info']) . '">' . "\n";
    }
    echo '<meta property="og:title" content="' . e($fullTitle) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . e($siteName) . '">' . "\n";
    echo '<meta property="og:type" content="' . e($ogType) . '">' . "\n";
    if ($socialImage !== '') {
        echo '<meta property="og:image" content="' . e($socialImage) . '">' . "\n";
        echo '<meta name="twitter:image" content="' . e($socialImage) . '">' . "\n";
    }
    echo '<meta name="twitter:card" content="' . ($socialImage !== '' ? 'summary_large_image' : 'summary') . '">' . "\n";
    echo '<meta name="twitter:title" content="' . e($fullTitle) . '">' . "\n";

    // headerInject is raw markup supplied by the authenticated admin (for
    // analytics snippets and the like). It is intentionally NOT escaped and
    // is writable only from the CSRF-protected settings form.
    if (!empty($siteConfig['headerInject'])) {
        echo $siteConfig['headerInject'] . "\n";
    }
}

/**
 * Number of published posts, cached in a small file so every request stops
 * loading the entire store just to compute pagination. Deleted by every
 * action that changes published-ness (publish, hide, delete); regenerated
 * lazily on the next read.
 */
function fn_published_count(Store $blogStore): int
{
    $cacheFile = FN_DATA_DIR . '/cache/published-count';
    if (is_file($cacheFile)) {
        $cached = trim((string) @file_get_contents($cacheFile));
        if ($cached !== '' && ctype_digit($cached)) {
            return (int) $cached;
        }
    }
    $count = count($blogStore->findBy(['draft', '=', false]));
    $dir = dirname($cacheFile);
    if (is_dir($dir) || (mkdir($dir, 0750, true) || is_dir($dir))) {
        @file_put_contents($cacheFile, (string) $count, LOCK_EX);
    }
    return $count;
}

function fn_invalidate_published_count(): void
{
    @unlink(FN_DATA_DIR . '/cache/published-count');
}

/**
 * Conditional-GET plumbing for feed-style responses. Sends ETag,
 * Last-Modified, and Cache-Control, then ends the request with an empty 304
 * when the client's validators still match. Call before producing the body —
 * feed readers poll constantly and shouldn't cost a render each time.
 */
/**
 * ETag seed for syndication endpoints (RSS, JSON feed, sitemap): every field
 * that can change an item, so edits to already-published posts bust caches.
 *
 * @param array<int,array<string,mixed>> $posts
 * @param array<string,mixed>            $siteConfig
 */
function fn_feed_seed(string $route, array $posts, int $publishedCount, array $siteConfig): string
{
    return $route . '|' . $siteConfig['name'] . '|' . $publishedCount . '|' . sha1(serialize(array_map(
        static fn (array $p): array => [
            $p['_id'], $p['date'], $p['title'] ?? '', $p['slug'] ?? '',
            $p['content'] ?? '', !empty($p['password']), $p['tags'] ?? [],
        ],
        $posts
    )));
}

function fn_conditional_get(string $etagSeed, int $lastModified): void
{
    $etag = '"' . sha1($etagSeed) . '"';
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
    header('Cache-Control: max-age=300');

    $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNoneMatch !== '') {
        // Weak comparison: caches may have prefixed W/ on the way through.
        if ($ifNoneMatch === '*' || str_replace('W/', '', $ifNoneMatch) === $etag) {
            http_response_code(304);
            exit;
        }
        return; // a presented ETag takes precedence over If-Modified-Since
    }

    $ifModifiedSince = (string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
    if ($ifModifiedSince !== '' && strtotime($ifModifiedSince) >= $lastModified) {
        http_response_code(304);
        exit;
    }
}

/**
 * Render the homepage (page 1) through a given template directory. Shared by
 * the public home route and the admin theme preview so the preview always
 * renders exactly what the theme would show live.
 *
 * @param array<string,mixed> $siteConfig
 */
function fn_render_home(
    array $siteConfig,
    \AltoRouter $router,
    Store $blogStore,
    Store $imageStore,
    string $templateDir,
    int $postsPerPage,
    int $numPages
): void {
    $page  = 1;
    $limit = $postsPerPage;
    $allPosts = array_map(
        fn ($p) => fn_with_image($p, $imageStore),
        $blogStore->findBy(['draft', '=', false], ['date' => 'desc'], $postsPerPage)
    );
    $pageTitle = 'Home';
    require $templateDir . '/home.php';
}

/**
 * Plain-text excerpt of a post body for list views. Strips markdown syntax
 * crudely and cuts at a word boundary. Password-protected posts always
 * return '' — their content must never leak into a listing.
 *
 * @param array<string,mixed> $post
 */
function fn_excerpt(array $post, int $limit = 160): string
{
    if (!empty($post['password'])) {
        return '';
    }
    $text = (string) ($post['content'] ?? '');
    $text = preg_replace('/```.*?```/s', ' ', $text) ?? $text;            // fenced code
    $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $text) ?? $text;  // images
    $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text; // links -> text
    $text = preg_replace('/^#{1,6}\s+.*$/m', ' ', $text) ?? $text;        // headings out entirely
    $text = preg_replace('/^\s*(?:[-+*]|\d+\.)\s+/m', '', $text) ?? $text; // list markers
    $text = preg_replace('/^>\s?/m', '', $text) ?? $text;                 // blockquotes
    $text = str_replace(['**', '__', '*', '_', '`', '~~'], '', $text);    // emphasis
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    $cut = mb_substr($text, 0, $limit);
    $sp  = mb_strrpos($cut, ' ');
    if ($sp !== false && $sp > 40) {
        $cut = mb_substr($cut, 0, $sp);
    }
    return $cut . '…';
}

/**
 * Names of installed templates: directories under templates/ that provide
 * the two required views.
 */
function fn_template_names(): array
{
    $names = [];
    foreach (glob(FN_TEMPLATES_DIR . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (is_file($dir . '/home.php') && is_file($dir . '/post.php')) {
            $names[] = basename($dir);
        }
    }
    sort($names);
    return $names;
}

/**
 * Canonical URL for a post: /{year}/{month}/{slug}, dated from the post's
 * publish timestamp in the site's configured timezone.
 */
function fn_post_url(\AltoRouter $router, array $post): string
{
    $ts = (int) ($post['date'] ?? 0);
    return $router->generate('post', [
        'year'  => date('Y', $ts),
        'month' => date('m', $ts),
        'slug'  => $post['slug'] ?? ('post-' . ($post['_id'] ?? 0)),
    ]);
}

/**
 * Resolve a stored template name to a real, existing template directory.
 * Defends against path traversal: only a bare directory name that actually
 * exists under templates/ is accepted; anything else falls back to liquid-new.
 */
function fn_template_dir(string $name): string
{
    $name = basename($name); // strip any path components
    $dir  = FN_TEMPLATES_DIR . '/' . $name;
    if ($name !== '' && is_dir($dir) && is_file($dir . '/home.php') && is_file($dir . '/post.php')) {
        return $dir;
    }
    return FN_TEMPLATES_DIR . '/liquid-new';
}

/**
 * Delete an image record and its file on disk. Stored paths are relative to
 * uploads/ (an absolute path means a pre-migration record). The single
 * cleanup path for post deletion AND image replacement — replacing used to
 * leak the old file forever.
 */
function fn_delete_image(Store $imageStore, int|string|null $id): void
{
    if ($id === null || !is_numeric($id)) {
        return;
    }
    $record = $imageStore->findById((int) $id);
    if ($record === null) {
        return;
    }
    $path = (string) ($record['path'] ?? '');
    if ($path !== '' && !str_starts_with($path, '/')) {
        $path = FN_UPLOAD_DIR . '/' . $path;
    }
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
    $imageStore->deleteById((int) $id);
}

/**
 * Attach a resolved public image URL to a post so templates never need to
 * touch the datastore themselves.
 *
 * @param array<string,mixed> $post
 * @return array<string,mixed>
 */
function fn_with_image(array $post, Store $imageStore): array
{
    $post['imageUrl'] = '';
    if (isset($post['image']) && is_numeric($post['image'])) {
        $record = $imageStore->findById((int) $post['image']);
        if ($record && !empty($record['url'])) {
            $post['imageUrl'] = $record['url'];
        }
    }
    return $post;
}
