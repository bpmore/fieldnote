<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">No postcards have arrived yet. The mail is slow from far away.</p>
<?php else: ?>
    <div class="mailbag">
        <?php foreach ($allPosts as $p): ?>
            <article class="postcard">
                <?php if (!empty($p['password'])): ?>
                    <div class="postcard-lock" aria-hidden="true">&#128274;</div>
                <?php elseif ($p['imageUrl'] !== ''): ?>
                    <img class="postcard-photo" src="<?= e($p['imageUrl']) ?>" alt="" loading="lazy">
                <?php elseif ($siteConfig['OGImage'] !== ''): ?>
                    <img class="postcard-photo" src="<?= e($siteConfig['OGImage']) ?>" alt="" loading="lazy">
                <?php else: ?>
                    <div class="postcard-blank" aria-hidden="true"></div>
                <?php endif; ?>
                <h2 class="postcard-title">
                    <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                </h2>
                <?php if (!empty($p['password'])): ?>
                    <p class="postcard-caption">sealed envelope &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                <?php else: ?>
                    <p class="postcard-caption">from <?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
