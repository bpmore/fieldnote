<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">Nothing on this roll yet. Check back soon!</p>
<?php else: ?>
    <div class="sheet">
        <?php foreach ($allPosts as $i => $p): ?>
            <article class="frame">
                <div class="frame-window">
                    <?php if (!empty($p['password'])): ?>
                        <span class="frame-glyph" aria-hidden="true">&#128274;</span>
                    <?php elseif ($p['imageUrl'] !== ''): ?>
                        <img src="<?= e($p['imageUrl']) ?>" alt="" loading="lazy">
                    <?php elseif ($siteConfig['OGImage'] !== ''): ?>
                        <img src="<?= e($siteConfig['OGImage']) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <span class="frame-glyph" aria-hidden="true">&#9678;</span>
                    <?php endif; ?>
                </div>
                <div class="frame-caption">
                    <p class="frame-no">frame <?= e(sprintf('%02dA', $i + 1)) ?></p>
                    <h2 class="frame-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="frame-meta">Protected &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php else: ?>
                        <p class="frame-meta"><?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
