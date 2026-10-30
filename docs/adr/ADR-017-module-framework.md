# ADR-017: Module framework

- Status: Accepted
- Date: 2026-10-30
- Task: PAYROLL_MODULE.md M-1 to M-4

## Decisions

- Optional feature areas are **modules** under `app/Modules`, each implementing
  `App\Modules\Contracts\Module` and registered in `ModuleRegistry` by its own
  service provider. Core bookkeeping is not a module.
- State per company is the table `company_modules` (enabled, who and when,
  settings as jsonb), read through `ModuleManager`, cached per request and in
  the cache store (`company:{id}:modules`), and forgotten on every change.
  Every change is audited with before and after. Own table rather than
  Laravel Pennant, for the audit trail, per-module settings and blocker checks.
- Enforcement in four layers: the `module:<key>` route middleware answers 404;
  navigation shows a module's items only while it is on and the user has the
  permission; actions call `ModuleManager::ensureEnabled()`
  (`ModuleDisabledException`, 404 over HTTP); schedules use
  `companiesWith()` and queued jobs check `isEnabled($key, $companyId)` in
  `handle()`.
- Jetstream roles are global, so module roles are always registered and module
  permissions are added to core roles at boot. `User::hasTeamPermission()`
  returns false for a module permission while the module is off, except the
  module's archive permissions, and gives the owner only the module
  permissions the module grants owners (payroll keeps `payroll:view-sensitive`
  from owners). The role picker and the member actions offer and accept module
  roles only while the module is on.
- The contract gained `rolePermissions()` and `archivePermissions()` beyond
  PAYROLL_MODULE.md A.3, to express the default permission table (B.2) and the
  archive exception (A.6) as data.
- Turning a module off checks its blockers (hard stop) and warnings (the user
  types the module's name), and that no module depending on it is on. Turning
  it on checks its dependencies. Data is never deleted.
- A dummy module in `tests/Support/Modules` proves the framework without
  payroll.
