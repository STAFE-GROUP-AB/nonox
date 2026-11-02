# ADR-011: Customer invoicing

- Status: Accepted
- Date: 2026-10-29
- Task: Phase 2, round 1

## Decisions

- **Registers.** Customers get running numbers from `number_series`
  (`customers`); personal numbers are encrypted at rest. Articles have a
  price excluding VAT, a VAT rate (25, 12, 6 or 0 %) and a revenue account,
  by default 3001/3002/3003/3004 for the rate.
- **Drafts and issuing.** An invoice is a draft until it is issued. Issuing
  gives it the next number from `number_series` (`invoices`, gapless, shared
  by invoices and credit invoices), an OCR reference (the number plus a Luhn
  check digit), and a snapshot of the customer as it is then. After that its
  contents never change: the model and a PostgreSQL trigger refuse it. Only
  status, paid amount and sending details change.
- **Amounts.** A row is quantity × price less discount, rounded to öre. VAT is
  calculated per rate on the sum of that rate's rows. The total is rounded to
  whole kronor; the difference is shown as öresavrundning and booked on 3740.
- **Booking.** Under *fakturametoden* issuing posts a voucher in series B:
  1510 debit, revenue accounts and output VAT (2611/2621/2631) credit, 3740
  for rounding. A payment posts 1930 against 1510 in series C. Under
  *kontantmetoden* nothing is posted when issuing; each payment posts its
  share of revenue, VAT and rounding against 1930, with the öre left over on
  the largest line; the payment that settles the invoice books what is left
  of each line after earlier payments and credits, so the payments add up
  to the invoice exactly (2026-11-02, AUD-FAKT-3, #52). Exception (2026-11-02, AUD-FAKT-4, #50): EU sales of
  goods and services in the periodisk sammanställning are reported for the
  invoice's period whatever the method (ML 2023:200 7 kap. 29-30 §§), so
  they are booked at issue under kontantmetoden too, and their payment only
  clears 1510 (`InvoiceBooking::bookedAtIssue`). Vouchers are made through `BookVoucher`, which uses the
  same SaveDraftVoucher and PostVoucher as manual vouchers, and point back to
  the invoice (`source_type`, `source_id`; `source_id` became text because
  invoices have UUIDs).
- **Credit invoices.** A credit starts as a draft with the original's rows
  and negative quantities; the rows can be changed to credit only part of
  it. When it is issued it settles the original by its own amount, in
  kronor and in the invoice's currency (`paid_ore`, `foreign_paid_minor`),
  and `credited_ore` keeps the part of `paid_ore` that credits settled. The
  original stays open for the rest and is closed only when nothing remains
  (CREDITED when only credits settled it, PAID when payments did too). A
  credit larger than what remains, or not below zero, is refused. Its
  voucher reverses the credited part of the original's (enligt granskning
  2026-11-02, juristgranskning återstår (AUD-FAKT-13, #47); ML 17 kap.
  22-23 §§, BFL 4 kap. 1 §, 5 kap. 1 §).
- **Credits of paid invoices.** A paid or part-paid invoice can be credited
  (ML 17 kap. 22-23 §§, 7 kap. 46 §). The credit settles what remains of the
  original first; the rest stays open on the credit invoice (ISSUED,
  negative remaining, credit on 1510) as the customer's credit balance. A
  credit larger than the original less earlier credits is refused. The
  balance is paid back with `RegisterRefund`, a negative `invoice_payments`
  row on the credit invoice booked like a payment with the signs reversed
  (series C; under kontantmetoden the refund's share of the credited
  revenue and VAT; in another currency with the exchange difference).
  Offsetting a credit balance against another invoice of the same customer
  is not built yet (enligt granskning 2026-11-02, juristgranskning återstår
  (AUD-FAKT-8, #53)).
- **ROT and RUT on a credit invoice.** The credit takes the original's
  type and deduction, never recomputed at the credit's date, rate or
  ceiling: the original's deduction in proportion to the labour (including
  VAT) the credit credits, rounded down to whole kronor; the credit that
  credits the last of the labour takes the rest, and none takes more than
  is left (enligt granskning 2026-11-02, juristgranskning återstår
  (AUD-FAKT-1, #48); ML 17 kap. 22 §, lag (2009:194) 7 §).
- **ROT and RUT already paid by Skatteverket.** A credit takes its
  deduction off what is still claimed on 1513 first; the rest, which
  Skatteverket has paid, is a debt (lag (2009:194) 13-16 §§: notify,
  Skatteverket decides repayment) booked on 2890 and kept on the credit
  invoice (`tax_reduction_repayable_ore`). The repayment is registered on
  the credit invoice (`RegisterTaxReductionRepayment`, 2890 against the
  bank in series C, `tax_reduction_repaid_ore`). The UI warns before the
  credit and reminds the user to notify Skatteverket (enligt granskning
  2026-11-02, juristgranskning återstår (AUD-FAKT-9, #49)).
  TODO(legal-verify): 2890 as the account.
- **Companies not registered for VAT** (2026-11-02, AUD-FAKT-5, #51). The
  company settings keep why a company is not registered (`teams.vat_exemption`:
  the small-business exemption in ML 2023:200 18 kap. 4 §, only exempt
  supplies under 10 kap., or a registration that is pending). Such a company
  cannot issue an invoice with VAT unless the draft carries an explicit
  confirmation (`invoices.unregistered_vat_confirmed`), since VAT charged
  wrongly must be paid to the state (16 kap. 23 §). An invoice without VAT
  keeps the exemption wording in `invoices.vat_exemption_text` when issued
  (17 kap. 24 § p. 13, 28 § p. 6) and the PDF prints it. The VAT page of an
  unregistered company shows any output VAT on 2611/2621/2631 with the
  16 kap. 23 § note. TODO(legal-verify): the wording, and how such VAT is
  reported and paid.
- **Payments** are kept in `invoice_payments` (append-only) with their
  voucher. A payment larger than what remains is refused.
- **Sending.** The PDF (ADR-002) is emailed to the customer's invoice address
  or another address; replies go to the company's email. Sent time and
  address are kept.
- Permissions follow AGENTS.md §4: `invoices:create` writes drafts and
  registers, `invoices:send` issues and sends, `invoices:credit` credits,
  `payments:create` registers payments, `invoices:view` reads.

## Quotes and orders

- `sales_documents` holds quotes (offerter) and orders, each with its own
  number series. They can be changed while open. A quote becomes an order,
  and a quote or an order becomes a draft invoice, row for row; the document
  then points to what it became. Neither is bookkeeping: nothing is posted
  until the invoice is issued. Both can be sent as a PDF.
- Rows are parsed by `SalesRowInput` for invoices, quotes and orders alike,
  so the three always calculate the same way.

## Rows in the browser

- While an invoice, quote or order is being written, its rows live in the
  browser (Alpine, `resources/js/sales-rows.js`): filling from an article,
  each row's amount and the totals follow every keystroke without a request.
  The rows reach the server only when the document is saved. Livewire 4's
  `wire:model.blur` no longer sends anything, and `.live.blur` made each field
  a request whose answer could overwrite the next field being typed.
- The browser calculates exactly like `InvoiceCalculator` (BigInt öre, half
  away from zero). `tests/js/sales-math.test.mjs` checks the same cases as the
  PHP tests (`npm test`, Node's built-in runner, no packages); CI runs it.
  The server recalculates on save, and that is what counts.

## Reminders

- An overdue, unpaid invoice can be reminded by email: what remains, the OCR
  number, a new date to pay by (ten days) and the invoice attached. Each
  reminder is kept (`invoice_reminders`, append-only).
- `TODO(legal-verify)`: no reminder fee and no late-payment interest are
  charged until it is confirmed when they may be.

## Not yet

- Reverse charge, EU and export invoices (the texts required by the VAT act)
  and foreign currency. Until then every invoice is a domestic invoice.
- Reminder fees and late-payment interest, Peppol, bank file
  matching of payments (camt.054/BgMax), recurring invoices.

## TODO(legal-verify)

- Required invoice content (mervärdesskattelagen): the PDF shows seller and
  buyer, VAT number, date, number, delivery date, quantities, prices, VAT
  per rate and the total. Confirm nothing is missing for simplified and full
  invoices.
- Kontantmetoden: invoices unpaid at the end of the fiscal year are booked
  as receivables at bokslut, built 2026-11-02 (see ADR-013, "Kontantmetoden
  at year-end"). Still open: the reversal method and the VAT period.

## Agreements (avtalsfakturering), added 2026-10-30

- An agreement holds a customer, rows (as on an invoice), an interval of 1,
  3, 6 or 12 months and the next invoice date. The day of that date is kept,
  so an agreement on the 31st invoices on the last day of shorter months.
- Each run makes an ordinary invoice through `SaveDraftInvoice`, linked by
  `invoices.recurring_invoice_id`. By default it is a draft to check; with
  "send automatically" it is issued (booked) and emailed through the same
  actions as by hand. A changed agreement never changes invoices already made.
- `invoices:run-agreements` runs every morning at 06:00 as the user who
  created each agreement, with that user's rights, and catches up missed
  dates (at most twelve per agreement and run). Past the end date the
  agreement ends; paused agreements are skipped.

