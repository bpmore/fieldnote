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
        if (!empty($siteConfig['statsEnabled'])) {
            (new Stats(FN_DATA_DIR))->record((string) ($post['slug'] ?? ''));
        }
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

    $posts  = $blogStore->findBy(['draft', '=', false], ['date' => 'desc'], 20);
    $newest = (int) ($posts[0]['date'] ?? 0);
    fn_conditional_get(fn_feed_seed('rss', $posts, $publishedCount, $siteConfig), $newest ?: time());

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
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base   = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
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
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base   = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
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
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base   = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
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
    $css = fn_palette_css($siteConfig);
    fn_conditional_get('palette|' . $css, time() - 60);
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
    require fn_template_dir($siteConfig['template']) . '/post.php';
}, 'accessibility');

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
    $limit     = count($allPosts);
    $pageTitle = $q === '' ? 'Search' : 'Search: ' . $q;
    require fn_template_dir($siteConfig['template']) . '/home.php';
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
    $limit    = count($allPosts);
    $pageTitle = 'Tagged: ' . $tag;
    require fn_template_dir($siteConfig['template']) . '/home.php';
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
    $update = ['draft' => false, 'scheduledFor' => 0]; // manual publish supersedes a schedule
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

        // Keep the previous text as a revision (newest last, capped at 10)
        // whenever this save actually changes something a writer could lose.
        $newContent = (string) $_POST['blogPostContent'];
        $newAuthor  = fn_clean($_POST['blogPostAuthor']);
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

        $blogStore->update($post);
        fn_flash_content_lint((string) $post['title'], (string) $post['content']);
        $redirect('dashboard');
    }

    $post = fn_with_image($post, $imageStore);
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
    $restore     = $revisions[$index];
    $revisions[] = [
        'title'   => (string) ($post['title'] ?? ''),
        'content' => (string) ($post['content'] ?? ''),
        'author'  => (string) ($post['author'] ?? ''),
        'savedAt' => time(),
    ];
    $post['revisions'] = array_slice($revisions, -10);
    if (($restore['title'] ?? '') !== ($post['title'] ?? '')) {
        $post['slug'] = fn_unique_slug($blogStore, (string) $restore['title'], (int) $id);
    }
    $post['title']   = (string) ($restore['title'] ?? '');
    $post['author']  = (string) ($restore['author'] ?? '');
    $post['content'] = (string) ($restore['content'] ?? '');
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

        // A new password mints a new session epoch: every other logged-in
        // device dies with the credential it was minted under. The session
        // submitting this form is re-stamped below so the admin stays in.
        $sessionEpoch = ($firstRun || !empty($_POST['blogPassword']))
            ? bin2hex(random_bytes(16))
            : (string) ($siteConfig['sessionEpoch'] ?? '');

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
            'sessionEpoch' => $sessionEpoch,
            'template'     => basename(fn_clean($_POST['blogTemplate'])),
            // Not part of this form; carried over or it would vanish on
            // every settings save. (Theme-keyed: inert after a switch.)
            'paletteOverrides' => $siteConfig['paletteOverrides'] ?? [],
            'searchEnabled' => !empty($_POST['blogSearchEnabled']),
            'statsEnabled' => !empty($_POST['blogStatsEnabled']),
            'trustedProxies' => fn_clean($_POST['blogTrustedProxies'] ?? ''),
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
        Security::setSessionEpoch($sessionEpoch);
        Security::stampSessionEpoch();
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
            Security::stampSessionEpoch();
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
            Security::stampSessionEpoch();
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
            // Previewing the active theme: replay saved palette overrides
            // after the theme tokens so the miniature matches the live site.
            $extra = '';
            $po = $siteConfig['paletteOverrides'] ?? [];
            if ($name === $siteConfig['template'] && is_array($po) && ($po['theme'] ?? '') === $name) {
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

    // Resolve the theme's own tokens per scheme, exactly as the auditor does:
    // :root is the default scheme, the media block overrides the other one.
    $rootTokens = CssTokens::extractTokens((string) CssTokens::rootBlock($css));
    $darkBody   = CssTokens::schemeBlock($css, 'dark');
    $lightBody  = CssTokens::schemeBlock($css, 'light');
    $schemeTok  = CssTokens::extractTokens((string) ($darkBody ?? $lightBody ?? ''));
    $themeTokens = ($darkBody !== null || $lightBody === null)
        ? ['light' => $rootTokens, 'dark' => array_merge($rootTokens, $schemeTok)]
        : ['dark' => $rootTokens, 'light' => array_merge($rootTokens, $schemeTok)];

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

        $newOverrides = ['theme' => $theme, 'light' => [], 'dark' => []];
        foreach (['light', 'dark'] as $scheme) {
            $effective = [];
            foreach (Wcag::REQUIRED_TOKENS as $tok) {
                $v = strtolower(trim((string) ($_POST['tok'][$scheme][$tok] ?? '')));
                if (!preg_match('/^#[0-9a-f]{6}$/', $v)) {
                    $v = $themeDefaults[$scheme][$tok];
                }
                $effective[$tok] = $v;
                if ($v !== $themeDefaults[$scheme][$tok]) {
                    $newOverrides[$scheme][$tok] = $v;
                }
            }
            foreach (Wcag::failingPairs($effective) as $f) {
                $f['suggest'] = Wcag::suggestColor($effective[$f['fg']], $effective[$f['bg']], $f['min']);
                $failures[$scheme][] = $f;
            }
            $values[$scheme] = $effective; // re-show exactly what was submitted
        }

        if ($failures === []) {
            $siteConfig['paletteOverrides'] =
                ($newOverrides['light'] === [] && $newOverrides['dark'] === []) ? [] : $newOverrides;
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
