# ADR-043: NONOX CRM

- Status: **Accepted for stages 1 and 2, implemented** (see Implementation and Stage 2: implementation); stages 3–6 are proposals, decided one at a time
- Date: 2026-11-03
- Builds on: ADR-017 (module framework), ADR-032 (NONOX Connect), ADR-035
  (company registry), the customer register and quotes/orders (Phase 2),
  the GDPR tooling (register extract, erasure, `privacy:prune`)
- Changes: AGENTS.md §7 listed CRM as out of scope ("separate modules
  later"). The owner has explicitly added it (2026-11-03); this ADR records
  the scope.

## Context

Small and medium-sized Swedish companies keep their customers in a CRM and
their money in an accounting system, and the two never agree. NONOX already
knows every invoice, payment, reminder, quote and order per customer. A CRM
inside NONOX can show what a customer is worth, how fast it pays and what
happened last, without an integration. The owner's brief (2026-11-03) asks
for the functionality of the leading Swedish CRM for small companies, without
AI suggestions, without advanced marketing automation and without custom
objects. Quotes and agreements are signed with a signature pad, not BankID.

The owner's decisions (2026-11-03):

1. **Company data from open data first** (Bolagsverket and SCB). Credit
   information comes later through a replaceable driver, when a contract
   with a credit reporting company exists.
2. **Newsletter statistics count clicks only.** No tracking pixel: opens are
   not measured, so no consent under LEK 9 kap. 28 § is needed.
3. **The CRM replaces the customer register when switched on.** `/kunder`
   becomes the CRM. A company without the module keeps the plain register.
   A prospect gets a customer number only when it becomes a customer.
4. **One stage at a time**, each shown to the owner before the next starts.

## Decision

A module **CRM** (key `crm`), off by default, depending on nothing but the
invoicing core. Turning it off hides it and keeps every row.

### Stages

1. **Core** (this ADR, decided): accounts, people, relations, timeline,
   status rules, key figures from the ledger, activities with a next step,
   phone-first screens, GDPR.
2. Pipelines and deals, loss reasons and win-back, forecast, goals per
   seller, dashboard (decided 2026-11-03, see Stage 2: implementation).
3. Quote and agreement signing with a signature pad; a signed quote becomes
   an order and then an invoice; agreement register with renewal reminders.
4. Prospecting: a company register from open data with filters, look-alike
   search by SNI, size class and region, call lists, monitoring of existing
   customers (new board, address, bankruptcy and liquidation).
5. E-mail and mailings: logging by BCC/forward to the company's inbox
   address, templates, newsletters to segments with click statistics and
   unsubscribe, web forms that create leads.
6. Calendar export (ICS) first, then two-way calendar and mail sync with
   Google and Microsoft once app registrations exist.

### Stage 1: data model

All tables are company-scoped (`BelongsToCompany`, tenancy tests).

- **`crm_accounts`**: the company or person the business deals with, prospect
  or customer. Name, organisation number (Luhn), kind (company/person),
  status, owner (a user), parent account (group of companies), SNI code,
  website, e-mail, phone, address, source, tags, `customer_id` (nullable,
  unique). When the module is switched on, every existing customer gets an
  account. An account becomes a customer, with a customer number from the
  existing series, when someone chooses **Gör till kund** (see
  "Implementation" below: quotes, orders and invoices need a customer, so the
  card makes the customer first and then opens the quote or invoice);
  the ledger fields (payment terms, VAT, reverse charge, currency) stay on
  `customers`, so bookkeeping is unchanged.
- **`crm_people`**: name, title, e-mail, phone, mobile, legal basis
  (agreement, legitimate interest, consent) with a note and the consent date,
  active flag. A person can belong to several accounts.
- **`crm_account_person`**: the person's role at the account: decision
  maker, ambassador, influencer, user, invoice recipient, other.
- **`crm_activities`**: note, call (with outcome: reached, no answer, left
  message, wrong number), meeting, e-mail (logged by hand in stage 1), task.
  Subject, text, account, person, assigned user, due time, done time.

### Stage 1: behaviour

- **Timeline** per account: activities plus quotes, orders, invoices,
  payments and reminders read from the ledger, newest first, paginated, with
  a fixed number of queries.
- **Status**: Prospekt → Aktiv kund → Inaktiv → Förlorad. A prospect becomes
  active with its first invoice; an active customer becomes inactive after
  a number of months without an invoice (setting, default 12); lost is set
  by hand with a reason. A daily command applies the rules; a status set by
  hand is kept until the next ledger event changes it.
- **Next step**: every open prospect or active account shows its next open
  activity. Completing an activity asks for the next one (one tap to skip).
  "Mina aktiviteter" lists what is due today and overdue.
- **Key figures from the ledger**, per account: invoiced last 12 months and
  in total (net of VAT, credit notes deducted), open and overdue receivable,
  average payment time in days (payment date minus invoice date, weighted by
  amount), and contribution margin where articles have a purchase price
  (invoiced net minus quantity × purchase price; the share of sales with a
  known cost is shown next to it, so a margin built on half the rows is not
  read as the whole truth).
- **Phone first**: list and account card work at 360 px; call, e-mail and map
  are links (`tel:`, `mailto:`); quick buttons for call, meeting, note and
  task; the installed app (ADR-031) needs nothing more.
- **Permissions**: `crm:view`, `crm:manage`, `crm:settings`. New role
  **Säljare** (`sales`): `crm:view` and `crm:manage`. A seller sees invoiced,
  unpaid and payment time per customer, but no invoices, vouchers or
  reports; for a private person (kind PERSON) no key figures and no ledger
  entries on the timeline, only the CRM's activities (see Implementation).
  Key figures are visible with `crm:view`: a seller needs to know whether a
  customer pays. Owner, admin, accountant and bookkeeper get all three;
  auditor gets `crm:view`.
- **API**: accounts, people and activities under `/api/v1/crm/…` with the
  existing token abilities pattern, using the same Actions as the screens.

### GDPR

- A legal basis per person is required. Prospects' contact people are
  normally legitimate interest (B2B); the screen says so and links to the
  balancing note in the manual. `TODO(legal-verify)`: the balancing test text
  and the retention period for prospects.
- The register extract (`/registerutdrag`) includes CRM data. Erasing a
  person anonymises their activities. An account linked to invoices is never
  deleted (BFL); only the person data around it can be.
- `privacy:prune` gets a CRM step: people and prospect accounts with no
  activity for a set number of months (setting, default 24) are listed in
  "Att göra" 30 days ahead and then erased, unless they are linked to the
  ledger.

## Consequences

- The plain customer register remains the default for companies that do not
  want a CRM; nothing changes for them.
- Stage 3 introduces the first public pages where an outsider signs a
  document; that stage gets its own security and legal review (simple
  electronic signature under eIDAS, document hash, audit trail).
- Stage 4 depends on what Bolagsverket's and SCB's open data actually
  contain and permit; it is researched before it is designed.
- Stage 6 needs the owner to register apps with Google and Microsoft.

## Implementation (stage 1, 2026-11-03)

Built in `app/Modules/Crm` (module key `crm`). Decisions taken while
building, where this ADR left them open or the code required a change:

- **Becoming a customer.** Quotes, orders and invoices take a customer, so a
  prospect becomes one by **Gör till kund** on its card (`ConvertToCustomer`,
  through `SaveCustomer`, next number in the customer series). The card then
  links to a new quote or invoice with the customer filled in (`?kund=<id>`
  on the quote, order and invoice editors). The status stays Prospekt until
  the first invoice.
- **Shared fields.** Name, organisation number, e-mail, phone and address of
  a customer's account follow the customer register: an observer on
  `Customer` copies them on every save, and a change on the CRM card goes
  through `SaveCustomer` and needs `invoices:create`. A seller changes only
  the CRM fields. The same observer gives a customer created anywhere (the
  register, an invoice, the API, an import) an account while the module is
  on, and removes the account when the customer is erased under GDPR (it is
  deleted only when never booked).
- **URLs.** `/kunder` stays the core route and shows the CRM list while the
  module is on and the user has `crm:view`, otherwise the plain register. The
  card is `/kunder/{account id}`; the register's old links keep working
  (`?redigera=<customer id>` opens the card, `?ny=1` the new-prospect form).
  `/kunder/aktiviteter`, `/kunder/personer` and `/kunder/installningar` are
  the module's own pages (404 while off). The sidebar gets a CRM section
  that starts on Mina aktiviteter.
- **Status of existing customers.** The backfill applies the status rules at
  once: a customer without an issued invoice starts as Prospekt. Credit
  invoices are not new business. A lost account without any invoice stays
  lost. The daily command skips archived companies.
- **Key figures.** Average payment time counts payments of the last 12
  months (not refunds or recovered write-offs). The contribution margin is
  for the last 12 months and uses the article's purchase price today; the
  coverage is the share of the period's net sales on rows with a known cost.
- **Pruning.** "Linked to the ledger" is read as: a prospect that has become
  a customer is never pruned (it is in the customer register), and a person
  who is the invoice recipient at a customer is never pruned. Lost accounts
  without a customer count as prospects. The clock is the last activity on
  the account or person (any save, link or activity). `TODO(legal-verify)`.
- **Overview.** CRM's "Att göra" is a personal provider (the overdue
  activities are the user's own).
- **API.** `PATCH /crm/accounts/{id}` with `status` (and `lost_reason`) sets
  the status by hand; `PATCH /crm/activities/{id}` with `"done": true`
  completes an activity.
- **Look.** The app has one (light) theme; the CRM uses the app's own
  components and adds no dark-mode styles of its own.

### Review round (2026-11-03)

- **Audit log.** CRM writes only ids, field names, statuses and whether a
  reason was given to the append-only log; never names, numbers, contact
  details or free text. (The core customer register still logs a customer's
  name on `customer.created`; that is outside the CRM.)
- **Anonymised customers.** `EraseRegisterEntry` dispatches
  `CustomerAnonymised` in its transaction; the CRM clears website, source,
  tags, SNI code, lost reason, the person links and the subject and text of
  the account's activities. The backfill skips anonymised and restricted
  customers, and the card offers no new quote, order or invoice for an
  inactive or restricted customer (`?kund=` uses the editors' own filter).
- **Personal numbers.** The CRM refuses a personal number in
  `org_number` and never copies a sole trader's personal number from the
  customer register. The core register keeps it in plain `org_number`
  (a known gap outside this module).
- **Kontakta inte.** A person can be flagged (objection, art. 21, or
  restriction, art. 18). After an objection only notes can be logged; after
  a restriction one note documenting it and nothing more, counted from when
  the restriction (or a change of reason) was set. Under a restriction the
  person's existing activities cannot be edited, only completed, and setting
  it closes their open activities (audited with ids only). No activity of a
  flagged person can change its type. Only an objection survives erasure,
  as an HMAC of each e-mail address and phone number in `crm_suppressions`
  (art. 21(3)), so the person is flagged again if added back; a restriction
  ends with the erasure. The key is `NONOX_CRM_SUPPRESSION_KEY` (else derived
  from `APP_KEY`), and matching also tries keys derived from `APP_KEY` and
  `APP_PREVIOUS_KEYS`. This is pseudonymisation. Entries not reviewed for
  `suppression_review_years` (default 3) are listed in Att göra.
  `TODO(legal-verify)`: how long the list is kept and the review default.
- **"Ny kund" for a sole trader.** The customer form under Kunder creates
  the customer through `SaveCustomer` with the number as typed (a sole
  trader's personal number included); the account never keeps it.
- **Anonymised private person.** When a PERSON account's customer is
  anonymised, the people linked only to that account are erased too; a
  company's contacts stay.
- **Information duty (art. 14).** An optional "Informerad den" date on the
  person, and a template text in the manual (`TODO(legal-verify)`).
- **Sellers and private persons.** Without `invoices:view`, a private
  person's account shows no key figures (not even invoiced) and a timeline
  of CRM activities only: no quotes, orders, invoices, payments or
  reminders. No one sees links into invoices they cannot open.
- **Auditor** keeps `crm:view`: an auditor already sees the invoices, and
  the CRM shows how customers were followed up. The manual says so.
- **"Lägg till kund".** `?ny=1` opens a customer form for those who may add
  customers (the account is created and turned into a customer in one
  transaction) and a prospect form for others.
- **Navigation.** With CRM on and `crm:view`, Kunder is only under CRM;
  others still find it under Register.
- **Person extract while off.** The contact-person extract works with the
  module off, for those with `settings:manage`.

## Stage 2: implementation (2026-11-03)

The owner's brief for stage 2: a visual pipeline with customisable stages
(at most seven, several pipelines per business area), deals with value,
probability per stage, close date and owner, a mandatory next step for
every open deal, a loss reason and a win-back flow, goals per seller, a
dashboard and a forecast. No AI. The economy is the advantage: a won deal
links to the quote, order or invoice, and goals are measured from real
data.

### Data model

All tables are company-scoped (`BelongsToCompany`; tenancy tests in
`tests/Tenancy/CrmDealTenancyTest.php` and `TenancyIsolationTest`), money is
integer öre.

- **`crm_pipelines`**: name, sort, `is_default`, `archived_at`. Exactly one
  active pipeline is the default.
- **`crm_pipeline_stages`**: pipeline, name, sort, `probability` (whole
  percent, CHECK 0–100). At most seven per pipeline (`Pipeline::MAX_STAGES`),
  enforced in `SavePipeline`. Won and lost are the deal's status, not stages.
- **`crm_deals`**: account (required), person (nullable, must be linked to
  the account), pipeline, stage, title, `value_ore` (excluding VAT, CHECK
  ≥ 0), `expected_close_date`, owner, status `OPEN`/`WON`/`LOST`, `won_at`,
  `lost_at`, `loss_reason_id`, `loss_note`, `quote_id`/`order_id`
  (`sales_documents`) and `invoice_id`, `win_back_at`, `win_back_task_at`.
- **`crm_loss_reasons`**: the company's list, archivable.
- **`crm_deal_stage_changes`**: deal, from stage (null on creation), to
  stage, user, time. History only; never updated.
- **`crm_goals`**: user, month (first day), metric
  `MEETINGS`/`QUOTES`/`ORDER_VALUE`, target (count, or öre). Unique per user,
  month and metric.
- `crm_activities.deal_id` (nullable) makes an activity a step of a deal;
  `crm_accounts.win_back_at`/`win_back_task_at` give a lost account the same
  win-back flow as a lost deal.

The default pipeline **Försäljning** (Ny kontakt 10 %, Behov 25 %, Offert
50 %, Förhandling 75 %) and six loss reasons are created by
`EnsurePipelineDefaults` in the module's enable hook (idempotent), and by
the migration for companies that already had CRM on (the values are written
out in the migration so it never changes with the action).

### Behaviour

- **Mandatory next step** (`DealNextStep`). `SaveDeal` refuses a new deal
  without `next_step` and creates the deal and its open activity in one
  transaction. `CompleteActivity` refuses the last open step of an open deal
  unless `next` is given, and then completes it and creates the next in one
  transaction (a note is not accepted as the next step: it is done when
  written). `LogActivity` refuses to mark that step done, turn it into a
  note or move it off the deal. On every screen (deal card, account card,
  Mina aktiviteter) completing the last step shows the next-step fields
  with no skip, and a way to win or lose the deal instead. Deals that still
  end up without a step (a restriction closes a person's activities,
  imported data) are listed in Att göra (the owner's own for everyone, all
  of them for `crm:settings`) and red on the board.
- **Board** (`/kunder/affarer`): a column per stage with count, value and
  weighted value; cards with account, value, close date (red when passed)
  and next step (red when overdue or missing). Desktop drags with plain
  HTML5 drag and drop in Alpine; every card also has a **Flytta** select,
  which the phone and the keyboard use. The phone shows one stage at a time
  with a switcher. No package was added (SortableJS is not in
  `package.json`). Filters for pipeline, owner and "utan nästa steg" live in
  the query string. At most 500 cards per board.
- **Win** (`WinDeal`): optional agreed value; optional quote, order or
  invoice, each checked to be the account's customer's (a credit invoice
  cannot be linked). A prospect becomes a customer through the existing
  `ConvertToCustomer` when `make_customer` is set *and* the user may add
  customers; a seller (no `invoices:create`) wins the deal without
  converting. Nothing is written to the ledger.
- **Lose** (`LoseDeal`): a reason from the active list is required, the
  note optional; `win_back_at` defaults to the setting `win_back_months`
  (default 6, 1–36) after today, can be given or cleared, and `SetWinBack`
  changes it later. A lost account (`SetAccountStatus` LOST) gets the same
  default date; a new reason keeps the date, another status clears it.
- **Win-back** (`crm:win-back`, daily at 05:40 after the status rules): one
  task "Vinn tillbaka" due at 09:00 for the deal's owner (else the
  account's, else the company owner; members only) on or after the date.
  `win_back_task_at`, set under a row lock, makes it idempotent; a new date
  allows a new task. Archived companies and companies with CRM off are
  skipped.
- **Settings** (`/kunder/installningar`, `crm:settings`): pipelines and
  stages (rename, reorder, probability, add up to seven, default, archive
  and restore), loss reasons (add, rename, archive), win-back months. A
  stage with deals (open or closed) is removed only by moving them to
  another stage of the same pipeline (`RemoveStage`; each open deal's move
  is in its history). A pipeline with open deals cannot be archived, nor
  the last active one.

### Figures

- **Forecast** (`Forecast`, `/kunder/prognos`): per month by expected close
  date, Σ(value × stage probability), rounded half up to whole öre once per
  month (never per deal), next to the value won that month; three months
  back and 6 or 12 ahead. Open deals whose close date has passed, deals
  without a date and deals beyond the last month are buckets of their own.
  Two queries.
- **Dashboard** (`/kunder/oversikt`, the CRM section's start): open value
  and weighted value, win rate = won / (won + lost) closed in the chosen
  period (30, 90 or 365 days), average sales cycle (calendar days in Swedish
  time from created to won, for deals won in the period; `SalesCycle`),
  completed activities per ISO week for the last eight weeks (notes
  included), the month's goals and the deals without a next step.
  Company-wide or "Mina". A fixed number of queries (tested).
- **Goals** (`GoalProgress`): set per seller and month by `crm:settings`;
  sellers see their own. MEETINGS = meeting activities assigned to the
  seller and completed in the month. QUOTES = quotes in the ledger with
  `created_by` = the seller and a quote date in the month:
  `sales_documents.created_by` records who made each quote, so no deal link
  is needed (a seller without `invoices:create` cannot make quotes and sees
  0; a company that wants this goal gives its sellers that right).
  ORDER_VALUE = the value of the deals the seller owns that were won in the
  month; next to it, the net invoiced on issued invoices dated in the month
  that are linked to the seller's won deals (directly, or as the invoice of
  the deal's order or quote), each invoice once per seller. Invoices have no
  seller, so that link is the only honest attribution.

### Permissions, audit, API, GDPR

- `crm:view` sees deals, the board, the forecast and the dashboard;
  `crm:manage` creates, moves, wins and loses; `crm:settings` changes
  pipelines, stages, loss reasons and goals. **A seller sees every deal of
  the company** (the simple model, so colleagues can take over a deal);
  "Mina affärer" is a filter, not a permission.
- Audit: `crm.deal_created`, `crm.deal_updated` (field names only),
  `crm.deal_moved`, `crm.deal_won` (ids of linked documents),
  `crm.deal_lost` (reason id, whether a note was given), `crm.win_back_set`,
  `crm.win_back_task`, `crm.pipeline_*`, `crm.stage_removed`,
  `crm.loss_reason_*`, `crm.goal_*`: ids, stages and statuses only, never
  titles, notes or names.
- API (Connect v1.6.0): `GET`/`POST /crm/deals`, `GET`/`PATCH
  /crm/deals/{deal}` (fields, `stage_id` moves, `status` `WON`/`LOST`,
  `win_back_at`), `GET /crm/pipelines`, `/crm/pipelines/{pipeline}`,
  `/crm/stages`, `/crm/loss-reasons`, and `deal_id` on activities;
  `PATCH /crm/activities/{id}` with `"done": true` and `next_step` completes
  a deal's last step. Same Actions and scopes; Idempotency-Key on POST.
- GDPR: erasing a person nulls `person_id` on their deals; an anonymised
  customer's deals get the title "Anonymiserad affär" and no loss note; a
  prospect's deals are deleted with it (foreign key cascade); the register
  extracts (customer and contact person) list the deals with their free
  text; pruning keeps prospects with an open deal and the contact person of
  an open deal while the deal has changed since the cutoff (a stale
  open deal keeps no one). `TODO(legal-verify)`: whether names mentioned
  only in a deal's title must be found by hand at erasure, as for
  activities.

### Stage 2: review round (2026-11-03)

- **Win-back never reaches someone who must not be contacted**
  (`WinBackBlock`): a deal's contact person or a private person's people
  marked Kontakta inte (objection or restriction), a customer with
  restricted processing or anonymised/inactive in the register, or an
  e-mail or phone on the suppression list. `LoseDeal` and
  `SetAccountStatus` then set no default date, `SetWinBack` and a given
  date in `LoseDeal` are refused with a message per case, and
  `crm:win-back` skips and clears the date under the row lock. It also
  skips (and clears) when the account has won a deal since the loss. A
  win-back date is today at the earliest and less than three years ahead.
- **The reminder is not contact**: the task does not touch
  `last_activity_at`, so it never keeps anyone from being pruned.
- **Anonymised customer**: its open deals are closed as lost without a
  reason, all win-back dates are cleared, and the account's open
  activities (already anonymised) are closed.
- **Pruning**: an open deal keeps its account and contact person only while
  the deal has changed since the cutoff.
- **Goals per seller** are personal data about employees: the manual tells
  the company to inform the sellers (art. 13) and not to use the figures as
  the only basis for decisions about one seller, and that a company with a
  collective agreement may have to negotiate first (11 § MBL).
  `TODO(legal-verify)`: whether introducing the goal follow-up triggers the
  duty to negotiate under 11 § MBL, and when.
- **Marketing to private persons**: the manual reminds of 19 §
  marknadsföringslagen and NIX for win-back contact with private persons.
- **Engineering**: Flytta on the board is a select plus a button (WCAG
  3.2.2), the phone's stage switcher is plain buttons with `aria-pressed`,
  four columns fit at 1280 px; a deal's value is at most
  10 000 000 000 000 öre; `WinDeal` locks the account, `CompleteActivity`
  the deal and `ArchivePipeline`/`SaveDeal`/`MoveDeal` the pipeline; a
  contact person marked Kontakta inte stays on the deal but its steps are
  planned without them, with a notice on the card; no deal is created or
  changed for a customer with restricted processing or that is inactive;
  a private person's linked quote, order and invoice are hidden from a
  seller without `invoices:view`, on the card and in the API.

### Deviations from the brief, with reasons

- **URLs.** The CRM already lives under `/kunder`, so stage 2 uses
  `/kunder/oversikt`, `/kunder/affarer`, `/kunder/affarer/{id}` and
  `/kunder/prognos` rather than `/crm/...`; the CRM section in the sidebar
  now starts on Översikt.
- **Forecast and goals share one page** (Prognos och mål); the dashboard
  shows the month's goals as well.
- **Removing a stage** needs a target stage for closed deals too:
  `stage_id` is not nullable, so a won or lost deal keeps a real stage for
  its history.
- **People on open deals are not pruned**, besides the accounts the brief
  named: the business with them is still going on.
- **No "reopen" for a closed deal.** A mistake is corrected with a new deal;
  the history stays as it was.

### Account card and person page (2026-11-03)

- **One design for both.** The account card (`/kunder/{account}`) and the
  new person page (`/kunder/personer/{person}`, `crm.people.show`, linked
  from Personer, the account card, the deal card and the timeline) share
  Blade components under `app/Modules/Crm/views/components` (`x-crm::…`):
  header with initials, quick actions and facts strip; a stepper (an ordered
  list with `aria-current="step"`) for the account's status path or an open
  deal's pipeline (`StepPath`); real tabs with counts (WAI-ARIA, arrow keys,
  one tabpanel); activity cards with a type icon and colour; deal list; key
  figures band; a fixed action bar on the phone with safe-area insets.
  Logging and completing activities is one trait (`WorksActivities`) on
  both pages, over LogActivity and CompleteActivity; the deal rule applies.
- **Timeline** filters by activity type or ledger documents, and gives a
  person's activities at any account; `Timeline::counts()` gives every tab's
  count in one query. Both pages render with a fixed number of queries
  (tested).
- **Person page rules.** Kontakta inte: a banner, and Ring, Mejla, Logga
  samtal, Planera möte and Uppgift are disabled with the reason (notes stay
  after an objection). Under a restriction the page is read-only except
  completing what was planned (stage 1). A seller without `invoices:view`
  sees no key figures for a private person's company. Another company's
  person answers 404.
- **Light theme only.** The app has one theme (stage 1); the pages use its
  tokens and add no dark-mode styles.
- **Stage 1 test adjusted:** the account-level quick action is now labelled
  "Logga samtal", so the check that a person marked Kontakta inte has no
  call button looks for that person's own button instead of the text.

