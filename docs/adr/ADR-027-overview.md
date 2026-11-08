# ADR-027: Översikt – key figures, "Att göra", cache, charts and forecast

- Status: accepted
- Date: 2026-11-01

## Context

The owner wants Översikt to say exactly what to concentrate on right now:
key figures, a single list of things to do across the system, the latest
vouchers, and a tab with statistics and a forecast. It is the first page
after sign-in, so it must be fast even for a company with years of data, and
it must never show another company's data or more than the user's role may
see.

## Decisions

### One service, cached per company, role and day

- `App\Domain\Overview\Overview` computes the page: `OverviewKpis` (four
  figures), the todo providers and `RecentVouchers`; `Statistics` computes the
  statistics tab separately, only when it is opened.
- The result is cached as plain arrays (the cache refuses objects) under
  `overview:{company}:{version}:shared:{fingerprint}:{date}`. The fingerprint
  (`AccessFingerprint`, shared with `LivewireCompanyGuard`) is a hash of the
  user's role in the company and the modules that are on, so everyone with
  the same role shares one entry and two roles never do. The date in the key
  moves deadlines with the day; entries live 10 minutes.
- Invalidation without knowing the keys: every key carries the company's
  version, and `OverviewCache::forget()` replaces the version.
  `OverviewCache::forgetAfterCommit()` does it once per transaction, after it
  commits (a read during the transaction would otherwise cache old data under
  the new version), and not at all when it rolls back. It is called for
  - every audit log entry with a company, except sign-ins and switches; every
    bookkeeping action already writes one (AGENTS.md §1.2), and
  - saves and deletes of the models the page reads (vouchers, invoices,
    supplier invoices, opening balances, reconciliations, the company and its
    modules) and of the models each todo provider names in `invalidatedBy()`
    (bank transactions, inbox messages, staff ledger entries).
- The reload arrow (`OverviewPage::reload`) forgets the version, renders
  again and tells the statistics tab to follow (`overview-refreshed`). The
  page shows when the figures were computed ("Uppdaterad 14:32").
- Measured on a seeded company (22 months, 353 vouchers, PostgreSQL on the
  developer's Mac): cold 29 queries, 34 ms; warm 1 query (the fingerprint),
  under 1 ms. Statistics: cold 8 queries, 11 ms; warm 1 query. The whole
  dashboard request fell from 16 to 12 queries warm. The number of queries
  does not depend on the amount of data: everything is aggregated in SQL.

### "Att göra": providers through the module framework

- A `TodoProvider` returns `TodoItem`s (key, icon, title, subtitle, link,
  priority, due date) for one user in one company, checks permissions itself
  and reads with a fixed number of queries. The list is sorted with deadlines
  first (overdue at the top), then by kind: VAT, employer declarations,
  overdue invoices, approvals, payments, bank, inbox, payroll, staff ledger,
  drafts, closing.
- Core providers: `CompanyTasks` reuses `CompanyOverview` (Mina företag) for
  the current company only – the same counts and permission rules for VAT,
  AGI, years to close, overdue invoices, approvals, payments, drafts and
  payroll – and lists up to three overdue invoices one by one with customer
  and amount. `BankTodos` adds unmatched bank file payments and the company
  account (1930) when it is not reconciled to the end of last month.
  `CompanyOverview::for()` gained an optional company and a `permission` key
  per task; Mina företag itself is unchanged.
- A module contributes by implementing `App\Modules\Contracts\ProvidesTodos`
  next to `Module`; it is asked only while the module is on. The inbox
  (documents to review, proposals ready) and the staff ledger (forgotten
  check-outs, same rule as its overview) do so. Payroll's tasks already come
  through `CompanyOverview`.
- Hook for delegated tasks: a provider implementing `PersonalTodoProvider`
  and tagged `TodoProviders::TAG` in the container is cached per user instead
  of per role and merged into the sorted list.

### Latest vouchers: the sign rule

The five most recently posted vouchers. When a voucher touches bank and cash
(1900–1999) its amount is their net change, debit minus credit: money in is
positive (green, +), money out negative (accent colour, −). Any other voucher
moves no money and shows the sum of its debits without a sign, in grey. A
note under the list explains this.

### Charts: Chart.js, bundled

- Chart.js 4.5.1 (MIT, compatible with AGPL-3.0; its one dependency
  `@kurkle/color` is MIT too), installed with npm and bundled by Vite. It is
  imported dynamically, so its 200 kB chunk is fetched only when the
  statistics tab opens. The CSP allows only our own scripts; nothing is
  loaded from a CDN. 4.6.1 was published ten days before this decision and
  was skipped on purpose.
- The statistics tab is a lazy Livewire component (`#[Lazy]`), so it is not
  computed until it is shown. Charts: revenue and costs per month against
  last year, result per month and accumulated, bank and cash balance with the
  forecast, the largest customers and cost accounts, and VAT per month.
- Accessibility: each chart has a one-sentence summary that is also its
  accessible name, and "Visa som tabell" shows the same figures in a table.
  Last year and the forecast are dashed, so colour is never the only cue. One
  fixed palette, checked for colour blindness and contrast; one value axis.

### The forecast method

Shown in Swedish under the chart and labelled "Prognos". No AI:

    balance at month end = balance before
                         + open customer invoices due in the month
                         − open supplier invoices due in the month
                         + average monthly result of the last three whole months

The start is today's bank and cash balance (1900–1999) by the books.
Overdue invoices count in the current month. For the current month only the
days left count of the average result. Three months: this one and the next
two. The math is `LiquidityForecast`, unit tested with fixed data.

## Consequences

- The figures can be up to ten minutes old for changes that neither write the
  audit log nor touch a listed model (and for time passing, such as a
  check-in turning into a forgotten check-out). The reload arrow and the
  timestamp make that visible.
- A new module adds itself to "Att göra" by implementing `ProvidesTodos`;
  nothing in the overview changes.
- The forecast counts salaries, tax and VAT only through the average result
  unless they exist as supplier invoices. It says so under the chart.
  (Superseded by the addendum of 2026-11-08 below.)
- The notification bell in the sketch is not built: NONOX has no
  notifications yet.

## Addendum 2026-11-08: explicit outflows and inflows in the forecast

The forecast counted salaries, tax and VAT only through the average result.
That hid the months when a large VAT payment or the employer's taxes fall
due. The forecast now adds explicit flows, each dated by a rule NONOX
already has, and the average result leaves out what the explicit flows
count. The horizon stays three months (owner decision pending on 6 or 12).

### The components

Each forecast month is the sum of these parts, shown in the tooltip and as
columns in "Visa som tabell" (`LiquidityForecast`, gathered by
`ForecastFlows`):

| Part (sv) | What | Date |
|---|---|---|
| Kundfakturor | open customer invoices (ISSUED, kind INVOICE), total − paid | due date |
| Leverantörsfakturor | open supplier invoices (REGISTERED, APPROVED) | due date |
| Avtalsfakturor | each coming invoice date of an ACTIVE agreement up to the horizon (a passed next date counts once, from today), and drafts an agreement has made (not when overdue by more than 30 days), total with VAT | invoice date + the customer's payment terms; the draft's due date |
| Lön och arbetsgivarskatter | net pay of runs CALCULATED, APPROVED or PAID but not booked; their tax and contributions; tax and contributions booked on 2710/2730/2731; a month without a run estimated as the latest run | payment date; `AgiDates::payment` of the payment month |
| Moms | each VAT period whose date falls in the horizon, as `VatReturn` computes it (a running period: booked so far; a reported period is left out); 2650 reported but not paid; the VAT (total − net) of coming agreement invoices and agreement drafts | `VatDeadlines` through the deadline engine (the agreement VAT on the date of the period its invoice date falls in, when that date is in the horizon); 2650 on the reported period's date, or at once |
| Skattekontot | debited preliminary tax, only the monthly amount the company has entered (`preliminary_tax_monthly_ore`); the balance of 1630 | `PreliminaryTaxProvider` dates after today |
| Snittresultat | the average result of the last three whole months, without the parts above | as before |

Overdue amounts count in the first month. Partially paid customer invoices
were already counted with what is left (`paid_ore` includes payments and
credit notes; there is no separate status); a fully credited invoice has
status CREDITED and is not counted.

**Tax account.** VAT, payroll taxes and preliminary tax are paid through the
tax account. Money on 1630 and VAT to get back pay them first, month by
month; what is left is never counted as coming to the bank (Skatteverket's
payout is not assumed). A debt on 1630 is paid in the first month. This is
the same view as Momsbufferten (`MoneyNotYoursFigures::remaining`), whose
balances (`MoneyNotYours::for`) the forecast reuses.

**What is left out, and why.** No amount of debited preliminary tax is ever
computed or read from Skatteverket: NONOX stores nothing from the tax account
API (ADR-054, stage 3), so only the amount the company has entered counts.
`TODO(legal-verify)`: the day Skatteverket debits it (as Momsbufferten).
VAT on other invoices not yet booked and overdue VAT periods that were never
reported in NONOX are not counted. Pension premiums and other personnel costs paid through supplier
invoices count as supplier invoices when they exist.

### Double counting: the decision

The average result is an accrual figure. It already contains the salary
cost of the last three months, the tax cost if booked monthly, and the
revenue of the agreement invoices. Adding the explicit flows on top would
count each of them twice. So the average leaves out exactly what an
explicit flow replaces, and only when that flow is counted:

- **Payroll**: when payroll is counted from the runs (the module is on and a
  REGULAR run of the last three months exists), the result rows of vouchers
  with source PAYROLL (accounts 4000–8998) are added back, except the
  EMPLOYER lines the run does not pay: pension, särskild löneskatt and
  agreement insurance (PayCodeCatalog: 7410, 7530, 7570, credited to 2740
  and 2514; taken out as BAS 7400–7499, 7530–7539, 7570–7579,
  `Statistics::NOT_PAID_BY_RUNS`). Those are paid to an insurer or through
  the preliminary tax and stay in the average as before (revised after
  review 2026-11-08: they had disappeared from the forecast). The explicit
  payroll part then stands for every month of the horizon, a month without a
  run being estimated as the latest run, so the salary is neither missing nor
  counted twice. Without a run, payroll stays in the average and the explicit
  part is empty (also the employer liabilities on 2710/2730, which the
  average already stands for). Chosen over removing BAS 70–76 by account:
  the source is exactly what the module produced, so the test can prove a
  payroll cost is counted once; manual salary vouchers stay in the average.
  The limit: a company that switched to the payroll module within the last
  three months has manual salaries in the average for those months.
- **Preliminary tax**: when the monthly amount is entered, 8900–8998 is added
  back.
- **Agreements**: the revenue (net, excluding VAT) of invoices made from an
  agreement, by invoice month, less the credit notes of those invoices in
  the credit note's month (as the ledger has them, so a fully credited
  invoice nets to nothing), is always taken out of the average: their
  future invoices are counted on their own dates, and an ended agreement
  brings no more revenue. Kontantmetoden books revenue on payment, so for
  such a company the month can differ; accepted.
- **VAT** is not part of the result, so nothing is taken out. The agreement
  invoices' inflow includes VAT, so their VAT is also an outflow (above).

`LiquidityForecastFlowsTest` proves it: a posted March salary leaves the
average unchanged (30 000 kr revenue over three months is 10 000 kr a
month), while April's payroll part holds the approved run's net pay and
March's booked taxes; a 9 000 kr pension row on a payroll voucher lowers the
average by 3 000 kr a month; an agreement invoice credited in the same
window leaves the average at 0.

### Access, queries, tenancy

Runs that are not booked are counted only for a role with `payroll:view`;
other roles get the estimate from the latest booked run (the overview cache
is keyed by role and modules, `AccessFingerprint`). The flows add a fixed
number of queries (the deadline engine, Momsbufferten's balances, three per
VAT period in the horizon, one each for preliminary tax, agreements, their
drafts, the runs and the agreements' revenue), proven equal for a short and
a long history. Every query runs inside the company scope; the tenancy test
puts another company's runs, agreements, VAT and invoices next to an empty
one.
