<?php use function Dropplets\e; ?>
    </main>
    <footer class="site-footer">
        <p class="footer-stars" aria-hidden="true">&#9733; &#9733; &#9733;</p>
        <?php if (!empty($siteConfig['footer'])): ?>
            <p><?= e($siteConfig['footer']) ?></p>
        <?php endif; ?>
        <p><a class="rss-link" href="<?= e($router->generate('feed')) ?>">RSS</a></p>
    </footer>
</body>
</html>
