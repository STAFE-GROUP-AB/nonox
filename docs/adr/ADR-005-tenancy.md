# ADR-005: Tenancy isolation

- Status: Accepted
- Date: 2026-10-29
- Task: T-0.4

## Decision

Company isolation is enforced in the model layer, fails closed, and is proven
by a shared test harness (`AGENTS.md` §1.3, §6.0, §13.2).

| Piece | Where | What it does |
|---|---|---|
| `CurrentCompany` | `app/Support/Tenancy` | The company of the current request or job. Web: the user's current company, **only while they are still a member**. Jobs and commands: nothing until `set()` or `run()`. |
| `CompanyContext` | `app/Support/Tenancy` | Scoped container state behind `CurrentCompany`, discarded between requests and queued jobs. |
| `CompanyScope` | `app/Support/Tenancy` | Global scope `company_id = current company`. **No current company → no rows.** |
| `CompanyScope::bypass()` | `app/Support/Tenancy` | The only sanctioned way around the scope. Every call needs a comment explaining why. |
| `BelongsToCompany` | `app/Support/Tenancy` | Adds the scope and `company()`, fills in `company_id` on create, refuses to create without a company or for another company, and refuses to move a row to another company. |
| `RunsForCompany` + `SetCompanyContext` | `app/Support/Tenancy` | Queued jobs carry `$companyId` and run inside it; they never use the session. |

The current company is forgotten on sign-in, sign-out and company switch, so a
request never keeps a stale company.

## Adding a company-scoped model

1. Migration: `$table->foreignId('company_id')->constrained('teams')`.
2. Model: `use BelongsToCompany;`. Relations from `Company` to the model must
   name `company_id` explicitly, because `Company::getForeignKey()` is `team_id`
   for Jetstream's tables.
3. Register it in `COMPANY_SCOPED_MODELS` in
   `tests/Tenancy/TenancyIsolationTest.php`. A test fails until you do.
4. Routes that load it by id must go through Eloquent, so other companies'
   records answer 404.

## What the harness proves for every registered model

Created rows get the current company; other companies' rows are invisible
through `all`, `count`, `find`, `findOrFail` and `exists`; with no company,
nothing is visible and nothing can be created; rows cannot be created for or
moved to another company; bulk `update` and `delete` stay within the company;
only `bypass()` sees across companies; queued jobs see only their own company;
opening another company's record by id answers 404.

Each guard was removed once on purpose to confirm the suite fails without it.

## Limits

- Raw queries (`DB::table`, `DB::select`) are not scoped. `AGENTS.md` §5 only
  allows them in migrations and justified reports, which must filter on
  `company_id` themselves.
- `withoutGlobalScope(s)` is forbidden outside `app/Support/Tenancy`, enforced
  by a test that scans `app/`.
- API tokens bound to a company are resolved in §12 (marked TODO in
  `CurrentCompany`).

## Addendum (2026-11-04): Livewire components for a non-current company

`LivewireCompanyGuard` ties every Livewire component to the current company
and the user's access fingerprint there, in the signed memo. The settings
page `/foretag/{company}` also renders forms for a company the user belongs
to but has not switched to. Such a component names its company in a
`#[Locked] companyId` or a `Company` property; the guard then also keeps that
company and the user's fingerprint in it in the memo, and a later request
answers 404 unless the user still belongs to that company with the same
access. A snapshot replayed after the user was removed from the company, or
lost the role, no longer shows its data.
