# ADR-043: NONOX CRM

- Status: **Accepted for stage 1, implemented** (see Implementation); stages 2–6 are proposals, decided one at a time
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
   seller, dashboard.
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
