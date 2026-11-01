# Sidindelning och synk

Alla listor i API:t (utom momskoderna, som är få) delas upp i sidor med en **markör** (cursor) och sorteras på `updated_at` och sedan id. Det gör att en synk aldrig hoppar över eller dubblerar en post, även när poster ändras medan du bläddrar.

## Parametrarna

| Parameter | Betyder |
|---|---|
| `limit` | Antal poster per sida, 1–200. Standard 50. |
| `cursor` | Värdet från `meta.next_cursor` på förra sidan. Behandla det som en ogenomskinlig sträng. |
| `updated_since` | Bara poster som ändrats **efter** den här tiden (ISO 8601, till exempel `2026-03-01T00:00:00+01:00`). |

Svaret har alltid samma form:

```json
{
  "data": [ { "…": "…" } ],
  "meta": {
    "limit": 50,
    "next_cursor": "eyJ1cGRhdGVkX2F0IjoiMjAyNi0wMy0wMiAxMDo0NToxMiIsImlkIjoiMGI5ZjNjMWUiLCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9",
    "has_more": true
  }
}
```

På sista sidan är `has_more` `false` och `next_cursor` `null`.

## En synk som håller

1. Första gången: hämta allt, sida för sida, tills `has_more` är `false`.
2. Spara det största `updated_at` du har sett.
3. Nästa gång: skicka det som `updated_since` och gå igenom alla sidor igen.

<!-- tabs -->
```php
use Carbon\CarbonImmutable as Carbon;
use Illuminate\Support\Facades\Http;

$since = $state->last_updated_at; // null första gången
$cursor = null;

do {
    $page = Http::withToken(config('services.nonox.token'))
        ->get('https://nonox.app/api/v1/vouchers', array_filter([
            'limit' => 200,
            'updated_since' => $since,
            'cursor' => $cursor,
        ]))
        ->throw()
        ->json();

    foreach ($page['data'] as $voucher) {
        $this->store($voucher);
        if ($state->last_updated_at === null || Carbon::parse($voucher['updated_at'])->gt($state->last_updated_at)) {
            $state->last_updated_at = $voucher['updated_at'];
        }
    }

    $cursor = $page['meta']['next_cursor'];
} while ($page['meta']['has_more']);

$state->save();
```

```js
let cursor = null;
let newest = state.lastUpdatedAt;

do {
  const url = new URL('https://nonox.app/api/v1/vouchers');
  url.searchParams.set('limit', '200');
  if (state.lastUpdatedAt) url.searchParams.set('updated_since', state.lastUpdatedAt);
  if (cursor) url.searchParams.set('cursor', cursor);

  const page = await (await fetch(url, { headers: { Authorization: `Bearer ${process.env.NONOX_TOKEN}` } })).json();

  for (const voucher of page.data) {
    await store(voucher);
    if (!newest || Date.parse(voucher.updated_at) > Date.parse(newest)) newest = voucher.updated_at;
  }

  cursor = page.meta.next_cursor;
  if (!page.meta.has_more) break;
} while (true);

state.lastUpdatedAt = newest;
```

```python
import os, requests
from datetime import datetime

cursor, newest = None, state.last_updated_at

while True:
    params = {"limit": 200, "updated_since": state.last_updated_at, "cursor": cursor}
    page = requests.get(
        "https://nonox.app/api/v1/vouchers",
        headers={"Authorization": f"Bearer {os.environ['NONOX_TOKEN']}"},
        params={k: v for k, v in params.items() if v},
        timeout=30,
    ).json()

    for voucher in page["data"]:
        store(voucher)
        if newest is None or datetime.fromisoformat(voucher["updated_at"]) > datetime.fromisoformat(newest):
            newest = voucher["updated_at"]

    if not page["meta"]["has_more"]:
        break
    cursor = page["meta"]["next_cursor"]

state.last_updated_at = newest
```
<!-- /tabs -->

Jämför tider som tider, inte som text: `updated_at` har tidszon (`+01:00` eller `+02:00`).

## Vad som räknas som en ändring

- Ett utkast som ändras eller bokförs får ett nytt `updated_at`.
- En bokförd verifikation ändras aldrig, med ett undantag: när den rättas sätts `corrected_by_voucher_id` en gång. Rättelseverifikationen är en egen, ny verifikation.
- Ett borttaget utkast försvinner ur listan. Bokförda verifikationer tas aldrig bort.

## Belopp och datum

- Belopp är hela öre i fält som slutar på `_ore`: `125000` är 1 250,00 kr. Räkna aldrig med flyttal.
- Datum (`voucher_date`) är `ÅÅÅÅ-MM-DD`, utan tid.
- Tidpunkter (`updated_at`, `posted_at`) är ISO 8601 med tidszon.
