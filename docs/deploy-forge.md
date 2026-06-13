# Deploying Fieldnote on Laravel Forge (nginx + PHP-FPM)

Fieldnote is plain PHP — not Laravel — but it deploys like any PHP site: a
front controller in `public/`, nginx `try_files`, and `composer install` on
deploy. This guide covers a Forge-managed VPS (the same pattern works on any
nginx + PHP-FPM host). The worked example is `brentpassmore.com`; substitute
your own domain throughout.

## Requirements

- PHP **8.1+** with extensions: `curl`, `gd`, `json`, `mbstring`, `zip`,
  `dom`, `simplexml` (`zip` for Markdown export/import; `dom` + `simplexml` for
  the platform importers; `openssl` — core — for ActivityPub keys).
- Composer.
- Two writable, gitignored runtime dirs, created on deploy: `data/`
  (config, posts, the `pages` store, ActivityPub keys) and `public/uploads/`.
- `data/` lives **outside** the web root — never serve it. The only PHP in the
  web root is `public/index.php`.

## 1. DNS

Point the domain at the server:

```
A   brentpassmore.com   -> <VPS IP>
```

(Apex works. A subdomain works too — whatever the domain is becomes the
ActivityPub handle domain, e.g. `@you@brentpassmore.com`.)

## 2. Forge site

New Site:

- **Root domain:** `brentpassmore.com`
- **Project type:** General PHP / Laravel
- **Web directory:** `/public`
- **PHP version:** 8.2 or 8.3

Forge's default nginx template already roots at `…/public` and includes
`try_files $uri $uri/ /index.php?$query_string;`, which is exactly what
Fieldnote's front controller needs. No custom nginx is required to serve it.

## 3. Repository + deploy script

- **Git repository:** `bpmore/fieldnote`, branch `main`.
- **Deploy script:**

```bash
cd /home/forge/brentpassmore.com
git pull origin $FORGE_SITE_BRANCH
composer install --no-dev --optimize-autoloader --no-interaction
mkdir -p data public/uploads
chmod -R 775 data public/uploads
```

No `artisan`, no migrations — Fieldnote stores everything in flat files under
`data/`. The `mkdir`/`chmod` lines matter: `data/` is gitignored, so a fresh
clone has none of it, and PHP-FPM (the `forge` user) must be able to write
both dirs.

Deploy.

## 4. Extensions + security headers (one-time, over SSH)

If `php -m` is missing `gd` or `zip` (export/import and image processing need
them), install and restart FPM (match the PHP version):

```bash
sudo apt-get install -y php8.3-gd php8.3-zip
sudo service php8.3-fpm restart
```

Fieldnote sends a strict Content-Security-Policy from PHP, but the three header
rules in `public/.htaccess` are Apache-only — nginx ignores `.htaccess`. Add
them once in the Forge nginx editor inside the `server { … }` block so they
apply on nginx too:

```nginx
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
```

## 5. SSL

Forge → site → SSL → Let's Encrypt → `brentpassmore.com`. DNS must resolve
first. Fieldnote follows whatever TLS the server terminates and 301-redirects
any non-canonical host to the configured domain.

## 6. First-run setup

Visit `https://brentpassmore.com`. The setup wizard runs on first load:

- Set the blog name and an admin password.
- Set the **Domain** field to `https://brentpassmore.com` — this drives
  canonical URLs, feeds, the sitemap, and the ActivityPub actor id.

Behind Cloudflare or another proxy? Also fill the **trusted proxy** field
(Settings → Advanced) so login rate-limiting sees real client IPs.

## 7. Enable ActivityPub federation (optional)

Settings → Features:

- Set the **AP handle** (e.g. `bpmore`) and save. **The handle locks once
  federation is enabled** — changing it later orphans followers.
- Enable **Fediverse federation** and save.

The blog is now followable as `@<handle>@<domain>`. An RSA-2048 keypair is
minted on first actor fetch into `data/activitypub/keys.json` (0640, outside
the web root). AP-1 is "followable but silent": follows are accepted and
signed, but posts do not yet federate (that's AP-2).

Verify from the server or anywhere:

```bash
curl -s "https://brentpassmore.com/.well-known/webfinger?resource=acct:bpmore@brentpassmore.com"
curl -s -H "Accept: application/activity+json" https://brentpassmore.com/ap/actor
```

The webfinger returns a JRD pointing at `/ap/actor`; the actor is a `Person`
with a `publicKey.publicKeyPem`.

## 8. Sanity checks

```bash
php bin/audit-themes.php   # 70/70 themes pass the WCAG gate
php bin/smoke-test.php     # full end-to-end suite (needs ext-zip)
```

## Notes

- The repo ships a `Dockerfile` / `docker-compose.yml` for container hosts;
  Forge serves PHP natively, so they are unused here.
- Updating: push to `main`, then redeploy in Forge (or rerun the deploy
  script). Content in `data/` and `public/uploads/` is never touched by a
  deploy.
- Moving a site between hosts: rsync `data/` and `public/uploads/`, or use the
  dashboard Export/Import. Passkeys are domain-bound and must be re-registered;
  the AP handle is domain-bound too.
