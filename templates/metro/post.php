<?php
use function Dropplets\e;
use function Dropplets\dpl_image_alt;
require __DIR__ . '/header.php';

$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="post">
    <header class="post-header">
        <span class="badge badge-large" aria-hidden="true">&#9679;</span>
        <div>
            <h1 class="post-title"><?= e($post['title']) ?></h1>
            <p class="station-line"><?= e($post['author']) ?> line &middot; <?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></p>
        </div>
    </header>

    <?php if (!empty($post['imageUrl'])): ?>
        <img class="post-hero" src="<?= e($post['imageUrl']) ?>" alt="<?= e(dpl_image_alt($post)) ?>">
    <?php endif; ?>

    <div class="post-content">
        <?= $parser->text($post['content']) ?>
    </div>
</article>
<div class="post-footer">
    <a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; Back to the route map</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
