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
    <p class="empty-state">Nothing here yet.</p>
<?php else: ?>
    <?php foreach ($allPosts as $p): ?>
        <article class="entry">
            <h2 class="entry-title"><a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a></h2>
            <p class="entry-meta"><?= e(date($dateFormat, (int) $p['date'])) ?> &middot; <?= e($p['author']) ?></p>
            <?php if (!empty($p['password'])): ?>
                <p class="entry-excerpt">&#128274; This post is password-protected.</p>
            <?php elseif (($x = dpl_excerpt($p, 220)) !== ''): ?>
                <p class="entry-excerpt"><?= e($x) ?></p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
<?php endif; ?>
<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
