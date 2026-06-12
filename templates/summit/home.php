<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">No routes logged yet. The trailhead opens soon.</p>
<?php else: ?>
    <div class="routes">
        <?php foreach ($allPosts as $p): ?>
            <article class="route">
                <p class="route-marker"><?= e(date($dateFormat, (int) $p['date'])) ?></p>
                <div class="route-body">
                    <h2 class="route-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="route-meta"><span aria-hidden="true">&#128274;</span> Restricted route &middot; permit required</p>
                    <?php else: ?>
                        <p class="route-meta">Logged by <?= e($p['author']) ?></p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
