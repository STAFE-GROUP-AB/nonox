# ADR-009: Cost centres and projects

- Status: Accepted
- Date: 2026-10-29
- Task: §6.5 (before T-1.5, so reports can filter on them from the start)

## Context

Many companies moving here, restaurants above all, book per cost centre (one
per restaurant) and per project. A SIE import that drops them breaks the
promise that nothing is lost when leaving another program.

## Decisions

- Two dimensions only, matching the SIE reserved numbers: **kostnadsställe**
  (dimension 1) and **projekt** (dimension 6). No free dimensions and no
  sub-dimensions: the program most users come from has only these two.
- Tables `cost_centers` and `projects`: `code` (up to 20 characters, unique per
  company), `name`, `is_active`. The code never changes, because voucher rows
  and SIE files refer to it. Objects are renamed or deactivated, never removed.
  `voucher_rows.cost_center_id` and `project_id` (there since the vouchers
  table) now have foreign keys.
- **Rows refer to objects by code** in every input (`cost_center`,
  `project`), looked up inside the company. The earlier raw-id input is gone,
  so a row can never point at another company's object.
- A deactivated object is hidden from the editor's lists but stays valid, so
  old vouchers can still be corrected.
- `requires_cost_center` / `requires_project` on an account are enforced at
  posting (they already were; they can now be met).
- The voucher editor shows the two columns only when the company has active
  objects (or the voucher already uses them), so a company without them sees
  nothing new.
- **SIE.** Export writes `#DIM 1`/`#DIM 6`, every `#OBJEKT`, and the object
  list on `#TRANS`, `#RTRANS` and `#BTRANS`; only when the company has
  objects, so files without dimensions are unchanged. Import creates every
  cost centre and project the file defines or uses, and puts them on the rows
  and on the row history. Other dimensions are reported and dropped.
- Not written or read: `#OIB`, `#OUB`, `#PSALDO`, `#PBUDGET`. Those are
  balances per object, which can be computed from the rows. Following the
  user's instruction, SIE support goes no further than the source program
  needs.
- Permissions: anyone with `vouchers:view` sees the registers; `accounts:manage`
  changes them. Every change is audited (`dimension.created`,
  `dimension.updated`).
