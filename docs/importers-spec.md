# Spec: platform importers

Status: proposed (June 2026). Fieldnote already imports a zip of
frontmatter-markdown (roadmap 3.1, the `Porter` class — Jekyll / Hugo / Bear /
11ty / Obsidian / its own export). This adds importers for the platforms that
*don't* hand you frontmatter-markdown, so moving in from the big incumbents is
a few clicks instead of a scripting project.

## Problem

"Flat files you can grep" is only a real promise if getting your existing
writing *in* is easy. Today that works for anyone already on a static-site
generator, but the largest pools of writers are on WordPress, Substack, Ghost,
Medium, and Blogger — none of which export frontmatter-markdown. Each needs a
format-specific converter. Credible migration *in* drives adoption; doing it
without losing posts, dates, tags, or images is the bar.

## Goals

- Import from the popular platforms below, reusing the entire existing
  `Porter` pipeline (dry-run preview, slug-collision skip / no-overwrite,
  image ingestion) — converters only normalize the source into what `Porter`
  already consumes
- Imported posts land as **drafts**, so the publish-time ContentLint
  accessibility gate applies before anything goes public, and the import shows
  a **per-post accessibility report** (heading skips, missing alt, vague links)
- Remote images are **downloaded and localized** into `uploads/`, SSRF-guarded
- The owner **picks the source platform** (with best-effort auto-detection)
- Nothing is written until the dry-run is confirmed (the existing guarantee)

## Non-goals (v1)

- Preserving old permalink structure or emitting redirects (documented caveat)
- Comments, reactions, members/subscribers, paid-tier gating
- Live API sync — these are one-time file-based imports
- Perfect fidelity of every embed/shortcode; unconvertible bits degrade to a
  clear placeholder, never silent loss

## Design decisions

### Architecture: converters normalize into the existing pipeline

The one decision everything hangs on: **a converter turns a platform export
into the same intermediate `Porter` already imports** — a set of entries, each
`{title, slug, date, tags, body-markdown, draft, images[]}`. Downstream is
unchanged: `Porter::analyze()` for the dry-run, `Porter::import()` for the
write, slug-collision skip, and image ingestion all stay as-is. New code is
confined to the converter layer.

```
upload  ->  detect/pick format  ->  Converter::toEntries()  ->  Porter (analyze -> dry-run -> import)
```

Each converter implements one method: take the uploaded file(s), return
normalized entries (or errors). `Porter` grows one entry point that accepts
in-memory entries in addition to its current "read .md files from a zip" path.

### Import as drafts + accessibility report

Import inserts posts directly, which bypasses the publish-time gate. So every
imported post is created as a **draft** (`draft = true`), and the gate runs
when the owner publishes it — imported content can't go public inaccessible.
The dry-run screen additionally shows a **per-post accessibility summary**
(`ContentLint::check()` on the converted markdown) so the owner sees what needs
fixing before publishing. Cost: a large blog imports as a pile of drafts to
review; that's the accessibility-first trade, and bulk-publish can come later.

### HTML → Markdown

WordPress, Ghost, Medium, Substack, and Blogger bodies are HTML, not markdown.
Convert with **`league/html-to-markdown`** (composer, well-maintained) rather
than hand-rolling. Configure it to keep tables and code blocks; wrap unknown
embeds (shortcodes, iframes) in a fenced block with a `<!-- imported: … -->`
marker so nothing vanishes silently. Output runs through the same Parsedown
safe-mode renderer as every other post.

### Image localization (SSRF-guarded)

Bodies and featured images reference remote URLs. Each is fetched through the
existing `SafeHttp` pattern (resolve host, reject private/reserved ranges, pin
curl to the validated IP, no redirects — the same guard the ActivityPub inbox
uses), size-capped, stored in `uploads/`, and the link rewritten to the local
copy. Failures leave the original URL and note it in the report. Bundled
images (Substack/Medium/WordPress export archives often include them) are
ingested directly, no fetch.

### Format selection

A dropdown on the import screen (WordPress, Ghost, Substack, Medium, Blogger,
WriteFreely, Notion, RSS feed, …) with best-effort **auto-detection** from the
upload (`.xml` with the WXR namespace → WordPress; `.json` with Ghost's `db`
shape → Ghost; `posts.csv` + HTML → Substack; an Atom feed → Blogger/RSS).
Auto-detect pre-selects the dropdown; the owner can override.

### Metadata mapping

Each converter maps source fields to the post shape (`title`, `slug`, `date`,
`tags`, `content`, `draft`, featured image). Where the source distinguishes
draft vs published, that's recorded in the report — but everything still
*imports* as a draft per the decision above. Slugs are preserved when present
(so a future redirect map is possible), else derived from the title.

### The platform set

| Platform | Export format | Body | Notes |
|---|---|---|---|
| **WordPress** | WXR (RSS-namespaced XML) | HTML | The big one; also covers **Squarespace** (exports WXR). Categories+tags, attachments, draft/publish status. |
| **Substack** | zip: `posts.csv` + per-post HTML | HTML | The timely one. CSV carries title/date/subtitle; HTML bundled. |
| **Ghost** | JSON export | HTML / lexical | Cleanest structured export; map `posts[]`, `tags[]`, `published_at`. |
| **Medium** | HTML archive (zip of per-post HTML) | HTML | "Download your information"; figure/caption handling matters. |
| **Blogger** | Atom XML export | HTML | Large legacy base. |
| **WriteFreely / Write.as** | Markdown (and/or JSON) | Markdown | Direct competitor; near-native, AP-friendly. Low effort, high positioning. |
| **Notion** | Markdown + CSV export (zip) | Markdown | Close to the existing importer with Notion quirks (asset folders, page-DB CSV). |
| **Dev.to / Hashnode** | Markdown export / API | Markdown | Dev audience; near-native. |
| **Generic RSS / Atom** | feed URL or file | HTML (often truncated) | Universal fallback so no platform is a dead end. Lossy; flagged as such. |

## Security notes

- All image fetches go through `SafeHttp` (no SSRF, no redirects, size caps);
  no body-embedded URL is fetched without it.
- Uploaded archives are size-capped and parsed defensively; zip entries are
  path-sanitized (no `../` traversal on extraction) — the existing importer's
  rule, kept.
- HTML is converted then re-rendered through Parsedown safe-mode, so imported
  markup can't inject script — same XSS posture as authored posts.
- Import stays behind auth + the central CSRF gate; the dry-run token lives in
  the session exactly as today.

## The honest dragons

1. **HTML→markdown fidelity** — tables, captions, galleries, shortcodes,
   embeds. The league converter handles the common cases; the rest degrade to
   a visible marker, never silent loss. This is the bulk of per-platform work.
2. **WXR sprawl** — WordPress exports carry attachments, nav menus, custom
   post types, serialized meta. Import posts + pages + their images; ignore the
   rest with a one-line note.
3. **Draft pile-up** — a 500-post blog imports as 500 drafts. Acceptable for
   v1 (accessibility-first); a "bulk publish that passed the gate" action is a
   fast follow.
4. **Permalinks change** — different URL scheme than the source. Slugs are
   preserved where possible; a redirect map is out of scope for v1.

## Acceptance criteria

1. From the import screen, picking a platform and uploading its native export
   produces a dry-run listing posts, collisions, and a per-post accessibility
   summary — nothing written until confirmed.
2. Confirm imports posts **as drafts**, with dates, tags, and featured images
   mapped; remote images are localized into `uploads/`; slugs preserved.
3. Publishing an imported draft runs the ContentLint gate (so inaccessible
   imported content can't go public unreviewed).
4. Re-importing the same archive creates nothing new (slug-collision skip).
5. Unconvertible embeds appear as a visible marker, never silently dropped.
6. SafeHttp blocks image fetches to private/reserved hosts; oversized fetches
   are skipped and noted.
7. Auto-detection pre-selects the right platform for WXR / Ghost-JSON /
   Substack-zip; the owner can override.
8. Smoke: one representative fixture per Phase-1 format imports to the expected
   draft count with correct title/date/tags.

## Estimate and recommendation

| Phase | Scope | Size |
|---|---|---|
| 0 | Converter layer + `Porter` in-memory entry point + import-as-draft + a11y report + image localization via SafeHttp + format-pick UI | M — **SHIPPED** |
| 1 | **WordPress (WXR)** — **SHIPPED** (`WordPressImporter`; covers Squarespace). **Generic RSS/Atom** — pending | M |
| 2 | **Substack**, **Ghost**, **WriteFreely** — timing, structured, positioning | M |
| 3 | **Medium**, **Blogger**, **Notion**, **Dev.to/Hashnode** | M |

Phase 0 + WordPress shipped: `Porter::analyzeEntries()` / `importEntries()` run
the shared pipeline (HTML→Markdown via `league/html-to-markdown`, inline +
featured image localization through `ImageHandler::storeFromUrl` /
`SafeHttp`, slug-collision skip, import-as-draft, per-post `ContentLint`
report). `WordPressImporter::parse()` maps WXR posts (title/slug/date/tags/
author/body, `_thumbnail_id` → attachment featured image). Import screen has a
source dropdown + auto-detection; the dry-run shows the accessibility report.
Remaining converters plug in by yielding the same entry shape.

Recommendation: build Phase 0 (the shared plumbing) with **WordPress** as the
first converter — it's the largest source and exercises every hard part
(XML + HTML→md + remote images + attachments), so the plumbing is proven on the
worst case. Ship the **generic RSS importer** alongside it as the safety net,
then add converters by demand. Document the "pre-convert with an external tool,
then use the frontmatter-markdown importer" path for the long tail.
