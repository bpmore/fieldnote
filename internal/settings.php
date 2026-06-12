<?php
use function Fieldnote\e;
use function Fieldnote\csrf_field;
require __DIR__ . '/header.php';
$isNew = ($siteConfig['name'] === '');
?>
<div class="row my-1">
    <div class="col-md-3"></div>
    <div class="col-md-6 text-center">
        <?php if ($isNew): ?>
            <h1><?php i18n("settings_blog_creation"); ?></h1><h3><?php i18n("settings_first_time"); ?></h3>
        <?php else: ?>
            <h1><?php i18n("settings_blog_edition"); ?></h1><h3><?php i18n("settings_welcome_back"); ?></h3>
        <?php endif; ?>
    </div>
    <div class="col-md-3"></div>
</div>
<div class="row my-1">
    <div class="col-md-3"></div>
    <div class="col-md-6">
        <form method="post" action="<?= e($router->generate('settings')) ?>">
            <?= csrf_field() ?>
            <fieldset class="my-3">
                <label><?php i18n("settings_i18n"); ?></label>
                <select class="form-select" name="blogI18N" id="blogI18N" required>
                    <?php foreach (['en_US' => 'English', 'fr_FR' => 'Francais', 'uk_UA' => 'Українська'] as $code => $label): ?>
                        <option value="<?= e($code) ?>" <?= (($siteConfig['I18N'] ?: 'en_US') === $code) ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <label><?php i18n("settings_blog_name"); ?></label>
                <input class="form-control" type="text" name="blogName" required value="<?= e($siteConfig['name']) ?>" />
                <label><?php i18n("settings_blog_info"); ?></label>
                <input class="form-control" type="text" name="blogInfo" value="<?= e($siteConfig['info']) ?>" />
                <label><?php i18n("settings_default_author"); ?></label>
                <input class="form-control" type="text" name="blogAuthor"
                       placeholder="<?php i18n("settings_default_author_placeholder"); ?>"
                       value="<?= e($siteConfig['author']) ?>" />
                <label><?php i18n("settings_blog_domain"); ?></label>
                <input class="form-control" type="url" name="blogDomain" required
                       placeholder="<?= e(($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? '')) ?>"
                       value="<?= e($siteConfig['domain']) ?>" />
                <label><?php i18n("settings_blog_OGImage"); ?></label>
                <input class="form-control" type="text" name="blogOGImage" value="<?= e($siteConfig['OGImage']) ?>" />
                <label><?php i18n("settings_footer_message"); ?></label>
                <input class="form-control" type="text" name="blogFooter" value="<?= e($siteConfig['footer']) ?>" />
                <label><?php i18n("settings_header_inject"); ?></label>
                <textarea class="form-control" name="blogHeaderInject" rows="3"><?= e($siteConfig['headerInject']) ?></textarea>
                <small class="text-muted">Raw HTML, inserted into every page head. Only use trusted snippets (for example, analytics).</small>
                <label><?php i18n("settings_template"); ?></label>
                <select class="form-select" name="blogTemplate" required>
                    <?php foreach (Fieldnote\fn_template_names() as $tplName): ?>
                        <option value="<?= e($tplName) ?>" <?= $siteConfig['template'] === $tplName ? 'selected' : '' ?>><?= e($tplName) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-check my-2">
                    <input class="form-check-input" type="checkbox" name="blogSearchEnabled" id="blogSearchEnabled"
                           <?= !empty($siteConfig['searchEnabled']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="blogSearchEnabled">Visitor search at /search</label>
                </div>
                <div class="form-check my-2">
                    <input class="form-check-input" type="checkbox" name="blogStatsEnabled" id="blogStatsEnabled"
                           <?= !empty($siteConfig['statsEnabled']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="blogStatsEnabled">View counts (cookie-less; no IPs or user agents are ever stored)</label>
                </div>
                <label><?php i18n("settings_posts_per_page"); ?></label>
                <input class="form-control" type="number" name="blogPostsPerPage" min="1" required value="<?= e((string) $siteConfig['postsPerPage']) ?>" />
                <label><?php i18n("settings_timezone"); ?></label>
                <input class="form-control" type="text" name="blogTimezone" required value="<?= e($siteConfig['timezone']) ?>" />
                <label><?php i18n("settings_basepath"); ?></label>
                <input class="form-control" type="text" name="blogBase" value="<?= e($siteConfig['basePath']) ?>" />
                <label>Trusted proxy IPs/CIDRs (comma-separated)</label>
                <input class="form-control" type="text" name="blogTrustedProxies"
                       placeholder="Only if behind Cloudflare or a reverse proxy"
                       value="<?= e((string) ($siteConfig['trustedProxies'] ?? '')) ?>" />
                <small class="text-muted">Login rate-limiting keys on the visitor address. Behind a proxy, list the proxy here so the real client IP (from X-Forwarded-For) is used instead — otherwise all visitors share one lockout bucket.</small>
            </fieldset>
            <?php if (Fieldnote\Security::isAuthenticated() && !$isNew): ?>
                <fieldset class="my-3">
                    <legend class="fs-6">Two-factor login</legend>
                    <p class="mb-2">
                        Status: <strong><?= $twoFactor->enabled() ? 'enabled' : 'disabled' ?></strong>
                        <a class="btn btn-sm btn-secondary ms-2" href="<?= e($router->generate('twofactor')) ?>">
                            <?= $twoFactor->enabled() ? 'Manage' : 'Set up' ?>
                        </a>
                    </p>
                </fieldset>
            <?php endif; ?>
            <?php if (!Fieldnote\Security::isAuthenticated() || !$isNew): ?>
                <fieldset class="my-3">
                    <?php if ($isNew): ?>
                        <legend><?php i18n("settings_password_legend"); ?></legend>
                        <input class="form-control" type="password" name="blogPassword" autocomplete="new-password"
                               placeholder="<?php i18n("settings_password_placeholder"); ?>" required />
                    <?php else: ?>
                        <legend><?php i18n("settings_password_legend_update"); ?></legend>
                        <input class="form-control" type="password" name="blogPassword" autocomplete="new-password"
                               placeholder="Leave blank to keep your current password" />
                    <?php endif; ?>
                </fieldset>
            <?php endif; ?>
            <div class="row mx-5">
                <div class="col-md-3"></div>
                <div class="col-md-6 text-center">
                    <button class="btn btn-lg btn-primary mb-3" type="submit">
                        <?= $isNew ? i18n('settings_submit_creation', false) : i18n('settings_submit_update', false) ?>
                    </button>
                    <a class="btn btn-sm btn-secondary" href="<?= e($router->generate('dashboard')) ?>"><?php i18n("settings_dashboard_return"); ?></a>
                </div>
                <div class="col-md-3"></div>
            </div>
        </form>
        <?php if (Fieldnote\Security::isAuthenticated() && !$isNew): ?>
            <form method="post" action="<?= e($router->generate('rotateSecret')) ?>" class="my-3"
                  data-confirm="Invalidate every draft share link ever issued?">
                <?= csrf_field() ?>
                <fieldset>
                    <legend class="fs-6">Draft share links</legend>
                    <p class="mb-2"><small class="text-muted">Each draft's Share button on the dashboard issues a signed link valid for 14 days. If one leaked:</small></p>
                    <button type="submit" class="btn btn-sm btn-outline-danger">Invalidate all share links</button>
                </fieldset>
            </form>
        <?php endif; ?>
    </div>
    <div class="col-md-3"></div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
