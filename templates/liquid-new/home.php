<?php
use function Dropplets\e;
require __DIR__ . '/header.php';

$dateFormat = i18n('dateformat', false);
?>
<?php if (empty($allPosts)): ?>
    <p class="empty-state">Nothing here yet. Check back soon!</p>
<?php else: ?>
    <div class="post-grid">
        <?php foreach ($allPosts as $p): ?>
            <article class="card">
                <?php if (!empty($p['password'])): ?>
                    <div class="card-lock" aria-hidden="true">&#128274;</div>
                <?php elseif ($p['imageUrl'] !== ''): ?>
                    <img class="card-image" src="<?= e($p['imageUrl']) ?>" alt="" loading="lazy">
                <?php elseif ($siteConfig['OGImage'] !== ''): ?>
                    <img class="card-image" src="<?= e($siteConfig['OGImage']) ?>" alt="" loading="lazy">
                <?php endif; ?>
                <div class="card-body">
                    <h2 class="card-title">
                        <a href="<?= e($router->generate('post', ['id' => $p['_id']])) ?>"><?= e($p['title']) ?></a>
                    </h2>
                    <?php if (!empty($p['password'])): ?>
                        <p class="card-meta">Protected post &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php else: ?>
                        <p class="card-meta">By <?= e($p['author']) ?> &middot; <?= e(date($dateFormat, (int) $p['date'])) ?></p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($numPages > 1): ?>
    <nav aria-label="Pages">
        <ul class="pagination">
            <?php if ($page > 1): ?>
                <li><a href="<?= e($page === 2 ? $router->generate('home') : $router->generate('posts', ['page' => $page - 1])) ?>" rel="prev">&larr;</a></li>
            <?php endif; ?>
            <?php for ($p = 1; $p <= $numPages; $p++): ?>
                <?php if ($p === $page): ?>
                    <li><span class="current" aria-current="page"><?= $p ?></span></li>
                <?php else: ?>
                    <li><a href="<?= e($p === 1 ? $router->generate('home') : $router->generate('posts', ['page' => $p])) ?>"><?= $p ?></a></li>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $numPages): ?>
                <li><a href="<?= e($router->generate('posts', ['page' => $page + 1])) ?>" rel="next">&rarr;</a></li>
            <?php endif; ?>
        </ul>
    </nav>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
