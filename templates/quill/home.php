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
    <p class="empty-state">The page is still blank. A first verse will arrive.</p>
<?php else: ?>
    <div class="verses">
        <?php foreach ($allPosts as $p): ?>
            <article class="verse">
                <p class="verse-date"><?= e(date($dateFormat, (int) $p['date'])) ?></p>
                <h2 class="verse-title">
                    <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                </h2>
                <?php if (!empty($p['password'])): ?>
                    <p class="verse-note">&#128274; a poem kept under lock</p>
                <?php elseif (($x = dpl_excerpt($p, 110)) !== ''): ?>
                    <p class="verse-note"><?= e($x) ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
