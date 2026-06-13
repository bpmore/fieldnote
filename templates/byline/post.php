<?php
use function Fieldnote\e;
use function Fieldnote\fn_image_alt;
require __DIR__ . '/header.php';

// Safe mode neutralizes raw HTML and javascript: URLs in post markdown.
$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="piece">
    <header class="piece-head">
        <h1 class="piece-headline"><?= e($post['title']) ?></h1>
        <p class="piece-byline">By <span class="piece-author"><?= e($post['author']) ?></span> &middot; <?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></p>
    </header>

    <?php if (!empty($post['imageUrl'])): ?>
        <figure class="piece-photo">
            <img src="<?= e($post['imageUrl']) ?>" alt="<?= e(fn_image_alt($post)) ?>">
        </figure>
    <?php endif; ?>

    <div class="piece-body">
        <?= $parser->text($post['content']) ?>
    </div>
</article>
<?php Fieldnote\fn_post_admin($router, $post); ?>

<nav class="piece-return">
    <a class="return-link" href="<?= e($router->generate('home')) ?>">&larr; All stories</a>
</nav>
<?php require __DIR__ . '/footer.php'; ?>
