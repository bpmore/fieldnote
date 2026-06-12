<?php use function Dropplets\e; ?>
    </main>
    <footer class="site-footer">
        <div class="field-band" aria-hidden="true"></div>
        <?php if (!empty($siteConfig['footer'])): ?>
            <p><?= e($siteConfig['footer']) ?></p>
        <?php endif; ?>
        <p><a class="rss-link" href="<?= e($router->generate('feed')) ?>">RSS</a></p>
    </footer>
</body>
</html>
