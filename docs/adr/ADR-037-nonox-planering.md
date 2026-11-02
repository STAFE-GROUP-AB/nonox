# ADR-037: NONOX Planering (staff scheduling)

- Status: **Accepted**
- Date: 2026-11-02
- Spec: `PLANNING_MODULE.md`
- Builds on: ADR-017 (module framework), ADR-012 (payroll), ADR-022 (staff
  ledger), ADR-033 (NONOX Avtal)

## Context

Restaurants, cafés, shops and salons plan their staff week by week, check
them in and out in a personalliggare (required in restaurants, salons and
construction, not in retail) and pay them by the hour with OB and overtime
from a collective agreement. Today NONOX has the ledger, the payroll and the
agreements, but no schedule, and OB hours are entered by hand or imported
from another scheduling system. The industry pages say so.

A competitor review (2026-11-02; Personalkollen, Caspeco, Quinyx, Planday,
Tamigo, and the schedule offer of the large bookkeeping programs) found:

- every scheduling product has a shift grid with templates, check-in tied to
  the shift, plan-versus-actual attest and a payroll basis; most show labour
  cost while planning, labour cost as a share of sales, rest-time warnings,
  open shifts, swaps, availability and an employee app;
- the most common complaint is the hand-off to payroll (re-entering hours);
  others are heavy setup, weak mobile apps and thin reporting;
- the bookkeeping programs have no schedule and no ledger of their own and
  rely on partner apps; the restaurant systems have no bookkeeping. No
  product has bookkeeping, payroll, ledger and schedule in one database.

## Decision

Build Planering as an optional module (`planning`) that depends on Lön, reads
the staff ledger and Avtal when they are on, and never writes to the ledger.
Plan versus actual is attested in Planering and becomes approved time reports
per pay code, including OB split by the agreement's OB windows and a Swedish
holiday calendar, so the payroll run takes them as it takes imported time
today. Labour cost is estimated per shift and compared with the actual sales
in the books per day.

Working-time and minors' rules are data with a source and `TODO(legal-verify)`,
and only warn. Functionality is replicated, never another product's texts,
layout or names (AGENTS.md §1.1).

## Consequences

- One flow from plan to payslip with no export; the industry pages' limit
  "OB räknas ännu inte från passens klockslag" can be removed when the OB
  split and attest ship.
- A shared `SwedishHolidays` calendar also fixes the payroll settings that
  today ignore public holidays.
- The scheduler role and Min sida grow; Min sida must stay fast on phones.
- Out of scope for now: automatic scheduling, forecasting beyond history,
  hourly POS sales, tips, chat and SMS.

## Amendment 2026-11-02: overtime and mertid (fix-overtid)

Overtime and mertid are measured per calculation period by `OvertimeTerms`
and `PayrollBasis::period`: a PERIOD method (worked time plus leave on
scheduled days, arbetstidslagen 7 § andra stycket, against the contracted
time) or a DAILY method selected by the agreement data (Visita–Unionen,
Handelns tjänstemannaavtal). The agreement's rounding is applied per day or
occasion, never to the period total. Without a collective agreement the
period is at most four weeks (5 § andra stycket), whatever the setting.
Hourly-paid staff get the agreement's overtime and mertid supplement on
supplement-only codes when the agreement gives it (Gröna Riks,
Detaljhandeln); their hourly pay is unchanged. Enligt AI-utredning
2026-11-02, juristgranskning återstår.
