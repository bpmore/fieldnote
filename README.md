# Fieldnote

**A markdown blog that respects your readers.** No database, no JavaScript,
no tracking, no build step. Write a post, pick one of 70 themes, done.
Every page your visitors see is a single HTML file and one small stylesheet.

Fieldnote (formerly Dropplets) is a modernized, security-hardened fork of
[johnroper100/dropplets](https://github.com/johnroper100/dropplets), rebuilt
for PHP 8 and current security practice.

## Why Fieldnote

**Accessibility first, enforced by a machine.** All 70 themes meet
WCAG 2.2 AA — in light mode *and* dark mode. That's not a pledge, it's a
gate: `php bin/audit-themes.php` computes the contrast ratio of every
color pair in every theme in both schemes, lints for skip links, heading
order, focus visibility, and ARIA, and fails the build if a single theme
slips. Keyboard users get a skip link and a visible focus ring on every
theme. Motion stops dead under `prefers-reduced-motion`. Layouts reflow at
320 px with no horizontal scroll, so the site works at 400 % zoom.

**Recolor any theme — it won't let you break accessibility.** The palette
customizer overrides any theme's eight color tokens, light and dark scheme
each, with the auditor's WCAG math running server-side on save. A
combination that fails contrast cannot be stored: Fieldnote computes the
nearest passing shade of *your* color (same hue, adjusted lightness) and
offers it back as a one-click fix.

**Zero JavaScript, genuinely.** Public pages ship markup and one 4–8 KB
stylesheet. Nothing else. No webfonts, no CDNs, no analytics snippet
phoning home — every asset is served from your own domain. Pages are fast
because there is nothing to be slow.

**70 themes, light and dark built into each one.** Photography walls,
recipe cards, broadsheet front pages, phosphor terminals, wanted posters,
risograph overprints. Every theme answers `prefers-color-scheme`, so your
blog follows each reader's preference automatically. The full roster is
below.

**A single password — and real two-factor.** One password runs the whole
site, protected by bcrypt, rate limiting, and optional TOTP two-factor
with single-use recovery codes. Works with 1Password, Google
Authenticator, Authy, Apple Passwords, any standard authenticator.

**Flat files you can grep.** Posts live in a flat-file store, config is a
PHP array, images are files on disk. Back up your blog with `cp -R`.
Migrate it with `rsync`. No database server to run, tune, or lose.

**Hardened where it counts.** CSRF tokens on every form, markdown rendered
in safe mode (raw HTML and `javascript:` URLs neutralized), SSRF-safe
remote image fetching with DNS pinning, destructive actions POST-only,
secrets and content stored entirely outside the web root.

## Requirements

- PHP 8.1 or newer with the `gd`, `curl`, `mbstring`, and `json` extensions
- [Composer](https://getcomposer.org/)

## Get going

```
git clone https://github.com/bpmore/fieldnote.git
cd fieldnote
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

The **theme gallery** (`/admin/themes`) shows live light + dark previews of
every installed theme with one-click apply, and links to the **palette
customizer** (`/admin/palette`) for recoloring the active theme under WCAG
enforcement.

### Two-factor login (optional)

Settings → "Two-factor login" → Set up. Scan the QR code with any TOTP
authenticator app (1Password, Google Authenticator, Authy, Apple Passwords, …),
confirm one code, and store the recovery codes it shows you. From then on,
login asks for your password and then a 6-digit code.

Recovery: each recovery code works once in place of a TOTP code. If you lose
both the authenticator and the codes, delete `data/totp.json` on the server to
fall back to password-only login.

Posts take comma-separated **tags**; each tag gets a page at `/tag/<name>`,
and tags flow into both feeds as categories.

**Scheduled publishing, no cron required.** Give a draft a publish time and
the first visitor to arrive after that moment publishes it — with the
permalink dated to the scheduled time. Works on any shared host.

**Visitor search** lives at `/search` (server-rendered, zero JS, toggleable
in Settings). Title matches rank first; password-protected post bodies are
never searched.

Syndication and discovery come built in: RSS at `/feed`, a JSON Feed at
`/feed.json`, a sitemap at `/sitemap.xml` (referenced from `/robots.txt`) —
all answering conditional requests with 304s so feed readers and crawlers
cost you nothing between posts.

## Project layout

```
public/        <- web root (index.php, static/, uploads/, .htaccess)
src/           <- application code (PSR-4, namespace Fieldnote\)
internal/      <- admin views (login, dashboard, write, settings)
templates/     <- front-end themes; liquid-new ships by default
data/          <- config.php and siteDatabase/ (NOT web-accessible)
vendor/        <- Composer dependencies
```

All assets are self-hosted: no CDNs, no webfonts. Public pages ship a single 4–8 KB theme stylesheet and no JavaScript, with automatic dark mode.

Keeping `data/` outside `public/` means your password hash and post store are never reachable over HTTP, regardless of web server.

## Themes

Seventy themes ship in `templates/`; pick one in Settings. Every theme is
zero-JS, self-hosted, supports light **and** dark mode via
`prefers-color-scheme`, and meets WCAG 2.2 AA (contrast, skip links, visible
focus, 24px targets, reduced-motion, 320px reflow) in both schemes —
mechanically enforced by `php bin/audit-themes.php`. Themes marked
*dark-identity* are dark by default and bring a light scheme for users who
prefer one.

**The originals**

| Theme | Personality |
|---|---|
| `liquid-new` | Default. Clean card grid, system fonts |
| `puddle` | Quiet literary serif, single column, hairline rules |
| `typewriter` | Warm paper, typewriter headings, dashed dividers |
| `bink` | Date-gutter rows, orange brand band |
| `benlk` | Condensed uppercase headlines, walnut header, blue links |
| `terminal` | Phosphor-green console, blinking cursor (dark-identity) |
| `gazette` | Broadsheet: double rules, datelines, drop caps |
| `noir` | Stark black, giant numbered headlines, one red accent (dark-identity) |
| `bloom` | Soft pastels, rounded glassy cards |
| `brutalist` | Hard borders, offset shadows, highlighter yellow |
| `zen` | Almost nothing: titles, dates, whitespace |
| `magazine` | Bold editorial hero story + kicker grid |
| `midnight` | Deep indigo, glass cards, violet-cyan glow (dark-identity) |
| `polaroid` | Snapshots taped to a board, handwritten captions |

**For a niche**

| Theme | Personality |
|---|---|
| `aperture` | Photography: full-bleed masonry wall, darkroom black |
| `gallery` | Art/portfolio: museum plates, small-caps labels |
| `darkroom` | Film: contact-sheet strips, sprocket holes (dark-identity) |
| `crumb` | Food: cream-and-cinnamon recipe cards |
| `pantry` | Homestead: mason-jar labels, gingham band |
| `bistro` | Café menu: dotted leaders, chalkboard dark mode |
| `wander` | Travel: tilted postcards, handwritten captions |
| `atlas` | Geography: topo contours, expedition log |
| `summit` | Hiking: elevation zigzag, route cards |
| `harbor` | Nautical: manifest rows, signal flags, brass |
| `tide` | Coastal: scalloped wave rules, washed-ashore cards |
| `stack` | Tech/dev: commit-log rows, first-class code blocks |
| `circuit` | PCB: copper traces, silkscreen labels (dark-identity) |
| `wire` | News: dense broadsheet ledger, urgent red |
| `dispatch` | Newsletter: airmail stripes, perforated stamps |
| `tabloid` | Front page: screaming caps, starburst EXCLUSIVE! |
| `stride` | Fitness: stat blocks, diagonal stripes |
| `chord` | Music: gig-poster setlist rows |
| `ledger` | Business: numbered register, navy restraint |
| `studio` | Design portfolio: watermark numerals, one carmine |
| `diary` | Journal: ruled paper, red margin line |
| `folio` | Literary: TOC leader dots, drop caps |
| `quill` | Poetry: centered verse, radical whitespace |
| `scribe` | Academic: numbered abstracts, oxblood |
| `hearth` | Family: rounded snapshot cards, warm pastels |
| `meadow` | Wildflower: petal dots, pressed-flower cards |
| `verdant` | Garden: seed-packet cards, leaf ornaments |
| `arcade` | Gaming: pixel borders, INSERT COIN footer |
| `workshop` | Maker: blueprint grid, drawing title blocks |
| `metro` | Transit: route-line spine, station dots |
| `archive` | Library: index cards, rubber-stamped dates |

**For a style**

| Theme | Personality |
|---|---|
| `mono` | One typeface, three sizes, grayscale + one blue |
| `grid` | Swiss: strict modular grid, lowercase, signal red |
| `byline` | Editorial: oversized serif headlines, pull quotes |
| `letterpress` | Print shop: debossed type, specimen rows |
| `bauhaus` | Primary circles/squares/triangles, numbered plates |
| `memphis` | 80s confetti, squiggles, tilted cards |
| `atomic` | 50s googie: starbursts, boomerang blobs |
| `deco` | Art deco: gold geometry, framed plates |
| `rewind` | 70s earth tones, sunburst stripes, tracklist |
| `vapor` | Vaporwave: sunset bands, cassette rows (dark-identity) |
| `neon` | Cyberpunk: HUD frames, scanlines, glow (dark-identity) |
| `frost` | Glassmorphic: blurred panels over gradients |
| `clay` | Neumorphic: soft extruded surfaces |
| `riso` | Risograph: two-ink overprint, off-registration |
| `origami` | Folded paper facets, clipped corners |
| `mosaic` | Byzantine tiles, grout gaps, gilt |
| `pop` | Pop-art: halftone dots, comic panels |
| `parchment` | Manuscript: vellum, illuminated capitals |
| `gothic` | Cathedral: pointed arches, candle gold (dark-identity) |
| `saloon` | Western: wanted posters, woodcut slab serif |
| `prairie` | Craftsman: stained-glass bands, long horizontals |
| `chalk` | Schoolhouse: chalkboard lessons (dark-identity) |
| `velvet` | Lounge: jewel tones, gilt-edged panels (dark-identity) |
| `ember` | Fireside: smoldering rules, rising glow (dark-identity) |
| `orbit` | Astronomy: star field, mission-log cards (dark-identity) |

`puddle`, `typewriter`, `bink`, and `benlk` are original reinterpretations of
community themes made for the original Dropplets (by jacksondc, judges119, and
benlk) — same spirit, all-new code.

See `templates/README.md` for the theme contract (required helpers, the
8-token palette, contrast rules) if you want to build your own.

## Building templates

A template is a folder under `templates/` with two required files:

- `home.php` lists posts
- `post.php` displays a single post

Static files (CSS, images, fonts) go in an `assets/` subfolder, served at
`/themes/<name>/<file>`. Call `Fieldnote\fn_render_head()` from your
`header.php` to get all SEO/social meta for free, and
`Fieldnote\fn_post_url($router, $post)` for canonical post links.
`Fieldnote\fn_excerpt($post)` gives a safe plain-text excerpt (it returns ''
for password-protected posts).

These variables are available:

- `$siteConfig` is the site configuration array
- `$allPosts` is the array of posts for the current page (each includes a resolved `imageUrl`)
- `$page` is the current page number
- `$limit` is the posts-per-page count
- `$post` is the single post array (on `post.php`, also with `imageUrl`)
- `$router` is the AltoRouter instance for generating URLs

Always escape user-controlled values in templates with `Fieldnote\e()`, and render post bodies through Parsedown in safe mode (see the shipped `liquid-new/post.php` for the pattern). A `404.php` in the template is optional and used for not-found pages.

## Security

See `UPGRADE.md` for the full list of fixes relative to 2.2. In short: stored XSS, settings-form code injection, missing CSRF protection, GET-based destructive actions, image-fetch SSRF, and plaintext per-post passwords have all been addressed.

## License

GPL-3.0-or-later, same as upstream.
