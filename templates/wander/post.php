<?php
use function Dropplets\e;
use function Dropplets\dpl_image_alt;
require __DIR__ . '/header.php';

// Safe mode keeps raw HTML and javascript: URLs out of rendered markdown.
$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="dispatch">
    <?php if (!empty($post['imageUrl'])): ?>
        <div class="vista">
            <img class="vista-photo" src="<?= e($post['imageUrl']) ?>" alt="<?= e(dpl_image_alt($post)) ?>">
        </div>
    <?php endif; ?>

    <header class="dispatch-head">
        <h1 class="dispatch-title"><?= e($post['title']) ?></h1>
        <p class="dispatch-meta">from <?= e($post['author']) ?> &middot; <?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></p>
    </header>

    <div class="dispatch-content">
        <?= $parser->text($post['content']) ?>
    </div>
</article>
<div class="dispatch-return">
    <a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; Back to the mailbag</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
