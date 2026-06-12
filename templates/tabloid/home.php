<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
$lead = $allPosts[0] ?? null;
$rest = array_slice($allPosts ?? [], 1);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">STOP THE PRESSES &mdash; nothing here yet!</p>
<?php else: ?>
    <article class="lead">
        <span class="burst" aria-hidden="true">EXCLUSIVE!</span>
        <?php if (!empty($lead['password'])): ?>
            <div class="lead-lock" aria-hidden="true">&#128274;</div>
        <?php elseif ($lead['imageUrl'] !== ''): ?>
            <img class="lead-image" src="<?= e($lead['imageUrl']) ?>" alt="" loading="lazy">
        <?php elseif ($siteConfig['OGImage'] !== ''): ?>
            <img class="lead-image" src="<?= e($siteConfig['OGImage']) ?>" alt="" loading="lazy">
        <?php endif; ?>
        <h2 class="lead-title">
            <a href="<?= e(dpl_post_url($router, $lead)) ?>"><?= e($lead['title']) ?></a>
        </h2>
        <?php if (!empty($lead['password'])): ?>
            <p class="lead-meta">SEALED FILES &middot; <?= e(date($dateFormat, (int) $lead['date'])) ?></p>
        <?php else: ?>
            <p class="lead-meta">BY <?= e($lead['author']) ?> &middot; <?= e(date($dateFormat, (int) $lead['date'])) ?></p>
        <?php endif; ?>
    </article>

    <?php if (!empty($rest)): ?>
        <div class="teasers">
            <?php foreach ($rest as $p): ?>
                <article class="teaser">
                    <p class="kicker"><?= !empty($p['password']) ? 'SEALED' : 'INSIDE' ?></p>
                    <h2 class="teaser-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>">
                            <?php if (!empty($p['password'])): ?><span aria-hidden="true">&#128274;</span> <?php endif; ?>
                            <?= e($p['title']) ?>
                        </a>
                    </h2>
                    <p class="teaser-meta"><?= e(date($dateFormat, (int) $p['date'])) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
