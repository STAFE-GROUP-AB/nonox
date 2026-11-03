# ADR-044: The operator company, and the queue and self-registration switches

- Status: Accepted (decided by the project owner), 2026-11-03; review round
  the same day (see "Review round")
- Builds on: ADR-038 (open beta, waiting list, /hq), ADR-043 (CRM: accounts,
  people, SavePerson, SuppressionList, ConvertToCustomer, EraseCrmData),
  the customer register (Phase 2), AGENTS.md §1.3, §6.0
- Numbering: the next free number when the work started.

## Context

The hosted service is run by one of the companies *inside* the
installation. That company needs every company using the service in its
own customer register (to invoice it) and every queue applicant in its own
CRM (to select and contact them), without anyone typing them in twice. The
repository is public and self-hosters must not get any of this by default:
which company runs the service is data in the database, never in code,
configuration or documentation.

The owner also wants to close the queue and to open self-registration
without a deploy.

## Decision

### 1. Instance settings

A new table `instance_settings` (key, jsonb value, `updated_by`, times):
instance data, not company data, no `company_id`. Read through
`App\Support\Instance\InstanceSettings` (a scoped binding: one query per
request or job). Keys and defaults:

| Key | Default | Meaning |
|---|---|---|
| `operator.company_id` | empty | **Driftbolaget**. Empty = the sync is off (self-hosters) |
| `waitlist.open` | true | `/ko` takes applications |
| `registration.open` | false | the sign-in form creates accounts for unknown addresses |

Everything goes to the operator company and nowhere else. A separate
company for the customers or for the queue was considered and removed:
another legal entity receiving the data would be a disclosure without a
legal basis.

Edited only on **HQ → Inställningar** (`/hq/installningar`): `EnsureHqAdmin`
on the route (404 for everyone else), `HqAccess::ensure` again on every
Livewire request and in `UpdateInstanceSettings`, and a fresh passkey
confirmation like the queue's decisions. The company must exist, not be
archived and not be a test company. Every change is written to the
instance audit log as `hq.settings_updated` with the changed keys' values
before and after: company ids and booleans only. The page's counters and
lists show the current operator company's links only.

### 2. Every company becomes a customer

`SyncCompanyToOperator` makes every company a customer of the operator
company, except the operator company itself and test companies
(`is_test_company`, demo data). An archived company never becomes a *new*
customer; a customer it already has is made inactive when it is archived
and active again when it is taken out of the archive. Nothing is deleted.

- **Where the data goes.** Name (the legal name), organisation number, VAT
  number, address, phone, and the company's e-mail or, when it has none,
  the owner's e-mail; country; 30 days' payment terms on creation. A sole
  trader's number is a personal number (`IdentityNumber`): it goes to the
  register's encrypted `personal_number`, never to `org_number`, and the
  VAT number (which contains it) is not copied. `TODO(legal-verify)`:
  dataskyddslagen 3 kap. 10 § for that personal number.
- **Never twice.** `operator_links` (instance data) keeps one row per
  (operator, source company) and per (operator, queue entry), with the
  customer, CRM account and CRM person it points at, whether the sync
  created them, and keyed hashes of the values last pushed, never the
  values. Without a link, the sync first takes the queue prospect it made
  for the company's owner (see 3), and otherwise creates a customer. A
  customer removed in the operator company after it was linked is not
  created again.
- **Never linked to someone else's record without HQ.** Anyone can type
  any organisation number when they create a company. When the operator
  already has a customer (that no link points at) or a CRM account (that
  is not a customer) with the same organisation number, the sync links
  nothing, writes nothing and adds no contact person: the link waits in
  `NEEDS_REVIEW` with the candidate's id, and HQ → Inställningar shows
  **Koppla till befintlig kund?** with both records side by side (ids,
  names, organisation numbers). **Koppla** links to the existing record as
  it is (an account becomes a customer through `ConvertToCustomer`);
  **Skapa ny kund** creates a separate customer. Both need the passkey
  step and are audited as `hq.operator_review_resolved` (ids and the
  decision). The sync then runs again.
- **Kept in step.** A change of a watched company field (name, legal name,
  numbers, e-mail, phone, address, country, archived, owner) queues a sync.
  Only the fields whose value changed in the source since the last push
  are written, so the operator's own edits of other fields (an invoice
  address, payment terms) stay. A customer anonymised or restricted under
  GDPR in the operator company is never written to again.
- **Change tracking.** The hashes are HMAC-SHA256 with a key derived from
  `APP_KEY` by HKDF with a fixed context string
  (`nonox-operator-sync-change-tracking-v1`), and the link keeps a short
  fingerprint of the key. After an `APP_KEY` rotation the fingerprint no
  longer matches: the next sync takes the company's current values as the
  new baseline and writes nothing, so a rotation never overwrites the
  operator's edits; a change made in the source before that sync is not
  pushed.
- **CRM.** With CRM on, the customer register's own observer gives the
  customer an account (ADR-043), and the company's owner becomes its
  contact person with role decision maker: legal basis **agreement** for a
  sole trader (the owner is the customer), **legitimate interest** for
  anyone else (a contact in their professional role). `TODO(legal-verify)`:
  the balancing test. An address on the suppression list, or a person
  marked Kontakta inte, is not added or linked. A new owner is added on the
  next sync; the former owner stays with the CRM's own pruning.

### 3. Every queue applicant becomes a CRM prospect

`SyncWaitlistEntryToOperator`, when an entry is created on `/ko`, **only
while CRM is on in the operator company** (otherwise nothing, and HQ
shows a warning): an account with the company name, the organisation
number when one was given and it is not a personal number, kind company,
source "Kö"; and the applicant as its contact person (role other) with
legal basis **consent**, `consent_at` = the day of the consent on `/ko`
and `informed_at` = the same day, since the form showed the privacy
policy. A consent given before the policy mentioned the CRM
(`INFORMED_FROM`, the day of version 1.4) is recorded as informed on that
day, with a note.

- When the operator already has an account with the same organisation
  number or a person with the same e-mail address, nothing is created or
  linked: `NEEDS_REVIEW`, as in 2. **Koppla** uses the existing account
  (and person); **Skapa ny** creates new ones.
- **Suppression.** If the e-mail or phone number is on the suppression
  list, or the person with that e-mail is marked Kontakta inte, nothing is
  created; the link records `SUPPRESSED` (no personal data).
- Rejected entries, and approved entries whose user already owns a
  company, get no prospect. A queue entry sent again does not change the
  prospect.

**Approval.** When the approved applicant creates their company, the
company's sync finds the prospect the sync made through
`waitlist_entries.approved_user_id` and turns it into the customer with
`ConvertToCustomer`; the company's own details then replace what was typed
in the queue. The applicant, when they are the owner, keeps their person,
now under the owner basis of 2. One account, one customer, one person.

### 4. Retention and erasure follow the queue

When a queue entry is deleted, `ReleaseWaitlistProspect` runs first, in
the same transaction. A link that belongs to a company by now (the
prospect became the customer) is only unlinked. Records the sync did not
create (linked by HQ's Koppla) are never erased here. Otherwise:

- **On request** (HQ → Radera anmälan; `DeleteWaitlistEntry` with reason
  `request`, art. 17): the person the sync created is **always** erased
  (`EraseCrmData::person`), even when someone has worked on them, and their
  e-mail and phone numbers go on the suppression list as HMAC with reason
  `ERASURE`, so a later sync cannot add them again (art. 6.1 c and f; the
  same mechanism as an objection in ADR-043, and like one, a person added
  by hand with those details is marked Kontakta inte). The account the
  sync created is erased too, unless someone has worked on it (activities
  or deals) and it is a company's: then it stays, without the person, as
  company data. A sole trader's account (the entry's number is a personal
  number) is about the person and is always erased. The toast says when a
  company's account stays.
- **By retention** (`waitlist:prune`, 30 days after a rejection, else 12
  months): the account and person the sync created are erased unless
  someone has worked on them (no activity and no deal on the account, no
  activity naming the person, the person at no other account). A person
  who stays is no longer kept on the queue consent: the legal basis
  becomes legitimate interest with the note "Kontakt i kön; behålls efter
  kontakt med personen." (the CRM's pruning clock is not moved), and the
  CRM's own pruning (24 months without activity by default) takes over.
  `TODO(legal-verify)`: the balancing test. `waitlist:prune` releases entry
  by entry; an entry whose prospect cannot be released is kept for the
  next night (and logged).

Audit: `hq.operator_prospect_released` with ids, counts and `by`.

### 5. Writing into the operator company

The sync writes into the operator company by design. It does so only
through `CurrentCompany::run($operator, …)` and its own Actions
(`SaveCustomer`, `ConvertToCustomer`, `SaveAccount`, `SavePerson`,
`LinkPerson`, `EraseCrmData`), authorised as the operator's owner: the
system has no user of its own. Every entry those Actions write while the
sync runs carries `via: operator_sync` (`Audit::withContext`), so the log
tells the sync from the owner. The instance audit log has
`hq.operator_customer_synced`, `hq.operator_prospect_synced`,
`hq.operator_review_needed` and `hq.operator_review_resolved` with ids and
the outcome. Nothing is written into the source company, and the tenants
never see the operator's data (`tests/Tenancy/OperatorSyncTenancyTest.php`).

### 6. Never failing the user's action

The company's `created`/`updated` events and `JoinWaitlist` queue
`SyncToOperator` (`ShouldQueueAfterCommit`) with the kind and id only. The
job reads the operator company from the settings when it runs. Any
exception is caught: the link gets `failed_at` and the exception class
(for a validation error also its message, never the data), the exception
is reported to the log, and HQ → Inställningar lists **Synk misslyckades**
with a **Försök igen** button (passkey). A failure can never roll back or
slow the company creation or the queue sign-up.

### 7. Backfill

`php artisan hq:sync-operator-customers` brings existing companies and
queue entries up to date: the queue first, then the companies. Idempotent
(the links make a second run change nothing; links in `NEEDS_REVIEW` wait
for HQ). `--dry-run` prints counts only and writes nothing; the normal run
prints ids and a count per outcome, never names, numbers or addresses.

### 8. Queue closed, self-registration open

- **Queue closed** (`waitlist.open` false): `/ko` shows "Kön är stängd just
  nu." and no form; `JoinWaitlist` and the form refuse a submission. Entries
  already in the queue stay. The calls to action on the public pages
  (`SignupPath`: header, hero, prices, industry, comparison and moving
  pages, the beta banner) say "Om betan" and lead to `/ko`; the landing
  page's beta note and FAQ say the queue is closed.
- **Self-registration open** (`registration.open` true): the sign-in form
  sends a code to every address and, after a correct code, asks an unknown
  address for a name and creates the account (the path from before
  ADR-038). Same rate limits as the sign-in (per IP, per address, per day,
  global per hour, wrong codes per session), the buttons are disabled
  while a request runs, and the name step checks again that registration
  is still open (or an invitation exists). Since every address gets a code,
  the answer reveals nothing about which addresses have accounts. The new
  user creates a company on `/kom-igang` as before. The calls to action say
  "Kom igång" and lead to `/logga-in`; `/ko` mentions that an account can be
  created directly. `/registrera` still leads to `/ko`.
- Defaults are today's behaviour: queue open, self-registration closed.

## Consequences

- Self-hosters see nothing new unless an HQ admin chooses an operator
  company; the switches default to ADR-038's behaviour.
- The privacy policy (version 1.4) states, without naming the company, that
  queue applicants are also kept in the service's CRM, for how long, and
  that they are erased at once on request; and that the companies and
  their owners are in the service's customer register. The texts that will
  name the operator in the privacy policy, terms and DPA are drafted in
  `docs/juridik/utkast-driftbolag.md`, not published (changed terms need 60
  days' notice, villkor.md §9).
- The register of processing and the retention table describe the
  operator's customer register and CRM.

## Review round (2026-11-03)

- Erasure on request always erases the person and suppresses their
  contact details; only a company's worked-on account stays (§4).
- A person kept after pruning moves from consent to legitimate interest
  (§4); the privacy policy says so.
- No automatic linking by organisation number or e-mail: `NEEDS_REVIEW`
  and HQ's decision (§2, §3).
- One recipient only: the separate targets for customers and the queue
  were removed (§1).
- The owner's legal basis depends on the legal form (§2).
- `informed_at` for consents before the policy change (§3).
- `via: operator_sync` on audit entries, an HKDF-derived key with a
  fingerprint for change tracking, HQ counters per operator company (§2,
  §5).

## Open

- A user who deletes their own account: the operator's CRM contact created
  for them stays under the CRM's rules. `TODO(legal-verify)`: whether it
  must be erased at once.
- The sync acts as the operator's owner (marked `via: operator_sync`); a
  dedicated system actor could replace that later.
