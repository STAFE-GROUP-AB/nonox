# ADR-082: Price lists and customer price agreements

- Status: **Accepted, implemented** (P1–P10 live 2026-11-22; owner 2026-11-20: "ja till alla" — all 17 recommended defaults, including the AGENTS.md §4 permission additions)
- Date: 2026-11-20
- Queue: item 28 in `docs/status/nummer-1.md` (`prislistor-kundavtal`)
- Builds on: ADR-006 (Money and audit log), ADR-011 (invoicing, quotes and
  orders), ADR-017 (module framework), ADR-025 (foreign currency), ADR-039
  (inventory), ADR-042 (till integrations), ADR-046 and its 2026-11-18
  amendment (signing, quote templates, agreement register), ADR-064 (NONOX API
  and Connect), ADR-071 §6, §9 and §12 (costing, customer ordering, units),
  ADR-074 (work orders), ADR-075 (customer portal), ADR-076 (moving from other
  programs), ADR-081 (ERP: per-package translation files, the principles)
- Format: follows ADR-081 (what exists, scope, data model, permissions,
  accounting impact, tenancy, legal items, decisions, packages)

No feature code ships with this ADR. It is built in the packages P1–P10 at the
end once the owner has answered the decisions.

## Context

The owner, 2026-11-19: "vi skall sedan bygga prislistor och kundavtal så vi får
in detta i NONOX fort och vi skall ju såklart ha den bästa och enklaste sättet
att arbeta med prislistor och kundavtal så vi blir ledande på detta också".

Today a price in NONOX is one number per article (`articles.price_ore`, kronor
excluding VAT) and a percentage discount typed by hand on each row
(`discount_bp`). There is no way to say "this customer pays 412 kr for this
article until 31 December", "ten or more cost 10 % less", "the summer campaign
runs in June", "prices go up 4 % on 1 January", or "this customer's EUR
prices". Everything a seller knows about a customer's terms lives in their head
or in a PDF in the agreement register.

"Best and simplest" is the brief. Simplest means a company that never opens a
price list sees nothing new except that the right price appears by itself.
Best means every price NONOX proposes can be explained on the row in one line,
is reproducible, never changes a sent document, and can be changed for a whole
assortment in one confident step with a preview.

The design is written from first principles and general knowledge of how B2B
and B2C pricing works (AGENTS.md §1.1). No competitor's screens, texts or
names are used.

## What exists (mapped 2026-11-20 on `origin/main` b4e7b130)

| Area | Where | What it does with prices today | Gap |
|---|---|---|---|
| Articles | `app/Models/Article.php`, `app/Actions/Invoicing/SaveArticle.php`, `app/Livewire/Invoicing/Articles.php` | `price_ore` (SEK excl. VAT, `MoneyCast`), `vat_rate`, `purchase_price_ore` (per stock unit), `sales_unit_id`/`stock_unit_id`/`purchase_unit_id` (ADR-071 §12.2) | One price, one currency, no history, no validity |
| Sales rows input | `app/Domain/Invoicing/SalesRowInput.php` | Parses rows typed on invoice, quote and order: the **price comes from the browser**; the article only supplies account, unit and dimension defaults | No server-side price resolution; nothing records why a price was chosen |
| Row maths | `app/Domain/Invoicing/InvoiceCalculator.php` | `rowNet = unit price × quantity × (10000 − discount_bp)`, öre, HalfUp; `discount()` parses percent | Fine as is; the resolver feeds it |
| Row editors | `app/Livewire/Invoicing/Concerns/EditsSalesRows.php` | Loads every active article with `price_ore` once; Alpine fills the row price on article pick | Price is the article's, regardless of customer, date, quantity or currency |
| Invoice rows | `app/Models/InvoiceRow.php`, trigger `invoice_rows_protect_issued` (`2026_10_29_220000_create_invoicing_tables.php`) | `unit_price_ore`, `discount_bp`, `foreign_unit_price_minor`, `unit_id`; rows frozen once issued by a PostgreSQL trigger | Frozen price already guaranteed for issued invoices; no explanation stored |
| Quotes and orders | `app/Models/SalesDocument.php` (QUOTE, ORDER), `SalesDocumentRow.php`, `SaveSalesDocument`, `ProgressSalesDocument` | Same row shape; quote → order → draft invoice copies rows | **No currency** on sales documents (SEK only); no re-pricing rule when a quote has expired |
| Recurring invoices (avtalsfakturering) | `app/Models/RecurringInvoice.php`, `RecurringInvoiceRow.php`, `RunRecurringInvoice`, `app/Livewire/Invoicing/Agreements.php`, route `/avtal` (`invoicing.agreements`) | Rows carry a fixed price that never changes | No way to let a yearly price increase reach running agreements |
| Foreign currency | ADR-025, `invoices.invoice_currency`, `exchange_rate_micros`, row `foreign_unit_price_minor`; `customers.currency` | Invoice typed in EUR with a rate; kronor computed | No price per currency; the seller converts by hand |
| CRM quote templates | `app/Modules/Crm/Models/QuoteTemplate*.php`, `CreateQuoteFromTemplate` (line ~96 takes `article->price_ore`) | Article rows take the article's price at creation | Ignores customer prices |
| CRM agreement register | `app/Modules/Crm/Models/Agreement.php` (`crm_agreements`), route `/kunder/avtal` | Validity, notice, auto-renewal, value, linked recurring invoice, signed document | Holds no price terms |
| Signing | `app/Domain/Signing/Signable.php` (core, works without CRM) | Quotes, agreements, ÄTA signable at a link | A price agreement could be signed the same way |
| Collective agreements | `app/Modules/Agreements` (key `agreements`, ADR-033), route `/lon/avtal` | Payroll only | **Name clash risk**: `Agreement`/`agreements` is taken three times already (see §Naming) |
| Costing | `app/Modules/Manufacturing/Support/Costing.php`, `DocumentCosting.php`, `ManufacturingBook.php` (ADR-071 §6) | Cost per unit, contribution and `margin_bp` on quote/order rows with a structure | Margin is shown only for rows with a bill of materials; no warning on a price below cost |
| Units | `app/Domain/Units/*` (`UnitRegister`, `UnitConverter`, `ArticleUnits`), `units` table (ADR-071 §12.1, the only source of units) | Conversions entered ↔ stock unit, BigRational, HalfUp once | A price per pall vs per piece is not modelled |
| Customer portal | `app/Modules/Portal/*` (ADR-075), `views/portal/quote.blade.php` | Shows sent quotes with their row prices; ordering (§11) not built | Ordering from the assortment needs "the customer's prices" (ADR-071 §9) |
| Customer ordering | ADR-071 §9 (planned; `customer-orders:review` not in code yet) | — | Depends on this ADR |
| Tills | `app/Modules/Pos/*` (ADR-042), `Support/ArticlePayload::grossOre()` | Pushes `price_ore + VAT` to the till | No till-specific price, no campaign to the till |
| Work orders | `app/Modules/WorkOrders/Models/MaterialEntry.php`, `WorkOrder.php` | Material and hourly prices typed or from the article | Ignores customer prices |
| Labels | `app/Modules/Inventory/Labels/LabelData.php` | Prints `price_ore + VAT` | A shelf label should show the price of the till list when one exists |
| API | `app/Modules/Connect/routes/api.php`, `Http/Controllers/Api/V1/ArticleController.php`, `Webhooks/WebhookEvents.php` | Articles with `price` read/write under `invoices:view`/`invoices:create`, Idempotency-Key on POST | No price list or agreement resources |
| Moving in | `app/Migration/Import/RegisterKind.php` (customers, suppliers, articles, open items, attachments), `app/Migration/Fortnox` | Articles with one price | No price list import; the source programs' price lists are lost on moving |
| Comparison | `docs/migration/jamforelse.json` row "Prislistor och kundavtal" | `nonox: "no"` | To flip to `yes` at launch (ADR-020 rules) |
| Demo data | `database/seeders/DemoCompanySeeder.php`, `IndustryDemoSeeder.php`, `Demo/Extras/*` | Articles with one price | No price lists for screenshots |
| Customers | `app/Models/Customer.php` | `currency`, `payment_terms_days`, default dimensions | No price list or price group |
| Permissions | `app/Policies/SalesRegisterPolicy.php`, `ArticlePolicy.php` | Articles under `invoices:view` / `invoices:create` (+ `inventory:manage`) | No permission for who may set prices |

## Principles

1. **The books stay the books.** Nothing here posts a voucher, changes a posted
   voucher or changes `PostVoucher`, `InvoiceBooking` or the invoice triggers.
   A price only reaches the ledger through the existing invoice flow, as the
   row's `unit_price_ore`, `discount_bp` and `net_ore` already do today.
2. **A price on a document is a copy, never a reference.** The resolver
   proposes a price when a row is made; the row stores the price and its
   explanation. Changing a price list never touches an existing row. An issued
   invoice is frozen by the existing trigger; a sent quote or order keeps its
   prices until the user explicitly re-prices a still editable document.
3. **Deterministic and explainable.** The same inputs always give the same
   price. Every resolved row carries a short, structured reason ("Kundavtal
   PA-12, från 10 st", "Kampanj Sommar, gäller till 30/6", "Grundpris"),
   shown on the row and in a "Varför detta pris?" popover with the full chain
   of what was considered and why it lost. No AI chooses a price.
4. **Simple until needed.** A company with no price lists and no agreements
   works exactly as today: the base price is the article's price. Every extra
   concept appears only once used.
5. **Batch, fixed number of queries.** Pricing N rows (an invoice of 300 rows,
   a portal assortment of 2 000 articles, a till sync) costs the same small
   number of queries as pricing one (§Resolver).
6. **Money in minor units, explicit rounding.** Öre for SEK (`Money`), minor
   units for other currencies (`*_minor`, ADR-025), quantities in milli-units
   of a unit from the `units` register (ADR-071 §12), percentages in basis
   points, every division with `RoundingMode::HalfUp` unless a rounding rule
   says otherwise, never floats (`Brick\Math`).
7. **History by validity, not by overwriting.** A price change is a new price
   line with a `valid_from`; the old line gets a `valid_to`. "What did this
   cost on 3 March?" is always answerable, and future price changes can be
   prepared in advance.
8. **Tenancy.** Every new table has `company_id` → `teams`, `BelongsToCompany`,
   indexes leading with `company_id`, a tenancy test (another company's id →
   404) and an entry in `App\Support\Database\DatabaseGrants`. Migrations are
   additive only.
9. **Audit log** (`Audit::record`): ids, enums, counts, amounts and field names
   only; never names, titles or free text.

## Naming (avoid the three existing "agreements")

`agreements` is the collective-agreement module key (ADR-033, `/lon/avtal`),
`/avtal` is recurring invoicing (`invoicing.agreements`, `RecurringInvoice`),
and `/kunder/avtal` is the CRM agreement register (`crm_agreements`). A fourth
"avtal" would confuse users and code.

- Code: `PriceList`, `PriceListItem`, `PriceAgreement`, `PriceAgreementItem`,
  `CustomerPriceGroup`; tables `price_lists`, `price_list_items`,
  `price_agreements`, `price_agreement_items`, `customer_price_groups`,
  `price_update_runs`. Domain namespace `App\Domain\Pricing`, actions
  `App\Actions\Pricing`, Livewire `App\Livewire\Pricing`.
- UI (Swedish): **Prislistor** (`/prislistor`) and **Kundpriser** — a customer
  price agreement is called **Prisavtal** in the UI (`/prisavtal`), under the
  sales menu next to Artiklar. It may be *linked* to a CRM agreement for
  renewal and notice (§Agreements), so there is still one place for the
  agreement as a contract and one for its prices. Decision 2.

## Scope

### 1. Base price

- The **base price** stays `articles.price_ore` (SEK, excl. VAT, per the
  article's sales unit). It is the fallback for every price resolution, so
  nothing changes for existing companies and the article form, the API and the
  importer keep working.
- Every change of `price_ore` is written to `article_price_history`
  (article_id, old/new öre, valid_from, changed_by, source enum: FORM, API,
  IMPORT, PRICE_UPDATE) so the base price has history too. A future-dated base
  price is expressed as a price update run (§6), which writes the new
  `price_ore` on its effective date through a scheduled command.

### 2. Price lists

A price list is a named set of prices with its own currency, VAT basis and
validity. Kinds (enum `PriceListKind`):

| Kind | Swedish | Used for | Applies to |
|---|---|---|---|
| `STANDARD` | Prislista | A customer group's prices (återförsäljare, storkund), or prices in another currency | Customers who have the list (directly or through their price group), and the default list of a currency |
| `CAMPAIGN` | Kampanj | Time-limited prices | Everyone, or chosen price groups, or the till only; always has `valid_from` and `valid_to` |
| `CHANNEL` | Kassa / Webb | Prices for the till (ADR-042) or the portal assortment | The channel it is attached to |

Fields on `price_lists`: `code` (unique per company), `name`, `kind`,
`currency` (ISO 4217, default SEK), `prices_include_vat` (bool; default false,
true by default for CHANNEL lists meant for consumers), `valid_from`,
`valid_to` (null = open), `is_default_for_currency` (at most one per currency
and company, partial unique index), `based_on_list_id` + `derived_rule`
(see below), `rounding_rule` (§7), `customer_price_group_ids` for campaigns
(pivot `price_list_price_group`), `archived_at`, `created_by`.

**Derived lists.** A list may be defined as "the base price (or another list)
minus 12 %, rounded to whole kronor" (`based_on_list_id` null = base price,
`derived_rule` = `{percent_bp: -1200}`), so a reseller list follows every base
price change without maintenance. Explicit items in a derived list override the
rule per article. This is the single most time-saving feature for small
companies and is cheap to compute (one query for the base, one for the list).

**Price list items** (`price_list_items`): `price_list_id`, `article_id`,
`unit_id` (null = the article's sales unit; otherwise any unit of the article
that `ArticleUnits` can convert), `min_quantity_milli` (0 = from the first
unit; several rows = a quantity ladder), `price_minor` (in the list's
currency; incl. VAT when the list says so) **or** `discount_bp` against the
currency's ordinary price — the currency's default list, else the base price
(converted for another currency) — because "10 % off" means off what other
customers pay (exactly one of them, check constraint), `valid_from`, `valid_to`.
A derived list instead starts from whatever its `based_on_list_id` names (the
base price when null), not the default list.
Unique on `(company_id, price_list_id, article_id, unit_id_or_0,
min_quantity_milli, valid_from)`; a check constraint `valid_to >= valid_from`;
an exclusion check in the action (not a DB exclusion constraint, to keep
migrations simple) that two items for the same key do not overlap in time.

### 3. Customer price groups

`customer_price_groups` (code, name, price_list_id): "Återförsäljare",
"Offentlig sektor". `customers.price_group_id` and `customers.price_list_id`
(both nullable, additive). A customer's own list wins over the group's list.
Groups exist so that a campaign can target "all resellers" and so that 200
customers can move to a new list in one change. Decision 3 (whether groups are
needed in v1 or a list per customer is enough).

### 4. Price agreements (Prisavtal)

A price agreement is a customer's negotiated terms, with a contract's life
cycle:

- `price_agreements`: `number` (series PA-1, PA-2 … per company, gapless is
  not required), `customer_id`, `title`, `currency`, `valid_from`, `valid_to`
  (null = until further notice), `status` (DRAFT, AWAITING_SIGNATURE, ACTIVE,
  ENDED, TERMINATED, SUPERSEDED), `crm_agreement_id` (nullable link to
  `crm_agreements` when CRM is on: renewal, notice period, reminders and the
  signed document then come from the agreement register and the price
  agreement's `valid_to` follows that agreement's `end_date`),
  `supersedes_id`, `allow_campaigns` (bool, default false: whether a cheaper
  campaign may beat the agreement), `price_floor_policy` (§9), `signed_at`,
  `document_sha256`, `terminated_on`, `created_by`.
- `price_agreement_items`: target = one `article_id`, or an `article_group`
  (decision 4: only if article groups exist by then; otherwise "all articles"),
  or ALL; `unit_id`; `min_quantity_milli`; `price_minor` **or** `discount_bp`
  **or** `list_id + discount_bp` ("the reseller list minus 3 %"); optional
  `max_quantity_milli` per agreement period (a volume cap, warned not
  enforced in v1).
- **Signing.** `PriceAgreement` implements `App\Domain\Signing\Signable`; the
  PDF shows the terms and the price table. Once ACTIVE (signed, or activated by
  hand with a confirmation), an agreement's items are **frozen** (model event
  plus policy; no DB trigger — this is not räkenskapsinformation). A change is
  a new version: "Ändra avtal" copies it to a DRAFT that `supersedes_id` the
  old one; when the new one becomes ACTIVE the old one becomes SUPERSEDED with
  `valid_to` = the day before.
- **Expiry.** An Overview to-do (`ProvidesTodos`) 30 days before `valid_to`
  ("Prisavtalet med kund 1043 löper ut 31/12"), and a daily command
  `pricing:expire` that sets ENDED. With a linked CRM agreement the CRM
  reminders are used instead, so the user never gets two reminders.
- **Several agreements** for one customer may be ACTIVE only if their
  validity does not overlap; enforced in the action.

**As built (P5), 2026-11-20:**

- **Freezing.** An agreement's terms (head and items) change only while it is
  a DRAFT: model events on `PriceAgreement` and `PriceAgreementItem`, the
  policy (`changeTerms`, `delete`) and the actions. Once it has left DRAFT
  only its life-cycle fields are written (status, `valid_to`,
  `terminated_on`, signature and activation fields). Deleting is tightened
  in the database too: a trigger refuses deleting an agreement that is not a
  DRAFT, and the items of such an agreement (the company cascade is
  unchanged), while the grants stay ReadWrite so drafts can be deleted.
- **Sending** (`SendPriceAgreementForSigning`) checks as for activation (at
  least one term, end not passed, no overlap, a version starts after the one
  it changes), makes the draft AWAITING_SIGNATURE and sends through the
  signing engine in one transaction. "Tillbaka till utkast" revokes every
  open link first. Signing type `PRICE`; see the cross-reference in ADR-046.
- **Manual activation** ("Aktivera utan signering") needs a reason, stored
  on the agreement (`activation_method` MANUAL, `activation_reason`,
  `activated_by`, `activated_at`; additive columns), never in the audit log.
- **Versions.** "Ändra avtal" copies an ACTIVE or TERMINATED agreement into
  one open DRAFT with a new number, starting tomorrow; on activation the old
  one is SUPERSEDED with `valid_to` the day before.
- **Termination** sets TERMINATED and `terminated_on` (not in the past, not
  after `valid_to`); `pricing:expire` (daily 06:05, after `crm:agreements`)
  sets ENDED when an ACTIVE agreement's `valid_to` has passed, and lets a
  DRAFT or ACTIVE agreement linked to the CRM register follow that
  agreement's `end_date` unless that would overlap. Archived companies are
  skipped.
- **Overview.** `PriceAgreementTodos` (core) reminds 30 days before
  `valid_to`, for `price-agreements:manage`, leaving out agreements linked to
  the CRM register while CRM is on and those with a new version on the way.
- Items: one article or all articles (all articles take a discount only); a
  discount is on the ordinary price or a named non-campaign list in the
  agreement's currency; the same target, unit and step only once. The
  resolver (P2) is unchanged.
- `TODO(legal-verify)`: P-L5 (the simple electronic signature for a price
  agreement between companies) and P-L6 (no index clause is modelled; a new
  version is the way to apply one).

**Review fixes (P5), 2026-11-20:**

- The database refuses only DELETE of a non-draft agreement and its items;
  UPDATE of terms is refused by the model events, the policy and the actions,
  not by a trigger (existing resolver tests adjust active terms directly).
- The overlap check also counts an agreement AWAITING_SIGNATURE, both when
  sending and when activating by hand, so two drafts for the same days cannot
  both be out for signing.
- `PriceAgreementActivator::activate` checks again under the row lock: the
  end date has not passed, a version does not start in the past, and no
  other agreement overlaps. A signature that fails these throws a
  ValidationException, which rolls back the signing transaction; the signer
  is told in Swedish to contact the sender. A signing link ends on the
  agreement's last day when that comes within the usual link time.
- A version may not start before today (send, manual activation and
  signature), the same rule as termination, so superseding never rewrites
  days that have passed. Chosen over "start it today" because it is simpler
  and never changes what the customer signed: a version signed after its
  start date is refused, and the user takes it back to draft, sets a new
  date and sends it again.
- A draft that was ever sent for signing cannot be deleted, so no signing
  request points at a deleted row.
- The pre-sign check runs before anything is written: `Signable` has
  `assertSignable()` (a no-op for quotes, CRM agreements, ÄTA and documents),
  which `SignAtLink` and `SignInSession` call with the document locked,
  before fields and the certificate are stored; files written in a signing
  that rolls back are deleted (`DB::afterRollBack`), as the field stamper
  already did.
- While a new version is out for signing (AWAITING_SIGNATURE), its end date
  does not follow a linked CRM agreement; `pricing:expire` follows only
  drafts and active agreements, so the version picks the CRM end date up
  again once it is active (or taken back to draft).
- Erasure of a customer: price agreements that were sent or applied keep the
  customer anonymised and get a neutral title and reason; a never-sent draft
  is deleted with the customer.
- GDPR: the manual-activation reason is in the customer's register extract
  (`prisavtal`) and is replaced by a fixed text when the customer is erased
  (the agreement is kept; a customer with a price agreement is anonymised,
  never deleted).
- PDF: seller and customer rows, a definition of the ordinary price (the
  seller's standard price in the agreement's currency on the day of sale,
  which the seller can change), and a statement that the price agreement
  covers prices and validity only.
- `TODO(legal-verify)`: the notice period for an open-ended B2B price
  agreement without a notice clause; the retention period for the
  manual-activation reason (limitation of claims under the agreement).

### 5. Price resolution order (the resolver)

`App\Domain\Pricing\PriceResolver` takes a `PriceContext` (company, customer
or null, price group, document currency, document date = the voucher date for
invoices, `document_date` for quotes/orders, the sale date for tills,
channel enum SALES | PORTAL | POS | RECURRING | WORK_ORDER) and a list of
`PriceRequest` (article id, unit id, quantity milli, row key). It returns one
`ResolvedPrice` per request: `unit_price_minor`, `discount_bp`, `currency`,
`includes_vat`, `source` (enum `PriceSource`), `source_ids` (list id, item id,
agreement id, tier), `considered` (the losing candidates with reasons), and
`warnings` (below cost, below floor, converted currency, no price).

For each row the candidates are, in this order; the first one that applies
wins, except where a step says "lowest":

1. **Manual price** typed or edited by the user → `MANUAL` (shown with a small
   marker; resolution never overwrites it on its own).
2. **Active price agreement** of the customer, valid on the document date,
   matching currency: the most specific item wins (article > article group >
   all), then the highest `min_quantity_milli` ≤ the row's quantity →
   `AGREEMENT`. If `allow_campaigns` is true, a cheaper campaign (step 4)
   wins over it.
3. **The customer's own price list**, else the customer's price group's list,
   valid on the date, in the document currency → `CUSTOMER_LIST`.
4. **Campaigns** valid on the date that target everyone, the customer's group
   or the channel: **the lowest of** steps 3–5 and the campaigns is used →
   `CAMPAIGN` when the campaign is the lowest. (Decision 5.)
5. **The default list of the document currency** (`is_default_for_currency`)
   → `CURRENCY_LIST`; for SEK without such a list, **the base price**
   (`articles.price_ore`) → `BASE`.
6. **Currency fallback**: no price in the document currency → either the SEK
   price converted at Riksbanken's rate on the document date (`ExchangeRate`,
   ADR-025) with the currency's rounding, marked `CONVERTED` and a warning;
   or no price and a required manual price. Decision 6.

Within a list or agreement:

- **Quantity ladder:** the row's whole quantity gets the price of the highest
  step it reaches ("from 10 st: 90 kr" means 12 st × 90 kr), not a graduated
  split. Decision 7.
- **Units:** the item's unit and the row's unit are compared through
  `ArticleUnits` / `UnitConverter`; a price per pall applies to a row in
  pieces by converting the quantity for the ladder and the price per unit,
  rounded HalfUp once to minor units. An item whose unit cannot be converted
  is skipped and listed in `considered`.
- **Discount vs net price on the row:** an item with `price_minor` gives the
  row `unit_price = price_minor`, `discount_bp = 0`. An item with
  `discount_bp` gives the row the price it discounts (the currency's ordinary
  price, see §2, or the agreement item's named list) as `unit_price` and the percent as `discount_bp`, so the invoice shows the
  ordinary price and the discount (transparent for the customer, and
  `InvoiceCalculator` is unchanged).
- **No stacking:** one price source per row; discounts never multiply.
  The user may still add a manual row discount on top (shown as `MANUAL`
  discount, step 1 for the discount part only). Decision 8.
- **Prices including VAT** (consumer lists, tills): the resolver returns the
  gross price; the row stores the excl.-VAT price
  `round_half_up(gross × 10000 / (10000 + vat_rate_bp))` and the gross price in
  the explanation. `TODO(legal-verify)` P-L1 on the consumer total.
- **Ties** (two items with the same specificity and step): the one with the
  latest `valid_from`, then the lowest id. Never random.

Settled while building P2 (Lead, 2026-11-20):

- **Manual discount on a discount item:** the agreed discount is folded into
  the unit price and the manual discount goes in `discount_bp`; the
  explanation records `folded_discount_bp`. P3's legal review (P-L4, how the
  discount is shown on the invoice) must confirm this.
- **Ended, superseded and terminated agreements** still price documents dated
  inside their validity, so a past date gives the price it had; a terminated
  agreement counts up to and including `terminated_on`.
- **Customer list fallback:** an article the customer's own list does not
  price falls back to the price group's list.
- **Derived lists and currency:** a derived list takes its source only in the
  same currency; a non-SEK list derived from the base price starts from the
  converted base price.
- **What a discount discounts** (review of P2): a discount item, in a list or
  an agreement, discounts the currency's ordinary price (its default list,
  else the base or converted base price); a discount item in the default list
  itself discounts the base price. A derived list follows its `based_on`
  source. Riksbanken's rate is fetched only when a row needs the converted
  base price.
- **Derived chains** are followed for at most five lists; a loop or a longer
  chain prices nothing from those lists and is listed in `considered`.
- **Manual price on an unknown article:** a row whose article is not the
  company's (unknown, or another company's) gets no price and the warning
  `unknown_article`, even when it carries a manual price.
- **The document date** is read as a day in `Europe/Stockholm`, also when a
  moment in UTC is passed.

**Batch design (no N+1).** `resolveMany()` loads, for the whole request list,
in a fixed number of queries regardless of N: (1) the customer with group, list
and active agreements; (2) agreement items for the requested article ids (and
group/ALL items); (3) list items for the relevant list ids and article ids,
filtered on the date; (4) the articles' base price, VAT, units and cost
(`purchase_price_ore` or `ManufacturingBook` cost when the module is on); (5)
the unit conversions; (6) the exchange rate once when needed. Everything else
is in memory. A query-count test with 1 row and 300 rows proves it. The
editors call the resolver **server-side** once for the whole price table of the
customer on customer/date/currency change (articles × the ladders, sent to
Alpine as today's `rowArticles()` payload, so picking an article stays instant)
and once on save to validate that the posted price equals the resolved one or
is marked MANUAL.

**What a row stores** (additive nullable columns on `invoice_rows`,
`sales_document_rows`, `recurring_invoice_rows`, `crm_quote_template_rows`
(article rows only) and work-order material rows): `price_source` (enum
string), `price_explanation` jsonb with ids, enums, tiers and minor amounts
only (`{source, list_id, item_id, agreement_id, tier_min_milli, base_minor,
gross_minor, rate_micros}`), rendered to Swedish text at display time from the
ids. Names are looked up when shown, so the jsonb holds no free text. On an
issued invoice the explanation is frozen by the same trigger as the row.

### 6. Re-pricing rules on documents

- **Draft invoice, open quote, open order:** changing customer, date, currency
  or quantity re-resolves only rows whose `price_source` is not `MANUAL`, and
  shows a toast "3 priser uppdaterades" with an undo. A button "Uppdatera
  priser" opens a diff (old, new, reason) for the whole document.
- **Quote → order → invoice:** prices are carried over unchanged (the customer
  accepted them). If the quote's `until_date` has passed, the progress dialog
  offers "Behåll offertens priser" (default) or "Hämta dagens priser" with the
  diff.
- **Issued invoice, credit invoice:** never re-priced; a credit invoice copies
  the original rows as today.
- **Recurring invoices:** each recurring invoice gets `price_mode` FIXED
  (default, today's behaviour) or FOLLOW_PRICES. With FOLLOW_PRICES,
  `RunRecurringInvoice` resolves the rows on the run date with channel
  RECURRING and stores the explanation on the generated invoice rows; the
  recurring invoice's own row prices are then only a display of the last run.
  Decision 9.

**As built (P3), Lead decisions 2026-11-20 after review** (these replace the
first bullet above where they differ):

- **No silent re-pricing.** Changing customer, date or currency never changes
  a row's price. The editor loads the new price table and clears the table's
  mark on the rows, so each row keeps exactly the price shown; on save a row
  whose price differs from the newly resolved one is stored as `MANUAL`, and
  one that equals it gets the resolver's source. A notice offers "Uppdatera
  priser", which shows the diff first. A changed quantity or unit re-prices
  only a row whose price came from the table, never one typed by hand or
  kept from before; leaving the article field unchanged never refills a
  price. No undo toast is needed, since nothing changes by itself.
- **A saved row keeps its price** only for the same customer, date and
  currency as when it was saved (`RowPriceSnapshot`, currency in the key);
  rows carried from a quote or order are kept whatever the new date.
- **Agreed discounts are folded** into the unit price (rounded half up once
  per unit, `folded_discount_bp` in the explanation) in the editor and on
  the saved row alike, and the discount field holds only a discount typed on
  top (decision 8), so the browser and the server always give the same
  amount. This replaces "the invoice shows the ordinary price and the
  discount" in §5; P-L4 stays open for a lawyer.
- **Prices including VAT:** the row's net unit price is rounded down
  (`floor(gross × 10000 / (10000 + VAT rate))`), so a consumer never pays
  more than the shown price × quantity (P-L1, tolerance still open).
- **Expired quote:** progressing a quote whose `until_date` has passed asks
  "Behåll offertens priser" (default) or "Hämta dagens priser", which shows
  the diff before the order or invoice is made.
- **The editor's price table** holds only the articles on the rows and one
  just picked, never the whole catalogue, and carries no source for a person
  without `prices:view`.

**As built (P8): other consumers of the price engine.**

- **Recurring invoices:** `price_mode` is set in the agreement editor ("Fast
  pris" / "Följ prislistan"). With FOLLOW_PRICES the run asks the resolver for
  the article rows through `SaveDraftInvoice` (new input `price_channel`,
  RECURRING) on the run date; the agreement's own rows then copy the last
  run's price, source and explanation and the agreement's totals follow. Free
  rows keep their price. The editor now loads the rows' articles, so an
  agreement saved from it keeps the article on its rows.
- **CRM quote templates:** article rows are sent without a price, so the quote
  is priced for the customer by SaveSalesDocument (SALES). Template rows have no
  price of their own on article rows, so there is nothing to mark manual.
- **Work-order material:** the sales price of an article is the resolver's
  (channel WORK_ORDER, the work order's customer, the work date, an agreed
  discount folded in); a manager-typed price is stored MANUAL; the cost plus
  mark-up stays the fallback.
- **Tills and labels:** `PriceContext::$channelListId` names the CHANNEL list a
  till uses; it is used after the customer's own and group list, only if it is
  a valid, unarchived CHANNEL list of the same channel and currency, and never
  one of another company. A till connection names it in its settings
  (`price_list_id`). `ChannelPrices` prices articles for POS, no customer, SEK,
  today, as a gross price; `SyncProducts` pushes it (the payload hash includes
  it, so a change is sent once) and `pricing:sync-channels` runs nightly at
  00:05. `LabelPrices` gives labels the same price.
- **P-L2 and P-L3 are not implemented:** no earlier "lowest price in the last
  30 days" and no new jämförpris is computed or shown for campaign prices on
  tills and labels, because the resolver prices a single date and list-item and
  base-price history is not complete enough to give it. Both stay open for a
  lawyer (TODO(legal-verify) in `ChannelPrices` and `LabelPrices`).

### 7. Rounding rules

`rounding_rule` on a list or a price update run (enum `PriceRounding`):
NONE (to öre), WHOLE (to whole kronor), HALF (to 0,50), TEN_ORE_9 (… ,90),
ENDS_IN_9 (whole kronor ending in 9: 99, 149, 1 299), TENS (to 10 kr). All are
integer arithmetic on minor units with HalfUp to the nearest step, except the
"ends in" rules, which take the nearest ending at or above (documented in the
method, unit-tested with edge cases incl. 0, 1 öre, negative and very large
values). Applied on derived lists, on percent updates and on currency
conversion; never on a typed price. A price above zero never rounds to zero:
where WHOLE, HALF or TENS would give 0, the result is one step instead
(0,49 kr → 1 kr with WHOLE, 4,99 kr → 10 kr with TENS); Lead decision
2026-11-20, review finding.

### 8. Mass price update (Prisuppdatering)

A two-step flow like the SIE import (ADR-008):

1. **Choose:** which prices (base prices, one list, several lists, a campaign),
   which articles (all, a filter on number range, supplier, article group,
   stock class, or an uploaded selection), the change (percent up/down,
   fixed amount, "set margin to X % over cost", or **from a file**), the
   rounding rule and the **effective date** (today or future).
2. **Preview:** a table with old price, new price, change in percent, margin
   before/after against cost, and flags (below cost, changed more than ±25 %,
   rounding moved more than 2 %). Rows can be excluded. Totals: number of
   prices, average change.
3. **Apply:** inside one transaction, writes new items with
   `valid_from = effective date` and closes the old ones with `valid_to = the
   day before`; for base prices with a future date, stores the run and lets
   `pricing:apply-scheduled` write `price_ore` on that date. The run
   (`price_update_runs`: who, when, scope as ids, rule, counts, a stored CSV of
   the diff for the archive) is audited and can be **reverted** until its
   effective date has passed (afterwards a new run is needed, which keeps
   history honest).

**From file:** CSV or XLSX (`openspout/openspout`, already a dependency, MIT)
with columns article number, unit code (optional), from quantity (optional),
price or percent, valid from (optional). The file is parsed, validated per row
(unknown article, unit not convertible, negative price, duplicate key,
decimal comma and space as thousands separator accepted, as `Money::parse`),
and shown in the same preview with a "fel" column; nothing is written until
confirmed. An export of any list in the same format makes "export, edit in a
spreadsheet, import" the round trip. The file is stored with the run (sha256)
and pruned with the run's retention (decision 10).

**Index clause (KPI):** a run type "Indexuppräkning" takes the index values
(old and new) typed by the user and applies the ratio; NONOX does not fetch
index figures in v1. Decision 11.

**As built (P6), 2026-11-20** (these settle details the text above leaves open):

- The preview and the diff are rows of `price_update_run_lines` (additive
  table: target BASE or LIST, list, article, unit, step, the item in force,
  old/new minor or discount, unrounded amount, purchase-price cost, dates,
  status, refusal code, flags). The diff stays when the file is pruned and is
  downloadable as CSV; no separate CSV is stored.
- Kinds built: percent, fixed amount (SEK targets only; other currencies are
  refused per line), index and file. "Margin over cost" (`MARGIN`) waits for
  P7's costing. Selection: all active articles, a number range (numeric when
  both bounds are digits), a search, or chosen articles; supplier, article
  group and stock class filters are not built.
- A list item in force on the date is never edited: it ends the day before and
  a new item (carrying `price_update_run_id`) starts on the date and keeps the
  old item's end. Refused, with the reason shown: discount items (they follow
  the ordinary price already), an item starting the same day, and in a file a
  key with only a later item. The "below cost" flag compares with the purchase
  price only where no unit or currency conversion is needed.
- Apply re-checks every line under row locks; a price changed since the
  preview is SKIPPED (`CHANGED_SINCE`), never overwritten. Base prices dated
  today are written at once with a PRICE_UPDATE history row; later ones by
  `pricing:apply-scheduled` (nightly, per company, idempotent).
- Revert (until and including the effective date, the earliest line date for
  a file) archives the run's items, gives the ended items back their end when
  nothing else took the period, and sets base prices back with a new history
  row; it never deletes and never overwrites a price changed by someone else.
- A file row may carry its own "Gäller från" (today or later); a file holds
  at most 10 000 rows and 12 MB (Livewire's upload limit, kept for every screen) and is read through the register importer's
  streaming reader. Export writes running and coming items in the same
  columns, guarding text against formulas.
- Retention (decision 10): `pricing:prune-updates` deletes a run's file two
  years after it was applied and clears an unapplied preview's lines and file
  after seven days; the run rows stay (`price_update_runs` has no DELETE grant).

### 9. Margin and floor warnings

- Cost per unit = the costing price used by ADR-071 §6 (`ManufacturingBook`
  when the module is on: own bill of materials, latest receipt, purchase
  price; otherwise `purchase_price_ore`), converted to the row's unit.
- A row priced below cost shows a red warning on the row ("Under
  självkostnad: 412 kr < 430 kr") and the document shows its total
  contribution margin for users with `prices:view-cost`. Warnings never block.
- Optional **floor**: a company setting "lägsta täckningsgrad" (e.g. 15 %) and
  a per-agreement `price_floor_policy` (WARN | REQUIRE_APPROVAL). With
  REQUIRE_APPROVAL a manual price below the floor needs a user with
  `prices:approve-below-floor` (hook into the ADR-081 approval engine as a new
  subject `SALES_PRICE_BELOW_FLOOR` when E2 is merged). Decision 12.
- Cost and margin are **never** shown on customer PDFs, in the portal or in the
  API without the cost permission.

**As built (P7), 2026-11-20** (after the cold review):

- **What counts as manual.** A typed price (source MANUAL), a discount typed on
  top of a list or agreement price (`discount_bp` above 0, since an agreed
  discount is folded into the price), and a row without an article. Only
  those can be held; a list or agreement price without a typed discount only
  warns. The floor is judged per row and for the document as a whole: a
  negative row (a discount row, a return) lowers the sales, and a manual row
  on a document under the floor needs approval even when no single row is.
- **Approval is a small record of its own** (`price_floor_approvals`), not an
  ADR-081 subject: the engine needs a configured policy and about ten
  cross-cutting edits per subject. It is for one version of the rows, the
  fingerprint of customer, date, currency and every row's article, unit,
  quantity, price, discount and source. The writer, anyone who changed the
  draft (`invoice.draft_saved`, `sales_document.saved`, written while the
  company has a floor) and the requester cannot decide it while
  `separate_supplier_approval` is on.
- **Where it holds.** `IssueInvoice` (also a recurring draft issued by a
  person; only `RunRecurringInvoice`'s automatic issue is exempt),
  `SendSalesDocument` (locked, in a transaction), `SendForSigning` for a quote,
  quote acceptance in the portal (`SignInSession::accept`, which tells the
  customer nothing of the floor) and `ProgressSalesDocument::toOrder`. A sent
  quote can still be edited; its changed rows are simply not approved, so it
  cannot be accepted or made an order until they are. Credit invoices are not
  held.
- **Only rows that lower the margin hold the document as a whole:** every
  negative row (typed or at a list price), rows with a typed discount and typed
  prices under the floor. A return takes cost back only for an article that is also sold
  on the document, up to the cost sold (quantity converted to the stock unit) and
  never more than the row's own amount, so a partial return keeps its margin and
  a return can never raise it (a cost that changed since the sale included). A positive
  free-text row (freight) or a row of 0 kr holds nothing.
- **The customer portal** hides a sent quote or order whose manual prices are
  not approved in the rows it has now (`PriceFloorGuard::heldIds`, rows, costs
  and approvals loaded once), both the document and its PDF; `sent_at` is kept.
- **An agreement sent automatically** (`RunRecurringInvoice`, `automatic: true`)
  is not guarded by the floor: the agreement itself is the price authority.
- **Other currencies.** The margin cannot be checked in a currency other than
  kronor, so while the company requires approval every typed price on an
  article row of such a document needs it.
- **Floor of 0 %** is refused (minimum 0,01 %): below cost and below zero
  would otherwise tell anyone cost by elimination. `marginCheck` answers a
  role that can edit documents but lacks `prices:view-cost` only with the
  floor, never a cost; no such role exists today, but if one is added it could
  probe the floor by editing prices, which should be weighed then.

### 10. Where the resolver is used

| Consumer | Channel | How |
|---|---|---|
| Invoice, quote and order editors (`EditsSalesRows`, `SalesRowInput`) | SALES | Price table per customer to Alpine; validation on save |
| `SaveDraftInvoice`, `SaveSalesDocument` via API | SALES | A row without `price` gets the resolved price; a row with `price` is MANUAL unless it equals the resolved one |
| CRM quote templates (`CreateQuoteFromTemplate`) | SALES | Article rows resolved for the deal's customer |
| Recurring invoices (`RunRecurringInvoice`) | RECURRING | Only with FOLLOW_PRICES |
| Work orders (material, hourly articles) | WORK_ORDER | Article-based entries resolved for the work order's customer |
| Customer portal assortment and ordering (ADR-071 §9, ADR-075 §11) | PORTAL | The contact sees the company's chosen assortment at the customer's resolved prices, incl. ladders ("från 10 st: 90 kr"); the order is re-resolved on receipt and the reviewer sees differences |
| Tills (`SyncProducts`, `ArticlePayload::grossOre`) | POS | The till connection may name a CHANNEL list; the gross price pushed is the resolved POS price for "no customer" on today's date; campaigns with the POS channel trigger a sync on their `valid_from` and `valid_to` (`pricing:sync-channels`, scheduled) |
| Shelf labels (`LabelData`) | POS | Same price as the till; the label may show "ord. pris" during a campaign (see legal P-L2) |
| API (`GET /prices/resolve`) | any | §API |

### 11. Price history and "what did it cost?"

- Every list item and agreement item is kept with its validity; nothing is
  deleted once used on a document (soft "archive" instead; hard delete only
  for items never valid yet).
- The article page gets a tab "Priser" showing all current and future prices
  of the article across lists, agreements and campaigns, and the base price
  history, plus a "pris på datum" lookup.
- The customer page gets a tab "Priser" with the customer's agreement(s), list,
  group and a searchable "this customer's price for every article today".
- Lowest price in the last 30 days per article and channel is computable from
  history for consumer campaigns (P-L2).
- Known gap (review finding 2026-11-20): `article_price_history` starts with
  P1 and is not backfilled. An article that existed before has no first
  history row, so the history answers "what did the base price cost on a
  date" only from the article's first price change after P1 (whose
  `old_price_ore` is the price it had until then).

## Data model (all additive)

```
customer_price_groups   id, company_id, code, name, price_list_id null, archived_at
                        unique(company_id, code)
price_lists             id, company_id, code, name, kind, currency char(3), prices_include_vat bool,
                        valid_from date null, valid_to date null, is_default_for_currency bool,
                        based_on_list_id null, derived_percent_bp int null, rounding_rule,
                        channel null, archived_at, created_by
                        unique(company_id, code); partial unique(company_id, currency) where is_default_for_currency
price_list_price_group  company_id, price_list_id, customer_price_group_id   (campaign targeting)
price_list_items        id, company_id, price_list_id, article_id, unit_id null, min_quantity_milli bigint default 0,
                        price_minor bigint null, discount_bp int null, valid_from date null, valid_to date null,
                        price_update_run_id null, archived_at
                        CHECK (num_nonnulls(price_minor, discount_bp) = 1), CHECK (price_minor >= 0),
                        CHECK (discount_bp BETWEEN 0 AND 10000)
                        index(company_id, price_list_id, article_id)
price_agreements        id, company_id, number int, customer_id, title, currency, valid_from, valid_to null,
                        status, crm_agreement_id null, supersedes_id null, allow_campaigns bool,
                        price_floor_policy, signed_at null, document_sha256 null, terminated_on null, created_by
                        unique(company_id, number); index(company_id, customer_id, status)
price_agreement_items   id, company_id, price_agreement_id, article_id null, article_group_id null,
                        applies_to_all bool, unit_id null, min_quantity_milli, price_minor null,
                        discount_bp null, base_price_list_id null, max_quantity_milli null
article_price_history   id, company_id, article_id, old_price_ore, new_price_ore, valid_from, source,
                        price_update_run_id null, changed_by, created_at       (append-only)
price_update_runs       id, company_id, kind, scope jsonb (ids only), rule jsonb, rounding_rule,
                        effective_date, status (PREVIEW, APPLIED, SCHEDULED, REVERTED), counts,
                        file_path null, file_sha256 null, created_by, applied_at
customers               + price_group_id null, + price_list_id null
recurring_invoices      + price_mode default 'FIXED'
invoice_rows, sales_document_rows, recurring_invoice_rows, crm_quote_template_rows,
work-order material rows
                        + price_source string null, + price_explanation jsonb null
sales_documents         + currency char(3) default 'SEK' (quotes/orders in EUR; carried to the
                        draft invoice's invoice_currency; decision 13)
```

`invoice_rows` already blocks every change once the invoice is issued
(`invoice_rows_protect_issued`); the two new columns are written while the
invoice is a draft, so the trigger needs no change. The `invoices_protect_issued`
function is not touched.

## Permissions

New core permission keys (an AGENTS.md §4 addition the owner must approve):

| Key | Purpose | Owner | Admin | Accountant | Bookkeeper | Approver | Auditor | CRM Säljare |
|---|---|---|---|---|---|---|---|---|
| `prices:view` | See lists, agreements, history, "why this price" | ✓ | ✓ | ✓ | ✓ | – | ✓ | ✓ |
| `price-lists:manage` | Create/edit lists, campaigns, groups, base prices, mass updates, file import | ✓ | ✓ | ✓ | – | – | – | – |
| `price-agreements:manage` | Create/edit/send/terminate price agreements | ✓ | ✓ | ✓ | ✓ | – | – | ✓ (module role) |
| `prices:view-cost` | See cost, margin and contribution | ✓ | ✓ | ✓ | ✓ | – | ✓ | – |
| `prices:approve-below-floor` | Approve a price under the floor (only with REQUIRE_APPROVAL) | ✓ | ✓ | ✓ | – | – | – | – |

- Changing `articles.price_ore` from the article form keeps today's rule
  (`invoices:create`) when the company has no price lists, and requires
  `price-lists:manage` once the setting "Styrda priser" is on (decision 14),
  so a company can separate who sells from who sets prices.
- Typing a manual price on a row stays with `invoices:create` (decision 12
  covers the floor).
- Read access to prices through API scopes: `prices:view`; writes:
  `price-lists:manage` / `price-agreements:manage`.
- The CRM seller role gets `price-agreements:manage` only while CRM is on
  (module role permissions, `CrmModule::rolePermissions`).

## Accounting impact

None beyond what exists. Prices become `unit_price_ore`, `discount_bp` and
`net_ore` on rows, booked by the unchanged `InvoiceBooking` when the invoice is
issued (fakturametoden) or paid (kontantmetoden). No new voucher source, series,
account or VAT logic. The VAT rate still comes from the article or the row;
a price list never changes a VAT rate. `docs/systemdokumentation/README.md`
gets one paragraph (in P3) stating that row prices are proposed by the price
engine, stored as a copy with their source, and never changed after issue;
that is a description of the sales flow, not a change of accounting behaviour,
but the BFNAR 2013:2 documentation should say how amounts on invoices arise.

## Tenancy risks

- The resolver must only read lists, items and agreements of `CurrentCompany`:
  all models use `BelongsToCompany`; the batch queries never use
  `CompanyScope::bypass`.
- Cross-references (`price_lists.based_on_list_id`, `customer_price_groups.
  price_list_id`, `price_agreements.crm_agreement_id`, items' `article_id` and
  `unit_id`) are validated inside the company in the actions (an id from
  another company → validation error, never a link). Tenancy tests cover each.
- Portal and till: the resolver runs in the company context of the portal
  identity or the till connection (`CurrentCompany::run`), as today.
- Jobs (`pricing:apply-scheduled`, `pricing:expire`, `pricing:sync-channels`)
  iterate companies and call `CurrentCompany::run($companyId)` per company.

## API (NONOX API v1, Connect)

| Method | Path | Scope | Notes |
|---|---|---|---|
| GET | `/api/v1/price-lists`, `/{id}`, `/{id}/items` | `prices:view` | Filters: `valid_on`, `article`, `currency`; cursor pagination |
| POST/PATCH | `/api/v1/price-lists`, `/{id}` | `price-lists:manage` | Idempotency-Key on POST |
| POST | `/api/v1/price-lists/{id}/items` (bulk, ≤ 1 000 items) | `price-lists:manage` | Idempotency-Key; validated as the file import; returns per-row errors |
| GET | `/api/v1/price-agreements`, `/{id}` | `prices:view` | |
| POST/PATCH | `/api/v1/price-agreements`, `/{id}`; POST `/{id}/activate`, `/{id}/terminate` | `price-agreements:manage` | Idempotency-Key on every POST |
| GET | `/api/v1/prices/resolve?customer=&date=&currency=&channel=&rows[]=article:qty:unit` | `prices:view` | The same resolver, ≤ 500 rows, returns price, discount, source and explanation (cost only with `prices:view-cost`) |
| — | `invoices` / `sales-documents` POST | unchanged scopes | A row without `price` is resolved; the response row carries `price_source` |

Webhooks (`WebhookEvents`): `price_list.changed` (items added/changed for a
date; payload ids and dates only) and `price_agreement.activated` /
`price_agreement.ended`, all requiring `prices:view`. For web shops and tills
outside NONOX that mirror prices. OpenAPI and the API changelog are updated in
P9.

## Moving in

- The register import (`app/Migration/Import`, ADR-076) gets a `PriceLists`
  kind: a CSV/XLSX with list code, currency, article number, from quantity,
  price, valid from — the same reader as the mass update file.
- The automated importer in `app/Migration/Fortnox` may later read the source's
  price lists and customer prices if its API offers them;
  `TODO(legal-verify)`/`TODO(verify)`: check the current API documentation and
  terms before building (AGENTS.md §14.1). Not in the first packages.
- SIE does not carry prices; nothing changes in SIE import or export.

## GDPR

Price lists and agreements are commercial data about companies. An agreement
with a sole trader (EF) customer is linked to a customer who may be a natural
person; it follows the customer's existing legal basis (contract), retention
(BFL where it supports an invoice; otherwise the agreement register's
retention) and erasure rules. The audit log stores ids only. A signed price
agreement follows ADR-046's signing retention. `docs/gdpr/registerforteckning.md`
and `lagringstider.md` get a line in P4.

## Legal items (`TODO(legal-verify)`)

- **P-L1 Prices to consumers incl. VAT.** Prisinformationslagen (2004:347)
  requires the price shown to consumers to include VAT and other taxes; the
  design lets a list be incl. VAT and stores the excl.-VAT row price derived
  from it. Verify that an invoice or till receipt to a consumer that ends up
  one öre off the shown price after VAT rounding is acceptable, or whether the
  row must be computed backwards from the gross total.
- **P-L2 Price reductions to consumers.** The rules on announcing a price
  reduction (prisinformationslagen, the "lowest price in the last 30 days"
  rule implementing the EU Omnibus directive) — verify the exact
  section, the exceptions (gradually increasing reductions, perishables) and
  whether NONOX must show the earlier lowest price on labels or in the till
  when a CAMPAIGN list with the POS channel is active. The history needed is
  stored either way.
- **P-L3 Jämförpris (unit price).** Whether consumer price lists must carry a
  comparison price per kg/litre and how that interacts with ADR-071's
  comparison price on labels.
- **P-L4 Invoice content.** Mervärdesskattelagen's requirement that an invoice
  shows the unit price excl. VAT and any discount not included in the unit
  price: the design keeps unit price and `discount_bp` separate; verify that a
  FIXED agreement price shown as the unit price (no discount line) is
  sufficient.
- **P-L5 Signed price agreement as an agreement.** That an e-signed price
  agreement through NONOX signering is valid as written agreement between
  companies (same assessment as ADR-046; no new question expected).
- **P-L7 Price changes in running subscriptions (P8).** TODO(legal-verify):
  unilateral price-change clauses in consumer subscriptions (avtalsvillkorslagen,
  duty to give notice); "Följ prislistan" changes the price of the next invoice
  without any notice, and the manual says only that the customer's approval is
  decided by the company's agreement.
- **P-L2 (P8 detail).** TODO(legal-verify): the exact section (7 a § prisinformationslagen?)
  and its exceptions for announcing a price reduction to consumers; the manual
  tells the company it is responsible for stating the earlier price.
- **P-L6 Index clauses.** No legal rule is assumed for how an index clause is
  applied; the user supplies the figures and the clause text.

## Out of scope (v1)

- Article groups as a new register (decision 4 decides whether the agreement
  target "article group" waits for one).
- Bundles and "buy 3 pay 2" promotions; coupon codes; customer-specific
  assortments beyond what the portal assortment setting does.
- Supplier price lists (purchase prices) — ADR-081 gap 3 owns purchasing.
- Automatic index figures from SCB or other sources.
- Price optimisation or AI-suggested prices.

## Decisions for the owner (answer in one go)

Until the owner answers, Lead builds on the recommended default. The owner
may overturn any of them before the package that depends on it merges.

| # | Fråga | Rekommenderat | Skäl |
|---|---|---|---|
| 1 | Kärnfunktion eller modul? | Kärnfunktion under Försäljning, ingen modul att slå på | Används av fakturering, offert, order, kassa och kundportal; ett företag utan prislistor ser ingen skillnad |
| 2 | Namn i gränssnittet | "Prislistor" (`/prislistor`) och "Prisavtal" (`/prisavtal`); kan kopplas till ett avtal i avtalsregistret (CRM) | "Avtal" används redan tre gånger (avtalsfakturering, avtalsregistret, kollektivavtal) |
| 3 | Kundprisgrupper i första versionen? | Ja, enkla grupper (kod, namn, prislista) | En kampanj för "alla återförsäljare" och att flytta många kunder till ny lista i ett steg |
| 4 | Artikelgrupper som mål i prisavtal | Vänta tills artikelgrupper finns; v1 har artikel eller alla artiklar | Inget nytt register byggs bara för detta |
| 5 | Får en kampanj slå kundens prislista? | Ja, lägsta priset av kundens lista och kampanjen vinner; ett prisavtal vinner över kampanj om inte avtalet tillåter kampanjer | Kunden ska aldrig få sämre pris än en kampanj, men ett avtalat pris är avtalat |
| 6 | Saknat pris i kundens valuta | Räkna om grundpriset med Riksbankens kurs på dokumentdatum, avrunda enligt listans regel och visa en varning på raden | Fakturan kan alltid skapas; säljaren ser att priset är omräknat |
| 7 | Kvantitetstrappa | Hela antalet får priset för det högsta steget som nås (inte delat per steg) | Enklast att förklara och räkna efter |
| 8 | Får rabatter läggas på varandra? | Nej, en priskälla per rad; säljaren kan lägga en manuell radrabatt ovanpå | Förutsägbart och förklarbart |
| 9 | Avtalsfakturering och prisändringar | Fast pris som i dag är standard; "Följ prislistan" kan väljas per avtalsfaktura | Ingen löpande faktura ändras utan att företaget valt det |
| 10 | Lagring av uppladdade prisfiler | Sparas med prisuppdateringen i 2 år, sedan rensas filen (diffen finns kvar) | Spårbarhet utan att lagra filer för evigt; inte räkenskapsinformation |
| 11 | Indexuppräkning | Användaren skriver in indextalen; NONOX hämtar inga index i v1 | Ingen extern källa att verifiera eller avtala om |
| 12 | Lägsta täckningsgrad | Av som standard; när den slås på varnar den, och ett prisavtal kan kräva godkännande (`prices:approve-below-floor`) via attestmotorn | Små företag ska inte stoppas; större kan styra |
| 13 | Valuta på offert och order | Ja, offert och order får valuta som följer med till fakturan | Annars kan en EUR-kund inte få en offert med sina priser |
| 14 | Styrda priser | Inställningen "Styrda priser" är av som standard; påslagen kräver den `price-lists:manage` för att ändra grundpris | Separera den som säljer från den som sätter pris när företaget vill |
| 15 | Behörigheter | Nya nycklar `prices:view`, `price-lists:manage`, `price-agreements:manage`, `prices:view-cost`, `prices:approve-below-floor` med rollerna i tabellen ovan, tillagda i AGENTS.md §4 | Ägarens godkännande krävs för ändringar i AGENTS.md §4 |
| 16 | Kampanj i kassan | Kampanjlistor kan riktas till kassan och synkas automatiskt vid start och slut | Kassapriset ska stämma utan handpåläggning |
| 17 | Ordningen | Paketen nedan, P1–P3 först | Grundmotorn ger värde på fakturan direkt |

## Build packages

Each package: migration(s), actions, policy, tests (feature, tenancy,
query-count with 1 and 300 rows where it prices rows), Livewire UI, its own
translation file `lang/packages/pricing-pN/en.json` (ADR-081 E0 pattern), a
Swedish changelog entry, the manual article(s) with real screenshots from demo
data (`skarmbilder.yaml`, `manual:screenshots`), demo data in
`Demo/Extras/*`, and the docs listed. "Model": **sonnet** for ordinary CRUD/UI,
**opus** for the resolver, money/rounding, re-pricing of documents and legal
texts. Review: technical; technical + legal where marked.

| # | Package | Depends on | Model | Review | Main files | Docs to update (QUICKSTART §5) |
|---|---|---|---|---|---|---|
| P1 | Pricing core: migrations for all tables in §Data model (tables + additive columns), models with `BelongsToCompany`, enums (`PriceListKind`, `PriceSource`, `PriceRounding`), `PriceRounding` maths, `DatabaseGrants`, tenancy tests, `article_price_history` written by `SaveArticle` | — | opus | technical | new `app/Domain/Pricing/*`, `app/Models/Price*`, `SaveArticle`, `DatabaseGrants` | changelog (internal "Grund för prislistor" only if user-visible: no), systemdokumentation not yet |
| P2 | `PriceResolver` (batch, explanation, ladders, units, currency fallback, incl.-VAT), golden tests with synthetic fixtures `tests/Fixtures/pricing/*.json`, property tests (deterministic, never negative, manual never overwritten), query-count test | P1 | opus | technical | `app/Domain/Pricing/PriceResolver.php`, `PriceContext`, `ResolvedPrice` | — |
| P3 | Resolver in invoice, quote and order editors and `SalesRowInput`/`SaveDraftInvoice`/`SaveSalesDocument`: price table to Alpine, "Varför detta pris?" popover, row marker, "Uppdatera priser" diff, quote → order → invoice rule, currency on quotes/orders (decision 13) | P2 | opus | technical + legal (P-L1, P-L4) | `EditsSalesRows`, `SalesRowInput`, `SaveSalesDocument`, `ProgressSalesDocument`, editor views, `SalesDocumentPdf` | changelog, manual (fakturor, offerter, ordrar) + screenshots, systemdokumentation paragraph + version, `lang/packages/pricing-p3` |
| P4 | Price lists and customer price groups UI (`/prislistor`): lists, items with ladders and validity, derived lists, campaigns, defaults per currency, customer page tab "Priser", article page tab "Priser" | P1, P2 | sonnet | technical | new `App\Livewire\Pricing\*`, customer and article pages, routes | changelog, manual article "Prislistor" + screenshots, landing `Features.php` + card with screenshot, `LandingPage::INCLUDED`, README, `docs/gdpr/*` line |
| P5 | Price agreements (`/prisavtal`): versions, Signable PDF, activation, expiry to-dos, `pricing:expire`, optional link to CRM agreement | P2, P4 | opus | technical + legal (P-L5) | `PriceAgreement`, Signing integration, Overview provider, CRM agreement card | changelog, manual "Prisavtal" + screenshots, `Features.php`, ADR-046 cross-reference note |
| P6 | Mass price update and file import/export: preview/diff, rounding, effective dates, revert, `pricing:apply-scheduled`, index run, XLSX/CSV via openspout | P4 | opus | technical | `app/Actions/Pricing/PlanPriceUpdate`, `ApplyPriceUpdate`, `RevertPriceUpdate`, file reader | changelog, manual "Prisuppdatering" + screenshots, `docs/gdpr/lagringstider.md` (decision 10) |
| P7 | Margin and floor: cost per row, warnings, contribution on documents, company floor setting, approval subject when ADR-081 E2 is merged | P3 | sonnet | technical | `DocumentCosting` (read), editors, approval adapter | changelog, manual (fakturor/offerter) |
| P8 | Other consumers: recurring invoices `price_mode`, CRM quote templates, work-order material, till sync (CHANNEL lists, `pricing:sync-channels`), labels | P3, P4 | sonnet | technical + legal (P-L2, P-L3 for tills/labels) | `RunRecurringInvoice`, `CreateQuoteFromTemplate`, WorkOrders material, `SyncProducts`, `ArticlePayload`, `LabelData` | changelog, manual (avtalsfakturering, kassa, etiketter, arbetsorder) + screenshots |
| P9 | API and webhooks: price-lists, price-agreements, `prices/resolve`, `price_source` on rows, three webhook events, Connect scopes | P4, P5 | sonnet | technical | `app/Modules/Connect/routes/api.php`, new controllers, `WebhookEvents` | `docs/api/openapi.yaml`, API changelog, developer portal text |
| P10 | Launch sweep: register import kind `PriceLists` (ADR-076), demo data for all industries, comparison row `docs/migration/jamforelse.json` "Prislistor och kundavtal" → `nonox: "yes"` (competitor values only from vendors' own pages, else `unknown`), industry pages, AGENTS.md §4 permission rows (approved in decision 15), this ADR to "Accepted, implemented" | P1–P9 | sonnet | technical + legal | `app/Migration/Import/*`, seeders, public pages, `docs/migration/*` | `jamforelse.json`, landing, README, changelog |

Customer-portal ordering at customer prices (ADR-071 §9, ADR-075 §11) is not a
package here: it calls `PriceResolver` with channel PORTAL when it is built.

**Waves:** P1 → P2 → (P3, P4 in parallel; P4 merges first because P3's editors
show list names) → (P5, P6, P7, P8 in parallel; P8 after P3 and P4) → P9 →
P10. Shared hotspots: `andringslogg.json`, `Features.php` (keep both sides,
newest changelog entry first), the systemdokumentation version (one bump, in
P3).

## Consequences

- Every row on a sales document gets a source and an explanation for its
  price; manual prices remain possible and visible as such.
- `articles.price_ore` stays the base price, so the API, importers and
  existing companies keep working without migration of data.
- AGENTS.md §4 gets five permission keys when decision 15 is approved.
- ADR-071 §9's "the customer's price list or agreement" and ADR-075 §11 now
  point at this ADR's resolver.
- The comparison row "Prislistor och kundavtal" can be set to `yes` only after
  P3, P4 and P5 are live.

## Follow-ups after P10

Known and accepted at launch; none blocks the feature.

- **Recurring agreements:** a re-save of a recurring agreement can swap the
  price source between two rows that have the same article and price.
  *Fixed 2026-11-26.* The agreement editor carries each stored row's id
  (`row_id`), and a re-save matches on it. A caller that sends no id is
  matched on article and price, preferring the identical source, then the
  same position, and each stored row is claimed once.
- **Customer portal:** the portal's `held()` loads every sent document instead
  of only those the page shows.
  *Fixed 2026-11-26.* The list is a simple paginator whose page alone is
  checked against the floor; a document, and the open-quote count, are
  checked on their own. A page whose documents are all held shows a neutral
  line and keeps its page links. With the floor on, `openQuoteCount()` loads
  the open quotes with their rows (bounded by the open quotes, not by the
  history); without it the count is one SQL query.
- **P3 editor follow-ups.** *Fixed 2026-11-26.*
  1. The browser worked out the net price of a price including VAT with the
     article's VAT rate, while the server uses the row's own. A price-table
     tier now carries the discounted gross price (`gross`), and the editor
     works out the net price from it with the row's VAT rate (the same
     rounding down as `DocumentPricing::consumerNet`), also when the VAT rate
     of the row is changed afterwards.
  2. `previewTodaysPrices()` added the stored rows' units to `keptUnitIds`;
     a preview now leaves the editor's state alone (`rowsFrom(keepUnits: false)`).
  3. A query-count test (`PricingP3FollowupsTest`) covers the invoice and quote
     editors' price table, "Uppdatera priser" and "Hämta dagens priser" for 1 and 120
     rows. No N+1 was found; the test is a guard.
  4. The explanation of a gross price now says that the list price includes
     VAT and that the net unit price is worked out with the row's VAT rate and
     rounded down.
  5. The customer's price screen showed the resolver's net price (rounded half
     up) while the editors round down. It now shows `DocumentPricing`'s price,
     so both agree to the öre (10,01 kr including 25 % VAT: 8,00 kr).
- **VAT rate changed on a saved gross-priced row (open, found 2026-11-26):**
  the snapshot match (rule 4) compares article, unit, quantity, price,
  discount and currency but not the VAT rate. A saved row from a list
  including VAT (10,01 kr, 9,44 kr net at 6 %) that is changed to 25 % keeps
  9,44 kr net, so the customer pays 11,80 kr including VAT, and the kept
  explanation still says the customer never pays more than the list price.
  This predates the P3 follow-ups. Proposed fix: include `vat_rate` in the
  snapshot match for rows whose explanation carries `gross_minor`, so such a
  row is priced again at the new rate on save.
  `TODO(legal-verify)`: part of P-L1 — when a correction of the VAT rate
  should change the price a consumer was shown.
- **Campaigns have no channel field:** a campaign cannot be aimed at one sales
  channel; only channel lists can. *Owner decision 2026-11-22: build it (P11
  below). P11 is built.*
- **Refunds and the margin floor:** whether a refund or credit should be
  checked against the lowest contribution margin is an owner decision.
  *Owner decision 2026-11-22 (Lead's recommendation): no. A credit note or
  refund corrects a sale that has already been made and priced; holding it
  would hold back the correction itself. Who may credit is already governed by
  `invoices:credit`; a general approval step for credits belongs to the
  approval queue (ADR-067), not to the margin floor.*
- **Mass update (P6):** no `MARGIN` rule (set the price from a target margin)
  and no supplier or customer-group filters.
- **Archive package:** the price source and explanation of issued invoice
  rows are in the archive package as `kundfakturor/Prisuppgifter.csv`
  (version 0.161). *Done 2026-11-23 (queue item 28 follow-up).* SIE stays
  unchanged: it carries no prices.
- **Open legal items:** P-L1 to P-L7 above stay `TODO(legal-verify)` until a
  lawyer has read them.

Rule added in P10 review: a price that has started to apply may be ended only from yesterday onwards, also when a new price replaces it (screen, API `replace_running` and file import alike). The file import may chain prices made by the same file, which allows a history with past dates.

## P11: a channel on campaigns (owner 2026-11-22)

A CAMPAIGN list may name one channel (`price_lists.channel`, the existing
column), or none. No channel keeps today's behaviour: the campaign applies
wherever its customers or groups apply. With a channel, the resolver uses the
campaign only when the document's channel (`PriceContext::$channel`) is the
same, so a till campaign never reaches an invoice and a portal campaign never
reaches the till. The till sync and the labels count only campaigns without a
channel or with POS. The campaign screen, the API (`channel` on a CAMPAIGN
list) and the file import take the field.

Built as follows. A campaign has two channel choices besides none: POS (the
till only) and SALES, which reaches every document channel except the till and
the portal, i.e. SALES, RECURRING and WORK_ORDER. RECURRING- or
WORK_ORDER-only campaigns are not offered, because a draft is re-priced under
SALES in the invoice editor and would lose them, and PORTAL has no in-app
consumer yet; `SavePriceList` refuses other channels on a campaign (a channel
list still needs POS or PORTAL, empty means none). `PriceBook::bestCampaign`
skips a campaign the document's channel (`PriceContext::$channel`) is not
reached by; it reads a
column already on the loaded list, so the query count is unchanged. The till
sync and the labels price through `ChannelPrices`, which asks for the POS
channel, so they take only campaigns without a channel or with POS without any
change; the nightly sync is date-driven and hash-based and has no
save-triggered path for campaigns. The register file import creates only
STANDARD lists, so it has no channel column.
