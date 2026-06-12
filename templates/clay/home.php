<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">Nothing here yet. Check back soon!</p>
<?php else: ?>
    <div class="slab-list">
        <?php foreach ($allPosts as $p): ?>
            <article class="slab">
                <?php if (!empty($p['password'])): ?>
                    <div class="slab-media slab-lock" aria-hidden="true">&#128274;</div>
                <?php elseif ($p['imageUrl'] !== ''): ?>
                    <div class="slab-media">
                        <img class="slab-image" src="<?= e($p['imageUrl']) ?>" alt="" loading="lazy">
                    </div>
                <?php endif; ?>
                <h2 class="slab-title">
                    <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                </h2>
                <p class="slab-meta">
                    <?php if (!empty($p['password'])): ?>
                        <span class="pill-tag">Protected</span>
                    <?php else: ?>
                        <span class="pill-tag"><?= e($p['author']) ?></span>
                    <?php endif; ?>
                    <span class="pill-tag"><?= e(date($dateFormat, (int) $p['date'])) ?></span>
                </p>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
