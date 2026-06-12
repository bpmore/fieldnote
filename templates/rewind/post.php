<?php
use function Dropplets\e;
use function Dropplets\dpl_image_alt;
require __DIR__ . '/header.php';

// Safe mode keeps raw HTML and javascript: URLs out of rendered markdown.
$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="record">
    <header class="record-head">
        <div class="sunburst" aria-hidden="true"></div>
        <h1 class="record-title"><?= e($post['title']) ?></h1>
        <p class="record-meta"><?= e($post['author']) ?> &middot; <?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></p>
    </header>

    <?php if (!empty($post['imageUrl'])): ?>
        <img class="record-hero" src="<?= e($post['imageUrl']) ?>" alt="<?= e(dpl_image_alt($post)) ?>">
    <?php endif; ?>

    <div class="record-content">
        <?= $parser->text($post['content']) ?>
    </div>
</article>
<div class="record-return">
    <a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; Flip back to side A</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
