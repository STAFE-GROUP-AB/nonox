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
| `<x-ui.page-heading title lead>` | The greeting or title at the top of a page |
| `<x-ui.card title description>` with `<x-slot:action>` | Every content block |
| `<x-ui.link href>` | Secondary actions; adds the arrow |
| `<x-ui.badge tone="neutral\|accent\|success">` | Status, "Kommer snart" |
| `<x-ui.field for label hint>` | Label, input slot, help text and error |
| `<x-ui.amount :money>` | Every amount: tabular digits, non-breaking spaces, negatives in colour with a minus sign |
| `<x-ui.empty-state title>` | Empty lists, with the next step |
| `<x-button>` | The primary action (Jetstream component, NONOX colours) |

`resources/views/dashboard.blade.php` is the reference page.

## Amounts

Always through `App\Support\Money\Money` and `<x-ui.amount>`: whole öre
internally, `1 234,50 kr` on screen, right-aligned in tables.
