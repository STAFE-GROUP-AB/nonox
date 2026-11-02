# ADR-013: Supplier invoices (leverantörsreskontra)

- Status: Accepted
- Date: 2026-10-30
- Task: Phase 2, round 2

## Decisions

- **Supplier register.** Suppliers get running numbers from `number_series`
  (`suppliers`). Bankgiro and plusgiro are checked with their Luhn check
  digit and stored formatted, so a payment never goes to a mistyped number.
  A supplier can have a default account (for example 4010 for goods), used
  on a cost row left without an account.
- **Entering an invoice.** The user types what is printed on the invoice:
  the amount to pay including VAT, the VAT (all rates together) and cost rows
  excluding VAT, each with an account and optionally a cost centre or
  project, for example one per restaurant. The rows plus the VAT must equal
  the total; the browser shows what is left to distribute while typing
  (rows live in Alpine, like sales rows in ADR-011) and the server checks it
  again. Typing the VAT as an amount, not per rate, matches how wholesaler
  invoices with both 12 % and 25 % lines are read.
- **The invoice file.** The PDF or image is stored with the draft. Its type is
  read from the content, as for voucher underlag. When the invoice is booked
  the file is linked to the voucher as underlag (same stored file, new
  `voucher_attachments` row with its SHA-256); it is never copied or removed.
- **Registering.** A draft can be changed and deleted. Registering gives it the
  next number from `number_series` (`supplier_invoices`), a snapshot of the
  supplier, and books it. After that its contents never change: the model and
  a PostgreSQL trigger refuse it. Only status, approval and paid amount change.
- **Booking.** Under *fakturametoden* registering posts a voucher in series D,
  dated on the invoice date: cost rows debit (with their dimensions), 2641
  input VAT debit, 2440 Leverantörsskulder credit. A payment posts 2440 against
  1930 in series E. Under *kontantmetoden* nothing is posted when
  registering; each payment posts its share of the costs and VAT against 1930,
  with the öre left over on the largest row (the payment that settles the
  invoice takes what is left of each row, 2026-11-02, AUD-FAKT-3, #52), and
  the first payment carries the invoice file as underlag. Exception (2026-11-02, AUD-FAKT-4, #50): an
  intra-Community acquisition of goods is reported for the invoice's period
  (ML 2023:200 7 kap. 26, 40 §§), so it is booked at registration under
  kontantmetoden too (`SupplierInvoiceBooking::bookedAtRegistration`). All vouchers go through `BookVoucher`, which gained
  a `rows()` form for rows with text and dimensions.
- **Approval (attest) and segregation of duties.** A registered invoice waits
  for approval by a user with `supplier-invoices:approve`. While the company
  setting `separate_supplier_approval` is on (default, AGENTS.md §4) the user
  who created or registered the invoice may not approve it. A company with
  one person turns it off from the supplier invoice page (`company:update`).
  Only an approved invoice can be paid (`payments:create`). Part payments are
  allowed; the invoice is paid when nothing remains.
- **Who sees it.** Anyone with a payables permission or `vouchers:view` reads
  supplier invoices, so the auditor and the approver can, the bookkeeper
  registers them.
- **Navigation.** A top-level tab, Inköp, holds supplier invoices; suppliers
  are under Register with customers and articles. The key figures gain "Att
  betala till leverantörer" with overdue invoices and invoices waiting for
  approval.

## Not built yet

- Supplier credit notes (negative invoices) and reverse charge / EU and
  non-EU purchases (2614/2645 and VAT boxes 20–24, 30–32, 50, 60–62).
  `TODO(legal-verify)`: which boxes and accounts for each kind of purchase.
- Payment proposals and payment files (ISO 20022 pain.001, Bankgirot LB) and
  reading the bank's confirmation (camt.054). Payments are registered by hand
  until then.
- Invoices in other currencies (built in ADR-025).
- Email-in of invoices and reading them automatically (optional module).
- Kontantmetoden at year-end: built 2026-11-02, see below.

## Kontantmetoden at year-end (2026-11-02)

Rule ("enligt AI-utredning 2026-11-02, juristgranskning återstår"):
Bokföringslagen (1999:1078) 5 kap. 2 §: a company may wait with booking
non-cash business events until they are paid, but "Vid räkenskapsårets
utgång ska dock samtliga då obetalda fordringar och skulder bokföras."
BFNAR 2006:1 punkt 4.2 c and 6.56–6.57 (kundfordringar at what was invoiced
and not paid, including VAT). Sources read 2026-11-02:
https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/bokforingslag-19991078_sfs-1999-1078/
and https://www.bfn.se/wp-content/uploads/2020/06/bfnar06-1-grund.pdf.

- **A closing step** on the closing page, "Obetalda fakturor vid årets slut"
  (`YearEndClosing::bookUnpaidInvoices`, `UnpaidInvoices`, `OpenInvoices`).
  It lists the customer and supplier invoices unpaid on the last day of the
  fiscal year: the invoice total less payments (in kronor as settled, so at
  the invoice's own rate) and credit invoices dated up to that day. A
  payment after the last day does not count. A customer invoice credited in
  part is unpaid for the rest.
- **Booking.** One voucher per side, in series A, on the last day, with the
  fiscal year as subject and source CLOSING, through `BookVoucher` and so
  `PostVoucher`:
  - customer invoices: 1510 debit the unpaid total, against the revenue
    accounts, output VAT and öresavrundning as `InvoiceBooking::revenue`
    books them under fakturametoden, each invoice's share as a payment of
    the unpaid amount would book it under kontantmetoden (with the credit
    invoices' lines added for a part credit);
  - supplier invoices and credit notes: the costs (with cost centre and
    project), input VAT and, for reverse charge, the output VAT, as
    `SupplierInvoiceBooking::payment` books the unpaid amount under
    kontantmetoden, against 2440.
  Rows are summed per account (and dimension).
- **Reversal.** Each voucher is reversed by a voucher with the opposite
  signs on the first day of the next fiscal year, which must exist. The
  payment in the next year is then booked as usual under kontantmetoden
  (revenue or cost and VAT against the bank), so nothing is booked twice and
  the next year's revenue, costs and VAT net to zero for these invoices.
  This is the same technique as the year-end currency revaluation
  (ADR-025). Booking the payment against 1510/2440 instead would need the
  payment flow to know about the year-end entry; reversal keeps it
  unchanged. `TODO(legal-verify)`: neither BFL nor BFNAR 2006:1 says how the
  year-end entry is undone; confirm that reversal on the first day is an
  acceptable method.
- **VAT.** The VAT is booked on the usual VAT accounts (2611/2621/2631,
  2641, and for reverse charge the 26xx pair) on the last day. The VAT
  return is calculated from the ledger, so if the VAT period holding the last
  day is not yet settled, the VAT on these invoices is in that return and the
  reversal and payment cancel out in the next year; if it is already settled,
  the VAT stays on the VAT accounts over the year end and is reported in the
  period of the payment. The UI asks the user to book this step before the
  VAT of the last period. The VAT report logic is unchanged.
  `TODO(legal-verify)`: in which VAT period a company on kontantmetoden
  reports the VAT on invoices unpaid at year-end (Mervärdesskattelag
  2023:200, 7 kap.). Not verified.
- **Order and once only.** After the last day of the year (so the payments
  up to that day are in), once per year, before the currency revaluation and
  the year's result. Kept as `year_end_entries` rows of type
  `UNPAID_RECEIVABLES` (amount = the receivable booked) and `UNPAID_PAYABLES`
  (amount = the liability booked), one per side that had anything, with the
  voucher; the audit entry `closing.unpaid_invoices_booked` carries both
  vouchers and both reversals.
- **Checklist.** The point "Obetalda fakturor vid årets slut är bokförda"
  blocks closing (TODO) while there are unpaid invoices and the step is not
  booked; it is OK when booked, and not applicable under fakturametoden or
  when nothing is unpaid. Like the currency point, it can be confirmed as
  booked by hand (`closing_checks` key `unpaid`, with who and when, audited
  as `closing.checked`), for a company that booked the entry manually.
- **Not built.** The 3 MSEK turnover limit for kontantmetoden (BFL 5 kap.
  2 §) is not checked.
