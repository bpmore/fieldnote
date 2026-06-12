<?php
use function Dropplets\e;
use function Dropplets\dpl_image_alt;
require __DIR__ . '/header.php';

// Parsedown in SAFE MODE: raw HTML and javascript: URLs in post markdown
// are neutralized instead of rendered.
$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="post">
    <header class="post-header plate">
        <div class="plate-inner">
            <div class="fan" aria-hidden="true"></div>
            <h1 class="post-title"><?= e($post['title']) ?></h1>
            <p class="post-meta">Presented by <?= e($post['author']) ?> &middot; <?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></p>
            <div class="fan fan-down" aria-hidden="true"></div>
        </div>
    </header>

    <?php if (!empty($post['imageUrl'])): ?>
        <div class="plate post-hero-frame">
            <div class="plate-inner">
                <img class="post-hero" src="<?= e($post['imageUrl']) ?>" alt="<?= e(dpl_image_alt($post)) ?>">
            </div>
        </div>
    <?php endif; ?>

    <div class="post-content">
        <?= $parser->text($post['content']) ?>
    </div>
</article>
<div class="post-footer">
    <div class="rule" aria-hidden="true"></div>
    <a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; Return to the programme</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
