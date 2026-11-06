# ADR-057: Import VAT on goods from outside the EU

- Status: Accepted
- Date: 2026-11-06
- Task: Track D.2, follows ADR-016 ("Not built yet: import of goods from outside the EU")

## Context

A VAT-registered company that imports goods from outside the EU declares the
import VAT itself in the momsdeklaration and deducts it in the same return.
The base is not the supplier's invoice but what Tullverket's tullräkning
says. ADR-016 left this to be booked by hand.

## Sources (read 2026-11-06, quoted in `App\Domain\Vat\ImportVat::SOURCES`)

- Mervärdesskattelag (2023:200), riksdagen.se
  (https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/mervardesskattelag-2023200_sfs-2023-200/):
  - 8 kap. 24 §: "Beskattningsunderlaget vid import utgörs av varans värde för
    tulländamål, fastställt av Tullverket, enligt unionstullkodexen."
  - 8 kap. 25 §: duty and other state taxes or charges, except VAT, that
    Tullverket takes at import and that are not already in the value are added.
  - 8 kap. 26–27 §§: bikostnader (provision, packaging, transport, insurance)
    up to the first destination in Sweden are added; a later destination in
    Sweden or another EU country counts when it is on the transport document.
  - 7 kap. 27 §: output VAT on import "ska redovisas för den
    redovisningsperiod då Tullverket har ställt ut en tullräkning eller ett
    tullkvitto för importen".
  - 7 kap. 41 §: the input VAT is deducted for the same period.
- Skatteverket, *Köpa varor från länder utanför EU*
  (https://www.skatteverket.se/foretag/moms/kopavarorochtjanster/inkopfranlanderutanforeu/kopavarorfranlanderutanforeuimport.4.361dc8c15312eff6fd37208.html):
  base in fält 50 in kronor, output VAT in 60–62 by rate, deduction in 48;
  the period follows the tullräkning "oavsett om du använder bokslutsmetoden
  eller faktureringsmetoden"; the rate is the rate for a sale in Sweden; the
  examples 105 000 + 12 600 = 117 600 and 92 000 + 8 000 = 100 000. A company
  that is not VAT-registered pays the VAT to Tullverket instead.
- BAS kontoplan 2026 (bas.se, `BAS_kontoplan_2026_v2.xlsx`, header "v 1.1"):
  4080 Import av handelsvaror with 4085–4087 "Import av handelsvaror, 25/12/6 %
  moms"; 4540 Import av råvaror och material with 4545–4547; 2615/2625/2635
  "Utgående moms import av varor, 25/12/6 %"; 2645 "Beräknad ingående moms på
  förvärv från utlandet"; 5721 Tullkostnader, 5722 Speditionskostnader. The
  chart has no 459x counter account.

## Decisions

- **The tullräkning is booked as a voucher, proposed and confirmed.**
  `ProposeImportVat` takes the tullräkning's date, number, kind of goods
  (handelsvaror or råvaror och material) and one line per rate with
  tullvärde, tull och avgifter and bikostnader in kronor. It returns the
  rows; the voucher editor fills them in under **Importmoms från
  tullräkning**, and the person checks them, attaches the tullräkning and
  posts through `SaveDraftVoucher` and `PostVoucher`. Nothing is booked
  without that step, and immutability, numbering and the audit log are the
  ordinary ones. No new table: the voucher and its underlag are the record.
- **Rows.** Per rate: the base debit on 4085/4086/4087 (or 4545/4546/4547)
  and the VAT, the rate on the sum of that rate's bases rounded half up to
  öre, credit on 2615/2625/2635. Then the total base credit on the parent
  account 4080 (or 4540) and the total VAT debit on 2645. The voucher
  balances and leaves the result untouched: the goods are a cost from the
  supplier's invoice, duty and the forwarder's fee from their own invoices.
- **Box 50** now reads 4085–4087 as well as 4545–4547 (`VatBoxes`). 4080 and
  4540 feed no box. Boxes 60–62 and 48 already read 2615/2625/2635 and 2645.
  `MomsPayload` reads the boxes, so the Skatteverket API submission needed no
  change.
- **Period.** The voucher date is the tullräkning's date whatever the
  company's method, so under kontantmetoden too the VAT lands in the period
  of the tullräkning, not when the supplier is paid (ML 7 kap. 27 och 41 §§).
- **A changed decision** that lowers the base is entered as the reduction
  with **Ändrat beslut som sänker underlaget**: the same rows the other way
  round, dated with the decision (the date field is then labelled "Datum
  för Tullverkets ändrade beslut"). `TODO(legal-verify)`: which period a
  lowering decision belongs to, the decision's or the original
  tullräkning's; ML 7 kap. 27 § names only the tullräkning. A raise is a new tullräkning. A reduction may lower only the duty or
  the bikostnader, so it does not require a customs value.
- **Supplier invoices** get the VAT treatment `NON_EU_GOODS`, **Varor från
  ett land utanför EU (import)**: no Swedish VAT on the invoice and no VAT
  calculated from it (`buyerIsLiable()` false, `chargesSwedishVat()` false).
  The user chooses the cost account, but not 4085–4087 or 4545–4547: they
  feed box 50, so the goods would be counted twice (the tullräkning's
  voucher already carries the base). Currency works as in ADR-025; the
  customs value in kronor from the tullräkning is what is declared, not the
  invoice at its booking rate. The database check on
  `supplier_invoices.vat_treatment` is widened by a migration; the API's
  `vat_treatment` enum and the AI agent's schema list the new value.
- Missing accounts are added to the company's chart with their BAS names
  when the proposal is made, as for purchases from abroad (ADR-016), with an
  audit entry (`account.created`, reason `import_vat`).

## Not built / open

- `TODO(legal-verify)`: BAS 2026 has no counter account for the import base;
  using the parent accounts 4080 and 4540 is a design choice to confirm with
  BAS or an accountant.
- `TODO(legal-verify)`: full deduction is assumed. Partial deduction
  (blandad verksamhet, ML 13 kap.) is left to the person, who changes the
  2645 row before posting.
- Centralised clearance in another EU country (ML 7 kap. 27 § andra stycket,
  16 kap. 21–22 §§) is not handled separately.
- A company that is not VAT-registered pays import VAT to Tullverket; the
  form does not stop such a company, and the manual says not to use it.
- Fixed at the same time: BAS 2026's 4075–4077 (Inköp av handelsvaror från
  annat EU-land, 25/12/6 % moms) now feed box 20 and 4065–4067 (Inköp av
  handelsvaror i Sverige, omvänd betalningsskyldighet) box 23 (`VatBoxes`).
- A tullräkning has at most one line per rate (three lines); non-scalar
  input is a validation error.
