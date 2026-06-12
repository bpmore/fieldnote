<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
use function Dropplets\dpl_excerpt;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
$posts = array_values($allPosts);
$lead = $posts[0] ?? null;
$rest = array_slice($posts, 1);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if ($lead === null): ?>
    <p class="empty-state">No dispatches yet. Check back soon.</p>
<?php else: ?>
    <article class="lead">
        <?php if (!empty($lead['password'])): ?>
            <div class="lead-lock" aria-hidden="true">&#128274;</div>
        <?php elseif ($lead['imageUrl'] !== ''): ?>
            <img class="lead-image" src="<?= e($lead['imageUrl']) ?>" alt="" loading="lazy">
        <?php elseif ($siteConfig['OGImage'] !== ''): ?>
            <img class="lead-image" src="<?= e($siteConfig['OGImage']) ?>" alt="" loading="lazy">
        <?php endif; ?>
        <p class="kicker"><span class="kicker-flag">Latest</span> <?= e(date($dateFormat, (int) $lead['date'])) ?></p>
        <h2 class="lead-title">
            <a href="<?= e(dpl_post_url($router, $lead)) ?>"><?= e($lead['title']) ?></a>
        </h2>
        <?php if (!empty($lead['password'])): ?>
            <p class="lead-meta">Protected dispatch</p>
        <?php else: ?>
            <?php $excerpt = dpl_excerpt($lead); ?>
            <?php if ($excerpt !== ''): ?>
                <p class="lead-excerpt"><?= e($excerpt) ?></p>
            <?php endif; ?>
            <p class="lead-meta">By <?= e($lead['author']) ?></p>
        <?php endif; ?>
    </article>

    <?php if (!empty($rest)): ?>
        <div class="ledger">
            <?php foreach ($rest as $p): ?>
                <article class="row">
                    <p class="dateline"><?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <h2 class="row-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="byline"><span aria-hidden="true">&#128274;</span> Protected</p>
                    <?php else: ?>
                        <p class="byline">By <?= e($p['author']) ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
