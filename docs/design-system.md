# NONOX design system

The visual identity of NONOX (`AGENTS.md` §11). It is our own: it must not
resemble any competitor.

## Principles

1. **Calm.** Light warm surfaces, generous space, one accent colour.
2. **Plain Swedish.** Write for someone who has never done bookkeeping in
   software. Say what to do next. Every empty state explains the next step.
3. **One primary action per view.** Secondary actions are links.
4. **Honest.** Things that do not exist yet are marked "Kommer snart".

## Tokens (`tailwind.config.js`)

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
