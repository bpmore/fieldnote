<?php
use function Dropplets\e;
use function Dropplets\dpl_image_alt;
require __DIR__ . '/header.php';

// Parsedown in SAFE MODE: raw HTML and javascript: URLs in post markdown
// are neutralized instead of rendered.
$parser = new ParsedownExtra();
$parser->setSafeMode(true);

$words = str_word_count(strip_tags((string) $post['content']));
$mins  = max(1, (int) ceil($words / 220));
$vibe  = $mins <= 2 ? 'Sprint' : ($mins <= 7 ? 'Tempo' : 'Endurance');
?>
<article class="post">
    <header class="post-header">
        <h1 class="post-title"><?= e($post['title']) ?></h1>
        <dl class="stat-bar">
            <div class="stat">
                <dt>Date</dt>
                <dd><?= e(date(i18n('dateformat', false), (int) $post['date'])) ?></dd>
            </div>
            <div class="stat">
                <dt>Coach</dt>
                <dd><?= e($post['author']) ?></dd>
            </div>
            <div class="stat">
                <dt>Pace</dt>
                <dd><?= e($mins) ?> min &middot; <?= e($vibe) ?></dd>
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
    <a class="back-link" href="<?= e($router->generate('home')) ?>">&larr; Back to the log</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
