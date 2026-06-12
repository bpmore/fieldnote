<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">NO GAMES LOADED &mdash; CHECK BACK SOON</p>
<?php else: ?>
    <div class="cabinet-list">
        <?php foreach ($allPosts as $p): ?>
            <article class="cabinet">
                <p class="score-row">
                    <span class="one-up">1UP</span>
                    <span aria-hidden="true">&middot;</span>
                    <?= e(date($dateFormat, (int) $p['date'])) ?>
                </p>
                <h2 class="cabinet-title">
                    <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                </h2>
                <?php if (!empty($p['password'])): ?>
                    <p class="cabinet-meta"><span class="lock" aria-hidden="true">&#128274;</span> LOCKED STAGE &mdash; PASSWORD REQUIRED</p>
                <?php else: ?>
                    <p class="cabinet-meta">PLAYER: <?= e($p['author']) ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
