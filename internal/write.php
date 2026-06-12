<?php
use function Fieldnote\e;
use function Fieldnote\csrf_field;
use function Fieldnote\fn_effective_upload_limit;

$needsEditor = true; // footer loads the EasyMDE bundle only when this is set
require __DIR__ . '/header.php';

$isEdit   = isset($post['title']);
$action   = $isEdit
    ? $router->generate('editPost', ['id' => $post['_id']])
    : $router->generate('write');
$uploadLimit   = fn_effective_upload_limit();
$uploadLimitMb = rtrim(rtrim(number_format($uploadLimit / 1048576, 1), '0'), '.');
// Per-post passwords are now hashed and never sent back to the browser. The
// field is shown blank; leaving it blank on edit keeps the existing password.
?>
<h1 class="setupH1 setup text-center"><?php i18n("write_title"); ?></h1>
<form method="post" enctype="multipart/form-data" action="<?= e($action) ?>">
    <?= csrf_field() ?>
    <fieldset>
        <input type="text" name="blogPostTitle" class="blogPostTitle form-control my-2"
               placeholder="<?php i18n("write_post_title_placeholder"); ?>" required
               value="<?= e($post['title'] ?? '') ?>" />
        <input type="text" name="blogPostAuthor" class="blogPostAuthor form-control my-2"
               placeholder="<?php i18n("write_post_author_placeholder"); ?>" required
               value="<?= e($post['author'] ?? $siteConfig['author'] ?? '') ?>" />
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
        <textarea name="blogPostContent" id="blogPostContent"
                  placeholder="<?php i18n("write_post_markdown_placeholder"); ?>"><?= e($post['content'] ?? '') ?></textarea>
    </fieldset>
    <input class="btn btn-primary mt-2" type="submit" value="<?= $isEdit ? 'Save Edits' : 'Save Post' ?>" />
</form>
<div class="text-center pt-4">
    <a href="<?= e($router->generate('dashboard')) ?>" class="btn btn-sm btn-secondary">Return To Dashboard</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
