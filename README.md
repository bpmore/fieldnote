# Dropplets 3.0

A minimalist markdown blogging platform. This is a modernized, security-hardened fork of [johnroper100/dropplets](https://github.com/johnroper100/dropplets) (which was itself a continuation of the original Dropplets).

No database, flat-file posts, a single password to manage everything, and a simple template system. The goals of the original are unchanged; the internals are rebuilt for PHP 8 and current security practice.

## Requirements

- PHP 8.1 or newer with the `gd`, `curl`, `mbstring`, and `json` extensions
- [Composer](https://getcomposer.org/)

## Get going

```
git clone https://github.com/bpmore/dropplets.git
cd dropplets
composer install
```

Point your web server's document root at the `public/` directory. Then:

1. Navigate to `https://your-domain/settings`
2. Fill in the form and set your password
3. Click create, and you land on the dashboard

For a quick local run without a web server:

```
composer install
php -S 127.0.0.1:8000 -t public public/index.php
# then open http://127.0.0.1:8000/settings
```

### Docker

```
docker compose up --build
# open http://localhost:8080/settings
```

## Manage your blog

Go to `https://your-domain/dashboard`. Writing, editing, publishing, hiding, deleting, settings, and logout all live there.

An RSS feed of published posts is available at `/feed`.

## Project layout

```
public/        <- web root (index.php, static/, uploads/, .htaccess)
src/           <- application code (PSR-4, namespace Dropplets\)
internal/      <- admin views (login, dashboard, write, settings)
templates/     <- front-end themes; liquid-new ships by default
data/          <- config.php and siteDatabase/ (NOT web-accessible)
vendor/        <- Composer dependencies
```

All assets are self-hosted (`public/static/`): no CDNs, no webfonts. Public pages ship a single ~7 KB stylesheet and no JavaScript, with automatic dark mode.

Keeping `data/` outside `public/` means your password hash and post store are never reachable over HTTP, regardless of web server.

## Building templates

A template is a folder under `templates/` with two required files:

- `home.php` lists posts
- `post.php` displays a single post

These variables are available:

- `$siteConfig` is the site configuration array
- `$allPosts` is the array of posts for the current page (each includes a resolved `imageUrl`)
- `$page` is the current page number
- `$limit` is the posts-per-page count
- `$post` is the single post array (on `post.php`, also with `imageUrl`)
- `$router` is the AltoRouter instance for generating URLs

Always escape user-controlled values in templates with `Dropplets\e()`, and render post bodies through Parsedown in safe mode (see the shipped `liquid-new/post.php` for the pattern). A `404.php` in the template is optional and used for not-found pages.

## Security

See `UPGRADE.md` for the full list of fixes relative to 2.2. In short: stored XSS, settings-form code injection, missing CSRF protection, GET-based destructive actions, image-fetch SSRF, and plaintext per-post passwords have all been addressed.

## License

GPL-3.0-or-later, same as upstream.
