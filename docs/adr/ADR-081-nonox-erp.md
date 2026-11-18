# ADR-081: NONOX as ERP

- Status: **Accepted** (owner 2026-11-18: "Ja" to all 24 recommended defaults and to the AGENTS.md §4 additions)
- Date: 2026-11-18
- Queue: item 26 in `docs/status/nummer-1.md` (`erp`)
- Builds on: ADR-007 (vouchers), ADR-008 (SIE4), ADR-009 (cost centres and
  projects), ADR-013 (supplier invoices), ADR-017 (module framework), ADR-023
  (inbox), ADR-028 (firm workspace), ADR-039 (inventory), ADR-060 (group
  overview and its addendum S2), ADR-066 (ask your figures, proposed), ADR-067
  (approval queue, proposed), ADR-071 (manufacturing and purchase orders),
  ADR-074 (work orders and ÄTA), ADR-079 (third-party modules and database roles)
- Format: follows ADR-037's "Amendment 2026-11-17: best in class"

No feature code ships with this ADR. It is built in the packages E0–E32 at the
end, in the stated waves, once it is accepted.

## Context

The owner decided on 2026-11-18: "Bygg in alla erp sakerna ovan inklusive
mindre kraven". Ten gaps separate NONOX from what a small or medium-sized
company expects of an ERP system: six large ones (group accounting, budget and
forecast, the purchasing process, inventory depth, MRP, project accounting) and
four smaller ones (a report builder, configurable approval workflows, document
management, permissions per cost centre or unit).

A survey of the code on `0ab036f6` (2026-11-18) found that a good part of the
ground is already built. Each gap below starts with what exists, by file path,
so that no package rebuilds it.

## Principles that apply to every gap

1. **The books stay the books.** Posted vouchers are immutable (AGENTS.md §1.2),
   voucher series stay gapless, `PostVoucher` and the voucher triggers are not
   changed by any package here. Nothing in this ADR posts a voucher on its own:
   where a package proposes bookkeeping (WIP, intra-group drafts), it creates
   **drafts** that a person with `vouchers:post` in that company posts.
2. **One company, one ledger.** Consolidation never writes into any member
   company's ledger. Group figures live in the separate group ledger of ADR-060
   §2.4. No package writes a row with another company's `company_id`, except
   the one opt-in delivery in gap 1 (intra-group invoices), which is designed
   exactly like an incoming e-mail.
3. **Tenancy.** Every new company-scoped table has `company_id` → `teams`,
   `BelongsToCompany`, indexes leading with `company_id`, a tenancy test
   (another company's id → 404) and a decision in
   `App\Support\Database\DatabaseGrants`. Migrations are additive only (new
   tables, new nullable columns, new indexes). `CompanyScope::bypass` is used
   only where this ADR names it, each time with a comment.
4. **Deterministic and explainable.** Forecasts, MRP and matching are plain
   rules anyone can recompute by hand; every proposal carries its reasons.
   NONOX AI (ADR-018) is never needed and never chooses.
5. **A person decides.** Proposals become drafts; approvals are explicit; no
   purchase order, invoice or voucher is sent or posted by a rule.
6. **No N+1.** Every new page and engine run loads in a fixed number of queries,
   proven by a query-count test with a short and a long history (QUICKSTART §6).
7. **Money in öre**, quantities in milli-units (the ADR-071 §12 convention),
   percentages in basis points.
8. **Audit log** entries hold ids, enums, counts and amounts only, never names,
   document titles or free text (QUICKSTART §6).
9. **Dimension scopes (gap 10) are respected by every later package.** A
   package that reads ledger rows, budgets, projects or documents goes through
   `DimensionScope` (gap 10) once it exists; until then it is a single call
   site that returns "unrestricted".

## Gap 1: group accounting (koncernredovisning)

### What exists

- **G.1, per-user overview** (ADR-060 stage 1): `App\Domain\Group\GroupOverview`,
  `GroupAccess`, `MarkKind`, `App\Models\CompanyGroup`, `CompanyGroupMember`,
  `CompanyGroupMark`, `App\Actions\Group\ManageCompanyGroup`, the Livewire
  screen under `app/Livewire/Group`, route `/koncern` and
  `/koncern/{group}/csv` (`routes/web.php` 204–205). Elimination *suggestions*
  for three pairs, nothing stored.
- **S2-1.1, shared group model and access** (ADR-060 addendum §3.1, as built):
  `company_holdings` (`App\Models\CompanyHolding`, `BelongsToCompany`),
  `corporate_groups`, `corporate_group_members`, `corporate_group_roles`
  (migrations `2026_12_16_100000/100100/100200`), `App\Domain\Group\ControlBasis`,
  `GroupAccessLevel`, `GroupRole`, `GroupHoldings`, `GroupRoleBinding`,
  `GroupAuditTrail`, actions `RecordHolding`, `CreateCorporateGroup`,
  `ConfirmGroupMember`, `RemoveGroupMember`, `GrantGroupRole`,
  `RevokeGroupRole`; `tests/Tenancy/CorporateGroupTenancyTest.php`.
- **Not built:** S2-1.2 to S2-1.6 (group screen, intercompany counterparties and
  row tags, consolidation engine and run log, eliminations and the group ledger,
  group reports) and all of stage 2 (ADR-060 §4). No intra-group transaction
  support exists: an invoice from one NONOX company to another is an ordinary
  customer invoice and an ordinary supplier invoice, each typed by hand.

### Scope

This ADR **adopts ADR-060's addendum as the design** for stage 1 and does not
redesign it. It adds:

1. **Stage 1, as specified in ADR-060 §3.2:** S2-1.2 to S2-1.6, unchanged in
   content (packages E22–E26).
2. **Intra-group transactions** (new, E27):
   - *Intra-group invoice delivery.* When company A issues a customer invoice to
     a customer whose organisation number belongs to a confirmed member B of
     the same corporate group, A may choose "Leverera till koncernföretaget".
     The invoice is delivered into B's NONOX Inbox (ADR-023) as a received
     e-invoice: the same Peppol BIS Billing 3.0 XML and PDF that A could have
     e-mailed or sent over Peppol (ADR-050). B's own users interpret, register
     and approve it as any supplier invoice. Both sides get their intercompany
     row tags from their own counterparty marking (S2-1.3).
   - *Paired drafts for transactions without an invoice* (loans, interest,
     group contributions, dividends decided): "Skapa motsvarande utkast" on a
     posted voucher in A proposes the mirrored rows for B and creates a
     **draft voucher in B**, only when the acting user holds `vouchers:create`
     in B, inside `CurrentCompany::run($b, …)`. It is never posted
     automatically. The two vouchers are linked only by an
     `intercompany_pairs` row in each company naming the other side by
     organisation number and voucher number, never by the other tenant's id.
   - *Reconciliation of intra-group balances* per pair and kind, with both
     sides and the difference: already the mismatch list of S2-1.5.
3. **Stage 2 (statutory group accounts)** is in the owner's scope ("all ERP
   things") and is planned here as packages E28–E31, mapped to ADR-060 §4's
   S2-2.1 to S2-2.7. Each is **gated**: it needs the stage 2 addendum to
   ADR-060 accepted and the human decisions H1–H8 named per package. This ADR
   does not decide any accounting method for stage 2.

### Data model

Stage 1 tables are ADR-060 §2.3–2.4 verbatim (`intercompany_counterparties`,
`intercompany_row_tags`, `group_account_maps`, `group_entries`,
`group_entry_rows`, `corporate_group_runs`). New in E27:

- `intercompany_deliveries` (company-scoped, `company_id` = the **sender** A):
  `invoice_id`, `recipient_org_number`, `corporate_group_id`, `status`
  (`DELIVERED`, `REFUSED`), `delivered_at`, `delivered_by`. A never learns
  anything about B's handling beyond "levererad".
- `intercompany_receipt_settings` (company-scoped, `company_id` = the
  **recipient** B): `sender_org_number`, `enabled`, `enabled_by`. Delivery is
  refused unless B's owner or admin has turned it on for that sender.
- The delivered document becomes an `inbox_messages` row with `company_id` = B
  and a new source value `GROUP_DELIVERY`, written by a dedicated action
  `DeliverIntercompanyInvoice` inside `CurrentCompany::run($b, …)`. This is the
  one cross-company write in this ADR (principle 2). Its comment names this
  ADR; its tenancy test proves that the sender cannot read the message, its
  status or B's supplier invoice, and that delivery without B's setting, to a
  company outside the group, or after the group membership ended, answers as
  if B did not exist.
- `intercompany_pairs` (company-scoped, one row in each company):
  `voucher_id` (own), `counterparty_org_number`, `counterparty_voucher_ref`
  (series and number as text, entered or copied), `kind`
  (`IntercompanyKind`), `created_by`.

### Permissions

As ADR-060 §2.2: group access is derived live from memberships
(`GroupAccess::levelFor`), administration is "owner or admin in every member
and the parent", the group role `viewer` grants no permission string.
Intercompany tags need `vouchers:create` or `accounts:manage`; reading them
`vouchers:view`. Delivery needs `invoices:send` in A; the receipt setting
`company:update` in B; a paired draft `vouchers:create` in both companies.

### Accounting impact

- No voucher is ever written by consolidation; eliminations are
  `group_entries` in the group ledger (ADR-060 §2.4), append-only by their own
  trigger, not räkenskapsinformation of any legal person.
- Row tags are metadata beside `voucher_rows`, never columns in them;
  `ledger:verify` and the hash chain are untouched (S2-1.3's immutability test).
- Paired drafts are ordinary drafts in B, posted by B's own people under B's
  ordinary rules (open fiscal year, unlocked period, gapless numbering at
  posting).
- `docs/systemdokumentation/README.md` gets a section "Koncern" describing the
  group ledger, the run log and its reproducibility, and intra-group delivery
  (E24, E25, E27). The group ledger is described as internal reporting until
  stage 2 is accepted.

### Tenancy risks (P0)

- Consolidation reads each member inside `CurrentCompany::run()` with
  `LedgerReports`, never with a scope bypass, and only the companies returned
  by `GroupAccess::includedCompanyIds($group, $from)` (ADR-060 S2-1.1 rule).
- CONSOLIDATED access must never return a per-company figure; the payload test
  of S2-1.4 is mandatory.
- Intra-group delivery is the single cross-tenant write; its guards are listed
  above and get their own test file `tests/Tenancy/IntercompanyDeliveryTenancyTest.php`.
- A paired draft is created by a user with rights in both companies; the draft
  must not carry A's ids (account ids, cost centre ids, project ids): rows are
  rebuilt from B's chart by account number and B's objects by code, and refused
  when B lacks them.

### Legal items

- `TODO(legal-verify)`: ÅRL 7 kap. 1–3 §§: when a parent must prepare group
  accounts, and the exemption for smaller groups. As understood, a group is
  "större" under ÅRL 1 kap. 3 § when more than one of these was exceeded in
  each of the last two years: an average of 50 employees, 40 MSEK balance
  sheet total, 80 MSEK net turnover, computed for the group. Confirm the
  values and the gross/net method before E28 shows any duty.
- `TODO(legal-verify)`: K3 (BFNAR 2012:1) chapter 9 (group accounts and shares
  in subsidiaries) and chapter 19 (business combinations); a parent preparing
  group accounts applies K3 (ADR-060 S2-R3). Combinations under common control
  (H2) stay blocked.
- `TODO(legal-verify)`: whether an invoice delivered through NONOX between two
  companies is "received" for BFL 5 kap. 7 § and kept in the form received for
  both parties; and whether the invoice is still an e-invoice under ML 2023:200
  17 kap. when it never leaves NONOX.
- `TODO(legal-verify)`: koncernbidrag (IL 35 kap.) are booked in each company by
  its own people; NONOX only proposes mirrored drafts and gives no tax advice.
- H1–H8 of ADR-060 §5 remain open and gate E28–E31.

### Out of scope

Foreign subsidiaries and currency translation; proportional consolidation;
associates under the equity method beyond the ADR-060 block; automatic
posting of anything in a subsidiary; filing group accounts with Bolagsverket.

## Gap 2: budget and forecast per account, cost centre and project

### What exists

- No ledger budget. `planning_budgets` (ADR-037 gap 2) is a staff-cost budget
  per week and area inside Planering only.
- Dimensions: `cost_centers`, `projects` (`App\Models\CostCenter`,
  `App\Models\Project`: code, name, is_active), `voucher_rows.cost_center_id`
  and `project_id` (ADR-009).
- Reports: `App\Domain\Accounting\Reports\LedgerQuery` (filters on one cost
  centre or project), `LedgerReports`, `ReportFilters` (`saldobalans`,
  `resultat`, `balans`, `huvudbok`, `verifikationer`), the Livewire
  `App\Livewire\Accounting\Reports`, `App\Domain\Insights\BusinessKpis`.
- SIE: `app/Support/Sie` reads and writes `#DIM`/`#OBJEKT`; `#PBUDGET` and
  `#PSALDO` are explicitly neither read nor written (ADR-009).

### Scope

1. **Budget versions** per fiscal year: any number, one marked *active*;
   kinds `BUDGET` and `FORECAST`; status `DRAFT` or `LOCKED` (a locked version
   is read-only, can be copied).
2. **Budget lines** per account (result accounts 3000–8999 in v1), optional
   cost centre, optional project, per month of the fiscal year.
3. **Entry**: a grid (accounts × months) with "fördela jämnt", "som i fjol"
   (last year's actuals per month), "fjolåret + x %", paste from a spreadsheet,
   and CSV import/export. Keyboard-first like the voucher editor.
4. **Actual vs budget**: income statement per month and year to date with
   budget, actual, difference and difference in %, filterable by cost centre
   and project, drill-down to the ledger (the existing account analysis).
5. **Rolling forecast**: a `FORECAST` version made from a cut-off month:
   actuals for every month up to the cut-off, and for the remaining months one
   of three deterministic methods per line: the active budget, the average of
   the last N closed months (default 3), or the same month last year × a
   growth factor. Overrides per cell are allowed and marked. "Rullande 12
   månader" shows the next twelve months across a fiscal year boundary by
   reading two versions.
6. **SIE `#PBUDGET`**: import into a new budget version (two-step, like the SIE
   import: parse → report → confirm) and export of the active version, behind
   a checkbox "Ta med budget" in the SIE export.

### Data model

- `budget_versions` (company): `fiscal_year_id`, `name` (≤ 60), `kind`
  (`BUDGET`, `FORECAST`), `status` (`DRAFT`, `LOCKED`), `is_active` (partial
  unique per company, fiscal year and kind), `forecast_cutoff` (date, null for
  budgets), `source_version_id` (null), `method` jsonb (forecast method per
  line group), `created_by`, `locked_by`, `locked_at`.
- `budget_lines` (company): `budget_version_id` (cascade), `account_id`,
  `cost_center_id` null, `project_id` null, `period` (date, first of month),
  `amount_ore` bigint **signed as in the ledger** (debit positive, credit
  negative, as SIE `#PBUDGET`), `is_override` bool. Unique on
  `(budget_version_id, account_id, COALESCE(cost_center_id,0),
  COALESCE(project_id,0), period)`. Revenue is shown positive in the UI.
- A `LOCKED` version is refused by the model and by a trigger for line changes
  (a small trigger of its own, not on voucher tables).

### Permissions

| Permission | owner | admin | accountant | bookkeeper | approver | auditor |
|---|---|---|---|---|---|---|
| `budgets:view` | ✓ | ✓ | ✓ | ✓ | | ✓ |
| `budgets:manage` (create, edit, import, lock, forecast) | ✓ | ✓ | ✓ | | | |

Under dimension scopes (gap 10) a scoped member sees and edits only lines on
their own objects.

### Accounting impact

None on the ledger: budgets are not posted and not part of the hash chain.
The systemdokumentation gets a short section "Budget och prognos" saying so,
and describing SIE `#PBUDGET` import/export (E4), because SIE behaviour
changes. ADR-008/ADR-009's "not written or read" for `#PBUDGET` is amended by E4.

### Tenancy risks

Ordinary company scope. The SIE import must resolve accounts and objects by
number and code inside the importing company (as today for `#TRANS`).

### Legal items

- `TODO(legal-verify)`: whether a budget is räkenskapsinformation (BFL 1 kap.
  2 § första stycket 6) or may be deleted freely. Proposed: not
  räkenskapsinformation; versions are kept until deleted by a person, deletion
  is audited. A budget used as a basis for an assessment in the annual report
  (an impairment test, going concern under K3) should be kept by the user, so
  E3 refuses to delete a LOCKED version whose fiscal year is closed.
- `TODO(legal-verify)`: the SIE 4 specification's exact `#PBUDGET` syntax
  (year index, period `YYYYMM`, object list, sign) against the published SIE
  4B text before E4 ships.

### Out of scope

Balance sheet and liquidity budgets (v1 reads and reports `#PBUDGET` lines on
balance accounts as skipped); driver-based or statistical forecasting; budget
approval workflows (gap 8 can add one later); quantities and `#PSALDO`.

## Gap 3: the purchasing process

### What exists (built in the `inventory` module, ADR-071 M3)

- **Purchase orders**: `app/Modules/Inventory/Models/PurchaseOrder`,
  `PurchaseOrderRow`, `PurchaseOrderVersion` (append-only snapshots),
  `PurchaseOrderInvoice`; migration
  `app/Modules/Inventory/database/migrations/2027_01_08_100000_create_purchase_orders.php`;
  statuses `DRAFT → SENT → PARTIALLY_RECEIVED → RECEIVED | CANCELLED`
  (`Domain/PurchaseOrderStatus`); sources `MANUAL`, `PROPOSAL`,
  `PRODUCTION_ORDER`; actions under `Actions/Purchasing/` (`SavePurchaseOrder`,
  `SendPurchaseOrder`, `PurchaseOrderLifecycle`, `CreatePurchaseOrdersFromProposal`,
  `MatchSupplierInvoice`); PDF and e-mail (`Purchasing/PurchaseOrderDocument`,
  `Mail/PurchaseOrderMail`).
- **Reorder points**: `articles.reorder_point_milli`, `reorder_quantity_milli`
  (company-wide), the proposal `Purchasing/PurchaseProposal` with demand
  sources (`PurchaseDemandSources`, `OpenCustomerOrderDemand`, and manufacturing's
  production order demand registered in `ManufacturingServiceProvider`).
- **Approval of orders**: one amount threshold,
  `purchase_order_approval_above_ore` (Lager's settings), above which sending
  needs `purchase-orders:send` (approver role) instead of `:manage`. No
  record of who approved, no multi-step flow, no segregation of duties.
- **Matching**: `Purchasing/PurchaseOrderMatch` shows ordered, received and
  invoiced value per invoice and flags quantity and price differences;
  `Livewire/Purchasing/PurchaseOrderMatchPanel`. Header-level only, nothing
  stored, no tolerance, no link to the supplier invoice's attest.
- **Goods receipts**: `ReceiveStock`, `stock_receipts.purchase_order_id`,
  `stock_receipt_lines.purchase_order_row_id`.
- **Supplier invoice attest** (ADR-013): `App\Actions\Payables\ApproveSupplierInvoice`,
  `SetSupplierApprovalRule`, `teams.separate_supplier_approval`.
- **Limits**: only stock articles can be ordered; orders in SEK; no
  requisitions.

### Scope

1. **Purchase requisitions (inköpsanmodan)**: anyone with
   `purchase-requisitions:create` asks for goods or services: article or free
   text, quantity, unit, estimated price, needed-by date, account, cost centre,
   project, reason. Requisitions go through the approval engine (gap 8,
   subject `PURCHASE_REQUISITION`); approved ones are converted by a buyer
   into purchase order rows (several requisitions into one order per
   supplier), keeping the link.
2. **Approval of orders** through the approval engine (subject
   `PURCHASE_ORDER`), replacing the single threshold; the threshold becomes
   the default policy so behaviour is unchanged until a company edits it. A
   sent order carries who approved which version.
3. **Non-stock order rows**: services and consumables with an account, cost
   centre and project instead of an article. Received by a "leverans
   bekräftad" tick (no stock movement).
4. **Reorder points per warehouse**: in gap 4 (E13); the proposal reads them
   when set, else the company-wide value.
5. **Three-way matching per row**: supplier invoice rows matched to purchase
   order rows and receipt lines, with tolerances (quantity %, price %, absolute
   kronor), a stored match result, and an attest hook: an invoice outside
   tolerance cannot be approved without an override with a reason by a user
   holding `supplier-invoices:approve` (or, by company setting, only warns).

### Data model

- `purchase_requisitions` (company): `number` (series
  `purchase_requisitions`), `requested_by`, `status` (`DRAFT`, `SUBMITTED`,
  `APPROVED`, `REJECTED`, `CONVERTED`, `CANCELLED`), `needed_by`, `reason`
  (≤ 500), `approval_request_id` null.
- `purchase_requisition_rows` (company): `article_id` null, `description`,
  `quantity_milli`, `unit_id`, `estimated_price_ore`, `account_id` null,
  `cost_center_id` null, `project_id` null, `supplier_id` null,
  `purchase_order_row_id` null (set on conversion).
- `purchase_order_rows` gains nullable `account_id`, `cost_center_id`,
  `project_id`, `confirmed_at`, `confirmed_by`; `article_id` becomes
  logically optional for non-stock rows. **`article_id` is NOT NULL today**:
  E10 adds a nullable `article_id` replacement only if it can be done
  additively; otherwise non-stock rows go in a new table
  `purchase_order_service_rows`. The builder decides by the additive-only rule
  and records it.
- `purchase_orders` gains `approval_request_id` null.
- `purchase_match_results` (company): `supplier_invoice_id`,
  `supplier_invoice_row_id` null, `purchase_order_row_id` null,
  `stock_receipt_line_id` null, `ordered_ore`, `received_ore`, `invoiced_ore`,
  `quantity_diff_milli`, `price_diff_ore`, `status` (`WITHIN`, `OUTSIDE`,
  `OVERRIDDEN`, `UNMATCHED`), `override_reason`, `overridden_by`,
  `computed_at`.
- Tolerances in Lager's settings: `match_quantity_tolerance_bp`,
  `match_price_tolerance_bp`, `match_absolute_tolerance_ore`,
  `match_outside_blocks_attest` (bool, default false = warn).

### Permissions

New: `purchase-requisitions:create`, `purchase-requisitions:view` (own always;
all with `:view`). Existing `purchase-orders:view|manage|send` stay;
`:send` now means "may send an approved order". Matching overrides need
`supplier-invoices:approve`. Proposed grants: owner, admin all; accountant
view; bookkeeper create and view; approver view (approves through the
engine); auditor view.

### Accounting impact

None directly: requisitions, orders and matches create no voucher (ADR-071
§2). The supplier invoice still books the cost once. The attest hook changes
when an invoice may be approved, which is internal control:
systemdokumentation 5a (Leverantörsfakturor) gets a paragraph on matching and
overrides (E11).

### Tenancy risks

Ordinary. Conversion of requisitions to order rows and matching must load
both sides inside the company; ids from Livewire state are re-resolved.

### Legal items

- `TODO(legal-verify)` (carried from ADR-071 M3): whether a sent purchase
  order is räkenskapsinformation (BFL 1 kap. 2 § första stycket 6) — nothing is pruned until
  decided; the same question for approved requisitions.
- `TODO(legal-verify)`: requisitions name the requesting employee and may hold
  free text; GDPR art. 5.1 c and retention in `lagringstider.md`.

### Out of scope

Supplier portals and catalogues (punchout), tendering (anbud), framework
agreements with call-offs, Peppol Order (ADR-050 later step), goods received
not invoiced accruals at month end, orders in foreign currency.

## Gap 4: inventory depth

### What exists (ADR-039, `app/Modules/Inventory`)

- **Several locations**: `stock_locations` as a tree (`Domain/LocationType`:
  `WAREHOUSE`, `ZONE`, `SHELF`, `BIN`), `Support/LocationTree`,
  `Livewire/LocationsPage`; warehouses carry an address.
- **Transfers**: `MovementType::Transfer` as two movements with one
  `transfer_group`, `Actions/RecordMovement` (kind `transfer`) and the scanner
  (`RunScanOperation`). Immediate only: no transfer order, no in-transit.
- **Picking**: `pick_lists`, `pick_list_lines`, `Actions/PickLists`,
  `Livewire/PickListsPage`, PDF (`Http/PickListPdfController`); default
  location first, then others, sorted by `sort_key`.
- **Valuation**: FIFO or weighted average, `Support/StockLedger`,
  `StockValuationReport`, `Actions/BookStockValuation` (year-end only).
- **Not built**: batch and serial numbers (no column anywhere), packing,
  shipments, delivery notes (följesedel), per-warehouse reorder points.

### Scope

1. **Batch and serial numbers**: per article `tracking` = `NONE`, `BATCH` or
   `SERIAL`, optional best-before/expiry. Receipts, production receipts and
   returns capture the batch or serials; picks, issues, scrap and transfers
   choose them (FEFO suggestion: earliest expiry first, then FIFO). Stock per
   location and batch. A trace report both ways: from a batch or serial to
   its supplier and receipt, and to the customers, orders and invoices it went
   to (recall list).
2. **Transfer orders** between warehouses: `DRAFT → SENT (in transit) →
   RECEIVED`, with a transit location per warehouse pair, partial receipt and
   a transfer document PDF.
3. **Reorder points per warehouse** (overrides the article's company value).
4. **Packing**: from a completed pick list, packages with weight and
   dimensions, contents per package (with batch/serial), a delivery note PDF,
   a manual carrier and tracking number. A shipment marks the customer order's
   rows delivered.

### Data model

- `articles` gains `tracking` (string, default `NONE`), `expiry_required`
  (bool).
- `stock_lots` (company): `article_id`, `kind` (`BATCH`, `SERIAL`), `code`
  (≤ 60), `expires_on` null, `received_on`, `supplier_id` null,
  `stock_receipt_line_id` null; unique `(company_id, article_id, kind, code)`.
- `stock_movements` gains `lot_id` null; `stock_lot_levels` (company):
  `article_id`, `location_id`, `lot_id`, `quantity_milli`; a serial's level is
  0 or 1000. `stock_levels` stays the total and is verified against the sum of
  lot levels by `VerifyStockCommand`.
- `pick_list_lines`, `stock_receipt_lines`, `stock_count_lines` and
  manufacturing's issue/receipt rows gain `lot_id` null (or a child table
  `*_lots` when one line covers several batches; the builder chooses per table).
- `stock_transfers` (company): `number`, `from_location_id`,
  `to_location_id`, `transit_location_id`, `status`, `sent_at`, `received_at`;
  `stock_transfer_lines`: `article_id`, `lot_id` null, `quantity_milli`,
  `received_milli`. `LocationType` gains `TRANSIT`.
- `article_location_settings` (company): `article_id`, `warehouse_id`,
  `reorder_point_milli`, `reorder_quantity_milli`.
- `shipments` (company): `number`, `pick_list_id`, `customer_id`,
  `sales_document_id` null, `carrier` (≤ 60), `tracking_number` (≤ 60),
  `shipped_at`, `status`; `shipment_packages`: `weight_grams`, dimensions;
  `shipment_package_lines`: `article_id`, `lot_id` null, `quantity_milli`.

### Permissions

Existing `inventory:view`, `inventory:count` (receive, pick, move, pack),
`inventory:manage` (articles, tracking settings, reorder points),
`inventory:settings`. No new permission strings; Lagerpersonal (`stock_clerk`)
packs and transfers but sees no prices, as today.

### Accounting impact

None on the ledger: stock still meets the books at the year-end valuation
(ADR-039). Valuation stays per article over the company (FIFO layers or
average), not per batch; a transfer carries no cost (`bringsCost()` is false
for `TRANSFER`). The systemdokumentation is unchanged unless E12's builder
changes the valuation, which this ADR does not allow.

### Tenancy risks

Ordinary. The trace report crosses customers and invoices inside one company;
it must use the company's own scope and the invoice policies.

### Legal items

- `TODO(legal-verify)`: traceability duties for food (EU 178/2002 art. 18)
  and other regulated goods: NONOX offers the data, it does not certify
  compliance; the manual says so.
- `TODO(legal-verify)`: whether serial numbers tied to a private customer are
  personal data needing their own line in `registerforteckning.md`.

### Out of scope

Carrier integrations and label booking, wave picking and pick routes,
cross-docking, consignment stock, transfers between companies (each company
is its own ledger, ADR-039), valuation per batch.

### As built (E14, 2026-11-18)

- **Tables** (`2027_01_24_100000_create_shipments`): `shipments`,
  `shipment_packages` (weight in grams, length/width/height in millimetres,
  entered as kilograms and centimetres), `shipment_package_lines` (article,
  `lot_id` null, quantity in thousandths, unique per package, article and
  lot). Status `DRAFT`, `SHIPPED`, `CANCELLED`; a partial unique index allows
  one live shipment per pick list. `shipped_at` is a timestamp; `ship_to` is
  the customer's name and address copied when the shipment is sent.
- **What may be packed** is read from the PICK movements of the pick list
  (`ShipmentContents::picked`), per article and lot, not from the pick list
  lines: a tracked article picked without a chosen lot is split over lots by
  the ledger, so the movements are the truth. A shipment can only be sent when
  everything picked is in a package and no package is empty.
- **"Rows delivered"**: order rows are rewritten on every save and a pick
  list is made per article, so delivery is computed, not stored on the rows:
  `ShipmentContents::delivery` sums shipped package lines per article against
  the order's rows and the shipment page shows "levererade av beställda".
  The order's status is not changed.
- **Permissions**: `inventory:count` for packing and sending (as for picking),
  `inventory:view` for reading and the delivery note. No new permission
  string. The delivery note (`inventory.shipments.deliveryNote`) has no prices.
- **Retention and erasure** (review): `inventory:prune-shipments` runs nightly
  (04:38). A shipped shipment keeps `ship_to`, `carrier` and `tracking_number`
  to the end of the seventh calendar year after `shipped_at`, then they are
  emptied (packages, lots and serials stay); a cancelled shipment is deleted
  90 days after it was cancelled. `TODO(legal-verify)`: seven years assumes the
  delivery note is räkenskapsinformation. `EraseRegisterEntry::customer`
  counts a shipment as use (the customer is anonymised, never deleted) and
  empties `ship_to` in the same transaction; `DataSubjectExport::customer`
  has a `forsandelser` section. After erasure or pruning the delivery note
  shows "Mottagare saknas"; only a draft reads the live customer.
  `setTracking` needs a shipped shipment; `ship` refuses a date in the future
  or before the goods were picked.
- **Not built**: carrier integration, label booking, partial shipments of one
  pick list (pack in several lists instead), editing a sent shipment except
  carrier and tracking number, lot trace entries for shipments (the trace still
  reaches the customer through the pick list).

## Gap 5: material requirements planning (MRP) from orders and forecasts

### What exists

- **Netting without time**: `Inventory/Purchasing/PurchaseProposal` nets
  reserved demand (open customer orders, open production orders) against on
  hand and incoming orders, plus reorder points; no periods, no lead times.
- **Production planning**: `app/Modules/Manufacturing/Planning`
  (`ProductionPlanner`, `PlanSnapshot`, `Engine/Scheduler`,
  `Engine/MaterialPlanner`, `Availability`, `Suggestions`) does finite-capacity
  scheduling of existing production orders with a material ready date; a short
  component without a purchase order gets "today + lead time" and the flag
  "antaget materialdatum" (ADR-071 §13.3). Structure explosion in
  `Inventory/Domain/StructureMath`, `Structures/*`, production order demand in
  `Manufacturing/Production/ProductionOrderDemand`, supply in
  `ProductionOrderSupply`.
- ADR-071 §11 put "MRP over a forecast period" out of scope. **This ADR
  reverses that.**
- **Not built**: a demand forecast per article, lead times as data on the
  article or supplier, safety stock, time buckets, planned orders.

### Scope

1. **Demand forecast per article** (`demand_forecasts`): per week or month,
   entered by hand, or computed deterministically from history (the average
   of the same period length over the last N periods of picks, POS sales,
   recipe sales and production issues, default 3 months), always editable,
   labelled by source.
2. **Planning data per article**: lead time in days (purchase or production),
   safety stock, minimum lot and lot multiple, planning method (`NONE`,
   `REORDER_POINT`, `MRP`).
3. **MRP run** (`App\Modules\Manufacturing\Mrp\MrpEngine`, pure; the module
   lives with manufacturing but works for trading companies too when Lager is
   on): for a horizon (default 13 weeks, max 52) in weekly buckets, gross
   requirements = open customer order rows by delivery date + forecast
   (consumed by orders in the same bucket: the larger of the two, not the
   sum) + dependent demand from planned and open production orders exploded
   through the structures; scheduled receipts = open purchase orders by
   expected date + open production orders by forecast finish; net against
   projected on hand and safety stock; planned orders lot-sized and offset by
   lead time. Level by level (low-level code) so a component's demand includes
   every parent.
4. **Output**: `mrp_runs` and `mrp_planned_orders` with pegging (which demand
   each covers) and exception messages ("släpp nu", "försenad", "flytta fram",
   "överskott"). A person converts planned orders to DRAFT purchase orders
   (via the existing `CreatePurchaseOrdersFromProposal` path) or DRAFT
   production orders (`SaveProductionOrder`). Planned orders are deleted with
   their run after 30 days.
5. The finite-capacity planner of ADR-071 §13 is unchanged; MRP is infinite
   capacity and feeds it only through the production orders a person creates.

### Data model

- `articles` gains `lead_time_days`, `safety_stock_milli`,
  `lot_minimum_milli`, `lot_multiple_milli`, `planning_method` (all nullable
  or defaulted).
- `demand_forecasts` (company): `article_id`, `period_start` (Monday or first
  of month), `period_kind` (`WEEK`, `MONTH`), `quantity_milli`, `source`
  (`MANUAL`, `HISTORY`), `created_by`; unique per article, period and kind.
- `mrp_runs` (company): `horizon_weeks`, `run_by`, `inputs_hash`,
  `engine_version`, counts, `created_at`.
- `mrp_planned_orders` (company): `mrp_run_id`, `article_id`, `kind`
  (`PURCHASE`, `PRODUCTION`), `quantity_milli`, `release_on`, `due_on`,
  `supplier_id` null, `pegging` jsonb (demand references), `exception` null,
  `converted_type`/`converted_id` null.

### Permissions

New: `mrp:view`, `mrp:run` (run, edit forecasts and planning data, convert).
Converting into orders additionally needs `purchase-orders:manage` or
`manufacturing:manage`. Proposed grants: owner, admin, bookkeeper run;
accountant and auditor view.

### Accounting impact

None. Planned orders and forecasts are not bookkeeping.

### Tenancy risks

Ordinary. The engine reads everything once inside the company; a test with
two companies sharing article numbers proves no cross-reading.

### Legal items

None identified.

### Out of scope

Capacity-constrained MRP (the §13 planner covers capacity for real orders),
statistical forecasting and seasonality models, distribution requirements
planning across warehouses, MRP across companies.

## Gap 6: project accounting

### What exists

- `projects` (ADR-009): code, name, is_active only. `voucher_rows.project_id`;
  `supplier_invoice_rows.project_id` (`App\Models\SupplierInvoiceRow`);
  payroll `time_reports.project_id` and `employments` (`app/Modules/Payroll`);
  mileage trips (`App\Models\VehicleTrip`).
- `LedgerQuery` filters one project; the reports show a project's income
  statement for a period with opening balance zero.
- **Work orders** (ADR-074, `app/Modules/WorkOrders`): `work_orders.project_id`,
  price forms, contract sum, ÄTA, time and material entries, a-conto
  instalments (`PaymentInstalment`), invoice basis, and pågående arbeten at year
  end (`Domain/OngoingWork`, `Actions/ProposeOngoingWorkVouchers`) which
  proposes **draft** vouchers on the work order's project.
- **Gap**: customer invoice rows (`invoice_rows`, migration
  `2026_10_29_220000_create_invoicing_tables.php`) and quote/order rows
  (`sales_document_rows`) carry **no** cost centre or project, so revenue from
  invoices never reaches a project; no project register beyond the code; no
  project budget, no project P&L page, no billing plan outside work orders.

### Scope

1. **Project register**: customer, project manager (user), status (`PLANNED`,
   `ACTIVE`, `COMPLETED`, `CLOSED`), start and end, price form (`FIXED`,
   `TIME_AND_MATERIAL`, `INTERNAL`), contract sum, estimated total cost,
   default cost centre. The code still never changes (ADR-009).
2. **Dimensions on revenue**: cost centre and project on invoice rows, quote
   and order rows, and as a default on the article and the customer; carried
   to the invoice voucher (series B) and, under kontantmetoden, to the payment
   voucher (series C) in proportion to the rows, largest remainder to the öre,
   as supplier invoices already do (ADR-013). Credit invoices copy them.
3. **Project P&L**: revenue, costs by group (material, external services,
   personnel, other), contribution and margin, for the project's life and per
   period; against the project budget (budget lines with the project, gap 2)
   and against the estimate; drill-down to the rows.
4. **Work in progress (upparbetat)**: per project, for the period end, the
   same rules as `OngoingWork` (löpande räkning: recorded, not invoiced; fast
   pris: degree of completion from costs against the estimated total cost),
   generalised to projects that have no work order. A project without a work
   order and with price form `TIME_AND_MATERIAL` has no billable value in
   NONOX and shows "kräver arbetsorder för upparbetat värde" rather than a
   guessed figure. Year end proposes **draft** vouchers (1620/2420 as in
   ADR-074 §7), reversed on the first day of the next year, posted by a person.
5. **Billing against plan**: a billing plan per project (milestones with a
   date and an amount or a share of the contract sum, status `PLANNED`,
   `READY`, `INVOICED`); "Fakturera" creates a draft invoice with the project
   on its rows. Work orders keep their own instalments; the project page shows
   both.

### Data model

- `projects` gains nullable `customer_id`, `manager_user_id`, `status`
  (default `ACTIVE` for existing rows), `starts_on`, `ends_on`, `price_form`,
  `contract_sum_ore`, `estimated_cost_ore`, `default_cost_center_id`.
- `invoice_rows`, `sales_document_rows` gain nullable `cost_center_id`,
  `project_id`; `articles` and `customers` gain nullable
  `default_cost_center_id`, `default_project_id`.
- `project_billing_milestones` (company): `project_id`, `position`,
  `description`, `due_on`, `amount_ore` or `share_bp` (CHECK exactly one),
  `status`, `invoice_id` null.
- `project_wip_snapshots` (company): `project_id`, `as_of`, figures (öre),
  `method`, `voucher_id` null (the draft it proposed), `created_by`.

### Permissions

New: `projects:view` (register, P&L), `projects:manage` (register, billing
plan). Existing `accounts:manage` still creates objects (ADR-009) and implies
`projects:manage`. Invoicing from a milestone needs `invoices:create`; WIP
drafts need `vouchers:create`, posting `vouchers:post`. A project manager who
is a scoped member (gap 10) sees only their projects.

### Accounting impact

**Yes.** Revenue rows on invoice vouchers now carry cost centre and project,
and the invoice voucher groups rows by account and dimensions instead of by
account only. VAT, receivable and rounding rows carry none. Under
kontantmetoden the payment voucher splits proportionally. SIE export already
writes object lists on `#TRANS`. Posted vouchers are unaffected; the change
applies to invoices booked after deployment. WIP produces drafts only.
`docs/systemdokumentation/README.md` §5 (Fakturering) and §4.10
(Kostnadsställen och projekt) get a new version in E6; §5a5 (Bokslut) in E8.
An accounting check reviews E6 and E8 before merge.

### Tenancy risks

Ordinary; objects referenced by code inside the company (ADR-009). Project
manager is a user id: must be a member of the company, re-checked on read.

### Legal items

- `TODO(legal-verify)`: K2 (BFNAR 2016:10) 6.13–6.24 and K3 chapter 23 for
  WIP on projects without work orders (as ADR-074 §7 for work orders).
- `TODO(legal-verify)`: proportional dimension split on kontantmetoden payment
  vouchers needs the same review as the supplier side got (AUD-FAKT-3).

### Out of scope

Resource planning per project (Planering and manufacturing planning cover
people), earned value management, project invoicing in foreign currency,
sub-projects (no hierarchy; ADR-009 has no sub-dimensions).

## Gap 7: report builder and analysis view across modules

### What exists

Fixed reports per area: `App\Livewire\Accounting\Reports` with
`ReportFilters::REPORTS`; Lager `Livewire/ReportsPage`; workshop
`Livewire/Workshop/AnalysisPage`; Planering staffing report; key figures
`App\Domain\Insights\BusinessKpis`; CSV via `App\Support\Csv` and
`App\Support\Spreadsheet`; per-user `saved_views` (no `company_id`, used by
the firm workspace and the chain view). ADR-066 (ask your figures) is
proposed, not built. No user-defined report exists.

### Scope

1. **Datasets, not SQL.** A fixed catalogue of datasets, each a PHP class with
   named dimensions and measures and the permission it needs:
   ledger rows (account, account group, period, series, cost centre, project;
   amount), budget lines (gap 2), customer invoices (customer, article,
   period, status; net, VAT, open), supplier invoices (supplier, account,
   period, status), stock movements and levels (article, location, type,
   batch; quantity, value; `inventory:view`), purchase orders, production and
   work orders, projects (gap 6), and payroll **aggregated per cost centre and
   pay code only, never per person** (`payroll:view`).
2. **Analysis view** (`/analys`): pick a dataset, rows, columns (a pivot),
   filters and a period comparison; totals, sorting, drill-down to the
   underlying list page. Server-side aggregation through the query builder in
   one query per view, a row cap (10 000 cells) and a statement timeout.
3. **Saved reports per company**, private or shared with the company, with a
   schedule-free "Kör igen"; export CSV, XLSX and PDF; pin to the overview as
   a tile.

### Data model

- `report_definitions` (company): `name` (≤ 80), `dataset` (key),
  `definition` jsonb (validated against the dataset's schema on save and on
  run), `visibility` (`PRIVATE`, `COMPANY`), `owner_user_id`, `pinned` bool.
  A definition holds keys only, never SQL or column names from input.

### Permissions

New: `analysis:view` (use the view on datasets the user may read),
`analysis:share` (save reports visible to the company). Each dataset checks its
own permission at run time, so a shared report shows nothing to a member who
lacks the dataset's permission. Proposed: owner, admin, accountant, auditor
view and share; bookkeeper view.

### Accounting impact

None: read only.

### Tenancy risks

High surface: every dataset must be built from company-scoped models and the
`DimensionScope` (gap 10); a dataset test per class runs the definition as a
member of company A with data in B and as a scoped member. Payroll and other
personal data datasets have a minimum group size of 3 people per cell, below
which the cell is suppressed (`TODO(legal-verify)` on the number).

### Legal items

- `TODO(legal-verify)`: aggregated payroll per cost centre can identify one
  person in a small unit; the suppression rule and art. 5.1 c.

### Out of scope

Free SQL, custom formulas beyond the dataset's measures, scheduled e-mail
reports, cross-company analysis (the group reports of gap 1 cover groups; the
firm workspace covers firms), BI connectors.

## Gap 8: configurable approval workflows

### What exists

- Supplier invoice attest: one step, `supplier-invoices:approve`, segregation
  by `separate_supplier_approval` (`ApproveSupplierInvoice`,
  `SupplierInvoicePolicy::blockedBySegregation`, `SetSupplierApprovalRule`).
- Payment batch approval: `payments:approve`, set once, protected by a trigger
  (migration `2026_11_02_310000_add_approval_to_supplier_payment_batches.php`,
  `ApprovePaymentBatch`).
- Purchase order threshold (gap 3), Planering's attest (`planning:attest`),
  payroll approval, time proposals (`TimeProposals::approve`).
- ADR-067 (one approval queue) is proposed and not built: it lists the
  sources and keeps "no second store".

### Scope

1. **Approval policies per company and subject type**: `SUPPLIER_INVOICE`,
   `PAYMENT_BATCH`, `PURCHASE_REQUISITION`, `PURCHASE_ORDER` in v1;
   `MANUAL_VOUCHER` (above an amount, before posting) and `CREDIT_INVOICE` in
   E21.
2. **Steps** in sequence, each with: a condition (amount range, cost centre,
   project, supplier, account range), who may approve (a permission, named
   users, or the cost centre's or project's owner), how many must approve
   (1 or N), and whether the preparer is excluded. The first matching policy
   (by priority) applies; a policy version is snapshotted on the request, so
   editing a policy never changes running requests.
3. **Default policies** reproduce today's behaviour exactly: one step with
   `supplier-invoices:approve` and segregation from
   `separate_supplier_approval`; one step with `payments:approve`; the
   purchase order threshold. A company that never edits a policy sees no change.
4. **Delegation**: a user may name a substitute for a date range; the
   substitute's decision is recorded with "på uppdrag av".
5. **Decisions** are approve or reject with an optional reason; a rejection
   returns the subject to its preparer. The subject's existing effect (status
   `Approved`, batch approval) is applied only after the last step, by the
   existing action, so its triggers and audit stay as they are.
6. The ADR-067 queue, when built, lists open approval requests as one source.

### Data model

- `approval_policies` (company): `subject_type`, `name`, `priority`,
  `is_active`, `version`.
- `approval_policy_steps` (company): `approval_policy_id`, `position`,
  `conditions` jsonb (validated schema), `approver_kind` (`PERMISSION`,
  `USERS`, `DIMENSION_OWNER`), `approver_permission` null, `approver_user_ids`
  jsonb null, `required_count`, `exclude_preparer`.
- `approval_requests` (company): `subject_type`, `subject_id` (string, uuid or
  id), `policy_snapshot` jsonb, `status` (`PENDING`, `APPROVED`, `REJECTED`,
  `WITHDRAWN`), `current_step`, `prepared_by`, timestamps.
- `approval_decisions` (company, append-only by trigger): `approval_request_id`,
  `step`, `user_id`, `on_behalf_of` null, `decision`, `reason` (≤ 500),
  `decided_at`.
- `approval_delegations` (company): `user_id`, `substitute_user_id`,
  `from`, `to`.
- `cost_centers` and `projects` gain nullable `owner_user_id` (for
  `DIMENSION_OWNER`).

### Permissions

New: `approvals:manage` (edit policies and delegations of others; owner,
admin). Deciding needs whatever the step names; a user may always set their
own delegation. Segregation of duties for payments (AGENTS.md §4) cannot be
switched off by a policy where the company setting requires it.

### Accounting impact

Internal control only; no voucher. The systemdokumentation §5a describes the
approval flow and that the approval history is kept (E2). A `MANUAL_VOUCHER`
policy (E21) blocks posting until approved, but numbering still happens at
posting, so series stay gapless.

### Tenancy risks

Approvers named by user id must be members with the needed permission at
decision time, re-checked live (a user who left cannot approve). The subject is
loaded inside the company.

### Legal items

- `TODO(legal-verify)`: BFNAR 2013:2 on behandlingshistorik: whether the
  approval decisions are part of it and must be kept as räkenskapsinformation
  (proposed: kept for the subject's retention period; never pruned earlier).

### Out of scope

A graphical flow designer with parallel branches, approval by e-mail reply,
external approvers without a login, approval of HR documents.

## Gap 9: document management

### What exists

Files are stored privately and encrypted (`App\Support\Files\EncryptedAdapter`,
`PrivateFile`, `StoredFiles` with `deleteUnreferenced` and a registry of every
path column). Documents live per feature: `voucher_attachments` (underlag),
supplier invoice files, `inbox_attachments` (ADR-023), `invoice_documents`,
`agreement_documents` (NONOX Avtal), CRM signing files (`/kunder/signeringar`),
`customer_portal_attachments`, boardroom minutes and articles
(`app/Modules/Boardroom`), archive packages (`App\Support\Archive\ArchivePackageBuilder`).
There is no company document archive for documents that belong to no
feature (contracts, insurance, permits, policies) and no single list of all
documents.

### Scope

1. **Company documents**: upload with title, category, tags, links to records
   (customer, supplier, project, work order, fixed asset, employee, voucher),
   versions (a new version keeps the old ones), and full-text search on title,
   tags and extracted PDF text (PostgreSQL full text, ADR stack).
2. **Retention classes**: `ACCOUNTING` (räkenskapsinformation: kept until
   seven years after the end of the calendar year of the fiscal year it
   belongs to; deletion blocked before then, AGENTS.md §10), `CONTRACT`
   (kept while linked agreement runs + a chosen period), `PERSONNEL` (only
   with `payroll:view-sensitive`, retention per `lagringstider.md`), `OTHER`
   (deletable). A legal hold flag blocks deletion.
3. **One list of all documents** (`/dokument`): a read model over the existing
   feature tables plus company documents, with the source and a link, each
   row shown only when the user may read the source. No second copy of any
   file (the ADR-067 principle).
4. Documents enter the archive package (`export:archive`) with their
   SHA-256 in the manifest.

### Data model

- `documents` (company): `title` (≤ 200), `category` (enum + company's own
  list), `retention_class`, `retain_until` (date, computed, never earlier
  than the rule), `legal_hold`, `contains_personal_data` (bool, asked on
  upload), `current_version_id`, `created_by`.
- `document_versions` (company): `document_id`, `version`, `path`, `mime`,
  `size`, `sha256`, `original_name`, `extracted_text` (tsvector source,
  nullable), `uploaded_by`, `created_at`; append-only. `path` is added to the
  `StoredFiles` registry.
- `document_links` (company): `document_id`, `linkable_type` (allow-listed),
  `linkable_id`.
- `document_tags` (company): `document_id`, `tag` (≤ 40).

### Permissions

New: `documents:view`, `documents:manage` (upload, edit, link, delete when
allowed), `documents:hold` (set and lift legal hold; owner, admin,
accountant). `PERSONNEL` documents additionally need
`payroll:view-sensitive`. Proposed: owner, admin, accountant all; bookkeeper
view and manage; auditor view; approver none.

### Accounting impact

A company document of class `ACCOUNTING` that is underlag for a voucher is
linked through `voucher_attachments` as today (same stored file, no copy);
the document archive adds no second route to underlag. The systemdokumentation
§4.5 (Underlag) and §10 (archive) describe the document archive and its
retention (E19, E20).

### Tenancy risks

The unified list reads several tables; each source adapter is company-scoped
and permission-checked, with a tenancy test per adapter. Files are served
through the existing private file controller pattern (404 for other
companies).

### Legal items

- `TODO(legal-verify)`: BFL 7 kap. 1–2 §§ and BFNAR 2013:2 (form of storage,
  "i den form den hade när den kom till företaget", conversion and readability)
  for documents uploaded here as räkenskapsinformation.
- `TODO(legal-verify)`: GDPR art. 5.1 e and 17 for `PERSONNEL` and `OTHER`
  documents with personal data; the erasure path must respect BFL retention
  (art. 17.3 b). `registerforteckning.md` and `lagringstider.md` are updated in
  E19.
- `TODO(legal-verify)`: whether extracted text must be deleted together with
  the file, and whether it is itself räkenskapsinformation (proposed: derived
  data, deleted with the file).

### Out of scope

Editing documents in the browser, e-signing (CRM signing exists), sharing
links to outsiders (the customer portal exists), OCR of images, WebDAV or sync
clients, records management for public authorities.

### Implementation notes (E19, 2026-11-18)

- **Personnel documents and the payroll administrator.** Only the module
  role `payroll_admin` holds `payroll:view-sensitive`, and a module role
  cannot carry a core permission (`ModuleRegistry::checkGrants`). So for a
  `PERSONNEL` document the matching payroll permission stands in for the
  documents one (`App\Domain\Documents\DocumentAccess`): `payroll:view` for
  reading, `employees:manage` for uploading and editing, `payroll:manage` for
  legal hold. `payroll:view-sensitive` is required either way, so owner,
  admin and accountant (who lack it by default, PAYROLL_MODULE.md B.2) do
  not see personnel documents, and the payroll administrator sees and files
  only those.
- **Vouchers are not a link type.** A räkenskapsinformation document becomes
  underlag through `voucher_attachments` (`AttachToVoucher::linkStoredFile`,
  same path and SHA-256); `document_links` allows customer, supplier,
  project, fixed asset, employee (personnel documents, payroll on) and work
  order (module on).
- **Contracts** keep `contract_ends_on` and `contract_retention_years`
  (0–30) on the document instead of a link to an agreement record; a
  contract without an end date runs and cannot be deleted.
- **Guards (after review).** The rule is the floor: a chosen keep-until date
  (at most 30 years after the rule) can be lowered again down to the rule
  (GDPR art. 5.1 e). For räkenskapsinformation (class `ACCOUNTING`, or a
  contract with `is_accounting_record`) the trigger computes the floor from
  the fiscal year and refuses anything lower, also across a change of class,
  mark or year. A document that must be kept cannot be deleted: policy,
  action, model event and the `documents_guard_retention` /
  `document_versions_append_only` triggers, which use the database's
  `current_date`. A legal hold has a reason key and must be lifted before a
  delete. No class change into or out of `PERSONNEL`; a running contract
  keeps its class. A personnel document's title is encrypted
  (`title_encrypted`). With payroll off, the owner reaches personnel
  documents (view, export, delete; not file new ones), logged as
  `document.personnel_owner_access`.
- **Registerutdrag** for customers, suppliers and employees lists the linked
  documents (`App\Domain\Documents\DocumentExtract`) with file addresses for
  those allowed to open them.
- **Search text** is not extracted for personnel documents, so their content
  never sits unencrypted in the database.
- The list lives at `/dokument` and the document at `/dokument/{id}`; E20
  extends the same list with the feature sources.

## Gap 10: permissions per cost centre or unit

### What exists

Permissions are per company and role (`App\Domain\Company\CompanyRole`:
owner, admin, accountant, bookkeeper, approver, auditor; module roles such as
Lagerpersonal), checked through `User::hasTeamPermission` and
`App\Domain\Firm\Memberships::may`. No restriction below the company exists.
Planering areas and warehouses can be tied to a cost centre
(`planning_areas.cost_center_id`, ADR-037 gap 7); warehouses not.

### Scope

1. **A unit is a cost centre.** A planning area, warehouse or till connection
   maps to a cost centre where it has one; no second unit concept.
2. **Dimension scopes on a membership**: an owner or admin may limit a member
   to a set of cost centres and/or projects. The scope narrows what the
   member's permissions reach; it never widens them.
3. **Who can be scoped**: bookkeeper, approver, module roles and a new role
   `unit_manager` (Enhetschef: `reports:view`, `budgets:view`,
   `projects:view`, `analysis:view`, approval by the engine). **Owner, admin,
   accountant and auditor can never be scoped**: they need the whole ledger
   (and the auditor by law, see legal items).
4. **Enforcement** through one service `App\Support\Tenancy\DimensionScope`
   (resolved like `CurrentCompany`): for a scoped member
   - ledger reports run only with a forced cost centre/project filter; whole-
     company reports (balance sheet, saldobalans, VAT, SIE export, archive,
     huvudbok without filter) are refused;
   - vouchers, supplier invoices and customer invoices are visible only when
     every row with a dimension is inside the scope and at least one row is;
     creating them requires all dimensioned rows inside the scope;
   - budgets, projects, analysis datasets, documents linked to a project, and
     approval steps of kind `DIMENSION_OWNER` follow the scope;
   - inventory: a member scoped to cost centres sees only warehouses mapped to
     them (E13 adds `stock_locations.cost_center_id` on warehouses).
5. Out-of-scope records answer **404**, like another company's (§13.2).

### Data model

- `membership_dimension_scopes` (company): `user_id`, `dimension`
  (`COST_CENTER`, `PROJECT`), `object_id`; unique per company, user,
  dimension and object. Bound to the membership like ADR-060's group roles:
  `team_user_id` stored, so a re-invited user starts unscoped-and-unprivileged
  until scoped anew (no revived scope). Absence of rows = unrestricted.
- Role `unit_manager` added to `CompanyRole` (or registered as a core role in
  `JetstreamServiceProvider`; the builder follows ADR-003).

### Permissions

New: `members:scope` (set scopes; owner, admin). The role table in
`docs/manual/sv/.../roller-och-behorigheter.md` is updated.

### Accounting impact

None on the ledger. Systemdokumentation §1 (Företag och behörighet) describes
scopes (E1), because who may see and create bookkeeping changes.

### Tenancy risks (P0 class)

A scope leak is treated as a P0 like a cross-company leak. A new test suite
`tests/Tenancy/DimensionScope/` runs every page, Livewire component, API
endpoint, export and dataset as a scoped member against out-of-scope rows,
and fails a new route or dataset that does not register a scope decision
(the way `StoredFilesTest` fails a missing path column). API tokens of a
scoped member carry the scope.

### Legal items

- `TODO(legal-verify)`: the company's auditor (ABL 9 kap. 3 §) and anyone the
  company names as responsible for the books must have full access; scoping
  is refused for auditor and accountant roles by design.
- `TODO(legal-verify)`: whether a unit manager seeing salary totals per cost
  centre needs its own art. 13 information to staff.

### Out of scope

Row-level rules on accounts or suppliers, field-level hiding, scopes across
companies, time-limited scopes (use delegation of gap 8 instead).

### As built (E1, 2026-11-18)

- **Three layers.** (1) Permissions: a limited member keeps only
  `DimensionScope::SCOPE_AWARE_PERMISSIONS` (`reports:view`, `vouchers:view`,
  `vouchers:create`, `vouchers:post`, `supplier-invoices:create`,
  `supplier-invoices:approve`), in `User::hasTeamPermission`; every other
  permission of the role, module permissions included, is refused. In
  `Memberships::may` (pages that span companies: Mina företag, koncern,
  month close, chain schedule) a limited membership grants nothing, because
  none of those pages applies a scope. (2) Routes and Livewire components:
  `DimensionScopeRegistry` names the scoped and neutral ones by exact name;
  everything else answers 404 (`EnforceDimensionScope` on the web group and
  after `AuthenticateConnect` on the API and MCP, `LivewireDimensionScopeGuard`
  for components). Refused areas may be named by prefix, so a new route there
  is refused; `DimensionScopeRegistryTest` fails a signed-in route outside
  every known area. (3) Data: the voucher and supplier-invoice lists use
  `DimensionScope::constrain`, the policies answer `denyAsNotFound` for a
  record outside the scope, and `SaveDraftVoucher` and `SaveSupplierInvoice`
  refuse rows outside it.
- **Row rule.** A row is inside when its cost centre is one of the member's
  or its project is one of the member's (union). Rows without either decide
  nothing. A voucher or invoice is visible when every dimensioned row is
  inside and at least one is; a member books only such records, so what they
  book stays visible to them.
- **Reports.** Only Resultatrapport and Huvudbok
  (`ReportFilters::SCOPED_REPORTS`), always with one of the member's cost
  centres or projects; a request for another report or unit is narrowed on
  the page and refused (404) for the CSV file. Reports count rows, so a row
  on the member's unit counts even when its voucher also has rows elsewhere
  and the voucher itself is not visible. Account balances of the whole
  company (voucher page, editor) are not shown. The ledger hash check is
  refused (it reads and counts the whole ledger).
- **API.** Every `/api/v1` endpoint and MCP answers 404 for a limited
  member's key or token in E1: the scope is carried by refusal until an
  endpoint applies it.
- **`unit_manager`** is a `CompanyRole` case with `reports:view` only (E3,
  E6 and E17 add their view permissions when they exist) and is always
  limited: without cost centres or projects it reaches nothing.
- **Storage.** `team_user.dimension_scoped` says whether a membership is
  limited, so an unlimited member costs no query (it comes with the pivot
  Jetstream reads; `User::teams()` adds it to the pivot). The rows are read
  once per request and user (`DimensionScopeCache`) and are part of the
  Livewire access fingerprint, so an open page answers 404 after a change.
  Leaving the company deletes the rows; a role change to admin, accountant or
  auditor lifts the scope.
- **Payroll** (review 2026-11-18, P1): vouchers with source `PAYROLL` and
  corrections of them are never shown to a limited member, in lists,
  voucher pages or reports (`DimensionScope::constrainVouchers`,
  `withoutPayroll`, `LedgerQuery::$withoutPayroll`). A small unit's salary
  cost can be one person's pay; E17 may open aggregated pay with its rule
  that hides cells under three people (owner decision 17).
- **Huvudbok**: for a row whose voucher the member may not open, only the
  row's own text and the reference without a link.
- **Shared registers**: the supplier register stays shared; a limited member
  who registers supplier invoices sees every supplier. The deadline calendar
  feed, role-based notices (payroll, new fiscal year) and the user's
  register extract (`begransad_till`) know about scopes.
- **Not in E1:** customer invoices (their rows get dimensions in E6, until
  then `invoices:*` are refused to a limited member), corrections
  (`vouchers:correct` refused), payments and payment files, modules
  (warehouses in E13), the spotlight search, notifications.

## Translations per package (lang/en.json conflicts)

Laravel supports extra JSON translation paths cleanly. Relied on: the
installed framework's source, `laravel/framework` 13.39.0
(`vendor/laravel/framework/src/Illuminate/Translation/FileLoader.php`,
`addJsonPath()` and `loadJsonPaths()`; `Illuminate/Support/ServiceProvider.php`,
`loadJsonTranslationsFrom()`, which calls `addJsonPath()` after the translator
resolves). `loadJsonPaths()` merges every registered JSON path and then the
application's `lang/` paths with `array_replace`, so on a duplicate key
**`lang/en.json` wins silently**. NONOX modules already use
`loadJsonTranslationsFrom` (see the comment in
`app/Modules/ModuleServiceProvider.php` about emptying the loaded cache).

Decision:

- Each package writes its English strings to its own file
  `lang/packages/<package-key>/en.json` (for example
  `lang/packages/e03-budget/en.json`). Swedish is the source language, so no
  `sv.json` is needed per package. Module packages may instead use the
  module's own `lang/` directory if it already has one.
- **E0** (a small preparation package, done first) registers every
  `lang/packages/*/` directory with `loadJsonTranslationsFrom` in a service
  provider's `boot()`, before anything translates, and extends
  `tests/Feature/TranslationsTest.php` to merge `lang/en.json` with every
  package file; and adds a test that fails when the same key appears in two
  files with different values (the silent override above).
- `lang/en.json` stays as it is; packages do not append to it. The changelog
  (`andringslogg.json`) and `Features.php` remain shared hotspots and are
  merged by keeping both sides.

## Owner decisions (answer in one go)

Until the owner answers, Lead builds on the recommended default (these are
also the **Lead decisions on the open questions**, made under the owner's
mandate of 2026-11-18). The owner may overturn any of them before the package
that depends on it merges.

| # | Question | Recommended default | Reason |
|---|---|---|---|
| 1 | Build statutory group accounts (stage 2, E28–E31) now, or only stage 1 plus intra-group transactions? | Build stage 1 and E27 now; start E28 (duty check); E29–E31 only after the accountant answers H2 and H4 | Stage 2 methods are undecided accounting questions; guessing them is the riskiest thing in this ADR |
| 2 | ADR-060 H1–H8 and D1 | As recommended in ADR-060 §5 (ask Bolagsverket on H1; accountant decides H2, H3, H4, H6, H8; jurist on H5; research note on H7; retire G.1 lists after one release) | Already analysed there; this ADR does not reopen them |
| 3 | Allow intra-group invoice delivery into another company's inbox (the one cross-company write)? | Yes, opt-in by the receiving company per sender, only between confirmed members of one group | Same data as an e-mail, consent on both sides, saves double typing |
| 4 | Paired draft vouchers in the counterpart company for loans, interest, group contributions? | Yes, drafts only, only for a user with `vouchers:create` in both | No posting across companies, still saves the mirror entry |
| 5 | Export `#PBUDGET` in SIE although NONOX keeps SIE "no richer than the source program"? | Import always when present; export only with the "Ta med budget" box, off by default | Lossless for those who have budgets, unchanged files for everyone else |
| 6 | Budget granularity | Month, result accounts only, optional cost centre and project | Matches `#PBUDGET` periods and the reports |
| 7 | Rolling forecast default method | Last 3 closed months' average for costs, active budget for revenue | Simple, recomputable, no model |
| 8 | Purchase order approval: keep the single threshold as default policy? | Yes; companies edit the policy to add steps | No behaviour change on deploy |
| 9 | Segregation of duties on purchase orders: may the creator approve their own order above the threshold? | No, when `separate_supplier_approval` is on (reuse the setting) | Same principle as supplier invoices and payments |
| 10 | Three-way match outside tolerance: block attest or warn? | Warn by default; a company setting makes it block with override | Small companies get invoices with freight and rounding lines |
| 11 | Default tolerances | Quantity 0 %, price 2 %, absolute 50 kr | Common practice; editable per company |
| 12 | Batch/serial valuation | Valuation stays per article (FIFO/average), not per batch | Avoids changing the year-end valuation and its systemdokumentation |
| 13 | MRP bucket and horizon | Weeks, 13 weeks default, 52 max | Matches the production planner's horizon |
| 14 | Forecast vs orders in MRP | Larger of forecast and orders per bucket (forecast consumption) | Avoids double counting |
| 15 | Kontantmetoden: split dimensions on payment vouchers proportionally? | Yes, largest remainder to the öre | Same as supplier invoices; keeps project P&L right |
| 16 | WIP for projects without work orders on time and material | Not computed; the page says a work order is needed | No billable value exists to compute from |
| 17 | Payroll in the analysis view | Aggregated per cost centre and pay code only, cells under 3 people suppressed | Avoids exposing individual pay |
| 18 | Report builder: may members share reports with the whole company? | Yes, with `analysis:share` (owner, admin, accountant, auditor) | Shared reports still check each viewer's permission |
| 19 | Manual voucher approval (`MANUAL_VOUCHER` policy) | Available, off by default | Most small companies post their own vouchers |
| 20 | Document retention for class `OTHER` | No automatic deletion; deletable by `documents:manage` | Owner knows best; nothing is lost silently |
| 21 | Personnel documents in the document archive | Allowed only with `payroll:view-sensitive` | Same gate as payroll's sensitive data |
| 22 | Which roles can be scoped to cost centres/projects | bookkeeper, approver, module roles, new `unit_manager`; never owner, admin, accountant, auditor | The books' responsible people and the auditor need everything |
| 23 | Is a warehouse a unit for scoping? | Yes, through the warehouse's cost centre (E13) | One unit concept, no new dimension |
| 24 | Order of build | The waves below; group stage 1 and budget first among the large gaps | Largest customer value first, fewest dependencies |

## Build packages

Each package ships alone: migration(s), actions, policy, tests (feature,
tenancy, query count; immutability where vouchers are touched), Livewire UI,
manual article(s) with real screenshots from demo data, a changelog entry, its
own translation file (E0), `Features.php` and the landing card when
user-visible, README, systemdokumentation where stated, OpenAPI when an API is
added, and demo data (`IndustryDemoSeeder` extras). Sized for one builder
agent each, like ADR-037's P-packages.

"Model": **standard** for ordinary CRUD and UI work, **heavy** for
accounting, consolidation, budget against the ledger, legal or
security-critical logic. "Review": **technical**, or **technical + legal**
where law applies.

| # | Package | Gap | Depends on | Model | Review | Main files (conflict surface) |
|---|---|---|---|---|---|---|
| E0 | Per-package translation files: provider registration, `TranslationsTest` merge, duplicate-key test | — | — | standard | technical | a service provider, `tests/Feature/TranslationsTest.php`, `lang/packages/` |
| E1 | Dimension scopes: `membership_dimension_scopes`, `DimensionScope`, `unit_manager` role, scope UI under members, enforcement in `LedgerQuery`/reports/vouchers/invoices, scope test suite | 10 | E0 | heavy | technical + legal | `CompanyRole`, `JetstreamServiceProvider`, `LedgerQuery`, `ReportFilters`, voucher/invoice policies, `tests/Tenancy/DimensionScope/` |
| E2 | Approval engine core: policies, steps, requests, decisions, delegation; supplier invoice and payment batch as first subjects with default policies equal to today | 8 | E0 | heavy | technical + legal | new `app/Domain/Approval/*`, `app/Actions/Approval/*`, `ApproveSupplierInvoice`, `ApprovePaymentBatch`, `SupplierInvoicePolicy` |
| E3 | Budget core: versions, lines, grid entry, copy/percent/CSV, actual vs budget report | 2 | E0 | heavy | technical + legal | new `app/Domain/Budget/*`, `App\Livewire\Accounting\Budget*`, `LedgerReports` (read) |
| E4 | SIE `#PBUDGET` import (two-step) and export (opt-in) | 2 | E3 | heavy | technical + legal | `app/Support/Sie/*`, `ImportSie`, `ExportSie`, SIE fixtures, systemdokumentation §4.9/4.11 |
| E5 | Rolling forecast: forecast versions from a cut-off, three methods, overrides, rolling 12 months | 2 | E3 | heavy | technical + legal | `app/Domain/Budget/Forecast*`, budget pages |
| E6 | Projects register + cost centre/project on invoice, quote and order rows, article/customer defaults, invoice and kontantmetod payment vouchers per dimension | 6 | E0 | heavy | technical + legal | `projects` migration, `Dimensions` page, `App\Domain\Invoicing\InvoiceBooking`, `IssueInvoice`, `RegisterPayment`, `CreditInvoice`, invoice and sales-document editors, systemdokumentation §5, §4.10 |
| E7 | Project P&L and project budget view, drill-down | 6 | E3, E6 | heavy | technical + legal | new `App\Livewire\Projects\*`, `LedgerQuery` (read) |
| E8 | Project WIP (generalised `OngoingWork`) and billing plan with milestones → draft invoices | 6 | E6 | heavy | technical + legal | `app/Modules/WorkOrders/Domain/OngoingWork`, new `project_billing_milestones`, closing page, systemdokumentation §5a5 |
| E9 | Purchase requisitions with approval, conversion to purchase order rows | 3 | E2 | standard | technical | new requisition models/actions/pages in `app/Modules/Inventory` |
| E10 | Purchase order approval through the engine (threshold as default policy), non-stock order rows with confirmation | 3 | E2 | standard | technical | `SavePurchaseOrder`, `SendPurchaseOrder`, `PurchaseOrderPolicy`, `PurchaseOrderEditor` |
| E11 | Three-way matching per row, tolerances, stored results, attest hook with override | 3 | E2, E10 | heavy | technical + legal | `PurchaseOrderMatch`, `MatchSupplierInvoice`, `PurchaseOrderMatchPanel`, `ApproveSupplierInvoice`, systemdokumentation §5a |
| E12 | Batch and serial numbers: tracking, lots, lot levels, capture on receipt/pick/issue/count, FEFO, trace report | 4 | E0 | standard | technical | `StockLedger`, `ReceiveStock`, `PickLists`, `StockCounts`, manufacturing issues, `VerifyStockCommand` |
| E13 | Transfer orders with transit, per-warehouse reorder points, warehouse cost centre | 4 | E0 (scope part after E1) | standard | technical | `RecordMovement`, `LocationType`, `PurchaseProposal`, `LocationsPage` |
| E14 | Packing and shipments, delivery note PDF | 4 | E12 | standard | technical | `PickListsPage`, new shipment models and PDF |
| E15 | Demand forecast per article and planning data on articles | 5 | E0 | standard | technical | `articles` migration, article form, new forecast page |
| E16 | MRP engine, runs, planned orders with pegging and exceptions, conversion to drafts | 5 | E15 | standard | technical | new `app/Modules/Manufacturing/Mrp/*`, `CreatePurchaseOrdersFromProposal`, `SaveProductionOrder` |
| E17 | Analysis view: dataset catalogue, pivot, drill-down, scope-aware | 7 | E1, E3 | standard | technical + legal | new `app/Domain/Analysis/*`, `/analys` |
| E18 | Saved and shared reports, CSV/XLSX/PDF export, overview tiles | 7 | E17 | standard | technical | `report_definitions`, overview |
| E19 | Document archive core: documents, versions, links, tags, retention classes, legal hold, search, archive package | 9 | E0 | heavy | technical + legal | new `app/Domain/Documents/*`, `StoredFiles` registry, `ArchivePackageBuilder`, `docs/gdpr/*`, systemdokumentation §4.5/§10 |
| E20 | Unified document list over existing feature files, per-source adapters with permissions | 9 | E19 | standard | technical + legal | adapters reading voucher, supplier invoice, inbox, agreement, signing, portal, boardroom files |
| E21 | Approval engine extended: manual voucher and credit invoice subjects, requisition/order wiring check, ADR-067 queue source | 8 | E2 | heavy | technical + legal | `PostVoucher` caller (not `PostVoucher` itself), credit invoice action, queue adapter |
| E22 | Koncern → Översikt (ADR-060 S2-1.2) | 1 | — | standard | technical + legal | `app/Livewire/Group/*`, `resources/views/livewire/group/**` |
| E23 | Intercompany counterparties and row tags (S2-1.3) | 1 | — | heavy | technical + legal | `intercompany_*` migrations, `app/Actions/Group/*` |
| E24 | Consolidation engine and run log (S2-1.4) | 1 | E23 | heavy | technical + legal | `ConsolidateGroup`, `group_account_maps`, `corporate_group_runs` |
| E25 | Eliminations and group ledger (S2-1.5) | 1 | E24 | heavy | technical + legal | `group_entries`, `group_entry_rows`, own trigger |
| E26 | Koncern → Elimineringar and Rapporter, CSV, PDF, label (S2-1.6) | 1 | E22, E25 | heavy | technical + legal | group screens and PDF |
| E27 | Intra-group invoice delivery into the counterpart's inbox, receipt settings, paired drafts | 1 | E23 | heavy | technical + legal | `DeliverIntercompanyInvoice`, Inbox source, invoice send flow, `tests/Tenancy/IntercompanyDeliveryTenancyTest.php` |
| E28 | Duty and framework check (S2-2.1) | 1 | E24 | heavy | technical + legal | group pages; gated on ADR-060 stage 2 addendum |
| E29 | Acquisition analysis and goodwill schedule (S2-2.2, S2-2.3) | 1 | E25, E28, H2, H4 | heavy | technical + legal | gated |
| E30 | Non-controlling interests and full eliminations (S2-2.4, S2-2.5) | 1 | E29, H3, H6 | heavy | technical + legal | gated |
| E31 | Group statements and PDF with filing checklist (S2-2.6, S2-2.7) | 1 | E30, H1, H7, H8 | heavy | technical + legal | gated |
| E32 | Launch sweep: comparison rows in `docs/migration/jamforelse.json`, industry pages, landing, legal review of the set, this ADR to "Accepted, implemented" | all | E1–E27 (E28–E31 when built) | standard | technical + legal | public pages, `docs/migration`, this ADR |

E28–E31 may be split further by the stage 2 addendum; the sizes above are a
headline.

**Parallel waves** (different files; shared hotspots `andringslogg.json` and
`Features.php` are merged by keeping both sides, newest changelog entry first;
translations no longer conflict after E0):

- **Wave 0:** E0 alone (small, unblocks everything).
- **Wave A:** E1, E2, E3, E6, E12, E15, E19, E22, E23 in parallel.
  E1 and E3 both read `LedgerQuery`/reports: merge E1 first, E3 rebases.
  E1 and E6 both touch invoice policies/editors lightly: merge E1 first.
- **Wave B:** E4, E5 (after E3); E7 (after E3, E6); E8 (after E6); E9, E10,
  E21 (after E2); E13, E14 (E14 after E12; E13's warehouse scope after E1);
  E16 (after E15); E17 (after E1, E3); E20 (after E19); E24, E27 (after E23).
  E9 and E10 both edit purchasing pages: E10 first. E7 and E8 both edit the
  project page: E7 first. E12 and E14 both edit pick lists: E12 merged before
  E14 starts.
- **Wave C:** E11 (after E10), E18 (after E17), E25 (after E24), E28 (after
  E24).
- **Wave D:** E26 (after E22, E25).
- **Wave E (gated):** E29, E30, E31 in sequence, each once its human
  decisions are answered.
- **Wave F:** E32.

Packages that change accounting (E4, E6, E8, E11, E21, E23–E31), budget
against the ledger (E3, E5, E7), data access (E1, E17, E20) or archiving and
personal data (E19, E20) get the independent legal and accounting review of
QUICKSTART §7 before merge.

## Consequences

- AGENTS.md §7's "Out of scope until explicitly added" no longer applies to
  inventory depth and MRP for the parts above; ADR-071 §11 ("MRP over a
  forecast period", "serial number tracing") is reversed by this ADR, and
  ADR-009's "`#PBUDGET` not written or read" is amended by E4.
- The systemdokumentation gets new versions in E1, E2, E4, E6, E8, E11, E19,
  E24, E25 and E27 (each package bumps it once; parallel branches keep one
  bump per deploy).
- `docs/gdpr/registerforteckning.md` and `lagringstider.md` change in E9, E19,
  E20 and E27.
- New permissions exist only while their module is on where they belong to a
  module (`mrp:*`, purchase requisitions under Lager); the core ones
  (`budgets:*`, `projects:*`, `analysis:*`, `approvals:manage`, `documents:*`,
  `members:scope`) are core permissions and go into AGENTS.md §4's list by a
  human-approved edit when E1–E3 merge.

## Addendum: E2 as built (approval engine core)

Built on branch `feat/erp-e2-attestmotor`. Choices the gap 8 text left open:

- **The default policy is code, not rows.** A subject that no active policy of
  the company's own applies to runs the existing single attest unchanged
  (`DecisionOutcome::Default`): same checks, messages, audit entries and
  triggers, and no `approval_requests` row. A company that never edits a policy
  therefore writes nothing new. `tests/Feature/Approval/DefaultApprovalPolicyTest.php`
  proves it per role, preparer and segregation setting, with no policy, an
  inactive one and one whose steps do not apply.
- **When a policy applies.** Policies carry no condition of their own: a policy
  applies when at least one of its steps' conditions matches; steps that do not
  match are skipped; only the matching steps are snapshotted, renumbered 1…n.
- **Segregation is live and one-way.** `exclude_preparer` is
  `step.exclude_preparer OR companies.separate_supplier_approval`, read at
  decision time. Preparers are `registered_by`/`created_by` (invoice) and
  `created_by` (payment file). A substitute who prepared the subject is out too.
- **Approver checks.** `PERMISSION` steps use the decider's own
  `hasTeamPermission` (so API scopes and archived companies apply);
  allowed permissions are the subject's own and `approvals:manage`. `USERS`
  and `DIMENSION_OWNER` approvers must be current members who themselves
  hold the subject's own approval permission (checked when the policy or
  owner is saved and again at decision time), so a policy only narrows who
  approves. An owner step requires exactly one approval. The same person may
  approve different steps of one request. A `DIMENSION_OWNER` step with no live owner on the subject's cost
  centres/projects falls back to the subject's own permission. Payment files
  allow `PERMISSION` and `USERS` only, with amount conditions only.
- **Delegation** applies only to steps that name people (`USERS`,
  `DIMENSION_OWNER` with an owner), never to `PERMISSION` steps, and the
  substitute must hold the subject's own approval permission themselves
  (checked when the delegation is added and at decision time), so a
  substitute never gains a permission. Columns are `starts_on`/`ends_on`
  (`from`/`to` are SQL keywords); a delegation is revoked (`revoked_at`), not
  deleted (a trigger allows only that one change); at most one year.
- **Rejection** closes the request (`REJECTED`) and leaves the subject as it
  is; the preparer (or anyone with the subject's prepare permission) sends it
  again, which opens a new request with the current policy (or the default as
  a snapshot). Owners and administrators may reject at any step, so a request
  whose named approver left is never stuck. The default policy has no
  rejection, as before. A cancelled payment file withdraws its pending request.
- **Archive package.** Requests (with the policy copy), decisions and
  substitutes go into `attest/` per fiscal year as CSV, listed in the
  manifest with SHA-256.
- **`approver_dimension`** (`COST_CENTER`/`PROJECT`) is an added nullable
  column on `approval_policy_steps`.
- **Database.** `approval_decisions` append-only (trigger, `AppendOnly`
  grant); `approval_requests` never deleted and unchangeable once finished, its
  subject and snapshot frozen (trigger, `NoDelete`); one pending request per
  subject (partial unique index); `approval_delegations` `NoDelete`.
- **UI.** `/attestfloden` (`approvals.policies`, under Inköp): policies,
  dimension owners, substitutes. The approval card on the supplier invoice
  and the payment file list show the steps and decisions.

## Addendum: E4 as built (SIE `#PBUDGET`)

Built on branch `feat/erp-e4-sie-budget`. Source: *SIE filformat, utgåva 4C,
2025-08-06* (SIE-gruppen), "#PBUDGET Periodbudgetpost" (p. 29), §4.5 (type 2
carries period budgets), §5.17 (no budget, no records), §8.9–8.13 (objects and
sub-dimensions) and the record summary (§6), read on 2026-11-18. It amends
ADR-008 ("`#PSALDO`/`#PBUDGET` … not written") and ADR-009 ("Not written or
read: … `#PBUDGET`") for `#PBUDGET` only; `#PSALDO`, `#OIB` and `#OUB` stay
unread and unwritten.

- **Syntax as read.** `#PBUDGET årsnr period konto {dimensionsnr objektnr}
  saldo kvantitet`: the period's change, credit negative; `årsnr` 0 is the
  year of `#RAR 0`; period `ÅÅÅÅMM`; the object specification is one
  dimension and one object (balance records), not an object list as on
  `#TRANS`; a type 2 file has none, and a type 2 reader ignores records that
  have one; a type 3 file gives the account as a whole and each object
  separately; the quantity is optional. A record without the `{}` field is
  read as if it had an empty one.
- **Mapping (`App\Domain\Budget\Sie\SieBudget`).** A file carries, per account
  and month, the whole amount T, the amount per cost centre and per project.
  Import writes one line per cost centre, one per project and one without
  objects for T minus their sum; when the file has no `{}` record the objects
  of one dimension are taken as the whole. Export sums the lines the same
  three ways and writes `{}` whenever an object is written, also at zero. So
  all three figures survive, and export → import → export is byte for byte
  equal apart from the identification lines (golden file
  `tests/Fixtures/sie/budget-export-2026.se`, round trip in
  `tests/Feature/Budget/SieBudgetTest.php`). A line with both a cost centre
  and a project is exported as its share of each, and comes back as separate
  lines with the same totals: SIE cannot carry the combination.
- **Left out, and said in the report:** other years (`årsnr` ≠ 0; each year
  comes with its own file), accounts outside 3000–8998 (balance and
  liquidity budgets are out of scope), dimensions other than 1 and 6, records
  with more than one object, quantities.
- **Refused** (the budget, never the bookkeeping): a period outside `#RAR 0`,
  an unreadable period, account or amount, the same account, month and object
  twice, an amount above `BudgetMonths::MAX_CELL_ORE`.
- **In the full SIE import** (decision 5, "import always when present") the
  budget is planned by `SieImportAnalyser` and written by `ImportSie` in its
  transaction, after the reconciliation, as a draft version "Budget från
  SIE-fil" (active only when the year has no active budget). Its accounts
  and objects are created like the vouchers'. A refused budget is a warning:
  the bookkeeping is read in without it. A user without `budgets:manage` gets
  the bookkeeping only (no such role exists today; defensive).
- **Budget only.** `/budget` has "Läs in budget från SIE-fil" (Livewire
  `BudgetSieImport`, types 2, 3 and 4, up to 12 MB through Livewire's
  temporary upload): "Granska filen" runs `SieBudgetAnalyser` and shows the
  report, "Läs in budgeten" runs `ImportSieBudget`, which checks the file
  again. The fiscal year must exist with the file's dates; accounts, cost
  centres and projects must exist in the company (a budget import never
  changes the chart or the registers). No table is added: the report lives in
  the component. Permission `budgets:manage`; the component is not in
  `DimensionScopeRegistry`, so a limited member is refused.
- **Export.** `ExportSie(..., withBudget: true)` writes the active `BUDGET`
  version's lines (through `BudgetScope`) after `#RES` and before `#VER`. The
  page Räkenskapsår shows an unticked "Ta med budget" only for a year with an
  active budget and a member with `export:sie` and `budgets:view`; the
  controller checks `budgets:view` before streaming. The archive package, the
  API and SFTP export are unchanged (no budget).
- **Audit.** `budget.created` with `start: sie`, `source` (`sie_import` with
  the import id, or `sie_file` with the file's SHA-256) and the line count;
  `sie.exported` gets `budget_version_id` and `budget_records` when the box is
  ticked; `sie.imported` gets `budget_version_id`. No names.
- **Open legal items.** `TODO(legal-verify)`: that a combined cost centre and
  project line may be exported as two single-object records (the format text
  describes one object per balance record); whether a budget read in from a
  file needs anything beyond E3's handling as non-räkenskapsinformation.

## Addendum: E5 as built (rolling forecast)

Built on branch `feat/erp-e5-prognos` on E3's tables; choices the gap 2 text
left open:

- **A forecast is a snapshot.** A `FORECAST` version stores every month as
  `budget_lines`: the posted outcome up to the cut-off (exact öre) and the
  computed months after it (whole kronor). "Spara och räkna om" reads the
  ledger and the active budget again and rewrites the lines; months typed by
  hand (`is_override`, also a typed zero) are kept. `BudgetLines::insert`
  keeps zero lines only when they are overrides. `SaveBudgetLines` refuses a
  forecast, so outcome months are never typed.
- **Cut-off.** `forecast_cutoff` is the last day of an ended month, from the
  month before the fiscal year (all forecast) to the year's last month. Two
  new CHECKs: a cut-off exactly on forecasts, and always a month end.
  "Closed months" in owner decision 7 is read as ended calendar months up to
  the cut-off. A new forecast defaults to the last month inside the company's
  locked period (`locked_until_date`), else the last ended month; an unlocked
  cut-off is flagged in the form and the editor. Typed amounts for months up
  to the cut-off are skipped, not refused.
- **Methods** (`ForecastMethod`, `ForecastSettings` in `method` jsonb):
  revenue rows (`BudgetMonths::isRevenue`) and cost rows each have a default
  (`BUDGET` and `AVERAGE` with N = 3), a row may have its own. The average
  counts only months inside this or the previous fiscal year, so a young
  company averages over the months it has; `LAST_YEAR` reads only months up
  to the cut-off. Both read the outcome without YearEndClosing's entries
  (vouchers of source `CLOSING` whose subject is a `FiscalYear`: tax,
  deferred tax, appropriations, revaluations and their reversals on the next
  year's first day); monthly depreciation and accruals (`CLOSING` with an
  asset or accrual subject) stay in, and the outcome months show everything.
  The page says how many months the average covers and when there is no
  previous year. A method that needs the budget is refused when the year has
  no active budget; the create form then defaults revenue to the average.
- **Rows.** The active budget's rows, rows added by hand and the rows the
  outcome needs. Each posted amount goes to one row, the most specific
  (account+cost centre+project, account+cost centre, account+project,
  account; the last is made when missing), so nothing is counted twice.
- **Moving on.** A draft forecast's cut-off can be moved (overrides that
  become outcome are dropped), or a new forecast is made "from" an earlier
  one (`source_version_id`), carrying its row methods and the overrides after
  the new cut-off. A row with its own method stays a row (the editor sends
  every row it shows) even when its method gives nothing. The first forecast
  of a year is active.
- **Rullande 12 månader** (`/budget/rullande-12-manader`,
  `accounting.budgets.rolling`, `RollingTwelveMonths`): each month from its
  fiscal year's active forecast, else its active budget; months without
  either are named; each forecast's cut-off is shown, and a forecast more
  than a month behind the books is flagged and no longer decides the start
  month. Four queries. Refused for dimension-limited members, like
  every budget page.
- **Audit:** `budget.forecast_created` and `budget.forecast_saved` with ids,
  cut-off month, method codes and counts.

## Addendum: E7 as built (project P&L)

- **Pages.** `/projekt/resultat` (`projects.index`, Bokföring → Projektresultat)
  lists every project's revenue, costs, contribution, margin and budgeted
  contribution over its whole life; `/projekt/{id}` (`projects.show`) shows one
  project for its whole life or one fiscal year (then also per month), the
  register facts, the comparison with contract sum and estimated total cost
  (whole life only), and a drill-down from an account to its posted rows (at
  most 200, with a link to the account's Huvudbok on the project). The
  register (`/projekt`) links each project to its page. Read only: no table,
  no migration, no audit entry.
- **Ledger.** `App\Domain\Projects\ProjectResults` reads the posted rows only
  through `LedgerQuery::rows()`, one `LedgerQuery` per fiscal year, joined
  with `UNION ALL` into one statement, so the life view costs one query
  whatever the number of years. Amounts are in the income statement's sign.
- **Groups.** By BAS class, a presentation choice: Intäkter 3000–3999,
  Material och varor 4000–4399 and 4700–4999, Underentreprenader och externa
  kostnader 4400–4699 (reverse charge and purchases from abroad) and
  5000–6999, Personal 7000–7699. Täckningsbidrag is revenue minus those three
  cost groups; Avskrivningar och finansiella poster 7700–8998 (financial
  income 8000–8399 included, so revenue stays class 3 as in the income
  statement) come below it and give Projektresultat. Margin is contribution
  over revenue in basis points.
- **Budget.** The lines with the project in the *active* `BUDGET` version of
  each fiscal year (`BudgetScope::activeBudgetLines`); forecasts and inactive
  versions are never read. Shown with `budgets:view`.
- **Permissions and scope.** `projects:view` opens both pages
  (`ProjectPolicy::viewResults`, `viewResult`). `projects:view` and
  `budgets:view` join `DimensionScope::SCOPE_AWARE_PERMISSIONS`, and the role
  `unit_manager` gets both. A limited member reaches only projects in their
  own project scope (a cost-centre scope opens no project page); any other
  project answers 404. Payroll vouchers and their corrections are left out
  (`withoutPayroll`), and so is the whole personnel group 7000–7699, actuals
  and budget, on the page, the drill-down and the list, whatever the
  voucher's source (SIE import, manual voucher): on a one-person project it is
  that person's pay (owner decision 17, cold review P1). The page says so.
  `TODO(legal-verify)`: Resultatrapport and Huvudbok for a limited member (E1)
  still show personnel accounts that were not booked by payroll. In the drill-down a voucher the member may not open
  (`constrainVouchers`, one query) shows only the row's own text and its
  number without a link. The budget pages and the project register stay
  refused to limited members (`DimensionScopeRegistry`).
- **Queries.** A fixed number per page and drill-down (one each for the ledger and the budget), whatever the
  number of projects, years, accounts or rows (`ProjectResultTest`).

## Addendum: E10 as built (purchase order approval and rows without an article)

Built on branch `feat/erp-e10-inkopsorder-attest`, on top of the approval engine (E2). Owner decisions 8 and 9 apply.

- **`article_id` choice: made nullable, additively.** `purchase_order_rows.article_id`
  is loosened with `ALTER COLUMN … DROP NOT NULL`; no row moves or changes, so
  there is no new table. A check constraint requires an article or an account on
  every row (`purchase_order_rows_article_or_account`). The row gains nullable
  `account_id`, `cost_center_id`, `project_id`, `confirmed_at` and
  `confirmed_by`. A row without an article ("utan artikel") is a service or
  consumable: description, account (cost centre and project when the account
  requires them), quantity as typed in a unit from the company's unit register
  (default unit when none is chosen), a price that must be typed, and no stock
  unit (`quantity_milli` is the typed quantity ×1000). `remainingMilli()` is 0 for
  it, so goods receipts, the purchase proposal and the planning queries (which
  filter on `article_id`) never see it. The PDF and the e-mailed snapshot show
  the description, quantity, unit and price; the account is internal.
- **Subject and rounds.** The engine subject is `PURCHASE_ORDER`, with the subject id
  `"{order id}.{approval_round}"`. `purchase_orders.approval_round` starts at 1 and is
  raised (with `approval_request_id`, `approved_by`, `approved_at` cleared and any
  open request withdrawn) when a draft that already has a request is saved again
  and when a sent order is revised into its next version, so an approval never
  covers something other than what was approved. Each round has its own
  request and decisions; earlier rounds stay in the history. The engine's one
  pending request per subject rule needed no change.
- **The threshold is the default policy.** `PurchaseOrderApprovals::mode()` is
  `POLICY` when a policy of the company applies (or the round has a request),
  `THRESHOLD` when no policy applies and the total exceeds
  `purchase_order_approval_above_ore`, otherwise `NONE`. A company that never
  edits a policy gets no approval rows: `PurchaseOrderPolicy::send` behaves as before
  (`purchase-orders:send` always; `:manage` only up to the amount), and
  `DefaultApprovalPolicy` style tests cover it.
- **Decision 9.** In `THRESHOLD` mode the creator (`created_by`) cannot send the order while
  `separate_supplier_approval` is on (`SendPurchaseOrder`). In `POLICY` mode the
  engine keeps the creator out of every step under the same setting. This is the
  one deliberate behaviour change for default-policy companies, taken from
  decision 9: an order above the threshold created by a user who can also send
  it must now be sent by someone else, or the owner switches the setting off.
- **Permission.** The subject's base permission is `purchase-orders:send` (approver,
  administrator, owner); the prepare permission is `purchase-orders:manage`.
  Step permissions are that or `approvals:manage`. The policy page offers the
  purchase order flow only while Lager is on, and saving such a policy needs Lager.
- **Sending.** `SendPurchaseOrder` refuses an order a policy applies to until it is
  approved for its round. The version that goes keeps `approval_request_id`,
  `approved_by` and `approved_at`; for the threshold, the sender is recorded as approver.
- **Confirming delivery.** `ConfirmOrderRowDelivery` ticks (and un-ticks) a row without an
  article on a sent, not cancelled order, without a stock movement. An order is
  fulfilled when every stock row is received and every other row is confirmed
  (`PurchaseOrderRow::isFulfilled`). A confirmed row cannot be removed or become a
  stock row, and an order with a confirmed row cannot be cancelled. The match with
  the supplier invoice counts a confirmed row at its full value and flags an
  unconfirmed one.
- **Not done here.** Per-row three-way matching and tolerances (E11); requisitions
  (E9). Purchase order approvals are not in the archive package's `attest/` CSVs:
  whether an order is räkenskapsinformation is the open TODO(legal-verify) of
  ADR-071; the rows stay in the database and nothing is pruned.

## Addendum: E16 as built (MRP engine)

Built on branch `feat/erp-e16-materialbehov`. Choices the gap 5 text left open:

- **Where it lives.** The engine, snapshot, actions, models and page are in
  `app/Modules/Manufacturing/Mrp`; the page is reached under Lager at
  `/lager/behovsplanering` (`inventory.mrp`, behind `module:inventory`) and
  guarded by E15's `mrp:view`/`mrp:run` (`InventoryPolicy::planView`/`planRun`),
  so a trading company with Lager alone plans too. The tables' migration is in
  the Manufacturing module, whose migrations always load.
- **What is planned.** Active stocked articles with planning method `MRP`
  only. An article is `PRODUCTION` when Tillverkning is on and it has a bill
  of materials valid today (the version `SaveProductionOrder` would copy);
  otherwise `PURCHASE`. A component that is not `MRP` gets no planned orders
  (the purchase proposal still covers it once a production order exists).
- **Inputs.** Customer orders come from `OpenCustomerOrderDemand::lines()`
  (new, dated; `reserved()` now sums it, unchanged behaviour), dated by
  `confirmed_delivery_on`, else `until_date`, else `document_date`. Purchase
  receipts: draft, sent and partly received rows, dated by `expected_on`, else
  `requested_delivery_on`, else order date + lead time. Production orders:
  **draft, released and started** (drafts count, as in the purchase proposal,
  so a converted planned order is not proposed again); finish from the latest
  production plan forecast, else `due_on`, else start + lead time; component
  need (`required − issued`) on the forecast start, else `planned_start_on`,
  else `due_on − lead time`, else today.
- **Netting.** Forecast consumption per owner decision 14 counts customer
  orders only; dependent demand is added on top. Supply is used on demand
  oldest first (this gives the pegging). A shortfall against demand or the
  safety stock first pulls forward only the needed part of the next later
  receipts (advice `MOVE_IN` with the moved quantity and the order's full
  quantity as `order_milli`, i.e. split the order; the rest stays on its
  date), then a planned order covers up to the safety stock, at
  least the minimum lot, rounded up to the multiple. Due on the bucket's
  Monday (today in the first bucket); release = due − lead time in calendar
  days; a past release is set to today and marked `LATE`, one within seven
  days `RELEASE_NOW`. `EXCESS` is computed latest receipt first, on the
  unmoved remainder, against the projected stock above safety stock
  **without this run's planned orders**, so a receipt is never called excess
  while a planned order covers the same need (cold review P2). The pegging
  lists at most 50 entries. On-hand stock is the whole balance, including
  expired or blocked lots, as in the purchase proposal. Planned orders are
  inserted in chunks of 500 (one query per chunk).
- **Purchase proposal alongside.** The purchase proposal still lists
  MRP-planned articles (reorder point, open customer orders). Drafts converted
  from planned orders count as incoming in both, so nothing is counted twice.
- **Advice on existing orders** (`MOVE_IN`, `EXCESS`) is stored as `messages`
  jsonb on `mrp_runs`, not as planned orders, since it concerns existing
  orders; planned orders carry `RELEASE_NOW`/`LATE` in `exception`.
- **Conversion.** Only planned orders of the company's latest run, once each.
  Purchases go through `CreatePurchaseOrdersFromProposal`, which gained an
  optional `expected_on` per line and a source parameter (new
  `PurchaseOrderSource::Mrp`); production through `SaveProductionOrder::create`.
  The expected or due date is the need date, or release + lead time when that
  is later (a late order). Both inner actions check their own permissions.
- **Retention.** Runs older than 30 days are deleted with their planned
  orders by each run and nightly by `mrp:prune` (03:10). Nothing here is
  räkenskapsinformation; the drafts made are ordinary orders and stay.
- **Database.** `mrp_runs` and `mrp_planned_orders` are `ReadWrite`; check
  constraints on horizon (1–52, Monday start), kind, exception, quantities
  and the converted pair.

## Addendum: E9 as built (purchase requisitions)

Built on branch `feat/erp-e9-inkopsanmodan`, on top of the approval engine (E2) and the purchase order approval (E10). Owner decisions 8 and 9 carry over.

- **Tables.** `purchase_requisitions` and `purchase_requisition_rows`, both company-scoped (`BelongsToCompany`, tenancy test, `ReadWrite` grants: a draft's rows are replaced when it is saved again, a requisition is never deleted). Number series `purchase_requisitions`. The row keeps the quantity as typed in the row's own unit (thousandths), an estimated price per that unit (null when unknown), optional account, cost centre, project and supplier, and `purchase_order_row_id` (set on conversion, `nullOnDelete`). `purchase_requisitions` carries the status, `requested_by`, `prepared_by` (whoever saved it last), an approval round, `approval_request_id`, who approved and who rejected with an optional reason (≤ 500, free text, never in the audit log), and `cancelled_at`.
- **Subject and rounds.** The engine subject is `PURCHASE_REQUISITION` with the subject id `"{id}.{approval_round}"`, as for purchase orders. The round is raised when a requisition is saved again and when it is taken back, so an approval or rejection is always bound to the version the decider saw: the page holds a `#[Locked]` `seenSubject`, the action locks the row and refuses a stale round. Only a draft or rejected requisition can be saved; a submitted one is taken back first ("Ta tillbaka"), which withdraws any open request. Preparers for segregation of duties are `requested_by` and `prepared_by`, so a buyer or administrator who edits someone else's draft cannot approve it while `separate_supplier_approval` is on.
- **Default flow is code, not rows.** With no policy of the company's own a requisition writes no request rows: one person who may send purchase orders approves it (`purchase-orders:send`, the approver role, administrators, owners), never a preparer while separate approval is on. This permission is also the subject's base permission for policy steps, so a policy only narrows who approves. Unlike an order, a requisition has its own status, so a rejection (`REJECTED`, with reason) works in the default flow too, and the requester sees it in the list. The generic `RejectApproval` and `ResubmitApproval` delegate to `RejectPurchaseRequisition` and `ResubmitPurchaseRequisition` for this subject. A rejection in a policy flow is the engine's decision on the current step (owners and administrators may always reject); resubmitting after such a rejection opens a new request with the policy as it is now.
- **Policy conditions.** The amount is the requester's estimate excluding VAT (rows without a price count as nothing); the supplier condition applies only when every row names the same supplier; dimensions and accounts come from the rows. The policy page offers "Nytt flöde för inköpsanmodan" while Lager is on.
- **Conversion.** `ConvertPurchaseRequisitions`: a buyer (`purchase-orders:manage`) picks a supplier and approved, not yet ordered rows (rows naming another supplier are refused) and gets one draft order, `PurchaseOrderSource::Requisition`, through `SavePurchaseOrder`, so every order rule (units, accounts, dimensions, restricted suppliers) applies unchanged. A stock article keeps its article and an empty price takes the article's purchase price; anything else becomes a row without an article on the requisition row's account, and the buyer may fill in the account and price the requester left out (account is required on a requisition row that is not a stock article, so this is only an override). Requisitions and rows are locked in id order. A requisition becomes `CONVERTED` when all its rows are ordered; some rows leave it `APPROVED`. If an order row is removed from the draft order, or the order is cancelled, the link is released and a converted requisition goes back to `APPROVED` (`PurchaseRequisitionLinks`). The order is then approved and sent as any order (E10); the requisition's approval does not replace it.
- **Permissions.** New `purchase-requisitions:create` (own requisitions, always visible to the requester) and `purchase-requisitions:view` (everyone's): owner and administrator both, accountant, approver and auditor view, bookkeeper both. Changing, sending, taking back and cancelling: the requester, or a buyer. No new approval permission.
- **Not done here.** Requisitions are not in the archive package, the approval queue (E21) or the data subject extract by person; whether a requisition is räkenskapsinformation is the open `TODO(legal-verify)` of this gap (nothing is pruned). Requisitions are not offered over the API.
- **Decisions confirmed by the Lead.** Approving a requisition needs `purchase-orders:send` (no new approval permission). Buyers (`purchase-orders:manage`) may edit, take back and cancel any requisition; an edit makes them its preparer (`prepared_by`), so they cannot approve it while separate approval is on.
- **Cold review changes.** (1) An amount condition on a requisition with an unpriced row treats the amount as incomplete (`SubjectFacts::amountIncomplete`): a lower bound counts as met, an upper bound as not met, so it never slips under a limit; the default flow is unchanged. (2) `stock_clerk` may write requisitions (`purchase-requisitions:create`) and finds them under Lager. (3) Cancelling a purchase order releases its requisition rows also after it was sent (a sent order can only be cancelled when nothing has been received or confirmed). (4) `purchase_requisitions` has the `NoDelete` grant. (5) The segregation notice on the page says where the owner switches the requirement off (Inköp → Leverantörsfakturor, Attest).

## Addendum: E17 as built (analysis view)

- **Page.** `/analys` (`analysis.index`, Bokföring → Analys), Livewire
  `App\Livewire\Analysis\AnalysisPage`. Read only: no table, no migration, no
  audit entry. The choices are keys in the URL; `AnalysisDefinition::normalise`
  keeps only what the dataset's catalogue names (up to two row dimensions, one
  column dimension, one measure, two filters, whole months, at most 60) and
  falls back to defaults for the rest, so nothing typed reaches SQL. Filter
  values and drill keys are bound parameters. This is the schema E18 will
  validate a saved `report_definitions.definition` against.
- **Datasets** (`app/Domain/Analysis/Datasets`, interface `Dataset`): ledger
  rows, budget lines (active budget only), customer invoices (one record per
  invoice: net, VAT, total, open), invoice rows (article), supplier invoice
  rows and aggregated payroll. Not yet: stock, purchase orders, production and
  work orders; each is one more class. The ledger reads every posted voucher, CLOSING
  ones included (depreciation, accruals, tax, the year's result), like
  `LedgerQuery` and the Resultatrapport, so a period's result accounts equal the
  report's (tested); drafts are left out. Every dataset checks its own permission in `allows()`
  (`DatasetCatalogue::for`), so a saved report added later shows nothing to a
  member who may not read it.
- **Query.** One grouped query per view: the period and the comparison (the
  same months a year earlier) are conditional aggregates (`FILTER`) in the same
  statement; at most 10 000 cells (`too many cells` otherwise), `SET LOCAL
  statement_timeout` 15 s, totals added up in PHP (every measure is a sum or a
  count). The comparison is off when months are a grouping. A filter picker is
  one query per chosen filter, a drill-down one query (at most 200 records).
- **Permission.** New `analysis:view` for owner, admin, accountant, bookkeeper,
  auditor and `unit_manager` (the approver has none). `analysis:share` belongs
  to E18. `analysis:view` joins `DimensionScope::SCOPE_AWARE_PERMISSIONS`;
  `analysis.index` and `AnalysisPage` are `Scoped` in `DimensionScopeRegistry`
  (`analysis.*` otherwise refused). Customer invoices, invoice rows and payroll
  are refused to a limited member in `allows()` as well (invoices:* and
  payroll:* are not scope-aware).
- **What changes for a limited member, and what does not (owner decision 17).**
  Payroll vouchers and their corrections are still never shown, and E7's rule is
  kept: the personnel accounts 7000–7699 are never read, in actuals, budget or
  supplier invoice rows, whatever the voucher's source. The ADR's E17 text lifts
  neither: aggregated pay is its own dataset behind `payroll:view`, which no
  limited member holds, so aggregated pay stays closed to a unit manager.
  `TODO(legal-verify)`: whether pay aggregated for a unit may be opened to its
  manager at all, and under which information to staff (art. 13).
- **Scope rule inside a dataset** (`DimensionedRowsDataset`). A row is read when
  its cost centre or its project is the member's (the Resultatrapport's row
  rule), after the voucher-level payroll exclusion; supplier invoice rows follow
  the invoice rule of E1 (`DimensionScope::constrain`, every dimensioned row
  inside). The scope is applied to the base query, before anything is grouped or
  filtered, so no grouping, column, filter or period can add a row. A cost
  centre or project outside the scope is shown as key −1 "Annan enhet", with
  neither code nor name, and the filter picker lists only the member's own
  units. A filter on another unit's id matches nothing. In the drill-down the
  member sees the row's own text, the unit codes only when they are their own,
  and no voucher link (as Huvudbok in E1). The voucher's own description is shown
  only for a voucher that passes `constrainVouchers` (the whole voucher inside
  the scope, no payroll), one extra query over at most 201 ids; otherwise only the
  row's text (the Huvudbok rule).
- **Budget widens E7.** A limited member reads budget lines on their own cost
  centres too, not only on their projects as in E7, still without 7000–7699.
  `TODO(legal-verify)`: as in E1, balance-sheet rows on their own units show up
  in the ledger dataset.
- **Payroll rule.** `PayrollDataset` groups first at the finest grain (pay
  code × cost centre × month; the only dimensions it has), per person inside the
  cell. A finest cell is hidden when fewer than `MIN_PEOPLE` (3) people have pay
  in it (a zero amount does not count) or when one person accounts for more
  than `DOMINANCE_PERCENT` (80 %) of it. Every total or regrouping that contains
  a hidden cell is hidden too (`BOOL_OR` over the finest cells in the same
  statement), so a hidden cell's figure cannot be had by subtracting one shown
  figure from another. No person dimension, no drill-down, no count of people is
  shown. Only approved, paid or posted runs, and only the lines that make up
  the gross pay; the cost centre is the line's own, else the employment's.
  Not prevented: comparing two periods or two cost centres can still show one
  person's change in a cell of three or more, and "Dold" shows that a pay code
  occurred. The dataset shows nothing beyond what `payroll:view` already sees
  per person. `TODO(legal-verify)`: the numbers three and 80 %; a group of three
  can still be informative in a uniform unit; difference over time within a
  shown cell; that Dold reveals that a pay code occurred (art. 5.1 c).
- **Not in E17:** saved and shared reports, export and overview tiles (E18).

## Addendum: E18 as built (saved and shared reports, export, overview tiles)

- **Table.** `report_definitions` (company-scoped `BelongsToCompany`, `owner_user_id` cascade, `name` ≤ 80, `dataset`, `definition` jsonb, `visibility` `PRIVATE`/`COMPANY` with a CHECK, `pinned`). Grants: read and write (owner edits and deletes). A saved report is the E17 query definition as keys, plus `rolling` and `months`; no figure, no SQL, no column name is stored. On save, `SaveReport` normalises the choices against the dataset's catalogue (`AnalysisDefinition::normalise`), so what is stored is what E17 would accept; on open the same normalisation runs again, so a dimension that later disappears falls back to a default instead of reaching SQL.
- **Opening re-runs, never replays.** `SavedReports::run` and the page build an `AnalysisContext` from the viewer: `DatasetCatalogue::for($viewer)` (each dataset's own permission and the E1 scope rule) and `DimensionScope::for($viewer)`, so a shared report shows each person only what they may read. A report whose dataset the viewer may not use is not listed and answers 404 (as a private report of another person and another company's id do, with no difference in the answer). The payroll rule (three people, 80 %, hidden totals) lives in the dataset query and so applies on screen, in files and on tiles.
- **Period.** `rolling` stores a number of months ending at the current one, so "Kör igen" always shows the latest; otherwise the months chosen stay.
- **Permissions.** `analysis:share` (owner, admin, accountant, auditor, as the ADR proposed; not the bookkeeper, not the unit manager, not in `SCOPE_AWARE_PERMISSIONS`, so a limited member never holds it). `ReportDefinitionPolicy`: any `analysis:view` holder saves a private report; `COMPANY` needs `analysis:share`; changing a report is the owner's; deleting is the owner's or, for a company report, anyone with `analysis:share`. Pinning a company report needs `analysis:share` too, because it puts a tile on everyone's overview. Sharing cannot widen: the visibility only decides who may start the run.
- **Export.** From the analysis page, `AnalysisPage::export(csv|xlsx|pdf)`, a Livewire download of the working choices as the viewer's own run (no new route). `ReportTable` is built from the `AnalysisResult`: a hidden cell, and every total that contains one, is the text "Dold", never a figure. CSV: UTF-8 with BOM, semicolon, decimal comma, `Csv::cell` against formulas. XLSX through `openspout/openspout` (already in `composer.json` for the register import, MIT, no new package); every cell is built explicitly (`StringCell` for text, `NumericCell` for figures), because `Row::fromValues` turns a text starting with `=` into a formula cell; a test unzips the sheet and finds no formula. PDF through Dompdf (as the other PDFs), landscape A4, at most 400 rows and 14 columns, counted in the `pdf` work quota. An export of a "too large" or an empty result is refused with a toast. Audit `analysis.exported` holds dataset key, format, row count and report id, never a figure or a name.
- **Overview tiles.** `Livewire\Overview\ReportTiles`, `#[Lazy]`, inside `OverviewPage` for holders of `analysis:view`: the overview itself runs no extra query. At most `SavedReports::MAX_TILES` (4) tiles, each the grand total (and last year's when compared) of the viewer's own run; hidden totals show "Dold" and carry no figure to the page. Tests prove the overview's query count is the same with 0 and 6 pinned and that six pinned cost no more than four. `ReportTiles` is `Scoped` in `DimensionScopeRegistry`.
- **Personal data.** The table holds the owner's user id and a free report name (shared with the company when `COMPANY`). `owner_user_id` is nullable (`nullOnDelete`): `DeleteAccount` deletes the person's private reports in every company and `RemoveTeamMember` their private reports in that company, while company reports stay without an owner, and whoever holds `analysis:share` may then update, pin, unpin and delete them (a sharer may also unpin another person's company report); the report name is not written to the audit log. The register of processing gets one row for it (docs/gdpr).
- **Not in E18:** schedule-free only, no e-mailed reports, no per-user pin of a company report (the ADR's single `pinned` flag), no PDF for tables over the limit, no export of a drill-down.

## Addendum: E20 as built (unified document list)

- **Where.** `/dokument/alla` (route `documents.all`, Livewire `DocumentOverviewPage`), linked from `/dokument`. `documents.*` is already refused for limited members in `DimensionScopeRegistry`; the voucher and supplier invoice adapters also refuse them by themselves.
- **One adapter per source** in `App\Domain\Documents\Sources` (`DocumentSource`): company documents, voucher underlag, supplier invoice files, inbox attachments, NONOX Avtal documents, signing PDFs and certificates, customer portal files, approved board minutes and payslips (including imported ones). `DocumentOverview` combines the allowed adapters in one `UNION ALL` and paginates it: a count and a select per page, however many files. No file is copied; a row links to the source's own file route (NONOX Avtal documents have none, so they open the page that lists them).
- **Permissions are the source's own**: `vouchers:view`, `SupplierInvoicePolicy::canSeePayables`, `inbox:view`, `AgreementAi::availableFor`, `invoices:view` (quote requests) or `crm:view` (agreement requests), `customer-portal:view`, `meetings:view` read through `ArchivedCompany::reading` (as the controller does, so an archived company's minutes stay listed), the document policy and `DocumentAccess`. Deliberately stricter than the source pages: payslips and employee-linked NONOX Avtal documents also need `payroll:view-sensitive` (owner decision 21), and signing requests of a module's own type (an ATA) are not listed because they go through that module's `SigningHook`. An employee's own payslip (`self:payslips`) is not listed either.
- **Encrypted titles** (personnel documents, NONOX Avtal file names) are decrypted in PHP after the page is read and are not searchable. The list fetches no names from the staff register, only the employee number; titles and file names are shown as they were saved in the source (the promise to the user, E20 review P2-1).
- **Tests**: `tests/Feature/Documents/DocumentOverviewTest.php`, one test per adapter for a role that may not see it, another company, filters and a query-count test. No new table or column, so no grants, tenancy-registry or StoredFiles change.

## Addendum: E21 as built (manual vouchers and credit invoices as approval subjects)

Built on branch `feat/erp-e21-attestamnen`, on top of the approval engine (E2),
the purchase order wiring (E10) and purchase requisitions (E9). Owner decision
19 applies.

- **Subjects.** `MANUAL_VOUCHER` and `CREDIT_INVOICE` (a customer credit
  invoice). Base permissions `vouchers:post` and `invoices:credit`; prepare
  permissions `vouchers:create` and `invoices:create`. Conditions: amount
  (a voucher's debit total; a credit invoice's total including VAT, tested
  without sign), cost centre, project, account range; no supplier.
- **What a manual voucher is.** A draft with source `MANUAL`, no
  `corrects_voucher_id` and no `source_type`: the voucher editor, the inbox
  and the API. Not invoicing, payments, payroll, VAT, closing or SIE, and not
  the tax account reconciliation (`BookTaxAccountTransaction` books with
  source `MANUAL` but links the voucher to its `tax_account_postings` row; a
  person confirms each transaction and the counter account there, and it is
  the reconciliation's own booking, so it is left out rather than given a new
  voucher source, which would need an enum change on `vouchers`).
- **Corrections.** A correction that only reverses a posted voucher is always
  allowed: it undoes something already posted, and approved where a policy
  applied. A correction with re-booking rows is new bookkeeping, so
  `CorrectVoucher` refuses it while a `MANUAL_VOUCHER` policy would hold
  those rows as a manual voucher (`DraftApprovals::holdsRows`); the person
  reverses the voucher and enters the re-booking as a manual draft that goes
  to approval. The app's own correction is a reversal; the API's `rows`
  answer 422 on `rows` in that case.
- **The default policy is "no approval".** With no active policy of the
  company's own for the type and no request on the draft, posting and issuing
  are unchanged and write nothing new (`DraftApprovals::mayBeHeld`, two
  `exists` queries). `ApprovalSubjectType::hasDefaultApproval()` is false for
  both, so the policy page says so.
- **`PostVoucher` is not changed.** `App\Actions\Accounting\PostManualVoucher`
  is the caller used by the voucher editor, the API (`POST
  /api/v1/vouchers/{id}/post`, which answers 422 while approval is missing)
  and the inbox; it locks the voucher first (PostVoucher's own first lock),
  checks the approval and then calls PostVoucher in the same transaction.
  `IssueInvoice` checks a credit draft the same way before it takes the
  invoice number. A waiting, rejected or deleted draft has no number, so
  series and invoice numbers stay gapless.
- **Locks.** `SaveDraftVoucher` and `SaveDraftInvoice` lock the draft row
  first and refuse anything that is no longer a draft; `AttachToVoucher` and
  `RemoveAttachment` lock the voucher. A save therefore never interleaves
  with posting, issuing or a decision on the same draft.
- **Approval is bound to a version.** The subject id is
  `"{draft uuid}.{fingerprint}"`, the fingerprint a 24-hex SHA-256 read from
  the database: for a voucher its date, text, series, source and rows (without
  row ids) and its attachments' SHA-256; for a credit invoice every column but
  id, company and timestamps, its rows and its ROT/RUT buyers
  (`DraftFingerprint`). The editors' save-before-post keeps it; any change
  gives a new subject. Open requests on other versions are withdrawn under the
  draft's lock when a save changes the version, and when a version is sent,
  decided, posted or issued, and when a voucher draft is deleted. The card
  carries the version it showed (`#[Locked]`), and every action re-checks it
  under the row lock. A version already approved cannot be rejected.
  An additive index `approval_requests_subject_prefix` (company, type,
  `subject_id text_pattern_ops`) serves the prefix lookups.
- **Sending.** The editors' Bokför and Skapa send the draft to approval
  (`SubmitForApproval`, a new `ApprovalEngine::submit()`) when a policy holds
  it; the card offers Skicka till attest, Attestera, Avvisa and, for an
  approved voucher, Bokför (with a confirmation). Approving
  (`ApproveDraft`) never posts.
- **Preparers.** The creator and everyone who saved the subject or changed a
  voucher's attachments (`DraftEditors`, read from the audit log; new entries
  `voucher.draft_saved` and `invoice.draft_saved` with a row count only). The
  same rule now covers purchase orders (`inventory.purchase_order_saved` and
  `_revised`, also in `SendPurchaseOrder`'s threshold mode) and purchase
  requisitions (`inventory.purchase_requisition_saved`).
  **Transition:** vouchers and invoices have no `updated_by` column, and draft
  saves were not audited before this release. For a draft last changed before
  the release, the creator, the attachment entries (`voucher.attachment_*`,
  audited before) and everyone who saves it from now on count; an earlier
  editor who neither created it nor touched its attachments cannot be seen.
  Such drafts are few (manual drafts in waiting) and a policy is opt-in, so
  this is accepted and noted here.
- **Wiring check (requisitions and orders).** Purchase orders and purchase
  requisitions go through the generic `RejectApproval`/`ResubmitApproval`
  and the queue: the queue reads submitted requisitions (only while Lager is
  on) with their rows in one batch (`SubjectFacts::forPurchaseRequisitions`)
  and links to `inventory.requisitions.show`.
- **ADR-067 source.** `App\Domain\Review\Contracts\ReviewSource`,
  `ReviewItem`, `ReviewSources` and `Sources\ApprovalRequestSource`: the open
  requests the person may decide on their current step, for every wired
  subject, in a fixed number of queries. Page `/att-godkanna`
  (`approvals.queue`, under Inköp), read-only with links (ADR-067 phase 1).
  A draft saved after it was sent is listed as stale and read-only.
- **Archive package.** Requests and decisions for the year's manual vouchers
  (by fiscal year) and credit invoices (by invoice date) join `attest/`; the
  requests are read first and only the drafts they name are looked up.
- **Audit.** The inbox's failed automatic booking records a code
  (`approval_required`, `validation`, `not_authorised`) and field names, never
  the validation messages.
- **Limited members (gap 10).** The card is not shown to a member limited to
  cost centres or projects, and the queue page is refused for them.

## Addendum: E11 as built (three-way matching per row)

Built on branch `feat/erp-e11-trevagsmatchning`, on top of E2 and E10. Owner
decisions 10 and 11 apply.

- **Allocation is its own table.** Supplier invoice rows carry an account and
  an amount but no quantity, and a registered invoice's rows never change, so
  which order row an invoice row pays for, and the quantity charged in the
  order row's unit, live in `purchase_match_allocations` (one order row per
  invoice row, unique on the invoice row). Allocations change only while the
  invoice is `REGISTERED`; an order row with an allocation or a stored result
  cannot be removed from its order (`SavePurchaseOrder::guardRemoval`).
  "Föreslå efter belopp" allocates a row only when its amount equals the
  received (else the ordered) value of exactly one free order row.
- **The comparison** (`ThreeWayMatch`, one result per order row with
  allocations, one per unallocated invoice row): quantities in the order
  row's unit (stock quantity × typed ÷ stock, half up; a row without an
  article counts in full once confirmed); previously invoiced = allocations
  of other non-credit invoices on the same row; quantity difference =
  invoiced − (received − previously invoiced); expected = invoiced quantity ×
  order price, half up. Only charging more is a deviation: quantity over
  received + the quantity % of received, price over max(price % of expected,
  absolute amount), nothing received or confirmed, or a cancelled order.
  Unallocated rows pass together when their sum is at most the absolute
  amount (freight, rounding), else `UNMATCHED`. Credit notes are not
  compared, and supplier credit notes do not reduce "previously invoiced".
- **Stored results.** `purchase_match_results` keeps every figure, the
  tolerances used, reason codes and a SHA-256 of the content. A comparison is
  stored only when its set of fingerprints differs from the current set; the
  old set is superseded (`superseded_at`). A trigger refuses DELETE and every
  UPDATE except superseding once and overriding an `OUTSIDE`/`UNMATCHED` row
  once (`NoDelete` grant). `stock_receipt_line_id` is a plain number without
  a foreign key, because an open receipt's lines are rebuilt. Comparisons stop
  once the invoice leaves `REGISTERED`, so the results it was approved with
  stay current.
- **Override** needs the `approve` ability on the invoice (the default
  attest, or deciding the current step of a company flow), a reason of 3–500 characters and the result id
  the person saw; a superseded result refuses it. An override follows to a new
  set only for a row whose fingerprint is unchanged.
- **Attest hook.** `ApproveSupplierInvoice` calls
  `PurchaseMatchAttest::check()` under the invoice lock before each decision,
  on the default attest and (after the engine authorises the decision) on
  each step of a company flow; a refusal rolls the decision back. The
  invoice page carries a token of the comparison it showed
  (`SupplierInvoiceActions::$matchSeen`, updated by the panel's
  `purchase-match-updated` event); an approval of a comparison with open
  deviations that is not the token is refused. Without a token (the API), the
  check is that the stored set did not change in this call. With
  `match_outside_blocks_attest` open deviations refuse; without it the
  approval goes through and `inventory.purchase_match_attested_with_deviations`
  records the count.
- **Segregation.** While `separate_supplier_approval` is on, a user who
  allocated a row of the invoice counts as its preparer: they can neither
  approve it nor override a deviation (the registrar was already excluded).
- **Settings.** The four `match_*` settings change only with the right to
  change the attest rule (`update` on the company, as
  `SetSupplierApprovalRule`); others see them disabled and a save that changes
  them is refused.
- **Unlinking** an order while the invoice has open or overridden deviations
  needs `supplier-invoices:approve`.
- **Known limits.** Supplier credit notes are not deducted from "previously
  invoiced", which can only give false positives (a row flagged that a credit
  note already settled), never let an over-charge through. Receiving goods is
  not segregated from approving the invoice: the person who registers the
  goods receipt may attest.
- **Archive package.** Not included yet; `TODO(legal-verify)` whether the
  results and overrides are behandlingshistorik to keep as
  räkenskapsinformation. Nothing is pruned.

## Addendum: E8 as built (project WIP and billing plan)

Built on branch `feat/erp-e8-projekt-pagaende`. Choices the gap 6 text left open:

- **One method for fixed-price jobs.** K2 6.15 asks for one method for all
  fixed-price jobs, so the method moved from the work order module's settings
  to the company: nullable `teams.fixed_price_method` (check constraint on the
  five values). `App\Domain\Projects\FixedPriceMethods::for()` reads it, else
  the module setting, so nothing changes on deploy; `WorkOrdersSettings::
  fixedPriceMethod()` delegates to it, and the Arbetsorder settings form also
  saves the company value (`SetFixedPriceMethod`, `fiscal-years:manage` only,
  since it is an accounting principle; a work order manager without it gets a
  refusal when changing the method; audit `company.fixed_price_method_changed`).
- **Generalised OngoingWork.** The fixed-price rule is extracted to the public
  `OngoingWork::fixedPrice()` (degree from costs over the estimate, earned
  revenue, adjustment on 1620/2450 or invoiced to 2450, K1 and "not set" give
  figures only, loss advice); `projectCosts()` became public. Work orders call
  it unchanged, except that a negative degree (credits above debits on the
  cost accounts) is now clamped to 0.
- **Project WIP** (`App\Domain\Projects\ProjectWip`, five queries): projects
  with price form `FIXED` or `TIME_AND_MATERIAL`, except those with a work
  order that the work order step counts on the same date
  (`OngoingWork::countedOn`: created by the date, not cancelled, not closed
  by it); those stay with Arbetsorder → Pågående arbeten, so nothing is
  counted twice or missed. Contract sum and estimated total cost come from the
  register; invoiced is the net of issued invoice rows with the project up to
  the date (credits netted); the revenue account is the one most invoiced on
  the project, else 3001. `TIME_AND_MATERIAL` shows "Kräver arbetsorder för
  upparbetat värde" (owner decision 16). Lines without costs or invoices are
  left out.
- **Page.** Bokslut → Pågående projekt at `/bokslut/pagaende-projekt`
  (`accounting.projectWip`), reached from the closing checklist item
  `project_wip` (WARN, shown when a fixed-price project without a work order
  exists) and the manual; no menu item. `ProjectPolicy::proposeWip`:
  `projects:view` and `vouchers:create`, never a limited member.
- **Drafts only, from what was seen.** `ProposeProjectWipVouchers` locks the
  projects, recomputes and compares the page's `#[Locked]` fingerprint
  (SHA-256 of date, method, figures and rows); a mismatch refuses. Two drafts
  through `SaveDraftVoucher` (adjustment, reversal the next day) with the
  project on every row; refused while either voucher (adjustment or
  reversal) of an earlier proposal for the same date still exists, draft or
  posted. `project_wip_snapshots` keeps each project's figures and
  the draft ids, append-only (model events and a trigger; `AppendOnly` grant;
  voucher ids without foreign keys so drafts can still be deleted). Audit
  `projects.wip_proposed`.
- **Billing plan.** `project_billing_milestones` (`ReadWrite`): amount or
  share (CHECK exactly one, share 1–10000 bp, shares of a plan at most
  100 %), status `PLANNED`/`READY`/`INVOICED`, `invoice_id` uuid with
  `nullOnDelete`, so deleting the draft invoice frees the milestone
  (`effectiveStatus()`). Kept with `projects:manage` or `accounts:manage`;
  "Fakturera" needs `invoices:create` and a `READY` milestone, and makes one
  draft invoice row (description, 1 × amount, the project) for the project's
  customer through `SaveDraftInvoice`. Neither customers nor projects carry a
  default VAT rate and a milestone has no article, so the rate is 25 % and the
  toast asks the person to check it. "Fakturerat ur planen" counts issued
  invoices only; milestones with a draft show as "Utkast". Edits, deletion and
  invoicing compare the milestone's version token under the row lock. The
  project page shows the plan and, with Arbetsorder on, the work orders'
  a conto instalments read only. Limited members never see the plan
  (`@can('viewBillingPlan')` on the page; the components are refused in
  `DimensionScopeRegistry` by default).
- **Legal items** (`TODO(legal-verify)`):
  - K2 6.13–6.24 and K3 chapter 23 for projects without work orders (L12/L13
    as for work orders).
  - Cost base: whether all costs on 4000–7999 are the right base for the
    degree of completion ("nedlagda utgifter").
  - Kontantmetoden: how WIP applies to a company that books invoices on
    payment.
  - Advance-invoice VAT: a milestone invoiced before the work is done is an
    advance invoice; its wording, and its VAT, which arises when the payment
    is received (Mervärdesskattelag (2023:200) 7 kap. 7 §, read on
    riksdagen.se 2026-11-18) and is reported for that period (7 kap. 14 §).
- **Two balance dates in one year.** Each proposal is reversed the day after
  its date; with two dates (for example a year end and a later correction)
  both reversals must be posted, which the manual says.

## Addendum: E27 as built (intra-group delivery and paired drafts)

Built on branch `feat/erp-e27-koncernleverans`. Choices gap 1 left open:

- **Migration** `2027_02_27_100000_create_intercompany_delivery_tables` (three
  company-scoped tables, each with a trigger that refuses an invoice or voucher
  of another company) and, in the inbox module,
  `2027_02_27_100100_widen_inbox_message_channel` (`inbox_messages.channel`
  varchar 10 → 20 for `GROUP_DELIVERY`; existing values unchanged).
  `intercompany_deliveries` is `GrantDecision::AppendOnly` and refused in the
  model; a partial unique index allows one `DELIVERED` row per invoice; a CHECK
  ties `delivered_at` to `DELIVERED` and allows `corporate_group_id` only there,
  so a refusal stores neither a group nor a reason. Pairs and receipt settings
  are `ReadWrite`.
- **Where delivery is offered.** Only for an issued (not imported) invoice to a
  customer the sender has marked as a group company (E23); the recipient is the
  marking's organisation number, which must equal the one on the invoice.
  Offering the button reveals nothing: it depends on the sender's own marking.
- **The group rule** is `App\Domain\Group\GroupCounterparts`: both companies
  have a member row in one corporate group (the parent's row has no holding) and
  every holding grounding a subsidiary's membership is acquired on or before
  today and not disposed of on or before today. It reads holding dates through
  `CompanyScope::bypass` (commented), and returns only yes/no and the group id.
- **Refusal is uniform.** No company with the number, not in a shared active
  group, no enabled receipt setting from the sender's number, inbox module off,
  receiver archived or a test company: the same `REFUSED` row, the same audit
  action and payload keys, the same Swedish toast
  (`DeliverIntercompanyInvoice::refusedMessage()`). The tenancy test compares
  the eight cases. The receiver is looked up only among
  `GroupCounterparts::of($sender)` (by id and organisation number), so "no
  company has the number" and "a company outside the group" run the same
  queries. Cases among the sender's own group companies (inbox off, no
  setting, archived, test company) may still differ slightly in time.
- **The write into B** happens inside `CurrentCompany::run($b)` and inside
  `Audit::withoutPerson()` (new, small: entries keep no signed-in user, IP or
  user agent), so B's log gets no person from A. The inbox treats
  `GROUP_DELIVERY` like `PEPPOL`: the UBL file is the original, it is read by the
  Peppol reader, and a rejected or unhandled delivery is never pruned. The
  sender's `delivered_by` stays in A only.
- **Paired drafts** (`CreatePairedDraft`) are offered on the voucher page
  (`PairedDraftPanel`, not rendered for a dimension-limited member) for posted
  vouchers, to companies where the user holds `vouchers:create` (through
  `Memberships`, which already refuses limited members and archived companies)
  and shares an active group. Kinds are the ten without an invoice
  (`IntercompanyMirror::PAIRED_KINDS`); the counterpart's kind is the mirror.
  Account suggestions mirror 1320↔2360, 1560↔2460, 1660↔2860 and 8820↔8830
  (ranges of ten; same TODO(legal-verify) as `IntercompanyKindRule`); everything
  else keeps its number. Amounts always come from the posted voucher, never from
  the form. The draft is saved through `SaveDraftVoucher` in B, which rebuilds
  rows by number and code. B's pair row names A's voucher reference; A's row
  starts without B's reference (a draft has no number) and a person enters it
  (`SetPairReference`). While B's draft exists a second one for the same voucher
  is refused; deleting the draft deletes B's pair row (cascade), and A's row is
  reused.
- **Screens.** The receipt setting, the delivery log and the pairs are a second
  component on Bokföring → Koncernmarkeringar (`IntercompanyDeliveries`), so no
  new route or menu entry; the delivery panel is on the invoice page under
  Peppol. Systemdokumentation 4.10c, version 0.145.
- **Cold review (2026-11-18).** The sender keeps its own copy: the delivered
  file is stored at `koncern/{company}/utgaende/{delivery}.xml` (the row's id is
  taken from the sequence first) with `xml_path` and `xml_sha256`, required by a
  CHECK on DELIVERED rows (migration `2027_02_27_100200`), registered in
  `StoredFiles` and copied into the sender's archive package under
  `kundfakturor/koncernleveranser/`. Both memberships are read `FOR SHARE` and
  B's receipt setting `FOR UPDATE` inside the delivery's transaction, after a
  shared lock on the `corporate_groups` row (lock order group → members →
  receipt setting, the same group-first order as `RemoveGroupMember`, so the two
  cannot deadlock), and the group is checked again after the locks. Not locked:
  a holding disposed of (`company_holdings.disposed_on`) at the same instant; a
  delivery that commits just before the disposal stands, as an e-mailed invoice
  would. Files written for an attempt that
  does not commit are deleted. A paired draft takes its rows, their number and
  amounts from `propose()`; the form only gives accounts, texts and codes. The
  counterpart's reference carries the fiscal year (`A12 (2026)` for a calendar
  year, the start and end dates for any other year,
  `CreatePairedDraft::reference`), so the duplicate guard does not mix years.
  Interest (8300–8499) and dividend (8010–8019) rows keep their number and get a
  "Kontrollera kontot hos motparten" warning (TODO(legal-verify) in
  `IntercompanyMirror::needsCheck`). `invoice_id` is a plain foreign key.

## Addendum: E13 as built (transfer orders, reorder points per warehouse)

- **Transit.** A transit place is a `stock_locations` row of type `TRANSIT`,
  inactive, one per pair of warehouses (`transit_from_id`, `transit_to_id`).
  Only movements of type `TRANSFER` with a `StockTransfer` as source may touch
  it; `StockLedger` refuses everything else (scanner, API, counts, hand moves).
  Counts, production issues, pickers and the scanner leave it out
  (`StockLocation::scopePhysical()`). A location, or its warehouse, cannot be
  deactivated while a sent order points at it.
- **Valuation** is untouched: goods in transit stay in `stock_levels`, so the
  article's total and value are the same all the way; a transfer carries no cost.
- **Reorder points per warehouse** replace the article's own point in the
  purchase proposal. Simplifications, also stated in the manual:
  - A surplus in one warehouse is not netted against the shortfall of
    another; a transfer order may be the better choice.
  - Draft transfer orders are not counted; goods on their way to a warehouse
    are.
  - Open purchase orders are subtracted company-wide, because they carry no
    warehouse.
  - Reserved quantity counts as in the company-wide path
    (`have - reserved + incoming`): the sum of the warehouses' shortfalls plus
    what is reserved, minus what is incoming.
- **Warehouse scope** is the `cost_center_id` mapping only; E1 refuses
  `inventory.*` for limited members, so nothing is enforced yet.
