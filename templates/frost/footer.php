<?php use function Dropplets\e; ?>
    </main>
    <footer class="site-footer">
        <div class="glass footer-panel">
            <?php if (!empty($siteConfig['footer'])): ?>
                <p><?= e($siteConfig['footer']) ?></p>
            <?php endif; ?>
            <p><a class="rss-link" href="<?= e($router->generate('feed')) ?>">RSS</a></p>
        </div>
    </footer>
</body>
</html>
