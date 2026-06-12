<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">The oven is still warming up &mdash; nothing here yet.</p>
<?php else: ?>
    <div class="recipe-stack">
        <?php foreach ($allPosts as $p): ?>
            <article class="recipe-card">
                <?php if (!empty($p['password'])): ?>
                    <div class="recipe-lock" aria-hidden="true">&#128274;</div>
                <?php elseif ($p['imageUrl'] !== ''): ?>
                    <img class="recipe-image" src="<?= e($p['imageUrl']) ?>" alt="" loading="lazy">
                <?php elseif ($siteConfig['OGImage'] !== ''): ?>
                    <img class="recipe-image" src="<?= e($siteConfig['OGImage']) ?>" alt="" loading="lazy">
                <?php endif; ?>
                <div class="recipe-body">
                    <h2 class="recipe-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="recipe-byline">A protected recipe &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php else: ?>
                        <p class="recipe-byline">by <?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
