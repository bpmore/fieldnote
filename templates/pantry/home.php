<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">The shelves are empty &mdash; nothing has been put up yet. Check back after harvest.</p>
<?php else: ?>
    <div class="shelf">
        <?php foreach ($allPosts as $p): ?>
            <article class="jar-label">
                <div class="scallops" aria-hidden="true"></div>
                <div class="label-body">
                    <p class="label-kicker">Small batch</p>
                    <h2 class="label-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="label-meta"><span class="lock" aria-hidden="true">&#128274;</span> Sealed jar &mdash; password to open</p>
                    <?php else: ?>
                        <p class="label-meta">Put up by <?= e($p['author']) ?></p>
                    <?php endif; ?>
                    <p class="stamp">Batch of <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
