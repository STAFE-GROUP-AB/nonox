# ADR-019: The 2027 interface and company colours

- Status: accepted
- Date: 2026-10-31

## Context

The landing page and the design sketches set a new look: a dark sidebar,
warm paper backgrounds, large rounded cards, black for selected states and
a warm vermilion as the one accent colour (AGENTS.md §11.1). Companies can
already choose their own colours under Varumärke for their documents and
the employee portal, and want the app to follow them.

## Decision

- The layout has a fixed dark sidebar (`ink`, #17171a) on wide screens with
  the company card (company menu and switcher), the main navigation and the
  user; on small screens the same content slides in from a dark top bar.
- The accent colour is a palette of CSS variables (`--accent-50` to
  `--accent-950`, "r g b"), used by Tailwind as `accent-*`. NONOX's
  palette is the default in `resources/css/app.css`.
- When the company has chosen its own colours under Varumärke, the app
  layout replaces the variables with a palette computed from them
  (`Brand::interfacePalette`): the accent colour first, then the main
  colour, passing over one that is nearly white (below 1.2:1 against white,
  a background colour on the documents). Shade 500 (white initials and
  marks) is darkened to 3:1, and 600 (buttons, white text) and 700 (text on
  white) to 4.5:1, so a light company colour still meets WCAG 2.2 AA.
- Pages use the full width beside the sidebar; only the short one-off forms
  (getting started, new company) keep a reading width.
- NONOX's bright vermilion (#ff4a1c) is shade 500 and is used for marks,
  avatars and the active menu item only: against white text it reaches
  3.4:1, too little for button text. Buttons use shade 600 (#d4330b, 4.9:1).
- Shared components (buttons, fields, cards, badges, sections, key figures,
  sub-navigation) carry the look, so pages follow without being rewritten.

## Consequences

- A company's colour changes the whole app for its members, as well as its
  documents. Pages written with fixed colours outside `accent-*` do not
  follow; new code uses `accent-*` for the accent.
- The system font stack is kept; no web font is loaded from a third party.
