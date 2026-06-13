<?php
use function Fieldnote\e;
use function Fieldnote\fn_image_alt;
require __DIR__ . '/header.php';

// Parsedown in SAFE MODE: raw HTML and javascript: URLs in the post
// markdown are neutralized instead of rendered.
$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="post">
    <header class="poster-head">
        <h1 class="poster-title"><?= e($post['title']) ?></h1>
        <p class="poster-band"><span><?= e($post['author']) ?></span><span><?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></span></p>
    </header>

    <?php if (!empty($post['imageUrl'])): ?>
        <img class="post-hero" src="<?= e($post['imageUrl']) ?>" alt="<?= e(fn_image_alt($post)) ?>">
    <?php endif; ?>

    <div class="post-content">
        <?= $parser->text($post['content']) ?>
    </div>

    <p class="post-back"><a href="<?= e($router->generate('home')) ?>">&larr; All dates</a></p>
</article>
<?php Fieldnote\fn_post_admin($router, $post); ?>

<?php require __DIR__ . '/footer.php'; ?>
