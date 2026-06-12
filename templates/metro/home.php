<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
$stop = ($page - 1) * count($allPosts);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">No service on this line yet. Stations are being built &mdash; check back soon.</p>
<?php else: ?>
    <ol class="route">
        <?php foreach ($allPosts as $i => $p): ?>
            <li class="station">
                <span class="badge" aria-hidden="true"><?= e((string) ($stop + $i + 1)) ?></span>
                <div class="station-body">
                    <h2 class="station-name">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="station-line"><span class="lock" aria-hidden="true">&#128274;</span> Restricted access &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php else: ?>
                        <p class="station-line"><?= e($p['author']) ?> line &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
