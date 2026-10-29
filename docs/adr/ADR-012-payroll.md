# ADR-012: Payroll (lön) – proposal and legal review

- Status: **Proposed**. No payroll code is written until this ADR is accepted
  and the questions below have answers from someone qualified (AGENTS.md §7,
  Phase 4).
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
