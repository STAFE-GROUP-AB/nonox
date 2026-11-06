# ADR-055: Payment initiation (paying supplier invoices from NONOX)

- Status: Proposed
- Date: 2026-11-06
- Task: Track C, launch readiness for Peppol and bank (nothing is built)

## Context

NONOX reads the company's bank accounts through an open-banking provider
(account information, ADR-034) and pays supplier invoices with payment
files: a payment proposal becomes an ISO 20022 pain.001 file that a person
approves in NONOX and uploads and signs in the bank (ADR-014), and the
bank's answer comes back as camt.054 or pain.002 (ADR-015). Money never
moves because of NONOX alone; the bank's own strong customer
authentication (BankID) is the last step, inside the bank.

Users ask for the next step: approve a payment in NONOX and have it sent
to the bank without handling a file. PSD2 calls this a **payment
initiation service** (betalningsinitieringstjänst). This ADR lists the
options and the questions that must be answered before anything is built.
**Nothing is built, and no provider is contacted or configured.** The brief
for this track is explicit: payment initiation stays unbuilt.

Sources to read before a decision (not yet read for this ADR, so nothing
below quotes them): Directive (EU) 2015/2366 (PSD2), Commission Delegated
Regulation (EU) 2018/389 (RTS on strong customer authentication and common
and secure communication), lag (2010:751) om betaltjänster, Finansinspektionen's
pages on authorisation and registration of payment service providers, the
EU proposals for PSD3 and a Payment Services Regulation, and the provider's
own terms for its payment API.

## Options

### A. None: keep payment files (status quo)

The company keeps approving in NONOX (attest, segregation of duties,
AGENTS.md §4) and signing in the bank.

- For: no new regulatory role; the bank's SCA and the bank's own limits
  protect every payment; the audit trail in NONOX is already complete
  (approval, file with SHA-256, the bank's answer); works for every bank
  that takes pain.001, also for self-hosters.
- Against: one manual step (upload and sign in the bank); not every bank
  accepts pain.001 from small companies, and some charge for it.

### B. Payment initiation through a licensed provider (PISP)

NONOX calls a licensed payment initiation provider's API (for example the
open-banking provider already chosen in ADR-034, which may also offer
payment initiation; its payment API, licence scope and terms have not been
read for this ADR, `TODO(provider-verify)`). The user is sent to the bank to
approve each payment or bulk with BankID (SCA), as in the consent flow of
ADR-034, and NONOX gets the status back.

- For: no file handling; the bank still performs SCA; the licence and the
  connection to the banks are the provider's.
- Against: a new contract and a new sub-processor role for payment data;
  NONOX's role towards Finansinspektionen is unclear (see the questions);
  bulk payments and future-dated payments differ per bank and are not
  available everywhere; a payment the bank accepted cannot be called back
  by NONOX; failure modes (pending, rejected after SCA, partial bulk) need
  their own reconciliation; self-hosters would need their own agreement.

### C. Own licence or registration

Stafe (or whoever operates an installation) becomes a payment institution
or a registered payment initiation provider with Finansinspektionen and
connects to the banks' PSD2 interfaces directly.

- For: no intermediary; full control of the flow.
- Against: authorisation, capital and insurance requirements, ongoing
  supervision, security and incident reporting, a connection per bank and
  its certificate (eIDAS QWAC/QSealC), and round-the-clock operation. Out
  of proportion for an open-source bookkeeping system, and impossible for
  self-hosters. Not recommended.

## Proposed direction

Keep **A** for 1.0. Re-open **B** only when the legal questions below are
answered in writing and the open questions of ADR-034 (Stafe's role towards
Finansinspektionen for account information) are settled, since B depends
on the same answers. Whatever is chosen, NONOX's own controls stay:
attest by someone other than the creator (default on), the payment
proposal and its SHA-256 kept, the bank's SCA as the last step, and no
payment ever initiated by a scheduled job without a person.

## Regulatory questions (all `TODO(legal-verify)`)

1. **Role.** With option B, is NONOX's operator a provider of a payment
   initiation service itself, an agent of the licensed provider (ombud,
   registered with Finansinspektionen), or a technical service provider
   outside the scope of lag (2010:751) om betaltjänster? Does the answer
   change when the company, not the operator, is the provider's customer?
2. **Self-hosters.** Can an open-source installation offer B at all, or
   only an installation whose operator has its own agreement and role?
3. **Strong customer authentication.** Does every payment (or bulk) need
   SCA in the bank, or can the bank's exemptions (trusted beneficiaries,
   corporate processes under the RTS) apply to payments initiated through a
   PISP, and who decides? NONOX must never store or replay credentials.
4. **Liability.** Who carries the loss for an unauthorised, wrong or late
   payment initiated through NONOX: the bank, the PISP, the operator or the
   company? What must NONOX log to show that a person approved exactly
   that payment (amount, payee account, date)? Does the operator need
   professional liability insurance?
5. **Changed payee details.** NONOX warns when an invoice's bank details
   differ from the supplier register (inbox and payables). Is a warning
   enough with option B, or must a change of payee account block initiation
   until a second person confirms?
6. **Personal data.** Payee names and accounts of sole traders are personal
   data; with option B the PISP becomes a sub-processor (or an independent
   controller?) for them. Register of processing, DPA and transfers.
7. **Bookkeeping.** The payment is booked (series E) when the bank's answer
   confirms it, as today (ADR-014/015). Does a PISP status "accepted" give
   enough underlag under BFL 5 kap. and BFNAR 2013:2, or is the bank's
   statement still required?
8. **Coming rules.** How would PSD3 and the Payment Services Regulation, if
   adopted, change questions 1–4 (for example the verification of the
   payee's name and IBAN)?

## Risks

- **Fraud.** A changed bankgiro or IBAN on an e-mailed or e-invoiced
  supplier invoice, paid with one click, is the most common attack on
  small companies. Payment files have the same risk, but the extra step in
  the bank is a moment to notice it.
- **Compromised account in NONOX.** Today an attacker who takes over a
  NONOX account can at most create a payment file; with B they could start
  payments that the victim then approves in the bank out of habit.
- **Partial failures.** A bulk payment where some payments are rejected
  after SCA, or a status that never arrives, must not be booked as paid.
- **Provider outage.** The kill switch of ADR-034 would have to cover
  payment initiation too, without leaving payments half-sent.
- **Expectations.** Showing "betald" in NONOX before the bank has executed
  the payment would mislead the user and the books.

## Consequences

- No code, no configuration, no package and no contact with a provider
  follow from this ADR.
- The public pages and the manual keep saying that NONOX can only read
  from the bank and that payments go as payment files signed in the bank.
- A decision needs a human-approved update of this ADR with written answers
  to the questions above.
