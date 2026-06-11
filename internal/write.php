<?php
use function Dropplets\e;
use function Dropplets\csrf_field;

$needsEditor = true; // footer loads the EasyMDE bundle only when this is set
require __DIR__ . '/header.php';

$isEdit   = isset($post['title']);
$action   = $isEdit
    ? $router->generate('editPost', ['id' => $post['_id']])
    : $router->generate('write');
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
               value="<?= e($post['author'] ?? '') ?>" />
        <input type="url" name="blogPostImageURL" class="blogPostImageURL form-control my-2"
               placeholder="<?php i18n("write_post_image_placeholder"); ?>"
               value="<?= e($post['imageUrl'] ?? '') ?>" />
        <input type="file" name="imageUpload" accept="image/png,image/jpeg,image/gif"
               class="blogPostImage form-control form-control-sm my-2" id="imageUpload" />
        <label for="imageUpload">
            <?= $isEdit ? 'Uploading a file replaces the existing image. (10 MB max)' : 'Choose a file to upload (10 MB max)' ?>
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
