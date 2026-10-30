# ADR-015: Incoming payments from the bank's file (camt.054)

- Status: Accepted
- Date: 2026-10-30
- Task: Phase 2, round 4

## Context

Bankgirot's BgMax file stops at the end of 2026 (payments in the new
infrastructure were left out of BgMax from 8 September 2026); banks deliver
incoming payments as ISO 20022 camt.054 instead. Sources: Handelsbanken,
*Conversion recommendations for the BgMax format – guide for implementing
camt.054*, version 1.0 (March 2025), and the camt.054.001.02 XSD from
iso20022.org, against which the test fixture was validated.

## Decisions

- **Reading.** `CreditNotification` reads `BkToCstmrDbtCdtNtfctn` by local
  element names, so camt.054.001.02 and later versions are read alike. Only
  booked credits are taken (`CdtDbtInd` CRDT, `Sts` BOOK or absent). Each
  `TxDtls` is one payment, its amount from `TxAmt`, else `InstdAmt`, else the
  entry when it has one transaction; an entry without details is one payment.
  OCR comes from `Strd/CdtrRefInf` with code SCOR (how the bank reports an
  identified OCR); free text from `Ustrd` and `RfrdDocInf/Nb`; the payer from
  `RltdPties/Dbtr/Nm`.
- **Safety.** Files with a DOCTYPE are refused and the network is never used
  while parsing, so no entity tricks are possible.
- **Never twice.** Each payment is stored with the bank's reference
  (`AcctSvcrRef`, else `ClrSysRef`), unique per company; without one, a
  fingerprint of date, amount, OCR, message and payer, numbered within the
  file. A payment already read from another file is skipped.
- **Matching is a suggestion.** An unpaid invoice whose OCR number equals the
  payment's OCR, or a whole number in its message, is suggested if the amount
  does not exceed what remains. The user can change or clear every suggestion
  and books the chosen ones; booking goes through `RegisterPayment` on the
  bank's booking date (series C), so fakturametoden and kontantmetoden are
  handled as for a payment entered by hand.
- **Records.** The file is stored as received with its SHA-256; transactions
  keep what the bank said (trigger), gaining only the invoice and, once, the
  payment.

## Not built yet

- Overpayments, payments in other currencies and one payment for several
  invoices (Extended Remittance Information).
- Supplier payments confirmed from camt.054 debit notifications (ADR-014).
- Reading camt.053 account statements for bank reconciliation (Phase 3).
