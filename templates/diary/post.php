<?php
use function Fieldnote\e;
use function Fieldnote\fn_image_alt;
require __DIR__ . '/header.php';

// Safe mode neutralizes raw HTML and javascript: URLs in post markdown.
$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="entry-page">
    <header class="entry-head">
        <p class="entry-date entry-date-post"><?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></p>
        <h1 class="entry-page-title"><?= e($post['title']) ?></h1>
        <p class="entry-meta">Written by <?= e($post['author']) ?></p>
    </header>

    <?php if (!empty($post['imageUrl'])): ?>
        <figure class="entry-photo">
            <img src="<?= e($post['imageUrl']) ?>" alt="<?= e(fn_image_alt($post)) ?>">
        </figure>
    <?php endif; ?>

    <div class="entry-body">
        <?= $parser->text($post['content']) ?>
    </div>
</article>
<?php Fieldnote\fn_post_admin($router, $post); ?>

<nav class="entry-return">
    <a class="return-link" href="<?= e($router->generate('home')) ?>">&larr; Back to the journal</a>
</nav>
<?php require __DIR__ . '/footer.php'; ?>
