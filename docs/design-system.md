# NONOX design system

The visual identity of NONOX (`AGENTS.md` §11). It is our own: it must not
resemble any competitor.

## Principles

1. **Calm.** Light warm surfaces, generous space, one accent colour.
2. **Plain Swedish.** Write for someone who has never done bookkeeping in
   software. Say what to do next. Every empty state explains the next step.
3. **One primary action per view.** Secondary actions are links.
4. **Honest.** Things that do not exist yet are marked "Kommer snart".

## Tokens (`@theme` in `resources/css/app.css`)

| Token | Value | Use |
|---|---|---|
| `accent-600` | `#9e2a1b` (falu red) | Primary buttons, links, active navigation, focus rings |
| `accent-700` | `#86231a` | Hover, negative amounts |
| `gray-*` | Tailwind `stone` | Text and borders (warm neutrals) |
| `paper` | `#f7f5f0` | Page background |
| Font | System UI stack | No external font requests |

Jetstream's components use `indigo` and `gray`; those names are mapped to the
NONOX palette, so Jetstream pages follow automatically.

The start pages (`resources/views/components/start-layout.blade.php`) carry the
same tokens as inline CSS, so they render before `npm run build`, including
dark mode. Dark mode for the signed-in app comes with the Tailwind 4 upgrade
(T-0.3).

## Components (`resources/views/components/ui`)

| Component | Use |
|---|---|
| `<x-ui.page-heading title lead>` with `<x-slot:action>` | The compact title of a page; the lead is one short sentence |
| `<x-ui.card title description>` with `<x-slot:action>` | Every content block |
| `<x-ui.link href>` | Secondary actions; adds the arrow |
| `<x-ui.badge tone="neutral\|accent\|success">` | Status, "Kommer snart" |
| `<x-ui.field for label hint>` | Label, input slot, help text and error |
| `<x-ui.amount :money>` | Every amount: tabular digits, non-breaking spaces, negatives in colour with a minus sign |
| `<x-ui.empty-state title>` | Empty lists, with the next step |
| `<x-button>` | The primary action (Jetstream component, NONOX colours) |

`resources/views/dashboard.blade.php` is the reference page.

## Översikt (ADR-027)

- Four key figures in a row (two on tablets, one on phones): the company
  account is the dark card; a figure that needs attention soon (VAT due
  within 14 days) uses the accent tint with its hint as a badge.
- "Att göra" rows: an icon in a 40 px rounded square (accent tint when due
  within a week), a bold title, one grey line of context and "Gör det nu →"
  on the right (only the arrow on phones; the screen reader hears the title).
  Icons are `<x-overview.icon name>`, our own simple strokes.
- Signed amounts: money in is green with "+", money out is the accent colour
  with "−", an amount that moves no money is grey without a sign. A short
  note under the list says so.
- The reload arrow sits at the right of the page heading with "Uppdaterad
  HH:MM" (an `aria-live` status) and spins while it works. The top bar's
  search goes to its left.
- Charts (`<x-overview.chart>`): Chart.js bundled by Vite, loaded only with
  the statistics tab. One fixed palette checked for colour blindness:
  `#d4330b`, `#1f6fb8`, `#a67800`, in that order; last year and forecasts
  are dashed in the same colour. One value axis, short ticks ("800 tkr"),
  full amounts in tooltips. Every chart has a one-sentence summary (also its
  accessible name) and "Visa som tabell" with the same figures.

## Navigation

One tree, `App\Support\Navigation\Navigation`, holds the sections of the
sidebar and their pages, with the permission and module checks. Modules add
their sections through `Module::navigation()`, with a `children` closure for
their pages.

- The pages of a section are listed in the sidebar under the open section
  only, indented, in smaller type; Bokföring groups them under small labels
  (Löpande, Rapporter och moms, Bokslut, Inställningar). On small screens the
  same tree is in the drawer, and the top bar says where you are.
- Pages carry no row of links to their siblings. In-page choices (fiscal
  year, report, filter) stay on the page.
- The current page has `aria-current="page"` and an accent mark.
- Key figures are shown on a section's start page only (Fakturor,
  Leverantörsfakturor, Verifikationer, Lönekörningar) and on Översikt.

## Calls to action

- The main call to action of a list or card ("Ny artikel", "Ny faktura",
  "Lägg till lokal") always sits first, on the left, before search fields
  and filters, on every page. In a card use the `cta` slot of
  `<x-ui.card>`; the `action` slot (right of the title) is for secondary
  things such as "Stäng" or "Visa".

## Amounts

Always through `App\Support\Money\Money` and `<x-ui.amount>`: whole öre
internally, `1 234,50 kr` on screen, right-aligned in tables.

## Spotlight (search)

One search for the whole system, `<x-spotlight.dialog>` with
`<x-spotlight.trigger>` (ADR-026). It is the fastest way anywhere: to a
record, a page or a new thing.

- **Opening.** ⌘K (Mac) or Ctrl+K anywhere, "/" when the user is not typing,
  the field "Sök eller gå till … ⌘K" in the top bar on desktop, or the
  magnifier in the dark bar on a phone. On a phone it is a full-screen sheet.
- **Keys.** ↑↓ choose, Enter opens, ⌘Enter / Ctrl+Enter opens in a new tab,
  Tab and Shift+Tab jump between groups, Esc closes and gives focus back.
  ← at the start of the field moves to the filter chips; Backspace removes
  the chip in focus.
- **Empty field.** "Senast öppnade" (the last eight records), "Att göra"
  (things waiting, with counts) and "Snabbval" (the most used create
  actions). Never an empty white box.
- **Results.** Grouped, at most five per group, best first; a group with an
  exact hit (a number, an OCR) moves to the top. A row is icon, title,
  subtitle, and on the right the amount (tabular) and a status badge. The
  typed words are marked in amber. A group with more hits ends with the
  option "Visa alla N", which opens the filtered list page.
- **Understood filters** are chips under the field ("Kundfakturor",
  "Obetalda", "Belopp över 10 000,00 kr", "Oktober 2026"); removing a chip
  removes its words from the field.
- **The active row** has the accent background and the accent mark on the
  left, like the current page in the sidebar.
- **Accessibility.** `role="dialog"` with `aria-modal`; the field is a
  `combobox` with `aria-activedescendant` into a `listbox` of `group`s and
  `option`s; the number of results is announced in a polite live region;
  focus stays in the dialog. Transitions only with `motion-safe`.
- **Words.** Swedish throughout: "Sök eller gå till …", "Inget hittades"
  with examples of what can be typed, "Visa alla 23".

## Accessibility (WCAG 2.2 AA)

The audit and the list of known gaps are in `docs/tillganglighet.md`. What
every view gets from the shared layout, and what a new view must keep:

- **Title and landmarks.** The app layout adds the skip link, wraps the page
  heading and the page in `<main id="main">`, and names the page after its
  first `h1` (`App\Support\Accessibility\PageTitle`). Every page has one
  `h1`, normally `<x-ui.page-heading>`; pass `<x-slot name="title">` only when
  the heading is not a good title (Översikt greets the user).
- **Text colour.** Small text is `text-gray-500` or darker; `gray-400` and
  `gray-500` are tuned to 4.5:1 on white, paper and the accent tints. Field
  borders are darkened to 3:1 in `app.css`.
- **Fields.** Use `<x-ui.field for label hint error>`: the error gets
  `role="alert"` and `resources/js/accessibility.js` links it and the hint
  to the input (`aria-invalid`, `aria-describedby`). A bare
  `<x-input-error :for="key" :field="inputId">` works too. After a sent
  form with errors, focus moves to the first one and the count is read out.
- **Dialogs and menus.** Use `x-dialog-modal`/`x-confirmation-modal`
  (`role="dialog"`, named by the title, focus trapped and returned) and
  `x-dropdown` (`aria-expanded`, Esc).
- **Focus.** Never remove the outline without a `focus-visible:ring`. Sticky
  bars carry `data-sticky-bar` so focused elements are kept below them.
- **Targets.** Icon buttons are at least 24 px (32 px for row actions).
