<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">Board unpopulated. No posts soldered yet.</p>
<?php else: ?>
    <ol class="net">
        <?php foreach ($allPosts as $p): ?>
            <li class="node">
                <h2 class="node-title">
                    <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                </h2>
                <?php if (!empty($p['password'])): ?>
                    <p class="node-meta"><span aria-hidden="true">&#128274;</span> Protected &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                <?php else: ?>
                    <p class="node-meta"><?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
