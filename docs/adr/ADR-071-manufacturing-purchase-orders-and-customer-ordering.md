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

- whether K2 (BFNAR 2016:10 ch. 11) and K3 (ch. 13) allow, require or forbid direct labour
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
