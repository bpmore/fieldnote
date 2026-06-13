<?php
use function Fieldnote\e;
use function Fieldnote\fn_image_alt;
require __DIR__ . '/header.php';

// Parsedown in SAFE MODE: this is the core XSS fix. Raw HTML and javascript:
// URLs in post markdown are neutralized instead of rendered.
$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="post">
    <header class="post-header">
        <h1 class="post-title"><?= e($post['title']) ?></h1>
        <p class="post-meta">By <?= e($post['author']) ?> &middot; <?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></p>
    </header>

    <?php if (!empty($post['imageUrl'])): ?>
        <img class="post-hero" src="<?= e($post['imageUrl']) ?>" alt="<?= e(fn_image_alt($post)) ?>">
    <?php endif; ?>

    <div class="post-content">
        <?= $parser->text($post['content']) ?>
    </div>
    <?php Fieldnote\fn_tag_links($router, $post); ?>
</article>
<?php Fieldnote\fn_post_admin($router, $post); ?>

<div class="post-footer">
    <a href="<?= e($router->generate('home')) ?>">&larr; Back to all posts</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
