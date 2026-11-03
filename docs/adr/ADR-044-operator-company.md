# ADR-044: The operator company, and the queue and self-registration switches

- Status: Accepted (decided by the project owner), 2026-11-03
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
| `operator.customers_company_id` | empty = the operator | where companies become customers |
| `operator.waitlist_company_id` | empty = the operator | where queue applicants become CRM prospects |
| `waitlist.open` | true | `/ko` takes applications |
| `registration.open` | false | the sign-in form creates accounts for unknown addresses |

Edited only on **HQ → Inställningar** (`/hq/installningar`): `EnsureHqAdmin`
on the route (404 for everyone else), `HqAccess::ensure` again on every
Livewire request and in `UpdateInstanceSettings`, and a fresh passkey
confirmation like the queue's decisions. A company must exist, not be
archived and not be a test company. Every change is written to the
instance audit log as `hq.settings_updated` with the changed keys' values
before and after: company ids and booleans only.

### 2. Every company becomes a customer

`SyncCompanyToOperator` makes a company a customer of the customers target.
Which companies: all except the target itself, the operator company and
the other target, and test companies (`is_test_company`, demo data). An
archived company never becomes a *new* customer; a customer it already has
is made inactive when it is archived and active again when it is taken out
of the archive. Nothing is ever deleted.

- **Where the data goes.** Name (the legal name), organisation number, VAT
  number, address, phone, and the company's e-mail or, when it has none,
  the owner's e-mail; country; 30 days' payment terms on creation. A sole
  trader's number is a personal number (`IdentityNumber`): it goes to the
  register's encrypted `personal_number`, never to `org_number`, and the
  VAT number (which contains it) is not copied.
- **Never twice.** `operator_links` (instance data) keeps one row per
  (target, source company) and per (target, queue entry), with the
  customer, CRM account and CRM person it points at, whether the sync
  created them, and keyed hashes (HMAC-SHA256) of the values last pushed,
  never the values. Without a link, the sync first takes the queue
  prospect of the company's owner (see 3), then the one customer of the
  target with the same organisation number that no other company is
  linked to (it is linked as it is, nothing overwritten), and only then
  creates a customer. A customer removed in the target after it was linked
  is not created again.
- **Kept in step.** A change of a watched company field (name, legal name,
  numbers, e-mail, phone, address, country, archived, owner) queues a sync.
  Only the fields whose value changed in the source since the last push
  are written, so the operator's own edits of other fields (an invoice
  address, payment terms) stay. A customer anonymised or restricted under
  GDPR in the target is never written to again.
- **CRM.** With CRM on in the target, the customer register's own observer
  gives the customer an account (ADR-043), and the company's owner becomes
  its contact person with role decision maker and legal basis **agreement**
  (`SavePerson`, `LinkPerson`). An address on the target's suppression
  list, or a person there marked Kontakta inte, is not added or linked. A
  new owner is added on the next sync; the former owner stays with the
  CRM's own pruning. Turning CRM on later: the backfill adds the owners.

### 3. Every queue applicant becomes a CRM prospect

`SyncWaitlistEntryToOperator`, when an entry is created on `/ko`, in the
waitlist target, **only while CRM is on there** (otherwise nothing, and HQ
shows a warning): an account with the company name, the organisation number
when one was given and it is not a personal number, kind company, source
"Kö"; and the applicant as its contact person (role other) with legal basis
**consent**, `consent_at` = the day of the consent on `/ko` and
`informed_at` = the same day, since the form showed the privacy policy.

- An account with the same organisation number and a person with the same
  e-mail address are reused (and then never erased by the sync).
- **Suppression.** If the e-mail or phone number is on the target's
  suppression list (an earlier objection, art. 21(3)), or the person with
  that e-mail is marked Kontakta inte, nothing is created; the link records
  `SUPPRESSED` (no personal data) so it is not tried again.
- Rejected entries, and approved entries whose user already owns a
  company, get no prospect.
- A queue entry sent again (an update of the open entry) does not change
  the prospect.

**Approval.** When the approved applicant creates their company, the
company's sync finds the prospect through `waitlist_entries.approved_user_id`
(only when customers and queue go to the same company and CRM is on) and
turns it into the customer with `ConvertToCustomer`; the company's own
details then replace what was typed in the queue. The applicant, when they
are the owner, keeps their person, now under legal basis agreement. One
account, one customer, one person.

### 4. Retention and erasure follow the queue

When a queue entry is deleted (HQ **Radera anmälan**, also on the
applicant's request, and `waitlist:prune` after 30 days for rejected and 12
months for other entries), `ReleaseWaitlistProspect` runs first, in the
same transaction:

- The link belongs to a company by now (the prospect became the customer):
  only unlinked. The customer is in the register and follows its rules.
- Otherwise, through `EraseCrmData`: the **account** is erased when the
  sync created it and nobody has worked on it: it is not a customer and
  has no activity and no deal. The **person** is erased when the sync
  created them, no activity names them, and they are at no other account
  (an account that stays counts as another account).
- Whatever someone has worked on stays, unlinked, for the CRM's own
  pruning (no activity for 24 months by default) or an erasure by hand.
  HQ says so in the toast after deleting the entry, so an erasure request
  is finished by hand in the CRM.

`waitlist:prune` releases entry by entry; an entry whose prospect cannot be
released is kept for the next night (and logged). Audit:
`hq.operator_prospect_released` with ids and counts.

`TODO(legal-verify)`: whether a prospect a seller has worked on may stay
after the applicant asked for erasure of the queue entry, or must be erased
in the CRM too.

### 5. Writing into another company

The sync writes into the target company by design. It does so only through
`CurrentCompany::run($target, …)` and the target's own Actions
(`SaveCustomer`, `ConvertToCustomer`, `SaveAccount`, `SavePerson`,
`LinkPerson`, `EraseCrmData`), authorised as the target's owner: the system
has no user of its own, and the owner holds every permission. The target's
audit log therefore shows its owner on `customer.created` and the CRM
entries; the instance audit log has `hq.operator_customer_synced` and
`hq.operator_prospect_synced` with ids and the outcome. Nothing is written
into the source company, and the tenants never see the target's data
(`tests/Tenancy/OperatorSyncTenancyTest.php`).

### 6. Never failing the user's action

The company's `created`/`updated` events and `JoinWaitlist` queue
`SyncToOperator` (`ShouldQueueAfterCommit`) with the kind and id only. The
job reads the target from the settings when it runs. Any exception is
caught: the link gets `failed_at` and the exception class (for a validation
error also its message, never the data), the exception is reported to the
log, and HQ → Inställningar lists **Synk misslyckades** with a **Försök
igen** button (passkey). A failure can never roll back or slow the company
creation or the queue sign-up.

### 7. Backfill

`php artisan hq:sync-operator-customers` brings existing companies and
queue entries up to date: the queue first, then the companies. Idempotent
(the links make a second run change nothing). `--dry-run` prints counts
only and writes nothing; the normal run prints ids and a count per outcome,
never names, numbers or addresses.

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
- The privacy policy (version 1.3) states that queue applicants' details are
  also kept in the service's customer register/CRM. The texts that will name
  the operator company in the privacy policy, terms and DPA are drafted in
  `docs/juridik/utkast-driftbolag.md`, not published.
- The register of processing (`docs/gdpr/registerforteckning.md`) and the
  retention table (`docs/gdpr/lagringstider.md`) describe the operator's
  customer register and CRM.

## Open

- A user who deletes their own account: the operator's CRM contact created
  for them stays under the CRM's rules (agreement, pruned after inactivity).
  `TODO(legal-verify)`: whether it must be erased at once.
- The sync acts as the target's owner; a dedicated system actor in the
  audit log could replace that later.
