<?php

namespace Fieldnote;

use SleekDB\Store;

/**
 * Front controller. Defines every route, then dispatches.
 *
 * Security model:
 *   - Public read routes (home, post listing, single post) require config to
 *     exist but no auth.
 *   - Every state-changing route requires an authenticated session AND, for
 *     POST, a valid CSRF token (enforced once, centrally, below).
 *   - Destructive actions (delete, publish, hide) are POST-only; the original
 *     exposed them as GET, which made them trivially CSRF-able via <img> tags.
 */

require __DIR__ . '/bootstrap.php';

use AltoRouter;

/** @var array<string,mixed> $siteConfig */
/** @var Store $blogStore */
/** @var Store $imageStore */

$router = new AltoRouter();
if (!empty($siteConfig['basePath'])) {
    $router->setBasePath($siteConfig['basePath']);
}

// Pagination math.
$postsPerPage = (int) $siteConfig['postsPerPage'];
if ($postsPerPage < 1) {
    $postsPerPage = 1;
}
$publishedCount = fn_published_count($blogStore);
$numPages = max(1, (int) ceil($publishedCount / $postsPerPage));

// Site-relative on purpose: records once embedded the configured domain,
// which broke every existing image whenever the domain (or dev host) changed.
$uploadPublicBase = rtrim((string) $siteConfig['basePath'], '/') . '/uploads';
$images = new ImageHandler(FN_UPLOAD_DIR, $uploadPublicBase);
$twoFactor = new TwoFactor(FN_DATA_DIR);

$redirect = static function (string $name, array $params = []) use ($router): void {
    header('Location: ' . $router->generate($name, $params));
    exit;
};

$requireConfig = static function () use ($configStore, $redirect): void {
    if (!$configStore->exists()) {
        $redirect('settings');
    }
};

$requireAuth = static function () use ($redirect): void {
    if (!Security::isAuthenticated()) {
        $redirect('login');
    }
};

$notFound = static function (): void {
    http_response_code(404);
    global $siteConfig, $router, $pageTitle;
    $pageTitle = 'Not Found';
    $tpl = fn_template_dir($siteConfig['template']) . '/404.php';
    if (is_file($tpl)) {
        require $tpl;
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 Not Found';
    }
    exit;
};

// ---------------------------------------------------------------------------
// Public read routes
// ---------------------------------------------------------------------------

$router->map('GET', '/', function () use ($requireConfig, $siteConfig, $blogStore, $imageStore, $postsPerPage, $numPages, $router) {
    $requireConfig();
    fn_render_home($siteConfig, $router, $blogStore, $imageStore, fn_template_dir($siteConfig['template']), $postsPerPage, $numPages);
}, 'home');

$router->map('GET', '/[i:page]', function ($page) use ($requireConfig, $siteConfig, $blogStore, $imageStore, $postsPerPage, $numPages, $router, $notFound) {
    $requireConfig();
    $page  = max(1, (int) $page);
    if ($page > $numPages) {
        $notFound();
    }
    $limit = $postsPerPage;
    $skip  = ($page - 1) * $postsPerPage;
    $allPosts = array_map(
        fn ($p) => fn_with_image($p, $imageStore),
        $blogStore->findBy(['draft', '=', false], ['date' => 'desc'], $postsPerPage, $skip)
    );
    $pageTitle = 'Posts | Page ' . $page;
    require fn_template_dir($siteConfig['template']) . '/home.php';
}, 'posts');

// Legacy numeric URLs (/post/1) permanently redirect to the canonical
// dated slug URL. Registered before the slug form so numeric paths match
// here first; slugs are guaranteed never purely numeric (see fn_slugify).
$router->map('GET|POST', '/post/[i:id]', function ($id) use ($requireConfig, $blogStore, $router, $notFound) {
    $requireConfig();
    $post = $blogStore->findById((int) $id);
    if ($post === null || empty($post['slug'])) {
        $notFound();
    }
    // Do not leak draft existence through a redirect.
    if (!empty($post['draft']) && !Security::isAuthenticated()) {
        $notFound();
    }
    header('Location: ' . fn_post_url($router, $post), true, 301);
    exit;
}, 'postById');

// Legacy undated slug URLs (/post/title) also redirect to the dated form.
$router->map('GET|POST', '/post/[:slug]', function ($slug) use ($requireConfig, $blogStore, $router, $notFound) {
    $requireConfig();
    $post = $blogStore->findOneBy(['slug', '=', (string) $slug]);
    if ($post === null) {
        $notFound();
    }
    if (!empty($post['draft']) && !Security::isAuthenticated()) {
        $notFound();
    }
    header('Location: ' . fn_post_url($router, $post), true, 301);
    exit;
}, 'postBySlug');

$router->map('GET|POST', '/[i:year]/[i:month]/[:slug]', function ($year, $month, $slug) use ($requireConfig, $siteConfig, $blogStore, $imageStore, $router, $notFound) {
    $requireConfig();
    $post = $blogStore->findOneBy(['slug', '=', (string) $slug]);
    if ($post === null) {
        $notFound();
    }
    // Drafts are not public. The original (and 3.0 until now) served any post
    // by ID regardless of draft status; only the admin may preview drafts.
    if (!empty($post['draft']) && !Security::isAuthenticated()) {
        $notFound();
    }

    // Wrong or unpadded date segments 301 to the canonical URL so each post
    // has exactly one address.
    $canonical = fn_post_url($router, $post);
    $requested = $router->generate('post', ['year' => $year, 'month' => $month, 'slug' => $slug]);
    if ($requested !== $canonical) {
        header('Location: ' . $canonical, true, 301);
        exit;
    }

    // Per-post password is now hashed. Submitted only via POST (never logged
    // in URLs the way the original $_REQUEST/GET handling allowed).
    $hash = (string) ($post['password'] ?? '');
    if ($hash === '') {
        $unlocked = true;
    } else {
        $attempt  = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (string) ($_POST['password'] ?? '') : '';
        $unlocked = $attempt !== '' && password_verify($attempt, $hash);
    }

    $post = fn_with_image($post, $imageStore);

    if ($unlocked) {
        $pageTitle = $post['title'];
        require fn_template_dir($siteConfig['template']) . '/post.php';
    } else {
        $pageTitle = 'Private post';
        require FN_INTERNAL_DIR . '/private.php';
    }
}, 'post');

// Static assets bundled inside a theme folder (templates/<name>/assets/).
// Served through PHP because templates/ lives outside the web root; the
// extension whitelist and realpath containment check keep it from ever
// serving theme PHP or anything outside the assets directory.
$router->map('GET', '/themes/[:theme]/[**:file]', function ($theme, $file) use ($notFound) {
    $types = [
        'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'svg' => 'image/svg+xml', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2',
    ];
    $ext = strtolower(pathinfo((string) $file, PATHINFO_EXTENSION));
    if (!isset($types[$ext])) {
        $notFound();
    }
    $assetsDir = realpath(FN_TEMPLATES_DIR . '/' . basename((string) $theme) . '/assets');
    $path      = $assetsDir === false ? false : realpath($assetsDir . '/' . $file);
    if ($assetsDir === false || $path === false || !str_starts_with($path, $assetsDir . '/') || !is_file($path)) {
        $notFound();
    }
    header('Content-Type: ' . $types[$ext]);
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    exit;
}, 'themeAsset');

$router->map('GET', '/feed', function () use ($requireConfig, $siteConfig, $blogStore, $publishedCount, $router) {
    $requireConfig();

    // Validators cover everything that can change an item: the post set
    // (ids/dates/count), titles, slugs, bodies, and protection status —
    // so an edit to an already-published post busts the ETag too.
    $posts  = $blogStore->findBy(['draft', '=', false], ['date' => 'desc'], 20);
    $newest = (int) ($posts[0]['date'] ?? 0);
    $seed   = $siteConfig['name'] . '|' . $publishedCount . '|' . sha1(serialize(array_map(
        static fn (array $p): array => [
            $p['_id'], $p['date'], $p['title'] ?? '', $p['slug'] ?? '', $p['content'] ?? '', !empty($p['password']),
        ],
        $posts
    )));
    fn_conditional_get($seed, $newest ?: time());

    $base = rtrim((string) $siteConfig['domain'], '/');
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base   = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    $parser = new \ParsedownExtra();
    $parser->setSafeMode(true);

    $xml = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    header('Content-Type: application/rss+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>' . "\n";
    echo '<title>' . $xml($siteConfig['name'] ?: 'Fieldnote') . '</title>' . "\n";
    echo '<link>' . $xml($base . $router->generate('home')) . '</link>' . "\n";
    echo '<description>' . $xml($siteConfig['info']) . '</description>' . "\n";
    echo '<atom:link href="' . $xml($base . $router->generate('feed')) . '" rel="self" type="application/rss+xml"/>' . "\n";

    foreach ($posts as $post) {
        // Never leak the body of a password-protected post into the feed.
        if (!empty($post['password'])) {
            continue;
        }
        $url = $base . fn_post_url($router, $post);
        echo '<item>' . "\n";
        echo '<title>' . $xml($post['title']) . '</title>' . "\n";
        echo '<link>' . $xml($url) . '</link>' . "\n";
        echo '<guid isPermaLink="true">' . $xml($url) . '</guid>' . "\n";
        echo '<pubDate>' . $xml(date(DATE_RSS, (int) $post['date'])) . '</pubDate>' . "\n";
        echo '<description>' . $xml($parser->text((string) $post['content'])) . '</description>' . "\n";
        echo '</item>' . "\n";
    }
    echo '</channel></rss>';
    exit;
}, 'feed');

// ---------------------------------------------------------------------------
// Authenticated, state-changing routes
// ---------------------------------------------------------------------------

$router->map('POST', '/post/[i:id]/publish', function ($id) use ($requireConfig, $requireAuth, $blogStore, $redirect, $notFound) {
    $requireConfig();
    $requireAuth();
    $post = $blogStore->findById((int) $id);
    if ($post === null) {
        $notFound();
    }
    $update = ['draft' => false];
    // First publish stamps the post's date (which the permalink embeds).
    // Hiding and re-publishing later must not move it, so remember it.
    if (empty($post['publishedAt'])) {
        $now = time();
        $update['date']        = $now;
        $update['publishedAt'] = $now;
    }
    $blogStore->updateById((int) $id, $update);
    fn_invalidate_published_count();
    $redirect('dashboard');
}, 'publish');

$router->map('POST', '/post/[i:id]/hide', function ($id) use ($requireConfig, $requireAuth, $blogStore, $redirect, $notFound) {
    $requireConfig();
    $requireAuth();
    if ($blogStore->findById((int) $id) === null) {
        $notFound();
    }
    $blogStore->updateById((int) $id, ['draft' => true]);
    fn_invalidate_published_count();
    $redirect('dashboard');
}, 'hide');

$router->map('POST', '/post/[i:id]/delete', function ($id) use ($requireConfig, $requireAuth, $blogStore, $imageStore, $redirect, $notFound) {
    $requireConfig();
    $requireAuth();
    $post = $blogStore->findById((int) $id);
    if ($post === null) {
        $notFound();
    }
    // Clean up the linked image record and its file on disk.
    fn_delete_image($imageStore, $post['image'] ?? null);
    $blogStore->deleteById((int) $id);
    fn_invalidate_published_count();
    $redirect('dashboard');
}, 'deletePost');

$router->map('GET|POST', '/post/[i:id]/edit', function ($id) use ($requireConfig, $requireAuth, $siteConfig, $blogStore, $imageStore, $images, $router, $redirect, $notFound) {
    $requireConfig();
    $requireAuth();
    $post = $blogStore->findById((int) $id);
    if ($post === null) {
        $notFound();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['blogPostTitle'], $_POST['blogPostContent'], $_POST['blogPostAuthor'])) {
            $redirect('editPost', ['id' => $id]);
        }
        $newTitle = fn_clean($_POST['blogPostTitle']);
        // Re-slug when the title changes (or when a pre-slug post is saved).
        if ($newTitle !== ($post['title'] ?? '') || empty($post['slug'])) {
            $post['slug'] = fn_unique_slug($blogStore, $newTitle, (int) $id);
        }
        $post['title']   = $newTitle;
        $post['author']  = fn_clean($_POST['blogPostAuthor']);
        $post['content'] = (string) $_POST['blogPostContent']; // markdown stored raw, escaped at render
        $post['password'] = fn_hash_post_password($_POST['blogPostPassword'] ?? '', $post['password'] ?? '');

        // Replace the image only if a new upload or URL was supplied —
        // and clean up the one being replaced, or it leaks on disk forever.
        $newImage = fn_resolve_image($images, $_FILES['imageUpload'] ?? null, $_POST['blogPostImageURL'] ?? '');
        if ($newImage !== null) {
            fn_delete_image($imageStore, $post['image'] ?? null);
            $rec = $imageStore->insert(['url' => $newImage[0], 'path' => $newImage[1]]);
            $post['image'] = $rec['_id'];
        }

        $blogStore->update($post);
        $redirect('dashboard');
    }

    $post = fn_with_image($post, $imageStore);
    $pageTitle = 'Edit Post';
    require FN_INTERNAL_DIR . '/write.php';
}, 'editPost');

$router->map('GET|POST', '/write', function () use ($requireConfig, $requireAuth, $siteConfig, $blogStore, $imageStore, $images, $router, $redirect) {
    $requireConfig();
    $requireAuth();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['blogPostTitle'], $_POST['blogPostContent'], $_POST['blogPostAuthor'])) {
            $redirect('write');
        }
        $title = fn_clean($_POST['blogPostTitle']);
        $post = [
            'title'    => $title,
            'slug'     => fn_unique_slug($blogStore, $title),
            'date'     => time(),
            'draft'    => true,
            'author'   => fn_clean($_POST['blogPostAuthor']),
            'content'  => (string) $_POST['blogPostContent'],
            'password' => fn_hash_post_password($_POST['blogPostPassword'] ?? '', ''),
        ];

        $image = fn_resolve_image($images, $_FILES['imageUpload'] ?? null, $_POST['blogPostImageURL'] ?? '');
        if ($image !== null) {
            $rec = $imageStore->insert(['url' => $image[0], 'path' => $image[1]]);
            $post['image'] = $rec['_id'];
        }

        $blogStore->insert($post);
        $redirect('dashboard');
    }

    $pageTitle = 'Write';
    require FN_INTERNAL_DIR . '/write.php';
}, 'write');

// ---------------------------------------------------------------------------
// Settings / auth
// ---------------------------------------------------------------------------

$router->map('GET|POST', '/settings', function () use ($configStore, $siteConfig, $twoFactor, $router, $redirect) {
    // Reachable when no config exists yet (first-time setup) OR when authed.
    if (!(Security::isAuthenticated() || !$configStore->exists())) {
        $redirect('login');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $required = ['blogName', 'blogDomain', 'blogTemplate', 'blogTimezone', 'blogI18N'];
        foreach ($required as $field) {
            if (!isset($_POST[$field]) || $_POST[$field] === '') {
                $redirect('settings');
            }
        }

        $firstRun = !$configStore->exists();

        // Password: set on first run; on later edits keep the existing hash
        // unless a new password was actually typed.
        if ($firstRun) {
            $password = password_hash((string) $_POST['blogPassword'], PASSWORD_DEFAULT);
        } elseif (!empty($_POST['blogPassword'])) {
            $password = password_hash((string) $_POST['blogPassword'], PASSWORD_DEFAULT);
        } else {
            $password = $siteConfig['password'];
        }

        $postsPerPage = (int) ($_POST['blogPostsPerPage'] ?? 1);
        if ($postsPerPage < 1) {
            $postsPerPage = 1;
        }

        $new = [
            'name'         => fn_clean($_POST['blogName']),
            'info'         => fn_clean($_POST['blogInfo'] ?? ''),
            'author'       => fn_clean($_POST['blogAuthor'] ?? ''),
            'domain'       => fn_clean_url($_POST['blogDomain']),
            'OGImage'      => fn_clean($_POST['blogOGImage'] ?? ''),
            'footer'       => fn_clean($_POST['blogFooter'] ?? ''),
            // headerInject is intentional raw markup (analytics snippets), only
            // ever writable by an authenticated admin and now CSRF-protected.
            'headerInject' => (string) ($_POST['blogHeaderInject'] ?? ''),
            'password'     => $password,
            'template'     => basename(fn_clean($_POST['blogTemplate'])),
            'postsPerPage' => $postsPerPage,
            'basePath'     => fn_clean($_POST['blogBase'] ?? ''),
            'timezone'     => fn_clean($_POST['blogTimezone']),
            'I18N'         => fn_clean($_POST['blogI18N']),
        ];

        if (!$configStore->save($new)) {
            http_response_code(500);
            exit('Unable to write configuration. Check that the data/ directory is writable.');
        }
        // First-run setup: the visitor just chose the admin password, so log
        // them straight in instead of bouncing them to the login form.
        if ($firstRun) {
            Security::regenerate();
            $_SESSION['isAuthenticated'] = true;
        }
        $redirect('dashboard');
    }

    $pageTitle = 'Settings';
    require FN_INTERNAL_DIR . '/settings.php';
}, 'settings');

$router->map('GET|POST', '/login', function () use ($configStore, $siteConfig, $twoFactor, $router, $redirect) {
    $requireConfigExists = $configStore->exists();
    if (!$requireConfigExists) {
        $redirect('settings');
    }
    if (Security::isAuthenticated()) {
        $redirect('dashboard');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $lockedFor = Security::loginLockedFor(FN_DATA_DIR);
        if ($lockedFor > 0) {
            $_SESSION['login_error'] = sprintf(
                'Too many failed attempts. Try again in about %d minute%s.',
                $mins = max(1, (int) ceil($lockedFor / 60)),
                $mins === 1 ? '' : 's'
            );
            $redirect('login');
        }

        $password = (string) ($_POST['blogPassword'] ?? '');
        if ($password !== '' && password_verify($password, (string) $siteConfig['password'])) {
            Security::regenerate();
            if ($twoFactor->enabled()) {
                // Password OK, but hold authentication until the second
                // factor checks out. Failures are NOT cleared yet, so the
                // shared throttle also covers code guessing.
                $_SESSION['pending_2fa'] = time();
                $redirect('loginVerify');
            }
            Security::clearLoginFailures(FN_DATA_DIR);
            $_SESSION['isAuthenticated'] = true;
            $redirect('dashboard');
        }
        // Generic failure: do not reveal whether the password was close.
        Security::recordLoginFailure(FN_DATA_DIR);
        $_SESSION['login_error'] = 'That password was not correct.';
        $redirect('login');
    }

    $pageTitle = 'Log In';
    $loginError = $_SESSION['login_error'] ?? '';
    unset($_SESSION['login_error']);
    require FN_INTERNAL_DIR . '/login.php';
}, 'login');

$router->map('GET|POST', '/login/verify', function () use ($siteConfig, $twoFactor, $router, $redirect) {
    if (Security::isAuthenticated()) {
        $redirect('dashboard');
    }
    // Only reachable for five minutes after a correct password.
    $pending = (int) ($_SESSION['pending_2fa'] ?? 0);
    if ($pending === 0 || time() - $pending > 300 || !$twoFactor->enabled()) {
        unset($_SESSION['pending_2fa']);
        $redirect('login');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $lockedFor = Security::loginLockedFor(FN_DATA_DIR);
        if ($lockedFor > 0) {
            $_SESSION['login_error'] = sprintf(
                'Too many failed attempts. Try again in about %d minute%s.',
                $mins = max(1, (int) ceil($lockedFor / 60)),
                $mins === 1 ? '' : 's'
            );
            $redirect('loginVerify');
        }

        $code = (string) ($_POST['code'] ?? '');
        if ($twoFactor->verifyTotp($code) || $twoFactor->useRecoveryCode($code)) {
            unset($_SESSION['pending_2fa']);
            Security::clearLoginFailures(FN_DATA_DIR);
            Security::regenerate();
            $_SESSION['isAuthenticated'] = true;
            $redirect('dashboard');
        }
        Security::recordLoginFailure(FN_DATA_DIR);
        $_SESSION['login_error'] = 'That code was not correct.';
        $redirect('loginVerify');
    }

    $pageTitle = 'Verify';
    $loginError = $_SESSION['login_error'] ?? '';
    unset($_SESSION['login_error']);
    require FN_INTERNAL_DIR . '/verify2fa.php';
}, 'loginVerify');

$router->map('GET|POST', '/settings/2fa', function () use ($requireConfig, $requireAuth, $siteConfig, $twoFactor, $router, $redirect) {
    $requireConfig();
    $requireAuth();

    $pageTitle        = 'Two-Factor Login';
    $justEnabledCodes = null;
    $twoFaError       = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['twofaAction'] ?? '');

        if ($action === 'enable' && !$twoFactor->enabled()) {
            // The candidate secret lives only in the session until the admin
            // proves their authenticator produces matching codes — you cannot
            // lock yourself out with a mis-scanned QR.
            $secret = (string) ($_SESSION['totp_setup_secret'] ?? '');
            if ($secret !== '' && Totp::verify($secret, (string) ($_POST['code'] ?? '')) !== null) {
                $plain  = TwoFactor::generateRecoveryCodes();
                $hashes = array_map(
                    static fn (string $c) => password_hash(TwoFactor::normalizeRecoveryCode($c), PASSWORD_DEFAULT),
                    $plain
                );
                if ($twoFactor->enable($secret, $hashes)) {
                    unset($_SESSION['totp_setup_secret']);
                    $justEnabledCodes = $plain; // shown exactly once
                } else {
                    $twoFaError = 'Could not write data/totp.json — check that data/ is writable.';
                }
            } else {
                $twoFaError = 'That code did not match. Enter a fresh code from your authenticator app.';
            }
        } elseif ($action === 'disable' && $twoFactor->enabled()) {
            $code = (string) ($_POST['code'] ?? '');
            if ($twoFactor->verifyTotp($code) || $twoFactor->useRecoveryCode($code)) {
                $twoFactor->disable();
                $redirect('settings');
            }
            $twoFaError = 'Enter a valid current code (or a recovery code) to disable two-factor login.';
        }
    }

    $setupSecret = null;
    $otpauthUri  = null;
    if (!$twoFactor->enabled() && $justEnabledCodes === null) {
        if (empty($_SESSION['totp_setup_secret'])) {
            $_SESSION['totp_setup_secret'] = Totp::generateSecret();
        }
        $setupSecret = (string) $_SESSION['totp_setup_secret'];
        $otpauthUri  = Totp::otpauthUri($setupSecret, 'admin', $siteConfig['name'] !== '' ? $siteConfig['name'] : 'Fieldnote');
    }

    require FN_INTERNAL_DIR . '/twofactor.php';
}, 'twofactor');

// ---------------------------------------------------------------------------
// Theme gallery (docs/theme-previews-spec.md)
// ---------------------------------------------------------------------------

$router->map('GET', '/admin/themes', function () use ($requireConfig, $requireAuth, $siteConfig, $router) {
    $requireConfig();
    $requireAuth();
    $pageTitle = 'Themes';
    require FN_INTERNAL_DIR . '/themes.php';
}, 'themes');

// The real homepage rendered through any installed theme, for the gallery's
// iframes. Admin-only: not because it leaks anything (published posts only),
// but to keep preview rendering from being an anonymous resource sink.
$router->map('GET', '/admin/themes/preview/[:theme]', function ($theme) use ($requireConfig, $requireAuth, $siteConfig, $blogStore, $imageStore, $postsPerPage, $numPages, $router, $notFound) {
    $requireConfig();
    $requireAuth();
    $name = basename((string) $theme);
    if (!in_array($name, fn_template_names(), true)) {
        $notFound();
    }

    // ?scheme=light|dark forces a palette regardless of the OS preference:
    // re-declare the matching token block after the stylesheet (later in the
    // cascade beats the theme's own prefers-color-scheme override). When the
    // requested scheme IS the theme's default, the :root block is replayed
    // instead, which cancels an active media-query override the same way.
    $scheme = (string) ($_GET['scheme'] ?? '');
    if (in_array($scheme, ['light', 'dark'], true)) {
        $css  = (string) @file_get_contents(fn_template_dir($name) . '/assets/theme.css');
        $body = CssTokens::schemeBlock($css, $scheme) ?? CssTokens::rootBlock($css);
        if ($body !== null) {
            $GLOBALS['fnSchemeOverrideCss'] = ':root{' . trim($body) . ';color-scheme:' . $scheme . '}';
        }
    }

    header('X-Robots-Tag: noindex');
    header("Content-Security-Policy: frame-ancestors 'self'");
    fn_render_home(
        $siteConfig,
        $router,
        $blogStore,
        $imageStore,
        fn_template_dir($name),
        min(3, $postsPerPage), // a taste is enough; 140 iframes of full grid is not
        $numPages
    );
}, 'themePreview');

$router->map('POST', '/admin/themes/apply', function () use ($requireConfig, $requireAuth, $configStore, $siteConfig, $redirect, $notFound) {
    $requireConfig();
    $requireAuth();
    $name = basename((string) ($_POST['theme'] ?? ''));
    if (!in_array($name, fn_template_names(), true)) {
        $notFound();
    }
    $siteConfig['template'] = $name;
    if (!$configStore->save($siteConfig)) {
        http_response_code(500);
        exit('Unable to write configuration. Check that the data/ directory is writable.');
    }
    $redirect('themes');
}, 'applyTheme');

$router->map('POST', '/logout', function () use ($redirect) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    $redirect('home');
}, 'logout');

$router->map('GET', '/dashboard', function () use ($requireConfig, $requireAuth, $siteConfig, $blogStore, $router) {
    $requireConfig();
    $requireAuth();
    $draftPosts        = $blogStore->findBy(['draft', '=', true], ['date' => 'desc']);
    $publishedPosts    = $blogStore->findBy(['draft', '=', false], ['date' => 'desc']);
    $draftPostCount     = count($draftPosts);
    $publishedPostCount = count($publishedPosts);
    $pageTitle = 'Dashboard';
    require FN_INTERNAL_DIR . '/dashboard.php';
}, 'dashboard');

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

// A POST whose body exceeded post_max_size reaches PHP with $_POST and
// $_FILES completely empty. Without this check the user would get a baffling
// CSRF error (the token vanished with everything else) — or worse, silently
// lose a written post. Say what actually happened.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST) && empty($_FILES)
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
) {
    http_response_code(413);
    header('Content-Type: text/plain; charset=utf-8');
    exit(sprintf(
        "The submission was too large for the server (limit: %s including any attached image).\n"
        . "Go back, attach a smaller image, and try again — your text is still in the previous tab.",
        ini_get('post_max_size')
    ));
}

// Enforce CSRF once, centrally, for every POST before any handler runs.
Security::requireValidCsrf();

$match = $router->match();
if (is_array($match) && is_callable($match['target'])) {
    call_user_func_array($match['target'], $match['params']);
} else {
    $notFound();
}

// ---------------------------------------------------------------------------
// Small request helpers
// ---------------------------------------------------------------------------

/**
 * Normalize a plain-text field on the way IN: trim and strip control bytes.
 * We do NOT HTML-encode at input (the original did, which double-encoded and
 * mixed contexts). Encoding happens at output via Fieldnote\e().
 */
function fn_clean(string $value): string
{
    $value = trim($value);
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
}

/**
 * Accept only an absolute http/https URL; anything else becomes the empty
 * string (the app then falls back to host-relative links).
 */
function fn_clean_url(string $value): string
{
    $value = trim($value);
    if (
        filter_var($value, FILTER_VALIDATE_URL)
        && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
    ) {
        return rtrim($value, '/');
    }
    return '';
}

/**
 * Hash a per-post password, or keep the existing hash when the field is blank.
 */
function fn_hash_post_password(string $submitted, string $existingHash): string
{
    $submitted = trim($submitted);
    if ($submitted === '') {
        return $existingHash; // empty means "no password" on create, "unchanged" on edit
    }
    return password_hash($submitted, PASSWORD_DEFAULT);
}

/**
 * Resolve a featured image from either an upload or a URL, upload winning.
 *
 * @param array{name:string,tmp_name:string,size:int,error:int}|null $file
 * @return array{0:string,1:string}|null
 */
function fn_resolve_image(ImageHandler $images, ?array $file, string $url): ?array
{
    if ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && ($file['name'] ?? '') !== '') {
        return $images->storeUpload($file);
    }
    $url = trim($url);
    if ($url !== '') {
        return $images->storeFromUrl($url);
    }
    return null;
}
