# ADR-003: Company owner and role permissions

- Status: Proposed (needs the project owner's review)
- Date: 2026-10-29
- Task: T-0.2

## Context

`AGENTS.md` §4 lists six roles with one-line purposes and the exact permission
keys, but not which keys each role gets. Jetstream also treats the owner
differently from the other roles.

## Decisions

### The owner is Jetstream's company owner, not a member role

Jetstream stores the owner in `teams.user_id` and gives the owner every
permission (`teamPermissions()` returns `['*']`). The owner is therefore not
registered with `Jetstream::role()`. The five member roles are registered from
`App\Domain\Company\CompanyRole`.

Not built yet: transferring ownership, because Jetstream has no flow for it.

### Permission mapping

The source of truth is `CompanyRole::permissions()`; this table mirrors it.

| Permission | Admin | Accountant | Bookkeeper | Approver | Auditor |
|---|:-:|:-:|:-:|:-:|:-:|
| company:update | ✓ | | | | |
| company:archive | | | | | |
| members:manage | ✓ | | | | |
| accounts:manage | ✓ | ✓ | | | |
| fiscal-years:manage | ✓ | ✓ | | | |
| periods:lock | ✓ | ✓ | | | |
| vouchers:create | ✓ | ✓ | ✓ | | |
| vouchers:post | ✓ | ✓ | ✓ | | |
| vouchers:correct | ✓ | ✓ | ✓ | | |
| vouchers:view | ✓ | ✓ | ✓ | ✓ | ✓ |
| invoices:create / send / credit | ✓ | ✓ | ✓ | | |
| invoices:view | ✓ | ✓ | ✓ | ✓ | ✓ |
| supplier-invoices:create | ✓ | ✓ | ✓ | | |
| supplier-invoices:approve | ✓ | | | ✓ | |
| payments:create | ✓ | ✓ | ✓ | | |
| payments:approve | ✓ | | | ✓ | |
| vat:prepare / vat:submit | ✓ | ✓ | | | |
| reports:view | ✓ | ✓ | ✓ | | ✓ |
| export:sie / export:archive | ✓ | ✓ | | | ✓ |
| import:sie / import:fortnox | ✓ | ✓ | | | |
| api-tokens:manage / settings:manage | ✓ | | | | |
| audit-log:view | ✓ | ✓ | | | ✓ |

Judgement calls the owner should confirm:

1. **The accountant cannot approve** supplier invoices or payments. Approval is
   kept with the approver and admin for segregation of duties (§4).
2. **The approver can view** vouchers and invoices, because approving
   something you cannot see is not meaningful. The §4 text says "approve only".
3. **The bookkeeper can correct vouchers** (`vouchers:correct`), since a
   correction is a new posting and "create/post vouchers" is in their purpose.
4. **The bookkeeper can see reports** but not export SIE.

## Consequences

- Policies check `hasTeamPermission`, which is false for non-members and true
  for the owner, so membership and permission are checked together (§4).
- Changing the mapping is a change to `CompanyRole::permissions()` plus this
  table, and `tests/Feature/Company/RolesTest.php` must still pass.
