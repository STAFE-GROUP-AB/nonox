# Idempotens

Ett nätverk tappar ibland svaret fast anropet gick fram. Utan skydd skulle ett nytt försök skapa ett utkast till, eller bokföra två gånger. Därför kräver **varje** `POST` till API:t huvudet `Idempotency-Key`.

## Så fungerar det

Skicka ett unikt värde per **avsedd åtgärd**, helst ett UUID v4. Gör du om samma åtgärd (ett nytt försök), skicka samma värde igen.

```bash
curl -X POST https://nonox.app/api/v1/vouchers \
  -H "Authorization: Bearer $NONOX_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 3f6c1b7e-2d4a-4e8b-9c0d-5a1b2c3d4e5f" \
  -d '{"voucher_date":"2026-03-02","description":"Kontantförsäljning","series":"A","rows":[{"account":1930,"debit_ore":125000},{"account":3001,"credit_ore":100000},{"account":2611,"credit_ore":25000}]}'
```

| Situation | Svar |
|---|---|
| Ny nyckel | Anropet utförs. Svaret sparas i 24 timmar. |
| Samma nyckel, samma anrop igen | **Samma svar** som första gången, med `Idempotent-Replayed: true`. Inget görs om. |
| Samma nyckel, ett annat anrop | `422 idempotency_key_reused`. Använd en ny nyckel för en ny åtgärd. |
| Samma nyckel medan det första pågår | `409 idempotency_in_progress` med `Retry-After`. Vänta och försök igen. |
| Det första anropet gav ett serverfel (5xx) | Sparas inte. Samma nyckel kan användas igen. |
| Ingen nyckel | `400 idempotency_key_required`. |
| Längre än 255 tecken, eller inte synlig ASCII | `400 idempotency_key_invalid`. |

"Samma anrop" betyder samma metod, adress, innehåll och uppladdade filer; NONOX jämför ett SHA-256-avtryck av dem. Nycklarna gäller per app eller API-nyckel, så två olika appar kan inte krocka.

## Ett nytt försök, rätt gjort

<!-- tabs -->
```php
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$key = (string) Str::uuid(); // En gång per åtgärd, utanför försöken.

$response = Http::withToken(config('services.nonox.token'))
    ->withHeaders(['Idempotency-Key' => $key])
    ->retry(3, 500, fn ($exception) => $exception instanceof \Illuminate\Http\Client\ConnectionException
        || in_array($exception->response?->status(), [409, 429, 500, 502, 503, 504], true))
    ->post('https://nonox.app/api/v1/vouchers', $voucher);
```

```js
const key = crypto.randomUUID(); // En gång per åtgärd, utanför försöken.

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
    // Försök igen med samma nyckel.
  }
  await new Promise((resolve) => setTimeout(resolve, 500 * 2 ** attempt));
}
```

```python
import os, time, uuid, requests

key = str(uuid.uuid4())  # En gång per åtgärd, utanför försöken.

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

Det viktiga: nyckeln skapas **en gång**, före försöken, och följer med varje försök. Skapas en ny nyckel per försök försvinner skyddet.

## Vad som inte behöver nyckeln

`GET`, `PATCH` och `DELETE` kräver ingen `Idempotency-Key`. Att hämta är ofarligt, `PATCH` på ett utkast ger samma resultat hur många gånger det än görs, och `DELETE` på ett utkast som redan är borttaget ger `404`.

## Bokföring är alltid säker att försöka igen

`POST /vouchers/{voucher}/post` med samma nyckel ger samma bokförda verifikation, med samma nummer. Även utan nyckeln skulle ett andra försök inte bokföra igen, eftersom verifikationen inte längre är ett utkast; men nyckeln gör att du får tillbaka det ursprungliga svaret i stället för ett fel.
