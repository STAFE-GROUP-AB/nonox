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

---

## Addendum 2026-11-07: spec S2 stage 1 and stage 2 plan

- Status: Accepted by the Lead for stage 1 (2026-11-07). Stage 2 needs the product owner's acceptance and decisions H1–H8. S2-1.1 built (branch `spec/s2/group-model`, see "S2-1.1 as built" under 3.1).
- Inputs: spec `docs/specs/nummer-1-spec.md` Part 3 S2 (stage 1 scope 1–5, data,
  behaviour, permissions, tests); rule sheet `docs/specs/rules/s2.md` (status ready,
  quote-checked 2026-11-07; rule ids S2-R1 … S2-R14, corrections C1–C10, human
  decisions H1–H8)
- Owner: S2 (`docs/specs/ownership.md`)

This addendum compares the spec's stage 1 with what G.1 built, decides what changes,
names the build packages and lists the decisions only a human can make. It does not
change the rejection of statutory group accounts in stage 1; stage 2 is planned here at
headline level only and needs its own accepted addendum before code.

### 1. Spec stage 1 against G.1

| Spec stage 1 scope | G.1 (above) | Gap |
|---|---|---|
| 1. Group with parent, subsidiaries, ownership %, acquisition date, voting rights | Per-user list; optional ownership share in basis points, shown only; no parent, no voting share, no dates | Large: no parent, no holder chain, no voting share, no control basis, no acquisition or disposal date (rule sheet C7, S2-R1d) |
| 2. Mark counterparties as group companies, intercompany vouchers tagged | Accounts marked per company with six kinds; counterparty marking explicitly not built | Large: no counterparty, no row-level tag |
| 3. Consolidated income statement and balance sheet for any period | Side by side with a sum column; one shared fiscal year, period start fixed to the year's first day | Medium: no consolidated figure after accepted eliminations, no free period, no mid-year date cut |
| 4. Elimination suggestions: sales/purchases, receivables/payables, loans, interest, dividends, group contributions | Three pairs (receivables/payables, sales/purchases, shares/equity), smaller side, nothing stored | Medium: loans, interest, dividends and group contributions missing; nothing can be accepted; no stored run |
| 5. Label "Intern koncernrapport, inte en koncernredovisning enligt ÅRL" | Label "Intern översikt, inte en koncernredovisning", on screen and in print | Small: wording, and it must also go on CSV/PDF headers and file names |
| Behaviour 1: create only as owner/admin in every company | Any user with `reports:view` in a company may add it | Changed rule |
| Permissions: group view only for members of every company or an explicit group role | `GroupAccess` shows the companies the user may see and says how many are hidden | Changed rule |
| Behaviour 5: reproducible, stored run log | Computed on every render, nothing stored | New |

### 2. Decisions

#### 2.1 The group becomes a shared entity; ownership facts live in the holder's books

The per-user list cannot carry the facts the rule sheet requires (C7): two users could
record different acquisition dates for the same subsidiary, and stage 2 needs exactly one
authoritative structure per parent (ÅRL 7 kap. 1 §: the *moderföretag* prepares the group
accounts). So the group becomes an entity. The original objection above (a holding
company's administrators would see which companies the owner grouped with it) is met by
splitting the data in two:

1. **Holdings are the holder company's own data.** New company-scoped table
   `company_holdings` (`BelongsToCompany`, `company_id` = the holder): `held_org_number`,
   `held_name`, `capital_bp`, `voting_bp` (basis points, kept separate: S2-R8 needs the
   capital share, S2-R1d the votes), `control_basis` (enum `ControlBasis`: VOTES,
   AGREEMENT_VOTES, BOARD, CONTROLLING_INFLUENCE, NONE — ÅRL 1 kap. 4 § points 1–4,
   entered by a human, never derived from `voting_bp`), `acquired_on` (a date, not a
   period, S2-R4a), `disposed_on` (date, null), `common_control` (nullable bool,
   S2-R4e / H2), timestamps. The held company is named by **organisation number and
   name, never by another tenant's id**, exactly like a customer row. This is the
   register of shares in group companies that the holder's own books already reflect
   (BAS 131x), so an administrator of the holder learns nothing they could not read in
   its ledger. Written with `company:update` (owner, admin). It can also feed the S1 note
   on shares in group companies; offered to S1, not a dependency.
2. **The group is a cross-company record with live access checks.** New tables
   `corporate_groups` (`id`, `parent_company_id`, `name`, `created_by`, timestamps) and
   `corporate_group_members` (`corporate_group_id`, `company_id`, `holding_id` null for
   the parent, `confirmed_by`, `confirmed_at`; unique per group and company). Neither is
   company-scoped (they span companies, like `company_groups`) and neither holds ledger
   data. A member row may only be created by a user who is owner or admin of **that**
   company and of the parent, and only when a holding in the group's tree names its
   organisation number; indirect subsidiaries (ÅRL 1 kap. 4 § andra stycket) follow
   from holdings held by members.
3. **Consistency checks before a member is confirmed:** the held company's
   `base_currency` equals the parent's, else refused (S2-R10; foreign currency is out of
   scope); the sum of `voting_bp` over all active holdings naming the same organisation
   number within the group is at most 10 000, else refused as a conflict.
4. **G.1's per-user lists stay** for one release as "Egen jämförelse": unchanged
   behaviour (per-company visibility, "N företag visas inte"), suggestions only, no
   acceptance, no run log. The overview offers "Gör om till koncern" (needs the
   owner/admin rule and a holding per subsidiary). Whether and when the lists are removed
   is decision D1 below. The new tables have new names, so the G.1 code and its tests
   keep working unchanged during the switch.

Rejected: (a) extending `company_groups` with `parent_company_id` and a nullable
`user_id` — two access models in one table, and every G.1 query would need a branch;
(b) storing holdings on the group row — the facts belong to the holder's books and would
be duplicated per group (a sub-group parent sits in two groups); (c) deriving members
automatically from organisation-number matches — it would pull in a NONOX company whose
administrators never agreed.

#### 2.2 Access: all or nothing at group level, plus one explicit group role

`GroupAccess` stays the only gate. It gains `levelFor(CorporateGroup $group, period)`
returning an enum `GroupAccessLevel`:

- **FULL** — the user has `reports:view` (via `Memberships::may`, archived and module
  rules included) in **every** member company that is active at any date of the
  requested period (members disposed before the period starts are ignored). This is
  stricter than the spec's "member of every company": membership as approver or payroll
  officer does not show figures elsewhere, so it must not show them here. FULL shows
  per-company columns, tags, pairs, mismatches and drill-down to the company's own ledger
  pages (which then run their own ordinary checks).
- **CONSOLIDATED** — no FULL, but an active `corporate_group_roles` row
  (`corporate_group_id`, `user_id`, `role` = `viewer`, `granted_by`, `granted_at`) **and**
  the user is still a member (any role) of the parent company. Shows only the
  consolidated income statement and balance sheet, elimination totals per type, member
  names and capital shares, and run metadata. Never per-company columns, one company's
  accounts, tags, voucher rows, pair amounts or mismatch details.
- **NONE** — 404 for the page, CSV, PDF, every run and every action, without the group's
  name, as for any record of another tenant. A partial member sees nothing at group
  level: G.1's "N företag visas inte" behaviour does **not** apply to the shared group.

Administration (confirm members, accept eliminations, grant roles) is not a role: it is
derived live as "owner or admin in every member company and the parent". No stored admin
flag can outlive a membership.

The group role never reaches a company: it is not a Jetstream membership, it is not read
by `Memberships`, `CurrentCompany`, `CompanyScope`, policies or the API token binding,
and it grants no permission string. Tests in S2-1.1 prove this (3.1). The grant dialog
states in Swedish that a viewer who also sees the parent's own reports can infer the
subsidiaries' totals; the administrators who grant the role accept that. No policy file
and no tenancy primitive changes.

Audit: group writes are not bookkeeping in any company, but they change what
consolidated figures show (spec Part 2). Each write calls `Audit::record` once **per
affected member company** with `company_id` = that company, subject = the group, and a
payload of ids and enum values that never names another member company. A company's
auditors thus see that their company was added to, removed from or eliminated within a
group, and nothing about the others. Holdings are company data and audited as such.

#### 2.3 Intercompany tags live beside the rows, never in them

Posted `voucher_rows` are immutable at three levels (AGENTS.md §1.2); a tag must not be a
column there and must not need any change to `PostVoucher` or the voucher triggers (hot
spots). Two new company-scoped tables (`BelongsToCompany`):

- `intercompany_counterparties`: `company_id`, `customer_id` null, `supplier_id` null,
  `counterparty_org_number`, `created_by`. Marks a customer or supplier of this company
  as a group company. Again an organisation number, never another tenant's id.
- `intercompany_row_tags`: `company_id`, `voucher_row_id` (FK to `voucher_rows`,
  `restrictOnDelete`; only rows of POSTED vouchers may be tagged),
  `counterparty_org_number`, `kind` (enum `IntercompanyKind`: SALES, PURCHASES,
  RECEIVABLE, PAYABLE, LOAN_RECEIVABLE, LOAN_PAYABLE, INTEREST_INCOME, INTEREST_EXPENSE,
  DIVIDEND_INCOME, DIVIDEND_DECIDED, GROUP_CONTRIBUTION_RECEIVED,
  GROUP_CONTRIBUTION_GIVEN, SHARES, EQUITY), `source` (COUNTERPARTY, ACCOUNT_MARK,
  MANUAL), `tagged_by`, timestamps; unique on `voucher_row_id`.

Tags are classification metadata, not räkenskapsinformation: they may be changed and
removed (each change audited); `ledger:verify` and the voucher hash chain are untouched.
Tags are **materialised by an idempotent action** ("Uppdatera koncernmarkeringar", also
run at the start of every consolidation run): rows of vouchers whose source (`INVOICE`,
`PAYMENT`, `SUPPLIER_INVOICE`) points at a marked customer or supplier are tagged, the
kind following from the account class and direction; manual vouchers (loan, interest,
dividend, group contribution) are tagged by a bulk action "tag rows on account X in
period P with counterparty Y", which takes G.1's account marks as its default. A person
confirms; no rule tags silently. Writing tags needs `vouchers:create` or
`accounts:manage` in that company; reading them needs `vouchers:view` there.

#### 2.4 Consolidation, accepted eliminations and the run log

- **Account map.** `group_account_maps` (`corporate_group_id`, `company_id`,
  `account_number`, `group_account_number`): identity (a BAS number maps to itself) by
  default; rows exist only for deviations.
- **Period.** Any `from`–`to` inside one fiscal year of every included company
  (generalises G.1's rule; a company whose fiscal year does not cover both dates is left
  out with the note G.1 already shows; S2-R4c's cut to the parent's balance date is
  stage 2). A subsidiary contributes income and expenses only from `acquired_on` to
  `disposed_on`, cut by voucher date (vouchers are dated, so a mid-month cut is exact).
  Its balance sheet is included when it is a member on `to`.
- **Engine.** `ConsolidateGroup` reads each company inside `CurrentCompany::run()` with the
  existing `LedgerReports` (no scope bypass, a fixed query count per company, as G.1),
  applies the map, sums, then applies **accepted** eliminations from the group ledger.
  Suggestions never change the consolidated figures.
- **Group ledger.** `group_entries` (`corporate_group_id`, `number` gapless per group,
  `entry_date`, `kind` ELIMINATION | ADJUSTMENT | REVERSAL, `reverses_id`, `reason`,
  `source` JSON with the suggestion's inputs, `accepted_by`, `accepted_at`) and
  `group_entry_rows` (`group_entry_id`, `group_account_number`, `company_id` of the side
  it eliminates, `debit_ore`, `credit_ore`, the same one-sided CHECK as voucher rows).
  Balanced on insert; append-only by a PostgreSQL trigger of its own (not a change to the
  voucher triggers); a mistake is reversed by a new entry. These rows never write into
  any company's books (spec: "separate group ledger") and are not räkenskapsinformation
  of any legal person. The spec's `eliminations` table becomes: suggestions are computed,
  acceptances are `group_entries` of kind ELIMINATION.
- **Run log.** `corporate_group_runs`: `corporate_group_id`, `from`, `to`, `run_by`,
  `created_at`, `inputs` (per company: fiscal year id, count of posted vouchers in the
  year and the `hash` of the last posted voucher in its hash chain; hash of the tag set;
  hash of the map; ids of accepted group entries; label version), `inputs_hash`,
  `output` (JSON figures), `output_hash`, `preliminary` (true when any company's period
  is not locked). The same `inputs_hash` must give the same `output_hash`; a test proves
  it, and a later posted voucher changes `inputs_hash`. Runs are append-only.
- **Label.** Every consolidated screen, print, CSV and PDF says "Intern koncernrapport,
  inte en koncernredovisning enligt ÅRL" (spec scope 5; S2-R14). File names and page
  titles use "intern-koncernrapport" / "Koncernöversikt", never the bare word
  "koncernredovisning", and nothing mentions K3 (K3 1.6). The rule sheet rates the label
  requirement **medium** (derived, no explicit rule) and did not research marketing law:
  the wording goes to the jurist before S2-1.6 merges (H5). The G.1 label is aligned to
  the same wording in S2-1.6.

#### 2.5 What stage 1 still does not do

Unchanged from "Not built (stage 1)" above, except that counterparty marking, row tags,
accepted eliminations in a separate group ledger, a free period with a date cut at
acquisition and disposal, and stored runs are now in scope. Still out: acquisition
method, goodwill, minority interest, internal profit, uniform principles, deferred tax,
currency translation, statutory output of any kind. Shares against equity stays a
suggestion whose difference is shown as "kräver förvärvsanalys (steg 2)".

Rule ids touched by stage 1 (as captured data or guards, not as statutory output):
S2-R1d (holder, capital and voting share, control basis captured), S2-R4a (date cut),
S2-R4b (suggestions only; no materiality judgment), S2-R7 (group contribution pair,
suggestion only, H6), S2-R10 (currency block), S2-R14 (label).

### 3. Stage 1 packages

#### 3.1 First package: S2-1.1 group model and access (Builder S2-A)

Branch `spec/s2/group-model`. One migration set and one action set, no screen.

- **Rule ids:** S2-R1d (data), S2-R10 (block); correction C7.
- **Migrations (additive only):**
  `database/migrations/2026_11_xx_create_company_holdings_table.php`;
  `database/migrations/2026_11_xx_create_corporate_groups_tables.php`
  (`corporate_groups`, `corporate_group_members`, `corporate_group_roles`).
- **Code:** `app/Models/CompanyHolding.php` (`BelongsToCompany`),
  `app/Models/CorporateGroup.php`, `CorporateGroupMember.php`, `CorporateGroupRole.php`;
  `app/Domain/Group/ControlBasis.php`, `GroupRole.php`, `GroupAccessLevel.php`;
  `app/Domain/Group/GroupAccess.php` extended with `levelFor()`, `administers()`,
  `corporateGroups()` (existing methods untouched); actions in `app/Actions/Group/`:
  `RecordHolding`, `CreateCorporateGroup`, `ConfirmGroupMember`, `RemoveGroupMember`,
  `GrantGroupRole`, `RevokeGroupRole` — each transactional, Swedish validation messages,
  `Audit::record` per affected company as in 2.2.
- **Tests first** (`tests/Tenancy/CorporateGroupTenancyTest.php`,
  `tests/Feature/Group/CorporateGroupTest.php`; `DatabaseTransactions`, never
  `RefreshDatabase`):
  1. Partial membership sees nothing: a user with `reports:view` in the parent and one
     subsidiary but not the second gets NONE; the group is missing from their list and
     every route, CSV and action answers 404 without the name; the same for a user whose
     role in one member lacks `reports:view` (approver).
  2. Removing one membership, or changing to a role without `reports:view`, drops FULL to
     NONE at once (no cache).
  3. A group role never grants a single company's ledger: a viewer who is not a member of
     subsidiary B gets CONSOLIDATED; `Memberships::of($viewer)` does not contain B;
     `switchCompany(B)` is refused; B's ledger, report, voucher, CSV and API routes answer
     404 as for any foreign company; an API token cannot be bound to B; the CONSOLIDATED
     payload contains no per-company column, account or row. Leaving the parent removes
     CONSOLIDATED at once.
  4. Creating a group or confirming a member as accountant (not owner/admin) in any one
     company is refused; granting a role likewise.
  5. `CompanyHolding` tenancy: another company's holding id answers 404 (standard suite);
     the table has no column holding another tenant's id.
  6. Refusals: a different base currency; voting shares over 100 % for one org number.
  7. Audit: one entry per affected company; no payload names another member company.
  8. G.1 regression: `tests/Feature/Group/GroupOverviewTest.php` and
     `tests/Tenancy/GroupTenancyTest.php` stay green unchanged.
- **Fixture:** none consumed yet. F-S2-a (`tests/Fixtures/s2/f-s2-a/`, owned by the S2
  fixture author) is read only once it is marked frozen, from S2-1.4 on; the builder
  does not read it before then.
- **Docs:** systemdokumentation unchanged (no accounting behaviour); ADR-060 status line;
  no changelog until a screen ships (S2-1.2).

**S2-1.1 as built (2026-11-07).** Details the addendum left open, decided in the build:

- Migrations are dated `2026_12_16_*` (after the newest existing migration), not `2026_11_xx`.
- `corporate_groups.parent_company_id` is unique: one authoritative group per parent (2.1).
  `company_holdings` has CHECKs for 0–10 000 bp and `disposed_on >= acquired_on`; its only
  foreign key is `company_id`.
- A holder has at most one undisposed holding per organisation number; the organisation
  number must be a legal person's (not a personnummer) and not the holder's own. The audit
  entry keeps shares, basis and dates, and only the *names* of changed fields for the held
  company's name and number.
- "Active" holding = `disposed_on` empty or after the day. `levelFor($group, $from)` ignores
  members whose holding was disposed of before `$from` (strictly before).
- A member's `holding_id` is the active holding naming it among the members' holdings: one
  with a control basis first, then the larger voting share. No rule refuses a holding whose
  basis is NONE; the human-entered basis is stored, not judged (S2-R1d).
- `administers()` = owner or admin, live, in every member and the parent, none archived.
  `ConfirmGroupMember` additionally needs owner/admin in the new company. `RemoveGroupMember`
  is also open to an owner/admin of the leaving company itself (withdrawal of consent without
  seeing the group); the parent cannot be removed, and a company through which other members
  are held only after them. `RevokeGroupRole` is also open to the role holder for their own role.
- Audit (2.2): `GroupAuditTrail` writes one entry per member company (for a removal, the members
  before it), payload `corporate_group_id`, `members` (count), `this_company`, and for roles
  `user_id` and `role`. The group name (free text) is not logged.
- `CompanyScope::bypass` is used twice, each with a comment: reading the members' holdings by
  organisation number in `ConfirmGroupMember` (the user administers every holder), and reading
  disposal dates of member holdings in `levelFor` with a period. G.1 code still never bypasses.
- Not yet testable in S2-1.1 and moved to the package that adds the surface: group routes,
  CSV and PDF answering 404 (S2-1.2/1.6), and the CONSOLIDATED payload without per-company
  data (S2-1.4).

#### 3.2 Remaining stage 1 packages

- **S2-1.2 Koncern → Översikt** (screen): structure tree from holdings, capital/voting,
  acquired/disposed, period status per company, role grants, "Gör om till koncern" from a
  G.1 list; manual article and changelog.
- **S2-1.3 Intercompany counterparties and row tags** (migrations + actions): the tables
  of 2.3, the materialising action, bulk manual tagging, tenancy tests for both tables,
  an immutability test proving no `voucher_rows` change and `ledger:verify` stays green.
- **S2-1.4 Consolidation engine and run log:** account map, `ConsolidateGroup`, period and
  acquisition/disposal date cut, `corporate_group_runs`, reproducibility test,
  fixed-query-count test, F-S2-a gross sums against its `expected.json`.
- **S2-1.5 Eliminations and group ledger:** the six spec pairs plus shares/equity,
  mismatches with both sides and the difference, accept and manual entries into
  `group_entries` with its own append-only trigger, reversals; F-S2-a eliminations net to
  zero; F-S2-c mismatch shown (stage 1 shows it; blocking applies to stage 2 output).
- **S2-1.6 Koncern → Elimineringar and Koncern → Rapporter** (screens, CSV, PDF with the
  label of 2.4), G.1 label aligned, manual, changelog. Public claims only after the
  stage 1 gate and the truth auditor (spec Part 5).

### 4. Stage 2 (headline only)

Gated on: the S1 gate green (spec order; S2-R3: a parent that prepares group accounts
must itself use K3), an accepted stage 2 addendum approved by the human, and the human
decisions named per package.

- **S2-2.1 Duty and framework check** (S2-R1, R1a–R1d, R2, R3; C8): size test over the two
  prior years with both methods, sub-group and immaterial exemptions, exclusion with a
  reason; warns before a K2 parent opts in. Needs prior-year figures and FTE per company.
- **S2-2.2 Acquisition analysis** (S2-R4, R4a, R6 split at acquisition, R8 at
  acquisition; C9): cost, fair values, untaxed reserves split, goodwill or negative
  goodwill, approver, 12-month adjustment log. Blocked by H2 and H4.
- **S2-2.3 Goodwill schedule** (S2-R5): default 5 years linear, residual 0; longer only
  with a stored reason; impairments never reversed.
- **S2-2.4 Non-controlling interests** (S2-R8) in equity and after "Årets resultat".
- **S2-2.5 Full eliminations** (S2-R4b internal profit with human inputs, S2-R6 deferred
  tax on untaxed reserves with dated tax-rate rows, S2-R7 group contributions). Blocked by
  H3 and H6.
- **S2-2.6 Group statements** (S2-R4c balance-date cut, R4d cash flow always (C2), R12
  equity and changes in equity, R13 notes and management report after a follow-up
  research note, R4f/C6 associates check that blocks statutory output when a material
  associate exists). H8 for later balance dates.
- **S2-2.7 PDF with the parent's annual report and a filing checklist** (S2-R9; C10): no
  submission. Blocked by H1 and H7.

**Common control (H2), proposed blocking.** Most NONOX groups are an owner who formed a
holding company and moved the operating company into it: a combination under common
control, outside K3 chapter 19 (S2-R4e; confidence low for the treatment). Until the
accountant decides: `company_holdings.common_control` must be answered (Ja/Nej) before an
acquisition analysis can start, and unanswered counts as Ja. When any active holding in
the group is Ja, every stage 2 output for that group (analysis, goodwill, statutory
statements, PDF) is blocked with a Swedish explanation naming the question; stage 1
reports keep working. Once H2 is decided, the chosen method is recorded here as an
addendum and unlocked behind it; nothing is guessed.

### 5. Human decisions

| Id | Question | Options | Recommendation | Blocks | Deadline |
|---|---|---|---|---|---|
| H1 | Must voluntary group accounts of a smaller group be filed with Bolagsverket? (ÅRL 8:16 p. 1 vs Bolagsverket's guide) | always / only larger groups | Ask Bolagsverket in writing; until then show "kontrollera med Bolagsverket", never auto-file | S2-2.7 checklist | Before S2-2.7 starts |
| H2 | Accounting for combinations under common control (K3 19.1 a; K3 silent) | book values (predecessor basis) / acquisition method by analogy | Accountant decides; block as in §4 until then | S2-2.2 onwards for owner-formed holding groups (most NONOX groups) | 2026-12-15 (before S2-2.2) |
| H3 | Materiality for internal-profit elimination and for excluding subsidiaries (ÅRL 2:3 a) | per-group human judgment / numeric default | Human judgment recorded per period; no numeric default | S2-2.5; exclusions in S2-2.1 | Before S2-2.5 |
| H4 | Is a parent's first group accounts a first-time application of K3 ch. 35 (35.24 relief for old acquisitions)? | yes / no | Accountant decides | S2-2.2 onboarding of existing groups | 2026-12-15 |
| H5 | Wording of the internal label and public texts (K3 1.6, ÅRL 7:6, marketing law); S2-R14 is medium | keep "Intern koncernrapport, inte en koncernredovisning enligt ÅRL" / other | Keep; jurist reviews the label and every public S2 text | S2-1.6 merge; any public claim | 2026-11-21 |
| H6 | Group contributions and appropriations in the group: eliminated, change in untaxed reserves as deferred tax (S2-R7, R6 medium) | confirm / other presentation | Accountant confirms on F-S2-a | F-S2-a `expected.json` freeze (stage 1 suggestion); S2-2.5 | 2026-11-14 |
| H7 | Group audit requirement and the koncernrevisionsberättelse (not researched) | — | Separate research note before stage 2 promises a filing set | S2-2.7 | Before S2-2.7 |
| H8 | Subsidiary balance date up to three months after the parent's (ÅRL 7:10 mentions only "före") | treat as > 3 months / allow own accounts | Default: cut to the parent's date; accountant confirms | S2-2.6 for mixed fiscal years | Before S2-2.6 |
| D1 | Retire G.1's per-user lists once the shared group ships? | keep both / remove after one release with notice and conversion | Remove after one release, with an in-app notice and "Gör om till koncern"; product owner decides | S2-1.2 wording; a later clean-up package | Before S2-1.2 merges |
| D2 | Is one group role, `viewer` (consolidated only, holder must stay a member of the parent), enough? | viewer only / also a board role with PDF | Viewer only in stage 1; revisit with stage 2 | S2-1.1 scope | Before S2-1.1 merges |

Also required by the spec: an accountant reviews F-S2-a/b/c, and the product owner
decides whether foreign subsidiaries are needed (a later ADR).

### 6. Ownership and hot spots

No hot-spot file changes: no policy, no tenancy primitive, no change to `PostVoucher`,
the voucher triggers or `Audit` (calls only). The Lead is asked to add these S2 paths to
`docs/specs/ownership.md`: `app/Actions/Group/**`, `app/Models/CorporateGroup*.php`,
`app/Models/CompanyHolding.php`, `app/Models/Intercompany*.php`,
`app/Models/GroupEntry*.php`, migrations matching `*company_holdings*`,
`*corporate_group*`, `*intercompany*`, `*group_entries*`, `*group_account_maps*`,
`app/Http/Controllers/Group/**`, `resources/views/livewire/group/**`,
`tests/Tenancy/CorporateGroupTenancyTest.php`, `tests/Tenancy/IntercompanyTenancyTest.php`.
Shared files touched for routes and translations (`routes/web.php`, `lang/en.json`) get
append-only edits.
