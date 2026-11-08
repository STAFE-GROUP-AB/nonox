# Rule sheet: ADR-075 customer portal (kundportal)

Architect pass, 2026-11-08. Input for the builder of ADR-075 and questions for a human lawyer.
Not legal advice. Nothing marked "not read" may be built on until it has been read.

## Sources actually read

| Id | Source | How |
|---|---|---|
| GDPR | Regulation (EU) 2016/679, https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32016R0679 | Fetched 2026-11-08; the articles below quoted from it |
| IMY-AVT | IMY, "Avtal med den registrerade", https://www.imy.se/verksamhet/dataskydd/det-har-galler-enligt-gdpr/rattslig-grund/avtal-med-den-registrerade/ | Fetched 2026-11-08 |
| IMY-RG | IMY, "Rättslig grund", https://www.imy.se/verksamhet/dataskydd/det-har-galler-enligt-gdpr/rattslig-grund/ | Fetched 2026-11-08 |

**Not read for this sheet** (named so nobody assumes they were): PSD2 (Directive (EU) 2015/2366)
and lag (2010:751) om betaltjänster; IMY's page on intresseavvägning; IMY's guidance on
logging and access control; Bankgirot's or Swish's QR formats; lag (2023:254) om vissa
produkters och tjänsters tillgänglighet; mervärdesskattelagen (2023:200) 17 kap. on invoice
copies; räntelagen and inkassolagen on disputed claims. Statements about BFL below are taken from
`docs/gdpr/lagringstider.md` and ADR-046, not re-read.

## A. Legal basis

| # | Requirement | Source | Status |
|---|---|---|---|
| A1 | Contract is a basis only where the data subject is party: "processing is necessary for the performance of a contract to which the data subject is party" | GDPR art. 6.1 b | Read |
| A2 | IMY: "Det här gäller bara avtal som den registrerade har ingått eller planerar att ingå." Collecting more than the contract needs requires another basis | IMY-AVT | Read |
| A3 | Therefore: art. 6.1 b for a private person or sole trader who is the customer; art. 6.1 f (the company's legitimate interest in performing its contract with the customer) for a contact at a legal person | ADR-075 §12 | **L1** |
| A4 | Legitimate interest: "processing is necessary for the purposes of the legitimate interests pursued by the controller or by a third party", unless the data subject's rights override them; a documented assessment is needed | GDPR art. 6.1 f | Read (IMY's page on the assessment not read) |
| A5 | Right to object to 6.1 f processing "on grounds relating to his or her particular situation, at any time" | GDPR art. 21.1 | Read |

## B. Principles, information and rights

| # | Requirement | Source | Status |
|---|---|---|---|
| B1 | Data "adequate, relevant and limited to what is necessary": identity holds e-mail and name only; no IP on page events | GDPR art. 5.1 c | Read |
| B2 | Data kept "for no longer than is necessary": retention defaults in ADR-075 §12 | GDPR art. 5.1 e | Read; periods are **L3** |
| B3 | Information to the data subject when data are collected from them (the invitation and the portal privacy page) | GDPR art. 13 | Article not quoted; **L2** |
| B4 | Answer requests under art. 15–22 "without undue delay" (recital 59: at the latest within one month) | GDPR art. 12.3 | Read |
| B5 | Access: "confirmation as to whether" data are processed, and a copy | GDPR art. 15.1 | Read |
| B6 | Portability applies where the basis is consent or contract and processing is automated: the JSON extract | GDPR art. 20.1 | Read |
| B7 | Erasure does not apply where processing is needed "for compliance with a legal obligation" or "for the establishment, exercise or defence of legal claims": messages and disputes kept after a contact removes the login | GDPR art. 17.3 b, e | Read; application is **L4** |
| B8 | Data protection by default: only what each purpose needs, as to amount, use, storage period and accessibility | GDPR art. 25.2 | Read |

## C. Security and roles

| # | Requirement | Source | Status |
|---|---|---|---|
| C1 | Appropriate measures, including "the pseudonymisation and encryption of personal data", ongoing confidentiality and integrity, timely restore, and "regularly testing, assessing and evaluating" them | GDPR art. 32.1 a–d | Read |
| C2 | The company uses only processors with sufficient guarantees: NONOX's DPA covers the portal | GDPR art. 28.1 | Read; DPA text to update |
| C3 | Breach: notify the supervisory authority without undue delay and, where feasible, within 72 hours; tell data subjects when the risk is high | GDPR art. 33.1, 34.1 | Read; follows `docs/gdpr/incidentrutin.md` |
| C4 | Security requirements carried over from ADR-046 and the sign-in (enumeration, hashed tokens, rate limits, session limits, CSP, no-store, noindex) | ADR-075 §8 | Internal rule |

## D. Payments (only if the portal ever takes payments)

v1 initiates no payment and takes no money; it shows the payment details already on the invoice.
PSD2 has **not** been read. Before any payment provider ADR:

| # | Question | Status |
|---|---|---|
| D1 | Does showing a provider's card or Swish checkout inside the company's portal, with the company as merchant and the provider holding the funds, make NONOX a payment service provider, or is it a technical service? | **L9**, PSD2 not read |
| D2 | Would starting a transfer from the payer's bank account through a licensed provider (payment initiation) put any duty on NONOX or the company? | **L10**, PSD2 and lag (2010:751) not read |

## E. Other law touched

| # | Question | Status |
|---|---|---|
| E1 | Must the portal show the invoice exactly as issued (the stored PDF), or is a re-rendered copy from the same data enough? ML 17 kap. 30–31 §§ and BFL 5 kap. 6 § are cited in `lagringstider.md` for e-invoices | **L6**, not re-read |
| E2 | Are portal questions and answers räkenskapsinformation when they concern an invoice (BFL 1 kap. 2 §, 7 kap.)? | **L5**, not read |
| E3 | Does accepting a quote while signed in with an e-mail code have the same or better evidential value as the ADR-046 link (eIDAS art. 25(1), freedom of form)? | **L7**, carried from ADR-046 |
| E4 | Must reminders stop while a customer disputes an invoice (inkassolagen, god inkassosed)? | **L8**, not read |
| E5 | Does lag (2023:254) on accessibility apply to a portal where consumers view and pay invoices? NONOX targets WCAG 2.2 AA in any case | **L11**, not read |

## Open questions for a human lawyer

- **L1** Is art. 6.1 f the right basis for portal logins of contact persons at legal-person
  customers, and 6.1 b for private persons and sole traders who are the customer themselves? What
  must the company's legitimate-interest assessment contain?
- **L2** What must the art. 13 information in the invitation contain, and may it be a link to a
  company-completed template?
- **L3** Are the retention periods defensible: inactive contact 12 months + 30 days' notice,
  disabled contact 30 days, page events 24 months, closed questions 24 months?
- **L4** When a contact removes their login, may the company keep their messages (author shown
  as "Borttagen kontakt") until the messages' own retention ends, under art. 17.3 e?
- **L5** Are questions and answers about an invoice, or a dispute, räkenskapsinformation to be
  kept seven years?
- **L6** Does the law require the company to keep, and the portal to show, the exact issued
  invoice PDF rather than a re-rendered copy?
- **L7** Evidential value of quote acceptance in a portal session (as ADR-046's open question).
- **L8** Must a disputed invoice be excluded from reminders and debt collection while the dispute
  is open?
- **L9**, **L10** The PSD2 questions in D1 and D2, before any payment provider ADR.
- **L11** Does the accessibility act apply, and with what deadline?
- **L12** Is a DPIA needed for the portal (a new category of people with logins, read by the
  company), or is the register of processing entry enough?
