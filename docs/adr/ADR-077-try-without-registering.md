# ADR-077: Try without registering

Status: Accepted (Lead, under the owner's mandate; open for review)
Date: 2026-11-08
Relates to: ADR-005 (tenancy), ADR-007 (vouchers and the ledger chain), ADR-017 (module framework),
ADR-032 (test companies and NONOX API), ADR-038 (waiting list and /hq), ADR-044 (operator company and
instance settings), AGENTS.md §1.2, §1.3, §1.4

## Context

The roadmap item `prova-utan-att-registrera` asks for a demo company that anyone can open and click
around in, without an account. Today a visitor to the public pages can read about NONOX, join the
queue (ADR-038) or, where self-registration is open, sign in with an e-mail code. None of these lets
a person see a year of bookkeeping, invoices and payroll before deciding.

What exists:

- `DemoCompanyBuilder` and the `Demo/Extras` build a synthetic company per industry through the
  ordinary Actions: about a year of vouchers, invoices, supplier invoices, VAT returns, salaries and
  staff. Measured on a development machine, a cafe takes about 21 000 queries and 27 seconds at
  15-33 ms per query (Herd on macOS), so on a Linux server about 5-10 seconds.
- Test companies (`teams.is_test_company`, ADR-032): invented data, marked in every page, never
  e-mailed, left out of statistics, billing and the operator's customer register.
- `MailQuota::take` is the one place every Action that sends e-mail through NONOX passes
  (invoices, quotes, reminders, invitations, inbox replies, CRM mailings and signing links).
- `ModuleManager::isEnabled` is the one place a module is switched on or off.
- `InstanceSettings` (ADR-044) holds installation-wide switches edited in /hq.
- The posted-voucher rules (AGENTS.md §1.2) are enforced by PostgreSQL triggers that reject every
  DELETE of ledger rows, and 33 trigger functions do the same for invoices, payroll, stock and so on.

What is missing: a way to hand a stranger their own company safely, a limit on how much a stranger
can make the server do, a rule that nothing leaves the building from it, and a way to remove it
again without weakening the immutability rules for real companies.

## Decision

Decided by Lead 2026-11-08 under the owner's mandate. Open for the consultant's and the lawyer's review.

### 1. One demo company and one guest per visitor

A visitor presses "Starta demon" on `/prova`, chooses an industry (the six single-company demos in
`DemoIndustries`; the two accounting-firm demos need several companies and are out of scope) and gets:

- a **guest user**: name "Gäst", a placeholder address `gast-<random>@demo.invalid` (the `.invalid`
  top-level domain is reserved by RFC 6761 and never resolves; the column is not nullable), no password
  (NONOX has none), `email_verified_at` set so the usual middleware lets it through;
- a **demo company** owned by the guest: a fresh copy of the industry demo built by the same code as
  the developer seeders, with its own unique organisation number, flagged `is_test_company` (so every
  rule for test companies applies), in the demo database (addendum).

The two rows are made in **one database transaction** in the demo database. A build that fails leaves nothing behind. The
build runs as a queued job (`BuildDemoCompany`) because it takes seconds; the visitor waits on a page
that refreshes itself, and the browser is signed in as the guest only when the job has finished and
only from the session that asked for it. On an installation with the `sync` queue the job runs inside
the request.

Companies are separate tenants (ADR-005); nothing in the demo can read or write another company.

### 2. Abuse limits, without a third-party CAPTCHA

- **Honeypot**: a field real visitors never see or fill in.
- **Time check**: the form carries an encrypted timestamp; a form submitted faster than three seconds
  or older than an hour is refused.
- **Per-IP limit**: three starts per IP and hour, and a separate counter for refused attempts. Counters
  live in the cache under a key made from a keyed hash of the IP address, with a one-hour life.
- **Global ceiling**: at most `NONOX_DEMO_MAX_LIVE` (default 40) demo companies live or being built at
  once. Beyond that the page says the demo is full and to try again in a few minutes.
- CSRF protection applies as on every form. No script from another origin is loaded.

### 3. Sessions expire, and the demo database is built again

Superseded in part by the addendum below: demos live in a database of their own, and the first version's
exception in the immutability triggers was dropped before it shipped.

- A demo lasts **2 hours** (`NONOX_DEMO_HOURS`). `EndExpiredSessions` signs a guest out and sends them
  to `/prova` when the time is up, with a message. The end time is in `demo_sessions` in the demo database.
- `demo:prune` runs every ten minutes and clears out expired demos by building the demo database again
  (addendum, section D).

### 4. Nothing leaves the building

The demo database can reach nothing outside NONOX:

| Outward path | How it is shut |
|---|---|
| Documents (invoices, quotes, reminders, CRM mail) | The test-company rules (ADR-032) already mark them as sent without e-mailing; the CRM follows the same rule |
| Invitations | `InviteTeamMember` refuses in a demo |
| Any other e-mail | A `MessageSending` listener cancels every message to a `@demo.invalid` address, in any process |
| Peppol, bank, AI, NONOX API, till systems, inbox | The modules `peppol`, `banking`, `ai`, `connect`, `pos`, `inbox` report "off" in the demo database (ModuleManager) |
| Skatteverket, VIES, Riksbanken, any other HTTP | A global HTTP-client middleware refuses every request made while the demo connection is the default |
| Payment files | Creating or downloading a bank payment file (pain.001) is refused in a demo |
| API tokens | Creating a personal access token for a guest is refused |
| New companies, profile, deleting the account | The guest cannot create a company or open the profile page |
| Signing in by code | `SendLoginCode` does nothing for a guest address |

While the demo is being **built** the modules are on and nothing is blocked (the extras need CRM,
inbox and so on to have something to show); the build happens in a queue job with no outbound calls of
its own. Seeded data contains only invented names, `example`/`test` addresses and no real person.

### 5. What the visitor sees

A banner on every page of a demo: "Demo. Det här är ett påhittat företag. Allt du gör försvinner när
tiden är ute (om 1 tim 40 min). Inget skickas till någon." with a link "Skapa konto" that leads to the
queue or sign-in (`SignupPath`). **Nothing is carried over**: an account is a fresh start; the demo's
data is unreachable when the time is up and is cleared out later, and the page says so. A person who wants their own data in NONOX imports it
(SIE4, ADR-008).

### 6. Switch

`InstanceSettings::DEMO_ENABLED` (`demo.enabled`), edited in /hq under Inställningar. **The default is off
everywhere, a hosted installation included** (owner rule 2026-11-08). When off, `/prova` answers 404, the landing
page shows no link, and `demo:prune` returns at once without looking at any database. The owner switches it on
in /hq after confirming that the demo database may be emptied.

### 7. GDPR

No personal data is collected: no name, address or e-mail address is asked for. The IP address is only
in the rate-limit cache key as a keyed hash for at most one hour, and in the web server's own access
log (covered by the existing privacy policy). The guest's session cookie is strictly necessary. All
data in a demo is invented. Nothing is added to the register of processing beyond a line stating this.

### 8. Not done (and why)

- **Demo of accounting firms and CRM prospecting data**: they need several companies per visitor.
- **Carrying data from the demo into an account**: mixing invented and real data in a ledger would break
  the integrity the system exists for.
- **A CAPTCHA**: a third party would see every visitor. The honeypot, time check and limits are enough
  against casual abuse; the ceiling bounds the cost of determined abuse.
- **Resetting a demo**: starting a new one costs the same and avoids a second code path.

## Consequences

- Every installation with public pages gets a try-it page at the price of a bounded number of
  short-lived rows. At the ceiling of 40, with 2 hours each, the work is at most 40 builds per two hours.
- The immutability triggers have no exception (see the addendum); the demo database is cleared by being built
  again.
- `docs/systemdokumentation` records the demo database in section 7c.

## Owner decision 2026-11-08: separate demo database

The first version of this ADR made demos rows in the main database and removed them through an
exception in the immutability triggers. The owner decided against any exception in the production
triggers: **demos run in a separate database**. The migration with the exception was removed before it
was released; the main database's trigger functions are byte for byte what they are without this
feature (a test compares their definitions with the demo database's, which is built from the same
migrations, and asserts that none contains `demo_purge`).

### A. The connection

- `config/database.php` has a second connection `demo`: the same server settings as the main one,
  `DB_DEMO_DATABASE` as the database name (default `<DB_DATABASE>_demo`), `DB_DEMO_USERNAME` and
  `DB_DEMO_PASSWORD` optional. It has the **full schema**: `php artisan migrate --database=demo`, run at
  deploy after the ordinary `migrate`. A demo therefore has the real tables with the real triggers, and
  shows the real behaviour: a posted voucher cannot be changed there either.
- One migration (`create_demo_database_tables`) adds what only the demo database has: `demo_sessions`
  (the guest, the company and the time the demo ends), `cache` and `cache_locks`, and `jobs`. It returns
  at once on any other connection, so the main database gets nothing.
- Models, `DB::`, `Schema::` and the migrator all use the default connection, so switching the default is
  enough for everything that does not name a connection.

### B. How a request becomes a guest's

What was read in the installed Laravel (`vendor/laravel/framework`, 2026-11-08) and relied on:

- `DatabaseManager::setDefaultConnection()` only writes `config('database.default')`; models resolve
  their connection with `Model::resolveConnection(null)` at each query. Switching the default switches
  Eloquent, the query builder, `Schema` and the migrator (`Migrator::usingConnection` does the same).
- `SessionManager::createDatabaseDriver()` takes `config('session.connection')` when the driver is built, and
  drivers are cached by `Manager::driver()` (`forgetDrivers()` clears them). `StartSession` builds the driver
  when it handles the request, and `addCookieToResponse` reads the cookie name from config. The
  `session.store` singleton and the `AuthManager`'s guards also hold the store (`forgetGuards()`).
- The cache store is picked by name from `config('cache.default')` and the `RateLimiter` singleton is built once
  from `config('cache.limiter')`. The queue connection is `config('queue.default')` at dispatch.
- `Illuminate\Foundation\Bootstrap\LoadConfiguration` merges `connections` and `stores` of a published
  `config/queue.php` and `config/cache.php` with the framework's own, so adding `demo` loses nothing.

The first middleware of the web group, `UseDemoDatabase`, runs **before the session starts and before the
user is resolved**. A request is a guest's when it carries the guest session cookie `nonox_demo_session`, or is
the opening request `/prova/oppna/{token}`. For such a request `DemoMode::activateRequest()` sets
`database.default`, `session.connection`, `session.cookie`, `session.driver=database`, `cache.default`,
`cache.limiter` and `queue.default` to the demo's, clears the cached session drivers, the `session.store`
singleton, the guards, the rate limiter and the current company, and `terminate()` puts every value back
(it matters in a long-lived process and in tests, where one application serves many requests).

- **Routing is decided by what exists, not by the cookie alone.** A request goes to the demo database only when
  all three hold: the feature is on, the cookie's session id exists in the demo database's `sessions`, and that session's
  user has a live row in `demo_sessions`. The cookie is decrypted with the application key and its name-bound prefix is
  checked, as `EncryptCookies` does. The one other guest request is `/prova/oppna/{token}`, and only for a token whose
  build is READY. In every other case the request is served by the main database and the demo cookie it carries is
  forgotten (queued expiry cookie), so a leftover cookie can never strand a real user on the demo database and a bare
  `/prova/oppna/…` link plants no cookie. With the feature off, or the demo database missing or not migrated, a request
  that carries the cookie never errors: it gets the main database and a forgotten cookie. The cookie is also expired on
  logout, on demo expiry, and on every way to sign up (`/logga-in`, `/ko`, `/registrera`, which run on the main database,
  and so does the banner's "Skapa konto").

- **The pages of the entry** (`/prova`, `/prova/bygger/{token}`) are never a guest's, even with the cookie: they
  run on the main database like any public page, with a session that holds nothing about a demo. The state of a
  build, and the entry's rate limits, are in the demo database's cache and are reached by naming the store.
- **The switch** (`InstanceSettings`) is read from the main connection by name, also when the default is the
  demo's (`DemoMode::onMainConnection`). The main connection's name is remembered at boot because
  `setDefaultConnection()` overwrites the config value.
- **A guest cookie can never resolve a main user.** Its name differs from the main session cookie's, so
  a request that has it is looked up in the demo database's `sessions` only, and one that does not is looked up in the
  main one only. A main session id sent under the guest name is not in the demo database; a guest id sent under
  the main name is not in the main one. Both directions are tested (`DemoIsolationTest`), as is the
  restoring of every setting after the request.

### B2. One switch for the request, the build and the worker

`DemoMode::enter()`/`leave()` is the only switch (a stack, so it nests). It sets the default connection, the session
driver (`demo-database`, a handler that stores no IP address and no browser name), connection, cookie and table, the
cache store and limiter, the queue connection, and the roots of the `local` and `uploads` file disks
(`storage/app/demo-private`, `demo-uploads`), and clears what holds on to the old ones. A guest's request, the demo
build (`DemoMode::building`) and a worker's demo job all call it, so that nothing keyed by company id (the cache, the
queue, files, the per-request module cache, which is also keyed by connection) can reach the main installation's
company with the same id. The demo cache store has the key prefix `nonox_demo_` as a second line of defence. A job leaves
only the switch it entered (matched by job object and connection name), so a nested sync job cannot restore the main
database in the middle of a build. The archive-package step of the builder no longer changes `queue.default`; it
builds the package directly if the queue has not. All mail is blocked while the demo is built, not only after.

### C. Queued jobs

- Jobs dispatched during a guest request go to the queue connection `demo` (the default was switched), whose
  `jobs` table is in the demo database (`NONOX_DEMO_QUEUE` overrides the driver; on an installation whose queue is
  `sync` it is `sync`). A worker for them is `php artisan queue:work demo` and must run on production for demos
  to build.
- When a worker takes a job from the connection `demo`, a listener on `JobProcessing` makes the demo
  database its default and `JobProcessed`/`JobFailed`/`JobExceptionOccurred` put it back. The job's models
  are restored on the default connection (`SerializesModels` records no connection when the model's is the
  default), which is then the demo's. The build job also switches explicitly, so it works under `sync` too.
- A failed job of the demo queue is written to the main installation's `failed_jobs` (the queue's failure
  provider). The build job catches everything and never fails; other jobs a guest dispatches carry no personal
  data. Known edge.

### D. Tenancy, and what is shared

- `CurrentCompany` and `BelongsToCompany` are unchanged: they filter on `company_id` and resolve the company
  from the user, on whichever connection is the default, so a demo is an ordinary tenant in its own database.
  The context is cleared when the request is switched.
- Shared: the code, `config/*` (apart from the values switched above), translations, views, files on the
  `local` disk (stored under random names; a demo's files are deleted when the demo database is built
  again, if no row of the main database refers to them), and the mail, HTTP and module walls, which stay
  **blocked**: all of them now key on one question, `DemoMode::active()` (is the default connection the demo's, outside
  the build). The operator's customer register ignores demo companies, and `RequirePasskey` does not ask a
  guest for a passkey (the data is invented and a guest has none).
- Not shared: users, companies, sessions, cache, queue, audit log and every other table.

### E. Clearing out: build the demo database again

Chosen over deleting rows because the demo database has all the triggers, so a posted voucher cannot be
deleted there either, and the alternatives (disabling triggers per table with `ALTER TABLE`, or
`session_replication_role`) need locks or superuser rights and weaken the very schema the demo shows.
Dropping the schema of a database that holds nothing but demos is the simpler safe option:

- `demo:prune` (every ten minutes) **returns immediately while `demo.enabled` is off.** Once on, it rebuilds the
  demo database when **no demo is live, none is being built, and some have expired**:
  `DROP SCHEMA public CASCADE; CREATE SCHEMA public;` on the `demo` connection, then `migrate --database=demo`, then
  the demo's own file directories are deleted (nothing outside `demo-private`/`demo-uploads` is touched).
- A start (`DemoSessions::begin`) holds a shared advisory lock on the demo database and a rebuild takes it
  exclusively (and gives up for this run if it cannot), so the two never overlap.
- On a busy day it never gets quiet, so once every `NONOX_DEMO_REBUILD_AFTER_HOURS` (24) it **drains**: a flag
  in `storage/framework` makes `/prova` refuse new demos until the live ones (at most two hours) have ended
  and the rebuild has run.
- **It can touch no other database.** Before anything it calls `DemoDatabase::assertSafe()`: the demo
  connection's database must be named **exactly `<main database>_demo`** (a foreign `*_demo` database on a shared server
  never qualifies), on the same host and port as the main connection, and the servers' `current_database()`
  and port must agree. All of it is checked before a statement that changes anything; each refusal is tested. It only
  uses the `demo` connection.
- **Owner rule:** emptying or rebuilding a database is allowed only for this dedicated demo database.
  Nothing else in NONOX may do it, and no test rebuilds any other.
- **Production is not switched on by this change.** The feature is off by default everywhere, and the
  installation needs the demo database created and migrated, a worker for the `demo` queue, and the owner's
  explicit confirmation at deploy.

### F. Consequences

- No exception in the immutability triggers; the main database never holds a demo, a guest, a guest session or
  a demo's cache entry.
- Costs: a second database to create and migrate at deploy, a second queue worker, and a short interval of
  an hour or so each day when new demos wait (draining) on a busy installation.
- A new migration must be run with `--database=demo` as well; the CI workflow does both.
