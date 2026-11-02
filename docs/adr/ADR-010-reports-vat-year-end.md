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
- Periods follow the company's redovisningsperiod: month, calendar quarter or
  fiscal year.
- Each box can be opened to show the accounts behind it.
- **File for Skatteverket**: eSKDUpload 6.0 XML, ISO-8859-1. Element names
  are taken from published documentation, not from Skatteverket's DTD itself,
  so the file is `TODO(legal-verify)` until it has been validated in
  Skatteverket's test service.
- **Posting the VAT**: one voucher in series M, source VAT, dated the last day
  of the period. Every VAT account (2610–2649) goes back to zero for the
  period, the total goes to 2650 in whole kronor, and the öre difference to
  3740. The boxes are stored in `vat_reports` (append-only, database trigger)
  together with the voucher. A period is posted once. VAT vouchers are left
  out when a period is calculated, so the report reads the same before and
  after; if something is posted in the period later, the page says so.
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

### Needed correction to AGENTS.md (owned by the project owner, not edited here)

§6.2 says a first/changed year "may be 6–18 months (BFL 3 kap)". It should
read: "length 12 months normally; when the obligation begins or the year is
changed it may be shorter (no minimum) or at most 18 months (BFL 3 kap. 3 §);
natural persons and handelsbolag with a natural-person partner taxed for the
income must use the calendar year (3 kap. 1 §)".
