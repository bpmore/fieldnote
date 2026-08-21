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

`color-scheme` in `:root` tells the browser how to render what the theme
does not — form controls, scrollbars, the canvas behind the page. Three
shapes are legal, and the auditor enforces the match:

| Shape | `:root` | Override block | `color-scheme` |
|---|---|---|---|
| Light-default | light values | `prefers-color-scheme: dark` | `light dark` |
| Dark-identity | dark values | `prefers-color-scheme: light` | `dark light` |
| Dark-only | dark values | `prefers-color-scheme: light`, still dark | `dark` |

The third is the one worth explaining. Six themes (blueprint, marquee,
microfiche, observatory, tape, wayfinding) shift to a slightly lifted dark
for readers who prefer light, rather than to an actual light scheme — their
"light" backgrounds sit at 0.5–3% luminance. Declaring `dark light` there
would tell the browser to draw light form controls on a dark page, so they
declare `dark` and mean it.

Put the declaration in `:root`, not `html`. `:root` is a pseudo-class and
outranks a type selector whatever the source order, so a theme carrying both
silently runs whichever value sits in `:root` — which is how nine themes came
to advertise `light` while shipping a dark override.

Keep every scheme difference inside the `:root` token blocks: the admin
theme gallery forces a scheme by replaying the matching token block, so
rules placed elsewhere in the media query won't flip in previews. Only the
first `prefers-color-scheme` block is read, so a second one defining tokens
is invisible to both the preview and the contrast gate.

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

`Fieldnote\fn_a11y_badge($router, $siteConfig)` renders a small WCAG 2.2 AA
badge linking to `/accessibility`, or nothing when the owner hasn't enabled
it (config `accessibilityBadge`, default off). Every theme footer calls it
already; the mark is `currentColor` inline SVG and `.a11y-badge` ships in the
shared a11y CSS, so it inherits your footer's (gate-passing) colors. If your
footer needs the badge somewhere specific, move the call — don't restyle it
into failing contrast.

`Fieldnote\fn_footer_copyright($siteConfig)` renders a `© <year> <name>`
line, or nothing unless the owner enabled it (config `copyright` =
blog|author). `Fieldnote\fn_social_links($siteConfig)` renders the curated
footer social links (config `social`), or nothing when none are set — each an
accessible labelled link with a `currentColor` inline-SVG icon and `rel="me"`.
Both are called after the footer content in every theme; `.footer-copyright`
and `.social-links` ship in the shared a11y CSS and inherit theme colors. Move
the calls to reposition; keep them gated and don't restyle into failing
contrast.

`Fieldnote\fn_post_admin($router, $post)` renders inline owner controls
(edit, publish/hide, delete) on a post, or nothing for logged-out visitors —
so `post.php` can call it unconditionally. Every theme calls it after
`</article>`; `.post-admin` ships in the shared a11y CSS and uses theme
tokens. Delete links to a server-rendered confirm page (the public surface is
no-JS), so it's safe without the dashboard's confirm script. Move the call to
reposition it; keep it gated and don't restyle it into failing contrast.

`Fieldnote\fn_utility_bar($router, $siteConfig)` renders the utility bar — the
profile-page nav link and the visitor search box — as one strip, or nothing
when both are disabled. Every theme calls it once **before `<header>`** (right
after `fn_skip_link()`), so the bar sits above the masthead and doesn't disturb
each theme's header design. It's themed from `--surface` / `--text` / `--line`
(all gate-checked), so it adapts to the palette; `.fn-utility` ships in the
shared a11y CSS. It composes two self-guarding helpers you can also place
yourself if a theme wants them elsewhere: `Fieldnote\fn_profile_link($router,
$siteConfig)` (the profile link, config `profilePage`, `.profile-link`) and
`Fieldnote\fn_search_form($router, $siteConfig, $value)` (the search box,
`role="search"`, config `searchEnabled`, `.search-form`).
