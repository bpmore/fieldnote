<?php
use function Fieldnote\e;
use function Fieldnote\fn_image_alt;
require __DIR__ . '/header.php';

// Parsedown in SAFE MODE: raw HTML and javascript: URLs in the post
// markdown are neutralized instead of rendered.
$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="exhibit">
    <?php if (!empty($post['imageUrl'])): ?>
        <div class="plate-frame">
            <img src="<?= e($post['imageUrl']) ?>" alt="<?= e(fn_image_alt($post)) ?>">
        </div>
    <?php endif; ?>

    <header class="exhibit-label">
        <h1 class="exhibit-title"><?= e($post['title']) ?></h1>
        <p class="exhibit-meta"><?= e($post['author']) ?>, <?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></p>
    </header>

    <div class="essay">
        <?= $parser->text($post['content']) ?>
    </div>

    <p class="exhibit-back"><a href="<?= e($router->generate('home')) ?>">&larr; Return to the exhibition</a></p>
</article>
<?php Fieldnote\fn_post_admin($router, $post); ?>

<?php require __DIR__ . '/footer.php'; ?>
