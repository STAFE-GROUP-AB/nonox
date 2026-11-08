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
  rule for test companies applies) and `demo_expires_at`.

The two rows are made in **one database transaction**. A build that fails leaves nothing behind. The
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

### 3. Sessions expire, and a scheduled command removes the rest

- A demo lasts **2 hours** (`NONOX_DEMO_HOURS`). `EndExpiredSessions` signs a guest out and sends them
  to `/prova` when the time is up, with a message.
- `demo:prune` runs every ten minutes. It selects companies where `demo_expires_at` has passed,
  `is_test_company` is true and **every member is a guest user**, and removes each one, its files and its
  guest user. It never truncates, wipes or drops anything.
- **It is the only deletion NONOX makes.** To make it possible without weakening the immutability
  rules, migration `2026_12_19_100000` adds:
  - `nonox_demo_purge(row)`, true only when the transaction has named the row's company in the setting
    `nonox.demo_purge` and that company is an expired demo (or is already being deleted in the same
    cascade);
  - one line at the top of each trigger function that guards DELETE, letting such a row through.
    UPDATE, INSERT and TRUNCATE keep their rules;
  - a trigger on `teams` that refuses to delete any company that is not an expired demo, and any
    company other than the one the transaction has named.
  A real company has no `demo_expires_at`, so the function answers false for every one of its rows and
  the team trigger refuses it. The purge refuses a flagged company that has a member who is not a guest.
- A trigger function redefined by a later migration would lose its line. `CreateDemoTest` builds a whole
  demo company and purges it, so a new append-only table or a rewritten function that blocks the purge
  fails that test at once. The rule for authors: when you create or replace a trigger that rejects
  DELETE, start its body with the same line (see the migration).
- Stored files that belong to the company (`StoredFiles::REFERENCES`) are deleted after the rows, only
  when no other row refers to them.

### 4. Nothing leaves the building

A demo company can reach nothing outside NONOX:

| Outward path | How it is shut |
|---|---|
| Documents (invoices, quotes, reminders, CRM mail) | The test-company rules (ADR-032) already mark them as sent without e-mailing; the CRM follows the same rule |
| Invitations | `InviteTeamMember` refuses in a demo |
| Any other e-mail | A `MessageSending` listener cancels every message to a `@demo.invalid` address, in any process |
| Peppol, bank, AI, NONOX API, till systems, inbox | The modules `peppol`, `banking`, `ai`, `connect`, `pos`, `inbox` report "off" for a demo company (ModuleManager) |
| Skatteverket, VIES, Riksbanken, any other HTTP | A global HTTP-client middleware refuses every request made while a demo company is the current company |
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
data is deleted, and the page says so. A person who wants their own data in NONOX imports it
(SIE4, ADR-008).

### 6. Switch

`InstanceSettings::DEMO_ENABLED` (`demo.enabled`), edited in /hq under Inställningar. The default is
on for an installation with public pages (`app.landing.hosted`) and off otherwise: a self-hosting
company does not want strangers creating companies in its database. When off, `/prova` answers 404,
the landing page shows no link, and `demo:prune` still removes what is left.

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
- The immutability triggers have a second, narrow exit. It is tested from three sides: a real company
  cannot be purged, a demo cannot be purged before it expires, and a full demo can be purged.
- `docs/systemdokumentation` records the exception under the integrity checks.
