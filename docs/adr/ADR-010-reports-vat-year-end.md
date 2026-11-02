# ADR-010: Reports, VAT and year-end rollover

- Status: Accepted
- Date: 2026-10-29
- Task: T-1.5

## Reports

- One page, `/rapporter`, with Saldobalans, Resultatrapport, Balansrapport,
  Huvudbok (Kontoanalys when one account is chosen) and Verifikationslista.
  Fiscal year, period, cost centre, project, account and comparison live in
  the URL, so a report can be bookmarked or sent.
- `LedgerQuery` reads the posted ledger of one fiscal year: the balance at the
  start of the period (opening balance plus everything posted before it) and
  the movements of the period. Every report runs a fixed number of queries; a
  test proves the count does not grow with the number of vouchers.
- With a cost centre or project filter the opening balance is zero, because
  opening balances are kept per account (ADR-008).
- Grouping follows the BAS account classes. The income statement shows
  revenue positive; the balance sheet shows both sides positive and a line
  for the result not yet booked to equity, so the two sides always meet.
  These are management reports, not the annual report's formal layout.
- Comparison: the same dates one year earlier, inside the previous fiscal year.
- **Export.** CSV for Excel (UTF-8 with BOM, semicolons, decimal commas).
  **PDF through the browser's print dialog** with a print stylesheet; no PDF
  package is added for reports. Server-side PDF (invoices sent by email)
  is decided with Phase 2 in ADR-002.

## VAT (momsdeklaration)

- Boxes are calculated from account movements, mapped by BAS account number
  (`VatBoxes`). There are no VAT codes on rows yet; the mapping covers the
  BAS VAT accounts. `TODO(legal-verify)` on the mapping, on dropping öre per
  box, and on boxes without accounts (06, 07, 08, 37, 38, 41).
- Periods follow the company's redovisningsperiod: calendar month, calendar
  quarter or the beskattningsår (= the fiscal year for a company), SFL 26
  kap. 10, 11 and 26 §§. Months and quarters are never cut at a fiscal year
  edge (#59): with a broken fiscal year, a quarter that straddles the year
  end is one period, listed under both fiscal years, calculated by date
  across both (`VatReturn` reads posted vouchers by date for the company, not
  per fiscal year; opening balances are not vouchers, so nothing is counted
  twice), declared once (eSKD `Period` = the quarter's last month, deadline
  from the quarter's end) and settled once. Its settlement voucher is dated
  the quarter's last day and so lands in the fiscal year that day belongs
  to; the VAT of the months before the year end reaches that year through
  the carried-forward opening balance on the VAT accounts. The closing
  checklist of a year only requires periods that end in it, and the archive
  package puts a period in the year of its last day. Enligt granskning
  2026-11-02, juristgranskning återstår (AUD-MOMS-6, #59).
- Each box can be opened to show the accounts behind it.
- **File for Skatteverket**: eSKDUpload 6.0 XML, ISO-8859-1. Element names
  are taken from published documentation, not from Skatteverket's DTD itself,
  so the file is `TODO(legal-verify)` until it has been validated in
  Skatteverket's test service.
- **Posting the VAT**: one voucher in series M, source VAT, dated the last day
  of the period. Every VAT account that feeds an output VAT box (10–12,
  30–32, 60–62) or box 48 goes back to zero for the period, the total goes to
  2650 in whole kronor, and the öre dropped from the declared boxes to 3740
  (less than one krona per box). Vilande moms (2618, 2628, 2638, 2648) and
  any other account in 2610–2649 without a box is not touched; the page
  lists it as a warning. Box 48 reads 2640–2647 and 2649, not 2648 (ML 7
  kap. 14 and 31 §§; enligt granskning 2026-11-02, juristgranskning återstår,
  AUD-MOMS-1, #58). The boxes are stored in `vat_reports` (append-only, database trigger)
  together with the voucher. VAT vouchers are left out when a period is
  calculated, so the report reads the same before and after.
- **Supplementary settlement** (#63): if something is posted in a settled
  period later, the page says so and offers a supplement: a new voucher in
  series M with only the difference on the VAT accounts that feed a box,
  2650 (corrected box 49 minus what 2650 already got) and 3740 (the rest),
  so all the period's settlement vouchers together book it as one. It is
  dated the period's last day, or today when that day is locked or its
  fiscal year closed. The corrected boxes are stored as a new version of the
  return (`vat_reports.version`, unique per company, period start and
  version; earlier versions are never changed); the highest version is the
  current one, and the archive package lists every version. The page tells
  the user to file a corrected return for the whole period at Skatteverket
  (Skatteverket, "Rätta en momsdeklaration"); NONOX does not file it. A
  late posting that only changes base boxes (05, 20–24, 35–42, 50) moves no
  VAT account, so there is nothing to book, but the page still says the
  boxes differ. Enligt granskning 2026-11-02, juristgranskning återstår
  (AUD-MOMS-5).
- **Deadlines** (`VatDeadlines`): month and quarter per SFL 26 kap. 26 §;
  yearly VAT (#60) per 33, 33 a and 33 b §§ from the legal form, EU trade in
  the year (`EuTrade`: a posted row on an account feeding box 20, 21, 35,
  37, 38 or 39, one query for many companies) and electronic filing
  (assumed, since the return is filed in Skatteverket's e-service): sole
  trader 12 May the year after; other legal persons by the fiscal year's
  last month as Skatteverket groups 33 b §; HB/KB and EU trade the 26th of
  the second month (27 December). Not handled: 33 c § decisions,
  byråanstånd, dödsbon, juridiska personer without an income tax return.
  Enligt granskning 2026-11-02, juristgranskning återstår (AUD-MOMS-9).
- **Locking**: after the VAT is declared the period can be locked
  (`companies.locked_until_date`, permission `periods:lock`). A lock only
  moves forward.
- Permissions: `vat:prepare` to see and download, plus `vouchers:post` to
  post the VAT.

## Year-end rollover (årsskifte)

- `CarryForwardBalances`: the closing balance of every balance-sheet account
  becomes the next year's opening balance. The next year is created if it
  does not exist. A result not yet booked to equity goes to 2099 (companies,
  associations) or 2019 (sole traders, partnerships), so the opening
  balances sum to zero. `TODO(legal-verify)` on the account per legal form.
- It can run again at any time while the next year is open; the next year's
  opening balances are replaced, and the audit log records how many.
- Closing a fiscal year (bokslut) is a separate, later step (Phase 3).

## Update 2026-11-02: fiscal-year length, calendar year and K-regelverk per legal form

Enligt AI-utredning 2026-11-02, juristgranskning återstår (all items below are
`TODO(legal-verify)`).

### Fiscal-year length (BFL 3 kap. 1–3 §§)

- Normally twelve months. When the bookkeeping obligation begins or the year
  is changed (omläggning) it may be shorter (no lower limit) or extended to at
  most 18 months; it may also be cut short when the obligation ends.
  `FiscalYearRules::MIN_MONTHS` (6) is removed, in the first-year form and in
  SIE import.
- Natural persons must use the calendar year (3 kap. 1 § second paragraph).
  `FiscalYearRules::requiresCalendarYear()` is true for `EF`: a new company
  must start in January, the first year may be changed but must end
  31 December, and `CreateNextFiscalYear`/`CarryForwardBalances` create a
  shortened year up to the next 31 December for an existing sole trader with
  a broken year (a change of fiscal year, 3 kap. 3 §), then calendar years.
  Existing years are never changed; the fiscal-year page, the settings page
  and the SIE review show warnings instead. Open question: that a change to
  the calendar year needs no permission from Skatteverket.
- A handelsbolag where a natural person is taxed for its income must also use
  the calendar year. NONOX does not know the partners, so `HB` and `KB` with a
  start month other than January require the confirmation
  `calendar_year_exempt` ("no natural person is taxed for the income"). A
  kommanditbolag is treated as a handelsbolag (open question).

### K1/K2/K3 per legal form (BFNAR 2006:1, 2010:1, 2016:10, 2017:3, 2012:1; BFL 6 kap.; ÅRL 1 kap. 3 §)

- `ReportingFrameworkRules::refusal()` blocks what the legal form decides:
  K1 only for `EF` and `IDEELL_FORENING`. `HB`/`KB` with only natural-person
  partners and K1 is refused until verified.
- `ReportingFrameworkRules::conditions()` returns the size conditions (K1: net
  turnover normally ≤ 3 MSEK; K2: not a larger company per ÅRL 1 kap. 3 §,
  not publikt AB or parent of a larger group). NONOX has no turnover, balance
  sheet total or employee figures, so it does not check size; the user
  confirms the conditions (`framework_confirmed`) and the audit entry records
  the confirmation. K3 needs no confirmation.
- Applied by `CreateCompany` (form, first-run guide, test companies) and by
  `UpdateCompanyDetails` only for a value the user changes; a saved value that
  looks wrong is kept and the settings page warns.

### AGENTS.md

§6.2 is corrected (owner's approval, 2026-11-02): no minimum length, at most
18 months (BFL 3 kap. 3 §), natural persons use the calendar year (3 kap. 1 §).

## OSS: the threshold and the return in euro (2026-11-02)

Sales to consumers in other EU countries (`ForeignSale::EuConsumerOss`,
invoicing in ADR-011) were always taxed in the buyer's country, and the OSS
basis was in kronor only. Both were checked against the law on 2026-11-02
(enligt AI-utredning 2026-11-02, juristgranskning återstår).

Sources, read 2026-11-02:

- Mervärdesskattelag (2023:200) 6 kap. 62–65 §§ (threshold 99 680 kronor,
  current and previous calendar year; the sale that exceeds it; the request
  to be taxed in the buyer's country) and 22 kap. 42, 44 §§ (the special
  return and payment in euro),
  https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/mervardesskattelag-2023200_sfs-2023-200/
- Skatteverket, "Deklarera och betala moms i One Stop Shop": amounts in
  another currency are converted to euro at the ECB rate for the last day
  of the period the return covers.

Decisions:

- **Threshold** (`OssThreshold`, the amount kept once with its source). An
  EU consumer sale carries Swedish VAT when the company has not elected
  taxation in the buyer's country and its EU consumer sales (all countries,
  excl. VAT, in kronor, issued invoices less credit invoices, per calendar
  year of the invoice date) are at most 99 680 kr this year including the
  invoice and were at most that last year. Such an invoice keeps
  `foreign_sale = EU_CONSUMER_OSS` (so it counts towards the threshold) and
  gets `swedish_vat_under_threshold = true`; `Invoice::bookedForeignSale()`
  then returns null, so it is booked and printed like a sale in Sweden
  (rows' accounts, 2611/2621/2631, boxes 05 and 10–12) and left out of the
  OSS basis. The invoice that crosses the threshold and every later one get
  the buyer country's rate. The decision is taken when the draft is saved,
  checked again on issue (refused if it no longer holds), and inherited by a
  credit invoice.
- **Election** (`teams.oss_elected`, `oss_elected_from`): the company has
  asked to be taxed in the buyer's country or is registered for OSS, from a
  date. Edited under Företagsuppgifter; audited as `company.updated`.
- **Euro** (`OssReport`): per country and rate, the taxable amount and the VAT
  in kronor (as booked on 2619) and in euro. Invoices in euro count with
  their euro amounts; every other currency, kronor included, is converted
  from the invoiced amounts at the ECB reference rate for the quarter's last
  day, or the next one published. The euro VAT is the rate applied to the
  euro base per country and rate, half up. The rates come from the ECB data
  portal behind `EuroReferenceRates` (`EcbRates`, cached for good once
  found; a fake in every test). Without a rate the euro columns are empty
  and the page says why. The basis downloads as CSV with both currencies and
  the rates used.

Open (`TODO(legal-verify)`): the rate when none is published on the last day
(Directive 2006/112/EC art. 369h not read), rounding of the euro VAT,
invoice date as the period and year of a sale, whether registration for OSS
is the request under 6 kap. 64 §, and that only goods and TBE services are
marked for OSS.
