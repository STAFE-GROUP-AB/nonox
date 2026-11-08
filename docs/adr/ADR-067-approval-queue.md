# ADR-067: AI prepares, a human approves (one approval queue)

Status: Proposed
Date: 2026-11-08
Relates to: AGENTS.md §1.2, §1.3, §4, §6.6, §11.3; ADR-007 (vouchers),
ADR-013 (supplier invoices), ADR-015 (incoming payments), ADR-018 (NONOX
AI), ADR-022 (staff ledger), ADR-023 (Inbox), ADR-027 (overview), ADR-033
(collective agreements), ADR-034 (open banking), ADR-063 (MCP server),
ADR-066 (Ask your figures)

## Context

NONOX already prepares a lot of work for people: the inbox reads documents
into vouchers and supplier invoices, the bank matcher pairs transactions
with ledger rows, camt.054 payments are matched to invoices, NONOX Avtal
proposes agreements and pay groups, the staff ledger proposes time reports,
and assistants on the MCP server create drafts. Each of these has its own
place and its own way of approving. An owner or bookkeeper has to visit
five pages to learn what is waiting.

What exists today, and how each one is approved:

| What is prepared | Prepared by | Stored as | Approved by | Permission |
|---|---|---|---|---|
| Vouchers and supplier invoices from e-mail | SIE, Peppol, sender template, NONOX AI (`App\Modules\Inbox\Actions\InterpretInboxMessage`, `Interpreter` enum) | `InboxMessage.proposal`, status `NEEDS_REVIEW` / `DRAFT_READY` | `ApproveInboxMessage` → `SaveDraftVoucher` + `PostVoucher`, or `SaveSupplierInvoice` + `RegisterSupplierInvoice`; `InboxReview` for quarantine | `inbox:manage` plus the posting policies (`PostVoucher` authorises `post`, `RegisterSupplierInvoice` authorises `register`) |
| Automatic booking from a sender template | `ApproveInboxMessage::automatically()`, only on a template a person approved and turned on, exact match, no warnings, authenticated sender (ADR-023) | `InboxTemplate.auto_book`, `auto_book_user_id` | Standing approval by the person who turned it on, audited `inbox.auto_booked` | That person must still be allowed to post |
| Bank feed match (open banking) | `App\Modules\Banking\Actions\MatchBankTransactions::proposals()`: same amount, within 7 days, each row once | Not stored; computed on each view | `MatchBankTransactions::confirm()`, audited `banking.matched` | `BankingGate::match` |
| Incoming payment → invoice (camt.054) | `App\Actions\Invoicing\ImportBankFile` (OCR and amount) | `BankTransaction.invoice_id` | `App\Actions\Invoicing\BookIncomingPayments` (series C via `RegisterPayment`); `IncomingPayments::assign()` changes the invoice | `BankFileImportPolicy` to read the file; `payments:create` and the invoice's `registerPayment` policy to book |
| Agreement, variant, lönegrupp and other employment facts | `AgreementIdentificationAgent`, `EmploymentTermsAgent` through `App\Modules\Agreements\Actions\AgreementAi` | `AgreementProposal` with quote, page, `quote_found`, status `PENDING` | `AgreementAi::accept()` / `reject()`; `acceptMatching()` accepts all with a verbatim quote | `ai:use` + `agreements:manage` |
| Time reports from the staff ledger | `App\Modules\StaffLedger\Actions\TimeProposals::propose()` | `TimeProposal` | `TimeProposals::approve()` / `reject()` | `payroll:approve` |
| Fixed assets on a purchase | The person, on the draft (`App\Actions\Assets\AssetProposals`) | `FixedAssetProposal` | Created inside `PostVoucher` / `RegisterSupplierInvoice` | Posting permission |
| Tax account counter-accounts | `App\Actions\TaxAccount\ReconcileTaxAccount` | Not stored | Booked by a person | Tax account page rights |
| Drafts from AI assistants | MCP `create_draft_voucher`, `create_draft_invoice` (ADR-063) | Draft `Voucher` / `Invoice` | Posting or sending in NONOX as usual | `vouchers:post`, `invoices:send` |
| Supplier invoice coding in the editor | `SupplierInvoiceAgent` via `AnalyzeDocument::supplierInvoice()` | Not stored: fills the open form | The person saves and registers | `ai:use`, `supplier-invoices:create` |
| Accrual (periodisering) | No suggestions today. People register accruals; the monthly run (`App\Actions\Accruals\Accruals::preview()` / `run()`) books planned steps | `Accrual`, `AccrualPosting` | `Accruals::run()` | `fiscal-years:manage` |

Rules that already hold and that this ADR must not weaken:

- **The AI never saves or posts** (ADR-018). It fills in a proposal.
- **Posting is done in a person's name** through `PostVoucher`, so
  numbering, the hash chain, locked periods and immutability apply
  (AGENTS.md §1.2, ADR-007).
- **Segregation of duties:** a user may not approve a payment they created,
  configurable per company and on by default (AGENTS.md §4); implemented as
  `Company.separate_supplier_approval` and
  `SupplierPaymentBatchPolicy::blockedBySegregation()`, and the same setting
  governs the attest of supplier invoices (`SupplierInvoicePolicy`).
- **ADR-063 rejected a separate "pending approval" state** for assistant
  drafts: a draft already is that state.

On the law: the company is responsible for its bookkeeping (BFL), which is
why ADR-018 and ADR-023 let only a person, or a person's standing approval,
book. This ADR keeps that line. It does not claim that BFL requires a human
for each entry; see open question 1.

## Decision

### 1. One queue, many sources, no second store

The queue is a **read model over the proposals that already exist**, not a
new table that every feature copies its proposals into.

- A new interface `App\Domain\Review\Contracts\ReviewSource`, shaped like
  `TodoProvider` (ADR-027): `items(User, Company, ReviewFilter): list<ReviewItem>`,
  `count(User, Company): int`, `approve(...)`, `reject(...)`, and
  `invalidatedBy()`. Each source checks permissions itself and returns only
  items the user may act on (or read only, marked as such).
- One source per existing kind: `InboxReviewSource`, `BankFeedMatchSource`,
  `IncomingPaymentSource`, `AgreementProposalSource`, `TimeProposalSource`,
  `AssistantDraftSource`, `AccrualRunSource`, and later
  `AccrualSuggestionSource` (§7). A module's source is only asked while the
  module is on, as with todo providers.
- **Approving or rejecting in the queue calls the existing action** of
  that kind (`ApproveInboxMessage`, `MatchBankTransactions::confirm`,
  `BookIncomingPayments`, `AgreementAi::accept`, `TimeProposals::approve`,
  `PostVoucher`, `Accruals::run`). The queue adds no posting path of its
  own, so every validation, lock and policy stays where it is.
- **Editing opens the existing editor** (the inbox workspace, the voucher
  editor, the supplier invoice editor, the agreement proposal form) with a
  return link to the queue. The queue itself edits nothing.
- Each source reads with a fixed number of queries; the page as a whole has
  a query-count test with few and many items per source.

### 2. What an item shows

`ReviewItem` is a value object with: kind, id, a title without personal
data in the list view (supplier or customer name only on open, and only for
users who may see it), date, amount, **who prepared it** (enum: `AI`,
`TEMPLATE`, `RULE`, `SIE`, `PEPPOL`, `ASSISTANT`, `PERSON`), the person
behind it when there is one (who ran the analysis, who turned on the
template, which API credential), **the reason**, **the confidence**,
warnings, staleness (§5), and the actions available to this user.

**Confidence is never a made-up percentage.**

- For rule-based matches, the reason is the rule in words ("Samma belopp,
  bokförd två dagar före bankens datum", "OCR stämmer exakt"), and the
  confidence band (`Exakt`, `Trolig`, `Osäker`) is a deterministic
  function of the rule per kind, documented in the source.
- For AI proposals, the model's own confidence is shown only where the
  agent returns one (today `DailyReportAgent`, per field) and labelled as
  the AI's own estimate. The verifiable facts are shown first: an
  agreement proposal's quote and whether it was found word for word
  (`quote_found`), the inbox's warnings, accounts dropped because they are
  not in the chart.
- Nothing is sorted or hidden by AI confidence alone.

### 3. Nothing is posted without a person

- Every approval is a person's action, in their name, with their
  permissions at that moment.
- **Standing approvals stay, narrowly.** ADR-023's automatic booking from a
  sender template is a person's earlier decision about one sender and one
  layout. It stays as it is, and its bookings appear in the queue's history
  tab "Bokfört automatiskt" with the name of the person whose approval
  booked them, so they are seen afterwards. No new kind of standing
  approval is introduced by this ADR, and **an AI interpretation can never
  be a standing approval**: a template only exists after a person approved
  a proposal, and automatic booking only runs on an exact template match.
- Assistant drafts (ADR-063) stay drafts; the queue lists them, it does not
  add a state.

### 4. Bulk approval and its limits

Bulk approval is a convenience for items whose correctness is checked
by deterministic rules, not a way around review:

- **Allowed** only for kinds and bands listed per source:
  bank feed matches and camt.054 payments in band `Exakt`; inbox items read
  by SIE, Peppol or a sender template (`Interpreter::Sie`, `Peppol`,
  `Template`) without warnings; agreement proposals with `quote_found`
  (today's `acceptMatching()`); time proposals.
- **Never in bulk:** anything prepared by AI without a deterministic check
  (`Interpreter::Ai` vouchers and supplier invoices); items with a warning
  (changed bankgiro/IBAN, look-alike sender domain, duplicate,
  authentication failure); items where segregation of duties blocks the
  user; stale items; payments.
- An optional company limit per item amount above which bulk is refused
  (owner decision on the default; open question 4).
- At most 100 items per bulk action. The confirmation dialog states, in
  plain Swedish, how many items, of which kinds, the total amount, and that
  each will be booked in the user's name (AGENTS.md §11.3).
- Each item is approved through its own action in its own transaction. One
  failure does not stop the rest; the result lists what was approved and
  what was left with the reason.

### 5. Stale suggestions

Each source re-checks its items when they are listed and again inside the
approving transaction. An item is **stale** when:

- its date falls in a closed fiscal year, or on or before
  `locked_until_date` (the same check as `PostVoucher`);
- an account it uses has been deactivated, or a required dimension is
  missing;
- the thing it points at has changed: the ledger row is already matched,
  the invoice is paid or credited, the employment or agreement version has
  changed, the supplier's payment details differ from those on the
  proposal;
- for stored proposals, the inputs it was computed from have changed. A
  stored proposal gets a `basis_sha256` of its inputs where it does not
  already have an equivalent; computed proposals (bank feed, tax account)
  are never stale because they are recomputed on each view.

A stale item cannot be approved, in bulk or alone. It shows why, and
offers what the existing flow allows: edit (for example a new date in an
open period, decided by the person), prepare again, or reject. **Stale
items are never deleted or rejected automatically.** Underlag in the inbox
keeps the retention rules of ADR-023; a proposal that has waited longer than
60 days is marked "Gammal" in the list so it is not forgotten.

### 6. Segregation of duties

- The existing policies decide, unchanged; the queue only shows the result
  (an approve button disabled with "Du skapade underlaget – en annan person
  måste godkänna").
- For segregation, the **preparer is a person**: the user who ran an AI
  analysis, who last edited the proposal, who created the payment batch,
  or the user behind the API credential that created a draft. The AI and
  the rule engine are never the "other person".
- Editing a proposal in the queue's editor makes the editor its preparer
  for these checks.

### 7. Accrual suggestions (new, rule-based)

Accrual proposals do not exist today. The queue starts with
`AccrualRunSource`: a month whose planned accrual steps
(`Accruals::preview()`) are not booked appears as one item, approved by
`Accruals::run()` with `fiscal-years:manage`. A later phase adds
`AccrualSuggestionSource`: a supplier invoice or voucher row on a cost
account whose stated service period spans more than one month proposes a
new `Accrual` (not a posting) for a person to confirm; the service period
comes from the invoice data or the AI reading of it, shown with its source.
Which items must be accrued, and any materiality simplification, is a legal
question and is not decided here (open question 3).

### 8. Tenancy, permissions and UI

- Every source runs in `CurrentCompany`; items of another company are never
  listed, and opening another company's item id answers 404 (tenancy test
  per source).
- No new permission. The page is shown to anyone who may act on at least
  one source; each item needs the permission its own action needs.
- Page "Att godkänna" at `/att-godkanna` (Swedish URL), with tabs per kind
  plus "Alla", filters in the query string, server-side pagination,
  keyboard: `j`/`k` move, `Enter` opens, `a` approves, `x` rejects, `?`
  lists shortcuts. Every approval shows the Swedish toast that the
  underlying action shows today.
- Översikt's "Att göra" gets one `ReviewTodos` provider with the count per
  kind, replacing the per-module counters where they duplicate it.
- The existing pages (inbox, bank, incoming payments, agreement proposals,
  time proposals) stay. The queue is a second door, not a replacement.

### 9. Audit

The existing actions keep their audit entries (`inbox.approved`,
`banking.matched`, `agreements.proposal_accepted`, ...). The queue adds:

- `review.decided`: kind, item id, decision (`approved`, `rejected`),
  `prepared_by` enum, confidence band, `edited` (bool), `via` (`queue`,
  `queue_bulk`);
- `review.bulk`: kind, count approved, count left, and a bulk id that each
  `review.decided` entry carries;
- `review.blocked`: kind, item id, reason code (`stale_locked_period`,
  `segregation`, `warning`, ...), when an approval was refused.

No names, amounts, texts or reasons in free text (ADR-006). A rejection
reason is a code; an optional comment is stored on the item where the kind
already has a place for it, never in the audit log.

### 10. Build plan

| Phase | Content | Size |
|---|---|---|
| 1 | `ReviewSource`, `ReviewItem`, the queue page (read-only list with links to existing pages) with sources for inbox, bank feed, camt.054, agreement proposals, time proposals, assistant drafts; query-count and tenancy tests | M (3-4 days) |
| 2 | Approve and reject from the queue through the existing actions; reasons and confidence bands per source; segregation display; staleness checks for locked periods, closed years, inactive accounts; `review.*` audit entries | M (3-4 days) |
| 3 | Bulk approval with the allowed list, limits, confirmation dialog, per-item transactions and result report; owner-decided amount limit setting | S (2 days) |
| 4 | `basis_sha256` on stored proposals that lack an equivalent (migration, additive), "Gammal" marker, history tab "Bokfört automatiskt"; `ReviewTodos` on Översikt; manual article, changelog, landing | M (3 days) |
| 5 | `AccrualRunSource`, then `AccrualSuggestionSource` after the legal question is answered; systemdokumentation update (accounting behaviour) | M (3-4 days + legal review) |
| 6 | Later: queue over the API (`GET /api/v1/review-items`, read only) and webhooks; firm workspace counts across clients (per company, counts only) | S-M |

## Alternatives considered

- **A generic `suggestions` table that every feature writes to.** Rejected
  for now: it duplicates state that already lives in `InboxMessage`,
  `AgreementProposal`, `TimeProposal` and drafts, every kind would need a
  migration and dual writes, and the two copies would drift. Revisit if the
  read model cannot stay within a fixed query budget as sources grow; an
  index table holding only ids and statuses would then be the next step.
- **Keep the separate pages only.** Rejected: the cost is that work waits
  unseen, which is the problem the owner described.
- **Auto-post AI proposals above a confidence threshold.** Rejected: it
  breaks ADR-018 ("the AI never saves or posts"), and model confidence is
  not calibrated.
- **A new "pending approval" voucher state.** Rejected, as in ADR-063: a
  draft already is that state, with the posting checks attached.

## Consequences

- One place shows everything that waits, while each kind keeps its own
  rules, actions and pages.
- New proposal kinds join by implementing `ReviewSource`; nothing needs to
  be copied into a central table.
- Bulk approval exists, but only where deterministic checks back it, so a
  person cannot approve an AI-read invoice without opening it.
- Stale items stay visible until a person decides; nothing disappears.
- The queue is only as good as each source's staleness check; every new
  source must come with tests for its stale cases.

## Open questions

1. TODO(legal-verify): whether BFL and BFNAR 2013:2 allow the company to
   rely on a standing approval (ADR-023's automatic booking from a sender
   template) for each later voucher, and what the systemdokumentation must
   then say about it. This ADR keeps the current behaviour, not a new rule.
2. TODO(legal-verify): whether bulk approval of rule-matched items is
   compatible with the company's duty to check underlag, and whether the
   behandlingshistorik must show each item as individually approved
   (the design records one `review.decided` entry per item).
3. TODO(legal-verify): which items must be accrued under K2 (BFNAR 2016:10)
   and K3, and whether a materiality simplification applies, before
   `AccrualSuggestionSource` proposes anything.
4. Owner decision: the default bulk amount limit per item (none, or a
   figure), and whether companies may raise it.
5. Owner decision: whether segregation of duties should also cover posting
   a voucher that the same person prepared or edited (AGENTS.md §4 only
   names payments; the attest of supplier invoices follows the same
   setting today).
6. Owner decision: the "Gammal" threshold (60 days proposed).
7. Should the employee-facing proposals (time reports, pay groups) show in
   the same list as bookkeeping items, or in a separate "Personal" tab with
   its own access rules? The design keeps the permission checks either way;
   the question is presentation and what an approver should see together.
