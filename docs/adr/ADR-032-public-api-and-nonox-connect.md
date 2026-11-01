# ADR-032: Public API and NONOX Connect

- Status: Proposed (waiting for the project owner's approval), 2026-11-01
- Relates to: AGENTS.md §1.2, §1.3, §4, §12, §13; ADR-005 (tenancy), ADR-006
  (audit log), ADR-007 (vouchers), ADR-017 (modules), ADR-024 (archived
  companies), ADR-029 (passkeys)

## Context

The owner's requirement: "API ok, måste stödja allt och med extremt hög
säkerhet." The API must be good enough for unified-API vendors (Chift,
Apideck, Unified.to) and the Swedish iPaaS Sharespine to build connectors
against. They expect OAuth 2.0 authorization code with refresh tokens,
scopes, one company per token, an OpenAPI description, idempotent writes,
documented rate limits and webhooks. Self-hosters and scripts need simple
keys.

Before this, `routes/api.php` was empty, Jetstream's API token feature was
switched off, and `CurrentCompany` had a `TODO(§12)` for API requests.

## Decisions

### A module, off by default

The API is the module `connect` (`app/Modules/Connect`, `ConnectModule`),
following ADR-017. It adds `connect:view` and `connect:manage` (owner and
administrator only; `connect:view` also while archived). Its settings live
in the module (`DEFAULTS`, `ConnectSettingsForm` under Moduler):

- `app_policy`: `all` (every app registered on the installation may ask) or
  `listed` (only the apps in `allowed_clients`);
- `ip_allowlist`: addresses or CIDR ranges that may call the API for the
  company (empty: any).

While the module is off for a company, **every** API call with that
company's credential answers 404, as if the API did not exist. Turning it
off revokes every grant and API key (data is kept; credentials are not
data), with a warning the user must confirm. Turning it on is blocked
while the server has no OAuth signing keys.

### Authentication: Laravel Passport for the protocol, our own rules around it

**Package: `laravel/passport` ^13.10** (MIT), which brings
`league/oauth2-server` 9.4 (MIT), `lcobucci/jwt` and `firebase/php-jwt`
(BSD-3), `defuse/php-encryption`, `league/event`, `phpseclib`,
`php-http/discovery`, `paragonie/random_compat`, `psr/http-server-*` and
`symfony/psr-http-message-bridge` (all MIT). All AGPL-compatible.

Verified from the installed package, not from memory (the Laravel Boost
`search-docs` tool was not available in this session):

- Laravel 13: `composer.json` requires `illuminate/* ^11.35|^12.0|^13.0`.
- PKCE: `league/oauth2-server`'s `AuthCodeGrant` validates
  `code_challenge`/`code_challenge_method` (plain and S256) and checks
  `code_verifier` at the token endpoint; it requires a challenge only for
  public clients. We require S256 for every client (below).
- Refresh token rotation: `Passport::$revokeRefreshTokenAfterUse = true` by
  default; `RefreshTokenGrant` revokes the old access and refresh token
  and issues new ones (read in `RefreshTokenGrant::respondToAccessTokenRequest`).
- Scopes: `Passport::tokensCan()`; refreshed tokens can only narrow scopes.
- Implicit and password grants are off by default in 13.x
  (`Passport::$implicitGrantEnabled`, `$passwordGrantEnabled`); client
  secrets are always hashed (UPGRADE.md, 13.0).

What Passport does **not** do, and we add:

1. **Grant families.** A *grant* (`connect_grants`) is one consent: one
   user let one app into one company with some scopes. Every authorization
   code, access token and refresh token carries `connect_grant_id`. This is
   done by replacing Passport's bridge repositories
   (`ConnectAuthCodeRepository`, `ConnectAccessTokenRepository`,
   `ConnectRefreshTokenRepository`) through the container, with the grant
   id passed in a request-scoped `GrantContext`.
2. **Reuse detection.** When a refresh token that was already used (or an
   authorization code already redeemed) comes back, the whole grant is
   revoked (RFC 9700 §4.14.2, RFC 6749 §4.1.2), the event is written to
   the audit log, and the app must ask again. The token request runs in
   one transaction and the code or refresh token row is locked
   (`SELECT … FOR UPDATE`), so two parallel uses of the same token
   serialise: the second is seen as a reuse. Consequence for app makers:
   never refresh the same token twice concurrently.
3. **Only two grant types.** Our token endpoint (`TokenController`) refuses
   every `grant_type` except `authorization_code` and `refresh_token`
   before Passport sees it (client credentials, password, implicit,
   personal access and device code are all refused). Passport's own routes
   are switched off (`Passport::ignoreRoutes()`).
4. **PKCE S256 for every app.** The authorization endpoint refuses a
   request without `code_challenge` or with `plain`, sending the app back
   with `invalid_request`.
5. **No silent re-approval.** Passport's `AuthorizationController`
   approves without asking when the user already has a token with the same
   scopes. We do not use it: our consent screen always asks.

Access tokens are JWTs signed with RS256 by Passport's keys and live at
most 15 minutes (`NONOX_CONNECT_ACCESS_TOKEN_MINUTES`, capped at 15).
Refresh tokens live 30 days (`NONOX_CONNECT_REFRESH_TOKEN_DAYS`) and are
rotated on every use. Every API request also checks the token row
(`revoked`) and the grant (`revoked_at`), so revocation is immediate, not
after expiry.

Endpoints: authorization `/anslut` (Swedish, user-facing), token
`/oauth/token` (no session, no cookies, no CSRF; 30/min per IP), metadata
`/.well-known/oauth-authorization-server` (RFC 8414). Apps are registered
by whoever runs the installation with `php artisan connect:app` (https
redirect URIs only, http only for localhost); a confidential app's secret
is shown once and stored hashed.

Considered and rejected: an own OAuth server with opaque tokens. It would
have made grant binding simpler, but the protocol details (code binding,
redirect URI matching, PKCE verification, client authentication) are
exactly where hand-written servers go wrong; league/oauth2-server is
widely used and audited. Sanctum alone has no OAuth.

### Consent requires an owner or administrator and a fresh passkey

The consent screen (`ConsentScreen`, Swedish) shows the app, what it asks
for in plain Swedish, and lets the person pick one of the companies where
they hold `connect:manage`, Connect is on, the company is not archived
and the app is allowed. Approving runs `ApproveConnection`, which on the
server:

- checks `connect:manage`, the module, the archive state and the app
  policy again;
- grants only the requested scopes the person holds themselves;
- **consumes a passkey confirmation** (`PasskeyStepUp`) made in the last
  five minutes in this session, by this user, with user verification,
  limited to their own passkeys (reusing ADR-029's ceremony and
  `VerifyPasskeyAssertion`). A signed-in session is not enough: whoever
  has only taken over a mailbox cannot let an app in. A user without a
  passkey is sent to the profile to add one first.

Local development skips the passkey exactly where ADR-029 does
(`PasskeySession::skippedLocally()`), never on a server.

### Effective rights: scopes ∩ the person's current permissions

Scopes are permission keys from AGENTS.md §4 (`vouchers:view`,
`vouchers:create`, `vouchers:post`, `vouchers:correct`, `accounts:manage`,
`reports:view`, `vat:prepare`, `export:sie`, `invoices:view`,
`invoices:create`, `invoices:send`, `invoices:credit`, `payments:create`,
`supplier-invoices:create`, `supplier-invoices:approve`); only those an
endpoint uses are offered.

A route can need several scopes together: `connect.scope:a+b` (all of
them), `a|b` (either). Issuing an invoice, registering a supplier invoice
and registering a payment post a voucher as the person behind the
credential, so those routes need `vouchers:create+vouchers:post` on top of
their own scope. Without it the Action's own voucher policy would refuse
with a generic 403; naming it on the route gives a predictable
`insufficient_scope` with `required_scope_sets` (same shape as
`x-required-scopes` in the OpenAPI file). It applies under kontantmetoden
too, where issuing books nothing: one rule is easier to get right.

On an API request the credential is held in `ConnectAccess` (a scoped
binding), and `User::hasTeamPermission()` asks it first: a permission is
granted only for the credential's company, only when it is one of the
credential's scopes, **and** only when the person has it right now. So the
intersection holds for every Policy and Action, not only for the scope the
route names (`connect.scope` middleware, which also guards endpoints that
otherwise need only membership, such as the chart of accounts). A role
change takes effect on the next request. A person removed from the company
gets 401, and their OAuth grants are revoked for good. An archived company
is read-only (ADR-024): writes answer 403 `company_archived`.

### API keys for scripts (replacing Jetstream's API tokens)

Jetstream's API token feature stays off and no route accepts Sanctum
tokens. Instead, `connect_api_keys`:

- bound to one company; scopes as above; **mandatory expiry** (at most
  `NONOX_CONNECT_MAX_KEY_DAYS`, default 365); optional IP allowlist;
- created only by an owner or administrator, after a fresh passkey
  confirmation (`CreateApiKey`), and only with scopes they hold;
- `nnx_` + 48 random characters, shown once; only the SHA-256 is stored
  (the key has ~285 bits of entropy, so a fast hash is the right choice and
  allows an indexed lookup);
- `last_used_at` and `last_used_ip` tracked; revocable at once;
- the company owner (and the creator) is e-mailed on creation, without the
  key.

The key acts as the person who created it, with the same intersection.

### Request pipeline

`/api/v1` routes do not use the `web` or `api` groups. In order:
`ConnectJson` (strict JSON, request id, `no-store`, no cookies, CSP
`default-src 'none'`), a per-IP throttle, `AuthenticateConnect`
(`Authorization: Bearer` only), `ConnectRateLimit`, `AuditApiWrites`,
`SubstituteBindings` (after authentication, so route model binding is
scoped to the credential's company and another company's id answers 404),
then per route `connect.scope` and `connect.idempotent`. CORS is off:
`config/cors.php` has no paths (Laravel's default answered `*` on `api/*`).

### Errors

RFC 9457 `application/problem+json`: `type` (`urn:nonox:problem:<code>`),
`title`, `status`, `code` (stable, machine-readable), `detail` in Swedish,
`instance` (`urn:nonox:request:<uuid>`, also in `X-Request-Id`), `errors`
per field for validation, `required_scopes` for 403
`insufficient_scope`. A 500 never reveals its cause. The OAuth endpoints
answer in OAuth's own format (RFC 6749 §5.2), which client libraries
expect.

### Pagination and sync

Cursor pagination ordered by `updated_at`, then id; `limit` 1–200
(default 50); `updated_since` (ISO 8601, strictly after). A sync stores
the largest `updated_at` it has seen and asks for everything after it.

### Idempotency

`Idempotency-Key` is required on **every** POST (not only on financial
records: one rule is easier to get right). Per credential, the key, a
SHA-256 of method, path, body and uploaded files, and the response are
kept for 24 hours (`connect_idempotency_keys`, pruned daily by
`connect:prune`). Same key and request: the stored response again with
`Idempotent-Replayed: true`. Same key, different request: 422. Same key
while the first is running: 409. A 5xx is not stored, so it can be retried.
The record is inserted with `INSERT … ON CONFLICT DO NOTHING` before the
request runs, so two parallel requests cannot both run.

### Rate limits

Per credential 120/min, per company 600/min (all apps and keys together),
per IP before authentication 600/min, token endpoint 30/min per IP; all
configurable. Every answer carries `RateLimit-Limit`, `RateLimit-Remaining`,
`RateLimit-Reset`; 429 adds `Retry-After`.

### Audit log

Every write through the API (`api.request`: credential, person, route,
status, Idempotency-Key, replay), on top of what the Actions themselves
log. Every grant created or revoked, every reused refresh token or code,
every API key created or revoked, settings changes (through
ModuleManager). Personal numbers are never returned by the API (customers
show only `has_personal_number`); a later scope for sensitive data would
have to log every read.

### Versioning

`/api/v1`. Within v1 only additive changes (new fields, endpoints, scopes,
enum values documented as open). A breaking change means `/api/v2`, with
v1 kept for at least 12 months. `docs/api/openapi.yaml` is the single
source of truth; a test fails when a route is missing there or a
documented path does not exist.

### Same Actions as the UI

Controllers are thin and call `SaveDraftVoucher`, `PostVoucher`,
`CorrectVoucher`, `DeleteDraftVoucher`, `AttachToVoucher`, `SaveCustomer`,
`SaveSupplier`, `SaveArticle`, `SaveDimensionObject`, `SaveDraftInvoice`,
`IssueInvoice`, `SendInvoice`, `CreditInvoice`, `RegisterPayment`,
`SaveSupplierInvoice`, `DeleteSupplierInvoiceDraft`,
`RegisterSupplierInvoice`, `ApproveSupplierInvoice`, `PaySupplierInvoice`
and `ExportSie`; reports and the VAT return come from `LedgerReports` and
`VatReturn`. The API converts its integer öre and basis points to the
Actions' written amounts exactly (`ApiInput`); JSON numbers with decimals
are refused for quantities and amounts. Segregation of duties on supplier
invoices is the Action's, so it holds for every credential. Posted vouchers stay immutable at all three levels
(ADR-007); the API can only post a draft or correct a posted voucher.

### Webhooks

Per-company subscriptions (`connect_webhooks`) under Anslutningar, added by
an owner or administrator after a fresh passkey confirmation, at most ten
per company, only for events whose data the person may see. The owner is
e-mailed about every new webhook.

- **Events** `voucher.posted`, `invoice.sent`, `invoice.paid`,
  `supplier_invoice.approved` are core domain events
  (`App\Events\*`, `ShouldDispatchAfterCommit`) dispatched by `PostVoucher`,
  `SendInvoice`, `RegisterPayment` (when the invoice becomes paid) and
  `ApproveSupplierInvoice`. Laravel runs their listeners only after the
  outermost transaction commits (verified in
  `Illuminate\Events\Dispatcher::dispatch`), so a rolled-back posting is
  never announced. The Connect module listens; the core does not know about
  webhooks.
- **Thin payloads**: ids, references, dates and amounts, never personal data
  or free text. A receiver fetches more through the API with its own scopes.
- **Who still may receive**: at dispatch the webhook's creator must still be
  a member with `connect:manage` and one of the event's permissions,
  evaluated without the current request's API credential
  (`ConnectAccess::without`). Turning the module off turns every webhook off.
- **Signature**: `Nonox-Signature: t=<unix>,v1=<hex HMAC-SHA256 of
  "t.body">` with a per-webhook secret (`nnxwh_` + 48 characters, shown once,
  stored with Laravel's `encrypted` cast because it is needed to sign; a
  hash would not do). `Nonox-Event-Id` is the same for every subscriber and
  every retry; receivers dedupe on it and reject timestamps more than five
  minutes off. The secret can be rotated (passkey).
- **Delivery**: one row per event and webhook (`connect_webhook_deliveries`,
  the exact body as text so retries send the same bytes). A queued job with
  the company id explicitly claims the row with a conditional update (a
  lease), so two workers never send the same attempt. Eight attempts: at
  once, then after 1, 5, 15 minutes, 1, 3, 6 and 13 hours (about 23 hours).
  Retries are scheduled in the table and queued by `connect:webhooks` every
  minute, not by the queue's own backoff, so they survive lost jobs and work
  with every driver. After the last failure the webhook is turned off, its
  waiting deliveries are cancelled, the event is audited and the owner (and
  creator) get a Swedish e-mail. The log keeps attempts, status code, a short
  reason and duration, never the receiver's answer, and is pruned after 30
  days.
- **SSRF**: https only; no credentials or fragment in the URL; port 443 or
  1024–65535; `localhost`, `.local`, `.internal` refused. The host is
  resolved (A and AAAA) when saving and before every attempt, and every
  address must be public unicast: Symfony's `IpUtils::PRIVATE_SUBNETS`
  (loopback, RFC 1918, link-local, CGNAT 100.64/10, documentation,
  benchmarking, IPv4-mapped, IPv4-compatible, 6to4, Teredo, NAT64, ULA) plus
  multicast, broadcast, 192.0.0.0/24, 192.88.99.0/24, 100::/64, 2001::/23,
  fec0::/10 and ff00::/8. The request is pinned to the checked address
  (`CURLOPT_RESOLVE`, so TLS is still verified against the host name), never
  follows redirects, ignores proxy settings, connects within 5 seconds,
  finishes within 10 and stops reading after 64 kB.

### Token revocation

`POST /oauth/revoke` (RFC 7009) with client authentication as at the token
endpoint. A valid access or refresh token of the app's own revokes the
whole grant (§2.1 allows it, and an app that revokes is usually being
disconnected); the app must ask again. Anything else answers 200 and
changes nothing (§2.2), so the endpoint reveals nothing about other apps'
tokens. Introspection (RFC 7662) is still not offered.

### Test companies

`is_test_company` on `teams`. `php artisan connect:test-company <email>`
or an owner or administrator under Anslutningar (at most three active per
person) creates a company through `CreateCompany`, turns Connect on when the
server has signing keys and seeds invented data through the ordinary
Actions. Its organisation number starts with `00`, a group number no Swedish
organisation has, so it can never block a real company's number. Every page
shows a banner; `SendInvoice`, `SendReminder` and `SendSalesDocument` do
not e-mail for a test company (the invoice is still marked as sent and the
event fires, so integrators can test webhooks); `Company::withoutTestCompanies()`
is the scope for statistics and billing. Otherwise it is an ordinary tenant
under every rule above.

## Deliberately excluded (for now)

- Quotes and orders, recurring invoices, reminders, VAT settlement, period
  locking and SIE import over the API. SIE import is a two-step flow
  (analysis, then a confirmed background job) that also creates accounts;
  exposing it safely needs its own design (upload size, the confirmation
  step as two calls, progress) and is next.
- Token introspection (RFC 7662).
- Client registration by companies or dynamic registration (RFC 7591):
  apps are registered by the operator.
- Client credentials and device grants: refused.
- Mutual TLS or DPoP sender-constrained tokens: considered; short-lived
  tokens plus rotation and reuse detection are the baseline for v1.
- Any endpoint returning personal numbers, payroll or staff-ledger data.

## Consequences

- Self-hosters run `php artisan passport:keys` once (or set
  `PASSPORT_PRIVATE_KEY`/`PASSPORT_PUBLIC_KEY`); the module cannot be
  turned on without keys. Changing the keys invalidates all access tokens.
- Owners and administrators who want to connect apps or create keys need a
  passkey.
- An app that refreshes the same token twice in parallel loses its grant.
- `laravel/passport` is 13.x; a major upgrade must read its UPGRADE.md, and
  the bridge repositories we replace are the place to check first.

## Open questions for the owner

1. Should turning the module off revoke everything (current choice), or
   only suspend it?
2. Is 15 minutes for access tokens and 30 days for refresh tokens right for
   the vendors' sync patterns? Chift/Apideck typically refresh on demand.
3. Should owners be able to restrict which roles' people may approve apps
   beyond owner/administrator?
4. Customer personal numbers: never in the API (current), or a dedicated
   scope with every read logged?
