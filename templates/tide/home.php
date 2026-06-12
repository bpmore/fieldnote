<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">The tide has left nothing on the sand yet. Drift back soon.</p>
<?php else: ?>
    <div class="shore">
        <?php foreach ($allPosts as $p): ?>
            <article class="find">
                <?php if (!empty($p['password'])): ?>
                    <div class="find-lock" aria-hidden="true">&#128274;</div>
                <?php elseif ($p['imageUrl'] !== ''): ?>
                    <img class="find-image" src="<?= e($p['imageUrl']) ?>" alt="" loading="lazy">
                <?php endif; ?>
                <div class="find-body">
                    <h2 class="find-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="find-meta">Kept under the tide &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php else: ?>
                        <p class="find-meta">Washed ashore <?= e(date($dateFormat, (int) $p['date'])) ?> &middot; by <?= e($p['author']) ?></p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
