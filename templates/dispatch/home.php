<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">The mailbag is empty. Check back soon!</p>
<?php else: ?>
    <div class="mailbag">
        <?php foreach ($allPosts as $p): ?>
            <article class="envelope">
                <div class="airmail" aria-hidden="true"></div>
                <div class="envelope-body">
                    <div class="stamp">
                        <span class="stamp-label">Postmarked</span>
                        <span class="stamp-date"><?= e(date($dateFormat, (int) $p['date'])) ?></span>
                    </div>
                    <p class="envelope-from">
                        <?php if (!empty($p['password'])): ?>
                            <span aria-hidden="true">&#128274;</span> Sealed dispatch
                        <?php else: ?>
                            From: <?= e($p['author']) ?>
                        <?php endif; ?>
                    </p>
                    <h2 class="envelope-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
