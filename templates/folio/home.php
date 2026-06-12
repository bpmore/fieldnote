<?php
use function Dropplets\e;
use function Dropplets\dpl_post_url;
use function Dropplets\dpl_pagination;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<h1 class="sr-only"><?= e($siteName) ?></h1>
<section class="toc" aria-label="Table of contents">
    <h2 class="toc-heading">Contents</h2>
    <?php if (empty($allPosts)): ?>
        <p class="empty-state">The pages of this volume are still being written.</p>
    <?php else: ?>
        <ol class="toc-list">
            <?php foreach ($allPosts as $p): ?>
                <li class="toc-row">
                    <a class="toc-link" href="<?= e(dpl_post_url($router, $p)) ?>">
                        <span class="toc-title">
                            <?php if (!empty($p['password'])): ?>
                                <span class="toc-lock" aria-hidden="true">&#128274;</span>
                            <?php endif; ?>
                            <?= e($p['title']) ?>
                        </span>
                        <span class="toc-leader" aria-hidden="true"></span>
                        <span class="toc-date"><?= e(date($dateFormat, (int) $p['date'])) ?></span>
                    </a>
                    <?php if (!empty($p['password'])): ?>
                        <p class="toc-byline">A sealed chapter</p>
                    <?php else: ?>
                        <p class="toc-byline">by <?= e($p['author']) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>

<?php dpl_pagination($router, $page, $numPages); ?>
<?php require __DIR__ . '/footer.php'; ?>
