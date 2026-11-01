# ADR-024: Company archiving and ownership transfer

Status: accepted, 2026-11-01

## Context

AGENTS.md §3.5 says companies are never hard-deleted while they have postings
within the retention period: "delete" means archive (read-only) and export the
archive package. §4 gives the owner alone the right to archive a company and to
transfer ownership. `teams.archived_at` and `Company::isArchived()` existed,
but nothing used them, and there was no way to archive or to hand a company
over. "Radera mitt konto" (`DeleteAccount`) already refuses owners of companies
that are not archived and tells them to transfer or archive first. The
security review listed "an archived company is not read-only" as an open item.

## Decisions

### Ownership transfer

- `TransferOwnership`: only the owner (`CompanyPolicy::transferOwnership`,
  `ownsTeam`). The new owner must already be a member with one of the company
  roles (`CompanyRole`: admin, accountant, bookkeeper, approver, auditor).
  Module-only roles (an employee on Min sida, staff in the staff ledger) and
  pseudonymised accounts are refused, so a company is never handed to someone
  who cannot run it.
- `teams.user_id` becomes the new owner; the new owner's `team_user` row is
  removed (Jetstream keeps the owner outside `team_user`), and the previous
  owner gets a `team_user` row as `admin`. One transaction with a row lock on
  the company. Audit entry `company.ownership_transferred` with both user ids
  and both roles.
- Allowed also while the company is archived: ownership is not
  räkenskapsinformation, and an owner who wants to delete their account must
  be able to leave an archived company to someone.
- No e-mail to the new owner in this version; the change is in the
  behandlingshistorik and visible at once in the company list.

### Archiving

- `ArchiveCompany` and `UnarchiveCompany`: owner only (`company:archive`, which
  no member role has). Archiving sets `archived_at`; nothing is deleted, so the
  retention rules of BFL 7 kap. are untouched.
- Before archiving, `ArchiveReadiness` lists every fiscal year with its latest
  finished archive package. A package is **current** when it was finished at
  or after the year's last posting. The owner can order the missing packages
  from the same page (they are built by the existing `CreateArchivePackage`
  job). When any year lacks a current package, archiving requires an explicit
  confirmation that the records are kept another way; the audit entry
  `company.archived` stores the package ids, the years without a package and
  the confirmation.

### What read-only means, and where it is enforced

Two layers, both in `App\Support\Tenancy\ArchivedCompany`:

1. **Permissions.** `User::hasTeamPermission` grants, in an archived company,
   only `ArchivedCompany::READ`: `vouchers:view`, `invoices:view`,
   `reports:view`, `audit-log:view`, `export:sie`, `export:archive`,
   `company:archive` (to unarchive), and the module read permissions
   `payroll:view`, `payroll:view-sensitive`, `payroll:archive-view`,
   `self:payslips`, `staff-ledger:view`, `staff-ledger:view-sensitive`,
   `inbox:view`. Every policy, action, Livewire page and API endpoint already
   asks for a permission, so every write is refused and buttons shown by
   permission disappear without touching each view.
2. **Model guard.** `BelongsToCompany` refuses to create or update a
   company-scoped row of an archived company (`CompanyIsArchived`, HTTP 403),
   for code that writes without asking for a permission: scheduled commands,
   queued jobs, the staff-ledger kiosk. Whether a company is archived is
   cached per request or job in `CompanyContext` and forgotten whenever the
   company is saved. Deletes are not refused: posted data is protected by the
   database triggers, users cannot delete without a write permission, and the
   retention clean-up must keep deleting personal data that has outlived its
   purpose.

Exceptions, each explicit in code:

- `WritableWhenArchived` models: `ArchivePackage` (packages can still be made
  and downloaded) and `ClientProfile` (an accounting firm's own note about a
  client, not the client's records).
- `ArchivedCompany::reading()` around the data-subject exports
  (registerutdrag), which are guarded by a write permission but only read;
  GDPR art. 15 applies for as long as the data is kept.
- `ArchivedCompany::maintenance()` around `privacy:prune`, which clears stale
  tokens and moves the staff ledger's chain anchor while removing old
  entries.
- Inbound mail to an archived company is dropped and logged
  (`inbox.dropped`, reason `company_archived`); the shared address does not
  route to archived companies, and IMAP fetch and the daily summary skip
  them. Recurring invoices (`invoices:run-agreements`) and staff-ledger
  reminders skip archived companies.
- `LivewireCompanyGuard` adds the archived state to the access fingerprint,
  so a page kept open while the company is archived answers 404 and is
  reloaded read-only.

### Interface

- Företagsinställningar has a tab "Ägarskap och arkiv" (`?flik=agarskap`),
  shown to the owner only, with transfer, the archive-package list, archive
  and unarchive. Every action has a confirmation in plain Swedish and a toast.
- Every page of an archived company shows the banner "Företaget är arkiverat
  och kan bara läsas", with a link to unarchive for the owner.
- The company switcher and Mina företag show archived companies last, dimmed
  and labelled "Arkiverat". Nothing waits in an archived company.

## Consequences

- Owners can now delete their account after archiving or handing over their
  companies, as `DeleteAccount` already expected.
- Inviting or removing members of an archived company is refused
  (`members:manage` is a write permission). An auditor who needs access to an
  archived company is invited after taking it out of the archive, or given an
  archive package.
- GDPR erasure (`EraseRegisterEntry`) in an archived company is refused like
  every other change; the owner takes the company out of the archive first.
- The data-subject export of a customer, supplier or employee works through
  its URL, but the link sits in the edit form, which an archived company does
  not open. A read-only entry point is a follow-up.
