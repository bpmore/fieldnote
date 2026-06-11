<?php

namespace Dropplets;

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

define('DPL_ROOT', dirname(__DIR__));
define('DPL_DATA_DIR', DPL_ROOT . '/data');
define('DPL_DB_DIR', DPL_DATA_DIR . '/siteDatabase');
define('DPL_TEMPLATES_DIR', DPL_ROOT . '/templates');
define('DPL_INTERNAL_DIR', DPL_ROOT . '/internal');
define('DPL_UPLOAD_DIR', __DIR__ === '' ? '' : DPL_ROOT . '/public/uploads');

require DPL_ROOT . '/vendor/autoload.php';

Security::startSession();
Security::sendBaseHeaders();

$configStore = new Config(DPL_DATA_DIR);
$siteConfig  = $configStore->load();

date_default_timezone_set($siteConfig['timezone'] ?: 'America/New_York');

$dbOptions = ['timeout' => false];
$blogStore  = new Store('blog', DPL_DB_DIR, $dbOptions);
$imageStore = new Store('images', DPL_DB_DIR, $dbOptions);

// One-time migration: give pre-3.1 posts a URL slug. Marker file keeps this
// from scanning the store on every request.
$slugMarker = DPL_DATA_DIR . '/.slugs-v1';
if (!is_file($slugMarker) && is_dir(DPL_DB_DIR . '/blog')) {
    foreach ($blogStore->findAll() as $existingPost) {
        if (empty($existingPost['slug'])) {
            $blogStore->updateById(
                (int) $existingPost['_id'],
                ['slug' => dpl_unique_slug($blogStore, (string) ($existingPost['title'] ?? ''))]
            );
        }
    }
    @touch($slugMarker);
}

/**
 * Parse a php.ini shorthand size ("2M", "512K") into bytes.
 */
function dpl_ini_bytes(string $value): int
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
function dpl_effective_upload_limit(): int
{
    return min(
        ImageHandler::MAX_BYTES,
        dpl_ini_bytes((string) ini_get('upload_max_filesize')),
        dpl_ini_bytes((string) ini_get('post_max_size'))
    );
}

/**
 * Build a URL-safe slug from a post title. Never empty and never purely
 * numeric (numeric paths are reserved for the legacy /post/{id} redirects).
 */
function dpl_slugify(string $title): string
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
function dpl_unique_slug(Store $blogStore, string $title, ?int $excludeId = null): string
{
    $base = dpl_slugify($title);
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
 * Canonical URL for a post: /{year}/{month}/{slug}, dated from the post's
 * publish timestamp in the site's configured timezone.
 */
function dpl_post_url(\AltoRouter $router, array $post): string
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
function dpl_template_dir(string $name): string
{
    $name = basename($name); // strip any path components
    $dir  = DPL_TEMPLATES_DIR . '/' . $name;
    if ($name !== '' && is_dir($dir) && is_file($dir . '/home.php') && is_file($dir . '/post.php')) {
        return $dir;
    }
    return DPL_TEMPLATES_DIR . '/liquid-new';
}

/**
 * Attach a resolved public image URL to a post so templates never need to
 * touch the datastore themselves.
 *
 * @param array<string,mixed> $post
 * @return array<string,mixed>
 */
function dpl_with_image(array $post, Store $imageStore): array
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
