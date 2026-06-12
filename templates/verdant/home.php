<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">Nothing has sprouted here yet &mdash; the beds are freshly sown.</p>
<?php else: ?>
    <div class="packets">
        <?php foreach ($allPosts as $p): ?>
            <article class="packet">
                <div class="packet-band" aria-hidden="true"></div>
                <?php if (!empty($p['password'])): ?>
                    <div class="packet-lock" aria-hidden="true">&#128274;</div>
                <?php elseif ($p['imageUrl'] !== ''): ?>
                    <img class="packet-image" src="<?= e($p['imageUrl']) ?>" alt="" loading="lazy">
                <?php endif; ?>
                <div class="packet-body">
                    <h2 class="packet-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="packet-byline">Sealed packet &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php else: ?>
                        <p class="packet-byline">Cultivated by <?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php endif; ?>
                    <p class="packet-orn" aria-hidden="true">&#10087;</p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
