<?php use function Dropplets\e; ?>
    </main>
    <footer class="site-footer">
        <p class="insert-coin" aria-hidden="true">&#9679; INSERT COIN &#9679;</p>
        <?php if (!empty($siteConfig['footer'])): ?>
            <p><?= e($siteConfig['footer']) ?></p>
        <?php endif; ?>
        <p><a class="rss-link" href="<?= e($router->generate('feed')) ?>">RSS FEED</a></p>
    </footer>
</body>
</html>
