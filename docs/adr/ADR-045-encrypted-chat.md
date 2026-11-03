# ADR-045: Encrypted chat that leaves nothing behind

- Status: Accepted, implemented; revised after review 2026-11-03 (server-checked follow allowlist, origin in the salt, replay and gap detection, locking, limits)
- Date: 2026-11-03
- Builds on: ADR-000 (passwordless sign-in), ADR-017 (module framework),
  ADR-029 (passkeys), ADR-031 (installable web app)

## Context

Support staff, an auditor and a customer often need to talk while looking at
the same thing in NONOX. E-mail and other chat tools keep everything that is
written, for years, often outside the EU. The owner's brief (2026-11-03):

> Support, an auditor and a customer can start an encrypted chat from any
> page. Starting gives a code; the code is sent by SMS or read out on the
> phone; the other person chooses *Anslut till chatt* and types it.
> Everything written lives only encrypted for the session, and when it is
> closed everything is deleted and nothing remains. If I, who started, tick
> *Följ mina sidor*, the person I chat with is taken to the same page in
> their NONOX.

The owner's decisions (2026-11-03):

1. **Exactly two participants.** The code stops working once the second
   person has joined.
2. **Any signed-in NONOX user** may join with the code, regardless of company.
3. **Nothing remains** afterwards: no audit-log entry, no database row, no
   application log line with a chat id or content.
4. **30 minutes idle** deletes a chat automatically; **8 hours** is the
   absolute maximum.

## Decision

### Core feature, not a module

Modules (ADR-017) are switched on per company and enforced per company. A
chat belongs to no company: it is between two users, who may be in different
companies or in none. It writes nothing to a company's data. So it is core,
on for every signed-in user, like the spotlight search (ADR-026).

### End-to-end encryption in the browser

Only WebCrypto, no new packages (`resources/js/secure-chat-core.js`).

- **The code**: 10 characters of Crockford base32 from
  `crypto.getRandomValues` (50 bits, each character 5 unbiased bits), shown as
  `K7Q4-XM9P-D2`. Typed codes are read case-insensitively, with or without
  dashes, spaces or dots, with O read as 0 and I or L as 1. U is not in the
  alphabet.
- **Derivation**:
  `master = PBKDF2-HMAC-SHA-256(code, salt "nonox-chat-v1|" + location.origin, 600 000 iterations, 256 bits)`,
  then HKDF-SHA-256 from `master` with info `"nonox-chat-v1 room"` gives the
  **room id** (32 bytes, base64url, the only thing the server learns) and with
  info `"nonox-chat-v1 key"` the **AES-256-GCM key** (never leaves the browser).
- **Deviation from the brief (HKDF alone)**: PBKDF2 stretches the code first.
  The operator sees room ids; with HKDF alone, testing all 2^50 codes against
  one room id is about 10^15 hash operations, a day on a few GPUs, and would
  reveal the key. With 600 000 iterations (OWASP's figure for PBKDF2-SHA-256)
  the same search costs about 10^21 operations, thousands of GPU-years, while
  a phone spends under a second once per chat and page load. HKDF still splits
  the stretched secret into the two values, as briefed. The site's origin is
  part of the salt, so the same code gives other values on another NONOX
  installation and no single precomputed table serves them all; both
  participants are on the same site, so they derive the same values.
- **Messages**: every event, a message `{t: "msg", id, n, body}` or a page
  `{t: "nav", id, n, path, company}`, is JSON encrypted with a fresh 96-bit IV.
  The additional authenticated data is `nonox-chat-v1|<room id>|<sender slot>`,
  so the server cannot move a message to another chat or relabel who wrote
  it without decryption failing.
- **Replay and gaps**: `id` is random per event and `n` counts the sender's
  events from 1 (kept in the sender's `sessionStorage` across page loads).
  The receiver remembers, per chat in `sessionStorage`, the sequence number
  each event id was first seen at: the same event fetched again after a reload
  (same id, same number) is shown, the same event stored again under a new
  number is a replay and is dropped, so a replayed page event cannot move the
  follower after a reload either. A counter that jumps shows "Ett eller flera
  meddelanden saknas här." where the jump is; a counter already seen is
  dropped. (A send that failed on the sender's side also leaves a jump; the
  sender saw the error.)
- The server stores and relays only ciphertext, IV, sender slot (A or B), a
  sequence number and the server time. It never receives the code or the key,
  and cannot tell a message from a page event (only their sizes differ).

### Ephemeral storage only

`App\Support\Chat\SecureChat` keeps everything in the cache store `chat`
(`config/cache.php`): Redis when the server has the extension and Redis is
configured (as in production, where the default cache is files), otherwise a
file store in `storage/framework/cache/chat`, never the database. The
environment variable `NONOX_CHAT_STORE` (`redis`, `file`, `array`) overrides
the choice; tests use `array`.

| Key | Holds |
|---|---|
| `chat:room:{room}` | starter and joiner user ids and display names, created, last activity, last sequence number |
| `chat:msgs:{room}` | the list of `{seq, from, iv, ct, at}` |
| `chat:user:{id}:a` / `:b` | the chat the user started / joined |

Every key's time to live is `min(last activity + 30 min, created + 8 h) − now`,
refreshed when someone joins or sends. **Polling is not activity**, so a chat
nobody writes in is deleted after 30 minutes even while both panels are open.
Reads also check the limits exactly and treat an expired chat as gone; the
keys expire in the store at that same moment. Everything that changes a chat
(start, join, send, end) runs under a lock per chat (`chat:lock:{room}`), so a
send that is running cannot write an ended chat back. A file store does not
expire entries by itself, so `chat:prune` (every five minutes) deletes
expired chat files.

Limits: 500 messages per chat, 4 096 bytes of ciphertext per message (the
browser caps a message at 1 000 characters, at most 3 000 bytes of UTF-8),
IV exactly 12 bytes.

**Deviation**: the brief listed a follow flag in the room metadata. The flag
is not needed by the server (the page events are encrypted and only the
starter's browser sends them), so it is kept in the starter's browser only,
and the server learns less.

### Who can do what

- Every endpoint requires a signed-in session (`auth:web`, Jetstream's session
  guard, `verified`, and the passkey step where it applies) and CSRF.
- The starter's user id is bound to the room at creation. The first other user
  who joins takes slot B. After that, every other user and an unknown room get
  **the same 404 and the same body**: no oracle. The starter who types their
  own code gets 409 "Det här är din egen chatt. Ge koden till den du vill
  chatta med." (2026-11-03, owner's request): they already hold the code, so
  this reveals nothing. A third user cannot poll, send or end it.
- Starting or joining counts against a limit of **10 attempts per 10 minutes
  per user and 60 per IP address** (an office shares one address), with
  Laravel's `RateLimiter` on the chat store. The address is in the key only as
  an HMAC-SHA-256 with the app key. A failed guess leaves only those expiring
  counters. All chat endpoints are also throttled at 240 requests a minute per
  user.
- **One chat as starter and one as joiner at a time**: starting a new chat
  ends the one the user started before, and joining another ends the one they
  had joined, in both cases only once the new one has succeeded (a wrong code
  changes nothing). A user who loses the tab can start again at once instead
  of waiting 30 minutes.
- Signing out ends the user's chats (listener on `Logout`), and the sign-out
  form removes the chat from the tab's `sessionStorage` before it is sent.
- No Policy class: there is no model. The service answers "not a
  participant" exactly like "does not exist", which is the policy.

### Transport

There is no websocket server (`BROADCAST_CONNECTION=log`), so the open panel
polls with `fetch`: every 1.5 s, 5 s after two quiet minutes, 15 s while the
tab is hidden, at once when it becomes visible. The endpoints are technical
JSON under `/chatt/*` (allowed by AGENTS.md §11.4) and all are **POST with the
room id in the body**, so the room id is never in a URL, a web server access
log, the session's "previous URL" or a browser history entry.

| Route | Name | Does |
|---|---|---|
| `POST /chatt/starta` | `chat.start` | open a chat under a room id (409 if taken: the browser makes a new code) |
| `POST /chatt/anslut` | `chat.join` | take slot B |
| `POST /chatt/hamta` | `chat.poll` | state and messages after `since` |
| `POST /chatt/skicka` | `chat.send` | store one ciphertext |
| `POST /chatt/avsluta` | `chat.end` | delete everything |
| `POST /chatt/mina-foretag` | `chat.companies` | the user's own companies, for the follow decision |
| `POST /chatt/kan-folja` | `chat.followable` | whether a path is a page that may be followed (below) |

### Följ mina sidor

The starter's browser, when the box is ticked and someone has joined, sends a
`nav` event with the path and query string (never a host) and the current
company id whenever they change (on every page load, `livewire:navigated`,
and a check on every poll for query strings changed by Livewire).

The follower's browser checks in three steps before anything opens:

1. **In the browser** (`safeFollowPath`, defence in depth): one leading `/`,
   same origin, no backslash or control characters, at most 1 000
   characters, and none of the patterns for files, downloads, callbacks,
   exports, invitations, kiosks and sign-in.
2. **Company**, from its own list (`chat.companies`): member and it is the
   current company → go on; member but another company is current → offer
   **Byt till <företag> och följ**, which submits the normal company switch
   (audited as always) and opens the page after it; not a member → **Du har
   inte tillgång till den sidan**, and stays.
3. **On the server** (`chat.followable`, `App\Support\Chat\FollowablePath`):
   an **allowlist**. The path is resolved with the router as a GET request
   and is followable only when the matched route renders a page: a
   `Route::view` (`ViewController`), a Livewire full-page component, or one
   of the controller actions and closures listed in
   `FollowablePath::SAFE_ROUTES`, each read and verified to render a view
   without changing anything. Every other controller action is denied. A
   deny list applies on top, also to pages: route names for audited reads,
   exports and callbacks (`hq.*`, `privacy.export.*`, the control visit, the
   kiosk, sign-in, and names ending in `.file`, `.pdf`, `.csv`, `.callback`
   and similar), and paths that end in a file or callback (`/fil`, `/pdf`,
   `/lonefil`, `/klar`, a file extension such as `.ics`), contain
   `/bilagor/`, `/underlag/`, `/betalfiler/`, or start with `/registerutdrag`,
   `/profil/mina-uppgifter`, `/bank/`, `/foretagsinbjudningar/` and the like.
   A path that is not followable shows **Den sidan går inte att följa, den
   öppnar en fil eller gör en ändring.** The endpoint writes nothing and logs
   nothing. It does tell the server the path, but that is no new knowledge:
   the follower's request for the page itself would reveal the same path.

The server never learns the company that was shared. The follower can pause
following. Old page events are not followed again after a reload (the
handled sequence number and the seen event ids are kept per chat).

### Interface

A **Chatt** button beside the bell (sidebar, and the dark bar on a phone), on
every signed-in page and in the installed app. A docked panel bottom right on
a desktop, a full-height sheet with safe-area insets on a phone, rendered in
`layouts/app` inside `@persist` and rehydrated from `sessionStorage` after a
full page load. Start view: the code in large type with **Kopiera**, "Läs upp
eller skicka koden. Den fungerar tills någon har anslutit.", **Skicka som sms**
(`sms:?&body=` with the code and plain instructions, no link). Join view: a
code field that formats as you type. Conversation: names (display name only,
from the server), times, Enter sends and Shift+Enter makes a new line, an
`aria-live` region that reads incoming messages, all text through `x-text`
(never HTML), and **Avsluta chatt** with an inline confirmation that says
everything is deleted. On a phone the open sheet is a modal dialog
(`role="dialog"`, `aria-modal`) and the page behind is made `inert`; on a
desktop it is a region beside the page. The **Chatt** button has
`aria-expanded` and `aria-controls`. Focus moves into the panel when it opens
and to its first control when the chat ends. Reduced motion is respected.

The browser keeps the room id, the key, the slot, the sender counter, the
seen event ids and (until someone joins) the code in the tab's
`sessionStorage`. Browsers normally clear it when the tab is closed, but a
browser that restores tabs after a restart can restore it too; then the chat
continues only if it still exists on the server, and the key is useless once
the chat is deleted. Messages are kept in memory only and fetched again after
a reload.

### Nothing remains

- No audit-log entry, no database write, no `Log::` call in the chat code
  (tested: no insert, update or delete query, no audit row, no log call for a
  whole chat).
- `room`, `iv` and `ct` are in the exception handler's `dontFlash`, and the
  browser sends JSON, which is never flashed.
- An exception during a chat request is logged as one line with the exception
  class only (`Encrypted chat request failed.`), never its message, trace or
  request, which could contain a room id. No Telescope, Debugbar, Nightwatch
  or Sentry is installed.

### What unavoidably exists, and for how long

| What | Where | How long |
|---|---|---|
| Ciphertext, IVs, user ids, display names, times | the chat store (Redis memory, or files) | until the chat ends, at most 30 min after the last message or join and at most 8 h; files at most 5 min longer (`chat:prune`) |
| Attempt counters per user id and per HMAC of the IP | the chat store | 10 minutes |
| Request counter per user id (240/min throttle) | the default cache | 1 minute |
| Access-log lines: time, IP, `POST /chatt/hamta` etc., no room id or content | web server (nginx) | the server's log rotation |
| Room id, key, code, counters and seen ids; the messages on screen | the participants' browsers (`sessionStorage`, memory) | until the chat ends, the user signs out or the tab is closed (a restored session can bring `sessionStorage` back) |
| A path asked about with `chat.followable` | nowhere: not written, not logged | the request only |
| Redis persistence (RDB/AOF snapshot) if the server has it on | Redis' data directory | until the next snapshot/rewrite; contains only ciphertext |

## Threat model

- **The server operator** (or anyone with Redis or the server) sees who chats
  with whom, when, how much and for how long, and the room id. They cannot
  read messages or shared pages without the code. Trying every code against a
  room id costs about 2^50 × 600 000 PBKDF2 iterations. They could change,
  replay or hold back ciphertext: a change fails authentication in the
  browser, a replay is dropped (seen event id under a new number), and an
  event held back between two that arrive shows as a gap ("Ett eller flera
  meddelanden saknas här."). Events held back at the end of a conversation,
  or a whole conversation withheld, cannot be told from silence. A malicious
  server could serve modified JavaScript:
  like every web-based end-to-end encryption, the guarantee assumes the
  NONOX code that is served. Self-hosters control that themselves.
- **Someone guessing codes** gets 10 attempts per 10 minutes per user (60 per
  address) against a space of 2^50 (≈10^15) codes, of which only open, not yet
  joined chats count. Guessing is hopeless, and a correct guess is visible:
  the starter sees an unexpected name join and can end the chat.
- **Someone who hears the code** (read out on the phone, an SMS on a shared
  screen) can join first if they are a signed-in NONOX user. The starter sees
  the name of whoever joined. Codes are single use.
- **A participant** can always copy, photograph or screenshot what is shown.
  The system cannot prevent that and does not pretend to.
- **The starter steering the follower**: a shared path is followed only when
  the server's allowlist says it renders a page. It is never followed to
  another host, and never to a download, a callback, an export of personal
  data, an audited read, an invitation, a kiosk or a route that signs out or
  changes the current company without the follower's click. What the page
  shows is still decided by the server for the follower's own account and
  role.
- **XSS in NONOX** would expose `sessionStorage` like every other secret in
  the page; the chat adds no HTML rendering of its own.

## Legal

- Legal basis: legitimate interest (GDPR art. 6.1 f): offering users a
  quick way to talk to support, their auditor or a customer, with the least
  data possible. Recipient: the hosting provider (Vultr, server).
- TODO(legal-verify): whether the operator is controller for this transient
  processing (it is offered to the user, not on a company's behalf) and
  whether the register of processing (art. 30) needs its own row; the row is
  added in `docs/gdpr/registerforteckning.md` section A.
- TODO(legal-verify): whether a chat between users of the service is a
  number-independent interpersonal communications service (NI-ICS) under the
  Swedish Electronic Communications Act (LEK, 2022:482) and the ePrivacy
  Directive. The reviewer's assessment (2026-11-03), not yet confirmed by a
  lawyer: most likely it is a minor ancillary feature intrinsically linked to
  the bookkeeping service (EECC article 2(5) and recital 17), and so not an
  NI-ICS at all; if it were, there is no duty to notify PTS for an NI-ICS, and
  the confidentiality rules (LEK 9 kap.) are met because the content is
  encrypted end to end and nothing is kept.
- TODO(legal-verify): whether keeping nothing at all is compatible with any
  duty to keep traffic data (we keep none, by design) or to assist
  authorities.

## Consequences

- A conversation cannot be recovered, ever, not even for the participants or
  for a dispute. That is the point.
- Without Redis, the file store works but is slower and depends on the
  scheduler for prompt deletion of expired chats.
- When a websocket server is added later, it can replace polling without
  changing the encryption or the storage.
