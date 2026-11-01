# ADR-028: The firm workspace (Mina företag) and tasks

Status: proposed, 2026-11-01

## Context

The owner (2026-11-01): "Har jag fler företag i Mina företag så borde
översikten finnas där också, men aggregerat, och möjligheten att delegera
uppgifter inom systemet. Tänk hur en redovisningsbyrå skulle drömma om att
arbeta effektivt med sina klienter."

Mina företag (`companies.overview`) already lists every company the user
belongs to with what waits there (`CompanyOverview::for`), a month-by-month
client table (`CompanyOverview::months`), a responsible consultant and the
firm's note (`ClientProfile`). What is missing is one aggregated picture, a
single prioritised to-do list across clients, a month-close board and a way
to hand work to a colleague or to the client.

## Decisions

### 1. Mina företag becomes the firm's workspace

Tabs, all under `/mina-foretag` (`?vy=`):

| Tab | `vy` | What it shows |
|---|---|---|
| Översikt (default) | `oversikt` | Totals across clients (bank and cash, customer receivables, supplier payables, VAT to pay) and **Att göra**: the system's to-dos (VAT, AGI, year-end, annual report, overdue invoices, approvals, payments, drafts, inbox, payroll) and open tasks in one list, sorted overdue → due date → priority. Filters: responsible consultant, deadline (this week / this month / overdue), type, status; saved views per user. |
| Månadsstängning | `stangning` | One row per client, columns for the month's steps. |
| Månad för månad | `manad` | Unchanged. |
| Per företag | `foretag` | Unchanged (the per-company cards). The old `?vy=uppgifter` redirects to `/uppgifter`. |

Every item names its company and opens **in** that company: the form posts to
`current-team.update` (`PUT /aktuellt-foretag`), which already only accepts a
company the user belongs to and only known destinations. Two destinations
are added: `task` (with `uppgift=<id>`, checked to belong to the company after
the switch, else the task list) and the record routes used by task links.

The new tabs are separate Livewire components (`App\Livewire\Firm\*`)
embedded by `CompanyOverviewPage::render`, so the existing page changes in
two lines and merges easily with the archiving work on the same page.

### 2. Totals and the to-do list without a query per company

`App\Domain\Firm\FirmWorkspace` reads all of the user's companies at once,
`whereIn('company_id', $ids)` with the ids taken from the user's own
memberships, inside `CompanyScope::bypass` with a comment. Each total is
one grouped query (bank/VAT from the posted ledger of the fiscal year that
contains today plus its opening balances, like `BusinessKpis`; receivables
and payables from open invoices). A figure is included for a company only
when the user's role there may read it (`reports:view`, `invoices:view`,
payables as `SupplierInvoicePolicy::canSeePayables`). The to-do list reuses
`CompanyOverview::for` and adds annual reports, the inbox and tasks.

Measured target: a bounded number of queries independent of the number of
companies (asserted in tests for 1 and 25 companies; see the PR for numbers
at 100).

The result is cached per user for 10 minutes as plain arrays. The cache key
contains a version per company (`firm:version:{companyId}`, read with one
`Cache::many`), bumped on every task change, so a colleague's new task shows
at once. A "Läs in på nytt" button forgets the user's entry.

### 3. Tasks (`tasks`)

```
tasks              id, company_id, title, description, type, status, priority, due_date,
                   assignee_id, created_by, completed_at, completed_by,
                   subject_type, subject_id (allow-listed link), period (YYYY-MM, recurring),
                   recurrence_id, timestamps
task_comments      id, company_id, task_id, user_id, body, timestamps
task_checklist_items id, company_id, task_id, label, position, done_at, done_by
task_recurrences   id, company_id, title, description, type, frequency (MONTHLY/QUARTERLY/YEARLY),
                   due_day, assignee_id, active, created_by, timestamps
month_close_checks id, company_id, month (YYYY-MM), step, checked_by, checked_at
saved_views        id, user_id, page, name, filters (jsonb)  — per user, not per company
notifications      Laravel's database notifications
users.task_digest  NONE | DAILY
```

- Statuses: `TODO` (Att göra), `IN_PROGRESS` (Pågår), `WAITING_FOR_CLIENT`
  (Väntar på kund), `DONE` (Klar). Types: VAT, AGI, YEAR_END, ANNUAL_REPORT,
  INVOICES, APPROVAL, BANK, INBOX, PAYROLL, DOCUMENTS (underlag), OTHER.
- All task tables use `BelongsToCompany` and are in the tenancy suite.
- A task may link one record of the same company: voucher, customer
  invoice, supplier invoice, payroll run, inbox message, VAT report. The
  link is checked inside the company scope, so another company's id is
  refused as "not found".
- Tasks are not räkenskapsinformation; they are deleted with the company.
  Create, assign, status changes, completion and deletion are written to
  the audit log (only metadata, not the text).

### 4. Permissions

Two new core permissions (added to `CompanyRole::PERMISSIONS`):

| Permission | Roles |
|---|---|
| `tasks:manage` | owner, admin, accountant, bookkeeper |
| `tasks:view` | owner, admin, accountant, bookkeeper, approver, auditor |

Rules:
- Create, edit, assign, delete and manage recurring tasks: `tasks:manage`.
- See all of a company's tasks: `tasks:view`.
- The **assignee** always sees, comments on and changes the status of their
  own task, whatever their role (also a client with only a module role), so
  work can be delegated to the client ("Väntar på kund": "Skicka kvittot
  för köpet den 12 oktober").
- The assignee must be the owner or a member of the task's company. Anyone
  else answers 404 (the same answer as for a user that does not exist), so
  membership of other companies is not revealed.
- Auditors read only.

This ADR is the record for the AGENTS.md §4 permission list; §1 is not
touched.

### 5. Recurring tasks

`tasks:generate-recurring` runs daily (05:10) and creates the period's
task for every active recurrence: the period key is `YYYY-MM` (monthly),
the quarter's first month (quarterly) or January (yearly), with
`unique(recurrence_id, period)` so a second run creates nothing. The due
date is `due_day` in the month after the period (e.g. VAT for October due
the 12th of November) — the user sets the day; NONOX does not decide
Skatteverket's deadlines here.

### 6. Notifications

Laravel database notifications (`notifications` table). The user is told
when a task is assigned to them, when someone comments on a task they
created or are assigned, and when a task they created is completed. The
bell (sidebar and phone bar) shows the unread count (one query) and opens
`/notiser`. A notification stores the company id and task id; opening it
goes through the company switch, so a user removed from the company gets
404.

Email: the user may choose a daily digest (on Uppgifter, `/uppgifter`: "E-post
varje vardag"). `tasks:send-digest` (weekdays 06:45) sends one
`TaskDigestMail` to each such user with open tasks assigned to them across
their companies. No email per event.

### 7. Month-close board

Steps: bank reconciled, supplier invoices, customer invoices, VAT, payroll/
AGI, accruals, report sent. Status per cell is derived from data where it
can be (a bank reconciliation dated at or after the month end, no
supplier invoices waiting for approval dated in or before the month, no
customer invoice drafts, the VAT and AGI states from `CompanyOverview`, an
accrual posting for the month), and otherwise checked off by hand
(`month_close_checks`, who and when). A hand check-off overrides a derived
"to do"; it can be undone. Months are kept, so the board shows any earlier
month.

### 8. Per-company Översikt

`App\Support\Overview\TaskTodoProvider` implements the Översikt's
`PersonalTodoProvider` (ADR-027) and is tagged `TodoProviders::TAG`: the
tasks assigned to the signed-in user in the current company show in "Att
göra", cached per user.

### 9. Uppgifter is its own page (owner, 2026-11-01)

Tasks are not a tab of Mina företag. `/uppgifter` (`tasks.index`) spans
every company the user belongs to, with the views Till mig (default), Alla
klienter (every open task in companies where the user holds `tasks:view`),
Väntar på kund, Lämnade till andra and Klara (30 days), each with its count
from one aggregate query. Filters (company, Hos byrån/kunden/ingen, due,
type, search) live in the URL. A row opens the task in its own company
through the company switch; the round button marks it done (or opens it
again) for the assignee or anyone with `tasks:manage` there. A page of 40
tasks is a fixed number of queries, whatever the number of companies.
`/mina-uppgifter` redirects to `/uppgifter`; recurring tasks stay per
company at `/uppgifter/aterkommande`.

### 10. Byrån and Kunden

Delegation works both ways inside one client company: the firm hands work
to the client ("Väntar på kund") and the client hands it back to the firm.
**Firm membership is derived from the role**: a member with the
Redovisningskonsult role (`accountant`, `ClientProfile::FIRM_ROLE`) is the
firm; everyone else, the owner included, is the client. This is the rule the
firm's note and the firm activity panel already use, so no new flag is
stored. A company without consultants has only one group ("I företaget").

- The assignee picker is grouped "Byrån" / "Kunden" (`TaskPeople::of`).
- When the firm picks a client person the status becomes "Väntar på kund";
  picking a firm person makes it "Att göra". The user can change it.
- A client user with `tasks:manage` (owner, admin, bookkeeper) can create
  tasks and assign them to the consultant; an assignee of any role works
  on their own task.
- Every assignment notifies the assignee (bell, and the digest for those
  who chose it) and is audited as `task.assigned` with the side.
- Only the owner or members of that company can be assigned (404 otherwise).
- A firm consultant who should not be treated as the firm can be given
  another role; a firm that uses the Bokförare role for its own staff will
  see them under Kunden. Open question below.

### 11. Archived companies

Archived companies (ADR-024) are left out of the totals, Att göra and the
month-close board, and no task can be created or changed there (the same
read-only rule as `User::hasTeamPermission`). Their tasks stay readable.

## Consequences

- Task comments are free text and may contain personal data; listed in
  `docs/gdpr/registerforteckning.md`.
- `TODO(legal-verify)`: the annual-report deadline shown on Mina företag
  (seven months after the end of the fiscal year for aktiebolag, ÅRL 8 kap.
  3 §) — shown as a reminder only.

## Open questions

- Firm vs client is taken from the Redovisningskonsult role. Should a firm be
  able to mark more of its staff (for example bookkeepers) as "Byrån" with a
  membership flag?

- Should a firm be able to keep some tasks internal (hidden from the
  client's own users)? v1 shows all of a company's tasks to everyone with
  `tasks:view`, the client's owner included.
