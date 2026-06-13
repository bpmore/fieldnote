# Spec: personal profile page (/about · /now · /profile · /brag)

Status: proposed (June 2026). A single owner-authored "this is me and what I
do" page, rendered through the active theme, with the URL/label the owner's
choice. Inspired by the "/brag directory" idea from Mostly Technical ep. 134 —
but built as a first-class personal page first; directory participation is a
deferred, optional second phase, not a dependency.

## Problem

A Fieldnote site is a stream of posts with no fixed "who is this / what have
they done" page. That page is the backbone of a personal site and of the
IndieWeb identity Fieldnote is already leaning into (rel="me" social links, an
ActivityPub actor). The Mostly Technical episode floats a community directory
of such pages (PR a JSON entry to a GitHub repo). The directory is external,
brand-new, and has no published schema yet — so the durable value is the
**page**, which is useful on its own and directory-ready later.

## Goals

- One owner-authored profile page, off by default, rendered through the active
  theme exactly like the existing `/accessibility` page
- The owner chooses the URL/label: **about · now · profile · brag** (the same
  choice drives the slug and the nav link text)
- Authored in markdown, through the same pipeline as posts, so it passes the
  ContentLint accessibility gate by construction
- Discoverable via a nav link, using the established header/footer helper
  pattern; off when disabled
- Strengthens IndieWeb identity (a stable personal URL to point rel="me" at)

## Non-goals (v1)

- Building or hosting the directory itself — out of scope and off-ethos for a
  single-admin, no-backend engine. A Fieldnote user simply links/PRs their own
  page or its JSON.
- Multiple profile pages (one site, one profile)
- Structured-field editing (name/role/links as separate inputs) — v1 is a
  single markdown body; structured export is the phase-2 question (below)

## Design decisions

### Naming and slug

Config `profilePage` enum: `off` (default) | `about` | `now` | `profile` |
`brag`. The value is **both** the slug (`/about`, `/now`, …) and the link
label (capitalised). All four candidates are free of route collisions
(reserved top-level paths are accessibility, search, feed, dashboard, login,
settings, tag, post, ap, robots.txt, sitemap.xml, …) and don't clash with post
URLs (posts live under `/post/` or dated paths). A note on the brand: "brag"
is louder than Fieldnote's quiet voice — offered, but `about`/`now` are the
on-tone defaults. ("/now" also nods to the established nownownow.com
convention.)

Decided: fixed enum of four (no custom slug in v1) — avoids the collision/
reserved-word validation surface. Custom can come later if asked.

### Storage and editing

The body is markdown. Two options:
- **(A) Config field** `profileContent` (markdown), edited in a Settings
  textarea. Simplest; no new editor surface. No revisions.
- **(B) A reserved post** flagged as a page (excluded from feed/home/search),
  edited with the existing editor — gets revisions, the lint gate inline, and
  the future Toast UI editor for free.

Decided: **(B)**. It reuses the editor, revisions, and the publish/edit
accessibility gate already built, for little extra code (a "page" flag + feed/
home/search exclusion), and it's the natural home once the WYSIWYG editor
lands. (A) is faster but a dead-end.

### Route and rendering

Mirror the `/accessibility` route: build a synthetic `$post` (title = the
label, content = the stored markdown) and `require post.php` so it renders in
the active theme. Register the route from the configured slug at boot
(`$router->map('GET', '/' . $slug, …)`), 404 when `profilePage = off`.

### Discoverability

A nav link to the page, via the same self-guarding helper-injection pattern as
search/social (`fn_profile_link`), gated on `profilePage`. Decided: **header**
(the masthead, beside the search box) — most discoverable, consistent with the
search-box decision.

### Phase 2 (deferred): directory / machine-readable export

Once (and only if) the directory has a stable schema and traction, expose
`/<slug>.json` and/or embed an h-card microformat / schema.org `Person`, so a
user can submit to the directory with no hand-editing. Kept decoupled and
versioned — the external schema will move. Not built in v1.

## Security & privacy

- A profile page aggregates personal info publicly; it's opt-in. Document that
  participating in any external directory means publishing to a third party
  (consistent with how external publishing is already flagged).
- Content is markdown through Parsedown safe-mode — same XSS posture as posts.
- No new network calls in v1.

## The honest dragons

1. **Brand tone** — "brag" vs Fieldnote's understatement. Solved by making the
   label the owner's choice and defaulting to off.
2. **Directory is vapor-ware-until-proven** — the reason phase 2 is deferred.
   Don't build to a schema that doesn't exist.
3. **Page-vs-post modelling** — option (B) introduces a "page" concept; keep it
   minimal (one flag, three exclusions) to avoid scope creep.

## Acceptance criteria

1. Settings offers Profile page = Off | About | Now | Profile | Brag; default
   Off; existing sites unchanged.
2. When set, `/<slug>` renders the owner's markdown through the active theme;
   changing the choice changes the URL and the nav label.
3. The page is excluded from the homepage, feed, JSON feed, sitemap, and
   search results.
4. Editing the page runs the ContentLint accessibility gate (same as posts).
5. A nav link appears when enabled and is gone when Off; the route 404s when
   Off.
6. Smoke: slug renders when enabled; 404 when off; excluded from feed/home/
   search; nav link presence tracks the setting.

## Estimate and recommendation

| Phase | Scope | Size |
|---|---|---|
| 1 — page + slug/label setting + nav link + exclusions + smoke | S–M |
| 2 — JSON/microformat export for directory submission | S (deferred) |

Recommendation: build phase 1 with storage option (B); ship it as a calm
personal page (default Off, on-tone labels), and leave directory coupling for
phase 2 once the directory is real.
