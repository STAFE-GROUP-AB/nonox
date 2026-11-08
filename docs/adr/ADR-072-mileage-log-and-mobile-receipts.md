# ADR-072: Mileage log and mobile receipts (körjournal och kvitton i mobilen)

Status: Proposed
Date: 2026-11-08
Relates to: ADR-012 (payroll), ADR-017 (module framework), ADR-018 (NONOX AI), ADR-022
(staff ledger PWA), ADR-023 (inbox), ADR-031 (installable web app), ADR-053 (NE-bilaga),
ADR-061 (car benefit), `PAYROLL_MODULE.md` B.4, B.9, B.11, rule sheet
`docs/specs/rules/korjournal.md` (KJ-R1 … KJ-R22), AGENTS.md §1.2, §1.3, §1.4, §10

## Context

Roadmap item `korjournal-och-kvitto-i-mobilen`: "a mileage log and a faster way to photograph
a receipt on the phone and get a proposed booking, which you then approve". The pilot users
are the ones the roadmap names: construction and service companies whose staff drive their
own or the company's cars, and sole traders who drive their own car in the business.

What NONOX has today (mapped in the rule sheet):

- **Mileage pay:** pay codes `MILERSATTNING` (tax-free, 7331) and `MIL_SKATTEPLIKTIG`
  (taxable excess, 7332); the `Allowances` step splits at rule
  `mileage.own_car_tax_free_per_mil` and needs a number of mil (AUD-LON-09). Payroll staff
  type the number of mil by hand into the run. Nothing records the trips behind it.
- **Company cars:** car benefit, fuel benefit × 1,2, the employee's own fuel payment, and a
  hand-set "at least 3 000 mil in service" flag (ADR-061). Rules for tax-free mileage in a
  company car (`mileage.company_car_fossil` 12 kr/mil, `mileage.company_car_electric`
  9,50 kr/mil) exist in `rules.json` but **no pay code uses them**, and the AGI ticks ruta
  050 only for `MILERSATTNING`.
- **Outlays (utlägg):** Min sida (`/anstalld`, the staff PWA `anstalld.webmanifest`) takes a
  receipt photo (`capture="environment"`), NONOX AI reads it when the company allows it,
  a payroll person approves with a cost account (voucher in series L against 2890), and the
  next run repays through `UTLAGG`. This is already the mobile receipt flow for employees.
- **Schema already prepared:** `expenses.type` has `MILEAGE_OWN_CAR` and
  `MILEAGE_COMPANY_CAR` and a `trip_data` jsonb, unused.
- **Inbox:** receives by e-mail only. A company receipt (paid with the company card) cannot be
  sent from the phone except by mailing it to `+kvitto`.
- **Sole trader (EF):** nothing. The owner is not an employee and cannot be paid mileage; the
  deduction is 2,50 kr/km under IL 16 kap. 27 §, booked in the business or claimed on the
  NE-bilaga.

What the law gives (rule sheet, read on riksdagen.se and skatteverket.se):

- Own car: 2,50 kr/km tax-free (IL 12:5 first paragraph). Company car where the employee paid
  **all** fuel: 0,95 kr/km el, 1,20 kr/km other fuel (12:5 second paragraph); a plug-in hybrid
  charged at the workplace still qualifies for trips from 2026-07-01 (12:5 third paragraph,
  SFS 2026:464). Above the schablon is pay (Skatteverket). AGI: tick 050 for both own car
  and a company car where the employee paid the fuel; the excess goes in 011.
- Only tjänsteresor qualify; commuting is private (IL 11:1, 12:26).
- A körjournal is not required by any law text read, but it is the evidence Skatteverket
  names for the fuel benefit (61:10), the 75 % rule at 3 000 mil (61:9) and benefit of minor
  extent (61:11, Skatteverket's 100 mil and 10 occasions). For a company car Skatteverket
  says the odometer at year start and year end must be in it.
- IMY's red-green list: an electronic körjournal for Skatteverket is fine; positioning the
  employee cannot switch off, positioning outside working hours, and using the log to follow
  up performance are not.

## Decision

Decided by Lead 2026-11-08 under the owner's mandate, open for the consultant's review.
Where this ADR and the rule sheet disagree, the sheet wins.

### 1. Placement: core trip log, two entry points, no new module

| Part | Where | Why |
|---|---|---|
| Vehicles, trips, year odometer readings | core: `app/Domain/Mileage`, `app/Actions/Mileage`, core migrations | A sole trader needs the log without payroll, and a pool car's log is evidence even when nobody is paid mileage (KJ-R14, KJ-R15). |
| Employee trip entry and the monthly claim | payroll self-service on Min sida (`/anstalld/korjournal`) | Paying mileage is payroll (`expenses`, `Allowances`, AGI). |
| Approval of employee claims | Lön → Ansökningar (the existing outlay queue) | Same approver, same segregation of duties. |
| Company-car year summary (3 000 mil, private use, private fuel share) | Lön → Förmånsbilar, per car | It is advice for the car benefit already there. |
| Sole trader's own trips and monthly voucher | Bokföring → Körjournal (`/korjournal`), shown when `legal_form = EF` | The owner is not an employee. |
| Company receipt photo | Inkorg → Fota kvitto (inbox module) | Interpretation and approval already live there. |

No new ADR-017 module: the log has no permission set worth switching on its own, and both
consumers (payroll, EF bookkeeping) already exist. A company setting **Körjournal** (off by
default, Företagsinställningar) shows or hides the screens; turning it off keeps the data.

Permissions (new, core): `mileage:view` (owner, admin, accountant, bookkeeper, auditor) and
`mileage:manage` (owner, admin, accountant: vehicles, every driver's trips, year readings,
closing a year). Employees log and claim with the payroll permission `self:expenses` and see
only their own trips. Approval needs `payroll:run`, as for outlays.

### 2. Data model

```
vehicles
  id, company_id, kind enum(PRIVATE_CAR, COMPANY_CAR), label,
  registration_number (encrypted), fuel (CarBenefitCar::FUELS),
  user_id null            -- the private car's owner (driver) for PRIVATE_CAR
  is_business_asset bool  -- EF: an asset of the business (KJ-R15 refuses the 2,50 kr deduction)
  archived_at null
vehicle_year_readings
  id, company_id, vehicle_id, year, start_odometer_km, end_odometer_km null, closed_at, closed_by
  unique(vehicle_id, year)
vehicle_trips
  id uuid, company_id, vehicle_id, driver_user_id, employee_id null,
  trip_date date, kind enum(BUSINESS, COMMUTE, PRIVATE),
  distance_hm int > 0                 -- hectometres (0,1 km): 25 kr/mil = 25 öre per hm, exact
  odometer_start_km null, odometer_end_km null,
  from_place, to_place, purpose (encrypted text, null for PRIVATE)
  customer_id null, project_id null,
  all_fuel_paid_by_driver bool null, charged_at_workplace bool default false,
  status enum(DRAFT, SUBMITTED, APPROVED, REJECTED, PAID, BOOKED),
  expense_id null, voucher_id null, timestamps
```

- Every table uses `BelongsToCompany`; each gets a tenancy test (another company's id → 404,
  another employee's trip on Min sida → 404).
- A company car's link to a person and its fuel come from the existing car benefit
  (`benefits` type CAR, ADR-061) for the trip date; a COMPANY_CAR with no benefit that day is
  a pool car.
- Distance is entered in km with one decimal, or computed from odometer start/end. A
  "tur och retur" switch stores two trips. Saved routes ("Kontoret – Lagret 14,2 km") are
  kept per person as text and a number; there is no map service and no position.
- A trip linked to a PAID expense or a BOOKED voucher is frozen: a PostgreSQL trigger
  rejects UPDATE and DELETE, as for approved payroll runs. A mistake after payment is
  corrected in the next month's claim (a negative correction row with a reason), never by
  editing.

### 3. Rules the log enforces (from the rule sheet)

- Rates come only from payroll rules by trip date's year (`RuleSet`), never constants
  (KJ-R1, KJ-R2). A missing rule blocks the claim like a run is blocked.
- PRIVATE trips are allowed only in a COMPANY_CAR and store date and km only (KJ-R9,
  KJ-R19).
- COMMUTE and PRIVATE trips are never tax-free. By default they cannot be claimed at all;
  a company that pays for commuting turns on "Ersätt resor till och från arbetet" and they go
  to `MIL_SKATTEPLIKTIG` (KJ-R6).
- Company car: the claim requires the driver's confirmation "Jag har betalat allt drivmedel
  för resorna"; a fuel benefit for the car in the month refuses the tax-free code (KJ-R3). A
  plug-in hybrid trip with "laddad på arbetsplatsen" is tax-free from 2026-07-01 only (KJ-R4).
- An EF owner's trip in a vehicle marked as a business asset is refused (KJ-R15).
- A company car's year cannot be closed without year-start and year-end odometer readings
  (KJ-R9). An odometer lower than the previous trip's end gives a warning, not a block.
- Place, purpose and odometer per trip are warnings for a company car and optional for an own
  car; date, distance and (for BUSINESS) purpose are required (KJ-R8, KJ-R9). Lawyer question
  L2 may tighten this.

### 4. Employee flow: trip → claim → payslip → AGI

1. **Log.** Min sida → Körjournal → "Ny resa". The employee picks a vehicle (their own car, or
   the company car they have that day), kind, date, distance or odometer, from/to, purpose,
   and optionally a project and a customer from the lists the company marks as selectable.
2. **Claim.** "Skicka in resorna för oktober" creates one `expenses` row per vehicle kind
   (`MILEAGE_OWN_CAR` or `MILEAGE_COMPANY_CAR`), `amount_ore` = the tax-free amount at the
   rule (or the company's own rate, §6), `trip_data` = sums per kind and the rule version
   used, and sets the trips SUBMITTED with `expense_id`.
3. **Approve.** Lön → Ansökningar shows the claim beside the trip list; approve or reject
   with a reason. The person who sent it cannot approve it while segregation of duties is on.
   **No voucher at approval** (unlike an outlay): mileage is pay and is booked by the salary
   voucher on the pay codes' accounts.
4. **Pay.** `ContextBuilder` turns approved mileage expenses into inputs:
   `MILERSATTNING` (own car) or the new `MIL_FORMANSBIL` / `MIL_FORMANSBIL_EL` (company car,
   rules `mileage.company_car_fossil` / `_electric`), quantity = hm / 100 mil, rate = the
   company's rate or none (= schablon). `Allowances` splits the excess to
   `MIL_SKATTEPLIKTIG`. Commute trips, when paid, become `MIL_SKATTEPLIKTIG` directly.
   The run claims the expense (`payroll_run_id`) like an outlay; approval of the run sets it
   PAID and the trips PAID.
5. **AGI.** `AgiDeclaration::MILEAGE` gets the two new codes, so 050 is ticked for a company
   car where the employee paid the fuel (KJ-R10; today's gap). The excess stays in 011.

The payslip line reads "Milersättning oktober, 17,25 mil". The trip list (PDF) is attached
to the salary voucher as underlag for the mileage lines (BFL 5:6–7).

### 5. Company cars: the log as evidence, never an automatic decision

Lön → Förmånsbilar shows per car and calendar year: business km and "3 000 mil uppnått / X mil
kvar" (61:9), private km and private occasions against 100 mil and 10 (Skatteverket's reading
of 61:11), and for a month with employer-paid fuel a **proposed** private fuel value = fuel
cost × private km / total km, with the arithmetic (61:10). Payroll staff accept a proposal
into `DRIVMEDELSFORMAN` or tick `long_business_mileage` themselves. NONOX never sets a
benefit to zero or to 75 % by itself (KJ-R11 to KJ-R13; L6, L7). A PRIVATE trip in a pool
car warns payroll staff (KJ-R14).

### 6. Settings (payroll module, Körjournal section)

- Rate per mil for own car and for company car: blank = Skatteverket's schablon for the trip
  year; a higher rate is allowed and split.
- "Ersätt resor till och från arbetet" (default off).
- Which projects and customers employees may pick (default: projects only, none of the
  customer register).
- Last day of the month to send trips (a reminder on Min sida, not a block).

### 7. Sole trader (EF) flow

Bokföring → Körjournal, for a company with `legal_form = EF` and Körjournal on. The owner
logs trips in their own car (not a business asset). "Bokför oktober" builds a **draft**
voucher in series A: debit a cost account the owner picks (proposal 5800 Resekostnader),
credit 2018 Övriga egna insättningar, amount = hm × the own-car rule, no input VAT
(KJ-R15, KJ-R17), the trip list PDF attached. It is posted through the normal
`PostVoucher` by the owner; the trips become BOOKED. The page says the alternative is not to
book and to claim the deduction on the NE-bilaga, and that the account choice is
`TODO(legal-verify)` until L8/L9 are answered. HB/KB partners are out (KJ-R16).

### 8. Mobile receipts

- **Employees (outlays):** unchanged flow (ADR-018 decision stands: the receipt agent reads
  only what is printed and never judges the purchase or the person). Added: the staff PWA
  manifest gets `shortcuts` "Fota kvitto" and "Ny resa", and its description mentions the
  körjournal.
- **Company receipts:** Inkorg → "Fota kvitto" (`inbox:manage`), a camera file input on a
  phone, creates an inbox message with source UPLOAD and type Receipt from the signed-in
  user. It passes the same checks as mail attachments (ADR-023 "Security before any
  reading"), then the same interpretation (template → NONOX AI if allowed → manual queue)
  and the same approval. Nothing is booked before a person approves (KJ-R21).
- **EF owner who paid privately:** the inbox voucher form offers "Betalt privat" as the
  payment, which credits 2018 instead of the bank account.
- The page never tells the user they may throw away the paper receipt until L14 (BFL 7:6) is
  answered (KJ-R22).

### 9. Personal data

- **No GPS, no background location, no telematics** in this ADR. Trips are typed by the
  person. A later GPS option is a separate decision with its own DPIA, opt-in per person, an
  off switch the person controls, and never outside working hours (IMY red-green list).
- Purpose limitation, stated on the screen: trip data is used to pay mileage, to book the
  owner's deduction and as evidence for car benefits. It is never used for performance,
  ranking or working-time control; no report shows trips per person except the claim and the
  car's own year summary.
- Private trips store date and km only. Free text (places, purpose) is encrypted at rest like
  other payroll free text. The audit log records ids and statuses, never places or purposes.
- Retention: trips under a paid claim or a booked voucher follow BFL 7:2 (seven years after
  the calendar year in which the fiscal year ended); `EraseEmployee` keeps them as it keeps
  outlays. Unpaid company-car trips: L13. Both go into `docs/gdpr/registerforteckning.md`,
  `lagringstider.md` and the data-subject export (`DataSubjectExport`).
- Legal basis per purpose is L12; until answered the register of processing lists the
  proposal from the rule sheet.

### 10. Out of scope

GPS and automatic trip detection; car telematics and OBD; map or route-distance services;
fuel card import; per diem and foreign trips (unchanged); VAT on the fuel share of mileage
(L10); output VAT on car and fuel benefits (L11); automatic 75 % or zero car benefit; HB/KB
partners; offline trip entry in the PWA (a later step after ADR-022's offline store is
reused); AI classification of trips.

## Consequences

- The schema prepared in 2026-10 (`MILEAGE_*` types, `trip_data`) is finally used; outlays
  and mileage share one approval queue and one run claim.
- Two new pay codes and an AGI change: a company-car mileage payment now ticks 050. This is
  accounting behaviour, so `docs/systemdokumentation/README.md` and `PAYROLL_MODULE.md` B.5
  and B.9 are updated in the same work, and golden payslip tests cover F1–F6, F10, F11.
- `rules.json` 2027 calls the mileage amounts' legal basis "not found"; it is IL 12:5. The
  builder corrects the note (and, per Q3, either keeps the keys pending or moves them to
  `continued`).
- New personal-data category (trips) in the GDPR register, retention list, privacy policy
  (one legal version bump) and data-subject export.
- Public docs: manual articles (`lon/korjournal.md`, `lon/utlagg.md`,
  `bokforing/korjournal-for-enskild-firma.md`, inbox), changelog entry, landing feature card
  with a real screenshot, `jamforelse.json` row with neutral keys, roadmap status.

## Build steps (each with tests first; builder, cold reviewer, Lead merges)

1. **Pay codes and AGI.** `MIL_FORMANSBIL`, `MIL_FORMANSBIL_EL` in `PayCodeCatalog` (7331,
   tax-free, QUANTITY), seeded idempotently for existing companies; `Allowances::TAX_FREE`
   and `AgiDeclaration::MILEAGE` extended. Tests: F3, F4, 050 for a company-car-only payment,
   F11 rounding. Correct the 2027 `rules.json` note.
2. **Core schema.** Migrations for `vehicles`, `vehicle_year_readings`, `vehicle_trips` with
   the freeze trigger; models with `BelongsToCompany`; tenancy tests (F15, F16);
   immutability test (UPDATE/DELETE of a PAID trip fails via Eloquent, query builder, raw SQL).
3. **Actions.** `LogTrip`, `SubmitMileageClaim`, `CloseVehicleYear` in `app/Actions/Mileage`;
   `ExpenseClaims::approve/reject` handle mileage types without a voucher. Tests per KJ rule:
   F5 (fuel benefit refuses), F6 (commute), F7b (business asset), F10 (laddhybrid date),
   F13, F14, segregation of duties.
4. **Payroll run.** `ContextBuilder` mileage branch; run approval sets trips PAID. Golden tests
   F1, F2, F6; query-count test (claims with 5 and 50 trips run the same number of queries).
5. **Min sida and Lön screens.** Trip entry, saved routes, claim, approval view, Förmånsbilar
   year summary (F8a–c, F9 proposals). Livewire tests; Swedish toasts; `lang/en.json`.
6. **EF owner.** Bokföring → Körjournal, draft voucher (F7), PDF trip list as attachment.
7. **Inbox upload.** "Fota kvitto" upload source with ADR-023's file checks; tests that an
   upload by a user without `inbox:manage` is refused and that no voucher exists before
   approval.
8. **Docs.** Manual, changelog, systemdokumentation, GDPR files, legal version, landing,
   comparison row, roadmap, PWA manifest shortcuts.

Steps 1 and 2 can run in parallel; 3 needs both; 5–7 need 3; 8 runs alongside each step.

## Owner questions

- **Q1** Should the körjournal be on by default for companies with payroll, or off until
  turned on (this ADR: off)?
- **Q2** May employees pick customers from the customer register on a trip, or only projects
  (this ADR: projects only by default)?
- **Q3** 2027 rates: keep pending until Skatteverket publishes, or carry forward from IL 12:5?
- **Q4** Is offline trip entry in the PWA needed for the pilot (this ADR: later)?
- **Q5** Is the monthly claim the right rhythm, or should a claim be possible per trip?
- **Q6** Is a GPS option wanted at all? If yes, it gets its own ADR and DPIA.

Lawyer and accountant questions are L0–L16 in the rule sheet.
