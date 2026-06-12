<?php
use function Dropplets\e;
use function Dropplets\dpl_image_alt;
require __DIR__ . '/header.php';

$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="post">
    <header class="post-header">
        <h1 class="post-title"><?= e($post['title']) ?></h1>
        <p class="post-meta"><?= e($post['author']) ?> &middot; <?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></p>
    </header>

    <?php if (!empty($post['imageUrl'])): ?>
        <figure class="post-figure">
            <img class="post-hero" src="<?= e($post['imageUrl']) ?>" alt="<?= e(dpl_image_alt($post)) ?>">
        </figure>
    <?php endif; ?>

    <div class="post-content">
        <?= $parser->text($post['content']) ?>
    </div>
</article>
<nav class="post-footer">
    <a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; Return to the index</a>
</nav>
<?php require __DIR__ . '/footer.php'; ?>
