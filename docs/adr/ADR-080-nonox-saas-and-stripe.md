# ADR-080: NONOX SaaS and Stripe in NONOX Connect (återkommande intäkter)

Status: Proposed
Date: 2026-11-09
Relates to: ADR-006 (money and audit log), ADR-007 (vouchers), ADR-010 (VAT report, OSS),
ADR-011 (invoicing, avtalsfakturering), ADR-015 (camt.054), ADR-016 (supplier reverse charge),
ADR-017 (module framework), ADR-020 (naming other programs), ADR-025 (foreign currency),
ADR-032 and ADR-064 (NONOX API and NONOX Connect), ADR-034 (open banking), ADR-042 (till
integrations), ADR-043 and ADR-046 (CRM and avtalsregister), ADR-076 (external references),
AGENTS.md §1.1–§1.4, §6, §10, §11.4.
Rule sheet: `docs/specs/rules/saas.md` (rules SA-R0 … SA-R47, questions L0–L8).

## Context

The owner ordered the module on 2026-11-09. The roadmap item is `nonox-saas` ("NONOX SaaS:
MRR, ARR och churn", Planerat, area Fakturering): a module for companies with recurring
revenue — SaaS, subscriptions, service agreements — showing monthly and annual recurring revenue
from agreements and avtalsfakturering, how it moves through new customers, expansion,
contraction and lost customers, churn, retained revenue and cohorts; computed from NONOX's own
agreements and invoices and, with Stripe connected through NONOX Connect, from Stripe's
subscriptions.

**Clean room.** A competitor launched something similar shortly before this ADR. Nothing of it
was opened or read, and nothing here refers to it. The metric definitions are argued from first
principles in the rule sheet (SA-R0–R22); the screens below are designed from what the
definitions need. Stripe's own analytics pages were also left unread; only Stripe's API
reference was read, as a data source.

What NONOX already holds (mapped in the rule sheet):

- **Avtalsfakturering** (`RecurringInvoice`, rows with discount, VAT rate, account; interval
  1/3/6/12 months; status ACTIVE/PAUSED/ENDED; `end_date`). Changes **overwrite** the agreement:
  there is no effective-dated history, and a pause has no "from" date.
- **CRM avtalsregister** (`Crm\Models\Agreement`, kind `SUBSCRIPTION` and others, value per
  MONTH/QUARTER/YEAR/TOTAL, `terminated_on`, `recurring_invoice_id`).
- **Invoices and credit invoices** with currency, rate, `recurring_invoice_id`, foreign-sale
  and OSS classification. Invoice rows carry **no service period**.
- **Periodiseringar** (`Accrual`), Riksbanken rates, camt.054 and open-banking matching.

Two things are missing for honest recurring-revenue figures: a **history of contract changes
with effective dates**, and a **frozen monthly result** that later changes cannot silently
rewrite. Both are decided below.

## Decision

### 1. Module `saas` ("NONOX SaaS")

A new optional module (ADR-017) with key `saas`, user-facing name **NONOX SaaS**, offered to
every legal form. It depends on nothing; its Stripe part needs the NONOX Connect add-on switch
(§7.8) and uses Banking (ADR-034) for payout matching when that module is on.

Permissions, existing only while the module is on:

| Permission | Owner | Admin | Accountant | Bookkeeper | Approver | Auditor |
|---|---|---|---|---|---|---|
| `saas:view` — every metric page, drill-down, CSV | ✓ | ✓ | ✓ | ✓ | – | ✓ |
| `saas:manage` — settings, close a month, classify credits, link Stripe customers | ✓ | ✓ | ✓ | – | – | – |

Connecting or disconnecting Stripe needs `settings:manage` plus `saas:manage`. Creating Stripe
draft vouchers needs `vouchers:create`; posting stays `vouchers:post`. Periodisation proposals
need `vouchers:create`. No new core permission.

User-facing URLs (AGENTS.md §11.4), route names English (`saas.*`):

| Path | Screen |
|---|---|
| `/aterkommande-intakter` | Översikt |
| `/aterkommande-intakter/rorelser` | Rörelser per månad |
| `/aterkommande-intakter/kunder`, `/kunder/{customer}` | Kunder and one customer's history |
| `/aterkommande-intakter/kohorter` | Kohorter |
| `/aterkommande-intakter/uppsagda` | Uppsagd MRR (scheduled churn) |
| `/aterkommande-intakter/rattelser` | Rättelser av stängda månader |
| `/aterkommande-intakter/manader` | Månader and closing |
| `/aterkommande-intakter/intakter` | Intäkter i perioden and periodisering |
| `/aterkommande-intakter/kallor` | Datakvalitet: what could not be counted, and why |
| `/aterkommande-intakter/stripe` | Stripe connection, sync log, customer matching, fee accounts |
| `/aterkommande-intakter/stripe/utbetalningar` | Stripe payouts and bank reconciliation |
| `/aterkommande-intakter/installningar` | Settings |

The Stripe webhook endpoint is technical and never seen by users: `POST /webhooks/stripe/{token}`.

### 2. Metric definitions

The definitions are fixed in the rule sheet, section A, and are the same for every company.
In short:

- **MRR** (SA-R3–R13): contracted recurring amount, excluding VAT, after recurring discounts, at
  the **last instant of the month**, normalised per revenue line (quarterly ÷ 3, annual ÷ 12, any
  *n*-month interval ÷ *n*; Stripe intervals likewise), integer öre half up per line. Trials 0;
  one-off fees and unlinked invoices never; usage-based lines outside MRR by default (O3);
  paused contracts 0; overdue contracts stay in until the contract ends. Foreign lines in SEK at
  Riksbanken's month-end mid rate, with the currency effect as its own movement. **ARR = MRR ×
  12**, labelled "årstakt".
- **Movements** (SA-R14): per customer and month, exactly one of Ny, Återaktivering,
  Återupptagen, Utökning, Minskning, Paus, Churn, plus Valutaeffekt and Rättelse. The bridge
  MRR(m) = MRR(m−1) + movements holds to the öre and is tested for every fixture month.
- **Churn timing** (SA-R15): churn is dated to the first month-end without MRR, that is, the end
  of the last paid or bound period. A notice is **Uppsagd MRR** until then.
- **Reactivation** (SA-R16): a returning customer is never "Ny" and keeps its first cohort.
- **Rates** (SA-R17–R19c): logo churn, gross and net revenue churn on opening MRR; NRR and GRR
  over 12 months at constant currency; cohorts by first MRR month; ARPA per customer (not per
  contract); LTV only with a margin the company enters (otherwise labelled "intäkts-LTV"); CAC
  only from cost accounts or cost centres the company chooses.
- **Credit invoices** (SA-R11) move MRR only when the contract changes; other credits reduce
  recognised revenue, and an unclassifiable imported credit waits under **Att granska**.

Every page states that the figures are management metrics, not bookkeeping (SA-R0).

### 3. Data model: a recurring-revenue ledger

All tables use `BelongsToCompany`, carry `company_id`, and get tenancy tests (another company's
id → 404). Money is integer minor units with a currency, plus SEK öre where converted.

**3.1 `saas_contract_versions` — append-only, the single input.** One row per known state of a
contract from a given date. PostgreSQL trigger rejects `UPDATE`, `DELETE` and `TRUNCATE`.

- `source_type` (`RECURRING_INVOICE`, `CRM_AGREEMENT`, `STRIPE_SUBSCRIPTION`), `source_id`,
  `customer_id` (nullable until matched), `effective_from` (date), `recorded_at` (timestamp),
  `recorded_by` (nullable for imports), `cause` (`CREATED`, `CHANGED`, `PAUSED`, `RESUMED`,
  `CANCEL_SCHEDULED`, `ENDED`, `BACKFILL`, `IMPORT`), `cancel_effective_on` (nullable).
- `lines` jsonb: per revenue line a stable `line_key`, description, currency, `period_months`
  (or Stripe interval and count), amount in minor units excl. VAT, discount and its end date,
  `usage` flag, trial end.
- `source_ref` (agreement version, Stripe subscription id and event id), `sha256` of the
  canonical row.

Writers:

- **NONOX agreements.** `SaveRecurringInvoice`, `RunRecurringInvoice`'s status change, and the
  CRM `SaveAgreement` dispatch domain events (`RecurringInvoiceChanged`,
  `RecurringInvoiceStatusChanged`, `CrmAgreementChanged`) — a small core change, events only.
  The module's listener writes a version only while the module is on. The editor gains an
  optional **"Gäller från"** date for a price change, a pause and an end (default today), shown
  only when the module is on; nothing else in avtalsfakturering changes.
- A CRM agreement linked to a recurring invoice is **not** counted (the recurring invoice
  wins), so nothing is counted twice. A CRM agreement with `value_period = TOTAL` counts only
  when it has both start and end date (total ÷ months); otherwise it is listed under Källor.
- **Turning the module on** writes one `BACKFILL` version per active or paused agreement,
  effective from the agreement's creation date, from its current state. History before that is
  unknown and the pages say from which date figures are complete (O9 asks whether to
  reconstruct older history from issued invoices).
- **Stripe** (§7): one version per change of a subscription as fetched.

**3.2 `saas_months`.** One row per company and month: `status` (`OPEN`, `CLOSED`),
`closed_at`, `closed_by`, the rates used per currency and their date, the totals, and
`versions_known_until` (the highest `recorded_at` the closed result used). A trigger makes a
`CLOSED` row immutable.

**3.3 `saas_customer_months`.** Per company, month and customer: MRR in SEK öre, the lines
behind it (each with `source_type`, `source_id`, version id, currency amount, rate), the
movement category and amount, the cohort month. Immutable once its month is `CLOSED`.

**3.4 `saas_corrections` — append-only.** A change that becomes known after month *M* is closed
and is effective in *M* or earlier is written here: `booked_month` (the first open month),
`affects_month`, customer, amount in SEK öre, the causing version. The movement category
**Rättelse** in the booked month is the sum of these rows (SA-R22).

**3.5 Settings** (`company_modules.settings`): close day, gross margin (no default), CAC cost
accounts and cost centres (no default), usage handling, the periodisation account (default 2979,
SA-R27), whether recurring invoices bill in advance or in arrears (default in advance), fee
accounts and VAT treatments for Stripe (§7.6).

### 4. Computation: reproducible and explainable

- **One pure domain service**, `App\Modules\Saas\Domain\RecurringRevenue`, takes the versions
  recorded up to an instant and a month, and returns per-customer lines, MRR and movements. It
  does no I/O and has no clock: the same inputs always give the same output. It is unit-tested
  with the rule sheet's fixtures F1–F8 and property tests (random version histories: the bridge
  identity always holds; a customer's MRR always equals the sum of its lines; reordering versions
  with equal content changes nothing).
- **Open month.** Recomputed by a queued job after every new version and nightly
  (`saas:recompute`, carrying `company_id`, `CurrentCompany::run`). Pages read only the stored
  rows: a fixed number of queries per page whatever the number of customers, proven by
  query-count tests with 5 and 500 customers.
- **Closing.** `saas:close-months` closes month *M* on the configured day of month *M+1*
  (default the 10th, when Stripe's fee invoices are also out, ST-TAX) or earlier by a person
  with `saas:manage` ("Stäng månad", with a dialog stating that later changes will appear as
  rättelser). Closing stores `versions_known_until` and the rates.
- **Proof of reproducibility.** `saas:verify {company}` recomputes every closed month from the
  versions recorded up to its `versions_known_until` and compares to the stored rows; any
  difference is a P1 bug, shown on Månader like `ledger:verify`.
- **Back-dated changes.** A new version effective in a closed month never touches that month.
  The next recompute writes `saas_corrections` for each affected closed month (the difference
  between the result with and without the new knowledge) into the first open month. Översikt has
  a switch **"Som rapporterat / Med rättelser"**: the second shows each closed month as it would
  be today, marked as such, without changing the stored rows.
- **Credits and contract edits** reach the ledger only as versions (a contract change) or as
  recognised-revenue adjustments (§5); a credit invoice alone never edits a version.
- **Drill-down everywhere** (SA-R21): every figure opens the customers and the documents
  (agreement version, invoice, credit invoice, Stripe ids) behind it, summing to the öre; every
  list downloads as CSV with the same columns.

**Screens** (own design system, tabular numerals, Swedish text):

1. **Översikt**: MRR, ARR, kunder, ARPA, kundchurn and brutto intäktschurn for the month, NRR
   and GRR (12 mån); a bridge from last month's MRR to this month's by category; 24 months of MRR;
   the "som rapporterat / med rättelser" switch; a banner when Källor has open items.
2. **Rörelser**: months as rows, categories as columns; each cell opens its customers.
3. **Kunder**: MRR, change, cohort, source, status; one customer's page shows MRR over time,
   every contract version with effective and recorded dates, and the documents.
4. **Kohorter**: customer retention and revenue retention (constant currency) per cohort.
5. **Uppsagda**: notices with their effective month and the MRR that will go.
6. **Rättelser**: per booked month, the corrections and the month each affects.
7. **Månader**: open/closed, closed by whom and when, verification result.
8. **Intäkter i perioden**: recognised recurring revenue next to MRR with a reconciliation
   (one-off fees, credits, part months, usage), and periodisation proposals (§5).
9. **Källor**: agreements without customer, CRM agreements that cannot be counted, Stripe
   customers not matched, credits to classify, currencies without a rate.

### 5. Revenue recognition (periodisering) from the same data

- Each invoice row of a recurring contract gets a service period: for Stripe, the line's
  `period` (ST-INV); for avtalsfakturering, the billing period implied by the agreement's interval
  and the in-advance/in-arrears setting.
- At month end and at year end the module proposes **draft** accrual vouchers (the existing
  `Accrual` feature) moving the unearned part to **2979 Övriga förutbetalda intäkter** (or the
  account chosen) and releasing it linearly (K2 6.6, 6.11, 6.26; K3 23.26; SA-R24–R27). VAT is
  not touched (SA-R28).
- The year-end view lists advances under 7 000 kr per invoice or contract and their total,
  with K2 2.4B's subscription example quoted, so the company makes the materiality judgement;
  NONOX never applies the 7 000-kr simplification by itself (SA-R25, L5).
- `docs/systemdokumentation/README.md` gets a section on these proposals in the step that
  builds them.

### 6. VAT per customer type

The module classifies each customer and each Stripe invoice by NONOX's own rules and books
accordingly; Stripe's tax result is compared, never trusted (SA-R38):

| Customer | Rule | Account / boxes | Rule sheet |
|---|---|---|---|
| Sweden, business or consumer | Swedish VAT | 3001 etc., 2611/2621/2631, boxes 05, 10–12 | SA-R34 |
| EU business with a valid VAT number | No Swedish VAT, buyer accounts for it | 3308, box 39, periodisk sammanställning (`ForeignSale::EuServices`) | SA-R35 |
| EU consumer, at or under 99 680 kr (this and last year) | Swedish VAT | as Sweden, `swedish_vat_under_threshold` | SA-R36 |
| EU consumer, above the threshold or opted | Buyer country's VAT through OSS | existing OSS flow (`OssThreshold`, `OssReport`); account per L4 | SA-R36 |
| Business outside the EU | No Swedish VAT | 3305, box 40 (`ForeignSale::ExportServices`) | SA-R37 |
| Consumer outside the EU | Swedish VAT with a warning until L3 is answered | as Sweden | SA-R37 |

Whether the product is an "elektronisk tjänst" (ML 6 kap. 57 §) is asked once per Stripe
product or NONOX article.

### 7. Stripe in NONOX Connect

**7.1 Connection: a restricted read-only key the customer pastes in (v1).**

The company creates a **restricted API key** (`rk_live_…`) in its own Stripe account with
**Read** on the resources NONOX needs and **None** on everything else, and pastes it into
NONOX. The Swedish guide lists the resources and links Stripe's own page on restricted keys.

Read: Customers (with tax ids), Products and Prices, Subscriptions, Invoices, Credit notes,
Charges, Refunds, Disputes, Balance (balance transactions), Payouts, Events. None: everything
else, in particular payment methods, Checkout, Connect and every write.
`TODO(verify)`: the exact labels in Stripe's key form, by creating a test key in a sandbox
before the guide is written.

On connect, NONOX:

- refuses keys that start with `sk_` or `pk_` (a full secret key gives NONOX write access the
  company did not mean to grant; ST-RAK calls sharing it "Dangerous"), and refuses `rk_test_`
  outside local and test environments;
- makes one read call per resource and refuses the key if any needed read fails, naming the
  missing permission in Swedish;
- stores the account id (`acct_…`) and the last four characters of the key; the key itself is
  stored with the `encrypted` cast, hidden from serialisation, never logged, never shown again.

**Why not Stripe Connect OAuth** (SA-R44):

| | Restricted key (chosen for v1) | Connect OAuth |
|---|---|---|
| Read-only | Yes: Read/None per resource, chosen by the company | `read_only` is only for Stripe "Extensions"; platforms get `read_write` |
| Who holds the power | One key per company, readable only for that account | NONOX's platform secret key with `Stripe-Account` reaches every connected account (the token response's `access_token` is deprecated) |
| Revocation | The company expires the key in Stripe, or disconnects in NONOX | `oauth/deauthorize`, or the company removes the platform |
| Self-hosters | Work without any registration with Stripe | Every instance would need its own Connect registration |
| Agreement with Stripe | None needed | NONOX must register as a Connect platform or extension |
| User experience | Worse: the user creates and pastes a key | Better: one click |
| Lifecycle signals | None; NONOX notices a revoked key on the next 401 | Deauthorisation events |

The UX gap is closed later by a **Stripe App with RAK authentication** (SA-R43): the app declares
exactly the read permissions above, the company installs it and copies the generated key into
NONOX. The backend is the same, so this is a packaging step, not a redesign; it needs a Stripe
App registration by the hosted service's operator (owner question O5).

**7.2 What is imported.**

| For | Objects |
|---|---|
| Metrics (step 2) | Customers, Products, Prices, Subscriptions (with items, discounts, trial and cancel fields), Events |
| Bookkeeping (step 3) | Invoices (lines with periods, taxes, customer tax data, PDF), Credit notes, Charges, Refunds, Disputes, Balance transactions, Payouts, Stripe's monthly fee invoice (uploaded by the user; it is not in the API) |

Every fetched object is stored raw in `stripe_objects` (connection, type, Stripe id, payload
jsonb, SHA-256, fetched at), append-only, a new row only when the payload's hash changes, so the
document NONOX booked from is kept as received (BFL 7 kap. 1 §, SA-R41).

**7.3 Idempotent sync.**

- Jobs per connection (`WithoutOverlapping`), carrying `company_id`, `CurrentCompany::run`.
- **Initial import**: all objects of the needed types, paginated with `limit=100` and
  `starting_after` (ST-PAG).
- **Incremental**: hourly and nightly, the Events list since the last processed event, with a
  three-day overlap; every referenced object is re-fetched and upserted by Stripe id + hash.
- **Limits** (ST-RL): a client-side token bucket of at most 20 requests per second and 5
  concurrent requests per connection, below Stripe's 25 per endpoint and 100 global; 429 and
  lock timeouts retried with exponential backoff and jitter; Search is not used. The read
  allocation (500 per transaction, at least 10 000 a month) is watched and logged.
- **Once only**: subscription changes become `saas_contract_versions` keyed by subscription id +
  payload hash; balance transactions, payouts and invoices are unique per connection and Stripe
  id; a draft voucher records the Stripe ids it contains and a unique index stops a second one.

**7.4 Webhooks (optional, for speed).** The company may add an event destination in Stripe
pointing to `POST /webhooks/stripe/{token}` (an opaque 48-character token per connection, as for
the tills) and paste its `whsec_` secret (encrypted, like the key). NONOX:

- finds the connection by token, then verifies `Stripe-Signature`: only `v1`, HMAC-SHA256 over
  `"{t}.{raw body}"`, constant-time comparison, tolerance 300 seconds, several `v1` signatures
  accepted during Stripe's secret roll (SA-R46);
- answers 2xx at once and queues the work; dedupes on event id (`stripe_webhook_events`,
  unique per connection);
- **treats the payload as a hint only**: it re-fetches the object through the API with the
  company's key before anything is recorded, so a forged or replayed event can at most cause a
  harmless re-fetch, and event order does not matter;
- is rate-limited per token and returns 404 for unknown tokens.

Without a webhook, polling alone keeps figures at most an hour old.

**7.5 Matching Stripe customers to NONOX customers without duplicates.**

`stripe_customer_links` (connection, Stripe customer id → `customer_id`, method, confirmed by and
when), unique per connection and Stripe customer. Several Stripe customers may link to one NONOX
customer; one Stripe customer links to exactly one.

1. Stripe `metadata.nonox_customer_number` (documented in the guide), if it names an existing
   customer of this company.
2. A VAT number or organisation number in the Stripe customer's tax ids equal to a customer's
   `vat_number` / `org_number` (normalised; Luhn-checked for Swedish numbers).
3. An exact e-mail match with exactly one customer.
4. Otherwise a **suggestion** by name and country, never applied automatically.

Rules 1–2 link automatically and are listed for review; 3–4 wait for a person on
`/aterkommande-intakter/stripe`. Creating a NONOX customer from a Stripe customer is an explicit
action ("Skapa kund"), offered only after the candidates have been shown, and it records the
link. Unlinked Stripe customers count in the metrics as their own provisional customers and
are listed under Källor until linked; linking later moves their history to the NONOX customer
as **Rättelse** for closed months.

**7.6 Bookkeeping: payouts, fees, clearing account.**

- **Series**: a new on-demand voucher series **S "Stripe"**, created only when a Stripe
  connection is made (as series K in ADR-042), never for every company.
- **Clearing account**: per connection, default **1686 Fordringar för kontokort och kuponger**
  (BAS 2026; SA-R29, L6).
- **Daily drafts** (`saas:stripe-drafts`, 05:30): one draft per day and connection with charges
  and payments (debit 1686; credit 1510 for an invoice already booked, or revenue and VAT per
  §6 when the sale is booked from Stripe), refunds, dispute withdrawals and reinstatements,
  each fee type on its mapped account and VAT treatment, and currency differences on 3960/7960
  (ADR-025). Never posted by NONOX.
- **Fees** (SA-R30–R31): every fee type (`stripe_fee`, `stripe_fx_fee`, `tax_fee`, dispute fees)
  must have an account (default 6570) **and** a VAT treatment chosen by the company —
  `OMVAND_SKATTSKYLDIGHET` (4535 / 2614 / 2645, boxes 21, 30, 48, as ADR-016) or `UNDANTAGEN` —
  before the first draft that contains it. No silent default until L1 is answered.
- **Payouts**: each automatic payout's balance transactions are fetched with `payout=po_…`
  (ST-PO); the sum of `net` must equal the payout to the öre, otherwise nothing is booked and the
  difference is shown. Manual payouts cannot be itemised by Stripe and are shown for a person to
  allocate.
- **Bank reconciliation**: the payout voucher (debit bank, credit 1686) is proposed only when a
  bank transaction is matched: amount and currency equal, booking date within three banking days
  of `arrival_date`, from a camt.054 file (ADR-015) or an open-banking feed (ADR-034). The
  Banking module's `MatchBankTransactions` gains a "Stripe-utbetalning" candidate. The clearing
  account's balance per connection is shown next to Stripe's not-yet-paid-out balance.
- **Sales from Stripe invoices**: per day as above, or per invoice (O8); whether a Stripe
  invoice is the company's own ML 17 kap. invoice and whether NONOX should import it as an
  issued invoice with `external_ref` (as ADR-076) is L2. Until L2 is answered, step 3b books only
  fees and payouts and leaves sales to the company's existing invoicing.
- **Underlag** (SA-R39–R41): each voucher carries the raw objects it was built from and the
  Stripe invoice PDFs, hashed, inside the archive package; the fee voucher links the uploaded
  monthly fee invoice or says it is missing.

**7.7 Secrets.** Encrypted at rest with the application key, hidden, scoped to one connection of
one company; outbound calls only to `api.stripe.com`; **disconnect** erases the key and the
webhook secret immediately, marks the connection `REVOKED`, keeps imported data subject to §8,
and tells the user to expire the key in Stripe; a 401 from Stripe marks the connection
`REVOKED` and notifies members with `saas:manage`; **key rotation** replaces the key after the
same verification as on connect. A Stripe account can be connected to one company at a time
(partial unique index on the account id among active connections). Audit entries (ids, field
names and enums only): `saas.module_enabled`, `saas.month_closed`, `saas.settings_updated`,
`saas.credit_classified`, `stripe.connected`, `stripe.key_rotated`, `stripe.disconnected`,
`stripe.revoked_by_provider`, `stripe.customer_linked`, `stripe.customer_unlinked`,
`stripe.draft_created`.

**7.8 Pricing under the Connect rule.** ADR-064 §3: the provider's price to NONOX plus 10 %,
shown as two lines. Stripe charges NONOX nothing for API access (no fee for keys or calls
appears in ST-KEY or ST-RL; the price page itself was not read). The company pays Stripe its own
transaction fees directly, and NONOX never touches the money. The rule therefore gives
**0 kr + 10 % = 0 kr**. Proposal: the Stripe connection is shown under NONOX Connect with the
two lines "Leverantörens pris: 0 kr" and "Påslag 10 %: 0 kr", so every external connection lives
in one place and the pricing rule stays honest; the module itself is included like every module.
Charging a fee for NONOX's own running cost would break the cost-plus rule and needs an owner
decision (**O1**).

### 8. Tenancy, GDPR, security

- **Tenancy**: every table is company-scoped with a tenancy test; webhook requests resolve the
  company from the connection token before anything else; jobs carry `company_id`; the partial
  unique index keeps one Stripe account in one company; an accounting firm connects per client
  company, never a firm-wide key.
- **GDPR**: Stripe customers who are natural persons (consumers, sole traders) are personal
  data. Purposes: bookkeeping underlag (legal obligation, art. 6.1 c, BFL) and the company's own
  revenue analysis (legitimate interest, art. 6.1 f, with the balancing test in
  `docs/gdpr/registerforteckning.md`). Minimisation: NONOX never asks for payment-method or
  card permissions; of a Stripe customer it keeps id, name, e-mail, country, address and tax ids
  as they appear on invoices. Raw objects not used as underlag are deleted 90 days after a
  disconnect or after the customer's erasure; underlag is kept for the BFL retention period and
  then pruned by `privacy:prune`. `EraseRegisterEntry` covers Stripe links and objects with the
  same BFL exception as for invoices. For the hosted service NONOX processes on the company's
  instruction (DPA); Stripe is the company's own supplier, not a NONOX sub-processor. Documents
  to update: `registerforteckning.md`, `lagringstider.md`, the privacy policy (one version bump).
- **Security**: as §7.4 and §7.7; the key and secret never appear in logs, exceptions, the
  audit log or API responses (a test greps the log output); the webhook route is excluded from
  CSRF only, is throttled, and does nothing before the signature check; outbound HTTP has a
  timeout and no redirects to other hosts.
- **Public pages (ADR-020)**: the landing feature card uses a real NONOX screenshot; a row in
  `docs/migration/jamforelse.json` with neutral keys and competitor values only from each
  vendor's own pages, else `unknown`. No competitor's UI, texts or screenshots are used, and the
  competitor that launched a similar feature is not described from anything but its own public
  pages, if at all.

### 9. Out of scope

- Writing to Stripe: creating customers, prices, subscriptions, invoices or refunds.
- Collecting payments or moving money; NONOX as a Stripe Connect platform for marketplaces.
- Other billing providers (the version table is source-agnostic so a later ADR can add one).
- Forecasts, budgets, scenario planning, IFRS 15.
- Filing anything with Skatteverket from Stripe data beyond what the VAT report and OSS already
  do.
- Stripe Tax registrations, Stripe's own revenue-recognition product, Stripe Sigma or Data
  Pipeline.
- Usage-based MRR beyond the separate "Rörlig återkommande intäkt" line.

## Build steps (tests first in each)

1. **Ledger and metrics from NONOX data.** Domain events in the three core actions; the
   "Gäller från" field; `saas_contract_versions` with its trigger; backfill on enable;
   `RecurringRevenue` with fixtures F1–F5, F7 and property tests; `saas_months`,
   `saas_customer_months`, `saas_corrections` with their triggers; recompute, close and verify
   commands; screens 1–7 and 9; CSV. Tests first: the bridge identity, churn at period end not at
   notice, reactivation not new, a back-dated change producing a correction and leaving the closed
   month untouched, `saas:verify` reproducing every closed month, credit invoices not moving MRR,
   tenancy, query counts with 5 and 500 customers, module off → 404.
2. **Stripe read-only for metrics.** `stripe_connections`, the restricted-key flow and its
   refusals, initial and incremental sync of customers, products, prices, subscriptions and
   events; `stripe_objects`; matching (§7.5); webhooks (§7.4). Tests first: `sk_`/`pk_` keys
   refused; a missing read permission named; signature with wrong secret, old timestamp, `v0`
   only, and altered body all refused; duplicate event processed once; out-of-order events give
   the same versions; a 429 retried; a 401 revokes; one Stripe account cannot be connected to two
   companies; no key in logs; an unlinked customer counted provisionally and moved by a later link
   as Rättelse. All against a fake Stripe HTTP client with recorded synthetic fixtures; no test
   calls Stripe.
3. **Stripe bookkeeping.** 3a: balance transactions, payouts, fee mappings, series S, daily and
   payout drafts, clearing-account reconciliation, bank matching (fixture F6). 3b, after L1, L2,
   L6 and L7 are answered: invoices, credit notes and VAT per §6, with Stripe's tax compared.
   Tests first: a payout that does not sum is never booked; a fee type without a VAT treatment
   blocks the draft; no draft twice for the same Stripe ids; reverse-charge fee rows land on
   4535/2614/2645; immutability of posted vouchers unchanged.
4. **Periodisering.** Service periods, accrual proposals to 2979, the year-end 7 000-kr view
   (fixture F8), the systemdokumentation section. Tests first: no VAT row in a periodisation;
   proposals never posted automatically.
5. **API and docs.** Read endpoints for months, movements and customers in `/api/v1` with
   OpenAPI; manual articles with `rutter`; changelog; landing card with a real screenshot;
   `Features.php`; comparison row; README; GDPR documents; the Stripe guide.
6. **Later:** the Stripe App with RAK authentication (O5).

Each step: builder with tests first, cold reviewer (correctness, security, tenancy, a11y, and a
lawyer pass on GDPR and VAT), Lead merges. Step 3b waits for the L-answers it names.

## Consequences

- Avtalsfakturering and the CRM gain domain events and a "Gäller från" date; their behaviour is
  otherwise unchanged and their tests must pass untouched.
- Figures are only as old as the module: history before it was turned on is not invented, and
  the pages say from when figures are complete.
- Closed months never change; the price is a second number ("med rättelser") that users must
  understand, which the manual explains with fixture F5.
- NONOX holds, for the first time, a read key to a payment provider account per company; the
  key is read-only by construction, but a leak would still expose customer and revenue data, so
  the key handling in §7.7 is a review focus.
- BAS 2026 findings outside this ADR are raised, not fixed: the OSS account (2619 vs 2670, L4)
  and ADR-042's 1580 (L6).

## Owner questions

- **O1** Stripe pricing: show it under NONOX Connect at "0 kr + 0 kr" (the cost-plus rule), or
  charge a fee for NONOX's own cost (which breaks the rule), or keep it entirely inside the module?
- **O2** Name and placement: "NONOX SaaS" as the module name with Swedish pages under
  `/aterkommande-intakter`; or a Swedish module name ("Återkommande intäkter")?
- **O3** Usage-based (metered) revenue: outside MRR with its own line (proposed), or inside?
- **O4** Pauses: MRR 0 with "Paus"/"Återupptagen" and re-classified as churn after 12 months
  (proposed), or another period, or churn at once?
- **O5** A Stripe App listing for one-click key generation: wanted, and is AI Reports Sweden AB
  the party that registers it with Stripe, as for the other external agreements?
- **O6** Closing: automatically on the 10th of the following month (proposed), another day, or
  only by a person?
- **O7** LTV and CAC in v1 (needs a margin and cost-account choices per company), or later?
- **O8** Stripe sales in the books: one draft per day (proposed) or one voucher per Stripe
  invoice; and, depending on L2, should Stripe invoices become NONOX invoices with
  `external_ref`?
- **O9** History before the module is on: start from the enable date (proposed), or reconstruct
  earlier months from issued recurring invoices, marked as estimated?
- **O10** Offer the module to every company (proposed), or only when the company says it sells
  subscriptions during setup?

## Legal questions (from the rule sheet)

L0 re-read quotes; **L1** VAT on Stripe's fees (exempt payment service or reverse charge);
**L2** Stripe-issued invoices as the company's ML 17 kap. invoices and their numbering;
**L3** SaaS to non-EU consumers, and SaaS as an electronic service; **L4** OSS account 2670 vs
2619; **L5** K2 2.4–2.4B and K3 23.7/23.26 for subscriptions; **L6** clearing account 1686 (and
ADR-042's 1580); **L7** daily summary verifikationer for online card payments; **L8** archive
location under BFL 7 kap. 3 a §. Every rule depending on them is marked `TODO(legal-verify)` in
the rule sheet.

## Addendum 2026-11-09: Lead decisions on the owner questions

Decided by Lead 2026-11-09 under the owner's mandate ("Kör allt du behöver"), open for the
owner's and the consultant's review.

- **O1:** Stripe under NONOX Connect at provider price + 10 % = 0 kr, shown as such.
- **O2:** the module is called "NONOX SaaS" in the module list. Its pages live under
  `/aterkommande-intakter` with the title "Återkommande intäkter".
- **O3:** metered revenue stays outside MRR, on its own line.
- **O4:** a pause counts as MRR 0 with the movement "Paus". A pause longer than 12 months
  becomes churn at the 12-month mark.
- **O5:** the Stripe App comes later, not in v1.
- **O6:** months are closed by a person. On the 10th of the next month a reminder appears in
  Mina datum. Nothing closes automatically.
- **O7:** LTV and CAC are left out of v1. ARPA and the cohorts are in.
- **O8:** Stripe bookkeeping waits for L1, L2, L6 and L7 (build step 3b). Payouts and fees
  (3a) are booked per payout.
- **O9:** history starts when the module is switched on. Earlier months can be estimated from
  issued recurring invoices, and they are labelled "uppskattad" and kept apart from closed
  months.
- **O10:** the module is offered to every company and is off by default.

Build order: step 1 (the ledger and metrics from NONOX's own data) starts now. Step 2 (the
Stripe read-only import for metrics) follows. Step 3 waits for the L-questions listed above.
