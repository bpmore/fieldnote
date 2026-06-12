<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
$sheetNo = ($page - 1) * count($allPosts);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">No drawings on file yet. The drafting table is clear &mdash; check back soon.</p>
<?php else: ?>
    <div class="sheet-list">
        <?php foreach ($allPosts as $i => $p): ?>
            <article class="sheet">
                <div class="title-block">
                    <div class="tb-cell tb-no">
                        <span class="tb-label">Sheet</span>
                        <span class="tb-value"><?= e(sprintf('%02d', $sheetNo + $i + 1)) ?></span>
                    </div>
                    <div class="tb-cell tb-title">
                        <span class="tb-label">Title</span>
                        <h2 class="tb-heading">
                            <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                        </h2>
                    </div>
                    <div class="tb-cell">
                        <span class="tb-label">Drawn by</span>
                        <span class="tb-value"><?= !empty($p['password']) ? '<span class="lock" aria-hidden="true">&#128274;</span> Restricted' : e($p['author']) ?></span>
                    </div>
                    <div class="tb-cell">
                        <span class="tb-label">Date</span>
                        <span class="tb-value"><?= e(date($dateFormat, (int) $p['date'])) ?></span>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
