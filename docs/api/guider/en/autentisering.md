# Authentication with OAuth

A program used by many companies (an integration platform, a point-of-sale system, an app) connects with **OAuth 2.0**. The company approves the app itself, sees exactly what it asks for, and can disconnect it at any time. The app never gets anyone's password or sign-in.

NONOX supports two flows only: **authorization code with PKCE (S256)** and **refresh token**. Implicit, password, client credentials and device code do not exist, and the token endpoint answers `unsupported_grant_type` to anything else. If you only need a script for your own company, an [API key](api-nycklar.md) is simpler.

## The endpoints

<!-- nonox:oauth-endpoints -->

RFC 8414 metadata is also at `/.well-known/oauth-authorization-server`, so OAuth libraries can discover the endpoints themselves.

## Register the app

Apps are registered by whoever runs the installation, not by the companies. On nonox.app: contact us. On your own installation:

```bash
php artisan connect:app "Example app" https://app.example/callback
php artisan connect:app "Example app mobile" https://app.example/callback --public
```

The command prints the `client_id` and, for a confidential app, the `client_secret`, **once**. The secret is stored only as a hash. Redirect URIs must be `https` (`http` only for `localhost` and `127.0.0.1`) and are compared exactly, character by character. `--public` is for mobile and desktop apps that cannot keep a secret; PKCE protects their code.

## Step 1: create a code verifier and a challenge

PKCE binds the code to whoever asked for it. The app creates a random value (`code_verifier`, 43–128 characters) for each sign-in and sends only its SHA-256 (`code_challenge`) through the browser. NONOX requires the `S256` method for every app, confidential ones too.

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

// Keep $verifier and $state in the user's session until step 3.
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

## Step 2: send the person to the consent screen

Send the browser to `/anslut` with these parameters (wrapped here for readability):

```http
GET https://nonox.app/anslut?response_type=code
    &client_id=9d3f6a2e-1b4c-4e8f-a7d2-5c6b8e9f0a1b
    &redirect_uri=https%3A%2F%2Fapp.example%2Fcallback
    &scope=vouchers%3Aview%20vouchers%3Acreate%20vouchers%3Apost
    &state=8c1f4b0e2d9a7c6b5e3f1a0d9c8b7a6e
    &code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM
    &code_challenge_method=S256
```

| Parameter | Value |
|---|---|
| `response_type` | Always `code`. |
| `client_id` | The app's id. |
| `redirect_uri` | One of the app's registered URIs, exactly. |
| `scope` | The scopes, separated by spaces. See [Scopes](behorigheter.md). |
| `state` | A random value that comes back unchanged. Check it against CSRF. |
| `code_challenge` | BASE64URL(SHA-256(code_verifier)). |
| `code_challenge_method` | Always `S256`. |

Without `code_challenge`, or with a method other than `S256`, the person is sent back with `error=invalid_request`. A wrong `client_id` or `redirect_uri` is shown on the page and the person is **not** redirected, so a forged address never gets an answer.

## Consent and passkey

The person signs in if needed and sees, in Swedish, which app it is and what it asks for. They choose one of their companies and approve. That works only when:

- the person is **owner or administrator** of the company;
- NONOX API is on for the company and the company is not archived;
- the company allows the app (if it has chosen to allow only some apps);
- the person **confirms with their passkey** right then. A signed-in session is not enough, so someone who has only taken over a mailbox cannot let an app in.

The app gets only the scopes the person holds. The consent screen is shown **every time**, also for an app approved before; NONOX never approves silently.

When the person approves, the browser comes back with a code:

```http
HTTP/1.1 302 Found
Location: https://app.example/callback?code=def50200a1b2c3…&state=8c1f4b0e2d9a7c6b5e3f1a0d9c8b7a6e
```

When they decline, `error=access_denied` comes instead:

```http
HTTP/1.1 302 Found
Location: https://app.example/callback?error=access_denied&state=8c1f4b0e2d9a7c6b5e3f1a0d9c8b7a6e
```

Check that `state` is the one you sent; if not, discard the answer.

## Step 3: exchange the code for tokens

The code is valid for ten minutes and can be used **once**. The token endpoint takes `application/x-www-form-urlencoded`, and a confidential app authenticates with `client_secret` in the form or with HTTP Basic. A public app sends only `client_id`.

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

The answer:

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

Store both on your server, encrypted. The access token is a signed JWT, but treat it as an opaque string: its contents are not an API and may change.

## Step 4: call the API

```bash
curl https://nonox.app/api/v1/company -H "Authorization: Bearer $ACCESS_TOKEN"
```

Only the `Authorization` header counts. The API never reads cookies or sessions. The answer to `GET /company` shows which company the connection belongs to and which scopes it has.

## Lifetimes and refreshing

| | Lifetime | When it ends |
|---|---|---|
| Code | 10 minutes, once | Start again at step 2 |
| Access token | at most 15 minutes (`expires_in`) | Refresh with the refresh token |
| Refresh token | 30 days, replaced on every refresh | The person has to approve again |

The values on this installation:

<!-- nonox:limits -->

Refresh shortly before the access token expires, or when a call answers `401`:

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

// Store the NEW refresh token before doing anything else.
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

Every refresh returns a **new** refresh token and invalidates the old one (rotation). Always store the new one before anything else. You may ask for fewer scopes on a refresh by sending `scope`, never for more.

## Reuse revokes everything

When a refresh token, or a code, is used a **second time**, NONOX takes it to mean someone else has it. The **whole connection** is then revoked: every token stops working at once, the event is written to the company's audit trail, and the person has to approve the app again.

Two refreshes with the same refresh token at the same time also count as reuse, because NONOX locks the row and the second one sees a token already used. Let only **one** thread or process refresh a connection at a time, for example with a lock in your database.

## Revocation

A company disconnects an app under **Anslutningar**, and all its tokens stop working on the next call, not only when they expire. The same happens when the person who approved the app leaves the company or when the module is turned off. The app sees `401` from the API and `invalid_grant` on its next refresh. There is no revocation (RFC 7009) or introspection (RFC 7662) endpoint yet.

## Errors from the token endpoint

The token endpoint answers as OAuth prescribes (RFC 6749 §5.2), not with problem+json, because that is what OAuth libraries expect:

```json
{
  "error": "invalid_grant",
  "error_description": "The provided authorization grant (e.g., authorization code, resource owner credentials) or refresh token is invalid, expired, revoked, does not match the redirection URI used in the authorization request, or was issued to another client."
}
```

| `error` | Means |
|---|---|
| `invalid_request` | A parameter is missing or wrong, for example `code_verifier`. |
| `invalid_client` | Wrong `client_id` or `client_secret`. |
| `invalid_grant` | The code or refresh token is invalid, expired, used or belongs to another app. Start again at step 2. |
| `unsupported_grant_type` | Only `authorization_code` and `refresh_token` exist. |
| `invalid_scope` | A scope that does not exist, or more than the app was given. |

The token endpoint takes at most 30 calls per minute per IP address.
