# Theme contract

Every theme is a folder here with `header.php`, `footer.php`, `home.php`,
`post.php`, `404.php`, and `assets/theme.css`. Themes are auto-discovered;
nothing to register. All themes are zero-JS, mobile-first, and must meet
WCAG 2.2 AA in **both** color schemes. `php bin/audit-themes.php` is the
gate — it must be green before a theme ships.

## Required helper calls

| Where | Call | Why |
|---|---|---|
| `header.php`, right after `<body>` | `Dropplets\dpl_skip_link()` | skip-to-content (styled by the shared baseline CSS) |
| `header.php`, main element | `<main id="main" …>` | skip-link target |
| `header.php`, in `<head>` | `Dropplets\dpl_render_head(...)` | meta, canonical, OG, and the shared a11y baseline `<style>` |
| `home.php`, after the post list | `Dropplets\dpl_pagination($router, $page, $numPages)` | aria-current, rel prev/next, 24px targets |
| `post.php`, hero image | `alt="<?= e(Dropplets\dpl_image_alt($post)) ?>"` | the hero is content, not decoration |

Other rules:

- **One `<h1>` per page.** Post pages: the post title. Home/404: either a
  visible h1 or `<h1 class="sr-only"><?= e($siteName) ?></h1>`. The site
  title in the header is NOT an h1.
- **Card/list images stay `alt=""`** — the adjacent title link names the
  destination; duplicating it double-announces in screen readers.
- Post bodies render through `ParsedownExtra` with `setSafeMode(true)`,
  always. Escape everything else with `Dropplets\e()`.
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
