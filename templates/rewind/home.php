<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
$trackNo = 0;
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">Side A is blank. Drop the needle later.</p>
<?php else: ?>
    <ol class="tracklist">
        <?php foreach ($allPosts as $p): $trackNo++; ?>
            <li class="track">
                <?php if (!empty($p['password'])): ?>
                    <span class="track-no" aria-hidden="true">&#128274;</span>
                <?php else: ?>
                    <span class="track-no" aria-hidden="true"><?= e(str_pad((string) $trackNo, 2, '0', STR_PAD_LEFT)) ?></span>
                <?php endif; ?>
                <div class="track-info">
                    <h2 class="track-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="track-meta">Locked groove &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php else: ?>
                        <p class="track-meta"><?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
