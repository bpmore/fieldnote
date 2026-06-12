<?php
use function Dropplets\e;
use function Dropplets\dpl_image_alt;
require __DIR__ . '/header.php';

$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="post sheet">
    <header class="post-header">
        <h1 class="post-title"><?= e($post['title']) ?></h1>
        <div class="title-block post-block">
            <div class="tb-cell">
                <span class="tb-label">Drawn by</span>
                <span class="tb-value"><?= e($post['author']) ?></span>
            </div>
            <div class="tb-cell">
                <span class="tb-label">Date</span>
                <span class="tb-value"><?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></span>
            </div>
            <div class="tb-cell">
                <span class="tb-label">Scale</span>
                <span class="tb-value">1 : 1</span>
            </div>
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
    <a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; Back to the flat file</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
