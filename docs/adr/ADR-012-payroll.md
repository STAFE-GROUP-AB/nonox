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
  SFL 11 kap. 16 § andra stycket allows January withholding by the table last applied the
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

## Karensavdrag per sjuklöneperiod (2026-11-02, L2)

Enligt AI-utredning 2026-11-02, juristgranskning återstår. Sjuklönelagen
6 § andra–tredje stycket and 7 § tredje stycket with the författnings-
kommentar in prop. 2017/18:96 s. 68–69, and the same rule in the
agreements (Handels tjänstemän § 10.3.2 anm. and § 10.3.4, Visita–Unionen
§ 23.3, Gröna Riks § 20 p. 4.2, Detaljhandeln § 15.4 A).

- Sick spells are linked into sjuklöneperioder (a spell starting within the
  reinfection window continues the period), across runs: the run reads the
  approved sick absences of the last 15 months, so the day numbering
  follows the whole chain, not only the last absence record.
- Each period's karensavdrag is capped at that period's own sick pay. Two
  periods in one month each get their own. The part not covered is carried
  to later sick-pay days of the same period, in the same run, the next run
  or after reinfection, and dropped when the period is over; a payslip note
  says which.
- An occasion is a period in which a karensavdrag was actually made, once
  even when taken over several runs. The ten occasions are counted in the
  twelve months back from the new period's first day. A period found in the
  absences that no approved run has recorded (sick leave from before payroll
  ran here, or a run not yet approved) counts as an occasion with its
  karensavdrag taken, and the payslip says so.
- State: `sick_pay_periods` (company-scoped, per payslip and period: first
  day, last day, day number, karensavdrag due and deducted, the agreement's
  days from day 15). Written by the calculation, deleted with the payslips
  on recalculation, frozen with the approved run by the same trigger as
  payslips (`payroll_children_protect_approved`). Only approved runs are
  read as history.
- `TODO(legal-verify)`: the whole of the above, and whether a run that is
  calculated but not approved should block the next month's sick pay rather
  than add a note.

## Legal audit fixes (2026-11-02)

Enligt granskning 2026-11-02, juristgranskning återstår. Each item names the
finding and the GitHub issue.

### Tax larger than the cash pay (AUD-LON-06, #68)

- Tax is withheld only from cash pay (SFL 10 kap. 3 §). Benefits are part of
  the basis (SFL 11 kap. 4 §), but Skatteverket: "Om du inte betalar ut någon
  kontant ersättning kan du inte göra något skatteavdrag. Det gäller även om
  den anställda får en skattepliktig förmån". The engine therefore withholds
  min(calculated tax, cash before tax), for every method (table, one-off,
  jämkning, SINK, A-SINK). The rest is not withheld, not declared in 001 and
  not a claim on the employee; the payslip says how much and that the
  employee pays it with the final tax.
- Deductions after tax (advance, net deduction, own payment for a benefit)
  larger than what is left make a claim on the employee. It is a payslip
  line of the system pay code `FORDRAN_ANSTALLD` (debit 1680 when it arises,
  credit 1680 when it is taken back), so the salary voucher balances. The
  accumulator `negative_net_carry_ore` holds it; the sum over all years is
  what is owed, and the next payslip takes it from the net pay as far as it
  reaches. The pay code cannot be entered by hand.
- `TODO(legal-verify)`: set-off against later pay (lag (1970:215) om
  arbetsgivares kvittningsrätt); whether tax-free cash (allowances) counts as
  cash to withhold from (here it does).

### Jämkning decisions (AUD-LON-01, #69)

- "En utbetalare ska följa ett beslut om särskild beräkningsgrund enligt
  55 kap. 9 § senast två veckor efter det att utbetalaren fick del av det"
  (SFL 11 kap. 27 §). The employee card stores the decision: kind
  (PERCENT, TABLE, AMOUNT), the percentage (basis points) or amount (öre),
  the table and column (new columns `tax_adjustment_table`,
  `tax_adjustment_column`), and the first and last payment day it applies
  to. The person entering it chooses the first day, no later than two
  weeks after receiving it; the form says so.
- The context builder passes the decision when the payment date is inside
  the validity. A percentage applies to all taxable pay, one-off payments
  included (as before). TABLE replaces the table and column, also for the
  one-off column. AMOUNT withholds the amount per payment with taxable pay.
  The cash cap (#68) applies to all of them.
- `TODO(legal-verify)`: Skatteverket's employer guidance describes a
  percentage only ("göra skatteavdrag enligt den procentsats som beslutet
  säger"); whether table and amount decisions occur, and how an amount
  decision treats one-off payments, is unverified.

### The youth reduction's monthly cap (AUD-LON-03, #70)

- "Första stycket gäller endast till den del den avgiftspliktiga
  ersättningen uppgår till högst 25 000 kronor per kalendermånad" (lag
  2026:100 2 §), and the basis is the pay "utgett under en kalendermånad"
  (SAL 2 kap. 24 §). The cap is therefore shared by all payslips to the
  person with a payment date in the month, not applied per payslip.
- Each payslip adds the youth-reduced basis it used to the calendar-year
  accumulator `youth_reduced_basis_mMM` (the payment month); a later
  payslip in the month gets cap − used. A second payslip to the same
  person in one run sees the first one's deltas (PayrollRuns::calculate).
- Accumulators are written at attestation, so a run calculated before
  another run for the person was attested may be out of date. The payslip
  keeps the values it read where they matter (`accumulators_read`), and
  attestation refuses until the run is calculated again.

### Pay that becomes contributable when the year passes 1 000 kr (AUD-LON-02, #71)

- "En ersättning till en person är avgiftsfri om ersättningen till den
  personen under året understigit 1 000 kronor" (SAL 2 kap. 14 §), and "I
  avgiftsunderlaget ska också ingå tidigare under året utgiven avgiftsfri
  ersättning som sammanräknad med en ersättning som utgetts under
  kalendermånaden blir avgiftspliktig" (SAL 2 kap. 24 § fjärde stycket).
- Avgiftsfri basis is kept in the calendar-year accumulator
  `contribution_free_basis_ytd`. The payslip that takes the year to the
  threshold adds it to its basis (`earlier_free_basis_ore` in the
  contribution explanation) and clears it; that month's rates apply to it.
- AGI: Skatteverket (agbeskrivning, ruta 010) lets the employer declare the
  earlier avgiftsfri pay in the period it becomes known: 011 holds the
  month's pay plus what earlier periods declared as avgiftsfri, and 010
  that earlier amount. Avgiftsfri pay is declared in 131 (benefits 132–134)
  rather than 011, element names from the component schema 1.1
  (`KontantErsattningEjUlagSA`, `SkatteplOvrigaFormanerEjUlagSA`,
  `SkatteplBilformanEjUlagSA`, `DrivmVidBilformanEjUlagSA`,
  `AvrakningAvgiftsfriErs`), checked against the schema files. This also
  covers the field-131 part of AUD-LON-04, which #71 depends on.
- Migration of year-to-date totals: a basis with no contributions paid in
  the earlier system is taken as avgiftsfri.
- `TODO(legal-verify)`: earlier avgiftsfri benefits are added to 011 with
  the cash (Skatteverket's example shows cash only); correcting each earlier
  period instead (Skatteverket's other option) is not offered.
