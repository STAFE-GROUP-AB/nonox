# NONOX Connect – utvecklarguide

NONOX Connect är NONOX öppna API. Andra program – integrationsplattformar, kassasystem, egna skript – kan läsa och skriva bokföringen i ett företag, men bara det de har fått rätt till och bara i det företag de har anslutits till.

Den fullständiga beskrivningen av varje anrop, med exempel, finns i [`openapi.yaml`](openapi.yaml) (OpenAPI 3.1). Den filen är facit; den här guiden förklarar hur delarna hänger ihop. Beslut och säkerhetsmodell: [ADR-032](../adr/ADR-032-public-api-and-nonox-connect.md).

Den publika utvecklarwebben finns på [nonox.app/utvecklare](https://nonox.app/utvecklare) (och på `/utvecklare` i varje installation): guider med kodexempel i curl, PHP, JavaScript och Python, en referens som genereras från `openapi.yaml`, och [ändringarna](CHANGELOG.md). English: [nonox.app/utvecklare?lang=en](https://nonox.app/utvecklare?lang=en). Guiderna skrivs i [`guider/sv`](guider/sv) och [`guider/en`](guider/en); webbsidorna och filerna är samma text.

- API: `https://nonox.app/api/v1` (eller `https://<din-server>/api/v1` för den som driftar själv)
- Godkännande (OAuth): `https://nonox.app/anslut`
- Token: `https://nonox.app/oauth/token`
- Metadata: `https://nonox.app/.well-known/oauth-authorization-server`
- I appen: **Anslutningar** (`/anslutningar`) visar anslutna appar och API-nycklar

## 1. Slå på modulen

API:t är en modul och är avstängt från början. En ägare eller administratör slår på **NONOX Connect** under Inställningar → Moduler. Så länge modulen är avstängd svarar varje anrop för företaget `404`, som om API:t inte fanns. Stängs modulen av kopplas alla appar bort och alla API-nycklar återkallas.

Under modulens inställningar kan företaget välja att bara tillåta vissa appar och begränsa hela API:t till vissa IP-adresser eller nät.

## 2. Två sätt att ansluta

| | OAuth 2.0 (appar) | API-nyckel (skript) |
|---|---|---|
| För | program som många företag använder | ett företags egna skript och integrationer |
| Skapas | appen registreras av den som driftar NONOX; ett företag godkänner den | under Anslutningar, av ägare eller administratör |
| Bekräftas med | passkey vid godkännandet | passkey när nyckeln skapas |
| Giltighet | access token högst 15 minuter, förnyas med refresh token | till ett slutdatum som måste anges (högst ett år) |
| Skickas som | `Authorization: Bearer <access token>` | `Authorization: Bearer nnx_…` |

Båda är bundna till **exakt ett företag**.

## 3. Registrera en app

Appar registreras av den som driftar installationen, inte av företagen:

```bash
php artisan connect:app "Exempelappen" https://app.example/callback
php artisan connect:app "Exempelappen mobil" https://app.example/callback --public
```

Kommandot skriver ut `client_id` och, för en konfidentiell app, `client_secret` – en gång. Hemligheten sparas bara som hash. Omdirigeringsadresser måste vara `https` (`http` går bara för `localhost` och `127.0.0.1`) och jämförs exakt. `--public` är för mobil- och skrivbordsappar som inte kan hålla en hemlighet; PKCE skyddar dem.

## 4. OAuth-flödet steg för steg

NONOX stöder bara **authorization code med PKCE (S256)** och **refresh token**. Implicit, password, client credentials och device code finns inte.

**1. Skapa en code verifier och en challenge**

```bash
CODE_VERIFIER=$(openssl rand -base64 64 | tr -d '\n=+/' | cut -c1-64)
CODE_CHALLENGE=$(printf '%s' "$CODE_VERIFIER" | openssl dgst -sha256 -binary | openssl base64 | tr '+/' '-_' | tr -d '=')
```

**2. Skicka användarens webbläsare till godkännandet**

```
https://nonox.app/anslut?response_type=code
  &client_id=<client_id>
  &redirect_uri=https://app.example/callback
  &scope=vouchers:view vouchers:create vouchers:post
  &state=<slumpvärde>
  &code_challenge=<CODE_CHALLENGE>
  &code_challenge_method=S256
```

Personen loggar in om det behövs, ser på svenska vad appen ber om, väljer ett av sina företag där hen är ägare eller administratör och godkänner med sin passkey. Saknas `code_challenge` eller är metoden inte `S256` skickas personen tillbaka med `error=invalid_request`. Avböjer hen kommer `error=access_denied`. Godkännandet visas varje gång – också för en app som har godkänts tidigare.

**3. Byt koden mot tokens** (koden gäller i tio minuter och kan användas en gång)

```bash
curl -X POST https://nonox.app/oauth/token \
  -d grant_type=authorization_code \
  -d client_id=<client_id> \
  -d client_secret=<client_secret> \
  -d redirect_uri=https://app.example/callback \
  -d code=<code> \
  -d code_verifier="$CODE_VERIFIER"
```

```json
{ "token_type": "Bearer", "expires_in": 900, "access_token": "eyJ0eXAi…", "refresh_token": "def50200…" }
```

**4. Anropa API:t**

```bash
curl https://nonox.app/api/v1/company -H "Authorization: Bearer <access_token>"
```

**5. Förnya** innan access token går ut:

```bash
curl -X POST https://nonox.app/oauth/token \
  -d grant_type=refresh_token \
  -d client_id=<client_id> \
  -d client_secret=<client_secret> \
  -d refresh_token=<refresh_token>
```

Varje förnyelse ger en **ny** refresh token och gör den gamla ogiltig. Spara alltid den nya. Används en refresh token (eller en kod) en andra gång tolkar NONOX det som att någon annan har den: **hela anslutningen återkallas** och företaget ser det i behandlingshistoriken. Två parallella förnyelser med samma token räknas också som återanvändning – låt bara en tråd förnya åt gången.

Fel från `/oauth/token` följer OAuth (RFC 6749 §5.2): `{"error": "invalid_grant", "error_description": "…"}`.

## 5. API-nycklar

En ägare eller administratör skapar nycklar under **Anslutningar**: namn, behörigheter, slutdatum (obligatoriskt) och om man vill en lista över IP-adresser eller nät som nyckeln får användas från. Det kräver en färsk passkey-bekräftelse. Nyckeln (`nnx_…`) visas **en gång**; NONOX sparar bara ett SHA-256-avtryck. Ägaren får ett mejl om varje ny nyckel. Senast använd (tid och adress) syns i listan, och en nyckel kan återkallas direkt.

```bash
curl https://nonox.app/api/v1/vouchers?status=POSTED -H "Authorization: Bearer nnx_…"
```

## 6. Behörigheter (scopes)

Varje scope är samma behörighet som rollerna i NONOX använder.

| Scope | Ger |
|---|---|
| `vouchers:view` | Läsa verifikationer, underlag, kontoplan, räkenskapsår, verifikationsserier och momskoder |
| `vouchers:create` | Skapa och ändra utkast och lägga till underlag |
| `vouchers:post` | Bokföra verifikationer |
| `vouchers:correct` | Rätta bokförda verifikationer |
| `invoices:view` | Läsa kundregistret |
| `invoices:create` | Lägga till och ändra kunder |
| `supplier-invoices:create` | Läsa, lägga till och ändra leverantörer |
| `supplier-invoices:approve` | Läsa leverantörsregistret |

`GET /company` kräver ingen särskild behörighet.

**Vad ett anrop faktiskt får göra** är snittet av två saker, som prövas vid varje anrop: nyckelns eller appens scopes **och** vad personen som godkände appen eller skapade nyckeln får göra i företaget **just nu**. Får personen en annan roll minskar åtkomsten direkt; lämnar personen företaget slutar nyckeln eller appen fungera (en OAuth-anslutning återkallas då för gott). Ett arkiverat företag kan bara läsas.

## 7. Fel

Alla fel från `/api/v1` är `application/problem+json` (RFC 9457):

```json
{
  "type": "urn:nonox:problem:insufficient_scope",
  "title": "Forbidden",
  "status": 403,
  "code": "insufficient_scope",
  "detail": "Nyckeln eller appen saknar behörigheten vouchers:post, …",
  "instance": "urn:nonox:request:7f3c2a9e-…",
  "required_scopes": ["vouchers:post"]
}
```

Programmet ska styra på `code` (eller `type`), aldrig på `detail` – den är svensk text för människor och kan ändras. Valideringsfel (`422 validation_failed`) har `errors` per fält. `instance` är samma id som svarshuvudet `X-Request-Id`; uppge det vid supportfrågor. Alla koder finns i `openapi.yaml` under `components.responses`.

## 8. Listor och synk

Listor sidindelas med markör (cursor) och sorteras på `updated_at` och sedan id.

- `limit`: 1–200, standard 50
- `cursor`: värdet från `meta.next_cursor` på föregående sida
- `updated_since`: bara poster som ändrats efter en tid (ISO 8601)

```json
{ "data": [ … ], "meta": { "limit": 50, "next_cursor": "eyJ1cGRhdGVk…", "has_more": true } }
```

För synk: spara `updated_at` från den sista posten du har sett, skicka den som `updated_since` nästa gång och gå igenom alla sidor med `cursor` tills `has_more` är `false`.

Belopp anges i hela öre (heltal) i fält som slutar på `_ore`; 1 250,00 kr är `125000`. Datum är `ÅÅÅÅ-MM-DD`, tidpunkter ISO 8601 med tidszon.

## 9. Idempotens

Varje `POST` kräver huvudet `Idempotency-Key` – ett unikt värde per avsedd åtgärd, gärna ett UUID. Det gör att ett nytt försök efter ett nätverksfel aldrig bokför två gånger.

- Samma nyckel och samma anrop igen: samma svar som första gången, med `Idempotent-Replayed: true`. Inget görs om.
- Samma nyckel men ett annat anrop: `422 idempotency_key_reused`.
- Samma nyckel medan det första anropet pågår: `409 idempotency_in_progress` – vänta och försök igen.
- Ett serverfel (5xx) sparas inte, så samma nyckel kan användas igen.

Nycklarna sparas i 24 timmar per app eller API-nyckel.

```bash
curl -X POST https://nonox.app/api/v1/vouchers \
  -H "Authorization: Bearer nnx_…" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 3f6c1b7e-2d4a-4e8b-9c0d-5a1b2c3d4e5f" \
  -d '{"voucher_date":"2026-03-02","description":"Kontantförsäljning","series":"A","rows":[{"account":1930,"debit_ore":125000},{"account":3001,"credit_ore":100000},{"account":2611,"credit_ore":25000}]}'
```

En bokförd verifikation ändras aldrig: utkast skapas och ändras, `POST /vouchers/{id}/post` bokför (och ger nästa nummer i serien utan luckor), och `POST /vouchers/{id}/correct` rättar med en rättelseverifikation.

## 10. Gränser för antal anrop

| Gräns | Standard |
|---|---|
| Per app eller API-nyckel | 120 anrop per minut |
| Per företag (alla appar och nycklar tillsammans) | 600 anrop per minut |
| Per IP-adress, före inloggning | 600 anrop per minut |
| `/oauth/token` per IP-adress | 30 anrop per minut |

Varje svar har `RateLimit-Limit`, `RateLimit-Remaining` och `RateLimit-Reset`. Över gränsen kommer `429` med `Retry-After` (sekunder). Den som driftar själv kan ändra gränserna med `NONOX_CONNECT_RATE_LIMIT_PER_CREDENTIAL`, `NONOX_CONNECT_RATE_LIMIT_PER_COMPANY`, `NONOX_CONNECT_RATE_LIMIT_PER_IP` och `NONOX_CONNECT_RATE_LIMIT_TOKEN_ENDPOINT` (tokenadressen, 30 per minut).

## 11. Säkerhet i korthet

- Bara `Authorization`-huvudet räknas. API:t använder inga kakor och ingen session.
- CORS är avstängt: API:t är till för servrar och appar, inte för webbläsare på andra webbplatser.
- Ett annat företags id ger alltid `404`, aldrig `403` – API:t avslöjar inte vad som finns.
- Personnummer lämnas aldrig ut. En kund har `has_personal_number`; själva numret kan skrivas men aldrig läsas.
- Varje skrivning, varje godkännande, återkallelse och återanvänd token skrivs i företagets behandlingshistorik.
- Svar har `Cache-Control: no-store`.

## 12. För den som driftar själv

OAuth-token signeras med en RSA-nyckel. Skapa nycklarna en gång:

```bash
php artisan passport:keys
```

De hamnar i `storage/oauth-private.key` och `storage/oauth-public.key` (aldrig i git). Alternativt anges de i miljövariablerna `PASSPORT_PRIVATE_KEY` och `PASSPORT_PUBLIC_KEY`. Utan nycklar går modulen inte att slå på. Byts nycklarna blir alla utfärdade access tokens ogiltiga (apparna förnyar med sin refresh token).

`php artisan connect:prune` (körs varje natt av schemaläggaren) tar bort utgångna idempotensposter, koder och tokens.

## 13. Webhooks – kommer

Webhooks för `voucher.posted`, `invoice.sent`, `invoice.paid` och `supplier_invoice.approved` är beskrivna i `openapi.yaml` under `webhooks` (med exempel och signaturhuvudet `Nonox-Signature: t=<unix>,v1=<HMAC-SHA256>`), men skickas inte ännu.
