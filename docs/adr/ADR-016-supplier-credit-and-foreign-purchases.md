# ADR-016: Supplier credit notes and purchases where the buyer accounts for VAT

- Status: Accepted
- Date: 2026-10-30
- Task: Phase 2, round 5

## Sources

- Skatteverket, *Fylla i momsdeklarationen* (boxes 20–24, 30–32, 48, 50,
  60–62) and the pages on buying goods and services from abroad.
- Mervärdesskattelag (2023:200), 8 kap. 21–24 §§ (exchange rates, import
  value) and 17 kap. 24 § (invoice contents, "omvänd betalningsskyldighet").
- BAS kontoplan 2026 v2 (bas.se). Account names in `VatTreatment` are copied
  verbatim from it.

## Decisions

- **Who accounts for the VAT** is chosen per supplier invoice
  (`vat_treatment`): a Swedish invoice with VAT (as before), goods or
  services from another EU country, services from outside the EU, and goods
  or services under Swedish reverse charge.
- When the buyer accounts for the VAT, the invoice carries no VAT. Each row
  has a rate (25, 12 or 6 %) and is booked on the BAS account that feeds the
  right box of the VAT return: 4515–4517 (box 20), 4535–4537 (21), 4531–4533
  (22), 4415–4417 (23), 4425–4427 (24). The VAT is the rate on the sum of the
  rows of that rate and is booked as output VAT on 2614/2624/2634 (boxes
  30–32) and as input VAT on 2645, or 2647 for Swedish reverse charge (box
  48). The existing VAT report reads these accounts, so nothing in it changed.
  Missing accounts are added to the company's chart when first needed.
- Under kontantmetoden the same rows are booked at payment, in proportion.
- **Credit notes** are supplier invoices with `kind` CREDIT and negative
  amounts, entered as printed (positive) and stored negative, so the same
  booking rules reverse them. They are approved like invoices. A credit note
  is settled by offsetting it against an approved invoice from the same
  supplier (a voucher in series E that moves the amount within 2440, or books
  the shares of costs and VAT against each other under kontantmetoden), or by
  registering money paid back. Credit notes never go into a payment file.

## Not built yet

- **Import of goods from outside the EU** (boxes 50, 60–62): the VAT base is
  the customs value on Tullverket's bill, not the supplier's invoice, so it
  needs its own entry. Until then it is booked by hand.
- **Invoices in foreign currency.** Amounts are entered in kronor.
  ML 8 kap. 21 § says to convert with the latest rate published by the ECB
  (or the most representative Swedish market rate) when the taxable event
  occurs. `TODO(legal-verify)`: which date is used for goods from another EU
  country (delivery or invoice date, ML 13 kap. 12 §).
- `TODO(legal-verify)`: whether every supplier from outside the EU must show
  a VAT number; the system does not require one.
- Partial deduction (blandad verksamhet, 2649).
