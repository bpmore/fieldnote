# Roadmap: hardening fixes and differentiating features

Status: proposed (June 2026). Items are ordered into phases; within a phase,
order is flexible unless a dependency says otherwise. Sizes: S (an evening),
M (a weekend), L (a week+). Each item carries acceptance criteria — done
means all of them pass, plus `php bin/audit-themes.php` still at 70/70.

Positioning context: the competitive set is Bear Blog / Mataroa (hosted,
minimal), WriteFreely (federated), Ghost (heavy), Kirby/Grav (flat-file CMS).
Fieldnote's defensible wedge is **machine-enforced accessibility** — the
auditor + 8-token theme contract is infrastructure competitors would have to
rebuild. Phase 1 doubles down on that; Phase 2 closes table-stakes gaps;
Phase 3 builds writer trust. Phase 0 pays debts found in review first.

---

## Phase 0 — fixes from code review

### 0.1 Clean up replaced images (S) — SHIPPED

**Problem:** `editPost` and `write` insert a new image record and repoint
`post['image']` but never delete the record/file being replaced
(src/routes.php). Only `deletePost` cleans up. Replacing an image leaks a
file per replacement, forever.

**Plan:** extract the cleanup block already in `deletePost` into
`fn_delete_image(Store $imageStore, int|string|null $id): void` (re-anchor
relative paths against FN_UPLOAD_DIR, unlink, delete record). Call it from
`deletePost` and from both save paths when a new image replaces an old one.
Add `bin/prune-images.php` (CLI, same shape as audit-themes): list records
not referenced by any post and files not referenced by any record;
`--delete` to remove them — this sweeps leaks accumulated before the fix.

**Accept:** replacing a featured image removes the old file and record;
prune script reports zero on a clean store; dry-run by default.

### 0.2 Stop counting all posts on every request (S) — SHIPPED

**Problem:** `$numPages` (src/routes.php:37) loads every published document
on every request — including theme-asset and feed hits — just to count them.

**Plan:** `fn_published_count(Store $blogStore): int` backed by a cache file
`data/cache/published-count` (int + mtime). Invalidate (delete) the file in
the four places that change published-ness: publish, hide, deletePost, and
first-publish inside edit/write saves. Regenerate lazily on next read.

**Accept:** steady-state request makes zero blog-store scans for counting;
publish/hide/delete immediately reflect in pagination; cache file absent →
regenerated.

### 0.3 Conditional GET on the feed (S) — SHIPPED

**Problem:** `/feed` re-renders 20 posts of markdown on every poll, and feed
readers poll constantly. No `ETag`/`Last-Modified`.

**Plan:** before rendering, compute `Last-Modified` = newest published
`date`, and an ETag from `sha1(count . maxDate . $siteConfig['name'])`.
Honor `If-None-Match`/`If-Modified-Since` with an empty 304. Send
`Cache-Control: max-age=300`. Same headers on the (future) JSON feed (2.4).

**Accept:** second request with `If-None-Match` → 304, no body; publishing a
post changes the ETag.

### 0.4 Trusted-proxy support for the login throttle (S)

**Problem:** `Security::throttleKey()` hashes `REMOTE_ADDR`. Behind
Cloudflare or any reverse proxy, every visitor shares one address: five
failed logins by anyone lock everyone out, and a rotating attacker isn't
slowed.

**Plan:** config key `trustedProxies` (array of CIDRs, default `[]`, exposed
in Settings as a single text input, comma-separated). When `REMOTE_ADDR`
matches a trusted CIDR, key the throttle on the last untrusted hop of
`X-Forwarded-For`. Never trust the header otherwise (spoofable). Document
the lockout-DoS tradeoff in README's security section.

**Accept:** with proxy IP trusted, two clients behind it throttle
independently; with default config, behavior is unchanged.

### 0.5 Strict CSP on public pages when headerInject is empty (M)

**Problem:** public pages send no CSP because `headerInject` may carry
inline analytics. Most installs leave it empty and get nothing.

**Plan:** two steps. (1) Move `fn_a11y_base_css()` from an inline `<style>`
to a static file `public/static/a11y.css` linked before the theme stylesheet
— inline styles are the only thing blocking a strict policy. The theme
preview's scheme-override `<style>` stays inline but previews are admin
routes with their own headers; give them `style-src 'self' 'unsafe-inline'`.
(2) In `Security`, add `sendPublicCsp()`:
`default-src 'self'; script-src 'none'; style-src 'self'; img-src * data:;`
(remote images in markdown are legitimate) — sent from `fn_render_head`
callers only when `headerInject === ''`. Any injected snippet disables it,
preserving today's behavior.

**Accept:** empty headerInject → public pages carry the CSP and render
identically on a sample of themes (spot-check 5 incl. one dark-identity);
non-empty headerInject → no CSP; previews still force schemes.

### 0.6 i18n catch-up (S)

**Problem:** fr/uk packs predate 2FA, the theme gallery, and newer labels;
non-English admins get a mixed UI.

**Plan:** sweep `internal/` for hardcoded English, add keys to `src/i18n.php`
(en first; fr/uk marked machine-translated in a comment, corrections
welcome). New rule for future work: admin-facing strings go through `i18n()`.

**Accept:** `grep` finds no user-visible hardcoded strings in internal/
views; en/fr/uk arrays have identical key sets.

### 0.7 Smoke test + CI (M) — SHIPPED

**Problem:** the only automated check is the theme auditor. Refactors are
verified by hand (forged-session curl loops — which caught a real regression
in the write form this week).

**Plan:** `bin/smoke-test.php`: creates a temp data dir with two fixture
posts (one with image, one password-protected, one draft), boots
`php -S 127.0.0.1:<random> -t public` against it via `FN_DATA_DIR` override
(make the define in bootstrap respect an env var), forges an authenticated
session file, then asserts a route matrix: public pages 200, admin pages 200
authed / 302 logged-out, CSRF-less POST 419, traversal 404, feed 304 logic,
theme preview override present. Exit non-zero on any failure. GitHub Action:
`composer install && php bin/audit-themes.php && php bin/smoke-test.php`.

**Accept:** suite passes locally and in CI; breaking the verify2fa
`$siteConfig` capture (the actual bug from this week) makes it fail.

---

## Phase 1 — the accessibility wedge

### 1.1 Palette customizer that refuses to be inaccessible (L) — the headline — SHIPPED

No platform anywhere can say "you cannot ship an inaccessible color scheme,
even on purpose." All the hard parts already exist: `CssTokens` parses theme
token blocks, the auditor owns the WCAG math, and the gallery already
injects token overrides server-side.

**Plan:**
- Hoist `parseColor` / `relativeLuminance` / `contrast` and `PAIR_MATRIX`
  from bin/audit-themes.php into `src/Wcag.php` (same move CssTokens made);
  auditor consumes it, behavior unchanged.
- Config gains `paletteOverrides`: `['light' => ['--accent' => '#...'], 'dark' => [...]]`,
  empty by default.
- New admin page `/admin/palette` (linked from the theme gallery): one color
  input per overridable token per scheme, pre-filled from the active theme's
  parsed tokens. Server-side on POST: merge overrides over theme tokens, run
  the pair matrix. For each failing pair, walk the failing color's lightness
  (HSL, keep hue/saturation) toward compliance and offer the nearest passing
  shade; the form re-renders showing `your pick → suggested` with ratios.
  Saving is only possible when every pair passes. Zero JS — round trips.
- Rendering: `fn_render_head` already supports an override `<style>`; build
  it from `paletteOverrides` for normal page loads (merge under any preview
  override). Gallery previews of the *active* theme show overrides applied.
- "Reset to theme defaults" clears the config key.

**Accept:** an override that drops `--accent` on `--bg` below 4.5:1 cannot
be saved and shows a passing suggestion; a passing override renders on the
public site in both schemes; clearing restores stock; audit still 70/70
(theme files untouched).

### 1.2 Accessibility lint for post content (M)

Themes are enforced; the writing isn't. Lint the rendered post on save.

**Plan:** `src/ContentLint.php` runs over Parsedown-safe-mode output:
- heading jumps (post body opening with `<h1>` — themes own the h1 — or
  skipping levels h2→h4);
- link text on a deny-list (`click here`, `here`, `read more`, `link`) or a
  bare URL as its own text;
- images in markdown with empty alt AND no adjacent caption text;
- all-caps runs > 10 words (screen-reader letter-by-letter trap).
Findings stored in session on save, rendered as a dismissible warning list
on the write screen ("published anyway — these are suggestions"). Never
blocks publishing.

**Accept:** a post with `[click here](url)` and an h2→h4 jump shows exactly
two warnings after save; a clean post shows none; warnings survive the
redirect (flash pattern, same as `login_error`).

### 1.3 Public proof: /accessibility page + badge (S)

Turn the gate into marketing. A public route `/accessibility` (rendered
through the active theme like a post) stating what is machine-checked —
contrast matrix, skip links, focus, reduced motion, 320px reflow — generated
from the same constants in `src/Wcag.php` so it can't drift from the code.
Plus a small self-hosted SVG badge themes can show in their footer
(config toggle, default off). README links the live page.

**Accept:** page renders in any theme; changing PAIR_MATRIX changes the
page; badge off by default.

---

## Phase 2 — table stakes the minimal competitors lack

### 2.1 Tags (M) — SHIPPED

The biggest content-model gap vs every competitor.

**Plan:** `post['tags']` = array of slugs (`fn_slugify` each, max 8, deduped).
Write form: one text input, comma-separated, pre-filled on edit. Routes:
`/tag/[:tag]` listing published posts with that tag (reuse
`fn_render_home`'s shape with a filtered query; paginate only if needed —
start without). Shared helper `fn_tag_links(\AltoRouter, array $post): void`
emitting a `<nav aria-label="Tags">` list — themes adopt it like
`fn_pagination`, no contract change forced (auditor doesn't require it).
Feed items gain `<category>` elements. The home query is unchanged.

**Accept:** tagging a post makes it appear at `/tag/x`; unknown tag → 404;
drafts never leak into tag pages; feed shows categories; untagged posts and
unmodified themes behave exactly as today.

### 2.2 Zero-JS search (M)

**Plan:** `GET /search?q=` (public, config-gated `searchEnabled`, default
on). Scan published posts with `mb_stripos` over title + content (no regex
from user input); rank title hits above content hits, then by date; cap at
50 results, no pagination. Render through a new shared internal-style view
that uses the active theme's header/footer? No — keep it theme-native:
reuse `home.php` with `$allPosts` = results and `$pageTitle = 'Search'`,
matching how tag pages work. Helper `fn_search_form(\AltoRouter): void`
(a `<form role="search">` with a labeled input) for themes that want it in
the header. Excerpts already exist (`fn_excerpt`). Password-protected post
content is excluded from matching (titles still match — they're public).

**Accept:** match in body finds the post; query shorter than 2 chars →
empty state, no scan; protected post bodies never match; `q` is escaped
everywhere it echoes.

### 2.3 Scheduled publishing without cron (M)

"Schedule posts on $3 shared hosting" — Ghost needs a daemon for this.

**Plan:** write/edit forms gain optional `datetime-local` input →
`post['publishAt']` (timestamp, site timezone). A scheduled post stays
`draft = true`. In bootstrap, after the stores open: if
`data/.schedule-check` is older than 60 seconds (touch it), query drafts
with `publishAt <= now` and flip each through the same logic as the publish
route (stamp `date`/`publishedAt` on first publish — reuse via a
`fn_publish_post()` helper extracted from the route). Lazy: the first
visitor after the scheduled time triggers it; the 60s marker keeps it from
scanning every request (and plays nicely with 0.2's count invalidation).
Dashboard shows "scheduled for …" badge on such drafts.

**Accept:** post scheduled 1 minute out appears on the public site on the
first request after that minute, with correct permalink date; editing a
scheduled post doesn't publish it early; no cron anywhere.

### 2.4 sitemap.xml + JSON Feed (S) — SHIPPED

**Plan:** `/sitemap.xml`: home + every published, non-password post
(`lastmod` from `publishedAt`), same query shape as the feed; referenced
from a generated `/robots.txt` (currently none — serve one). `/feed.json`:
JSON Feed 1.1 mirroring the RSS items; discovery `<link>` added in
`fn_render_head` next to the RSS one. Both get 0.3's conditional-GET
treatment. Password-protected posts are excluded from both (the RSS feed
already set this precedent).

**Accept:** validators pass both; protected posts absent; 304s work.

---

## Phase 3 — writer trust and retention

### 3.1 Markdown export / import (L)

"Flat files you can grep" deserves a real escape hatch — migration *in*
drives adoption, credible migration *out* drives it harder.

**Plan:** export: dashboard button → streamed zip (`ZipArchive`; add
`ext-zip` to composer suggest with a graceful "extension missing" message):
`posts/YYYY-MM-DD-slug.md` with YAML frontmatter (title, date, author,
tags, draft, image path), `uploads/` copied verbatim, `site.yaml` (config
minus password hash). Import: authed upload of such a zip OR a zip of bare
`.md` files with Jekyll/Hugo/Bear-style frontmatter; map common keys,
re-slug on collision, re-ingest images referenced by frontmatter through
`ImageHandler` (relative paths from the zip, not remote fetches). Dry-run
screen first: "will create N posts, M images, K collisions" then confirm.

**Accept:** export → wipe data/ → import on a fresh install reproduces the
blog (posts, images, tags, drafts); a minimal Jekyll post imports; importing
the same zip twice doesn't duplicate (slug match = skip, reported).

### 3.2 Privacy stats with a stronger guarantee than Bear's (M)

**Plan:** on the single-post route, append to `data/stats/YYYY-MM-DD.json`:
`{slug: count}`. Dedup within a day via a salted hash of (IP + UA) where the
salt is random per day and **deleted with the day's dedup set** — the
guarantee is "no IP address is ever written to disk, and views can't be
correlated across days." Skip obvious bots by UA list. Aggregate view:
dashboard panel with a 30-day per-post bar chart in pure CSS (heights from
inline... no — admin CSP forbids inline styles; use a `<table>` styled as
bars via a width class bucket, or `<meter>` elements). Config toggle
`statsEnabled`, default ON with a line in README (it's cookie-less,
JS-less, and stores no identifiers — nothing to disclose under GDPR's
usual analytics triggers, but say it plainly). Retention: 90 daily files,
prune on write.

**Accept:** two requests same day from one client = 1 view; next day = new
view; no file under data/ ever contains an IP or UA string; disabling stops
writes; dashboard renders with zero JS.

### 3.3 Draft share links (S)

**Plan:** generate `data/secret` (32 random bytes) on first need. Dashboard
draft rows gain "Share": creates
`/draft/[i:id]/[:token]` where token =
`hash_hmac('sha256', "$id|$exp", $secret)` truncated to 32 hex chars, with
`exp` (14 days) embedded in the URL and covered by the MAC. Route verifies
constant-time, checks expiry, renders `post.php` through the active theme
with `X-Robots-Tag: noindex`. Revocation = rotate `data/secret` (button in
settings, invalidates all share links).

**Accept:** link renders the draft logged-out; tampered id/exp/token → 404;
expired → 404; published posts redirect to the canonical URL.

### 3.4 Post revisions (S)

**Plan:** on edit save, if title/content/author changed, push the previous
values + timestamp onto `post['revisions']`, capped at 10 (shift oldest).
Edit screen lists revisions (timestamp + title) with a POST "restore"
(restoring also pushes the current state, so nothing is lost). No diff UI —
flat-file users can diff the store; the feature is "undo a bad save."

**Accept:** save → revision appears; 11th save drops the oldest; restore
round-trips; revisions never render publicly or in the feed.

---

## Phase 4 — moonshot (needs its own spec before any code)

### 4.1 ActivityPub federation (XL)

Every Fieldnote blog followable from Mastodon — WriteFreely's headline
feature on top of a far better theming/accessibility story. Scope sketch:
WebFinger + actor document, HTTP signatures (key pair in data/), followers
collection, `Create(Note/Article)` delivery on publish with a flat-file
retry queue, inbox handling for Follow/Undo. Hard parts: signature
interop quirks, delivery fan-out on shared hosting, deletes/updates.
Decision point AFTER Phase 2: if tags/search/scheduling shipped well, write
`docs/activitypub-spec.md` at the depth of the theme-preview spec and
re-estimate. Do not start it as a side effect of anything else.

---

## Suggested order

| # | Item | Size | Depends on |
|---|---|---|---|
| 1 | 0.1 image cleanup, 0.2 count cache, 0.3 feed 304 | S×3 | — |
| 2 | 0.7 smoke test + CI | M | — (protects everything after) |
| 3 | 1.1 palette customizer | L | Wcag.php hoist |
| 4 | 2.1 tags, 2.4 sitemap/JSON feed | M+S | 0.3 (shared 304 helper) |
| 5 | 2.2 search, 2.3 scheduling | M+M | 0.2 (count invalidation) |
| 6 | 1.2 content lint, 1.3 proof page | M+S | 1.1's Wcag.php for 1.3 |
| 7 | 0.4 proxy, 0.5 CSP, 0.6 i18n | S+M+S | — (any gap) |
| 8 | 3.3 share links, 3.4 revisions | S+S | — |
| 9 | 3.2 stats | M | — |
| 10 | 3.1 import/export | L | 2.1 (tags in frontmatter) |
| — | 4.1 ActivityPub | XL | own spec, after Phase 2 ships |

Rationale for the order: pay the three cheapest debts immediately, then get
CI in place so everything else lands protected, then build the headline
feature (1.1) while energy is high. Tags/search/scheduling are what a
reviewer comparing platforms will check for. Import/export goes late only
because its frontmatter format should include tags, which must exist first.
