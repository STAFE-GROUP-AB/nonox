# ADR-012: Payroll (lön) – proposal and legal review

- Status: **Superseded in scope by `PAYROLL_MODULE.md`** (2026-10-30), which
  makes payroll an optional module and every rate versioned data that blocks
  a run until a named, qualified person has verified it (`payroll:verify-rule`).
  The questions below remain the checklist for that verification.
- Date: 2026-10-29

## Why now

Many of the first companies are restaurants. For them payroll is not an
extra: staff are often paid by the hour, work evenings and weekends, and
change from month to month. Payroll is also where mistakes cost the most,
for the employees and towards Skatteverket. This ADR proposes the scope and
lists what must be confirmed before building.

## Proposed scope, version 1

1. **Employees**: name, personal number (encrypted, §10), address, bank
   account for salary, tax table and column or a decision on special tax,
   employment form (monthly salary / hourly), start and end dates, and the
   collective agreement the employment follows, if any.
2. **Time and absence**: hours per day for hourly staff, entered by hand or
   imported from a file; sick days, vacation, parental leave and VAB as
   absence types.
3. **Salary types** (lönearter): monthly salary, hourly wage, unsocial-hours
   supplements (OB), overtime, sick pay, sick-pay deduction, vacation pay and
   vacation allowance, benefits (for example meals), deductions after tax.
   Each salary type says whether it is taxable, whether it carries employer
   contributions, whether it earns vacation pay, and which accounts it is
   booked on.
4. **Pay run** per month: calculate gross pay, preliminary tax from
   Skatteverket's tax tables, employer contributions and net pay; review;
   lock. A locked pay run never changes; mistakes are corrected in the next
   run, like vouchers.
5. **Outputs**: payslips as PDF (emailed or printed), a salary payment file
   for the bank, a salary voucher in series L, and the employer declaration
   (AGI) per employee and month as a file for Skatteverket.
6. **Vacation**: accrual of vacation days and vacation pay per employee,
   including the vacation liability for the balance sheet.

Out of scope for version 1: pension administration beyond booking a
pension cost, salary exchange, expense claims with receipts, and integrations
with time clocks (a file import covers most of them).

## Related legal requirement for restaurants

Restaurants must keep a **personalliggare** (staff register) with check-in
and check-out times. It is not payroll, but it is the same people and the
same hours. It should be designed together with payroll so hours are entered
once. Separate ADR when payroll starts.

## Questions for the legal review (TODO(legal-verify))

Taxes and contributions
1. Which tax tables and columns apply, how they are obtained from
   Skatteverket in a machine-readable form, and how often they change.
2. When the employer must withhold a flat percentage instead of using the
   tables (for example without a tax decision, or for secondary employment).
3. The current employer contribution rates, including reduced rates by age
   and any temporary reductions, and the rule for the employee's age that
   decides the rate.
4. How benefits (meals, staff food in restaurants) are valued for tax and
   contributions.

Employer declaration (AGI)
5. The current technical format and submission channel for AGI, including
   test environments, and the deadlines per company size.
6. How corrections to an earlier month are submitted.

Sick pay and absence
7. Sick pay from day 2 to day 14, the karensavdrag calculation for monthly
   and hourly staff, and what the employer must report to Försäkringskassan.
8. How parental leave and VAB affect salary and vacation.

Vacation (semesterlagen)
9. Vacation pay versus vacation allowance for monthly and hourly staff, the
   minimum percentage, how vacation days accrue, and what changes under
   collective agreements.
10. How the vacation liability is calculated for the balance sheet.

Collective agreements
11. Which parts of the restaurant agreement (HRF/Visita) must be supported
    from day one: OB periods and rates, overtime, minimum wages, rest rules.
    Whether agreement rates may be shipped in the software or must be entered
    by each company (licensing and keeping them current).

Records and privacy
12. How long payroll records must be kept, and how that interacts with
    GDPR erasure requests from former employees.
13. What a payslip must contain.

Booking
14. The BAS accounts to use for each salary type, contributions, preliminary
    tax and vacation liability, per legal form.

## Consequences

- Nothing is built until the answers exist. The answers become part of this
  ADR and of the systemdokumentation.
- Tax tables, contribution rates and agreement rates change every year, so
  they must be data with valid-from dates, never constants in code.
- Payroll gets the same guarantees as the ledger: locked pay runs are
  immutable, corrections are new entries, everything is in the audit log.

## Status of the questions (2026-10-30)

Researched values with sources and quotes are in
`app/Modules/Payroll/database/data/2026/rules.json` (imported unverified):
employer contributions (31.42 %, reduced rates by birth year, the temporary
youth reduction from 1 April 2026, the 1 000 kr threshold, regional support),
särskild löneskatt, SINK, prisbasbelopp and inkomstbasbelopp, kostförmån,
traktamente, milersättning, sjuklön (sjuklönelagen 6–8 §§), semester
(semesterlagen 3–4, 16 a–b, 18 §§), gifts, friskvård, AGI deadlines and the
AGI XML schema version 1.1. Still to research: car benefit formula, foreign
per diem by country, AGI field codes, the engångsbelopp tables (published as
HTML only) and the tax table file layout (SKV 433, edition 36).

## Tax tables (P-4, 2026-10-30)

- `payroll:import-tax-tables {year} {file}` reads Skatteverket's
  allmanna-tabeller-manad.txt by its record layout (postbeskrivning
  allmänna tabeller 2026), checks that every table starts at 1 kr with
  contiguous intervals and an open last one, and stores it with the file's
  SHA-256. An imported year is never replaced. Golden tests use table 33
  from the official 2026 file.
- Amount rows give the tax in kronor. Percentage rows (monthly income over
  80 000 kr, SKV 433 avsnitt 5) and SINK need the rule `tax.rounding`.
  `TODO(legal-verify)`: how the percentage result is rounded and how öre in
  the income are placed in the whole-krona intervals; until the rule is
  verified such runs are blocked.
- Engångsbelopp are published only as HTML; column 1 for 2026 is in
  rules.json as `tax.one_off`, columns 2–6 must be added before use.

## A new payment year (2027, 2026-11-02)

- `data/2027/rules.json` holds the 2027 values decided on 2026-11-02, each
  with source, date read and a short quote. Values not yet published are
  listed under `pending` (authority, expected date) and left out; keys an
  open-ended earlier version covers are listed under `continued`. Nothing
  is guessed or copied from 2026.
- `payroll:import-rules` without a year imports every year's file and runs
  nightly from the scheduler, so a new file reaches every installation
  after deploy. It never changes a version verified for the installation or
  by any company (previously a company's check did not protect a version).
- Lön → Regler starts with a year view (`Support/YearReadiness`) for the
  next payment year: rules verified, unverified, not published or missing,
  and whether the tax tables are imported, with verification in place.
- `payroll:import-tax-tables {year} --download` reads the same tables from
  Skatteverket's open data (RowStore), converted to the record layout and
  checked like a file; on 2026-11-02 the 2026 open data matched the
  official text file line for line.
- `payroll:fetch-published-tax-tables` runs nightly at 04:10 and imports
  the current year's and, from October, the next year's tables once they
  are published. An imported year is never fetched again, and a year with
  fewer tables than the year before is refused (an imported year cannot be
  replaced, so a partly published year must not be taken). Decided
  2026-11-02 by the owner: the tables are Skatteverket's own data, checked
  by the importer, and need no human approval; the payroll rules still do.
- After tables are imported, and when the nightly rule import brings new
  or changed versions, everyone with `payroll:settings` in each active
  company with Lön gets a `PayrollYearNotice` saying what to check, opening
  Lön → Regler in that company.
- Without tables for the payment year a run is blocked once with
  "Skattetabellerna för {år} är inte inlästa ännu". `TODO(legal-verify)`:
  SFL 10 kap. 16 § allows January withholding by the table last applied the
  year before.

## Rounding, one-off tax and A-SINK (2026-10-30)

- `tax.rounding` = DOWN_TO_KRONA: "Belopp som avser skatt eller avgift enligt
  skatteförfarandelagen (2011:1244) ska anges i hela krontal så att öretal
  faller bort" (skatteförfarandeförordningen 22 kap. 1 §). SINK-lagen 7 § and
  A-SINK-lagen 9 § say the same for their tax.
- One-off payments (SFL 11 kap. 17 §) use the engångsbelopp table for the
  column on the expected annual income, which Skatteverket defines as the
  total gross income including benefits, this and earlier one-off payments of
  the year at the main employer for the whole year. Here: regular taxable pay
  of the period × 12 + this one-off + one-offs earlier in the year. Without
  regular pay, 30 % ("Om du inte har tidsbestämd lön ska arbetsgivaren dra av
  30 procent"). A jämkning percentage applies to one-off payments too.
- A-SINK: 15 % (lag 1991:591 9 §), withheld by the payer (SFL 13 kap. 4 §).
- Still open: how a salary with öre is placed in a table interval (no source
  found; öre are dropped), and the mandatory withholding when tax
  information is missing (blocked).

## Time reports in the payroll run (2026-11-01, GitHub issue #15)

The owner decided that the payroll run uses approved time reports.

- A run pays the approved time reports with the pay code TIMLON dated in its
  month, for employments paid by the hour that are in force on the day. They
  become one input "Timlön enligt tidrapport" with the summed hours; the
  Additions step prices it at the employment's hourly rate, and the pay code
  decides tax, employer contributions and vacation basis, exactly as for
  hourly pay entered by hand. No new rule was added. Monthly-paid
  employments are not affected.
- The run that pays a report claims it (`time_reports.payroll_run_id`), read
  for all employees in one locked query. A recalculation releases and claims
  again, reopening releases, and a report claimed by another run is never
  taken. A PostgreSQL trigger refuses changing or deleting a report claimed
  by an approved run, and claiming one for such a run.
- Reports not approved are not paid; the run page shows the hours waiting
  (submitted reports, and the staff ledger's pending proposals through
  `TimeReportPay::addPendingSource`).
- A report approved after its month's run was attested is never paid
  automatically (owner's decision, 2026-11-01). A regular run asks the
  person running payroll about approved, unclaimed TIMLON reports dated
  before its month, for employees in the run with an hourly employment in
  force on the report date, and not while the report's own month has an
  open regular run. The run page shows the hours per person and months with
  "Ta med i den här lönekörningen" and "Inte nu". The answer is stored per
  report on the run (`late_time_reports_included` / `_skipped`, jsonb) and
  audited; recalculation respects it, reports approved after the answer are
  asked about separately, "Ändra valet" takes it back while the run is open,
  and the payroll_runs trigger freezes it at attestation. Included reports
  are claimed like the month's own and paid as one line per month and
  employment, "Timlön enligt tidrapport (oktober 2026)", at the hourly rate
  of the employment the hours were worked in; "Inte nu" leaves them
  unclaimed and the next run asks again. No new rule: tax, contributions
  and vacation basis follow the pay code.
  `TODO(legal-verify)`: whether hourly pay for an earlier month paid in a
  later run is reported in AGI for the payment month (as assumed for other
  late payments; the engine does so today). Also unverified: whether such
  hours should be priced at the hourly rate in force when they were worked
  if it has changed since (the engine uses the employment's current rate,
  as for the run's own month).
