<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">No entries logged yet. The expedition sets out soon.</p>
<?php else: ?>
    <div class="log">
        <?php foreach ($allPosts as $p): ?>
            <?php
            $ts = (int) $p['date'];
            // Decorative pseudo-coordinates derived from the date.
            $coord = date('d', $ts) . '°' . date('i', $ts) . '′ N · ' . date('m', $ts) . '°' . date('H', $ts) . '′ W';
            ?>
            <article class="entry">
                <p class="entry-meta">
                    <span class="entry-coord" aria-hidden="true"><?= e($coord) ?></span>
                    <?php if (!empty($p['password'])): ?>
                        <span class="entry-lock" aria-hidden="true">&#128274;</span> Restricted entry &middot; <?= e(date($dateFormat, $ts)) ?>
                    <?php else: ?>
                        <?= e(date($dateFormat, $ts)) ?> &middot; logged by <?= e($p['author']) ?>
                    <?php endif; ?>
                </p>
                <h2 class="entry-title">
                    <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                </h2>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
