# ADR-079: Third-party modules (egna moduler)

Status: Proposed
Date: 2026-11-08
Roadmap: `egna-moduler` ("Bygg egna moduler"; comment: security first)
Relates to: ADR-005 (tenancy), ADR-006 (money and audit log), ADR-007 (vouchers and the
ledger chain), ADR-017 (module framework), ADR-029 (passkeys), ADR-032 (public API and
NONOX Connect), ADR-044 (operator company), ADR-063 (MCP server), ADR-064 (NONOX API and
NONOX Connect), AGENTS.md §1.2, §1.3, §1.4, §5, §12, §13

## Context

The roadmap promises "a description and an example module that shows how to build your
own extension to NONOX, the same way as the modules that exist today", with the
condition that we first decide how extensions written by others are kept safe. This ADR
is that decision. It is written before any code, threat model first.

### What exists today

**Module framework (ADR-017).** A module is a class implementing
`App\Modules\Contracts\Module` (key, name, dependencies, permissions, roles,
`rolePermissions()`, `archivePermissions()`, navigation, settings component,
`canEnable`/`canDisable` returning a `ModuleCheck`, `onEnable`/`onDisable`). Optional
contracts `ProvidesTodos` and `ProvidesDeadlines` feed the overview. Each module has its
own service provider, listed in `bootstrap/providers.php`, which:

- registers the module object in the singleton `ModuleRegistry`;
- in `boot()` calls `loadMigrationsFrom(__DIR__.'/database/migrations')` (the same
  `migrations` table and the same database role as the core), `loadViewsFrom`, registers
  routes (with the `module:<key>` middleware, 404 while off), `Livewire::component(...)`,
  `Gate::policy(...)`, event listeners, console commands and rate limiters.

`ModuleManager` keeps per-company state in `company_modules`, audits every change and
enforces "on" in four layers (route middleware, navigation, `ensureEnabled()` in actions,
`companiesWith()`/`isEnabled()` in schedules and jobs). `User::hasTeamPermission()` asks
`ModuleRegistry::moduleForPermission()` whether a permission belongs to a module and then
requires that module to be on.

Two properties of the registry matter here:

- `ModuleRegistry::register()` overwrites an existing key silently. A second provider
  registering `payroll` replaces payroll.
- Nothing stops a module from listing a **core** permission (`vouchers:post`) in
  `permissions()` or in `rolePermissions()`. `moduleForPermission()` would then return
  that module and `hasTeamPermission('vouchers:post')` would start to depend on it being
  on, and on its owner list.

Both are harmless while every module is ours. Neither is acceptable for code we did not
write.

**Public API and NONOX API (ADR-032, ADR-064).** Laravel Passport with our own rules:
authorization code + PKCE S256 only, grant families with refresh-token reuse detection,
15-minute JWT access tokens, consent by an owner or administrator with a fresh passkey,
effective rights = scopes ∩ the person's current permissions (`ConnectAccess`), one
company per credential, idempotency keys, rate limits, audit. API keys for scripts.
Webhooks per company (at most ten, created with a passkey, HMAC-signed, thin payloads,
SSRF-hardened delivery). Today four events: `voucher.posted`, `invoice.sent`,
`invoice.paid`, `supplier_invoice.approved`, plus `sie.uploaded`. **OAuth apps are
registered only by the operator** (`php artisan connect:app`); there is no self-service
registration and no app directory. The MCP server (ADR-063) is a thin layer on the same
credentials. ADR-064 renamed the module to "NONOX API" in user-facing text; the code key
stays `connect`.

**Tenancy (ADR-005).** `BelongsToCompany` + `CompanyScope` fail closed;
`CompanyScope::bypass()` is the sanctioned escape, `withoutGlobalScope(s)` is forbidden
by a test that scans `app/`. Raw queries (`DB::table`, `DB::select`) are not scoped.
`LivewireCompanyGuard` ties components to the current company.

**Immutability (ADR-007, ADR-006).** Posted vouchers and their rows, voucher
attachments, the audit log and about 35 further tables (payroll runs, tax account
postings, VAT reports, year-end closings, agreement rule versions, signing records and
more) are protected by PL/pgSQL triggers that reject UPDATE, DELETE and TRUNCATE. The
voucher chain is `sha256(previous_hash || canonical_json(...))`, verified nightly by
`ledger:verify`.

**Database role.** `config/database.php` uses one connection, and `.env.example` ships
`DB_USERNAME=postgres`. The application, its migrations and every module run as the same
role, which on a default self-hosted install is the PostgreSQL superuser, and on any
install is the owner of every table and trigger function. There is no separate
migration role and no restricted runtime role anywhere in the repository (no `GRANT`,
`REVOKE` or `CREATE ROLE`). The production role on nonox.app has not been checked for
this ADR.

**Hosted vs self-hosted.** `config('app.hosted')` (`NONOX_HOSTED`) marks the service run
by the operator company at nonox.app (ADR-044). Self-hosters run the same AGPL code on
their own servers and are their own operators.

## Threat model

### Who and what we defend against

| Actor | Goal |
|---|---|
| A malicious module author | Read other companies' data, alter the books, steal keys, persist |
| An honest author with a bug | Same effects by accident: a missing `company_id` filter, a raw update |
| A compromised author account or dependency | Ship a malicious update to an honest module (supply chain) |
| A malicious app (out of process) | Get more data or rights than the customer granted; phish users inside NONOX |
| A customer who installs anything | Lose data, or be in breach of BFL or GDPR because of an extension |

### What in-process PHP code can do

A module loaded into the NONOX process is NONOX. It runs with the same PHP user, the
same container, the same database connection and the same secrets. Concretely, any
module code can:

1. **Read every company's data.** `CompanyScope::bypass(fn () => ...)`,
   `DB::table('customers')->get()`, `DB::select(...)` or a plain PDO handle from
   `DB::connection()->getPdo()`. The scan for `withoutGlobalScope` only covers `app/`,
   and it is a convention check, not a barrier.
2. **Decrypt everything encrypted.** `config('app.key')` decrypts every `encrypted` cast:
   personal numbers, webhook secrets, OAuth client secrets for Peppol, bank and
   Skatteverket connections, IMAP passwords.
3. **Read `.env` and key files**: database password, Mailgun key, Passport private key
   (so it can mint valid access tokens for any grant), signing secrets, `APP_KEY`.
4. **Alter or destroy the books, despite the triggers**, because the role that owns the
   tables can `ALTER TABLE vouchers DISABLE TRIGGER ALL`, `DROP TRIGGER`, or
   `CREATE OR REPLACE FUNCTION vouchers_protect_posted()` with an empty body, and a
   superuser can additionally `SET session_replication_role = replica` (which skips
   ordinary triggers). The hash chain does not save us: the hash is unkeyed, so code that
   can write the table can also recompute every hash and the fiscal year's
   `ledger_head_hash`; `ledger:verify` would then report a clean chain. The triggers
   protect against application bugs, not against code running as the table owner.
   (These are PostgreSQL facts from its documentation on `ALTER TABLE`, `CREATE
   FUNCTION` and `session_replication_role`; build step 1 proves each one with a test
   against our schema before we rely on the claim.)
5. **Change behaviour of the core**: rebind any container binding (`Money`, `PostVoucher`,
   the mailer, `CreditData`), register a global middleware, a model observer on
   `Voucher`, an event listener on `UserAccountDeleting`, override a Livewire component
   name, replace a module by key (above), add core permissions to a role.
6. **Call out**: `Http::post()` anywhere, with any data, and receive instructions back.
   Our SSRF guards apply to our webhook sender only.
7. **Persist and hide**: write to `storage/`, schedule commands, queue jobs, add routes
   without the `module:` middleware, write PHP files into a writable directory.
8. **Run at install time**, before any of our code: Composer plugins and scripts, and
   autoloaded `files` entries run on `composer install` and on every request.

Nothing at the application level (policies, scopes, arch tests, middleware) contains
this. Only the operating system, the database role and the network can, and only for the
parts they control.

### Supply chain

- A module is a Composer package with its own dependencies. Each of them is code in the
  same process, with the same powers.
- A version range (`^1.2`) means an update we never reviewed can arrive on the next
  `composer update`. A tag can be moved; a GitHub account can be taken over.
- Review of one version says nothing about the next.

### Licence (AGPL-3.0)

`TODO(legal-verify)` for every point below; this is the common reading, not advice.

- A module loaded into the NONOX process, sharing its classes and data structures, is in
  the FSF's reading part of a combined work with NONOX. If it is **distributed** (put on
  Packagist, sold, handed to a customer), it must be released under terms compatible
  with AGPL-3.0, and whoever conveys it must offer its source.
- AGPL §13: whoever runs a **modified** NONOX (and a module plausibly counts) as a
  network service for others must offer those users the complete corresponding source,
  modules included. A firm running NONOX with a private module for its clients would be
  bound by this.
- A proprietary in-process module is therefore not something we can promise authors.
  An AGPL §7 "additional permission" (a plugin exception, as some projects grant) could
  allow it, but it must be granted by every copyright holder. CONTRIBUTING.md licenses
  contributions under AGPL-3.0 without a contributor agreement, so the exception would
  need every contributor's consent, and it is irreversible for released versions.
- An **out-of-process app** that only talks to NONOX over HTTP is a separate work. It can
  be under any licence, including closed source. This is the same position any program
  calling a public API is in.

### Consequences of the threat model

1. In-process third-party code cannot be made safe by rules in our code. It can only be
   **trusted** (reviewed, pinned, signed) and **contained** by things outside PHP.
2. On the hosted service, a single in-process module would have all of the above over
   **every customer's** books. One bad module, or one bad update, is a breach of every
   company's data and of the operator's processor obligations.
3. A self-hoster who installs a module takes that risk for their own companies only, as
   they already do with every package in `composer.lock`.

## Options

### A. In-process Composer packages, self-hosted only, signed allow-list

Third parties write modules exactly like ours (the ADR-017 contract) and ship them as
Composer packages. Self-hosters install them; the hosted service never does. NONOX
publishes a signed allow-list of reviewed package versions; installs check against it.

| For | Against |
|---|---|
| Full power: own tables, pages, Livewire, policies, schedules, overview todos | All eight powers in the threat model; no containment possible in PHP |
| Same developer experience as our own modules; the roadmap text describes exactly this | Distributed modules must be AGPL-compatible (above) |
| Works offline, no API round trips, no latency | Review cost per version, forever; review cannot prove absence of malice |
| No new infrastructure | Upgrades of NONOX break modules that use internals |
| | Useless for nonox.app customers, who are most users |

### B. Out-of-process apps over the NONOX API, with webhooks and UI extension points

Third parties build apps that run on their own servers. They connect through the
existing OAuth flow (ADR-032) with scoped, company-bound tokens, receive webhooks, and
appear inside NONOX only through declared extension points: links, and later sandboxed
iframes, carrying a short-lived signed context.

| For | Against |
|---|---|
| Every control we already have applies: consent with passkey, scopes ∩ permissions, one company per grant, rate limits, idempotency, audit, revocation | Only what the API exposes; today's API has gaps (quotes, orders, reminders, period locks over the API are excluded in ADR-032) |
| Cannot bypass tenancy, triggers or keys: it never has them | Each extension needs hosting by its author |
| Any licence for the app | Latency and eventual consistency (webhooks, retries) |
| A bad app is revoked in one place, with immediate effect | UI integration is weaker than a native page |
| Works the same on nonox.app and on self-hosted installs | A directory, review process and new events need building |
| Builds on the most hardened code we have | Customers must trust a second processor with their data (GDPR) |

### C. A sandboxed runtime inside NONOX (WebAssembly, a JS isolate, a separate PHP worker)

Run extension code inside NONOX but in a sandbox with an explicit host API (for example
Extism or wasmtime with a capability interface, or a locked-down PHP worker process with
its own restricted DB role).

| For | Against |
|---|---|
| Native-feeling extensions on the hosted service | The host API is a second API to design, secure and version, next to the REST API |
| Containment by construction, if the host API is narrow | PHP has no mature in-process WASM runtime; a sidecar runtime is new infrastructure to operate on every self-hosted install |
| | Resource limits (CPU, memory, time), timeouts and secrets handling are ours to get right |
| | Livewire UI cannot run in the sandbox; UI would still be iframes |
| | Small team, a year of work before the first useful extension |

A separate PHP worker with a restricted DB role is the cheapest variant of C, but a
restricted role already exists in B (the API) without the second interface.

## Decision (proposal for the owner)

**B is the extension model of NONOX, on the hosted service and everywhere else. A is
allowed only on self-hosted installs, as "lokala moduler", with the risk stated plainly
and never listed in the hosted directory. C is out of scope.** In addition, the database
gets a restricted runtime role, which protects the books against bugs and compromised
code in NONOX itself, whatever extension model is used.

The user-facing names: **appar** (B) and **lokala moduler** (A). "Modul" keeps meaning
our own modules under Moduler.

### 1. Apps (option B)

#### 1.1 Registration and the manifest

An app is an OAuth client (ADR-032) described by a manifest, `nonox-app.json`, that the
author submits for review:

```json
{
  "manifest_version": 1,
  "id": "acme.tidrapport",
  "name": "Acme Tidrapport",
  "vendor": { "legal_name": "Acme AB", "org_number": "5560000000", "country": "SE",
              "security_contact": "security@acme.example", "support_url": "https://..." },
  "privacy_policy_url": "https://...",
  "data_processing": { "role": "processor", "dpa_url": "https://...", "locations": ["SE", "EU"],
                       "subprocessors_url": "https://..." },
  "redirect_uris": ["https://app.acme.example/nonox/callback"],
  "scopes": [
    { "scope": "invoices:view", "reason_sv": "Visar fakturerad tid per kund." },
    { "scope": "invoices:create", "reason_sv": "Skapar fakturautkast av godkänd tid." }
  ],
  "events": ["invoice.sent", "invoice.paid"],
  "webhook_url": "https://app.acme.example/nonox/webhooks",
  "extensions": [
    { "point": "customer.actions", "label_sv": "Öppna tidrapporter", "url": "https://app.acme.example/nonox/customer" }
  ],
  "api_version": "v1",
  "min_nonox": "1.0"
}
```

- `id` is `vendor.name`, lower-case ASCII, globally unique, never reused.
- Every scope has a Swedish reason, shown on the consent screen next to the scope.
  Scopes not in the manifest cannot be requested.
- `data_processing.role` is `processor` (the app processes on the customer's behalf) or
  `controller`. `TODO(legal-verify)`: which roles we accept, and what the consent screen
  must say for each.
- The manifest is versioned with the app. A new version that adds scopes, events or
  extension points is a new review, and existing grants keep the old scopes until the
  customer approves the new ones on a fresh consent (refreshed tokens can only narrow,
  ADR-032).

On the hosted service the operator registers reviewed apps (as today with `connect:app`,
extended to read the manifest). A self-hoster can register any app on their own install;
the directory (§3) shows which ones NONOX has reviewed.

#### 1.2 Grants and consent

Unchanged from ADR-032: owner or administrator, fresh passkey, one company, scopes ∩
the person's permissions at every request, immediate revocation. Added:

- The consent screen shows the vendor's legal name and org number, the data location,
  the review status (`Granskad av NONOX <datum>` or `Inte granskad`) and the reason per
  scope.
- An unreviewed app on the hosted service cannot be granted at all (§4).
- A grant can be paused and revoked under Anslutningar; revoking also removes the app's
  webhook subscriptions and extension points for that company.
- The app's user-facing actions in the audit log carry the app id
  (`via: app:acme.tidrapport`), so the behandlingshistorik shows who acted.

#### 1.3 App webhooks

Today webhooks are created by a person per company. For apps, a **grant subscription**:
when a grant is approved, the app's manifest events are subscribed for that company to
the app's registered `webhook_url`, filtered by the granted scopes (an event is only
sent if the grant holds one of the event's permissions, evaluated as today with
`ConnectAccess::without`). Same signature, delivery, retries, SSRF rules and payload
policy (thin, no personal data, no free text) as ADR-032; the secret is per app and
grant. These subscriptions do not count against the ten per company and are listed
under the app, not under Webhooks.

The event catalogue grows as apps need it, each event a core domain event dispatched
after commit, each with a permission. Candidates, in rough order of demand:
`customer.created/updated`, `supplier_invoice.registered`, `payment.registered`,
`period.locked`, `fiscal_year.closed`, `quote.accepted`, `order.created`. Each needs its
own small review (does the payload leak anything?), not this ADR.

#### 1.4 UI extension points

Phase 1: **links only.** Phase 2: **sandboxed iframe panels.** No third-party script is
ever loaded into a NONOX page.

Extension points are named places in the UI, each a stable contract:

| Point | Where | Context |
|---|---|---|
| `nav.app` | An "Appar" section in the sidebar, one entry per granted app | company |
| `customer.actions` | Customer card, action menu | company, customer id |
| `supplier.actions` | Supplier card | company, supplier id |
| `invoice.actions` | Invoice page | company, invoice id |
| `voucher.actions` | Voucher page | company, voucher id |
| `article.actions` | Article page | company, article id |
| `overview.card` (phase 2) | The overview, as a panel | company |

A link opens the app's URL in a new tab with one query parameter, `nonox_context`: a
JWT signed by NONOX (EdDSA or RS256, key separate from Passport's token key, published
at `/.well-known/nonox-app-keys.json`) with:

- `iss` the installation URL, `aud` the app's client id, `iat`, `exp` = 60 seconds,
  `jti` (single use: the app must reject a replayed `jti`);
- `company` and `user` as **pairwise identifiers** (a stable per-app pseudonym, an HMAC
  of the internal id with a per-app secret), never internal ids, e-mail or name;
- `point` and `subject` (`{"type": "customer", "id": "<public id>"}`).

The context **grants nothing**. It tells the app where the user came from; the app
then uses its own access token, which only reaches what the grant allows. An app that
receives a context for a company it has no grant in shows its own "connect first" page.
The pairwise ids prevent two apps from correlating users with each other.

Phase 2 iframe panels:

- `<iframe sandbox="allow-scripts allow-forms allow-same-origin" referrerpolicy="no-referrer">`
  pointing to the app's declared origin. Same-origin here is the **app's** origin, so
  the frame cannot reach NONOX's DOM, cookies or storage. No `allow-top-navigation`, no
  `allow-popups-to-escape-sandbox`, no `allow-modals`.
- The CSP `frame-src` (today `'self' blob:` in `SecurityHeaders`) gains the origins of
  apps granted in the **current company only**, computed per response.
- A fixed frame around the panel says "Innehåll från <app> (<vendor>)". A panel is never
  shown on the sign-in page, the passkey ceremony, the consent screen, payment approval,
  or any page where a code or passkey is entered, so an app cannot fake those prompts
  inside our chrome.
- Messages from the frame (`postMessage`) are checked against the app's origin and
  limited to: `resize`, `toast` (text only, prefixed with the app name), and `navigate`
  to a NONOX route from a fixed allow-list. Nothing that acts on data.

Phase 2 is a separate decision after phase 1 has been used; it is specified here so the
manifest and context format do not change.

#### 1.5 What apps cannot do

- Hold a credential for more than one company per grant.
- Request a scope the consenting person does not hold, or that is not in the manifest.
- Use client credentials, password or device grants (refused, ADR-032).
- Read personal numbers, payroll or staff-ledger data (excluded from the API, ADR-032).
- Post, send, pay or approve without a person, where the API itself requires a person
  (the MCP rules of ADR-063 apply to MCP-based apps).
- Load script into NONOX pages.

### 2. Local modules (option A, self-hosted only)

#### 2.1 Where they may run

- `ModuleRegistry` refuses to register a module that is not first-party while
  `config('app.hosted')` is true. NONOX boots without it and logs an alert. This is a
  guard against mistakes, not against a malicious package, which runs before any guard
  (threat model 8).
- The hosted service installs only the dependencies in our own `composer.lock`, from
  our own repository. That is the real control, and it already holds.

#### 2.2 Registry hardening (needed for our own modules too)

- `register()` refuses a key that is already registered (no silent replacement).
- Third-party keys must be `vendor.name`; first-party keys stay unprefixed. The set of
  first-party keys is a constant in the registry.
- A third-party module's `permissions()`, `roles()`, `rolePermissions()` and
  `archivePermissions()` may only contain strings prefixed with its own key
  (`acme.tidrapport:view`). Core permissions and other modules' permissions are refused
  at registration. Role keys likewise.
- Navigation of a third-party module goes into an "Tillägg" section, after ours.

These are cheap and catch the honest author's mistakes early.

#### 2.3 Install policy

- Installing a local module is an operator act: `composer require` plus
  `php artisan nonox:module:install vendor/name`, which reads `nonox-module.json`, shows
  the declared permissions, tables, routes, outbound hosts and schedules, checks the
  package against the allow-list (§2.4), and adds the provider to a
  `config/nonox-modules.php` list. Laravel's package discovery would load any package's
  provider on its own, and what a third-party `composer.json` declares is not under our
  control. So NONOX's boot compares `bootstrap/cache/packages.php` with the
  installed-module list and refuses to boot (in production) when a discovered provider
  belongs to a package of `"type": "nonox-module"` that is not listed. Again: this
  catches mistakes, not malice.
- The admin page Moduler shows local modules with a visible "Lokal modul från <vendor>,
  inte granskad av NONOX" or "granskad version x.y.z" label. A company owner on a
  self-hosted install sees it before turning it on.
- `composer.json` should pin local modules to an exact version, and `composer.lock` is
  the source of truth. The install command warns on a range.

#### 2.4 Signed allow-list

NONOX publishes `modules.json` (package name, version, git commit hash, the dist
archive's SHA-256, review date, reviewer, review report URL, licence) signed with an
Ed25519 key (`sodium_crypto_sign_verify_detached`, part of PHP's bundled sodium). The
public key ships in the NONOX repository. `php artisan nonox:module:verify` checks every
installed `nonox-module` package in `composer.lock` against it and reports reviewed,
unreviewed and **mismatch** (same version, different commit: treated as compromised).
The nightly integrity job runs it and shows the result next to `ledger:verify`.

The allow-list states what we reviewed. It does not make a module safe, and the install
page says so in Swedish.

#### 2.5 Licence for local modules

Local modules that are distributed must be under AGPL-3.0 or a licence compatible with
it, and the manifest's `license` must be one of: AGPL-3.0-only/-or-later,
GPL-3.0-only/-or-later, LGPL-3.0, MIT, BSD-2/3-Clause, Apache-2.0 (AGENTS.md §1.4). The
install command refuses others. `TODO(legal-verify)`: whether this is the right list for
in-process modules, and the §13 obligation of a firm running NONOX plus a private local
module for its clients.

### 3. Directory and review (certification)

A public directory at `/appar` (Swedish URL) lists reviewed apps, and separately
reviewed local modules for self-hosters. Listing requires review; review is per
version.

**Review of an app (B):**

1. Identity: the vendor is a registered company (org number checked against the company
   lookup of ADR-035), with a named security contact and a disclosure policy.
2. Manifest: each scope has a reason that matches what the app does; least privilege.
   Events and extension points match.
3. Legal: privacy policy, DPA where the app is a processor, sub-processors, data
   location. `TODO(legal-verify)`: the minimum we require (EU/EEA only, or adequacy
   decisions too).
4. Security self-assessment (a short questionnaire: token storage, refresh single-flight,
   webhook signature check, `jti` replay check, encryption at rest, incident response),
   and a test against a NONOX test company (ADR-032) by the reviewer: consent, revocation,
   webhook verification, behaviour on 401/403/429.
5. No review of the app's own source is promised for closed-source apps; the listing
   says what was and was not checked.
6. Re-review on every manifest change, and yearly. A listed app that misbehaves is
   delisted and, on the hosted service, every grant can be revoked at once by the
   operator (a new `connect:app --suspend` that revokes all grants and notifies owners).

**Review of a local module (A):**

1. Source review of the exact commit, with its full dependency tree (`composer.lock` of
   the module's test install). No Composer plugins, no install scripts, no autoloaded
   `files` beyond the module's own helpers.
2. The SDK's arch tests (§5) pass, and the tenancy harness (§5) covers every model.
3. No raw SQL outside migrations; no `CompanyScope::bypass`; no `withoutGlobalScope`;
   no writes to core tables; no reads of `config('app.key')`, `env()` or files outside
   its own storage; outbound HTTP only to declared hosts through a wrapper.
4. Migrations: only `create table` and `alter table` on its own prefixed tables
   (§5), no triggers on or foreign keys from core tables except `company_id` and
   read-only references.
5. Reviewed by two people, one of them not involved in writing it. Result published as
   a short report; the version enters the signed allow-list.

Who does the reviewing, and whether reviews cost money, is an owner question.

### 4. What the hosted service allows

| | nonox.app | Self-hosted |
|---|---|---|
| Our own modules | Yes | Yes |
| Reviewed apps (B) | Yes, after consent | Yes |
| Unreviewed apps (B) | No: the operator does not register them | Yes, the operator's choice |
| Customer-built private apps (B) | `TODO(owner)`: allow API keys and own OAuth apps for a company's own integration, unreviewed, clearly marked? (API keys already exist and are effectively this) | Yes |
| Local modules (A) | **Never** | Yes, operator's choice, labelled |
| Sandboxed code (C) | No | No |

### 5. SDK and sample

Two deliverables, matching the two options. Both live in their own public repositories
under the project, both AGPL-3.0 for the SDK code that touches NONOX internals and MIT
for the app SDK (so closed-source apps can use it). `TODO(legal-verify)`: confirm the
MIT choice for the client SDK does not conflict with anything copied from NONOX (it must
be written from the OpenAPI file, not copied from `app/`).

**5.1 App SDK (B), `nonox/app-sdk` (PHP, Laravel-friendly but framework-free):**

- OAuth: authorization URL with PKCE S256, code exchange, **single-flight refresh** (a
  lock per grant, because a concurrent double refresh revokes the grant, ADR-032), token
  storage interface.
- A typed client generated from `docs/api/openapi.yaml`, with idempotency keys on every
  creating POST, pagination and `Retry-After` handling.
- `WebhookVerifier`: checks `Nonox-Signature` (timestamp within five minutes, constant-time
  compare), dedupes on `Nonox-Event-Id`.
- `ContextVerifier`: checks the `nonox_context` JWT against the installation's key set,
  `aud`, `exp`, and `jti` replay.
- A **sample app** (`nonox/sample-app`): a small Laravel app "Tidrapport till faktura"
  that reads customers, creates invoice drafts (never sends), listens to `invoice.paid`,
  and adds a `customer.actions` link. With a README in Swedish and English and a
  walkthrough against a test company.
- A TypeScript SDK can follow the same spec later; not in this ADR.

**5.2 Module SDK (A), inside the NONOX repository plus a skeleton package:**

- `php artisan nonox:make-module acme/tidrapport` scaffolds a package outside `app/`
  (`packages/acme/tidrapport` during development) with:
  - `nonox-module.json`: key `acme.tidrapport`, name, vendor, licence, permissions,
    roles, table prefix, routes prefix, outbound hosts, schedules, `nonox` version
    constraint;
  - the `Module` class implementing the contract, its service provider using a
    `ThirdPartyModuleServiceProvider` base that registers views, routes (under
    `/tillagg/<vendor>-<name>/...` with `module:acme.tidrapport` and `auth`),
    migrations and Livewire components **only** through methods that enforce the prefixes;
  - one model with `BelongsToCompany`, a policy, a Livewire page calling one Action;
  - migrations whose tables are prefixed `x_<vendor>_<name>_` (`x_acme_tidrapport_entries`),
    each with `company_id` constrained to `teams`;
  - `lang/en.json` for its strings, Swedish in the source;
  - Pest tests: the action's happy path and validation, the policy per role, and the
    **tenancy harness** (§5.3) for every model.
- **Tenancy harness for modules:** the harness of `tests/Tenancy/TenancyIsolationTest.php`
  is extracted into a reusable dataset/trait (`AssertsCompanyIsolation`) so a module's
  test suite registers its models the same way ours do; a model left out fails the
  suite (the same guard ADR-005 has, scanning the module's `src/Models`).
- **Arch tests** shipped with the SDK (Pest `arch()`), required for listing: no
  `DB::statement/unprepared/select/table` outside migrations, no `CompanyScope::bypass`,
  no `withoutGlobalScope`, no `env()`, no `config('app.key')`, no `Http::` outside the
  declared-hosts wrapper, no classes from `App\` except a published list of stable
  contracts (§6), no writes through core models. These catch honest mistakes; they are
  trivially evaded by a determined author, which is why review exists.
- A **sample local module** in the skeleton: the same "Tidrapport" idea natively, to
  show the difference.
- A manual section, `docs/manual/sv/utvecklare/` (`/manual/utvecklare`), linked from the
  developer portal: apps first, local modules second, with the threat model in plain
  Swedish.

### 6. Database protections that hold against in-process code

What the triggers guarantee today: no UPDATE/DELETE/TRUNCATE on protected rows **by any
statement run by a role that cannot alter the tables or their trigger functions**.
Because NONOX runs as the owner (and by default as superuser), that condition is false.
The fix is roles, and it protects against bugs and compromise in NONOX itself, not only
in modules. Specification:

1. **Three roles.**
   - `nonox_owner`: owns the schema, every table and every trigger function. `NOLOGIN`
     for daily use; used only by `php artisan migrate` through a separate connection
     (`DB_MIGRATION_USERNAME`/`PASSWORD`, a `pgsql_migrate` connection, and
     `migrate --database=pgsql_migrate`).
   - `nonox_app`: the runtime role. Not superuser, not owner, no `CREATE` on the schema,
     no `TRIGGER` privilege, no `REFERENCES` beyond what is needed. `SELECT, INSERT,
     UPDATE, DELETE` granted per table by migrations; `TRUNCATE` never. It cannot
     `ALTER TABLE`, `DROP TRIGGER`, replace a function it does not own, or set
     `session_replication_role` (superuser only, or an explicit `GRANT SET ON PARAMETER`
     we never give).
   - `nonox_readonly`: `SELECT` only, for reports, backups and support.
2. **Append-only tables lose UPDATE/DELETE where possible.** `audit_logs`: `nonox_app`
   gets `SELECT, INSERT` only. Tables that need the one allowed update
   (`vouchers.corrected_by_voucher_id`, drafts that become posted) keep `UPDATE` and the
   trigger remains the guard; column-level grants (`GRANT UPDATE (col, ...)`) are used
   where a table's mutable columns are a short list.
3. **Default privileges**: `ALTER DEFAULT PRIVILEGES FOR ROLE nonox_owner` so new tables
   get the grants from a single, reviewed migration helper, and a test fails when a
   table has no explicit grant decision.
4. **Keyed or anchored ledger chain** (optional, owner decision): the chain head
   per fiscal year is periodically signed with a key the runtime does not hold, or
   exported to an append-only external anchor (an e-mail to the owner, the archive
   package of ADR-024), so a role that could rewrite rows still could not rewrite history
   undetected.
5. **Defaults.** `.env.example` stops suggesting `postgres`; the README's install guide
   creates the roles with a script (`deploy/postgres/roles.sql`); `php artisan
   nonox:doctor` warns when the runtime role is a superuser or owns protected tables. The
   hosted service moves first. Existing self-hosted installs keep working on one role,
   with the warning.

What this still does not stop, stated plainly: in-process code holding `nonox_app`
credentials can still `INSERT` posted-looking data through the normal path, read every
company's rows (row-level security is not part of this proposal; see Out of scope),
decrypt with `APP_KEY`, and call out. Roles protect **history**, not **confidentiality**.

### 7. Versioning and compatibility

- **Apps** depend on `/api/v1` (ADR-032: additive only within v1, at least twelve months
  of overlap for v2), the event catalogue (events are additive; a payload field is never
  removed within a major version), the manifest format (`manifest_version`), the context
  JWT format and the extension point names. Each is documented in the developer portal
  with a changelog; deprecations are announced in the API changelog and e-mailed to
  listed vendors' contacts at least six months ahead.
- **Local modules** may only use a published list of stable contracts:
  `App\Modules\Contracts\*`, `BelongsToCompany`, `CurrentCompany`, `Money`/`MoneyCast`,
  `Audit::record`, `ModuleManager::isEnabled/ensureEnabled`, the layout components and
  the core domain events. Everything else in `app/` is internal and may change in any
  release. The stable list follows semantic versioning of NONOX: breaking it needs a
  major version. The manifest's `nonox` constraint is checked at install and at boot; a
  module that does not match is not loaded (the rest of NONOX boots).
- NONOX itself gets semantic version numbers from 1.0 for this purpose (today's tags are
  `v0.9.<count>`). `TODO(owner)`.

### 8. Out of scope

- Option C (sandboxed runtime), until B has shown what extensions people build.
- A marketplace with payments, revenue share or billing through NONOX.
- Self-service app registration by third parties; operator registration stays, with the
  review workflow added.
- Dynamic client registration (RFC 7591), client credentials (unchanged from ADR-032).
- Row-level security in PostgreSQL per company. It would contain tenancy at the database
  level and is worth its own ADR: it needs `SET app.company_id` per transaction, every
  cross-company job (schedules, operator views) re-designed, and it does nothing for
  `APP_KEY`.
- Extension points that act on the books from inside NONOX pages (an app button that
  posts). Apps act through the API, where a person and the policies are in the path.
- Third-party code that changes core screens, menus other than declared points, or the
  voucher entry grid.
- Payroll, personal numbers and staff-ledger data for apps.

## Build steps (tests first)

Each step is its own branch and PR, in this order. Steps 1–3 are worth doing even if
third-party extensions are never launched.

1. **Prove the threat model.** Tests (in a throwaway `nonox_test_roles` database) that,
   running as today's role, `ALTER TABLE vouchers DISABLE TRIGGER`, `DROP TRIGGER`,
   `CREATE OR REPLACE FUNCTION vouchers_protect_posted()` and (as superuser)
   `session_replication_role` each allow changing a posted voucher, and that recomputed
   hashes pass `ledger:verify`. These tests document the current gap; they are marked
   to flip in step 2.
2. **Database roles (§6).** Migration connection, role script, grants helper, the test
   from step 1 flipped (each attempt fails as `nonox_app`), a test that every table has a
   grant decision, `nonox:doctor`, README and systemdokumentation update (integrity
   controls). Hosted service switches; self-host guide updated.
3. **Registry hardening (§2.2).** Failing tests first: duplicate key refused, a module
   listing `vouchers:post` refused, a third-party role named `owner` refused; then the
   code. The dummy module in `tests/Support/Modules` gets a third-party twin.
4. **App manifest and directory data.** Manifest schema + validator (JSON Schema, tests
   with valid and invalid manifests), `connect:app --manifest`, the consent screen
   showing vendor, review status and reasons per scope (feature tests, Swedish strings,
   `lang/en.json`).
5. **Grant subscriptions (§1.3).** Tests: approving a grant subscribes the manifest's
   events, an event is not sent without a matching scope, revoking a grant stops
   delivery, tenancy (another company's event never reaches the grant).
6. **Link extension points and the context token (§1.4 phase 1).** Key set endpoint,
   pairwise ids, signer, the points on customer, supplier, invoice, voucher and article
   pages. Tests: expiry, audience, pairwise ids differ per app, no internal id or
   personal data in the token, links only for apps granted in the current company.
7. **App SDK and sample app (§5.1)** in their own repositories, tested against a test
   company in CI.
8. **Module SDK (§5.2)**: extract the tenancy harness, the base provider, the scaffold
   command, the arch tests, the sample local module; then `nonox:module:install` and
   `nonox:module:verify` with the signed allow-list (tests with a valid signature, a
   wrong signature and a commit mismatch).
9. **Directory page `/appar`**, the manual section, the developer portal, the changelog,
   the roadmap item, landing page and comparison data as AGENTS/QUICKSTART §5 requires.
10. **Iframe panels (§1.4 phase 2)**: only after an owner decision on phase 1's
    experience. Tests for the CSP per company, the sandbox attributes, the blocked pages
    and the `postMessage` allow-list.

## Consequences

- nonox.app never runs third-party code. Every extension there is an app with a grant
  the customer can see and revoke.
- The API becomes the product surface for extensions; gaps in it (ADR-032's exclusions)
  now block apps and get priority by demand.
- Self-hosters keep full freedom, with honest labels and a way to verify what they
  installed.
- The database role change protects the books against our own bugs and a compromised
  dependency, which matters more than modules do.
- Review is an ongoing cost. Without people to review, the directory stays small; that
  is preferable to an unreviewed one.
- The roadmap text ("same way as the modules that exist today") is honoured for
  self-hosters by local modules; for nonox.app customers it becomes "apps", and the
  roadmap entry should be reworded when this ADR is accepted.

## Owner questions

1. **Option choice.** B everywhere, A only self-hosted, C out of scope: agreed?
2. **Local modules at all?** Should NONOX support option A officially (SDK, allow-list,
   review), or only document that the code is AGPL and leave self-hosters to it? A is a
   permanent review cost for a minority of installs.
3. **Private apps on nonox.app.** May a customer register its own OAuth app for its own
   integration without review (API keys already allow scripts), and how is that shown?
4. **Who reviews, and does it cost?** In-house, an external security firm, or both; free
   listing or a fee per review.
5. **Data location for listed apps**: EU/EEA only, or also countries with an adequacy
   decision? `TODO(legal-verify)`.
6. **AGPL plugin exception.** Do we want an AGPL §7 additional permission for local
   modules (so they could be closed source)? It needs every contributor's consent and
   cannot be withdrawn for released versions. Recommendation: no.
7. **Database roles on the hosted service.** Approve the switch to `nonox_owner` /
   `nonox_app` / `nonox_readonly` on nonox.app (needs a maintenance window and a check
   of how the current production role was created).
8. **Anchoring the ledger chain** (§6.4): worth it, and to where (owner e-mail, archive
   package, an external timestamping service)?
9. **Semantic versioning from 1.0**, so local modules and app vendors can state
   compatibility.
10. **Names**: "Appar" and "Lokala moduler" in the UI, `/appar` as the directory URL.

## Addendum 2026-11-08: what starts before the owner decides

Decided by Lead 2026-11-08 under the owner's mandate, open for the consultant's review:
build steps 1 (prove the threat model with tests) and 3 (registry hardening) start now.
Both protect the books whether or not third-party extensions ever launch, and neither
changes the hosted service's database. Step 2 (database roles) waits for owner question 7,
because the hosted service needs a maintenance window and a check of the production
role. Steps 4–10 wait for owner questions 1–4 and 10.

## Addendum: steps 1 and 3 as built

Branch `feat/module-registry-hardening`. Neither step changes roles, grants or triggers.

### Step 1: the threat model, proven

`tests/Feature/Security/TriggerBypassGapTest.php`, Pest group `known-gap`. Each test runs
inside the test transaction (`DatabaseTransactions`); PostgreSQL DDL is transactional, so
every `ALTER TABLE`, `DROP TRIGGER` and `CREATE OR REPLACE FUNCTION` is rolled back with
it. Run against a local test database as the developer's role, which owns the tables and
is a superuser, like a default self-hosted install.

| Scenario (running as today's role) | Result |
|---|---|
| The role owns `vouchers` (or is a superuser): the precondition | confirmed |
| `ALTER TABLE vouchers DISABLE TRIGGER vouchers_protect_posted` (and the rows' trigger), then change a posted voucher's amounts and description | gap confirmed |
| `DROP TRIGGER` on both tables, then change one posted voucher and delete another | gap confirmed |
| `CREATE OR REPLACE FUNCTION vouchers_protect_posted()` (and the rows' function) with an empty body; the triggers stay enabled but no longer refuse | gap confirmed |
| Change a posted voucher, recompute `previous_hash`/`hash` along the chain and `fiscal_years.ledger_head_hash`; `LedgerVerifier` and `ledger:verify` report a clean ledger | gap confirmed |
| `SET session_replication_role = replica` | not run: superuser only; skipped with the reason. It rests on the PostgreSQL documentation |

After each test, `TriggerBypassGapTest` reads the catalog on a second connection, which
sees only committed state, and compares it with `Tests\Support\LedgerTriggers`: each of the
five ledger triggers by `pg_get_triggerdef()` (function, events, timing, row or statement
level) and enabled, and the md5 of the four trigger functions' bodies (both protect
functions, the balance check and the truncate guard). `LedgerTriggersIntactTest` (not in
`known-gap`) checks the same once more, on both connections. The DDL takes ACCESS
EXCLUSIVE locks on `vouchers` and `voucher_rows` until the test's rollback, so the file is
skipped under parallel testing; the suite runs sequentially. Step 2 flips the `known-gap`
tests: each attempt must fail as `nonox_app`.

### Step 3: registry hardening

`ModuleRegistry::register()` throws `App\Modules\InvalidModule` when:

- the key is already registered (it used to replace the module silently);
- the module is not first-party and its key is not `vendor.name` (lowercase letters,
  digits and hyphens, one dot, `_` allowed in the name, no trailing newline, at most 50
  characters, the length of `company_modules.module_key`);
- a permission is a core permission (`CompanyRole::PERMISSIONS`) or another module's;
- a role key is a core role (`owner` and the member roles of AGENTS.md §4) or another
  module's;
- `rolePermissions()` or `archivePermissions()` list a permission the module does not add
  itself (so also `*`, which Jetstream treats as every permission);
- for a module that is not first-party: a permission or role key does not start with
  `<key>:` (`acme.tidrapport:view`, role `acme.tidrapport:admin`), or `rolePermissions()`
  names a role that is neither a core role nor its own.

Our own modules follow every rule except the last two, which are for third-party modules
only: the agreements, planning and staff ledger modules give their permissions to other
modules' roles (`payroll_admin`, `employee`, `scheduler`), and our permission names (`self:payslips`,
`employees:manage`) predate the prefix rule.

First-party means both: a key in `ModuleRegistry::FIRST_PARTY` and a class in
`App\Modules\`. A module with a first-party key whose class lives elsewhere is treated as
third-party, so its key is refused.

**Rule: a module registers in its service provider's `register()` method**, as all twelve
first-party modules do. `register()` checks the key at once; the rest needs `roles()`,
whose labels are translated, and translations are not available while providers register.
So `ModuleServiceProvider::checkModules()` runs in a `booting` callback, which fires after
every provider has registered and before any provider boots. It closes the registry, so a
module registered later (in a `boot()` method or at runtime) is refused with "registers too
late", and then `ModuleRegistry::verify()` checks every module against the ones registered
before it. A refused module therefore stops the boot before its own routes, listeners or
role grants exist. `JetstreamServiceProvider::configurePermissions()` also calls `verify()`
first, and any read of the lists checks unchecked modules first. A refused module is
removed and the error stops the boot: a mistake is found on the first request, never
silently in production.

The check translates the role labels, which loads and caches the JSON translations before
any provider has booted. A package that adds its own JSON strings in `boot()`
(`loadJsonTranslationsFrom()`) would then never see them, so `checkModules()` empties the
translator's cache afterwards (`setLoaded([])`), and a test proves a provider booting later
gets its strings. Changing the `Module` contract so role keys can be read without labels
would avoid the translation entirely; it was left for step 8 because it touches every module.

The lists a module had when it was checked (permissions, role keys, role permissions,
archive permissions) are kept by the registry and served from it
(`permissionsOf()`, `roleKeysOf()`, `rolePermissionsOf()`, `archivePermissionsOf()`;
`User::hasTeamPermission`, the Jetstream roles, firm memberships and the company overview
use them), so a module cannot return something else after the check. Role labels are not
part of the check: `JetstreamServiceProvider` reads them once at boot and registers the
roles with them, so they are fixed in the locale the application boots in, not translated
per request.

Tests: `tests/Feature/Modules/ModuleRegistryHardeningTest.php`;
`tests/Support/Modules/ThirdPartyDummyModule.php` is the third-party twin of the dummy
module, and `TestRegistry` builds a registry that treats the test keys (`dummy`, `extra`)
as first-party. Not built yet from §2.1–2.2: the "Tillägg" navigation section and the
hosted-service refusal of modules that are not first-party; both belong with step 8.

## Addendum 2026-11-08: step 2 as built (database roles)

Owner decision 2026-11-08 (owner question 7): separate users, so that no migrations and no
SQL that changes the schema can be run by the code that runs. Branch `feat/database-roles`,
on top of steps 1 and 3.

### What was built

- **Roles** (`database/roles/roles.sql`, idempotent, roles and privileges only, never data,
  never LOGIN or a password): `nonox_owner`, `nonox_app`, `nonox_readonly`, each
  `NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS`. Only the owner has `CREATE`
  on the schema (also revoked from `PUBLIC`); `TRUNCATE`, `REFERENCES` and `TRIGGER` are
  revoked from the other two; sequences `USAGE, SELECT` for the app, `SELECT` for the reader;
  default privileges for objects the owner creates later (sequences and reader `SELECT`, never
  app table privileges). The script refuses to run while the app or the reader is a member
  of the owner.
- **Ownership** (`database/roles/transfer-ownership.sql`): moves every table, view, sequence,
  function and type in the schema that is not an extension member to `nonox_owner`. The
  runbook uses `REASSIGN OWNED` when the old user is dedicated to NONOX, this script otherwise.
- **Grant decisions** (`App\Support\Database\DatabaseGrants::TABLES`, enum `GrantDecision`):
  one decision per table, `ReadWrite`, `NoDelete`, `AppendOnly`, `AppendOnlyLockable`,
  `AppendAndPrune` or `ReadOnly`. Every table whose trigger function can only raise (no
  `RETURN`) on UPDATE and DELETE is append-only, so the refusal holds by grant as well as by
  trigger (22 tables, plus `vat_number_checks`, whose one allowed update comes from its
  foreign key's `ON DELETE SET NULL`, which PostgreSQL runs as the table owner); `audit_logs`
  and `system_documentation_versions` likewise. `vat_reports` is `AppendOnlyLockable`:
  `SettleVat` locks the period's reports with `SELECT … FOR UPDATE`, which PostgreSQL allows
  only with an UPDATE privilege, so the app gets `UPDATE (id)` alone (an UPDATE of any other
  column is refused by the grant, of `id` by the trigger). `staff_ledger_entries` and
  `crm_signing_events` are `AppendAndPrune` (insert and delete, never update);
  `migrations` is `ReadOnly`. `NoDelete` (12 tables, e.g. `tax_account_postings`,
  `payroll_rule_versions`, `supplier_payment_batches`) where the full test suite deleted no
  row (PostgreSQL's `n_tup_del` after a full run) and no code path deletes (grep of the
  models, `DB::table` and raw SQL). Every other table is `ReadWrite`: tables whose trigger
  guards posted or issued rows (vouchers, invoices, supplier invoices, payroll) keep the
  privileges drafts need, and the trigger stays the guard. Column-level grants were not
  used beyond `vat_reports`: Eloquent writes `updated_at` and every dirty column, and a
  column list per table would break on the next migration that adds a column.
- **Grants follow new tables.** `DatabaseGrants::sql()` is one DO block that revokes and
  re-grants every decided table and gives an undecided table nothing for the app (fail
  closed, with a warning). It runs as the owner after every migrate (`MigrationsEnded` and
  `NoPendingMigrations` listeners in `AppServiceProvider`, on the `pgsql_owner` connection),
  from `php artisan nonox:db-grants`, or with psql from `database/roles/grants.sql`, which is
  generated from the class (`--write`) and tested to be identical.
- **Connections.** `config/database.php` has `pgsql_owner` (`DB_MIGRATION_USERNAME`,
  `DB_MIGRATION_PASSWORD`, `DB_MIGRATION_URL`). The ADR text above said `pgsql_migrate`; the
  build uses `pgsql_owner`, the name in the owner's brief. The roles are **on** exactly when
  `DB_MIGRATION_USERNAME` is set; without it `pgsql_owner` is the same user as `pgsql` and
  nothing changes (development, CI, existing self-hosted installs).
- **How migrations get the owner** (from the installed Laravel 13.39 source, not the docs):
  `MigrateCommand::runMigrations()` calls `Migrator::usingConnection($this->option('database'))`,
  which sets both the migration repository's source (the `migrations` table is read and
  written on that connection) and the resolver's **default** connection for the duration, so
  `Schema::` and `DB::` inside every migration run as the owner without changes to the
  migrations; `runMigration()` does the same per migration (`setDefaultConnection`), and
  `MigrationsEnded` fires inside the callback. No migration in the repository sets its own
  `$connection`.
- **Guard.** With the roles on, `migrate`, `migrate:fresh|install|refresh|reset|rollback` and
  `db:wipe` stop with a Swedish message unless `--database=pgsql_owner` is given (a
  `CommandStarting` listener; Laravel reroutes Symfony's console events to it outside unit
  tests, and tests opt in with `WithConsoleEvents`). The local "Uppdatera databasen nu"
  button and the setup page pass/show the option.
- **`php artisan nonox:doctor`** (read-only, catalogue queries only): warns when the roles are
  off, when the runtime role is a superuser, owns tables or trigger functions (directly or by
  membership), has `TRIGGER` or `TRUNCATE` on a table, has `CREATE` on the schema, when a
  table has no decision, or when a table's privileges differ from its decision. Exit 1 on any
  warning.

### Tests

- `tests/Feature/Security/DatabaseRolesTest.php` (group `database-roles`): the step 1 attacks
  as `nonox_app`, each refused: `ALTER TABLE … DISABLE TRIGGER` (one and `ALL`), `DROP
  TRIGGER`, `CREATE OR REPLACE FUNCTION` of both protect functions, direct UPDATE/DELETE of a
  posted voucher and its rows, rewriting the hash chain (`ledger:verify` stays clean and
  unchanged), `TRUNCATE`, `ADD COLUMN`, `OWNER TO`, `DROP TABLE`, `CREATE TABLE`, `CREATE
  TRIGGER`, `SET session_replication_role` (now testable: the role is not a superuser), and
  UPDATE/DELETE of `audit_logs` refused by grant. Posting, the audit log and the hash chain
  work as `nonox_app`. The reader can read and not write.
- `tests/Feature/Security/DatabaseGrantsTest.php`: every table has a decision and no decision
  names a missing table; trigger-refused tables are append-only; `grants.sql` is current; the
  scripts change no data; the roles script is idempotent and the roles have no attributes;
  every table's actual privileges equal its decision; an undecided table fails closed; the
  listener re-applies the grants after `MigrationsEnded` and `NoPendingMigrations` and does
  nothing with the roles off; the guard refuses `migrate` without the owner connection; the
  config turns the roles on only with `DB_MIGRATION_USERNAME`.
- `tests/Feature/Security/DoctorCommandTest.php`: single-user warnings, a clean report as
  `nonox_app`, the warnings for extra privileges and for membership of the owner, read-only.
- How they run: with a superuser test user (developers, CI) the harness
  (`tests/Support/DatabaseRolesHarness.php`) runs the real scripts **inside the test
  transaction** (role creation, ownership and grants are transactional in PostgreSQL) and
  continues with `SET LOCAL ROLE nonox_app`; everything is rolled back. Against a database
  switched over to the roles, the suite can also log in as `nonox_app` and the same tests run
  unchanged. `TriggerBypassGapTest` stays in group `known-gap` for the single-user mode and is
  skipped when the test user cannot alter the ledger.

### Run against a database switched over to the roles

`nonox_test_roles` was switched over with the real scripts (`roles.sql`,
`transfer-ownership.sql`, `migrate --database=pgsql_owner`, which applied the grants), and
the whole suite was run logged in as `nonox_app`. `migrate` without the owner connection
stopped with the guard's message (exit 1); with it, "Nothing to migrate" and the grants;
`nonox:doctor` reported OK as `nonox_app` and four warnings as the developer's superuser.
That run found the one real gap the per-table analysis had missed: `SELECT … FOR UPDATE` on
`vat_reports` (fixed with `AppendOnlyLockable`, above). Nine tests asserted the trigger's
message where the grant now refuses first; they accept either refusal now. What still fails
as `nonox_app`, by design, is test code that changes the schema: the tenancy harness creates
its fixture table (`TenantNote`, every `TenancyIsolationTest` case), `LedgerVerifyTest`
disables the triggers to simulate tampering, the staff-ledger chain tests alter
`staff_ledger_entries`, and `FrameworkPerFiscalYearTest` drops a check constraint. The suite
is therefore run as a superuser in development and CI, where the roles tests create the
roles inside the test transaction.

### Not done, and open

- The hosted service has not been switched. `docs/drift/databasroller.md` is the runbook
  (expected window 30 minutes, database changes under a minute, rollback with `REASSIGN
  OWNED BY nonox_owner TO <old user>` and the saved `.env`). Before it: a read-only check of
  how the production role was created (step 1 of the runbook).
- `.env.example` still says `DB_USERNAME=postgres` (§6.5 wanted that changed): CI copies the
  file and the README's install steps set the user, so it is left with a commented roles
  block instead. The script lives in `database/roles/`, not `deploy/postgres/`.
- `NoDelete` rests on what the suite and a grep found. A delete path that neither covers
  would fail with `permission denied` after the switch; the runbook says to watch the log
  for it and how to add the privilege or roll back.
- Anchoring the hash chain outside the database (§6.4) is not part of this step.
