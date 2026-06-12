<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">The meadow is still in seed. Come back when it blooms.</p>
<?php else: ?>
    <div class="pressings">
        <?php foreach ($allPosts as $p): ?>
            <article class="pressing">
                <h2 class="pressing-title">
                    <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                </h2>
                <?php if (!empty($p['password'])): ?>
                    <p class="pressing-meta"><span aria-hidden="true">&#128274;</span> Tucked away &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                <?php else: ?>
                    <p class="pressing-meta">Gathered by <?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
