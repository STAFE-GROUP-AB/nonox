# ADR-029: Passkeys as a second step for those who see sensitive personal data

- Status: Proposed (waiting for the project owner's approval), 2026-11-01
- Relates to: ADR-000 §3 (passwordless sign-in), ADR-003 (roles), ADR-021,
  AGENTS.md §1.4 and §4, GDPR art. 9 and 32
- Numbering: ADR-024 to ADR-028 are taken or referenced by work in progress, so
  this is ADR-029.

## Context

Sign-in is an eight-digit code sent by email (ADR-000). For most users that is
enough, but it means that whoever takes over a mailbox takes over the account.
For a payroll administrator that is sick leave (health data, GDPR art. 9) and
personal numbers for every employee. The security review asked for a second
factor for the roles that can see that data, and the owner chose passkeys
(WebAuthn): nothing to remember, phishing-resistant, and they keep sign-in
free of passwords.

## Package

Compared on 2026-11-01 (licence, Laravel 13 / Livewire 4, maintenance,
storage, options):

| | laravel/passkeys | spatie/laravel-passkeys | laragear/webauthn |
|---|---|---|---|
| Licence | MIT | MIT | MIT |
| Version | 0.2.1 (May 2026) | 1.8.1 | 5.0.2, **abandoned**: the author points to laravel/passkeys |
| Laravel 13 | yes (`illuminate/* ^11–^13`) | yes | yes |
| Built for | actions usable as a second factor (`GenerateVerificationOptions($user)` limits the challenge to the user's passkeys, `VerifyPasskey(..., $user)` checks ownership) | passkey as the only sign-in (usernameless), with its own Livewire component | primary login |
| Engine | web-auth/webauthn-lib 5.3 (MIT) | web-auth/webauthn-lib | own |
| Storage | `passkeys`: user_id, name, credential_id (unique), credential (JSON public credential record: public key, sign counter, AAGUID), last_used_at | `passkeys` with serialized data | `webauthn_credentials` |
| User verification | required at registration and sign-in | configurable | configurable |
| Attestation | none (only "none" statements accepted) | none | configurable |

**Chosen: laravel/passkeys ^0.2.** It is Laravel's own package and is
already installed: `laravel/fortify` 1.41 requires it, so it adds no new code
to the dependency tree (all of it MIT, AGPL-compatible). Fortify configures it
from `config/fortify.php` → `passkeys` and switches its routes off. Its actions
fit a second factor directly. It being 0.x is the risk: the constraint `^0.2`
stays within 0.2.x, and NONOX uses only the actions, the `Passkey` model and
`WebAuthn` serializer, behind `App\Actions\Auth\Passkeys`, so a breaking
change is contained there. The browser part is ours
(`resources/js/passkeys.js`, ~100 lines, bundled by Vite), so no npm package
and no CDN are needed and the content security policy is unchanged:
WebAuthn is `navigator.credentials`, which `script-src 'self'` and
`connect-src 'self'` already cover, and the Permissions-Policy does not deny
`publickey-credentials-*`.

Attestation stays "none": NONOX does not need to know the make of the
authenticator, and accepting any authenticator that verifies the user (PIN,
biometrics) is what makes passkeys usable on everyone's own phone or computer.

## Decisions

### Who must use a passkey

`App\Domain\Auth\PasskeyRequirement::appliesTo()` is the single rule. A user
must use a passkey when, in **any** of their companies:

1. they hold a permission in `PasskeyRequirement::SENSITIVE_PERMISSIONS`:
   - `payroll:view-sensitive`: absence causes (sick leave);
   - `payroll:view` and `payroll:archive-view`: payroll runs and payslips,
     whose sick pay and qualifying-day lines show who was ill (see ADR-021);
   - `staff-ledger:view-sensitive`: full personal numbers in the staff ledger;
   - `staff-ledger:control-visit`: the control visit view shows every personal
     number in full;

   a module's permission counts only in a company where the module has been
   turned on at some point (`company_modules.enabled_at`), since before that
   there is no data to see. In practice that is the owner, administrator,
   accountant, auditor and payroll administrator of a company with payroll,
   and the owner, administrator and staff ledger manager of a company with the
   staff ledger. A bookkeeper, approver or employee is not covered; or

2. the company's owner has turned on **"Kräv passkey för alla i företaget"**
   (`teams.require_passkeys`, off by default, only the owner can change it,
   audited). It applies to the whole account, since passkeys belong to the
   user.

A user who is not covered can still add passkeys on the profile; from then on
they are asked for one at every sign-in too (`mustVerify()`).

The owner role as such is not covered: an owner of a company without payroll
or staff ledger sees no health data. That is an open question for the owner
below.

### Sign-in

The email code and all its limits are unchanged. After a correct code:

- a user with passkeys must answer a WebAuthn assertion, limited to their own
  passkeys, with user verification required, before `Auth::login()`;
- a user who must have one but has none adds one there (with an explanation
  why), and is then signed in;
- everyone else is signed in as before.

The pending user is kept server-side in the session for ten minutes. Options
(challenges) are kept in the session, bound to the user, single use and valid
for five minutes. Failed assertions are limited to ten per user per ten
minutes. A passkey can only be added at sign-in by a user who has none; more
are added from the profile after signing in with one, so a stolen mailbox
cannot add its own key to an account that already has one.

A session that passed the step carries `auth.passkey_verified_at`. The
`RequirePasskey` middleware (web group) sends a signed-in session that must
pass the step but has not (a role given meanwhile, a session from before
this change) to `/logga-in/passkey`; until then only that page, its own
Livewire updates and sign-out work. Public pages stay open.

### Profile

"Passkeys" lists each passkey with name, authenticator (from its AAGUID),
added and last used; adds one with a name; removes one. The last one cannot be
removed while a passkey is required for the user. Adding sends the user an
email ("En passkey har lagts till på ditt konto"). The audit log records
`passkey.added`, `passkey.verified`, `passkey.removed` with the passkey's name
only, never key material.

### Recovery

A user who has lost every device asks an owner or administrator (holder of
`members:manage`) of a company they belong to. "Återställ passkeys" in the
member list removes all their passkeys, ends their sessions
(`sessions_valid_from`), writes `passkey.reset` to **that company's** audit
log and emails the user. At the next sign-in they add a new one. Guards:

- the one resetting must themselves have signed in with a passkey in this
  session, so a mailbox takeover of an administrator without one cannot be
  used to strip someone else's;
- they must share the company with the member and manage its members; an
  administrator of another company cannot reach the member, whichever company
  is named;
- nobody resets themselves, and an administrator cannot reset the company
  owner's passkeys.

Passkeys belong to the user, so a reset in company A also affects the
member's sign-in elsewhere; that is why it is guarded as above, logged in A
and emailed. As the last way back (for example an owner with no one else to
ask), whoever runs the server can run `php artisan passkeys:reset {email}`,
logged in each of the user's companies.

### Local development

WebAuthn needs a secure context; `http://localhost` is one, `http://*.test`
is not. `PasskeySession::skippedLocally()` skips the step only when
`APP_ENV=local` **and** `APP_URL` is a local address **and**
`PASSKEYS_SKIP_LOCALLY` is on (it follows `OTP_AUTOFILL` unless set), the same
safety as the code autofill: on a server it is off whatever the configuration.
Tests cover production, staging and a real host.

## Consequences

- Covered users need a device with a passkey (any current phone or computer).
  Losing all of them needs another person in the company, or the server
  operator.
- Changing `APP_URL`'s host makes existing passkeys unusable (they are bound
  to the relying party ID). Self-hosters are told in `.env.example`.
- Tests use a software authenticator (`tests/Support/VirtualAuthenticator`)
  that signs real ES256 assertions, since the package has no test helpers.

## Open questions for the owner

1. Should every owner be covered regardless of modules, as the review
   suggested? Today only owners of companies with payroll or staff ledger are.
2. Customers' personal numbers (sole traders, private customers) are shown in
   full to everyone with `invoices:create`, bookkeepers included. They are not
   health data, so they are not in the list; adding `invoices:create` would
   cover almost everyone.
3. Bootstrapping: a user who must have a passkey and has none adds the first
   one with only the email code. Whoever already controls their mailbox at that
   moment gets there first. The user is emailed about every passkey added. An
   alternative is to let an administrator approve the first passkey.
4. API tokens (Sanctum, `/api/v1`) are not affected by the passkey step.
