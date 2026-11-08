# ADR-070: Share register and boardroom (aktiebok och styrelserum)

Status: Proposed
Date: 2026-11-08
Relates to: ADR-006 (audit log), ADR-007 (vouchers and ledger chain), ADR-017 (module framework),
ADR-046 (CRM signing), ADR-051 (Företagsguiden), ADR-068 (read access log),
`app/Domain/Deadlines` (Mina datum), AGENTS.md §1.2, §1.3, §1.4, §10

## Context

Every aktiebolag must keep a share register (aktiebok), hold an annual general meeting,
keep minutes of board and general meetings, and report some changes to Bolagsverket. Small
companies keep these in a spreadsheet, a binder, or not at all. NONOX already knows the
company's legal form, its fiscal year, its equity accounts (2081 aktiekapital and onwards)
and its deadlines (`DeadlineType::AnnualMeeting`, `DeadlineCategory::Bolagsverket`), so the
register and the meeting calendar sit next to data it already holds.

The roadmap item is `aktiebok-och-styrelserum` (Planerat, 2026-11-08). It is tried with the
pilot companies before the swarm builds it.

### Legal frame (all of it `TODO(legal-verify)` until checked against the law text)

The points below are the working understanding. None of them may be coded as a rule until
the legal-verify pass has confirmed the paragraph and its current wording.

| Topic | Working understanding | Source to verify |
|---|---|---|
| Duty to keep a register | The board shall ensure that a share register is drawn up without delay. | ABL 5 kap. 1 § |
| Content | Shares in number order, each owner's name, personal or organisation number and postal address, share class, and any recorded restrictions (förbehåll). | ABL 5 kap. 2–3 §§ |
| Form | The register may be kept on paper or by automated processing, in a secure way. | ABL 5 kap. |
| Retention of old entries | Superseded entries must be kept for a minimum period (believed to be ten years). | ABL 5 kap. |
| Public access | The register shall be available to anyone at the company's office. | ABL 5 kap. 15 § |
| Avstämningsbolag | Companies whose register is kept by a central securities depository are out of scope. | ABL 5 kap. |
| Annual general meeting | Within six months of the end of the fiscal year. | ABL 7 kap. 10 § |
| Notice periods | Earliest and latest days before the meeting differ by meeting type and by private or public company. | ABL 7 kap. 18 § |
| Minutes | General meeting minutes and board minutes, with who checks (justerar) them. | ABL 7 kap. 49 §, 8 kap. 24 § |
| Reporting to Bolagsverket | Changes in the board, the auditor and the articles must be reported to be registered; some take effect only on registration. | ABL 8 kap., 9 kap., 7 kap. |

The GDPR legal basis for the register's personal data is the legal obligation (art. 6.1 c);
this goes into `docs/gdpr/registerforteckning.md` when the feature ships.

## Decision

Decided by Lead 2026-11-08 under the owner's mandate, open for the consultant's review.

### 1. A module, offered to aktiebolag

A new optional module `boardroom` (ADR-017), offered during setup and in the module list
only when `legal_form = AB`. It is not core. A company that keeps its register elsewhere
must not get a second, out-of-date copy.

Permissions, which exist only while the module is on:

- `share-register:view`
- `share-register:manage`
- `meetings:view`
- `meetings:manage`

Owner and admin get all four. Accountant gets view plus manage. Auditor gets the two view
permissions. Bookkeeper and approver get none.

### 2. The register is an append-only event log

The register is never edited in place. Each change is an event, and the register at any
date is the state the events produce.

- `shareholders` holds a person or a legal entity, with the personal number in an `encrypted`
  cast. It is company-scoped (`BelongsToCompany`) and has a tenancy test.
- `share_classes` holds the share class code, its votes per share, and any restriction text
  from the articles.
- `share_events` is append-only, with a PostgreSQL trigger that rejects `UPDATE`, `DELETE`
  and `TRUNCATE`, as for `audit_logs`. Each event stores its kind, effective date, share
  number range, class, from-holder, to-holder, consideration in öre (optional), a document
  reference, the user and a reason. Event kinds:
  - `FORMATION`
  - `NEW_ISSUE`
  - `TRANSFER`
  - `INHERITANCE_GIFT`
  - `SPLIT`
  - `REVERSE_SPLIT`
  - `REDEMPTION`
  - `CORRECTION`
- A mistake is fixed with a `CORRECTION` event that names the event it corrects. The pattern
  is the same as the correcting voucher, and the original event is never removed.
- Each event carries `hash = sha256(previous_hash || canonical_json(event))`, which makes the
  history tamper-evident in the same way as the voucher chain (ADR-007).
  `php artisan share-register:verify` recomputes the chain.

The share number ranges are checked on every event. No share may be held by two owners at
once, and no share may exist outside the issued ranges.

### 3. The register against the books

The register does not post vouchers. A new issue or a redemption is still bookkept as a
voucher by a person. NONOX compares the issued shares times the quota value with the balance
on 2081. When they differ, it shows a todo on the Overview that names both figures.

### 4. Meetings and minutes

- Meetings come in three kinds: annual general meeting, extra general meeting and board
  meeting. Each has a date, an agenda, attendees, decisions and minutes.
- The annual general meeting is pre-filled from the closing: adoption of the income
  statement and balance sheet, the profit allocation from `YearEndClosing`, and discharge
  from liability.
- Minutes go through draft, checked and signed. Signing uses the existing name-signature
  flow (ADR-046). The UI states plainly that this is a simple electronic signature.
  `TODO(legal-verify)`: whether minutes need more than that in any case.
- A signed set of minutes is locked: a PDF plus a SHA-256 hash. Corrections are made as a
  new, linked document.
- Meeting minutes, the register and its history are included in the archive package
  (`export:archive`).

### 5. Notices and reminders, never filing

- A notice is generated from the meeting date. The earliest and latest dates for sending it
  come from a rules file, `database/data/boardroom/rules.json`. Every value in that file is
  marked unverified until the legal-verify pass is done. While a value is unverified, the
  page shows the dates as advice with the text "kontrollera mot aktiebolagslagen", never as
  a rule.
- Decisions that must be reported to Bolagsverket raise a reminder in Mina datum, under the
  existing `Bolagsverket` category. Examples are a new board member, a new auditor and new
  articles of association. The reminder links to Bolagsverket's own e-service.
- NONOX does not file anything with Bolagsverket. There is no agreement or API for that.
  That decision is revisited together with the digital annual report (ADR-058).

### 6. Public access to the register

The company must make the register available to anyone. NONOX gives the owner a PDF of the
register at a chosen date to show or hand out. NONOX itself publishes nothing.

`TODO(legal-verify)`: whether a copy handed out must include personal numbers, or may leave
them out. Until that is answered, the PDF has two versions: a full one, and one without
personal numbers. Who may print each version is logged under ADR-068 once that ADR is built.

### 7. Out of scope

- avstämningsbolag (central securities depository);
- ekonomiska föreningar and their member registers (the separate roadmap item `foreningar`);
- share option programmes, warrants and convertibles beyond recording them as a text note;
- valuation of shares;
- K10. ADR-062 reads the register later, when both are built.

## Consequences

- One more append-only table with a hash chain, so `ledger:verify`-style tooling is reused,
  not reinvented.
- The module touches no hot spot: it does not post vouchers, does not change the
  annual-report figures, and reads `YearEndClosing` only to pre-fill an agenda.
- Personal numbers of shareholders become a new category in the register of processing and
  in the data-subject export (`DataSubjectExport`).
- Builders start with a rules sheet from a researcher and fixtures. The process is the same
  as for spec nummer-1 phases: researcher rule sheet, fixtures, builder with tests first, a
  cold reviewer, then Lead merges.

## Owner decisions (2026-11-08)

1. The module is part of the open-source code like every other module, and it is included in
   the hosted service's annual price (995 kr per year on nonox.app) at no extra charge. It is
   still a module that each company switches on or off itself (ADR-017); switching it off
   hides it and keeps the data.
2. An accounting firm may keep the register on the client's behalf. The accountant role gets
   `share-register:manage` and `meetings:manage` as in §1, and every change is recorded with
   the user who made it.

## Addendum 2026-11-08: rule sheet

The researcher's rule sheet is `docs/specs/rules/aktiebok.md` (AB-R1 … AB-R46, read from the
current texts of ABL, ABF, ÅRL 8:3 and DSL 3:10 on riksdagen.se; Bolagsverket's pages could
not be fetched). Lead spot-checked ABL 5 kap. 3, 5 and 10 §§ against riksdagen.se. Where this
ADR and the sheet disagree, the sheet wins, and its section G corrections are accepted:

- The paragraph references in the "Legal frame" table are corrected by the sheet: content
  5:5, retention 5:3, public access 5:10, minutes 7:48–49, board changes 8:43–44 (effect on
  receipt, 8:13), auditor 9:47–48, articles 3:5.
- Retention follows 5:3: the whole register while the company exists and ten years after
  dissolution; removed data ten years. It is modelled apart from the BFL seven years.
- The data model and event kinds are extended as listed in section G (certificate flag,
  rights holders, entry date, `registered_on`, bonus issue, conversion, lapsed new issue,
  address change, pre-emption notes, the company as holder). Articles are stored as
  versioned, structured data.

Decided by Lead 2026-11-08 under the owner's mandate, open for the consultant's review:

- **Public access (§6):** the full register is what the company shows for inspection under
  5:10. The PDF without personal numbers is a voluntary courtesy copy, labelled as such, and
  is never offered as the legal fulfilment. L1 decides whether that changes.
- **Minutes (§4):** NONOX marks minutes "Godkänt i NONOX" with who and when, not "Signerat",
  until L2 is answered. An advanced electronic signature (ABL 1:13) is a later step.
- **Notice periods and deadlines** come from the sheet's section H as `verified: false`
  values and are shown as advice until L4–L6 are answered.
- **Accounting firms (owner decision 2 above):** a firm user may enter transfers only when
  the company has recorded that the board has authorised the firm (5:9). L9 confirms the form.
- **Existing companies:** an opening event records today's register, and the page tells the
  user to keep the old book for ten years (5:3, L10).

Open lawyer questions L1–L10 are listed at the end of the sheet. SOU 2026:26 (sole-owner
reporting proposed from 2028-07-31) goes on the regulatory watch list.
