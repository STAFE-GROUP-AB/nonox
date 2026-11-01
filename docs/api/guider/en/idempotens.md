# Idempotency

Networks sometimes lose the answer even though the call went through. Without protection, a retry would create another draft, or post twice. That is why **every** `POST` to the API needs the `Idempotency-Key` header.

## How it works

Send a unique value per **intended operation**, ideally a UUID v4. When you repeat the same operation (a retry), send the same value again.

```bash
curl -X POST https://nonox.app/api/v1/vouchers \
  -H "Authorization: Bearer $NONOX_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 3f6c1b7e-2d4a-4e8b-9c0d-5a1b2c3d4e5f" \
  -d '{"voucher_date":"2026-03-02","description":"Kontantförsäljning","series":"A","rows":[{"account":1930,"debit_ore":125000},{"account":3001,"credit_ore":100000},{"account":2611,"credit_ore":25000}]}'
```

| Situation | Answer |
|---|---|
| New key | The call runs. Its answer is kept for 24 hours. |
| Same key, same call again | **The same answer** as the first time, with `Idempotent-Replayed: true`. Nothing is done again. |
| Same key, a different call | `422 idempotency_key_reused`. Use a new key for a new operation. |
| Same key while the first is running | `409 idempotency_in_progress` with `Retry-After`. Wait and retry. |
| The first call failed with a server error (5xx) | Not kept. The same key can be used again. |
| No key | `400 idempotency_key_required`. |
| Longer than 255 characters, or not visible ASCII | `400 idempotency_key_invalid`. |

"The same call" means the same method, path, body and uploaded files; NONOX compares a SHA-256 fingerprint of them. Keys are per app or API key, so two different apps cannot collide.

## A retry, done right

<!-- tabs -->
```php
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$key = (string) Str::uuid(); // Once per operation, outside the attempts.

$response = Http::withToken(config('services.nonox.token'))
    ->withHeaders(['Idempotency-Key' => $key])
    ->retry(3, 500, fn ($exception) => $exception instanceof \Illuminate\Http\Client\ConnectionException
        || in_array($exception->response?->status(), [409, 429, 500, 502, 503, 504], true))
    ->post('https://nonox.app/api/v1/vouchers', $voucher);
```

```js
const key = crypto.randomUUID(); // Once per operation, outside the attempts.

for (let attempt = 1; attempt <= 3; attempt++) {
  try {
    const response = await fetch('https://nonox.app/api/v1/vouchers', {
      method: 'POST',
      headers: { Authorization: `Bearer ${process.env.NONOX_TOKEN}`, 'Content-Type': 'application/json', 'Idempotency-Key': key },
      body: JSON.stringify(voucher),
    });
    if (![409, 429, 500, 502, 503, 504].includes(response.status)) {
      return response;
    }
  } catch (networkError) {
    // Retry with the same key.
  }
  await new Promise((resolve) => setTimeout(resolve, 500 * 2 ** attempt));
}
```

```python
import os, time, uuid, requests

key = str(uuid.uuid4())  # Once per operation, outside the attempts.

for attempt in range(3):
    try:
        response = requests.post(
            "https://nonox.app/api/v1/vouchers",
            headers={"Authorization": f"Bearer {os.environ['NONOX_TOKEN']}", "Idempotency-Key": key},
            json=voucher,
            timeout=30,
        )
        if response.status_code not in (409, 429, 500, 502, 503, 504):
            break
    except requests.ConnectionError:
        pass
    time.sleep(0.5 * 2 ** attempt)
```
<!-- /tabs -->

What matters: the key is created **once**, before the attempts, and goes with every attempt. A new key per attempt removes the protection.

## What does not need the key

`GET`, `PATCH` and `DELETE` need no `Idempotency-Key`. Reading is harmless, a `PATCH` on a draft gives the same result however many times it runs, and a `DELETE` on a draft that is already gone answers `404`.

## Posting is always safe to retry

`POST /vouchers/{voucher}/post` with the same key returns the same posted voucher, with the same number. Even without the key a second attempt would not post again, since the voucher is no longer a draft; but the key gives you the original answer back instead of an error.
