# ADR-061: Automatic car benefit (bilförmån)

- Status: Accepted (beta; every rule researched, not legally verified)
- Date: 2026-11-06
- Task: Track D.4 (closes the legal audit finding AUD-LON-08)

## Context

Payroll had the pay code `BILFORMAN`, the table `benefits` (with `car_data`)
and the rule `benefit.car.formula`, but nothing computed a car benefit: the
person running payroll typed the month's value. The value is set by law
(inkomstskattelagen 1999:1229, 61 kap. 5–11 §§) from data the company has, so
it can be computed, and a typed value is an easy place for a mistake that
lands in both the preliminary tax and the employer contributions.

## Sources (read 2026-11-06)

- Inkomstskattelag (1999:1229), ändrad t.o.m. SFS 2026:1393, 2 kap. 27 §
  (prisbasbelopp for the calendar year in which the tax year ends), 61 kap.
  5–11, 18–19 §§ and the transitional provisions of SFS 2021:479 and 2022:479,
  <https://data.riksdagen.se/dokument/sfs-1999-1229.text>. Quotes:
  - 5 §: "summan av – 0,29 prisbasbelopp, – ett ränterelaterat belopp, – ett
    prisrelaterat belopp, och – bilens fordonsskatt"; "nybilspris
    multiplicerat med summan av 70 procent av statslåneräntan och 0,01 … vid
    utgången av november året närmast före … dock lägst 0,5 procent"; "13
    procent av bilmodellens nybilspris".
  - 7 §: "bilmodell som är sex år eller äldre … det högsta av … nybilspriset
    och anskaffningsutgiften för extrautrustning … och – fyra prisbasbelopp".
  - 8 §: extrautrustning is added, "dock inte … alkolås".
  - 8 a §: 350 000 kr (elbil, vätgasbil), 140 000 kr (laddhybrid), 100 000 kr
    (gasbil, "annan gas än gasol eller vätgas"); "som högst uppgå till 50
    procent av nybilspriset".
  - 9 §: "minst 3 000 mil i tjänsten … 75 procent"; "en tolftedel för varje
    hel kalendermånad som han inte haft förmånen".
  - 10 §: drivmedel "marknadsvärdet multiplicerat med 1,2".
  - 11 §: "minskat med vad han betalat"; "ringa omfattning … noll".
  - SFS 2021:479: applies to cars taxable "för första gången den 1 juli 2021
    eller senare"; "Äldre bestämmelser gäller fortfarande" for earlier cars.
    SFS 2022:479 (8 a §): cars taxable "för första gången efter den 30 juni
    2022"; older 8 a § for earlier cars.
- Skatteverket, Belopp och procent 2026: "Statslåneräntan 30 november 2025:
  2,55 procent", "Prisbasbeloppet inkomstår 2026: 59 200 kronor", and the
  worked example 322 000 kr, fordonsskatt 4 050 kr → 72 045 kr (8 967 kr for
  2,785 % of 322 000),
  <https://skatteverket.se/foretag/skatterochavdrag/beloppochprocent/2026.106.1522bf3f19aea8075ba3294.html>.
- Skatteverket, Förmånsvärde miljöbilar: the amounts for cars "som togs i
  trafik den 1 juli 2022 eller senare", "får dock inte överstiga 50 procent av
  bilens nypris", "Elhybrider och etanoldrivna bilar omfattas inte",
  <https://www.skatteverket.se/privat/skatter/arbeteochinkomst/formaner/bilforman/miljobilar.4.3f4496fd14864cc5ac9e89a.html>.
- Skatteverket, Bilförmån för arbetsgivare: the employer may itself apply 75 %
  "löpande under året så länge du kan anta" 3 000 mil, and must otherwise
  "ompröva redovisningen … och redovisa fullt förmånsvärde",
  <https://www.skatteverket.se/foretag/arbetsgivare/lonochersattning/formaner/bilforman.4.3016b5d91791bf546791919.html>.
- Skatteverket, Drivmedelsförmån: 1,2 × market value for the tax deduction,
  "Som underlag för arbetsgivaravgifter använder du däremot marknadsvärdet utan
  att multiplicera det med 1,2", ruta 018, the employee's payment in ruta 098,
  <https://www.skatteverket.se/foretag/arbetsgivare/lonochersattning/formaner/bilforman/drivmedelsforman.4.3016b5d91791bf546791a89.html>.
- Skatteverket's Rättslig vägledning (Beräkna bilförmånsvärde) could not be
  read from this environment (request rejected); nothing here relies on it.

## Decisions

1. **The register is the existing `benefits` table** (type `CAR`), no new
   table or migration. `car_data` holds make and model, registration number,
   model year, the day the car first became taxable under
   vägtrafikskattelagen, fuel, nybilspris, extra equipment, yearly
   fordonsskatt, the 3 000 mil flag and the employee's monthly payment;
   `valid_from`/`valid_to` are the first and last day with the car. Screen
   Lön → Förmånsbilar (`/lon/formansbilar`, `payroll.cars`), action
   `CompanyCars`, audited (`payroll.car_benefit_created|updated|deleted`, ids
   and field names only). `payroll:view` sees it, `payroll:manage` changes it
   (owner, payroll administrator); the accountant and admin roles only look.
2. **The calculation is pure** (`Domain\Benefits\CarBenefitCalculator`) and
   every number is rule data: `benefit.car.formula` (existing: shares,
   statslåneränta, floor, reductions, 50 % cap, 75 %), `prisbasbelopp`, and
   the new `benefit.car.scope` (`formula_from_taxable_on` 2021-07-01,
   `reductions_from_taxable_on` 2022-07-01, six years, four
   prisbasbelopp), open-ended from 2026 and carried into 2027 (`continued`).
   Like every payroll rule it is imported unverified and used only once a
   person has checked it in Lön → Regler.
3. **Order**: price = nybilspris + extra equipment; a model six years or
   older (income year − model year ≥ 6) at least four prisbasbelopp; then the
   8 a § reduction, at most 50 % of that price; then 5 §; then 75 %.
   **Rounding**: each part is cut to whole kronor (as Skatteverket's example),
   the year's value and the monthly twelfth too.
4. **Months**: the regular run of a month (not extra runs) adds one line
   `BILFORMAN` with a twelfth of the value for the month's calendar year, for
   every month the employee has the car on any day (9 § andra stycket counts
   only whole months without it). The year is the month's, not the payment
   day's. With two employments in the month, the one that started last
   carries it. The line's explanation holds the whole calculation.
5. **Own payment**: a monthly amount on the car becomes an input
   `FORMAN_EGENBETALNING`; the engine already takes it from the net pay,
   lowers the taxable benefit (never below nothing), moves the reduction to
   ruta 013 in the AGI and credits 7388 (ADR-012, #80). Only a net-pay
   deduction lowers the value; there is no gross-deduction option.
6. **Posting and AGI unchanged**: the value is not booked as a cost; the tax
   and the contributions on it are (2710, 2730/7510). It is declared in ruta
   013 (`agi.field_codes.car_benefit`), with the existing nought rule.
7. **Refused, not guessed**: a car taxable before 1 July 2021, an
   environmental car taxable before 1 July 2022 (older wording not in the
   consolidated law), two cars in one month, a year without a verified
   formula (2027 until the statslåneränta of November 2026 is published) and a
   month with both a registered car and a typed `BILFORMAN` line stop the run
   with a Swedish message; the typed pay code remains for the cases NONOX
   does not compute.
8. **History is kept**: a car that has been in an attested month can only get
   an end date on or after the end of that month; it cannot be changed
   otherwise or deleted, and a new car cannot start in an attested month.
   Saving a car sends calculated runs of the months it touches back to draft.
9. **Fuel is not computed** (drivmedelsförmån stays a typed amount): it needs
   the month's fuel cost and mileage, and its contribution basis is the market
   value without the factor 1,2, which the engine (one contribution flag per
   line) cannot express yet. See the open finding below.

## Consequences

- The legal audit's AUD-LON-08 test is now a real test; the golden payslip
  with a typed 6 842 kr gives the same result as the registered car.
- Personal data: the car per employee (with registration number) is payroll
  data, kept and erased with the employee (EraseEmployee lists `benefits`);
  `docs/gdpr/registerforteckning.md` and `lagringstider.md` name it.

## Open questions (TODO(legal-verify))

- Rounding of the parts and of the monthly twelfth (here cut down to kronor).
- How "sex år eller äldre" is counted (here income year − model year ≥ 6),
  whether the 8 a § cap is on the price with extra equipment and after 7 §,
  and SKV's advice on extra equipment for cars ten years or older.
- A change of car within a month; a car registered abroad (övergångs-
  bestämmelserna punkt 2 b); the 3 000 mil requirement for part of a year
  (the flag is the user's judgement; earlier months are not re-calculated
  when it is turned off — the AGI must then be corrected by hand).
- Jämkning of the value on application (18–19 §§) is Skatteverket's
  decision and not built; "ringa omfattning" (11 § andra stycket) means the
  car is not registered at all.
- Fuel: see the addendum below.

## Addendum 2026-11-06: drivmedelsförmån and review fixes

### Fuel benefit (a production bug)

`DRIVMEDELSFORMAN` put its whole amount in the contribution basis. The
manual told users to type the benefit value, that is 1,2 × the market value,
so the contributions were taken on 1,2 × the market value: about 20 % too
much on the fuel. Sources (read 2026-11-06):

- Socialavgiftslagen (2000:980) 2 kap. 10 a § punkt 2, ändrad t.o.m. SFS
  2026:493: "värdet av drivmedelsförmån som avses i 61 kap. 10 §
  inkomstskattelagen ska beräknas till marknadsvärdet",
  <https://data.riksdagen.se/dokument/sfs-2000-980.text>.
- Skatteverket, Drivmedelsförmån: "Som underlag för arbetsgivaravgifter
  använder du däremot marknadsvärdet utan att multiplicera det med 1,2"
  (1 440 kr tax basis, 1 200 kr contribution basis).
- Skatteverket, agbeskrivning: ruta 018 `DrivmVidBilformanUlagAG` "hela det
  uppräknade förmånsvärdet", also when the employee paid; ruta 098
  `BetForDrivmVidBilformanUlagAG` (component schema 1.1, `faltkod="098"`) "det
  belopp som betalningsmottagaren betalat för drivmedel vid bilförmån genom
  nettolöneavdrag"; Skatteverket "beräknar arbetsgivaravgifterna automatiskt
  på marknadsvärdet". <https://www.skatteverket.se/agbeskrivning>

Decisions:

1. **The input stays the benefit value** (1,2 × market value), as the manual
   always said, so nothing already typed changes meaning and no stored data
   is converted. The market value is taken back as value / 1,2, half up to
   öre (new rule `benefit.fuel.factor`, `tax_factor_bp` 12000, IL 61 kap.
   10 §). The payslip line carries it as its contribution part
   (`PayslipLineResult::contributionOre`, `contribution_basis_ore` in the
   explanation).
2. **The employee's payment for the fuel** is the new system pay code
   `DRIVMEDEL_BETALNING` ("Egen betalning för drivmedel vid bilförmån",
   net deduction, credit 7388). It lowers the taxable fuel value (never below
   nothing) and the contribution basis up to the market value, and is
   declared in ruta 098; ruta 018 keeps the whole value. A payment without a
   fuel benefit in the month stops the run.
3. **Posted payroll is not touched**: no migration, no recalculation. A
   calculated but not attested run gets the new values when it is calculated
   again. The changelog asks customers who entered fuel benefits to check
   earlier periods with their accountant (a correction is a new AGI for the
   month, outside NONOX's automatic flow).
4. The rule must be checked under Lön → Regler before a run with fuel
   benefit can be calculated, like every payroll rule.

TODO(legal-verify): value / 1,2 can differ from Skatteverket's market value
by under one krona (319 kr → 265,83 kr, Skatteverket 266 kr); the payment
lowering the contribution basis (61 kap. 11 § through 2 kap. 10 a §);
098 is written as the payment used against the value (at most the value);
avgiftsfri fuel (ruta 134) has no 098 of its own, the payment lowers 134.
The general `FORMAN_EGENBETALNING` still moves onto 018 when it is larger
than the other benefits (unchanged).

### Review fixes

- A period (new car, later or open end date) may not take in a month whose
  regular run is attested and which the car did not already cover.
- One car per employee and **month**: a new car can start on the first of the
  month after the other ends (TODO(legal-verify) on a change within a month).
- The run reads all cars once (`ContextBuilder::prepareCars`, called by
  `PayrollRuns::calculate`), works out the carrying employment from the run's
  employments, uses the run's rules when the month is in the payment day's
  year (else one `RuleSet` for the month), and stores the
  `benefit.car.formula` version on the `BILFORMAN` line. The run's
  `rule_snapshot_hash` also covers the month's rules when they differ.
- The monthly twelfth is cut down each month, so twelve months can fall a
  few kronor short of the year's value (72 045 → 12 × 6 003 = 72 036). No
  source on rounding the twelfth was found; the TODO stays and the manual
  says so. No remainder rule is invented.
- `CarBenefitsPage::$editing` is `#[Locked]`.
