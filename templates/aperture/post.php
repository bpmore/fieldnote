<?php
use function Dropplets\e;
use function Dropplets\dpl_image_alt;
require __DIR__ . '/header.php';

// Parsedown in SAFE MODE: raw HTML and javascript: URLs in the post
// markdown are neutralized instead of rendered.
$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="post">
    <?php if (!empty($post['imageUrl'])): ?>
        <img class="post-hero" src="<?= e($post['imageUrl']) ?>" alt="<?= e(dpl_image_alt($post)) ?>">
    <?php endif; ?>

    <div class="post-body">
        <header class="post-header">
            <h1 class="post-title"><?= e($post['title']) ?></h1>
            <p class="post-meta"><?= e($post['author']) ?> &middot; <?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></p>
        </header>
        <div class="post-content">
            <?= $parser->text($post['content']) ?>
        </div>
        <p class="post-back"><a href="<?= e($router->generate('home')) ?>">&larr; Back to the wall</a></p>
    </div>
</article>
<?php require __DIR__ . '/footer.php'; ?>
