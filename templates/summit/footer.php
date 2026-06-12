<?php use function Dropplets\e; ?>
    </main>
    <footer class="site-footer">
        <div class="ridge ridge-flip" aria-hidden="true"></div>
        <div class="site-footer-inner">
            <?php if (!empty($siteConfig['footer'])): ?>
                <p><?= e($siteConfig['footer']) ?></p>
            <?php endif; ?>
            <p><a class="rss-link" href="<?= e($router->generate('feed')) ?>">RSS feed</a></p>
        </div>
    </footer>
</body>
</html>
