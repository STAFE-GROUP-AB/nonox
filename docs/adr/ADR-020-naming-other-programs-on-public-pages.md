# ADR-020: Naming other bookkeeping programs on public pages

- Status: Accepted (approved by the project owner in chat, 2026-10-31)
- Amends: AGENTS.md §1.1

## Context

AGENTS.md §1.1 allowed the word "Fortnox" only in the migration module and
`docs/migration`, as a descriptive reference to a data source. The public
site at nonox.app must be found by people who search for a free
alternative to the established bookkeeping programs, and must let them
compare features and prices before they move.

## Decision

The names of other bookkeeping programs (Fortnox, Visma/Spiris, Bokio,
Björn Lundén and others) may appear on the **public pages**: the landing
page, the moving guide, the comparison and the "alternativ till" pages, as
plain-text, descriptive references for comparison and migration.

- Never their logos, icons, colours, screenshots, UI layouts, help texts or
  marketing copy (§1.1 otherwise unchanged).
- Comparisons follow Marknadsföringslagen (2008:486) 18 §: they compare
  like with like, are objective and verifiable, and say where and when each
  price was taken. Unverified figures are marked as such.
- The data lives in `docs/migration` (prices, features, sources and the
  time they were fetched), so it is reviewed and versioned like other
  documentation, and the pages read it from there.
- Inside the product itself the rule stays: the names appear only in the
  migration module.

## Consequences

Prices change; the comparison shows the date and time each was fetched and
must be refreshed when they change. TODO(legal-verify): review the
comparison against Marknadsföringslagen and the trademark use before wide
promotion.
