<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
use function Dropplets\dpl_excerpt;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">No stories filed yet. The presses are warming up.</p>
<?php else: ?>
    <div class="headline-stack">
        <?php foreach ($allPosts as $i => $p): ?>
            <article class="story<?= $i === 0 ? ' story-lead' : '' ?>">
                <p class="story-meta">
                    <?php if (!empty($p['password'])): ?>
                        <span class="story-lock" aria-hidden="true">&#128274;</span>
                        Protected &middot; <?= e(date($dateFormat, (int) $p['date'])) ?>
                    <?php else: ?>
                        <?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?>
                    <?php endif; ?>
                </p>
                <h2 class="story-headline">
                    <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                </h2>
                <?php if (empty($p['password'])): ?>
                    <?php $standfirst = dpl_excerpt($p, 200); ?>
                    <?php if ($standfirst !== ''): ?>
                        <p class="story-standfirst"><?= e($standfirst) ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="story-standfirst">This story is held under embargo. A password is required to read it.</p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
