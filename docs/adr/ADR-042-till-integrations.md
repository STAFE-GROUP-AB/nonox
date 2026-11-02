# ADR-042: Till integrations in Connect: Zettle and Onslip, series K

- Status: **Accepted** (built; awaiting the owner's app registrations with Zettle and Onslip, issues #98 and #99)
- Date: 2026-11-02
- Builds on: ADR-017 (module framework), ADR-032 (NONOX Connect), ADR-034
  (NONOX Banking: OAuth client with encrypted credentials), ADR-039 (Lager:
  `StockLedger`, `MovementType::PosSale`, `StockChanged`)

## Context

Shops, cafés and restaurants sell through a till. Until now the till's
daily report reached NONOX by e-mail (Inkorg) or as a SIE file, and stock
sold at the till had to be taken out of Lager by hand. ADR-039 left a
point-of-sale sync as the next step. The two tills most asked for by the
target users are Zettle and Onslip 360.

## Decision

A module **Kassakopplingar** (key `pos`, `app/Modules/Pos`), off by
default, that **depends on Lager** (`inventory`). Its page is
`/anslutningar/kassa` (route `pos.index`, menu item "Kassa"); the Connect
page links to it while it is on. It is a separate module rather than part
of `connect`, because turning Connect off revokes API grants and keys
(ADR-032), while a till connection is NONOX acting as an OAuth *client*,
the same shape as Banking.

### Framework (shared by every till)

- `PosDriver` interface with `ZettleDriver` and `OnslipDriver`: authorize
  URL, code exchange, revoke, fetch and parse sales, push a product, push
  a stock change, list the till's products. Every call is made once; the
  queued jobs retry with backoff (`PushStockJob` 30 s … 1 h, six tries;
  `SyncProductsJob` four backoffs), one run per connection at a time
  (`WithoutOverlapping`). Every job carries `company_id` and runs in it.
- Tables (all `BelongsToCompany`, all in the tenancy suites):
  `pos_connections` (provider, status, `credentials` with the
  `encrypted:array` cast and hidden, an opaque 48-character
  `webhook_token`, settings, stock location; a partial unique index lets
  one merchant account feed one company at a time),
  `pos_product_links` (article ↔ till product/variant, the hash of what was
  last sent), `pos_sales` (unique on company + provider + the till's id;
  totals in öre per VAT rate in basis points, payments per method, tips,
  warnings, the raw payload and its SHA-256), `pos_sale_rows`, `pos_fees`,
  `pos_stock_pushes` (unique per connection and movement),
  `pos_webhook_messages` (unique per connection and message id) and
  `pos_log_entries` (the Swedish log on the page).
- **Sales in, exactly once.** `RecordPosSale` inserts the `pos_sales` row
  first, inside the transaction, with `INSERT … ON CONFLICT DO NOTHING`;
  a replayed webhook, the nightly fetch or a second worker find the key
  taken and change nothing. Only then are stock movements posted.
- **Matching.** A goods line is matched to an active article by the till
  product's link, else GTIN, scanner code, SKU or article number. A
  matched stocked article gets a `POS_SALE` movement (sold: out, returned:
  in) from the connection's stock location, else the article's default
  location, through `StockLedger::post()` with the sale as source. A line
  without an article, a location or enough stock (negative stock off) is
  left `UNMATCHED` and shown under **Omatchade rader**; a person with
  `inventory:manage` links it (`LinkPosSaleRow`), which also links the
  till's product for the future and posts every waiting line of the same
  product, or sets it aside. Lines without goods (custom amounts, gift
  cards) are `SKIPPED`.
- **Stock back to the till, as deltas.** `QueueStockPushes` listens to
  `StockChanged` and queues one `pos_stock_pushes` row per movement of a
  linked article; the till gets the change, never an absolute balance, so
  a sale at the till in the meantime is never overwritten. A `POS_SALE`
  movement whose source is a sale of the same connection is skipped (no
  loops). With a stock location on the connection only movements in its
  subtree count; without one, every movement but transfers. Each push has
  a UUID v5 derived from connection and movement, sent as Zettle's
  `externalUuid` on every attempt.
- **Articles out.** `SyncProducts` sends active articles whose content hash
  changed (at most 200 per run); with two-way sync the till's existing
  products are first linked by GTIN/scanner code/SKU/number so nothing is
  created twice. Nothing is deleted in the till. A saved article queues a
  sync (debounced 15 s per connection).
- **Daily sales → bookkeeping.** `CreateDailyDraft` builds one **draft**
  voucher per company and day in a new series **K "Kassa"** from every
  sale and fee of that day not yet in a voucher, and marks them with the
  voucher (deleting the draft frees them). It is never posted by NONOX.
  Rows (`DailySettlement`): credit sales per VAT rate (net) and output VAT
  per rate; debit each payment method's account; credit tips; debit card
  fees against the card settlement account; a difference of at most one
  krona per sale to öresutjämning. A bigger difference gives no voucher,
  only the difference. Accounts are module settings with BAS 2026
  defaults that exist in NONOX's chart templates: 3001/3002/3003/3004,
  2611/2621/2631, card 1680, cash 1910, Swish 1930, invoice 1510, gift
  card 2890, other 1680, fees 6570 against 1680, rounding 3740, tips 2890.
  The command `pos:daily-drafts` (05:20) creates the drafts for past days
  in the name of the person who connected the till, if they still have
  `vouchers:create`.
- **Series K** is an on-demand series (`CreateDefaultVoucherSeries::ON_DEMAND`):
  it is created when the module is turned on, when a till is connected and
  before a daily draft (only K, through the new `$codes` argument), never
  for every company. The brief asked for K among the defaults for new
  companies; that was not done, because a SIE import keeps a file's own
  series K as "Inläst serie K" when the company has none, and a default K
  "Kassa" would silently merge another program's series K into the till
  series (SieImportTest). No existing voucher or series is touched.
- **Permissions** (no new ones): connecting, disconnecting and a
  connection's settings `settings:manage`; the page and its log
  `inventory:view` (or `settings:manage`); linking or setting aside
  unmatched lines `inventory:manage`; the daily draft `vouchers:create`.
- **Not configured.** Without the env values the till is shown with
  "Inte konfigurerad på den här servern" and cannot be connected.
- **Schedule.** `pos:reconcile-sales` hourly (two days back) and nightly at
  03:40 (seven days back): fetches each active connection's sales,
  records the new ones, and for Zettle the card fees. `--dry-run` only
  counts. `pos:daily-drafts` daily.
- Audit entries: `pos.connect_started`, `pos.connected`,
  `pos.disconnected`, `pos.settings_updated`, `pos.sale_recorded`,
  `pos.row_linked`, `pos.row_ignored`, `pos.stock_pushed`,
  `pos.daily_draft_created`.

### Zettle

Facts from Zettle's public API documentation (github.com/iZettle/api-documentation,
read 2026-11-02; the current portal developer.zettle.com renders in the
browser and could not be read as text):

- OAuth 2.0 authorization code: `https://oauth.zettle.com/authorize`
  (`response_type=code`, `client_id`, `scope`, `redirect_uri`, `state`),
  token `POST https://oauth.zettle.com/token` with `client_secret`;
  refresh with `grant_type=refresh_token` (rotation may be on: the newest
  refresh token is kept); access token lifetime 7200 s; `GET /users/self`
  gives `organizationUuid`; `DELETE /application-connections/self`
  disconnects. Source: `authorization.md`.
- Scopes asked: `READ:PRODUCT WRITE:PRODUCT READ:PURCHASE READ:FINANCE`
  (`product-library.adoc`, `purchase.adoc`, finance guides).
  `TODO(verify)`: whether `/users/self` needs an extra scope.
- Products: `POST https://products.izettle.com/organizations/self/products`,
  update `PUT …/products/v2/{uuid}` (JSON merge patch), price in minor
  units with `currencyId`, `vatPercentage` as a string, `barcode`, `sku`;
  UUIDs for new resources are version 1 (`product-library.adoc`,
  `pusher-api/user-guides/create-subscriptions.md`). Images need Zettle's
  image service and are **not** sent (`TODO`).
- Purchases: `GET https://purchase.izettle.com/purchases/v2` with
  `startDate`, `endDate`, `limit` (≤ 1000), `descending`,
  `lastPurchaseHash`; `purchaseUUID1`, `amount` and `vatAmount` in minor
  units, `refund`, `products[]` (`quantity` string, `type`
  PRODUCT/CUSTOM_AMOUNT/GIFTCARD, `rowTaxableAmount`, `vatPercentage`,
  `productUuid`, `variantUuid`, `barcode`, `sku`), `payments[]` (`type`,
  `gratuityAmount` included in the amount). Source: `purchase.adoc`.
  `TODO(verify)`: that `rowTaxableAmount` is the row's net after
  discounts; a sale whose rows do not add up carries a warning.
- Webhooks (Pusher): `POST https://pusher.izettle.com/organizations/self/subscriptions`
  with a v1 `uuid`, `transportName: WEBHOOK`, `eventNames:
  [PurchaseCreated]`, `destination`, `contactEmail`; the answer's
  `signingKey` is kept (encrypted). `X-iZettle-Signature` is the hex
  HMAC-SHA256 of the envelope's timestamp, a period and the payload;
  Zettle waits 10 s for the answer (`create-subscriptions.md`). NONOX
  verifies, stores, queues and answers 200; the `TestMessage` is answered
  without a key; envelopes up to seven days old are accepted (Zettle
  re-delivers with the original timestamp; recording is idempotent).
  Address: `POST /hooks/kassa/zettle/{token}` (technical path, no
  session; the connection is found by the opaque token, never by the
  organisation in the body).
- Inventory: `GET https://inventory.izettle.com/organizations/self/locations`
  (STORE, SOLD, SUPPLIER, BIN; cached a day), start tracking
  `POST /organizations/self/inventory {productUuid}`, change
  `PUT /organizations/self/inventory {changes[], externalUuid}`: increases
  SUPPLIER → STORE, decreases STORE → BIN, whole units only
  (`inventory-api/user-guides/…`). `TODO(verify)`: newer inventory paths
  on developer.zettle.com, and whether `externalUuid` deduplicates.
- Finance: `GET https://finance.izettle.com/v2/accounts/liquid/transactions`
  with `start`, `end`, `includeTransactionType=PAYMENT_FEE`, `limit`,
  `offset` (`finance-api/user-guides/fetch-account-transactions-v2.md`).
  The fee is booked as the negated amount.

### Onslip 360

Facts from developer.onslip360.com (read 2026-11-02) and Onslip's
Apache-2.0 PHP client on github.com/Onslip (read for wire details only;
not a dependency, nothing copied):

- Base `https://api.onslip360.com/v1/`, sandbox
  `https://test.onslip360.com/v1/`; resources under `~{realm}/`
  (`/docs/api-users-guide/general-api-features`).
- OAuth authorization code with PKCE S256: authorize
  `https://www.onslip360.com/oauth-authorization` (sandbox
  `https://test.onslip360.com/oauth-authorization`) with `client_id` (the
  Integration's alias), `code_challenge_method=S256`, `code_challenge`,
  `redirect_uri`, `response_type=code`, `scope` (permission names),
  `state`; the code lives 60 s; token `…/v1/oauth-token.json`; the
  answer's `access_token` is the Hawk id and `secret` the Hawk key
  (`/docs/api-users-guide/general-api-features/oauth`). The token call's
  JSON body (`grant_type`, `code`, `code_verifier`, `redirect_uri`,
  `client_id`) and the `realm` in the answer come from the published
  client: `TODO(verify)` with Onslip.
- Hawk on every call, SHA-256, `Authorization: Hawk id, ts, nonce, hash,
  mac`; NONOX's own `Hawk` class is tested against the examples published
  with the Hawk protocol (github.com/mozilla/hawk, `API.md`), and PKCE
  against RFC 7636 Appendix B.
- Permissions asked: `show-products edit-products show-product-groups
  show-records show-tills`; never `edit-records`.
- Sales: tills `~{realm}/tills/`, receipts `~{realm}/journal/{till}/` with
  `q=type:receipt AND date>=… AND date<…`, paged with `o`/`c` and
  `X-Total-Count`, query parameters separated with `;`
  (`/resources-and-endpoints/records`, query language page). Receipt
  types `sale` and `return` are read; `credit` is not a sale. Amounts are
  numbers in kronor and are read exactly into öre. `TODO(verify)`: that a
  date range in `q` works on `listRecords`, and the sign of return
  receipts. Sales are fetched (hourly and nightly); Onslip's triggers
  (webhooks) are not used yet.
- Products: `POST ~{realm}/products/`, `PATCH ~{realm}/products/{id}`,
  price including VAT, VAT on the **product group**: the connection maps
  each VAT rate to a product group; unmapped rates are not sent.
- **No stock API** is documented: nothing is sent to Onslip's stock;
  sales still take goods out of Lager.
- No revoke call is documented: disconnecting erases the credentials and
  the manual tells the user to remove the integration in Onslip.

## Open legal questions (`TODO(legal-verify)`)

- Whether the till's own Z-report (kassaregisterlagen, SKVFS 2014:9) must
  be the verifikation's underlag for the daily voucher, and whether a
  summary from the till's API may serve. Until then the manual tells the
  user to attach the Z-report.
- Every default account above, in particular card settlement on 1680
  (BAS has 1580 for card receivables, not in the template), gift cards
  on 2890 (single- or multi-purpose voucher, ML 2023:200 ch. 3) and tips
  on 2890.
- The VAT treatment of card fees (booked without VAT; the provider may
  invoice from abroad).
- Whether invoice payments made at the till should be booked from the
  daily summary at all.

## Not done

- A company that already has a series K (for example "Inläst serie K" from
  a SIE import) keeps it; the daily drafts then go into that series. A
  choice of series for the drafts is not built.
- Pushing article images to Zettle; deleting products in the till.
- Onslip webhooks (triggers), Onslip stock, Onslip API keys entered by hand.
- Zettle's API-key (assertion) grant for self-hosted apps.
- Manual adjustments made in the Zettle app are not read back
  (`InventoryBalanceChanged`); NONOX is the master for stock counts.
- `pos_integrations` stays SOON on the public pages until the owner has
  registered the apps and connected a real till.
