<?php
use function Fieldnote\e;
use function Fieldnote\fn_image_alt;
require __DIR__ . '/header.php';

$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="post jar-label">
    <div class="scallops" aria-hidden="true"></div>
    <div class="label-body">
        <header class="post-header">
            <p class="label-kicker">Small batch</p>
            <h1 class="post-title"><?= e($post['title']) ?></h1>
            <p class="label-meta">Put up by <?= e($post['author']) ?></p>
            <p class="stamp">Batch of <?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></p>
        </header>

        <?php if (!empty($post['imageUrl'])): ?>
            <img class="post-hero" src="<?= e($post['imageUrl']) ?>" alt="<?= e(fn_image_alt($post)) ?>">
        <?php endif; ?>

        <div class="post-content">
            <?= $parser->text($post['content']) ?>
        </div>
    </div>
</article>
<?php Fieldnote\fn_post_admin($router, $post); ?>

<div class="post-footer">
    <a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; Back to the pantry shelf</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
