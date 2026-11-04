# ADR-000: Founding decisions

- Status: Accepted
- Date: 2026-10-29
- Decided by: project owner

## Context

`AGENTS.md` was adopted as the master specification. It conflicted with the
existing repository and with the project owner's standing conventions on four
points. The owner decided each one explicitly.

## Decisions

### 1. Name: NONOX Ekonomisystem

Replaces the working name "STAFE OSS Accounting" and the `{{APP_NAME}}`
placeholder. Used in `APP_NAME`, the README, `composer.json` and the start page.

Open question for the owner: the name can be read as a reference to a
competitor's trademark. A trademark check is recommended before public launch.

### 2. Database: PostgreSQL 16+

As specified in `AGENTS.md` §2. Immutability of posted vouchers and the
append-only audit log rely on PostgreSQL triggers (§1.2, §6.4, §6.6). This
overrides the owner's general MySQL default for this project only.

### 3. Login: passwordless email one-time codes (OTP) for everyone

Overrides `AGENTS.md` §2, which required passwords plus mandatory 2FA for the
owner, admin and accountant roles.

- Every user signs in with a one-time code sent by email. There are no passwords.
- No 2FA requirement.
- Double submits are guarded: the button is disabled client-side while sending,
  and server-side an already-sent, still-valid code is reused within a cooldown
  (e.g. 60 s) behind a lock, so at most one email is sent.
- Locally (`APP_ENV=local`, controlled by `OTP_AUTOFILL=true`) the code is
  filled in and verified automatically, and the email field is prefilled with the
  seeded admin address, so local sign-in is one click.
- Addendum (security review 2026-11-04): the caps that keep the form from
  flooding inboxes count mails actually sent, not requests: 5 per address in
  ten minutes, 20 per address and day from one client address (IPv6 counted
  per /64, an IPv4-mapped address as its IPv4 address), 50 per address and day
  in all, and 500 per hour for the service, which counts only codes to
  addresses without an account (open self-registration, invitations); known
  accounts are bounded per address. A reached cap does not refuse: nothing
  new is sent and the latest code stays valid until it expires (ten minutes).
  This makes it harder, not impossible, to disturb someone else's sign-in by
  asking for codes in their name: after the latest code has expired, the
  owner may have to wait. The service-wide warning is logged at most once per
  ten minutes. Only the per-IP request limit answers "För många försök". The
  code mail is not queued (it must not wait behind long jobs); it is sent
  after the response with `defer()`, so the answer takes almost the same time
  whether or not an address has an account and never waits for the mail
  server.

`AGENTS.md` §2 and task T-0.3 were updated accordingly. §1 is unaffected.

### 4. License: AGPL-3.0

As specified in `AGENTS.md`. The README previously claimed MIT but the
repository had no LICENSE file. `LICENSE` now contains the official AGPL-3.0
text from gnu.org and `composer.json` declares `AGPL-3.0-only`.

### 5. Everything user-facing is Swedish, including URLs

Added 2026-10-29 by the owner: the system is built for Swedish companies, so
every URL and every text a user sees is Swedish. URLs use lowercase ASCII with
hyphens (å/ä → a, ö → o). Code, comments and commits stay English as
`AGENTS.md` prescribes. The rule is in `AGENTS.md` §11.4, and the onboarding
path in §3.2 changed from `/onboarding/company` to `/kom-igang`.

## Consequences

- The previous domain layer (separate `companies` table, decimal amounts, JSON
  voucher lines, MySQL-only setup) does not match `AGENTS.md` §3 and §6 and will
  be replaced during Phase 0 and Phase 1.
- `tasks.md` (the original functional specification) is superseded by
  `AGENTS.md` and was removed. It remains available in git history.
- Competitor names were removed from the README; per `AGENTS.md` §1.1 they may
  only appear in the migration module.
