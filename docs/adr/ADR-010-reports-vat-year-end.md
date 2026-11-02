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
  box, and on the BAS accounts read for boxes 06, 07 and 08 (boxes 37 and 38
  have no BAS account and are reached through `accounts.vat_box`, #64, #97).
- **Box 05 by the VAT, not only the account** (#61): box 05 reads
  3000–3003, 3005–3099 and 3914 by account, and also, per voucher, the credit
  on a revenue account without a box (3990, 3500–3699 and the like; not
  3200–3299, 3400–3499, 3700–3799 or 3960–3989) as far as the voucher's output
  VAT on sales in Sweden (2610/2611, 2620/2621, 2630/2631) explains it: base
  = VAT / rate, less what box 05 accounts on the same voucher already carry.
  Output VAT whose base is on no revenue account (a sold fixed asset, where
  the price meets the asset account) is shown as a warning with the missing
  base; a difference within rounding the VAT to whole kronor per voucher is
  ignored. One extra grouped query per period. Enligt granskning 2026-11-02,
  juristgranskning återstår (AUD-MOMS-3, #61).
- **A sale without VAT is not taxable** (#62): a voucher whose only sales
  rows are on box 05 accounts (3700–3799 aside) and that books no output VAT
  at all (2610–2639) moves those rows to box 42. A voucher that also moves
  another revenue account (a reclassification) is left by account. Rows are
  still booked per account, so an invoice that mixes a 25 % and a 0 % row on
  the same account is reported by its VAT only as far as rule #61 goes; the
  editors therefore warn (`SalesVatRules`, `resources/js/vat-rate-check.js`)
  when a row's rate or a voucher's output VAT does not fit the account
  (3001 25 %, 3002 12 %, 3003 6 %, 3004 0 % after their BAS 2026 names; any
  other box 05 account 25/12/6 %, box 42 0 %). Warnings, not validation, so
  imports and unusual cases still post. Enligt granskning 2026-11-02,
  juristgranskning återstår (AUD-MOMS-4, #62).
- **Boxes 06, 07, 08 and 37** (#64): 06 reads 3401–3403 and 08 reads 3913
  (BAS 2026 v1.1: Egna uttag momspliktiga, Frivilligt momspliktiga
  hyresintäkter); 3914 Övriga momspliktiga hyresintäkter reads as 05. Box 07
  is the base under the margin scheme, which is not on any account (3211
  holds the price less the VAT), so it is derived per period as the VMB VAT
  on 2616/2626/2636 / rate. BAS has no account for box 37; instead an
  account in 3000–8999 can be tied to a base box (05, 06, 08, 20–24,
  35–42, 50) with `accounts.vat_box`, which wins over the number. VAT
  accounts cannot be tied, so the settlement keeps following BAS. EuTrade
  also counts accounts tied to its boxes. BAS 2026 v1.1 has no 2617, 2627 or
  2637 (checked in the official chart), so nothing is mapped to them; such
  an account in an imported chart stays a VAT account without a box and is
  listed as not declared. Enligt granskning 2026-11-02, juristgranskning
  återstår (AUD-MOMS-7, #64).
- **Box 38 has no BAS account** (#97): box 38 read 3107 by its number, but
  BAS 2026 v1.1 has only 3105, 3106 and 3108 in group 3100 (3108 feeds box
  35), and Skatteverket's description of fält 38 (mellanmans försäljning
  till en momsregistrerad köpare i ett annat EU-land, also reported as
  trepartshandel in the periodisk sammanställning) names no account. Box 38
  therefore reads no account by number, like box 37. Invoices for
  triangulation still book on 3107, a free number in BAS group 3100, and
  create it tied to box 38 (`ForeignSale::vatBox()`); an existing 3107
  without a box is tied when such an invoice is issued (audited as
  `account.updated`), and a migration ties every existing 3107 without a box,
  so earlier returns are unchanged. A 3107 tied to another box is left
  alone. Enligt granskning 2026-11-02, juristgranskning återstår (#97).
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

## INK2: schablonintäkt on periodiseringsfonder (2026-11-02, #83)

Enligt granskning 2026-11-02, juristgranskning återstår (BS-AUD-BOK-8, #83).

- **Rule.** IL 30 kap. 6 a § (Lag 2018:1206): statslåneräntan at the end of
  November of the year before the calendar year in which the tax year ends,
  at least 0,5 %, × the deductions for the funds held at the start of the
  tax year, in proportion for a tax year that is not twelve months. Reported
  in INK2S 4.6 a, field 7654 (SKV field table INK2S_SKV2002-34-01-26-04).
  The auditor's note of "× 72 %" is the rule before Lag 2018:1206 and is not
  applied; Skatteverket's own example (400 000 × 1,96 % = 7 840) has no such
  factor.
- **Decision.** `TaxAllocationReserves` proposes the amount from the opening
  balances of BAS 2110–2149. The rates are data with a source, keyed by the
  November year (`RATES`); a year without a rate gives no proposal and a
  warning. The proposal goes into `Ink2::adjustments()`, so it reaches 4.15,
  INK2 1.1 and the SRU file.
- **Override.** Fields in `Ink2Codes::PROPOSED` keep an entered 0 in
  `income_tax_returns.adjustments`; an entered amount (0 included) replaces
  the proposal and an empty field restores it. No schema change.
- TODO(legal-verify): the rates; that the booked opening balance equals the
  deductions made; rounding; the month count for broken years.

## INK2: basis for särskild löneskatt on pension costs (2026-11-02, #87)

Enligt granskning 2026-11-02, juristgranskning återstår (BS-AUD-BOK-14, #87).

- **Fields.** INK2 1.4 = 7132, 1.5 (negative basis) = 7133, INK2S 4.21 =
  8022 (SKV field tables INK2_/INK2S_SKV2002-34-01-26-04). 7132 and 7133 may
  not both be present.
- **Decision.** They live in `Ink2Codes::INFORMATION`: entered like the
  adjustments but without effect on the taxable result. The proposal is the
  year's net movement on BAS 7400–7499 (positive → 7132 and 8022, negative →
  7133). Entering one of 7132/7133 stops the proposal for the other. A warning
  shows when 8022 is filled in and 1.4/1.5 are empty (Skatteverket's
  ställningstagande dnr 8-292969-2025, read via a secondary source).
- TODO(legal-verify): the basis under lag (1991:687) 2 § (tryggandelagen
  provisions, item j, gruppsjukförsäkring, administration fees) versus the
  booked 74xx amount.

## Dividends within the free equity (2026-11-02, #84, #85, #86)

Enligt granskning 2026-11-02, juristgranskning återstår (BS-AUD-BOK-2, -3,
-13; #84, #85, #86).

- **Rule.** ABL 17 kap. 3 § första stycket: no value transfer unless the
  restricted equity is fully covered afterwards, computed on the latest
  adopted balance sheet; lag (2018:672) om ekonomiska föreningar 12 kap. 3 §
  likewise. The room is the free equity (BFNAR 2016:10 p. 5.8: the
  disposition covers the sum of the free equity). The prudence rule (17 kap.
  3 § andra stycket) is a judgement and is only shown as a reminder.
- **Decision.** `DividendLimit` computes the room as
  `AnnualAccounts::freeEquity()` of the year (retained earnings and the
  year's result, whether booked to 2099 or not) and refuses a larger
  dividend, or any dividend when it is negative. `SaveAnnualReport` (the
  board's proposal) uses it from #84; `YearEndClosing::dispose` (the
  meeting's decision, booked 2098/2898) uses it on the closed year's balance
  sheet from #85. The earlier rule that refused every dividend after a loss
  year is gone (#86): the limit is the free equity, not the year's result.
- TODO(legal-verify): changes in restricted equity after the balance date,
  transfers already made since the meeting (17 kap. 4 §), and the same limit
  for ideella föreningar and stiftelser.

## K2 balance sheet lines (2026-11-02, #88)

Enligt granskning 2026-11-02, juristgranskning återstår (BS-AUD-BOK-4, #88).

- **Rule.** ÅRL 3 kap. 4 § and bilaga 1 (balance sheet): Skulder till
  kreditinstitut, Förskott från kunder and Leverantörsskulder are separate
  items. BFNAR 2016:10 (uppställningsform balansräkning, read 2026-11-02)
  lists Checkräkningskredit and Övriga skulder till kreditinstitut as
  separate posts under both long- and short-term liabilities, and
  Förbättringsutgifter på annans fastighet and Pågående nyanläggningar och
  förskott as separate tangible fixed asset posts.
- **Decision.** `K2Schedule::BALANCE` lines are lists of BAS ranges and use
  the same split as the INK2R codes in `Ink2Codes` (7360–7370, 7214–7217,
  7350–7354), so the annual report and INK2R agree. Maskiner och inventarier
  stays one line (BAS 1200–1279, 1290–1299); the obeskattade reserver and
  avsättningar are still one line each.
- TODO(legal-verify): whether Maskiner och andra tekniska anläggningar and
  Inventarier, verktyg och installationer, and the kinds of obeskattade
  reserver, must be shown separately for a K2 company; the BAS ranges
  against the official BAS–K2 mapping.

## Stock changes and goods for resale (2026-11-02, #89)

Enligt granskning 2026-11-02, juristgranskning återstår (BS-AUD-BOK-9, #89).

- **Rule.** SKV field table INK2R_SKV2002-34-01-26-04 (read 2026-11-02): 3.2
  Förändring av lager av produkter i arbete, färdiga varor och pågående
  arbete för annans räkning, + 7411 and − 7510; 3.5 Råvaror och
  förnödenheter 7511; 3.6 Handelsvaror 7512. BFNAR 2016:10, uppställningsform
  resultaträkning: the stock change of products is an operating income item;
  Handelsvaror is the cost of goods sold (purchases ± the change in stock of
  goods for resale); Råvaror och förnödenheter includes legoarbeten and
  underentreprenader.
- **Decision.** `K2Schedule::INCOME` and `Ink2Codes::INCOME` use the same
  ranges: 4940–4959 and 4970–4979 → stock change; 4960–4969 → Handelsvaror;
  the rest of 4000–4999 → Råvaror och förnödenheter. Whether a purchase is
  raw material or goods for resale depends on the business, so it is set per
  account: an account in 4000–4899 with `sru_code` 7512 counts as
  Handelsvaror. The chart of accounts has a toggle for it and SIE #SRU sets
  it on an account created by a SIE import. Without the marking, purchases stay on 3.5 as before. A
  one-sided field that turns negative is still written with its sign, but
  the income tax page warns.
- TODO(legal-verify): 4900–4939 (semi-finished goods) and 4980–4999; whether
  a negative 3.5/3.6 should move to another field.

## Specification of changes in equity (2026-11-02, #90)

Enligt granskning 2026-11-02, juristgranskning återstår (BS-AUD-BOK-5, #90).

- **Rule.** ÅRL 6 kap. 2 § andra stycket (Lag 2024:347): companies other
  than HB and EF specify the changes in equity compared with the previous
  year's balance sheet, in the förvaltningsberättelse. BFNAR 2016:10/2025:2
  (uppställningsform förvaltningsberättelse) puts it under the heading
  Förändringar i eget kapital; p. 20.11 adds a row per correction of the
  opening equity.
- **Decision.** `AnnualAccounts::equityChanges()` returns the rows (opening,
  dividend, carried forward, the year's result, other changes, closing) with
  an amount per column of `equityColumns()` and a total. The dividend and the
  amount carried forward come from the DISPOSITION entry of the previous
  year whose voucher lies in this year; other changes are the residual, so
  every column reconciles by construction.
- TODO(legal-verify): whether a new issue, a bonus issue or a shareholder
  contribution must be named on its own row instead of "Övriga
  förändringar"; corrections of errors under BFNAR 2016:10 p. 20.11.

## Fastställelseintyg (2026-11-02, #91)

Enligt granskning 2026-11-02, juristgranskning återstår (BS-AUD-BOK-10, #91).

- **Rule.** ÅRL 8 kap. 3 § p. 1 (aktiebolag) and p. 2 (ekonomiska
  föreningar), read 2026-11-02: certified copies reach Bolagsverket within a
  month of the adoption; on the copy a board member or the managing director
  certifies that the balance sheet and income statement have been adopted,
  gives the day, and the meeting's decision on the profit or loss.
- **Decision.** Four nullable columns on `annual_reports` (adoption_date,
  adoption_decision, certifier_name, certifier_role) and a last page in the
  PDF. The decision defaults to a sentence built from the booked DISPOSITION
  entry of the year; the meeting is "årsstämman" for an AB and
  "föreningsstämman" for an ekonomisk förening. The page is always printed,
  with dotted lines for what is missing, so the same PDF can be signed by
  hand.
- TODO(legal-verify): Bolagsverket's form requirements (original or copy,
  digital filing, the wording of the bestyrkande), which could not be read
  (captcha).

## Entered notes: ställda säkerheter, eventualförpliktelser, events after the year (2026-11-02, #92)

Enligt granskning 2026-11-02, juristgranskning återstår (BS-AUD-BOK-11, #92).

- **Rule.** ÅRL 5 kap. 1 §, 14 § (ställda säkerheter: omfattning, art och
  form), 15 § (the sum of eventualförpliktelser), 16 § (åtaganden for group,
  associated and jointly controlled companies), 22 § (significant events
  after the year: art and economic effect); BFNAR 2025:2 p. 18.13 a–e (the
  kinds), 18.14 (the mortgage amount, else the carrying value), 18.15 (the
  definition of an eventualförpliktelse), 18.20A (say so when the effect
  cannot be estimated). Read 2026-11-02. The notes are only required when the
  circumstance exists.
- **Decision.** Columns on `annual_reports`: `pledged_assets` (json, öre per
  `PledgedAssetKind`), `pledged_other_text`, `contingent_liabilities_ore`
  and `_text`, `group_commitments`, `events_after`,
  `events_effect_unknown`. The figures cannot be read from the books, so the
  person enters them; the page reminds about the pledged assets when there
  are bank loans (2330–2359, 2410–2419, 2480–2489). A note without data is
  left out (no "Inga" line). Notes are numbered after the fixed asset notes.
- TODO(legal-verify): whether K2 practice expects "Inga" when there are no
  ställda säkerheter or eventualförpliktelser; whether 5 kap. 16 § needs
  amounts per kind.

## Comment on a net sales variation over 30 % (2026-11-02, #93)

Enligt granskning 2026-11-02, juristgranskning återstår (BS-AUD-BOK-12, #93).

- **Rule.** BFNAR 2016:10 p. 5.5 andra stycket (also in the 2016 base
  version, and kept in BFNAR 2025:2): "Varierar nettoomsättningen mer än 30
  procent mellan åren ska företaget kommentera detta." BFNAR 2025:2 exempts a
  company with an abbreviated income statement, which NONOX does not make.
- **Decision.** `AnnualAccounts::netSalesVariations()` compares every pair of
  consecutive years in the multi-year overview in integer öre: a variation
  is |new − old| × 100 > 30 × |old|, and any change from 0. Exactly 30 % is
  not a variation. `SaveAnnualReport` refuses to save without
  `net_sales_comment` when there is a variation; the document prints a
  factual sentence per pair and the comment.
- TODO(legal-verify): whether every pair in the overview needs a comment or
  only the latest year against the one before.
