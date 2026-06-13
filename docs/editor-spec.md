# Spec: optional WYSIWYG editor (Toast UI)

Status: proposed (June 2026). The current editor is EasyMDE — a markdown
textarea with a preview toggle; the source syntax is always visible. This adds
an opt-in clean-writing mode without disturbing the markdown pipeline or the
accessibility-first defaults.

## Problem

Writing a post means looking at raw markdown. That suits people who think in
markdown and want the source; it is a barrier for writers who want a clean
surface where the syntax renders inline and disappears as they type (the
Typora experience). Fieldnote's positioning is approachable and accessible —
a clean editor fits — but markdown purists must keep their source view. The
answer is to let the site owner choose, not to pick one for everyone.

## Goals

- A per-site setting selects the editor: `markdown` (EasyMDE, current,
  **default**) or `rich` (Toast UI, WYSIWYG with a Markdown tab fallback)
- Posts are still stored as **markdown** — feeds (RSS/JSON), ActivityPub,
  export/import, and the ContentLint gate all keep working unchanged
- Self-hosted and vendored: no CDN, no network calls, no runtime build step
- The accessible default is preserved — the plain markdown editor stays the
  default; rich mode is strictly opt-in
- The server-side ContentLint gate stays authoritative regardless of editor

## Non-goals (v1)

- Replacing EasyMDE — both editors coexist, chosen by the setting
- Per-post editor choice (site-level only; single-admin model)
- Collaborative editing, paste-image-to-upload, or changing storage to HTML

## Design decisions

### Setting

New config key `editorMode`, an enum `'markdown' | 'rich'`, default
`'markdown'`. Rendered as a `<select class="form-select">` in Settings,
mirroring `blogTemplate` / `blogI18N`; one save-handler line in the settings
POST route matching those. Existing sites load with `markdown` (the default),
so nothing changes for anyone until they opt in.

### Loading the bundle

`write.php` already sets `$needsEditor = true`; `header.php`/`footer.php` load
the EasyMDE assets behind it. Extend that to branch on
`$siteConfig['editorMode']`:

- `markdown` → existing `easymde.min.css/js` (+ icons)
- `rich` → vendored `toastui-editor.min.css` + `toastui-editor-all.min.js`

`admin.js` initializes the chosen editor on `#blogPostContent` — read the mode
from a `data-editor` attribute on the textarea (no inline script; admin CSP is
relaxed but stays script-clean). Only one bundle ever loads per page.

### Markdown bridge (storage stays markdown)

Toast UI is initialized with `initialEditType: 'wysiwyg'` and
`initialValue` = the textarea's stored markdown. On form submit, write
`editor.getMarkdown()` back into the real `blogPostContent` textarea before the
POST fires. The edit/publish/lint/feed pipeline never sees anything but
markdown — the editor is purely a front-end skin over the same field.

### ParsedownExtra compatibility — the crux

Rendering everywhere uses **ParsedownExtra** (`src/routes.php`,
`src/ContentLint.php`): footnotes (`[^1]`), definition lists, abbreviations —
features beyond CommonMark/GFM. Toast UI's parser (ToastMark) is CommonMark +
GFM and does **not** understand those extras, so round-tripping a post that
uses them through WYSIWYG would escape or mangle them.

Decisions:
- Enable Toast UI GFM features (tables, strikethrough, task lists, autolinks)
  so the common extensions round-trip cleanly.
- Guard the ParsedownExtra-only syntax: on load, if the post's markdown
  contains footnote / definition-list / abbreviation markers, open rich mode
  on its **Markdown tab** (not WYSIWYG) with a notice — "this post uses
  extended markdown; switch to the Markdown editor to avoid reformatting." Do
  not silently WYSIWYG-ify content the editor can't faithfully reproduce.
- Document the WYSIWYG-supported subset in the theme/writer docs.

### Round-trip normalization

A WYSIWYG re-serializes markdown on save, which reformats it (bullet char,
emphasis marker, wrapping). With post revisions (last 10) and markdown export,
that risks cosmetic churn and burned revision slots. Mitigations:
- Only write `getMarkdown()` back when it differs from the original value, so a
  no-op open-and-save doesn't rewrite the file.
- Accept that genuine edits in rich mode normalize formatting; document it.
- Optionally bias rich mode toward new posts; existing posts open as stored.

### Accessibility

`contenteditable` WYSIWYG is weaker for assistive tech than a `<textarea>`.
Two things keep this on-brand:
- The **default stays `markdown`** — the accessible path is the default path.
- Even in rich mode, Toast UI's **Markdown tab is a real textarea** — an
  accessible fallback is always one click away.

Public output is identical either way, so the `/accessibility` story and the
70-theme audit are unaffected. Verify Toast UI's toolbar ARIA during build;
document any gaps.

### Heading-skip prevention (bonus)

Constrain the WYSIWYG heading control to H2/H3 for body content — matching the
EasyMDE toolbar and the ContentLint gate — so the editor prevents the
heading-skip errors the gate catches, at the keystroke.

## Security notes

- Bundle is vendored and same-origin (no SRI needed); pin the version, commit
  the file, confirm Toast UI makes no network calls by default (no remote
  spellcheck/telemetry).
- Stored content is still markdown, still rendered through Parsedown safe-mode
  server-side — the XSS posture is unchanged; the editor adds no new sink.
- Admin-only surface under the relaxed admin CSP; keep init out of inline
  script (data-attribute + admin.js), so no `unsafe-inline` is introduced.

## The honest dragons

1. **ParsedownExtra-only syntax** — the real one. Guard on load + document the
   supported subset; never silently mangle footnotes/def-lists.
2. **Normalization churn** vs revisions/export — write-back only on change.
3. **Bundle weight** (~few hundred KB) on the admin page — admin-only and
   loaded only when `editorMode = rich`; acceptable.
4. **Maintenance** — pin the Toast UI version; bump deliberately.

## Acceptance criteria

1. Settings shows Editor = Markdown | Rich; default Markdown; existing sites
   are unchanged until they opt in.
2. Rich mode loads Toast UI in WYSIWYG with the stored markdown, syntax hidden;
   the Markdown tab toggle works.
3. Saving in rich mode writes markdown back; stored content is markdown; feeds,
   ActivityPub, and export are byte-compatible with markdown-mode saves.
4. The ContentLint gate still blocks inaccessible content from rich-mode saves
   and publishes (no editor can bypass it).
5. A post using ParsedownExtra-only syntax is not silently reformatted — it
   opens on the Markdown tab with a notice.
6. No CDN or network calls; bundle served locally; admin CSP satisfied with no
   inline script.
7. Markdown mode (EasyMDE) is untouched and remains the default.
8. Smoke: `editorMode` persists; rich mode serves the toastui bundle and not
   easymde, and vice versa.

## Estimate and recommendation

| Phase | Scope | Size |
|---|---|---|
| Vendor bundle + `editorMode` setting + load switching + JS bridge | M |
| ParsedownExtra guard + round-trip write-back + a11y check + smoke | M |

Total: M–L. Recommendation: ship rich mode as opt-in with the default
unchanged, land the ParsedownExtra guard with it (not after), and iterate on
round-trip fidelity once it's in real use.
