# ADR-025: Invoices in foreign currency and exchange differences

- Status: Accepted (supplier invoices 2026-11-01; customer invoices and the
  year-end revaluation 2026-11-02)
- Date: 2026-11-01
- Task: Phase 3, multi-currency (AGENTS.md §7)

## Context

Supplier invoices in another currency (USD from software and hosting
suppliers, EUR) were converted to kronor at Riksbanken's rate on the invoice
date by the inbox, but only the kronor were stored: the currency, the
foreign amount and the rate were written into the description. When such an
invoice is paid, the bank takes a different number of kronor, and that
difference (kursdifferens) was not booked at all, so 2440 never cleared.

## Sources

- BAS kontoplan 2026 as kept in `database/data/bas/2026/{k1,k2,k3}.json`:
  3960 "Valutakursvinster på fordringar och skulder av rörelsekaraktär" and
  7960 "Valutakursförluster på fordringar och skulder av rörelsekaraktär".
  Both exist in all three templates; the names are copied verbatim.
- Mervärdesskattelag (2023:200) 8 kap. 21 § (conversion of the taxable
  amount), already cited in ADR-016.
- Riksbanken's SWEA API (`RiksbankRates`), the daily mid rate.

## Decisions

- **Stored on the invoice** (migration `2026_11_05_100000`):
  `invoice_currency` (ISO 4217, default SEK), `foreign_total_minor` (the
  total as written, in hundredths of the currency, signed like `total_ore`,
  so negative on a credit note), `exchange_rate_micros` (kronor per unit in
  millionths, the `ExchangeRate` scale) and `foreign_paid_minor`. The column
  is not called `currency` because `MoneyCast` reads a model's `currency`
  attribute as the currency of every `*_ore` column, and those stay kronor.
  A check constraint requires the amount and a positive rate whenever the
  currency is not SEK. The registered-invoice trigger now also lets
  `foreign_paid_minor` change, nothing else.
- **Entering.** The amounts that are booked (total, VAT, rows) are kronor as
  before. The editor has a currency field; for another currency it asks for
  the total in that currency and the rate, prefilled with Riksbanken's rate on
  the invoice date (editable), and fills in the total in kronor. The inbox
  passes the currency, the foreign total and the rate it already had. The
  server checks that the total in kronor is the foreign total at the rate
  within one krona (`ExchangeRate::toOre`, integer arithmetic, half up), so a
  typo in either does not book a wrong rate.
- **Paying.** The person enters how much of the foreign amount is paid
  (default: what remains) and the kronor the bank actually took (prefilled
  with Riksbanken's rate on the payment date, to be corrected from the bank
  statement). The payment settles its share of the kronor booked on the
  invoice: `total_ore × foreign paid / foreign total`, rounded half up with
  `BigDecimal`; the payment that clears the foreign amount settles exactly
  what is left in kronor, so 2440 ends at zero whatever the rounding on the
  way. The voucher in series E, through `BookVoucher` and so `PostVoucher`:
  2440 debit the settled kronor, 1930 credit the kronor paid, and the
  difference debit 7960 (loss) or credit 3960 (gain). The same rate gives no
  difference row. A credit note refunded in a foreign currency works the
  same way with the signs reversed: more kronor back than booked is a gain.
- **Kontantmetoden.** The costs and VAT are booked at payment at the
  invoice's booking rate (their share of the kronor booked), and the
  difference to the kronor paid goes to 3960/7960, so the VAT follows the
  rate of the taxable event and not the payment day.
- **What the payment keeps.** `supplier_payments.amount_ore` is the kronor
  that moved on the bank account, `foreign_amount_minor` the foreign amount
  paid and `exchange_difference_ore` the difference booked (positive a loss).
  The kronor settled is `amount_ore - exchange_difference_ore`; existing rows
  (default 0) need no rewrite, which the append-only trigger would refuse
  anyway. The audit entry `supplier_invoice.paid` carries currency, foreign
  amount, settled kronor and difference.
- **Missing accounts.** If 3960 or 7960 is not in the company's chart it is
  added with its BAS name when first needed, and logged
  (`account.created`, reason `exchange_difference`), like the VAT accounts in
  ADR-016. A deactivated one is not reactivated; posting then fails.
- **Payment files and offsets.** pain.001 files first carried SEK to bankgiro and
  plusgiro only, so invoices in another currency were refused by
  `CreatePaymentBatch` and paid in the internet bank. Since 2026-11-06 they
  can be paid by file for Handelsbanken and Nordea (ADR-014, addendum);
  other banks still refuse them. Offsetting a credit note against an invoice is refused when
  either is in another currency, since it would need foreign amounts and an
  exchange difference of its own.
- **Shown.** The invoice page shows the foreign total and booking rate next to
  the kronor, what remains in the currency, and each payment's foreign amount
  and exchange gain or loss; the list shows the foreign total and remainder
  under the kronor.

## Customer invoices in foreign currency (2026-11-02)

The same rules on the receivables side (1510), with the differences that
come from the invoice being written by us rather than received:

- **Stored** (migration `2026_11_13_100000`): `customers.currency` (the
  currency new invoices start in); on `invoices` `invoice_currency`,
  `exchange_rate_micros`, `exchange_rate_date`, `exchange_rate_source`
  (`riksbank` or `manual`), `foreign_net_minor`, `foreign_vat_minor`,
  `foreign_total_minor` and `foreign_paid_minor`; on `invoice_rows`
  `foreign_unit_price_minor` and `foreign_net_minor`; on `invoice_payments`
  `foreign_amount_minor` and `exchange_difference_ore` (kronor received minus
  kronor settled, so here positive is a **gain**); on `invoice_reminders`
  `foreign_remaining_minor`; on `bank_transactions` `foreign_currency` and
  `foreign_amount_minor`. The `*_ore` columns stay kronor as booked, so every
  report, the VAT return, SIE and the reskontra sums are unchanged. Same
  check constraint as for supplier invoices; the issued-invoice trigger also
  lets `foreign_paid_minor` change.
- **Writing.** The rows are typed in the currency. Each row is converted to
  kronor at the booking rate (`ExchangeRate::toOre`, half up); the kronor VAT
  is calculated per rate on the kronor rows, not converted from the foreign
  VAT, so the VAT booked and reported follows the conversion of the taxable
  amount. The foreign totals are kept as written, without öresavrundning
  (öresavrundning applies to kronor only). The rate defaults to Riksbanken's
  mid rate on the VAT date (or the latest banking day before) and can be
  typed; a typed rate equal to Riksbanken's keeps the source `riksbank`.
- **One rate, taken on the VAT date (2026-11-02).** The same rate is the
  booking rate and the rate of the VAT base, since the kronor VAT is
  calculated on the converted rows. It is taken for the day the VAT arises
  (Mervärdesskattelag (2023:200) 8 kap. 21 §, 7 kap. 3–4 §§): the invoice's
  delivery date when it has one, otherwise the invoice date, which then
  stands in for the delivery (`SaveDraftInvoice::vatDate`; enligt
  AI-utredning 2026-11-02, juristgranskning återstår). A future delivery
  date gets today's rate. The editor fetches the rate again when the
  delivery date changes. Supplier invoices still take the invoice date.
- **The invoice** shows rows and totals in the currency and, below, the VAT
  per rate and the total in kronor with the rate and its source, and the
  company's IBAN and BIC.
- **Paying, crediting, reminding.** A payment works as for suppliers, with
  the signs of a receivable: 1930 debit the kronor received, 1510 credit the
  share settled, 3960 credit a gain or 7960 debit a loss; under
  kontantmetoden the revenue and VAT are booked at the booking rate. A part
  payment never settles all the kronor; the one that clears the currency
  settles exactly what is left. A credit invoice always takes the original's
  currency and rate (whatever its draft says), so it cancels the receivable
  exactly. A reminder is for what remains in the currency. ROT and RUT
  require kronor. Quotes, orders and agreements stay in kronor.
- **Bank files.** camt.054 gives the instructed amount and its currency
  (`AmtDtls/InstdAmt`); when that differs from the account's currency it is
  kept on the bank transaction and used as the foreign amount paid, with the
  booked kronor as the kronor received. The instructed amount is no longer
  taken as the account amount when it is in another currency. Without a
  foreign amount, a payment matched on the OCR number settles what remains.
  Accounts kept in another currency than kronor are not supported.
- **API and webhooks.** `invoice_currency`, `exchange_rate_micros` and row
  `unit_price_minor` on input; payments take `foreign_amount_minor`. The
  `invoice.sent` and `invoice.paid` payloads carry the currency, the foreign
  amounts and the exchange difference.

## Unrealised differences at year-end (2026-11-02)

- `CurrencyRevaluation` lists the customer and supplier invoices in another
  currency open on the last day of the fiscal year (payments and credit
  invoices up to that day deducted), valued at Riksbanken's rate that day.
  The difference to the kronor booked is booked once, before the year's
  result, on the last day in series A: 1510 and 2440 against 3960 (gain) and
  7960 (loss), with a reversing voucher on the first day of the next fiscal
  year (which must exist), so a later payment is still measured against the
  invoice's own rate. Kept as a `year_end_entries` row of type
  `CURRENCY_REVALUATION`; the year-end checklist has a point for it.
- Without a Riksbanken rate for a currency on the last day the voucher is
  booked by hand.

## Revaluation is required, and covers kontantmetoden (2026-11-02)

Rule ("enligt AI-utredning 2026-11-02, juristgranskning återstår"): K2
(BFNAR 2016:10, consolidated 2025-06-16) punkt 13.5 (short-term receivables
in foreign currency "ska värderas till balansdagens kurs"), 17.8 (a liability
in foreign currency "ska räknas om till balansdagens kurs"), 11.8 and 14.6;
K3 (BFNAR 2012:1, consolidated 2025-12-15) punkt 30.3, 30.5–30.7. Sources
read 2026-11-02: https://www.bfn.se/wp-content/uploads/vl16-10-k2ar-kons2025.pdf
and https://www.bfn.se/wp-content/uploads/vl12-1-k3-kons20251215.pdf.

- **Kontantmetoden.** The invoices unpaid on the last day are first booked on
  1510 and 2440 at their booking rate by the closing step for unpaid
  invoices (ADR-013, "Kontantmetoden at year-end"); the revaluation then
  values them like under fakturametoden. Both use `OpenInvoices`, so the
  kronor the revaluation takes as booked are the kronor that step booked.
  The revaluation refuses to book under kontantmetoden before that step is
  booked (or confirmed as booked by hand).
- **Part credits.** A customer invoice with a credit invoice for part of it
  is no longer skipped: what is left (the invoice less the credit invoices
  and payments up to the last day, in the currency and in kronor) is valued.
- **Bank accounts in another currency.** Not applicable: bank accounts are
  kept in kronor only (see "Bank files" above and "Not built yet"), so there
  is no foreign-currency bank balance to value (K2 14.6). When foreign bank
  accounts are built, their revaluation must be built with them.
- **Checklist.** The point "Valutafordringar och valutaskulder är
  omvärderade" blocks closing again (TODO) when there are open foreign
  invoices and the revaluation would change something. It is OK when the
  revaluation entry is booked, when the closing rate gives no difference, or
  when a person confirms "Omvärderingen är bokförd för hand" (stored in
  `closing_checks` with key `currency`, who and when, audited as
  `closing.checked`; unticked again while the year is open). The manual
  confirmation is what clears the point when Riksbanken has no rate and the
  voucher is booked by hand. This replaces the earlier WARN status, which
  never blocked.

## Not built yet (next steps)

- Offsets between foreign documents. (Foreign currency in payment files was
  built 2026-11-06, see ADR-014's addendum.)
- Bank accounts kept in another currency (a euro account, its own
  revaluation), and overpayments in a foreign currency (refused, as in
  kronor).
- Quotes, orders and agreements in foreign currency.
- Currencies with other than two decimals (JPY, ISK) are stored as
  hundredths like the rest; Riksbanken quotes some per 100 units.
  `RiksbankRates` does not adjust for that yet.

## Open legal questions

- `TODO(legal-verify)`: for customer invoices, which paragraph of
  Mervärdesskattelag (2023:200) 17 kap. corresponds to Council Directive
  2006/112/EC art. 230 (an invoice may be in any currency if the VAT payable
  is expressed in the national currency, converted as in art. 91), and
  whether the kronor total is also required on the invoice.
- `TODO(legal-verify)`: Riksbanken's mid rate as the closing rate (the K2
  commentary names it as acceptable, while the normal case is the bank's
  buying rate for receivables and selling rate for liabilities), and the
  reversal on the first day of the next year. That the revaluation is
  required, also under kontantmetoden, follows K2 13.5/17.8 and K3 30.3
  (enligt AI-utredning 2026-11-02, juristgranskning återstår).
- OSS sales in another currency (resolved 2026-11-02, ADR-010): the OSS
  basis is now also in euro; invoices in euro count with their euro amounts,
  others are converted at the ECB rate for the quarter's last day. The VAT
  stays booked on 2619 in kronor at the invoice's rate.

- `TODO(legal-verify)`: whether Riksbanken's own mid rate counts as "the
  latest average rate fixed on the most representative currency market in
  Sweden" (ML 8 kap. 21 § alternative 1). Skatteverket names the ECB rate
  and Nasdaq OMX Stockholm's fixed mid rate. An ECB option for the VAT base
  (cross rates via euro, 8 kap. 22 §) was not added: the booking and the VAT
  base share one rate, and choosing the source is the open question.
- `TODO(legal-verify)`: advance invoices (the VAT arises when the payment is
  received, 7 kap. 7 §) and construction services under omvänd
  betalningsskyldighet (the invoice date, 7 kap. 8 §) use the delivery date
  or invoice date like every other invoice.
- `TODO(legal-verify)`: that realised differences on trade payables belong on
  3960/7960 ("av rörelsekaraktär") and not among the financial items in
  class 8 (which the BAS data in this repository does not list).

## Payment files abroad: the booking (2026-11-06)

A supplier invoice paid by a payment file (ADR-014, addendum) is booked by
the same `PaySupplierInvoice` as a payment registered by hand, with the
kronor and the booking day from the bank's debit notification (camt.054):
2440 debit the share of the invoice's kronor, 1930 credit the kronor the bank
took, and 3960 (gain) or 7960 (loss) for the difference. Nothing is booked
when the file is made, approved or confirmed by hand; the debit
notification's instructed amount (`AmtDtls/InstdAmt`) must equal the foreign
amount in the file. The accounts, the rounding and the settling of 2440 are
those above; no accounting rule changed.

