<?php use function Dropplets\e; ?>
    </main>
    <footer class="site-footer">
        <?php if (!empty($siteConfig['footer'])): ?>
            <p><?= e($siteConfig['footer']) ?></p>
        <?php endif; ?>
        <p class="footer-coord" aria-hidden="true">end of charted territory</p>
        <p><a class="rss-link" href="<?= e($router->generate('feed')) ?>">RSS feed</a></p>
    </footer>
</body>
</html>
