<?php
use function Dropplets\e;
use function Dropplets\dpl_image_alt;
require __DIR__ . '/header.php';

// Parsedown in SAFE MODE: raw HTML and javascript: URLs in post markdown
// are neutralized instead of rendered.
$parser = new ParsedownExtra();
$parser->setSafeMode(true);
?>
<article class="post">
    <header class="memo">
        <h1 class="post-title"><?= e($post['title']) ?></h1>
        <dl class="memo-meta">
            <div class="memo-line">
                <dt>From</dt>
                <dd><?= e($post['author']) ?></dd>
            </div>
            <div class="memo-line">
                <dt>Date</dt>
                <dd><?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></dd>
            </div>
        </dl>
    </header>

    <?php if (!empty($post['imageUrl'])): ?>
        <img class="post-hero" src="<?= e($post['imageUrl']) ?>" alt="<?= e(dpl_image_alt($post)) ?>">
    <?php endif; ?>

    <div class="post-content">
        <?= $parser->text($post['content']) ?>
    </div>
</article>
<div class="post-footer">
    <a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; Return to the register</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
