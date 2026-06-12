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
        <p class="post-meta">
            <span class="meta-chip"><?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></span>
            <span class="meta-chip">author: <?= e($post['author']) ?></span>
        </p>
        <h1 class="post-title"><?= e($post['title']) ?></h1>
    </header>

    <?php if (!empty($post['imageUrl'])): ?>
        <img class="post-hero" src="<?= e($post['imageUrl']) ?>" alt="<?= e(dpl_image_alt($post)) ?>">
    <?php endif; ?>

    <div class="post-content">
        <?= $parser->text($post['content']) ?>
    </div>
</article>
<div class="post-footer">
    <a class="back-link" href="<?= e($router->generate('home')) ?>">cd ..</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
