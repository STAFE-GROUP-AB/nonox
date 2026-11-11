# ADR-071: Manufacturing, purchase orders and customer ordering

Status: Proposed
Date: 2026-11-08
Relates to: ADR-011 (invoicing, quotes and orders), ADR-013 (supplier invoices),
ADR-017 (module framework), ADR-022 (staff ledger kiosk), ADR-023 (inbox),
ADR-037 (staff scheduling), ADR-039 (inventory), ADR-040 (scanner app),
ADR-043 (CRM and GDPR), ADR-046 (quote templates), AGENTS.md §1.2, §1.3, §1.4, §5

## Context

A joinery that makes tables and chests of drawers asked for four things that its current
system does not do:

1. When an order for ten tables is entered, check whether the parts for all ten are in
   stock, and offer to create purchase orders for what is missing, grouped per supplier,
   to print or send.
2. Change the bill of materials for one order (another top, two extra brackets) without
   creating a new article structure. Today every variant becomes a new structure.
3. A tablet in the workshop where a worker taps their picture, enters their code, picks an
   open production order and records hours and minutes worked on it. The data should show
   bottlenecks and how long each product really takes.
4. Business customers place orders themselves, or send a specification that becomes an
   order draft, and get a confirmation and an estimated delivery date that the system
   learns from history.

The owner added a fifth: when a bill of materials is chosen on a quote or an order, the
cost of materials **and labour** should show at once, so the margin per product is known
when the order is taken, and the labour figure should improve as recorded hours come in.

What NONOX has today:

- Quotes and orders as `SalesDocument` (`kind` QUOTE or ORDER), converted to invoices.
- The inventory module (ADR-039): articles with `purchase_price_ore`,
  `default_supplier_id`, `supplier_article_number`, reorder point and quantity, locations,
  movements, receipts, counts, FIFO or weighted average valuation, and the year-end stock
  valuation voucher (`BookStockValuation`, 1460 against 4960).
- Suppliers and supplier invoices (ADR-013), the inbox with document interpretation
  (ADR-023), the staff ledger kiosk PWA with picture and code (ADR-022), and staff
  scheduling with staffed hours (ADR-037).

What is missing: purchase orders, bills of materials, production orders, a workshop
terminal, labour costing, and ordering by the customer.

## Decision

Decided by Lead 2026-11-08 under the owner's mandate, open for the consultant's review.

### 1. Where each part lives

| Part | Module | Why |
|---|---|---|
| Purchase orders | `inventory` (existing) | A shop needs them as much as a joinery. |
| Bills of materials, operations, production orders, costing | new `manufacturing`, requires `inventory` | Only manufacturing companies need it. |
| Workshop terminal | `manufacturing`, its own setting (off by default) | It only makes sense with production orders. |
| Per-person analysis | `manufacturing`, its own setting (off by default) | Employee monitoring; see §7. |
| Customer ordering | the customer portal (roadmap `kundportal`), its own setting | The portal is the customer's login; ordering is one thing it can do. |

Every module and setting is switched on and off per company (ADR-017). Switching off hides
the screens and keeps the data.

New permissions, which exist only while their module is on:

- `purchase-orders:view`, `purchase-orders:manage`, `purchase-orders:send`
- `manufacturing:view`, `manufacturing:manage`, `production:report` (the terminal),
  `production:analyse`
- `customer-orders:review`

Owner and admin get all. Accountant gets view and analyse. Bookkeeper gets purchase orders
view and manage. Approver gets `purchase-orders:send` when the company requires approval of
purchase orders above an amount it chooses. Auditor gets the view permissions. A terminal
user is a staff-ledger-style person, not a NONOX user, and can only report time.

### 2. Purchase orders

- `purchase_orders`: company, number (own number series), supplier, status, order date,
  requested delivery date, our reference, delivery address, notes, created by, sent at,
  sent to (address), and the source (manual, reorder point, production order).
- `purchase_order_rows`: article, supplier article number, quantity in milli-units, unit,
  price in öre (from `purchase_price_ore`, editable), expected date, received quantity.
- Status: DRAFT → SENT → PARTIALLY_RECEIVED → RECEIVED, or CANCELLED. A sent order is
  changed only by a new version that is sent again; the earlier version is kept.
- Sending: a PDF in the company's design (ADR-030 branding), printed, or e-mailed through the
  app's mail with the company as sender and reply-to. The user always sees the PDF and the
  recipient before it goes. Peppol Order is a later step (ADR-050).
- Receiving uses the existing `ReceiveStock`; a receipt can pick a purchase order and ticks
  off its rows. Receiving does not book a voucher, as today.
- When the supplier invoice arrives, NONOX shows the purchase order and the receipt next to
  it and flags differences in quantity or price. It does not approve automatically.
- Purchase orders are commitments, not bookkeeping. They create no voucher.

### 3. Bills of materials and operations

- `bills_of_materials`: the article it makes, a name, a version number, active flag, and
  the batch size it describes (normally 1).
- `bom_rows`: either a **component** (an article, quantity, unit, scrap percentage) or an
  **operation** (a work step from `operations`, standard minutes). A component may itself
  have a bill of materials (several levels). A loop is refused.
- `operations` (arbetssteg): name such as Kapning, Hyvling, Montering, Slipning, Lackering,
  the hourly cost in öre, and an optional capacity in staffed hours per day.
- A bill of materials is versioned. Changing an active one creates a new version; orders keep
  the version they were made from.

### 4. The order's own copy

Choosing a bill of materials on a quote row or an order row copies it into the row
(`sales_document_row_structures`). The copy is edited inline on the row: change a quantity,
swap a component, add or remove a line, change minutes. The template is untouched, and no
new bill of materials is created. "Spara som ny stycklista" saves the copy as a new
bill of materials or a new version when the user wants to reuse it. The copy follows the
quote into the order and into the production order.

### 5. Availability and purchase suggestions

For an order of N units the structure is exploded through every level into the quantity
needed per component, with scrap. For each component:

```
free now      = on hand − reserved by other open production orders
coming        = open purchase order rows, with expected date
short         = max(0, needed − free now − coming)
```

Reservations follow production orders in their planned start order. The page shows per
component: needed, on hand, reserved, coming (with date), short, and whether the order can
start now, on a date, or not until something is ordered.

"Skapa inköpsordrar för det som saknas" groups the short components per default supplier,
rounds to the supplier's minimum and pack quantity when known, and creates DRAFT purchase
orders. A component without a supplier is listed for the user to choose one. Nothing is
sent without the user confirming each order (§2).

### 6. Costing on quotes and orders

A row with a structure shows, internally and never on the customer's PDF:

- **material**: each component's quantity × its cost: the current valuation price for stock
  items (the method the company chose, FIFO or weighted average, ADR-039), or the purchase
  price for others;
- **labour**: each operation's minutes × the operation's hourly cost;
- **overhead**: an optional percentage per company, off by default;
- **cost per unit, contribution and margin** against the row's net price, and the same for
  the whole document.

The hourly cost of an operation is set by the company. NONOX helps with a suggestion from
payroll when the payroll module is on: total staff cost (gross pay, employer contributions,
vacation pay accrual) divided by worked hours, as one company-wide figure over the last
twelve months. Individual salaries are never shown or used per person in costing.

**Learning.** Each production order gathers the minutes reported in the terminal per
operation. The order page shows calculated against actual as they come in, and the actual
labour cost so far. After at least five finished orders of the same article, NONOX suggests
new standard minutes per operation (the median of the finished orders). The user accepts or
ignores the suggestion; standards never change by themselves. A quote made afterwards uses
the new standard.

### 7. Workshop terminal

- A PWA on a shared tablet, built like the staff ledger kiosk (ADR-022): pictures of the
  people, a personal code, nothing else on the screen. The device is paired to the company
  with a device token, as the kiosk is.
- After the code: the open production orders (started or released, not finished), each with
  product, customer reference and quantity. The worker picks one, picks the operation, and
  enters hours and minutes for today. A timer mode (start/stop) is a later option.
- Time entries are append-only, corrected by a new entry, with person, order, operation,
  date, minutes, device and entered-at.
- The terminal shows each person only their own entries.
- The time is production time. It is not the staff ledger's attendance and not payroll time,
  and the page says so. Linking it to payroll is out of scope.

**Analysis.**

- Per product and operation (on as soon as the terminal is on): calculated against actual
  minutes, spread, trend, and the bottleneck operations, which have the most queued hours
  against their capacity or the largest overruns. Orders waiting for material are separated
  from orders waiting for work.
- Per person (setting `per_person_analysis`, off by default): minutes per product and
  operation per person against the median. Before it can be switched on, the owner confirms
  three things: that staff have been informed, that a legitimate-interest assessment is
  recorded, and that any duty to negotiate has been handled. Only `production:analyse`
  sees it.

`TODO(legal-verify)`: GDPR art. 6.1 f and 13 for the per-person analysis. Also whether
MBL 11 § or 19 § applies before introducing it, and what the information to staff must
contain. These questions go to the lawyer before the setting can be built.

### 8. Production orders and the books

- `production_orders`: company, number, article, quantity, the structure copy, the sales
  order row it serves (optional; stock production has none), planned start, due date,
  status DRAFT → RELEASED → STARTED → FINISHED, or CANCELLED.
- Releasing reserves components. Starting may issue components to production. Finishing
  issues what remains and receives the finished goods. Two new movement types are added to
  `MovementType`:
  - `PRODUCTION_ISSUE`: out, at the components' valuation cost;
  - `PRODUCTION_RECEIPT`: in, at the order's manufacturing cost.
- The manufacturing cost of a finished order is the components' cost plus the direct labour
  (actual minutes × the operation's hourly cost). Overhead is included only if the company
  chooses it and the framework allows it.
- No voucher per production order. Materials and wages are already booked when bought and
  paid. As today, the books meet the stock at the year-end valuation (`BookStockValuation`).
  That valuation includes finished goods at manufacturing cost. It also includes work in
  progress: components issued plus labour reported on unfinished orders.

`TODO(legal-verify)` for the accounting check:

- whether K2 (BFNAR 2016:10 ch. 12, not 11) and K3 (ch. 13) allow, require or forbid direct labour
  and indirect costs in the cost of own-manufactured goods;
- the BAS accounts for finished goods and work in progress (1450/1440) and their change
  accounts (4970/4940) beside 1460/4960;
- whether ÅRL 4:9 allows the operation's standard hourly cost as an approximation of the
  actual cost.

### 9. Customer ordering

- The customer portal gives each business customer's contacts a login with an e-mail code,
  like every NONOX login. It is a separate guard, not a NONOX user.
- **Ordering from the assortment:** the company chooses which articles each customer sees and
  at which prices (the customer's price list or agreement). The customer fills a cart, sets
  a requested date and a reference, and sends.
- **Ordering by specification:** the customer uploads a PDF, drawing or text. It goes to the
  inbox (ADR-023). The interpretation produces an order draft with matched articles and
  structures, and marks what it could not match. AI is optional. With AI off, the draft is
  empty and the document is attached.
- Both become a `SalesDocument` ORDER with a new status RECEIVED ("Inkommen, ej granskad"),
  shown in a queue to users with `customer-orders:review`. The reviewer corrects, prices,
  checks material, and saves it as an OPEN order. Only then does the customer get the
  confirmation e-mail with the order number. A refusal sends a message with the reason.
- The customer sees their orders and statuses, the confirmed delivery date, and, once the
  portal's invoice part is built, their invoices.

### 10. Delivery date estimate

For a confirmed order NONOX estimates a delivery date from:

- **material**: the latest expected date of the purchase orders the order waits for. For
  components still to be ordered, the supplier's median lead time from sent to received over
  the last twelve months;
- **work**: the order's operation minutes against each operation's capacity, behind the
  production orders already queued;
- **history**: the median and 80th percentile of the days from release to finished for the
  same article.

The estimate is shown as a range ("preliminärt v. 47–48") with the words "beräknat, inte
bekräftat". Until there are five finished orders of the article, it is shown only to the
company, not to the customer. The company confirms a delivery date before the customer sees
one. The customer is never shown an estimate the company has not confirmed.

### 11. Out of scope

- detailed scheduling per machine and Gantt planning;
- MRP over a forecast period (only for confirmed orders and reorder points);
- quality control and serial number tracing;
- subcontracting operations to another company;
- variant configurators (a customer composing a product from options in the portal);
- payroll from terminal time.

## Consequences

- One module, `manufacturing`, holds the joinery-specific parts. Purchase orders strengthen
  `inventory` for every company that holds stock.
- `SalesDocument` gains one status (RECEIVED) and a structure copy per row. Quotes and orders
  without structures are unchanged.
- `MovementType` gains two cases, and the year-end stock valuation must value finished goods
  and work in progress. That change goes through the accounting check before it is built.
- Two new personal-data categories, production time per person and customer portal logins,
  go into `docs/gdpr/registerforteckning.md` and `lagringstider.md` when built.
- Build order: purchase orders → bills of materials, structure copy and costing → production
  orders and stock movements → terminal → customer ordering → delivery estimate. Each step
  follows the swarm process: rule sheet and accounting check where accounting is touched,
  fixtures, builder with tests first, cold reviewer, Lead merges.
- The first pilot is a joinery. Its name and data never go into the repository.

## Addendum 2026-11-08: shared structure engine and a first answer

- ADR-073 (recipes and waste) builds the structure engine first, in the inventory module,
  using this ADR's table names (`bills_of_materials`, `bom_rows`) with versions dated by
  `valid_from`. §3 here is built on that engine and does not create its own.
  The scrap percentage uses ADR-073's loss formula.
- K2 is BFNAR 2016:10 chapter 12, not 11. Point 12.10 says direct labour and employer
  contributions must be included in the cost of own-manufactured goods, which partly
  answers the first `TODO(legal-verify)` in §8. The rest of that question stays open.

## Note 2026-11-09: backdating guard for BOM saves (from ADR-073 review)

The structure engine (`StructureWriter`, ADR-073 §2) does not guard against a `valid_from` in
the past. Kök guards it in its own action (`SaveRecipe`: a date before today or before the
latest completed stock count needs an explicit confirmation and is audited). Manufacturing's
action that saves a bill of materials needs its own guard of the same kind, against the
production orders and counts it would change, before it is built.

## Addendum 2026-11-09: units of measure (§12)

The owner, for the joinery: "Enheten på alla artiklar måste kunna anges i styck, kvadrat,
kubik och så vidare eftersom trä och saker till t.ex. ett bord anges i flera olika enheter."
Sawn timber is bought by the cubic metre, stocked by the running metre and used by the piece;
board is bought by the sheet and used by the square metre; screws are bought by the pack and
used by the piece.

What exists: `articles.unit` is free text (max 10 characters) used by invoicing, the API and
Peppol. Quantities are integer thousandths of that unit (`*_milli`, ADR-039). The structure
engine (ADR-073) stores a row in the component's own unit and keeps what was typed
(`bom_rows.entered_quantity`, `entered_unit`); `article_unit_conversions.factor_milli` holds
"thousandths of the extra unit in one article unit", and `StructureUnits::FIXED` knows g for
kg and dl/cl/ml for l. `UblInvoiceWriter::UNITS` maps unit text to UN/ECE Recommendation 20
codes, with C62 as the fallback.

### 12.1 A unit catalogue per company

`units`: `company_id`, `code` (string 10, as printed: `m²`), `name`, `dimension`
(enum COUNT, LENGTH, AREA, VOLUME, VOLUME_LIQUID, MASS, TIME, OTHER), `base_numerator` and
`base_denominator` (bigint: one of this unit is numerator/denominator of the dimension's base
unit), `rec20_code` (char 3, null), `aliases` (jsonb, lower-case spellings matched on import
and migration), `is_default`, `archived_at`. Unique `(company_id, code)`. `BelongsToCompany`.

Seeded on company creation and by the migration for existing companies (codes taken from
`UblInvoiceWriter::UNITS`, never invented):

| Code | Name | Dimension | Base factor | Rec 20 |
|---|---|---|---|---|
| st | styck | COUNT | 1 st | H87 |
| m | meter | LENGTH | 1 m | MTR |
| lpm | löpmeter | LENGTH | 1 m | MTR (not in today's map; `TODO(legal-verify)`) |
| m² | kvadratmeter | AREA | 1 m² | MTK |
| m³ | kubikmeter | VOLUME | 1 m³ | MTQ |
| kg | kilogram | MASS | 1 kg | KGM |
| g | gram | MASS | 1/1000 kg | GRM |
| l | liter | VOLUME_LIQUID | 1/1000 m³ | LTR |
| h | timme | TIME | 1 h | HUR |
| förp | förpackning | COUNT, no fixed factor | — | XPK |
| par | par | COUNT, no fixed factor | — | PR |
| set | set | COUNT, no fixed factor | — | SET |

Helper units for entry only (not offered as stock units by default): mm (MMT), cm (CMT),
dm² (no code in today's map), dl, cl, ml (none in today's map), min (MIN). A unit without a
code goes out as C62 on Peppol, as today.

- **Same-dimension conversions are built in**, from the base factors: m ↔ mm/cm/lpm,
  m² ↔ dm², m³ ↔ l (1 m³ = 1 000 l; VOLUME and VOLUME_LIQUID share the base m³), kg ↔ g,
  l ↔ dl/cl/ml, h ↔ min. This replaces `StructureUnits::FIXED`.
- **förp, par and set** have no fixed size: how many pieces a pack holds is per article.
- **Users may add units** (`rulle`, `bunt`, `pall`) with a dimension (OTHER when none fits)
  and, optionally, a Rec 20 code chosen from a list. A unit in use cannot be deleted, only
  archived.

### 12.2 Stock, purchase and sales unit per article

- `articles.stock_unit_id` (the unit stock is counted, valued and moved in; every `*_milli`
  stock column is in it), `purchase_unit_id` and `sales_unit_id` (null = the stock unit).
- `articles.unit` stays and is written from the sales unit's code, so invoicing, the API and
  Peppol keep working unchanged. It is not dropped in this step.
- Every unit other than the stock unit needs a conversion: built in (same dimension), from
  the article's dimensions (§12.3), or entered on the article.
- `article_unit_conversions` gains `numerator` and `denominator` (bigint, both > 0: one stock
  unit is numerator/denominator of this unit, stored reduced) and `source` (MANUAL,
  DIMENSIONS). Existing rows are backfilled with numerator = `factor_milli`,
  denominator = 1 000; `factor_milli` stays until every reader uses the fraction.
- Bill of materials rows, purchase order rows, production orders and pick list lines may use
  any unit the article converts to. They store the quantity in the stock unit (milli) and
  keep the entered quantity and unit, as `bom_rows` does today. Changing a conversion never
  changes a saved row (as today); it only affects the next entry.

Migration path for existing rows: the free text is lower-cased and trimmed and matched
against the catalogue codes and aliases (the keys of `UblInvoiceWriter::UNITS`: `styck`,
`stk`, `pcs` → st; `kvm`, `m2` → m²; `tim`, `timme` → h; and so on). A text that matches
nothing becomes a company unit with that code, dimension OTHER and no Rec 20 code. Stock,
purchase and sales unit are all set to the result, so nothing an existing company sees
changes. The migration has only `up()` and writes no stock movement.

### 12.3 Conversions from the material's dimensions

Sawn timber and board carry their nominal size: `articles.material_length_um`,
`material_width_um`, `material_thickness_um` (bigint micrometres, entered in mm with up to
three decimals, so 0,6 mm veneer is 600). They are separate from the existing `length_mm`,
`width_mm`, `height_mm`, which describe the package for shipping.

From them NONOX derives conversions with `source = DIMENSIONS` (W, T, L in metres):

| Stock unit | Derived unit | Per stock unit |
|---|---|---|
| lpm or m | m² (face) | W |
| lpm or m | m³ | W × T |
| lpm or m | st (when L is set) | 1/L |
| m² | m³ | T |
| m² | st (when L and W are set) | 1/(L × W) |
| st | lpm, m², m³ | L; L × W; L × W × T |

Example: a 45 × 95 stud, stock unit lpm: 1 lpm = 0,045 × 0,095 = 0,004275 m³, stored as
171/40 000. Derived conversions are recomputed when the dimensions change; a manual
conversion for the same unit wins over a derived one.

### 12.4 Precision and rounding

Decision: **quantities stay integer milli-units of the stock unit.** No column moves to
micro-units.

Why: a move to micro-units would touch every `*_milli` column (stock levels, the append-only
stock ledger with its trigger, receipts, counts, pick lists, Kök's recipes, POS imports, BOM
rows), `Quantity`, the valuation and the API, for a precision problem that only appears when
the stock unit is chosen badly. A thousandth of a running metre is 1 mm, of a square metre
10 cm², of a piece a thousandth of a piece. A thousandth of a cubic metre is one litre, too
coarse for veneer (1 m² of 0,6 mm veneer is 0,0006 m³, 0,6 milli). So:

- The stock unit is the unit the goods are counted and picked in: lpm or st for timber, m² or
  st for board and veneer. m³ is a purchase and display unit. The article form warns when a
  dimension is set and m³ is chosen as the stock unit.
- Conversion to the stock unit happens once, at entry:
  `stock_milli = round_half_up(entered_milli × denominator / numerator)`, with Brick\Math,
  never floats.
- An entry that rounds to zero milli of the stock unit is refused ("Mängden blir noll i
  lagerenheten"). An entry whose round trip differs by more than 0,5 % from what was typed
  shows a warning with both figures; the row can still be saved.
- What the user typed is stored beside the stock quantity, so a row is always shown exactly
  as entered.

**Cost follows the stock unit.** `purchase_price_ore`, the valuation (FIFO or weighted
average) and the cost per unit on movements are per stock unit. A purchase order row has its
price per the row's unit. A price per m³ converted to a price per lpm rarely lands on whole
öre, so a receipt keeps the line's exact value: `stock_movements.value_ore` (nullable, on
movements in) is the row's quantity × price in the row's own unit, rounded half up once; the
valuation uses it when set and quantity × unit cost otherwise. That is a change to the stock
ledger of ADR-039 and goes through the accounting check before it is built.

### 12.5 Display

- Internal screens (bill of materials, purchase order, production order, pick list, stock,
  costing) show each row in its own unit and, when it differs, the stock-unit equivalent:
  "2,5 m³ (≈ 584,795 lpm)". The pick list leads with the stock unit (what is taken from the
  shelf) and shows the order's unit beside it.
- Customer-facing documents (quote and invoice PDF, order confirmation, the customer portal)
  show only the row's unit. Peppol carries the row's quantity and its unit's Rec 20 code
  (C62 when none). Supplier-facing documents (purchase order PDF, Peppol Order later) show
  the purchase unit and the supplier's article number.

`TODO(legal-verify)`: ML 17 kap. 24 § requires the quantity and kind of goods on an invoice;
confirm that the sales unit's code (`lpm`, `förp`) is enough as the unit text. Confirm that
MTR is acceptable for running metres, that X-prefixed Rec 21 codes (XPK) pass the Peppol BIS
Billing 3.0 validation as unit codes, and which codes to use for dm², dl, cl and ml.

### 12.6 Fixtures (copied into `tests/Fixtures` when built)

| # | Case | Input | Expected |
|---|---|---|---|
| F-U1 | Stud 45 × 95, L 4 800 mm, stock lpm | buy 2,5 m³ | 584 795 milli-lpm (584,7953… half up); conversion m³ = 171/40 000 |
| F-U1b | same | BOM row 4 st | 19 200 milli-lpm, shown "4 st (19,2 lpm)"; 1 st = 0,02052 m³ |
| F-U1c | same, 6 500 kr/m³ | receipt of 2,5 m³ | `value_ore` 1 625 000; unit cost 2 779 öre/lpm (2 778,75 half up); 584,795 × 2 779 would give 1 625 145, which is why the line value is kept |
| F-U2 | Oak board 27 mm, 2 440 × 1 220 mm, stock m² | 1 st | 2 977 milli-m² (2,9768 half up); 1 m² = 0,027 m³ |
| F-U2b | same, 1 450 kr/m² | BOM row 1,62 m² (top 1 800 × 900) | material 234 900 öre |
| F-U3 | Oak veneer 0,6 mm, stock m² | 0,001 m³ | 1 667 milli-m² (1,6667 half up); round trip 0,02 %, no warning |
| F-U3b | same veneer with stock unit m³ | 0,3 m² | 0,18 milli-m³ rounds to 0 → refused; the form warned when m³ was chosen |
| F-U4 | Board, stock m² | 250 dm² | 2 500 milli-m² (built in) |
| F-U4b | Lacquer, stock l | 0,012 m³ | 12 000 milli-l (built in) |
| F-U5 | Screw, stock st, 1 förp = 200 st | buy 3 förp at 189 kr/förp | 600 000 milli-st; `value_ore` 56 700; unit cost 95 öre (94,5 half up) |
| F-U6 | Stud as F-U1 | 0,000001 m³ | 0,000234 lpm → 0 milli → refused |
| F-U6b | Stud as F-U1 | 0,0001 m³ | 23 milli-lpm (23,39 half up); round trip 0,0000983 m³ = 1,7 % off → warning, savable |
| F-U7 | Migration | unit texts `Styck`, `kvm`, `tim`, `M3`, `rulle` | st, m², h, m³, and a new company unit `rulle` (OTHER, no code → C62 on Peppol) |
| F-U8 | Invoice row 12 lpm | sales unit lpm | PDF "12 lpm"; Peppol quantity 12 with the lpm code; no stock equivalent on the PDF |

### 12.7 Decisions (units)

1. One unit catalogue per company with the defaults above; users may add units.
2. Each article has a stock, a purchase and a sales unit; `articles.unit` stays as the sales
   unit's code.
3. Conversions are stored as exact fractions; same-dimension conversions come from the
   catalogue, cross-dimension ones from the article (manual or from its dimensions).
4. Quantities stay milli-units of the stock unit; the stock unit must be the counting unit;
   results of zero are refused and round-trip changes above 0,5 % warn; half up, once, at
   entry.
5. Cost is per stock unit; receipts keep their exact line value.
6. Rows display in their own unit with the stock equivalent on internal screens; customer
   documents show only the row's unit.

Open questions: the Peppol unit codes above (`TODO(legal-verify)`); whether the 0,5 % warning
threshold should be a company setting; whether a sawmill-type customer needs m³ as a stock
unit (then this decision is revisited, with micro-units only for the affected columns);
`value_ore` in the valuation (accounting check).

### 12.8 As built (M1, 2026-11-10)

Built: the unit catalogue (`units`, seeded on company creation and by the migration), the
stock, purchase and sales unit and the material's nominal size on `articles`, conversions as
exact fractions (`article_unit_conversions.numerator`, `denominator`, `source`), the Enheter
page (Artiklar → Enheter), the units section on the article form (Avancerad), and the
conversion rules of §12.2–§12.4 in `App\Domain\Units` (`UnitConverter`, `MaterialConversions`,
`UnitDefaults`, `UnitCatalogue`, `ArticleUnits`). The fixtures of §12.6 are in
`tests/Fixtures/units/units-of-measure.json` and run in `tests/Unit/Units/UnitConverterTest.php`.
Not built, as scoped: bills of materials, purchase orders and production orders (they will use
`UnitConverter::toStock`); invoice rows are unchanged.

Where the build deliberately differs from, or adds to, the text above:

- **`articles.unit` keeps what was typed.** An article saved with only a unit text (the old form,
  the API, an importer) keeps that text ("tim" stays "tim"); its catalogue unit is the one the
  text names (§12.2's matching) and all three units follow it. Only an article saved with explicit
  units gets `unit` from the sales unit's code (the stock unit's when there is none). This keeps
  invoices and Peppol exactly as before for every existing article. The migration does not touch
  `articles.unit`.
- **Articles written without units** (`Article::create`) get the stock unit their text names, in a
  `creating` hook, so every article has one.
- **Units named by code.** SaveArticle also accepts `stock_unit`, `purchase_unit` and
  `sales_unit` as codes (for imports and the demo), besides the ids.
- **A unit a company adds has no base factor** (dimension is informational); only the defaults
  convert by themselves. The `units.rec20_code` column is `varchar(3)`.
- **Derived conversions are a superset of §12.3.** Every pair among lpm/m, m², m³ and st is
  derived when the dimensions it needs are set (m³ to m² from the thickness alone, which F-U3b
  needs). Derived rows have `source = DIMENSIONS`; a manual row for the same unit wins and a
  removed manual row gives the derived one back.
- **`factor_milli` is nullable** and its check constraint is dropped: a derived fraction can be
  smaller than one thousandth. It is filled in when it fits and no reader uses it any more; the
  model fills the fraction from `factor_milli` for rows written the old way.
- **Entered quantities** may have any number of decimals in `UnitConverter` (F-U6 is 0,000001 m³);
  the structure writer still reads at most three, as before.
- **The structure writer and recipes store rows in the stock unit** (§12.2) and use the fractions
  and the catalogue's built-in conversions; `StructureUnits::FIXED` is gone and `StructureUnits`
  now delegates. The zero refusal reads "Mängden blir noll i lagerenheten"; the 0,5 % warning is
  collected in `StructureWriter::$warnings` and shown as a toast on the recipe page. Bills of
  materials and purchase orders will show it on their own screens.
- **Stock screens show the stock unit.** `Article::stockUnitCode()` reads the eager-loaded
  `stockUnit` relation (it never queries, so lists keep a fixed number of queries) and falls back
  to the unit text. Lager → Artiklar, the overview, receipts, counts, pick lists and their PDF,
  and the recipe page load it. Still on the unit text, to be moved when M2–M4 touch them: the
  scanner, the stock and valuation reports, the Kök waste and variance pages, the labels'
  comparison price and the Connect API (`unit` there stays the unit text).
- **The stock unit cannot change** once the article has stock movements or sits in a structure
  (SaveArticle refuses with a Swedish message); the text says to create a new article.
- **Helper units** (mm, cm, dm², dl, cl, ml, min) are in the catalogue and offered as conversion
  units, but the stock-unit choices in code (`UnitDefaults::stockChoices()`) exclude them. The
  article form's selects still list every unit: filtering them is left for the screens that
  warn about m³ as a stock unit.
- **`value_ore` and the valuation are not changed.** `UnitConverter::lineValueOre` and
  `unitCostOre` compute F-U1c and F-U5 and are tested against them, but nothing writes them to
  the stock ledger. No accounting behaviour changed, so the system documentation is unchanged.
- **Peppol and invoices.** `UblInvoiceWriter::UNITS` is unchanged (it is now public, so the
  catalogue can read it); `lpm` still goes out as C62. The Rec 20 codes in the catalogue are the
  ones of §12.1.

Still open, as in §12.7: the Peppol unit codes (`TODO(legal-verify)`: MTR for running metres,
XPK as a unit code, codes for dm², dl, cl, ml), whether the 0,5 % threshold is a company setting
(it is the constant `UnitConverter::WARN_ABOVE`), whether a sawmill-type customer needs m³ as a
stock unit, and `value_ore` in the valuation (accounting check).

### 12.9 Units everywhere (item 21, 2026-11-10)

The owner: "bygg en enhetstabell som man kan styra per företag som sedan används, och tillåt
inte fritext". The catalogue of §12.1 is now the only source of units. Every place that took a
unit as free text takes a unit of the company's register instead.

**Inventory of places that entered or stored a unit as text, and what changed**

| Place | Stored in | Now |
|---|---|---|
| Invoice rows, credit invoices (invoice editor, `SaveDraftInvoice`, `CreditInvoice`) | `invoice_rows.unit` | `unit_id` + text snapshot; select in the row grid |
| Quotes and orders (`SalesDocumentEditor`, `SaveSalesDocument`, `ProgressSalesDocument`) | `sales_document_rows.unit` | `unit_id` + snapshot; select |
| Recurring invoices (`AgreementEditor`, `SaveRecurringInvoice`, `RunRecurringInvoice`) | `recurring_invoice_rows.unit` | `unit_id` + snapshot; select |
| CRM quote templates (`QuoteTemplatesPage`, `SaveQuoteTemplate`) | `crm_quote_template_rows.unit` (null = the article's) | `unit_id` (null = the article's sales unit) + snapshot; select with "Artikelns enhet" / "Standardenheten" |
| CRM quotes from a template or a deal (`CreateQuoteFromTemplate`, `CreateQuoteForDeal`) | through `SaveSalesDocument` | pass the template row's `unit_id`; a deal's quote row gets the default unit (was the text `st`) |
| Work order material (`WorkOrderPage`, `FieldWorkOrderPage`, `RecordMaterial`) | `work_order_material_entries.unit` | `unit_id` + snapshot; select. Material from stock always takes the article's stock unit |
| Work order invoicing (`InvoiceDraft`) | invoice rows | each row carries `unit_id`: the material entry's, or the unit its text names (`st`, the labour kind's `tim`/`resa`/`km`), added to the register if missing |
| Article conversions (article form Avancerad, Lager → artikel → Omräkningar, `ResolveArticleUnits`, `UnitConversions`) | `article_unit_conversions.unit` (a code) | select of register units; a code the register does not know is refused. Kept as a code, not an id: the conversion is keyed by the code everywhere in `UnitConverter` |
| Article unit text (article form Enkel, `SaveArticle`) | `articles.unit` | already a select (M1); the server now refuses a text the register does not know, or an archived unit the article did not already have |
| Recipe rows (Kök) | `bom_rows.entered_unit` | already a select of the units the article converts to (M1); unchanged |
| API: invoices (`POST/PATCH /invoices`), articles, MCP `create_invoice_draft` | — | `unit_id` or a code/alias of the register; anything else is 422. New `GET /units`. `unit_id` in the responses |
| Register import (CSV/Excel, ADR-076) and the move from the previous program | `articles.unit` via `SaveArticle` | the importer adds an unknown spelling to the register first (`UnitCatalogue::resolve`, dimension OTHER) and lists the new units in the import result; it never fails on a unit |

Not free text, so unchanged: work order labour kinds (`LabourUnit` enum: HOUR, TRIP, KM), payroll
and agreements (`AMOUNT`, `HOURS`, … enums), the planning rules, the recipe's yield unit
(`PORTIONS`/`UNIT`). The customer portal only shows saved rows; it prints the snapshot as before.

**Data model.** `invoice_rows`, `sales_document_rows`, `recurring_invoice_rows`,
`crm_quote_template_rows` and `work_order_material_entries` get `unit_id` (nullable FK to
`units`). The `unit` text stays as the printed snapshot, so PDFs, Peppol (which still maps the text
through `UblInvoiceWriter::UNITS`), SIE and history read exactly what they read before. `units` gets
`row_default` (one per company, partial unique index): the unit a row without an article starts with.
Without a marked unit it is st, otherwise the first active unit.

**The snapshot.** A row prints the unit's code, unless the row or its article already carries
another spelling of the same unit: an article written "tim" (unit h) still prints "tim", and so does
a labour row from a work order. Changing the row's unit to another one prints the new code.

**Validation.** Server side, in `UnitRegister`: a `unit_id` must be a unit of the current company
(another company's id answers "Enheten finns inte i företagets enhetslista", never the other unit);
a text must be a code or alias; an archived unit is refused on a new row. The editors keep the units
their document had when it was opened (`keptUnitIds`, `#[Locked]`), so an old row still shows and
saves its archived unit. Copy flows (credit, quote → order → invoice, recurring runs) carry the saved
`unit_id` and are not refused when a unit has since been archived. An archived unit cannot be the
row default, and the row default cannot be archived before another unit is chosen.

**Migration and backfill** (`up()` only, additive). The Crm and WorkOrders modules add `unit_id` to
their tables (`2027_01_07_200100`, `2027_01_07_200200`); `2027_01_08_100000_add_unit_id_to_sales_rows`
adds it to the three core tables, marks each company's row default and links the rows of all five.
Like M1's migration it is self-contained: its matching is a frozen copy of `UnitCatalogue::find()`
and `resolve()` (code, alias or name, a trailing full stop ignored), so later changes to the app do not
change what it does. Every distinct (company, unit text) is resolved: an existing unit, otherwise a
new company unit with that code and dimension OTHER, so no spelling is lost. Rows are then linked,
except where a trigger forbids it: rows of issued invoices (`invoice_rows_protect_issued`) and the
append-only material entries keep `unit_id` null and their text, which remains authoritative for
them; their spellings are still added to the register. A text longer than a code (CRM rows allowed
20 characters) is stored cut to ten characters and matches the cut form again. Rows written later
with only a text are linked when created (`HasRegisterUnit`, below).

**No accounting behaviour changes**: amounts, VAT, booking and the ledger are untouched, and the
printed unit of every existing row stays as it was. The system documentation is therefore unchanged.

**Matching a text.** A text names a unit by its code, an alias or its name, case-insensitive, with one
trailing full stop ignored: `st.` is st, `tim.` is h, `kilogram` is kg, `kvadratmeter` is m². The
same rule serves the screens and the API (`UnitRegister::match`), imports and the backfill
(`UnitCatalogue::find`/`resolve`), so a spelling never becomes a second unit of the same kind.
`resolve` inserts with ON CONFLICT DO NOTHING and reads the unit back, so two imports adding the same
spelling at once share it.

**The default unit.** Styck is marked as the row default when a company's catalogue is seeded, also
for companies created after the migration. The unit that is the default, whether marked or by
fallback, cannot be archived. Choosing a default locks the company's units.

**Archived units.** An article's own unit stays selectable on its rows (editors and API) and on the
article (API PATCH), as in `RecordMaterial` and `ResolveArticleUnits`. An import whose text names an
archived unit uses it instead of failing the row. A credited row saved before the register gets the
unit its text names in the editor.

**Imports** resolve the unit inside the row's savepoint, after which the row is saved; a row that is
refused adds no unit and none is reported. **Rows written with only a text** (`HasRegisterUnit`) are
linked when the text names a unit; an unknown text throws, unless the code runs inside
`UnitCatalogue::addingUnits()` (meant for importers of rows), so no screen or API adds units by
accident. **Work order invoicing** maps each labour kind to a fixed register code (h, resa, km,
`LabourUnit::registerCode()`), whatever the language, prints the label when it names that unit, and
looks each unit up once per draft.

**API decision (owner, 2026-11-10): no free text.** The 422 for an unknown unit text is kept as a
documented breaking exception to the v1 rule of additive changes only, dated 2026-11-10 in the API
changelog with the way to adapt: send `unit_id` or a code/alias/name from `GET /units`.

Open: linking issued invoice rows
and old material entries would need a one-time exception in their triggers (NULL → id only); not
done. Peppol still maps the printed text, not `units.rec20_code`, so the `TODO(legal-verify)` items
of §12.5 stand.

## As built (M2, 2026-11-10): bills of materials, operations and costing (§3, §4, §6)

M2 builds §3, §4 and §6 in a new module `manufacturing` (Tillverkning, requires `inventory`, off by
default), on the structure engine of ADR-073, with the units of §12 for every quantity. Nothing is
bookkeeping: no voucher, no stock movement, and the costing is a management figure, not the stock
valuation. No accounting behaviour changed, so the system documentation is unchanged.

Built:

- **Operations** (`operations`): name (unique per company, case-insensitive), `hourly_cost_ore`
  (≥ 0), `daily_capacity_minutes` (entered as staffed hours per day, nullable), `archived_at`.
  Archived, never deleted. Page: Tillverkning → Arbetssteg.
- **Bills of materials** are `bills_of_materials` of kind BOM, always `yield_unit = UNIT` (the batch,
  normally 1). `bom_rows` gains the foreign key to `operations` and the check that a row is either a
  component or an operation with minutes > 0. Operation rows carry whole minutes for the batch.
  `SaveBillOfMaterials` goes through `StructureWriter` (versions, loops, units, scrap with ADR-073's
  loss formula) and has the backdating guard of the note of 2026-11-09: a `valid_from` before today
  or before the latest completed count needs `confirm_backdate` and is audited
  (`manufacturing.bom_backdated`). Pages: Tillverkning → Stycklistor (list and one bill of
  materials with versions, edit as a new version, switch off and on).
- **The row's own copy** (§4): `sales_document_row_structures` and
  `sales_document_row_structure_lines` (same shape as `bom_rows`), on a new stable row key
  `sales_document_rows.line_key` (UUID). Choosing copies the version valid today; the copy is edited
  for the row only (quantities, units, scrap, swapped/added/removed components and operations,
  minutes, batch); "Spara som ny stycklista" saves it as a new version of a chosen product (a new
  bill of materials if it has none, `manufacturing:manage`). The copy follows the quote into the
  order: when `ProgressSalesDocument::toOrder` links the order to its quote, a listener in the
  module copies every row copy to the order row with the same `line_key`.
- **Costing** (§6) in `ManufacturingBook` / `Costing` / `DocumentCosting`, shown on the bill of
  materials page and in the panel "Stycklistor och kalkyl" on the quote and order page (internal,
  never on the PDF): material, labour, overhead (company setting `overhead_bp`, off by default),
  cost per unit, row cost, contribution and margin per row and summed over the rows with a copy.
- Audit (ids, enums, counts and amounts only): `manufacturing.operation_created|updated|archived|restored`,
  `.bom_saved`, `.bom_backdated`, `.bom_activated|deactivated`, `.row_structure_chosen|changed|removed|saved_as_bom`.
- Permissions: `manufacturing:view` (owner, admin, accountant, auditor) and `manufacturing:manage`
  (owner, admin). Bookkeepers get neither, as §1 says.

Design choices where this ADR left room:

1. **Stable row key.** Quote and order rows are written anew on every save, so the copy hangs on
   `line_key`, which the editor sends back with each row and `SaveSalesDocument` keeps (a missing or
   duplicate key gets a new UUID). Rows saved before M2 get a key on their next save. The editor
   reloads its rows after a save, so new rows keep their keys.
2. **The copy is edited in its own panel under the rows**, not inside the shared row grid of quotes,
   orders and invoices, so invoice rows and the browser-side row editor are untouched. Only saved rows
   can get a copy; the costing uses the saved row (the panel says so).
3. **Copies are kept, not pruned**, when their row disappears; they are simply not shown.
4. **Cost of a component**: the valuation cost when on hand (the company's FIFO or weighted average),
   else its own bill of materials valid today (a sub-assembly, its labour counted as labour), else the
   latest receipt cost, else the purchase price. Each line is rounded half up once; per unit each part
   is rounded and the total is the sum of the parts.
5. **Overhead** is a percentage of material plus labour, applied once at the top level (not inside
   sub-assemblies), and only in the costing.
6. **The row quantity** is converted to the output's stock unit with the unit catalogue and the
   article's conversions; when the row's unit does not convert, the row shows no cost and says why.
7. **Changing a row's copy** needs `manufacturing:view` plus the right to change the open document
   (`invoices:create`); another company's document answers 404.
8. **A structure book holds one kind.** `StructureBook::for()` loads Kök's recipes unless asked for
   another kind, and an article cannot have both a recipe and a bill of materials.

Not built in M2: the payroll suggestion for the hourly cost (§6; payroll keeps no worked hours per
period to divide by, so the source needs a decision), learning from reported minutes (§6, needs
production orders and the terminal), `production:report` and `production:analyse` (added with the
terminal), and the API (this ADR asks for none in M2).

## Addendum 2026-11-09: production planning calendar (§13)

The owner: "en slags planeringskalender där jag kan se alla orders, produkter som tillverkas
och liksom live se vart de troligtvis kommer levereras och vara klara och om saker händer att
jag kan se avvikelser och se hur det påverkar leveranser, t.ex. om en person är sjuk."

This addendum replaces the first bullet of §11 (detailed scheduling per person and
workstation, and a Gantt view, are now in scope) and extends §10: when a plan exists, the
delivery estimate for an order is the plan's forecast, and the history-based range stays as
the fallback. The Swedish specification with mock-ups is kept outside the repository
(`NONOX-Planeringskalender-specifikation.pdf`, 2026-11-09); this section is the binding
summary.

### 13.1 Scope and screens

Part of `manufacturing`, under `/tillverkning/planering` (the staff schedule of ADR-037 keeps
`/planering`):

- `/tillverkning/planering`: timeline (Gantt), one row per order or production order, its
  operations as bars, the promised date as a marker, the forecast at the bar's end.
- `/tillverkning/planering/resurser`: one row per person and per workstation with working
  hours, absence (shown only as "Frånvarande"), booked operations and load per day.
- `/tillverkning/planering/kalender`: month and week calendar of forecast finish and delivery
  dates.
- `/tillverkning/planering/ordrar`: the orders with customer, promised date, forecast date,
  difference in working days, traffic light and what they wait for (material, capacity,
  nothing).
- `/tillverkning/planering/avvikelser`: open deviations with their impact and suggestions.

### 13.2 Resources

- **Workstations** (`production_workstations`): name, code, the operations they serve, and a
  working calendar (the company's working hours by default). Downtime is recorded in
  `production_downtimes` (start, expected end, reason enum, source).
- **People** are employees. Working hours come from published shifts in Planering (ADR-037)
  when that module is on, otherwise from a weekly pattern per person in manufacturing
  (`production_work_patterns`). Public holidays come from `App\Domain\Calendar\SwedishHolidays`.
- **Skills**: an operation may require a skill (`operations.required_skill_id`) and a
  workstation. Skills are Planering's `planning_skills` and `planning_employee_skills` when
  Planering is on, otherwise a list kept in manufacturing with the same shape.
- **Absence** is read through one narrow query that returns employee id, dates and part-day
  hours of approved payroll absences, never the type or the note (§13.7).

### 13.3 Forecasting algorithm

Deterministic forward finite-capacity scheduling (list scheduling), recomputed as a whole:

1. Snapshot, in a fixed number of queries: open production orders (RELEASED, STARTED, and
   DRAFT ones tied to an open sales order), their operations in sequence with remaining
   minutes (standard × quantity − reported, never below zero, or the latest "remaining"
   estimate reported from the terminal), the locked assignments, material requirements,
   stock, open purchase order rows with expected dates, resources, calendars and absences,
   for the horizon (default 26 weeks, maximum 52).
2. Material ready date per production order: free stock and incoming purchase order rows are
   allocated in the order of step 3 (as reservations in §5). A component still short with no
   purchase order gets today + the supplier's median lead time (or the article's lead time),
   and the forecast is flagged "antaget materialdatum".
3. Order of planning: locked assignments first, as they are; then production orders sorted by
   manual priority, promised date (none last), release time and production order number. The
   sort is total, so the same input always gives the same plan.
4. For each production order, each operation in sequence: earliest start = the latest of now,
   the previous operation's end plus its wait time (drying, curing), and the material ready
   date for the first operation. Candidates are the people with the skill (and the
   workstation when one is required). The operation fills the candidate's free working time
   from the earliest start, across breaks and days, and in gaps left by earlier operations.
   The candidate with the earliest end wins; ties go to the fewest booked minutes in the
   horizon, then the lowest id. An operation is not split between people unless a planner
   splits it.
5. Finish = the last operation's end plus its wait time. Forecast delivery date = the first
   working day whose start is at or after the finish, plus a delivery buffer in working days
   (company setting, default 0).
6. Traffic light: green when the forecast is at least one working day before the promised
   date; yellow when it is on the promised date or rests on an assumed material date; red when
   it is after. Grey without a promised date.

Limits, stated in the UI: the plan is greedy, not optimised (it does not minimise total
lateness); no sequence-dependent setup times; one person and at most one workstation per
operation; standard minutes are only as good as the history (§6, learning); nothing is
forecast past the horizon ("efter horisonten").

### 13.4 Running it

- A queued job `RecomputeProductionPlan(company_id, trigger)` calls `CurrentCompany::run()`,
  is unique per company (`ShouldBeUnique`) and delayed a few seconds to absorb bursts. It is
  dispatched by an absence approved or changed, a shift published, a downtime, a purchase
  order's expected date, a receipt, a terminal report, a production order status change, a
  sales order quantity or date change, a lock, and nightly.
- The result is stored as a run (`production_plan_runs`) with slots and order forecasts. The
  new run is compared with the previous one; changed forecasts become deviation impacts. The
  latest 30 runs are kept and older ones pruned, except runs referenced by a deviation or a
  lock.
- Performance: the snapshot loads in a fixed number of queries (no N+1, proved with a
  query-count test at 10 and 100 orders); the scheduler works in memory. Target: under two
  seconds for 300 production orders × 8 operations × 20 resources.
- **Live updates by Livewire polling**, not broadcasting: the screens poll a cheap "latest run
  id" every 15 seconds while visible (`wire:poll.15s.visible`) and reload when it changes.
  NONOX has no websocket server today, self-hosters would need one, and 15 seconds is fast
  enough for a workshop. Broadcasting can be added later behind the same "run changed" event.

### 13.5 Deviations

`production_deviations`: type (ABSENCE, PURCHASE_DELAY, SCRAP, OVERRUN, WORKSTATION_DOWN,
QUANTITY_CHANGE, MATERIAL_SHORTAGE, LOCK_BROKEN), source (morph to the record that caused it;
for ABSENCE only the employee id and dates, never the absence record), status (OPEN, HANDLED,
DISMISSED), and the runs before and after. `production_deviation_impacts`: per affected
production order and sales order, the customer id, promised date, forecast before and after,
and the change in working days.

How they enter: absence from payroll or Planering; a supplier delay by changing the expected
date on a purchase order row (by hand, or from a supplier message in the inbox confirmed by a
user); scrap, overrun (with a new remaining estimate) and a stopped workstation from the
workshop terminal (`production:report`); a quantity or date change on the sales order.

Suggestions are computed by simulation (§13.6) and shown with their result before they are
applied: move to another qualified person, overtime for a qualified person (with ADR-037's
working-time warnings and the estimated labour cost in öre), split an operation between two
people, reprioritise (showing which other orders move), and a new date to the customer. A
suggestion that does not help is shown as such. Nothing is applied without a user with
`production:plan`, and no customer message is sent until a user with
`production:confirm-dates` has seen the text and the recipient and confirmed (as §2 for
purchase orders).

### 13.6 Simulation, locking, audit

- "Tänk om" creates a scenario (`production_scenarios`: the changes as ids, enums, dates and
  minutes) and computes it in memory without touching the live plan. Applying it writes the
  changes and triggers a normal run. Scenarios expire after seven days.
- A planner can lock an assignment (person, workstation, start) and set a frozen window
  (default 0 working days). The scheduler never moves locked work; when locked work becomes
  impossible (the person is absent), a LOCK_BROKEN deviation is raised instead of a silent
  move.
- Audit log (`Audit::record`; ids, enums, dates and minutes only, no names or free text):
  `manufacturing.plan_locked`, `.plan_unlocked`, `.operation_moved`, `.operation_split`,
  `.priority_changed`, `.scenario_applied`, `.deviation_handled`, `.deviation_dismissed`,
  `.customer_date_confirmed`, `.customer_date_message_sent`.

### 13.7 Permissions and personal data

- `manufacturing:view` sees the calendar. New: `production:plan` (move, lock, split,
  reprioritise, apply scenarios, handle deviations) and `production:confirm-dates` (confirm a
  delivery date to the customer and send the message). The names follow the existing
  `production:report` and `production:analyse`. Owner and admin get both; Planering's
  scheduler role gets `manufacturing:view` and `production:plan`; accountant, bookkeeper and
  auditor get view only.
- **Sick leave is health data (GDPR art. 9).** The calendar shows every kind of absence the
  same way, "Frånvarande", and never the reason: not in the plan, the deviation, the impact
  panel, the audit log, the customer message or the API. The planning engine cannot read the
  reason, because its absence query selects no type column. The reason stays in payroll,
  visible only to those who see it there today. A deviation of type ABSENCE is raised for
  every kind of absence, so the type does not reveal sickness by elimination.
- Retention: plan runs as in §13.4; deviations and impacts 24 months, after which the
  employee id is removed and the rest kept for delivery accuracy statistics.

`TODO(legal-verify)`: whether showing "Frånvarande" for a named person to everyone with
`manufacturing:view` needs a narrower audience under art. 5.1 c; whether absence shown next
to a deviation can still reveal health by inference; the 24-month retention; and whether the
workshop terminal may show who is absent today (proposed: it does not).

### 13.8 Data model

`production_workstations`, `production_workstation_operations`, `production_downtimes`,
`production_work_patterns`, `production_order_operations` (sequence, operation, workstation,
standard minutes, remaining minutes override, wait minutes after), `production_plan_runs`,
`production_plan_slots` (run, order operation, employee, workstation, start, end, minutes,
locked), `production_plan_forecasts` (run, production order, sales document, material ready
on, finish at, forecast delivery on, promised on, status, flags), `production_plan_locks`,
`production_scenarios`, `production_deviations`, `production_deviation_impacts`,
`production_customer_date_changes` (sales document, old and new date, decided by, message sent
at). `sales_documents` gains `confirmed_delivery_on` (§10's confirmed date) unless it exists.
Every table has `company_id` and uses `BelongsToCompany`; another company's id gives 404. Money
(overtime cost estimates) is in öre; quantities (scrap, quantity changes) are milli-units of
the stock unit (§12).

### 13.9 Fixtures (the scheduling algorithm on synthetic data)

Workshop: five people working 07:00–16:00 with lunch 12–13, Monday to Friday. Skills: Maja
(Montering), Omar (Kapning, Hyvling, Montering), Tove (Slipning, Lackering), Jonas (Kapning,
Hyvling), Lina (Slipning). Lackering needs the workstation Lackbox and is followed by 12
hours' drying. Plan computed Monday 2026-11-16 07:00. Minutes per unit are Kapning / Hyvling /
Montering / Slipning / Lackering.

| Order | Product | Qty | Material ready | Promised |
|---|---|---|---|---|
| TO-1042 | Byrå 80 (40/30/120/60/45) | 6 | now | 2026-11-24 |
| TO-1041 | Ekbord 180 (30/25/90/45/30) | 10 | now | 2026-11-25 |
| TO-1043 | Pall (10/0/25/15/10) | 20 | 2026-11-24 (purchase order) | 2026-12-01 |

| # | Event | TO-1042 | TO-1041 | TO-1043 |
|---|---|---|---|---|
| F-P0 | Base plan | 11-20 green | 11-23 green | 11-27 green |
| F-P1 | Tove absent 11-17 – 11-23 | 11-25 red (+3) | 11-26 red (+3) | 11-27 green |
| F-P1a | F-P1 + overtime for Tove 11-24 16–19 | 11-25 red | 11-25 yellow | 11-27 green |
| F-P1b | F-P1 + TO-1041 prioritised first | 11-26 red | 11-25 yellow | 11-27 green |
| F-P2 | Purchase order for TO-1043 delayed to 11-30 | 11-20 green | 11-23 green | 12-03 red (+4) |
| F-P3 | Lackbox down 11-18 07:00 – 11-19 16:00 | 11-23 green (+1) | 11-24 green (+1) | 11-27 green |
| F-P4 | Montering on TO-1042 takes 240 min longer | 11-23 green (+1) | 11-23 green | 11-27 green |
| F-P5 | One top scrapped on TO-1041 (+45 min Slipning, +30 min Lackering) | 11-20 green | 11-24 green (+1) | 11-27 green |
| F-P6 | TO-1041 quantity 10 → 14 | 11-20 green | 11-25 yellow (+2) | 11-27 green |

Changes are counted in working days between the old and the new forecast. The expected slot
lists (who does what, when) for F-P0 and F-P1 are in the specification's appendix.

### 13.10 Decisions (planning calendar)

1. The calendar is part of `manufacturing` at `/tillverkning/planering`, with timeline,
   resource, calendar, order list and deviation views.
2. Deterministic forward finite-capacity list scheduling, recomputed as a whole in a queued
   job per company; no optimiser.
3. Live updates by polling the latest run id, not broadcasting.
4. Deviations are found by comparing runs; suggestions are simulated and applied only by a
   human; customer messages go only after confirmation by `production:confirm-dates`.
5. Absence is "Frånvarande" only; the engine cannot read the reason.
6. Build order: resources and calendars → the scheduler with fixtures F-P0 to F-P6 → timeline
   and order list → deviations and impact → simulation and locks → customer date messages →
   terminal deviation reports → mobile view.

## Addendum 2026-11-10: as built (M3), purchase orders and purchase proposals

Built in the `inventory` module, as §1 says: §2 (purchase orders), §5 (availability and purchase
suggestions) for stock articles, and the purchase side of §12 (a row in any unit the article
converts to, stored in the stock unit, priced per its own unit). The bills-of-materials part of
§5 waits for M2/M4 (see "Extension point" below).

**Data.** `purchase_orders` (number from the series `purchase_orders`, `version`, supplier,
status, `source`, order date, requested delivery date, our reference, delivery address, notes,
created by, sent at/to/by and method), `purchase_order_rows` (article, supplier article number,
description, `entered_quantity` as typed and `unit_id` from the company's unit catalogue,
`quantity_milli` in the stock unit, `price_ore` per the row's unit, expected date,
`received_milli`), `purchase_order_versions` (each sent version as a JSON snapshot of header and
rows, its total, method, recipient, sender and time; written once, the model refuses changes and
the app role may only insert), `purchase_order_invoices` (a supplier invoice matched with an
order by a person). `stock_receipts.purchase_order_id` and
`stock_receipt_lines.purchase_order_row_id` link a goods receipt to the order. Every table has
`company_id` and `BelongsToCompany`.

**Lifecycle.** DRAFT → SENT → PARTIALLY_RECEIVED → RECEIVED, or CANCELLED. Sending writes the
version and sets the status from what the rows have received. "Ny version" (`revise`) on a sent
order that waits for goods turns it back into a DRAFT with the next version number; the sent
versions stay as they were and their PDFs can be opened again (`?version=n`). A row goods have
arrived on cannot be removed, change article, or drop below what was received. An order with
received goods is not cancelled; cancelling an order also cancels its goods receipt where
nothing has arrived.

**Sending.** The order page shows the PDF (Dompdf, the company's branding, ADR-030) and the
recipient (prefilled from the supplier's e-mail) before anything goes. E-mail goes through the
app's mail from the company's name with its e-mail as reply-to, counts against the documents
mail quota, and never leaves a test company (ADR-032). "Utskriven, markera som skickad" sends
nothing. The supplier sees the row's own unit and its own article number only (§12.5). A
supplier under restricted processing (GDPR art. 18) gets no new order.

**Receiving and the supplier invoice, without double booking.** "Ta emot varor" makes (or
reopens; at most one open per order) a goods receipt with the rows still to come, at the row's
cost per stock unit (`UnitConverter::unitCostOre(lineValueOre(entered, price), quantity_milli)`,
F-U1c and F-U5) and the article's default location. `ReceiveStock` ticks off the order's rows
and refreshes its status. A receipt made from an order cannot be edited through `SaveReceipt`
(it would lose the row links). Matching a supplier invoice shows ordered value, received value
and the invoice's amount excluding VAT, and flags rows where received ≠ ordered and a difference
between the invoice and the received value; it approves nothing. Purchase orders, receipts and
matches create no voucher: the cost is booked once, by the supplier invoice, as before, and the
stock meets the books at the year-end valuation. `stock_movements.value_ore` (§12.4) is still not
written, so the receipt's unit cost is the rounded cost per stock unit as today; the exact line
value remains for the accounting check of §12.4. No accounting behaviour changed, so the system
documentation is unchanged.

**Permissions (§1).** `purchase-orders:view`, `:manage`, `:send` exist while Lager is on. Owner and
admin get all; accountant and auditor view; bookkeeper view and manage; approver view and send.
Choices where the ADR was open: sending needs `:send`, or `:manage` while the order is at or
below the company's approval amount (`purchase_order_approval_above_ore` in Lager's settings,
"Attestgräns för inköpsorder", off by default). The approver's `:send` is granted whether or not
an amount is set. There is no rule that the sender must differ from the creator (§4's
segregation of duties covers payments; open question below). Purchasing has its own menu section
"Beställningar" (the core "Inköp" holds the supplier invoices), so an approver, who does not see the stock, can reach the orders. Lagerpersonal receives
an order's goods on Inleveranser without seeing the order or its prices.

**Purchase proposal (§5).** For each active stock article with a reorder point or a reservation:
on hand (all locations), reserved (sum over the registered demand sources), coming (rows of
DRAFT, SENT and PARTIALLY_RECEIVED orders not yet received, with the earliest expected date;
drafts count, so a proposal is not made twice), short = max(0, reserved − on hand − coming). An
article at or below its reorder point after reservations and coming goods is refilled with
max(reorder quantity, reorder point − available). The proposal is the larger of the shortage and
the refill, expressed in the purchase unit (the stock unit when the purchase unit does not
convert) and rounded up: to whole units for COUNT units and units without a fixed size (förp,
par, set, a company's own unit: the pack is the supplier's pack quantity), otherwise to a
thousandth. Articles have no separate supplier minimum; the reorder quantity is the minimum for
a refill. "Skapa inköpsordrar för det som saknas" makes one DRAFT per supplier (source PROPOSAL);
an article without a supplier needs one chosen first. The "can start now / on a date" column of
§5 belongs to production orders and comes with M4.

**Open customer orders as demand.** `OpenCustomerOrderDemand` reserves the stock articles on
ORDER sales documents with status OPEN. Quantities already taken out by a completed pick list for
the order are deducted. A row's unit text is converted when the article converts it; otherwise
the quantity is read in the stock unit, as pick lists do.

**Extension point for M4.** `App\Modules\Inventory\Purchasing\PurchaseDemandSource` (label,
reserved per article in stock milli) and the singleton `PurchaseDemandSources`. Manufacturing
registers a source that explodes its open production orders' structures (§5, with scrap) in its
service provider; the proposal, the page and the order creation then include it unchanged. Source
`PRODUCTION_ORDER` exists in `PurchaseOrderSource` for orders created from a production order.

**Deviations from the text above.** `source` is MANUAL, PROPOSAL or PRODUCTION_ORDER (§2 says
"reorder point"; a proposal combines reorder points and customer orders). Only stock articles can
be ordered (receiving goes through goods receipts, which take stock articles only). No API: §2
does not specify one; the Connect API is unchanged. Purchase orders are in SEK; matching an
invoice in another currency compares the booked kronor and says so. The delivery address is one
text field, prefilled from the first warehouse with an address, otherwise the company's address.

**Personal data.** The recipient's e-mail, the delivery address and our reference are stored per
order and version; the audit log gets numbers, versions and methods only. The orders are in the
supplier's register extract. Erasing a supplier (art. 17) that has purchase orders anonymises
it instead of deleting it (the orders point at it) and clears `purchase_orders.sent_to`. The sent
versions, with their recipient and the supplier block of the snapshot, are append-only for the app
and are kept as sent: `TODO(legal-verify)` whether they are räkenskapsinformation (art. 17.3 b) or
must be anonymised too, which would need a privileged erasure path. `docs/gdpr/registerforteckning.md`
and `lagringstider.md` describe them.

**Open receipt follows the order.** A goods receipt made from an earlier version is rebuilt when a
sent order's rows are saved and when it is sent again (`PurchaseOrderReceipts::sync`): lines where
nothing has arrived follow their row (article, what remains, cost per stock unit), rows added in a
revision get lines, lines of removed rows are dropped. A row goods have arrived on keeps its article
and unit and cannot go below what arrived. Everywhere an order and its receipts are both locked, the
open receipts are locked first, then the order, as `ReceiveStock` does.

Still open (`TODO(legal-verify)`): whether a sent purchase order is räkenskapsinformation (BFL 5
kap. 6 §, an agreement of particular importance) and must be kept as such, or may be pruned
earlier (until decided, nothing is pruned); whether segregation of duties should forbid the
creator of an order above the approval amount from sending it; the §12.4 accounting check of
`value_ore` before receipts keep the exact line value.

## Addendum 2026-11-11: as built (M4), production orders and the books (§8)

Built in the `manufacturing` module: §8 (production orders, the two movement types, finished goods
and work in progress in the year-end valuation) and the production-order half of §5 (reservations
and the "can start now" view on the order page). Fixtures F-M1–F-M6 (cost) and F-V1–F-V3 (valuation)
are in `tests/Fixtures/manufacturing/production-orders.json`.

**Data.** `production_orders` (number from the series `production_orders`, shown TO-<n>; status;
article; the source version; the copy's name and batch `yield_milli`; `quantity_milli` in the stock
unit with what was typed and its `unit_id`; `location_id` where the finished goods are received;
`sales_document_id` + `sales_line_key` for the customer order row; planned start, due date; who and
when for release, start, finish and cancel; and at finish `produced_milli`, material, labour and
overhead cost, `overhead_bp` and the receipt's `unit_cost_ore`). The copy is two tables shaped like
`bom_rows`: `production_order_components` (net quantity, loss, typed quantity and unit, note, plus
`required_milli` for the whole order and `issued_milli`/`issued_value_ore`) and
`production_order_operations` (operation, batch minutes, `planned_minutes` for the order, note). The
latter is §13.8's table: M6 adds its workstation, remaining-minutes override and waiting time there.
Two append-only ledgers (model events and Lager's `inventory_append_only()` trigger, grant
`AppendOnly`): `production_order_issues` (component, article, location, stock movement, signed
quantity and value, date) and `production_labour_entries` (order operation, source PLANNED, ENTERED
or TERMINAL, signed minutes, the hourly cost when recorded, cost, date). Every table has `company_id`
and `BelongsToCompany`.

**Copy.** A new order copies the customer order row's own copy (§4) when it serves one, else the
article's bill of materials valid today, through the same shape as M2 (`StructureLines`, now shared
by `SaveRowStructure` and `SaveProductionOrder`, so loops, nesting, units and loss are checked the same
way). The template is never touched. Quantity and copy change only while the order is a draft; each
change recomputes `required_milli = round_half_up(gross × quantity ÷ batch)` (at least one milli) and
`planned_minutes = round_half_up(minutes × quantity ÷ batch)`.

**Status machine** (`ProductionOrderStatus`, `ProductionOrderLifecycle`): DRAFT → RELEASED (needs a
component; reserves) ⇄ back to DRAFT; RELEASED → STARTED (optionally issuing everything); while
STARTED, "Ta ut komponenter" issues what remains; STARTED → FINISHED; DRAFT/RELEASED/STARTED →
CANCELLED (a started order needs a reason). Each step locks the order row and is audited
(`manufacturing.production_order_*`, ids, numbers, dates and amounts only).

**Movements and cost.**

- `PRODUCTION_ISSUE` out: each component is taken from its default location first, then the others
  holding it in code order; what is not on hand is refused unless negative stock is allowed. The
  value of an issue is what the article's cost value under the company's method loses (cost value on
  the day before the issue minus after it, from the movement ledger), so FIFO takes the oldest layers
  and the average takes the average, and stock plus work in progress always adds up to what came in
  (F-M5, F-M6). With negative stock allowed, the part not on hand is valued at the purchase price.
  Components that are not stock articles are not issued; at finish they count at their costing price
  (`ManufacturingBook`: own bill of materials, latest receipt, purchase price) and an article without
  one blocks the finish.
- Labour: on finish each operation gets a labour entry dated the finish day. Minutes entered are the
  operation's total, so only the difference to what was already reported is added (ENTERED); an
  operation nobody reported on gets its planned minutes (PLANNED); an operation with terminal minutes
  and nothing entered gets nothing more. The cost uses the operation's hourly cost at that moment.
  M5's terminal writes TERMINAL rows to the same table.
- `PRODUCTION_RECEIPT` in: produced quantity (the order's by default) at
  `unit_cost = round_half_up((material + labour + overhead) × 1000 ÷ produced_milli)`, at the order's
  location. Overhead is `ManufacturingSettings::overhead_bp` of material plus labour, only when the
  company sets `overhead_in_stock_value` (Tillverkning → Inställningar, off by default); the costing
  overhead of M2 is unchanged. As with M3 receipts, `stock_movements.value_ore` is not written, so the
  receipt carries the rounded cost per stock unit.
- Cancel: every location's net issue goes back as one `PRODUCTION_ISSUE` movement in at the issued
  cost per stock unit (rounded half up) and a negative issue row with the exact value. Reported labour
  stays on the order.

**No voucher per order.** The year-end valuation includes:

- finished goods, as ordinary stock articles valued from their movements (the receipt's cost), on the
  article's stock class pair; and
- work in progress (`WorkInProgress::at`): for orders neither finished nor cancelled on the day, the
  issues and labour up to that day, plus overhead when the company includes it. It is a new
  extension point in Lager: `StockValuationAddition`/`StockValuationAdditions`, which
  `BookStockValuation::plan()` adds as its own group on its own pair (`wip_stock_account`/
  `wip_change_account`, 1440/4940 by default), in the same voucher, included when it has a value or
  its stock account carries a balance. Lager knows nothing of production. The 97 % rule applies to
  the stock classes only.

**Purchase proposal.** `ProductionOrderDemand` (a `PurchaseDemandSource`) reserves
`required − issued` of stock components of released and started orders. A second small extension,
`PurchaseSupplySource` (`PurchaseDemandSources::registerSupply()`), lets `ProductionOrderSupply` count
the finished goods of released and started orders as coming, so an article made for a customer order
is not proposed for purchase as well. The order page shows per component: needed, issued, on hand,
reserved by other released and started orders, coming on purchase orders, short. Purchase orders for
the shortage are made from the purchase proposal (M3); there is no button on the order page.

**Screens.** Tillverkning → Tillverkningsorder (`/tillverkning/tillverkningsorder`, filters `oppna`,
`utkast`, `frislappta`, `startade`, `fardiga`, `makulerade`, `alla` and `sok`), `/ny` (from an article
or from a customer order row, `?kundorder=&rad=`; the order's Stycklistor och kalkyl panel links each
row there and lists its orders), and the order page. Every irreversible step has a Swedish
confirmation and every save a toast. Lager's closing page shows the work in progress per order.
Permissions are `manufacturing:view` and `manufacturing:manage` (§1); there is no separate
production permission. Query-count tests cover the list and the order page.

**Design choices where this ADR left room.**

1. Separate tables for components and operations instead of one copy table, so M5 can reference an
   order operation and M6 can extend it.
2. Labour is a dated ledger, not a field on the operation, so work in progress can be valued on any
   past day and M5 feeds it without a schema change.
3. Issues are valued by the valuation delta rather than by a stored cost on the out movement, which
   keeps ADR-039's ledger unchanged (out movements carry no cost).
4. The finished article must be a stock article; the order refuses one that is not.
5. The year-end change of finished goods is booked on the article's stock class pair; there is no
   separate finished-goods class yet (see the open questions).

The system documentation is at 0.132 (section 5o and 5j).

Still open (`TODO(legal-verify)`, also in code and in the system documentation §8):

- whether K2 (BFNAR 2016:10 ch. 12, point 12.10) and K3 (ch. 13) allow, require or forbid indirect
  costs (overhead) in the cost of own-manufactured goods; NONOX counts material and direct labour,
  overhead only by the company's choice;
- the BAS accounts for work in progress and finished goods (1440/1450) and their change accounts
  (4940, and 4950 or 4970) beside 1460/4960;
- whether ÅRL 4:9 accepts the operation's standard hourly cost as an approximation of the actual
  cost;
- whether the K2 97 % rule (point 12.5) and lägsta värdets princip apply to work in progress.
