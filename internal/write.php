<?php
use function Fieldnote\e;
use function Fieldnote\csrf_field;
use function Fieldnote\fn_effective_upload_limit;
use function Fieldnote\fn_with_image;

$needsEditor = true; // footer loads the EasyMDE bundle only when this is set
require __DIR__ . '/header.php';

$isEdit   = isset($post['title']);
// The current-image thumbnail is this view's own need, so this view resolves
// it. It used to rely on whichever route required this file having called
// fn_with_image() first — and the one that re-renders after refusing an
// inaccessible save passes the raw record, so the writer lost sight of the
// featured image at exactly the moment they were being asked to fix the post.
if ($isEdit) {
    $post = fn_with_image($post, $imageStore);
}
$action   = $isEdit
    ? $router->generate('editPost', ['id' => $post['_id']])
    : $router->generate('write');
$uploadLimit   = fn_effective_upload_limit();
$uploadLimitMb = rtrim(rtrim(number_format($uploadLimit / 1048576, 1), '0'), '.');
// Per-post passwords are now hashed and never sent back to the browser. The
// field is shown blank; leaving it blank on edit keeps the existing password.
//
// Blocking accessibility errors: passed directly by the edit handler when a
// save to a public post is refused, or stashed by the publish and restore
// routes before they redirect here. Distinct from the dashboard's advisory
// flash — these actually stopped a save, publish, or restore.
//
// The stash names the post it belongs to, and is always drained even when it
// is not shown. An interrupted redirect used to leave it set, and the next
// render — including /write, a blank new-post form — would announce another
// post's failures above a form nothing was submitted to.
$blocked = $_SESSION['content_lint_block'] ?? null;
unset($_SESSION['content_lint_block']);
$lintErrors = $lintErrors ?? null;
if ($lintErrors === null && $isEdit && is_array($blocked)
    && (int) ($blocked['id'] ?? 0) === (int) ($post['_id'] ?? 0)) {
    $lintErrors = $blocked['errors'] ?? null;
}
?>
<h1 class="setupH1 setup text-center"><?php i18n("write_title"); ?></h1>
<?php if (!empty($lintErrors)): ?>
    <div class="alert alert-danger" role="alert">
        <p class="mb-1"><strong>Not saved.</strong> Fix these accessibility issues, then save again:</p>
        <ul class="mb-0">
            <?php foreach ($lintErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<form method="post" enctype="multipart/form-data" action="<?= e($action) ?>">
    <?= csrf_field() ?>
    <fieldset>
        <input type="text" name="blogPostTitle" class="blogPostTitle form-control my-2"
               placeholder="<?php i18n("write_post_title_placeholder"); ?>" required
               value="<?= e($post['title'] ?? '') ?>" />
        <input type="text" name="blogPostAuthor" class="blogPostAuthor form-control my-2"
               placeholder="<?php i18n("write_post_author_placeholder"); ?>" required
               value="<?= e($post['author'] ?? $siteConfig['author'] ?? '') ?>" />
        <input type="text" name="blogPostTags" class="form-control my-2"
               placeholder="Tags, comma-separated (optional)"
               value="<?= e(implode(', ', (array) ($post['tags'] ?? []))) ?>" />
        <?php /* Deliberately never pre-filled: stored URLs are site-relative
                 (rejected by type="url"), and round-tripping the old absolute
                 URL made every save re-download and duplicate the image. */ ?>
        <input type="url" name="blogPostImageURL" class="blogPostImageURL form-control my-2"
               placeholder="<?php i18n("write_post_image_placeholder"); ?>" />
        <?php if ($isEdit && !empty($post['imageUrl'])): ?>
            <p class="my-2">
                <img class="write-current-image" src="<?= e($post['imageUrl']) ?>" alt="">
                <small class="text-muted">Current featured image — upload a file or paste a URL to replace it.</small>
            </p>
        <?php endif; ?>
        <input type="file" name="imageUpload" accept="image/png,image/jpeg,image/gif"
               data-max-bytes="<?= (int) $uploadLimit ?>"
               class="blogPostImage form-control form-control-sm my-2" id="imageUpload" />
        <label for="imageUpload">
            <?= $isEdit ? "Uploading a file replaces the existing image. ({$uploadLimitMb} MB max)" : "Choose a file to upload ({$uploadLimitMb} MB max)" ?>
        </label>
        <input type="password" name="blogPostPassword" class="form-control my-2" autocomplete="new-password"
               placeholder="<?php i18n("write_post_password_placeholder"); ?>" />
        <?php if (!$isEdit || !empty($post['draft'])): ?>
            <label for="blogPostScheduledFor" class="mt-1">Publish automatically at (optional — drafts only)</label>
            <input type="datetime-local" name="blogPostScheduledFor" id="blogPostScheduledFor"
                   class="form-control my-2"
                   value="<?= !empty($post['scheduledFor']) ? e(date('Y-m-d\TH:i', (int) $post['scheduledFor'])) : '' ?>" />
        <?php endif; ?>
        <textarea name="blogPostContent" id="blogPostContent"
                  placeholder="<?php i18n("write_post_markdown_placeholder"); ?>"><?= e($post['content'] ?? '') ?></textarea>
    </fieldset>
    <input class="btn btn-primary mt-2" type="submit" value="<?= $isEdit ? 'Save Edits' : 'Save Post' ?>" />
</form>
<?php if ($isEdit && !empty($post['revisions'])): ?>
    <div class="mt-4">
        <h2 class="fs-5">Revisions</h2>
        <ul class="list-group">
            <?php foreach (array_reverse((array) $post['revisions'], true) as $revIndex => $rev): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                    <span><?= e(date('M j, Y H:i', (int) ($rev['savedAt'] ?? 0))) ?> &middot; <?= e((string) ($rev['title'] ?? '')) ?></span>
                    <form method="post" action="<?= e($router->generate('restoreRevision', ['id' => $post['_id']])) ?>"
                          data-confirm="Restore this revision? The current text is kept as a revision.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="revision" value="<?= (int) $revIndex ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Restore</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<div class="text-center pt-4">
    <a href="<?= e($router->generate('dashboard')) ?>" class="btn btn-sm btn-secondary">Return To Dashboard</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
