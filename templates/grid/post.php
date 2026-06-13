<?php
use function Fieldnote\e;
use function Fieldnote\fn_image_alt;
require __DIR__ . '/header.php';

// Parsedown in SAFE MODE: raw HTML and javascript: URLs in post markdown
// are neutralized instead of rendered.
$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="post">
    <header class="post-header">
        <h1 class="post-title"><?= e($post['title']) ?></h1>
    </header>
    <div class="post-grid module">
        <div class="post-side">
            <p class="side-label">date</p>
            <p class="side-value"><?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></p>
            <p class="side-label">author</p>
            <p class="side-value"><?= e($post['author']) ?></p>
        </div>
        <div class="post-main">
            <?php if (!empty($post['imageUrl'])): ?>
                <img class="post-hero" src="<?= e($post['imageUrl']) ?>" alt="<?= e(fn_image_alt($post)) ?>">
            <?php endif; ?>
            <div class="post-content">
                <?= $parser->text($post['content']) ?>
            </div>
        </div>
    </div>
</article>
<?php Fieldnote\fn_post_admin($router, $post); ?>

<div class="post-footer">
    <a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; index</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
