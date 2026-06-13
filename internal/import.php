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
    <p class="text-muted">Import your writing from another platform, or a Fieldnote / markdown
        archive. Nothing is written until you confirm on the next screen, and posts whose slug
        already exists here are always skipped — importing can never overwrite or duplicate.
        Platform imports land as <strong>drafts</strong>, so the accessibility check runs when you
        publish each one.</p>
    <form method="post" enctype="multipart/form-data" action="<?= e($router->generate('import')) ?>">
        <?= csrf_field() ?>
        <label for="importSource" class="form-label mb-0">Source</label>
        <select class="form-select mb-2" name="importSource" id="importSource">
            <option value="auto">Auto-detect</option>
            <option value="markdown">Markdown / Fieldnote export (.zip of .md with frontmatter)</option>
            <option value="wordpress">WordPress (.xml export — also Squarespace)</option>
            <option value="substack">Substack (.zip export)</option>
            <option value="medium">Medium (.zip export)</option>
            <option value="ghost">Ghost (.json export)</option>
            <option value="writefreely">WriteFreely / Write.as (.json export)</option>
            <option value="rss">RSS / Atom feed (file or URL below)</option>
        </select>
        <input type="file" name="importZip" accept=".zip,.xml,.json,application/xml,text/xml,application/json,application/rss+xml,application/atom+xml" class="form-control">
        <label for="importUrl" class="form-label mb-0 mt-2">&hellip; or a feed URL</label>
        <input type="url" name="importUrl" id="importUrl" class="form-control" placeholder="https://example.com/feed.xml">
        <small class="text-muted d-block mt-1">Feeds often truncate post bodies, so RSS is a fallback — use a platform export above when you have one.</small>
        <button type="submit" class="btn btn-primary mt-3">Inspect</button>
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
        <thead><tr><th scope="col">Title</th><th scope="col">Slug</th><th scope="col">Accessibility</th><th scope="col">Action</th></tr></thead>
        <tbody>
            <?php foreach ($analysis['posts'] as $p): ?>
                <tr>
                    <td><?= e($p['title']) ?><?= $p['draft'] ? ' <span class="badge text-bg-secondary">draft</span>' : '' ?></td>
                    <td><code><?= e($p['slug']) ?></code></td>
                    <td>
                        <?php if (!empty($p['a11y'])): ?>
                            <details><summary class="text-warning"><?= count($p['a11y']) ?> to fix</summary>
                                <ul class="small mb-0"><?php foreach ($p['a11y'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul>
                            </details>
                        <?php elseif (array_key_exists('a11y', $p)): ?>
                            <span class="text-success">clean</span>
                        <?php else: ?>&mdash;<?php endif; ?>
                    </td>
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
