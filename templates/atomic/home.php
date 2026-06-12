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
    <ul class="orbit-list">
        <?php foreach ($allPosts as $p): ?>
            <li class="orbit-item">
                <article class="boomerang">
                    <h2 class="item-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?php if (!empty($p['password'])): ?><span aria-hidden="true">&#128274;&nbsp;</span><?php endif; ?><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="item-meta">Protected post &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php else: ?>
                        <p class="item-meta">By <?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php endif; ?>
                </article>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
