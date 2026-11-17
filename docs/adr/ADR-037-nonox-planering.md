# ADR-037: NONOX Planering (staff scheduling)

- Status: **Accepted**; amended 2026-11-02 and 2026-11-17 (best in class)
- Date: 2026-11-02
- Spec: `PLANNING_MODULE.md`
- Builds on: ADR-017 (module framework), ADR-012 (payroll), ADR-022 (staff
  ledger), ADR-033 (NONOX Avtal)

## Context

Restaurants, cafés, shops and salons plan their staff week by week, check
them in and out in a personalliggare (required in restaurants, salons and
construction, not in retail) and pay them by the hour with OB and overtime
from a collective agreement. Today NONOX has the ledger, the payroll and the
agreements, but no schedule, and OB hours are entered by hand or imported
from another scheduling system. The industry pages say so.

A review of the scheduling products on the Swedish market and the schedule
offer of the large bookkeeping programs (2026-11-02) found:

- every scheduling product has a shift grid with templates, check-in tied to
  the shift, plan-versus-actual attest and a payroll basis; most show labour
  cost while planning, labour cost as a share of sales, rest-time warnings,
  open shifts, swaps, availability and an employee app;
- the most common complaint is the hand-off to payroll (re-entering hours);
  others are heavy setup, weak mobile apps and thin reporting;
- the bookkeeping programs have no schedule and no ledger of their own and
  rely on partner apps; the restaurant systems have no bookkeeping. No
  product has bookkeeping, payroll, ledger and schedule in one database.

## Decision

Build Planering as an optional module (`planning`) that depends on Lön, reads
the staff ledger and Avtal when they are on, and never writes to the ledger.
Plan versus actual is attested in Planering and becomes approved time reports
per pay code, including OB split by the agreement's OB windows and a Swedish
holiday calendar, so the payroll run takes them as it takes imported time
today. Labour cost is estimated per shift and compared with the actual sales
in the books per day.

Working-time and minors' rules are data with a source and `TODO(legal-verify)`,
and only warn. Functionality is replicated, never another product's texts,
layout or names (AGENTS.md §1.1).

## Consequences

- One flow from plan to payslip with no export; the industry pages' limit
  "OB räknas ännu inte från passens klockslag" can be removed when the OB
  split and attest ship.
- A shared `SwedishHolidays` calendar also fixes the payroll settings that
  today ignore public holidays.
- The scheduler role and Min sida grow; Min sida must stay fast on phones.
- Out of scope for now: automatic scheduling, forecasting beyond history,
  hourly POS sales, tips, chat and SMS. (Partly reversed by the amendment
  2026-11-17 below; tips, SMS and fully automatic publishing stay out.)

## Amendment 2026-11-02: overtime and mertid (fix-overtid)

Overtime and mertid are measured per calculation period by `OvertimeTerms`
and `PayrollBasis::period`: a PERIOD method (worked time plus leave on
scheduled days, arbetstidslagen 7 § andra stycket, against the contracted
time) or a DAILY method selected by the agreement data (Visita–Unionen,
Handelns tjänstemannaavtal). The agreement's rounding is applied per day or
occasion, never to the period total. Without a collective agreement the
period is at most four weeks (5 § andra stycket), whatever the setting.
Hourly-paid staff get the agreement's overtime and mertid supplement on
supplement-only codes when the agreement gives it (Gröna Riks,
Detaljhandeln); their hourly pay is unchanged. Enligt AI-utredning
2026-11-02, juristgranskning återstår.

## Amendment 2026-11-17: best in class

Status: **Accepted (design)**. No feature code ships with this amendment; it
is built in the packages P1–P10 at the end, in that dependency order.

### Context

The owner decided on 2026-11-17 that Planering shall be the best staff
scheduling for Swedish small and medium-sized companies, built now (queue
item 25 in `docs/status/nummer-1.md`). Measured against the leading
scheduling tools, eight things are missing. The first version put automatic
scheduling, forecasting, hourly till sales and chat out of scope
(Consequences above, `PLANNING_MODULE.md` §11). This amendment reverses
that, gap by gap, and says what still stays out.

What the decisions build on: posted sales per day in the books
(`SalesFromBooks`), till receipts with a timestamp (`pos_sales.sold_at`,
ADR-042), the labour-cost estimate per shift (`ShiftCost`, `ShiftCoster`),
the working-time checks (`WorkingTimeChecks`), skills and availability,
approved absence in payroll (`absences`, already read by `AttestData`), cost
centres on voucher rows and on the employment (ADR-009, `SalaryVoucher`), the
firm workspace's cross-company pattern (ADR-028) and Min sida.

### Principles that apply to every gap

1. **A person decides.** Nothing here publishes, changes a published shift,
   approves a request or sends anything to payroll on its own. A proposal
   becomes draft shifts; the scheduler reviews and publishes.
2. **Warn, never block**, as in the first version: budget, demand, absence
   and working-time findings are warnings with their source. The one
   exception is the proposal engine, which never *proposes* an assignment
   that breaks a hard constraint (gap 3); a person may still plan it by hand
   and sees the warning.
3. **Honest granularity.** A figure is shown only at the resolution its
   source has. Vouchers have a date, not a time, so sales from the books are
   per day. Per hour exists only where a till integration supplies receipt
   times, or where the company has entered its own hour profile, which is
   labelled as its own estimate.
4. **No AI needed.** Forecasts and proposals are deterministic, explainable
   heuristics: the same input gives the same output, and every proposed
   assignment carries its reasons. NONOX AI (ADR-018) is optional and off by
   default; when a company has it on, it may at most phrase the summary of a
   proposal in plain Swedish from the engine's structured reasons. It never
   chooses, ranks or sees anything the engine did not already output, and
   the screen works identically without it.
5. **Tenancy.** Every new table has `company_id` → `teams`, uses
   `BelongsToCompany`, gets a tenancy test (another company's id → 404) and
   a decision in `DatabaseGrants`. Migrations are additive only (new tables,
   new nullable columns, new indexes; no type changes, no drops). The only
   cross-company read is the chain view (gap 8), built on ADR-028's pattern.
6. **No N+1.** Every new screen and every engine run loads its data in a
   fixed number of queries, independent of the number of employees, shifts,
   days or companies; each package proves it with a query-count test with a
   short and a long history (QUICKSTART §6).
7. **Audit log** entries hold ids, enums and counts only, never message
   text, task text or absence types.

### Gap 1: staffing demand from sales, with a staffing target

**Decision.** A forecast of sales per day, and per hour where the data
supports it, turned into a required staffing level per hour (or staff-hours
per day) and area, shown against the planned staffing.

*Sources, by resolution:*

- **Books (always).** Net sales per day from posted vouchers on the sales
  accounts (`SalesFromBooks`, unchanged). Per area when the area has a cost
  centre (gap 7) and the revenue rows carry it; otherwise per company. Per
  day is the finest honest resolution: `vouchers.voucher_date` is a date.
- **Tills (when connected, ADR-042).** `pos_sales` grouped by hour of
  `sold_at` in Europe/Stockholm, net of VAT (`total_ore − vat_ore`), refunds
  negative, tips excluded, one grouped query. A till connection is mapped to
  an area or to the whole company in `planning_sales_sources`. Till data
  gives the *shape* of the day (share per hour); the daily total shown as
  "bokfört" still comes from the books.
- **The company's own hour profile (fallback).** Share of the day's sales
  per hour and weekday, entered by the user (`planning_hour_profiles`),
  shown as "Egen uppskattning". Without it and without a till, demand is per
  day only and the hour view says so. Days with 23 or 25 hours (DST) are
  bucketed in local time.

*Forecast (deterministic).* For a future date: the average of the same
weekday in the last N weeks (default 6, setting `forecast_weeks`), skipping
public holidays and eves (`SwedishHolidays`) and days with no sales. A public
holiday is forecast from the same holiday last year when the books have it,
else it has no forecast. No trend, no seasonality model, no machine learning:
a figure anyone can recompute by hand, and the screen shows which days it
averaged. A person can override a day (`planning_forecast_overrides`, with a
reason, e.g. an event nearby); an override is shown as such.

*Staffing target (`planning_staffing_rules`).* Per area (or company),
weekday and time window, valid from a date:

- `sales_per_staff_hour_ore`: the sales one staffed hour should carry; and/or
- `min_staff`: a floor (opening, safety, two at closing).

Required staff in an hour = max(`min_staff`, ceil(forecast sales in the hour
÷ `sales_per_staff_hour_ore`)). With daily data only, required staff-hours
per day = ceil(forecast ÷ target). The day view shows required against
planned per hour as a band; the week view shows staff-hours per day; under-
and over-staffing are warnings.

### Gap 2: staff-cost budget per week and area

**Decision.** `planning_budgets`: per company, area (null = whole company)
and ISO week (`week_start` date, a Monday), either a fixed amount
(`amount_ore`) or a share of the week's forecast sales (`percent_bp`, needs
gap 1). Entered on a budget page, copied forward, or filled for a period from
one amount or one percentage. NONOX has no ledger budget today; if one comes
(`#PBUDGET`), it may become a second source by a later amendment.

*While planning:* the week footer shows the estimated cost (the sum of the
existing `cost_estimate_ore`, same rules: only verified payroll rules,
missing parts named) against the budget per area and in total, with a warning
at a threshold (setting `budget_warn_at_bp`, default 9 500 = 95 %) and at
100 %. Saving or publishing shifts that cross the budget shows the warning in
the toast; it never blocks. The figure stays labelled an estimate.

*Who:* budgets are set with `planning:budget` (new; owner, admin,
payroll_admin). The scheduler sees the budget and the warnings
(`planning:view`) but cannot change it.

### Gap 3: a schedule proposal

**Decision.** A deterministic, explainable constraint heuristic
(`App\Modules\Planning\Domain\Proposal\ProposalEngine`, pure), run on demand
for one company and a period of at most four weeks. Semi-automatic first:

- **v1 (P7)** fills shifts that already exist without a person: open shifts,
  a template applied without people, or a copied week with people removed.
  The planner decides *which* shifts exist; the engine proposes *who*.
- **v2 (P8)** may also propose *which* shifts: it turns the gap between
  required and planned staffing (gap 1) into shift blocks from the company's
  allowed shift lengths, within the budget (gap 2).

*Hard constraints* (never proposed; the slot stays open with the reason):
availability `UNAVAILABLE` on any day the shift touches; approved absence on
the day (gap 6, any extent);
a missing required skill; overlap with another shift; every rule in the
company's working-time rule set (daily rest, weekly rest, weekly hours, the
four-week overtime cap) and the minors' rules, evaluated by
`WorkingTimeChecks` on the employee's shifts including the proposal so far.
The rules are unverified data (`TODO(legal-verify)` as today), so the engine
applies them conservatively: a proposal is never made where a hard rule
warning exists after the addition in the scope the shift affects (its own
warnings, its week for the week rules, its week and the three after it for
the four-week overtime cap, the next shift's daily rest, the minors' hours on
its day), even when that warning was already there. The one exception is
mertid (`extra_time_week`), which is information about pay rather than a
limit: an accepted proposal can add a mertid notice, shown as the reason
"Ger mertid" and scored last. The yearly mertid and overtime caps (200 h)
need worked time and are not checked on a plan.

*Soft criteria* (a score, lower is better; the weights are company settings
shown as "Vad väger mest", defaults in brackets):

- **Fairness** (high). Per employee, over the last 8 weeks plus the period
  (setting `fairness_weeks`), two ratios: (a) planned hours ÷ contracted
  hours (the employment's weekly hours × weeks); (b) unsocial minutes ÷
  planned minutes, where unsocial means the minutes `ObSplitter` puts in an
  OB window of the employee's agreement, or, without an agreement, the
  company's own definition (default weekdays 19–07, weekends, public
  holidays). The cost of a candidate is how far the assignment moves them
  away from the mean of the employees eligible for the same area and skill.
  Hourly staff without contracted hours count only in (b). Fairness is
  measured inside one company only.
- **Contracted hours** (high): employees below their contracted hours for
  the period first; mertid and overtime last.
- **Preference** (medium): `PREFERRED` availability lowers the score; no
  stated availability is neutral.
- **Continuity** (low): the area the employee worked most in the window.
- **Extra cost** (low): only the *marginal* overtime, mertid or supplement
  the assignment would cause. **Never the base wage**: preferring the
  cheapest person would systematically favour young staff on youth rates,
  an indirect discrimination risk. `TODO(legal-verify)`:
  diskrimineringslagen (2008:567) 1 kap. 4 § 2 on cost-based selection.

*Algorithm.* Load everything once (employees with employment, skills,
availability, approved absence, shifts in the fairness window and the
period, agreements, rules), in a fixed number of queries. Order the slots
most-constrained first (fewest eligible candidates, then earliest start);
assign the lowest-scoring eligible candidate; ties broken by employee id.
Then one bounded improvement pass (at most 2 000 pairwise swaps) that keeps a
swap only if it lowers the total score and breaks no hard constraint. Pure
PHP, no solver package. Target, measured in the package: a week with 40
employees and 300 slots in under two seconds on the production server.

*Output.* A `planning_proposals` row (period, parameters, user, counts,
engine version) and one `planning_proposal_items` row per slot: the proposed
employee or none, the score parts and the reasons as structured codes ("under
avtalad tid", "färre kvällspass än snittet", "önskar arbeta", "ingen ledig
med kompetensen Kassa"). The review page `/planering/forslag` shows each slot
with its reasons; the planner accepts all, some or none. Accepting writes
draft shifts (`source` `PROPOSAL`) through `SaveShift`, so every existing
check and the audit apply; nothing is published. A proposal is stale when any
shift in its period changed after it was made; accepting a stale proposal is
refused with "Schemat har ändrats sedan förslaget gjordes. Gör ett nytt
förslag." Proposals are deleted after 90 days.

*As built (P7, v1):* `Domain/Proposal/ProposalEngine` with `ProposalLoader`
(a fixed number of queries; it never reads a wage), `MakeProposal`,
`AcceptProposal`, `ProposalPage` and `planning:prune-proposals` (nightly).
Where the build had to choose:

- Unsocial time is the company's own definition for everyone
  (`UnsocialTime`, settings `unsocial_from`/`unsocial_to`, default weekdays
  19–07, weekends and public holidays in full), also for staff on an
  agreement: the share is then comparable between people on different
  agreements, and `ObSplitter` sums cumulative OB codes, which would count a
  minute twice. Agreement OB windows can be added later as an option.
- Mertid (`extra_time_week`) is the one rule warning that does not block: it
  is information about pay, not a limit. It is shown as the reason "Ger
  mertid" and scored by "Undvik mertid" (the extra-cost criterion, which
  counts only mertid: overtime over the ordinary week is a hard constraint,
  and OB is the same whoever works the shift). Every other rule warning is
  hard in the scope above: a week rule gives at most one warning per week,
  so a rise in the count is not enough (a 50-hour week would otherwise take
  more).
- "Kan inte" is checked on every day the shift touches, against that day's
  times; employment must cover every day too.
- Approved absence in the fairness window (weekdays, a part day as a half,
  from `AbsenceDays`) is taken off the contracted hours (weekly hours over
  five per day), so someone back from leave is not put first.
- The loader reads only the employment's dates and weekly hours: no pay
  column is selected at all.
- Week rules are checked in every week the shift touches: a shift over
  Sunday midnight takes rest and hours from the next week too.
- Accepting takes a transaction-scoped advisory lock per company, locks the
  proposal and its shifts, then re-checks, so two proposals for the same
  person cannot be accepted at once.
- Each soft part is in [-1, 1] before weighting (levels Mycket 3, Något 2,
  Lite 1, Inte alls 0). Fairness is the distance from the mean of those who
  could take the shift, relative to the mean; the unsocial part counts by
  the shift's own share of unsocial time. A swap is kept when the two
  shifts' summed scores fall.
- Accepting re-checks every hard constraint on freshly loaded data
  (absence, availability, skills and rules may have changed outside the
  period), besides the period's `updated_at`; a proposal is accepted once.
- The page shows reasons only, never scores, so nothing reads as a ranking
  of employees; the score parts are stored for the 90 days as the
  explanation.

### Gap 4: tasks in shifts

**Decision.**

- `planning_task_templates`: title, optional instructions, area and/or
  skill, weekdays (ISO list), optional time of day, valid from/to, active.
- `planning_shift_tasks`: an instance on a shift (uuid FK, cascade): title and
  instructions copied from the template or written for that shift, optional
  due time, `done_at`, `done_by` (user), position.

Recurring tasks are put on shifts when a shift is saved or a period is
published: every active template whose area/skill and weekday match the shift
and whose time falls inside it, in one grouped query per publish. A template
change applies to unpublished shifts and to future published shifts without a
ticked task. The employee on the shift ticks tasks off in Min sida (Mitt
schema), the planner on the schedule; a tick can be undone the same day.
The fields say that task text must not hold personal data.

Tasks are a work aid, not a statutory record: they are not presented as
egenkontroll under the food legislation or as part of the staff ledger.
`TODO(legal-verify)` before any such claim.

### Gap 5: messages to staff tied to the schedule

**Decision.** Persistent, company-scoped schedule messages, **not** the
encrypted chat. ADR-045's chat is deliberately ephemeral, between exactly two
users, in no company, with no database row and no audit entry. A schedule
message must be kept for the period it concerns, be addressed to the right
employees of one company and show who has read it; stretching the chat would
break every invariant ADR-045 promises. The two complement each other
instead of duplicating: a schedule message has no reply thread, and whoever
needs a conversation starts the existing chat from the Chatt button.

- `planning_messages`: author, scope (`SHIFT`, `DAY`, `PERIOD`, `AREA`),
  `shift_id`/`area_id` as the scope needs, `date_from`/`date_to`, `body`
  (at most 2 000 characters, `encrypted` cast), `requires_ack`.
- `planning_message_recipients`: one row per employee, frozen when sent (a
  later schedule change does not widen the audience), `notified_at`,
  `read_at`, `acknowledged_at`.

Recipients are the employees with a published shift in the scope, or all
employees of the area. Delivery uses the existing notifier channels: Notiser
in NONOX, and an e-mail that says only that there is a new message about the
schedule, with a link, never the body, so no message text leaves NONOX by
e-mail. Employees without a login cannot read a message and are counted as
"nås inte", as notices are today. The planner sees read and acknowledged per
recipient; an employee sees only their own messages, never the recipient
list. The compose field says "Skriv inte om hälsa, sjukdom eller andra
känsliga uppgifter här."

*Retention:* a message and its receipts are deleted 12 months after its
`date_to` (`planning:prune-messages`, daily); the period is a proposed
default and the owner's decision (open questions). Messages are part of the
register extract for author and recipients and are listed in
`docs/gdpr/registerforteckning.md` and `lagringstider.md`.

### Gap 6: approved vacation and absence in the schedule

**Decision.** The schedule reads `absences` for the visible period in one
query that selects only `employee_id`, `date_from`, `date_to`, `extent_bp`
and `status`, **never `type`, `note` or `doctor_certificate_received`**. The
absence type is health data for sick leave and is readable only with
`payroll:view-sensitive` under payroll's audited read (PAYROLL_MODULE.md
B.15); Planering never asks for it, also not for vacation.

- On the schedule (`planning:view`): an approved absence shows as
  **Frånvarande** across the day (**Delvis frånvarande** when `extent_bp`
  < 10 000), with no cause. A requested absence shows as **Frånvaro
  begärd**, to holders of `planning:manage` only.
- A shift on a day with approved absence gets the warning "Personen är
  frånvarande den här dagen." on save, publish, request decision and in the
  proposal engine (hard constraint). A newly approved absence on a day with
  published shifts appears in Planering's to-dos ("Pass hos frånvarande
  personal") so the planner can reassign them.
- Colleagues see nothing: Min sida shows an employee only their own schedule
  and absence (unchanged); open shifts and swap partners never carry an
  absence marker.
- The attest logic that already reads absences is unchanged.

`TODO(legal-verify)`: whether "frånvarande" derived from a sick or VAB
report is itself health data (GDPR art. 9) in the planner's view; whether
"Frånvaro begärd" (employees' own sick and VAB requests, up to seven days
ahead) and "Delvis frånvarande" are, and whether a pattern of such marks
lets a scheduler infer a cause; and the legal basis for showing any of them
to a scheduler without `payroll:view-sensitive` (proposed: art. 9.2 b with
dataskyddslagen (2018:218) 3 kap. 2 §, limited to "absent").

*As built (P1):* the query also selects `hours` (scheduled hours missed per
day, not health data), because payroll's paths set only the hours, not the
extent: part of a day is an `extent_bp` below 10 000, else the hours against
the employment's hours per working day (weekly hours over five, else 8). The
warning also covers the day after the visible period (a shift running past
midnight), the to-do looks eight weeks ahead for the shifts of people with
approved absence only, and the audit log of a request decision holds rule
codes, never warning texts.

### Gap 7: shifts on cost centres, carried to the payroll voucher

**Decision.**

- `planning_areas.cost_center_id` (nullable): the area's default.
- `planning_shifts.cost_center_id` (nullable): taken from the area when the
  shift is saved, changeable per shift; null means the employment's cost
  centre (today's behaviour). Input by code, looked up in the company
  (ADR-009); an inactive code is refused for new shifts.
- `time_reports.cost_center_id` (nullable): `SendToPayroll` writes one time
  report per date, pay code *and* cost centre; the run carries it to
  `payslip_lines.cost_center_id` (nullable) when it prices the time reports.
- `SalaryVoucher::rows` uses the line's cost centre, else the employment's.
  Lines without a time report (monthly salary, benefits, absence deductions)
  keep the employment's cost centre, as today. The voucher still summarises
  per account and cost centre (GDPR art. 5.1 c, unchanged).
- Employer contributions on the run are split over the cost centres in
  proportion to the gross amounts per cost centre, largest remainder to the
  öre, so the voucher balances. This changes accounting behaviour:
  `docs/systemdokumentation/README.md` gets a new version, and an accounting
  check reviews it before merge. Proportional versus "the employment's cost
  centre" is the owner's choice (open questions); proportional is
  recommended.

With areas on cost centres, sales from the books can be read per area
(gap 1), and labour cost % per area becomes real.

### Gap 8: one schedule across companies and units

**Decision.** Units inside one company are areas, optionally tied to a
staff-ledger location; they get a unit filter and per-unit totals. Across
companies there is **no chain entity and no shared data**: a read-only chain
view built exactly like the firm workspace (ADR-028 §2).

- `/mina-foretag/schema`: the week across the companies where the user holds
  `planning:view`, chosen from their own memberships, the selection saved in
  the existing `saved_views` (per user, page `planning-chain`, re-checked
  against the memberships on every read). The reads run in
  `CompanyScope::bypass` with that comment, one grouped query per table
  across the ids, with the permission checked per company (the budget column
  only where the user may see it). Measured: a bounded number of queries for
  1 and 25 companies.
- Every change happens in one company: a click opens the shift in its
  company via `current-team.update` (audited), as firm tasks do.
- The same person in two companies is recognised only through the same login
  (`employees.user_id`) in both, and only when the viewer has `planning:view`
  in both; then overlap and daily-rest warnings span the companies. A viewer
  without access to the other company learns nothing about it, not even that
  a conflict exists. The employee sees all their own shifts across companies
  in Min sida, being a member of each.
- **Postponed, with reasons:** a shared staff pool, lending staff between
  companies, cross-company proposals and one consolidated budget. Each
  company is a separate employer: employment, payroll, AGI and working-time
  accounting are per employer, and a pool would need either cross-company
  writes (against AGENTS.md §1.3) or a new legal construction. A later ADR if
  chains ask for it. `TODO(legal-verify)`: how arbetstidslagen's limits apply
  to one person with several employers in the same group. `TODO(legal-verify)`:
  whether reading one employee's shifts across companies for the conflict
  check is compatible with the purpose they were collected for (GDPR art.
  6.4), and what art. 13 information employees with one login in several
  companies must get.

### Permissions

| Permission | New | owner | admin | payroll_admin | scheduler | employee |
|---|---|---|---|---|---|---|
| `planning:view` (schedule, demand, budget figures, chain view per company) | no | ✓ | ✓ | ✓ | ✓ | |
| `planning:manage` (plan, proposals, tasks, messages, staffing rules, forecast overrides) | no | ✓ | ✓ | ✓ | ✓ | |
| `planning:budget` (set budgets) | **yes** | ✓ | ✓ | ✓ | | |
| `planning:attest` | no | ✓ | ✓ | ✓ | | |
| `self:schedule` (own schedule, tick own tasks, read own messages) | no | ✓ | ✓ | ✓ | ✓ | ✓ |

No role gains `payroll:view-sensitive` through Planering. The new permission
exists only while the module is on (ADR-017), and `roller-och-behorigheter.md`
in the manual is updated with it.

### New tables and columns (all additive)

`planning_sales_sources`, `planning_hour_profiles`,
`planning_forecast_overrides`, `planning_staffing_rules`, `planning_budgets`,
`planning_proposals`, `planning_proposal_items`, `planning_task_templates`,
`planning_shift_tasks`, `planning_messages`, `planning_message_recipients`;
nullable `cost_center_id` on `planning_areas`, `planning_shifts`,
`time_reports` and `payslip_lines`; `PROPOSAL` added to `Shift::SOURCES`
(the column has no CHECK). Each table with `company_id`, `BelongsToCompany`,
indexes leading with `company_id`, a tenancy test and a `DatabaseGrants`
decision.

### Legal items (`TODO(legal-verify)`)

- Arbetstidslagen (1982:673): the rule values the engine applies (as today),
  and notice of schedule changes (12 §): a change to a published shift less
  than 14 days ahead gets a warning, as rule data with its source.
- MBL 11 §: whether introducing schedule proposals, staffing targets or task
  lists is a major change that an employer bound by a collective agreement
  must negotiate first. The manual says so in plain text; NONOX decides
  nothing.
- Health data: absence shown only as "Frånvarande" (gap 6); the message
  field warns against health content.
- Monitoring: tick-offs and read receipts are personal data about work; they
  are kept only as long as the shift's or message's retention, used for
  nothing else, and never aggregated into a ranking of employees. Sales per
  employee are not built.
- Diskrimineringslagen: cost is never a criterion between people (gap 3).
- Minors (AFS 2023:2): the minors' rules are hard constraints in proposals.

### What stays out

Fully automatic scheduling or publishing; AI choosing people; sales per
employee; tips; SMS; weather or event feeds; booking-system integrations; a
shared staff pool across companies; group chat between employees.

### Lead decisions on the open questions (2026-11-17, under the owner's mandate)

The owner asked for the package to be built now; Lead chose the recommended
options so the build is not blocked. The owner may overturn any of them
before P10.

- Schedule messages are kept 12 months after the last day the message
  concerns, then pruned.
- Employer contributions are split over cost centres in proportion to gross
  pay.
- Chains get the read-only chain view (P9). A shared staff pool and lending
  between companies stay out and need their own ADR and legal review.
- The competitor names in this ADR's original context are replaced by a
  neutral description.

### Build packages

Each package ships alone: migration(s), actions, policy, tests (feature,
tenancy, query count; immutability untouched), Livewire UI, the manual
article(s) in `docs/manual/sv/planering/` with real screenshots, a changelog
entry, `lang/en.json`, `app/Support/Landing/Features.php` and the landing
card when it is user-visible, README, `PLANNING_MODULE.md`, and demo data
(`IndustryDemoSeeder` extra) so the screenshots are real. Sized for one
builder agent each.

| # | Package | Gap | Depends on | Main files (conflict surface) |
|---|---|---|---|---|
| P1 | Absence in the schedule: "Frånvarande" band, warnings on save, publish and decide, to-do for shifts of absent staff | 6 | — | `ScheduleData`, schedule view, `SaveShift`, `PublishPeriod`, `DecideRequest`, `PlanningTodos`, new `Support/AbsenceDays` |
| P2 | Cost centres: area and shift cost centre, time reports and payslip lines per cost centre, proportional contributions in `SalaryVoucher`, systemdokumentation version | 7 | — | `SetupPage`, `SaveArea`, shift form, `SendToPayroll`, payroll pricing, `SalaryVoucher`, systemdokumentation |
| P3 | Tasks: templates, instances on save and publish, tick-off in Min sida and on the schedule | 4 | — | new task models and actions, new `TaskTemplatesPage`, `MySchedule`, shift form |
| P4 | Messages: compose, recipients, read and acknowledge in Min sida, notifier, prune command, GDPR documents | 5 | P3 (both edit `MySchedule`) | new message models and actions, `MySchedule`, `ScheduleNotifier`, `docs/gdpr/*` |
| P5 | Demand: sales sources (books per day, per area once P2 is in, tills per hour), hour profiles, forecast, overrides, staffing rules; demand band in the day view and on Bemanning och kostnad | 1 | — | new `Domain/Demand/*`, `StaffingPage`, `StaffingReport`, a read model over `pos_sales`, new demand settings page |
| P6 | Budget: budget page, amount or percent of forecast, footer and toast warnings, `planning:budget` | 2 | P5 | new budget model and page, week footer in the schedule view, `PlanningModule` |
| P7 | Proposal v1: engine filling existing open shifts, fairness metrics, review page, accept into drafts | 3 | P1 | new `Domain/Proposal/*`, new `ProposalPage` (`/planering/forslag`), `SaveShift` (source) |
| P8 | Proposal v2: shifts generated from the demand gap within the budget | 3 | P5, P6, P7 | `Domain/Proposal/*`, `ProposalPage` |
| P9 | Chain view across companies; unit filter and totals inside a company; cross-company conflict warnings | 8 | — (budget column once P6 is in) | new `App\Livewire\Firm\ChainSchedule`, firm workspace read helpers, `saved_views` page key |
| P10 | Launch sweep: comparison rows in `docs/migration/jamforelse.json`, industry pages, landing, legal review of the whole set, this ADR to "Accepted, implemented" | all | P1–P9 | public pages, `docs/migration`, this ADR |

**Parallel waves** (different files; the shared hotspots `lang/en.json`,
`andringslogg.json` and `Features.php` are merged by keeping both sides,
newest changelog entry first):

- **Wave A:** P1, P2, P5 and P9 in parallel. P1 and P2 both touch `SaveShift`
  and the shift form lightly: merge P1 first, P2 rebases.
- **Wave B:** P3, P6 (after P5) and P7 (after P1) in parallel. P3 and P6 both
  edit the schedule view (shift form versus week footer): one rebases on the
  other.
- **Wave C:** P4 (after P3) and P8 (after P5, P6, P7) in parallel.
- **Wave D:** P10.

Packages that change payroll or accounting (P2) or add processing of personal
data (P3's tick-offs, P4, P7's fairness metrics) get the independent
legal and accounting review (QUICKSTART §7) before merge.
