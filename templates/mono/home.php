<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">Nothing here yet.</p>
<?php else: ?>
    <ul class="index">
        <?php foreach ($allPosts as $p): ?>
            <li class="index-item">
                <h2 class="index-title">
                    <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                </h2>
                <p class="index-meta">
                    <?php if (!empty($p['password'])): ?>
                        <span aria-hidden="true">&#128274;</span> Protected &middot; <?= e(date($dateFormat, (int) $p['date'])) ?>
                    <?php else: ?>
                        <?= e(date($dateFormat, (int) $p['date'])) ?>
                    <?php endif; ?>
                </p>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
