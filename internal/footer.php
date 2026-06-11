<?php use function Dropplets\e; ?>
    </div>
    <?php if (!empty($needsEditor)): ?>
        <script src="<?= e($siteConfig['basePath']) ?>/static/vendor/easymde.min.js" defer></script>
    <?php endif; ?>
    <script src="<?= e($siteConfig['basePath']) ?>/static/admin.js" defer></script>
</body>
</html>
