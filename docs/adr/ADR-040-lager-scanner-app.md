# ADR-040: Lager scanner app ("Skanna")

- Status: **Accepted**
- Date: 2026-11-02
- Builds on: ADR-039 (NONOX Lager), ADR-031 (installable web app), ADR-022
  (the staff ledger kiosk's offline queue)

## Context

Goods are received, picked, moved and counted standing at a shelf with a
phone in one hand, often where the network is poor. Lager's pages
(ADR-039) work on a phone, but every step is a form round trip and nothing
works without coverage. Small shops and warehouses also use cheap
Bluetooth or corded hand scanners that type a code and press Enter.

## Decision

### One page, part of the installable app

`/lager/skanna` is a standalone, phone-first page (its own Vite entry,
`app/Modules/Inventory/resources/js/scanner.js`, and CSS), linked from the
Lager menu as **Skanna** and from the app manifest as the shortcut **Skanna
lager**. It has its own manifest, `public/skanna.webmanifest` (id
`/lager/skanna`, start `/lager/skanna?app=skanna`, own icons
`public/brand/skanna-*`, home-screen title "Skanna"), so warehouse staff can
install it as a separate app; there is no store app (ADR-031). Opened from
that app (the `?app=skanna` mark, kept in the window's `sessionStorage`, and
running standalone) the page has no link to the full app; in a browser or
from the NONOX app the link stays. This is no access boundary: what a user
may do is still decided by the role. The sign-in link after an expired
session goes through `/lager/skanna`, so signing in returns to Skanna.
On a computer it shows a QR code of its own URL (bacon/bacon-qr-code via the
existing `QrSvg`) and a button to continue there.

Five modes, big tiles: **Ta emot**, **Plocka**, **Flytta**, **Inventera**,
**Slå upp**. Users with `inventory:view` may open it and look things up;
the four handling modes need `inventory:count` (Lagerpersonal has both).

### Server: thin controller, existing actions

`ScannerController` serves the page, the service worker, the reference
data (`GET /lager/skanna/data`), a lookup (`GET /lager/skanna/sok?kod=`),
article images, and the operations endpoint
(`POST /lager/skanna/operationer`). Every operation runs through
`RunScanOperation`, which only calls the Lager actions: `SaveReceipt` +
`ReceiveStock` (receive, with or without an existing receipt),
`PickLists::pick` + `complete` (pick), `RecordMovement` kind `transfer`
(move, policy `count`), `StockCounts::addLine`/`record`/`complete` and
`start` (count). Nothing in the scanner moves stock by itself.

A short pick keeps its reason on the line (`pick_list_lines.short_reason`:
MISSING, DAMAGED, WRONG_LOCATION, OTHER); `PickLists::pick` takes it as an
optional argument. A short line without a reason is refused.

No price, cost or value is ever sent to the phone, whatever the role: the
reference data and the lookup leave them out, so Lagerpersonal cannot see
them by reading the network either.

### Exactly once: the UUID per operation

The phone makes a UUID v4 for each operation and stores it in its queue.
The server keeps `inventory_scan_operations` (company, user, uuid, kind,
status, result), unique on (company_id, uuid). The first arrival inserts the
row (`INSERT … ON CONFLICT DO NOTHING`), runs the action in a savepoint and
stores the answer; a second arrival of the same UUID waits on the unique
index until the first commits, then returns the stored answer with
`replayed: true` and posts nothing. The same UUID from another user is
refused. Answers are `OK`, `REJECTED` (a validation message from the action,
for example negative stock) or `NOT_FOUND` (an id that is not in the
signed-in company). A rejected answer is stored too; retrying from the phone
makes a new UUID.

### The company is the session's

The phone sends the company id its queue was made in, and the server only
compares it: a mismatch answers 409 for the whole request and the queue
waits (the user may have switched company). Ids in payloads are looked up
under the company scope; another company's receipt, pick list or stock
count is `NOT_FOUND`, its articles and locations fail validation. The lookup
and image endpoints answer 404 for another company's codes and images.

### Offline

- A service worker for the narrower scope `/lager/skanna`
  (`Service-Worker-Allowed`), served outside the auth middleware since it
  holds no data, precaches the page and its built files (from Vite's
  manifest, including the lazily loaded decoder). It keeps the page only
  when it is the real page, never a sign-in redirect. JSON is never cached.
  The app-wide worker (ADR-031) is unchanged.
- IndexedDB `nonox-skanna` holds the reference data (stocked articles:
  number, name, unit, GTIN, scanner code, SKU, default location; active
  locations; non-zero levels; open receipts, pick lists and stock counts),
  the queue and the conflicts. The view is the server's data with the
  waiting operations applied (`withPending`), so stock and lists are right
  on the phone before they are sent. ADR-031 kept data off devices; this is
  a deliberate, narrow exception like the kiosk's: no prices, customers or
  personal data, only what a person at the shelf already sees.
- Operations are sent oldest first, at most 50 per request, every 20 seconds
  and when the network returns. Rejected ones become conflicts with the
  server's message and advice per kind; the user retries (new UUID) or
  removes them. A banner shows "N väntar på att skickas".
- Starting a stock count needs the network (the system quantities are
  captured then); counting does not. Counts are sent as absolute quantities,
  so a resend changes nothing.

### Codes and labels

The scanner and the label studio (ADR-041) use one encoding:

| Label | Content |
|---|---|
| Location | `L:` + `full_code`, e.g. `L:HL-A-03-2` (QR or Code 128; the bare code printed as text) |
| Article with a valid GTIN | the GTIN as EAN-13/EAN-8; a GTIN-12 as EAN-13 with a leading 0 |
| Article without GTIN | `scanner_code` when set, else `A:` + article number (Code 128 or QR) |

Reading (`ScanCode::resolve` on the server, `resolveCode` in
`scanner-core.js`, kept identical and tested on both sides): `L:` → location
by full code; `A:` → article by number; otherwise an article by GTIN (also
with one leading zero added or removed), scanner code, SKU, number; then a
location by its bare full code. Prefixes and location codes ignore case.

### Scanning input

- **Camera**: the browser's `BarcodeDetector` when it supports EAN-13,
  EAN-8, Code 128 and QR; otherwise `@zxing/library` 0.23.0 (Apache-2.0,
  AGPL-3.0 compatible; its dependency ts-custom-error is MIT), loaded lazily
  only on such browsers (Safari on iPhone). jsQR, already in the project,
  reads only QR codes, so it does not cover EAN on iPhone.
- **Keyboard wedge**: the scan field always has focus; a hand or Bluetooth
  scanner types the code and Enter. The on-screen keyboard is suppressed
  (`inputmode="none"`) until the user taps the keyboard button.
- **Feedback**: a WebAudio beep (no sound files), `navigator.vibrate`, a
  full-screen green or red flash and the message in text; sound can be
  turned off.

## Consequences

- The scanner is a second way into the same actions; their rules
  (negative stock, active locations, reasons on count differences) apply
  unchanged and are reported to the phone as conflicts.
- The manifest shortcut exists for every installation, also where Lager is
  off; there it leads to a 404 page.
- No legal values are introduced.
