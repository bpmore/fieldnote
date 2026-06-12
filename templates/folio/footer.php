<?php use function Dropplets\e; ?>
    </main>
    <footer class="colophon">
        <div class="ornament" aria-hidden="true"></div>
        <?php if (!empty($siteConfig['footer'])): ?>
            <p><?= e($siteConfig['footer']) ?></p>
        <?php endif; ?>
        <p><a class="foot-link" href="<?= e($router->generate('feed')) ?>">RSS</a></p>
    </footer>
</body>
</html>
