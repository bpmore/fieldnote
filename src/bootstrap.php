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
define('FN_DATA_DIR', FN_ROOT . '/data');
define('FN_DB_DIR', FN_DATA_DIR . '/siteDatabase');
define('FN_TEMPLATES_DIR', FN_ROOT . '/templates');
define('FN_INTERNAL_DIR', FN_ROOT . '/internal');
define('FN_UPLOAD_DIR', __DIR__ === '' ? '' : FN_ROOT . '/public/uploads');

require FN_ROOT . '/vendor/autoload.php';

Security::startSession();
Security::sendBaseHeaders();

$configStore = new Config(FN_DATA_DIR);
$siteConfig  = $configStore->load();

date_default_timezone_set($siteConfig['timezone'] ?: 'America/New_York');

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
 * Shared accessibility baseline injected into every theme's <head> ahead of
 * the theme stylesheet (so themes can override at equal specificity).
 * Covers the WCAG 2.2 plumbing no theme should have to re-implement:
 * skip-link reveal, :focus-visible ring, pagination target-size floor,
 * .sr-only, and a global reduced-motion kill switch.
 */
function fn_a11y_base_css(): string
{
    return <<<'CSS'
.skip-link{position:absolute;left:-999px;top:auto}
.skip-link:focus{position:fixed;left:.75rem;top:.75rem;z-index:999;padding:.5rem 1rem;background:var(--bg,#fff);color:var(--text,#000);outline:2px solid var(--focus,currentColor);outline-offset:2px}
:focus-visible{outline:2px solid var(--focus,currentColor);outline-offset:2px}
.pagination{display:flex;flex-wrap:wrap;justify-content:center;gap:.4rem;list-style:none;margin:2rem 0 0;padding:0}
.pagination li{margin:0}
.pagination a,.pagination .current{display:inline-flex;align-items:center;justify-content:center;min-width:24px;min-height:24px}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}
@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important;scroll-behavior:auto!important}}
CSS;
}

/**
 * Skip-to-content link. Themes call this immediately after <body> and give
 * their main element id="main". Styling lives in fn_a11y_base_css().
 */
function fn_skip_link(string $label = 'Skip to content'): void
{
    echo '<a class="skip-link" href="#main">' . e($label) . '</a>' . "\n";
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
 * the 24px minimum target size comes from fn_a11y_base_css().
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
    echo '<style>' . fn_a11y_base_css() . '</style>' . "\n";
    echo '<link rel="stylesheet" href="' . e($themeCssHref) . '">' . "\n";
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
    echo '<link rel="icon" href="' . $base . '/logo.svg" type="image/svg+xml">' . "\n";
    echo '<link rel="alternate" type="application/rss+xml" title="' . e($siteName) . '" href="' . e($router->generate('feed')) . '">' . "\n";
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
