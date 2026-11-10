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
pages says "Öppen beta – skarp lansering av version 1.0 den 1 januari 2027" (moved on 2026-11-09 by the owner to 1 februari 2027; the banner now says 1 februari 2027).
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

## Addendum (2026-11-03): switches and the operator company

ADR-044 makes two of the decisions above switchable in **HQ → Inställningar**
without a deploy: the queue can be closed (`/ko` then shows a closed message
and takes nothing in), and self-registration (§1) can be opened again behind
the same rate limits. The defaults keep this ADR's behaviour: queue open,
self-registration closed. HQ can also choose the **operator company**, whose
customer register and CRM then receive every company and every queue
applicant; see ADR-044.

## Addendum (2026-11-04): security review

- **The form no longer updates a live entry.** Sending `/ko` again with the
  address of an open entry used to overwrite its answers, so anyone who knew
  an applicant's address could change them. A live entry (open or approved)
  is now left exactly as it is; the answer is still the same thank-you page.
  An applicant who wants to change something writes to the operator.
- **Reading HQ needs a passkey.** An HQ admin is now covered by
  `PasskeyRequirement` like a role with sensitive permissions (ADR-029): the
  session must have passed the passkey step before any `/hq` page or HQ
  Livewire request answers, and an admin without a passkey is sent to add one
  first (the existing first-passkey step, unchanged). This replaces "Reading
  needs no passkey step beyond the one the session already passed" above:
  there is now always such a step. Decisions still need the fresh
  confirmation of `HqAccess::confirm`.

## Addendum (2026-11-10): onboarding after approval

What follows an approval used to be one generic mail and an empty form. It is
now built from the answers on the waiting list (decided by the project owner).

- **Welcome mail.** `WaitlistApprovedMail` takes the entry and is written to
  the person: their first name and the company's name; a next step by
  `current_system` (Fortnox: the automatic move at `/flytt/fortnox` and the
  manual article; another program: a SIE file and importing the registers at
  `/bokforing/las-in-uppgifter`; no program or "Kalkylblad eller papper":
  start from scratch); payroll and moving payroll data when `employees` is not
  "inga"; and the modules that suit the industry, each linked to its manual
  section. The sender is the instance's normal address, Reply-To is
  `config('nonox.waitlist.reply_to')` (`andreas@stafegroup.com`), the signature
  is "Med vänliga hälsningar, Andreas Kviby", a line under the body says who
  the sender is and where a reply goes, and the beta and 1.0 sentence keeps its
  date from `nonox.launch_date`. No booking link. `OnboardingPlan` is the one
  place that turns the answers into a plan, so the mail, the checklist and the
  help mail cannot disagree. The module suggestions are a fixed map from
  industry to module keys; a key is only used if the registry has it and, for a
  module offered to some companies only, if the company is offered it.
- **Prefilled `/kom-igang`.** `CreateCompanyForm` fills in name, organisation
  number and industry from the user's approved entry (`approved_user_id`), all
  of it editable, and runs the existing company lookup on the number (ADR-035),
  which proposes address and legal form and lists what differs from the name
  the user wrote.
- **Kom igång checklist.** `PersonalChecklist` adds "Flytta från Fortnox" right
  after "Skapa företaget" for a Fortnox user, "Lägg upp lön och anställda" when
  there are employees, and "Slå på modulerna för din bransch" (names the modules
  that are offered and not yet on, links to Inställningar → Moduler) when the
  industry is known, from the company or else the entry. A company with no entry
  and no industry sees the standard list. One extra query on the overview.
- **Two follow-up mails.** `waitlist:send-onboarding-reminders` runs daily at
  09:20: (a) two days after approval to someone with no sign-in and no company;
  (b) five days after the company was created if no SIE import or register
  import has finished and no Fortnox move has started. Each is sent at most once
  per entry (`login_reminder_sent_at`, `help_reminder_sent_at`, checked again
  under a row lock and stamped in the same transaction as the queueing), nothing
  goes out later than 14 days after the approval, and never to an entry that is
  not approved, is deleted or has opted out. Every mail carries "Inga fler
  påminnelser": a signed, non-expiring one-click link to
  `/ko/inga-paminnelser/{entry}` that sets `reminders_opted_out_at` (GET, and POST
  for `List-Unsubscribe-Post`, which is exempt from CSRF; the signature is the
  proof), also as the `List-Unsubscribe` header. A link to a deleted entry gets
  the same answer. The audit log gets ids only: `waitlist.reminder_sent`
  (`entry_id`, `kind`), `waitlist.reminders_opted_out`, `hq.onboarding_viewed`.
- **HQ → Introduktion** (`/hq/introduktion`, `OnboardingProgress`): per approved
  entry the approval date, signed in, company created, bookkeeping moved in,
  first invoice sent and the reminders sent, filterable on each step and
  sortable so the owner sees who to call. "Signed in" is an `auth.signed_in`
  entry after the approval, "company created" a company the approved user owns,
  created after the approval, "moved in" as above, "first invoice" an invoice
  with a number. The facts are subqueries of the list query, so it costs the
  same number of queries for 5 and for 50 entries (tested). Only presence and
  dates are read, never amounts.

Review amendments (2026-11-10):

- The help mail goes out only for a company with nothing in it: no finished
  import, no Fortnox move, no posted voucher and no issued invoice. The login
  reminder is skipped when the approval reused an existing user
  (`approved_user_existed`) or the person already owned a company.
- A user gets one welcome mail and one set of reminders: a later approved entry
  of the same user sends nothing. The mails keep plain values, not the entry,
  so a deleted entry does not break a queued mail.
- The sender is neutral by default: `WAITLIST_REPLY_TO` and `WAITLIST_SIGNATURE`
  are empty, replies then go to `MAIL_FROM_ADDRESS` and the mails are signed
  with the app name; the operator sets real values per instance.
- Values the applicant wrote are escaped for Markdown before they enter a mail.
- "Inga fler påminnelser": a GET shows a button, a POST (the button, or a mail
  client's one-click per RFC 8058) opts out, and the audit row is written
  without IP address and user agent. The signed link is built from `APP_URL`.
- The form on `/kom-igang` (not `/foretag/nytt`) is prefilled, only for someone
  who owns no company created since the approval, and the register lookup runs
  once per number and session. The checklist additions apply only to a company
  the person owns and created after the approval.
- Module suggestions are service information: all NONOX modules are included at
  no extra cost, and the mails never suggest a paid NONOX Connect service
  (marknadsföringslagen (2008:486) 19 § is the rule to check, not the LEK).
- The reminders start at the deploy, and the privacy policy text that describes
  them is published at the same time. That is information under art. 13, not a
  change of terms for existing users.

Module suggestions per industry (2026-11-10, decided by the owner): each
suggestion carries a one-sentence reason, in the mail and, for the first
three, in the checklist step. Order: café and restaurant: personalliggare,
payroll (with Min sida), planning, kassakopplingar, kök, lager, inkorg; hair and
beauty: personalliggare, payroll, planning, kassakopplingar, lager, inkorg;
crafts: arbetsorder, payroll (with the mileage log), planning, inkorg, AI;
retail: kassakopplingar, lager, payroll, planning, inkorg; consultant: CRM,
inkorg, AI, payroll; accounting firm and finance consultant: inkorg and CRM
plus a pointer to Mina företag (core, not a module); other: inkorg, AI, payroll.
With no employees payroll and planning are left out; the staff ledger stays for
café, restaurant and hair and beauty, worded "Krav i branschen – gäller även
ägaren i vissa fall". The customer portal is not live and is never suggested.
`TODO(legal-verify)`: that wording, and that NONOX's staff ledger does not cover
construction sites, so crafts are not offered it.

Consequences: the data is the waiting list's own (ADR-038 §2) used for the
service the person asked for. The register of processing, the retention list
and the privacy policy (version 1.8) say so. `TODO(legal-verify)`: whether the
reminders and module suggestions are service information and not direct
marketing under 19 § marknadsföringslagen (2008:486);
the opt-out and `List-Unsubscribe` are offered either way.
