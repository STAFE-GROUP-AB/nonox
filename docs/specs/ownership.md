# File ownership for `docs/specs/nummer-1-spec.md`

Kept by the Lead (spec Part 1.4). One row per path prefix. A builder that needs a
path owned by another item asks the Lead first. Rows marked **hot spot** need the
Lead's explicit approval for every change, whichever item owns the work.

Last updated: 2026-11-07.

## Hot spots (Lead approval per change)

| Path | Why |
|---|---|
| `app/Actions/Accounting/PostVoucher.php` and the voucher immutability triggers (`database/migrations/*voucher*trigger*`) | Immutability, AGENTS.md §1.2 |
| `app/Support/Tenancy/**` (incl. `BelongsToCompany`), `app/Policies/**` | Tenancy, AGENTS.md §1.3 |
| `app/Support/Audit/**` (`Audit::record`) | Append-only audit log |
| `app/Modules/Module*.php`, `app/Support/Landing/Features.php` | Module framework, public claims |
| `app/Domain/AnnualReport/AnnualReportDocument.php`, `app/Domain/AnnualReport/PrintedFigures.php` | One figures source for K2, K3 and iXBRL |
| `tests/Fixtures/annual-report/**` | K2 golden files; must stay byte-identical |

## Items

| Path prefix | Owner | Status |
|---|---|---|
| `docs/specs/rules/s1.md` | S1 Researcher | in progress |
| `docs/specs/rules/s2.md` | S2 Researcher | in progress |
| `tests/Fixtures/s1/**` | Fixture author (S1) | in progress |
| `tests/Fixtures/s2/**` | Fixture author (S2) | in progress |
| `docs/adr/ADR-059-k3-annual-report.md` (addenda) | S1 Architect | waits for `rules/s1.md` |
| `app/Domain/AnnualReport/K3*`, `app/Domain/Assets/Component*`, `app/Domain/Closing/DeferredTax*`, `app/Domain/AnnualReport/CashFlow*` | S1 Builders A–C | not started |
| `docs/adr/ADR-060-group-overview.md` (addenda), `app/Domain/Group/**`, `app/Livewire/Group/**`, `tests/Feature/Group/**`, `tests/Tenancy/GroupTenancyTest.php` | S2 | stage 1 consolidation not started |
| `docs/adr/ADR-065-export-and-portability.md`, `app/Support/Portability/**`, `app/Actions/Portability/**`, `tests/Feature/Portability/**` | F10 Builder | in progress |
| `app/Support/Privacy/**`, `app/Actions/Privacy/**`, `app/Console/Commands/Prune*`, `docs/gdpr/**` | F8 | after F10's first package |
| `docs/status/sanningsrevision.md`, `resources/views/start/**`, `app/Support/Landing/LandingPage.php`, `docs/migration/jamforelse.json` | Truth auditor (Track A) | in progress |
| `docs/specs/**` (except `rules/`), `docs/status/nummer-1.md`, `docs/status/manskliga-beslut.md`, `docs/status/vecka-*.md` | Lead | — |

## Reserved numbers

- ADR-065: F10 export and portability.
- ADR-066 and up: assigned by the Lead when an architect starts (check other branches first; parallel work has collided before).
