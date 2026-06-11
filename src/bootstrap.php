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
