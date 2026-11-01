# Pagination and sync

Every list in the API (except the VAT codes, which are few) is split into pages with a **cursor** and ordered by `updated_at`, then id. A sync therefore never skips or duplicates a record, even when records change while you page through.

## The parameters

| Parameter | Means |
|---|---|
| `limit` | Records per page, 1–200. Default 50. |
| `cursor` | The value of `meta.next_cursor` from the previous page. Treat it as an opaque string. |
| `updated_since` | Only records changed **after** this time (ISO 8601, for example `2026-03-01T00:00:00+01:00`). |

The answer always has the same shape:

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

On the last page `has_more` is `false` and `next_cursor` is `null`.

## A sync that holds

1. The first time: fetch everything, page by page, until `has_more` is `false`.
2. Store the largest `updated_at` you have seen.
3. Next time: send it as `updated_since` and walk all pages again.

<!-- tabs -->
```php
use Carbon\CarbonImmutable as Carbon;
use Illuminate\Support\Facades\Http;

$since = $state->last_updated_at; // null the first time
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

Compare times as times, not as text: `updated_at` carries a time zone (`+01:00` or `+02:00`).

## What counts as a change

- A draft that is changed or posted gets a new `updated_at`.
- A posted voucher never changes, with one exception: when it is corrected, `corrected_by_voucher_id` is set once. The correcting voucher is a new voucher of its own.
- A deleted draft disappears from the list. Posted vouchers are never deleted.

## Amounts and dates

- Amounts are whole öre in fields ending in `_ore`: `125000` is SEK 1,250.00. Never compute with floats.
- Dates (`voucher_date`) are `YYYY-MM-DD`, with no time.
- Timestamps (`updated_at`, `posted_at`) are ISO 8601 with a time zone.
