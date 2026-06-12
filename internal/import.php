<?php
use function Fieldnote\e;
use function Fieldnote\csrf_field;
require __DIR__ . '/header.php';
?>
<h1 class="setupH1 setup text-center">Import</h1>
<div class="row"><div class="col-md-2"></div><div class="col-md-8">

<?php if ($importError !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= e($importError) ?></div>
<?php endif; ?>

<?php if ($analysis === null): ?>
    <p class="text-muted">Upload a zip of markdown posts: a Fieldnote export, or any archive of
        <code>.md</code> files with Jekyll/Hugo/Bear-style frontmatter. Nothing is written until
        you confirm on the next screen, and posts whose slug already exists here are always
        skipped — importing can never overwrite or duplicate.</p>
    <form method="post" enctype="multipart/form-data" action="<?= e($router->generate('import')) ?>">
        <?= csrf_field() ?>
        <input type="file" name="importZip" accept=".zip,application/zip" class="form-control" required>
        <button type="submit" class="btn btn-primary mt-3">Inspect archive</button>
        <a href="<?= e($router->generate('dashboard')) ?>" class="btn btn-secondary mt-3"><?php i18n("settings_dashboard_return"); ?></a>
    </form>
<?php else: ?>
    <?php
    $toCreate = array_filter($analysis['posts'], static fn (array $p): bool => !$p['collision']);
    $toSkip   = count($analysis['posts']) - count($toCreate);
    $images   = count(array_filter($toCreate, static fn (array $p): bool => $p['image'] !== ''));
    ?>
    <div class="alert alert-info" role="status">
        Will create <strong><?= count($toCreate) ?></strong> post(s) and import
        <strong><?= $images ?></strong> image(s); <strong><?= $toSkip ?></strong> skipped
        (slug already exists). Nothing has been written yet.
    </div>
    <?php if ($analysis['errors'] !== []): ?>
        <div class="alert alert-warning" role="alert">
            <ul class="mb-0"><?php foreach ($analysis['errors'] as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <table class="table table-sm">
        <thead><tr><th scope="col">File</th><th scope="col">Title</th><th scope="col">Slug</th><th scope="col">Action</th></tr></thead>
        <tbody>
            <?php foreach ($analysis['posts'] as $p): ?>
                <tr>
                    <td><code><?= e($p['file']) ?></code></td>
                    <td><?= e($p['title']) ?><?= $p['draft'] ? ' <span class="badge text-bg-secondary">draft</span>' : '' ?></td>
                    <td><code><?= e($p['slug']) ?></code></td>
                    <td><?= $p['collision'] ? '<span class="text-muted">skip — exists</span>' : 'create' . ($p['image'] !== '' ? ' + image' : '') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($toCreate !== []): ?>
        <form method="post" action="<?= e($router->generate('importConfirm')) ?>" class="d-inline">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">Import <?= count($toCreate) ?> post(s)</button>
        </form>
    <?php endif; ?>
    <a href="<?= e($router->generate('import')) ?>" class="btn btn-secondary">Cancel</a>
<?php endif; ?>

</div><div class="col-md-2"></div></div>
<?php require __DIR__ . '/footer.php'; ?>
