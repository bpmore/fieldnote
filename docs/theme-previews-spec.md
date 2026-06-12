# Spec: Theme previews in the admin area

Status: implemented (June 2026) — see /admin/themes. One deviation: gallery
iframes use `sandbox="allow-same-origin"` (still no scripts/forms/navigation)
because a fully sandboxed frame gets an opaque origin, drops the session
cookie, and the auth-gated preview would render the login page instead.

## Problem

Settings offers 70 themes as a bare `<select>` of names. Names like `riso`
or `clay` say nothing; the only way to evaluate a theme is to apply it to
the live site and look — disruptive on a public blog, and it takes 70
round-trips to see them all. Admins need to *see* each theme (in both color
schemes) before committing.

## Goals

- Browse live previews of every installed theme from the admin area
- See both light and dark schemes without changing OS settings
- Apply a theme from its preview in one action
- Zero JavaScript, consistent with the rest of the public surface
- No new build steps, no shipped screenshot assets, works for user-authored
  themes automatically

## Non-goals

- Theme editing/customization UI
- Per-visitor theme switching on the public site
- Thumbnail image generation (rejected below)

## Design

### Approach: live render in sandboxed iframes (chosen)

A new auth-gated route renders the real homepage through any installed
theme. The admin "Themes" page shows a card grid; each card embeds that
route in a scaled-down `<iframe>`. Previews are therefore always truthful —
they render the blog's actual posts through the theme's actual code — and
new theme folders show up automatically via `fn_template_names()`.

Rejected alternative: pre-generated PNG thumbnails per theme. Requires a
generation step (headless browser dependency), goes stale when posts or
theme CSS change, adds ~15-30 MB of repo weight, and silently misses
user-authored themes.

### New routes (src/routes.php)

```
GET /admin/themes                      -> theme gallery page (auth required)
GET /admin/themes/preview/[:theme]     -> homepage rendered with that theme
                                          (auth required; ?scheme=dark optional)
POST /admin/themes/apply               -> set config['template'] (auth + CSRF)
```

- `requireConfig()` + `requireAuth()` on all three, same as `/dashboard`.
- `[:theme]` validated with the existing `fn_template_dir()` (basename +
  exists check) — unknown names 404, no traversal surface.
- The preview route reuses the home route's body (extract a small
  `fn_render_home(string $templateDir)` helper from the `/` closure so the
  two stay in lockstep), with two differences:
  - `$postsPerPage` capped at 3 — previews only need a taste, and the page
    weight of 70 iframes × full grid matters
  - response carries `X-Robots-Tag: noindex` and
    `Content-Security-Policy: frame-ancestors 'self'` (preview pages are
    admin-only plumbing)

### Forcing the dark scheme without JavaScript

`prefers-color-scheme` inside an iframe follows the OS, so a dark preview
can't be triggered by markup alone. The theme contract makes a server-side
override possible: every theme.css declares all 8 tokens in `:root` and
overrides them in exactly one `@media (prefers-color-scheme: …)` block.

For `?scheme=dark` (or `?scheme=light` on dark-identity themes), the
preview route extracts the override block's `:root` body from the theme's
`theme.css` (same parser as `bin/audit-themes.php` — hoist `rootBlock()` /
`schemeBlock()` into a shared `Fieldnote\CssTokens` helper so the auditor
and the preview use one implementation) and emits it after the stylesheet
link as:

```html
<style>:root { /* override block body */ }</style>
```

Token-driven themes render their opposite scheme faithfully because every
color flows from the tokens. Decorative rules that live *outside* the
`:root` body but inside the media block (rare; e.g. an image filter) won't
flip — acceptable for a preview, and worth a one-line note in
templates/README.md encouraging themes to keep scheme differences inside
the token block.

Implementation detail: `fn_render_head()` gains an optional
`?string $schemeOverrideCss = null` parameter (default null = current
behavior, zero impact on existing themes).

### Gallery page (internal/themes.php)

New admin view, linked from the dashboard nav next to Settings:

- One card per `fn_template_names()` entry: theme name, current-theme
  badge, two scaled iframes (light + dark) side by side, and an Apply
  button (POST + CSRF, identical pattern to the publish/hide forms)
- Iframe scaling: fixed-size card (e.g. 320×240) containing an iframe
  rendered at 1280×960 and scaled with
  `transform: scale(0.25); transform-origin: 0 0;` — real desktop layout,
  miniaturized. `loading="lazy"` on every iframe so the page loads 2-4
  previews' worth of work, not 140 renders up front
- `sandbox` attribute (no `allow-scripts` needed — themes ship none);
  `title="Preview of <name>, light scheme"` on each iframe for AT
- Pagination or grouping is unnecessary: lazy iframes keep initial cost
  flat regardless of theme count
- The gallery is an admin view (internal/), so it may use the existing
  admin stylesheet; it is exempt from the public zero-JS rule but should
  honor it anyway — nothing here needs JS

### Apply flow

`POST /admin/themes/apply` with `theme` + CSRF token → validate via
`fn_template_dir()` → `$configStore` write of `template` key (reuse the
settings handler's persistence path) → redirect back to `/admin/themes`
with the new current-theme badge. No "preview as live" mode; applying is
explicit.

## Security notes

- Preview route is authenticated; it must never be reachable logged-out
  (it renders drafts? No — it reuses the public home query, published
  posts only, but the route stays admin-only anyway to avoid enumeration
  and resource abuse)
- Theme name input crosses two validators: route regex + `fn_template_dir()`
- CSS override injection embeds file content from `templates/<name>/assets/
  theme.css` only — admin-controlled files already served verbatim by the
  asset route; no user input is interpolated into the `<style>` block
- `frame-ancestors 'self'` prevents external embedding of preview pages

## Acceptance criteria

1. `/admin/themes` (logged in) lists all 70 themes with visibly distinct
   light and dark miniatures; logged out → redirect to login
2. `?scheme=dark` preview of `liquid-new` shows the dark palette on a
   light-OS machine; `?scheme=light` flips `terminal` to hardcopy mode
3. Applying a theme from the gallery changes the live site and badges the
   card; CSRF-less POST is rejected
4. `/admin/themes/preview/../../etc` and unknown names → 404
5. `php bin/audit-themes.php` still passes 70/70 (no theme files change)
6. Lighthouse a11y pass on the gallery page: iframe titles, button labels,
   focus order

## Implementation order

1. Hoist CSS token-block parser out of `bin/audit-themes.php` into
   `src/CssTokens.php`; auditor consumes it (behavior unchanged)
2. Extract `fn_render_home()` from the `/` route closure
3. Preview route + scheme override in `fn_render_head()`
4. Gallery view + dashboard nav link
5. Apply route
6. Verification: acceptance criteria above + live sweep script
