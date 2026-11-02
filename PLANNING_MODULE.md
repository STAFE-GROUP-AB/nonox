# PLANNING_MODULE.md — NONOX Planering (schema)

> Instructions for agents building the **Planning module** (`planning`), an
> optional module on the module framework (`PAYROLL_MODULE.md` Part A).
> Read `AGENTS.md`, `PAYROLL_MODULE.md` and ADR-037 first. If this file and
> `AGENTS.md` conflict, `AGENTS.md` wins; on payroll, `PAYROLL_MODULE.md` wins.

Language: code and docs in English, UI and URLs in Swedish.

---

## 0. Idea

**Schema + personalliggare = löneunderlag.** The manager plans shifts with
the labour cost visible while planning. Staff check in and out in the staff
ledger as today. NONOX compares plan and actual, the manager attests the
differences, and the approved time becomes the payroll basis: ordinary hours,
OB per OB type from the collective agreement, overtime, mertid and absence. It
goes straight into the payroll run, with no export.

Bookkeeping incumbents have no schedule and no ledger of their own; the
restaurant schedulers have no bookkeeping. NONOX has all of it in one
database. The module uses that: labour cost against the actual sales in the
books, per day.

Research behind this spec: competitor feature map and demand ranking
(2026-11-02), summarised in ADR-037. Clean room (AGENTS.md §1.1): functionality
only, never another product's texts, layout, colours or feature names.

## 1. Principles

1. **The schedule never writes to the staff ledger.** The ledger records
   when work actually starts and ends (skatteförfarandelagen); a plan cannot
   stand in for it. Planning only reads the ledger.
2. **No rule of law is hard-coded as fact.** Working-time limits
   (arbetstidslagen), rules for minors (AFS 2023:2) and agreement rules are
   data with a source, marked `TODO(legal-verify)` until a named person has
   checked them, exactly as payroll rules are (PAYROLL_MODULE.md B.6). The
   checks warn; they never block a plan.
3. **Payroll owns pay.** Planning produces approved time per pay code;
   rates, OB prices and contributions come from the payroll and agreement
   engines. A labour-cost figure in the schedule is labelled an estimate.
4. **Employees first on the phone.** Everything an employee does (see the
   schedule, availability, claim an open shift, ask for a swap, ask for
   leave) works in Min sida on a phone.
5. **Tenancy, audit, no N+1.** Every table has `company_id` and
   `BelongsToCompany`, every change is audited, the week view loads in a
   fixed number of queries.

## 2. Module definition

- Key `planning`, name **Planering**, depends on `payroll` (employees, pay
  codes, absence live there). Uses `staff_ledger` and `agreements` when they
  are on.
- Permissions: `planning:view`, `planning:manage` (plan, publish),
  `planning:attest` (attest time to payroll).
- Roles: owner, admin → all; `payroll_admin` → all; new role `scheduler`
  (**Schemaläggare**) → view + manage; employees see only their own schedule
  in Min sida.
- Navigation: **Planering** → Schema, Tid att attestera, Öppna pass och
  byten, Bemanning och kostnad, Inställningar.
- Turning the module off hides it and stops notifications; nothing is
  deleted.

## 3. Settings (`company_modules.settings` for `planning`)

- Week starts Monday; default view (week/day); time step (15 min).
- Areas (**Avdelningar**, e.g. Kök, Servering, Bar) with colours, optionally
  tied to a staff-ledger location; roles/skills per employee.
- Publish rules: how far ahead the schedule should be published (default 14
  days, as information only); notify on publish and on changes (in-app and
  email).
- Staff self-service: allow open-shift claims, swaps (always with manager
  approval), availability.
- Working-time rule set: the defaults from arbetstidslagen with their
  paragraph as source, editable per company (an agreement may deviate):
  dygnsvila 11 h, veckovila 36 h, max 5 h without a break, 40 h per week
  averaged, overtime and mertid caps. All `TODO(legal-verify)`.
- Minors: on/off, with the limits as editable data, `TODO(legal-verify)`
  against AFS 2023:2.
- Attest tolerance: differences under N minutes (default 5) between plan and
  actual are accepted silently; rounding of check-in times (none by default).
- Sales source for labour cost %: revenue accounts (default 3000–3799) from
  the books, per day.

## 4. Data model

```
planning_areas        id, company_id, name, colour, location_id null, sort, archived_at
planning_skills       id, company_id, name
planning_employee_skills  employee_id, skill_id
planning_shifts       id (uuid), company_id, employee_id null (null = open shift), area_id null,
                      skill_id null, starts_at, ends_at (timestamptz, Europe/Stockholm),
                      break_minutes, note, status (DRAFT | PUBLISHED), published_at, published_by,
                      source (MANUAL | TEMPLATE | COPY | SWAP | CLAIM), template_id null,
                      cost_estimate_ore null, cost_calculated_at null, created_by, updated_by
planning_templates    id, company_id, name, weeks (1–8), notes
planning_template_shifts  template_id, week_index, weekday, start, end, break_minutes,
                      employee_id null, area_id null, skill_id null
planning_availability id, company_id, employee_id, date or weekday (recurring), from, to,
                      kind (AVAILABLE | UNAVAILABLE | PREFERRED), note
planning_requests     id, company_id, kind (CLAIM | SWAP | DROP), shift_id, requested_by (employee),
                      counterpart_employee_id null, counterpart_shift_id null,
                      status (PENDING | APPROVED | REJECTED | WITHDRAWN), decided_by, decided_at, note
planning_publications id, company_id, from, to, published_by, published_at, shift_count, notified_count
planning_attestations id, company_id, employee_id, date, planned_minutes, actual_minutes,
                      basis jsonb (minutes per pay code), deviations jsonb, status (OPEN | ATTESTED | SENT),
                      attested_by, attested_at, time_report_ids jsonb
planning_ical_tokens  employee_id, token_hash, created_at, revoked_at
```
Indexes: `(company_id, starts_at)`, `(company_id, employee_id, starts_at)`.
A published shift that is changed keeps its history in the audit log.

## 5. Domain services (`app/Modules/Planning/Domain`)

- `SwedishHolidays` (shared, `app/Domain/Calendar`): public holidays for a
  year computed from the rules of lagen (1989:253) om allmänna helgdagar
  (Easter by the computus, Midsommar, Alla helgons dag), plus the eves
  agreements use (julafton, nyårsafton, midsommarafton, påskafton).
  `TODO(legal-verify)` note on the source; unit-tested for 2024–2030.
- `ObSplitter`: splits an interval into minutes per OB code from an
  agreement version's `ob_windows` and `day_types` (both already in the
  agreement data), using `SwedishHolidays`. Pure; heavily unit-tested
  (midnight, DST change, storhelg, overlapping windows → highest wins only if
  the agreement says so, otherwise per data).
- `ShiftCost`: estimate per shift = hours × hourly rate (monthly salary →
  hourly by the employment's weekly hours) + OB minutes × the agreement's OB
  price + vacation pay and employer contributions **only from verified
  payroll rules**; otherwise the figure says which part is missing.
- `WorkingTimeChecks`: runs the company's rule set over an employee's
  shifts (plan, or plan + actual): dygnsvila, veckovila, break, weekly hours,
  overtime/mertid caps, minors' times. Returns warnings with the rule's
  source. Never blocks.
- `PlanVsActual`: matches staff-ledger shifts (LedgerQuery) to planned
  shifts per employee and day; classifies: on time, early/late start,
  early/late finish, missing check-out, unplanned work, no-show.
- `PayrollBasis`: from attested days: ordinary minutes, OB per code,
  overtime/mertid beyond the planned or contracted time (rule from the
  agreement or settings), absence (from payroll absences).

## 6. Actions (`app/Modules/Planning/Actions`)

SaveArea, SaveSkill, SaveShift (create/move/resize/assign; drag and drop
calls it), DeleteShift (drafts only; a published shift is cancelled and the
employee notified), CopyPeriod (week → weeks, with or without people),
ApplyTemplate, SaveTemplate (incl. "save this week as template"),
PublishPeriod (status, publication row, notifications), SaveAvailability,
RequestShift (claim/swap/drop), DecideRequest (approve re-runs the checks),
AttestDay / AttestPeriod (writes approved `TimeReport` rows per pay code for
the payroll run; replaces the staff ledger's "Föreslå tidrapport" when the
module is on), ReopenAttestation (only before the payroll run is attested),
IssueIcalToken / RevokeIcalToken.

Every action: authorized by policy, transactional, audited, tenancy-safe.

## 7. UI

- **Schema** (`/planering`): week grid, people as rows (or areas), days as
  columns; drag to create, move and resize; shift chips coloured by area;
  open shifts row; per-day footer with planned hours, estimated labour cost,
  sales the same weekday (from the books) and labour cost %. Toggle day view
  (timeline). Filters: area, skill, location. Unpublished changes are
  marked; one **Publicera** button for the period with a summary of who will
  be notified. Warnings from `WorkingTimeChecks` as badges on the shift.
  Keyboard: arrows to move focus, Enter to edit, Delete to remove a draft, `c`
  copy, `v` paste. Works on a tablet; read-only list on a phone.
- **Mallar**: templates; apply to weeks.
- **Öppna pass och byten**: requests to decide, with the checks re-run.
- **Tid att attestera** (`/planering/attest`): per period, per person per
  day: plan, actual, difference, OB split, warnings; attest a row, a person
  or all rows without deviations with one click; then "Skicka till lönen".
- **Bemanning och kostnad**: per day and week, planned and actual hours,
  labour cost, sales from the books and labour cost %; compared with the same
  weeks last year when the books have them.
- **Min sida** (employee): my schedule (week list, next shift on the start
  page), open shifts to claim, swap or drop a shift, availability, iCal
  link, and the existing check-in.
- Overview todos: next period not published, open requests, days to attest
  before the next payroll run.

## 8. Payroll integration

- Attested time becomes `TimeReport` rows with the hourly pay code, the OB
  pay codes from the agreement (or company pay codes for OB when no
  agreement), overtime/mertid codes, in status approved — the same path as
  today's ledger proposals and PAXml/CSV imports (B.16a), so the payroll run
  picks them up unchanged.
- Monthly-paid staff: only deviations become pay lines (OB, overtime,
  unpaid absence); planned ordinary time is not paid twice.
- A day sent to payroll is locked in Planning; changes after that need
  ReopenAttestation before the run is attested, or a correction line in the
  next run.

## 9. API and integrations

- Connect API (read): shifts and published schedule per period; webhooks
  `planning.published`, `planning.attested`.
- iCal feed per employee (secret, revocable URL).
- Hourly POS sales: later; daily sales come from the books now.

## 10. Tests

Unit: SwedishHolidays, ObSplitter (agreement fixtures), ShiftCost,
WorkingTimeChecks, PlanVsActual, PayrollBasis. Feature: every action
(happy path + each rule + policy per role), publishing and notifications,
requests flow, attest → time reports → payroll run picks them up. Tenancy
suite for every table. Query count for the week view (fixed). Livewire
tests for the grid interactions. Never `RefreshDatabase`.

## 11. Out of scope for the first version

Automatic scheduling (AI), forecasting beyond "same weekday last weeks",
hourly POS integration, tips distribution, chat/messaging, SMS.

## 12. Tasks (in order)

1. Module skeleton, settings, permissions, areas/skills, `SwedishHolidays`,
   `ObSplitter` with tests.
2. Shifts, templates, copy, publish, week grid UI, cost estimate, checks.
3. Min sida: schedule, availability, requests, iCal; notifications.
4. Plan vs actual, attest, payroll basis → time reports; staff ledger link.
5. Staffing and cost page (sales from the books, labour cost %).
6. Demo data (IndustryDemoSeeder extra), manual section `planering`,
   public pages (Features, landing, industry pages: remove the "OB räknas
   ännu inte från passens klockslag" limit when 2 + 4 are done), README,
   systemdokumentation, ADR-037 status.
