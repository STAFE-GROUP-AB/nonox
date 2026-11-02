# How the NONOX manual is written

The public manual at `/manual` is built from the Markdown files in
`docs/manual/sv/`. This file is the contract between the people (and
agents) who write articles and the code that renders them
(`app/Support/Manual`). The manual is for **users of NONOX**, not for
developers: the developer site at `/utvecklare` covers the API.

## Files

```
docs/manual/sv/avsnitt.yaml            sections, in menu order
docs/manual/sv/<avsnitt>/<artikel>.md  one article per file
```

- `<avsnitt>` is a key in `avsnitt.yaml`; `<artikel>` is the article's path:
  lowercase ASCII, hyphens, å and ä as `a`, ö as `o`
  (`bokfor-en-verifikation`, `las-en-period`).
- The URL is `/manual/<avsnitt>/<artikel>`.
- Every section has an article named `oversikt.md` that introduces the
  section and links to its other articles. It is the section's start page.

## Front matter

Every article starts with YAML front matter, then one `#` heading that is
the article's title.

```markdown
---
beskrivning: En eller två meningar som säger vad artikeln hjälper dig med. Visas i sökningen och som beskrivning i Google.
ordning: 20
roller: [owner, admin, accountant, bookkeeper]
modul: payroll
rutter: [vouchers.index, vouchers.create]
sokord: [verifikat, bokföringsorder, kontera]
---

# Bokför en verifikation
```

| Field | Required | What it is |
|---|---|---|
| `beskrivning` | yes | 1–2 sentences, at most 200 characters. |
| `ordning` | yes | Sort order inside the section (`oversikt` is 0). Use steps of 10. |
| `roller` | no | Role keys (AGENTS.md §4) that can do what the article describes. Shown as "Gäller för". Leave out when every role can. |
| `modul` | no | Module key when the article needs a module switched on (`payroll`, `agreements`, `staff_ledger`, `inbox`, `ai`, `banking`, `connect`). |
| `rutter` | no | Named routes in the app (`routes/web.php`, `app/Modules/*/routes`) this article explains. The app's help button links to the article for the page you are on. **Every name must exist** — a test checks it. One route should belong to one article; the most specific article wins. |
| `sokord` | no | Other words people search for (synonyms, other programs' terms in plain words). |

## Markdown

GitHub-flavoured Markdown, plus:

- **Notices**: GitHub alerts.
  `> [!TIP]` (Tips), `> [!NOTE]` (Bra att veta), `> [!IMPORTANT]` (Viktigt),
  `> [!WARNING]` (Varning — something cannot be undone).
- **Steps**: a numbered list directly under a heading that starts with
  "Så här" is shown as numbered steps.
- **Keys**: `<kbd>Ctrl</kbd>` + `<kbd>Enter</kbd>`.
- **Menu paths and buttons** in bold, exactly as the app shows them:
  **Bokföring → Verifikationer**, then **Ny verifikation**.
- **Links between articles**: absolute paths, `[Rätta en verifikation](/manual/bokforing/ratta-en-verifikation)`,
  with an optional `#rubrik`. A test checks that every `/manual/…` link resolves.
- **Links to the public site**: `/flytta-hit`, `/sakerhet`, `/utvecklare/…`.
- No links into the logged-in app (the address differs on a self-hosted
  NONOX). Name the menu path instead.
- **Screenshots**: `![Bildtext.](/bilder/manual/<avsnitt>/<artikel>.webp)`
  on a line of its own, usually after the first paragraph. The alt text is
  shown as the caption: one sentence in Swedish that says what the picture
  shows. Screenshots are never made by hand: list the page in
  `docs/manual/skarmbilder.yaml` and take it with `php artisan
  manual:screenshots` from the demo companies (see README, "Skärmbilder
  till manualen"), so they only ever show made-up data and can be taken
  again when the app changes. A test checks that every image exists, is in
  the list and is shown by an article.

## Writing

- **Swedish**, plain and friendly, *du*-form. Short sentences. Explain an
  accounting word the first time it appears, or link to the glossary
  (`/manual/ordlista/ordlista#ord`).
- Start with what the reader gets done, not with background.
- Structure most task articles like this:
  1. One short paragraph: what this is and when you need it.
  2. `## Så här gör du` — numbered steps, one action per step, exact labels.
  3. `## Det här händer i bokföringen` — which voucher series, which accounts,
     when it is booked (only where something is booked).
  4. `## Bra att veta` / `## Vanliga frågor` — the questions people really ask.
  5. `## Läs också` — 2–5 links.
- **Describe what NONOX actually does.** Read the code: routes, Livewire
  components, Blade views and `lang/sv.json` for the exact labels, the
  Actions for what is booked and what is checked. Never describe a feature
  that is not built; never guess a label.
- **Never invent rules of law.** State what the app does and, where the law
  is involved, name the law (Bokföringslagen, Mervärdesskattelagen,
  Semesterlagen …) without inventing details, amounts or deadlines that are
  not in the code or in `docs/`. If NONOX marks something `TODO(legal-verify)`,
  say that the reader should check it with their accountant or Skatteverket.
- No real company, person or personal number. Examples use
  "Exempelbolaget AB", "Café Hörnet", "Anna Andersson". Leave organisation
  and personal numbers out.
- Never name a competitor, except in `flytta/` where the source program
  may be named as a plain fact (ADR-020).
- Articles for employees (`personal/`) assume no accounting knowledge at
  all and are written for a phone screen: very short steps.
