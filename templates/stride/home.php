<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">No sessions logged yet. Check back soon!</p>
<?php else: ?>
    <div class="wo-list">
        <?php foreach ($allPosts as $p): ?>
            <article class="wo-card">
                <div class="wo-date" aria-hidden="true">
                    <span class="wo-day"><?= e(date('d', (int) $p['date'])) ?></span>
                    <span class="wo-mon"><?= e(date('M', (int) $p['date'])) ?></span>
                </div>
                <div class="wo-body">
                    <h2 class="wo-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <p class="wo-meta">
                        <?php if (!empty($p['password'])): ?>
                            <span class="chip">&#128274; Protected</span>
                        <?php else: ?>
                            <span class="chip"><?= e($p['author']) ?></span>
                        <?php endif; ?>
                        <time datetime="<?= e(date('Y-m-d', (int) $p['date'])) ?>"><?= e(date($dateFormat, (int) $p['date'])) ?></time>
                    </p>
                </div>
                <?php if (!empty($p['password'])): ?>
                    <div class="wo-thumb wo-lock" aria-hidden="true">&#128274;</div>
                <?php elseif ($p['imageUrl'] !== ''): ?>
                    <img class="wo-thumb" src="<?= e($p['imageUrl']) ?>" alt="" loading="lazy">
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
