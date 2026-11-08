# Rule sheet: ADR-072 mileage log and mobile receipts (körjournal och kvitton i mobilen)

Researcher pass, 2026-11-08. Input for the builder of ADR-072. Not legal advice; the open
questions at the end go to a human lawyer or accountant before any rule marked
`TODO(legal-verify)` is built as a hard block.

## Sources actually read

| Id | Source | Version read | How |
|---|---|---|---|
| IL | Inkomstskattelag (1999:1229) — https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/inkomstskattelag-19991229_sfs-1999-1229/ | Consolidated "Ändrad: t.o.m. SFS 2026:1393" | Full text from `https://data.riksdagen.se/dokument/sfs-1999-1229.text`, read locally: 11 kap. 1 §, 12 kap. 2, 5, 7, 8, 24, 26–29 §§, 16 kap. 27 §, 61 kap. 5–11 §§, transitional note to SFS 2026:464 |
| SFL | Skatteförfarandelag (2011:1244) — `https://data.riksdagen.se/dokument/sfs-2011-1244.text` | current consolidated | 10 kap. 3 §, 39 kap. 3 § |
| SAL | Socialavgiftslag (2000:980) — `https://data.riksdagen.se/dokument/sfs-2000-980.text` | current consolidated | 2 kap. 10, 10 a, 11, 12, 21 §§ |
| ML | Mervärdesskattelag (2023:200) — `https://data.riksdagen.se/dokument/sfs-2023-200.text` | "Ändrad: t.o.m. SFS 2026:1025" | 5 kap. 31 §, 8 kap. 7 § (beskattningsunderlag uttag), 13 kap. 20–23 §§, the 25 § on deferred reporting of uttag |
| BFL | Bokföringslag (1999:1078) — `https://data.riksdagen.se/dokument/sfs-1999-1078.text` | "Ändrad: t.o.m. SFS 2024:342" | 1 kap. 2 § p. 9, 5 kap. 6–8 §§, 7 kap. 2 § |
| SKV-BP | Skatteverket, *Belopp och procent 2026* — https://skatteverket.se/foretag/skatterochavdrag/beloppochprocent/2026.106.1522bf3f19aea8075ba3294.html | page read 2026-11-08 | WebFetch |
| SKV-TB | Skatteverket, *Traktamente och bilersättning* (arbetsgivare) — https://www.skatteverket.se/foretag/arbetsgivare/lonochersattning/traktamenteochbilersattning | read 2026-11-08 | WebFetch |
| SKV-KJ | Skatteverket, *Körjournal* (arbetsgivare, bilförmån) — https://www.skatteverket.se/foretag/arbetsgivare/lonochersattning/formaner/bilforman/korjournal.4.3016b5d91791bf546791a00.html | read 2026-11-08 | WebFetch. The example journal on the page is an image; its column headings were **not** read |
| SKV-DF | Skatteverket, *Drivmedelsförmån* — https://www.skatteverket.se/foretag/arbetsgivare/lonochersattning/formaner/bilforman/drivmedelsforman.4.3016b5d91791bf546791a89.html | read 2026-11-08 | WebFetch |
| SKV-AG | Skatteverket, *Fältkoder i arbetsgivardeklarationen* — https://www.skatteverket.se/agbeskrivning | read 2026-11-08 | WebFetch, fältkod 011, 018, 050, 098 |
| SKV-NV | Skatteverket, *Resor i näringsverksamheten* — https://www.skatteverket.se/foretag/skatterochavdrag/avdragforforetag/resorochbilkostnader/resorinaringsverksamheten.4.3152d9ac158968eb8fd47ff.html | read 2026-11-08 | WebFetch |
| SKV-BM | Skatteverket, *Bilar och moms* — https://skatteverket.se/foretag/moms/sarskildamomsregler/bilarochmoms | read 2026-11-08 | WebFetch |
| IMY-RG | IMY, *Rödgrön lista* ("Använda platstjänster"), PDF created 2026-03-25 — https://www.imy.se/link/3106ace08fcd45c881ffbd308db84d1d.aspx | read 2026-11-08 | PDF converted to text |

**Method note.** The law texts were read in full locally. Skatteverket's pages were read
through a fetch tool that returns the page as converted text; the Swedish quotes below from
SKV-* are as that tool returned them. A human re-reading each SKV quote against the live page
before the builder starts is cheap and is item **L0**.

**Not read (gaps not filled from memory):**

- Skatteverket's 2027 amounts (Belopp och procent 2027 is not published; `rules.json` 2027
  already lists the mileage keys as pending).
- Any Skatteverket ställningstagande on input VAT on the fuel part of a bilersättning paid
  for an employee's own car. No such provision was found in ML; see KJ-R17.
- Skatteverket's guidance on how the private share of fuel is computed from a körjournal
  (SKV-DF only says the market value of fuel used for private driving × 1,2).
- IMY's own page on positioning of employees (the URL tried returned 404); only IMY-RG was
  read. An older Datainspektionen decision (2014, GPS körjournal) was seen only in search
  results and is **not** relied on.
- BAS account guidance for the sole trader's own-car deduction (no source read).

## What already exists in NONOX (mapped 2026-11-08)

- Pay codes `MILERSATTNING` (tax-free, own car, account 7331, unit QUANTITY) and
  `MIL_SKATTEPLIKTIG` (taxable excess, 7332, tax table, contributions) in
  `app/Modules/Payroll/Domain/PayCodeCatalog.php`.
- `Allowances` step (`Domain/Engine/Steps/Allowances.php`): `MILERSATTNING` priced against
  rule `mileage.own_car_tax_free_per_mil`, excess split to `MIL_SKATTEPLIKTIG`; a quantity
  (mil) is required (ADR-012, AUD-LON-09, #76).
- Rules 2026 `mileage.own_car_tax_free_per_mil` 2 500 öre, `mileage.company_car_fossil`
  1 200, `mileage.company_car_electric` 950 (`database/data/2026/rules.json`), all required
  keys in `RuleSet`. **The two company-car keys have no pay code and are never used by the
  engine.**
- AGI: `AgiDeclaration::MILEAGE = ['MILERSATTNING']` sets `Bilersattning` (ruta 050).
  **A förmånsbil mileage payment would not tick 050** (see KJ-R10).
- `expenses` table already has `type` enum `MILEAGE_OWN_CAR`, `MILEAGE_COMPANY_CAR` and a
  `trip_data` jsonb, **unused**; only `OUTLAY` is created (`ExpenseClaims`).
- Utlägg on Min sida (`/anstalld`, `MyExpenses`, file input `capture="environment"`), NONOX AI
  receipt reading (`AnalyzeDocument::readsReceiptsFor`, setting `read_employee_receipts`,
  ADR-018 "expense-receipt concern"), approval books cost + 2641 against 2890 in series L,
  the next run repays through `UTLAGG` (`ContextBuilder::outlays`).
- Company cars with fuel type (`CarBenefitCar::FUELS`, incl. `PLUG_IN_HYBRID`, `ELECTRIC`),
  fuel benefit `DRIVMEDELSFORMAN` × 1,2 and own payment (`FuelBenefit`), the 3 000 mil flag
  `long_business_mileage` (typed by hand today).
- Inbox (ADR-023): e-mail only, aliases `+kvitto`; no upload from a phone.
- Staff PWA manifest `public/anstalld.webmanifest` ("Lönebesked, frånvaro och utlägg").

## Classification used

- **HARD** — NONOX enforces it (validation, block, constraint).
- **ADVICE** — NONOX computes or warns; a person decides.
- **OUT** — out of scope for ADR-072.

---

## A. Tax-free mileage allowance (employees)

**KJ-R1 Own car, tax-free amount per km.** The employee's cost for business trips (tjänsteresor)
with their own car is deductible at a fixed amount per km; the employer may pay up to that
amount without tax or contributions.
- IL 12 kap. 5 § första stycket: "Utgifter för tjänsteresor med egen bil ska dras av med 2 kronor och 50 öre för varje kilometer."
- SKV-BP: "Egen bil: 25 kronor per mil." SKV-TB: "Om du betalar ut skattefri ersättning enligt schablon gör du inte något skatteavdrag eller betalar arbetsgivaravgifter på ersättningen."
- Class: HARD (amount from rule `mileage.own_car_tax_free_per_mil`, never hard-coded).
- Note for the 2027 rule file: the amount **is** in the law text (12:5, no end date in the
  consolidated text). `rules.json` 2027 lists the key as pending with the note "The legal
  basis for the amount was not found in the law text"; that note is wrong. The builder
  should move the three mileage keys to `continued` with IL 12:5 as source, or keep them
  pending until Belopp och procent 2027 confirms them (owner/consultant choice, Q3).
- Test hints: 172,5 km at 25 kr/mil → 43 125 öre tax-free (fixture F1).

**KJ-R2 Förmånsbil, tax-free amount per km by fuel.** When the employee drives the company car
on business and pays **all** fuel for those trips: el 0,95 kr/km, other fuel 1,20 kr/km.
- IL 12 kap. 5 § andra stycket: "ska utgifter för elektricitet till elbil dras av med 95 öre för varje kilometer och utgifter för annat drivmedel med 1 krona och 20 öre för varje kilometer."
- SKV-BP: "Förmånsbil: 12 kronor per mil förutom förmånsbilar som drivs helt med el där schablonbeloppet är 9,50 kronor per mil." and "För en laddhybridbil som kan drivas både med el och annat drivmedel gäller det högre beloppet".
- Mapping from `CarBenefitCar::FUELS`: `ELECTRIC` → 9,50 kr/mil; `PETROL`, `DIESEL`,
  `ETHANOL`, `GAS`, `PLUG_IN_HYBRID`, `ELECTRIC_HYBRID` → 12 kr/mil.
  `HYDROGEN`: `TODO(legal-verify)` — 12:5 says "elektricitet till elbil" vs "annat
  drivmedel"; a fuel-cell car is not charged from outside; 12 kr/mil is the literal reading.
- Class: HARD (rules `mileage.company_car_electric` / `mileage.company_car_fossil`).
- Test hints: F3, F4.

**KJ-R3 Förmånsbil only if the employee paid all fuel.** If the employer paid any of the fuel
(fuel card, partly), no tax-free mileage for the company car; any payment is pay.
- SKV-TB: skattefri schablonersättning only "om den anställda betalat allt drivmedel själv"; partial payment: "kan du inte betala ut skattefri bilersättning vid tjänsteresor" … "eventuell ersättning är lön." Also: "inte kan betala ut skattefri ersättning till din anställda för hens faktiska kostnader för drivmedel."
- Class: HARD at the claim (the employee confirms "Jag har betalat allt drivmedel för resorna"
  per claim; a car whose `Benefit` has a fuel benefit or a fuel card flag in the month blocks
  the tax-free code) and ADVICE (the approver checks).
- Test hint: förmånsbil with a `DRIVMEDELSFORMAN` line in the same month → claim is refused
  as tax-free; the approver may pay it only as taxable pay (F5).

**KJ-R4 Laddhybrid charged at the workplace, from 2026-07-01.** For a plug-in hybrid company
car, charging with electricity the employer provides at the workplace no longer removes the
tax-free amount, for trips after 2026-07-01.
- IL 12 kap. 5 § tredje stycket (Lag 2026:464): "gäller avdragsrätten i andra stycket även i de fall han eller hon laddar förmånsbilen med elektricitet som arbetsgivaren tillhandahåller i anslutning till arbetsplatsen." Transitional rule: "träder i kraft den 1 juli 2026" and "tillämpas första gången på utgifter för drivmedel som är förenade med tjänsteresor efter ikraftträdandet."
- SKV-TB: "Från och med 1 juli 2026 … även om hen har laddat bilen i anslutning till arbetsplatsen".
- Class: HARD (by trip date: a `PLUG_IN_HYBRID` trip on or after 2026-07-01 may carry
  "laddad på arbetsplatsen" without losing the tax-free code; before that date it may not).
- `TODO(legal-verify)`: "efter ikraftträdandet" — is a trip **on** 2026-07-01 covered? The
  builder uses `>= 2026-07-01` (the law is in force that day) until answered (L4).
- Test hint: F10.

**KJ-R5 Excess above the tax-free amount is pay.** A rate above the schablon: the excess is
salary with tax deduction and employer contributions, reported as pay.
- SKV-TB: "ska den överskjutande delen av ersättningen hanteras som lön. Det innebär att du ska göra skatteavdrag och betala arbetsgivargifter på den överskjutande delen."
- SKV-AG fältkod 050: "Har du betalat ut högre bilersättning än schablonbeloppet ska du redovisa den överskjutande delen som lön." Fältkod 011: "kontant ersättning för arbete som är underlag för arbetsgivaravgifter".
- SFL 10 kap. 3 § andra stycket p. 9 (skatteavdrag on "andra ersättningar för kostnader i tjänsten … till den del ersättningen avser kostnader som det är uppenbart att mottagaren inte får dra av"); SAL 2 kap. 11 § p. 1 (kostnadsersättning likställs med ersättning för arbete). `TODO(legal-verify)`: the exact statutory chain from these two to "excess is avgiftspliktig, the schablon part is not" is not spelled out in the text read; Skatteverket's guidance above is followed.
- Class: HARD — already built (`MIL_SKATTEPLIKTIG`, tax table, contributions, AGI 011).
  ADR-072 adds the same split for the förmånsbil code.
- Test hint: F2.

**KJ-R6 Only tjänsteresor qualify; commuting is private.** The deduction in 12:5 is for
tjänsteresor. Trips between home and the regular workplace (arbetsresor) are a separate
deduction in the employee's own return (12:26–29), not a tax-free allowance. Everything
received because of the employment is taxable unless exempted (11:1).
- IL 11 kap. 1 §: "Löner, arvoden, kostnadsersättningar … ska tas upp som intäkt, om inte något annat anges". IL 12 kap. 26 §: "resor mellan bostaden och arbetsplatsen (arbetsresor)". SKV-DF gives "resor till och från arbetet" as an example of private trips paid by the employer.
- Class: HARD for the data (each trip has a kind: `BUSINESS` / `COMMUTE` / `PRIVATE`); a
  payment for `COMMUTE` or `PRIVATE` trips can only go to a taxable pay code.
  ADVICE for the classification itself (the employee chooses, the approver checks).
- Test hint: F6.

**KJ-R7 Tjänsteställe: home can be the workplace.** Whether a trip from home is a tjänsteresa
depends on the tjänsteställe: where most of the work is done; for work on constantly
changing sites, usually where material is collected; in construction and similar trades with
limited time per site, the home.
- IL 12 kap. 8 §: "Tjänstestället är den plats där den skattskyldige utför huvuddelen av sitt arbete." … "Om arbetet pågår en begränsad tid på varje plats enligt vad som gäller för vissa arbeten inom byggnads- och anläggningsbranschen och liknande branscher, anses bostaden som tjänsteställe."
- Class: ADVICE. The employment may carry a "tjänsteställe" note (free text or "bostaden")
  shown beside the trip classification. NONOX does not decide. `TODO(legal-verify)` (L5):
  whether NONOX should pre-classify home→site trips as BUSINESS when the employment says
  "bostaden är tjänsteställe".

**KJ-R8 Distance must be shown; a körjournal is the evidence.** For mileage the number of
kilometres must appear in the underlag; Skatteverket names a körjournal from the employee as
a way to get it. The employer must keep documentation for its AGI and for control.
- SKV-TB: "antal körda kilometer [ska] framgå"; uppgifter genom en körjournal "från din anställda".
- SFL 39 kap. 3 §: "Den som är uppgiftsskyldig … ska i skälig omfattning genom räkenskaper, anteckningar eller annan lämplig dokumentation se till att det finns underlag för att fullgöra uppgiftsskyldigheten samt för kontroll".
- Class: HARD — a mileage claim cannot be submitted without at least one trip with date and
  distance; ADVICE for the rest of KJ-R9.

**KJ-R9 What a körjournal should contain.** Skatteverket (förmånsbil page): odometer at the
start **and** end of the year must be there; it **should** contain registration number, year,
date and odometer at start and end of each trip, km per trip, start and end address, errand
or purpose, places, companies or contact persons visited (not needed for private trips),
driver, refuelling or charging; record fuel cost when refuelling.
- SKV-KJ, as read: "Mätarställning vid årets början" and "mätarställning vid årets slut"; the
  "bör" list as above. An electronic körjournal is accepted and "räknas inte som den typ av
  extrautrustning som höjer bilförmånsvärdet"; it must be completed with customers visited.
- No law text read makes a körjournal mandatory; SKV-KJ calls it a good way to show use.
- Class: for a **company car** (förmånsbil or pool car): HARD for year-start and year-end
  odometer per vehicle (a year cannot be "closed" in the log without both); HARD per trip for
  date and distance; ADVICE (warning, not block) for start/end place, purpose and odometer per
  trip. For an **own car** claim: HARD date, distance, purpose; place and odometer optional
  (SKV-TB only requires the km to appear; purpose is needed to judge KJ-R6).
- Private trips in a company car: date and distance only; no place, no purpose (SKV-KJ: not
  needed for private trips; data minimisation, KJ-R19).
- Test hint: closing year 2026 for a pool car with year-start odometer 41 200 and no
  year-end value → blocked with a Swedish message.

**KJ-R10 AGI ruta 050 for both own car and förmånsbil.** Ruta 050 is ticked for compensation
for trips in a private car **including a förmånsbil where the employee pays the fuel**; the
tax-free part is shown only by the tick.
- SKV-AG fältkod 050: "ersättning för resor med privat bil inklusive förmånsbil där den anställda själv betalar drivmedel." SKV-TB: up to the schablon "endast med kryss i ruta 050".
- Class: HARD. **Gap in today's code:** `AgiDeclaration::MILEAGE` lists only
  `MILERSATTNING`. The new förmånsbil code must be added.
- Test hint: an employee paid only `MIL_FORMANSBIL` 30 000 öre → AGI individual has
  `Bilersattning = 1`, no amount in 011.

## B. Company cars: what the körjournal is evidence for

**KJ-R11 Fuel benefit: total distance unless private use is shown lower.** Employer-paid fuel
for a company car is a benefit valued at market value × 1,2 of the fuel assumed used for the
**total** distance, reduced if the employee makes it probable that private consumption was
lower.
- IL 61 kap. 10 §: "beräknas till marknadsvärdet multiplicerat med 1,2 av den mängd drivmedel som kan antas ha förbrukats för förmånsbilens totala körsträcka … Om han gör sannolikt att den mängd drivmedel som han har förbrukat vid privat körning är lägre, ska marknadsvärdet för drivmedelsförmånen sättas ned i motsvarande mån."
- SKV-DF: "multiplicera marknadsvärdet med 1,2 för den mängd drivmedel som har använts vid privat körning"; contributions on "marknadsvärdet utan att multiplicera det med 1,2"; market value "inklusive moms".
- Class: ADVICE. From the log NONOX may **propose** the month's private fuel as fuel cost ×
  private km / total km, shown with the arithmetic, which payroll staff accept into
  `DRIVMEDELSFORMAN` (existing pay code). `TODO(legal-verify)` (L6): whether a distance
  share is an acceptable way to "göra sannolikt", or whether consumption per km must be shown.
- Test hint: F9.

**KJ-R12 3 000 mil business → 75 % car benefit.**
- IL 61 kap. 9 §: "För den som kört minst 3 000 mil i tjänsten med förmånsbilen under ett kalenderår ska förmånsvärdet beräknas till 75 procent".
- SKV-KJ calls it "omfattande tjänstekörning".
- Class: ADVICE. The log sums BUSINESS km per car and year and shows "3 000 mil uppnått / X
  mil kvar"; the existing manual flag `long_business_mileage` stays the decision (a person
  ticks it). `TODO(legal-verify)` (L7): the flag is set before the year ends (monthly
  benefit); how a forecast that later fails is corrected is a payroll question, not built.
- Test hint: F8a.

**KJ-R13 Car benefit of minor extent (ringa omfattning) → value 0.**
- IL 61 kap. 11 § andra stycket: "Om den skattskyldige haft bilförmån bara i ringa omfattning ska förmånen värderas till noll."
- SKV-KJ: a careful körjournal shows that the employee has not driven privately more than 100
  mil **or** on more than 10 occasions in a year; "Båda villkoren ska vara uppfyllda."
- Class: ADVICE. The log shows, per car and calendar year, private km and private occasions
  against 100 mil / 10. It never sets a benefit to zero by itself. The numbers 100 and 10 are
  Skatteverket's guidance, not law text: they become a payroll rule key
  (`benefit.car.minor_extent`, `TODO(legal-verify)`), never a constant.
- Test hints: F8b, F8c.

**KJ-R14 Company pool car without a benefit.** A company-owned car that nobody has as a
förmånsbil: private use would be a benefit (61 kap. 5 §, valuation; 11 kap. 1 §, taxable).
The körjournal is how the company shows there was none.
- Class: ADVICE — a PRIVATE trip in a vehicle marked "ingen förmånsbil" raises a warning to
  payroll staff ("Privat körning i en bil utan bilförmån kan vara en skattepliktig förmån").

## C. The owner of an enskild firma

**KJ-R15 Own car in the business: 2,50 kr/km deduction, not if the car is a business asset.**
- IL 16 kap. 27 § första stycket: "Utgifter för resor som en näringsidkare gör med egen bil i näringsverksamheten ska dras av med 2 kronor och 50 öre för varje kilometer. Detta gäller dock inte om bilen är en tillgång i näringsverksamheten."
- SKV-NV: "Om du använder din egen bil får du avdrag med 25 kronor per mil." A privately leased car counts as own car. Förmånsbil from an employment used in the business: 12 / 9,50 kr per mil.
- SKV-NV on booking: "Du bokför löpande dina utgifter som du har i samband med resor som du gör i din näringsverksamhet." If an expense is not booked, the deduction can be made "i R16 eller R22 på NE-bilagan" (as returned by the fetch tool; NONOX's `NeCodes` labels R16 "Kostnader som inte bokförts men som ska dras av" — L8 checks R22).
- Class: HARD (an EF owner trip claim is refused for a vehicle registered as a business
  asset; rate from the same rule key as KJ-R1, since 16:27 points to the same amount).
  ADVICE for booking vs NE.
- Booking (proposal, `TODO(legal-verify)`, L9): one voucher per month in series A, debit a
  cost account the owner picks (proposal 5800 Resekostnader — no BAS source read), credit
  2018 Övriga egna insättningar (the owner paid the car privately). No input VAT (KJ-R17).
  Underlag: the month's trip list as a PDF attached to the voucher (BFL 5:6–7).
- Test hint: F7.

**KJ-R16 Trading partner (HB) and AB owners.** An AB owner who is employed is an employee
(section A). A partner in an HB with a car benefit from the HB follows 16:27 andra stycket
(1,20 / 0,95 kr/km). Class: OUT for HB in ADR-072 (no HB partner screen exists); AB owner
uses the employee flow.

## D. VAT

**KJ-R17 No input VAT on mileage allowance.** No provision in ML (2023:200) was found that
gives the employer input VAT on a mileage allowance for an employee's own car or the EF
owner's own car. ML 13 kap. 23 § gives deduction for running costs (incl. fuel) only if the
vehicle "tillhör inventarierna i verksamheten eller har hyrts för användning i denna".
- ML 13 kap. 23 §: "får dra av ingående skatt som hänför sig till driftskostnader, om fordonet tillhör inventarierna i verksamheten eller har hyrts för användning i denna."
- Class: HARD default: mileage vouchers carry no 2641 row. `TODO(legal-verify)` (L10):
  whether Skatteverket accepts input VAT on the fuel share of a bilersättning (practice not
  read; not built until answered).

**KJ-R18 Fuel for company cars and output VAT on private use.** Fuel bought for a company car
(an inventarie or hired) is a running cost with input VAT (ML 13:23; SKV-BM: "Du kan däremot
få avdrag för moms på drivmedel och andra driftskostnader"). Private use of a personbil
belonging to the business is uttag of a service (ML 5:31) with the beskattningsunderlag
taken from the SAL benefit value (ML 8 kap. 7 §); SKV-BM: "För personbilar ska du redovisa
utgående moms med 20 procent av bilförmånsvärdet."
- Class: OUT for ADR-072 (car benefit VAT is a separate question for the car-benefit screen).
  The körjournal only supplies km. `TODO(legal-verify)` (L11): whether employer-paid fuel
  used privately (drivmedelsförmån) gives its own uttag, separate from the bilförmån.

## E. Personal data

**KJ-R19 Location and trips are personal data; no tracking.** Trip places, times and
customers visited describe where a named person was.
- IMY-RG "Använda platstjänster", **inte okej**: "Använda positioneringsteknik som medarbetare inte kan stänga av vid behov, exempelvis vid läkarbesök"; "Övervaka medarbetares position utanför arbetstid"; "Kräva att mobilens platsdelning ska vara på hela dagen, även under raster"; "Använda elektronisk körjournal som införts endast i syfte att redovisa till Skatteverket för att följa upp medarbetares prestationer." **Okej**: "Använda elektronisk körjournal för att uppfylla krav på redovisning till Skatteverket."
- Class: HARD design rules: no GPS or background location in v1; trips are typed by the
  person; private trips store date and km only; trip data is never used for performance
  reports, ranking or working-time control (purpose limitation, GDPR art. 5.1 b), and the
  screen says so. Any later GPS option needs its own DPIA and is out of ADR-072.
- `TODO(legal-verify)` (L12): legal basis per purpose (paying the allowance: contract /
  art. 6.1 b; tax underlag: legal obligation SFL 39:3 / art. 6.1 c; förmånsbil private
  trips: 6.1 c via 61:10–11?), to be entered in `docs/gdpr/registerforteckning.md`.

**KJ-R20 Retention.** Trips that underlie a payment or a voucher are underlag to a
verifikation (BFL 5:6–7) and räkenskapsinformation (BFL 1:2 p. 9); kept "fram till och med det
sjunde året efter utgången av det kalenderår då räkenskapsåret avslutades" (BFL 7:2).
- Class: HARD (no deletion of a trip linked to an approved claim or voucher within the
  period; `EraseEmployee` keeps them as it keeps outlays).
- `TODO(legal-verify)` (L13): retention of company-car trips that were never paid (private
  trips, pool-car trips) — underlag under SFL 39:3 only; how long?

## F. Mobile receipts

**KJ-R21 A receipt is a verifikation; the person approves.** Every business event has a
verifikation with date compiled, date of event, what, amount, counterparty (BFL 5:6–7). An
employee's receipt photo is the received verifikation for the outlay; the approval adds
account and checks VAT.
- Class: HARD (already built for utlägg: no booking before a person approves, receipt
  attached to the voucher, ADR-018 "No agent saves, posts or pays anything").
- ADR-018's decision stands: the receipt agent reads only what is printed and never judges
  the purchase or the person.

**KJ-R22 Paper receipt after scanning.** `TODO(legal-verify)` (L14): BFL 7 kap. 6 § on
destroying paper originals after conversion was **not** read in this pass; the mobile flow
must not tell users they may throw the paper receipt away until it is.

---

## Synthetic fixtures (numbers only, no real data)

All distances in hectometres (`hm`, 0,1 km) in storage; 25 kr/mil = 25 öre per hm,
12 kr/mil = 12 öre per hm, 9,50 kr/mil = 9,5 öre per hm. Payroll quantity in mil = hm / 100.

| Id | Case | Input | Expected |
|---|---|---|---|
| F1 | Own car, at schablon | Employee E1, March 2026: trips 42,0 km + 118,5 km + 12,0 km = 1 725 hm, company rate 25 kr/mil | One `MILERSATTNING` input, quantity 17,25 mil, 43 125 öre tax-free; no `MIL_SKATTEPLIKTIG`; AGI 050 ticked; nothing in 011 |
| F2 | Own car, above schablon | Same trips, company rate 30 kr/mil | Total 51 750 öre; `MILERSATTNING` 43 125 tax-free; `MIL_SKATTEPLIKTIG` 8 625 (tax table, contributions); 050 ticked; 8 625 in 011 |
| F3 | Förmånsbil, petrol, employee paid all fuel | 250,0 km business, rate = schablon | `MIL_FORMANSBIL` 25 mil × 12 kr = 30 000 öre tax-free; 050 ticked |
| F4 | Förmånsbil, el | 250,0 km business | 25 mil × 9,50 = 23 750 öre tax-free |
| F5 | Förmånsbil with fuel card | Car has `DRIVMEDELSFORMAN` in April 2026; employee claims 100 km | Tax-free refused with "Skattefri milersättning för förmånsbil kräver att du betalat allt drivmedel själv"; approver may pay 1 200 öre only as taxable pay |
| F6 | Commuting | 20 days × 36 km home–office, kind COMMUTE, company pays 25 kr/mil | 72 mil × 25 = 180 000 öre, all taxable (`MIL_SKATTEPLIKTIG`), 050 **not** ticked by this line alone |
| F7 | EF owner, own car | Owner, May 2026: 1 240 km business, car not a business asset | Voucher series A dated 2026-05-31: D 5800 (proposal) 310 000 öre, K 2018 310 000 öre; no 2641; trip list PDF attached |
| F7b | EF owner, car is an asset | Same, vehicle marked business asset | Claim refused (IL 16:27 sista meningen) |
| F8a | 3 000 mil | Förmånsbil, 2026 BUSINESS sum 30 400 km | Shows "3 000 mil i tjänsten uppnått"; flag not set automatically |
| F8b | Minor private use | 2026 PRIVATE: 950 km on 9 occasions | Shows "Privat körning inom 100 mil och 10 tillfällen"; no automatic zero benefit |
| F8c | Not minor | 2026 PRIVATE: 950 km on 11 occasions | Shows "Fler än 10 privata tillfällen" |
| F9 | Private fuel share proposal | Month: total 2 000,0 km, private 300,0 km, fuel card 300 000 öre incl. VAT | Proposed market value 45 000 öre; `DRIVMEDELSFORMAN` 54 000 (× 1,2); contribution basis 45 000 (existing FuelBenefit) — proposal only |
| F10 | Laddhybrid, workplace charging | Trip 2026-06-20 100 km "laddad på arbetsplatsen"; trip 2026-07-02 100 km same | June trip: no tax-free förmånsbil code; July trip: 10 mil × 12 = 12 000 öre tax-free |
| F11 | Rounding | Own car: 7,3 + 4,4 + 0,8 km = 125 hm; förmånsbil el same km | Own: 3 125 öre exact; el: 125 × 9,5 = 1 187,5 → 1 188 öre (HalfUp on the claim sum, not per trip; `TODO(legal-verify)` L15) |
| F12 | Mobile utlägg | Photo of parking receipt 120,00 kr incl. 24,00 kr VAT, approved on 5800 (proposal) | Voucher series L: D 5800 9 600, D 2641 2 400, K 2890 12 000; next run `UTLAGG` 12 000 net (existing behaviour) |
| F13 | Odometer consistency | Car C1 trips: 41 200→41 242, then 41 230→41 260 | Second trip warned "Mätarställningen är lägre än förra resans slut" (ADVICE, not block) |
| F14 | Year odometer | Pool car C2, 2026 year-start 10 000, no year-end | Closing 2026 log blocked: "Fyll i mätarställningen vid årets slut" |
| F15 | Tenancy | Company A trip id requested by a user of company B | 404 |
| F16 | Other employee | Employee E1 opens E2's trip id on Min sida | 404 |

## Open questions for a human lawyer or accountant

- **L0** Re-read each SKV quote above against the live pages (fetch-tool text).
- **L1** Is a körjournal ever a legal requirement (e.g. for the 3 000 mil rule or for a
  pool car), or always only evidence? (No law text read requires one.)
- **L2** Must the employer's mileage underlag show purpose and places per trip, or is
  date + km + "tjänsteresa" enough for tax-free payment of own-car mileage?
- **L3** 2027: move the mileage keys to `continued` from IL 12:5, or keep them pending until
  Skatteverket publishes Belopp och procent 2027?
- **L4** Laddhybrid: is a trip on 2026-07-01 itself "efter ikraftträdandet"?
- **L5** May NONOX pre-classify home→site trips as tjänsteresor when the employment records
  "bostaden är tjänsteställe" (IL 12:8 andra stycket)?
- **L6** Is fuel cost × private km / total km an acceptable way to "göra sannolikt" the
  private consumption (IL 61:10)?
- **L7** 3 000 mil: how is the 75 % value handled month by month when the threshold is only
  known at year-end?
- **L8** NE-bilaga: R16 or R22 for an own-car deduction that was not booked?
- **L9** EF owner booking: which BAS account (5800 group?) against 2018, monthly or per trip,
  and is booking preferable to the NE adjustment?
- **L10** Input VAT on the fuel share of a mileage allowance: allowed, and if so how computed?
- **L11** Does employer-paid fuel used privately give its own uttag under ML, separate from
  the car benefit's 20 % rule?
- **L12** Legal basis per purpose for trip data (6.1 b / 6.1 c / 6.1 f) and whether an
  employer may require employees with a company car to keep a log.
- **L13** Retention of company-car trips that underlie no payment.
- **L14** BFL 7:6 — may the paper receipt be destroyed after the photo is stored?
- **L15** Rounding of the tax-free amount: per claim, per month, or per trip?
- **L16** Hydrogen (fuel-cell) company cars: 12 or 9,50 kr/mil?
