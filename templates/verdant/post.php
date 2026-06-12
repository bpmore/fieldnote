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
    <header class="post-header">
        <h1 class="post-title"><?= e($post['title']) ?></h1>
        <p class="post-byline">Cultivated by <?= e($post['author']) ?> &middot; <?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></p>
        <p class="post-orn" aria-hidden="true">&#10087;</p>
    </header>

    <?php if (!empty($post['imageUrl'])): ?>
        <img class="post-hero" src="<?= e($post['imageUrl']) ?>" alt="<?= e(dpl_image_alt($post)) ?>">
    <?php endif; ?>

    <div class="post-content">
        <?= $parser->text($post['content']) ?>
    </div>
</article>
<div class="post-footer">
    <a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; Back to the garden</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
