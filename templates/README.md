# Theme contract

Every theme is a folder here with `header.php`, `footer.php`, `home.php`,
`post.php`, `404.php`, and `assets/theme.css`. Themes are auto-discovered;
nothing to register. All themes are zero-JS, mobile-first, and must meet
WCAG 2.2 AA in **both** color schemes. `php bin/audit-themes.php` is the
gate — it must be green before a theme ships.

## Required helper calls

| Where | Call | Why |
|---|---|---|
| `header.php`, right after `<body>` | `Fieldnote\fn_skip_link()` | skip-to-content (styled by the shared baseline CSS) |
| `header.php`, main element | `<main id="main" …>` | skip-link target |
| `header.php`, in `<head>` | `Fieldnote\fn_render_head(...)` | meta, canonical, OG, and the shared a11y baseline `<style>` |
| `home.php`, after the post list | `Fieldnote\fn_pagination($router, $page, $numPages)` | aria-current, rel prev/next, 24px targets. The baseline CSS gives the `.pagination` list a centered flex layout with bullets removed — style on top of that, don't re-add resets |
| `post.php`, hero image | `alt="<?= e(Fieldnote\fn_image_alt($post)) ?>"` | the hero is content, not decoration |

Other rules:

- **One `<h1>` per page.** Post pages: the post title. Home/404: either a
  visible h1 or `<h1 class="sr-only"><?= e($siteName) ?></h1>`. The site
  title in the header is NOT an h1.
- **Card/list images stay `alt=""`** — the adjacent title link names the
  destination; duplicating it double-announces in screen readers.
- Post bodies render through `ParsedownExtra` with `setSafeMode(true)`,
  always. Escape everything else with `Fieldnote\e()`.
- No JavaScript. No fixed widths — layouts must reflow at 320 px
  (= 400 % zoom) with no horizontal scroll.
- Don't disable focus outlines. The baseline provides a `:focus-visible`
  ring using `var(--focus)`; you may restyle it, never remove it.
- Animations need no special handling — the shared baseline disables them
  under `prefers-reduced-motion`.

## Color tokens

All colors live in CSS custom properties, declared in exactly two places:
`:root` and one `@media (prefers-color-scheme: …)` override block.
**No color literals anywhere else in the file** — the auditor enforces this.
Decorative extras (`--shadow`, `--glow`, gradient stops) are fine but must
be defined inside the token blocks too.

Light-default themes: light values in `:root`, dark overrides in
`@media (prefers-color-scheme: dark)`, and `color-scheme: light dark`.
Dark-identity themes (terminal, noir, midnight, neon) invert: dark in
`:root`, light overrides in `@media (prefers-color-scheme: light)`, and
`color-scheme: dark light`.

Keep every scheme difference inside the `:root` token blocks: the admin
theme gallery forces a scheme by replaying the matching token block, so
rules placed elsewhere in the media query won't flip in previews.

Required tokens and the contrast matrix the auditor checks in both schemes:

| Token | Role | Must hit |
|---|---|---|
| `--bg` | page background | — |
| `--surface` | cards/panels (may equal `--bg`) | — |
| `--text` | body text | ≥ 4.5:1 vs `--bg` and `--surface` |
| `--muted` | secondary/meta text | ≥ 4.5:1 vs `--bg` and `--surface` |
| `--accent` | links/titles used as text | ≥ 4.5:1 vs `--bg` and `--surface` |
| `--accent-contrast` | text sitting on `--accent` fills | ≥ 4.5:1 vs `--accent` |
| `--line` | borders, rules, non-text UI | ≥ 3:1 vs `--bg` |
| `--focus` | focus ring color | ≥ 3:1 vs `--bg` |

Aliases are fine (`--bright: var(--accent)`) when retrofitting old token
names — the auditor resolves one level of `var()` indirection.

## Target sizes

Pagination gets a 24 px floor from the baseline. Any other standalone link
(footer RSS, back-links, 404 button) needs enough padding to reach 24 × 24 px,
unless it genuinely sits inline in a sentence (WCAG 2.5.8 inline exception).

## Optional helpers

`Fieldnote\fn_tag_links($router, $post)` renders the post's tags as an
aria-labelled nav of links to `/tag/<name>` pages (nothing when untagged).
Opt in from `post.php` the way `gazette` and `liquid-new` do; base layout
for `.tag-list` ships in the shared a11y CSS, visual styling is yours.
