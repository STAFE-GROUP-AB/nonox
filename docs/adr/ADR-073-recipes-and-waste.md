# ADR-073: Recipes and waste for restaurants (recept och svinn)

Status: Proposed
Date: 2026-11-08
Relates to: ADR-013 (supplier invoices), ADR-017 (module framework), ADR-023 (inbox),
ADR-039 (inventory), ADR-040 (scanner app), ADR-042 (till integrations),
ADR-012 (payroll), ADR-071 (manufacturing: bills of materials), AGENTS.md §1.2, §1.3, §1.4, §5
Rule sheet: [`docs/specs/rules/recept-och-svinn.md`](../specs/rules/recept-och-svinn.md) (RS-R1 … RS-R18, L1 … L11)

## Context

The roadmap item `recept-och-svinn` promises restaurants three things: what a dish costs at
today's ingredient prices, the margin per dish, and a log of waste, with the till's daily
sales showing what was sold. Restaurants also ask the follow-up question every kitchen manager
asks once a week: *we sold this much, so how much should we have used, and where did the rest
go?*

What NONOX has today:

- **Lager** (ADR-039, `app/Modules/Inventory`): articles with `unit`, `purchase_price_ore`,
  `is_stock_item`, a manual net realisable value; locations; the append-only stock ledger
  (`stock_movements`) with types RECEIPT, PICK, TRANSFER, COUNT, POS_SALE, RETURN and
  **SCRAP** (with a 40-character `reason`); receipts that can be linked to a supplier invoice
  and carry `unit_cost_ore`; stock counts with a reason per difference; FIFO or weighted
  average valuation; and the year-end `BookStockValuation` voucher with **one** account pair
  per company (1460/4960 by default). Quantities are thousandths of the unit (`Quantity`).
  `RecordMovement` kind `scrap` already writes off goods by hand.
- **The scanner PWA** (ADR-040): five modes on a phone, an operation queue with a UUID per
  operation (exactly once), and no prices or costs ever sent to the phone.
- **Kassakopplingar** (ADR-042, `app/Modules/Pos`): sales from Zettle and Onslip arrive once
  each in `pos_sales` / `pos_sale_rows` (signed `quantity_milli`, `amount_ore`, `vat_rate`).
  A row is matched to an article; a matched article that is **not** a stock item is stored
  with `article_id` and status SKIPPED (`RecordPosSale`), so sales of dishes are already
  recorded against the dish's article. Daily sales become a draft voucher in series K.
- **Payroll** (ADR-012): pay codes `KOSTFORMAN_HEL`, `KOSTFORMAN_LUNCH`,
  `KOSTFORMAN_FRUKOST` priced from verified 2026 rules, and `FORMAN_EGENBETALNING`, the
  employee's own payment that lowers the benefit (`Allowances`). Planning (ADR-037) shows the
  pattern for feeding payroll from another module: attested rows become payroll inputs in one
  action (`SendToPayroll`).
- **Supplier invoices and the inbox** (ADR-013, ADR-023): invoice rows are booked on accounts
  and carry **no article**. Prices reach stock only through receipts.

What is missing: recipes, a recipe's cost and margin, consumption derived from sales, a waste
log with reasons, staff meals, and a report comparing theoretical with counted usage.

ADR-071 (Proposed, decided by Lead, built later) introduces **bills of materials** for a
joinery: an article made from components with a scrap percentage, several levels, versioned,
loops refused. A recipe is the same shape. This ADR has to decide whether to wait for it,
copy it, or build its core now.

## Decision

Proposed by the researcher for Lead's decision. Every legal statement below points to a rule
in the rule sheet; nothing that books a voucher or feeds payroll is built before the
accounting check of the fixtures and the L-questions it depends on.

### 1. Where it lives

A new module **Kök** (key `kitchen`, `app/Modules/Kitchen`), off by default, that **requires
`inventory`** and **uses `pos` and `payroll` when they are on** (it works without them, with
less). It is a module, not an inventory setting, because only food businesses need it, and its
role (Kökspersonal) and menu do not belong in a shop's Lager.

Swedish paths under `/kok`: `/kok/recept`, `/kok/recept/{recept}`, `/kok/meny` (dishes and
margins), `/kok/svinn`, `/kok/personalmat`, `/kok/avvikelser` (variance). Route names English
(`kitchen.recipes.index` …). The scanner gets a sixth tile **Svinn** while the module is on.

### 2. One structure engine, built now in `inventory`, used by both modules

The recipe and ADR-071's bill of materials are one engine: *an article (or a portion) made from
quantities of other articles, with a loss, a yield, versions and levels*. Building it twice
would give two cost calculations that disagree. Waiting for ADR-071 would block this item on a
larger one.

Decision: build the **engine** now, in the `inventory` module, with ADR-071's names, and let
each module own its screens:

- `bills_of_materials`: company, `kind` (RECIPE or BOM), the output article (the dish article
  that is sold, or a stock article such as a sauce made in batches), name, version, `valid_from`
  (date), active flag, yield (`yield_milli` with `yield_unit`: portions or the output article's
  unit). ADR-071's "batch size" **is** the yield.
- `bom_rows`: component article, net quantity in thousandths of the article's unit,
  `loss_bp`, position, note. ADR-071's **operation** rows are added by the manufacturing module
  later (a nullable `operation_id` and minutes); the engine treats a row without a component as
  an operation and ignores it when costing materials.
- A component may itself be the output article of another active structure (a sub-recipe,
  for example vitlökssmör or a fond). Exploding is recursive; a loop is refused on save.
- **Versions.** Changing an active structure creates a new version with a `valid_from`. Sales
  on a date use the version valid on that date, so last month's theoretical consumption does
  not change when the chef changes the recipe today. ADR-071 keeps "orders keep the version they
  were made from"; both rules hold with `valid_from` plus a version reference on the order.
- **The loss formula is fixed once for both modules**: a row states the net quantity that ends
  in the product and the loss as a share of the **gross** quantity:
  `gross = net × 10 000 / (10 000 − loss_bp)`, rounded half up to whole thousandths (RS-C2).
  ADR-071 says only "scrap percentage"; Lead confirms this formula for it too (Lead question LQ1).
- **Units.** Rows are stored in the component article's unit. The form accepts g for kg and
  ml/cl/dl for l with fixed factors; any other conversion (a bottle of 70 cl, a box of 12) is a
  per-article conversion in `article_unit_conversions` (article, unit, `factor_milli`), part of
  the engine (RS-C1).

This changes ADR-071 §1 ("Bills of materials … new `manufacturing`"): the tables and the
explosion and costing service move to `inventory`; the manufacturing module keeps operations,
production orders, the terminal and its screens. Lead amends ADR-071 or rejects this section
(LQ2). If rejected, recipes get their own tables in `kitchen` and the two engines must be
reconciled when ADR-071 is built; the ADR says so plainly rather than pretending they are
compatible.

### 3. Recipes and dishes

- A **dish** (rätt) is an ordinary article, `is_stock_item = false`, with its menu price (stored
  excluding VAT, as all article prices) and its VAT rate, linked to the till product as today
  (ADR-042). Its recipe is a RECIPE structure whose output is the dish and whose yield is in
  portions.
- A **sub-recipe** (grundrecept: sås, fond, deg) outputs a stock article in kg or l. It can be
  used in other recipes and, when it is made in batches and stored, counted like any article.
- The recipe page shows ingredients with net quantity, loss and gross quantity, the method as
  free text, allergens as free text tags (no legal claim; see §11), and the cost columns for
  users who may see costs.

### 4. Portion cost and margin

- Cost per row, batch and portion follow RS-C3 to RS-C5 exactly (fixture F-RS-2).
- **Which unit cost.** By default the component's current valuation cost under the company's
  method (FIFO or weighted average, ADR-039), the same source ADR-071 §6 uses. The page also
  shows the **latest purchase cost** (last receipt line) and marks a row when the two differ by
  more than 5 %. This is a management figure and the page says so: "Kalkyl – inte
  lagervärdering" (RS-R4). Components without any cost show "saknar pris" and the portion
  cost is shown as incomplete, never as zero.
- **Margin per dish** against the dish's net menu price: contribution and food cost % (RS-C6).
  The menu page lists every dish with portion cost, net price, contribution, food cost %, the
  number sold in a chosen period (from the till) and the contribution earned, sortable. A
  second price column shows the **realised** average net price from the till rows
  (`amount_ore / quantity`) when the till is connected, because discounts and happy hours make
  it differ from the list price.
- A **target food cost %** per company (setting, no default value) colours dishes above it.
  NONOX never changes a price.

### 5. Theoretical consumption from the till

- For a period, every `pos_sale_rows` row linked to a dish article is exploded through the
  dish's recipe version valid on the sale's business date into ingredient quantities (RS-C7).
  Returns (negative quantities) reduce. Rows of a dish with no valid recipe are listed under
  "Sålt utan recept" with the quantity, never guessed (F-RS-5).
- The source is the till's API rows, not the Z-report. The report says "enligt kassans
  försäljningsrader" (RS-R17). Modifiers ("extra bacon", "utan lök") are not read in v1 and
  are listed as a known source of variance.
- **Drawing ingredients from stock (optional setting, off by default).** With
  `draw_on_sale` on, a nightly command `kitchen:consume` (after `pos:reconcile-sales` at 03:40)
  posts the exploded quantities as stock movements of a new type **`RECIPE_SALE`** ("Såld via
  recept"), out, from the till connection's stock location, one row per ingredient and day,
  source = a `kitchen_consumption_runs` record. Exactly once: each sale row is recorded in
  `kitchen_sale_consumptions` (unique on `pos_sale_row_id`) inside the same transaction; a sale
  that arrives late is consumed in the next run. If negative stock is off and an ingredient
  would go below zero, that day's rows for the ingredient wait under "Väntar på lager" (as
  unmatched till rows do in ADR-042) until a person resolves it. With the setting off, stock
  changes only through receipts, waste, staff meals and counts, and the variance report still
  works.

### 6. Waste registration

- **Where.** The scanner's new tile **Svinn** (phone, offline queue, UUID per operation, no
  costs shown, ADR-040) and the page `/kok/svinn`. Both call one action, `RegisterWaste`.
- **What is registered.** Either an **ingredient** (article, quantity, location) or a **dish or
  sub-recipe** in portions or kg, which the action explodes through the recipe into ingredient
  quantities. Each ingredient quantity becomes a `SCRAP` movement through `StockLedger::post`,
  with the reason code in `reason` and the waste entry as source. No new movement type is
  needed for waste.
- **Reason codes** (stored as codes, Swedish labels):
  `UTGANGET` Utgånget datum, `FORDARVAT` Fördärvat (kylfel, mögel, skadat),
  `BEREDNING` Beredningssvinn utöver receptet, `OVERPRODUKTION` Lagat men inte sålt,
  `FEL` Fellagat eller felbeställt, `SKANKT` Skänkt (with the recipient organisation's name),
  `OVRIGT` Övrigt (note required).
- **Plate waste** (tallrikssvinn) is food already sold. It is recorded as a measurement in kg
  with no stock movement (`kitchen_waste_measurements`), because its ingredients left stock
  with the sale. The page says so.
- **The waste log is append-only**, like the stock ledger: a wrong entry is corrected by a new
  entry (a RETURN movement back in, with a reference). It is kept as long as the stock
  movements and goes into the archive package (RS-R10, L5).
- The screen tells the user that waste means "goods that have left the stock" (RS-R5): goods
  waiting to be thrown away are registered when they are thrown away, not before.
- **Donations** (`SKANKT`) show the cost value and Skatteverket's position as summarised in
  RS-R11, with `TODO(legal-verify)`. NONOX books and proposes no VAT for them.

### 7. Staff meals (personalmat)

- `/kok/personalmat` and the scanner record staff meals: date, meal (frukost, lunch, middag),
  the dish or "valfri rätt" (an average portion cost the company sets), quantity, and what was
  paid per meal. Each entry posts a stock movement of a new type **`INTERNAL_USE`** ("Eget
  bruk"), out, exploded through the recipe, with reason `PERSONALMAT`, `REPRESENTATION` or
  `AGARE` (owner of an EF).
- **Without payroll**: entries are counts only, with no person. That is enough for the stock
  and the variance report and processes no personal data.
- **With payroll on**: each entry names the employee. A monthly action, "Skicka kostförmåner
  till lönen", in the style of Planning's `SendToPayroll`, creates payroll inputs per employee:
  `KOSTFORMAN_LUNCH`/`KOSTFORMAN_FRUKOST` (or `KOSTFORMAN_HEL` when there are at least three
  meals that day, RS-R12) with quantities, and `FORMAN_EGENBETALNING` with what the employee
  paid, **capped per meal at that meal's benefit value**. The cap matters: payroll's
  `Allowances::ownPayment` sets the payment against all of the employee's benefits, so an
  uncapped overpayment for lunches would lower an unrelated car benefit; IL 61:4 reduces each
  benefit by what was paid for it (fixture F-RS-4, employee C). Representation meals are
  tax-free (SKV kost) and are not sent.
- **VAT on staff meals is not booked** (RS-R13, L7). The page shows the underlag per month:
  free meals, subsidised meals, what staff paid, and the raw-material cost, with the note that
  the VAT base for free meals under ML 8:6 is more than the raw-material cost.
- **Owner's meals in an EF** produce, on request, a **draft** voucher for the uttag with the
  BAS 3401–3404 accounts proposed and `TODO(legal-verify)` (RS-R16, L9). Never posted by NONOX.
- Staff meals sold at a staff price through the till are sales and arrive from the till like
  any sale; they are not entered again here (RS-R17).

### 8. Variance report (avvikelser)

- Between two completed stock counts that include an ingredient, per ingredient (RS-C8):
  opening count, receipts, theoretical consumption from sales, waste by reason, staff meals,
  other recorded outs, closing count, **unexplained variance** in quantity, value (at the
  closing valuation cost) and as a share of theoretical consumption. Fixture F-RS-3.
- With `draw_on_sale` on, the same figure is the stock count's COUNT difference, and the
  report shows both and that they agree.
- Sorted by value. Grouped by stock class (§9). Drill-down to the sales rows, waste entries and
  count lines behind each figure. A fixed number of queries regardless of the number of
  ingredients and sales rows (query-count test).
- A count period that a later waste entry falls into shows that entry as "registrerad efter
  inventeringen" (F-RS-5).

### 9. The books

- **No voucher per waste entry, per staff meal or per day of consumption.** Waste and internal
  use are not affärshändelser with the outside world (RS-R10); the purchase is already a cost;
  the books meet the stock at the year-end valuation, as in ADR-039. Interim income statements
  show waste inside purchases and the variance report is the management view of it (RS-R9).
- **Account pairs per stock class.** Food used in cooking is Råvaror och förnödenheter
  (1410/4910), goods sold on unchanged are Handelsvaror (1460/4960) (RS-R1). ADR-039's
  `BookStockValuation` books one pair per company, and NONOX has no article groups. This ADR
  adds a **stock class** on articles (`stock_class`: `RAW_MATERIAL` or `GOODS_FOR_RESALE`, an
  additive column defaulting from the company setting) with one account pair per class in the
  Lager settings; `BookStockValuation` books one pair per class, still in one voucher. That change touches accounting behaviour: it goes through the
  accounting check, updates `docs/systemdokumentation/README.md`, and is a separate build step.
  Drinks served at the table are a human choice (L1); NONOX offers both and decides neither.
- **Sub-recipes on hand** at the balance day are valued at ingredient cost, and the valuation
  says "Exklusive arbete" with `TODO(legal-verify)` (RS-R8, L4).
- **The 97 % rule and fixed values** are not applied by NONOX in this ADR (RS-R5, RS-R6;
  owner question O3).
- **Purchase accounts.** BAS 2026 separates raw materials (group 43, 4310) from goods for
  resale (group 40, 4010 "Inköp av handelsvaror i Sverige"). NONOX's BAS 2026 templates still
  name 4010 "Inköp material och varor" and lack 4310 and 1410/4910. A restaurant onboarding
  needs them; adding them to the templates is a small separate change outside this module.

### 10. Ingredient prices from supplier invoices

Supplier invoice rows carry no article, so a price reaches a recipe only through a goods
receipt with a unit cost (ADR-039). Two steps, the second optional:

1. **v1:** the existing receipt linked to a supplier invoice. The recipe shows the date and
   receipt each price comes from.
2. **Later:** "Ta emot från faktura": when the inbox has interpreted a supplier invoice
   (ADR-023), NONOX proposes receipt lines by matching the supplier's article numbers
   (`supplier_article_number`, `default_supplier_id`), with the person confirming each line.
   AI is optional; without it the person picks articles. Discounts and freight on the invoice
   are spread only when the person chooses to (RS-R4: discounts lower cost).

### 11. Permissions and roles

New permissions, existing only while the module is on:

- `kitchen:view`: recipes, dishes and the waste and staff-meal logs, **without costs**;
- `kitchen:register`: register waste and staff meals (phone and page);
- `kitchen:manage`: create and change recipes and dishes, see costs and margins, the variance
  report, the module's settings.

Owner, admin and accountant get all three; bookkeeper all three; auditor `kitchen:view` and
sees costs through `reports:view`; approver none. A module role **Kökspersonal**
(`kitchen_staff`) gets `kitchen:view` and `kitchen:register`, and no prices anywhere (as
Lagerpersonal in ADR-039 and the scanner in ADR-040). Naming an employee on a staff meal needs
`kitchen:register`; the per-person monthly list and "Skicka kostförmåner till lönen" need
`payroll:manage` as well. Changing stock settings (`draw_on_sale`) needs `inventory:settings`.

Allergens are free-text tags for the kitchen's own use; NONOX makes no claim about allergen
information to guests, and the page says so (food information law is out of scope).

### 12. Personal data

- Waste entries and stock movements carry the registering user's id, as stock movements do
  today. No new category.
- Staff meals per employee are personal data, processed only with payroll on, because the
  employer reports the benefit (RS-R18). They go into `docs/gdpr/registerforteckning.md` and
  `lagringstider.md` when built, with retention following payroll's (L11). The audit log holds
  ids and codes only, never names.
- Recipes, prices, sales rows and the variance report carry no personal data.

### 13. Tenancy and integrity

Every new table has `company_id` and `BelongsToCompany`, and a tenancy test (another company's
id answers 404). `kitchen:consume` and the payroll hand-over run per company with
`CurrentCompany::run()`. Stock movements are only ever written through `StockLedger`, so the
ledger stays append-only and `inventory:verify` still holds.

### 14. Out of scope

- Menu engineering beyond margin and volume (no star/plowhorse matrix), price suggestions.
- Reading till modifiers and add-ons; recipes per modifier.
- Nutrition values and legally binding allergen information to guests.
- Purchase planning from forecasts (ADR-071 purchase orders cover ordering when built).
- Production planning and labour minutes in recipes (ADR-071's operations).
- Booking VAT on staff meals, donations or representation with own food until L6–L8 are
  answered.
- Food-waste reporting to authorities (no duty found for restaurants in the sources read).

## Consequences

- A restaurant gets dish costs, margins, a waste log on the phone and a weekly variance
  report from data NONOX already holds (till rows, receipts, counts), without a new voucher type.
- `inventory` gains the structure engine (`bills_of_materials`, `bom_rows`,
  `article_unit_conversions`) and two movement types (`RECIPE_SALE`, `INTERNAL_USE`). The
  manufacturing module of ADR-071 builds on the same engine; ADR-071 §1 and §3 need an
  amendment (LQ2) and the loss formula is shared (LQ1).
- `BookStockValuation` books per stock class (§9). That is an accounting change with its own
  accounting check and systemdokumentation update.
- The payroll hand-over reuses existing pay codes; no payroll engine change, but the cap per
  meal on own payment is the kitchen module's responsibility, with a test.
- Public pages: the roadmap entry stays "Planerat" until built; the landing feature, the
  comparison row (`docs/migration/jamforelse.json`) and the manual follow when it ships.
- Two corrections to earlier ADRs are recorded in the rule sheet (K2's inventory chapter is 12,
  not 11; K2 12.5 allows the 97 % rule and 12.10 requires direct labour in own-manufactured
  goods). ADR-039 and ADR-071 get addenda when Lead accepts this ADR.

## Build steps (each: failing tests first, then code, then docs; cold review; Lead merges)

1. **Engine in `inventory`.** Migrations (`up()` only) for `bills_of_materials`, `bom_rows`,
  `article_unit_conversions`; the explosion and costing service. Tests: F-RS-1 and F-RS-2 to
  the öre, multi-level, loop refused, loss 10 000 bp refused, yield 0 refused, version chosen by
  date, unit conversion g→kg and fl→cl. Tenancy tests.
2. **Kitchen module shell.** `KitchenModule` (requires inventory), permissions, role, menu,
  routes under `/kok`. Policy tests per role; Kökspersonal sees no cost in any response.
3. **Recipes and dishes UI.** Recipe page, sub-recipes, menu page with margins; N+1 query-count
  test with 5 and 50 dishes.
4. **Waste.** `RegisterWaste` (ingredient or exploded dish), reason codes, append-only log,
  correction by new entry, plate-waste measurement; scanner tile with the operation queue.
  Tests: exactly once per UUID, SCRAP movements with reason and source, no cost in the scanner
  payload, F-RS-3's waste figure.
5. **Theoretical consumption and variance.** From `pos_sale_rows`; "Sålt utan recept";
  variance per F-RS-3 to the milli and öre; query-count test.
6. **Draw on sale (setting).** `RECIPE_SALE`, `kitchen:consume`, exactly once per sale row,
  "Väntar på lager". Tests: replay, late sale, negative stock off.
7. **Staff meals.** `INTERNAL_USE`; counts without payroll; with payroll the monthly hand-over
  with F-RS-4 (including the per-meal cap); representation not sent. Waits for the accountant's
  check of F-RS-4.
8. **Year-end per stock class.** `BookStockValuation` change, systemdokumentation, accounting
  check, ADR-039 addendum. Waits for L1.
9. **Docs and public pages.** Manual articles (`docs/manual/sv/…`), changelog, landing feature
  card with a real screenshot from a `nonox_demo_*` database, comparison row, GDPR register.

## Owner questions

- **O1** Module name in the menu: "Kök" or "Recept och svinn"?
- **O2** `draw_on_sale` default: off (proposed; counts drive the variance) or on (live stock of
  ingredients, reorder points work, needs good recipes from day one)?
- **O3** Should NONOX offer the 97 % rule (K2 12.5) in the year-end valuation for companies that
  choose it, now that the K2 text is verified? It is a company choice with consistency rules,
  not a default.
- **O4** Which unit cost should recipes show first: the valuation cost (proposed, same as
  ADR-071) or the latest purchase cost?
- **O5** Staff meals with payroll on: send benefits automatically each month after a person
  confirms (proposed), or only list them for the payroll administrator to enter?
- **O6** The pilot: which restaurant tests it (name and data stay out of the repository)?

## Lead questions

- **LQ1** Confirm the shared loss formula (gross = net × 10 000 / (10 000 − loss_bp)) for
  ADR-071's scrap percentage too.
- **LQ2** Accept moving the structure engine from `manufacturing` to `inventory` (amending
  ADR-071 §1 and §3), or keep recipes in their own tables and reconcile later.

## Addendum 2026-11-08: Lead decisions

Decided by Lead 2026-11-08 under the owner's mandate, open for the consultant's review.

- **LQ1:** accepted. One loss formula for recipes and for ADR-071's scrap percentage:
  gross = net × 10 000 / (10 000 − loss_bp).
- **LQ2:** accepted. The structure engine is built now in inventory, with ADR-071's table
  names and versions dated by `valid_from`. ADR-071 is amended to reuse it.
- **O1:** the module and menu are called "Kök", with paths under `/kok`.
- **O2:** deducting ingredients on sale is off by default.
- **O3:** the 97 % rule is offered only to K2 companies, off by default. The page states the
  K2 12.18 condition: discarded goods must be removed physically, not left at zero value.
- **O4:** valuation cost first, the same figure as the books. The latest purchase cost is
  shown beside it for comparison.
- **O5:** staff-meal benefits go to payroll as proposed lines that the payroll
  administrator approves in the run. They are never added without that approval.
- **O6:** the pilot restaurant is the owner's choice.

The rule sheet gets an independent quote check before the module is merged. The builder
corrects the K2 chapter reference in `ValuationMethod` (chapter 12, not 11) and adds the
missing BAS 2026 accounts (1410, 4910, 4310) to the templates, if the sheet's sources
support them.

## Addendum: as built (2026-11-08)

Built on branch `feat/kitchen-recipes-waste`, for Lead's review. Where the build differs
from the decision above, it says so.

- **Engine (step 1)** in `app/Modules/Inventory/Structures` (`StructureBook`,
  `StructureWriter`, `UnitCosts`, `StructureCost`) and `Domain/StructureMath`,
  `StructureUnits`, `StructureKind`; tables `bills_of_materials`, `bom_rows`,
  `article_unit_conversions` as decided, plus `entered_quantity`/`entered_unit` on a row
  (what the person typed). A version is never changed, only switched off; every save is a
  new version, and on equal `valid_from` the higher version wins. Explosion is recursive to
  the components that are not themselves made from a structure on the date (a stocked
  sub-recipe is exploded to its ingredients, not drawn as the sub-recipe article). Loops are
  checked against every active version on save, with a depth limit as a second guard.
  Fixtures F-RS-1 and F-RS-2 pass to the öre. Unit cost order: valuation cost, else the latest
  receipt cost, else the article's purchase price, else "saknar pris" (incomplete).
- **Stock classes (step 8)**: `articles.stock_class` (null = the company's
  `default_stock_class`, which defaults to GOODS_FOR_RESALE). The original
  `stock_account`/`change_account` settings are the GOODS_FOR_RESALE pair; new settings
  `raw_material_stock_account` (1410) and `raw_material_change_account` (4910).
  `BookStockValuation::plan()` groups the valuation per stock account; a class is included
  when it has stock, is the default, or an article carries it, so an existing company books
  exactly as before (tested). The split is saved in `stock_valuations.classes`.
- **97 % rule (Lead O3)**: setting `rule_97`, shown and applied only for K2 companies, off by
  default, with the K2 12.18 condition text; 97 % of each class's cost rounded half up;
  refused when any article is written down to net realisable value.
- **Module (step 2)** `app/Modules/Kitchen`, key `kitchen`, depends on `inventory`. Besides
  the decided roles, the payroll administrator (`payroll_admin`) gets `kitchen:view` so that
  the staff-meal hand-over (`kitchen:view` + `payroll:manage`) is reachable for that role.
  Extra route `/kok/installningar` for the module settings; `/kok` redirects to
  `/kok/recept`. New recipe at `/kok/recept/nytt`; a recipe's URL uses the output article id.
- **Scanner (step 4)**: Lager got a small extension point (`Scanner\ScannerExtension`,
  `ScannerExtensions`) so Kök adds the Svinn tile and the operation kind `waste` without
  Lager depending on Kök. Kökspersonal may open Skanna through the extension's permission
  without `inventory:view`; receipts, pick lists and counts are sent to the phone only to
  those who handle goods. Staff meals are registered on the page only, not in the scanner.
- **Waste**: correction reverses a whole entry (RETURN at today's cost per unit) and is
  itself recorded as an entry with the opposite quantity; one correction per entry.
- **Draw on sale (step 6)**: off by default; `draw_on_sale_from` is set to the day it is
  switched on, and older sales are never drawn. A waiting ingredient (`kitchen_consumption_waits`)
  is per ingredient and day; the till rows are recorded as drawn in the same run (unique per
  row), and the waiting quantity is retried nightly or resolved by a person (post now or
  leave to the count). Command `kitchen:consume` at 04:10.
- **Variance (step 5)**: the two counts must be of the same location; movements are those
  of that location subtree; theoretical consumption is from all the company's till rows
  (dish articles, not stocked); COUNT movements are left out. F-RS-3 passes to the milli and
  öre; F-RS-5 cases are tested. With draw on sale, the closing count's difference is shown
  beside the variance.
- **Staff meals (step 7)**: proposals live in `kitchen_payroll_proposals`; approval calls
  `PayrollRuns::addInput` (source MANUAL, note "Personalmat … (Kök)"), so payroll's own
  checks apply. Full day = at least one breakfast and two lunches or dinners on the same day
  (TODO(legal-verify)). The own payment is capped per meal (per full day at the day value);
  the excess is shown, not sent. A test runs a payroll calculation and shows an unrelated
  benefit is not reduced (F-RS-4, employee C). The staff price entered is the amount deducted
  from pay; meals paid at the till are sales and are not entered.
- **Not built**: the owner's (EF) draft uttag voucher (§7, RS-R16, L9); "Ta emot från faktura"
  (§10 step 2, later by decision); the landing feature card with a screenshot and the
  manual screenshots (need a `nonox_demo_*` run). BAS 2026 templates got 1410 and 4910; 4310
  was not added because the rule sheet does not quote its BAS name, and 4010 keeps its
  present name.
