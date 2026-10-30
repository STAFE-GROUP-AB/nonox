# ADR-014: Payment files for supplier invoices (pain.001)

- Status: Accepted
- Date: 2026-10-30
- Task: Phase 2, round 3

## Context

Bankgirot's Leverantörsbetalningar (LB) file format could no longer be used
after 12 May 2026; Swedish banks take supplier payments as ISO 20022
CustomerCreditTransferInitiation, pain.001.001.03. The banks follow the
same schema but differ in a few fields. Sources read for this decision:

- Handelsbanken, *ISO 20022 CustomerCreditTransferInitiation pain.001.001.03*,
  implementation guide version 1.1.1 (1 July 2025), and *Country Specific
  Information for the future new Swedish payment infrastructure*, version
  1.0.1 (18 December 2025).
- Nordea, *Message Implementation Guide pain.001.001.03*, version 3.3, and
  *Examples per payment type – pain.001.001.03 Nordic countries*, version 2.4
  (7 October 2026).
- SEB, *ISO 20022 Message Implementation Guideline pain.001.001.03 and
  pain.002.001.03*, version 26.4.0.0, valid from 5 October 2026.
- Danske Bank, *MIG CGI-MP Customer Credit Transfer pain.001.001.03* v3.6
  (10 July 2026), *Appendix* v5.3 (5 August 2026, appendix L: new Swedish
  clearing) and *Supported payment types and examples* v5.3 (18 August 2026).
- Länsförsäkringar Bank, *Message Implementation Guidelines pain.001.001.03
  and pain.002.001.03*, version 1.1 (12 November 2025).
- The pain.001.001.03 XSD published by iso20022.org. Files made for both
  banks were validated against it while building (the XSD is not committed).

## Decisions

- **Format.** One `PmtInf` per file (one debit account, one execution date),
  `PmtMtd` TRF, service level NURG, UTF-8. Debtor account as IBAN, debtor
  agent as the bank's BIC. Amounts with two decimals, `CtrlSum` and
  `NbOfTxs` in both the group header and the payment information.
- **Payees.** Bankgiro (`CdtrAcct/Id/Othr`, `SchmeNm/Prtry` BGNR) and plusgiro
  (`SchmeNm/Cd` BBAN). An OCR reference goes in `Strd/CdtrRefInf` with code
  SCOR; without OCR a short message "Faktura <supplier's invoice number>" goes
  in `Ustrd`. Payments to bank accounts and abroad are not offered yet.
- **Per bank** (`PaymentBank`):
  - Handelsbanken: initiating party id with scheme `BANK`, category purpose
    `SUPP`, creditor agent as clearing member id under `SESBA`, 9900 for
    bankgiro and 9500 for plusgiro.
  - Nordea: initiating party id with scheme `CUST` (the agreement id), no
    category purpose, creditor agent as BIC (`BGABSESS` for bankgiro,
    `NDEASESS` for plusgiro) as in Nordea's examples.
  - SEB: scheme `CUST` (the EDI agreement customer number), service level
    `Prtry` MPNS ("required" for giro payments), creditor agent SESBA 9900
    for bankgiro and 9960 for plusgiro.
  - Danske Bank: scheme `CUST`, service level NURG, debtor and creditor agent
    as BIC DABASESX with country SE, as in every Swedish example of Danske's.
    The guide gives no other rule for giro payments.
  - Länsförsäkringar: no scheme code for the initiating party (the example
    is "16" followed by the organisation number), no service level (only
    NPCA is accepted if sent), creditor agent SESBA 9900 / 9960.
  - Swedbank and the savings banks (which use Swedbank's guide): not offered
    until Swedbank's MIG 1.0 pain.001 (updated 28 April 2026) has been read;
    it is only downloadable through Swedbank's site in a browser.
  - Files for all five banks were validated against the ISO XSD.
- **Whose details.** Payee details come from the supplier snapshot taken when
  the invoice was registered, not from the register now. A changed bank number
  in the register cannot redirect an invoice that was already approved, a
  common fraud pattern.
- **Batches.** Only approved invoices that are not in another open batch can be
  added; each is paid for what remains. The file is stored as made, with its
  SHA-256, and a trigger keeps the batch unchanged except for its status. When
  the bank has paid, the user confirms the batch and every invoice gets its
  payment on the execution date (series E, through `PaySupplierInvoice`). If the
  bank did not pay it, the batch is cancelled and the invoices can be paid
  again. Nothing is booked when the file is made.

## Open

- `TODO(verify-with-bank)`: each company sends a first file through its bank's
  test service before real use; the UI says so.
- Reading the bank's status report (pain.002) and debit notifications
  (camt.054) to confirm batches automatically.
- Signing in the bank is the bank's approval of the payment; within NONOX the
  approval is the attest of the invoice.
