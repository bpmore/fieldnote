<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">nothing published yet</p>
<?php else: ?>
    <div class="index">
        <?php foreach ($allPosts as $p): ?>
            <article class="index-row module">
                <div class="index-meta">
                    <p class="index-date"><?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php if (!empty($p['password'])): ?>
                        <p class="index-author"><span aria-hidden="true">&#128274;</span> protected</p>
                    <?php else: ?>
                        <p class="index-author"><?= e($p['author']) ?></p>
                    <?php endif; ?>
                </div>
                <h2 class="index-title">
                    <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                </h2>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
