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

### 6. NONOX AI fills in the setup (added 2026-11-01)

The owner (2026-11-01): "NONOX Avtal ska ju kunna analysera med NONOX AI och
liksom fylla i alla regler som krävs per personal, annars har jag ju ingen
glädje av den modulen." Setting up an agreement by hand (which agreement and
variant, collective, lönegrupp, branschvana, working hours, local deviations)
for every employee is the work that makes the module worth having or not.
NONOX AI (ADR-018, module `ai`, optional) does that work as proposals.

**The dividing line.** The *values* of an agreement (rates, OB windows,
ladders, percentages, minimum wages) never come from the AI. They come only
from the central, versioned and verified agreement data (§2). The AI decides
nothing about pay either: it reads the company's own documents and proposes
which *facts* about the company and each employment to record, each with the
quote and page it read them from. A person with `agreements:manage` accepts,
edits or rejects each proposal; nothing takes effect before that, and the
payroll engine only ever reads accepted facts plus verified agreement data.

**Agents** (in `app/Modules/Agreements/Agents`, used only when both `ai` and
`agreements` are on and the user has `ai:use` and `agreements:manage`):

| Agent | Reads | Proposes |
|---|---|---|
| `AgreementIdentificationAgent` | Documents the company uploads: a hängavtal, a membership confirmation from an employer organisation, a previous payslip or payroll report; plus the company's SNI code and business type | Which agreement(s) and variant (`MEMBER`/`HANGAVTAL`) apply, per collective, with the sentence that shows it |
| `EmploymentTermsAgent` | One employee's employment contract (anställningsavtal) or a previous payslip | Agreement and collective for the employment, job title, the lönegrupp candidate(s) with the agreement's group description it matched, form of employment, weekly hours, monthly or hourly pay, start date, branschvana the document states, and any terms that differ from the agreement |
| `LocalAgreementAgent` | A local agreement (lokalt avtal) | Deviations expressed in the `nonox.agreement/1` override schema (for example another OB window or a comp-time rule), flagged as company overrides that need `agreements:verify` before a run uses them |

**"Analysera all personal".** One action runs `EmploymentTermsAgent` over
every employee that has a document, and the result is a review list: one row
per employee and field, the proposal, the source quote, and Godkänn /
Ändra / Avvisa per row or for all rows that agree with the document. That is
the "fill in everything" experience; the person still approves it.

**Deterministic, not AI.** Age and age-based minimum wage (from the date of
birth in the personal number), the branschvana step date, below-minimum
warnings, missing OB on a shift, and the explanation of every payslip line
are computed by code from accepted facts and verified data. The AI may phrase
an explanation, never compute it.

**Data minimisation.** Personal numbers, bank details and addresses are
masked in the text before it is sent to the AI provider; the provider gets
only what the proposal needs. Each run is logged (who, which document, which
agent, tokens) without the content, as in ADR-018.

**AI Act.** Proposing which pay group an employment belongs to is close to
Annex III point 4(b) ("decisions affecting terms of work-related
relationships"). The design keeps the AI to reading documents and proposing
recorded facts, with a person deciding every field and the rule values out of
the AI's reach, and it never evaluates a person's performance or behaviour.
TODO(legal-verify): whether these agents are high-risk under Annex III 4(b)
despite that, and what that requires from 2 December 2027 (ADR-018 AI Act
section); the impact assessment `docs/gdpr/konsekvensbedomning-ai.md` gets
a section before the agents ship.

## Implementation status (2026-11-01)

Built on branch `feat/agreements` (systemdokumentation §5h, version 0.58):
A1 (module, versioned data with verification and the run block, company
binding, employment facts, `agreements:import` and `agreements:verify`),
A2 (agreement calendar), A3 (pricing of already split OB and overtime
lines, explanation per line), A4 (minimum wage warnings) and the AI setup
of §6 with `AgreementIdentificationAgent` and `EmploymentTermsAgent`. As
built, the payroll engine gains `PayrollExtensions` (context extensions
and steps registered by other modules) and an agreement code is its own
pay code with `formula_ref = agreement:<code>`.

Added 2026-11-02 (branch of agent avtal-ersattning): A9 (the agreement's
vacation percentage, semestertillägg and lowest vacation pay per day,
applied through `VacationInput::withAgreementTerms`), A10 (pension and
agreement insurances from the payroll rules `pension.*` and
`insurance.fora.*`, booked 7410/7570 against 2740, and a Fora/Collectum
basis as NONOX's own CSV, not their file formats), A11 (commission with
guarantee, fixed supplements, meal deductions), A12 (golden payslips, at
least ten per wave-1 agreement), local overrides in
`collective_agreements.overrides` verified with `agreements:verify` before
use, and `LocalAgreementAgent`. As built, the engine gained
`PayrollContext::withVacation()`/`withPension()`, optional agreement
fields on `VacationInput` and on the pension plan array (threshold, ceiling,
ages), and three pay codes (`AVTALSTILLAGG`, `PROVISION`,
`AVTALSFORSAKRING`); the agreement logic stays in the module
(`AgreementPayTerms`, `AgreementCostsStep`). Remaining: A5–A8 (other
branch), ITP 2, Fora's JSON file and Collectum's formats.

**A5–A8 (2026-11-02).** §4 is implemented by NONOX Planering (ADR-037)
instead of a `work_shifts` table of this module: published shifts, the
staff ledger or the manager's own time, attest, and `ObSplitter` as the
shift splitter. On top of it (gap-analys §5a): the agreement's rounding of
OB and overtime as data; overtime and mertid of monthly-paid staff over a
calculation period of 1–52 weeks (a rotating schedule's length), with the
agreement's maximum as a warning; comp time chosen per occasion in the
attest with the agreement's factor per code (or the company's own),
kept as a balance in payroll (`comp_time_entries`, Lön → Komptid, on the
payslip and Min sida) and paid out on pay code `KOMPTID`; and sick pay by
the published schedule (the shift's hours on a sick day, lost OB at the
sick-pay percentage, OB and, for irregular hours, the schedule's hours in
the karensavdrag's average week) plus the salaried agreements' deduction
per calendar day from day 15 as data. The payroll engine gets only generic
inputs (`SickPayTerms`, `LongSickTerms`, `AbsenceInput::lostSupplementsOre`);
`AgreementSickPayExtension` fills them. Not built in A5–A8: overtime
supplements for hourly-paid staff, karens counted in time, the agreements'
own hour values in sick pay, the 105-day cap, and comp-time payout on
termination as a rule.

### Overtime, rounding and vacation basis (fix-overtid, 2026-11-02)

Enligt AI-utredning 2026-11-02, juristgranskning återstår (verified findings
L5, A1, A2):

- **Vacation basis (L5).** `OVERTID` and `KOMPTID` are vacation-pay basis
  (semesterlagen 16 b §; under sammalöneregeln variable pay earning 12 %,
  16 a § tredje stycket, through `CloseVacationYear`). Agreement codes carry
  `vacation_basis` with `vacation_basis_source`: Gröna Riks (§ 17 p. 1) and
  Detaljhandeln (§ 14.6 A) true; Visita–Unionen (§ 16) and Handelns
  tjänstemannaavtal (§ 9.4.1) false, since semesterlön is included in their
  overtime pay. Paid-out comp time follows the flag of the overtime it was
  earned on, first in, first out (`CompTimeLedger::origins`), carried on the
  payslip line as `explanation.vacation_basis`, which the vacation ledger
  reads before the pay code's flag. A migration sets the flag on existing
  system pay codes still at the old seeded value. SJUKLON, SJUKAVDRAG and
  KARENSAVDRAG keep their flags (the ledger excludes category SICK).
- **Overtime method (A1).** `working_time.overtime_method` PERIOD or DAILY;
  leave on scheduled ordinary time counts as worked under PERIOD
  (arbetstidslagen 7 § andra stycket, 10 §); without an agreement the
  calculation period is at most four weeks (5 § andra stycket); Gröna Riks'
  limit is 16 weeks monthly, 8 hourly (`max_weeks_hourly`, § 9 p. 1).
  Hourly-paid staff get supplements through `overtime.hourly_paid` and the
  `*_TL` codes (Gröna Riks § 12, Detaljhandeln § 9); mertid codes
  `GR_MT_*`, `DH_MT_*` from 2026-04-01 (GR § 12 p. 2, DH § 9.5).
- **Rounding (A2).** `rounding.overtime.per` DAY or OCCASION and
  `threshold_minutes`; never on the period total. OB rounding per shift and
  code is unchanged and uncertain (the agreements do not say per what).

**A8 sick pay, corrected (2026-11-02, enligt AI-utredning, juristgranskning
återstår).** The verification of the sick pay (L1, L2, L4) found deviations;
now built:

- *Agreement hour value and karens in time* (sjuklönelagen 2 § andra
  stycket lets a central agreement set the calculation): new data keys
  `sick_pay.hour_value` (`MONTHLY_PER_WEEK` with months/weeks per year:
  Visita–Unionen 12/52 § 23.3, Handels tjänstemän 12,2/52 § 10.3.2;
  `MONTH_WORKING_HOURS`: Gröna Riks § 20 p. 4.3.1, the month's hours from
  the published shifts or the weekly pattern; `DAY_PERCENT`: Detaljhandeln
  § 13.1, 4,6 % per full day and 1/166 per hour) and `sick_pay.karens`
  (`HOURS`, 20 % of the weekly hours, `AVERAGE` or `AGREED` — Detaljhandeln
  § 15.4 uses the hours agreed in advance). What the format cannot express
  yet is a payslip note, never a silent other method: Detaljhandeln's karens
  time valued per hour, fixed supplements in its day base, more than 22
  scheduled days.
- *Average week* (prop. 2017/18:96 s. 68): attested time and OB in up to 13
  calendar weeks before the sick period's first week, from the first week
  with attested time, leaving out weeks with nothing but absence; without
  history the published schedule from the period's first week, averaged
  over the weeks that have shifts; without either and without weekly hours
  the run is blocked with a Swedish message.
- *Day 15–90*: duration rows get `max_days_12_months` (105 / 45, VU § 23.3,
  HT § 10.3.7) and `or_direct_transfer`; `monthly_salary_includes`
  (`FIXED_SUPPLEMENTS`, `VARIABLE_AVERAGE`, `BENEFITS`), `cap_includes` and
  `variable_pay_codes`. The employment gets `sick_pay_direct_transfer` and
  `fixed_supplements_ore` (Lön → Avtal). The variable average is the codes'
  sum on approved payslips in the twelve months before the period's month,
  over the months employed. Kost/bostad and part-time sick leave from day
  15 are payslip notes, `TODO(legal-verify)`.
- The agreement version and the duration group follow the sick period's
  first day; the agreement part works without Planering (before, all of A8
  needed both modules).
- Karens per sjuklöneperiod and its carry across runs are in the payroll
  engine (ADR-012, `sick_pay_periods`).

### H.1: overtime kinds per occasion and comp-time factors per date (2026-11-06)

Gröna Riks (§ 12 p. 1.2, p. 2.2) and Detaljhandeln (§ 9.1, 9.2, 9.5, 9.6)
pay overtime and mertid by *where* the time lies: the two first hours
adjoining ordinary time, the rest, time not adjoining, a day off, and
(Detaljhandeln) the evening after 20.00, Saturday and the eves after
12.00, Sundays and public holidays. Before H.1 a period's overtime went on
the one code chosen under Planering → Inställningar. Model change (data
format `nonox.agreement/1`, additive; datamodell §8):

- `overtime.kinds`: `ranks` (lowest paid first), `first_hours` (minutes per
  day), `rest_kind`, `not_adjoining_kind`, `day_off_kind`, `windows` that
  raise the kind (`days`, `day_type` or inline `spans`; `day_off_only`),
  `codes` per kind for `OVERTIME` and `EXTRA_TIME`, and `day_off_minimum`
  (Detaljhandeln: at least three hours paid for overtime on a day off).
- `overtime.comp_time.hours_per_hour` may give a list of
  `{valid_from, factor}` steps per code (Detaljhandeln: 1.5 for 50 % until
  2026-03-31, 1.35 for 35 % from 2026-04-01).
- Engine: `Planning\Domain\OvertimeKinds` (pure) classifies each day's
  stretches outside the plan with the same threshold and rounding as
  `OvertimeTerms::day`, and `PayrollBasis::week` lays the period's
  overtime and mertid on the kinds, highest first; the rest (leave counted
  as worked, codes without a rate that day) stays on the company's code.
  Hourly-paid staff get each kind's supplement-only code. Comp time is
  earned per code with the factor in force on the day the time was worked
  (corrected after review, see below).
- The kinds apply when the company's code for overtime (or mertid) is empty
  or one of the agreement's codes; a code of the company's own keeps the old
  behaviour. Payroll is unchanged: the time reports are priced by the
  existing `AgreementPricer`, and no account or voucher changes.

Open (`TODO(legal-verify)`): which stretches are the overtime when the
period's netting leaves less than the stretches (neither agreement says;
GR § 9 p. 3 s. 14 only settles över- and undertid within the period. NONOX
takes the highest first, which can pay more than the agreement requires,
and says so on the attest row); Gröna Riks' two first hours per day or per
occasion (NONOX: per day); Detaljhandeln 70 % for overtime 00.00–06.00 on a
weekday morning; Detaljhandeln's minimum on a day off when only part of the
day is overtime after netting. Not built: Detaljhandeln's exceptions from
mertid pay (§ 9.5).

**H.1 corrections after review (2026-11-06).** Re-read against GR
2025–2027 and D1 HA2025-110:

- *Rate and comp-time factor by the day worked.* GR § 12 p. 1.2 s. 19 ("fr.o.m.
  den 1 april 2026 35 %") and D1 § 9.2 s. 35 ("för övertidsarbete som utförs
  till och med den 31 mars 2026") tie the rate to when the overtime is
  performed. Each stretch now keeps its date; the period's overtime is still
  sent with the period's last day, but each time report carries
  `time_reports.priced_on` (new nullable column) and the payroll run prices it
  on that day. Comp time takes the factor of the day worked
  (D1 § 9.6 s. 37–38).
- *Comp-time factors confirmed.* GR § 12 p. 1.2 s. 19 and p. 2.2 s. 20 give
  1.5 h for a) and b) and 2 h for c) without a date, although the a) rate
  steps 45 → 35 % on 2026-04-01; the factors are undated in the data on
  purpose. D1 § 9.6 s. 37–38: 1 h 30 min for 50 % (until 2026-03-31), 1 h 21
  min for 35 % (from 2026-04-01), 1 h 45 min for 70 %, 2 h for 100 %.
- *Day-off minimum.* D1 § 9.1 s. 35: "Vid övertidsarbete på dag som på grund
  av arbetstidens förläggning är fridag utges ersättning för minst tre
  timmar", in the section for full-time staff, naming no rate. In D1 a day
  off has one kind (70 % on a weekday, 100 % on a Saturday, an eve, a Sunday
  or a holiday, § 9.2), so the top-up is paid on that day's code with the
  hourly pay. `TODO(legal-verify)` that this is the intended rate.

**H.1b: only the higher of OB and overtime (Detaljhandeln, 2026-11-06).**
D1 § 8.1 Anm. s. 33, § 9.1 Anm. s. 35, § 9.3 Anm. s. 36 and § 9.5 Anm. 2
s. 37: when overtime or mertid falls on OB time, only the percentually
higher compensation is paid. Before this NONOX paid both (OB is split from
every worked minute per day; overtime is counted per period). Decision:

- Data: `overtime.kinds.ob_overlap: {method: HIGHEST_ONLY, source}` (only
  Detaljhandeln has it).
- When the period's overtime is laid on the stretches, each allocated
  stretch is split by the agreement's OB windows. Per minute the two
  percentages are compared on the day worked: overtime ≥ OB → the OB is
  taken back; OB > overtime (only 18.15–20.00 against the first two hours'
  35 %) → the overtime supplement is taken back on its supplement-only code
  (`*_TL`), so the minute keeps the hourly pay and the higher percentage.
- The take-back is a negative time report sent with the period's last day
  and priced on the day worked, with a note on the attest row naming the
  minutes, the code and the source. Days already attested keep their OB
  reports; nothing approved or posted is changed. A period whose month is
  already approved is corrected in the next run as before.
- Not when OB is included in the salary, and not when the overtime is taken
  as comp time (then a note says the OB is still paid; `TODO(legal-verify)`).
- Not for a stretch of which only part is overtime after netting (which of
  its minutes is not known), and not for a day that was not itself sent to
  payroll or a take-back whose pay code is missing: then both are paid and
  the attest row says so. Leaving a take-back out can only pay more, never
  less than the higher of OB and overtime.
- Accounts and vouchers are unchanged (the same pay codes and accounts), so
  the systemdokumentation is not changed; the amounts on the salary voucher
  are lower where both were paid before.


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
