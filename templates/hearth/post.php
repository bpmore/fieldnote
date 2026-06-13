<?php
use function Fieldnote\e;
use function Fieldnote\fn_image_alt;
require __DIR__ . '/header.php';

// Safe mode keeps raw HTML and javascript: URLs out of rendered markdown.
$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="story">
    <header class="story-head">
        <h1 class="story-title"><?= e($post['title']) ?></h1>
        <p class="story-meta"><?= e($post['author']) ?> &middot; <?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></p>
    </header>

    <?php if (!empty($post['imageUrl'])): ?>
        <img class="story-photo" src="<?= e($post['imageUrl']) ?>" alt="<?= e(fn_image_alt($post)) ?>">
    <?php endif; ?>

    <div class="story-content">
        <?= $parser->text($post['content']) ?>
    </div>
</article>
<?php Fieldnote\fn_post_admin($router, $post); ?>

<div class="story-return">
    <a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; Back to the album</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
