# Errors

Every error from `/api/v1` is `application/problem+json` as defined by RFC 9457. The shape is always the same, so a program can handle every error in one place.

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

## The fields

| Field | Contents |
|---|---|
| `type` | `urn:nonox:problem:<code>`. |
| `title` | The HTTP status's short English name. |
| `status` | The HTTP status. |
| `code` | **Branch on this.** Stable and machine-readable; never changes within v1. |
| `detail` | A Swedish explanation for people. It may change; never branch on it. |
| `instance` | `urn:nonox:request:<uuid>`, the same id as the `X-Request-Id` header. Quote it in support questions. |
| `errors` | Only with `422 validation_failed`: the errors per field. |
| `required_scopes` | Only with `403 insufficient_scope`: the scopes needed. |

A server error (`500`) never reveals its cause, only `instance`.

## Every code

The list is generated from the OpenAPI file, so it is always complete. `detail` is the Swedish text the API sends.

<!-- nonox:errors -->

## What a program should do

| Status | Do this |
|---|---|
| `400` | Fix the call. Usually the `Idempotency-Key` is missing or the body is not a JSON object. |
| `401` | Refresh the access token (OAuth) and try once more. When it answers `401` again, or `invalid_grant` on the refresh, the connection was revoked: ask the person to connect again. For an API key: it has expired or was revoked. |
| `403` | Do not retry. Read `code`: a scope is missing, the address is not allowed, the company is archived or no longer allows the app. |
| `404` | The record does not exist, belongs to another company, or NONOX Connect is off for the company. The API deliberately does not tell them apart. |
| `409` | A call with the same `Idempotency-Key` is running. Wait `Retry-After` seconds and retry with the same key. |
| `415` | Send `Content-Type: application/json` (or `multipart/form-data` for attachments). |
| `422` | Fix the data according to `errors`, or use a new `Idempotency-Key` for a new operation. |
| `429` | Wait `Retry-After` seconds. See [Rate limits](granser.md). |
| `5xx` | Retry with the same `Idempotency-Key` and a growing delay. |

## Handling errors, an example

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

## Errors from OAuth

The token endpoint (`/oauth/token`) answers in OAuth's own format (`error`, `error_description`), not with problem+json. See [Authentication](autentisering.md#errors-from-the-token-endpoint).
