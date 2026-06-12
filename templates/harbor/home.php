<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">The manifest is empty &mdash; no cargo has come ashore yet.</p>
<?php else: ?>
    <ul class="manifest">
        <?php foreach ($allPosts as $p): ?>
            <li class="manifest-row">
                <div class="row-main">
                    <h2 class="row-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="row-meta"><span aria-hidden="true">&#128274;</span> Sealed cargo</p>
                    <?php else: ?>
                        <p class="row-meta">Entered by <?= e($p['author']) ?></p>
                    <?php endif; ?>
                </div>
                <p class="row-date"><?= e(date($dateFormat, (int) $p['date'])) ?></p>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
