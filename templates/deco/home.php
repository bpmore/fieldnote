<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">The programme is empty for now. Do return soon.</p>
<?php else: ?>
    <div class="plate-list">
        <?php foreach ($allPosts as $p): ?>
            <article class="plate">
                <div class="plate-inner">
                    <?php if (!empty($p['password'])): ?>
                        <div class="plate-lock" aria-hidden="true">&#128274;</div>
                    <?php elseif ($p['imageUrl'] !== ''): ?>
                        <img class="plate-image" src="<?= e($p['imageUrl']) ?>" alt="" loading="lazy">
                    <?php endif; ?>
                    <div class="rule" aria-hidden="true"></div>
                    <h2 class="plate-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="plate-meta">Private engagement &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php else: ?>
                        <p class="plate-meta"><?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php endif; ?>
                    <div class="rule" aria-hidden="true"></div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
