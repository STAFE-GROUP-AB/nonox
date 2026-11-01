# ADR-004: First-run setup guide

- Status: Accepted
- Date: 2026-10-29
- Task: T-0.2 (follow-up)

## Context

The people who install the system are not technical. The original start page
was a technical checklist, and the first account came from a demo seeder. The
owner asked for a guide that asks for their company, name and email on the
first visit, and makes that email the sign-in address.

## Decision

- `/` shows a three-step guide while there are no users: company, person, then
  a one-time code that confirms the email address. The first user and their
  company are created together in one transaction (`CompleteFirstRun`), and the
  user is signed in.
- **The guide only runs when `APP_ENV=local` and no user exists**
  (`App\Support\FirstRun`). On a server, an open first-run form would let
  anyone who reaches the URL first become the owner of the installation.
  Every step re-checks this on the server, and the final step runs behind a
  lock so two simultaneous submissions cannot both succeed.
- When something technical is missing, `/` explains the first problem in plain
  Swedish with exactly one command. Technical details are folded away, and
  hidden entirely in production.
- The start pages are self-contained (inline CSS) so they render before the
  frontend has been built.
- `composer setup` no longer seeds demo data. `DemoCompanySeeder` remains for
  developers.
  `IndustryDemoSeeder` adds one synthetic company per industry page, with
  the year's bookkeeping, for demos and manual screenshots; it is never part
  of the installation and refuses to run in production.

## Open

- Creating the first user on a production server needs its own flow (for
  example an artisan command run on the server). Until then a production
  installation without users shows "Systemet är inte färdiginstallerat".
