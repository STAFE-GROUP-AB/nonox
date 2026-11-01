# ADR-036: The public user manual at /manual

- Status: Proposed
- Date: 2026-11-01
- Task: a manual for users and employees, linked from the app

## Context

NONOX has a developer site (`/utvecklare`, ADR-032) and a moving guide
(`/flytta-hit`), but nothing that explains the app itself to the people who
use it: company owners, bookkeepers, accounting firms and employees on Min
sida or in the staff ledger. Help has to be findable from inside the app, on
the page the user is stuck on, and must work on a phone for employees.

## Decision

1. **Content is Markdown in the repository**: `docs/manual/sv/avsnitt.yaml`
   names the sections in menu order; each article is
   `docs/manual/sv/<avsnitt>/<artikel>.md` with YAML front matter
   (`beskrivning`, `ordning`, `roller`, `modul`, `rutter`, `sokord`) and one
   `#` title. The contract for writers is `docs/manual/SKRIVREGLER.md`. The
   articles read the same on GitHub, are reviewed in pull requests, and ship
   with the version of NONOX they describe. Swedish only for now; English
   would be `docs/manual/en/` beside it.
2. **Rendered on the server** (`App\Support\Manual`): `Manual` reads the
   index, `ManualMarkdown` renders an article (GitHub alerts as notices with
   Swedish labels, a numbered list under a "Så här …" heading as steps, heading
   anchors and a table of contents, tables in scroll boxes, `rel="noopener"`
   on external links). It is independent of the developer site's Markdown,
   which rewrites links between guides and handles two languages.
3. **Cached in the file store under a hash of the files** (names, sizes,
   modification times, the content path and the installation's address), so
   an edited article shows at once and the help button in the app never adds
   a database query, whichever cache store is the default.
4. **Routes**: `/manual` (`manual.home`), `/manual/{avsnitt}`
   (`manual.section`, the section's `oversikt.md` plus its articles),
   `/manual/{avsnitt}/{artikel}` (`manual.article`) and
   `/manual/sokindex.json` (`manual.search`). Unknown paths answer 404, and
   so does the overview under a second address. The manual is shown exactly
   when the public pages are (`app.landing.enabled`).
5. **Search runs in the browser** (`resources/js/manual.js`, its own Vite
   entry, no package): the index (title, description, search words,
   headings, body text up to 4,000 characters) is fetched once with an ETag.
   Words are folded (case, å/ä/ö), every word must match, and a hit in the
   title counts more than one in the search words, headings, description and
   body. `/` or Ctrl/⌘+K focuses the box, arrow keys move through the hits.
   A server-side search was not needed for some 150 articles.
6. **Help in the app**: each article lists the route names it explains in
   `rutter`. `Manual::articleForRoute()` maps a route to the most specific
   article (a task article before an overview, then the article naming the
   fewest routes). The sidebar and the phone bar of the app, and the header
   of Min sida, show **Hjälp**, opening that article (or the section for
   employees, or the manual's start page) in a new tab. It is hidden when the
   public pages are off.
7. **Search engines and sharing**: canonical address, meta description from
   `beskrivning`, `BreadcrumbList`, and `HowTo` for an article with steps,
   otherwise `TechArticle`. Every page is in the sitemap. Share images exist
   for the start page and for every section in `avsnitt.yaml`; an article
   uses its section's image (`ShareCards::imageFor` walks up the path).
8. `NONOX_MANUAL_PATH` can point the manual at another folder (a fork's own
   manual, or the fixture manual for a local preview). The tests use
   `tests/Fixtures/manual`, and also validate the real articles: front
   matter, existing roles, modules and routes, every section has an
   overview, and every `/manual/…` link and `#anchor` resolves.

## Consequences

- Writing help is a documentation change, not a code change.
- A renamed route makes the manual's test fail until the article's `rutter`
  are updated, so the help button cannot silently point at nothing.
- Adding a section to `avsnitt.yaml` needs `php artisan og:images` for its
  share image (the share image test says so).
- No usage tracking ("Was this helpful?") on purpose: the public pages set no
  analytics.
