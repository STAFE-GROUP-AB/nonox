# NONOX Connect – utvecklarguide

NONOX Connect är NONOX öppna API. Andra program – integrationsplattformar, kassasystem, egna skript – kan läsa och skriva bokföringen i ett företag, men bara det de har fått rätt till och bara i det företag de har anslutits till.

Den fullständiga beskrivningen av varje anrop, med exempel, finns i [`openapi.yaml`](openapi.yaml) (OpenAPI 3.1). Den filen är facit; den här guiden förklarar hur delarna hänger ihop. Beslut och säkerhetsmodell: [ADR-032](../adr/ADR-032-public-api-and-nonox-connect.md).

Den publika utvecklarwebben finns på [nonox.app/utvecklare](https://nonox.app/utvecklare) (och på `/utvecklare` i varje installation): guider med kodexempel i curl, PHP, JavaScript och Python, en referens som genereras från `openapi.yaml`, och [ändringarna](CHANGELOG.md). English: [nonox.app/utvecklare?lang=en](https://nonox.app/utvecklare?lang=en). Guiderna skrivs i [`guider/sv`](guider/sv) och [`guider/en`](guider/en); webbsidorna och filerna är samma text.

- API: `https://nonox.app/api/v1` (eller `https://<din-server>/api/v1` för den som driftar själv)
- Godkännande (OAuth): `https://nonox.app/anslut`
- Token: `https://nonox.app/oauth/token`
- Återkalla token: `https://nonox.app/oauth/revoke`
- Metadata: `https://nonox.app/.well-known/oauth-authorization-server`
- I appen: **Anslutningar** (`/anslutningar`) visar anslutna appar, API-nycklar, webhooks, SFTP och testföretag
- Ändringar i API:t: [CHANGELOG.md](CHANGELOG.md)

## 1. Slå på modulen

API:t är en modul och är avstängt från början. En ägare eller administratör slår på **NONOX Connect** under Inställningar → Moduler. Så länge modulen är avstängd svarar varje anrop för företaget `404`, som om API:t inte fanns. Stängs modulen av kopplas alla appar bort, alla API-nycklar återkallas och alla webhooks stängs av.

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

**6. Koppla bort** (RFC 7009). När en användare kopplar bort er app, eller om en token kan ha läckt, återkallar appen själv:

```bash
curl -X POST https://nonox.app/oauth/revoke \
  -d client_id=<client_id> \
  -d client_secret=<client_secret> \
  -d token=<refresh_token> \
  -d token_type_hint=refresh_token
```

En egen access- eller refresh-token återkallar **hela anslutningen** (alla koder och tokens); appen måste be om godkännande igen. Svaret är alltid `200`, också för en okänd eller redan återkallad token eller en annan apps token – då händer ingenting. Fel klientautentisering ger `401 invalid_client`.

## 5. API-nycklar

En ägare eller administratör skapar nycklar under **Anslutningar**: namn, behörigheter, slutdatum (obligatoriskt) och om man vill en lista över IP-adresser eller nät som nyckeln får användas från. Det kräver en färsk passkey-bekräftelse. Nyckeln (`nnx_…`) visas **en gång**; NONOX sparar bara ett SHA-256-avtryck. Ägaren får ett mejl om varje ny nyckel. Senast använd (tid och adress) syns i listan, och en nyckel kan återkallas direkt.

```bash
curl https://nonox.app/api/v1/vouchers?status=POSTED -H "Authorization: Bearer nnx_…"
```

## 6. Behörigheter (scopes)

Varje scope är samma behörighet som rollerna i NONOX använder.

| Scope | Ger |
|---|---|
| `vouchers:view` | Läsa verifikationer, underlag, kontoplan, räkenskapsår och perioder, kostnadsställen, projekt, verifikationsserier och momskoder |
| `vouchers:create` | Skapa och ändra utkast och lägga till underlag |
| `vouchers:post` | Bokföra verifikationer |
| `vouchers:correct` | Rätta bokförda verifikationer |
| `accounts:manage` | Lägga till och ändra kostnadsställen och projekt |
| `reports:view` | Saldobalans, huvudbok, balansrapport och resultatrapport |
| `vat:prepare` | Läsa momsdeklarationen per period |
| `export:sie` | Hämta ett räkenskapsår som SIE-fil |
| `invoices:view` | Läsa kunder, artiklar, kundfakturor (med PDF) och inbetalningar |
| `invoices:create` | Lägga till och ändra kunder, artiklar och fakturautkast |
| `invoices:send` | Skapa fakturor av utkast och mejla dem |
| `invoices:credit` | Kreditera obetalda fakturor |
| `payments:create` | Registrera inbetalningar och betalningar av leverantörsfakturor |
| `supplier-invoices:create` | Läsa och lägga till leverantörer och leverantörsfakturor, registrera fakturor |
| `supplier-invoices:approve` | Läsa och attestera leverantörsfakturor |
| `planning:view` | Läsa schemat i Planering: pass per period, avdelningar och publiceringar |

`GET /company` kräver ingen särskild behörighet.

**Anrop som bokför.** Att skapa en kundfaktura (`/invoices/{id}/issue`), registrera en leverantörsfaktura (`/supplier-invoices/{id}/register`) och registrera en betalning (`/invoices/{id}/payments`, `/supplier-invoices/{id}/payments`) bokför en verifikation i personens namn. De kräver därför också `vouchers:create` och `vouchers:post`. I `openapi.yaml` står det som `x-required-scopes` (en lista av uppsättningar där alla scopes i en uppsättning behövs), och ett `403 insufficient_scope` har `required_scope_sets` på samma form.

**Attest av någon annan.** Kräver företaget att en annan person attesterar (standard), kan den som registrerade en leverantörsfaktura inte attestera den – oavsett vilken app eller nyckel som används. Svaret är då `422`.

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

`php artisan connect:webhooks` körs varje minut av schemaläggaren och köar webhook-leveranser som ska försökas igen. Leveranserna skickas i kön (`QUEUE_CONNECTION`); med `sync` skickas de direkt i anropet som orsakade dem, så kör en köarbetare i drift.

## 13. Resurser

| Område | Adresser |
|---|---|
| Företaget | `GET /company` |
| Bokföring | `/accounts`, `/vat-codes`, `/fiscal-years`, `/fiscal-years/{id}/periods` (låsta månader), `/fiscal-years/{id}/sie` (SIE 4, CP437), `/voucher-series` |
| Kostnadsställen och projekt | `/cost-centers`, `/projects` (läsa, lägga till, byta namn, inaktivera) |
| Verifikationer | `/vouchers` (utkast, bokföra, rätta, underlag) |
| Kunder och artiklar | `/customers`, `/articles` |
| Kundfakturor | `/invoices` (utkast), `/issue` (nummer, OCR, bokförs i B), `/send` (mejl), `/credit` (utkast till kreditfaktura), `/payments` (inbetalning i C), `/pdf` |
| Leverantörsfakturor | `/suppliers`, `/supplier-invoices` (utkast), `/file` (fakturan som PDF eller bild), `/register` (bokförs i D), `/approve` (attest), `/payments` (betalning i E) |
| Betalningar | `GET /payments` (inbetalningar), `GET /supplier-payments` (utbetalningar) |
| Rapporter | `/reports/trial-balance`, `/reports/general-ledger`, `/reports/balance-sheet`, `/reports/income-statement` med `fiscal_year_id`, `from`, `to`, `cost_center`, `project` |
| Moms | `/vat-periods?fiscal_year_id=…`, `/vat-return?fiscal_year_id=…&period_start=…` |
| Planering | `/planning/shifts?from=…&to=…` (publicerat schema, högst 93 dagar; `include_drafts=true` för planen som den är nu), `/planning/areas`, `/planning/publications`. Kräver modulen Planering. Kostnadsuppskattningar ingår inte. |

Belopp in och ut är hela öre. Momssatser och rabatter anges i hundradels procent: `2500` är 25 %, `1000` är 10 %. Antal skickas som heltal eller som text med högst tre decimaler (`"1.5"`); decimaltal i JSON avvisas, eftersom de inte är exakta. Momsdeklarationens rutor är hela kronor (`amount_sek`), precis som på blanketten.

Ännu inte i API:t: offerter och order, avtalsfakturering, påminnelser, SIE-import (den tvåstegsimport som finns i appen) och att bokföra momsen eller låsa perioder.

## 14. Webhooks

En webhook berättar för ert program när något händer, så att ni slipper fråga hela tiden. En ägare eller administratör lägger till den under **Anslutningar** med passkey: en `https`-adress, vilka händelser och – en gång – en hemlighet (`nnxwh_…`). Ägaren får ett mejl om varje ny webhook.

| Händelse | När |
|---|---|
| `voucher.posted` | En verifikation har bokförts (också när en faktura skapas eller en betalning registreras) |
| `invoice.sent` | En kundfaktura har mejlats till kunden |
| `invoice.paid` | En kundfaktura är helt betald |
| `supplier_invoice.approved` | En leverantörsfaktura har attesterats |
| `planning.published` | En period av schemat har publicerats (Planering) |
| `planning.attested` | Tid i schemat har attesterats (Planering; webhooken kräver `planning:attest`) |
| `sie.uploaded` | En SIE-fil har kommit in via SFTP och granskats (webhooken kräver `import:sie`; se SFTP nedan) |

Händelser skickas först när ändringen är sparad (efter commit), aldrig för något som sedan ångrades. De är tunna: id, nummer, datum och belopp, inga personuppgifter. Behöver ni mer hämtar ni posten med API:t.

```http
POST /er/webhook HTTP/1.1
Content-Type: application/json
User-Agent: NONOX-Webhooks/1.0 (+https://github.com/STAFE-GROUP-AB/nonox)
Nonox-Event-Id: c2d4e6f8-1a3b-4c5d-8e7f-9a0b1c2d3e4f
Nonox-Event-Type: invoice.paid
Nonox-Signature: t=1772444713,v1=5f8e2a1c…

{"id":"c2d4e6f8-…","type":"invoice.paid","created_at":"2026-03-28T09:12:01+01:00","company_id":42,"data":{"invoice_id":"5c2e8a41-…","invoice_number":1042,"customer_id":18,"total_ore":237500,"paid_ore":237500,"currency":"SEK","payment_id":88,"paid_on":"2026-03-28"}}
```

**Kontrollera varje leverans** innan ni litar på den:

1. Läs `t` och `v1` ur `Nonox-Signature`.
2. Räkna HMAC-SHA256 med hemligheten över `"<t>.<rå kropp>"` – exakt de bytes ni fick, innan JSON tolkas – och jämför med `v1` i konstant tid.
3. Avvisa om `t` skiljer mer än fem minuter från er klocka (skydd mot uppspelning).
4. Spara `Nonox-Event-Id` och hoppa över en händelse ni redan har tagit emot: samma händelse kan komma två gånger om ert svar försvann på vägen.

```php
[$t, $v1] = [null, null];
foreach (explode(',', $_SERVER['HTTP_NONOX_SIGNATURE']) as $part) {
    [$k, $v] = explode('=', $part, 2) + [1 => ''];
    if ($k === 't') { $t = (int) $v; }
    if ($k === 'v1') { $v1 = $v; }
}
$body = file_get_contents('php://input');
$ok = $t !== null && abs(time() - $t) <= 300
    && hash_equals(hash_hmac('sha256', "{$t}.{$body}", $secret), (string) $v1);
```

**Svara 2xx inom tio sekunder** och gör det tunga arbetet efteråt. Allt annat – ett annat statusvärde, inget svar, en omdirigering – räknas som misslyckat och försöks igen efter 1, 5 och 15 minuter och 1, 3, 6 och 13 timmar: åtta försök under ungefär ett dygn. Misslyckas även det sista stängs webhooken av, väntande händelser ställs in och ägaren får ett mejl. Den slås på igen under Anslutningar; det som hände medan den var avstängd hämtas med `updated_since`.

**Säkerhet.** Adressen måste vara `https` (port 443 eller 1024–65535) och peka ut på internet. NONOX slår upp namnet före varje försök och vägrar adresser i privata nät, loopback, link-local (till exempel molnens metadata-adress 169.254.169.254), CGNAT, multicast och motsvarande i IPv6. Anslutningen görs till exakt den adress som kontrollerades, omdirigeringar följs aldrig, svaret läses som mest 64 kB och sparas inte – leveransloggen under Anslutningar visar bara tid, händelse, antal försök och statuskod. Hemligheten byts med **Ny hemlighet** (den gamla slutar gälla direkt).

## 15. Testföretag

För att prova API:t utan att röra riktig bokföring finns testföretag: egna företag med påhittade kunder, leverantörer, artiklar, kostnadsställen, verifikationer, en skapad och delbetald faktura, ett fakturautkast och en leverantörsfaktura som väntar på attest – allt gjort med samma funktioner som appen. NONOX Connect är påslaget.

- Den som driftar: `php artisan connect:test-company utvecklare@exempel.se --name="Prova API AB"` (användaren måste finnas).
- En ägare eller administratör: **Anslutningar → Testföretag för API:t**. Högst tre per person.

Ett testföretag har en gul banderoll på varje sida, ett organisationsnummer som börjar med `00` (en grupp som inget riktigt företag har), mejlar aldrig fakturor, påminnelser eller offerter till någon och räknas inte med i statistik eller debitering. Det är ett vanligt företag i övrigt, helt skilt från andra företags data. Skapa en API-nyckel i testföretaget och börja anropa.

## 16. SFTP för SIE-filer

Program som hellre lämnar filer än anropar ett API, till exempel kassasystem och redovisningsbyråers verktyg, kan lämna SIE-filer i företagets egen SFTP-mapp. Servern är [SFTPGo](https://github.com/drakkan/sftpgo) (AGPL-3.0), som frågar NONOX vid varje inloggning och varje uppladdning. Hur den sätts upp står i `deploy/sftpgo/README.md`; hur ett program använder den står i guiden [SFTP för SIE-filer](/utvecklare/sftp).

- En ägare eller administratör som själv får läsa in SIE-filer slår på SFTP under **Anslutningar** och blir ansvarig för filerna som kommer in. Användarnamnet skapas slumpvis (`nnx` och 13 tecken) och säger ingenting om företaget.
- Inloggning bara med SSH-nyckel: ed25519, eller RSA med minst 3072 bitar. Nycklar läggs till med passkey; lösenord, FTP, WebDAV och SFTPGo:s webbklient är avstängda. En lista med tillåtna adresser (CIDR) kan sättas, också den med passkey.
- `/in` tar emot `.se`, `.si` och `.sie`, högst 50 MB per fil och högst 30 SIE-filer i timmen (tillsammans med uppladdningar i webbläsaren). Filen kan inte skrivas över, döpas om eller tas bort.
- Varje fil granskas som när den laddas upp i webbläsaren och blir en SIE-inläsning som väntar på att en person läser rapporten och bekräftar. **Ingenting bokförs automatiskt.** Filen tas sedan bort ur mappen; den krypterade kopian följer SIE-inläsningen.
- `/ut`: en ägare eller administratör kan lägga en SIE4-fil för innevarande räkenskapsår där. Den tas bort efter sju dagar.
- Webhooken `sie.uploaded` berättar att en fil har granskats.
