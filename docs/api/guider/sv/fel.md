# Fel

Alla fel från `/api/v1` kommer som `application/problem+json` enligt RFC 9457. Formen är alltid densamma, så ett program kan hantera alla fel på ett ställe.

```http
HTTP/1.1 422 Unprocessable Content
Content-Type: application/problem+json
X-Request-Id: 7f3c2a9e-4b1d-4c8e-9a51-2d6f0e8b3c17

{
  "type": "urn:nonox:problem:validation_failed",
  "title": "Unprocessable Content",
  "status": 422,
  "code": "validation_failed",
  "detail": "Uppgifterna kunde inte godtas. Se errors.",
  "instance": "urn:nonox:request:7f3c2a9e-4b1d-4c8e-9a51-2d6f0e8b3c17",
  "errors": {
    "rows": ["Verifikationen balanserar inte: debet och kredit skiljer sig åt."],
    "voucher_date": ["Det finns inget räkenskapsår som omfattar 2031-01-01."]
  }
}
```

## Fälten

| Fält | Innehåll |
|---|---|
| `type` | `urn:nonox:problem:<code>`. |
| `title` | HTTP-statusens korta engelska namn. |
| `status` | HTTP-statusen. |
| `code` | **Styr på den här.** Stabil och maskinläsbar; ändras aldrig inom v1. |
| `detail` | En svensk förklaring för människor. Kan ändras; styr aldrig på den. |
| `instance` | `urn:nonox:request:<uuid>`, samma id som svarshuvudet `X-Request-Id`. Uppge det vid supportfrågor. |
| `errors` | Bara vid `422 validation_failed`: felen per fält. |
| `required_scopes` | Bara vid `403 insufficient_scope`: behörigheterna som behövs. |

Ett serverfel (`500`) avslöjar aldrig orsaken, bara `instance`.

## Alla koder

Listan genereras från OpenAPI-filen, så den är alltid komplett.

<!-- nonox:errors -->

## Vad programmet ska göra

| Status | Gör så här |
|---|---|
| `400` | Rätta anropet. Oftast saknas `Idempotency-Key` eller så är kroppen inte ett JSON-objekt. |
| `401` | Förnya access token (OAuth) och försök en gång till. Ger det `401` igen, eller `invalid_grant` vid förnyelsen, har anslutningen återkallats: be personen ansluta igen. För en API-nyckel: nyckeln har gått ut eller återkallats. |
| `403` | Försök inte igen. Läs `code`: en behörighet saknas, adressen är inte tillåten, företaget är arkiverat eller tillåter inte längre appen. |
| `404` | Posten finns inte, tillhör ett annat företag, eller så är NONOX API avstängt för företaget. API:t skiljer medvetet inte på dem. |
| `409` | Ett anrop med samma `Idempotency-Key` pågår. Vänta `Retry-After` sekunder och försök igen med samma nyckel. |
| `415` | Skicka `Content-Type: application/json` (eller `multipart/form-data` för underlag). |
| `422` | Rätta uppgifterna efter `errors`, eller använd en ny `Idempotency-Key` för en ny åtgärd. |
| `429` | Vänta `Retry-After` sekunder. Se [Gränser för anrop](granser.md). |
| `5xx` | Försök igen med samma `Idempotency-Key` och ökande väntetid. |

## Ett exempel på felhantering

<!-- tabs -->
```php
use Illuminate\Http\Client\RequestException;

try {
    $voucher = Http::withToken(config('services.nonox.token'))
        ->withHeaders(['Idempotency-Key' => $key])
        ->post('https://nonox.app/api/v1/vouchers/'.$id.'/post')
        ->throw()
        ->json('data');
} catch (RequestException $exception) {
    $problem = $exception->response->json();

    match ($problem['code'] ?? null) {
        'validation_failed' => $this->showErrors($problem['errors']),
        'insufficient_scope' => $this->askForScopes($problem['required_scopes']),
        'company_archived' => $this->markReadOnly(),
        default => report($exception),
    };
}
```

```js
const response = await fetch(`https://nonox.app/api/v1/vouchers/${id}/post`, {
  method: 'POST',
  headers: { Authorization: `Bearer ${process.env.NONOX_TOKEN}`, 'Idempotency-Key': key },
});

if (!response.ok) {
  const problem = await response.json();

  switch (problem.code) {
    case 'validation_failed': showErrors(problem.errors); break;
    case 'insufficient_scope': askForScopes(problem.required_scopes); break;
    default: throw new Error(`${problem.code} (${problem.instance})`);
  }
}
```

```python
response = requests.post(
    f"https://nonox.app/api/v1/vouchers/{voucher_id}/post",
    headers={"Authorization": f"Bearer {os.environ['NONOX_TOKEN']}", "Idempotency-Key": key},
    timeout=30,
)

if not response.ok:
    problem = response.json()
    if problem["code"] == "validation_failed":
        show_errors(problem["errors"])
    elif problem["code"] == "insufficient_scope":
        ask_for_scopes(problem["required_scopes"])
    else:
        raise RuntimeError(f"{problem['code']} ({problem['instance']})")
```
<!-- /tabs -->

## Fel från OAuth

Tokenadressen (`/oauth/token`) svarar i OAuths eget format (`error`, `error_description`), inte med problem+json. Se [Autentisering](autentisering.md#fel-fran-tokenadressen).
