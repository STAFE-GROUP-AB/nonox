# ADR-068: Read access log (logg över vem som såg vad)

Status: Proposed
Date: 2026-11-08
Relates to: ADR-006 (audit log), ADR-021 (auditor access to payslips), ADR-029 (passkeys),
ADR-043 (CRM, GDPR review sections), ADR-063/ADR-064 (API, MCP, NONOX Connect), AGENTS.md §1.3, §6.6, §10

## Context

### What exists today

The behandlingshistorik (`audit_logs`, `App\Support\Audit\Audit`, ADR-006) records **changes**:
who created, posted, changed or deleted what. It is räkenskapsinformation under BFNAR 2013:2,
append-only (model events plus PostgreSQL triggers that reject `UPDATE`, `DELETE` and
`TRUNCATE`) and kept at least seven years (`docs/gdpr/lagringstider.md`, first row).

A handful of **reads** have been bolted onto the same log one at a time:

| Action | Where | What it stores |
|---|---|---|
| `payroll.payslip_viewed` | `app/Modules/Payroll/Http/PayslipController.php` | payslip id, period; not when the employee opens their own (ADR-021) |
| `payroll.absences_viewed` | `app/Modules/Payroll/Livewire/AbsenceRequestList.php` (`mount`) | up to 50 absence ids shown with causes (health data, art. 9) |
| `staff_ledger.personal_numbers_viewed` | `app/Modules/StaffLedger/Livewire/Concerns/RevealsPersonalNumbers.php` | the page class, no subject |
| `staff_ledger.control_visit_opened` | `app/Modules/StaffLedger/Actions/OpenControlVisit.php`, `RecordKioskEvents.php` | where, export flag, manager person id |
| `privacy.exported` | `app/Http/Controllers/DataSubjectExportController.php`, `app/Modules/Crm/Http/CrmPersonExportController.php` | subject kind and id |
| `payroll.file_downloaded`, `payroll.agi_downloaded`, `archive.package_downloaded`, … | various | ids, kinds |

Everything else that shows personal data is not logged at all: opening an employee
(`app/Modules/Payroll/Livewire/Employees.php`, the edit form with bank details and the
register-extract link), a CRM person (`app/Modules/Crm/Livewire/PersonCard.php`), a private
customer (`app/Livewire/Invoicing/Customers.php`), a K10 owner or income-tax partner
(`app/Livewire/Accounting/K10Page.php`, `IncomeTaxPage.php`), and every API or MCP read
(ADR-063, ADR-064).

The current approach has three problems:

1. **Coverage is accidental.** Whether a read is logged depends on whether the developer of that
   screen remembered to. `docs/gdpr/README.md` already promises "läsning loggas" for sensitive
   payroll data; there is no rule that makes the promise true across modules.
2. **Wrong retention.** A read entry in `audit_logs` inherits the seven-year retention of
   räkenskapsinformation and can never be deleted (trigger). A record that "user 17 opened payslip
   4411" is not räkenskapsinformation; it is personal data about both the employee and user 17, and
   storage limitation (art. 5.1 e) needs its own period.
3. **No way to answer the data subject.** `DataSubjectExport` (`app/Support/Privacy/DataSubjectExport.php`)
   exports what is stored about a person but not who consulted it, and the reads that are logged
   are mixed with change entries that the extract does not filter.

### Legal basis (what the law actually says, and what it does not)

No provision of the GDPR says "keep a read log". The obligation, where it exists, follows from
the risk-based duties below. This ADR treats a read log as an *appropriate measure* that the
controller (the customer company, for its employees, customers and contacts) needs NONOX to
provide, and that NONOX as processor must make available (art. 28.3 c, f and h).

- **Art. 5.1 f (integrity and confidentiality):** personal data must be processed with
  "appropriate security", including protection against "unauthorised or unlawful processing".
  A read by someone who has the role but not the reason is exactly that, and is invisible
  without a log.
- **Art. 5.2 (accountability):** the controller must be able to demonstrate compliance with 5.1.
  Without a read log a company cannot show that only the payroll staff read payslips.
- **Art. 24.1:** the controller "shall implement appropriate technical and organisational
  measures", taking account of the nature, scope, context and purposes and the risks.
- **Art. 25.2 (data protection by default):** access by default limited to what each purpose
  needs. Logging is the check that the limit holds.
- **Art. 32.1 b and d:** "the ability to ensure the ongoing confidentiality, integrity,
  availability" and a process for "regularly testing, assessing and evaluating the
  effectiveness" of the measures. Art. 32.4: people acting under the controller's authority
  must not process data "except on instructions from the controller". A read log is the usual
  way to verify 32.4.
- **Art. 15.1 and CJEU C-579/21 *Pankki S* (22 June 2023, ECLI:EU:C:2023:501):** the Court held
  that information on "consultation operations" of a data subject's data, "concerning the dates
  and purposes of those operations", is information the data subject has the right to obtain
  from the controller. It does **not** give a right to the identity of the employees who
  consulted the data under the controller's instructions, "unless that information is essential"
  for exercising the data subject's rights, and then only after balancing against the
  employees' rights and freedoms (art. 15.4: a copy "shall not adversely affect the rights and
  freedoms of others"). The Court also observed that log data may themselves be personal data.
  This judgment is the strongest reason to build the log: once a company has such a log, the
  dates and purposes in it fall within an art. 15 request, and NONOX should make answering
  that request a button, not a database query.
- **IMY.** We found no current IMY guidance that requires read logging in general business
  systems. The Datainspektionen general advice *Säkerhet för personuppgifter* (revised November
  2008, still hosted on imy.se) was written under the repealed personuppgiftslagen and is not
  binding, but it states the practice well: depending on sensitivity a behandlingshistorik
  "bör följas upp och skyddas mot otillåtna ändringar", should be detailed enough to
  investigate unauthorised use (it lists "läsning" among the operations), and "bör inte
  utformas eller utnyttjas så att den medför risk för intrång i personalens integritet".
  IMY's later material on reported incidents stresses active access control
  (behörighetsstyrning) as a basic measure. `TODO(legal-verify)`: whether IMY has issued
  current (post-2018) guidance on access logging outside health care that we should cite instead.
- **Swedish sector rules.** Health care has an explicit duty to log and systematically follow up
  access (patientdatalagen (2008:355) 4 kap. 3 § and HSLF-FS 2016:40). NONOX does not process
  patient records and that regime does not apply, but it is the Swedish benchmark for what a
  read log and its follow-up look like, and it is cited here only as such. Sick leave in payroll
  is health data under art. 9 even though PDL does not apply; that is why payroll reads are
  the first priority below. `TODO(legal-verify)`: whether any other Swedish sector rule applies
  to a bookkeeping/payroll system or to the auditors and accounting firms who use it (e.g.
  revisorslagen, penningtvättslagen record-keeping), and whether a customer in a regulated sector
  can rely on this log for its own duties.
- **The log is monitoring of employees.** Every row identifies the user who read. For the
  customer company that is processing of its own employees' personal data (art. 88, and in
  Sweden the employer's information and possibly negotiation duties under MBL 11 and 19 §§).
  `TODO(legal-verify)`: whether introducing the read log, or turning on follow-up reports, triggers
  MBL 11 § negotiation for a company with a collective agreement, as already asked for CRM goals
  in ADR-043.

## Decision

### 1. A separate table, `access_logs`, not `audit_logs`

Reads go to a new append-only table with its own retention. Changes stay in `audit_logs`.

```
access_logs
  id               bigint identity
  company_id       FK teams, not null           -- every logged read is inside one company
  actor_type       enum USER | API_TOKEN | CONNECT_APP | MCP | KIOSK | SYSTEM
  actor_id         bigint null                  -- users.id, token id, app id, device id
  on_behalf_of     bigint null                  -- users.id when a token/app/MCP acts for a user
  subject_type     string(64)                   -- morph alias: employee, payslip, crm_person, customer, staff_ledger_person, k10_owner, income_tax_partner, ...
  subject_id       string(64)
  person_ref       string(64) null              -- the data subject the row is about (see §5), as a morph alias + id
  kind             enum OPEN | REVEAL | LIST | EXPORT | DOWNLOAD
  fields           text[] null                  -- for REVEAL: field names only (personal_number, bank_account, absence_cause)
  purpose          string(48)                   -- code from a fixed enum, derived from the screen (§4)
  request_id       uuid                         -- groups rows written by one request
  created_at       timestamptz default now()
  index (company_id, person_ref, created_at)
  index (company_id, actor_type, actor_id, created_at)
  index (created_at)                            -- pruning
```

What is **never** stored, following QUICKSTART §6 and the CRM rule in ADR-043: names, personal
numbers, salaries, absence causes, free text, search terms, IP addresses or user agents. A row is
ids, enums and field names only. IP and user agent are deliberately left out: they are already in
the session and sign-in entries of `audit_logs`, and repeating them on every read would multiply
personal data about users for no added purpose (art. 5.1 c). The `request_id` lets an
investigation join a read to the sign-in session when needed.

Why not `audit_logs`:

- retention differs (seven years vs. §6 below) and `audit_logs` can never be pruned;
- volume differs by orders of magnitude; reads would bury the change history the bookkeeping law
  is about, and the ledger chain checks scan `audit_logs`;
- the access rules differ: `audit-log:view` is held by accountants and auditors
  (`app/Domain/Company/CompanyRole.php`); who-read-what is more sensitive (§7).

The existing read actions (`payroll.payslip_viewed`, `payroll.absences_viewed`,
`staff_ledger.personal_numbers_viewed`, `staff_ledger.control_visit_opened`) move to
`access_logs` going forward. Entries already written stay in `audit_logs` (they cannot be moved
or deleted, by design); the register extract and the access-log page read both sources for the
transition period. ADR-021 gets an addendum. `privacy.exported` and the file downloads stay in
`audit_logs` **and** get an `access_logs` row: an extract or a download is both an action the
company must be able to prove (audit) and a disclosure the data subject may ask about (access).

### 2. Append-only, with pruning as the only exception

- Model: `AccessLog` throws on `updating` and `deleting`, like `AuditLog`.
- Database: triggers reject `UPDATE` and `TRUNCATE` always. `DELETE` is rejected unless the
  transaction has set `SET LOCAL nonox.access_log_prune = 'on'` **and** the row is older than
  the minimum retention (`created_at < now() - interval '12 months'`), so neither a bug nor a
  compromised application user can erase recent reads. Only `privacy:prune` sets the flag.
- No foreign keys with cascade to the subject tables: deleting or anonymising an employee or CRM
  person must not delete the rows that show who read them (§6 handles erasure).
- `BelongsToCompany` on the model, with the usual tenancy tests (§9). `company_id` is never null:
  a read outside a company context is not a read of company personal data and is not logged here.

### 3. What is logged (granularity)

The rule: **log when a user is shown personal data beyond a name in a list, and log every
deliberate reveal, export or download.** A row per data subject per request, not per field
render.

| Category | OPEN (single record) | REVEAL (field shown in clear) | LIST | EXPORT / DOWNLOAD |
|---|---|---|---|---|
| Payslips | yes, every view by someone other than the employee (as today) | – | payroll run page listing payslips with amounts: yes, ids on the page | PDF, archive package with payroll, AGI file: yes |
| Employees (payroll) | yes: edit form, employee card | personal number, bank account, IBAN, emergency contact, notes, tax adjustment | employee list shows masked identity only: **no** | register extract, payroll file import download: yes |
| Absences with cause (art. 9) | yes | cause and note | yes, ids on the page (as today, limit 50) | yes |
| Staff ledger people and records | yes | personal / coordination / foreign ID in clear (as today) | list with numbers revealed: yes; masked: no | control-visit export, register extract: yes |
| CRM persons | yes: person card | – (no field is masked today) | people list: **no** | register extract, CSV export of people: yes |
| Customers and suppliers that are natural persons (private person, sole trader) | yes: customer card | personal-number-as-org-number in clear, ROT/RUT buyer's personal number | no | register extract: yes |
| K10 owners, income-tax partners, car benefits | yes | personal number in clear | no | SRU file, worksheet PDF: yes |
| Register extracts of any kind | – | – | – | yes, always |
| API, Connect, MCP reads of any of the above | yes, one row per subject returned (§8) | per field set returned | collection endpoints returning these subjects: yes, ids returned | yes |

Not logged: lists that only show names and company-level data, dashboards and aggregates
(salary totals, headcount), search suggestions (ADR-026 stores no search text and is already
in the register of processing), the user's own data (an employee opening their own payslip on
Min sida, a user exporting their own account), and reads by the system itself (PDF rendering in a
job, nightly checks).

`TODO(legal-verify)`: that leaving name-only lists unlogged is proportionate under art. 32, and
whether reading a customer card of a private person without any sensitive field needs logging at
all (we chose yes: it is cheap and is what a data subject is most likely to ask about).

### 4. Purpose is derived, not typed

*Pankki S* is about "dates and purposes". NONOX cannot know a user's reason, and asking for a
free-text reason on every click would be both useless and a new store of free text about people.
Instead each logging call site declares a fixed purpose code from an enum, chosen by where the
read happens:

`PAYROLL_PROCESSING`, `PAYROLL_REVIEW` (auditor, ADR-021), `EMPLOYEE_ADMINISTRATION`,
`ABSENCE_HANDLING`, `STAFF_LEDGER_CONTROL`, `CUSTOMER_ADMINISTRATION`, `INVOICING`,
`CRM_FOLLOW_UP`, `TAX_RETURN`, `DATA_SUBJECT_REQUEST`, `ARCHIVE_EXPORT`, `INTEGRATION`
(API/Connect/MCP), `SUPPORT` (§8).

Each code has a Swedish label for the extract ("Lönehantering", "Revision av lön", …). The
company may override labels in its own words but not add free text per row.

### 5. Whose row it is: `person_ref`

A row is about a data subject. For most subjects `subject_type/subject_id` is the person
(employee, CRM person, staff-ledger person). For a payslip or an absence the person is the
employee behind it; `person_ref` stores that (`employee:123`) so the extract and the page query
one indexed column instead of joining through every subject table. One natural person can have
several refs (an employee who is also a CRM contact); the extract is per ref, as the existing
register extracts are per register (`DataSubjectExport`), and the manual tells the company to
run the extract in each register where the person appears.

### 6. Retention and pruning of the log itself

The log is personal data about both the data subject and the reader (art. 5.1 e).

- **Default retention: 24 months** from the read, then `privacy:prune`
  (`app/Console/Commands/PrunePersonalDataCommand.php`) deletes the rows in batches under the
  prune flag (§2). Long enough to cover an art. 15 request about the last one to two years and an
  incident discovered months later; short enough not to become a permanent record of employees'
  work.
- The company may set **12 to 60 months** under Inställningar → Dataskydd (owner only), for a
  company with its own documented reason (e.g. a sector rule). The minimum of 12 is also the
  trigger's floor.
- **Erasure of the data subject** (`EraseRegisterEntry`, `EraseEmployee`, CRM erasure) does not
  delete the access rows: they document how the data were handled up to the erasure, which the
  company needs to show (art. 5.2, art. 17.3 e for legal claims). The rows hold ids only, so once
  the subject row is anonymised the access rows no longer identify the person; they are pruned on
  the normal schedule. `TODO(legal-verify)`: that keeping these id-only rows for the rest of the
  retention period after an erasure request is defensible.
- **Erasure of the reader** (`DeleteAccount`): rows keep `actor_id`; the account is pseudonymised
  as today ("Raderad användare"), so the row still shows that one person read, without a name.
- **Archive package and JSON dump** (ADR-065): the access log is **not** included. It is not
  räkenskapsinformation, and copying it into a seven-year archive would defeat §6. The company can
  export the log itself as CSV from the page (§7), which writes its own `access_logs` row.

### 7. Who may see the log

- New permission **`access-log:view`**, held by **owner** and **admin** only (admin gets every
  permission except `company:archive`, so adding it to `CompanyRole::PERMISSIONS` is enough).
  Not accountant, bookkeeper, approver or auditor: they hold `audit-log:view`, but who read whose
  payslip is the most sensitive record in the system about the company's own staff. A company
  that wants its external auditor or DPO to see it invites them as admin or (later) gets a
  dedicated role; `TODO(legal-verify)`: whether a company's DPO under art. 38.2 must be given
  access as a role of its own.
- Opening the page requires a passkey confirmation (ADR-029), like other sensitive payroll pages.
- The page: Inställningar → Dataskydd → **Åtkomstlogg** (`/installningar/dataskydd/atkomstlogg`),
  filter by person (via the register's own search, returning the ref), by user, by category and
  date. Viewing the log is itself logged (`subject_type = access_log`, `kind = LIST`), so the people
  who can see it are also visible.
- **Follow-up**, as the 2008 advice and PDL practice recommend: a monthly Att göra item for the
  owner, "Gå igenom åtkomstloggen", linking to a summary (counts per user and category, reads
  outside the user's usual categories, reads at unusual hours). No automatic judgement and no
  scoring of individual employees; the summary is a starting point for a human. `TODO(legal-verify)`:
  whether that summary is itself profiling of employees that needs information under art. 13.
- **The data subject.** The register extract gains a section **"Vem har sett mina uppgifter"**:
  per row date, category, kind and purpose label, and the reader's **role** (e.g. "Lönehandläggare
  (Administratör)", "Revisor", "Integration: API-nyckel"), **not the reader's name** by default
  (*Pankki S*, art. 15.4). The person running the extract sees a checkbox "Ta med namn på dem som
  har läst" with a short text explaining when names may be necessary (suspected unauthorised
  access) and that the company must weigh the employees' interests first; ticking it is audited
  (`privacy.exported` payload gets `with_reader_names: true`). `TODO(legal-verify)`: the wording of
  that guidance text.
- **The reader.** A user's own extract (Profil → Mina uppgifter) includes the reads they made,
  as ids, dates and categories (the reader is a data subject too).
- **The service provider.** Stafe Development AB staff never read customer personal data through
  the application without the company's instruction. If support access is ever built, every read is
  logged with `purpose = SUPPORT` and shown on the company's page; the processor agreement
  (`docs/juridik/personuppgiftsbitradesavtal.md`) is updated in the same change.

### 8. API, Connect and MCP

Reads through `/api/v1` and NONOX Connect (ADR-064) and the MCP server (ADR-063) go through the
same Actions and query objects as the UI. They log with `actor_type` = the token/app kind,
`actor_id` = token or app id, `on_behalf_of` = the user who created the key or authorised the
app, `purpose = INTEGRATION`. A collection response logs one row per returned subject in the
categories of §3. This matters most for AI assistants: a company must be able to see that an
assistant read 40 employee records last Tuesday.

### 9. Performance, tenancy and failure

- **One `INSERT` per request at most.** Call sites do not insert; they call
  `AccessLog::note($subject, Kind::Open, Purpose::PayrollProcessing, fields: [...])`, which adds to a
  request-scoped collector (a scoped singleton, de-duplicated on subject + kind + fields). A
  middleware (web, Livewire update endpoint, API) flushes the collector with **one multi-row
  insert** after the response is built and **before it is returned**. A page that lists 25 payslips
  adds exactly one query, not 25; a query-count test with a short and a long list proves it
  (QUICKSTART §6).
- **Synchronous, not deferred.** No `defer()`, no `afterResponse`, no queue: `defer()` has already
  killed PHP-FPM workers in production without a trace, and a read log that silently loses
  rows fails the very purpose (art. 5.2). If the insert fails, the request fails with a 500 and
  the data have still been rendered but not sent; the failure is reported. Jobs and console
  commands that read for the system are not logged; a job that exports on behalf of a user (archive
  package, register extract) flushes in the job, with `company_id` set explicitly
  (`CurrentCompany::run()`).
- **Livewire.** Rows are noted where the data are loaded (`mount`, the action that opens a
  record, the reveal action), not in `render()`, so a re-render does not log again. A reveal is
  logged when the user asks to reveal (as `RevealsPersonalNumbers` does today), not on every poll.
- **Size.** A company with 50 employees and daily payroll work writes a few thousand rows a month.
  The `created_at` index keeps pruning a range delete; if a large installation needs it, the table
  can later be partitioned by month without changing the API (an addendum, not now).
- **Tenancy.** `BelongsToCompany`; a tenancy test creates reads in companies A and B and asserts
  that A's owner never sees B's rows on the page, in the CSV, in the extract or via the API
  (another company's ref answers 404).

### 10. Documents to update in the implementing change

- `docs/gdpr/registerforteckning.md`: an "Åtkomstlogg" entry in section B (art. 30.2; the
  customer company is controller for the log, NONOX processor), with categories "anställda,
  kunder, kontaktpersoner och användare", data "id:n, tid, kategori, ändamål", retention
  "24 månader, 12–60 enligt företagets val". Section A gets a row only if support access (§7) is
  built. Remove "läsning loggas" claims from `docs/gdpr/README.md` that are not yet true until
  each phase ships.
- `docs/gdpr/lagringstider.md`: a row for the access log, and a note that it is not part of the
  seven-year behandlingshistorik.
- `docs/juridik/integritetspolicy.md`: a sentence in the processing table for users (reads are
  logged, what, how long, who sees it) and in the section on data subjects of customers (the right
  to know dates and purposes). That is a policy change: bump `LegalPageController::VERSION`
  and `EFFECTIVE` once, in the deploy that ships phase 1 (QUICKSTART §5; parallel branches collide
  on this bump).
- `docs/juridik/personuppgiftsbitradesavtal.md` section 4 (TOMs): the read log as a measure under
  art. 32, available to the controller.
- `docs/gdpr/konsekvensbedomning-lon.md` and `konsekvensbedomning-personalliggare.md`: the log as
  a risk-reducing measure.
- `docs/systemdokumentation/README.md`: describes `access_logs` as separate from the
  behandlingshistorik and states that it is not räkenskapsinformation (no version bump is needed
  unless accounting behaviour changes; the implementer checks `SystemDocumentationVersionsTest`).
- ADR-021 addendum: payslip views move to `access_logs`.
- Manual: a new article under Inställningar for the Åtkomstlogg page, and the register-extract
  articles get the new section. Changelog entry per phase.

### 11. Phases

| Phase | Content | Size |
|---|---|---|
| 1 | Table, triggers, model, collector + middleware, permission, purpose enum, migrate the four existing read call sites, payroll (payslips, employees, absences) and staff ledger, the page with filters and CSV, pruning, docs and policy bump | M (4–6 days incl. review) |
| 2 | Register extract section "Vem har sett mina uppgifter" with role-not-name default, user's own reads in Mina uppgifter | S (2 days) |
| 3 | CRM persons, private customers/suppliers, K10 owners, income-tax partners, car benefits | S–M (2–3 days) |
| 4 | API, Connect and MCP actors | S (2 days) |
| 5 | Monthly follow-up item and summary | S (1–2 days) |

## Alternatives considered

- **Keep using `audit_logs`.** Rejected: seven-year retention that cannot be pruned, mixes
  räkenskapsinformation with monitoring data, and `audit-log:view` is too widely held.
- **Log every page view of every record (including name-only lists).** Rejected: the log becomes
  noise, grows the store of personal data about employees, and nobody can follow it up. The
  2008 advice and art. 5.1 c both point to proportionality.
- **Log at the database level (pgaudit or `SELECT` triggers).** Rejected: PostgreSQL has no
  `SELECT` triggers; pgaudit logs statements, not which person was shown to whom, cannot tell a
  list query from a render, needs server configuration self-hosters will not have, and writes to
  the server log where retention and tenancy are uncontrolled.
- **Queue the writes.** Rejected: a lost job means a lost row, and the queue payload duplicates the
  data outside the table's retention. One synchronous multi-row insert is cheap enough.
- **Ask for a reason on every sensitive open (break-the-glass).** Rejected for v1: friction on
  routine payroll work and free text about people. Could return later for exceptional access
  (e.g. an auditor opening a payslip outside the review period) as an addendum.
- **Show the reader's name to the data subject by default.** Rejected: *Pankki S* does not require
  it and art. 15.4 requires balancing; the company can include names deliberately.

## Consequences

- A company can answer "who has looked at my salary/sick leave/personal number?" with dates,
  purposes and roles, as *Pankki S* reads art. 15, and can investigate a suspected snoop.
- One more table with personal data about the customer's own employees, kept 24 months. It is
  only as good as its coverage: new screens must call `AccessLog::note`. A test walks every route
  and Livewire component that loads a model of a logged `subject_type` and fails if no row is
  noted (the same idea as the tenancy suite).
- Reads become slightly slower (one insert per request on logging pages).
- The privacy policy changes once, with a version bump.
- `docs/gdpr/README.md` stops claiming more than the code does.

## Open questions (`TODO(legal-verify)` unless noted)

1. Whether current IMY guidance on access logging outside health care exists and should replace
   the 2008 Datainspektionen advice as the cited practice.
2. Whether any Swedish sector rule beyond PDL (none found) requires read logging for a
   bookkeeping/payroll system or for the auditors and firms using it.
3. Whether introducing the log or the monthly summary triggers MBL 11 § negotiation, and the
   information the company must give its staff (art. 13) before turning it on.
4. Whether leaving name-only lists unlogged is proportionate (art. 32).
5. The default retention (24 months) and the range (12–60).
6. Keeping id-only access rows after the data subject's erasure.
7. The guidance text for including readers' names in an extract (art. 15.4 balancing).
8. Whether a DPO must get a dedicated role with `access-log:view`.
9. Whether the follow-up summary counts as profiling of employees.
10. (Owner decision) Whether a company may turn the log off. This ADR says no for payroll, absence
    and staff ledger (art. 9 and personal numbers) and yes, per category, for CRM and customers.

## Sources

- Regulation (EU) 2016/679 (GDPR), arts. 5.1 c, e, f, 5.2, 12.3, 15.1, 15.4, 24.1, 25.2, 28.3,
  32.1 b, d, 32.4, 88, recital 39: https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32016R0679
- CJEU, C-579/21 *J.M. v Pankki S*, judgment of 22 June 2023, ECLI:EU:C:2023:501; press release
  No 107/23: https://curia.europa.eu/jcms/upload/docs/application/pdf/2023-06/cp230107en.pdf
- EDPB Guidelines 01/2022 on data subject rights – Right of access, version 2.1 (2024):
  https://www.edpb.europa.eu/documents/guideline/guidelines-012022-on-data-subject-rights-right-of-access_en
- Datainspektionen, *Säkerhet för personuppgifter*, allmänna råd, revised November 2008 (pre-GDPR,
  not binding), hosted by IMY: https://www.imy.se/globalassets/dokument/ovrigt/faktabroschyr-allmannarad-sakerhet.pdf
- IMY, *Anmälda personuppgiftsincidenter 2022* (active access control as a basic measure):
  https://www.imy.se/globalassets/dokument/rapporter/anmalda-personuppgiftsincidenter-2022.pdf
- Patientdatalagen (2008:355) 4 kap. 3 § and HSLF-FS 2016:40 (health care only; benchmark, not
  applicable). Not fetched as primary text for this ADR; `TODO(legal-verify)` before quoting.
- Repository: `app/Support/Audit/Audit.php`, `docs/adr/ADR-006-money-and-audit-log.md`,
  `docs/adr/ADR-021-auditor-access-to-payslips.md`, `docs/adr/ADR-043-crm.md` (Review round,
  GDPR), `app/Support/Privacy/DataSubjectExport.php`, `app/Actions/Privacy/*`,
  `docs/gdpr/registerforteckning.md`, `docs/gdpr/lagringstider.md`, `docs/gdpr/README.md`.
