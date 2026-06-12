<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">The album is empty for now. New memories coming soon.</p>
<?php else: ?>
    <div class="album">
        <?php foreach ($allPosts as $p): ?>
            <article class="snapshot">
                <?php if (!empty($p['password'])): ?>
                    <div class="snapshot-lock" aria-hidden="true">&#128274;</div>
                <?php elseif ($p['imageUrl'] !== ''): ?>
                    <img class="snapshot-photo" src="<?= e($p['imageUrl']) ?>" alt="" loading="lazy">
                <?php elseif ($siteConfig['OGImage'] !== ''): ?>
                    <img class="snapshot-photo" src="<?= e($siteConfig['OGImage']) ?>" alt="" loading="lazy">
                <?php endif; ?>
                <div class="snapshot-note">
                    <h2 class="snapshot-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="snapshot-meta">Just for family &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php else: ?>
                        <p class="snapshot-meta"><?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
