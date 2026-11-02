# ADR-006: Money and the audit log

- Status: Accepted
- Date: 2026-10-29
- Task: T-0.5

## Money

- `App\Support\Money\Money` holds whole öre in an `int` and an ISO 4217
  currency. It never uses floats (`AGENTS.md` §1.2).
- Exact arithmetic uses `brick/math` (MIT), now an explicit dependency. It was
  already installed through Laravel; 1.0.0 has no changes from 0.20.
- Default rounding is `RoundingMode::HalfUp`, which in brick/math rounds half
  away from zero (`-2.5 → -3`), so a credit note mirrors its invoice exactly.
- VAT rates are basis points (`2500` = 25 %), as in `AGENTS.md` §8.1.
- Parsing accepts `1234,50`, `1 234,50`, `1234.50`, an optional `kr` and a
  Unicode minus. Anything else is rejected rather than guessed (`1.234,50`,
  three decimals).
- Formatting is `1 234,50 kr` with non-breaking spaces.
- `MoneyCast` maps `*_ore` columns to Money. It refuses floats and strings.

## Audit log (behandlingshistorik, §6.6)

- `audit_logs` is append-only at two levels:
  - the model throws on update and delete;
  - PostgreSQL triggers reject `UPDATE`, `DELETE` and `TRUNCATE`.

  Tests confirm this through Eloquent, the query builder and raw SQL.
- `AuditLog` does **not** use `BelongsToCompany`. Some entries belong to no
  company (signing in), and recording must never fail because no company is
  known. Entries are read with `AuditLog::forCompany($company)`.
- `payload` is `jsonb`, so key order is not preserved.
- `user_id` references `users` without cascading. Deleting users is disabled
  (account deletion is off), and an audit entry must never be rewritten.

What is recorded today:

| Action | When |
|---|---|
| `company.created` | A company is created (with the creating user, also in the first-run guide) |
| `company.updated` | Company details change; payload holds only the changed fields, before and after |
| `company.switched` | A user switches company (§3.5) |
| `company.member_added` / `member_role_changed` / `member_removed` | Membership changes |
| `company.invitation_sent` | Someone is invited |
| `auth.signed_in` / `auth.signed_out` | Sign-in and sign-out |

Vouchers, periods and settings are added in their own tasks (§6.6 lists them as
mandatory).

## Not yet

- A page to read the log (`audit-log:view`).
- Archiving the log in the archive package (§10, T-1.6).

## Versions of the systemdokumentation (2026-11-02, #44)

Enligt granskning 2026-11-02, juristgranskning återstår (AUD-BOK-9, #44).

- **Rule.** BFL 5 kap. 11 § and BFNAR 2013:2, comment on p. 9.1: the
  systemdokumentation is räkenskapsinformation; when it changes, the
  replaced version is kept so the bookkeeping can be followed at every point
  in time during the archiving period.
- **Decision.** `system_documentation_versions` keeps every distinct text of
  `docs/systemdokumentation/README.md` this installation used (version and
  date from the header, SHA-256, the program version, the time it was taken
  into use). It is instance data, like an audit entry without a company, so
  it has no `company_id`; it is append-only in the model and by trigger.
  `VersionRecorder` records after every `migrate` (`MigrationsEnded` and
  `NoPendingMigrations`), from `nonox:build-info`, from
  `nonox:record-version` and hourly from the scheduler, so a self-hosted
  installation records without our deploy script. The archive package adds
  the versions in use during the fiscal year and `versioner.txt`. The header
  of the document is kept at the last row of the change table, and a test
  checks the order.
- **Note for whoever adds a version row:** update `- Version:` and
  `- Datum:` in the header to the new last row, or the test fails.
