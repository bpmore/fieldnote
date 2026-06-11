# Dropplets 3.0 security fixes

Each finding from the source audit, what was changed, and how it was verified. All checks below were run against the rewrite with PHP 8.3 and the live built-in server.

## Critical

**Stored XSS in post rendering.** Post bodies render through `ParsedownExtra` with `setSafeMode(true)`, and titles, authors, and image URLs are escaped with `htmlspecialchars` via the `Dropplets\e()` helper. Input is no longer HTML-encoded on the way in (that caused double-encoding and mixed contexts); encoding happens at output.
Verified: a post with `<script>alert(1)</script>` in the title, `"><img src=x onerror=alert(2)>` in the author, and a `<script>` plus `javascript:` link in the body rendered with zero live script tags. The title appears as `&lt;script&gt;...`, the author as inert entities, and the `javascript:` link was stripped, while normal markdown (bold) still rendered.

**PHP code injection via config generation.** `config.php` is now written with `var_export()` of the config array and loaded with `require`, instead of concatenating raw POST values into PHP source. See `src/Config.php`.
Verified: setup writes a valid returnable-array config; values containing quotes are serialized safely.

**Missing CSRF protection.** A per-session token is generated (`src/Security.php`), embedded in every admin form via `csrf_field()`, and enforced centrally for every POST in `src/routes.php` before any handler runs. Failures return HTTP 419.
Verified: POST to `/settings` without a token returned 419; the same POST with the token succeeded (302 to dashboard).

**Session handling.** `session_start()` now runs first in the bootstrap with `HttpOnly`, `SameSite=Lax`, and `Secure` (under HTTPS) cookie params, and `session_regenerate_id(true)` fires on successful login. See `Security::startSession()` and `Security::regenerate()`.

## High

**SSRF in remote image fetch.** The abandoned ImageCache library is removed. `src/ImageHandler.php` accepts only http/https URLs, resolves the host, and rejects any private, loopback, or reserved IP (IPv4 and IPv6), then downloads with cURL and redirects disabled, with size and content caps, and re-encodes through GD (which also strips polyglot payloads).
Verified: 8/8 URL cases passed, cloud metadata (169.254.169.254), 127.0.0.1, localhost, 10.x, 192.168.x, file://, and ftp:// all rejected; a public https image URL allowed.

**Destructive GET actions.** Publish, hide, and delete are POST-only routes, each requiring a CSRF token. The old GET URLs no longer exist.
Verified: `GET /post/1/delete` returned 404; publish via POST with token returned 302.

**Plaintext per-post passwords.** Post passwords are hashed with `password_hash()` and checked with `password_verify()`, accepted only via POST (never `$_REQUEST`/GET, so they no longer leak into logs or referers).

## Medium and lower

**Config and data exposure.** The web root is now `public/`. `config.php` and `siteDatabase/` live in `data/`, outside the web root, so the password hash and post store are unreachable over HTTP on any server, not just Apache.
Verified: `GET /config.php` over HTTP returned 404 while the file exists on disk in `data/`; the stored password is a `$2y$` bcrypt hash.

**Path traversal in template selection.** `dpl_template_dir()` runs the stored template name through `basename()` and only accepts a directory that actually exists and contains `home.php` and `post.php`, otherwise it falls back to `liquid-new`.

**Dependencies.** Git submodules pinned to 2021 commits replaced with Composer packages (`altorouter/altorouter`, `erusev/parsedown`, `erusev/parsedown-extra`, `rakibtg/sleekdb`), so security updates flow through `composer update`.

**End-of-life runtime.** Dockerfile moved from `php:7.4-apache` (EOL) to `php:8.3-apache`, document root set to `public/`, and only the needed extensions installed.

**Smaller items.** 404 responses now send a real `404` status code with an optional themed page; image filenames are random and filesystem-safe (no colons, no collisions); pagination off-by-one in `home.php` rewritten; the unmaintained SimpleMDE editor replaced with maintained EasyMDE.

## Not changed by design

The header injection field remains raw, unescaped markup. It is intended for analytics snippets, is writable only from the authenticated and now CSRF-protected settings form, and should be treated as trusted admin input. A strict Content-Security-Policy was left to per-deployment configuration because the bundled templates load Bootstrap from a CDN.
