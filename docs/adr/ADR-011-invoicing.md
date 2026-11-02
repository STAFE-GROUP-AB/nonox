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
  the largest line. Vouchers are made through `BookVoucher`, which uses the
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

