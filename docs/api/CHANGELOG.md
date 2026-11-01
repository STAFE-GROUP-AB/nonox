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
