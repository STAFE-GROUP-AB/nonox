# Rate limits

The API limits the number of calls per minute, so a program stuck in a loop cannot make NONOX slow for everyone else. The limits are generous for an ordinary sync.

## The limits

The values below are read from the settings of the installation that shows this page. On nonox.app they are the defaults.

<!-- nonox:limits -->

The limit per company covers all apps and keys together, so a single app cannot crowd out the others.

## The headers

Every answer tells you how much is left:

```http
HTTP/1.1 200 OK
RateLimit-Limit: 120
RateLimit-Remaining: 117
RateLimit-Reset: 42
```

| Header | Means |
|---|---|
| `RateLimit-Limit` | Calls per minute for the key or app. |
| `RateLimit-Remaining` | How many are left in this minute. |
| `RateLimit-Reset` | Seconds until the window starts over. |

## Over the limit

```http
HTTP/1.1 429 Too Many Requests
Content-Type: application/problem+json
Retry-After: 23

{
  "type": "urn:nonox:problem:rate_limited",
  "title": "Too Many Requests",
  "status": 429,
  "code": "rate_limited",
  "detail": "För många anrop. Försök igen om 23 sekunder.",
  "instance": "urn:nonox:request:7f3c2a9e-4b1d-4c8e-9a51-2d6f0e8b3c17"
}
```

Wait at least `Retry-After` seconds before the next call. A `POST` that got `429` was not carried out; retry it with the same `Idempotency-Key`.

<!-- tabs -->
```php
$response = Http::withToken(config('services.nonox.token'))->get($url);

if ($response->status() === 429) {
    sleep((int) $response->header('Retry-After') ?: 1);
}
```

```js
const response = await fetch(url, { headers: { Authorization: `Bearer ${process.env.NONOX_TOKEN}` } });

if (response.status === 429) {
  const seconds = Number(response.headers.get('Retry-After')) || 1;
  await new Promise((resolve) => setTimeout(resolve, seconds * 1000));
}
```

```python
response = requests.get(url, headers={"Authorization": f"Bearer {os.environ['NONOX_TOKEN']}"}, timeout=30)

if response.status_code == 429:
    time.sleep(int(response.headers.get("Retry-After", "1")))
```
<!-- /tabs -->

## Being kind to the limits

- Sync with `updated_since` instead of fetching everything every time. See [Pagination and sync](sidindelning.md).
- Use `limit=200` for large lists: fewer calls for the same amount.
- Read `RateLimit-Remaining` and slow down before you reach zero.
- Do not run many parallel threads against the same company; the per-company limit is shared.
