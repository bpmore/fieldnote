# Upgrading from Dropplets 2.2 to 3.0

This is a security and modernization rewrite. It keeps the project goals intact (no database, flat-file posts, single password access, simple templates) but changes the project layout, dependency management, and several internals. Read this before deploying over an existing install.

## What changed at a glance

1. The web root is now `public/`. Point your server (Apache vhost `DocumentRoot`, nginx `root`, or shared-host subfolder) at `public/`, not the project root.
2. Configuration and post data moved OUTSIDE the web root, into `data/` (`data/config.php` and `data/siteDatabase/`). On the old layout these sat next to `index.php` and were only protected by `.htaccess`, which did nothing on non-Apache servers.
3. Dependencies are managed by Composer instead of git submodules. Run `composer install` after pulling. The `AltoRouter`, `parsedown`, `parsedown-extra`, and `SleekDB` submodules are gone, along with the abandoned bundled `ImageCache` library.
4. PHP 8.1 or newer is required (the codebase targets 8.1+, the Docker image ships 8.3). PHP 7.4 reached end of life in November 2022.

## Security fixes included

These are behavior changes worth knowing about:

- Stored cross-site scripting is fixed. Post bodies now render through Parsedown in safe mode, and every user-controlled value (title, author, footer, image URLs) is escaped at output. Posts that previously relied on raw HTML in the body will no longer execute that HTML.
- PHP code injection through the settings form is fixed. Config is serialized with `var_export()` rather than string-concatenated into PHP source.
- Cross-site request forgery protection is now enforced on every POST. All admin forms include a token.
- The destructive actions (publish, hide, delete) are POST-only. The old GET URLs (for example `/post/5/delete`) no longer exist and will 404. If you bookmarked or scripted any of these, switch to a POST with a CSRF token.
- Server-side request forgery in the remote-image feature is fixed. Featured-image URLs that resolve to private, loopback, or reserved addresses, or that use a non-HTTP scheme, are rejected.
- Per-post passwords are now hashed with `password_hash()` instead of stored and compared in plaintext, and are accepted only via POST.
- Sessions use `HttpOnly`, `SameSite=Lax`, and `Secure` (over HTTPS) cookies, and the session ID is regenerated on login.

## Migrating existing content

Your old posts live in the `siteDatabase/` folder. Move that folder to `data/siteDatabase/` in the new layout.

Two data caveats:

- Old posts whose `password` field holds a plaintext value will no longer match on the post-unlock screen, because unlock now expects a bcrypt hash. Re-save those posts through the editor and set the password again, or clear it.
- Old posts whose bodies contain intentional raw HTML will have that HTML neutralized by safe mode. If you genuinely need raw HTML in a post, that is now a template-level decision rather than an open door for every post.

Your old `config.php` cannot be reused directly: the new format is a returned array written by the app. The simplest path is to delete it and walk through `/settings` once to regenerate it. Your password will need to be set again at that point.

## Local development

```
composer install
php -S 127.0.0.1:8000 -t public public/index.php
```

Then open `http://127.0.0.1:8000/settings` to create the blog.

## Notes

- The header injection field is still raw, unescaped markup by design (it is meant for analytics snippets). It is only writable from the authenticated, CSRF-protected settings form. Treat it as trusted admin input. Note it expects HTML — plain text entered there will show up as visible text at the top of every page.
- The Markdown editor was switched from the unmaintained SimpleMDE to its maintained successor EasyMDE. No content change, just the editor widget.

## 3.1 hardening and refresh (June 2026)

Changes since the initial 3.0 rewrite:

- Draft posts are no longer readable by anyone who guesses the URL; `/post/{id}` returns 404 for drafts unless you are logged in.
- Login is rate-limited: five failed attempts from one address lock that address out for 15 minutes (`data/login_throttle.json`; addresses are stored hashed).
- Security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`) are sent from PHP on every response, so they apply on nginx and the built-in server, not just Apache.
- Admin pages run under a strict Content-Security-Policy with no inline scripts or styles. All admin assets (Bootstrap, EasyMDE) are self-hosted under `public/static/vendor/` — no CDN dependency at all.
- The image fetcher pins the validated IP for the actual download (`CURLOPT_RESOLVE`), closing the DNS-rebinding window, and enforces the 10 MB cap even on responses without a `Content-Length`.
- First-run setup logs you in immediately instead of bouncing you to the login form.
- An RSS feed lives at `/feed` (password-protected posts are excluded from it).
- The public theme was rebuilt: semantic HTML, one ~7 KB stylesheet, system fonts, zero JavaScript, dark-mode support, lazy-loaded images, full OpenGraph/Twitter/canonical metadata. A public page is now ~10 KB of HTML+CSS instead of ~320 KB of framework assets.
- Fixed: i18n strings (and date formats) silently fell back to their raw key names because the language table never reached global scope under Composer's file autoloading.
- Fixed: the admin stylesheet pointed at a path outside the web root and never loaded.
