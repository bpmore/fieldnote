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
    <header class="post-head panel">
        <div class="panel-body">
            <h1 class="post-title"><?= e($post['title']) ?></h1>
            <p class="panel-meta"><?= e($post['author']) ?> &middot; <?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></p>
        </div>
    </header>

    <?php if (!empty($post['imageUrl'])): ?>
        <img class="post-hero" src="<?= e($post['imageUrl']) ?>" alt="<?= e(fn_image_alt($post)) ?>">
    <?php endif; ?>

    <div class="post-content">
        <?= $parser->text($post['content']) ?>
    </div>

    <p class="post-back"><a href="<?= e($router->generate('home')) ?>">&larr; return to feed</a></p>
</article>
<?php Fieldnote\fn_post_admin($router, $post); ?>

<?php require __DIR__ . '/footer.php'; ?>
