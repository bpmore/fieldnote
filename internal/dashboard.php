<?php
use function Fieldnote\e;
use function Fieldnote\csrf_field;
use function Fieldnote\fn_post_url;
require __DIR__ . '/header.php';
setlocale(LC_ALL, i18n('locale', false));

// Render the action buttons for one post row. Destructive actions are POST
// forms (publish/hide/delete), each carrying a CSRF token, so they can no
// longer be triggered by a forged GET from a malicious page. The delete
// confirmation runs from admin.js via data-confirm (CSP: no inline handlers).
$renderActions = function (array $p, bool $isDraft) use ($router, $siteConfig) {
    $toggleRoute = $isDraft ? 'publish' : 'hide';
    $toggleLabel = $isDraft ? i18n('dashboard_publish', false) : i18n('dashboard_draft', false);
    ob_start(); ?>
    <form method="post" action="<?= e($router->generate($toggleRoute, ['id' => $p['_id']])) ?>" class="d-inline">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-outline-primary"><?= e($toggleLabel) ?></button>
    </form>
    <a href="<?= e($router->generate('editPost', ['id' => $p['_id']])) ?>" class="btn btn-sm btn-outline-secondary"><?php i18n("dashboard_edit"); ?></a>
    <form method="post" action="<?= e($router->generate('deletePost', ['id' => $p['_id']])) ?>" class="d-inline"
          data-confirm="Delete this post permanently?">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-outline-danger"><?php i18n("dashboard_delete"); ?></button>
    </form>
    <?php if ($isDraft):
        $shareExp = time() + 14 * 86400;
        $shareUrl = rtrim((string) $siteConfig['domain'], '/') . $router->generate('draftShare', [
            'id' => $p['_id'], 'exp' => $shareExp, 'token' => Fieldnote\fn_draft_token((int) $p['_id'], $shareExp),
        ]); ?>
        <details class="d-inline-block align-middle">
            <summary class="btn btn-sm btn-outline-secondary">Share</summary>
            <input type="text" readonly class="form-control form-control-sm mt-1"
                   value="<?= e($shareUrl) ?>" aria-label="Draft share link, valid for 14 days">
        </details>
    <?php endif; ?>
    <?php return ob_get_clean();
};

$renderList = function (array $posts, bool $isDraft) use ($router, $renderActions) {
    ob_start(); ?>
    <ul class="list-group">
        <?php foreach ($posts as $p): ?>
            <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
                <div>
                    <a class="fs-5 fw-semibold text-decoration-none" href="<?= e(fn_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    <div class="text-muted small">
                        <?php i18n("dashboard_posted_by"); ?> <?= e($p['author']) ?> &middot; <?= e(date(i18n('dashboard_post_fulldate', false), (int) $p['date'])) ?>
                        <?php if ($isDraft && !empty($p['scheduledFor'])): ?>
                            &middot; <span class="badge text-bg-info">publishes <?= e(date('M j, H:i', (int) $p['scheduledFor'])) ?></span>
                        <?php endif; ?>
                        <?php if ($isDraft && !empty($p['scheduleBlocked'])): ?>
                            &middot; <span class="badge text-bg-warning">scheduled publish held — fails the accessibility check; edit, then publish or reschedule</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="d-flex gap-1 flex-wrap"><?= $renderActions($p, $isDraft) ?></div>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php return ob_get_clean();
};
?>
<h1 class="setupH1 setup text-center"><?php i18n("dashboard_title"); ?></h1>
<?php
$importResult = $_SESSION['import_result'] ?? null;
unset($_SESSION['import_result']);
if (is_array($importResult)): ?>
    <div class="alert alert-success mt-3" role="status">
        Import finished: <?= (int) $importResult['created'] ?> created,
        <?= (int) $importResult['images'] ?> image(s),
        <?= (int) $importResult['skipped'] ?> skipped (already existed).
        <?php if (!empty($importResult['errors'])): ?>
            <ul class="mb-0 mt-1"><?php foreach ((array) $importResult['errors'] as $err): ?><li><?= e((string) $err) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if (in_array((string) ($siteConfig['profilePage'] ?? 'off'), Fieldnote\Config::PROFILE_SLUGS, true)): ?>
    <p class="text-center mt-2"><a href="<?= e($router->generate('editProfile')) ?>">Edit your profile page (/<?= e((string) $siteConfig['profilePage']) ?>)</a></p>
<?php endif; ?>
<?php
$contentLint = $_SESSION['content_lint'] ?? null;
unset($_SESSION['content_lint']);
if (is_array($contentLint) && !empty($contentLint['warnings'])): ?>
    <div class="alert alert-warning mt-3" role="status">
        <strong>Saved.</strong> Accessibility suggestions for &ldquo;<?= e((string) $contentLint['title']) ?>&rdquo;:
        <ul class="mb-0 mt-2">
            <?php foreach ((array) $contentLint['warnings'] as $w): ?>
                <li><?= e((string) $w) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<div class="text-center">
    <a href="<?= e($router->generate('write')) ?>" class="btn btn-primary"><?php i18n("dashboard_write_post"); ?></a>
    <a href="<?= e($router->generate('themes')) ?>" class="btn btn-secondary">Themes</a>
    <a href="<?= e($router->generate('settings')) ?>" class="btn btn-secondary"><?php i18n("dashboard_settings"); ?></a>
    <a href="<?= e($router->generate('export')) ?>" class="btn btn-outline-secondary">Export</a>
    <a href="<?= e($router->generate('import')) ?>" class="btn btn-outline-secondary">Import</a>
    <form method="post" action="<?= e($router->generate('logout')) ?>" class="d-inline">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-danger"><?php i18n("dashboard_logout"); ?></button>
    </form>
</div>

<?php if ($draftPostCount > 0): ?>
    <div class="mt-5">
        <h2><?php i18n("dashboard_draft_post"); ?> <span class="badge text-bg-secondary align-middle"><?= (int) $draftPostCount ?></span></h2>
        <?= $renderList($draftPosts, true) ?>
    </div>
<?php endif; ?>

<?php if ($publishedPostCount > 0): ?>
    <div class="mt-5">
        <h2><?php i18n("dashboard_published_post"); ?> <span class="badge text-bg-success align-middle"><?= (int) $publishedPostCount ?></span></h2>
        <?= $renderList($publishedPosts, false) ?>
    </div>
<?php endif; ?>

<?php if ($draftPostCount === 0 && $publishedPostCount === 0): ?>
    <p class="text-center text-muted mt-5">No posts yet. Time to write your first one!</p>
<?php endif; ?>

<?php
if (!empty($siteConfig['statsEnabled'])):
    $viewTotals = (new Fieldnote\Stats(FN_DATA_DIR))->totals(30);
    $titleBySlug = [];
    foreach (array_merge($publishedPosts, $draftPosts) as $vp) {
        $titleBySlug[(string) ($vp['slug'] ?? '')] = (string) ($vp['title'] ?? '');
    }
    if ($viewTotals !== []): ?>
    <div class="mt-5">
        <h2>Views <span class="badge text-bg-secondary align-middle"><?= array_sum($viewTotals) ?></span>
            <small class="text-muted fs-6">last 30 days &middot; cookie-less, no IPs stored</small></h2>
        <table class="table table-sm">
            <thead><tr><th scope="col">Post</th><th scope="col" class="text-end">Views</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($viewTotals, 0, 10, true) as $slug => $views): ?>
                    <tr>
                        <td><?= e($titleBySlug[$slug] ?? $slug) ?></td>
                        <td class="text-end"><?= (int) $views ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
