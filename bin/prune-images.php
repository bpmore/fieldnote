<?php

/**
 * Orphaned-image sweeper. CLI only.
 *
 *   php bin/prune-images.php            report orphans (dry run)
 *   php bin/prune-images.php --delete   remove them
 *
 * Finds two kinds of garbage left behind before image replacement learned
 * to clean up after itself:
 *   - image records no post references
 *   - files under public/uploads/ no image record references
 *
 * Records and files referenced by ANY post (draft or published) are never
 * touched.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// SleekDB triggers implicit-nullable deprecations on PHP 8.4 (same
// suppression bootstrap.php applies).
error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

use SleekDB\Store;

$root = dirname(__DIR__);
// Same env overrides bootstrap.php honours, so this can be pointed at a
// disposable instance. Without them the only way to exercise a destructive
// sweeper was to run it against the real install, which is why it had none.
$dataDir   = getenv('FN_DATA_DIR') ?: $root . '/data';
$dbDir     = $dataDir . '/siteDatabase';
$uploadDir = getenv('FN_UPLOAD_DIR') ?: $root . '/public/uploads';
$delete    = in_array('--delete', $argv, true);

if (!is_dir($dbDir . '/images')) {
    echo "No image store found — nothing to prune.\n";
    exit(0);
}

$dbOptions  = ['timeout' => false];
$blogStore  = new Store('blog', $dbDir, $dbOptions);
$imageStore = new Store('images', $dbDir, $dbOptions);

// Resolve a stored path (relative to uploads/, or absolute pre-migration).
$resolve = static function (string $path) use ($uploadDir): string {
    if ($path === '') {
        return '';
    }
    return str_starts_with($path, '/') ? $path : $uploadDir . '/' . $path;
};

// Is a resolved path somewhere this script is allowed to delete?
//
// A pre-migration record stores an absolute path, and nothing constrains where
// it points: a record carried over from an old install, or hand-edited, could
// name a file anywhere on disk, and --delete would unlink it. The record is
// still an orphan and still worth reporting — but deleting a file outside the
// uploads directory is not this script's business.
$insideUploads = static function (string $path) use ($uploadDir): bool {
    if ($path === '') {
        return false;
    }
    $realUploads = realpath($uploadDir);
    $realTarget  = realpath($path);
    if ($realUploads === false || $realTarget === false) {
        return false;
    }
    return str_starts_with($realTarget, rtrim($realUploads, '/') . '/');
};
$skippedOutside = [];

$referencedIds = [];
foreach ($blogStore->findAll() as $post) {
    if (isset($post['image']) && is_numeric($post['image'])) {
        $referencedIds[(int) $post['image']] = true;
    }
}

$orphanRecords = []; // [id, resolvedPath]
$keptFiles     = []; // realpath => true, files owned by referenced records
foreach ($imageStore->findAll() as $record) {
    $id   = (int) $record['_id'];
    $path = $resolve((string) ($record['path'] ?? ''));
    if (!isset($referencedIds[$id])) {
        $orphanRecords[] = [$id, $path];
    } elseif ($path !== '' && ($real = realpath($path)) !== false) {
        $keptFiles[$real] = true;
    }
}

// Files owned by orphan records get removed with their record; exclude them
// from the recordless-file list so nothing is reported (or deleted) twice.
$orphanRecordFiles = [];
foreach ($orphanRecords as [, $path]) {
    if ($path !== '' && ($real = realpath($path)) !== false) {
        $orphanRecordFiles[$real] = true;
    }
}

$orphanFiles = [];
if (is_dir($uploadDir)) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        // Only file types ImageHandler writes; leaves .htaccess/.gitkeep
        // and anything an admin placed there by hand alone.
        if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['jpg', 'png', 'gif'], true)) {
            continue;
        }
        $real = $file->getRealPath();
        if (!isset($keptFiles[$real]) && !isset($orphanRecordFiles[$real])) {
            $orphanFiles[] = $real;
        }
    }
}

if ($orphanRecords === [] && $orphanFiles === []) {
    echo "Clean: every image record is referenced by a post and every upload has a record.\n";
    exit(0);
}

foreach ($orphanRecords as [$id, $path]) {
    printf("record #%d (no post references it)%s\n", $id, $path !== '' ? ' + ' . $path : '');
    if ($delete) {
        if ($path !== '' && is_file($path)) {
            if ($insideUploads($path)) {
                @unlink($path);
            } else {
                $skippedOutside[] = $path;
            }
        }
        // The record goes either way: it is an orphan whatever its file is.
        $imageStore->deleteById($id);
    }
}
foreach ($orphanFiles as $path) {
    printf("file %s (no image record references it)\n", $path);
    if ($delete) {
        // These come from walking the uploads tree, so they are inside it by
        // construction; checked anyway rather than assumed.
        if ($insideUploads($path)) {
            @unlink($path);
        } else {
            $skippedOutside[] = $path;
        }
    }
}

printf(
    "\n%d orphan record(s), %d orphan file(s) %s.\n",
    count($orphanRecords),
    count($orphanFiles),
    $delete ? 'deleted' : 'found — re-run with --delete to remove'
);
if ($skippedOutside !== []) {
    printf(
        "%d file(s) left alone: they sit outside %s and are not this script's to delete.\n",
        count($skippedOutside),
        $uploadDir
    );
    foreach ($skippedOutside as $path) {
        printf("  kept %s\n", $path);
    }
}
exit(0);
