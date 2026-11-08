# Agent quickstart

The fastest way into NONOX for an AI coding agent (or a new developer). `AGENTS.md` is the
rulebook and wins on any conflict; this file is the map, the commands and the lessons that
are not obvious from the code. Read both before the first change.

## 1. Five rules that prevent real damage

1. **Never empty a database.** No `migrate:fresh`, `migrate:refresh`, `db:wipe`, `DROP`,
   `TRUNCATE`, and never the `RefreshDatabase` trait (tests use `DatabaseTransactions`).
   Run `grep -rl RefreshDatabase tests/` before every test run.
2. **Name the database on every command:** `DB_DATABASE=<name> php artisan …` and
   `DB_DATABASE=<name> vendor/bin/pest …`. There is no `.env.testing`; the default `.env`
   points at a developer's own data. Use your own databases: `nonox_test_<topic>` for tests,
   `nonox_demo_<topic>` for demo data and screenshots. `php artisan migrate --force` creates
   a missing database (`createdb` may not be installed). `php artisan serve` needs
   `--no-reload` to keep env overrides.
3. **Run the full suite with the database session in UTC** before you call anything done:
   `PGTZ=UTC DB_DATABASE=nonox_test php -d memory_limit=1G vendor/bin/pest` (sequential). CI's PostgreSQL runs in
   UTC; local machines usually do not. NONOX itself is fixed to `Europe/Stockholm` in
   `config/app.php` and `config/database.php` — never make the time zone configurable.
4. **Pushing to `main` deploys the hosted service.** Work on a branch; merge only what is
   tested and reviewed; after a push, check GitHub Actions (`gh run list --branch main`)
   until it is green.
5. **The repository is public.** Never commit real company data, personal numbers, keys,
   `.env`, `.claude/` or an unrelated `package-lock.json` change. Instance-specific facts
   (which company runs the hosted service, customers, settings) live only in the database.

## 2. The stack in one paragraph

Laravel 13, PHP 8.4 (`declare(strict_types=1)` everywhere), Livewire 4 + Alpine, Tailwind 4,
PostgreSQL only, Pest, Larastan level 8, Pint, Vite. Jetstream teams **are** companies
(`App\Models\Company`, table `teams`, FK `company_id`). Sign-in is passwordless (e-mail code)
with passkeys as a second step for sensitive data. Money is integer öre (`Money`, `MoneyCast`).
Posted vouchers are immutable at three levels (policy, model events, PostgreSQL triggers).
Every company-scoped model uses `BelongsToCompany`; queued jobs get `company_id` and call
`CurrentCompany::run()`/`set()`. All user-facing text and URLs are Swedish (`/fakturor`,
`/kunder/affarer`); code, comments, commits and ADRs are English.

## 3. Map of the repository

| Where | What |
|---|---|
| `app/Actions/{Domain}` | Single-purpose, invokable, transactional business actions. The UI and the API both call these. |
| `app/Domain/{Domain}` | Enums, value objects, domain services and rules. |
| `app/Livewire/**` | Thin screen components that call Actions. |
| `app/Modules/{Name}` | Optional modules (ADR-017): own routes, views, migrations, Livewire, policies. Keys: `agreements`, `ai`, `banking`, `connect`, `crm`, `inbox`, `inventory`, `payroll`, `planning`, `pos`; plus StaffLedger and Contracts. A module is switched on per company in `company_modules`; its permissions only exist while it is on. |
| `app/Support/**` | Money, Luhn, SIE, Tenancy, Audit, Manual, Landing (public pages data), Chat, Instance settings. |
| `app/Http/Controllers/Api/V1`, `app/Modules/Connect` | REST API and NONOX Connect (OAuth apps, API keys, webhooks). OpenAPI in `docs/api/openapi.yaml`. |
| `resources/views/start/**` | Public website (landing, comparison, industries, moving guide, legal pages). |
| `docs/adr/` | Architecture decisions, ADR-000 … ADR-051 (see §8). Next number = highest + 1; check other branches first, parallel work has collided. |
| `docs/systemdokumentation/README.md` | BFNAR 2013:2 system documentation, versioned; update it when accounting behaviour changes. |
| `docs/manual/sv/<section>/*.md` | The public user manual at `/manual` (rules: `docs/manual/SKRIVREGLER.md`). Front matter `rutter` links each article to app routes for the in-app help button. |
| `docs/andringslogg/andringslogg.json` | Public changelog at `/andringslogg`. |
| `docs/fardplan/fardplan.json` | Public roadmap at `/fardplan`. |
| `docs/migration/jamforelse.json` | Comparison with other programs (ADR-020); vendor sources in `jamforelse-funktioner.md`. |
| `docs/gdpr/`, `docs/juridik/` | Register of processing, retention, privacy policy, terms, DPA, sub-processors. |
| `database/seeders/DemoCompanySeeder.php`, `IndustryDemoSeeder.php`, `Demo/Extras/*` | Synthetic demo companies for screenshots. Seed only into a `nonox_demo_*` database. |
| `tests/Feature/**`, `tests/Tenancy/**`, `tests/js/*.test.mjs` | Pest feature tests, the tenancy suite, and Node tests for front-end logic. |

## 4. Commands

```bash
# setup (first time)
composer install && npm install && cp .env.example .env && php artisan key:generate
DB_DATABASE=nonox_test php artisan migrate --force

# while working: only what you touched
DB_DATABASE=nonox_test vendor/bin/pest tests/Feature/Crm
DB_DATABASE=nonox_test vendor/bin/pest --dirty

# before calling it done (once, after the last change)
grep -rl RefreshDatabase tests/
PGTZ=UTC DB_DATABASE=nonox_test php -d memory_limit=1G vendor/bin/pest   # full suite, sequential
vendor/bin/phpstan analyse --memory-limit=2G
vendor/bin/pint --test
npm test && npm run build

# docs and media
DB_DATABASE=nonox_demo_x php artisan db:seed --class=DemoCompanySeeder
DB_DATABASE=nonox_demo_x php artisan manual:screenshots    # manual images (public/bilder/manual)
php artisan og:images [--only=…]                            # share images for public pages
```

Two tests (`ManualTest` and `DeveloperPortalTest`, "loads no script … from another origin")
fail while a Vite dev server is running (`public/hot` exists). That is environmental; do not
kill someone else's dev server, rerun when it is gone.

## 5. Definition of done: what a change must also update

Tests enforce most of these, so a forgotten one turns the suite red.

| When you … | Also update | Guarded by |
|---|---|---|
| ship anything a user can notice | a changelog entry: Swedish, ≤ 3 sentences, `typ` and `omrade` from `Changelog::TYPES/AREAS`, `datum` never in the future; the newest `feat`/`fix` commit must have an entry | `ChangelogTest` |
| add a user-facing string | `lang/en.json` (append; never rewrite the file with a JSON tool — it collapses duplicate keys) | `TranslationsTest` |
| change a screen | the manual article with the exact UI labels, `rutter`, screenshots | `ManualTest` |
| launch a feature | `app/Support/Landing/Features.php` (live/soon), `LandingPage::INCLUDED`, the landing feature card with a real screenshot, README feature list | `LandingPageTest` |
| launch a module | a row in `docs/migration/jamforelse.json` with `keys` (competitor values only from the vendor's own pages, else `unknown`); never only-NONOX rows without neutral ones; an `/alternativ-till-x` page compares only NONOX and x | `IndustryPagesTest` |
| add a company-scoped model | `BelongsToCompany` + a tenancy test (another company's id → 404) | `tests/Tenancy` |
| change accounting behaviour | `docs/systemdokumentation/README.md` and its version header | `SystemDocumentationVersionsTest` |
| change the API | `docs/api/openapi.yaml` and the API changelog | `OpenApiSpecTest` |
| change personal-data processing | `docs/gdpr/registerforteckning.md`, `lagringstider.md`, the privacy policy in `docs/juridik/`; a policy change bumps `LegalPageController::VERSION`/`EFFECTIVE` (one bump per deploy; parallel branches collide here) | legal page tests |
| make a design decision | an ADR, or an addendum to the relevant one | — |
| are unsure about law | `// TODO(legal-verify): …` and say so in the report; never invent legal rules | — |

## 6. Patterns to copy

- **Actions** validate, authorize (policy or `hasTeamPermission`), run in `DB::transaction`,
  and write the audit log. Livewire components only call them and show a Swedish toast.
- **Audit log** (`Audit::record`) is append-only: ids, field names and enums only — never
  names, contact details or free text.
- **N+1 is a bug.** Pages run a fixed number of queries; prove it with a query-count test
  (`DB::enableQueryLog()` with a short and a long history). `preventLazyLoading` is on.
- **Personal data** in CRM, payroll and the queue follows GDPR rules already in code:
  legal basis per person, register extracts, erasure, pruning, the suppression list
  (HMAC hashes only), "Kontakta inte". Read ADR-043's review sections before touching CRM.
- **Public pages** may name other programs only as verifiable plain-text comparisons (ADR-020).
- **Look Laravel up, do not guess.** Read the installed version's docs or `vendor/` source
  and say which you relied on.

## 7. Workflow that has worked

1. Restate the task, read the ADRs and code it touches, list the docs it will affect (§5).
2. Branch (`feat/…`, `fix/…`), ideally in a git worktree with its own test database.
3. Failing test first for behaviour; then the code; then the docs.
4. Independent review — a second agent reads the diff for correctness, security, tenancy,
   a11y, and as a lawyer for GDPR/marketing law — then fix every confirmed finding and have
   the fixes re-checked.
5. Merge, run §4's full checks with `PGTZ=UTC`, push, watch CI, verify the live site.

## 8. ADR index

000 Founding decisions · 001 Stack · 002 PDF · 003 Company roles · 004 First-run setup ·
005 Tenancy · 006 Money and audit log · 007 Vouchers and ledger chain · 008 SIE4 and opening
balances · 009 Cost centres and projects · 010 Reports, VAT, year-end · 011 Invoicing ·
012 Payroll · 013 Supplier invoices · 014 Payment files (pain.001) · 015 Incoming payments
(camt.054) · 016 Supplier credit notes and reverse charge · 017 Module framework · 018 NONOX AI ·
019 Interface and company colours · 020 Naming other programs · 021 Auditor access to payslips ·
022 Staff ledger · 023 Inbox · 024 Archiving and ownership transfer · 025 Foreign currency ·
026 Spotlight search · 027 Overview · 028 Firm workspace and tasks · 029 Passkeys ·
030 Firm branding · 031 Installable web app · 032 Public API and Connect · 033 Collective
agreements · 034 Open banking · 035 Company lookup · 036 User manual · 037 Staff scheduling ·
038 Waiting list and /hq · 039 Inventory · 040 Scanner app · 041 Labels · 042 Till
integrations · 043 CRM · 044 Operator company and access switches · 045 Encrypted chat ·
046 CRM signing, quote templates and agreements · 047 CRM prospecting from open data ·
048 CRM e-mail, mailings and web forms · 049 CRM calendar · 050 Peppol · 051 Företagsguiden · 053 NE-bilaga and INK4 · 054 Skatteverket APIs · 055 Payment initiation (proposed) · 056 Tax in the closing · 057 Import VAT · 058 Digital annual report (proposed) · 062 K10 underlag (proposed) · 063 MCP server (proposed) · 064 NONOX API and NONOX Connect · 065 Export and portability (proposed) · 066 Ask your figures (proposed) · 067 Approval queue (proposed) · 068 Read access log (proposed) · 069 Sustainability data, VS/VSME (proposed) · 070 Share register and boardroom (proposed) · 071 Manufacturing, purchase orders and customer ordering (proposed) · 072 Mileage log and mobile receipts · 074 Work orders and change orders (ÄTA) (052 is reserved for Regelkoll).

## 9. Lessons that cost time before

- CI was red for two days while local runs were green: the database session time zone
  differed. Hence §1.3.
- A changelog entry with an unknown area, and one dated a few minutes in the future, both
  broke the suite after a push. Check `Changelog::AREAS` and the clock.
- Manual section overviews live at `/manual/<section>`; `/manual/<section>/oversikt`
  redirects. Link the section, and let tests open every link they render.
- `zsh` does not split `$VAR` into words: pass file lists literally.
- Rewriting JSON files (`lang/en.json`, `andringslogg.json`, `jamforelse.json`) with a
  serializer reformats or deduplicates them; edit as text and append.
- When two branches both add a changelog entry, both edit `lang/en.json`, or both bump the
  legal version or the system documentation version, the merge conflicts there: keep both
  sides, newest changelog entry first, one version bump.
- The full suite outgrew PHP's memory limit (2026-11-05): with about 5 700 tests one process passes
  512 MB and dies with "Allowed memory size … exhausted" and no summary line. Run it with
  `php -d memory_limit=1G`; a run without a `Tests:` line is not a green run.
- Parallel agents share the session scratchpad: name scratch files uniquely (include the branch
  or task), never reuse a generic name like en2.py.
