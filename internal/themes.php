<?php
use function Fieldnote\e;
use function Fieldnote\csrf_field;
use function Fieldnote\fn_template_names;
require __DIR__ . '/header.php';
?>
<h1 class="setupH1 setup text-center">Themes</h1>
<p class="text-center text-muted">Live previews of every installed theme, light and dark. Applying changes the public site immediately.</p>
<div class="text-center mb-4">
    <a href="<?= e($router->generate('dashboard')) ?>" class="btn btn-secondary"><?php i18n("settings_dashboard_return"); ?></a>
    <a href="<?= e($router->generate('settings')) ?>" class="btn btn-secondary"><?php i18n("dashboard_settings"); ?></a>
</div>
<div class="theme-grid">
    <?php foreach (fn_template_names() as $tplName): ?>
        <?php $isCurrent = ($siteConfig['template'] === $tplName); ?>
        <section class="card theme-card" aria-label="Theme <?= e($tplName) ?>">
            <div class="card-body">
                <h2 class="fs-5 card-title d-flex justify-content-between align-items-center">
                    <?= e($tplName) ?>
                    <?php if ($isCurrent): ?><span class="badge text-bg-success">Current theme</span><?php endif; ?>
                </h2>
                <div class="theme-previews">
                    <?php foreach (['light', 'dark'] as $scheme): ?>
                        <div class="theme-frame">
                            <!-- allow-same-origin (without allow-scripts) keeps the session
                                 cookie on the request — a fully sandboxed frame gets an opaque
                                 origin and the auth-gated preview would render the login page.
                                 Scripts, forms, and navigation stay blocked. -->
                            <iframe src="<?= e($router->generate('themePreview', ['theme' => $tplName]) . '?scheme=' . $scheme) ?>"
                                    title="Preview of <?= e($tplName) ?>, <?= $scheme ?> scheme"
                                    loading="lazy" sandbox="allow-same-origin" tabindex="-1"></iframe>
                            <span class="theme-frame-label" aria-hidden="true"><?= ucfirst($scheme) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!$isCurrent): ?>
                    <form method="post" action="<?= e($router->generate('applyTheme')) ?>" class="mt-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="theme" value="<?= e($tplName) ?>">
                        <button type="submit" class="btn btn-sm btn-primary">Apply <?= e($tplName) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
