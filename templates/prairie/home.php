<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">Nothing on the drafting table yet. Check back soon.</p>
<?php else: ?>
    <div class="rows">
        <?php foreach ($allPosts as $p): ?>
            <article class="row">
                <div class="row-text">
                    <h2 class="row-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="row-meta"><span aria-hidden="true">&#128274;</span> Protected post</p>
                    <?php else: ?>
                        <p class="row-meta">By <?= e($p['author']) ?></p>
                    <?php endif; ?>
                </div>
                <p class="row-date"><?= e(date($dateFormat, (int) $p['date'])) ?></p>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
