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
## 2026-11-10: enhetslistan
<!-- /lang -->
<!-- lang:en -->
## 2026-11-10: the unit register
<!-- /lang -->

<!-- lang:sv -->
### Tillagt
- `GET /units`: företagets enhetslista, med id, kod, namn, vad enheten mäter, Peppol-kod, alternativa stavningar och vilken enhet en rad utan artikel får (`is_default`).
- Fakturarader och artiklar har det nya fältet `unit_id`, enheten i enhetslistan. En rad eller artikel kan skickas med `unit_id` i stället för `unit`. `unit` är kvar och visar enheten som den skrivs på fakturan.

### Ändrat (brytande undantag, 2026-11-10)
- **Det här är ett medvetet undantag från regeln att v1 bara utökas.** Företagets ägare har bestämt att enheter inte får vara fritext. `unit` på fakturarader och artiklar ska vara en kod, stavning eller ett namn som finns i enhetslistan, till exempel `st`, `tim` eller `kilogram`. En annan text, ett `unit_id` som hör till ett annat företag eller en arkiverad enhet som fakturan eller artikeln inte redan har svarar 422 med fältet `rows.N.unit_id` (fakturor) eller `unit`/`unit_id` (artiklar). Tidigare sparades vilken text som helst.
- **Så anpassar du integrationen:** hämta enheterna med `GET /units` och skicka `unit_id`, eller en kod eller stavning ur listan i `unit`. Saknas en enhet lägger företaget till den under Artiklar → Enheter. En rad utan enhet får artikelns enhet, eller företagets standardenhet.
<!-- /lang -->
<!-- lang:en -->
### Added
- `GET /units`: the company's unit register, with id, code, name, what the unit measures, Peppol code, alternative spellings and the unit a row without an article gets (`is_default`).
- Invoice rows and articles have a new field `unit_id`, the unit in the register. A row or article can be sent with `unit_id` instead of `unit`. `unit` stays and shows the unit as printed on the invoice.

### Changed (breaking exception, 2026-11-10)
- **This is a deliberate exception to the rule that v1 only grows.** The owner decided that units may not be free text. `unit` on invoice rows and articles must be a code, spelling or name the register knows, for example `st`, `tim` or `kilogram`. Any other text, a `unit_id` of another company, or an archived unit the invoice or article does not already have answers 422 with the field `rows.N.unit_id` (invoices) or `unit`/`unit_id` (articles). Any text used to be stored.
- **How to adapt an integration:** read the units with `GET /units` and send `unit_id`, or a code or spelling from the list in `unit`. When a unit is missing, the company adds it under Artiklar → Enheter. A row without a unit gets the article's unit, or the company's default unit.
<!-- /lang -->

<!-- lang:sv -->
## 2026-11-09: external_ref på fakturor
<!-- /lang -->
<!-- lang:en -->
## 2026-11-09: external_ref on invoices
<!-- /lang -->

<!-- lang:sv -->
### Tillagt
- Kund- och leverantörsfakturor har det nya fältet `external_ref`: fakturanumret i det tidigare programmet. Det är satt bara på obetalda fakturor som lästs in från ett annat program. Sådana fakturor har inget NONOX-nummer, så `number` är `null` på dem, precis som på ett utkast. Webhookarna `invoice.*` och `supplier_invoice.*` har samma fält.
<!-- /lang -->
<!-- lang:en -->
### Added
- Customer and supplier invoices have a new field `external_ref`: the invoice number in the previous program. It is set only on unpaid invoices read in from another program. Such invoices have no NONOX number, so `number` is `null` on them, as on a draft. The `invoice.*` and `supplier_invoice.*` webhooks carry the same field.
<!-- /lang -->

<!-- lang:sv -->
## 2026-11-08: v1.10.0
<!-- /lang -->
<!-- lang:en -->
## 2026-11-08: v1.10.0
<!-- /lang -->

<!-- lang:sv -->
### Tillagt

- **Verifikationer**: `source` kan vara `K3_TRANSITION`, verifikationen som bokför övergången från K2 till K3 mot eget kapital den första dagen i det första året med K3.
<!-- /lang -->
<!-- lang:en -->
### Added

- **Vouchers**: `source` can be `K3_TRANSITION`, the voucher that books the transition from K2 to K3 against equity on the first day of the first K3 year.
<!-- /lang -->

<!-- lang:sv -->
## 2026-11-06: MCP-server (beta)
<!-- /lang -->
<!-- lang:en -->
## 2026-11-06: MCP server (beta)
<!-- /lang -->

<!-- lang:sv -->
**Indraget 2026-11-07: inte tillgänglig ännu.** MCP-servern öppnas först när integritetspolicyn har uppdaterats med AI-leverantörer som mottagare. Tills dess svarar `/api/mcp` 404. Posten nedan beskriver hur den kommer att fungera.
<!-- /lang -->
<!-- lang:en -->
**Withdrawn 2026-11-07: not available yet.** The MCP server opens only once the privacy policy has been updated to name AI providers as recipients. Until then `/api/mcp` answers 404. The entry below describes how it will work.
<!-- /lang -->

<!-- lang:sv -->
### Tillagt

- **MCP**: `POST /api/mcp` är en MCP-server för AI-assistenter, ovanpå samma nycklar och OAuth-token som API:t. Verktygen läser kontoplan, verifikationer, rapporter, kunder, fakturor och leverantörer och skapar utkast till verifikationer och kundfakturor; inget verktyg bokför, skickar, betalar eller tar bort. En nyckel med andra behörigheter än läsa och skapa utkast nekas med `403 mcp_scope_not_allowed`. Se [AI-assistenter (MCP)](/utvecklare/ai-assistenter). REST-API:t v1 är oförändrat.
<!-- /lang -->
<!-- lang:en -->
### Added

- **MCP**: `POST /api/mcp` is an MCP server for AI assistants, on the same keys and OAuth tokens as the API. Its tools read the chart of accounts, vouchers, reports, customers, invoices and suppliers and create draft vouchers and draft customer invoices; no tool posts, sends, pays or deletes. A key with scopes beyond reading and creating drafts is refused with `403 mcp_scope_not_allowed`. See [AI assistants (MCP)](/utvecklare/ai-assistenter). The REST API v1 is unchanged.
<!-- /lang -->

<!-- lang:sv -->
## 2026-11-06: v1.9.0
<!-- /lang -->
<!-- lang:en -->
## 2026-11-06: v1.9.0
<!-- /lang -->

<!-- lang:sv -->
### Tillagt

- **Leverantörsfakturor**: `vat_treatment` kan vara `NON_EU_GOODS`, varor från ett land utanför EU (import). Fakturan har ingen svensk moms och ingen moms räknas fram ur den; importmomsen bokförs från Tullverkets tullräkning i appen.
<!-- /lang -->
<!-- lang:en -->
### Added

- **Supplier invoices**: `vat_treatment` can be `NON_EU_GOODS`, goods from a country outside the EU (import). The invoice carries no Swedish VAT and none is calculated from it; the import VAT is booked from Swedish Customs' customs bill in the app.
<!-- /lang -->

<!-- lang:sv -->
## 2026-11-04: v1.8.0
<!-- /lang -->
<!-- lang:en -->
## 2026-11-04: v1.8.0
<!-- /lang -->

<!-- lang:sv -->
### Tillagt

- **Peppol**: fakturor har fältet `peppol` med den senaste sändningen som e-faktura via Peppol (status `QUEUED`, `SENT`, `DELIVERED` eller `FAILED`, och tidpunkterna), eller `null`. Mottagarens Peppol-ID visas inte.
- **Webhooks**: `invoice.peppol_sent` (Peppol-operatören har tagit emot e-fakturan) och `invoice.peppol_failed` (den kunde inte levereras); båda kräver `invoices:view`.
<!-- /lang -->
<!-- lang:en -->
### Added

- **Peppol**: invoices have the field `peppol` with the latest sending as a Peppol e-invoice (status `QUEUED`, `SENT`, `DELIVERED` or `FAILED`, and the times), or `null`. The receiver's Peppol ID is not shown.
- **Webhooks**: `invoice.peppol_sent` (the access point has accepted the e-invoice) and `invoice.peppol_failed` (it could not be delivered); both need `invoices:view`.
<!-- /lang -->

<!-- lang:sv -->
## 2026-11-04: v1.7.0
<!-- /lang -->
<!-- lang:en -->
## 2026-11-04: v1.7.0
<!-- /lang -->

<!-- lang:sv -->
### Tillagt

- **Signering**: `POST /quotes/{quote}/signing` mejlar en personlig länk för att signera offerten med namnteckning; när den är signerad blir offerten en order (och ett fakturautkast med `create_invoice`) och affären `deal_id` vinns. `GET /quotes/{quote}/signing` visar länkarnas status, dokumentets SHA-256 och händelserna. Själva länken returneras aldrig.
- **CRM, avtal**: `GET` och `POST /crm/agreements`, `GET` och `PATCH /crm/agreements/{agreement}` (`terminated_on` säger upp avtalet), och `GET`/`POST /crm/agreements/{agreement}/signing`.
- **Webhook**: `quote.signed` (en offert har signerats; webhooken kräver `invoices:view`).
<!-- /lang -->
<!-- lang:en -->
### Added

- **Signing**: `POST /quotes/{quote}/signing` e-mails a personal link to sign the quote with a signature pad; once signed, the quote becomes an order (and a draft invoice with `create_invoice`) and the deal `deal_id` is won. `GET /quotes/{quote}/signing` shows the links' status, the document's SHA-256 and the events. The link itself is never returned.
- **CRM, agreements**: `GET` and `POST /crm/agreements`, `GET` and `PATCH /crm/agreements/{agreement}` (`terminated_on` terminates the agreement), and `GET`/`POST /crm/agreements/{agreement}/signing`.
- **Webhook**: `quote.signed` (a quote was signed; the webhook needs `invoices:view`).
<!-- /lang -->

<!-- lang:sv -->
## 2026-11-03: v1.6.0
<!-- /lang -->
<!-- lang:en -->
## 2026-11-03: v1.6.0
<!-- /lang -->

<!-- lang:sv -->
### Tillagt

- **CRM, affärer**: `GET` och `POST /crm/deals`, `GET` och `PATCH /crm/deals/{deal}`. En ny affär kräver `next_step`, en planerad aktivitet som kopplas till affären. `PATCH` med `stage_id` flyttar affären, `"status": "WON"` vinner den (med `quote_id`, `order_id` eller `invoice_id` om du vill) och `"status": "LOST"` förlorar den med `loss_reason_id` och `win_back_at`.
- **CRM, säljtrattar**: `GET /crm/pipelines`, `GET /crm/pipelines/{pipeline}`, `GET /crm/stages` och `GET /crm/loss-reasons`, bara läsning.
- **Aktiviteter**: `deal_id` i svar, i `POST` och som filter. Affärens sista öppna steg klarmarkeras bara med `"done": true` och `next_step` i samma `PATCH`; annars svarar API:t 422.
<!-- /lang -->
<!-- lang:en -->
### Added

- **CRM, deals**: `GET` and `POST /crm/deals`, `GET` and `PATCH /crm/deals/{deal}`. A new deal needs `next_step`, a planned activity linked to the deal. `PATCH` with `stage_id` moves the deal, `"status": "WON"` wins it (optionally with `quote_id`, `order_id` or `invoice_id`) and `"status": "LOST"` loses it with `loss_reason_id` and `win_back_at`.
- **CRM, pipelines**: `GET /crm/pipelines`, `GET /crm/pipelines/{pipeline}`, `GET /crm/stages` and `GET /crm/loss-reasons`, read only.
- **Activities**: `deal_id` in responses, in `POST` and as a filter. A deal's last open step is completed only with `"done": true` and `next_step` in the same `PATCH`; otherwise the API answers 422.
<!-- /lang -->

<!-- lang:sv -->
## 2026-11-03: v1.5.0
<!-- /lang -->
<!-- lang:en -->
## 2026-11-03: v1.5.0
<!-- /lang -->

<!-- lang:sv -->
### Tillagt

- **CRM**: `GET` och `POST /crm/accounts`, `GET` och `PATCH /crm/accounts/{account}` (kunder och prospekt; `PATCH` med `status` sätter status för hand, `LOST` kräver `lost_reason`), `GET` och `POST /crm/people`, `GET` och `PATCH /crm/people/{person}` (kontaktpersoner med roll och rättslig grund) och `GET` och `POST /crm/activities`, `GET` och `PATCH /crm/activities/{activity}` (anteckningar, samtal, möten, e-post och uppgifter; `"done": true` klarmarkerar). Samma regler som i appen. En person har `informed_at` och `do_not_contact` (`OBJECTION` eller `RESTRICTION`); för en sådan person går det bara att skapa anteckningar.
- **Scopes**: `crm:view` och `crm:manage`. Ingen har dem när modulen CRM är avstängd.
<!-- /lang -->
<!-- lang:en -->
### Added

- **CRM**: `GET` and `POST /crm/accounts`, `GET` and `PATCH /crm/accounts/{account}` (prospects and customers; `PATCH` with `status` sets the status by hand, `LOST` needs `lost_reason`), `GET` and `POST /crm/people`, `GET` and `PATCH /crm/people/{person}` (contact people with role and legal basis) and `GET` and `POST /crm/activities`, `GET` and `PATCH /crm/activities/{activity}` (notes, calls, meetings, e-mails and tasks; `"done": true` completes one). The same rules as in the app. A person has `informed_at` and `do_not_contact` (`OBJECTION` or `RESTRICTION`); for such a person only notes can be created.
- **Scopes**: `crm:view` and `crm:manage`. No one has them while the CRM module is off.
<!-- /lang -->

<!-- lang:sv -->
## 2026-11-02: v1.4.0
<!-- /lang -->
<!-- lang:en -->
## 2026-11-02: v1.4.0
<!-- /lang -->

<!-- lang:sv -->
### Tillagt

- **Lager**: `GET /inventory/articles` (lagerförda artiklar med saldo totalt och per lagerställe; filter `gtin` och `number`), `GET /inventory/locations`, `GET /inventory/stock-levels` (filter `location_id` och `article_id`) och `POST /inventory/receipts` (skapa en inleverans och, med `"receive": true`, ta emot den direkt). Antal är text med högst tre decimaler; inköpspriser visas bara med `inventory:manage`.
- **Scopes**: `inventory:view` och `inventory:manage`. Ingen har dem när modulen Lager är avstängd.
- **Webhook**: `stock.changed` (lagersaldot har ändrats, med varje rörelse och saldot efteråt; webhooken kräver `inventory:view`).
<!-- /lang -->
<!-- lang:en -->
### Added

- **Inventory (Lager)**: `GET /inventory/articles` (stocked articles with the stock in total and per location; filters `gtin` and `number`), `GET /inventory/locations`, `GET /inventory/stock-levels` (filters `location_id` and `article_id`) and `POST /inventory/receipts` (create a goods receipt and, with `"receive": true`, receive it at once). Quantities are text with up to three decimals; purchase prices are shown only with `inventory:manage`.
- **Scopes**: `inventory:view` and `inventory:manage`. No one has them while the Lager module is off.
- **Webhook**: `stock.changed` (stock changed, with every movement and the balance after it; the webhook needs `inventory:view`).
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
- **SFTP för SIE-filer**: varje företag kan få en egen SFTP-mapp där ett annat program lämnar SIE-filer, med inloggning bara med SSH-nyckel. Filen granskas som en vanlig SIE-inläsning och väntar på att en person bekräftar. Se [SFTP för SIE-filer](/utvecklare/sftp).
- **Webhook**: `sie.uploaded` (en SIE-fil har kommit in via SFTP och granskats; webhooken kräver `import:sie`).
<!-- /lang -->
<!-- lang:en -->
### Added

- **Customer invoices in foreign currency**: `invoice_currency` and `exchange_rate_micros` (optional, default Riksbanken's rate) on `POST /invoices` and `PATCH /invoices/{id}`, and the row price `unit_price_minor` in hundredths of the currency. Responses carry the currency, the rate with its date and source and the foreign amounts (`foreign_*_minor`, rows `unit_price_minor` and `net_minor`). Every `*_ore` is still kronor.
- **Payment in currency**: `foreign_amount_minor` on `POST /invoices/{id}/payments`; `amount_ore` is the kronor received. The payment answers with `foreign_amount_minor` and `exchange_difference_ore`.
- **Customers**: `currency`.
- **Webhooks**: `invoice.sent` and `invoice.paid` carry `invoice_currency` and the foreign amounts; `invoice.paid` also the payment's amount and exchange difference.
- **SFTP for SIE files**: each company can get its own SFTP drop where another program delivers SIE files, logging in with an SSH key only. The file is checked like an ordinary SIE import and waits for a person to confirm it. See [SFTP for SIE files](/utvecklare/sftp?lang=en).
- **Webhook**: `sie.uploaded` (a SIE file arrived over SFTP and was checked; the webhook needs `import:sie`).
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
