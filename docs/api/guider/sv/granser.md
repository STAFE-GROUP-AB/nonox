# Gränser för anrop

API:t begränsar antalet anrop per minut, så att ett program som fastnat i en loop inte gör NONOX långsamt för alla andra. Gränserna är generösa för en vanlig synk.

## Gränserna

Värdena nedan läses från inställningarna på den installation som visar sidan. På nonox.app är de standardvärdena.

<!-- nonox:limits -->

Gränsen per företag gäller alla appar och nycklar tillsammans, så att en enskild app inte kan tränga undan de andra.

## Svarshuvudena

Varje svar berättar hur mycket som finns kvar:

```http
HTTP/1.1 200 OK
RateLimit-Limit: 120
RateLimit-Remaining: 117
RateLimit-Reset: 42
```

| Huvud | Betyder |
|---|---|
| `RateLimit-Limit` | Antal anrop per minut för nyckeln eller appen. |
| `RateLimit-Remaining` | Hur många som är kvar i den här minuten. |
| `RateLimit-Reset` | Sekunder tills fönstret börjar om. |

## Över gränsen

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

Vänta minst `Retry-After` sekunder innan nästa anrop. Ett `POST` som fick `429` har inte utförts; försök igen med samma `Idempotency-Key`.

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

## Snällt mot gränserna

- Synka med `updated_since` i stället för att hämta allt varje gång. Se [Sidindelning och synk](sidindelning.md).
- Använd `limit=200` för stora listor: färre anrop för samma mängd.
- Läs `RateLimit-Remaining` och sakta ner innan du når noll.
- Kör inte många parallella trådar mot samma företag; gränsen per företag delas.
