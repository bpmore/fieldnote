<?php
use function Dropplets\e;
use function Dropplets\dpl_image_alt;
require __DIR__ . '/header.php';

// Safe mode neutralizes raw HTML and javascript: URLs in post markdown.
$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="chapter">
    <header class="chapter-head">
        <p class="chapter-kicker"><?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></p>
        <h1 class="chapter-title"><?= e($post['title']) ?></h1>
        <p class="chapter-byline">by <?= e($post['author']) ?></p>
        <div class="ornament" aria-hidden="true"></div>
    </header>

    <?php if (!empty($post['imageUrl'])): ?>
        <figure class="chapter-plate">
            <img src="<?= e($post['imageUrl']) ?>" alt="<?= e(dpl_image_alt($post)) ?>">
        </figure>
    <?php endif; ?>

    <div class="chapter-body">
        <?= $parser->text($post['content']) ?>
    </div>

    <div class="ornament" aria-hidden="true"></div>
</article>
<nav class="chapter-return">
    <a class="return-link" href="<?= e($router->generate('home')) ?>">&larr; Return to the contents</a>
</nav>
<?php require __DIR__ . '/footer.php'; ?>
