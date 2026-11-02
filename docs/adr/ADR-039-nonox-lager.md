# ADR-039: NONOX Lager (inventory)

- Status: **Accepted**
- Date: 2026-11-02
- Builds on: ADR-017 (module framework), ADR-032 (NONOX Connect), the
  article register (Phase 2) and the year-end closing (Phase 3)
- Changes: AGENTS.md §7 listed inventory/warehouse as out of scope. The owner
  has explicitly added it (2026-11-02); this ADR records the scope.

## Context

Shops, cafés, second-hand and re-use businesses and small wholesalers keep
goods. They need to know what they have and where, to receive deliveries,
to pick orders, to count the stock at least once a year and to value it at
the year end (underlag för varulagret). Today NONOX has an article register
for invoicing only, and the closing checklist has a manual point "Varulagret
är inventerat". The year-end value is entered by hand on 1460 against a
4900 account.

## Decision

A module **Lager** (key `inventory`), off by default, with no dependency.
Turning it off hides it and keeps every row.

### Data model

- **Articles are extended, not duplicated.** The existing `articles` table
  gets additive columns (migration with `up()` only): SKU, EAN/GTIN (GS1
  mod-10 check digit validated, unique per company), scanner code, brand,
  model, long description, attributes (`properties`, a key/value list),
  colours and materials (lists), weight and dimensions, purchase price (öre),
  VAT treatment (`STANDARD` or `VMB`), default supplier and supplier article
  number, `is_stock_item`, reorder point and quantity, default location, and
  a manual net realisable value per unit with a note. The article form gets
  the tabs Enkel (today's fields) and Avancerad; invoicing and the API keep
  working unchanged. "Utgången" is the existing `is_active = false`.
- **`article_images`** (path, position, alt) exists for the next agents
  (upload and AI); nothing writes it yet.
- **Stock locations** (`stock_locations`): one tree per company, a type per
  node (WAREHOUSE → ZONE → SHELF → BIN; a level may be skipped), a short
  code, the printable `full_code` (the codes from the warehouse down,
  `HL-A-03-2`), a `sort_key` with numeric codes zero-padded so a pick list
  walks in order, an address on warehouses, and an active flag. The parent
  and level are chosen once (no dragging); a changed code is carried down. A
  location with goods (or goods below it) cannot be deactivated.
- **The stock ledger** (`stock_movements`) is append-only: model guards and a
  PostgreSQL trigger refuse UPDATE and DELETE, as for the closing entries.
  A row has the article, location, signed quantity, cost per unit (on
  movements in), type, date, source (morph), transfer group, reason, note
  and who. Types: RECEIPT (inleverans), PICK (plock/utleverans), TRANSFER
  (flytt, two rows with one group), COUNT (inventering), POS_SALE (kassa),
  RETURN, SCRAP (kassation).
- **Balances** (`stock_levels`) per article and location are kept by
  `StockLedger` in the same transaction as each movement, with the row
  locked. `php artisan inventory:verify` compares them with the ledger.
- **Quantities** are whole thousandths of the article's unit (`*_milli`,
  bigint): `1,5 kg` is 1500. No floats. Values are quantity × cost / 1000,
  rounded half up to öre.
- **Negative stock** is a company setting (default off).
- **Goods receipts** (`stock_receipts`, lines): standalone or linked to a
  supplier invoice; received in full or in parts; each receipt posts RECEIPT
  movements at the line's cost. No accounting change: the supplier invoice
  books the purchase as today.
- **Pick lists** (`pick_lists`, lines): from a customer order, an invoice or
  by hand. Each article is taken from its default location first, then from
  the other locations with stock, and the lines are sorted by `sort_key`.
  Completing posts PICK movements for what was picked; a PDF (dompdf) prints
  the list.
- **Stocktakes** (`stock_counts`, lines): a location subtree or all; the
  system quantity is captured at the start; differences need a reason and
  are posted as COUNT movements on completion.
- **Valuations** (`stock_valuations`, append-only): the year-end basis as
  it was, with every line, the method and the booked change.

### Valuation

- Årsredovisningslagen (1995:1554) 4 kap. 9 § sets lägsta värdets princip
  (the lower of cost and net realisable value on the balance day) and 4 kap.
  11 § allows first in, first out, weighted average prices or a similar
  method for interchangeable goods, never last in, first out (riksdagen.se,
  read 2026-11-02). NONOX offers **FIFO** and **weighted average**
  (perpetual) as a company setting, and applies lägsta värdets princip per
  article where a net realisable value is entered by hand.
- `TODO(legal-verify)`: whether BFNAR 2016:10 (K2) kap. 11 narrows the choice
  for K2 companies; the K2 text was not read.
- **No obsolescence schablon.** Skatteverket describes a tax rule that lets
  the stock be taken at 97 % of its total cost (skatteverket.se, Varulager,
  read 2026-11-02), but whether K2 allows a schablon in the accounts was not
  verified, so it is left out (`TODO(legal-verify)`).
- The valuation is built from the ledger, so it can be run for any past day.

### Year end

- An optional step under Lager → Rapporter → Bokslut values the stock on the
  last day, saves the valuation and books the difference to the balance on
  the stock account against the change account, on the last day, series A,
  before the year's result. Booked again only for a new difference.
- Accounts are settings: 1460 Lager av handelsvaror with **4960** Förändring
  av lager av handelsvaror by default (1410 with 4910 for raw materials). The
  owner suggested 4990; NONOX's K2 schedule and INK2R mapping (#89) put
  4980–4999 on Råvaror och förnödenheter and 4960–4969 on Handelsvaror, so
  4960 lands on the right line for goods for resale. `TODO(legal-verify)`.
- The closing checklist point becomes "Varulagret är inventerat och värderat"
  while Lager is on: done when a valuation is saved for the year, or when a
  person ticks it by hand as before. With Lager off it is unchanged.

### Permissions

`inventory:view`, `inventory:count` (handle goods: receive, pick, move,
count), `inventory:manage` (articles, locations, costs, valuation; includes
count), `inventory:settings`. Owner, admin and accountant get all; bookkeeper
all but settings; auditor view (and sees values through reports:view). A
module role **Lagerpersonal** (`stock_clerk`) gets view and count: no prices,
no settings. Booking the stock change also needs `fiscal-years:manage`.

### API

NONOX Connect: `GET /inventory/articles` (with stock per location, filters
gtin and number), `GET /inventory/locations`, `GET /inventory/stock-levels`
(`inventory:view`), `POST /inventory/receipts` (`inventory:manage`, optional
immediate receipt). Webhook `stock.changed` (needs `inventory:view` of the
webhook's creator, like every event) with ids, signed quantities and the
balance after; no costs or notes.

## Deferred (built by the next agents on this model)

- **Images and AI**: upload into `article_images`; AI descriptions fill
  `description`, `properties`, `colours`, `materials`.
- **Labels and a label studio**: print `full_code` for locations and
  `gtin`/`scanner_code`/`sku` for articles.
- **Point-of-sale sync (Zettle, Onslip)**: post sales with
  `StockLedger::post($company, null, MovementType::PosSale, $lines, $date,
  $sourceRecord)`; match articles on `gtin`, `scanner_code`, `sku` or
  `number`; listen to `StockChanged` to push stock back.
- **VMB invoicing**: `vat_treatment = VMB` is stored, but invoices do not yet
  calculate the margin or fill box 07. `TODO(legal-verify)`.
- **Variants**: no variant matrix; a size or colour is its own article with
  attributes. A later ADR may add a parent article.
- **Transfers between companies** are not supported (each company is its own
  ledger).
- **Stock accounting per movement** (perpetual booking of each receipt and
  pick) is not offered; the books change only at the year-end step.
