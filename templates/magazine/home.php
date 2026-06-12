<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_excerpt;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';
$dateFormat = i18n('dateformat', false);
$lead = $allPosts[0] ?? null;
$rest = array_slice($allPosts, 1);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if ($lead === null): ?>
    <p class="empty-state">Nothing here yet.</p>
<?php else: ?>
    <article class="hero<?= (empty($lead['password']) && $lead['imageUrl'] !== '') ? '' : ' hero-bare' ?>">
        <?php if (empty($lead['password']) && $lead['imageUrl'] !== ''): ?>
            <img class="hero-image" src="<?= e($lead['imageUrl']) ?>" alt="">
        <?php endif; ?>
        <div class="hero-body">
            <p class="kicker"><?= e(date($dateFormat, (int) $lead['date'])) ?> &middot; <?= e($lead['author']) ?></p>
            <h2 class="hero-title"><a href="<?= e(dpl_post_url($router, $lead)) ?>"><?= e($lead['title']) ?></a></h2>
            <?php if (!empty($lead['password'])): ?>
                <p class="hero-excerpt">&#128274; This story is password-protected.</p>
            <?php elseif (($x = dpl_excerpt($lead, 240)) !== ''): ?>
                <p class="hero-excerpt"><?= e($x) ?></p>
            <?php endif; ?>
        </div>
    </article>
    <?php if (!empty($rest)): ?>
        <div class="story-grid">
            <?php foreach ($rest as $p): ?>
                <article class="story">
                    <?php if (!empty($p['password'])): ?>
                        <div class="story-image story-lock" aria-hidden="true">&#128274;</div>
                    <?php elseif ($p['imageUrl'] !== ''): ?>
                        <img class="story-image" src="<?= e($p['imageUrl']) ?>" alt="" loading="lazy">
                    <?php endif; ?>
                    <p class="kicker"><?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <h3 class="story-title"><a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a></h3>
                    <?php if (empty($p['password']) && ($x = dpl_excerpt($p, 120)) !== ''): ?>
                        <p class="story-excerpt"><?= e($x) ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
