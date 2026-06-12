<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">The board has been wiped clean. Class resumes soon.</p>
<?php else: ?>
    <ol class="lessons">
        <?php foreach ($allPosts as $p): ?>
            <li class="lesson">
                <article>
                    <h2 class="lesson-title">
                        <a href="<?= e(dpl_post_url($router, $p)) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="lesson-meta"><span class="date-tag"><span aria-hidden="true">&#128274;</span> Held back &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></span></p>
                    <?php else: ?>
                        <p class="lesson-meta"><span class="date-tag"><?= e(date($dateFormat, (int) $p['date'])) ?></span> by <?= e($p['author']) ?></p>
                    <?php endif; ?>
                </article>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
