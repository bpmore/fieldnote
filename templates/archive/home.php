<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_excerpt;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">Drawer empty. No cards have been filed.</p>
<?php else: ?>
    <p class="drawer-label" aria-hidden="true">Catalog &mdash; all entries</p>
    <div class="catalog">
        <?php foreach ($allPosts as $p): ?>
            <article class="index-card">
                <p class="card-stamp-row">
                    <span class="stamp"><?= e(date($dateFormat, (int) $p['date'])) ?></span>
                </p>
                <h2 class="card-title">
                    <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                </h2>
                <p class="card-meta">Filed by: <?= e($p['author']) ?></p>
                <?php if (!empty($p['password'])): ?>
                    <p class="card-note is-locked">&#128274; Restricted item &mdash; request access with a password.</p>
                <?php elseif (($x = dpl_excerpt($p, 140)) !== ''): ?>
                    <p class="card-note"><?= e($x) ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
