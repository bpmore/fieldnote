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
                <legend class="fs-6">Site</legend>
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
                <details class="settings-help my-2">
                    <summary>How to point a custom domain at this blog</summary>
                    <ol class="mt-2 mb-1">
                        <li><strong>DNS</strong> — add an A/AAAA record (or CNAME) for your domain pointing at this server.</li>
                        <li><strong>Web server</strong> — serve the domain with its document root on Fieldnote's <code>public/</code> directory.
                            Apache works out of the box (an <code>.htaccess</code> ships in <code>public/</code>); nginx needs
                            <code>try_files $uri /index.php?$query_string;</code> in the site block.</li>
                        <li><strong>HTTPS</strong> — issue a certificate at the server or proxy (Let's Encrypt, Cloudflare, etc.). Fieldnote follows whatever the server terminates.</li>
                        <li><strong>Set the field above</strong> to <code>https://yourdomain.com</code> and save. This drives canonical URLs,
                            feeds, and the sitemap — and any request that arrives on a different host is permanently
                            redirected here, so www/apex and old addresses can't serve duplicates.</li>
                        <li><strong>Behind Cloudflare or a reverse proxy?</strong> Also fill in the trusted proxy field in Advanced so
                            login rate-limiting sees real visitor addresses.</li>
                    </ol>
                    <p class="mb-0"><small class="text-muted">Changing the domain later is safe: posts, images, and themes store nothing host-specific.</small></p>
                </details>
                <label><?php i18n("settings_timezone"); ?></label>
                <input class="form-control" type="text" name="blogTimezone" required value="<?= e($siteConfig['timezone']) ?>" />
            </fieldset>

            <fieldset class="my-3">
                <legend class="fs-6">Appearance</legend>
                <label><?php i18n("settings_template"); ?></label>
                <select class="form-select" name="blogTemplate" required>
                    <?php foreach (Fieldnote\fn_template_names() as $tplName): ?>
                        <option value="<?= e($tplName) ?>" <?= $siteConfig['template'] === $tplName ? 'selected' : '' ?>><?= e($tplName) ?></option>
                    <?php endforeach; ?>
                </select>
                <label><?php i18n("settings_posts_per_page"); ?></label>
                <input class="form-control" type="number" name="blogPostsPerPage" min="1" required value="<?= e((string) $siteConfig['postsPerPage']) ?>" />
                <label><?php i18n("settings_blog_OGImage"); ?></label>
                <input class="form-control" type="text" name="blogOGImage" value="<?= e($siteConfig['OGImage']) ?>" />
            </fieldset>

            <fieldset class="my-3">
                <legend class="fs-6">Footer</legend>
                <label><?php i18n("settings_footer_message"); ?></label>
                <input class="form-control" type="text" name="blogFooter" value="<?= e($siteConfig['footer']) ?>" />
                <label for="blogCopyright" class="mt-2">Copyright line</label>
                <div class="d-flex gap-2">
                    <select class="form-select" name="blogCopyright" id="blogCopyright">
                        <?php $cw = (string) ($siteConfig['copyright'] ?? 'off'); ?>
                        <option value="off" <?= $cw === 'off' ? 'selected' : '' ?>>Off</option>
                        <option value="blog" <?= $cw === 'blog' ? 'selected' : '' ?>>&copy; year + blog name</option>
                        <option value="author" <?= $cw === 'author' ? 'selected' : '' ?>>&copy; year + author name</option>
                    </select>
                    <input class="form-control" type="number" name="blogCopyrightStartYear" min="1900" max="2100"
                           style="max-width:9rem" placeholder="Start year"
                           value="<?= e((string) ($siteConfig['copyrightStartYear'] ?? '')) ?>" />
                </div>
                <small class="text-muted">The current year is automatic; a start year shows a range (e.g. 2021&ndash;<?= e(date('Y')) ?>).</small>
            </fieldset>

            <fieldset class="my-3">
                <legend class="fs-6">Social links</legend>
                <small class="text-muted d-block mb-1">Optional, shown in the footer. Leave blank to hide. Profile URLs (https); for Email, just the address. Links carry <code>rel="me"</code> for fediverse verification.</small>
                <?php foreach (Fieldnote\Social::NETWORKS as $netKey => $netMeta):
                    $isEmail = !empty($netMeta['email']); ?>
                    <label for="blogSocial_<?= e($netKey) ?>" class="small mb-0"><?= e($netMeta['label']) ?></label>
                    <input class="form-control form-control-sm mb-1" id="blogSocial_<?= e($netKey) ?>"
                           type="<?= $isEmail ? 'email' : 'url' ?>" name="blogSocial_<?= e($netKey) ?>"
                           placeholder="<?= $isEmail ? 'you@example.com' : 'https://&hellip;' ?>"
                           value="<?= e((string) ($siteConfig['social'][$netKey] ?? '')) ?>" />
                <?php endforeach; ?>
            </fieldset>

            <fieldset class="my-3">
                <legend class="fs-6">Features</legend>
                <label for="blogProfilePage">Profile page</label>
                <div class="d-flex gap-2 align-items-center">
                    <select class="form-select" name="blogProfilePage" id="blogProfilePage">
                        <?php $pp = (string) ($siteConfig['profilePage'] ?? 'off'); ?>
                        <option value="off" <?= $pp === 'off' ? 'selected' : '' ?>>Off</option>
                        <?php foreach (Fieldnote\Config::PROFILE_SLUGS as $ps): ?>
                            <option value="<?= e($ps) ?>" <?= $pp === $ps ? 'selected' : '' ?>>/<?= e($ps) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (in_array($pp, Fieldnote\Config::PROFILE_SLUGS, true)): ?>
                        <a class="btn btn-sm btn-secondary text-nowrap" href="<?= e($router->generate('editProfile')) ?>">Edit the page</a>
                    <?php endif; ?>
                </div>
                <small class="text-muted d-block mb-2">A personal "about / now" page at the chosen URL, linked in the header. Save this form first, then edit the page.</small>
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
                <div class="form-check my-2">
                    <input class="form-check-input" type="checkbox" name="blogAccessibilityBadge" id="blogAccessibilityBadge"
                           <?= !empty($siteConfig['accessibilityBadge']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="blogAccessibilityBadge">Show the WCAG 2.2 AA badge in the theme footer (links to /accessibility)</label>
                </div>
                <div class="form-check my-2">
                    <input class="form-check-input" type="checkbox" name="blogFederationEnabled" id="blogFederationEnabled"
                           <?= !empty($siteConfig['federationEnabled']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="blogFederationEnabled">Fediverse federation (ActivityPub, experimental)</label>
                </div>
                <?php
                $fedHost = (string) (parse_url((string) $siteConfig['domain'], PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? ''));
                $fedHandle = (string) ($siteConfig['apHandle'] ?: 'blog');
                ?>
                <?php if (!empty($siteConfig['federationEnabled'])): ?>
                    <p class="my-1"><small>Followable as <code>@<?= e($fedHandle) ?>@<?= e($fedHost) ?></code>.
                        The handle is locked while federation is on — changing it would orphan followers.
                        Requires HTTPS; changing the site domain also orphans followers.</small></p>
                <?php else: ?>
                    <label>Fediverse handle (sets <code>@handle@<?= e($fedHost) ?></code>; locked once enabled)</label>
                    <input class="form-control" type="text" name="blogApHandle"
                           value="<?= e($fedHandle) ?>" />
                <?php endif; ?>
            </fieldset>

            <details class="settings-help my-3">
                <summary>Advanced</summary>
                <fieldset class="mt-2">
                    <label><?php i18n("settings_header_inject"); ?></label>
                    <textarea class="form-control" name="blogHeaderInject" rows="3"><?= e($siteConfig['headerInject']) ?></textarea>
                    <small class="text-muted">Raw HTML, inserted into every page head. Only use trusted snippets (for example, analytics).</small>
                    <label><?php i18n("settings_basepath"); ?></label>
                    <input class="form-control" type="text" name="blogBase" value="<?= e($siteConfig['basePath']) ?>" />
                    <label>Trusted proxy IPs/CIDRs (comma-separated)</label>
                    <input class="form-control" type="text" name="blogTrustedProxies"
                           placeholder="Only if behind Cloudflare or a reverse proxy"
                           value="<?= e((string) ($siteConfig['trustedProxies'] ?? '')) ?>" />
                    <small class="text-muted">Login rate-limiting keys on the visitor address. Behind a proxy, list the proxy here so the real client IP (from X-Forwarded-For) is used instead — otherwise all visitors share one lockout bucket.</small>
                </fieldset>
            </details>
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
            <fieldset class="my-3" id="passkeySection"
                      data-options-url="<?= e($router->generate('passkeyCreateOptions')) ?>"
                      data-register-url="<?= e($router->generate('passkeyRegister')) ?>"
                      data-csrf="<?= e(Fieldnote\Security::csrfToken()) ?>">
                <legend class="fs-6">Passkeys</legend>
                <p class="mb-2"><small class="text-muted">Sign in with Touch ID, Face ID, or a security key —
                    no password typed, nothing to phish. Your password keeps working as a fallback.</small></p>
                <?php foreach ($passkeys->list() as $pk): ?>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="flex-grow-1"><?= e((string) $pk['label']) ?>
                            <small class="text-muted">&middot; added <?= e(date('M j, Y', (int) $pk['createdAt'])) ?></small></span>
                        <form method="post" action="<?= e($router->generate('passkeyDelete')) ?>"
                              data-confirm="Remove this passkey?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= e((string) $pk['id']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                        </form>
                    </div>
                <?php endforeach; ?>
                <div class="d-flex gap-2">
                    <input type="text" id="passkeyLabel" class="form-control form-control-sm my-0"
                           placeholder="Label (e.g. MacBook Touch ID)">
                    <button type="button" id="passkeyAdd" class="btn btn-sm btn-secondary text-nowrap">Add a passkey</button>
                </div>
                <p id="passkeyMsg" class="small mb-0" role="status"></p>
                <?php if ($passkeys->enabled()): ?>
                    <p class="mb-0 mt-1"><small class="text-muted">Passkeys are bound to this site's domain —
                        changing the domain above orphans them (password login is unaffected).
                        Lost all devices? Delete <code>data/passkeys.json</code> on the server.</small></p>
                <?php endif; ?>
            </fieldset>
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
