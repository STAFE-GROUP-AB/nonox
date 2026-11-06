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
- Swedbank, *MIG CustomerCreditTransferInitiation pain.001.001.03* version
  1.0.0 and *MIG Appendix* version 1.1.0 (service 1.0, which covers supplier
  and bankgiro/plusgiro payments; also used by the savings banks), with
  Swedbank's own pain.001.001.03 XSD.
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
  in `Ustrd`. Payments abroad were not offered at first; see "Payments abroad in a foreign currency" below.
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
  - Swedbank and the savings banks: scheme `BANK` (agreement id format
    nnnnnnnnnnnnAnnn), service level NURG, category purpose SUPP, debtor
    account the company's own bankgiro (`Othr` + `Prtry` BGNR: "For
    Domestic payments use Othr/BGNR"), debtor agent SWEDSESS, creditor agent
    SESBA 9900 / 9960, and `RfrdDocAmt/RmtdAmt` beside an OCR reference
    ("Required for Bankgiro payments").
  - Files for all six banks were validated against the ISO XSD, and
    Swedbank's against Swedbank's own XSD too.
  - The camt.054 reader (ADR-015) was run against Swedbank's example credit
    notification and read every payment, OCR and payer correctly.
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
- **Approval of the batch** (added 2026-11-01 after a security review found
  `payments:approve` defined but never enforced). A new batch waits for
  approval by a user with `payments:approve` (owner, admin, approver) through
  `ApprovePaymentBatch`. While the company setting
  `separate_supplier_approval` is on (default, AGENTS.md §4) the user who made
  the batch may not approve it; it is the same setting that governs the attest
  of supplier invoices, so a one-person company turns off both with one
  switch. Until the batch is approved the file is not handed out for download
  and the batch cannot be confirmed as paid; it can still be cancelled. The
  approval (`approved_at`, `approved_by`) is written once, while the batch is
  open, and audited as `payment_batch.approved` with the file's SHA-256. The
  batch's file, amounts and items were already frozen when it was made; the
  trigger now also refuses changing or removing an approval and confirming an
  unapproved batch. Batches confirmed before this step have no approval and
  stay downloadable as history; open ones must be approved like new ones.

## The bank's answers (added 2026-11-02)

Sources read for this part:

- Handelsbanken, *ISO 20022 CustomerPaymentStatusReport pain.002.001.03*,
  implementation guide version 1.0.4 (19 March 2025), with its examples
  "Rejected transactions on execution day" and "Partially accepted file
  including accept on transaction level"
  (handelsbanken.com, Global Gateway, ISO 20022 XML; read 2026-11-02).
- Handelsbanken, *ISO 20022 Debit Notification camt.054.001.02*,
  implementation guide version 1.0.7 (23 February 2026), with its example
  "Debit notification Swedish local from bg no" (read 2026-11-02).
- SEB's and Länsförsäkringar's guides listed above cover pain.002.001.03 too.
- ISO 20022 External Code Sets (iso20022.org): ExternalPaymentTransactionStatus1Code
  for the statuses, ExternalStatusReason1Code and ExternalReturnReason1Code for
  the reasons (read 2026-11-02).

Decisions:

- **End-to-end id per payment.** A payment's EndToEndId is now
  `LF<invoice number>-<file number>` (at most 35 characters), unique per
  company, so an invoice paid in a second file after a rejection is told apart.
  Files made earlier used `LF<invoice number>`; they are still matched, with
  the file's message id when the bank gives it and otherwise the payment still
  waiting for the bank. The file's MsgId is also its PmtInfId, as before.
- **What is read.** pain.002 (any version, by local name): a status per
  payment (TxSts), per block (PmtInfSts) or for the whole file (GrpSts) when
  nothing more specific is given. RJCT is a rejection; ACCP, ACTC, ACSP, ACSC,
  ACWC and ACCC are acceptances; the rest (PDNG, RCVD, PART by itself) say
  nothing final and are skipped. camt.054 and camt.053: booked debits (Sts
  BOOK) per TxDtls, with Refs/MsgId, PmtInfId and EndToEndId ("NOTPROVIDED"
  counts as none); an entry without details but with NtryDtls/Btch is the
  whole file booked as one amount. A booked credit with RvslInd and the
  payer's references is a returned payment, as Handelsbanken's guide
  describes. Files are read with DOM without network or DOCTYPE (no XXE), at
  most 10 MB, like the camt.054 credit reader (ADR-015).
- **Idempotency.** A file is read once (SHA-256 per company). Each answer is
  stored once per kind and bank reference (AcctSvcrRef, or a fingerprint).
  A payment with a booked payment is never booked again; a second answer
  about it is "already handled".
- **Matching.** By reference first; otherwise, for debits only, the same
  amount in kronor, booked from 3 days before to 10 days after the file's
  payment day, to the same bankgiro or plusgiro when the bank names it, among
  payments neither paid nor rejected, and only when exactly one fits. These
  windows are our own choice, not a rule from any bank.
- **What is done automatically.** Only for answers matched by reference in a
  file read by a person with `payments:create`: acceptances are recorded;
  rejections mark the payment rejected with the bank's reason and the invoice
  is free to pay again; booked debits are paid through `PaySupplierInvoice`
  on the bank's booking day (series E, both accounting methods exactly as a
  manual payment), and only when the file is approved, the amount equals the
  file's and the invoice still owes it. Everything else — matches by amount,
  answers from NONOX Bank, returns, rejections of a payment already booked —
  waits for a person to confirm or dismiss. A file is closed when all its
  payments are paid or rejected (paid if any was paid, otherwise cancelled).
  A partly paid file cannot be cancelled, and "the bank has paid" books only
  the payments without an answer.
- **NONOX Bank.** Debits in kronor fetched through the bank connection
  (ADR-034) go through the same matcher; one that fits a payment becomes a
  suggestion, never a booking.
- **Currency.** Payment files pay kronor only, so no exchange difference
  arises here; an answer in another currency is left to a person.

## Open

- `TODO(verify-with-bank)`: each company sends a first file through its bank's
  test service before real use; the UI says so. The same goes for reading a
  first status report and debit notification: the synthetic test files follow
  Handelsbanken's guide and examples, not files received from each bank.
- Booking a returned payment (reversal) automatically.
- Signing in the bank is the bank's approval of the payment; within NONOX the
  invoice is attested and the payment file approved, by different people
  unless the company has turned separate approval off.

## Payments abroad in a foreign currency (added 2026-11-06)

Task D.5. Before this, an approved supplier invoice in another currency was
refused by `CreatePaymentBatch` and paid in the internet bank (ADR-025). It
can now be paid by file, in its own currency, from the company's kronor
account.

Sources read for this part (read 2026-11-06):

- Handelsbanken, *ISO 20022 CustomerCreditTransferInitiation Pain.001 version
  3, Country Specific Information*, version 1.8.1 (1 July 2025), chapter 6
  (Sweden), 6.2 *Cross-border payments*: the creditor's address with country
  is mandatory; creditor agent preferably BIC; creditor account as IBAN where
  available; `ChrgBr` mandatory; unstructured message at most 140 characters;
  "payments with an equivalent amount" (`EqvtAmt`) is an alternative that we do
  not use; EUR payments within Europe (SEPA) are classified by the bank
  itself with service level NURG or SEPA and category purpose SUPP;
  *Regulatory reporting cross-border payment* with `RgltryRptg/Dtls/Cd`
  (Skatteverket's payment codes, for payments above a counter value set by
  the tax authority).
- Nordea, *Corporate eGateway Message Implementation Guideline
  pain.001.001.03*, version 3.3 (16 September 2026): `ChrgBr` (SLEV is the
  default for SEPA and used also when SHAR is sent for a payment that
  qualifies; only SHAR or SLEV for payments to and from EU/EEA; DEBT not
  within the EEA); creditor agent "not required for payments to SEPA
  participant countries in any currency"; `RgltryRptg` "Sweden: Required for
  international payments above 150.000 SEK", with `Dtls/Ctry` required and
  `Dtls/Cd` three digits; `EqvtAmt` only for international payments from
  Denmark and Norway. *Examples per payment type – pain.001.001.03 Nordic
  countries*, version 2.4 (7 October 2026), example 6.1 (a cross-border
  payment with `InstdAmt` in USD, `CdtrAgt/BIC`, creditor `PstlAdr`,
  `RgltryRptg`).
- Skatteverket, *Kontrolluppgift om betalningar från eller till utlandet
  (KU80 och KU81)*: the reporting duty is tied to 150 000 kronor.

Decisions:

- **Which banks.** Only banks whose cross-border chapter has been read:
  Handelsbanken and Nordea (`PaymentBank::foreignPayments()`). For Danske
  Bank, SEB, Swedbank and Länsförsäkringar the invoice is still refused with
  the old advice (pay in the internet bank).
  `TODO(provider-verify)`: read their guides, then switch them on.
- **Which invoices.** Currency in AUD, CAD, CHF, CZK, DKK, EUR, GBP, NOK, PLN,
  USD (two decimals); the supplier needs an IBAN (from the snapshot taken at
  registration, like the bank details of a giro payment), a BIC unless the
  payment is a SEPA payment, a country other than Sweden and a town. Address
  and payment code come from the register as it is now, since they cannot
  redirect money. Accounts that are not IBANs (US, for instance) are not
  supported; `TODO(provider-verify)`.
- **Amount and debit account.** `InstdAmt` is the invoice's remaining amount
  in its currency, `DbtrAcct/Ccy` stays SEK and the bank converts (the cross
  currency payment both guides describe). `EqvtAmt` is not used: the invoice
  fixes the foreign amount, not the kronor.
- **Charges.** `ChrgBr` at transaction level: SLEV for EUR to an IBAN in the
  SEPA area (the EU, EEA, Switzerland and Monaco, as in Handelsbanken's
  guide), SHAR otherwise. DEBT and CRED are never written.
- **Blocks.** One `PmtInf` per currency, kronor first, and a SEPA euro block
  apart from other euro payments. The first block keeps the file's message
  id as `PmtInfId` (so files without foreign payments are unchanged), the
  rest add `/2`, `/3`. Each batch item stores its block (`payment_info_id`),
  so a status report about one block rejects only its payments.
  `GrpHdr/CtrlSum` sums the numbers irrespective of currency, as ISO 20022
  defines it; `TODO(provider-verify)` that both banks accept a mixed file.
- **Regulatory reporting.** The supplier has a three-digit `payment_code`
  (Skatteverket's list). It is written as `RgltryRptg/Dtls/Cd` whenever set
  (Nordea also gets `Ctry` SE). It is required, with a Swedish message, when
  the payment, at the invoice's booking rate, exceeds 150 000 kronor.
  `TODO(legal-verify)`: whether the limit is judged on the bank's kronor
  rather than on the booking rate; a payment close to the limit may need the
  code although the check passed.
- **Remittance.** `Ustrd` "Invoice <number>" (plus " ref <OCR>"), in English
  because the supplier abroad reads it. No structured Swedish OCR reference
  is written for payments abroad.
- **Names and characters.** Names are cut at 70 characters, the message at
  140. `TODO(provider-verify)`: Nordea replaces characters SWIFT does not
  allow; we do not transliterate.
- **Booking only on the bank's word.** Confirming the file by hand is refused
  while a payment in a foreign currency waits: the kronor are not known. The
  debit notification (camt.054 or camt.053) gives them. The reader keeps the
  instructed amount (`AmtDtls/InstdAmt`) when the entry was booked in kronor
  (`payment_responses.instructed_currency`, `instructed_amount_minor`). A
  payment abroad is booked on its own debit, by reference, through
  `PaySupplierInvoice` with the bank's kronor and the foreign amount, so the
  exchange difference is the bank's actual (ADR-025: 2440, 1930, 3960/7960).
  It is booked automatically only when the instructed currency and amount
  equal the file's, the kronor are within 5 percent (at least 500, at most
  5 000 kronor; our own limits, not a bank rule) of Riksbanken's mid rate on
  the bank's booking day, or of the kronor expected at the invoice's rate
  when no rate can be had, and the file is approved; otherwise a person confirms or the answer is left unmatched.
  A debit in a foreign currency without kronor, or one booked as a single
  amount for several payments, is never booked.
- **Item amount.** `amount_ore` of an item abroad is the kronor expected; the
  batch's `total_ore` counts kronor payments only, and the foreign amounts are
  shown per currency.

### Hardening after review (2026-11-06)

- **Auto-booking tolerance** as above (was 25 percent of the invoice-date
  estimate, which let 1 000 USD at 10,00 book up to 2 499 kr of exchange loss
  unseen).
- **Payment code** required from 120 000 kronor at the invoice rate (the
  limit 150 000 kr divided by a 25 percent margin for the bank's rate), and
  always written when filled in. `TODO(legal-verify)` stays: the limit and
  its basis.
- **IBAN** is checked (`Iban`: country length from the registry, mod 97-10) in
  `SaveSupplier` and again in `ForeignPayment`; a typed supplier country must
  match the IBAN's. A country left empty is placed by the IBAN.
- **SWIFT characters.** `SwiftText` transliterates to the SWIFT Latin set and
  strips control characters before cutting at the guides' lengths: creditor
  name and address line 70, town 35, postcode 16, message 140 (Handelsbanken
  6.2: 140). Payments to giro numbers are written exactly as before (tested
  against the output of the code before D.5).
- **One currency per file.** A batch that mixes currencies is refused until a
  bank has accepted a file whose `GrpHdr/CtrlSum` adds amounts in different
  currencies. `TODO(provider-verify)`. The several blocks a currency can need
  (SEPA and other euro payments) stay in one file.
- **Snapshot.** The supplier snapshot now carries country, address line,
  postcode and town; SEPA and `ChrgBr` follow the IBAN's country, the creditor
  address the snapshot. An invoice registered before has no town and cannot be
  paid by file.
- A defect found in review and fixed: with two or more payments in one block
  the closing element of the block was written after the first payment; the
  tests now compare with the earlier output and check a block with two
  payments.

