<?php use function Dropplets\e; ?>
    </main>
    <footer class="site-footer">
        <div class="dim-rule" aria-hidden="true"><span class="dim-label">END OF SHEET</span></div>
        <?php if (!empty($siteConfig['footer'])): ?>
            <p><?= e($siteConfig['footer']) ?></p>
        <?php endif; ?>
        <p><a class="rss-link" href="<?= e($router->generate('feed')) ?>">RSS feed</a></p>
    </footer>
</body>
</html>
