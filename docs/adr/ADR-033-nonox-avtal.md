# ADR-033: NONOX Avtal (collective agreements in payroll)

- Status: **Proposed**
- Date: 2026-11-01
- Spec: `docs/avtal/README.md`, `docs/avtal/datamodell.md`, `docs/avtal/gap-analys.md`
  and one file per agreement in `docs/avtal/`
- Builds on: `PAYROLL_MODULE.md` B.1, B.6, B.8, B.9; ADR-012 (payroll);
  ADR-017 (module framework); ADR-022 (staff ledger)

## Context

NONOX's first customers are cafés, restaurants, shops, hairdressers and
bakeries. Almost all of them are bound by a collective agreement, either as a
member of an employers' organisation or through a hängavtal signed directly
with the union. A payroll run that ignores the agreement pays the wrong OB,
the wrong overtime and the wrong vacation pay, and books the wrong pension and
insurance premiums. Today the payroll engine has a `collective_agreements`
table with a free `rules` jsonb (B.8), an `employments.collective_agreement_id`
column, and nothing that reads either: the run builder never loads the
agreement, and OB and overtime are amounts or hours entered by hand
(`gap-analys.md` §2).

The owner's requirement (2026-11-01): every collective agreement relevant to
NONOX's target groups must be supported, starting with café, restaurant and
retail, then the segments NONOX competes for (hairdressing and beauty,
bakeries and konditorier, hotels, small consultancies, later construction and
installation).

PAYROLL_MODULE.md B.1.5 says "No collective agreement is hardcoded and no
agreement text is copied (copyright). Only generic, configurable templates
ship." That principle stays: this ADR proposes how agreement **facts**
(minimum wages, OB windows and rates, overtime ladders, premiums) are shipped
as **versioned, sourced, verifiable data**, never as code and never as
agreement text.

## Decision (proposed)

### 1. A module of its own: `agreements` ("NONOX Avtal")

```
key:          agreements
name:         Avtal
description:  Kollektivavtal i lönen: minimilöner, OB, övertid och mertid,
              semester, sjuklön, pension och avtalsförsäkringar enligt avtalet
              företaget är bundet av.
dependencies: [payroll]
roles:        none of its own (payroll_admin and owner use it)
permissions:  agreements:view  agreements:manage  agreements:verify
```

- Built on the module framework (ADR-017): on/off per company under
  Moduler, `module:agreements` on its routes (404 while off), navigation
  under Lön, `ModuleManager::ensureEnabled()` in its actions. It depends on
  `payroll`; payroll cannot be turned off while Avtal is on.
- Turning it off does not change approved runs (they store the rule versions
  used) and deletes nothing. Draft runs recalculate without agreement rules,
  with a warning on the run page.
- Code: `app/Modules/Agreements/` with `Domain/` (the agreement rule set
  value objects and calculators: `ShiftSplitter`, `ObCalculator`,
  `OvertimeCalculator`, `MinimumWageCheck`, `DayCalendar`), `Models/`,
  `Actions/`, `Livewire/`, `database/data/agreements/<key>/<valid_from>.json`.
- The payroll engine gains extension points, not agreement logic: a step
  receives an `AgreementRuleSet|null` in `PayrollContext`, and the new steps
  (OB, overtime/mertid, minimum wage check, insurance premiums) live in the
  Agreements module and are registered into the calculator only while the
  module is on. Payroll without Avtal keeps working exactly as today.

### 2. Data: global, versioned, verified agreement rule sets

- New global table `agreement_rule_versions` (not company-scoped, like
  `payroll_rule_versions`): `agreement_key`, `variant`, `valid_from`,
  `valid_to`, `rules jsonb` (format in `docs/avtal/datamodell.md`),
  `sources jsonb`, `verified_by`, `verified_at`, unique
  (`agreement_key`, `variant`, `valid_from`). The same Postgres trigger as
  `payroll_rule_versions_protect_verified` refuses changing or deleting a
  verified version; a correction is a new version.
- New company table `agreement_rule_verifications` (append-only, like
  `payroll_rule_verifications`): a company's own named check of a version.
- Shipped as seed data, updated by reviewed PRs, imported with
  `php artisan agreements:import {file}` and verified with
  `php artisan agreements:verify {key} {valid_from}` or in the UI by a user
  with `agreements:verify`.
- **A run is blocked** (`PayrollBlockedException`, listed in the pre-run
  checklist) when an employment in the run is bound to an agreement and no
  verified version covers the payment date, or the period's work dates. No
  fallback to a previous version or to "generic" rules (B.6).
- Premiums that change every 1 January (SAF-LO, ITP, AGS, TFA, TGL,
  omställning) are **payroll rule versions** (`pension.saf_lo`,
  `insurance.fora.arbetare`, ...), referenced by key from the agreement rule
  set, because they are shared by many agreements and change on a different
  cycle.

### 3. Settings live in the module

- **Company level** (the existing company-scoped `collective_agreements`
  table becomes the binding, with new columns): `agreement_key`, `variant`
  (`MEMBER` = bound as a member of the employers' organisation,
  `HANGAVTAL` = bound by a hängavtal with the union), `bound_from`,
  `bound_to`, `local_agreement_path` (an uploaded local agreement, private
  bucket), `overrides jsonb` (only keys the data model marks `local_override:
  true`, for example a locally agreed beräkningsperiod, a higher OB, a
  different payment of overtime as comp time). Every override is audited and
  shown on the payslip explanation.
- **Employment level**: `employments.collective_agreement_id` (exists),
  plus `agreement_group` (lönegrupp/befattningsgrupp in the agreement),
  `industry_experience_from` (date that drives experience-based minimums),
  `overtime_compensation` (`PAY` | `COMP_TIME` | `INCLUDED_IN_SALARY` where
  the agreement allows it), `ob_compensation` (`PAY` | `INCLUDED` where
  allowed). Age-based minimums use `employees.birth_date` (exists).
- An employee not covered (e.g. an owner's family member, a manager outside
  the agreement) has `collective_agreement_id = null`: no agreement rules,
  no warnings.

### 4. Inputs: worked shifts with clock times

OB, night work, storhelg, mertid and overtime need **start and end times**,
not hours per day. The Avtal module adds `work_shifts` (company-scoped:
employee, employment, date, start_at, end_at, breaks, source enum(MANUAL,
STAFF_LEDGER, SCHEDULE, IMPORT_PAXML), status, payroll_run_id) and a
`ShiftSplitter` that cuts each shift into the agreement's time windows and
day types. Sources:

- the staff ledger's closed shifts (ADR-022), which have check-in and
  check-out times but are flattened to hours per day by
  `TimeProposals::propose` today;
- a PAXml import (built by another agent) that may deliver either shifts or
  already split OB lines. Already split lines are accepted as payroll inputs
  as they are, with `source = IMPORT`, and the OB step does not split them
  again;
- manual entry and the work schedule.

### 5. What the module does not do (v1)

- No scheduling, no rest-time (dygns- och veckovila) enforcement, no
  arbetstidslagen reporting. A warning only, when a shift sequence obviously
  breaks the agreement's rest rule, behind a setting.
- No submission to Fora or Collectum. v1 produces the reports the employer
  needs to report salaries to them (`gap-analys.md` §4).
- No agreements outside the waves in `docs/avtal/README.md`.

## Legal questions (all `TODO(legal-verify)`)

1. **Copyright in agreement texts.** A collective agreement is a private
   contract, not an official document under upphovsrättslagen 9 §, so the
   text is protected. Our position: facts (a rate, a time window, a date) and
   our own description of a mechanism are not protected expression; we never
   copy wording, tables or layout, and every value links to the party's own
   publication. To confirm with a lawyer: whether a compilation of all values
   of one agreement could infringe a catalogue right (upphovsrättslagen 49 §)
   held by the parties, and whether short quotes in source references
   (≤ 25 words, as now in `rules.json`) are covered by citaträtten (22 §).
2. **Liability.** Whether shipping agreement values, even marked unverified,
   creates liability towards a company that pays wrong; the wording of the
   disclaimer in the module and in `docs/juridik/villkor.md`.
3. **Hängavtal content.** Whether the union's hängavtal binds the company to
   exactly the same version of the branch agreement (including later
   revisions) and to the same insurances; whether NONOX may present the
   member and hängavtal variants as the same rule set.
4. **Paywalled sources.** Several parties publish full texts only to
   members; values verified from a member copy can be shipped only if the
   source can be cited publicly. Otherwise the value stays company-entered.
5. **Personal data.** Shifts with clock times are working-time records
   (arbetstidslagen 11 §); retention and the employee's access to them.
6. **Fora and Collectum reporting.** Whether producing their salary report
   files needs an agreement with them, and the current formats.

## Consequences

- Agreement facts become reviewable data with sources, verified per version,
  and block a run when unverified, the same discipline as tax and
  contribution rules.
- Every agreement adds a data file and golden payslip fixtures, not code. New
  mechanics (a new kind of supplement) are engine work and need an ADR
  amendment.
- Twice-yearly maintenance (new wage steps each spring under the current
  agreements, new premiums each January, a new agreement after the 2027
  bargaining round) is a recurring, scheduled task (`docs/avtal/README.md`
  §4).
- PAYROLL_MODULE.md B.1.5 and B.8 must be amended when this ADR is accepted:
  "no agreement text is copied; agreement facts ship only as sourced,
  verifiable data in the Avtal module".
