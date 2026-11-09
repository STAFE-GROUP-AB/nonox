# ADR-078: Status page and security policy

Status: Accepted (built 2026-11-08)
Date: 2026-11-08
Relates to: ADR-029 (passkeys), ADR-034 (open banking), ADR-038 (open beta, waiting list, /hq),
ADR-044 (operator company, instance settings), ADR-050 (Peppol), ADR-054 (Skatteverket APIs),
`docs/gdpr/incidentrutin.md`, `docs/gdpr/sakerhetskopiering.md`, `SECURITY.md`,
AGENTS.md §1.3, §6.4, §10

## Context

The roadmap item `statussida-och-sakerhet` asks for three things: a page that says whether the
service is running and what has gone wrong lately, a published security policy with an address
for reporting vulnerabilities, and an independent penetration test. The first two are code and
text. The third is a purchase and is tracked separately in the roadmap item.

What exists today, mapped before deciding:

| Part | State |
|---|---|
| `GET /up` | Laravel's built-in health route (`bootstrap/app.php`). Answers 200 when the app boots. For load balancers, not for people. |
| `ledger:verify --all` | Scheduled 03:15 daily. It writes `ledger.verified` to the audit log per company. There is no instance-wide record of "the nightly check ran". |
| Scheduler | `routes/console.php` and the module providers. `connect:webhooks` runs every minute. Nothing records that the scheduler is alive. |
| Queue | Laravel's queue. Horizon is not installed (`composer.json`). The default connection is `sync` in `.env.example`. |
| Backups | An operator concern (`docs/gdpr/sakerhetskopiering.md`, `scripts/restore-test.sh`). The application cannot see a backup. |
| External connections | `IntegrationHealth` (ADR-034, ADR-050) already counts bank and Peppol problems across companies for /hq. `Skatteverket::isConfigured()` exists. |
| Security text | `SECURITY.md` (English, in the repository), the page `/sakerhet` (review grades) and `docs/juridik/*.md` rendered by `LegalPageController`. There is no `security.txt`. |
| /hq | `EnsureHqAdmin` (404 for everyone else), passkey step-up for decisions, `instance_settings` (ADR-044). |

## Decision

Decided by Lead 2026-11-08 under the owner's mandate. Open for the consultant's review.

### 1. A public status page at `/status`

A Swedish page, no sign-in, linked from the footer, `/sakerhet` and the security policy, and
listed in `sitemap.xml`. It shows one banner (all fine, planned maintenance, disruption,
outage) and a list of parts with a state, a one-line note and, where it means something, the
time of the last success.

**What it shows, and only if the instance can know it:**

| Part | How it is known |
|---|---|
| Web app | The page answered. |
| Database | `select 1`. If it fails the page still renders, from a static fallback that is never cached, with the state "outage". |
| Background jobs | For the `database` queue driver: the age of the oldest unreserved job (10 minutes = disruption, 60 minutes = outage). For `sync` and `null`: "runs directly". For any other driver: "not measured". Horizon is not installed, so nothing here depends on it. |
| Scheduled jobs | A heartbeat from the scheduler (see 2). Late after 10 minutes. |
| Nightly integrity check | A heartbeat from `ledger:verify --all` (late after 36 hours). The page says only that the check ran, with the time, or that it did not run or could not finish. What it found never appears here: findings go to the error log and to /hq → Driftstatus (see 2). |
| Backup, restore test | Only if the operator's scripts report them (see 2). If nothing has ever been reported the rows are left out, not shown as "unknown", since an installation that cannot know should not look as if it does. |
| Peppol, bank | Set up or not, paused by the kill switch or not, the sync heartbeat (late or failed), and a widespread-failure flag: at least a quarter of at least three connections failing. A problem at one company is not a public signal and stays in /hq → Integrationer. |
| Skatteverket | Set up or not. There is no measurement, and the page says so ("Anslutningen mäts inte"). |

**What it never shows:** a company, a user, an organisation number, a count of customers or of
failing connections, an error message, a driver name, a key or any part of one, a host name
other than the site's own. The snapshot is a list of states and fixed Swedish sentences; the
only free text on the page is the operator's incident text (3).

**Cost.** The page reads one cached snapshot (`StatusSnapshot::get()`, `Cache::remember`, 60
seconds), rebuilt under a cache lock so that a burst of requests asks the database once. If the
cache or the database fails the page falls back to a static "outage" snapshot built outside the
cache. A cold build is six queries with no incidents and seven with any, whatever the
number of incidents, companies or users; a test pins that the count does not grow with the
number of incidents. A warm request runs no query for the snapshot. Publishing in /hq and every
heartbeat clears the cache, so the operator sees their own change at once. The page is not
personalised.

**Overall state** is the worst of the parts and of the open incidents. `Unknown` and `Info`
never count. A paused connection counts as "maintenance". The states are text and a symbol,
never colour alone (WCAG 2.2).

### 2. Heartbeats: how the instance knows

A table `status_heartbeats` (key, last run, last success, last failure) holds one row per key.

- **Scheduler.** A listener on `ScheduledTaskFinished` and `ScheduledTaskFailed` records the
  commands in a short allow-list: `connect:webhooks` (the scheduler is alive, it runs every
  minute), `ledger:verify`, `peppol:sync`, `bank:fetch`. Other commands are ignored, so the
  table stays tiny and a new job does not appear on a public page by accident. A non-zero exit
  code counts as a failure. A failure to write the heartbeat never stops the job.
- **Integrity exit codes.** `ledger:verify` exits 0 when intact, 3 (`EXIT_DEVIATIONS`) when it ran
  and found deviations, and any other non-zero code when it crashed. A deviation run counts as
  completed for the public "ran" row, writes `Log::error` with company ids only (the operator's
  alert) and shows in /hq → Driftstatus; a crash counts as "could not finish". A run skipped
  because the overlap mutex was held leaves no exit code and is not recorded at all. The cache is
  cleared only when a heartbeat changes between healthy and failed.
- **Operator scripts.** `php artisan status:heartbeat backup [--failed]` and
  `status:heartbeat restore-test [--failed]`. Only these two keys are accepted. A backup script
  appends one line to its own run. `scripts/restore-test.sh` calls the command when
  `NONOX_STATUS_HEARTBEAT=1`, so a self-hoster testing on another machine does not write to a
  database by accident.

Lateness thresholds: scheduler 10 minutes, Peppol sync 2 hours, bank fetch 3 hours, integrity
and backup 36 hours, restore test 9 days.

### 3. Incident notes, managed in /hq

New screen `/hq/status` ("Driftstatus"), for HQ admins only. Two tables, `status_incidents`
(title, severity, status, started, resolved) and `status_incident_updates` (the history).

- **Severity:** information, planned maintenance, disruption, outage. Only the last three
  change the banner, and only while the incident is open.
- **Status:** utreds, orsak funnen, övervakas, åtgärdad. "Åtgärdad" closes it and sets the
  resolved time; a later note with another status opens it again.
- **Create, annotate, resolve, reopen, delete.** Deleting exists for a note that should never
  have been public; resolved incidents are history and normally stay.
- **Retention on the page:** open incidents, and incidents resolved within the last 90 days
  (at most 50). The rows stay in the database.
- **Permissions.** `HqAccess::ensure` on the route, in the Livewire component (Livewire's
  update requests do not pass route middleware) and in every action. Writing is a decision and
  needs the fresh passkey confirmation like the other HQ screens (ADR-029, ADR-038).
- **Audit.** `hq.status_incident_opened | updated | deleted` in the instance audit log with the
  id, severity and status values and counts, never the text. Incident text is operator-written
  public copy, so it is not personal data by design; the screen says so ("skriv aldrig namn på
  företag eller personer"). The `created_by` user id is kept for accountability and is not
  shown.
- **Output.** Escaped, line breaks kept. No Markdown and no links, so an incident note cannot
  carry a phishing link by mistake.

A status page is only honest if a person writes when something is wrong that the automatic
checks cannot see. The automatic parts say what they can measure; the incident notes say the
rest.

### 4. `/.well-known/security.txt` (RFC 9116)

Built against RFC 9116 (rfc-editor.org/rfc/rfc9116), read 2026-11-08:

| Field | Value | RFC |
|---|---|---|
| `Contact` | Required. The instance setting `security.contact` (an e-mail address or an `https://` address) set in /hq → Inställningar; else `NONOX_SECURITY_EMAIL`; else `NONOX_PRIVACY_EMAIL`. An e-mail address is written as `mailto:`. A web address must be `https`. | 2.5.3 |
| `Expires` | Required, exactly once, RFC 3339 UTC. See below. | 2.5.5 |
| `Preferred-Languages` | `sv, en` | 2.5.8 |
| `Policy` | `https://<host>/sakerhetspolicy`, only when the policy page is published (5); otherwise the line is left out. Always `https`. | 2.5.7 |
| `Canonical` | `https://<host>/.well-known/security.txt` | 2.5.2 |

- **No contact, no file.** Without a `Contact` the file is not valid (section 2.5.3), so the
  route answers 404. A self-hoster who has set nothing therefore publishes no security.txt
  claiming a contact that does not exist.
- **Headers and location.** `Content-Type: text/plain; charset=utf-8`, LF line endings (section
  2.2), served at `/.well-known/security.txt` (section 3). `/security.txt` redirects there with
  301, which section 3 allows for legacy compatibility.
- **Expires is the date of the last confirmation plus 364 days (Lead decision 2026-11-09).** It
  is the `security.contact` setting's `updated_at` plus 364 days, or, when the contact comes from
  the environment, the build date plus 364 days. It is not renewed by being read, so a file nobody
  looks after goes stale and says so. With an environment-sourced contact the date follows the build, so every deploy renews it; we accept that, since a deployed installation is a maintained one, and an operator who wants a deliberate renewal sets the contact in /hq. /hq → Inställningar shows the date, warns 30 days before it
  (and after), and has a passkey-confirmed button "Bekräfta säkerhetskontakten" that renews it.
  The first version computed "today + 180 days" on every request; the review rejected that as a
  field that always claims freshness. 364 days (Lead decision) leaves no edge case against the RFC ("less than a year").
- **URLs come from `APP_URL`**, never from the request's Host header, so a forged Host cannot
  change `Canonical` or `Policy`.
- **Not included:** `Encryption` and a cleartext signature (section 2.3 recommends but does not
  require them; we have no published key yet), `Acknowledgments`, `Hiring`. Adding `Encryption`
  later is an open question.
- **Self-hosting.** Every installation serves its own file from its own settings; nothing names
  the hosted service. The security policy page uses the same contact.

### 5. The public security policy at `/sakerhetspolicy`

A Swedish document in `docs/juridik/sakerhetspolicy.md`, rendered by `LegalPageController` with
placeholders for the operator, the contact, the status page and `security.txt`. It mirrors
`SECURITY.md` (which stays the English source in the repository) and covers: how to report,
what we promise (3 working days to acknowledge, 10 to assess, severity-based fix, an agreed
disclosure date; all marked as targets, not guarantees), scope and out of scope, and a
section "Skydd för dig som rapporterar".

**The page is off until a lawyer has read it (Lead decision 2026-11-09).**
`NONOX_SECURITY_POLICY_PUBLISHED` defaults to false. While it is false there is no
`/sakerhetspolicy` (404), no footer or sitemap link, and no `Policy` line in security.txt;
Contact still works. The hosted service turns it on only after the legal review. A self-hosted
installation can turn it on only with a company name set, and then gets a neutral text with no
waiver ("Om testning"); the waiver and the GitHub reporting line are shown only when
`NONOX_HOSTED` is on (`hosted:start` markers in the Markdown, `SecurityPolicy::filter`).

**Safe harbour, kept modest (hosted service only).** We say that we will start no legal action
and make no police report against someone who acts in good faith inside the policy, with four
conditions (report to us and allow time; test only accounts you created yourself; do not
degrade the service; do not demand payment), and "if you see other people's data: stop at once,
save, copy or change nothing, and tell us".
We add, in the same section, that this binds only us, that we cannot waive third parties'
rights or stop an authority, and that the policy is no permission to break the law. We do not
promise immunity, we do not state that a given act is lawful, and we do not run a bounty. A
company cannot waive the state's prosecution of an offence; promising "no police report" is the
most we can offer, and even that is conditional.

`TODO(legal-verify)`: question 35 in `docs/juridik/fragor-till-jurist.md`; the page carries the
marker as a stripped comment, and a test checks that it stays until someone removes it
deliberately. The policy tells reporters how their contact details are handled and for how long
(two years after the case closes, `docs/gdpr/registerforteckning.md` and `lagringstider.md`) and
links the privacy policy. The privacy policy text itself is unchanged; the owner decides whether
security reports belong in it (1.7). No bump of `LegalPageController::VERSION`.

### 6. Penetration test

Not code. The roadmap item stays open for it. When a test is done, its summary goes into the
review on `/sakerhet` (`SecurityReview`), not onto the status page.

## Consequences

- Three tables, all instance data without `company_id`: no `BelongsToCompany`, no tenancy test
  entry. The tenancy suite is satisfied by the leakage test, which creates a company and a user
  and asserts that nothing about them is in the page.
- `InstanceSettings` gains one key, `security.contact`, editable in /hq → Inställningar. The
  value is logged only as "set", never as the address (it may be a person's mailbox).
- The footer of every public page gains two links; `sitemap.xml` gains two entries.
- Operators who want the backup row add one line to their backup script. Until they do, the
  page does not mention backups, which is the intended honest behaviour. The hosted service must
  add it (open item).
- `docs/gdpr/sakerhetskopiering.md` and `SECURITY.md` mention the new page and the heartbeat.
- No personal data is added: incident text is public by rule, and the only person-linked field
  is the admin's user id on the incident, kept like the user id on any audit entry.

## Open questions

1. **Legal read** of the safe-harbour section (see 5).
2. **Hosted service**: add `status:heartbeat backup` to the nightly backup job on the hosted
   server and `NONOX_STATUS_HEARTBEAT=1` to the weekly restore test. Owner/operator action.
3. **Encryption key** for `security.txt`: publish a PGP key and add `Encryption`, or leave it.
4. **A real uptime probe from outside.** The page runs on the app it describes, so a full outage
   of the app cannot be reported by the app. An external monitor (and a static fallback page on
   another host) would close that gap. Out of scope here; the page says only what the app can
   know.
5. **Subscriptions** (RSS/e-mail for incidents) are not built.
6. **Penetration test** purchase and scope.
7. **Route the `ledger:verify` error to a person.** On deviations the command writes `Log::error`
   (company ids only). On the hosted service that log line must reach mail or Nightwatch, or nobody
   is told, since the public page deliberately says only that the check ran. Operator action; noted
   in `docs/drift/statussida-och-larm.md`.
