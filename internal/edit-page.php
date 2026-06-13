<?php
use function Fieldnote\e;
use function Fieldnote\csrf_field;

// Editor for the standalone profile page. Reuses the EasyMDE wiring (the
// textarea id="blogPostContent" + $needsEditor) and the same blocking-lint
// display as the post editor. $pageContent and (on a refused save) $lintErrors
// come from the editProfile route.
$needsEditor = true;
require __DIR__ . '/header.php';
$slug = (string) ($siteConfig['profilePage'] ?? '');
?>
<h1 class="setupH1 setup text-center">Edit profile page</h1>
<?php if (!empty($lintErrors)): ?>
    <div class="alert alert-danger" role="alert">
        <p class="mb-1"><strong>Not saved.</strong> Fix these accessibility issues, then save again:</p>
        <ul class="mb-0"><?php foreach ($lintErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>
<form method="post" action="<?= e($router->generate('editProfile')) ?>">
    <?= csrf_field() ?>
    <p class="text-muted">Renders at <code>/<?= e($slug) ?></code> through your theme. Markdown; the same accessibility checks as a post apply on save.</p>
    <textarea name="pageContent" id="blogPostContent"
              placeholder="Write your profile in Markdown&hellip;"><?= e($pageContent ?? '') ?></textarea>
    <input class="btn btn-primary mt-2" type="submit" value="Save profile page" />
</form>
<div class="text-center pt-4">
    <a href="<?= e($router->generate('dashboard')) ?>" class="btn btn-sm btn-secondary">Return To Dashboard</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
