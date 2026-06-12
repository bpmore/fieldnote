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
        <p class="post-meta">
            <span class="pill-tag"><?= e($post['author']) ?></span>
            <span class="pill-tag"><?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></span>
        </p>
    </header>

    <?php if (!empty($post['imageUrl'])): ?>
        <div class="post-hero-frame">
            <img class="post-hero" src="<?= e($post['imageUrl']) ?>" alt="<?= e(dpl_image_alt($post)) ?>">
        </div>
    <?php endif; ?>

    <div class="post-content">
        <?= $parser->text($post['content']) ?>
    </div>
</article>
<div class="post-footer">
    <a class="button" href="<?= e($router->generate('home')) ?>">&larr; Back to all posts</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
