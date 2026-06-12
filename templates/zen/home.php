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
    <ul class="zen-list">
    <?php foreach ($allPosts as $p): ?>
        <li>
            <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?><?= !empty($p['password']) ? ' &#128274;' : '' ?></a>
            <time><?= e(date($dateFormat, (int) $p['date'])) ?></time>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
