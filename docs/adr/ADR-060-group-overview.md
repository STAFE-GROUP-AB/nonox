# ADR-060: Koncernöversikt – an internal group overview

- Status: Accepted for stage 1 only
- Date: 2026-11-06
- Task: Track G.1

## Context

Owners of small holding structures (a holding company and a few subsidiaries)
want to see their companies side by side. NONOX already shows one company at a
time and, in "Mina företag", what waits in each. A combined view must not
weaken tenancy (AGENTS.md §1.3): cross-tenant leakage is a P0 bug.

It must also not pretend to be something it is not. A statutory
koncernredovisning (ÅRL, consolidated accounts: uniform principles,
elimination of internal profits, goodwill, minority interests) is a different,
larger product and is **not** built. This is internal reporting for the owner.

## Decisions

### A group is the user's own list, not a company's

`company_groups` belongs to a **user** (`user_id`), with `company_group_members`
(company id, optional ownership share in basis points) and `company_group_marks`
(company id, BAS account number, kind). None of the three tables is
company-scoped and none holds company data: only ids, a name, a share and
account numbers. Deleting the user deletes their groups; removing a company
removes its rows.

Rejected: storing a group on an "owning company". A holding company's
administrators would then see which companies the owner has put with it, and a
company the user later leaves would still carry the grouping. A per-user list
needs no data from any company to exist.

### Access is decided at read time, from memberships, never from the group row

`GroupAccess` is the only gate. A company's figures are shown only if the user
is a member of **that** company with `reports:view` there (`Memberships::may`,
the same rule as `User::hasTeamPermission`, including the archived-company
rule). It runs on every render, every CSV request and every action:

- A row naming a company the user cannot see is skipped; the page only says
  that "ett företag i koncernen visas inte" and how many, never which.
- Removing a membership, or changing to a role without `reports:view`
  (approver, payroll and so on), removes the company from the view at once. No
  cache is involved.
- Adding a company or a mark requires membership and `reports:view` in that
  company, checked in the Action, not trusted from the client. The group id
  that Livewire keeps in public state is re-resolved among the **user's own**
  groups on every use; another user's group answers 404 (page, CSV and every
  Action), as for any record of another tenant.
- Each company is read inside `CurrentCompany::run($company, …)`, so the
  ordinary `CompanyScope` applies. No query crosses companies, and
  `CompanyScope::bypass` is not used anywhere in this feature. The reports are
  the existing `LedgerReports` (income statement, balance sheet) per company.

Group settings are the user's own and are not bookkeeping, so they write no
audit entry (AGENTS.md §1.2 concerns bookkeeping actions).

### Same fiscal year, or the company is left out with a note

The user picks a fiscal year among those the companies have, identified by
start and end date. The default is the year most companies have. Companies
without exactly that year are **not** summed; they are listed with the reason
("har inte räkenskapsåret …"). This is "refuse, with a clear note" rather than
guessing how to stretch odd years. The period always starts at the year's first
day; the user may end it earlier.

### Intra-group marking: accounts, not counterparties

What the data model supports is a ledger by account number. Customers and
suppliers carry an organisation number, but a ledger row has no counterparty,
and inferring sales from invoices would duplicate invoice and credit-note
semantics. So the user marks **accounts** per company with one of six kinds:
shares in group companies, receivable, payable, equity in subsidiary, sales,
purchases. "Föreslå markeringar" marks accounts whose name contains "koncern"
by BAS class (an aid, not a rule; the user can change every mark). Defaults are
not applied silently.

### Elimination suggestions

Per pair the page shows both sides, the amount that **could** be eliminated
(the smaller side, never below zero) and the difference:

| Pair | Left | Right |
|---|---|---|
| Receivables and payables | closing balance, receivable accounts (debit) | closing balance, payable accounts (credit) |
| Sales and purchases | revenue accounts over the period (credit) | cost accounts over the period (debit) |
| Shares and equity | closing balance, share accounts (debit) | closing balance, equity accounts (credit) |

Plus a before/after of total assets, total equity and liabilities, and
operating revenue. Suggestions only: nothing is booked, no voucher, no table
besides the three above is written. The ownership share is shown under the
company name and is **not** used in any calculation.

### Cost

Per company the page runs a fixed set of queries (fiscal years, accounts,
opening balances and movements for the two reports), independent of the number
of accounts and vouchers; a test fixes the count with 3 and with 123 accounts.
Marks add one query for the group.

## Not built (stage 1)

- Consolidated accounts under ÅRL, in any form: no elimination of internal
  profits, no goodwill, no minority interest, no uniform principles, no
  currency translation. Currency is the companies' own kronor figures.
- Counterparty-based marking (customer/supplier flagged as a group company).
- Automatic elimination vouchers, or any posting.
- Companies with different fiscal years combined by stretching or splitting.
- Share-weighted figures (proportional consolidation) from the ownership share.
- A group for an accounting firm's clients (a firm's own "Mina företag" remains
  the tool for that).
- PDF export (printing the page and CSV are provided).

## Consequences

- `TODO(legal-verify)`: the BAS account names and ranges used for the
  "Föreslå markeringar" aid are matched on the word "koncern" in the chart of
  accounts, not on a verified list of BAS numbers; confirm against the BAS
  licence-clean account list before marketing the aid beyond the manual.
- The page must keep saying, in print as well, that it is an internal overview
  and not a koncernredovisning.
- Landing and comparison pages are not changed: no screenshot of a demo group
  exists yet, and `Features.php` entries need one (see `LandingPageTest`).
