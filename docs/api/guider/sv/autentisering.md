# Autentisering med OAuth

Ett program som många företag använder (en integrationsplattform, ett kassasystem, en app) ansluts med **OAuth 2.0**. Företaget godkänner appen själv, ser exakt vad den ber om, och kan koppla bort den när som helst. Appen får aldrig någons lösenord eller inloggning.

NONOX stöder bara två flöden: **auktoriseringskod med PKCE (S256)** och **refresh token**. Implicit, password, client credentials och device code finns inte, och tokenadressen svarar `unsupported_grant_type` på allt annat. Behöver du bara ett skript för ditt eget företag är en [API-nyckel](api-nycklar.md) enklare.

## Adresserna

<!-- nonox:oauth-endpoints -->

Metadata enligt RFC 8414 finns också på `/.well-known/oauth-authorization-server`, så att OAuth-bibliotek kan hitta adresserna själva.

## Registrera appen

Appar registreras av den som driftar installationen, inte av företagen. På nonox.app: hör av dig till oss. På en egen installation:

```bash
php artisan connect:app "Exempelappen" https://app.example/callback
php artisan connect:app "Exempelappen mobil" https://app.example/callback --public
```

Kommandot skriver ut `client_id` och, för en konfidentiell app, `client_secret`, **en gång**. Hemligheten sparas bara som hash. Omdirigeringsadresser måste vara `https` (`http` går bara för `localhost` och `127.0.0.1`) och jämförs exakt, tecken för tecken. `--public` är för mobil- och skrivbordsappar som inte kan hålla en hemlighet; där är det PKCE som skyddar koden.

## Steg 1: skapa en code verifier och en challenge

PKCE binder koden till den som bad om den. Appen skapar ett slumpvärde (`code_verifier`, 43–128 tecken) för varje inloggning, och skickar bara dess SHA-256 (`code_challenge`) i webbläsaren. NONOX kräver metoden `S256` för alla appar, också konfidentiella.

<!-- tabs -->
```bash
CODE_VERIFIER=$(openssl rand -base64 64 | tr -d '\n=+/' | cut -c1-64)
CODE_CHALLENGE=$(printf '%s' "$CODE_VERIFIER" | openssl dgst -sha256 -binary | openssl base64 | tr '+/' '-_' | tr -d '=')
STATE=$(openssl rand -hex 16)
```

```php
$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$state = bin2hex(random_bytes(16));

// Spara $verifier och $state i användarens session till steg 3.
```

```js
import crypto from 'node:crypto';

const verifier = crypto.randomBytes(48).toString('base64url');
const challenge = crypto.createHash('sha256').update(verifier).digest('base64url');
const state = crypto.randomBytes(16).toString('hex');
```

```python
import base64, hashlib, secrets

verifier = secrets.token_urlsafe(48)
challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b"=").decode()
state = secrets.token_hex(16)
```
<!-- /tabs -->

## Steg 2: skicka personen till godkännandet

Skicka webbläsaren till `/anslut` med de här parametrarna (radbrutet här för läsbarhetens skull):

```http
GET https://nonox.app/anslut?response_type=code
    &client_id=9d3f6a2e-1b4c-4e8f-a7d2-5c6b8e9f0a1b
    &redirect_uri=https%3A%2F%2Fapp.example%2Fcallback
    &scope=vouchers%3Aview%20vouchers%3Acreate%20vouchers%3Apost
    &state=8c1f4b0e2d9a7c6b5e3f1a0d9c8b7a6e
    &code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM
    &code_challenge_method=S256
```

| Parameter | Värde |
|---|---|
| `response_type` | Alltid `code`. |
| `client_id` | Appens id. |
| `redirect_uri` | En av appens registrerade adresser, exakt. |
| `scope` | Behörigheterna, åtskilda med mellanslag. Se [Behörigheter](behorigheter.md). |
| `state` | Ett slumpvärde som kommer tillbaka oförändrat. Kontrollera det mot CSRF. |
| `code_challenge` | BASE64URL(SHA-256(code_verifier)). |
| `code_challenge_method` | Alltid `S256`. |

Saknas `code_challenge`, eller är metoden inte `S256`, skickas personen tillbaka med `error=invalid_request`. Är `client_id` eller `redirect_uri` fel visas felet på sidan och personen skickas **inte** tillbaka, så att en falsk adress aldrig får ett svar.

## Godkännandet och passkey

Personen loggar in om det behövs och ser på svenska vilken app det är och vad den ber om. Hen väljer ett av sina företag och godkänner. Det går bara när:

- personen är **ägare eller administratör** i företaget;
- NONOX Connect är påslaget för företaget och företaget inte är arkiverat;
- företaget tillåter appen (om det har valt att bara tillåta vissa appar);
- personen **bekräftar med sin passkey** i samma stund. En inloggad session räcker inte, så den som bara har kommit åt en mejlkorg kan inte släppa in en app.

Appen får bara de behörigheter personen själv har. Godkännandet visas **varje gång**, också för en app som godkänts tidigare; NONOX godkänner aldrig tyst.

Godkänner personen skickas webbläsaren tillbaka med en kod:

```http
HTTP/1.1 302 Found
Location: https://app.example/callback?code=def50200a1b2c3…&state=8c1f4b0e2d9a7c6b5e3f1a0d9c8b7a6e
```

Avböjer hen kommer `error=access_denied` i stället:

```http
HTTP/1.1 302 Found
Location: https://app.example/callback?error=access_denied&state=8c1f4b0e2d9a7c6b5e3f1a0d9c8b7a6e
```

Kontrollera att `state` är samma som du skickade, annars: släng svaret.

## Steg 3: byt koden mot tokens

Koden gäller i tio minuter och kan användas **en gång**. Tokenadressen tar `application/x-www-form-urlencoded`, och en konfidentiell app autentiserar sig med `client_secret` i formuläret eller med HTTP Basic. En publik app skickar bara `client_id`.

```http
POST /oauth/token HTTP/1.1
Host: nonox.app
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code&client_id=9d3f6a2e-1b4c-4e8f-a7d2-5c6b8e9f0a1b&client_secret=…&redirect_uri=https%3A%2F%2Fapp.example%2Fcallback&code=def50200a1b2c3…&code_verifier=dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk
```

<!-- tabs -->
```bash
curl -X POST https://nonox.app/oauth/token \
  -d grant_type=authorization_code \
  -d client_id="$CLIENT_ID" \
  -d client_secret="$CLIENT_SECRET" \
  -d redirect_uri=https://app.example/callback \
  -d code="$CODE" \
  -d code_verifier="$CODE_VERIFIER"
```

```php
use Illuminate\Support\Facades\Http;

$tokens = Http::asForm()->post('https://nonox.app/oauth/token', [
    'grant_type' => 'authorization_code',
    'client_id' => config('services.nonox.client_id'),
    'client_secret' => config('services.nonox.client_secret'),
    'redirect_uri' => 'https://app.example/callback',
    'code' => $request->query('code'),
    'code_verifier' => $request->session()->pull('nonox_verifier'),
])->throw()->json();
```

```js
const response = await fetch('https://nonox.app/oauth/token', {
  method: 'POST',
  body: new URLSearchParams({
    grant_type: 'authorization_code',
    client_id: process.env.NONOX_CLIENT_ID,
    client_secret: process.env.NONOX_CLIENT_SECRET,
    redirect_uri: 'https://app.example/callback',
    code,
    code_verifier: verifier,
  }),
});
const tokens = await response.json();
```

```python
import os, requests

tokens = requests.post(
    "https://nonox.app/oauth/token",
    data={
        "grant_type": "authorization_code",
        "client_id": os.environ["NONOX_CLIENT_ID"],
        "client_secret": os.environ["NONOX_CLIENT_SECRET"],
        "redirect_uri": "https://app.example/callback",
        "code": code,
        "code_verifier": verifier,
    },
    timeout=30,
).json()
```
<!-- /tabs -->

Svaret:

```http
HTTP/1.1 200 OK
Content-Type: application/json; charset=UTF-8
Cache-Control: no-store
Pragma: no-cache

{
  "token_type": "Bearer",
  "expires_in": 900,
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9…",
  "refresh_token": "def502004f7c1e…"
}
```

Spara båda på servern, krypterade. Access token är en signerad JWT, men behandla den som en ogenomskinlig sträng: dess innehåll är inget API och kan ändras.

## Steg 4: anropa API:t

```bash
curl https://nonox.app/api/v1/company -H "Authorization: Bearer $ACCESS_TOKEN"
```

Bara `Authorization`-huvudet räknas. API:t läser aldrig kakor eller sessioner. Svaret på `GET /company` visar vilket företag anslutningen gäller och vilka behörigheter den har.

## Livslängd och förnyelse

| | Livslängd | När den tar slut |
|---|---|---|
| Kod | 10 minuter, en gång | Börja om från steg 2 |
| Access token | högst 15 minuter (`expires_in`) | Förnya med refresh token |
| Refresh token | 30 dagar, byts vid varje förnyelse | Personen måste godkänna igen |

Värdena på den här installationen:

<!-- nonox:limits -->

Förnya strax innan access token går ut, eller när ett anrop ger `401`:

<!-- tabs -->
```bash
curl -X POST https://nonox.app/oauth/token \
  -d grant_type=refresh_token \
  -d client_id="$CLIENT_ID" \
  -d client_secret="$CLIENT_SECRET" \
  -d refresh_token="$REFRESH_TOKEN"
```

```php
$tokens = Http::asForm()->post('https://nonox.app/oauth/token', [
    'grant_type' => 'refresh_token',
    'client_id' => config('services.nonox.client_id'),
    'client_secret' => config('services.nonox.client_secret'),
    'refresh_token' => $connection->refresh_token,
])->throw()->json();

// Spara den NYA refresh token innan något annat görs.
$connection->update(['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token']]);
```

```js
const tokens = await (await fetch('https://nonox.app/oauth/token', {
  method: 'POST',
  body: new URLSearchParams({
    grant_type: 'refresh_token',
    client_id: process.env.NONOX_CLIENT_ID,
    client_secret: process.env.NONOX_CLIENT_SECRET,
    refresh_token: connection.refreshToken,
  }),
})).json();
```

```python
tokens = requests.post(
    "https://nonox.app/oauth/token",
    data={
        "grant_type": "refresh_token",
        "client_id": os.environ["NONOX_CLIENT_ID"],
        "client_secret": os.environ["NONOX_CLIENT_SECRET"],
        "refresh_token": connection.refresh_token,
    },
    timeout=30,
).json()
```
<!-- /tabs -->

Varje förnyelse ger en **ny** refresh token och gör den gamla ogiltig (rotation). Spara alltid den nya innan du gör något annat. Du kan be om färre behörigheter vid en förnyelse genom att skicka `scope`, men aldrig fler.

## Återanvändning återkallar allt

Används en refresh token, eller en kod, en **andra gång** tolkar NONOX det som att någon annan har kommit över den. Då återkallas **hela anslutningen**: alla tokens slutar gälla direkt, händelsen skrivs i företagets behandlingshistorik och personen måste godkänna appen igen.

Två förnyelser med samma refresh token samtidigt räknas också som återanvändning, eftersom NONOX låser raden och den andra ser en redan använd token. Låt därför bara **en** tråd eller process förnya åt gången per anslutning, till exempel med ett lås i din databas.

## Återkallelse

Företaget kopplar bort en app under **Anslutningar**, och då slutar alla dess tokens gälla vid nästa anrop, inte först när de går ut. Det sker också när personen som godkände appen lämnar företaget eller när modulen stängs av. Appen märker det som `401` från API:t och `invalid_grant` vid nästa förnyelse. Det finns ingen adress för återkallelse (RFC 7009) eller introspektion (RFC 7662) ännu.

## Fel från tokenadressen

Tokenadressen svarar som OAuth föreskriver (RFC 6749 §5.2), inte med problem+json, eftersom det är vad OAuth-bibliotek förväntar sig:

```json
{
  "error": "invalid_grant",
  "error_description": "The provided authorization grant (e.g., authorization code, resource owner credentials) or refresh token is invalid, expired, revoked, does not match the redirection URI used in the authorization request, or was issued to another client."
}
```

| `error` | Betyder |
|---|---|
| `invalid_request` | En parameter saknas eller är fel, till exempel `code_verifier`. |
| `invalid_client` | Fel `client_id` eller `client_secret`. |
| `invalid_grant` | Koden eller refresh token är ogiltig, har gått ut, har använts eller tillhör en annan app. Börja om från steg 2. |
| `unsupported_grant_type` | Bara `authorization_code` och `refresh_token` finns. |
| `invalid_scope` | En behörighet som inte finns, eller fler än appen fick. |

Tokenadressen tar högst 30 anrop per minut och IP-adress.
