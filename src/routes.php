<?php

namespace Dropplets;

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
$publishedCount = count($blogStore->findBy(['draft', '=', false]));
$numPages = max(1, (int) ceil($publishedCount / $postsPerPage));

$uploadPublicBase = rtrim((string) $siteConfig['domain'], '/') . '/uploads';
$images = new ImageHandler(DPL_UPLOAD_DIR, $uploadPublicBase);

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
    $tpl = dpl_template_dir($siteConfig['template']) . '/404.php';
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
    $page  = 1;
    $limit = $postsPerPage;
    $allPosts = array_map(
        fn ($p) => dpl_with_image($p, $imageStore),
        $blogStore->findBy(['draft', '=', false], ['date' => 'desc'], $postsPerPage)
    );
    $pageTitle = 'Home';
    require dpl_template_dir($siteConfig['template']) . '/home.php';
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
        fn ($p) => dpl_with_image($p, $imageStore),
        $blogStore->findBy(['draft', '=', false], ['date' => 'desc'], $postsPerPage, $skip)
    );
    $pageTitle = 'Posts | Page ' . $page;
    require dpl_template_dir($siteConfig['template']) . '/home.php';
}, 'posts');

$router->map('GET|POST', '/post/[i:id]', function ($id) use ($requireConfig, $siteConfig, $blogStore, $imageStore, $router, $notFound) {
    $requireConfig();
    $post = $blogStore->findById((int) $id);
    if ($post === null) {
        $notFound();
    }
    // Drafts are not public. The original (and 3.0 until now) served any post
    // by ID regardless of draft status; only the admin may preview drafts.
    if (!empty($post['draft']) && !Security::isAuthenticated()) {
        $notFound();
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

    $post = dpl_with_image($post, $imageStore);

    if ($unlocked) {
        $pageTitle = $post['title'];
        require dpl_template_dir($siteConfig['template']) . '/post.php';
    } else {
        $pageTitle = 'Private post';
        require DPL_INTERNAL_DIR . '/private.php';
    }
}, 'post');

$router->map('GET', '/feed', function () use ($requireConfig, $siteConfig, $blogStore, $router) {
    $requireConfig();

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
    echo '<title>' . $xml($siteConfig['name'] ?: 'Dropplets') . '</title>' . "\n";
    echo '<link>' . $xml($base . $router->generate('home')) . '</link>' . "\n";
    echo '<description>' . $xml($siteConfig['info']) . '</description>' . "\n";
    echo '<atom:link href="' . $xml($base . $router->generate('feed')) . '" rel="self" type="application/rss+xml"/>' . "\n";

    $posts = $blogStore->findBy(['draft', '=', false], ['date' => 'desc'], 20);
    foreach ($posts as $post) {
        // Never leak the body of a password-protected post into the feed.
        if (!empty($post['password'])) {
            continue;
        }
        $url = $base . $router->generate('post', ['id' => $post['_id']]);
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
    if ($blogStore->findById((int) $id) === null) {
        $notFound();
    }
    $blogStore->updateById((int) $id, ['draft' => false]);
    $redirect('dashboard');
}, 'publish');

$router->map('POST', '/post/[i:id]/hide', function ($id) use ($requireConfig, $requireAuth, $blogStore, $redirect, $notFound) {
    $requireConfig();
    $requireAuth();
    if ($blogStore->findById((int) $id) === null) {
        $notFound();
    }
    $blogStore->updateById((int) $id, ['draft' => true]);
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
    if (isset($post['image']) && is_numeric($post['image'])) {
        $record = $imageStore->findById((int) $post['image']);
        if ($record && !empty($record['path']) && is_file($record['path'])) {
            @unlink($record['path']);
        }
        $imageStore->deleteById((int) $post['image']);
    }
    $blogStore->deleteById((int) $id);
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
        $post['title']   = dpl_clean($_POST['blogPostTitle']);
        $post['author']  = dpl_clean($_POST['blogPostAuthor']);
        $post['content'] = (string) $_POST['blogPostContent']; // markdown stored raw, escaped at render
        $post['password'] = dpl_hash_post_password($_POST['blogPostPassword'] ?? '', $post['password'] ?? '');

        // Replace the image only if a new upload or URL was supplied.
        $newImage = dpl_resolve_image($images, $_FILES['imageUpload'] ?? null, $_POST['blogPostImageURL'] ?? '');
        if ($newImage !== null) {
            $rec = $imageStore->insert(['url' => $newImage[0], 'path' => $newImage[1]]);
            $post['image'] = $rec['_id'];
        }

        $blogStore->update($post);
        $redirect('dashboard');
    }

    $post = dpl_with_image($post, $imageStore);
    $pageTitle = 'Edit Post';
    require DPL_INTERNAL_DIR . '/write.php';
}, 'editPost');

$router->map('GET|POST', '/write', function () use ($requireConfig, $requireAuth, $siteConfig, $blogStore, $imageStore, $images, $router, $redirect) {
    $requireConfig();
    $requireAuth();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['blogPostTitle'], $_POST['blogPostContent'], $_POST['blogPostAuthor'])) {
            $redirect('write');
        }
        $post = [
            'title'    => dpl_clean($_POST['blogPostTitle']),
            'date'     => time(),
            'draft'    => true,
            'author'   => dpl_clean($_POST['blogPostAuthor']),
            'content'  => (string) $_POST['blogPostContent'],
            'password' => dpl_hash_post_password($_POST['blogPostPassword'] ?? '', ''),
        ];

        $image = dpl_resolve_image($images, $_FILES['imageUpload'] ?? null, $_POST['blogPostImageURL'] ?? '');
        if ($image !== null) {
            $rec = $imageStore->insert(['url' => $image[0], 'path' => $image[1]]);
            $post['image'] = $rec['_id'];
        }

        $blogStore->insert($post);
        $redirect('dashboard');
    }

    $pageTitle = 'Write';
    require DPL_INTERNAL_DIR . '/write.php';
}, 'write');

// ---------------------------------------------------------------------------
// Settings / auth
// ---------------------------------------------------------------------------

$router->map('GET|POST', '/settings', function () use ($configStore, $siteConfig, $router, $redirect) {
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
            'name'         => dpl_clean($_POST['blogName']),
            'info'         => dpl_clean($_POST['blogInfo'] ?? ''),
            'domain'       => dpl_clean_url($_POST['blogDomain']),
            'OGImage'      => dpl_clean($_POST['blogOGImage'] ?? ''),
            'footer'       => dpl_clean($_POST['blogFooter'] ?? ''),
            // headerInject is intentional raw markup (analytics snippets), only
            // ever writable by an authenticated admin and now CSRF-protected.
            'headerInject' => (string) ($_POST['blogHeaderInject'] ?? ''),
            'password'     => $password,
            'template'     => basename(dpl_clean($_POST['blogTemplate'])),
            'postsPerPage' => $postsPerPage,
            'basePath'     => dpl_clean($_POST['blogBase'] ?? ''),
            'timezone'     => dpl_clean($_POST['blogTimezone']),
            'I18N'         => dpl_clean($_POST['blogI18N']),
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
    require DPL_INTERNAL_DIR . '/settings.php';
}, 'settings');

$router->map('GET|POST', '/login', function () use ($configStore, $siteConfig, $router, $redirect) {
    $requireConfigExists = $configStore->exists();
    if (!$requireConfigExists) {
        $redirect('settings');
    }
    if (Security::isAuthenticated()) {
        $redirect('dashboard');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $lockedFor = Security::loginLockedFor(DPL_DATA_DIR);
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
            Security::clearLoginFailures(DPL_DATA_DIR);
            Security::regenerate();
            $_SESSION['isAuthenticated'] = true;
            $redirect('dashboard');
        }
        // Generic failure: do not reveal whether the password was close.
        Security::recordLoginFailure(DPL_DATA_DIR);
        $_SESSION['login_error'] = 'That password was not correct.';
        $redirect('login');
    }

    $pageTitle = 'Log In';
    $loginError = $_SESSION['login_error'] ?? '';
    unset($_SESSION['login_error']);
    require DPL_INTERNAL_DIR . '/login.php';
}, 'login');

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
    require DPL_INTERNAL_DIR . '/dashboard.php';
}, 'dashboard');

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

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
 * mixed contexts). Encoding happens at output via Dropplets\e().
 */
function dpl_clean(string $value): string
{
    $value = trim($value);
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
}

/**
 * Accept only an absolute http/https URL; anything else becomes the empty
 * string (the app then falls back to host-relative links).
 */
function dpl_clean_url(string $value): string
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
function dpl_hash_post_password(string $submitted, string $existingHash): string
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
function dpl_resolve_image(ImageHandler $images, ?array $file, string $url): ?array
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
