# ADR-021: Auditors keep read access to payslips, and every view is logged

- Status: Proposed (needs the project owner's approval)
- Relates to: AGENTS.md §4 (auditor role), GDPR art. 5.1 c and art. 32

## Context

The auditor role (Revisor) is read-only on everything, and through
`payroll:archive-view` it can open payslips and the payroll part of the
archive package. Payslips carry salary and can reveal sick leave (sick pay,
karensavdrag), which may be health data under GDPR art. 9.

An auditor needs the payslips: salary costs, tax and employer contributions
are part of what the audit covers, and the payslips are räkenskapsinformation.
Removing the access would push auditors to ask for exports by e-mail, which
is worse for confidentiality than reading them in the system.

## Decision

- The auditor keeps `payroll:archive-view` and with it read access to
  payslips and the archive package.
- The auditor does not get `payroll:view-sensitive`: the absence register,
  absence notes, bank accounts and personal numbers in the employee register
  stay hidden.
- Every time someone other than the employee opens a payslip,
  `payroll.payslip_viewed` is written to the audit log (already in place),
  so the company can see who read what.
- Downloading an archive package with payroll requires
  `payroll:archive-view`, also when the role set changes.

## Consequences

- The company can answer an employee who asks who has read their payslips.
- The company is controller for the auditor's access and must cover it in
  its own record of processing; NONOX documents the role in
  `docs/gdpr/registerforteckning.md`.

TODO(legal-verify): whether an external auditor's reading of payslips in
the system needs its own legal basis beyond the audit obligation in
Aktiebolagslagen 9 kap.
