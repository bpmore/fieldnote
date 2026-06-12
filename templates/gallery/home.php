<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">The exhibition is being installed. Please return soon.</p>
<?php else: ?>
    <div class="plates">
        <?php foreach ($allPosts as $p): ?>
            <article class="plate">
                <div class="plate-frame">
                    <?php if (!empty($p['password'])): ?>
                        <div class="plate-lock" aria-hidden="true">&#128274;</div>
                    <?php elseif ($p['imageUrl'] !== ''): ?>
                        <img src="<?= e($p['imageUrl']) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <div class="plate-mono" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim($p['title']) !== '' ? $p['title'] : 'U', 0, 1))) ?></div>
                    <?php endif; ?>
                </div>
                <div class="plate-label">
                    <h2 class="plate-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="plate-meta">Private collection, <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php else: ?>
                        <p class="plate-meta"><?= e($p['author']) ?>, <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
