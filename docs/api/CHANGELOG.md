# NONOX Connect API – ändringar

Alla ändringar i `/api/v1` och OAuth-adresserna. Inom v1 görs bara tillägg (nya adresser, fält, scopes och enum-värden); något som bryter bakåtkompatibiliteten får `/api/v2`, och v1 finns kvar i minst tolv månader (ADR-032). Fullständig beskrivning: [openapi.yaml](openapi.yaml).

## 1.1.0 (2026-11-01)

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

## 1.0.0 (2026-11-01)

- Första versionen: OAuth 2.0 med PKCE, API-nycklar, företaget, kontoplan, momskoder, räkenskapsår, verifikationsserier, verifikationer med underlag, kunder och leverantörer.
