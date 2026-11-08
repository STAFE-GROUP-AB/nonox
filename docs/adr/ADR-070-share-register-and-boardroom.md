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

## Addendum 2026-11-08: as built

Built on branch `feat/boardroom-share-register` as the module `boardroom` (`app/Modules/Boardroom`).
The rule sheet's section G corrections are in the code; every `TODO(legal-verify)` from the sheet
is kept as a code comment where the rule is used.

**Offered to aktiebolag only.** A new module contract `OfferedTo` (`app/Modules/Contracts`) leaves
the module out of Moduler for other company forms, and `canEnable` blocks it. A company whose form
changes after the module is on keeps it, so its register is not hidden.

**Permissions.** As §1 and owner decision 2: owner, admin and accountant get all four, the auditor
the two view permissions. The view permissions are archive permissions: with the module off the
register, the meetings and their PDFs stay readable (5:3), but nothing can be entered. A user with
the Redovisningskonsult role (who does not own the company) records register entries only after
the owner or an administrator has recorded the board's authorisation (decision date and where it is
written) on `boardroom_registers`; the firm cannot authorise itself (5:9, L9). The rule covers every
register entry, not only transfers, because 5:9 is about entering owners at all.

**Tables.** `boardroom_` prefix, as `staff_ledger_` (the table `articles` is taken by the article
register): `boardroom_registers` (one row per company: chain head, last sequence, last board minutes
number, firm authorisation; locked while numbering), `boardroom_share_classes` (votes in tenths),
`boardroom_shareholders` (kind PERSON, ENTITY or COMPANY_ITSELF; personal number and foreign id
encrypted), `boardroom_share_events`, `boardroom_articles` (versioned; only `registered_on` may be set
once afterwards), `boardroom_meetings`, `boardroom_agenda_items`, `boardroom_minutes`.

**Event log.** `boardroom_share_events` is append-only (model events and a PostgreSQL trigger on
UPDATE, DELETE and TRUNCATE). Each entry has a gapless `sequence`, `effective_date`, `recorded_at`
(the 5:9 entry date), `decided_on`, `registered_on`, a share range, class, from- and to-holder, an
encrypted copy of the recipient's details (`holder_snapshot`), consideration and capital change in
öre, certificate flag, ratio, `refers_to_event_id`, note, document reference, reason and user.
`hash = sha256(previous_hash || canonical_json(entry))` with the holder's details in clear text, as
the staff ledger; `php artisan share-register:verify` (nightly 03:45) recomputes the chain, the
sequence and the head, and folds the register.

Kinds: OPENING, FORMATION, NEW_ISSUE, REGISTRATION, NEW_ISSUE_LAPSED, BONUS_ISSUE, TRANSFER,
INHERITANCE_GIFT, SPLIT, REVERSE_SPLIT, REDEMPTION, CONVERSION, CERTIFICATE, RIGHTS_HOLDER_NOTE,
PRE_EMPTION_NOTICE, PRE_EMPTION_CLAIM, PRE_EMPTION_ACTION, NOTE_ENDED, ADDRESS_CHANGE, CORRECTION.
Two choices beyond the sheet: a new issue's registration is its own REGISTRATION entry (the log
cannot be edited to fill in `registered_on` later), and every note is ended by NOTE_ENDED naming it.

**Fold.** `RegisterFold` applies the entries in order of effective date and sequence, skipping
those voided by a CORRECTION (which names exactly one earlier entry; a correction cannot be
corrected or repeated). Recording an entry folds the whole history with it added and refuses it if
any step breaks, so a backdated entry or a correction that makes a later entry impossible is
refused. Formation and opening entries must come before every other kind. SPLIT, REVERSE_SPLIT,
BONUS_ISSUE and CONVERSION take the registration date as effective date. A reverse split renumbers
from 1 in the order of the holders' first shares; surplus shares pass to the company (4:50); a
surplus that does not make whole new shares is refused (`TODO(legal-verify)`). A transfer of shares
with a rights-holder note is refused until the note is ended (AB-R4). The register at a date is
folded on every read (one query); there is no second copy to drift.

**Retention.** Nothing is ever removed from the log, and a holder named in it cannot be deleted
(5:3); the GDPR documents state ten years after dissolution. Pruning after dissolution is not built.

**2081.** `ShareCapitalCheck` compares the registered capital (registered shares × quota value; an
unregistered new issue is shown apart, 2082 per BAS usage, `TODO(legal-verify)` for the
accounting-checker) with the credit balance of 2081 on the date. A difference is an Overview todo
that names both figures. Nothing is posted.

**PDFs.** The full register (labelled "fullständig version för granskning hos bolaget", 5:10) and the
courtesy copy, labelled on every page as voluntary and not the register for inspection, with birth
dates instead of personal numbers and organisation numbers kept. Every print is in the audit log with
its version (ADR-068 will move these reads to the read access log).

**Meetings and minutes.** An AGM needs its fiscal year; its agenda is the usual procedure items, the
three mandatory decisions (cannot be removed) and fees, with the year's result after tax (or the
recorded disposition and dividend) as the allocation proposal. Election items are added by the user,
because NONOX does not track terms yet. Minutes: DRAFT → CHECKED (7:48 / 8:24 checked from the
register on the meeting day and the attendees) → APPROVED as "Godkänt i NONOX" with who and when,
a stored PDF and its SHA-256; the PDF says the approval is not a signature. Approved minutes are
locked by the model and a trigger; the meeting is locked too, except the date a notification reached
Bolagsverket. A correction is new minutes with `corrects_minutes_id`. Board minutes are numbered on
approval under the register row lock, so a discarded draft uses no number (8:26).

**Notices and reminders.** `database/data/boardroom/rules.json` holds section H with `verified: false`
on every value and a 15-working-day outer limit for "genast" (L6). The meeting page shows the notice
window, the item-request deadline and the stored notice method as advice with "kontrollera mot
aktiebolagslagen", flags a notice sent outside the window, and warns that an articles change may need
notice by post (7:23). A new deadline type `company_registration` (category Bolagsverket) puts in Mina
datum every decision marked to report until its `reported_on` is entered, and every unregistered new
issue at decision + 6 months; each links to Bolagsverket's e-service (URL `TODO(legal-verify)`, L8).
NONOX files and sends nothing.

**Archive and data subjects.** The archive package gets `aktiebok/` (the full register at the year end
and the whole history as CSV with each hash) and `protokoll/` (approved minutes of the year's meetings
and of the AGM for the year, copied as stored). `/registerutdrag/aktieagare/{id}` gives a holder's
register extract as JSON.

**Not built yet.** Board composition and terms (8:13 effect on receipt is only shown as text); the
annual report deadline computed from the recorded AGM date (ÅRL 8:3, AB-R43); the continued meeting
(7:14); the extra meeting on demand (7:13); share certificates as documents; sole-owner reporting
(SOU 2026:26) and pruning after dissolution.
