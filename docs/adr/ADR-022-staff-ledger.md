# ADR-022: Staff ledger (personalliggare)

- Status: Accepted
- Date: 2026-11-02
- Spec: the owner's specification "Nonox Personalliggare" (2026-10-31)

## Context

From 1 January 2027 more industries must keep an electronic staff ledger
(prop. 2025/26:282, 39 kap. skatteförfarandelagen), and from 1 July 2027 it
must be possible to transfer it to Skatteverket electronically at a control
visit. v1 covers restaurants, vehicle service, food and tobacco wholesalers,
body and beauty care including hairdressers, and laundries. Construction
(ID06, byggarbetsplatsanmälan) is v2. The spec talks about a local "Core", a
relay called "Connect" and a NativePHP kiosk; none of those exist in NONOX.

## Decisions

### Architecture

- "Core" is this NONOX installation, self-hosted or on NONOX CLOUD
  (nonox.app). "Connect" is NONOX CLOUD itself: **there is no separate relay
  in v1.** A company that self-hosts and wants mobile check-in outside the
  premises needs its NONOX reachable from the internet.
- The module is `staff_ledger` under `app/Modules/StaffLedger`, built on the
  module framework (ADR-017): it is turned on and off per company under
  Moduler, its pages carry `module:staff_ledger` (404 while off), and its
  settings (reminder hours, long-shift hours, allowed kiosk clock skew) are
  module settings. Check-in methods are set per location.
- Turning the module off stops the kiosks but keeps the ledger: the ledger
  and the control visit view stay reachable for the retention period through
  archive permissions (`staff-ledger:view`, `:control-visit`,
  `:view-sensitive`), like payroll's archive.
- Personal numbers are masked in every list (people, ledger). A user with
  `:view-sensitive` shows them in full on purpose, and each showing is
  written to the audit log. The control visit view shows them in full,
  since that is what it is for.

### Kiosk: a PWA, not NativePHP

- The kiosk is an installable page served by NONOX
  (`/personalliggare/kiosk/start`) with a web manifest and a service worker
  scoped to `/personalliggare/kiosk/`. The worker precaches the page, its
  manifest, icon and the built assets listed in Vite's manifest, so the kiosk
  starts and works with no network.
- The page holds no company data. A manager registers a device for one
  company and one location and gets a one-time pairing code (15 minutes,
  single use, shown with a QR link). The kiosk exchanges it for a 64-character
  random device token; the server keeps only its SHA-256. The token is sent as
  a bearer token; there is no session, no login and no CSRF on the kiosk's
  endpoints, which are throttled (120 requests per minute per IP, pairing 10).
  A manager (`staff-ledger:manage`) can also remove a kiosk: one that never
  sent an entry is deleted, one that did is revoked and hidden, since each
  entry keeps the device that sent it.
  A device can be revoked at any time; a kiosk that is set up again gets a
  new device, so sequence numbers never collide.
- Several companies at the same address each keep their own locations and
  ledgers; a kiosk serves exactly one company. The UI says so where locations
  are created.

### Offline for 72 hours (and longer)

- The roster (people, PIN verifiers, QR hashes, who is in now) and the
  device token live in IndexedDB. Every check-in is written there first, with
  the device's own time (with UTC offset, to the second), a per-device
  sequence number and a client-side hash chain:
  `client_hash = sha256(previous_client_hash || canonical_json(event))`.
  The person sees "Incheckad" at once; a background loop sends the queue, in
  sequence order, every 15 seconds and when the network comes back. Nothing
  expires from the queue, so 72 hours offline is no special case.
- The server stores the device time (`occurred_at`) and its own time
  (`received_at`), and never rewrites or drops an event. It is idempotent on
  (device, sequence) and on the event's UUID: a resent event is acknowledged
  as a duplicate. It flags, on the entry itself: clock skew beyond the setting
  (`CLOCK_SKEW`, with the measured skew in seconds), events sent more than 15
  minutes after they happened (`LATE`), a skipped sequence number
  (`SEQUENCE_GAP`; a late arrival fills the gap without moving the device
  back) and a device chain that does not continue or does not recompute
  (`CLIENT_CHAIN_BREAK`). Only an event it cannot store at all (unknown
  person, malformed) is rejected, and the kiosk keeps rejected events for the
  USB export.
- The kiosk and the server share the canonical form; a fixed event is tested
  on both sides (`tests/js/staff-ledger-kiosk.test.mjs`, `KioskSyncTest`).

### PINs offline: the trade-off

- PINs are four digits. The server never stores a PIN: each person has a
  random 16-byte salt and a PBKDF2-SHA256 verifier (100 000 iterations,
  32 bytes), which the kiosk recomputes with WebCrypto. That is the only way a
  PIN can be checked with no network.
- The cost: a device holds every person's salt and verifier, and 10 000
  candidate PINs are few. Someone who copies a kiosk's storage can recover
  the PINs offline in minutes. We accept that because a PIN only identifies a
  person *at that kiosk*: it opens no account and no data, and every
  registration is visible, attributable and correctable. Mitigations: the
  person taps their own name first (one derivation per attempt, ~100 ms), five
  wrong PINs lock that person out on the device for five minutes, trivial PINs
  (0000, 1234) are refused, a lost device is revoked, and a new PIN is set by
  a manager.
- A manager's PIN also opens the control visit view on the kiosk. Online it is
  checked on the server, five tries per device per 15 minutes, then 429.
  Offline it is checked against the managers' verifiers, with the same local
  lockout, and the opening is queued and written to the audit log at the next
  sync. The server cannot check such a report (the PIN was checked on the
  device), so it treats it as a claim by the device token (security review
  2026-11-01: a token could otherwise write any number of openings naming
  anybody): it is written only when it names an active manager of the
  device's company who has a PIN, identical reports are written once, at
  most five per sync, and each carries `where: kiosk_offline`,
  `reported_by: device` and `verified: false`.
- The control visit data (personal numbers, employers, 31 days of shifts)
  is never handed to a device token alone (security review 2026-11-01: the
  token sits in the kiosk's storage, so anyone copying it could fetch every
  personal number). Online, the server returns it in plain text only after
  checking a manager's PIN, and the kiosk keeps it in memory while the view
  is open. For working offline the roster carries it sealed: AES-256-GCM
  under a random data key, which is in turn sealed for each manager with a
  key derived from the manager's PIN (PBKDF2, its own salt, stored encrypted
  on the server and set when the PIN is set or first used online). Four
  digits are few, so someone with a copy of the kiosk's storage can still
  try all of them; sealing makes that brute force the only way in, instead
  of one request.

### QR codes

- A person's QR code is `NXPL1.{person}.{nonce}.{hmac}`, signed with the
  application key and bound to the company. A new nonce invalidates the old
  card. The kiosk gets only `sha256(token)` and recognises a scanned code
  offline by hashing it; it can never make one.
- The kiosk scans with the browser's `BarcodeDetector` where it exists
  (Chrome, Android). Safari on iPad, the most likely kiosk, has none, so the
  kiosk lazily loads **jsQR 1.4.0** (Apache-2.0, AGPL-compatible) only there.
  It is bundled by Vite as a separate chunk and precached by the service
  worker. No MIT/BSD QR decoder of comparable maturity was found; Apache-2.0
  is compatible with AGPL-3.0.
- QR codes are rendered on the server with bacon/bacon-qr-code (BSD-2-Clause,
  already installed through Fortify).
- Apple Wallet and Google Wallet passes are **not** in v1: they need signing
  certificates per issuer. The QR is shown on the person's own page and as a
  printable card.

### Mobile check-in with a geofence

- Off by default, turned on per location, and it requires the location's
  coordinates and radius. The person must be signed in and linked to their
  register entry. The browser's Geolocation API gives a position; the server
  computes the distance (haversine) and refuses beyond the radius or with an
  accuracy worse than 200 m. Only the distance is stored, never the position.
  The page alone allows `geolocation=(self)` in its Permissions-Policy, as the
  kiosk alone allows `camera=(self)`.
- **Trust boundary (security review 2026-11-01).** The position is sent by the
  person's own browser; the server cannot tell a real fix from coordinates
  typed in at home. The geofence stops honest mistakes, not a person who
  wants to cheat. We therefore do not present a mobile entry as verified
  presence: the method is labelled "Mobil, självrapporterad position", and
  every such entry carries the flag `SELF_REPORTED_POSITION` ("Position
  uppgiven av telefonen, ej verifierad"), which is part of the hashed entry
  and shown in the ledger, the control visit PDF and the CSV and XML exports.
  Attempts are rate-limited to ten per person and ten minutes, so the
  geofence cannot be probed. Employers who need verified presence use the
  kiosk (the device sits on the premises) and leave mobile check-in off.
  TODO(legal-verify): whether Skatteverket accepts self-reported positions
  as a check-in method for the 2027 industries, and whether such entries
  must be marked in the transfer format, is not known; ask before the
  electronic transfer is built.

### The ledger

- `staff_ledger_entries` is append-only and company-scoped. Each entry
  copies the person's name, personal number (encrypted) and employer at the
  time, so the ledger shows what was true then. Types: `CHECK_IN`,
  `CHECK_OUT`, `MANUAL` (by a manager, with direction and a reason) and
  `CORRECTION` (refers to an entry; `VOID` or `TIME` with the correct time,
  a reason, who and when). Originals are never changed; views show them
  struck through or with the correction beside them.
- Hash chain per company and location:
  `hash = sha256(previous_hash || canonical_json(entry))`, appended under a
  row lock on the location, with the entry's id taken from the sequence
  before it is written. The head is kept on the location. The personal
  number is hashed in clear text, so re-encryption with a new key does not
  break the chain; times are hashed as stored (whole seconds, local time),
  so the hour that repeats in October cannot cause a false alarm.
- Three layers stop changes, as for vouchers and the audit log: the model
  refuses update and delete, a PostgreSQL trigger refuses UPDATE, DELETE and
  TRUNCATE, and `personalliggare:verify` recomputes every chain nightly
  (03:30) and writes the result to the audit log, shown on the overview.
- The only deletion is the retention prune (`privacy:prune` →
  `PruneStaffLedger`): it removes a prefix of a location's chain, inside a
  transaction that sets `nonox.staff_ledger_prune` for that transaction only,
  moves the location's chain anchor to the last removed hash and writes the
  anchor to the audit log. The verifier checks the anchor against the last
  recorded prune, so moving it by hand is detected.
- Retention (changed 2026-11-07): an entry is kept until two years after
  the end of the calendar year in which the company's fiscal year
  (beskattningsår) containing the entry's date ended, 9 kap. 5 § tredje
  stycket skatteförfarandeförordningen (2011:1261). It used to count from
  the entry's own calendar year, which removed entries of a broken or
  extended fiscal year a year too early. `Retention` finds the fiscal year
  in `fiscal_years`; without one it derives a twelve-month year from
  `fiscal_year_start_month`, and when that is in doubt (it overlaps a
  known fiscal year, or the company has none) the entry is kept. Fiscal
  years are loaded once per company per run. Both the entry's time and the
  time it was received must be past retention; the walk along the chain
  stops at the first entry that is kept. Enligt AI-utredning 2026-11-02,
  juristgranskning återstår.
- Forgotten check-outs: `personalliggare:remind` (every 15 minutes) mails
  the person, if the register has their email, and the managers once per
  check-in after the configured hours (default 12). Nobody is ever checked
  out automatically.

### Foreign identity numbers (added 2026-11-07)

- 9 kap. 5 § skatteförfarandeförordningen asks for "personnummer,
  samordningsnummer eller motsvarande utländska nummer". A person can be
  registered with a foreign identity number and the issuing country (ISO
  3166-1 alpha-2, plus XK; not SE). The number is kept in the same
  encrypted `personal_number` column, trimmed with runs of spaces
  collapsed, at most 30 characters and never Luhn-checked;
  `identity_country` is null for a Swedish number. The blind index of a
  foreign number is computed from the country and the number's letters and
  digits in upper case, so a duplicate is found whatever the spacing. A
  Swedish number's index is unchanged.
- An employer of hired staff may likewise be foreign: the number (widened
  column, 30 characters) and `employer_country`.
- Entries copy both countries. They are part of the hash payload only when
  set, so every entry written before keeps its hash. Lists show a foreign
  number as "Utländskt nummer (NO), dolt"; the control visit, the kiosk's
  sealed copy, the PDF and the CSV show it in full with the country (the
  CSV in its own columns), and the provisional XML has
  `UtlandskIdentitet`/`UtlandsktNummer` with `Nummer` and `Land` as a
  choice beside the Swedish elements (older files still validate).
  TODO(legal-verify): how Skatteverket's schema will carry a foreign
  number and country.
- Not implemented: the employer data from 2027 in prop. 2025/26:282
  (39 kap. 11 a § andra stycket SFL, employer for everyone with another
  employer, identified by name and personal, coordination, organisation or
  foreign number). The Riksdag decision is planned for 2026-11-11 and the
  rule would apply from 2027-07-01; the förordning is not issued.
  Enligt AI-utredning 2026-11-02, juristgranskning återstår.

### Control visit ("Skatteverket är här")

- In the app it is one tap from the staff ledger's navigation; the user's
  sign-in and the `staff-ledger:control-visit` permission take the place of
  a PIN. On the kiosk it is one tap and a manager's PIN. It shows who is in
  now and the ledger for a chosen period, searchable by name or personal
  number, with numbers in full. Every opening and every export is audited.
- Exports: PDF (dompdf, ADR-002), CSV (`App\Support\Csv`, BOM, semicolons),
  and XML. Skatteverket's schemalager publishes a schema for the
  construction industry's ledger (v1.1) but none for the 2027 industries, so
  the XML is NONOX's own provisional format with its own XSD
  (`app/Modules/StaffLedger/database/xsd/personalliggare-provisorisk-0.1.xsd`),
  behind the `LedgerXmlFormat` interface. TODO(SPEC).
- A view or export covers at most 13 months (`ControlVisitReport::MAX_MONTHS`;
  the view shortens a longer period, the export refuses it with a Swedish
  message), and exports count against the company's hourly `pdf` work quota
  (300, shared with invoice and annual report PDFs and report CSVs), so one
  account cannot tie up the server with repeated year-long exports.
- Electronic transfer goes through the `SkatteverketTransfer` interface; the
  container binds `MockSkatteverketTransfer`, which sends nothing and says
  so. TODO(SPEC).
- The kiosk's "USB export" builds a ZIP on the device from its cache: a CSV
  of confirmed shifts and queued events, the raw queue with the device hash
  chain, and a read-me. It works with no network.

### Payroll link

- With payroll on, people can be linked to an employee. "Föreslå
  tidrapport" sums closed shifts per employee and day into a proposal
  (`staff_ledger_time_proposals`). A user with `staff-ledger:manage` and
  `payroll:approve` approves it, which writes approved `time_reports` with
  the hourly pay code; or rejects it. Ledger and time reports stay separate
  tables. Since 2026-11-01 (GitHub issue #15) payroll runs pay the approved
  time reports as hourly pay (ADR-012, "Time reports in the payroll run");
  pending proposals are shown on the run page as hours waiting for approval,
  through a hook the payroll module offers (`TimeReportPay::addPendingSource`),
  so payroll does not depend on this module.

### Not built

- "Checked in without a schedule": NONOX has no staff schedules; the
  overview says so. No scheduling feature was invented.
- NFC (excluded by the spec), wallet passes, a separate relay.

## Consequences

- No new PHP packages. One npm package, `jsqr` (Apache-2.0), bundled.
- The kiosk depends on the browser keeping IndexedDB. A kiosk that is
  "cleared" loses its queue; the server flags the resulting sequence gap if
  a later event from the same device arrives. Kiosks should be dedicated
  devices with the page added to the home screen.
- Shared files touched: `bootstrap/providers.php`, `vite.config.js`
  (kiosk entry), `app/Http/Middleware/SecurityHeaders.php` (a page may set a
  narrower Permissions-Policy), `PrunePersonalDataCommand`, the tenancy
  suite and `lang/en.json`.
