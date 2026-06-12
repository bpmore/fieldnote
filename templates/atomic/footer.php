<?php use function Dropplets\e; ?>
    </main>
    <footer class="site-footer">
        <span class="starburst starburst-small" aria-hidden="true"></span>
        <?php if (!empty($siteConfig['footer'])): ?>
            <p><?= e($siteConfig['footer']) ?></p>
        <?php endif; ?>
        <p><a class="rss-link" href="<?= e($router->generate('feed')) ?>">RSS</a></p>
    </footer>
</body>
</html>
