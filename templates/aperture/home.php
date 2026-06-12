<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">The wall is bare. New work coming soon.</p>
<?php else: ?>
    <div class="wall">
        <?php foreach ($allPosts as $p): ?>
            <?php $hasImage = empty($p['password']) && $p['imageUrl'] !== ''; ?>
            <article class="tile<?= $hasImage ? '' : ' tile-type' ?>">
                <?php if (!empty($p['password'])): ?>
                    <div class="tile-lock" aria-hidden="true">&#128274;</div>
                <?php elseif ($hasImage): ?>
                    <img class="tile-image" src="<?= e($p['imageUrl']) ?>" alt="" loading="lazy">
                <?php endif; ?>
                <div class="tile-caption">
                    <h2 class="tile-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="tile-meta">Protected &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php else: ?>
                        <p class="tile-meta"><?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
