# ADR-025: Supplier invoices in foreign currency and exchange differences

- Status: Accepted
- Date: 2026-11-01
- Task: Phase 3, multi-currency (AGENTS.md §7), first part

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
- **Payment files and offsets.** pain.001 files carry SEK to bankgiro and
  plusgiro only (`CreditTransferFile` writes `Ccy="SEK"`), so invoices in
  another currency are left out of the payment-file list and refused by
  `CreatePaymentBatch`; they are paid in the internet bank and registered on
  the invoice. Offsetting a credit note against an invoice is refused when
  either is in another currency, since it would need foreign amounts and an
  exchange difference of its own.
- **Shown.** The invoice page shows the foreign total and booking rate next to
  the kronor, what remains in the currency, and each payment's foreign amount
  and exchange gain or loss; the list shows the foreign total and remainder
  under the kronor.

## Not built yet (next steps)

- **Unrealised differences at year-end.** Open payables in foreign currency
  are not revalued at the closing rate at bokslut. Next step: a closing-check
  item listing open foreign invoices with a proposed voucher at the closing
  rate (and its reversal on the first day of the next year), on 3960/7960.
  `TODO(legal-verify)`: K2 (BFNAR 2016:10) point 10.7 and K3 chapter 30 on
  valuation of monetary items at the closing rate, and whether a company on
  kontantmetoden does this for the unpaid invoices it books at bokslut.
- Foreign currency in payment files (pain.001 with a foreign `Ccy` and IBAN
  creditor accounts) and offsets between foreign documents.
- Customer invoices in foreign currency (receivables, 1510) with the same
  rules.
- Currencies with other than two decimals (JPY, ISK) are stored as
  hundredths like the rest; Riksbanken quotes some per 100 units.
  `RiksbankRates` does not adjust for that yet.

## Open legal questions

- `TODO(legal-verify)`: that Riksbanken's mid rate on the invoice date is an
  acceptable booking rate (ML 8 kap. 21 § names the ECB rate for the VAT
  base; the two can differ slightly).
- `TODO(legal-verify)`: that realised differences on trade payables belong on
  3960/7960 ("av rörelsekaraktär") and not among the financial items in
  class 8 (which the BAS data in this repository does not list).
