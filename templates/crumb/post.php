<?php
use function Fieldnote\e;
use function Fieldnote\fn_image_alt;
require __DIR__ . '/header.php';

// Safe mode keeps raw HTML and javascript: URLs out of rendered markdown.
$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="recipe-page">
    <header class="recipe-head">
        <h1 class="recipe-page-title"><?= e($post['title']) ?></h1>
        <p class="recipe-page-byline">by <?= e($post['author']) ?> &middot; <?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></p>
        <div class="crumb-rule" aria-hidden="true"></div>
    </header>

    <?php if (!empty($post['imageUrl'])): ?>
        <img class="recipe-hero" src="<?= e($post['imageUrl']) ?>" alt="<?= e(fn_image_alt($post)) ?>">
    <?php endif; ?>

    <div class="recipe-content">
        <?= $parser->text($post['content']) ?>
    </div>
</article>
<?php Fieldnote\fn_post_admin($router, $post); ?>

<div class="recipe-return">
    <a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; Back to the kitchen</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
