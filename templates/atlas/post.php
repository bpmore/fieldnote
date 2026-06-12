<?php
use function Dropplets\e;
use function Dropplets\dpl_image_alt;
require __DIR__ . '/header.php';

// Parsedown in SAFE MODE: raw HTML and javascript: URLs in post markdown
// are neutralized instead of rendered.
$parser = new ParsedownExtra();
$parser->setSafeMode(true);

$ts = (int) $post['date'];
$coord = date('d', $ts) . '°' . date('i', $ts) . '′ N · ' . date('m', $ts) . '°' . date('H', $ts) . '′ W';
?>
<article class="post">
    <header class="post-header">
        <p class="post-coord" aria-hidden="true">&#9672; <?= e($coord) ?></p>
        <h1 class="post-title"><?= e($post['title']) ?></h1>
        <p class="post-meta"><?= e(date(i18n('dateformat', false), $ts)) ?> &middot; logged by <?= e($post['author']) ?></p>
    </header>

    <?php if (!empty($post['imageUrl'])): ?>
        <img class="post-hero" src="<?= e($post['imageUrl']) ?>" alt="<?= e(dpl_image_alt($post)) ?>">
    <?php endif; ?>

    <div class="post-content">
        <?= $parser->text($post['content']) ?>
    </div>
</article>
<div class="post-footer">
    <a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; Return to base camp</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
