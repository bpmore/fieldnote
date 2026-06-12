<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">The fire is lit, but no stories have been told yet.</p>
<?php else: ?>
    <div class="entries">
        <?php foreach ($allPosts as $p): ?>
            <article class="entry">
                <h2 class="entry-title">
                    <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                </h2>
                <?php if (!empty($p['password'])): ?>
                    <p class="entry-meta"><span aria-hidden="true">&#128274;</span> A story kept private &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                <?php else: ?>
                    <p class="entry-meta">Told by <?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
