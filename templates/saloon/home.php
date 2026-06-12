<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">The notice board is bare. Ride back at sundown.</p>
<?php else: ?>
    <div class="board">
        <?php foreach ($allPosts as $p): ?>
            <article class="poster">
                <p class="poster-eyebrow" aria-hidden="true">&#9733; Notice &#9733;</p>
                <h2 class="poster-title">
                    <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                </h2>
                <?php if (!empty($p['password'])): ?>
                    <p class="poster-lock"><span aria-hidden="true">&#128274;</span> Sealed by the sheriff</p>
                    <p class="poster-reward">Posted <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                <?php else: ?>
                    <?php if ($p['imageUrl'] !== ''): ?>
                        <img class="poster-image" src="<?= e($p['imageUrl']) ?>" alt="" loading="lazy">
                    <?php endif; ?>
                    <p class="poster-reward">Reward &middot; By <?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
