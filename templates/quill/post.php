<?php
use function Fieldnote\e;
use function Fieldnote\fn_image_alt;
require __DIR__ . '/header.php';

$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="post">
    <header class="post-header">
        <p class="post-date"><?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></p>
        <h1 class="post-title"><?= e($post['title']) ?></h1>
        <p class="post-author"><?= e($post['author']) ?></p>
    </header>

    <?php if (!empty($post['imageUrl'])): ?>
        <img class="post-hero" src="<?= e($post['imageUrl']) ?>" alt="<?= e(fn_image_alt($post)) ?>">
    <?php endif; ?>

    <div class="post-content">
        <?= $parser->text($post['content']) ?>
    </div>
</article>
<?php Fieldnote\fn_post_admin($router, $post); ?>

<nav class="post-footer">
    <a class="back-link" href="<?= e($router->generate('home')) ?>">return</a>
</nav>
<?php require __DIR__ . '/footer.php'; ?>
