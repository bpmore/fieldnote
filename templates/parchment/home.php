<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_excerpt;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">The codex is yet unwritten. The scribe will return.</p>
<?php else: ?>
    <div class="folios">
        <?php foreach ($allPosts as $p): ?>
            <article class="folio-entry">
                <h2 class="folio-title">
                    <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                </h2>
                <p class="folio-rubric"><?= e(date($dateFormat, (int) $p['date'])) ?> &middot; <?= e($p['author']) ?></p>
                <?php if (!empty($p['password'])): ?>
                    <p class="folio-text is-locked">&#128274; This folio is sealed; a password is required to break the wax.</p>
                <?php elseif (($x = dpl_excerpt($p, 160)) !== ''): ?>
                    <p class="folio-text"><?= e($x) ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
