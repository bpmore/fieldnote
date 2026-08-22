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
$passkeys = new Passkeys(FN_DATA_DIR);

// WebAuthn relying party. The RP ID is the bare host (no scheme, no port);
// passkeys are bound to it, so changing the site's domain orphans them —
// documented in the spec and warned about in settings.
$webauthn = static function () use ($siteConfig): \lbuchs\WebAuthn\WebAuthn {
    $host = (string) (parse_url((string) $siteConfig['domain'], PHP_URL_HOST)
        ?: preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    return new \lbuchs\WebAuthn\WebAuthn(
        $siteConfig['name'] !== '' ? (string) $siteConfig['name'] : 'Fieldnote',
        strtolower($host),
        ['none'], // we don't care which vendor made the authenticator
        true      // base64url binary fields in the JSON args
    );
};

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
    $tpl = fn_template_dir(fn_effective_template($siteConfig)) . '/404.php';
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
    fn_render_home($siteConfig, $router, $blogStore, $imageStore, fn_template_dir(fn_effective_template($siteConfig)), $postsPerPage, $numPages);
}, 'home');

$router->map('GET', '/[i:page]', function ($page) use ($requireConfig, $siteConfig, $blogStore, $imageStore, $postsPerPage, $numPages, $router, $notFound) {
    $requireConfig();
    $page  = max(1, (int) $page);
    if ($page > $numPages) {
        $notFound();
    }
    $skip  = ($page - 1) * $postsPerPage;
    $allPosts = array_map(
        fn ($p) => fn_with_image($p, $imageStore),
        $blogStore->findBy(['draft', '=', false], ['date' => 'desc'], $postsPerPage, $skip)
    );
    $pageTitle = 'Posts | Page ' . $page;
    require fn_template_dir(fn_effective_template($siteConfig)) . '/home.php';
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
        // The owner's own visits (now invited by the inline edit control) must
        // not inflate the view count.
        if (!empty($siteConfig['statsEnabled']) && !Security::isAuthenticated()) {
            (new Stats(FN_DATA_DIR))->record((string) ($post['slug'] ?? ''));
        }
        $pageTitle = $post['title'];
        require fn_template_dir(fn_effective_template($siteConfig)) . '/post.php';
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

    $posts  = $blogStore->findBy(['draft', '=', false], ['date' => 'desc'], 20);
    $newest = (int) ($posts[0]['date'] ?? 0);
    fn_conditional_get(fn_feed_seed('rss', $posts, $publishedCount, $siteConfig), $newest ?: time());

    $base = rtrim((string) $siteConfig['domain'], '/');
    if ($base === '') {
        $base = fn_request_base();
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
        foreach ((array) ($post['tags'] ?? []) as $tag) {
            echo '<category>' . $xml((string) $tag) . '</category>' . "\n";
        }
        echo '<description>' . $xml($parser->text((string) $post['content'])) . '</description>' . "\n";
        echo '</item>' . "\n";
    }
    echo '</channel></rss>';
    exit;
}, 'feed');

$router->map('GET', '/feed.json', function () use ($requireConfig, $siteConfig, $blogStore, $publishedCount, $router) {
    $requireConfig();

    $posts  = $blogStore->findBy(['draft', '=', false], ['date' => 'desc'], 20);
    $newest = (int) ($posts[0]['date'] ?? 0);
    fn_conditional_get(fn_feed_seed('json', $posts, $publishedCount, $siteConfig), $newest ?: time());

    $base = rtrim((string) $siteConfig['domain'], '/');
    if ($base === '') {
        $base = fn_request_base();
    }

    $parser = new \ParsedownExtra();
    $parser->setSafeMode(true);

    $items = [];
    foreach ($posts as $post) {
        // Same rule as RSS: protected bodies never leak into a feed.
        if (!empty($post['password'])) {
            continue;
        }
        $url  = $base . fn_post_url($router, $post);
        $item = [
            'id'             => $url,
            'url'            => $url,
            'title'          => (string) $post['title'],
            'content_html'   => $parser->text((string) $post['content']),
            'date_published' => date(DATE_ATOM, (int) $post['date']),
            'authors'        => [['name' => (string) ($post['author'] ?? '')]],
        ];
        if (!empty($post['tags'])) {
            $item['tags'] = array_values((array) $post['tags']);
        }
        $items[] = $item;
    }

    header('Content-Type: application/feed+json; charset=utf-8');
    echo json_encode([
        'version'       => 'https://jsonfeed.org/version/1.1',
        'title'         => $siteConfig['name'] !== '' ? $siteConfig['name'] : 'Fieldnote',
        'home_page_url' => $base . $router->generate('home'),
        'feed_url'      => $base . $router->generate('jsonFeed'),
        'description'   => (string) $siteConfig['info'],
        'items'         => $items,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}, 'jsonFeed');

$router->map('GET', '/sitemap.xml', function () use ($requireConfig, $siteConfig, $blogStore, $publishedCount, $router) {
    $requireConfig();

    $posts  = $blogStore->findBy(['draft', '=', false], ['date' => 'desc']);
    $newest = (int) ($posts[0]['date'] ?? 0);
    fn_conditional_get(fn_feed_seed('sitemap', $posts, $publishedCount, $siteConfig), $newest ?: time());

    $base = rtrim((string) $siteConfig['domain'], '/');
    if ($base === '') {
        $base = fn_request_base();
    }
    $xml = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    echo '<url><loc>' . $xml($base . $router->generate('home')) . '</loc>'
        . ($newest > 0 ? '<lastmod>' . date('Y-m-d', $newest) . '</lastmod>' : '') . '</url>' . "\n";
    foreach ($posts as $post) {
        // Protected posts are findable by their owner, not by crawlers.
        if (!empty($post['password'])) {
            continue;
        }
        echo '<url><loc>' . $xml($base . fn_post_url($router, $post)) . '</loc>'
            . '<lastmod>' . date('Y-m-d', (int) ($post['publishedAt'] ?? $post['date'])) . '</lastmod></url>' . "\n";
    }
    echo '</urlset>';
    exit;
}, 'sitemap');

$router->map('GET', '/robots.txt', function () use ($siteConfig, $router) {
    $base = rtrim((string) $siteConfig['domain'], '/');
    if ($base === '') {
        $base = fn_request_base();
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\n"
        . "Disallow: /dashboard\n"
        . "Disallow: /admin/\n"
        . "Disallow: /login\n"
        . "Disallow: /settings\n"
        . "\n"
        . 'Sitemap: ' . $base . $router->generate('sitemap') . "\n";
    exit;
}, 'robots');

// Draft share links: an HMAC-signed, expiring URL lets someone read one
// draft without logging in. Tampering with the id, the expiry, or the token
// 404s; published posts redirect to their canonical address.
$router->map('GET', '/draft/[i:id]/[i:exp]/[:token]', function ($id, $exp, $token) use ($requireConfig, $siteConfig, $blogStore, $imageStore, $router, $notFound) {
    $requireConfig();
    $id  = (int) $id;
    $exp = (int) $exp;
    if ($exp < time() || !hash_equals(fn_draft_token($id, $exp), (string) $token)) {
        $notFound();
    }
    $post = $blogStore->findById($id);
    if ($post === null) {
        $notFound();
    }
    if (empty($post['draft'])) {
        header('Location: ' . fn_post_url($router, $post), true, 301);
        exit;
    }
    header('X-Robots-Tag: noindex, nofollow');
    $post = fn_with_image($post, $imageStore);
    $pageTitle = $post['title'];
    require fn_template_dir($siteConfig['template']) . '/post.php';
}, 'draftShare');

// Palette overrides as a real stylesheet (no inline <style> on public pages,
// so the strict CSP holds). Versioned by content hash at link time.
$router->map('GET', '/palette.css', function () use ($siteConfig) {
    // ?t names the theme the linking page rendered. Without it this request
    // would re-resolve against the clock, so a page rendered just before a
    // theme-of-day rollover could link a stylesheet served just after it.
    // Validated against installed themes; anything else falls back.
    $forTheme = basename((string) ($_GET['t'] ?? ''));
    if ($forTheme !== '' && !in_array($forTheme, fn_template_names(), true)) {
        $forTheme = '';
    }
    $css = fn_palette_css($siteConfig, $forTheme !== '' ? $forTheme : null);
    fn_conditional_get('palette|' . $forTheme . '|' . $css, time() - 60);
    header('Content-Type: text/css; charset=utf-8');
    echo $css;
    exit;
}, 'paletteCss');

// Public accessibility statement, generated from the same Wcag constants the
// auditor enforces — the page cannot drift from what the code actually checks.
$router->map('GET', '/accessibility', function () use ($requireConfig, $siteConfig, $router) {
    $requireConfig();

    $pairs = '';
    foreach (Wcag::PAIR_MATRIX as [$fgTok, $bgTok, $min]) {
        $pairs .= sprintf(
            "- `%s` (%s) on `%s`: at least %.1f:1\n",
            $fgTok,
            strtolower(Wcag::TOKEN_ROLES[$fgTok]),
            $bgTok,
            $min
        );
    }

    $content = <<<MD
This site runs on [Fieldnote](https://github.com/bpmore/fieldnote), where
accessibility is enforced by machine, not promised by policy. Every theme —
including this one — must pass an automated WCAG 2.2 AA audit before it can
ship, in the light **and** dark color scheme.

## What is checked, mechanically

**Color contrast** over every one of these token pairs, in both schemes:

{$pairs}
**Structure and interaction**, on every theme:

- A skip-to-content link as the first focusable element
- A visible focus indicator on everything interactive (removing it fails the audit)
- Exactly one `<h1>` per page
- Pagination targets at least 24×24 px (WCAG 2.5.8)
- Animation and transitions stop under `prefers-reduced-motion`
- Layouts reflow at 320 px wide with no horizontal scrolling (400% zoom)

## Enforced end to end

The audit gates the project's CI — a theme that slips below AA cannot merge.
The same contrast math runs when this site's owner customizes colors: a
palette that fails any pair above cannot be saved, only corrected. And every
page is plain HTML with one small stylesheet — no JavaScript is required to
read, navigate, or search this site.

Found something inaccessible anyway? Tell the site's owner — and if it's a
theme bug, [report it upstream](https://github.com/bpmore/fieldnote/issues).
MD;

    $post = [
        'title'    => 'Accessibility',
        'author'   => $siteConfig['name'] !== '' ? $siteConfig['name'] : 'Fieldnote',
        'date'     => time(),
        'content'  => $content,
        'imageUrl' => '',
        'tags'     => [],
    ];
    $pageTitle = 'Accessibility';
    require fn_template_dir(fn_effective_template($siteConfig)) . '/post.php';
}, 'accessibility');

// Personal profile page (docs/brag-spec.md). Owner-authored markdown, rendered
// through the active theme like /accessibility, at the owner-chosen slug
// (about/now/profile/brag). Lives in the 'pages' store, so it never appears in
// the feed, home, search, tag, or sitemap. The route only exists when enabled.
$fnProfileSlug = (string) ($siteConfig['profilePage'] ?? 'off');
if (in_array($fnProfileSlug, Config::PROFILE_SLUGS, true)) {
    $router->map('GET', '/' . $fnProfileSlug, function () use ($requireConfig, $siteConfig, $pagesStore, $router, $fnProfileSlug) {
        $requireConfig();
        $page = $pagesStore->findOneBy(['key', '=', 'profile']);
        $post = [
            'title'    => ucfirst($fnProfileSlug),
            'author'   => $siteConfig['author'] !== '' ? $siteConfig['author'] : ($siteConfig['name'] ?: 'Fieldnote'),
            'date'     => (int) ($page['updatedAt'] ?? time()),
            'content'  => (string) ($page['content'] ?? ''),
            'imageUrl' => '',
            'tags'     => [],
        ];
        $pageTitle = ucfirst($fnProfileSlug);
        require fn_template_dir(fn_effective_template($siteConfig)) . '/post.php';
    }, 'profilePage');
}

// Admin editor for the profile page. Fixed path (independent of the slug). The
// body is markdown through the same ContentLint accessibility gate as a public
// post; a failing save is refused and re-rendered with the fixes. Revisions
// are kept (last 10) on the page record.
$router->map('GET|POST', '/admin/profile', function () use ($requireConfig, $requireAuth, $siteConfig, $pagesStore, $router, $redirect) {
    $requireConfig();
    $requireAuth();
    if (!in_array((string) ($siteConfig['profilePage'] ?? 'off'), Config::PROFILE_SLUGS, true)) {
        $redirect('settings'); // disabled — nothing to edit
    }
    $page = $pagesStore->findOneBy(['key', '=', 'profile']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $newContent = (string) ($_POST['pageContent'] ?? '');
        $lintErrors = ContentLint::check($newContent);
        if ($lintErrors !== []) {
            $pageContent = $newContent;
            $pageTitle = 'Edit profile page';
            require FN_INTERNAL_DIR . '/edit-page.php';
            return;
        }
        if ($page === null) {
            $pagesStore->insert(['key' => 'profile', 'content' => $newContent, 'revisions' => [], 'updatedAt' => time()]);
        } else {
            $revisions = (array) ($page['revisions'] ?? []);
            if ($newContent !== (string) ($page['content'] ?? '')) {
                $revisions = array_slice(array_merge($revisions, [[
                    'content' => (string) ($page['content'] ?? ''),
                    'savedAt' => time(),
                ]]), -10);
            }
            $pagesStore->updateById((int) $page['_id'], ['content' => $newContent, 'revisions' => $revisions, 'updatedAt' => time()]);
        }
        $redirect('dashboard');
    }
    $pageContent = (string) ($page['content'] ?? '');
    $pageTitle = 'Edit profile page';
    require FN_INTERNAL_DIR . '/edit-page.php';
}, 'editProfile');

// Zero-JS visitor search: a server-rendered scan of published posts through
// the theme's home view. Title matches outrank body matches; protected post
// bodies are never searched (their titles are public, so titles still match).
$router->map('GET', '/search', function () use ($requireConfig, $siteConfig, $blogStore, $imageStore, $router, $notFound) {
    $requireConfig();
    if (empty($siteConfig['searchEnabled'])) {
        $notFound();
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    $results = [];
    if (mb_strlen($q) >= 2) {
        foreach ($blogStore->findBy(['draft', '=', false], ['date' => 'desc']) as $p) {
            $inTitle = mb_stripos((string) ($p['title'] ?? ''), $q) !== false;
            $inBody  = empty($p['password']) && mb_stripos((string) ($p['content'] ?? ''), $q) !== false;
            if ($inTitle || $inBody) {
                $results[] = ['rank' => $inTitle ? 0 : 1, 'post' => $p];
            }
        }
        usort($results, static fn (array $a, array $b): int =>
            [$a['rank'], -(int) $a['post']['date']] <=> [$b['rank'], -(int) $b['post']['date']]);
        $results = array_slice($results, 0, 50);
    }
    $allPosts = array_map(fn ($r) => fn_with_image($r['post'], $imageStore), $results);
    $page      = 1;
    $numPages  = 1;
    // Drives fn_search_status() in the theme's home.php — set only here, so the
    // status line never appears on the homepage or tag pages.
    $searchQuery = $q !== '' ? $q : null;
    $pageTitle = $q === '' ? 'Search' : 'Search: ' . $q;
    require fn_template_dir(fn_effective_template($siteConfig)) . '/home.php';
}, 'search');

// Tag pages: published posts carrying the tag, rendered through the theme's
// home view (same shape as the homepage; tags are slugs, so URLs stay clean).
$router->map('GET', '/tag/[:tag]', function ($tag) use ($requireConfig, $siteConfig, $blogStore, $imageStore, $router, $notFound) {
    $requireConfig();
    $tag = (string) $tag;
    $matching = array_values(array_filter(
        $blogStore->findBy(['draft', '=', false], ['date' => 'desc']),
        static fn (array $p): bool => in_array($tag, (array) ($p['tags'] ?? []), true)
    ));
    if ($matching === []) {
        $notFound();
    }
    $allPosts = array_map(fn ($p) => fn_with_image($p, $imageStore), array_slice($matching, 0, 50));
    $page     = 1;
    $numPages = 1; // fn_pagination renders nothing for a single page
    $pageTitle = 'Tagged: ' . $tag;
    require fn_template_dir(fn_effective_template($siteConfig)) . '/home.php';
}, 'tag');

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
    // Inaccessible content must not go public. All lint rules block at this
    // boundary; the writer is returned to the editor with the specific fixes
    // and the post stays a draft.
    $lintErrors = ContentLint::check((string) ($post['content'] ?? ''));
    if ($lintErrors !== []) {
        $_SESSION['content_lint_block'] = ['id' => (int) $id, 'errors' => $lintErrors];
        $redirect('editPost', ['id' => (int) $id]);
    }
    // A manual publish supersedes any pending schedule; fn_publish_post owns
    // that rule so the scheduler and this route cannot drift apart.
    fn_publish_post($blogStore, $post, time());
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

$router->map('GET|POST', '/post/[i:id]/delete', function ($id) use ($requireConfig, $requireAuth, $siteConfig, $blogStore, $imageStore, $router, $redirect, $notFound) {
    $requireConfig();
    $requireAuth();
    $post = $blogStore->findById((int) $id);
    if ($post === null) {
        $notFound();
    }
    // GET renders a server-side confirmation: the inline delete control on a
    // public post page links here, and that page runs under a no-JS CSP, so
    // the dashboard's data-confirm cannot guard a one-click delete. The actual
    // delete is the POST below (CSRF-gated centrally), the same one the
    // dashboard's confirm submits.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $pageTitle = 'Delete post';
        require FN_INTERNAL_DIR . '/confirm-delete.php';
        return;
    }
    // Clean up the linked image record and its file on disk.
    fn_delete_image($imageStore, $post['image'] ?? null);
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
        $newTitle = fn_clean($_POST['blogPostTitle']);

        // Keep the previous text as a revision (newest last, capped at 10)
        // whenever this save actually changes something a writer could lose.
        $newContent = (string) $_POST['blogPostContent'];
        $newAuthor  = fn_clean($_POST['blogPostAuthor']);

        // Editing a post that is already public must not push an inaccessible
        // version live. Drafts stay free to save (advisory warnings only) — the
        // gate is the public boundary, the same model as the theme/palette
        // enforcement. Re-render the editor with the submitted text intact and
        // the specific fixes; nothing is persisted, so the live post is
        // untouched and no image churn happens.
        if (empty($post['draft'])) {
            $lintErrors = ContentLint::check($newContent);
            if ($lintErrors !== []) {
                $post['title']   = $newTitle;
                $post['author']  = $newAuthor;
                $post['content'] = $newContent;
                $post['tags']    = fn_parse_tags((string) ($_POST['blogPostTags'] ?? ''));
                $pageTitle = 'Edit Post';
                require FN_INTERNAL_DIR . '/write.php';
                return;
            }
        }

        if ($newTitle !== ($post['title'] ?? '') || $newContent !== ($post['content'] ?? '') || $newAuthor !== ($post['author'] ?? '')) {
            $post['revisions'] = array_slice(array_merge((array) ($post['revisions'] ?? []), [[
                'title'   => (string) ($post['title'] ?? ''),
                'content' => (string) ($post['content'] ?? ''),
                'author'  => (string) ($post['author'] ?? ''),
                'savedAt' => time(),
            ]]), -10);
        }

        // Re-slug when the title changes (or when a pre-slug post is saved).
        if ($newTitle !== ($post['title'] ?? '') || empty($post['slug'])) {
            $post['slug'] = fn_unique_slug($blogStore, $newTitle, (int) $id);
        }
        $post['title']   = $newTitle;
        $post['author']  = fn_clean($_POST['blogPostAuthor']);
        $post['tags']    = fn_parse_tags((string) ($_POST['blogPostTags'] ?? ''));
        // Scheduling only matters while the post is a draft; the lazy
        // publisher in bootstrap flips it when the time passes.
        $post['scheduledFor'] = !empty($post['draft'])
            ? max(0, (int) strtotime((string) ($_POST['blogPostScheduledFor'] ?? '')))
            : 0;
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

        // Touching the post clears any held-schedule flag — the writer is
        // actively resolving it (and may have re-scheduled above).
        $post['scheduleBlocked'] = false;
        $blogStore->update($post);
        fn_flash_content_lint((string) $post['title'], (string) $post['content']);
        $redirect('dashboard');
    }

    $pageTitle = 'Edit Post';
    require FN_INTERNAL_DIR . '/write.php';
}, 'editPost');

// Restore a revision: the current text is pushed as a revision first, so a
// restore can itself be undone. Re-slugs when the restored title differs,
// matching the edit flow.
$router->map('POST', '/post/[i:id]/restore', function ($id) use ($requireConfig, $requireAuth, $blogStore, $redirect, $notFound) {
    $requireConfig();
    $requireAuth();
    $post = $blogStore->findById((int) $id);
    if ($post === null) {
        $notFound();
    }
    $revisions = array_values((array) ($post['revisions'] ?? []));
    $index     = (int) ($_POST['revision'] ?? -1);
    if (!isset($revisions[$index])) {
        $notFound();
    }
    $restore    = $revisions[$index];
    $newTitle   = (string) ($restore['title'] ?? '');
    $newAuthor  = (string) ($restore['author'] ?? '');
    $newContent = (string) ($restore['content'] ?? '');

    // A restore is a save to an already-public post, so it clears the same
    // public boundary as publishing or editing one. Without this the writer
    // could reinstate exactly the content the edit route had just refused, by
    // restoring the revision that refusal snapshotted. Checked before anything
    // is written, so a refused restore leaves the live post untouched. Drafts
    // stay free to restore, matching the edit flow.
    if (empty($post['draft'])) {
        $lintErrors = ContentLint::check($newContent);
        if ($lintErrors !== []) {
            $_SESSION['content_lint_block'] = ['id' => (int) $id, 'errors' => $lintErrors];
            $redirect('editPost', ['id' => (int) $id]);
        }
    }

    // Snapshot the version being replaced, but only when it actually differs —
    // the edit route guards the same way. Restoring one revision repeatedly
    // would otherwise push no-op entries through the ten-deep cap and evict
    // the real history.
    if ($newTitle !== ($post['title'] ?? '') || $newContent !== ($post['content'] ?? '') || $newAuthor !== ($post['author'] ?? '')) {
        $revisions[] = [
            'title'   => (string) ($post['title'] ?? ''),
            'content' => (string) ($post['content'] ?? ''),
            'author'  => (string) ($post['author'] ?? ''),
            'savedAt' => time(),
        ];
    }
    $post['revisions'] = array_slice($revisions, -10);
    // Re-slug on a title change, or when a pre-slug post is restored.
    if ($newTitle !== ($post['title'] ?? '') || empty($post['slug'])) {
        $post['slug'] = fn_unique_slug($blogStore, $newTitle, (int) $id);
    }
    $post['title']   = $newTitle;
    $post['author']  = $newAuthor;
    $post['content'] = $newContent;
    $blogStore->update($post);
    $redirect('editPost', ['id' => (int) $id]);
}, 'restoreRevision');

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
            'tags'     => fn_parse_tags((string) ($_POST['blogPostTags'] ?? '')),
            'scheduledFor' => max(0, (int) strtotime((string) ($_POST['blogPostScheduledFor'] ?? ''))),
            'content'  => (string) $_POST['blogPostContent'],
            'password' => fn_hash_post_password($_POST['blogPostPassword'] ?? '', ''),
        ];

        $image = fn_resolve_image($images, $_FILES['imageUpload'] ?? null, $_POST['blogPostImageURL'] ?? '');
        if ($image !== null) {
            $rec = $imageStore->insert(['url' => $image[0], 'path' => $image[1]]);
            $post['image'] = $rec['_id'];
        }

        $blogStore->insert($post);
        fn_flash_content_lint((string) $post['title'], (string) $post['content']);
        $redirect('dashboard');
    }

    $pageTitle = 'Write';
    require FN_INTERNAL_DIR . '/write.php';
}, 'write');

// ---------------------------------------------------------------------------
// Settings / auth
// ---------------------------------------------------------------------------

$router->map('GET|POST', '/settings', function () use ($configStore, $siteConfig, $twoFactor, $passkeys, $router, $redirect) {
    // Reachable when no config exists yet (first-time setup) OR when authed.
    if (!(Security::isAuthenticated() || !$configStore->exists())) {
        $redirect('login');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $firstRun = !$configStore->exists();

        // The admin password is required on first run and optional afterwards,
        // where blank means "keep the current one". Enforced here rather than
        // trusting the form's `required` attribute: /settings is reachable
        // unauthenticated before a config exists, so a bare POST that omits
        // the field used to store password_hash('') — a valid hash that no
        // input can ever satisfy, because /login refuses an empty submission
        // before it verifies. That locked the site permanently, recoverable
        // only by deleting data/config.php.
        $newPassword = (string) ($_POST['blogPassword'] ?? '');
        if ($firstRun && $newPassword === '') {
            $redirect('settings');
        }

        // A new password mints a new session epoch: every other logged-in
        // device dies with the credential it was minted under. The session
        // submitting this form is re-stamped below so the admin stays in.
        // One predicate for both, so the hash and the epoch cannot disagree.
        // Note !== '' rather than !empty(), or a literal "0" password would
        // silently keep the old hash and skip the rotation.
        $rotating     = $newPassword !== '';
        $password     = $rotating ? password_hash($newPassword, PASSWORD_DEFAULT) : (string) $siteConfig['password'];
        $sessionEpoch = $rotating ? bin2hex(random_bytes(16)) : (string) ($siteConfig['sessionEpoch'] ?? '');

        $postsPerPage = (int) ($_POST['blogPostsPerPage'] ?? 1);
        if ($postsPerPage < 1) {
            $postsPerPage = 1;
        }

        $profilePage = in_array($_POST['blogProfilePage'] ?? 'off', Config::PROFILE_SLUGS, true)
            ? (string) $_POST['blogProfilePage']
            : 'off';

        // Footer copyright + curated social links.
        $copyright = in_array($_POST['blogCopyright'] ?? 'off', ['blog', 'author'], true)
            ? (string) $_POST['blogCopyright']
            : 'off';
        $copyrightStartYear = preg_match('/^\d{4}$/', (string) ($_POST['blogCopyrightStartYear'] ?? ''))
            ? (string) $_POST['blogCopyrightStartYear']
            : '';
        $social = [];
        foreach (array_keys(Social::NETWORKS) as $netKey) {
            $raw = trim((string) ($_POST['blogSocial_' . $netKey] ?? ''));
            if ($raw === '') {
                continue;
            }
            // Email stores a bare address; everything else must be an http(s)
            // URL (fn_clean_url drops anything that isn't, so blanks are safe).
            $social[$netKey] = !empty(Social::NETWORKS[$netKey]['email'])
                ? fn_clean($raw)
                : fn_clean_url($raw);
            if ($social[$netKey] === '') {
                unset($social[$netKey]);
            }
        }

        $new = [
            'name'         => fn_clean($_POST['blogName'] ?? ''),
            'info'         => fn_clean($_POST['blogInfo'] ?? ''),
            'author'       => fn_clean($_POST['blogAuthor'] ?? ''),
            'domain'       => fn_clean_url($_POST['blogDomain'] ?? ''),
            'OGImage'      => fn_clean($_POST['blogOGImage'] ?? ''),
            'footer'       => fn_clean($_POST['blogFooter'] ?? ''),
            // headerInject is intentional raw markup (analytics snippets), only
            // ever writable by an authenticated admin and now CSRF-protected.
            'headerInject' => (string) ($_POST['blogHeaderInject'] ?? ''),
            'password'     => $password,
            'sessionEpoch' => $sessionEpoch,
            'template'     => basename(fn_clean($_POST['blogTemplate'] ?? '')),
            'themeOfDay'   => !empty($_POST['blogThemeOfDay']),
            // Curated rotation pool: keep only real installed themes, drop the
            // rest (a stale name can't poison the rotation). Empty = all.
            'themePool'    => array_values(array_intersect(
                fn_template_names(),
                array_map('basename', array_map('strval', (array) ($_POST['blogThemePool'] ?? [])))
            )),
            'themeRotateDays' => ((int) ($_POST['blogThemeRotateDays'] ?? 1)) === 7 ? 7 : 1,
            // Not part of this form; carried over or it would vanish on
            // every settings save. (Theme-keyed: inert after a switch.)
            'paletteOverrides' => $siteConfig['paletteOverrides'] ?? [],
            'searchEnabled' => !empty($_POST['blogSearchEnabled']),
            'statsEnabled' => !empty($_POST['blogStatsEnabled']),
            'accessibilityBadge' => !empty($_POST['blogAccessibilityBadge']),
            'profilePage' => $profilePage,
            'copyright' => $copyright,
            'copyrightStartYear' => $copyrightStartYear,
            'social' => $social,
            'federationEnabled' => !empty($_POST['blogFederationEnabled']),
            // The handle is part of the actor's identity: locked once
            // federation has been on (changing it would orphan followers).
            'apHandle' => !empty($siteConfig['federationEnabled'])
                ? (string) ($siteConfig['apHandle'] ?: 'blog')
                : (trim((string) ($_POST['blogApHandle'] ?? '')) !== '' ? fn_slugify((string) $_POST['blogApHandle']) : 'blog'),
            'trustedProxies' => fn_clean($_POST['blogTrustedProxies'] ?? ''),
            'postsPerPage' => $postsPerPage,
            'basePath'     => fn_clean($_POST['blogBase'] ?? ''),
            'timezone'     => fn_clean($_POST['blogTimezone'] ?? ''),
            'I18N'         => fn_clean($_POST['blogI18N'] ?? ''),
        ];

        // Validate what is about to be STORED, not what was posted. The old
        // gate checked raw $_POST fifty lines above this array, before the
        // normalizers ran — so blogName=" " passed and stored '', which the
        // settings view reads as "not configured yet": it reverted to the
        // first-run form and hid the 2FA and passkey sections with it.
        // fn_clean_url() likewise turns a malformed domain into '', silently
        // disabling canonical-host enforcement. Template and timezone get
        // checked against reality for the same reason themePool already is:
        // an unknown template falls back at render time so stored config
        // disagrees with the gallery, and an unknown timezone throws on every
        // request once theme-of-day is on.
        foreach (['name', 'domain', 'template', 'timezone', 'I18N'] as $requiredKey) {
            if ((string) $new[$requiredKey] === '') {
                $redirect('settings');
            }
        }
        if (!in_array($new['template'], fn_template_names(), true)
            || !in_array($new['timezone'], \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            $redirect('settings');
        }

        if (!$configStore->save($new)) {
            http_response_code(500);
            exit('Unable to write configuration. Check that the data/ directory is writable.');
        }
        // First-run setup: the visitor just chose the admin password, so log
        // them straight in instead of bouncing them to the login form.
        // The epoch moves on every save that rotates the password; the login
        // only happens on first run, where the visitor just chose it.
        Security::rotateEpoch($sessionEpoch);
        if ($firstRun) {
            Security::completeLogin(FN_DATA_DIR);
        }
        $redirect('dashboard');
    }

    $pageTitle = 'Settings';
    $needsPasskeys = true; // footer loads the WebAuthn bundle only when set
    require FN_INTERNAL_DIR . '/settings.php';
}, 'settings');

$router->map('GET|POST', '/login', function () use ($configStore, $siteConfig, $twoFactor, $passkeys, $router, $redirect) {
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
            Security::completeLogin(FN_DATA_DIR);
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
    $passkeysEnabled = $passkeys->enabled();
    $needsPasskeys   = $passkeysEnabled;
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
        if ($twoFactor->verify($code)) {
            unset($_SESSION['pending_2fa']);
            Security::completeLogin(FN_DATA_DIR);
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
                $plain = $twoFactor->enable($secret);
                if ($plain !== null) {
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
            if ($twoFactor->verify($code)) {
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

    // Render the preview AS this theme: pin the local config copy so palette
    // overrides (keyed to their authored theme) apply exactly when previewing
    // that theme — and never leak onto the others, rotation on or off.
    $siteConfig['template']   = $name;
    $siteConfig['themeOfDay'] = false;

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
            // Previewing the theme the overrides were authored for: replay
            // them after the theme tokens so the miniature matches the live
            // site.
            $extra = '';
            $po = $siteConfig['paletteOverrides'] ?? [];
            if (is_array($po) && ($po['theme'] ?? '') === $name) {
                foreach ((array) ($po[$scheme] ?? []) as $tok => $val) {
                    if (preg_match('/^--[a-z0-9-]+$/', (string) $tok) && preg_match('/^#[0-9a-f]{6}$/i', (string) $val)) {
                        $extra .= $tok . ':' . $val . ';';
                    }
                }
            }
            $GLOBALS['fnSchemeOverrideCss'] = ':root{' . trim($body) . ';' . $extra . 'color-scheme:' . $scheme . '}';
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

// Palette customizer: override the active theme's color tokens, with the
// auditor's WCAG math run server-side on save — a palette that fails the
// contrast matrix cannot be stored, only corrected.
$router->map('GET|POST', '/admin/palette', function () use ($requireConfig, $requireAuth, $configStore, $siteConfig, $router, $redirect) {
    $requireConfig();
    $requireAuth();

    $theme = (string) $siteConfig['template'];
    $css   = (string) @file_get_contents(fn_template_dir($theme) . '/assets/theme.css');

    // One implementation of the polarity rule, shared with the auditor — this
    // baseline has to match the gate's or a palette the gate would reject can
    // be validated against the wrong scheme's defaults and saved.
    $themeTokens = CssTokens::schemeTokens($css) ?? ['light' => [], 'dark' => []];

    // Hex-normalized theme defaults (color inputs only accept #rrggbb).
    $themeDefaults = ['light' => [], 'dark' => []];
    foreach (['light', 'dark'] as $scheme) {
        foreach (Wcag::REQUIRED_TOKENS as $tok) {
            $rgb = Wcag::parseColor($themeTokens[$scheme][$tok] ?? '');
            $themeDefaults[$scheme][$tok] = $rgb !== null ? Wcag::toHex($rgb) : '#000000';
        }
    }

    $saved = $siteConfig['paletteOverrides'] ?? [];
    if (!is_array($saved) || ($saved['theme'] ?? '') !== $theme) {
        $saved = [];
    }

    // What the form shows: theme defaults overlaid with saved overrides.
    $values = $themeDefaults;
    foreach (['light', 'dark'] as $scheme) {
        foreach ((array) ($saved[$scheme] ?? []) as $tok => $val) {
            $values[$scheme][$tok] = $val;
        }
    }

    $failures = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_POST['paletteAction'] ?? '') === 'reset') {
            $siteConfig['paletteOverrides'] = [];
            $configStore->save($siteConfig);
            $_SESSION['palette_saved'] = 'Palette reset to theme defaults.';
            $redirect('palette');
        }

        // Store the palette that was validated, not its difference from the
        // theme's current defaults. A diff is only meaningful against the
        // theme.css it was taken from: edit that file — a theme upgrade, a
        // hand tweak — and the live palette silently becomes new defaults plus
        // an old diff, a combination the contrast matrix never saw. Both this
        // route ("cannot be stored, only corrected") and the renderer ("both
        // halves were validated at save time") claim an invariant that only
        // storing the whole thing can keep.
        //
        // The trade is deliberate: a customized theme stops inheriting later
        // theme.css colour changes. Predictable and checked beats current and
        // unverified, for the one feature whose entire purpose is the check.
        $newOverrides = ['theme' => $theme, 'light' => [], 'dark' => []];
        $differs      = false;
        foreach (['light', 'dark'] as $scheme) {
            $effective = [];
            foreach (Wcag::REQUIRED_TOKENS as $tok) {
                $v = strtolower(trim((string) ($_POST['tok'][$scheme][$tok] ?? '')));
                if (!preg_match('/^#[0-9a-f]{6}$/', $v)) {
                    $v = $themeDefaults[$scheme][$tok];
                }
                $effective[$tok] = $v;
                if ($v !== $themeDefaults[$scheme][$tok]) {
                    $differs = true;
                }
            }
            $newOverrides[$scheme] = $effective;
            foreach (Wcag::failingPairs($effective) as $f) {
                $f['suggest'] = Wcag::suggestColor($effective[$f['fg']], $effective[$f['bg']], $f['min']);
                $failures[$scheme][] = $f;
            }
            $values[$scheme] = $effective; // re-show exactly what was submitted
        }

        if ($failures === []) {
            // Nothing changed from the theme's own colours: store nothing, so a
            // theme that was never customized keeps tracking its stylesheet.
            $siteConfig['paletteOverrides'] = $differs ? $newOverrides : [];
            if (!$configStore->save($siteConfig)) {
                http_response_code(500);
                exit('Unable to write configuration. Check that the data/ directory is writable.');
            }
            $_SESSION['palette_saved'] = 'Palette saved — every pair passes WCAG 2.2 AA.';
            $redirect('palette');
        }
        // fall through: re-render with failures and suggestions
    }

    // The one-click correction form: current values with each failing
    // foreground replaced by its computed nearest-passing shade.
    $suggestedValues = null;
    if ($failures !== []) {
        $suggestedValues = $values;
        foreach ($failures as $scheme => $list) {
            foreach ($list as $f) {
                if ($f['suggest'] === null) {
                    $suggestedValues = null;
                    break 2;
                }
                $suggestedValues[$scheme][$f['fg']] = $f['suggest'];
            }
        }
    }

    $pageTitle = 'Palette';
    $savedNotice = (string) ($_SESSION['palette_saved'] ?? '');
    unset($_SESSION['palette_saved']);
    require FN_INTERNAL_DIR . '/palette.php';
}, 'palette');

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

// ---------------------------------------------------------------------------
// ActivityPub, phase AP-1 (docs/activitypub-spec.md): followable, silent.
// All endpoints 404 unless federation is enabled in settings.
// ---------------------------------------------------------------------------

$fnApBase    = rtrim((string) $siteConfig['domain'], '/') ?: fn_request_base();
// One builder for every actor URL, so basePath lands on all of them or
// none. 'url' below already used $router->generate() while id, inbox,
// outbox and followers concatenated a literal path — so under a non-empty
// basePath the actor advertised an id and an inbox that 404, and follows
// were dropped, because the inbox compares a Follow's object against this
// value. Built from basePath rather than generate() because these are
// needed before the AP routes are registered; the CSRF exemption in the
// dispatch block already derives /ap/inbox the same way.
$fnApPath    = static fn (string $path): string => rtrim((string) $siteConfig['basePath'], '/') . $path;
$fnActorUrl  = $fnApBase . $fnApPath('/ap/actor');
$fnApHandle  = (string) ($siteConfig['apHandle'] ?: 'blog');
$fnFedOn     = !empty($siteConfig['federationEnabled']);
$federation  = new Federation(FN_DATA_DIR, $fnActorUrl);
// One acct: string, built where the host is already known. The webfinger
// route used to re-parse it out of the base it had just been handed.
$fnApHost    = (string) parse_url($fnApBase, PHP_URL_HOST)
    . (parse_url($fnApBase, PHP_URL_PORT) ? ':' . parse_url($fnApBase, PHP_URL_PORT) : '');
$fnApAcct    = "acct:$fnApHandle@$fnApHost";

$router->map('GET', '/.well-known/webfinger', function () use ($fnFedOn, $fnApAcct, $fnActorUrl, $notFound) {
    if (!$fnFedOn) {
        $notFound();
    }
    if (!in_array((string) ($_GET['resource'] ?? ''), [$fnApAcct, $fnActorUrl], true)) {
        $notFound();
    }
    header('Content-Type: application/jrd+json');
    echo json_encode([
        'subject' => $fnApAcct,
        'links'   => [['rel' => 'self', 'type' => 'application/activity+json', 'href' => $fnActorUrl]],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}, 'webfinger');

$router->map('GET', '/ap/actor', function () use ($fnFedOn, $siteConfig, $federation, $fnApBase, $fnApPath, $fnActorUrl, $fnApHandle, $router, $notFound) {
    if (!$fnFedOn) {
        $notFound();
    }
    $doc = [
        '@context' => ['https://www.w3.org/ns/activitystreams', 'https://w3id.org/security/v1'],
        'id'                => $fnActorUrl,
        'type'              => 'Person',
        'preferredUsername' => $fnApHandle,
        'name'              => $siteConfig['name'] !== '' ? (string) $siteConfig['name'] : 'Fieldnote',
        'summary'           => (string) $siteConfig['info'],
        'url'               => $fnApBase . $router->generate('home'),
        'inbox'             => $fnApBase . $fnApPath('/ap/inbox'),
        'outbox'            => $fnApBase . $fnApPath('/ap/outbox'),
        'followers'         => $fnApBase . $fnApPath('/ap/followers'),
        'manuallyApprovesFollowers' => false,
        'publicKey' => [
            'id'           => $federation->keyId(),
            'owner'        => $fnActorUrl,
            'publicKeyPem' => $federation->keys()['public'],
        ],
    ];
    if ((string) $siteConfig['OGImage'] !== '') {
        $icon = (string) $siteConfig['OGImage'];
        $doc['icon'] = ['type' => 'Image', 'url' => str_starts_with($icon, '/') ? $fnApBase . $icon : $icon];
    }
    header('Content-Type: application/activity+json');
    echo json_encode($doc, JSON_UNESCAPED_SLASHES);
    exit;
}, 'apActor');

$router->map('POST', '/ap/inbox', function () use ($fnFedOn, $federation, $fnActorUrl, $notFound) {
    if (!$fnFedOn) {
        $notFound();
    }
    $body = (string) file_get_contents('php://input');
    if (strlen($body) > 65536) {
        http_response_code(413);
        exit;
    }
    $activity = $federation->verifyInbox($body);
    if ($activity === null) {
        http_response_code(401);
        exit;
    }

    $type = (string) ($activity['type'] ?? '');
    if ($type === 'Follow' && ($activity['object'] ?? null) === $fnActorUrl) {
        $actor = $federation->fetchActor((string) $activity['actor']);
        $inbox = (string) ($actor['inbox'] ?? '');
        if ($inbox !== '') {
            $federation->addFollower(
                (string) $activity['actor'],
                $inbox,
                (string) ($actor['endpoints']['sharedInbox'] ?? '')
            );
            $federation->deliverAccept($activity, $inbox);
        }
    } elseif ($type === 'Undo' && (($activity['object']['type'] ?? '') === 'Follow')) {
        $federation->removeFollower((string) $activity['actor']);
    }
    // Every other type: acknowledged and dropped — never an error oracle.
    http_response_code(202);
    exit;
}, 'apInbox');

$router->map('GET', '/ap/outbox', function () use ($fnFedOn, $fnApBase, $notFound) {
    if (!$fnFedOn) {
        $notFound();
    }
    // AP-1 is followable-but-silent; posts arrive in AP-2.
    header('Content-Type: application/activity+json');
    echo json_encode([
        '@context'     => 'https://www.w3.org/ns/activitystreams',
        'id'           => $fnApBase . '/ap/outbox',
        'type'         => 'OrderedCollection',
        'totalItems'   => 0,
        'orderedItems' => [],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}, 'apOutbox');

$router->map('GET', '/ap/followers', function () use ($fnFedOn, $federation, $fnApBase, $notFound) {
    if (!$fnFedOn) {
        $notFound();
    }
    // Count only: the member list is nobody else's business.
    header('Content-Type: application/activity+json');
    echo json_encode([
        '@context'   => 'https://www.w3.org/ns/activitystreams',
        'id'         => $fnApBase . '/ap/followers',
        'type'       => 'OrderedCollection',
        'totalItems' => count($federation->followers()),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}, 'apFollowers');

// ---------------------------------------------------------------------------
// Export / import (roadmap 3.1)
// ---------------------------------------------------------------------------

$fnRequireZip = static function (): void {
    if (!class_exists(\ZipArchive::class)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Export/import needs PHP's zip extension (ext-zip). Install it and reload.");
    }
};

$router->map('GET', '/admin/export', function () use ($requireConfig, $requireAuth, $fnRequireZip, $siteConfig, $blogStore, $imageStore, $images) {
    $requireConfig();
    $requireAuth();
    $fnRequireZip();
    $porter = new Porter($blogStore, $imageStore, $images, FN_UPLOAD_DIR);
    $zipPath = $porter->exportZip($siteConfig);
    if ($zipPath === null) {
        http_response_code(500);
        exit('Could not build the export. Check that data/cache is writable.');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="fieldnote-export-' . date('Y-m-d') . '.zip"');
    header('Content-Length: ' . (string) filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}, 'export');

$router->map('GET|POST', '/admin/import', function () use ($requireConfig, $requireAuth, $fnRequireZip, $siteConfig, $blogStore, $imageStore, $images, $router) {
    $requireConfig();
    $requireAuth();
    $fnRequireZip();

    $analysis = null;
    $importError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $url     = trim((string) ($_POST['importUrl'] ?? ''));
        $file    = $_FILES['importZip'] ?? null;
        $hasFile = $file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name']);

        $cacheDir = FN_DATA_DIR . '/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0750, true);
        }
        $stored = $cacheDir . '/import-' . bin2hex(random_bytes(6)) . '.upload';

        if ($url !== '') {
            // Feeds are URLs — fetch through SafeHttp (SSRF-guarded), then parse.
            $res = SafeHttp::get($url, ['Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.8'], 12, 5 * 1024 * 1024);
            if ($res === null || $res[0] >= 400 || $res[1] === '') {
                $importError = 'Could not fetch that URL.';
            } else {
                file_put_contents($stored, $res[1]);
            }
        } elseif ($hasFile) {
            move_uploaded_file($file['tmp_name'], $stored);
        } else {
            $importError = 'Choose a file or enter a feed URL.';
        }

        if ($importError === '') {
            $posted = (string) ($_POST['importSource'] ?? 'auto');
            // Unknown ids fall back to sniffing rather than failing: the form
            // only offers known ones, so anything else is a stale or hand-made
            // request. Aliases resolve to the converter that actually runs.
            $source = Importers::isKnown($posted) ? Importers::canonical($posted) : 'auto';
            if ($source === 'auto') {
                $head = (string) file_get_contents($stored, false, null, 0, 4096);
                if (str_starts_with($head, "PK\x03\x04")) {
                    if (SubstackImporter::looksLikeSubstack($stored)) {
                        $source = 'substack';
                    } elseif (MediumImporter::looksLikeMedium($stored)) {
                        $source = 'medium';
                    } elseif (NotionImporter::looksLikeNotion($stored)) {
                        $source = 'notion';
                    } else {
                        $source = 'markdown';
                    }
                } elseif (WordPressImporter::looksLikeWxr($head)) {
                    $source = 'wordpress';
                } elseif (GhostImporter::looksLikeGhost($head)) {
                    $source = 'ghost';
                } elseif (DevtoImporter::looksLikeDevto($head)) {
                    $source = 'devto';
                } elseif (HashnodeImporter::looksLikeHashnode($head)) {
                    $source = 'hashnode';
                } elseif (WriteFreelyImporter::looksLikeWriteFreely($head)) {
                    $source = 'writefreely';
                } elseif (BloggerImporter::looksLikeBlogger($head)) {
                    $source = 'blogger';
                } elseif (RssImporter::looksLikeFeed($head)) {
                    $source = 'rss';
                } else {
                    $source = 'markdown';
                }
            }

            $porter   = new Porter($blogStore, $imageStore, $images, FN_UPLOAD_DIR);
            // null: no converter for this id, so it is a Fieldnote/markdown zip
            // and Porter reads the archive itself.
            $entries  = Importers::parse($source, $stored);
            $analysis = $entries === null
                ? $porter->analyze($stored)
                : $porter->analyzeEntries($entries);
            if ($analysis['posts'] === []) {
                $importError = implode(' ', $analysis['errors']) ?: 'Nothing importable found.';
                $analysis = null;
                @unlink($stored);
            } else {
                // The dry-run screen confirms against exactly this upload.
                $_SESSION['import_pending'] = ['path' => $stored, 'source' => $source, 'exp' => time() + 900];
            }
        }
    }

    $pageTitle = 'Import';
    require FN_INTERNAL_DIR . '/import.php';
}, 'import');

$router->map('POST', '/admin/import/confirm', function () use ($requireConfig, $requireAuth, $fnRequireZip, $siteConfig, $blogStore, $imageStore, $images, $redirect) {
    $requireConfig();
    $requireAuth();
    $fnRequireZip();
    $pending = $_SESSION['import_pending'] ?? null;
    unset($_SESSION['import_pending']);
    if (!is_array($pending) || $pending['exp'] < time() || !is_file($pending['path'])) {
        $redirect('import');
    }
    $porter = new Porter($blogStore, $imageStore, $images, FN_UPLOAD_DIR);
    // Same table as the dry run, by construction rather than by review.
    $entries = Importers::parse((string) ($pending['source'] ?? 'markdown'), $pending['path']);
    $_SESSION['import_result'] = $entries === null
        ? $porter->import($pending['path'], $siteConfig)
        : $porter->importEntries($entries, $siteConfig);
    @unlink($pending['path']);
    $redirect('dashboard');
}, 'importConfirm');

// ---------------------------------------------------------------------------
// Passkeys (docs/passkeys-spec.md). All POST, so the central CSRF gate covers
// them; the JS reads the token from the page it lives on.
// ---------------------------------------------------------------------------

$router->map('POST', '/settings/passkeys/options', function () use ($requireConfig, $requireAuth, $webauthn, $passkeys) {
    $requireConfig();
    $requireAuth();
    $wa = $webauthn();
    $args = $wa->getCreateArgs(
        'admin',            // single-account model: one user, fixed handle
        'admin',
        'Fieldnote admin',
        60,
        true,               // resident/discoverable: usernameless sign-in
        'required',         // user verification (biometric/PIN) is the 2nd factor
        null,
        array_map(static fn (array $c): string => Passkeys::b64uDecode($c['id']), $passkeys->list())
    );
    $_SESSION['passkey_challenge'] = [
        'value' => $wa->getChallenge()->getBinaryString(),
        'type'  => 'create',
        'exp'   => time() + 120,
    ];
    header('Content-Type: application/json');
    echo json_encode($args);
    exit;
}, 'passkeyCreateOptions');

$router->map('POST', '/settings/passkeys/register', function () use ($requireConfig, $requireAuth, $webauthn, $passkeys) {
    $requireConfig();
    $requireAuth();
    header('Content-Type: application/json');
    $challenge = $_SESSION['passkey_challenge'] ?? null;
    unset($_SESSION['passkey_challenge']); // single-use, whatever happens next
    if (!is_array($challenge) || $challenge['type'] !== 'create' || $challenge['exp'] < time()) {
        http_response_code(400);
        echo json_encode(['error' => 'Challenge missing or expired — try again.']);
        exit;
    }
    try {
        $data = $webauthn()->processCreate(
            Passkeys::b64uDecode((string) ($_POST['clientDataJSON'] ?? '')),
            Passkeys::b64uDecode((string) ($_POST['attestationObject'] ?? '')),
            $challenge['value'],
            true, // user verification
            true
        );
        $passkeys->add(
            Passkeys::b64uEncode((string) $data->credentialId),
            (string) $data->credentialPublicKey,
            (int) ($data->signatureCounter ?? 0),
            fn_clean((string) ($_POST['label'] ?? ''))
        );
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Registration failed: ' . $e->getMessage()]);
    }
    exit;
}, 'passkeyRegister');

$router->map('POST', '/settings/passkeys/delete', function () use ($requireConfig, $requireAuth, $passkeys, $redirect) {
    $requireConfig();
    $requireAuth();
    $passkeys->remove((string) ($_POST['id'] ?? ''));
    $redirect('settings');
}, 'passkeyDelete');

$router->map('POST', '/login/passkey/options', function () use ($requireConfig, $webauthn, $passkeys, $notFound) {
    $requireConfig();
    if (Security::isAuthenticated() || !$passkeys->enabled()) {
        $notFound();
    }
    $wa = $webauthn();
    // Empty credential list on purpose: discoverable credentials mean the
    // authenticator picks the passkey, and no credential ids leak here.
    $args = $wa->getGetArgs([], 60, true, true, true, true, true, 'required');
    $_SESSION['passkey_challenge'] = [
        'value' => $wa->getChallenge()->getBinaryString(),
        'type'  => 'get',
        'exp'   => time() + 120,
    ];
    header('Content-Type: application/json');
    echo json_encode($args);
    exit;
}, 'passkeyLoginOptions');

$router->map('POST', '/login/passkey/verify', function () use ($requireConfig, $webauthn, $passkeys, $router) {
    $requireConfig();
    header('Content-Type: application/json');
    if (Security::isAuthenticated()) {
        echo json_encode(['ok' => true, 'redirect' => $router->generate('dashboard')]);
        exit;
    }
    // Shares the password throttle: guessing signatures is rate-limited the
    // same as guessing passwords.
    if (Security::loginLockedFor(FN_DATA_DIR) > 0) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many failed attempts. Try again later.']);
        exit;
    }
    $challenge = $_SESSION['passkey_challenge'] ?? null;
    unset($_SESSION['passkey_challenge']);
    if (!is_array($challenge) || $challenge['type'] !== 'get' || $challenge['exp'] < time()) {
        http_response_code(400);
        echo json_encode(['error' => 'Challenge missing or expired — try again.']);
        exit;
    }
    try {
        $credential = $passkeys->find((string) ($_POST['id'] ?? ''));
        if ($credential === null) {
            throw new \RuntimeException('unknown credential');
        }
        $wa = $webauthn();
        $wa->processGet(
            Passkeys::b64uDecode((string) ($_POST['clientDataJSON'] ?? '')),
            Passkeys::b64uDecode((string) ($_POST['authenticatorData'] ?? '')),
            Passkeys::b64uDecode((string) ($_POST['signature'] ?? '')),
            (string) $credential['publicKey'],
            $challenge['value'],
            ((int) $credential['signCount']) > 0 ? (int) $credential['signCount'] : null,
            'required' // authenticator-side biometric/PIN is the second factor
        );
        $passkeys->updateSignCount((string) $credential['id'], (int) $wa->getSignatureCounter());

        // The same call as every other successful login, rather than the same
        // four lines written again.
        Security::completeLogin(FN_DATA_DIR);
        echo json_encode(['ok' => true, 'redirect' => $router->generate('dashboard')]);
    } catch (\Throwable $e) {
        Security::recordLoginFailure(FN_DATA_DIR);
        http_response_code(400);
        // Generic on purpose: do not narrate what failed.
        echo json_encode(['error' => 'Passkey sign-in failed.']);
    }
    exit;
}, 'passkeyLoginVerify');

// Rotating the app secret invalidates every draft share link ever issued
// (a fresh secret is generated on next use).
$router->map('POST', '/settings/rotate-secret', function () use ($requireConfig, $requireAuth, $redirect) {
    $requireConfig();
    $requireAuth();
    @unlink(FN_DATA_DIR . '/secret');
    $redirect('settings');
}, 'rotateSecret');

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

// The ActivityPub inbox receives signed JSON from remote servers: no CSRF
// token, no form body. Its authentication is the HTTP signature (verified
// in the handler), so both form-centric gates below must skip it. Path-based
// on purpose: with federation off the handler itself answers 404.
$fnIsApInbox = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)
        === $fnApPath('/ap/inbox');

// A FORM post whose body exceeded post_max_size reaches PHP with $_POST and
// $_FILES completely empty. Without this check the user would get a baffling
// CSRF error (the token vanished with everything else) — or worse, silently
// lose a written post. Say what actually happened. Scoped to form content
// types: those are the only ones PHP ever populates $_POST from, so a JSON
// body would always trip this otherwise.
$fnContentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (
    !$fnIsApInbox
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && (str_starts_with($fnContentType, 'application/x-www-form-urlencoded') || str_starts_with($fnContentType, 'multipart/form-data'))
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

// Canonical-host enforcement: once a domain is configured, a request that
// arrives on any other host (www vs apex, an old domain, the bare server IP)
// is permanently redirected to the canonical address — one address per page
// for crawlers, and Host-header values stay out of generated URLs. Only safe
// methods redirect; a 301 would silently drop a POST body.
$fnCanonical = parse_url((string) $siteConfig['domain']);
if (!empty($fnCanonical['host']) && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    $canonicalHostPort = strtolower($fnCanonical['host'] . (isset($fnCanonical['port']) ? ':' . $fnCanonical['port'] : ''));
    $requestHostPort   = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    // Scheme counts too: an https:// domain upgrades plain-HTTP requests.
    // X-Forwarded-Proto is honored so a TLS-terminating proxy doesn't loop —
    // a proxy that strips that header should keep the domain as http://.
    $requestIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $schemeMismatch = ($fnCanonical['scheme'] ?? '') === 'https' && !$requestIsHttps;
    if ($requestHostPort !== '' && ($requestHostPort !== $canonicalHostPort || $schemeMismatch)) {
        header('Location: ' . rtrim((string) $siteConfig['domain'], '/') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
}

// Enforce CSRF once, centrally, for every POST before any handler runs.
if (!$fnIsApInbox) {
    Security::requireValidCsrf();
}

// Public pages get a strict CSP whenever the admin hasn't injected custom
// head markup (which may legitimately carry inline analytics). Sent before
// dispatch — admin pages and theme previews replace it with their own
// policies before producing any output.
if (empty($siteConfig['headerInject'])) {
    Security::sendPublicCsp();
}

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
