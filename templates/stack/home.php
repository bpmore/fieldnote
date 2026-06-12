<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">// nothing committed yet — check back soon</p>
<?php else: ?>
    <ol class="log" reversed>
        <?php foreach ($allPosts as $p): ?>
            <li class="log-row">
                <article class="log-entry">
                    <span class="log-badge"><?= e(date($dateFormat, (int) $p['date'])) ?></span>
                    <div class="log-main">
                        <h2 class="log-title">
                            <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                        </h2>
                        <?php if (!empty($p['password'])): ?>
                            <p class="log-meta"><span class="log-lock" aria-hidden="true">&#128274;</span> protected post</p>
                        <?php else: ?>
                            <p class="log-meta">author: <?= e($p['author']) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if (empty($p['password']) && $p['imageUrl'] !== ''): ?>
                        <img class="log-thumb" src="<?= e($p['imageUrl']) ?>" alt="" loading="lazy">
                    <?php endif; ?>
                </article>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
