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
- **Credit invoices.** Only an unpaid invoice can be credited, as a whole: a
  draft with the same rows and negative quantities. When it is issued it
  settles the original (status CREDITED) and is itself settled, and its
  voucher reverses the original's.
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

## Not yet

- Reverse charge, EU and export invoices (the texts required by the VAT act)
  and foreign currency. Until then every invoice is a domestic invoice.
- Reminders and late-payment interest, Peppol, bank file
  matching of payments (camt.054/BgMax), recurring invoices.

## TODO(legal-verify)

- Required invoice content (mervärdesskattelagen): the PDF shows seller and
  buyer, VAT number, date, number, delivery date, quantities, prices, VAT
  per rate and the total. Confirm nothing is missing for simplified and full
  invoices.
- Kontantmetoden: invoices unpaid at the end of the fiscal year must be
  booked as receivables at bokslut (Phase 3).
