# ADR-074: Work orders and change orders (arbetsorder och ÄTA)

Status: Proposed
Date: 2026-11-08
Rule sheet: `docs/specs/rules/arbetsorder-och-ata.md` (rules AO-R1 … AO-R39, fixtures F1–F10,
L-questions L1–L16)
Relates to: ADR-009 (cost centres and projects), ADR-011 (invoicing, quotes and orders,
ROT/RUT), ADR-013 (supplier invoices), ADR-016 (reverse charge on purchases), ADR-017 (module
framework), ADR-022 (staff ledger), ADR-031 (installable web app), ADR-037 (staff scheduling),
ADR-039 (inventory), ADR-043 (CRM and GDPR), ADR-046 (signing), ADR-071 (manufacturing),
roadmap `kundportal`, AGENTS.md §1.2, §1.3, §1.4, §5, §10

## Context

Roadmap item `arbetsorder-och-ata`: a craftsman (electrician, plumber, carpenter, painter)
needs a work order that collects time and material on site and becomes the invoice basis, and
change and additional work (ÄTA, ändrings- och tilläggsarbeten) that the customer approves,
with a price, before the work is done. ROT/RUT and reverse charge must come out right without
the user thinking about them.

What NONOX has today (mapped in the rule sheet, "What NONOX already has"):

- Quotes and orders as `SalesDocument`, converted to draft invoices row for row. No project,
  no labour flag, no ROT on them.
- ROT and RUT on invoices (`TaxReduction`, `is_labour` rows, property designation, 1513,
  2890), only under fakturametoden, with the year of payment deciding the rate and ceiling.
  The invoice records neither the hours worked nor the kind of work, which the claim to
  Skatteverket needs (HFL 9 §, AO-R14).
- Reverse charge for construction per customer (`reverse_charge_construction`), applied to
  every row of the invoice, booked on 3231.
- Signing with a drawn or typed signature, document hash and certificate (ADR-046), but only
  inside the CRM module.
- Projects (ADR-009) on voucher rows, supplier invoice rows and payroll time reports; not on
  invoices or sales documents.
- Payroll time reports, Planning shifts and attest, staff ledger time proposals: all are pay
  time or attendance, none is billable time.
- Articles, stock and movements (ADR-039); supplier invoices (ADR-013).
- A staff ledger that does **not** cover construction yet (ADR-022: construction is v2). The
  roadmap text "personalliggaren finns redan" holds only for the 2027 industries.

What is missing: the work order itself, billable time and material, the ÄTA document and its
approval, hours on the ROT invoice basis, a conto plans, and year-end support for ongoing work.

## Decision

Proposed by the researcher 2026-11-08 for Lead and the owner. Every rule that the rule sheet
marks `TODO(legal-verify)` is built as a warning (ADVICE) until its L-question is answered.

### 1. Module, permissions and roles

A new optional module `work_orders` ("Arbetsorder"), `app/Modules/WorkOrders`, on the
module framework (ADR-017). It requires nothing; it uses Payroll, Inventory, Staff ledger and
the customer portal when they are on. Switching it off hides the screens and keeps the data;
approved ÄTA documents stay reachable through `work-orders:view` for the retention period
(as the staff ledger keeps its archive).

Permissions (exist only while the module is on):

| Permission | What |
|---|---|
| `work-orders:view` | read work orders, ÄTA, entries |
| `work-orders:manage` | create and change work orders, price ÄTA, correct entries, create the invoice basis |
| `work-orders:report` | report time and material and take photos on work orders one is assigned to |
| `change-orders:send` | send an ÄTA to the customer for approval, record an oral approval, mark "utfört utan kundens besked" |

| Role | Gets |
|---|---|
| Owner, admin | all |
| Accountant | view, manage |
| Bookkeeper | view, manage, send |
| Approver | — |
| Auditor | view |
| New module role `field_worker` ("Utförare") | report, and view of the work orders assigned to them only |

Creating the invoice draft still needs `invoices:create`, and issuing it `invoices:send`,
exactly as today: the work order prepares, the invoicing core decides (ADR-011).

A field worker is a NONOX user with e-mail code sign-in, like every NONOX login. Owner
question O3 asks whether a kiosk-style person (ADR-022, ADR-071 §7) is wanted instead.

### 2. The work order

`work_orders` (company-scoped, `BelongsToCompany`, tenancy test):

- number (own number series `work_orders`), customer, title, description;
- origin: a `SalesDocument` order or quote (optional), so an accepted quote becomes a work
  order the way it becomes an order today;
- project: a `project_id`. By default a project is created with the work order's number as
  code (owner question O2), so supplier invoice rows, payroll time reports and vouchers can
  already point at it (ADR-009);
- site: address, property designation or the association's organisation number and apartment
  number (AO-R15, HFL 9 a §), access notes (encrypted, §9);
- price form: `TIME_AND_MATERIAL` (löpande räkning, with hourly rates per labour kind and a
  material mark-up), `FIXED` (contract sum), or `ESTIMATE` (löpande räkning with an
  approximate price including VAT and an optional agreed other limit, AO-R6);
- tax reduction: none, ROT or RUT (customer must be a private person; the property is
  required for ROT, as `IssueInvoice` already requires);
- VAT: reverse charge copied from the customer and locked on the work order (AO-R20). ROT/RUT
  and reverse charge on the same work order are refused;
- `is_construction` (default on): drives the invoice deadline flag (AO-R22) and the
  personalliggare hint (AO-R27);
- standard contract named (free list plus "annat"; never clause text, AO-R37);
- assigned users; planned start and end;
- status DRAFT → PLANNED → IN_PROGRESS → DONE → CLOSED, or CANCELLED. DONE stops reporting;
  CLOSED means everything is invoiced or written off as not billable.

A work order is not bookkeeping. It creates no voucher.

### 3. Time and material

- `work_order_time_entries`: work order, optional ÄTA, user, date, minutes, labour kind
  (company list: "Arbete", "Resa", "Maskin"; only kinds marked labour may become `is_labour`),
  billable flag, rate in öre (from the work order, editable by `manage`), note, source
  (`MOBILE`, `WEB`, `STAFF_LEDGER_PROPOSAL`), entered at, invoice row id once invoiced.
- `work_order_material_entries`: work order, optional ÄTA, article or free text, quantity in
  milli-units, unit, cost in öre, sales price in öre, source: `STOCK` (issues stock through
  Inventory as a new movement type `WORK_ORDER_ISSUE`, when Inventory is on), `SUPPLIER_INVOICE_ROW`
  (a row of an approved supplier invoice is marked "vidarefakturera på arbetsorder"; one
  supplier row can be billed once), or `FREE`.
- Both are append-only. A wrong entry is corrected by a reversing entry with a reason, as
  vouchers are corrected; invoiced entries cannot be reversed, only credited through a credit
  invoice. Each act is audited with ids only.
- Time on a work order is **billable time**, not pay time and not attendance. It never
  becomes a payroll time report by itself. When Payroll is on, a manager may turn a week's
  entries of an hourly-paid employee into proposed `time_reports` with the work order's
  project, approved in payroll as today (later step 9).
- No per-person productivity analysis (as ADR-071 §7 keeps it off by default). The work
  order shows hours per labour kind and per day, not rankings of people.

### 4. Change orders (ÄTA)

`change_orders` (company-scoped, tenancy test), one per change, numbered per work order
(ÄTA 1, 2 …), with the content in the rule sheet §D: scope, basis (change ordered by the
customer, tilläggsarbete under KtjL 8 §, customer circumstances under 38 § p. 2), price form
(fixed, or löpande räkning at stated rates, or approximate with a limit), amounts per labour,
material and other, VAT, the expected ROT/RUT and what the customer pays, the effect on time,
payment terms, and whether ROT applies (inherited, can be switched off, e.g. insurance-paid
work; AO-R17).

Statuses:

```
DRAFT → SENT → APPROVED | DECLINED | EXPIRED | WITHDRAWN
DRAFT → APPROVED_ORALLY          (user records who, when, channel; weaker evidence, shown so)
DRAFT → PERFORMED_WITHOUT_ANSWER (KtjL 8 § second/third paragraph, AO-R4)
```

- **Price before work.** Time and material can be reported on an ÄTA in any status, but
  entries on an ÄTA that is not APPROVED, APPROVED_ORALLY or PERFORMED_WITHOUT_ANSWER are held
  back from the invoice basis and the work order shows "ÄTA 3 är inte godkänt" on the field
  worker's screen.
- **Performed without answer** requires one of the three reasons in KtjL 8 §, at least one
  logged contact attempt and a justification; the customer then gets the ÄTA PDF by e-mail.
  NONOX never judges whether the price is "obetydligt" (L3).
- **Approval reuses ADR-046.** The same signing engine (token, page, document SHA-256, drawn
  or typed signature, certificate, immutable evidence, rate limits, CSP) with the ÄTA PDF as
  the document. ADR-046 lives inside the CRM module and invalidates every link while CRM is
  off. This ADR proposes to **move the signing engine to core** (`App\Support\Signing`, a
  `Signable` interface with one implementation per document type: quote, agreement,
  change order), keeping CRM's behaviour and tests unchanged; each document type decides
  whether its module is on. Owner question O1 (alternative: the module requires CRM).
- **On site.** Two ways: the link by e-mail (default), or "Signera på plats", which opens the
  same signing page on the field worker's phone for the customer to sign; the evidence then
  records the user and device that presented it. Owner question O4.
- An APPROVED or DECLINED ÄTA, its PDF and its evidence are immutable (PostgreSQL trigger as
  in ADR-046) and are kept as räkenskapsinformation for the retention period and included in
  the archive package (AO-R25).
- The approved PDF is always e-mailed to the customer (AO-R10: a durable copy either way).
- Consumer warnings on the work order (AO-R6, AO-R7): when recorded main work passes 115 %
  of an approximate price (or the agreed other limit), with approved ÄTA amounts shown apart.

### 5. Invoice basis

"Skapa fakturaunderlag" (needs `work-orders:manage` and `invoices:create`) selects uninvoiced
entries up to a date and builds a **draft invoice** through `SaveDraftInvoice`, never an
issued one:

- Rows grouped: main work, then each approved ÄTA under a heading row with its number;
  per group labour per labour kind (hours × rate), material per article, travel and machines.
- ROT/RUT: the draft gets the work order's type and property; only labour kinds marked
  labour become `is_labour`; travel and material never (AO-R13). The deduction is computed by
  `TaxReduction` exactly as today.
- Hours: the draft records the invoiced labour hours and the kind of work
  (`invoices.labour_hours_milli`, `invoices.work_description`, new columns, part of the
  issued invoice's immutable content; the invoice trigger is updated with tests first). The
  invoice page shows them next to the "begär hos Skatteverket" help. A ROT/RUT work order
  with no recorded hours cannot produce a basis (AO-R14, F10).
- Fixed price: the basis takes the contract sum (or the next a conto instalment, §6) plus
  approved ÄTA; recorded hours still go on the invoice for ROT.
- Reverse charge: inherited; the draft is a reverse-charge invoice as today. A work order
  whose rows would mix reverse charge and other VAT is not allowed (separate invoices, L9).
- A specified attachment (hours per day, rates, material, ÄTA numbers) is always available
  as a PDF for KtjL 40 § (AO-R8).
- Entries are reserved by the draft and released if the draft is deleted; they become
  invoiced when it is issued.
- Construction invoice deadline: uninvoiced entries of a construction work order are flagged
  per month of performance with the last day under ML 17 kap. 16 § (AO-R22, F6).

### 6. A conto and payment plans

A FIXED or ESTIMATE work order can have a payment plan (amounts or percentages with dates or
milestones). The plan is part of what the customer approves (a quote signed through §4's
engine, or the work order confirmation). Each instalment becomes an ordinary draft invoice
through §5, with its own ROT on its own labour share (AO-R16). The final invoice invoices the
remainder as rows of its own (L14). A conto plans are not offered under kontantmetoden until
L10 is answered (ROT already requires fakturametoden). Issued invoices are booked exactly as
today; nothing in ADR-011 changes.

### 7. Year-end: ongoing work

A closing step "Pågående arbeten" lists the open work orders on the balance sheet date and,
depending on the price form and the company's method (`fixed_price_method`: K2 main rule,
K2 alternative rule, K3 completion method, not set), shows recorded-not-invoiced work and
material, invoiced-ahead amounts, costs recorded, contract sum including approved ÄTA, and a
degree of completion from costs. It proposes an adjustment voucher with reversal on the first
day of the next year (AO-R30–R33, F7a, F7b). It never books by itself. ÄTA on a fixed-price
work order count into that work order unless the user has split them off (AO-R32).

This step changes accounting behaviour: it goes through the accounting check, updates
`docs/systemdokumentation/README.md`, and stays behind L12 and L13 before it ships.

### 8. Mobile use on site

- The field screens are part of the installable web app (ADR-031) under `/arbetsorder`
  (Swedish paths, AGENTS.md §11.4): my work orders today, report time (a stepper for hours
  and minutes, or start/stop), add material by search or by scanning the article's code
  (the scanner from ADR-040 when Inventory is on), take photos, write a note, start an ÄTA,
  let the customer sign.
- Large touch targets, works one-handed, no table layouts; one request per action.
- v1 needs a network. An offline queue in IndexedDB with idempotent UUIDs, as the staff
  ledger kiosk does (ADR-022), is a later step (step 8 below) and is designed so entries
  keep the device's own time and the server's receive time.
- The field worker never sees prices other than their own entries' quantities, never the
  customer's personal number, and only the work orders they are assigned to.

### 9. GDPR

- The ROT buyer's personal number already lives encrypted and hidden on the customer
  (`customers.personal_number`); the work order never copies it, and no work order screen,
  export, API or audit entry shows it (AO-R38). Whether the ROT invoice must print it is L6;
  until answered NONOX keeps today's PDF.
- New categories (AO-R39): site address of a private person, access notes (encrypted at
  rest, removed automatically when the work order is CLOSED or CANCELLED, never in the audit
  log), photos (stored on the private disk; kept with the work order; part of
  räkenskapsinformation only when attached to an ÄTA or an invoice basis), and time per
  worker (needed for billing; no per-person analysis).
- ÄTA signing evidence follows ADR-046 (name, title, signature, IP, user agent).
- When built: `docs/gdpr/registerforteckning.md`, `lagringstider.md`, the privacy policy and,
  for erasure, the existing register-extract and erasure actions extended to work orders
  (erasure blocked where BFL retention applies). L16 before release.

### 10. Relation to other work

- **ADR-071 (manufacturing, built later).** Both have time on an order and material from
  articles. They stay separate tables (production time is a cost of goods; work-order time is
  billable to a customer), but use the same shapes: minutes as integers, append-only entries
  with reversing corrections, `MovementType` cases for stock issues (`PRODUCTION_ISSUE` there,
  `WORK_ORDER_ISSUE` here), the same article search and scanner. Purchase orders (ADR-071 §2)
  may later be created from a work order's material shortfall; not in v1. The workshop
  terminal is not used for field work.
- **Customer portal (roadmap `kundportal`, item 6).** The portal is a second channel for the
  same ÄTA: a signed-in customer contact sees their work orders' ÄTA and approves them with the
  same evidence record, method `PORTAL`. v1 uses the e-mail link only; the ÄTA model must not
  assume the link is the only way. The portal ADR decides the login.
- **Staff ledger.** When construction support (ADR-022 v2) exists, a work order can name the
  ledger location of its site, and the work order shows the personalliggare hint (AO-R27).
  Attendance never turns into billable time automatically (AO-R29).
- **CRM.** A won deal's quote becomes an order and can become a work order; deals are not
  changed.

### 11. Out of scope

- Construction staff ledger (ID06, byggarbetsplatsanmälan) — ADR-022 v2.
- Småhusentreprenad specifics (final inspection, KtjL 51–61 §§), besiktning protocols.
- Standard contract clause texts of any kind (AO-R37).
- Resource scheduling and dispatch on a map; route planning; mileage logs for tax.
- Payroll from work-order time without a manager's approval in payroll.
- Per-person productivity analysis.
- A claim file to Skatteverket for ROT/RUT (the user claims in Skatteverket's e-service, as
  today); ADR-054 may add it.
- Several ROT buyers sharing one invoice (spouses splitting the deduction) — owner question
  O6; today one buyer per invoice.
- Recording the buyer's payment method for the electronic-payment rule (AO-R18) — an
  invoicing change of its own.

## Consequences

- One new module. Invoicing gains two columns on invoices (hours, kind of work) and the
  trigger that protects issued invoices is updated; quotes and orders are unchanged.
- The signing engine moves from CRM to core if O1 is accepted; CRM's behaviour and tests must
  stay identical.
- `MovementType` gains `WORK_ORDER_ISSUE`; supplier invoice rows gain a re-invoice link.
- The closing gains a "Pågående arbeten" step after the accounting check.
- Public pages: the roadmap text claims the staff ledger is ready for this flow; for
  construction it is not. The landing page, `Features.php`, `jamforelse.json`, the manual and
  the changelog are updated when each step ships, never before.

## Build steps (tests first in each)

Each step: rule sheet rules referenced in tests, failing tests first, tenancy test for every
new model (another company's id → 404), Swedish toasts, manual article, changelog entry,
cold reviewer, Lead merges.

0. **Answers.** Lead sends L1–L16 to the lawyer/accountant; owner answers O1–O8. HARD rules
   whose L-question is open are built as warnings.
1. **Signing to core** (if O1 = yes). Move without behaviour change; the full CRM signing
   suite stays green unchanged; a new test proves a second `Signable` type works with CRM off.
2. **Work orders core.** Module, permissions, roles, model, number series, project creation,
   policies, from quote/order, reverse charge/ROT exclusivity, tenancy suite.
3. **Time and material.** Append-only entries with reversals, inventory issue movement,
   supplier invoice row re-invoicing (billed once), audit with ids only.
4. **Invoice basis.** Draft invoice building, `is_labour` only from labour kinds, hours and
   kind of work on the invoice (trigger tests first), ROT/RUT via `TaxReduction`, reverse
   charge inherited, specified attachment, ML 17:16 deadline flag. Fixtures F1, F4, F5, F6, F10.
5. **ÄTA.** Model, statuses, PDF, signing, oral approval, performed-without-answer with
   reasons and attempts, immutability trigger, held-back entries, consumer price warnings.
   Fixtures F1, F2, F3.
6. **Mobile field screens** (online): my day, report, material with scanner, photos, start an
   ÄTA, sign on site (if O4 = yes). Query-count tests (no N+1).
7. **A conto plans** with ROT per instalment and the final remainder invoice. Fixture F9.
8. **Offline queue** for field reporting (IndexedDB, UUID idempotency, device time).
9. **Payroll proposals** from work-order time for hourly-paid employees.
10. **Year-end "Pågående arbeten"** after the accounting check and L12/L13; systemdokumentation
    updated. Fixtures F7a, F7b.
11. **Staff ledger link** when ADR-022 v2 (construction) exists. Fixture F8.

## Owner questions

| # | Question | Proposal |
|---|---|---|
| O1 | Move the ADR-046 signing engine to core so ÄTA can be signed without the CRM module, or require CRM for ÄTA signing? | Move to core |
| O2 | Create a project automatically for each work order (code = work order number), or only link an existing project when the user picks one? | Automatic, can be switched off per company |
| O3 | Field workers as NONOX users (e-mail code, role `field_worker`), or as kiosk-style persons without a login like the staff ledger? | NONOX users |
| O4 | Allow "Signera på plats" on the field worker's phone, with weaker evidence, or only the e-mail link? | Allow, marked as on-site signing |
| O5 | Print the ROT buyer's personal number on the invoice (Skatteverket's page lists it), once L6 is answered? | Follow L6 |
| O6 | Support several ROT buyers on one work order (split per person)? | Not in v1 |
| O7 | Default labour kinds and whether travel is billed per trip, per km or not at all | "Arbete", "Resa" (per trip), "Maskin" |
| O8 | Module name in the UI: "Arbetsorder" alone, or "Arbetsorder och ÄTA"? | "Arbetsorder" |

The first pilot is a craftsman company. Its name and data never go into the repository.

## Addendum 2026-11-08: Lead decisions on the owner questions

Decided by Lead 2026-11-08 under the owner's mandate, open for the consultant's review.

- **O1 Signing:** moves to core (`app/Domain/Signing`). The customer portal (ADR-075) does the
  same move, so it is done once, by the portal build. This module builds on that shared
  signing domain and does not move it again.
- **O2 Projects:** a project is created automatically for each work order. The company can
  switch this off.
- **O3 Field workers:** NONOX users with the role `field_worker` ("Utförare"), signing in with
  the e-mail code like everyone. They see only their own work orders.
- **O4 Signing on site:** allowed on the worker's phone, but only with a code sent to the
  customer's own e-mail or phone. The worker never approves on the customer's behalf.
- **O5 Personnummer on the ROT invoice:** printed, because Skatteverket's page lists it, until
  L6 says otherwise. It is kept as `TODO(legal-verify)`.
- **O6 Several ROT buyers:** supported, each with their own share, for example two spouses.
- **O7 Defaults:** the kinds of work are Arbete, Resa and Material. Travel is billed as a
  fixed amount or per km and never counts toward ROT.
- **O8 Name in the UI:** "Arbetsorder".

The roadmap text is corrected: the staff ledger for construction is not built yet (ADR-022
v2), so this module does not claim it.

## Addendum: as built (2026-11-08, branch feat/work-orders-ata)

Built: steps 2–7 and 10 of the build steps, on the shared signing domain from the customer
portal build (commit c6f3bd93, merged). Not built: step 8 (offline queue; reports already
carry a device-made UUID so a repeat is stored once), step 9 (payroll proposals) and step 11
(staff ledger link).

- **Module** `app/Modules/WorkOrders`, key `work_orders`, permissions as in §1, role
  `field_worker` ("Utförare") with `work-orders:view` and `work-orders:report`. A field worker
  is recognised by the role: they see only assigned work orders, get the field screen instead
  of the office page, and never see prices. `work-orders:view` is the archive permission, so
  decided ÄTA PDFs stay readable while the module is off (route outside `module:`).
- **Tables**: `work_orders`, `work_order_labour_kinds` (type LABOUR/TRAVEL/MACHINE, unit
  HOUR/TRIP/KM; Arbete and Resa seeded), `work_order_users`, `work_order_buyers`,
  `work_order_time_entries` and `work_order_material_entries` (uuid ids, append-only trigger
  `work_order_entries_append_only`: only `reversed_by_id` once and `invoice_id` while the
  invoice is a draft may change), `change_orders` (trigger `change_orders_protect_decided`),
  `change_order_contact_attempts` (append-only), `work_order_payment_instalments`,
  `work_order_invoices` (BASIS, INSTALMENT, FINAL; the contract share and the fixed-price ÄTA
  each invoice takes), `work_order_photos` (private disk), `change_order_on_site_codes`.
  Check constraints: price form, status, ROT/RUT xor reverse charge.
- **Core changes**: `invoices.labour_hours_milli` and `invoices.work_description` (protected
  by the existing `invoices_protect_issued` trigger, which compares every column not listed as
  mutable, so no trigger change was needed; a test proves it); `invoice_tax_reduction_buyers`
  for several buyers (O6) with `TaxReduction::used()` counting each buyer's share and
  `recheck()` per buyer; the invoice form gained "Arbetade timmar" and "Typ av arbete"; the
  ROT/RUT PDF prints hours, kind of work and the buyer's personnummer (O5, `TODO(legal-verify)`
  L6); `MovementType::WorkOrderIssue`; `SupplierInvoiceRow::supplierInvoice()`;
  `NumberSeries::WORK_ORDERS`; a non-blocking "Pågående arbeten är granskade" point (WARN) in
  the year-end checklist while the module is on; destination `work-orders` in
  `CompanyOverview` for signing notices.
- **Signing**: `ChangeOrder` implements `Signable` with the type **`ATA`** (SignableTypes
  allows at most ten characters, so not `CHANGEORDER`), key in `signable_id`. Sending makes the
  ÄTA SENT (content then fixed); `markSigned` makes it APPROVED with the signer's name and
  `approval_method` LINK or ON_SITE; `WorkOrdersSigningHook::declined` makes it DECLINED.
  On site (O4): a new link is made for the same ÄTA and a six-digit code goes to the customer's
  own e-mail; the field worker's phone opens the signing page only after the code (hash only,
  15 minutes, five tries, the counter committed apart from the error). A field worker may
  present only an ÄTA the office has already sent. SMS is not built: e-mail only.
- **Invoice basis** as §5; hours in thousandths rounded from minutes. ROT/RUT basis without
  labour hours is refused (F10, AO-R14, also at fixed price). An a conto instalment does not
  require hours (the work may not have started); the final invoice does.
- **A conto** for FIXED only. ESTIMATE plans wait for L14 (how the final invoice deducts a
  conto on löpande räkning without negative rows). Kontantmetoden: refused (L10).
- **Year-end** `OngoingWork` with methods NOT_SET, K2_MAIN, K2_ALTERNATIVE, K3_MAIN,
  K3_COMPLETION in the module settings; costs from posted voucher rows on accounts 4000–7999 on
  the work order's project; estimated total cost typed on the page (not stored). Proposals
  become two draft vouchers (adjustment, reversal next day), never posted. Accounts 1620, 2420,
  2450 created with their names when missing; 1470 shown as a figure only (change account L12).
- **Advice** (`Advice`): KtjL consumer = no organisation or VAT number (L1); 15 % or the
  agreed limit on main work incl. VAT, ÄTA apart (F3); ML 17:16 deadline per month from the
  first day of the deadline month (F6); personalliggare hint above four prisbasbelopp for a
  business customer (F8; 2027 amount `TODO(legal-verify)`); ROT a conto paid one year without
  finished work by 31 January (F9, shown on the work order only, not yet on the invoice page).
- **Open**: the legal version bump (folded into the Lead's 1.7 bump; the privacy
  policy and DPA texts are updated), and a credited final invoice does not reopen the
  contract sum.

### Review fixes (2026-11-08)

- **Credits with several buyers** reverse each buyer's own deduction on the original, net of
  that buyer's earlier credits, in proportion to the credit; a buyer with no deduction gets
  none back (P1-1).
- **Hours** (Lead): every invoice from a work order claims the labour minutes recorded and not
  claimed by an earlier invoice of the order (`work_order_invoices.labour_minutes`); a conto
  invoices reserve no time entries; a ROT/RUT basis is refused only when the order has no
  recorded labour at all. `TODO(legal-verify)`: an invoice with ROT and no new hours (P1-2).
- **Field workers** get 404 on the ÄTA PDF; the signed copy goes to the sender, else a member
  a module names (the work order's creator), never to a field worker (P2-1).
- **Locks**: once an ÄTA is sent or decided, the work order's customer, VAT, ROT/RUT, price
  form and property are fixed; the ÄTA's ROT rate is taken on the day it was sent or decided;
  the PDF of an oral or performed-without-answer decision is stored with its SHA-256 (P2-2).
- **Triggers**: the buyer trigger checks old and new invoice (P2-3); a SENT ÄTA can only move
  to SENT or a decided status (P2-4); an entry on an issued invoice cannot get a reversal link,
  and an entry can only be reserved by a draft (P3-4).
- **Year-end**: on löpande räkning only main work and invoiceable ÄTA count, a fixed-price ÄTA
  at its amount (P2-5); advice when the estimate exceeds the price (K2 6.19) and when the
  method changes after a closed year (K2 6.15) (P3-7).
- **On-site code**: HMAC keyed with the app key; the link is forgotten on expiry and after the
  fifth wrong try (P3-1). EXPIRED is set when the work order is read and every link has run
  out (P3-9).
- **Smaller**: oral approval and performed-without-answer need a price or löpande räkning
  (P3-2); a field worker cannot edit a priced ÄTA (P3-3); the supplier row is locked when billed
  on and a unique violation becomes a validation error (P3-5); hours show three decimals
  (P3-6); the buyer's personnummer prints only on invoices issued from this release on
  (`invoices.prints_buyer_personal_number`, set at issue) (P3-8).

### Re-check fixes (2026-11-08)

- `RecordMaterial` tells the unique violations apart by name: the supplier-row index
  (`RecordMaterial::SUPPLIER_ROW_INDEX`) gives "Raden är redan vidarefakturerad"; a repeated
  device id on the primary key returns the stored entry (idempotent).
- The signing core no longer knows module roles: `SigningHook::mayReceiveCopy()` lets a module
  refuse the company copy for a member; the work-order hook refuses field workers, CRM allows
  everyone.
- A ROT/RUT invoice without new hours prints "Arbetade timmar: redovisade på faktura N"
  (`invoices.labour_hours_reported_on`, the earlier invoices of the order that claimed the
  hours) instead of 0. `TODO(legal-verify)` kept.
- The decision PDF of an oral or performed-without-answer ÄTA is written inside the
  transaction (its path is part of the immutable row) and deleted if the transaction fails.
- EXPIRED is written by `work-orders:expire-change-orders` every fifteen minutes, per company
  with the module on, in that company; the pages only show the label for a lapsed link.
