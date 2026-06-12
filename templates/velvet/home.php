<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">The salon is open, but nothing hangs on the walls yet.</p>
<?php else: ?>
    <div class="gallery">
        <?php foreach ($allPosts as $p): ?>
            <article class="panel">
                <?php if (!empty($p['password'])): ?>
                    <div class="panel-lock" aria-hidden="true">&#128274;</div>
                <?php elseif ($p['imageUrl'] !== ''): ?>
                    <img class="panel-image" src="<?= e($p['imageUrl']) ?>" alt="" loading="lazy">
                <?php endif; ?>
                <div class="panel-body">
                    <h2 class="panel-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="panel-meta">Private collection &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php else: ?>
                        <p class="panel-meta"><?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
