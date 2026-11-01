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
  with the öre left over on the largest row, and the first payment carries the
  invoice file as underlag. All vouchers go through `BookVoucher`, which gained
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
- `TODO(legal-verify)`: under kontantmetoden, unpaid supplier invoices at the
  end of the fiscal year must be booked as liabilities at bokslut.
