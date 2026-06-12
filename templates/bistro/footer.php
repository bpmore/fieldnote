<?php use function Dropplets\e; ?>
        </main>
        <footer class="site-footer">
            <div class="ornament" aria-hidden="true">&#10043;</div>
            <?php if (!empty($siteConfig['footer'])): ?>
                <p><?= e($siteConfig['footer']) ?></p>
            <?php endif; ?>
            <p><a class="rss-link" href="<?= e($router->generate('feed')) ?>">RSS feed</a></p>
        </footer>
    </div>
</body>
</html>
