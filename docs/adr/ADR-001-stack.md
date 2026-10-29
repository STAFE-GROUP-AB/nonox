# ADR-001: Technology stack and versions

- Status: Accepted
- Date: 2026-10-29
- Task: T-0.1

## Decision

Exact versions in use at the end of T-0.1 (verified with `composer show`,
`npm ls` and the running services):

### Runtime

| Component | Version | Constraint |
|---|---|---|
| PHP | 8.4.27 (CLI) | `^8.4` |
| PostgreSQL | 18.0 locally (Herd), 18 in CI | 16+ required |
| Node.js | 22.22.0 | 20+ |

### PHP packages

| Package | Version | Constraint |
|---|---|---|
| laravel/framework | 13.39.0 | `^13.0` |
| laravel/jetstream (Livewire stack, teams) | 5.5.3 | `^5.5` |
| laravel/fortify | 1.41.0 | via Jetstream |
| laravel/sanctum | 4.3.3 | `^4.3` |
| livewire/livewire | 4.4.8 | `^4.0` |
| laravel/tinker | 3.0.2 | `^3.0` |
| pestphp/pest | 4.7.8 | `^4.0` (dev) |
| pestphp/pest-plugin-laravel | 4.1.0 | `^4.0` (dev) |
| phpunit/phpunit | 12.5.33 | via Pest |
| larastan/larastan | 3.13.2 | `^3.0` (dev) |
| phpstan/phpstan | 2.3.4 | via Larastan |
| laravel/pint | 1.32.1 | `^1.32` (dev) |

### Frontend

| Package | Version |
|---|---|
| tailwindcss | 3.4.19 |
| alpinejs | 3.15.3 |
| vite | 5.4.21 |
| laravel-vite-plugin | 1.3.0 |
| axios | 1.21.0 |

### Quality gates

- `composer check` runs `pint --test`, Larastan level 8 and Pest, which is the
  same as CI (`.github/workflows/ci.yml`).
- `declare(strict_types=1);` in every PHP file, enforced by Pint
  (`pint.json`: `declare_strict_types`).
- Tests use `DatabaseTransactions` against the dedicated `nonox_test` database.
  The schema is migrated once and never rebuilt per test.
- GitHub Actions are pinned to commit SHAs.

## Context

- The repository started on Laravel 11, which no longer receives security fixes
  and had an open critical advisory (CVE-2026-114509, TokenGuard authentication
  bypass). It was upgraded to Laravel 13 after reading the Laravel 12 and 13
  upgrade guides and the PHPUnit 12, Tinker 3, Fortify, Jetstream and Livewire
  release notes. `composer audit` reports no advisories.
- Laravel 13 hardens session and cache deserialization through skeleton config
  values that this project lacked. `config/session.php` (`serialization: json`)
  and `config/cache.php` (`serializable_classes: false`) were added.
- The Pest installation guide requires PHP 8.4+, which matches `AGENTS.md` §2.

## Known gaps against AGENTS.md §2

| Gap | Planned in |
|---|---|
| Tailwind 3 (Jetstream default) instead of Tailwind 4 | T-0.3 |
| Vite 5 has moderate advisories that only affect the dev server and build (fix requires Vite 8) | T-0.3, together with Tailwind 4 |
| Redis + Horizon not installed (queue is `sync`) | First task that needs background jobs (SIE import, T-1.4) |
| S3-compatible storage not configured (local disk) | Attachments (T-1.2/T-1.3) |
| PDF engine not chosen | ADR-002 |

## Update 2026-10-29: Livewire 4

Livewire was upgraded from 3.8.11 to 4.4.8 at the owner's request, before the
voucher pages were built. The official upgrade guide was read and measured
against the code first:

- There is no `config/livewire.php`, so the renamed config keys
  (`component_layout`, `component_placeholder`) do not apply.
- The code had no `wire:model.blur`/`.change`, `wire:transition` modifiers,
  `stream()`, JavaScript hooks, bracket notation in `wire:model` or `@persist`.
  All `<livewire:...>` tags were already self-closing.
- Jetstream's modals use `wire:model` on wrapper components but read it with
  `@entangle`, so the change to child events does not affect them (covered by
  the member and profile tests).
- The endpoint moved from `/livewire/update` to `/livewire-{hash}/update`.
  Nothing in the app hardcoded it.
- One test changed: Livewire 4's test client turns a missing model into a 404
  response, as a real request does, instead of throwing.

`resources/js/app.js` no longer imports and starts its own Alpine. Livewire
provides Alpine, and starting a second copy gave two instances on the same page.

