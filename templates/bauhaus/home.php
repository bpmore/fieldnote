<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">Nothing here yet. Check back soon!</p>
<?php else: ?>
    <ol class="plates">
        <?php foreach ($allPosts as $p): ?>
            <li class="plate">
                <span class="plate-marker<?= !empty($p['password']) ? ' is-locked' : '' ?>" aria-hidden="true"><?php if (!empty($p['password'])): ?>&#128274;<?php endif; ?></span>
                <div class="plate-body">
                    <h2 class="plate-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="plate-meta">Protected post &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php else: ?>
                        <p class="plate-meta">By <?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
