# ADR-038: Open beta behind a waiting list, and /hq for the owner

- Status: Accepted (decided by the project owner), 2026-11-02
- Relates to: ADR-000 §3 (passwordless sign-in), ADR-029 (passkeys), ADR-020
  (naming other programs on public pages), AGENTS.md §3.2, §4, §11.4, GDPR
  art. 5, 6, 13 and 17
- Numbering: the next free number when the work started.

## Context

Until now the sign-in form created an account for any e-mail address that
entered a code (ADR-000 §3): the first sign-in was the registration. The owner
wants the hosted service to start as an **open beta** where new companies are
let in at the pace the team can help them, with the **launch of version 1.0 on
1 January 2027**. People should answer a few questions, wait in a queue, and
be approved by the owner before they can sign in. Nobody may get an account
by just typing an address.

## Decision

### 1. No self-registration

`LoginForm` sends a code only to an **existing user** (not anonymised) or an
address with a **valid invitation to a company** (`TeamInvitation::stillValid`,
14 days). Any other address gets no code and no account, but sees exactly the
same next step ("Om … har ett konto eller en inbjudan har vi skickat en kod")
as a known address, so the form does not reveal who has an account. The code
step and the e-mail step link to the queue ("Inte godkänd än? Ställ dig i
kö"). A new account is still created on the first sign-in of an invited
address (with the invitation's name, or a name step); the invitation is
checked again when the name is submitted.

Closed paths: the account creation for unknown addresses in `LoginForm`
(`verify` and `createAccount`); `/registrera` now redirects to `/ko`. Fortify's
registration feature was already off (`config/fortify.php` has only
`updateProfileInformation`) and its routes are ignored. Remaining ways to a
new user: an invitation to a company (Jetstream invitations, also the payroll
portal invitation, which is a team invitation with the role `employee`),
approval in /hq, and the first-run setup of a new local installation
(`FirstRun::allowed()`: local environment and no users at all). The API,
NONOX Connect (OAuth, test company, SFTP) never create users.

Known limit: the code e-mail is sent synchronously, so the answer for a known
address can take a little longer than for an unknown one. The page itself is
identical; the timing difference is accepted for now.

### 2. The waiting list (`/ko`)

A public Livewire form (`JoinForm` → `JoinWaitlist`) on installations with
public pages (`app.landing.enabled`). Questions: name, e-mail, phone
(optional), company name, organisation number (optional, Luhn-checked),
industry (the industry pages plus "Annat"), number of employees (ranges),
current system (the programs of the public comparison, "Inget",
"Kalkylblad eller papper", "Annat"), what they want to test, why they want to
switch, and consent with a link to the privacy policy.

- Stored in `waitlist_entries`, **instance data, not company data** (no
  `company_id`, no `BelongsToCompany`): an entry exists before any company.
- One live entry per address: a partial unique index on `email` where status
  is not `REJECTED`, plus a lock per address. Sending the form again updates a
  pending or parked entry and leaves an approved one alone; the answer is the
  same thank-you page either way, and also for a filled-in honeypot.
- Rate limits: 5 submissions per IP address and 3 per e-mail address and hour.
  The button is disabled while the request runs.
- The IP address is stored only as an HMAC with the app key; the user agent
  is cut to 255 characters.
- A queued confirmation e-mail (`WaitlistConfirmationMail`) that carries only
  the name, not the answers.

### 3. /hq

The owner's area: `/hq` (the queue) and `/hq/ko/{id}` (one entry).

- Access: the addresses in `config('nonox.hq_admins')` (env `HQ_ADMINS`,
  comma separated), or `app.admin_email` when that list is empty. Everyone
  else, guests included, gets **404**, so the area does not reveal itself.
  `EnsureHqAdmin` is used instead of the `auth` middleware for that reason
  (the auth middleware would redirect guests to the sign-in page); it also
  sends an admin whose session still needs its passkey step (ADR-029) there.
  The Livewire components check the list again on every request.
- Decisions (Godkänn, Neka, Parkera, Radera, bulk approval) need a **fresh
  passkey confirmation** like webhooks and API keys (ADR-032): one assertion
  covers decisions for five minutes (`nonox.hq.step_up_seconds`).
  `PASSKEYS_SKIP_LOCALLY` skips it on a local installation.
- **Godkänn** creates the user (name and e-mail, `email_verified_at` empty
  until the first code is entered), marks the entry `APPROVED` with
  `approved_user_id`, and queues `WaitlistApprovedMail` with a link to
  `/logga-in`. After the first sign-in the user has no company and is sent
  to `/kom-igang` as before. An address that already has a user is linked to
  it instead of getting a second one.
- **Neka** and **Parkera** mail nobody. Approved and rejected entries are
  final; a parked entry can be decided later; a rejected address may queue
  again as a new entry.
- CSV export (semicolons, UTF-8 with BOM, guarded against formulas) of the
  filtered list.
- Every action is written to the audit log **without a company** (new
  `Audit::instance`): `waitlist.approved`, `waitlist.rejected`,
  `waitlist.parked`, `waitlist.deleted`, `waitlist.exported` (filters and row
  count), `waitlist.pruned`. Payloads carry ids and statuses, never the
  applicant's name or address, because the audit log is append-only and the
  entry itself is deleted later.
- HQ admins get a database notice per new entry ("Ny i kön: <företag>", with a
  link to the entry) and one summary e-mail a day (`waitlist:digest`, 07:15,
  nothing on a day without entries); no e-mail per entry.

### 4. Retention

`waitlist:prune` (daily, 04:40) deletes rejected entries 30 days after the
decision, and pending, parked and approved entries 12 months after the
sign-up or the approval (`nonox.waitlist.retention_months`). The account an
approval created is not touched. HQ can delete an entry at once when the
applicant asks (GDPR art. 17). The HQ notices about an entry go with it.
`TODO(legal-verify)`: both periods.

### 5. Public pages

Every self-signup call to action (header, hero, prices, industry pages,
comparison pages, moving guide, closing sections) now says **"Ställ dig i
kö"** and links to `/ko`; the header keeps a small "Logga in" for approved
users. A banner on every public page, the manual and the Swedish developer
pages says "Öppen beta – skarp lansering av version 1.0 den 1 januari 2027".
It sits in the flow above the header and scrolls away, so it never covers
content. Download links for running NONOX yourself stay: the software is
open source and needs no queue.

### 6. The HQ menu and Kunder (added 2026-11-02)

HQ is a section of the sidebar, not a link in the footer. An HQ admin sees
**HQ** last in the main menu, with the pages **Kö** (`/hq`) and **Kunder**
(`/hq/kunder`). HQ belongs to the instance, not to a company: it is built by
`Navigation` outside the company sections, so it shows whatever company the
admin has selected, and also with no company. Nobody else sees it, whatever
their role in a company.

**Kunder** (`App\Livewire\Hq\Customers`, read model
`App\Support\Hq\CustomerDirectory`) has two tabs:

- **Företag**: name, organisation number, legal form, created, archived,
  enabled modules, number of members (owner included), owner's name and
  e-mail, last activity (the latest entry in the audit log for the company)
  and the number of posted vouchers. Search on name and organisation number;
  filters for module, archived and created period; sorting; pagination.
  `/hq/kunder/foretag/{id}` adds the members with role and last sign-in and
  every module with its state.
- **Användare**: name, e-mail, companies with role, created, last sign-in,
  and source: *via kön* (an approved waiting-list entry points at the user),
  *via inbjudan* (a member of a company through `team_user`; since
  self-registration is closed, membership means an invitation) or *övrigt*
  (the first-run setup and accounts from before the beta). The source is
  derived, not stored: a user who later joins a company by invitation and
  came from the queue still counts as *via kön*. Search on name and e-mail;
  filters for source and created period; sorting; pagination.
  `/hq/kunder/anvandare/{id}` shows the same for one user.

Last sign-in is the latest `auth.signed_in` entry in the audit log, written
on every sign-in by the `Login` event (`AuditSubscriber`). No new column was
needed.

Data minimisation: no amounts, vouchers, invoices, personal numbers or
employee data. The only figure taken from the bookkeeping is the count of
posted vouchers. A sole trader's organisation number is the owner's personal
number, so it is shown as "Dolt (personnummer)"; searching on it still finds
the company, since the admin must already know the number.

Read-only: no impersonation, no editing, no deletion, and the page says so.
Access is the same as the queue: `EnsureHqAdmin` on the routes (404 for
everyone else, guests included) and `HqAccess::ensure` again on every
Livewire request. Reading needs no passkey step beyond the one the session
already passed; only decisions in the queue do.

Every cross-company query runs inside `CompanyScope::bypass` with a comment
(AGENTS.md §6.0). The lists cost the same number of queries whatever the
number of rows: last activity, last sign-in, the voucher count and the source
are subqueries of the list query, the owner and memberships are eager-loaded,
and the enabled modules are one query per page.

Every visit is audited at instance level, without personal data:
`hq.customers_viewed` (the tab), `hq.company_viewed` (`company_id`) and
`hq.user_viewed` (`user_id`).

## Consequences

- A self-hosted installation with public pages also gets the queue; its
  operator decides in /hq with `ADMIN_EMAIL` or `HQ_ADMINS`. Without public
  pages there is no queue, and new users are invited by their companies.
- The manual article "Logga in" and the getting-started overview describe the
  queue; a new article explains access during the beta.
- After 1 January 2027 the owner decides whether the queue stays, opens up or
  is removed; the date lives in `config('nonox.launch_date')` and in the
  public texts.
