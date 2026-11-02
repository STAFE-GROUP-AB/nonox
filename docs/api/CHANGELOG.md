<!-- lang:sv -->
# Ändringar i API:t
<!-- /lang -->
<!-- lang:en -->
# API changelog
<!-- /lang -->

<!-- lang:sv -->
Varje ändring i NONOX Connect API, den senaste först. Inom v1 görs bara tillägg (nya anrop, nya frivilliga fält, nya värden i svar); något som skulle bryta en befintlig integration får `/v2`, och v1 finns kvar bredvid i minst tolv månader. Filen visas på [nonox.app/utvecklare/andringar](https://nonox.app/utvecklare/andringar).
<!-- /lang -->
<!-- lang:en -->
Every change to the NONOX Connect API, newest first. Within v1 changes are additive only (new endpoints, new optional fields, new values in responses); anything that would break an existing integration gets `/v2`, and v1 stays alongside for at least twelve months. This file is shown at [nonox.app/utvecklare/andringar](https://nonox.app/utvecklare/andringar).
<!-- /lang -->

<!-- lang:sv -->
## 2026-11-02: v1.3.0
<!-- /lang -->
<!-- lang:en -->
## 2026-11-02: v1.3.0
<!-- /lang -->

<!-- lang:sv -->
### Tillagt

- **Kundfakturor i utländsk valuta**: `invoice_currency` och `exchange_rate_micros` (frivillig, annars Riksbankens kurs) på `POST /invoices` och `PATCH /invoices/{id}`, och radpriset `unit_price_minor` i hundradelar av valutan. Svaret har valutan, kursen med datum och källa och beloppen i valutan (`foreign_*_minor`, rader `unit_price_minor` och `net_minor`). Alla `*_ore` är fortfarande kronor.
- **Betalning i valuta**: `foreign_amount_minor` på `POST /invoices/{id}/payments`; `amount_ore` är kronorna som kom in. Betalningen svarar med `foreign_amount_minor` och `exchange_difference_ore`.
- **Kunder**: `currency`.
- **Webhooks**: `invoice.sent` och `invoice.paid` har med `invoice_currency` och valutabeloppen; `invoice.paid` även betalningens belopp och kursdifferens.
<!-- /lang -->
<!-- lang:en -->
### Added

- **Customer invoices in foreign currency**: `invoice_currency` and `exchange_rate_micros` (optional, default Riksbanken's rate) on `POST /invoices` and `PATCH /invoices/{id}`, and the row price `unit_price_minor` in hundredths of the currency. Responses carry the currency, the rate with its date and source and the foreign amounts (`foreign_*_minor`, rows `unit_price_minor` and `net_minor`). Every `*_ore` is still kronor.
- **Payment in currency**: `foreign_amount_minor` on `POST /invoices/{id}/payments`; `amount_ore` is the kronor received. The payment answers with `foreign_amount_minor` and `exchange_difference_ore`.
- **Customers**: `currency`.
- **Webhooks**: `invoice.sent` and `invoice.paid` carry `invoice_currency` and the foreign amounts; `invoice.paid` also the payment's amount and exchange difference.
<!-- /lang -->

<!-- lang:sv -->
## 2026-11-02: v1.2.0
<!-- /lang -->
<!-- lang:en -->
## 2026-11-02: v1.2.0
<!-- /lang -->

<!-- lang:sv -->
### Tillagt

- **Planering**: `GET /planning/shifts?from=…&to=…` (det publicerade schemat, så som de anställda fick se det, högst 93 dagar; `include_drafts=true` för planen som den är nu), `GET /planning/areas`, `GET /planning/publications`.
- **Scope**: `planning:view`. Ingen har den när modulen Planering är avstängd.
- **Webhooks**: `planning.published` (en period av schemat har publicerats) och `planning.attested` (tid har attesterats; kräver `planning:attest`).
<!-- /lang -->
<!-- lang:en -->
### Added

- **Scheduling (Planering)**: `GET /planning/shifts?from=…&to=…` (the published schedule as the employees were shown it, at most 93 days; `include_drafts=true` for the plan as it stands now), `GET /planning/areas`, `GET /planning/publications`.
- **Scope**: `planning:view`. No one has it while the Planering module is off.
- **Webhooks**: `planning.published` (a period of the schedule was published) and `planning.attested` (time was attested; needs `planning:attest`).
<!-- /lang -->

<!-- lang:sv -->
## 2026-11-01: v1.1.0
<!-- /lang -->
<!-- lang:en -->
## 2026-11-01: v1.1.0
<!-- /lang -->

<!-- lang:sv -->
### Tillagt

- **Webhooks** skickas nu: `voucher.posted`, `invoice.sent`, `invoice.paid`, `supplier_invoice.approved`. Signerade med `Nonox-Signature: t=<unix>,v1=<HMAC-SHA256 av "t.kropp">`, med `Nonox-Event-Id` och `Nonox-Event-Type`, skickade efter commit, åtta försök under ungefär ett dygn och sedan avstängning med mejl till ägaren. Hanteras under Anslutningar (ägare eller administratör, passkey).
- **Återkallelse av token** (RFC 7009): `POST /oauth/revoke`, och `revocation_endpoint` i metadata.
- **Artiklar**: `GET/POST /articles`, `GET/PATCH /articles/{id}`.
- **Kostnadsställen och projekt**: `GET/POST /cost-centers`, `GET/PATCH /cost-centers/{id}`, samma för `/projects`.
- **Kundfakturor**: `GET/POST /invoices`, `GET/PATCH /invoices/{id}`, `POST /invoices/{id}/issue`, `/send`, `/credit`, `/payments`, `GET /invoices/{id}/pdf`.
- **Leverantörsfakturor**: `GET/POST /supplier-invoices`, `GET/PATCH/DELETE /supplier-invoices/{id}`, `POST /supplier-invoices/{id}/file`, `/register`, `/approve`, `/payments`.
- **Betalningar**: `GET /payments`, `GET /supplier-payments`.
- **Rapporter**: `GET /reports/trial-balance`, `/general-ledger`, `/balance-sheet`, `/income-statement`.
- **Moms**: `GET /vat-periods`, `GET /vat-return`.
- **Räkenskapsår**: `GET /fiscal-years/{id}/periods` (låsta månader), `GET /fiscal-years/{id}/sie` (SIE 4).
- **Scopes**: `accounts:manage`, `reports:view`, `vat:prepare`, `export:sie`, `invoices:send`, `invoices:credit`, `payments:create`.
- `required_scope_sets` i `403 insufficient_scope`: vilka kombinationer av scopes som räcker.
- **Testföretag** med exempeldata för att prova API:t (`php artisan connect:test-company` eller under Anslutningar).

### Ändrat

- Beskrivningarna av `invoices:view`, `invoices:create`, `supplier-invoices:create` och `supplier-invoices:approve` täcker nu också de nya resurserna. En nyckel eller app som redan har dem får tillgång till de nya adresserna inom samma område; inget som fungerade tidigare slutar fungera.
<!-- /lang -->
<!-- lang:en -->
### Added

- **Webhooks** are now delivered: `voucher.posted`, `invoice.sent`, `invoice.paid`, `supplier_invoice.approved`. Signed with `Nonox-Signature: t=<unix>,v1=<HMAC-SHA256 of "t.body">`, with `Nonox-Event-Id` and `Nonox-Event-Type`, sent after commit, eight attempts over about a day and then disabled with a mail to the owner. Managed under Anslutningar (owner or administrator, passkey).
- **Token revocation** (RFC 7009): `POST /oauth/revoke`, and `revocation_endpoint` in the metadata.
- **Articles**: `GET/POST /articles`, `GET/PATCH /articles/{id}`.
- **Cost centres and projects**: `GET/POST /cost-centers`, `GET/PATCH /cost-centers/{id}`, the same for `/projects`.
- **Customer invoices**: `GET/POST /invoices`, `GET/PATCH /invoices/{id}`, `POST /invoices/{id}/issue`, `/send`, `/credit`, `/payments`, `GET /invoices/{id}/pdf`.
- **Supplier invoices**: `GET/POST /supplier-invoices`, `GET/PATCH/DELETE /supplier-invoices/{id}`, `POST /supplier-invoices/{id}/file`, `/register`, `/approve`, `/payments`.
- **Payments**: `GET /payments`, `GET /supplier-payments`.
- **Reports**: `GET /reports/trial-balance`, `/general-ledger`, `/balance-sheet`, `/income-statement`.
- **VAT**: `GET /vat-periods`, `GET /vat-return`.
- **Fiscal years**: `GET /fiscal-years/{id}/periods` (locked months), `GET /fiscal-years/{id}/sie` (SIE 4).
- **Scopes**: `accounts:manage`, `reports:view`, `vat:prepare`, `export:sie`, `invoices:send`, `invoices:credit`, `payments:create`.
- `required_scope_sets` in `403 insufficient_scope`: which combinations of scopes are enough.
- **Test companies** with demo data for trying the API (`php artisan connect:test-company` or under Anslutningar).

### Changed

- The descriptions of `invoices:view`, `invoices:create`, `supplier-invoices:create` and `supplier-invoices:approve` now also cover the new resources. A key or app that already has them gets access to the new endpoints in the same area; nothing that worked before stops working.
<!-- /lang -->

<!-- lang:sv -->
## 2026-11-01: utvecklarwebben
<!-- /lang -->
<!-- lang:en -->
## 2026-11-01: developer site
<!-- /lang -->

<!-- lang:sv -->
- Den publika utvecklarwebben på [/utvecklare](https://nonox.app/utvecklare), på svenska och engelska (`?lang=en`): guider, en referens som genereras från `openapi.yaml`, kodexempel i curl, PHP, JavaScript och Python, och specifikationen att ladda ner som YAML och JSON.
- `openapi.yaml`: taggarna har fått ett engelskt namn (`x-en-name`) och behörigheterna en svensk beskrivning (`x-sv-scopes`). Bara tillägg; inget anrop har ändrats.
<!-- /lang -->
<!-- lang:en -->
- The public developer site at [/utvecklare](https://nonox.app/utvecklare), in Swedish and English (`?lang=en`): guides, a reference generated from `openapi.yaml`, code samples in curl, PHP, JavaScript and Python, and the spec as YAML and JSON downloads.
- `openapi.yaml`: the tags have an English name (`x-en-name`) and the scopes a Swedish description (`x-sv-scopes`). Additions only; no endpoint changed.
<!-- /lang -->

## 2026-11-01: v1.0.0

<!-- lang:sv -->
Första versionen av NONOX Connect (ADR-032).

- **Anrop:** företaget, kontoplanen, momskoder, räkenskapsår, verifikationsserier, verifikationer (lista, hämta, skapa och ändra utkast, ta bort utkast, bokföra, rätta, underlag upp och ner), kunder och leverantörer (lista, hämta, skapa, ändra).
- **Autentisering:** OAuth 2.0 med auktoriseringskod och PKCE (S256 för alla appar), godkännande av ägare eller administratör med passkey, access tokens på högst 15 minuter, refresh tokens som byts vid varje användning och återkallar hela anslutningen om de används två gånger. API-nycklar med slutdatum och valfri IP-lista.
- **Regler:** `Idempotency-Key` på varje POST, sidindelning med markör och `updated_since`, fel som problem+json (RFC 9457), gränser per nyckel, företag och IP-adress med `RateLimit-*`-huvuden.
- **Kommer:** webhooks för `voucher.posted`, `invoice.sent`, `invoice.paid` och `supplier_invoice.approved` är beskrivna men skickas inte ännu.
<!-- /lang -->
<!-- lang:en -->
The first version of NONOX Connect (ADR-032).

- **Endpoints:** the company, chart of accounts, VAT codes, fiscal years, voucher series, vouchers (list, get, create and change drafts, delete drafts, post, correct, upload and download attachments), customers and suppliers (list, get, create, change).
- **Authentication:** OAuth 2.0 authorization code with PKCE (S256 for every app), consent by an owner or administrator with a passkey, access tokens of at most 15 minutes, refresh tokens rotated on every use that revoke the whole connection when used twice. API keys with an expiry date and an optional IP list.
- **Rules:** `Idempotency-Key` on every POST, cursor pagination with `updated_since`, problem+json errors (RFC 9457), limits per key, company and IP address with `RateLimit-*` headers.
- **Coming:** webhooks for `voucher.posted`, `invoice.sent`, `invoice.paid` and `supplier_invoice.approved` are described but not delivered yet.
<!-- /lang -->
