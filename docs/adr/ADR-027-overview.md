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
- The notification bell in the sketch is not built: NONOX has no
  notifications yet.
