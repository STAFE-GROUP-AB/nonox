# PAYROLL_MODULE.md — Lönemodul (toggleable per company)

> Instructions for AI agents building the **Payroll module** and the **module framework** it plugs into.
> This file is used together with `AGENTS.md` (core system). Read both. If they conflict on payroll, this file wins; on everything else, `AGENTS.md` wins.

Prerequisites from core (must exist before starting):
- Jetstream Teams = Companies (`App\Models\Company`, `current_team_id`)
- Tenancy primitives (`BelongsToCompany`, `CurrentCompany`, tenancy test harness)
- `Money` value object, `Luhn`, `AuditLog`
- Accounting: accounts, fiscal years, voucher series, `PostVoucher` action
- Roles & permissions via `Jetstream::role()` + Policies

Language: code/docs in English, UI in Swedish (default) + English.

---

## Part A — Module framework

The payroll module is the **first** optional module. The framework must be generic: the next modules are **ROT & RUT** (husavdrag), then e.g. Anläggningsregister, Projekt, Peppol, Bankkoppling. Build the framework once, correctly.

### A.1 Concept
- A **module** is a self-contained feature area that a company can **enable or disable** in its company settings under the tab **"Moduler"**.
- Core bookkeeping is **not** a module — it is always on.
- Each company decides independently. One user may have payroll enabled in Company A and disabled in Company B.
- Disabling a module **never deletes data** (Bokföringslagen retention). It hides the module's UI, blocks new writes, and keeps read-only/archive access for users with the right permission.

### A.2 Code structure
```
app/Modules/
  Contracts/Module.php            # interface every module implements
  ModuleRegistry.php              # all installed modules (registered by service providers)
  ModuleManager.php               # enable/disable/isEnabled per company, cached
  Http/Middleware/EnsureModuleEnabled.php
  Livewire/CompanyModulesTab.php  # the "Moduler" tab
  Payroll/                        # ← this module
    PayrollModule.php             # implements Module
    PayrollServiceProvider.php
    Domain/  Actions/  Livewire/  Http/  Policies/  Support/  routes/  lang/  views/
    database/migrations/  database/data/
  RotRut/                         # next module (same shape) — NOT built in this task
```
Each module's ServiceProvider registers its routes, Livewire components, policies, translations, navigation items, permissions and scheduled tasks — **always wrapped in module checks**, never globally active.

Migrations for all installed modules always run (tables exist even when disabled). Enabling a module never runs migrations at request time.

### A.3 `Module` contract
```php
interface Module
{
    public function key(): string;                    // 'payroll', 'rot_rut'
    public function name(): string;                   // translated: 'Lön'
    public function description(): string;            // translated, shown on the module card
    public function icon(): string;
    public function dependencies(): array;            // other module keys required, [] for payroll
    public function permissions(): array;             // permission strings this module adds
    public function roles(): array;                   // extra roles this module adds (key => label)
    public function navigation(): array;              // nav items (label, route, permission, icon)
    public function settingsComponent(): ?string;     // Livewire component for module settings, shown when enabled
    public function canEnable(Company $c): ModuleCheck;   // e.g. company profile complete
    public function canDisable(Company $c): ModuleCheck;  // blockers, see A.6
    public function onEnable(Company $c, User $by): void;  // seed defaults (idempotent)
    public function onDisable(Company $c, User $by): void; // stop schedules, revoke module tokens
}
```
`ModuleCheck` = `{ bool $allowed, array $blockers, array $warnings }` with translated messages.

### A.4 Storage
```
company_modules
  id, company_id (FK teams), module_key string, enabled bool
  enabled_at, enabled_by, disabled_at, disabled_by
  settings jsonb default '{}'
  unique(company_id, module_key)
```
- `ModuleManager::isEnabled(string $key, ?Company $c = null): bool` — cached per request and in cache store (`company:{id}:modules`), invalidated on change.
- Every enable/disable is written to the audit log with before/after.
- Decision: own table + `ModuleManager` (not Laravel Pennant), because we need audit trail, settings per module, and dependency/blocker checks. Pennant may wrap it later if a SaaS billing layer is added.

### A.5 Enforcement (all four layers are required)
1. **Routes**: every module route group uses middleware `module:payroll` → 404 when disabled (do not reveal the module exists).
2. **Navigation**: nav items are rendered only when the module is enabled **and** the user has the item's permission.
3. **Policies/Actions**: every module Action starts with `ModuleManager::ensureEnabled('payroll')` → throws `ModuleDisabledException`. This protects API, jobs, console commands and Livewire actions too.
4. **Jobs & schedules**: scheduled tasks iterate only companies with the module enabled; queued jobs re-check on `handle()` and exit cleanly if it was disabled meanwhile.

API: module endpoints return 404 when disabled; API tokens get module abilities only while enabled.

### A.6 Disable rules
`canDisable()` returns **blockers** (hard stop) and **warnings** (confirm required).
For payroll, blockers:
- A payroll run in status `CALCULATED`, `APPROVED` or `PAID` that is not yet `POSTED`
- An AGI for a period with paid salaries that is not `SUBMITTED`
- Unpaid approved expenses (utlägg) linked to a payroll run

Warnings:
- Employees with status ACTIVE will lose self-service access
- Unused vacation balances and semesterlöneskuld remain in the ledger
- Scheduled payroll reminders will stop

When disabled:
- Payroll UI and self-service are hidden. The role `employee` loses access (users who are **only** employees cannot open the company at all; show a friendly message).
- Users with `payroll:archive-view` (default: owner, auditor, payroll_admin) still see a read-only **"Lönearkiv"** (payslips, runs, AGI files, reports) — required for retention and audits.
- Salary vouchers already posted stay in the ledger as normal vouchers (core is unaffected).

Re-enabling restores everything; `onEnable` is idempotent and never overwrites existing settings or data.

### A.7 Roles with modules
Jetstream roles are global, so:
- Module roles (`payroll_admin`, `employee`) are always registered, but the **role picker** in team member management only shows roles from enabled modules.
- A member's module role is kept when the module is disabled but grants nothing (permissions are filtered through `ModuleManager`).
- `hasTeamPermission()` is wrapped: permissions belonging to a disabled module always return `false`, except `payroll:archive-view`.

### A.8 UI — tab "Moduler" in company settings
- Add a tab **"Moduler"** to the Jetstream team settings page (`/teams/{company}` → shown as "Företagsinställningar").
- Visible to `owner` and `admin` (permission `modules:manage` — add to core permissions).
- One card per installed module: icon, name, description, status badge (Aktiv / Inaktiv), toggle switch, link "Inställningar" when enabled, list of dependencies.
- Toggle ON → `canEnable()` → confirmation dialog explaining what happens → enable → redirect to the module's onboarding/settings.
- Toggle OFF → `canDisable()` → show blockers (toggle stays on, with links to resolve each one) or warnings with a confirmation dialog that requires typing the module name.
- Each module's own settings live in a sub-tab or modal rendered by `settingsComponent()`.

### A.9 Framework tests
- Enable/disable per company is isolated (A enabled, B disabled → B gets 404 on every payroll route/API endpoint, no nav item, actions throw).
- Disabled module: jobs exit, schedules skip, API abilities removed.
- Blockers prevent disabling; warnings require confirmation.
- Archive read-only access works when disabled; no write path works.
- Role picker hides module roles when disabled; permissions resolve to false.
- Audit log entries for every toggle.
- Dependency check: a module cannot be enabled before its dependencies, and a dependency cannot be disabled while a dependent module is enabled.

---

## Part B — Payroll module (lön)

Location: `app/Modules/Payroll/`. Everything is company-scoped, uses `BelongsToCompany`, and is covered by the tenancy suite and the module checks in A.5.
Payroll is the most regulated part of the system: **when in doubt, stop and write `TODO(legal-verify): <question>` and list it in the PR.**

### B.1 Principles
1. **Rules are data, not code.** Rates, thresholds, tax tables, benefit values and allowances live in versioned tables with `valid_from` / `valid_to`. A run uses the values valid on the **payment date** (utbetalningsdag) unless a rule says otherwise.
2. **An approved payroll run is immutable.** Corrections happen in a later run (correction lines / retroactive pay), never by editing. Enforced at Policy, Model events and Postgres triggers.
3. **Deterministic calculation.** Same inputs + same rule versions = identical output. Every line stores the rule version that produced it.
4. **Explainability.** Every amount on a payslip expands to formula + inputs ("Varför blev skatten 8 431 kr?").
5. **No collective agreement is hardcoded and no agreement text is copied** (copyright). Only generic, configurable templates ship.
6. Money in integer öre via `Money`. Never floats.

### B.2 Module definition
```
key:          payroll
name:         Lön
description:  Löner, lönespecifikationer, semester, sjuklön, förmåner, utlägg och arbetsgivardeklaration (AGI).
dependencies: []
roles:        payroll_admin (Löneadministratör), employee (Anställd)
permissions:  payroll:view  payroll:manage  payroll:run  payroll:approve  payroll:post
              payroll:view-sensitive  payroll:agi-submit  payroll:settings  payroll:archive-view
              employees:manage  self:payslips  self:absence  self:expenses  self:time
```
Default permission mapping:

| Role | Permissions |
|---|---|
| owner | all except `payroll:view-sensitive` (must be granted explicitly to a named user) |
| admin | `payroll:view`, `payroll:approve`, `payroll:archive-view`, `payroll:settings` |
| accountant | `payroll:view`, `payroll:post`, `payroll:archive-view` |
| auditor | `payroll:archive-view` (aggregated reports only, no individual absence causes) |
| payroll_admin | all payroll permissions incl. `payroll:view-sensitive` |
| employee | `self:*` only |
| bookkeeper, approver | none by default |

`onEnable` (idempotent):
1. Create default settings (B.3).
2. Seed system pay codes (B.5) with BAS account mapping matched against the company's chart (create missing accounts only after confirmation in the onboarding wizard).
3. Ensure voucher series **L** (Lön) exists.
4. Create default work schedule "Heltid 40 h mån–fre".
5. Start the onboarding wizard: company payroll settings → import or create employees → tax tables check → first run.

`onDisable`: stop payroll schedules, revoke self-service sessions for employee-only users, revoke payroll API abilities.

### B.3 Module settings (`company_modules.settings` for `payroll`)
```
salary_period             MONTHLY_CURRENT | MONTHLY_ARREARS
default_payment_day       25 (moved to previous banking day if weekend/holiday)
proration_method          WORK_SCHEDULE | CALENDAR_DAYS
absence_deduction_method  configurable formulas (day/hour), documented
vacation_year_start_month 4
vacation_rule_default     SAMMALONEREGELN | PROCENTREGELN
vacation_liability_booking MONTHLY | YEAR_END
net_pay_rounding          ORE | WHOLE_KRONOR
segregation_of_duties     true  (calculator ≠ approver)
use_employment_dimensions true
payment_method            PAIN001 | BANKGIROT_SALARY | MANUAL
payslip_delivery          PORTAL | PORTAL_AND_EMAIL_NOTICE
employee_self_service     true
agi_submission_mode       FILE | API
holiday_calendar          SE
```

### B.4 Data model
```
employees
  id, company_id, employee_number, user_id null (self-service login)
  first_name, last_name, personal_number (encrypted), birth_date (derived; age rules)
  address, postal_code, city, country, email, phone, language (sv|en)
  bank_clearing, bank_account (encrypted) | iban, bic (encrypted)
  tax_residence enum(SE, NON_RESIDENT_SINK, NON_RESIDENT_A_SINK)
  tax_table int null, tax_column int null, municipality_code null, church_member bool null
  tax_adjustment_type enum(NONE, PERCENT, TABLE, AMOUNT), tax_adjustment_value, valid_from, valid_to  (jämkning)
  emergency_contact (encrypted), notes (encrypted)
  status enum(ACTIVE, ON_LEAVE, TERMINATED)

employments
  id, company_id, employee_id, start_date, end_date null
  employment_type enum(TILLSVIDARE, ALLMAN_VISSTID, VIKARIAT, SASONG, PROVANSTALLNING, TIMANSTALLD)
  salary_form enum(MONTHLY, HOURLY)
  monthly_salary_ore null, hourly_rate_ore null
  employment_rate_bp (10000 = 100 %), weekly_hours, work_schedule_id
  position_title, cost_center_id null, project_id null
  collective_agreement_id null, pension_plan_id null
  vacation_rule, vacation_days_per_year (min from rules), payment_day null (override)

salary_changes          employee_id, employment_id, valid_from, monthly_salary_ore|hourly_rate_ore, reason  (drives retroactive pay)

work_schedules          id, company_id, name, pattern jsonb (hours per weekday, rotating weeks)

pay_codes (lönearter)
  id, company_id, code, name_sv, name_en
  category enum(SALARY, ADDITION, DEDUCTION, ABSENCE, BENEFIT, ALLOWANCE_TAX_FREE, ALLOWANCE_TAXABLE,
                EXPENSE, NET_DEDUCTION, NET_ADDITION, VACATION, SICK, PENSION, CORRECTION)
  unit enum(AMOUNT, HOURS, DAYS, PERCENT, QUANTITY)
  formula_ref null, is_taxable, tax_method enum(TABLE, ONE_OFF, NONE)
  is_contribution_basis, is_vacation_basis, is_pension_basis
  agi_field_code null, debit_account_id, credit_account_id null
  use_employment_dimensions, is_system, is_active

payroll_runs
  id, company_id, period (YYYY-MM), payment_date, type enum(REGULAR, EXTRA, CORRECTION, FINAL_PAY)
  status enum(DRAFT, CALCULATED, APPROVED, PAID, POSTED, REPORTED)
  calculated_by/at, approved_by/at, posted_voucher_id null, agi_submission_id null
  rule_snapshot_hash

payroll_inputs
  id, company_id, payroll_run_id, employee_id, pay_code_id, date_from, date_to,
  quantity, rate_ore, amount_ore, note, source enum(MANUAL, TIME, ABSENCE, EXPENSE, IMPORT, RECURRING, RETRO)

recurring_inputs        employee_id, pay_code_id, amount/quantity, valid_from, valid_to

payslips
  id, company_id, payroll_run_id, employee_id, employment_id
  gross_ore, taxable_ore, tax_ore, net_ore, contribution_basis_ore, employer_contribution_ore
  pdf_path, published_at null, hash

payslip_lines
  id, payslip_id, pay_code_id, description, quantity, rate_ore, amount_ore,
  tax_effect, rule_version_id, explanation jsonb

accumulators
  id, company_id, employee_id, scope enum(CALENDAR_YEAR, VACATION_YEAR), year, key, value
  keys: gross_ytd, tax_ytd, benefit_ytd, contribution_basis_ytd, vacation_days_earned,
        vacation_days_paid, vacation_days_unpaid, vacation_days_saved (per earning year),
        vacation_variable_basis_ore, sick_period_state, negative_net_carry_ore

absences   (SENSITIVE — payroll:view-sensitive)
  id, company_id, employee_id, type enum(SICK, VAB, PARENTAL, VACATION, LEAVE_UNPAID, LEAVE_PAID,
       STUDY, MILITARY, OTHER), date_from, date_to, extent_bp, hours null,
  doctor_certificate_received, related_absence_id null, status enum(REQUESTED, APPROVED, REJECTED), approved_by

benefits   employee_id, type enum(CAR, FUEL, MEAL, HOUSING, OTHER), value_rule jsonb, valid_from, valid_to, car_data jsonb

expenses   (utlägg, traktamente, milersättning)
  id, company_id, employee_id, type enum(OUTLAY, PER_DIEM_DOMESTIC, PER_DIEM_FOREIGN, MILEAGE_OWN_CAR,
       MILEAGE_COMPANY_CAR), date, amount_ore, vat_ore, account_id, receipt_path, trip_data jsonb,
  status enum(SUBMITTED, APPROVED, REJECTED, PAID), payroll_run_id null

time_reports  employee_id, date, hours, pay_code_id, project_id null, status, payroll_run_id null (the run that pays it; frozen once that run is approved)

collective_agreements, pension_plans   — configurable templates (B.8)
payroll_rule_versions                  — B.6
tax_tables                             — B.7
agi_submissions                        — B.13
```

### B.5 System pay codes (seeded on enable, editable mapping)
Monthly salary, hourly salary, overtime (simple/qualified), OB, bonus, commission, retroactive pay, vacation pay, vacation supplement, vacation compensation (payout), unpaid vacation, sick pay, karensavdrag, sick deduction, VAB deduction, parental leave deduction, unpaid leave, car benefit, fuel benefit, meal benefit, housing benefit, benefit co-payment (net), per diem tax-free / taxable, mileage tax-free / taxable, outlay repayment, advance, garnishment, salary exchange (gross deduction), pension premium, correction ±.

### B.6 Versioned rules (`payroll_rule_versions`)
Columns: `key`, `valid_from`, `valid_to`, `value jsonb`, `source_url`, `verified_by`, `verified_at`.
Global (not company-scoped), shipped as seed data `app/Modules/Payroll/database/data/{year}/rules.json`, updated yearly via a reviewed PR.

Required keys — **every value is `TODO(legal-verify)` against Skatteverket/Försäkringskassan before seeding:**
```
employer_contribution.full
employer_contribution.reduced_by_birthyear      (older employees: only ålderspensionsavgift)
employer_contribution.youth_reduction           (any temporary youth reduction: ages, rate, income cap, period)
employer_contribution.min_annual_basis
employer_contribution.regional_support
special_payroll_tax.pension
sink.rate
prisbasbelopp, inkomstbasbelopp
benefit.meal.free_day / breakfast / lunch_or_dinner
benefit.car.formula
per_diem.domestic.full / half / night, per_diem.domestic.meal_reductions
per_diem.foreign.by_country
mileage.own_car_tax_free_per_mil, mileage.company_car_fossil, mileage.company_car_electric
sick_pay.percentage, sick_pay.karensavdrag_percent_of_weekly, sick_pay.employer_period_days,
sick_pay.reinfection_window_days, sick_pay.certificate_from_day
vacation.minimum_days, vacation.supplement_sammaloneregeln, vacation.percent_rule,
vacation.max_saved_days, vacation.save_years
gift.tax_free_limits, wellness.tax_free_limit
agi.field_codes, agi.deadlines, agi.schema_version
```
A missing rule for the payment date throws `MissingPayrollRuleException` and **blocks the run. Never fall back to a default value.**

### B.7 Tax tables (`Support/Tax`)
- `php artisan payroll:import-tax-tables {year} {file}` — validates row counts/checksums, stores `tax_tables(year, table_no, column_no, period_type, income_from_ore, income_to_ore, value, value_type enum(AMOUNT, PERCENT))`, plus the one-off percentage table (engångsbelopp).
- `TODO(legal-verify)`: official file format and download location; whether an official API exists. Do not scrape.
- Withholding order per payslip:
  1. Tax basis = taxable cash + taxable benefits − gross deductions
  2. Regular salary → table + column lookup (column per Skatteverket rules)
  3. One-off amounts (bonus, retro, final settlement, vacation payout) → one-off percentage based on expected annual income
  4. Jämkning if valid on payment date
  5. SINK → flat rate, no table
  6. Missing tax info → mandatory default withholding rule (`TODO(legal-verify)`)
- Golden tests per shipped year: incomes × table/column with expected tax taken from the official tables.

### B.8 Collective agreements & pension templates
- `collective_agreements.rules jsonb`: overtime ladders, OB periods (weekday + time range → pay code), on-call, travel time, vacation supplement override, sick pay top-ups, pension plan reference.
- Ship generic templates only: "Tjänstemän – generisk", "Arbetare – generisk".
- Pension plans as configurable structures: `ITP1-like`, `ITP2-like`, `SAF-LO-like`, `Custom` — percentages and income thresholds as data.

### B.9 Calculation engine
`Domain/Engine/PayrollCalculator` — pure, no side effects. Input: `EmployeeSnapshot`, `EmploymentSnapshot`, `PayrollInputs`, `Absences`, `Benefits`, `Expenses`, `Accumulators`, `RuleSet(date)`, `TaxTable`. Output: `CalculatedPayslip` (lines, explanations, accumulator deltas). Persisting is done by `CalculatePayrollRun` action.

Pipeline — one class per step, each unit-tested:
1. **BaseSalary** — monthly × employment rate; hourly = hours × rate; mid-month start/end prorated per setting.
2. **AbsenceDeductions** — day/hour deductions, partial days and partial extent.
3. **SickPay** — karensavdrag computed as a percentage of an average week's sick pay; days 2–14 sick pay %; day 15+ no employer sick pay (flag for Försäkringskassan); återinsjuknande within the window; certificate warning from the configured day.
4. **Vacation** — sammalöneregeln (salary continues + supplement per day) or procentregeln; variable pay addition; unpaid vacation; saved days; payout at termination (one-off tax).
5. **Additions** — overtime, OB, bonus, commission, retro (one-off tax).
6. **Benefits** — add value to tax and contribution basis; co-payment as net deduction reduces the benefit value.
7. **TaxFreeAllowances** — per diem and mileage within limits tax-free; excess automatically moved to taxable pay code.
8. **GrossDeductions** — salary exchange etc., reduces taxable and contribution basis.
9. **WithholdingTax** — B.7.
10. **NetItems** — outlay repayment, advances, benefit co-payment, garnishment (Kronofogden decision, protected amount — `TODO(legal-verify)`).
11. **EmployerContributions** — rate by birth year/age band on payment date, respecting income caps.
12. **Pension** — per plan, special payroll tax computed for bookkeeping.
13. **NetPay** — must be ≥ 0; negative → carried forward as a claim on the employee + warning.
14. **Accumulators** — YTD and vacation updates.

Rounding: tax per Skatteverket rules; net per setting; contribution rounding level `TODO(legal-verify)`.

### B.10 Payroll run workflow
```
DRAFT ──calculate──▶ CALCULATED ──approve──▶ APPROVED ──▶ PAID ──▶ POSTED ──▶ REPORTED
   ▲          │
   └─ reopen ─┘ (only before approval)
```
- Blocking pre-run checklist: tax table/column or SINK decision for every employee, valid bank details, rules + tax tables exist for the payment date, no conflicting absences, previous AGI status known.
- Warnings: net change > 20 % vs last month, missing doctor's certificate, negative net, negative vacation balance.
- Diff view vs previous run per employee and pay code.
- Segregation of duties per setting.
- Approval locks the run and payslips (immutability triggers). Corrections only via `CORRECTION` run or correction lines in the next run.

### B.11 Payslips, payment, self-service
- Payslip PDF (own design), sv/en per employee: employer, period, payment date, all lines, gross, tax, net, YTD, vacation balance, tax table/column, benefit values, masked bank account.
- Published in the portal; optional email notice **without** salary details.
- Payment file: ISO 20022 pain.001 (category purpose SALA); optional Bankgirot salary format (`TODO(legal-verify)`). Only for APPROVED runs. PAID on confirmation or camt.054 match.
- **Self-service (role `employee`)**, mobile-first: own payslips, vacation balance, absence requests (vacation/VAB/sick report), expenses with receipt photo, time reports, update address. Bank account change requires 2FA + `payroll_admin` confirmation (fraud protection). 2FA required for all employees.
- An employee can never see another employee's data — mandatory tests.

### B.12 Bookkeeping (salary voucher)
`PostPayrollRun` → core `PostVoucher` in series **L**, dated payment date, dimensions from employment when enabled.
Default BAS mapping (configurable per pay code, verified against the company's chart in the onboarding wizard):
```
7010 / 7210  Löner kollektivanställda / tjänstemän         D
7081 / 7281  Sjuklöner                                     D
7082 / 7285  Semesterlöner                                 D
7385         Kostnader för förmåner                        D
7510         Arbetsgivaravgifter                           D
7410         Pensionsförsäkringspremier                    D
7530         Särskild löneskatt                            D
7090 / 7290  Förändring semesterlöneskuld                  D/K
7519         Sociala avgifter på semester- och löneskulder D/K
2710         Personalskatt                                 K
2730         Lagstadgade sociala avgifter och särskild löneskatt K
2740         Avtalade sociala avgifter                     K
2920         Upplupna semesterlöner                        K/D
2940         Upplupna lagstadgade sociala avgifter         K/D
2890 / 1930  Nettolöner att betala / Bank                  K
```
- Semesterlöneskuld accrual voucher monthly or at year end (setting).
- Skattekonto payment voucher (2710 + 2730 against 1630/1930) when paid.
- Reconciliation must hold: Σ net = 2890, Σ tax = 2710, Σ contributions = 2730.

### B.13 AGI — arbetsgivardeklaration på individnivå
- Per company per **payment month**, from all runs paid in that month.
- Huvuduppgift: contribution basis per rate category, total contributions, total withheld tax.
- Individuppgifter: personal number, cash salary, benefits per type, tax, SINK, specification number, other mandatory field codes (from `pay_codes.agi_field_code` + `agi.field_codes` rule data).
- Corrections and removals using the same specification number; resubmission for earlier periods.
- v1: XML per Skatteverket schema, validated against the official XSD, for upload in their e-service. v2: direct API (`TODO(legal-verify)`: availability, agreement, authentication).
- Deadline calendar in the payroll dashboard, from rule data.
- `agi_submissions`: period, xml_path, sha256, status enum(GENERATED, SUBMITTED, ACCEPTED, REJECTED, CORRECTED), receipt_reference, submitted_by.

### B.14 Other features
- **Year-end**: lock calendar-year accumulators, roll vacation year (earned → available, saved days, expiry), reconcile yearly AGI totals vs 2710/2730/7510.
- **Termination / final pay**: vacation payout, remaining salary, advance vacation deduction (`TODO(legal-verify)`), employment certificate and arbetsgivarintyg data export.
- **Retroactive pay**: salary change with past `valid_from` → difference per period proposed as correction lines (one-off tax).
- **Försäkringskassan**: flag sick day 15+ and parental leave; export the data needed for notification (no integration in v1).
- **Reports**: lönejournal, lönelista per pay code, cost per cost center/project, semesterskuld, YTD per employee, AGI vs ledger reconciliation, pension per provider. Auditor sees aggregated versions only.

### B.15 Security & GDPR
- Encrypted at rest: personal number, bank details, emergency contact, absence notes.
- Absence types and certificates: `payroll:view-sensitive` only, and **every read is audit-logged** (who viewed whose sick data, when).
- Payslip PDFs in a private bucket via short-lived signed URLs.
- Personal numbers never in logs, URLs, exceptions, queue payloads or analytics.
- Retention: vouchers and underlag per BFL (7 years); sick-pay records per sjuklönelagen (`TODO(legal-verify)`); employee data after termination per documented legal basis. Disabling the module never shortens retention (A.6).

### B.16 Migration from Fortnox (payroll part)
- Import employees, employments, salary history, pay code mapping (wizard maps source codes → our codes), YTD accumulators, vacation balances per earning year, semesterlöneskuld, historical payslips as PDF.
- Accumulators **must** be imported before the first run in our system; the checklist blocks otherwise.
- `TODO(legal-verify)`: available Fortnox payroll API endpoints/export formats (e.g. PAXml). Fallback: CSV + PDF upload.
- Reconciliation report: YTD gross/tax/contributions per employee vs source; mismatch blocks "migration complete".

### B.16a Payroll files from scheduling systems (löneunderlag, PAXml and CSV)
Restaurants, cafés and shops often compute hours and OB in a scheduling or time system and export a payroll file in which each OB type is its own code. The Swedish standard is **PAXml** (paxml.se).

- **Formats.** PAXml 1.0, 2.0, 2.1, 2.2 and 2.3, read per the official schemas `https://www.paxml.se/{version}/paxml.xsd` (the published versions are listed at `https://www.paxml.se/api/schema_versions`; there is no 1.2). The schemas have no namespace; the root is `<paxml>`. Read: `<lonetransaktioner>/<lonetrans anstid persnr>` (`lonart` — the receiving system's own code — else `lonkod`, PAXml's standard code; `benamning`, `datum` or `datumfrom`/`datumtom`, `antal`, `apris`, `belopp`) and `<tidtransaktioner>/<tidtrans anstid persnr>` (`tidkod`, `datum` or `datumfrom`/`datumtom`, `timmar`). Everything else (`personal` with bank accounts, `schematransaktioner`, `resetransaktioner`, `loneutbetalning`, `saldon`, …) is counted and left unread. Fallback: a CSV file, semicolon separated with a header row: `anstallningsnummer;personnummer;loneart;benamning;datum;datum_tom;antal;apris;belopp` (`loneart` and one of the two identifiers required; decimal comma or point; UTF-8 or Windows-1252).
- **Security.** `Support/Paxml/PaxmlReader` streams the file with XMLReader, `LIBXML_NONET`, no entity substitution, no DTD loading, and refuses any file with a DOCTYPE before an element is read (XXE, billion laughs). Max 10 MB and 50 000 transactions. The original is stored encrypted on the private disk (`payroll-imports/{company}/{sha256}`); only `payroll:view-sensitive` can download it, and every download is audited. Personal numbers are used in memory to match employees and never stored on the lines (an unknown person is shown as `ÅÅÅÅMMDD-XXXX`), logged or put in a URL.
- **Flow** (`Actions/PayrollFileImports`, `payroll:run`, open run only). Upload on the run page (`/lon/korningar/{run}`) with a **source system** (`payroll_import_sources`) → the file's page `/lon/korningar/{run}/underlag/{import}` shows the validation report: employees matched by anställningsnummer, else personal number (both given and pointing at different people = conflict, not matched); unknown employees; codes not mapped. Codes are mapped per source system (`payroll_import_mappings`: external code → pay code, or "läs inte in"), saved once and used for every later file from the same system; changing a mapping reads the file again. Lines are **proposals** (`payroll_file_import_lines`): a person approves them (one by one or "Godkänn N rader utan anmärkning"); each approved line becomes a `payroll_inputs` row with `source = IMPORT` and the run goes back to DRAFT. Nothing is approved, calculated or posted by the import. Removing such an input in the run makes the line a proposal again.
- **What is never paid from a file.** PAXml absence codes (SJK*, VAB, FPE, SEM*, TJL, … — the 2.3 recommended list) are skipped: absence is registered in the run, where sick pay and deductions are calculated, and the cause is sensitive. Schedule time and balances (SCH, FLX, FRX, NVX, TS1–TS9) are skipped. A line without `apris` and `belopp` can only be hourly pay (TIMLON) — OB or overtime at the full hourly rate would be wrong, so such a line is blocked. Negative values are blocked (corrections are entered by hand). Pay codes in the categories SICK, ABSENCE, VACATION, PENSION and ALLOWANCE_TAXABLE cannot be mapping targets.
- **Idempotency.** The same file (SHA-256) is read once per period (`unique(company_id, period, sha256)`). "Läs in filen igen" re-reads the stored file with today's mappings and employees: approved lines stay, every other line of the file is replaced.
- **Audit.** `payroll.file_imported`, `payroll.file_reprocessed`, `payroll.file_lines_approved`, `payroll.file_line_rejected`, `payroll.file_line_unapproved`, `payroll.file_import_deleted`, `payroll.file_downloaded`, `payroll.import_source_created`, `payroll.import_mapping_saved`, `payroll.import_mapping_removed`, `payroll.import_source_deleted` — none carries a personal number.
- **Settings.** Source systems and their mappings are under Företagsinställningar → Moduler → Lön (`payroll:settings`).
- Retention: see `docs/gdpr/lagringstider.md` (`TODO(legal-verify)`: whether the original file must be kept as underlag once the run is attested, or the approved lines suffice).

### B.17 Tests
- Framework tests (A.9).
- Golden payslip suite ≥ 50 fixtures (`tests/Fixtures/payroll/*.json`): full month, mid-month start/end, part-time, hourly, sick day 1 / 2–14 / 15+, återinsjuknande, VAB, parental leave, vacation (both rules), saved vacation, termination payout, bonus as one-off, retro pay, car benefit with co-payment, per diem with meal reductions, mileage excess, SINK, jämkning, reduced contribution age bands, negative net carry-forward, garnishment.
- Golden tax-table tests per shipped year.
- AGI XML validated against the XSD in CI.
- Salary voucher balances and reconciles to payslips.
- Immutability suite for approved runs/payslips.
- Tenancy suite + employee-only scoping suite.
- CI grep check: no numeric literals in `app/Modules/Payroll/Domain/Engine` other than 0, 1, 100.

### B.18 Glossary
Lön = salary · Lönekörning = payroll run · Lönespecifikation = payslip · Löneart = pay code · Preliminärskatt = withholding tax · Engångsbelopp = one-off payment · Jämkning = tax adjustment · SINK = non-resident tax · Arbetsgivaravgifter = employer contributions · Sjuklön/karensavdrag = sick pay / qualifying deduction · Semesterlön/semestertillägg = vacation pay / supplement · Semesterersättning = vacation payout · Semesterlöneskuld = vacation liability · Förmån = benefit · Traktamente = per diem · Milersättning = mileage · Utlägg = outlay · Kollektivavtal = collective agreement · Ackumulator = YTD accumulator · AGI = employer declaration.

---

## Part C — Tasks (in order)

**Framework**
- **M-1** `Module` contract, `ModuleRegistry`, `ModuleManager`, `company_modules`, cache, audit.
- **M-2** Enforcement: `module:` middleware, nav filtering, `ensureEnabled` in actions, job/schedule guards, API abilities, permission wrapping.
- **M-3** "Moduler" tab in company settings with cards, toggles, blockers/warnings dialogs, module settings host.
- **M-4** Framework test suite (A.9). Register an empty dummy module in tests to prove the framework works without payroll.

**Payroll**
- **P-1** `PayrollModule` + ServiceProvider, roles/permissions, `onEnable`/`onDisable`, settings component, onboarding wizard skeleton.
- **P-2** Data model, encryption, tenancy and employee-scoping tests.
- **P-3** Versioned rules + `MissingPayrollRuleException` + seed format (values marked legal-verify).
- **P-4** Tax table importer + lookup + one-off + SINK + jämkning + golden tax tests.
- **P-5** Engine steps 1–3 and 9–14 + golden payslips.
- **P-6** Vacation + semesterlöneskuld.
- **P-7** Benefits, allowances, expenses, pension, gross/net items.
- **P-8** Run workflow, checklist, diff, approval, immutability.
- **P-9** Payslip PDF + self-service portal (mobile-first).
- **P-10** Salary voucher, liability voucher, payment file.
- **P-11** AGI XML + XSD validation + corrections + deadline calendar.
- **P-12** Reports, year-end, termination, retro pay.
- **P-13** Disable/archive mode (Lönearkiv) + blockers from A.6.
- **P-14** Fortnox payroll migration + systemdokumentation chapter "Lön".

**Definition of Done (each task):** Pint, Larastan level 8 and Pest green · tenancy + module-toggle tests for anything new · sv + en translations · systemdokumentation updated if behaviour changed · OpenAPI updated if API changed · all new `TODO(legal-verify)` items listed in the PR.

**Next module after this:** ROT & RUT (`app/Modules/RotRut`) — must plug into this framework without any change to `ModuleManager` or the "Moduler" tab. If it would require a change, the framework is wrong: fix the framework in a separate PR first.
