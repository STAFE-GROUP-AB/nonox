# Granskningschecklista: Lön och arbetsgivardeklaration (AGI)

| Fält | Värde |
|---|---|
| Modul | Lön (löneberäkning, förmåner, semester, sjuklön, tjänstepension), Avtal (kollektivavtal) och OB/övertid i Planering, lönefil, bokföring i serie L och arbetsgivardeklaration (AGI) |
| Version av NONOX som granskas | ____________________ |
| Granskare (namn) | ____________________ |
| Datum | ____________________ |
| Kod/ADR som testas | `app/Modules/Payroll/Domain/Engine/` (stegen BaseSalary, AbsenceDeductions, SickPay, Additions, Vacation, Allowances, WithholdingTaxStep, NetItems, EmployerContributions, Pension, NetPay), `app/Modules/Payroll/Domain/Tax/WithholdingTax.php`, `app/Modules/Payroll/Domain/Benefits/FuelBenefit.php`, `app/Modules/Payroll/Domain/Benefits/CarBenefitCalculator.php`, `app/Modules/Payroll/Domain/SalaryVoucher.php`, `app/Modules/Payroll/Domain/PayCodeCatalog.php`, `app/Modules/Payroll/Support/Agi/`, `app/Modules/Payroll/Support/Vacation/VacationLedger.php`, `app/Modules/Payroll/Actions/PayrollRuns.php`, `app/Modules/Payroll/database/data/2026/rules.json`, `app/Modules/Payroll/database/data/2027/rules.json`, `app/Modules/Agreements/` (AgreementPricer, MinimumWageCheck, TimeRounding, `database/data/agreements/*`), `app/Modules/Planning/Domain/ObSplitter.php`, `app/Modules/Planning/Domain/OvertimeKinds.php`, `PAYROLL_MODULE.md`, `docs/adr/ADR-012-payroll.md`, `docs/adr/ADR-033-nonox-avtal.md`, `docs/adr/ADR-061-car-benefit.md` |
| Manualavsnitt | `docs/manual/sv/lon/` (särskilt `lonekorning.md`, `regler-och-skattetabeller.md`, `franvaro-och-sjuklon.md`, `semester.md`, `formaner-traktamente-och-milersattning.md`, `formansbilar.md`, `arbetsgivaravgifter-och-pension.md`, `lonefil-och-bokforing.md`, `arbetsgivardeklaration.md`), `docs/manual/sv/avtal/`, `docs/manual/sv/planering/ob-och-overtid-i-lonen.md` |

Den här checklistan gäller beslut 7 i `docs/status/manskliga-beslut.md`: en namngiven, auktoriserad redovisningskonsult granskar riktiga fall och skriver under, och Beta-märkningen tas bort för den godkända modulen. Beslut 17 i samma fil gäller drivmedelsförmånen och har en egen, markerad del i checklistan (fallen LON-19 till LON-26).

## Att veta innan du börjar

- Alla belopp nedan är räknade ur reglerna i `app/Modules/Payroll/database/data/2026/rules.json` (och `2027/rules.json` där det anges), ur kollektivavtalsdatat i `app/Modules/Agreements/database/data/agreements/` och ur koden. Beloppen är uträknade för hand ur lagens och avtalens regler så som koden tillämpar dem; de har inte körts i appen. Avvikelser mellan din körning och tabellen betyder antingen att koden räknar fel, att jag har läst koden fel, eller att regeln är fel. Skriv det i kommentaren.
- Skatteavdrag enligt tabell är hämtat ur Skatteverkets allmänna månadstabell 2026, tabell 33 (filen `tests/Fixtures/payroll/skattetabell-2026-manad-tabell-33.txt` i projektet). **Konsulten verifierar tabellvärdena mot Skatteverkets publicerade tabell 33 för 2026** (skatteverket.se, Skattetabeller) innan de används som facit. Procentrader i tabellen avrundas nedåt till hel krona (regel `tax.rounding`, skatteförfarandeförordningen 22 kap. 1 §).
- Alla regler i `rules.json` importeras som **okontrollerade** och används inte i en lönekörning förrän en namngiven person har kontrollerat dem under **Lön → Regler** (se LON-01). Det gäller alla regler, inte bara de märkta `researched`.
- Använd bara **syntetiska testdata**. Personnummer ska vara påhittade med giltig kontrollsiffra och får aldrig vara en verklig persons. Inga verkliga bolag, anställda eller kontonummer.
- Kronor skrivs här med mellanslag som tusentalsavskiljare och komma före öre: `32 000,00 kr`.

## Förutsättningar

**Testbolag.** "Testbolaget Lön AB" (påhittat organisationsnummer med giltig kontrollsiffra, till exempel enligt Luhn), aktiebolag, kalenderår som räkenskapsår, K2. Företaget är registrerat som arbetsgivare (kryssruta under **Företagsinställningar**), har organisationsnummer och telefonnummer ifyllda (krävs för AGI), är momsregistrerat med beskattningsunderlag under 40 miljoner kronor om inget annat sägs. Kontoplanen innehåller kontona 1630, 1680, 1930, 2514, 2710, 2730, 2740, 2890, 7010, 7081, 7082, 7090, 7321, 7322, 7331, 7332, 7388, 7410, 7510, 7519, 7530, 2920, 2940 (**Lön → Kom igång** erbjuder **Lägg till kontona**). Banken är kopplad under **Leverantörer → Betalningar** (krävs för lönefilen).

**Moduler.** **Företagsinställningar → Moduler:** slå på **Lön** (alla fall). Slå på **Avtal** för LON-27 till LON-30 och **Planering** för LON-29. Slå på modulerna först när fallet kräver det.

**Löneinställningar** (**Företagsinställningar → Moduler → Lön → Inställningar**, spara med **Spara löneinställningarna**), om inget annat sägs i fallet: **Utbetalningsdag** 24, **Delmånad räknas på** Kalenderdagar, **Semesteråret börjar månad** 4, **Semesterregel** Sammalöneregeln, **Semesterlöneskuld bokförs** Vid bokslut, **Skatt på semesterersättning med varje lön** Enligt skattetabell, som lönen, **Regionalt stöd** av, **Den som beräknar får inte attestera** på (använd två användare, en som beräknar och en som attesterar) eller av om du är ensam.

**Testpersoner** (**Lön → Anställda → Ny anställd**; skattetabell och kolumn skrivs in som om de stod på ett skattebesked):

| Person | Född | Lön | Skatt | Övrigt |
|---|---|---|---|---|
| Anna Test | 1990-06-15 | Månad, 32 000 kr, 100 % | Bosatt i Sverige, tabell 33, kolumn 1 | 40 timmar per vecka, anställd från 2025-01-01 |
| Bertil Test | 1955-08-01 | Månad, 32 000 kr, 100 % | tabell 33, kolumn 3 | 40 h/vecka |
| Cecilia Test | 2005-03-01 | Månad, 32 000 kr, 100 % | tabell 33, kolumn 1 | 40 h/vecka |
| Dan Test | 1980-08-20 | Timme, 150 kr | tabell 33, kolumn 1 | |
| Eva Test | 1992-02-02 | Timme, 200 kr, med "Betala semesterersättning med varje lön…" ikryssad | tabell 33, kolumn 1 | |

**Period.** Löneår 2026. Lönekörningar skapas under **Lön → Lönekörningar** med **Månad** under **Ny lönekörning** och **Skapa lönekörning**. En månad kan bara ha en lönekörning: kör varje fall i ett eget färskt testbolag, eller i en egen månad, och skriv vilken månad du använde. Fallen anger april 2026 med utbetalningsdag fredag 2026-04-24 om inget annat sägs.

**Arbetsgång i en körning.** Lägg in det fallet kräver under **Timmar och tillägg** (Anställd, Löneart, **Antal timmar, mil eller dagar** eller **eller belopp**, **Lägg till**) och under **Frånvaro** (Anställd, Orsak, Från, Till och med, **Timmar per dag**, **Registrera**). Klicka **Beräkna**, klicka på lönebeskedet för att se raderna, skatten och varningarna, klicka **Attestera** och **Ja, attestera**.

**Adresser (alla under det inloggade företaget).** Lön: `/lon` (Kom igång), `/lon/regler`, `/lon/anstallda`, `/lon/ansokningar`, `/lon/korningar` (lista) och `/lon/korningar/{id}`, `/lon/formansbilar`, `/lon/semesterar`, `/lon/semesterskuld`, `/lon/rapporter`, `/lon/flytt`, `/lon/arbetsgivardeklaration`. Avtal: `/lon/avtal` och `/lon/avtal/versioner`. Planering: `/planering` (Schema), `/planering/attest` (Tid att attestera), `/planering/installningar`.

**Så läser du "Resultat".** Förväntat resultat anger bruttolön (summan av löneraderna), preliminär skatt, nettolön och arbetsgivaravgift (**Brutto**, **Skatt**, **Netto**, **Arbetsgivaravgift** på lönebeskedet).

---

## Del 1. Grundlön, skatt och arbetsgivaravgifter

### LON-01 Regler och skattetabeller kontrolleras innan första lönen

- Förutsättningar: nytt testbolag med Lön på; Anna Test inlagd; ingen regel kontrollerad; skattetabellerna för 2026 inlästa av driftansvarig.
- Steg i appen:
  1. Skapa en lönekörning för april 2026 (**Lön → Lönekörningar**, **Skapa lönekörning**) och klicka **Beräkna**.
  2. Läs rutan **Det här måste lösas innan lönekörningen kan beräknas**. Klicka **Kontrollera reglerna**.
  3. På **Lön → Regler**: välj **Gäller den** 2026-04-24. Notera siffran för regler som saknas eller inte är kontrollerade.
  4. För varje regel: klicka **Kontrollera**, läs **Källan säger**, klicka **Öppna källan och jämför värdet**, jämför kolumnen **Värde** med källan, kryssa i **Jag har jämfört värdet med källan och tar ansvar för att det stämmer för vårt företag** och klicka **Markera som kontrollerad**. Gör detta för alla regler som används i fallen (arbetsgivaravgifter, skatt, basbelopp, förmåner, traktamente, milersättning, sjuklön, semester, AGI).
  5. Klicka **Beräkna** igen.
- Förväntat resultat:
  - Steg 1 stoppar: texten "*N* regler är inte kontrollerade för utbetalningsdagen 2026-04-24: …" och ingen lönebesked skapas (kod: `app/Modules/Payroll/Actions/PayrollRuns.php`, `blockers`). Reglerna som listas är de i `RuleSet::REQUIRED_KEYS` (`app/Modules/Payroll/Domain/Rules/RuleSet.php`).
  - Saknas skattetabellerna för 2026 stoppas körningen med "Skattetabellerna för 2026 är inte inlästa ännu…". Saknas tabell eller kolumn för en person: "Skattetabell 33 kolumn 1 finns inte bland de inlästa tabellerna för 2026."
  - Efter steg 4: körningen beräknas. Kolumnen **Kontrollerad** visar ditt namn och dagens datum på varje regel.
  - Regeln `per_diem.foreign.by_country` (Utlandstraktamente per land) finns inte i `rules.json` ("Missing until researched" i filens `note`) men står i `REQUIRED_KEYS`. Kontrollera om den visas som "saknas" på **Lön → Regler** och om **Inför löneåret** någonsin kan bli helt grön för 2026. Notera utfallet.
  - Konsulten verifierar: varje regels värde mot den källa som visas (alla 2026-värden i `rules.json` är importerade okontrollerade; `source_url` och `source_quote` står i filen).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-02 Månadslön heltid och deltid: skatt, arbetsgivaravgift, netto

- Förutsättningar: LON-01 klar; Anna Test och en deltidsanställd "Folke Test" (född 1990-06-15, tabell 33 kolumn 1, **Sysselsättningsgrad, %** 75, **Lön, kronor** 30 000). Körning april 2026.
- Steg i appen: skapa lönekörning, **Beräkna**, öppna lönebeskedet per person.
- Förväntat resultat:
  - **Anna (heltid):** brutto 32 000,00 kr. Skatt tabell 33 kolumn 1 för 32 000 kr = 6 381,00 kr. Netto 32 000,00 − 6 381,00 = 25 619,00 kr. Arbetsgivaravgift 32 000,00 × 31,42 % = 10 054,40 kr (regel `employer_contribution.full`, 3 142 bp).
  - **Folke (75 %):** 30 000,00 × 75 % = 22 500,00 kr. Skatt tabell 33 kolumn 1 för 22 500 kr = 4 023,00 kr. Netto 22 500,00 − 4 023,00 = 18 477,00 kr. Arbetsgivaravgift 22 500,00 × 31,42 % = 7 069,50 kr.
  - Lönebeskedet visar tabell, kolumn och inkomst som användes. Löneraden heter **Månadslön**.
  - Konsulten verifierar: tabellvärdena 6 381 och 4 023 mot Skatteverkets tabell 33; att 31,42 % är summan 3,55 + 2,00 + 10,21 + 0,30 + 2,64 + 0,10 + 12,62 (regeln `employer_contribution.full`, Skatteverket Belopp och procent 2026).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-03 Delmånad: lön vid anställningens början mitt i månaden

- Förutsättningar: Gudrun Test (månadslön 32 000 kr, tabell 33 kolumn 1, **Anställd från** enligt delfall). Körning av månaden i fråga.
- Steg i appen: lägg upp personen med **Anställd från** enligt tabellen, kör månaden, läs raden **Månadslön, *x* av *y* dagar**. Byt sedan **Delmånad räknas på** till **Arbetsschema** och räkna om delfall a.
- Förväntat resultat (kalenderdagar, `BaseSalary.php`: månadslön × anställda dagar / dagar i månaden, halv upp till öre):
  - a) Start 2026-04-16 (april har 30 dagar, 15 dagar): 32 000,00 × 15 / 30 = 16 000,00 kr. Skatt tabell 33 kolumn 1 för 16 000 kr = 2 419,00 kr. Arbetsgivaravgift 16 000,00 × 31,42 % = 5 027,20 kr.
  - b) Start 2026-03-10 (mars har 31 dagar, 22 dagar): 32 000,00 × 22 / 31 = 22 709,677… = 22 709,68 kr. Skatt för 22 709 kr = 4 071,00 kr. Arbetsgivaravgift 22 709,68 × 31,42 % = 7 135,38 kr.
  - c) Start 2026-02-15 (28 dagar, 14 dagar): 16 000,00 kr (hälften).
  - d) Med **Arbetsschema** och schemat Heltid 40 h mån–fre, start 2026-04-16: arbetsdagar i april 2026 = 22 vardagar − långfredagen 3 april − annandag påsk 6 april = 20; arbetsdagar 16–30 april = 11 (16, 17, 20–24, 27–30). 32 000,00 × 11 / 20 = 17 600,00 kr. Raden heter **Månadslön, 11 av 20 arbetsdagar**.
  - Konsulten verifierar: att dagsproportionering är en godtagbar metod (lagen anger ingen) och att bolagets val i inställningen är avtalat med den anställda.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-04 Arbetsgivaravgift efter ålder och ungdomsnedsättningen

- Förutsättningar: Anna (1990), Bertil (1955), Cecilia (2005) och "Dagmar Test" (född 1937-05-05), "Emil Test" (född 2008-06-01), "Frida Test" (född 2002-06-01), "Gunnar Test" (född 1959-03-03), alla med månadslön 32 000 kr, tabell 33 kolumn 1. Körning april 2026 (betalas 2026-04-24) och för jämförelse mars 2026 (betalas 2026-03-24).
- Steg i appen: kör april; läs **Arbetsgivaravgift** per person; kör mars (eller skapa mars först) och läs Cecilia.
- Förväntat resultat (april 2026, basis 32 000,00 kr):
  - Anna: full avgift 31,42 % = 10 054,40 kr.
  - Bertil (född 1955, `employer_contribution.reduced_by_birthyear`, band 1938–1958: 10,21 %): 32 000,00 × 10,21 % = 3 267,20 kr.
  - Dagmar (född 1937 eller tidigare: 0 %): 0,00 kr.
  - Gunnar (född 1959, utanför bandet 1938–1958 för 2026): full avgift 10 054,40 kr. (För 2027 flyttas gränsen till 1959; se LON-26.)
  - Cecilia (född 2005, april 2026): nedsättning från 2026-04-01, 20,81 % på de första 25 000 kr och full avgift på resten: 25 000,00 × 20,81 % = 5 202,50 kr; 7 000,00 × 31,42 % = 2 199,40 kr; summa 7 401,90 kr.
  - Emil (född 2008) och Frida (född 2002): ingen nedsättning (födda 2003–2007 omfattas 2026): 10 054,40 kr vardera.
  - Cecilia i mars 2026 (före 2026-04-01, `youth_reduction` med `applies: false`): full avgift 10 054,40 kr.
  - Löntak i nedsättningen: Cecilia med månadslön 40 000 kr i april: 25 000,00 × 20,81 % = 5 202,50 kr + 15 000,00 × 31,42 % = 4 713,00 kr = 9 915,50 kr.
  - Konsulten verifierar: 20,81 % och taket 25 000 kr mot Skatteverket (Arbetsgivaravgifter, tillfälligt sänkta för unga 1 april 2026 – 30 september 2027); att nedsättningen gäller "ersättning som betalas ut från och med den 1 april 2026" (regelns källcitat); att födelseårsbanden 2003–2007 motsvarar "vid årets ingång fyllt 18 men inte 23 år" även för den som är född 1 januari (Cecilia-fallet täcker inte det). Samma fråga för 67-årsgränsen: person född 1 januari 1959 fyller 67 på årets första dag; koden bedömer bara födelseår.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-05 Avgiftsfri ersättning under 1 000 kr per år

- Förutsättningar: Hanna Test (född 1990-06-15, timlön 125 kr, tabell 33 kolumn 1). Inga tidigare löner under 2026. Två körningar: mars och april 2026.
- Steg i appen: i mars: **Timmar och tillägg**, Löneart **Timlön**, **Antal timmar, mil eller dagar** 4 (= 500,00 kr). Beräkna och attestera. I april: samma 4 timmar. Läs avgiften och skapa AGI för respektive månad (se LON-33).
- Förväntat resultat (regel `employer_contribution.min_annual_basis`, 100 000 öre = 1 000 kr; kod `EmployerContributions.php`):
  - Mars: årets ersättning 500,00 kr < 1 000 kr: arbetsgivaravgift 0,00 kr. I AGI hamnar 500 kr i ruta 131 (inte 011).
  - April: årets ersättning 500 + 500 = 1 000,00 kr, inte under gränsen: avgift tas på hela 1 000,00 kr = 1 000,00 × 31,42 % = 314,20 kr (även på marsbeloppet). I AGI april: ruta 011 = 1 000 (500 + 500 tidigare), ruta 010 = 500.
  - Gränsfall: mars 499,99 kr och april 500,00 kr (skriv beloppen i **eller belopp** på Timlön; kunde inte verifiera att fältet tillåts för Timlön): summa 999,99 kr är under gränsen, avgift 0 i båda månaderna.
  - Exempel ur manualen: 600 + 600 kr: mars 0, april avgift på 1 200,00 kr = 377,04 kr.
  - Konsulten verifierar: SAL 2 kap. 14 § ("understigit 1 000 kronor") och 24 § fjärde stycket (avgift på hela årets ersättning när gränsen nås); att Skatteverkets exempel för ruta 010/131 stämmer med fältens innehåll.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-06 Regionalt stöd (avdrag på arbetsgivaravgifterna)

- Förutsättningar: Anna (32 000 kr) och Bertil (född 1955, 32 000 kr) i en körning; Ivar Test (född 1990-01-10, månadslön 80 000 kr, tabell 33 kolumn 1) i en egen körning i ett annat testbolag (taket delas annars mellan alla i körningen). Under **Lön → Inställningar** kryssa i **Regionalt stöd (nedsatta arbetsgivaravgifter i stödområdet)** och **Spara löneinställningarna**. Körning april 2026.
- Steg i appen: kör april; läs avgiften per person; skapa AGI och läs ruta 471 och 476 (LON-33).
- Förväntat resultat (regel `employer_contribution.regional_support`: 10 % av underlaget, högst 7 100 kr per månad för hela företaget, aldrig under ålderspensionsavgiften 10,21 %, inte för den som var 67 år eller äldre vid årets början):
  - Anna: avgift 10 054,40 kr; avdrag 10 % × 32 000,00 = 3 200,00 kr (golv 10,21 % × 32 000 = 3 267,20 kr; 10 054,40 − 3 267,20 = 6 787,20 ≥ 3 200,00); avgift efter avdrag 6 854,40 kr. Matchar manualens exempel.
  - Taket: avdragen delar på 7 100,00 kr per månad för hela företaget (ordningen mellan personer styr bara vem som får det). Anna 3 200,00 kr och Ivar i samma körning ger Ivar bara 7 100,00 − 3 200,00 = 3 900,00 kr. Med bara Ivar (80 000,00 kr): avgift 25 136,00 kr; 10 % = 8 000,00 kr > taket: avdrag 7 100,00 kr; avgift 18 036,00 kr.
  - Bertil (född 1955, 67 år eller äldre vid årets början): inget avdrag; avgift 3 267,20 kr (och ingen post i regionalt stöd i förklaringen: `excluded: age`).
  - AGI: ruta 471 = underlaget i hela kronor, ruta 476 = avdraget i hela kronor, och summan av arbetsgivaravgifter (487) är efter avdraget.
  - Dokumentationsavvikelse att notera: `docs/manual/sv/lon/regler-och-skattetabeller.md` rad 60 och `docs/manual/sv/lon/arbetsgivaravgifter-och-pension.md` rad 41 säger att regionalt stöd "dras inte av" i lönen ännu, medan inställningen finns (`app/Modules/Payroll/views/settings-form.blade.php` rad 22), räkningen finns i `EmployerContributions.php` och avsnittet "Regionalt stöd" i samma manual beskriver avdraget. Skriv vilken beskrivning som stämmer med din körning.
  - Konsulten verifierar: lag (2001:1170) 5 § (10 %, 7 100 kr, golv), 2 § (67 år och äldre), vilka verksamheter som är undantagna, och att avdraget bör beräknas på periodens summa för företaget (koden räknar per lönebesked i öre; kodens TODO).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-07 Bonus som engångsbelopp och engångsbelopp utan fast lön

- Förutsättningar: Anna (32 000 kr, tabell 33 kolumn 1) och Dan (timlön, ingen timlön denna månad). Inga engångsbelopp tidigare 2026. Körning april 2026.
- Steg i appen: **Timmar och tillägg**: Anna, Löneart **Bonus**, **eller belopp** 5 000. Dan: Löneart **Bonus**, **eller belopp** 5 000, inga andra rader (kör Dan i en egen körning om bolaget kräver en löneperiod med lön).
- Förväntat resultat (`WithholdingTaxStep.php`, `WithholdingTax::oneOff`, regel `tax.one_off`):
  - Anna: brutto 32 000,00 + 5 000,00 = 37 000,00 kr. Tabellskatt på 32 000 kr: 6 381,00 kr. Engångsbeloppets skatt: förväntad årsinkomst = 32 000,00 × 12 + 5 000,00 = 389 000,00 kr, i kolumn 1 intervallet 192 001–477 600 kr = 26 %. 26 % × 5 000,00 = 1 300,00 kr. Total skatt 6 381,00 + 1 300,00 = 7 681,00 kr. Netto 37 000,00 − 7 681,00 = 29 319,00 kr. Arbetsgivaravgift 37 000,00 × 31,42 % = 11 625,40 kr.
  - Dan utan fast lön: 30 % (`tax.one_off_without_regular_salary`, 3 000 bp) × 5 000,00 = 1 500,00 kr.
  - Konsulten verifierar: att procentsatserna i `tax.one_off` (kolumn 1: 0, 10, 21, 26, 34, 54 % i sex intervall) är de i Skatteverkets "Engångsbelopp" / SKV 433 avsnitt 10; att bonus räknas som engångsbelopp (inställd tax_method ONE_OFF) och att den är semesterlönegrundande (`make_bonus_vacation_basis`, kodens TODO, ärende #77).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-08 Schablonskatter: sidoinkomst, SINK, A-SINK och jämkning

- Förutsättningar: fyra personer i en körning (april 2026): "Sara Test" (Skatt: **Bosatt i Sverige**, **Huvudinkomst eller sidoinkomst**: **Sidoinkomst – 30 procent skatt**), "Sven Test" (**Bosatt utomlands, SINK**, utan svenskt personnummer med födelsedata enligt manualen), "Stina Test" (**Bosatt utomlands, A-SINK**, **Verksamhetens art** ifylld) och Anna med **Jämkning** **Skatteavdrag med en procentsats** 20, **Gäller från** 2026-04-01.
- Steg i appen: lägg in lönerna som belopp i **Timmar och tillägg** (Löneart **Timlön**, **eller belopp**): Sara 8 123,45; Sven 20 001,99; Stina 20 001,99; Anna månadslön 32 000. Beräkna.
- Förväntat resultat (öretal i skatten faller bort, `tax.rounding` = DOWN_TO_KRONA):
  - Sara: 30 % × 8 123,45 = 2 437,035 → skatt 2 437,00 kr.
  - Sven (SINK 22,5 %, `sink.rate` 2 250 bp): 20 001,99 × 22,5 % = 4 500,447… → 4 500,00 kr. För betalning efter 2026-12-31 gäller 20 % (2027-versionen av `sink.rate`): 20 001,99 × 20 % = 4 000,398 → 4 000,00 kr.
  - Stina (A-SINK 15 %, `a_sink.rate`): 20 001,99 × 15 % = 3 000,2985 → 3 000,00 kr. AGI redovisar skatten i ruta 275 och verksamhetens art i ruta 112.
  - Anna med jämkning 20 %: 32 000,00 × 20 % = 6 400,00 kr (i stället för tabellens 6 381,00). Med en bonus på 5 000 kr i samma körning: 37 000,00 × 20 % = 7 400,00 kr (procenten gäller även engångsbeloppet).
  - Konsulten verifierar: SFL 11 kap. 20 § (30 % på sidoinkomst), lag (1991:591) 9 § (A-SINK 15 %), SINK-satsen 22,5 % för 2026 och 20 % från 2027 (lag 1991:586 7 § i lydelse SFS 2025:1356), och att procentjämkning ska följas även för engångsbelopp.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-09 Skatten större än kontantlönen och negativ nettolön

- Förutsättningar: Dan (timlön 150 kr) och Anna. Körning april 2026.
- Steg i appen:
  - a) Dan: Löneart **Timlön**, 10 timmar; Löneart **Bilförmån**, **eller belopp** 15 000. Beräkna.
  - b) Anna: Löneart **Avdrag för förskott**, **eller belopp** 40 000. Beräkna; kör sedan nästa månads körning (maj 2026) utan nya avdrag.
- Förväntat resultat:
  - a) Kontant lön 10 × 150,00 = 1 500,00 kr; skattepliktigt 1 500,00 + 15 000,00 = 16 500,00 kr; tabellskatt tabell 33 kolumn 1 för 16 500 kr = 2 540,00 kr. Skatten får bara dras från kontantlönen (SFL 10 kap. 3 §): **skatt 1 500,00 kr**, netto 0,00 kr. Varningen "Skatten blev 2 540,00 kr men kontantlönen är 1 500,00 kr. Bara 1 500,00 kr dras i skatt…" visas. Arbetsgivaravgift 16 500,00 × 31,42 % = 5 184,30 kr. AGI ruta 001 = 1 500 (det som drogs).
  - b) Netto före förskott 25 619,00 kr (LON-02); förskott 40 000,00 kr → nettolön 0,00 kr och raden **Skuld till företaget från lön** (lönearten FORDRAN_ANSTALLD) på 40 000,00 − 25 619,00 = 14 381,00 kr; varning om negativ nettolön. I maj dras skulden: nettolön 25 619,00 − 14 381,00 = 11 238,00 kr, med raden **Avdrag för skuld från tidigare lön**. Bokföring: se LON-31 (konto 1680).
  - Konsulten verifierar: att den delen av skatten som inte kan dras (1 040,00 kr i a) inte ska bli en fordran på den anställda; att automatisk avräkning mot senare lön (b) är kvittning som är tillåten (lag 1970:215 om arbetsgivares kvittningsrätt, kodens TODO).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

---

## Del 2. Frånvaro, sjuklön och karensavdrag

Alla fall: Anna Test (32 000 kr, 40 h/vecka), körning april 2026, **Orsak** **Sjuk** om inget annat sägs. Timvärdet i koden: 32 000,00 × 12 / 52 / 40 = 184,6153… kr/h (`HourlyValue.php`); veckan = 7 384,615… kr; sjuklön 80 % (`sick_pay.percentage`); karensavdrag 20 % av en veckas sjuklön = 7 384,615 × 80 % × 20 % = 1 181,54 kr.

### LON-10 Sjukdagar och karensavdrag, korta fall

- Förutsättningar: som ovan; ett fall per körning eller per testbolag.
- Steg i appen: **Frånvaro**: Anställd Anna, Orsak **Sjuk**, Från/Till och med och **Timmar per dag** enligt fallet; **Registrera**; **Beräkna**.
- Förväntat resultat:
  - a) Sjuk tisdag 2026-04-07 och onsdag 2026-04-08, 8 h/dag (16 h): **Sjukavdrag** 16 × 184,6153 = 2 953,846 → −2 953,85 kr; **Sjuklön 80 %** 2 363,08 kr; **Karensavdrag** −1 181,54 kr. Brutto 32 000,00 − 2 953,85 + 2 363,08 − 1 181,54 = 30 227,69 kr. Skatt för 30 227 kr = 5 979,00 kr. Netto 24 248,69 kr. Arbetsgivaravgift 30 227,69 × 31,42 % = 9 497,54 kr.
  - b) Sjuk bara 1 timme (onsdag 2026-04-08, **Timmar per dag** 1): Sjukavdrag −184,62 kr; sjuklön 80 % = 147,69 kr; karensavdraget 1 181,54 kr får inte överstiga sjuklönen i perioden: **−147,69 kr**, resten 1 033,85 kr dras inte i april eftersom sjukperioden är slut (varning "…Resten, 1 033,85 kr, dras inte eftersom sjukperioden är slut."). Brutto 32 000,00 − 184,62 + 147,69 − 147,69 = 31 815,38 kr.
  - b2) Samma timme men onsdag 2026-04-29 (sjukperioden kan fortsätta in i maj): varningen blir "…Resten, 1 033,85 kr, dras från sjuklönen om sjukperioden fortsätter i nästa lönekörning."
  - c) **Vård av barn** 8 timmar (2026-04-14): **Avdrag för frånvaro** −1 476,92 kr (8 × 184,615), inget karensavdrag, ingen sjuklön. Brutto 30 523,08 kr, skatt för 30 523 kr = 6 029,00 kr.
  - Konsulten verifierar: sjuklönelagen (1991:1047) 6 § (80 %, karensavdrag 20 % av en genomsnittlig veckas sjuklön) och att månadslön × 12 / 52 är en godtagbar omräkning till veckolön (kodens TODO i `HourlyValue.php`); att karensavdraget aldrig får bli större än periodens sjuklön, och att resten får tas senare i perioden (prop. 2017/18:96 s. 68–69, kodens TODO i `SickPay.php`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-11 Sjukperiod längre än 14 dagar

- Förutsättningar: som ovan.
- Steg i appen: registrera sjukfrånvaro **Från** 2026-04-13 **Till och med** 2026-04-28, **Timmar per dag** 8. **Beräkna**.
- Förväntat resultat (arbetsgivarperioden = kalenderdagarna 1–14, 13–26 april; dag 15 är 27 april; `sick_pay.employer_period_days` = 14):
  - Arbetsdagar med frånvaro dag 1–14: 13–17 och 20–24 april = 10 dagar = 80 h. Dag 15–16 (27–28 april) = 16 h utan sjuklön.
  - **Sjukavdrag** för alla 96 timmar: 96 × 184,6153 = 17 723,08 kr → −17 723,08 kr. **Sjuklön 80 %** på 80 h: 80 × 184,6153 × 80 % = 11 815,38 kr. **Karensavdrag** −1 181,54 kr. Brutto 32 000,00 − 17 723,08 + 11 815,38 − 1 181,54 = 24 910,76 kr. Skatt för 24 910 kr = 4 622,00 kr. Arbetsgivaravgift 24 910,76 × 31,42 % = 7 826,96 kr.
  - Varning "Sjukperioden är längre än arbetsgivarperioden. Anmäl sjukfallet till Försäkringskassan."
  - Konsulten verifierar: att sjuklönen bara betalas för de 14 första kalenderdagarna (7 § sjuklönelagen) och att dagarna räknas som kalenderdagar även när helg inte ger frånvarotimmar.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-12 Återinsjuknande inom fem dagar och högkostnadsskyddet för karensavdrag

- Förutsättningar: som ovan. Fall b kräver tio tidigare sjukperioder med karensavdrag de senaste tolv månaderna i attesterade körningar (eller inläst historik); skriv hur du skapade den.
- Steg i appen: registrera frånvaro enligt fallet. **Beräkna**.
- Förväntat resultat (`sick_pay.reinfection_window_days` = 5, `sick_pay.karensavdrag_percent_of_weekly.max_occasions_per_12_months` = 10):
  - a1) Sjuk 1–3 april (24 h) och 7 april (8 h): fyra dagar mellan 3 och 7 april, en sjukperiod, **ett** karensavdrag. 32 h × 184,6153 = 5 907,69 kr avdrag; sjuklön 80 % = 4 726,15 kr; karens −1 181,54 kr. Brutto 32 000,00 − 5 907,69 + 4 726,15 − 1 181,54 = 29 636,92 kr. Skatt för 29 636 kr = 5 828,00 kr. Arbetsgivaravgift 9 311,92 kr.
  - a2) Sjuk 1–3 april (24 h) och 9 april (8 h): sex dagar emellan, två perioder, **två** karensavdrag. Period 1: sjuklön 24 × 184,6153 × 80 % = 3 544,62 kr, karens 1 181,54 kr. Period 2: sjuklön 1 181,54 kr, karens 1 181,54 kr (hela dagens sjuklön försvinner). Brutto 32 000,00 − 5 907,69 + 4 726,15 − 2 363,08 = 28 455,38 kr. Skatt för 28 455 kr = 5 526,00 kr.
  - b) Med tio karensavdrag de senaste tolv månaderna räknat från sjukperiodens första dag: inget nytt karensavdrag; varning "Inget karensavdrag för sjukperioden från …: den anställda har redan haft karensavdrag 10 gånger de senaste tolv månaderna." Sjuklön 80 % betalas som vanligt.
  - Konsulten verifierar: att återinsjuknande inom fem kalenderdagar är samma sjukperiod, och att tio tillfällen är rätt högkostnadsskydd (sjuklönelagen 6 § tredje stycket; regeln `sick_pay.karensavdrag_percent_of_weekly`). Observera att NONOX läser karens från attesterade körningar: attestera förra månaden innan nästa räknas.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

---

## Del 3. Semester

### LON-13 Semesterdagar: sammalöneregeln och semesterersättning med varje lön

- Förutsättningar: Anna (Sammalöneregeln, 25 semesterdagar, anställd sedan 2025-01-01, saldo ifyllt via **Lön → Flytt av lönedata** om körningen inte har historik) och Eva Test (timlön 200 kr, **Betala semesterersättning med varje lön i stället för betalda semesterdagar**). Körning april 2026.
- Steg i appen: a) **Frånvaro**: Anna, Orsak **Semester**, Från 2026-04-13, Till och med 2026-04-17, **Timmar per dag** 8. b) Eva: Löneart **Timlön**, 160 timmar. **Beräkna**. Byt för b) **Skatt på semesterersättning med varje lön** till **Som engångsbelopp** och beräkna om.
- Förväntat resultat:
  - a) Fem betalda semesterdagar. Månadslönen fortsätter. **Semestertillägg**: 0,43 % × 32 000,00 = 137,60 kr per dag × 5 = 688,00 kr (regel `vacation.supplement_sammaloneregeln`, 43 bp). Brutto 32 688,00 kr. Skatt för 32 688 kr = 6 582,00 kr. Netto 26 106,00 kr. Arbetsgivaravgift 32 688,00 × 31,42 % = 10 270,57 kr. Saldot minskar med 5 dagar.
  - b) Timlön 160 × 200,00 = 32 000,00 kr; **Semesterersättning 12 %** (`vacation.percent_rule`) = 3 840,00 kr (semesterlönegrundande lön 32 000,00 kr). Brutto 35 840,00 kr. Enligt tabell: skatt för 35 840 kr (tabell 33 kolumn 1) = 7 386,00 kr; arbetsgivaravgift 35 840,00 × 31,42 % = 11 260,93 kr. Som engångsbelopp: tabellskatt 6 381,00 kr + 26 % × 3 840,00 (förväntad årsinkomst 32 000 × 12 + 3 840 = 387 840 kr, intervall 192 001–477 600) = 998,40 → 998,00 kr, summa 7 379,00 kr.
  - Konsulten verifierar: semesterlagen 16 a § (0,43 % per dag), 16 b § och 5 § (12 %); skatteregeln för semesterersättning (tabell eller engångsbelopp; Skatteverket kallar den engångsbelopp, kodens TODO i `Vacation.php`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-14 Semesterår, semesterlöneskuld och slutlön (ingen siffra uträknad)

- Förutsättningar: Anna med intjänat saldo; semesteråret slut. Företagsinställning **Semesterlöneskuld bokförs** Varje månad.
- Steg i appen: **Lön → Semesterår**: granska **Betalda dagar**, **Uttagna**, **Kvar**; skriv under **Spara** (högst så många som visas, dagar över 20) och klicka **Stäng semesteråret**. **Lön → Semesterskuld**: läs **Dagar kvar**, **Per dag**, **Skuld**, **Sociala avgifter**. Avsluta anställningen (**Lön → Anställda → Ändra → Anställningens slut**, **Sista anställningsdag** 2026-04-30) och beräkna slutlön.
- Förväntat resultat: kunde inte beräkna med säkerhet. Skulden räknas ur dagsvärdet i **Dagsvärde i semesterlöneskulden, % av månadslönen** plus semestertillägget (`VacationLedger::liability`, Vacation.php) och slutlönen ur samma värde (28 § semesterlagen); beloppen är bara kontrollerbara mot den egna rapporten. Kontrollera: (1) att antalet dagar som får sparas är max (kvarvarande dagar − 20); (2) att sparade dagar ska tas inom fem år (`vacation.save_years`); (3) att utbetalningarna kommer med som engångsbelopp i nästa körning och aldrig två gånger; (4) att verifikationen får raderna 7090/2920 och 7519/2940 med skillnaden mot förra bokningen; (5) att slutlönen innehåller **Semesterersättning vid anställningens slut** för kvarvarande dagar och intjänad semester.
  - Konsulten verifierar: värdet per dag utan kollektivavtal (29 § semesterlagen, kodens TODO i `VacationLedger.php` rad 110), sociala avgifter på skulden, och 26 a § om rörlig lön.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

---

## Del 4. Förmåner, ersättningar och pension

### LON-15 Kostförmån, milersättning och traktamente

- Förutsättningar: Anna, körning april 2026. Reglerna `benefit.meal.*`, `mileage.own_car_tax_free_per_mil`, `per_diem.domestic.*` kontrollerade.
- Steg i appen: **Timmar och tillägg**, Löneart och **Antal timmar, mil eller dagar** / **eller belopp** enligt fallet.
- Förväntat resultat:
  - a) **Kostförmån, lunch eller middag** × 10: 10 × 124,00 = 1 240,00 kr förmånsvärde (regel `benefit.meal.lunch_or_dinner`, 12 400 öre), skattepliktig men inte utbetald; arbetsgivaravgift 1 240,00 × 31,42 % = 389,61 kr. Lägger du också in **Egen betalning för förmån** 1 240 kr: förmånsvärdet blir 0,00 kr (raden **Förmånsvärdet minskat med egen betalning**) och 1 240,00 kr dras från nettolönen; konto 7388 krediteras 1 240,00 kr. Kostförmån, hel dag × 5: 5 × 310,00 = 1 550,00 kr. Kostförmån, frukost per styck 62,00 kr.
  - b) **Milersättning, egen bil**, 40 mil, **eller belopp** 1 500: skattefritt 40 × 25,00 = 1 000,00 kr (regel 2 500 öre per mil, konto 7331) och **Milersättning, skattepliktig del** 500,00 kr (konto 7332), med arbetsgivaravgift 500,00 × 31,42 % = 157,10 kr. Bara 40 mil utan belopp: 1 000,00 kr skattefritt, ingen skattepliktig del. AGI: ruta 050 = 1.
  - c) **Traktamente, hel dag** × 3 = 900,00 kr, **Nattraktamente** × 2 = 300,00 kr, **Traktamente, halv dag** × 1 = 150,00 kr, alla skattefria (konto 7321). Mer än 300 kr per dag betalat: överskottet blir **Traktamente, skattepliktig del**. AGI: ruta 051 = 1. Inmatning av ett belopp utan antal dagar stoppar körningen med "behöver antal mil eller dagar".
  - Konsulten verifierar: 124 kr / 310 kr / 62 kr för 2026 (Skatteverket Belopp och procent 2026), 25 kr per mil och 300/150 kr traktamente 2026; att villkoren för skattefritt traktamente (övernattning, avstånd, minskning efter tre månader) är användarens ansvar (kodens TODO).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-16 Bilförmån (Lön → Förmånsbilar)

- Förutsättningar: reglerna `benefit.car.formula`, `benefit.car.scope` och `prisbasbelopp` (59 200 kr, 2026) kontrollerade; Anna.
- Steg i appen: **Lön → Förmånsbilar**, **Registrera förmånsbil**: Anställd, Märke och modell, Registreringsnummer (påhittat), Årsmodell, **Skattepliktig i Sverige från**, Drivmedel, **Nybilspris (kr)**, **Extrautrustning (kr)**, **Fordonsskatt per år (kr)**, **Egen betalning per månad (kr)**, **Första dag med bilen**, rutan för minst 3 000 mil, **Spara**. Läs **Förmånsvärde per år** och **Per månad** och **Visa beräkning**. Kör april.
- Förväntat resultat (IL 61 kap. 5 §: 0,29 prisbasbelopp + ränteandel + 13 % av nybilspriset + fordonsskatt; statslåneränta 2,55 %, 70 % × 2,55 % + 1 % = 2,785 %; varje del och månadsvärdet avrundas nedåt till hel krona):
  - a) Bensin, modellår 2024, första skattepliktig 2024-03-01, nybilspris 400 000 kr, fordonsskatt 1 800 kr: 17 168 + 11 140 (400 000 × 2,785 %) + 52 000 + 1 800 = **82 108 kr/år**; **6 842 kr/månad** (82 108 / 12 = 6 842,33 → 6 842). Raden **Bilförmån** 6 842,00 kr beskattas och ger arbetsgivaravgift 6 842,00 × 31,42 % = 2 149,76 kr; betalas inte ut. AGI ruta 013 = 6 842. Med **Egen betalning per månad** 1 000 kr: skattepliktigt 5 842,00 kr; arbetsgivaravgift 5 842,00 × 31,42 % = 1 835,56 kr; 1 000,00 kr dras från nettolönen och krediteras 7388.
  - b) Skatteverkets exempel: nybilspris 322 000 kr, fordonsskatt 4 050 kr: 17 168 + 8 967 + 41 860 + 4 050 = **72 045 kr/år**; **6 003 kr/månad**; 12 × 6 003 = 72 036 kr (rest 9 kr läggs inte på).
  - c) Elbil, nybilspris 500 000 kr, fordonsskatt 360 kr, första skattepliktig 2023-01-15: nedsättning 350 000 kr men högst 50 % × 500 000 = 250 000 kr; underlag 250 000 kr: 17 168 + 6 962 + 32 500 + 360 = **56 990 kr/år**; **4 749 kr/månad**.
  - d) Laddhybrid, nybilspris 450 000 kr, fordonsskatt 500 kr: nedsättning 140 000 kr, underlag 310 000 kr: 17 168 + 8 633 + 40 300 + 500 = **66 601 kr/år**; **5 550 kr/månad**. Gasbil, samma pris: nedsättning 100 000 kr, underlag 350 000 kr: 17 168 + 9 747 + 45 500 + 500 = **72 915 kr/år**; **6 076 kr/månad**.
  - e) Äldre modell: modellår 2019 (2026 − 2019 = 7 ≥ 6 år), nybilspris 180 000 kr < 4 prisbasbelopp (236 800 kr): underlag 236 800 kr; fordonsskatt 1 000 kr: 17 168 + 6 594 + 30 784 + 1 000 = **55 546 kr/år**; **4 628 kr/månad**.
  - f) Minst 3 000 mil i tjänsten (bil a): 75 % × 82 108 = **61 581 kr/år**; **5 131 kr/månad**.
  - g) Del av året: bil a från 2026-09-15: september–december räknas som hela månader med bilen (4 × 6 842 = 27 368 kr); månader utan bilen räknas inte.
  - h) Bil som blev skattepliktig före 2021-07-01, eller en elbil före 2022-07-01: lönekörningen stoppas med texten "Bilen blev skattepliktig före 1 juli 2021. För den gäller äldre regler som NONOX inte räknar…". Räkna då värdet själv och lägg in det med lönearten **Bilförmån**.
  - Konsulten verifierar: IL 61 kap. 5, 7, 8, 8 a, 9 §§ och övergångsbestämmelserna SFS 2021:479 och 2022:479; statslåneräntan 2,55 % (30 november 2025); avrundningen (kodens TODO i `CarBenefitCalculator.php`); att 12 × månadsvärdet kan understiga årsvärdet med några kronor; att förmånsvärdet inte bokförs som kostnad (verklig kostnad bokförs vid köpet) och att bilen byts mitt i månaden inte stöds.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-17 Tjänstepension och särskild löneskatt

- Förutsättningar: **Lön → Anställda → Pensionsplaner → Ny pensionsplan**: Namn "SAF-LO test", **Mall** **Som SAF-LO (arbetare)**, **Procent av lönen upp till taket** 4,5, **Procent av lönen över taket** 30, **Spara planen**; planen vald under **Tjänstepension** på Anna. Reglerna `inkomstbasbelopp` (83 400 kr) och `special_payroll_tax.pension` (24,26 %) kontrollerade.
- Steg i appen: kör april med olika månadslöner (ändra lönen mellan körningar eller använd flera personer).
- Förväntat resultat (taket är 7,5 × 83 400 / 12 = 52 125,00 kr per månad):
  - a) Lön 32 000,00 kr: premie 32 000,00 × 4,5 % = 1 440,00 kr; särskild löneskatt 24,26 % × 1 440,00 = 349,34 kr.
  - b) Lön 60 000,00 kr: 52 125,00 × 4,5 % = 2 345,625 + (60 000,00 − 52 125,00) × 30 % = 2 362,50 → premie **4 708,13 kr** (4 708,125 avrundat halv upp); särskild löneskatt 24,26 % × 4 708,13 = **1 142,19 kr**.
  - c) Lön exakt 52 125,00 kr: 2 345,63 kr (hela beloppet under taket); 52 126,00 kr: 2 345,93 kr.
  - d) Avtalsplan ITP 1 (via Avtal) med tak 30 inkomstbasbelopp = 208 500,00 kr/månad, lön 250 000,00 kr: 2 345,625 + (208 500 − 52 125) × 30 % = 46 912,50 → premie **49 258,13 kr**; särskild löneskatt **11 950,02 kr**. SAF-LO utan tak: 61 708,13 kr och 14 970,39 kr.
  - e) Åldersgränser (SAF-LO via avtal: från månaden personen fyller 22 till månaden före 65; ITP 1 från 25): född 2004-04-30 får premie i april 2026 (22 år den 30 april), född 2004-05-01 inte.
  - Premien och löneskatten syns på lönebeskedet under **Arbetsgivaren betalar också** men ändrar inte brutto eller netto. Bokföring: 7410/2740 och 7530/2514 (se LON-31).
  - Konsulten verifierar: avtalens satser 4,5 % / 30 % (SAF-LO, ITP 1) och 7,5 inkomstbasbelopp mot Collectums och Avtalats premietabeller 2026; särskild löneskatt 24,26 %; ITP 1 från 25 år (avtalat.se) och att pensionsgrundande lön är den kontanta (kodens TODO i `Pension.php`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-18 Lönerevision med retroaktiv lön

- Förutsättningar: Anna med lön 32 000 kr; januari, februari och mars 2026 redan utbetalda och attesterade (egna körningar eller inläst "hittills i år" via **Lön → Flytt av lönedata**). April-körningen öppen.
- Steg i appen: **Lön → Anställda → Ändra** på Anna, kortet **Lönerevision**: **Ny lön från** 2026-01-01, **Månadslön** 33 500, **Spara ny lön**. Kör april.
- Förväntat resultat (`app/Modules/Payroll/Support/Run/ContextBuilder.php`; retroaktiv lön är pay code RETROLON och beskattas som engångsbelopp):
  - Rader: **Månadslön** 33 500,00 kr och tre rader **Retroaktiv lön för** januari, februari, mars med 1 500,00 kr var, totalt 4 500,00 kr. Brutto 38 000,00 kr.
  - Skatt: tabellskatt för 33 500 kr = 6 783,00 kr; engångsbelopp: förväntad årsinkomst 33 500,00 × 12 + 4 500,00 = 406 500,00 kr, intervall 192 001–477 600 = 26 %: 26 % × 4 500,00 = 1 170,00 kr; total skatt 7 953,00 kr. Netto 38 000,00 − 7 953,00 = 30 047,00 kr. Arbetsgivaravgift 38 000,00 × 31,42 % = 11 939,60 kr.
  - Samma skillnad betalas inte två gånger: kör om samma månad efter att körningen återöppnats och kontrollera att summan är 4 500,00 kr.
  - Konsulten verifierar: att retroaktiv lön beskattas som engångsbelopp (SKV 433 / Skatteverkets "Engångsbelopp") och att frånvaroavdrag och semesterlön i tidigare månader inte räknas om (kodens TODO, `ContextBuilder.php`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______


---

## Del 5. DRIVMEDELSFÖRMÅN: regeln `benefit.fuel.factor` (faktor 1,2) — mänskligt beslut 17

> **Den här delen är särskilt viktig.** Beslut 17 i `docs/status/manskliga-beslut.md` säger att regeln `benefit.fuel.factor` lagts till med status `researched`, att en lönekörning med drivmedelsförmån stoppas tills någon har kontrollerat regeln under **Lön → Regler**, och att regeln ska kontrolleras av lönekonsulten enligt den här checklistan innan rättelsen driftsätts. Ingen kund använder drivmedelsförmån i dag. Konsultens kontroll av LON-19 är själva beslutsunderlaget; signera inte Lön-modulen utan att ha tagit ställning till den.

### Vad regeln gör och hur koden använder den

- **Regeln** (`app/Modules/Payroll/database/data/2026/rules.json`, nyckel `benefit.fuel.factor`, `valid_from` 2026-01-01, `valid_to` öppet, värde `{"tax_factor_bp": 12000}` = 1,2, `status: researched`, `source_read_on` 2026-11-06). I 2027-filen står den under `continued` (gäller även 2027). I **Lön → Regler** heter den **Drivmedelsförmån, uppräkning** och värdet visas som "Skatteavdrag på marknadsvärdet × 1,2, arbetsgivaravgifter på marknadsvärdet" (`app/Modules/Payroll/Support/RulePresenter.php`).
- **Inmatning:** den som kör lönen lägger in lönearten **Drivmedelsförmån** (`DRIVMEDELSFORMAN`) med **eller belopp** = förmånsvärdet, alltså marknadsvärdet för drivmedlet vid privat körning, inklusive moms, gånger 1,2 (manualen `formaner-traktamente-och-milersattning.md`). NONOX räknar inte själv ut marknadsvärdet ur liter och pris.
- **Beräkning** (`app/Modules/Payroll/Domain/Benefits/FuelBenefit.php`, `app/Modules/Payroll/Domain/Engine/Steps/Additions.php` rad 47): hela det inskrivna värdet är skattepliktigt förmånsvärde (underlag för skatteavdraget) och går oavkortat i AGI-ruta 018. **Underlaget för arbetsgivaravgift** är marknadsvärdet = inskrivet värde / 1,2, avrundat halv upp till öre. Faktorn läses ur regeln; är den inte kontrollerad för utbetalningsdagen stoppas körningen (`PayrollBlockedException`).
- **Den anställdas egen betalning** (`DRIVMEDEL_BETALNING`, **Egen betalning för drivmedel vid bilförmån**, dras från nettolönen) sänker det skattepliktiga värdet (högst till noll) och avgiftsunderlaget (högst marknadsvärdet). I AGI står hela värdet kvar i ruta 018 och betalningen i ruta 098 (`app/Modules/Payroll/Support/Agi/AgiDeclaration.php`, `AgiXml.php`: `DrivmVidBilformanUlagAG` = 018, `BetForDrivmVidBilformanUlagAG` = 098, `SkatteplBilformanUlagAG` = 013).
- **Regeln ligger inte i `RuleSet::REQUIRED_KEYS`** (`app/Modules/Payroll/Domain/Rules/RuleSet.php`): en lönekörning utan drivmedelsförmån påverkas inte av om regeln är kontrollerad.

### Källor som konsulten ska kontrollera regeln mot

| Nr | Källa | Vad som ska stämma |
|---|---|---|
| K1 | Skatteverket, **Drivmedelsförmån** (https://www.skatteverket.se/foretag/arbetsgivare/lonochersattning/formaner/bilforman/drivmedelsforman.4.3016b5d91791bf546791a89.html) | Skatteavdraget räknas på marknadsvärdet × 1,2; "Som underlag för arbetsgivaravgifter använder du däremot marknadsvärdet utan att multiplicera det med 1,2" (citeras i `FuelBenefit.php`); ruta 018 och 098. Kontrollera att sidan fortfarande säger så och att faktorn är 1,2 för 2026. |
| K2 | **Inkomstskattelagen (1999:1229) 61 kap. 10 och 11 §§** (https://data.riksdagen.se/dokument/sfs-1999-1229.text) | 10 §: drivmedel värderas till marknadsvärdet multiplicerat med 1,2. 11 §: förmånsvärdet minskas med vad den anställda betalat; ringa omfattning ger noll. Kontrollera lydelsen (ändrad t.o.m. SFS 2026:1393 enligt regelns källa) och att bestämmelsen förutsätter att den anställda har förmån av bil. |
| K3 | **Socialavgiftslagen (2000:980) 2 kap. 10 a § punkt 2** (https://data.riksdagen.se/dokument/sfs-2000-980.text) | Underlaget för arbetsgivaravgifter för drivmedelsförmån är marknadsvärdet utan uppräkning. Kontrollera även att den anställdas betalning får dras från avgiftsunderlaget upp till marknadsvärdet (kodens TODO i `FuelBenefit.php`). |
| K4 | Skatteverket, **Arbetsgivardeklaration, fältbeskrivning** (https://www.skatteverket.se/agbeskrivning), ruta 018 och 098 | Ruta 018 ska innehålla det uppräknade förmånsvärdet; ruta 098 den anställdas betalning; ruta 013 ska fyllas i (även 0) när 018 fylls i (`AgiXml.php`: ruta 013 skrivs alltid ut när 018 > 0). |
| K5 | `docs/adr/ADR-061-car-benefit.md` | Hur beslutet bygger på K1–K4. |

Frågor som konsulten ska besvara efter att ha läst K1–K4 (skriv svaren under LON-19):
1. Är faktorn 1,2 korrekt för 2026, och gäller den oförändrad från 2027-01-01 (regelns anteckning: "ändringar i kraft 2027-01-01 efter budgetpropositionen" är inte kontrollerade)?
2. Är marknadsvärdet "inklusive moms"? (manualen säger det.) Räknas NONOX:s tillbakaräkning (värde / 1,2) rätt när användaren skriver in värdet uppräknat?
3. Ska en egen betalning minska avgiftsunderlaget, och i så fall högst med marknadsvärdet?
4. Ska 018 vara hela det uppräknade värdet och 098 betalningen, och ska 013 stå med 0?
5. Får drivmedelsförmån skrivas in utan att den anställda har bilförmån den månaden (se LON-25 b)?

### LON-19 Kontrollera regeln `benefit.fuel.factor` och stoppet utan kontroll

- Förutsättningar: nytt testbolag, Lön på, alla andra regler för 2026 kontrollerade (LON-01), **Drivmedelsförmån, uppräkning** **inte** kontrollerad. Anna Test och Dan Test inlagda.
- Steg i appen:
  1. Öppna **Lön → Regler**, välj **Gäller den** 2026-04-24, leta upp raden **Drivmedelsförmån, uppräkning**. Läs värdet, källan och **Källan säger**.
  2. Skapa lönekörningen för april 2026 (Anna har bara sin månadslön). Lägg under **Timmar och tillägg** till **Anställd** Dan, **Löneart** **Drivmedelsförmån**, **eller belopp** 1 200. Klicka **Beräkna**.
  3. Ta bort Dans rad (**×**) och klicka **Beräkna**: körningen ska gå igenom för Anna.
  4. Lägg tillbaka Dans rad. Gå till **Lön → Regler**, klicka **Kontrollera** på **Drivmedelsförmån, uppräkning**, jämför med K1–K4, kryssa i **Jag har jämfört värdet med källan och tar ansvar för att det stämmer för vårt företag** och klicka **Markera som kontrollerad** (bara om du kan stå för värdet). Beräkna igen.
- Förväntat resultat:
  - Steg 1: raden visar **Ej kontrollerad**, värdet "Skatteavdrag på marknadsvärdet × 1,2, arbetsgivaravgifter på marknadsvärdet" och faktorn 1,2 (12 000 bp). Källan är IL 61 kap. 10 § och SAL 2 kap. 10 a § punkt 2.
  - Steg 2: körningen stoppas för hela körningen ("alla eller ingen") med texten "Dan Test: Drivmedelsförmånen kan inte räknas: regeln Drivmedelsförmån, uppräkning är inte kontrollerad för utbetalningsdagen. Öppna Lön → Regler, jämför värdet med källan och markera det som kontrollerat." Ingen lönebesked skapas.
  - Steg 3: Anna räknas (brutto 32 000,00 kr, skatt 6 381,00 kr, avgift 10 054,40 kr). Regeln påverkar alltså bara körningar med drivmedelsförmån.
  - Steg 4: efter kontrollen räknas körningen. Dans lönebesked innehåller **Drivmedelsförmån** 1 200,00 kr. Kolumnen **Kontrollerad** visar konsultens namn och datum.
  - Konsulten verifierar: svara på frågorna 1–5 ovan med hänvisning till K1–K4. Om värdet eller någon källa inte stämmer: markera inte regeln som kontrollerad, skriv avvikelsen i tabellen "Avvikelser" och i kommentaren.
  - Konsulten verifierar också: att `status: researched` i `rules.json` bara är en anteckning i datafilen (koden läser den inte) och att regelns enda spärr är att den inte är kontrollerad.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar (inklusive svar på fråga 1–5): ______

### LON-20 Grundfall: förmånsvärde 1 200 kr, marknadsvärde 1 000 kr

- Förutsättningar: LON-19 klar (regeln kontrollerad). Anna Test, 32 000 kr, tabell 33 kolumn 1, ingen förmånsbil. Körning april 2026.
- Steg i appen: **Timmar och tillägg**: Anställd Anna, Löneart **Drivmedelsförmån**, **eller belopp** 1 200, **Lägg till**. **Beräkna**. Öppna lönebeskedet och läs raden och förklaringen. Skapa AGI för april (LON-33) och hämta filen.
- Förväntat resultat:
  - Marknadsvärde = 1 200,00 / 1,2 = **1 000,00 kr**. Förmånsvärdet 1 200,00 kr betalas inte ut.
  - **Skattepliktigt:** 32 000,00 + 1 200,00 = 33 200,00 kr. Tabellskatt tabell 33 kolumn 1 för 33 200 kr = **6 682,00 kr**. Kontantlön 32 000,00 kr; netto 32 000,00 − 6 682,00 = **25 318,00 kr**.
  - **Avgiftsunderlag:** 32 000,00 + 1 000,00 = 33 000,00 kr; arbetsgivaravgift 33 000,00 × 31,42 % = **10 368,60 kr**. (Räknades avgiften fel på det uppräknade värdet blev den 33 200,00 × 31,42 % = 10 431,44 kr; skillnaden 62,84 kr är felet.) Förmånens del av avgiften = 1 000,00 × 31,42 % = 314,20 kr.
  - Lönebeskedet visar **Brutto** 33 200,00 kr och raden **Drivmedelsförmån** 1 200,00 kr (förklaring: `contribution_basis_ore` = 100 000 öre).
  - **AGI (april):** ruta 011 = 32 000; ruta 013 = 0 (skrivs alltid ut när 018 finns); ruta 018 = 1 200; ruta 001 = 6 682; huvuduppgift ruta 487 = 10 368 (10 368,60 med öretalet borttaget) och ruta 497 = 6 682.
  - **Bokföring (serie L):** förmånsvärdet bokförs inte som kostnad. Debet 7010 32 000,00; debet 7510 10 368,60; kredit 2710 6 682,00; kredit 2730 10 368,60; kredit 1930 25 318,00 (debet 42 368,60 = kredit 42 368,60).
  - Konsulten verifierar: K1–K4; att skatten räknas på 33 200 kr medan avgiften räknas på 33 000 kr; att förmånsvärdet inte ska bokföras som kostnad i lönen (kostnaden bokfördes när bränslet köptes).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-21 Egen betalning för drivmedlet, 300 kr

- Förutsättningar: som LON-20.
- Steg i appen: lägg i LON-20:s körning också Löneart **Egen betalning för drivmedel vid bilförmån**, **eller belopp** 300, **Lägg till**. **Beräkna**. Skapa AGI och hämta filen.
- Förväntat resultat (exempel ur manualen: förmån 1 200, betalning 300 → skattepliktigt 900, avgiftsunderlag 700 för drivmedelsdelen):
  - Raden **Drivmedelsförmånen minskad med egen betalning** −300,00 kr. Skattepliktigt förmånsvärde 1 200,00 − 300,00 = 900,00 kr.
  - Skattepliktigt totalt 32 000,00 + 900,00 = 32 900,00 kr; tabellskatt för 32 900 kr = **6 632,00 kr**.
  - Avgiftsunderlag 32 000,00 + 1 000,00 − 300,00 = 32 700,00 kr (drivmedelsdelen 700,00 kr); arbetsgivaravgift 32 700,00 × 31,42 % = **10 274,34 kr**.
  - Netto: kontant 32 000,00 − skatt 6 632,00 − egen betalning 300,00 = **25 068,00 kr**.
  - **AGI:** ruta 018 = **1 200** (hela värdet), ruta 098 = **300**, ruta 013 = 0, ruta 001 = 6 632, ruta 011 = 32 000; ruta 487 = 10 274, ruta 497 = 6 632.
  - **Bokföring:** debet 7010 32 000,00; debet 7510 10 274,34; kredit 2710 6 632,00; kredit 2730 10 274,34; kredit 7388 300,00; kredit 1930 25 068,00 (debet 42 274,34 = kredit 42 274,34).
  - Konsulten verifierar: K2 (61 kap. 11 §), K3 (avgiftsunderlag minskas med betalningen) och K4 (018 oförändrad, 098 betalningen).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-22 Egen betalning större än förmånsvärdet, och betalning utan förmån

- Förutsättningar: som LON-20.
- Steg i appen: a) drivmedelsförmån 1 200 kr och egen betalning 1 500 kr. b) bara egen betalning 300 kr, ingen drivmedelsförmån. **Beräkna**.
- Förväntat resultat:
  - a) Minskningen begränsas till förmånsvärdet: −1 200,00 kr; skattepliktigt förmånsvärde 0,00 kr; skatt tabell 33 för 32 000 kr = 6 381,00 kr. Avgiftsunderlag 32 000,00 + 1 000,00 − 1 000,00 (högst marknadsvärdet) = 32 000,00 kr; avgift **10 054,40 kr**. Nettolönen minskar med hela betalningen: 32 000,00 − 6 381,00 − 1 500,00 = **24 119,00 kr**. AGI: ruta 018 = 1 200 och ruta 098 = **1 200** (inte 1 500). Konsulten bedömer om de 300 kr som betalats utöver förmånsvärdet ska dras från nettolönen alls.
  - b) Körningen stoppas: "Egen betalning för drivmedel vid bilförmån finns utan någon drivmedelsförmån i månaden. Lägg in drivmedelsförmånen eller ta bort betalningen."
  - Konsulten verifierar: IL 61 kap. 11 § ("aldrig under noll") och att ruta 098 ska vara begränsad till förmånsvärdet.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-23 Avrundning av marknadsvärdet (värde / 1,2)

- Förutsättningar: som LON-20. Ett värde per körning (eller en person per värde).
- Steg i appen: lägg in **Drivmedelsförmån** med **eller belopp** enligt tabellen; läs förklaringen på raden (`contribution_basis_ore`) och arbetsgivaravgiften.
- Förväntat resultat (marknadsvärde = värde × 10 000 / 12 000 avrundat halv upp till öre; arbetsgivaravgift 31,42 % på lön 32 000,00 kr + marknadsvärdet):

| Förmånsvärde (inskrivet) | Exakt värde / 1,2 | Marknadsvärde i avgiftsunderlaget | Underlag totalt | Arbetsgivaravgift totalt | Förmånens del av avgiften |
|---|---|---|---|---|---|
| 1 000,00 | 833,3333… | 833,33 | 32 833,33 | 10 316,23 | 261,83 |
| 999,99 | 833,325 | 833,33 | 32 833,33 | 10 316,23 | 261,83 |
| 1 000,01 | 833,3417… | 833,34 | 32 833,34 | 10 316,24 | 261,84 |
| 1 234,56 | 1 028,80 | 1 028,80 | 33 028,80 | 10 377,65 | 323,25 |
| 3 333,33 | 2 777,775 | 2 777,78 | 34 777,78 | 10 927,18 | 872,78 |
| 49,99 | 41,6583… | 41,66 | 32 041,66 | 10 067,49 | 13,09 |

  - Utan förmånen är avgiften 10 054,40 kr; "förmånens del" är skillnaden mot den.
  - Konsulten verifierar: att räkna tillbaka marknadsvärdet ur det uppräknade värdet kan avvika mindre än en krona från Skatteverkets egen beräkning (kodens TODO i `FuelBenefit.php`), och om det är acceptabelt. Skriv under Avvikelser om NONOX ska ta marknadsvärdet som ett eget fält i stället.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-24 Drivmedelsförmån för äldre och unga anställda

- Förutsättningar: Bertil (född 1955), Cecilia (född 2005), Dagmar (född 1937-05-05), alla med månadslön 32 000 kr och en drivmedelsförmån på 1 200 kr. Körning april 2026.
- Steg i appen: lägg Drivmedelsförmån 1 200 på var och en. **Beräkna**.
- Förväntat resultat (marknadsvärde 1 000,00 kr, avgiftsunderlag 33 000,00 kr per person):
  - Bertil (10,21 %): 33 000,00 × 10,21 % = **3 369,30 kr**. (Räknad på 34 200: 3 389,72 kr vore fel.)
  - Cecilia (nedsatt avgift 20,81 % på de första 25 000 kr): 25 000,00 × 20,81 % = 5 202,50 kr + 8 000,00 × 31,42 % = 2 513,60 kr = **7 716,10 kr**.
  - Dagmar (född 1937 eller tidigare): **0,00 kr** i arbetsgivaravgift; skatteavdraget räknas ändå på 33 200,00 kr.
  - Konsulten verifierar: att åldersreglerna i `employer_contribution.*` tillämpas på marknadsvärdet (avgiftsunderlaget) och att taket för nedsättningen av unga (25 000 kr per månad) räknas på hela underlaget inklusive drivmedlets marknadsvärde.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-25 Skatten större än kontantlönen med drivmedelsförmån, och drivmedelsförmån utan bilförmån

- Förutsättningar: Dan Test (timlön) och Anna. Körning april 2026.
- Steg i appen: a) Dan: Löneart **Timlön**, **eller belopp** 500, och **Drivmedelsförmån**, **eller belopp** 12 000. b) Anna: **Drivmedelsförmån** 1 200 utan någon **Bilförmån** (ingen bil under **Lön → Förmånsbilar**).
- Förväntat resultat:
  - a) Marknadsvärde 12 000,00 / 1,2 = 10 000,00 kr. Skattepliktigt 500,00 + 12 000,00 = 12 500,00 kr; tabellskatt tabell 33 kolumn 1 för 12 500 kr = 1 712,00 kr, men kontantlönen är bara 500,00 kr: skatt som dras **500,00 kr**, netto 0,00 kr, varning "Skatten blev 1 712,00 kr men kontantlönen är 500,00 kr…". Avgiftsunderlag 500,00 + 10 000,00 = 10 500,00 kr; arbetsgivaravgift 10 500,00 × 31,42 % = **3 299,10 kr**. AGI: ruta 001 = 500, 011 = 500, 018 = 12 000.
  - b) Beräkningen går igenom utan varning, och AGI visar ruta 013 = 0 tillsammans med 018 = 1 200 trots att den anställda inte har någon bilförmån. Konsulten beslutar: ska NONOX varna eller stoppa när drivmedelsförmån läggs in utan bilförmån samma månad? (IL 61 kap. 10 §: drivmedelsförmånen hör till bilförmånen. Koden kontrollerar ingenting: `Additions.php` rad 47.) Skriv beslutet under Avvikelser.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-26 AGI-filen för drivmedel och regelns giltighet 2027

- Förutsättningar: LON-20, LON-21 och LON-22 a körda och attesterade i varsitt testbolag (eller varsin månad); regeln `agi.field_codes` kontrollerad.
- Steg i appen: **Lön → Arbetsgivardeklaration**, **Skapa arbetsgivardeklaration** för månaden, **Hämta fil**, öppna XML-filen i en texteditor. Gå sedan till **Lön → Regler** med **Gäller den** 2027-01-25.
- Förväntat resultat:
  - XML-elementen per individ: `KontantErsattningUlagAG` (011), `SkatteplBilformanUlagAG` (013), `DrivmVidBilformanUlagAG` (018), `BetForDrivmVidBilformanUlagAG` (098, bara när betalning finns), `AvdrPrelSkatt` (001); huvuduppgiften har `SummaArbAvgSlf` (487) och `SummaSkatteavdr` (497). Värden: LON-20: 011 32 000, 013 0, 018 1 200, 001 6 682, 487 10 368, 497 6 682. LON-21: 011 32 000, 013 0, 018 1 200, 098 300, 001 6 632, 487 10 274, 497 6 632. LON-22 a: 011 32 000, 013 0, 018 1 200, 098 1 200, 001 6 381, 487 10 054, 497 6 381.
  - Regel 2027: **Drivmedelsförmån, uppräkning** gäller samma version (öppen `valid_to`) för 2027-01-25; den som kontrollerade den för 2026 behöver inte kontrollera den igen, men `rules.json` 2027 anger att ändringar i kraft 2027-01-01 inte är kontrollerade. Kontrollera att andra 2027-regler visas som "inte publicerad ännu" (till exempel `tax.one_off`, `benefit.car.formula`, måltidsförmåner) och att en lönekörning för januari 2027 därför stoppas av dem, inte av drivmedelsregeln.
  - Konsulten verifierar: att fältkoderna 011, 013, 018, 098 och 001 är de som Skatteverkets fältbeskrivning anger; filen kan även skickas till Skatteverkets testtjänst (**Hämta kontrollresultat**, Beta, bara pilotanvändare). Notera varje stoppande fel eller rimlighetsfel som rör 013, 018 eller 098.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______


---

## Del 6. Kollektivavtal (Avtal) och OB/övertid (Planering)

Förutsättningar för hela delen: **Lön → Avtal** är på (modulen Avtal). Avtalet är valt under **Lön → Avtal**, fliken **Företag och anställda**, kortet **Avtal som företaget är bundet av**: **Lägg till avtal**, **Avtal**, **Bundet som** **Medlem i arbetsgivarorganisationen**, **Bundet från** 2025-01-01, **Spara avtalet**. Avtalets version är kontrollerad under **Lön → Avtal**, fliken **Versioner och kalender**: **Kontrollera**, öppna varje källa, kryssa i rutan, **Markera som kontrollerad**. Per anställd: **Lön → Avtal**, kortet **Anställda**, **Ändra**, **Avtal**, **Lönegrupp**, **Branschvana från**, **Spara**. En okontrollerad version stoppar lönekörningen.

### LON-27 Kontroll mot avtalets minimilön

- Förutsättningar: Gröna Riks (Visita–HRF, medlem), Detaljhandelsavtalet och Visita–Unionen, alla kontrollerade. Månadslön med heltid.
- Steg i appen: lägg anställningarna enligt tabellen, kör månaden, läs varningen på lönebeskedet. Minimilönen visas också under **Lön → Avtal**, kolumnen **Minimilön**.
- Förväntat resultat (minimilönen hämtas ur lönesteget som gäller på periodens sista dag; högsta tillämpliga rad gäller; varning, lönen ändras aldrig; `app/Modules/Agreements/Domain/MinimumWageCheck.php`):

| Fall | Avtal, grupp, person | Månad | Lön | Minimilön | Förväntat |
|---|---|---|---|---|---|
| a | Gröna Riks, Lönegrupp 2, född 1996-03-03, ingen branschvana | april 2026 | 26 000,00 | 26 580,00 (steg 2026-04-01) | Varning, 580,00 kr under |
| b | samma person | mars 2026 | 26 000,00 | 25 856,00 (steg 2025-04-01) | Ingen varning |
| c | samma person | april 2026 | 26 580,00 | 26 580,00 | Ingen varning (lika med minimum) |
| d | Gröna Riks, Lönegrupp 2, **Branschvana från** 2020-04-01 (72 månader) | april 2026 | 28 000,00 | 28 944,00 (2026-04-01, minst 72 mån) | Varning, 944,00 kr under |
| e | Gröna Riks, Lönegrupp 2U, född 2007-04-15 | april 2026 | 19 500,00 | 19 992,00 (ålder 18: åldern räknas från månaden efter födelsedagen) | Varning, 492,00 kr under |
| f | samma person | maj 2026 | 20 000,00 | 21 610,00 (ålder 19) | Varning, 1 610,00 kr under |
| g | Detaljhandeln, grupp Butiks-, lager- och kontorspersonal, född 1996-03-03, **Branschvana från** 2023-01-01 (40 månader) | april 2026 | 28 000,00 | 29 156,00 (steg 2026-04-01, 36 mån) | Varning, 1 156,00 kr under |
| h | Visita–Unionen, grupp Alla tjänstemän, född 1990-06-15 | maj 2026 / juni 2026 | 24 000,00 | 23 657,00 / 24 296,00 (steg 2026-06-01) | Ingen varning i maj, varning 296,00 kr under i juni |

  - För 2U: när personen fyllt 20 ska lönekörningen varna om att byta lönegrupp (`youth_too_old`).
  - Konsulten verifierar: samtliga minimilöner mot avtalstexterna (källorna står i versionen, GR s. 59–60, D1 § 7.2 C s. 24–25, VU löneavtal § 2 p. 4 s. 59); hur ålder och branschvana räknas (GR: månaden efter födelsedagen; Detaljhandeln: kodens TODO "förändring betyder födelsemånaden"); att en person som uppfyller flera rader ska ha den högsta.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-28 OB och övertid prissatta enligt avtalet (timmar inlagda för hand)

- Förutsättningar: avtalen i LON-27 bundna och kontrollerade; anställd med månadslön 28 000 kr (Gröna Riks och Detaljhandeln), 40 000 kr (Visita–Unionen), **Arbetstid, timmar per vecka** ifylld. Körning april 2026 (Visita–Unionen: maj och juni).
- Steg i appen: **Timmar och tillägg**: Anställd, avtalets **Löneart** (till exempel **OB-tillägg (Gröna Riks)**), **Antal timmar, mil eller dagar**, lämna **eller belopp** tomt, **Lägg till**, **Beräkna**. Läs "Sats enligt", "version från", "steg från" och "Källa" under raden.
- Förväntat resultat (satsen tas ur lönesteget i kraft på första dagen i månaden; belopp = timmar × sats):
  - Gröna Riks OB april 2026: 10 h × 27,59 = **275,90 kr**; natt-OB 5 h × 24,31 = **121,55 kr**. Samma 10 h i mars 2026: 10 × 26,79 = **267,90 kr** (steg 2025-04-01).
  - Gröna Riks övertid, månadslön 28 000 kr, delare 173: timlön 28 000,00 / 173 = 161,8497 kr/h. April 2026: två första timmarna i anslutning (35 % + timlönen = 135 %) 218,50 kr/h; därutöver (70 % + timlönen = 170 %) 275,14 kr/h; inte i anslutning (90 % + timlönen = 190 %) 307,51 kr/h. Två timmar A + en timme B = 437,00 + 275,14 = **712,14 kr**. I mars 2026 gällde 45 % för A: 145 % = 234,68 kr/h.
  - Gröna Riks deltid: Lön 28 000 kr och **Sysselsättningsgrad, %** 50, 20 timmar per vecka: timlön = 14 000,00 / (20 × 4,3) = 162,79 kr; mertid A (135 %) = **219,77 kr/h**.
  - Visita–Unionen: OB, maj 2026 8 h × 26,68 = **213,44 kr**; juni 2026 8 h × 27,48 = **219,84 kr**. Övertid i anslutning, månadslön 40 000 kr / 94 = **425,53 kr/h**; övrig övertid / 72 = **555,56 kr/h**; 3 timmar i anslutning = 1 276,59 kr. Övertidsersättningen är inte semesterlönegrundande (avtalet säger att semesterlönen ingår).
  - Detaljhandeln, månadslön 28 000 kr, delare 166: timlön 168,67 kr; OB 50 % = **84,34 kr/h**, 70 % = **118,07 kr/h**, 100 % = **168,67 kr/h**.
  - Skatt, avgift: OB och övertid är vanlig lön (tabell, 31,42 %, semesterlönegrundande utom Visita–Unionens övertid).
  - Stopp som ska ske: anställd med **OB** = **Ingår i lönen (skriftligt avtal)** och en OB-rad: körningen stoppas med "OB ingår i lönen enligt anställningens avtalsuppgifter…". Löneart från ett annat avtal än anställningens: "Lönearten … hör inte till …".
  - Konsulten verifierar: satserna mot avtalen (GR § 12–13, VU § 11 och § 21, D1 § 8–9), att satsen "plus timlönen" avser månadslön (som inte betalar övertidstimmen), delarna 173/166 och 4,3-faktorn för deltid, och att övertidsprocenten 35 % från 1 april 2026 (45 % före) är rätt i Gröna Riks (`GR_OT_A` i `visita-hrf-grona-riks/MEMBER-2025-04-01.json`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-29 OB uppdelad från arbetspass (Planering) och övertid per vecka

- Förutsättningar: Planering och Avtal på; Gröna Riks (medlem) bundet och kontrollerat; månadsanställd "Gabriel Test" (28 000 kr, 40 h/vecka, Gröna Riks, Lönegrupp 2); **Planering → Inställningar** med **Löneart för övertid (månadslön, heltid)** tom eller en av avtalets lönearter. Pass publicerade under **Planering → Schema**, faktisk tid ändrad vid behov under **Planering → Tid att attestera** (**Ändra**: **Från**, **Till**, **Rast (minuter)**).
- Steg i appen: **Planering → Tid att attestera**, välj period, läs kolumnerna **Plan**, **Faktiskt**, **OB**, **Till lönen**; **Attestera**; **Skicka till lönen** och bekräfta. Öppna lönekörningen och klicka **Beräkna**.
- Förväntat resultat (OB-fönster och rundning till påbörjad halvtimme per pass; `app/Modules/Planning/Domain/ObSplitter.php`, `TimeRounding`):
  - a) Fredag 2026-04-24 18:00–02:00 (ingen rast): OB 20:00–02:00 = 6 h × 27,59 = **165,54 kr** (OB-fönster mån–fre 20.00 – 06.00 nästa dag) och natt-OB 01:00–02:00 = 1 h × 24,31 = **24,31 kr** (natt-OB 01.00–06.00 gäller även utöver ordinarie OB). Summa OB 189,85 kr.
  - b) Fredag 17:00–20:10: 10 minuter OB avrundas till påbörjad halvtimme = 0,5 h × 27,59 = 13,795, per rad avrundat halv upp till **13,80 kr**.
  - c) Lördag 2026-04-25 14:00–22:00: OB från 16.00 = 6 h × 27,59 = **165,54 kr**.
  - d) Söndag 2026-04-26 10:00–18:00: hela dygnet OB (sön- och helgdag) = 8 h × 27,59 = **220,72 kr**.
  - e) Midsommarafton fredag 2026-06-19 16:00–24:00: OB som lördag från 16.00 = 8 h × 27,59 = **220,72 kr** (juni 2026 har samma steg som april). Minuter som ligger i flera OB-fönster med samma kod räknas en gång.
  - f) Övertid: måndag 2026-04-13 planerat 08:00–16:00, faktiskt 08:00–19:00, resten av veckan enligt schema och hela veckan attesterad: veckans övertid är 3 h i anslutning; de två första är art a (35 %), den tredje art b (70 %): 2 × 218,50 + 275,14 = **712,14 kr**. Övertiden skickas med veckans sista dag på avtalets lönearter.
  - g) Detaljhandeln (28 000 kr, delare 166), tisdag 2026-04-14 18:00–22:00: OB 50 % 18.15–20.00 = 1,75 h × 84,34 = **147,60 kr**; OB 70 % 20.00–22.00 = 2 h × 118,07 = **236,14 kr**. Ingen rundning till halvtimme i Detaljhandeln.
  - Konsulten verifierar: OB-fönster och satser mot avtalen; rundningen "varje påbörjad halvtimme" per pass eller per period (kodens TODO och dataanteckning); att Gröna Riks två första övertidstimmar räknas per dag och inte per tillfälle; att Detaljhandelns OB vid 00.00–06.00 en vardag inte ska vara 70 % (dataanteckning); att OB på samma tid som övertid inte dubbelbetalas.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-30 Avtalets sjuklön och semester

- Förutsättningar: Avtal på; kontrollerade versioner. Anna Test som tjänsteman i Visita–Unionen (anställd från 2025-01-01, alltså över ett år), Detaljhandelsavtalet för en månadsanställd 28 000 kr med arbetsschema 7,65 h/dag (kunde inte verifiera att schemat tillåter 7,65 timmar; annars notera hur dagen räknas), Gröna Riks för en timanställd.
- Steg i appen: a) registrera sjukfrånvaro 2026-04-13 till 2026-04-30 (**Timmar per dag** 8). b) sjuk tisdag 2026-04-14, **Timmar per dag** 7,65. c) semesterdagar (Frånvaro, Orsak **Semester**) med semesterlönegrundande lön 312 000 kr inläst som ingående saldo (**Lön → Flytt av lönedata**, semester) och 25 betalda dagar.
- Förväntat resultat:
  - a) Visita–Unionen, dag 15–90 för den som varit anställd minst 12 månader: dag 1–14 (13–26 april, 80 arbetstimmar): **Sjukavdrag** −14 769,23 kr, **Sjuklön 80 %** 11 815,38 kr, **Karensavdrag** −1 181,54 kr. Dag 15–18 (27–30 april): avdrag per kalenderdag = 90 % × (32 000,00 × 12 = 384 000,00) / 365 = 946,85 kr (tröskeln är 10 prisbasbelopp = 592 000,00 kr, ej nådd; högsta avdrag per dag är 384 000,00 / 365 = 1 052,05 kr) → 4 × 946,85 = **3 787,40 kr**, raden **Sjukavdrag dag 15–90 enligt avtal**. Brutto 32 000,00 − 14 769,23 + 11 815,38 − 1 181,54 − 3 787,40 = **24 077,21 kr**. Skatt för 24 077 kr = 4 421,00 kr. Arbetsgivaravgift 7 565,06 kr. Varning att anmäla sjukfallet till Försäkringskassan.
  - b) Detaljhandeln, hel sjukdag: avdrag 4,6 % × 28 000,00 = **1 288,00 kr**; sjuklön 80 % = 1 030,40 kr; avtalets karens = 20 % av 38,25 h = 7,65 h × (28 000,00 / 166 = 168,67 kr/h) × 80 % = 1 032,29 kr, men karensavdraget får inte överstiga periodens sjuklön: **−1 030,40 kr**, rest 1,89 kr dras inte. Brutto 28 000,00 − 1 288,00 + 1 030,40 − 1 030,40 = 26 712,00 kr. Är arbetsschemat 8 h/dag räknas 7,65 h som del av dag (7,65 × 168,67 = 1 290,36 kr i stället för 1 288,00 kr). Notera vilket du fick.
  - c) Gröna Riks, procentregeln: 12,72 % × 312 000,00 / 25 = 1 587,456 kr per dag, men lägsta semesterlön per dag för 20 år och äldre är 1 675,00 kr (steg 2026-04-01): tre dagar ger **5 025,00 kr** (3 × 1 675,00). Lagens 12 % ger 3 × 1 497,60 = 4 492,80 kr. Visita–Unionen, sammalöneregeln: semestertillägg 0,8 % per dag av 32 000,00 = 256,00 kr; fem dagar = **1 280,00 kr** (lagen: 688,00 kr, LON-13).
  - Konsulten verifierar: VU § 23.1–23.3 (dag 15–90, 90 %/10 %, 10 prisbasbelopp, 105 dagar per tolv månader), D1 § 13.1 och § 15.4 (4,6 %, 1/166, karens på överenskommen veckotid), GR § 17 (12,72 %, lägsta semesterlön 1 675 kr), VU § 16 (0,8 %); att avtalets karens får kapas vid periodens sjuklön (kodens TODO).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

---

## Del 7. Lönefil, bokföring (serie L) och arbetsgivardeklaration

För de följande fallen används en gemensam körning: **april 2026** med Anna, Bertil och Cecilia (alla 32 000 kr, födda 1990, 1955 och 2005; tabell 33 kolumn 1, 3 och 1), inga andra rader. Beräknat och attesterat resultat:

| Person | Brutto | Skatt | Netto | Arbetsgivaravgift |
|---|---|---|---|---|
| Anna | 32 000,00 | 6 381,00 | 25 619,00 | 10 054,40 |
| Bertil | 32 000,00 | 3 135,00 | 28 865,00 | 3 267,20 |
| Cecilia | 32 000,00 | 6 381,00 | 25 619,00 | 7 401,90 |
| **Summa** | **96 000,00** | **15 897,00** | **80 103,00** | **20 723,50** |

### LON-31 Lönefil till banken och lönens verifikation i serie L

- Förutsättningar: körningen ovan attesterad; bankuppgifterna under **Leverantörer → Betalningar** ifyllda; varje anställd har **Clearingnummer** och **Kontonummer för lön**; **Semesterlöneskuld bokförs** Vid bokslut.
- Steg i appen: öppna lönekörningen under **Lön → Lönekörningar**. 1. **Hämta lönefil till banken** och öppna filen. 2. Klicka **Markera som utbetald**. 3. Klicka **Bokför lönen**. Läs verifikationen (länk på lönekörningen). Byt för delfall c Bertils kontonummer (ändring väntar på bekräftelse av en annan person) och försök hämta lönefilen.
- Förväntat resultat:
  - a) Lönefilen (ISO 20022 pain.001) har tre betalningar: 25 619,00 kr (Anna), 28 865,00 kr (Bertil), 25 619,00 kr (Cecilia); antal transaktioner 3, kontrollsumma **80 103,00 kr**. Filen kan inte skapas om en anställd saknar bankkonto, om ett ändrat konto inte är bekräftat ("Nytt bankkonto …1234 väntar på bekräftelse"; en annan behörig person klickar **Bekräfta**) eller om ingen har något att få utbetalat.
  - b) Verifikationen i serie **L** med utbetalningsdagen 2026-04-24 som datum. Raderna är summerade per konto (inga enskilda löner): debet 7010 96 000,00; debet 7510 20 723,50; kredit 2710 15 897,00; kredit 2730 20 723,50; kredit 1930 80 103,00. Summa debet 116 723,50 = summa kredit 116 723,50.
  - c) Variant med sjukfrånvaro (LON-10 a, Anna ensam): debet 7010 29 046,15 (32 000,00 − 2 953,85); debet 7081 1 181,54 (sjuklön 2 363,08 − karensavdrag 1 181,54); debet 7510 9 497,54; kredit 2710 5 979,00; kredit 2730 9 497,54; kredit 1930 24 248,69. Debet 39 725,23 = kredit 39 725,23.
  - d) Variant med förskott 40 000 kr (LON-09 b, Anna ensam): konto 1680 får förskottet −40 000,00 (kredit) och skulden +14 381,00 (debet), summerat kredit 25 619,00; konto 1930 får 0,00 (ingen utbetalning; kontrollera hur en rad med noll hanteras). Verifikationen balanserar: debet 7010 32 000,00 + 7510 10 054,40 = 42 054,40; kredit 2710 6 381,00 + 2730 10 054,40 + 1680 25 619,00 = 42 054,40.
  - e) Förmåner (kostförmån, bilförmån, drivmedelsförmån) bokförs inte som egen kostnad; bara avgiften på dem ingår i 7510. Pension: debet 7410 / kredit 2740 och debet 7530 / kredit 2514 (LON-17).
  - f) Verifikationen kan inte ändras efter bokning; en andra körning av "Bokför lönen" ska inte vara möjlig.
  - Konsulten verifierar: kontoplanen (kollektivanställda 70xx även för tjänstemän; BAS 2026), att 7388 för egen betalning och 1680 för förskott är rimliga, att särskild löneskatt på pension bokas mot 2514, och att verifikationen summerar per konto (GDPR) men fortfarande går att följa till lönebeskeden.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-32 Skatt och avgifter mot skattekontot

- Förutsättningar: LON-31 klar (status Bokförd).
- Steg i appen: på lönekörningen, rutan för skattekontot: **Dag** (förslaget är den 12:e månaden efter utbetalningen, 2026-05-12), **Bokför mot skattekontot**. Öppna **Lön → Rapporter → Avstämning** och välj 2026.
- Förväntat resultat:
  - Verifikation i serie L: debet 2710 15 897,00; debet 2730 20 723,50; kredit 1630 36 620,50 (summa 36 620,50 = 36 620,50). Det görs en gång per körning.
  - Särskild löneskatt på pension ingår inte (betalas med företagets egen preliminärskatt).
  - Avstämningen, april: skatt lönebesked 15 897,00; skatt deklarerad (efter LON-33) 15 897; skatt bokförd (2710) 15 897,00. Avgifter: lönebesked 20 723,50; deklarerad 20 723; bokförd 20 723,50. Skillnaden 0,50 kr är öresavrundning och ska inte markeras.
  - Observation att bedöma: Skatteverket drar hela kronor (20 723 + 15 897 = 36 620 kr) medan verifikationen bokför 36 620,50 kr; 0,50 kr blir kvar på 2730 och 1630. Konsulten avgör om bokningen ska följa den deklarerade hela-kronorssumman (`PayrollRuns::postTax` bokar öresumman; kodens TODO om skattekontot).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-33 Innehållet i arbetsgivardeklarationen (AGI-filen)

- Förutsättningar: LON-31 klar; företagets organisationsnummer och telefonnummer ifyllda; regeln för filformatet (`agi.schema_version`, version 1.1) och `agi.field_codes` kontrollerade.
- Steg i appen: **Lön → Arbetsgivardeklaration**, **Utbetalningsmånad** april 2026, **Skapa arbetsgivardeklaration**, **Hämta fil**. Öppna XML. Skriv **Kvittensnummer (frivilligt)**, **Markera som inlämnad**, **Godkänd av Skatteverket**.
- Förväntat resultat:
  - Huvuduppgift (HU): redovisningsperiod 202604; ruta 487 (`SummaArbAvgSlf`) = **20 723** (20 723,50 med öretalet borttaget); ruta 497 (`SummaSkatteavdr`) = **15 897**.
  - Individuppgifter (en per anställd, ruta 570 specifikationsnummer = anställningsnummer): Anna ruta 011 = 32 000, ruta 001 = 6 381; Bertil 011 = 32 000, 001 = 3 135; Cecilia 011 = 32 000, 001 = 6 381. Alla belopp i hela kronor, öre faller bort.
  - Tillägg per fall: milersättning (LON-15 b) ger ruta 050 = 1; traktamente ger ruta 051 = 1; bilförmån ruta 013; kostförmån ruta 012; lön under 1 000 kr/år (LON-05) ruta 131 och i skärningsmånaden rutorna 011 och 010; SINK ruta 274 / A-SINK ruta 275 med ruta 112; regionalt stöd ruta 471 och 476; en förmån som den anställda betalat helt visas med 0 i ruta 012.
  - Skatten är den som faktiskt drogs (LON-09 a: ruta 001 = 1 500, inte 2 540).
  - Stopp som ska ske: en lönekörning i månaden som inte är attesterad ("…betalas ut i månaden men är inte attesterad…"); organisationsnummer eller telefonnummer saknas; ett belopp som blir negativt; A-SINK utan verksamhetens art; enskild firma stöds inte.
  - Filen kontrolleras mot Skatteverkets schema när det finns installerat och sparas med kontrollsumma; varje hämtning skrivs i behandlingshistoriken.
  - Konsulten verifierar: fältkoderna 011, 012, 013, 018, 098, 001, 010, 131–134, 274, 275, 112, 050, 051, 471, 476, 487, 497 mot Skatteverkets fältbeskrivning (https://www.skatteverket.se/agbeskrivning); att ruta 487 ska innehålla enbart arbetsgivaravgifter (särskild löneskatt på pension ingår inte); avrundningen: individuppgifterna avrundas var för sig medan huvuduppgiften är summan av öresbeloppen avrundad (10 054 + 3 267 + 7 401 = 20 722 mot 20 723; kodens TODO i `AgiDeclaration.php` rad 25).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-34 Deklarationsdag och betalningsdag för AGI

- Förutsättningar: **Lön → Arbetsgivardeklaration** visar **Nästa arbetsgivardeklaration**. Företaget är momsregistrerat. Fall a: beskattningsunderlaget under 40 miljoner (förvalt). Fall b: **Beskattningsunderlaget för moms är över 40 miljoner kronor om året** ikryssat under företagets uppgifter.
- Steg i appen: läs deklarationsdagen för varje utbetalningsmånad i tabellen; byt inställning och läs igen.
- Förväntat resultat (`app/Domain/Deadlines/AgiDates.php`, `app/Modules/Payroll/Support/Agi/AgiDeadlines.php`, regel `agi.deadlines`; en dag på helg, söndag, helgdag eller de tre aftnarna flyttas till nästa vardag):

| Utbetalningsmånad | Små (≤ 40 mkr): deklarera och betala | Stora (> 40 mkr): deklarera | Stora: betala |
|---|---|---|---|
| december 2025 | 2026-01-19 (17 jan är lördag) | 2026-01-26 | 2026-01-19 (17 jan lördag) |
| januari 2026 | 2026-02-12 | 2026-02-26 | 2026-02-12 |
| april 2026 | 2026-05-12 | 2026-05-26 | 2026-05-12 |
| juli 2026 | 2026-08-17 (17:e i augusti) | 2026-08-26 | 2026-08-12 |
| november 2026 | 2026-12-14 (12 dec lördag) | 2026-12-28 (27 dec söndag) | 2026-12-14 |
| december 2026 | 2027-01-18 (17 jan söndag) | 2027-01-26 | 2027-01-18 |

  - Små: skatteverkets lista i regeln `agi.deadlines` (2026) har datumen 2026-01-19, 02-12, 03-12, 04-13, 05-12, 06-12, 07-13, 08-17, 09-14, 10-12, 11-12, 12-14 och 2027-01-18 och ska stämma med tabellen ovan (lagens datum flyttade till nästa vardag).
  - En sen månad visas med "skulle ha lämnats senast" och datumet.
  - Konsulten verifierar: SFL 26 kap. 26, 30 och 32 §§ och 62 kap. 3 § (att stora arbetsgivare deklarerar den 26:e, 27:e i december, men betalar den 12:e, 17:e i januari); att augusti för stora arbetsgivare har betalningsdag 12 augusti och inte 17 augusti (koden flyttar bara januari till 17:e för betalning; kontrollera mot 62 kap. 3 § andra stycket); att helgdagsflytten (lag 1930:173) gäller även aftnar.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### LON-35 Rättelse av inlämnad AGI och avslut av löneåret

- Förutsättningar: LON-33 med status **Godkänd av Skatteverket**. Attesterade lönekörningar är låsta, så provet gäller själva rättelsefunktionen, inte en ändrad lön.
- Steg i appen: **Lön → Arbetsgivardeklaration**, leta upp april, **Skapa rättelse**, **Hämta fil**, **Markera som inlämnad**, **Godkänd av Skatteverket**. Därefter **Lön → Rapporter → Årsskifte**: läs hindren; bokför alla körningar och lämna in alla månader; **Avsluta löneåret**, **Ja, avsluta löneåret**. Försök skapa en lönekörning med utbetalningsdag i året.
- Förväntat resultat:
  - Rättelsen innehåller alla individuppgifter för månaden igen; en anställd som inte längre ska finnas med får en individuppgift med `Borttag` (ruta 205) = 1; den förra filen visas som **Ersatt av rättelse** när rättelsen är godkänd.
  - Löneåret kan avslutas först när året är slut, alla körningar med utbetalning i året är bokförda och alla månader med utbetald lön har inlämnad deklaration. Efter avslut kan ingen lönekörning skapas med utbetalningsdag i året; AGI för året kan fortfarande rättas.
  - Lönemodulen kan inte stängas av så länge en attesterad körning inte är bokförd eller en månad saknar inlämnad deklaration.
  - Konsulten verifierar: att rättelsefilens individuppgifter ersätter rätt specifikation (anställningsnummer) och att rättelse till noll sker med Borttag, inte med nollor.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______


---

## Öppna TODO(legal-verify) i koden

Sökning: `grep -rn "TODO(legal-verify)" app/Modules/Payroll app/Modules/Agreements app/Modules/Planning` (kod och datafiler, 112 träffar den dag checklistan skrevs; en rad per träff, ingen urvalsgranskning). Raderna för `database/data/*` är träffar i datafilernas anteckningar. Rader som delar fil och radnummer är olika förbehåll i samma anteckning. Bedöm varje rad: **Godkänd** (förbehållet är rimligt eller löst), **Ändra** (koden/datat ska ändras före Beta-avtagning) eller **Avslag** (förbehållet gör att funktionen inte får användas), och skriv en kommentar.

| Nr | Fil:rad | Frågan | Konsultens beslut (Godkänd / Ändra / Avslag) | Kommentar |
|---|---|---|---|---|
| 1 | `app/Modules/Payroll/database/data/2026/rules.json:726` | SAF-LO 2026: 0,10 % av premien finansieras av överskott (4,85 % i stället för 4,95 % med försäkringarna). Om arbetsgivarens kostnad bokförs på 4,40 % eller 4,50 % kontrolleras mot fakturan. Åldersgränser: från månaden personen fyller 22 till månaden före 65. |  |  |
| 2 | `app/Modules/Payroll/database/data/2026/rules.json:742` | ITP 1 2026: vid vilken ålder premierna slutar, från vilken ålder de börjar (25 enligt avtalat.se) och Collectums administrationsavgift. |  |  |
| 3 | `app/Modules/Payroll/database/data/2026/rules.json:794` | Fora arbetare: vilken TSL-rad som gäller per avtalsområde (0,34/0,74 % med Huvudavtal, 0,15/0,55 % utan; Handels gick inte med i Huvudavtalet 2024). Kammarkollegiets återbetalning bokförs inte. Åldrar anges inte i källan. |  |  |
| 4 | `app/Modules/Payroll/database/data/2026/rules.json:794` | Fora arbetare (samma not, andra förbehållet): åldrarna som premierna gäller för är inte angivna i källan. |  |  |
| 5 | `app/Modules/Payroll/database/data/2026/rules.json:818` | Fora tjänstemän: TRR till månaden före 67 år, 0,15 % återbetalas via Fora; TFA 0,02 % och TRR 0,40 %/0,80 % lästa ur Foras prislista 2026. |  |  |
| 6 | `app/Modules/Payroll/database/data/2027/rules.json:3` | Regelfilen för 2027: alla versioner importeras okontrollerade. Lagstadgade värden är tagna ur gällande lagtext med redan beslutade ändringar; budgetpropositionen för 2027 var inte lagd 2026-11-02. Kontrolleras igen efter riksdagens decemberbeslut. |  |  |
| 7 | `app/Modules/Payroll/database/data/2027/rules.json:365` | Regler som fortsätter in i 2027 (tax.one_off_without_regular_salary, agi.field_codes): Skatteverkets vägledning, ingen ändring aviserad. Kontrolleras igen i januari 2027. |  |  |
| 8 | `app/Modules/Payroll/database/data/2027/rules.json:372` | tax.side_income (30 % på sidoinkomst, SFL 11 kap. 20 §) i 2027: ändringar i kraft 2027-01-01 är inte kontrollerade. |  |  |
| 9 | `app/Modules/Payroll/database/data/2027/rules.json:380` | benefit.car.scope och benefit.fuel.factor i 2027 (IL 61 kap. 7 och 10 §§, SAL 2 kap. 10 a §): ändringar i kraft 2027-01-01 efter budgetpropositionen är inte kontrollerade. |  |  |
| 10 | `app/Modules/Payroll/Support/CompTimeLedger.php:36` | Komptid (CompTimeLedger): vilket timvärde avtalet föreskriver för utbetald komptid (avtalets delare används inte), och om komptid enligt Visita–Unionen eller Handels tjänstemannaavtal omfattas av att semesterlönen ingår i övertidsersättningen. |  |  |
| 11 | `app/Modules/Payroll/Support/Agi/AgiPayee.php:24` | AGI-mottagare (AgiPayee): om utländskt skatteregistreringsnummer måste krävas för varje SINK- och A-SINK-mottagare (SFF 6 kap. 2 § har undantag; här är det frivilligt). |  |  |
| 12 | `app/Modules/Payroll/Support/Agi/AgiDeclaration.php:25` | AGI (AgiDeclaration): Skatteverkets avrundning av belopp med öre, och om summerade avgifter får avvika från Skatteverkets egen beräkning per individ med de öre som faller bort. |  |  |
| 13 | `app/Modules/Payroll/Support/Agi/AgiDeclaration.php:197` | AGI: avgiftsfritt drivmedel (ruta 134) har ingen egen ruta 098; den anställdas betalning sänker ruta 134. |  |  |
| 14 | `app/Modules/Payroll/Support/Agi/AgiDeclaration.php:224` | AGI: tidigare avgiftsfria förmåner läggs också till i ruta 011, eftersom Skatteverkets exempel bara visar kontant ersättning. |  |  |
| 15 | `app/Modules/Payroll/Support/Calendar/SwedishHolidays.php:26` | Helgdagskalender (SwedishHolidays): 3 a § semesterlagen tillåter lördag och söndag som semesterdagar i fallen i 9 § tredje stycket (scheman med helgarbete). |  |  |
| 16 | `app/Modules/Payroll/Support/Calendar/VacationDays.php:26` | Semesterdagar (VacationDays): vilken räknemetod ett kollektivavtal föreskriver, och att semester som delas över två månader bedöms per månad i lönekörningen. |  |  |
| 17 | `app/Modules/Payroll/Support/Vacation/VacationLedger.php:110` | Semesterlager (VacationLedger): värdet per dag enligt 29 § utan kollektivavtal, och avdrag för förskottssemester. |  |  |
| 18 | `app/Modules/Payroll/Support/Vacation/VacationLedger.php:244` | Semesterlager: sparade dagar som förfaller oanvända. |  |  |
| 19 | `app/Modules/Payroll/Support/Vacation/VacationLedger.php:310` | Semesterlager: värdering av semesterlöneskulden under sammalöneregeln (BFN:s vägledning) och avgifter för anställda med nedsatta avgifter. |  |  |
| 20 | `app/Modules/Payroll/Support/Run/ContextBuilder.php:494` | Lönekörningens underlag (ContextBuilder): frånvaroavdrag och semesterlön i månader före en lönerevision är värderade på den gamla lönen och räknas inte om. |  |  |
| 21 | `app/Modules/Payroll/Support/CarBenefits.php:80` | Förmånsbilar (CarBenefits): Skatteverkets regel när bilen byts under en månad. |  |  |
| 22 | `app/Modules/Payroll/Http/EmployerCertificateController.php:29` | Arbetsgivarintyg: vilka fält intyget ska innehålla, till exempel arbetade timmar per månad för timanställda. |  |  |
| 23 | `app/Modules/Payroll/Actions/CompanyCars.php:208` | Förmånsbilar (CompanyCars): en bil per anställd och månad; byte av bil inom en månad. |  |  |
| 24 | `app/Modules/Payroll/Actions/MovePayrollData.php:43` | Flytt av lönedata (MovePayrollData): det tidigare systemets egna exportformat (till exempel PAXml) och API, för att läsa data utan CSV-steget. |  |  |
| 25 | `app/Modules/Payroll/Actions/AgiSubmissions.php:46` | AGI-inlämning (AgiSubmissions): deklaration för enskild firma, vars arbetsgivar-id är ägarens personnummer. |  |  |
| 26 | `app/Modules/Payroll/Actions/PayrollRuns.php:303` | Lönekörning (PayrollRuns): SFL 11 kap. 16 § andra stycket låter januaris skatteavdrag använda förra årets tabell; någon sådan reserv är inte byggd, körningen stoppas. |  |  |
| 27 | `app/Modules/Payroll/Actions/PayrollRuns.php:688` | Lönekörning (PayrollRuns): hur särskild löneskatt på pensionskostnader når skattekontot (bokföringen mot skattekontot tar den inte med). |  |  |
| 28 | `app/Modules/Payroll/Actions/CloseVacationYear.php:41` | Stängning av semesterår (CloseVacationYear): begränsningen i 26 a § till dagar som tillsammans med intjänade dagar överstiger 25, och vilken lön som räknas som rörlig lön. |  |  |
| 29 | `app/Modules/Payroll/Actions/ConfirmBankChange.php:22` | Bekräfta ändrat bankkonto (ConfirmBankChange): om ett enmansföretag behöver ytterligare bevis, till exempel en ny inloggningskod, innan ett lönekonto ändras. |  |  |
| 30 | `app/Modules/Payroll/Actions/EraseEmployee.php:32` | Radering enligt GDPR (EraseEmployee): om frånvaroposter bakom betald sjuklön är räkenskapsinformation (sparas med lönebeskedet) eller får raderas tidigare. |  |  |
| 31 | `app/Modules/Payroll/Actions/SalaryPaymentFile.php:31` | Lönefil (SalaryPaymentFile): varje banks krav på lönefiler (vissa kräver särskilt löneavtal; Swedbanks femsiffriga clearingnummer). |  |  |
| 32 | `app/Modules/Payroll/Domain/Tax/WithholdingTax.php:23` | Skatteberäkning (WithholdingTax): hur en periodinkomst med öre placeras i tabellens intervall i hela kronor (här: hela kronor, öre bortfaller). |  |  |
| 33 | `app/Modules/Payroll/Domain/PayCodeCatalog.php:24` | Lönearter (PayCodeCatalog): övertid som rörlig lönedel under sammalöneregeln. |  |  |
| 34 | `app/Modules/Payroll/Domain/PayCodeCatalog.php:30` | Lönearter: vinstandel som inte betalas för arbete kan falla utanför semesterlönegrundande lön; ett avtal kan säga annat. |  |  |
| 35 | `app/Modules/Payroll/Domain/PayCodeCatalog.php:95` | Lönearter: BAS 2026:s namn på konto 7388 (hämtat från FAR:s vägledning). |  |  |
| 36 | `app/Modules/Payroll/Domain/PayCodeCatalog.php:103` | Lönearter: BAS 2026:s namn på konto 7570, kontrolleras mot BAS före release. |  |  |
| 37 | `app/Modules/Payroll/Domain/Benefits/CarBenefitCalculator.php:38` | Bilförmån (CarBenefitCalculator): avrundningen av delarna och månadsvärdet; om 50 %-taket i 8 a § tas på priset med extrautrustning och efter 7 §; hur de sex åren i 7 § räknas (här: inkomstår minus modellår). |  |  |
| 38 | `app/Modules/Payroll/Domain/Benefits/FuelBenefit.php:28` | DRIVMEDELSFÖRMÅN (FuelBenefit): marknadsvärdet räknas tillbaka ur det inskrivna värdet (värde / 1,2, halv upp till öre) och kan därför avvika med mindre än en krona från Skatteverkets; den anställdas betalning dras också från avgiftsunderlaget (IL 61 kap. 11 § genom SAL 2 kap. 10 a §) upp till marknadsvärdet, medan Skatteverkets sida bara säger att avgiften räknas på marknadsvärdet. |  |  |
| 39 | `app/Modules/Payroll/Domain/Engine/Steps/SickPay.php:54` | Sjuklön (SickPay): överföring av rest av karensavdraget och räkningen av tillfällen (sjuklönelagen 6–7 §§, prop. 2017/18:96 s. 68–69); deltidssjukskrivning från dag 15 enligt avtal (hela avdraget per kalenderdag görs, en notering säger det). |  |  |
| 40 | `app/Modules/Payroll/Domain/Engine/Steps/SickPay.php:270` | Sjuklön: de avtal som lästs säger inget om deltidssjukskrivning från dag 15. |  |  |
| 41 | `app/Modules/Payroll/Domain/Engine/Steps/SickPay.php:352` | Sjuklön: Detaljhandelsavtalet § 13.1 drar 4,6 % per hel dag; karenstiden värderas här per timme. |  |  |
| 42 | `app/Modules/Payroll/Domain/Engine/Steps/EmployerContributions.php:46` | Arbetsgivaravgifter (EmployerContributions): avrundning av avgifter per lönebesked (halv upp till öre; AGI redovisar summor); regionalt stöd räknas per lönebesked i öre med golv per lönebesked där lagen talar om periodens summa; företagsinställningen gäller alla anställda (ett fast driftställe i området). |  |  |
| 43 | `app/Modules/Payroll/Domain/Engine/Steps/WithholdingTaxStep.php:30` | Skatteavdrag (WithholdingTaxStep): Skatteverkets vägledning till arbetsgivare beskriver bara en procentsats vid jämkning; tabell- och beloppsformerna följer personalkortets kolumner, och hur ett beloppsbeslut behandlar engångsbelopp (här täcker beloppet hela utbetalningen) är inte verifierat. |  |  |
| 44 | `app/Modules/Payroll/Domain/Engine/Steps/WithholdingTaxStep.php:47` | Skatteavdrag: förhöjt skatteavdrag enligt SFL 11 kap. 21 § när skatteuppgift saknas för en huvudinkomst (tabellen på utbetalarens ort plus 10 %) är inte byggt; sådana lönebesked stoppas. |  |  |
| 45 | `app/Modules/Payroll/Domain/Engine/Steps/WithholdingTaxStep.php:50` | Skatteavdrag: om skattefri kontantersättning (traktamente, milersättning) får användas för att dra skatt på annan lön; här räknas den som kontant. |  |  |
| 46 | `app/Modules/Payroll/Domain/Engine/Steps/NetPay.php:23` | Nettolön (NetPay): att ta en tidigare fordran från senare lön är kvittning (lag 1970:215 om arbetsgivares kvittningsrätt). Fordran kommer från ett avdrag arbetsgivaren lagt in (förskott, nettoavdrag); om varje sådan fordran får kvittas utan ny samtycke är en fråga för jurist. |  |  |
| 47 | `app/Modules/Payroll/Domain/Engine/Steps/Pension.php:22` | Tjänstepension (Pension): planens satser är företagets (avtal eller egen policy); vilken lön som är pensionsgrundande följer lönearternas kontanta lön. Plan från kollektivavtal kan ha egen tröskel, tak och åldrar. |  |  |
| 48 | `app/Modules/Payroll/Domain/Engine/Steps/Allowances.php:31` | Traktamente (Allowances): villkor för skattefritt traktamente (övernattning, mer än 50 km, minskade belopp efter tre månader och två år) och måltidsavdrag på traktamente är användarens ansvar vid inmatning. |  |  |
| 49 | `app/Modules/Payroll/Domain/Engine/Steps/NetItems.php:16` | Nettoposter (NetItems): utmätning och skyddat belopp stöds inte. |  |  |
| 50 | `app/Modules/Payroll/Domain/Engine/Steps/Vacation.php:37` | Semester (Vacation): värdet av sparade dagar under procentregeln (här: årets värde). |  |  |
| 51 | `app/Modules/Payroll/Domain/Engine/Steps/Vacation.php:67` | Semester: semesterersättning när anställningen upphör beskattas som engångsbelopp. |  |  |
| 52 | `app/Modules/Payroll/Domain/Engine/Steps/Vacation.php:140` | Semester: lönen för semesterdagar under procentregeln dras som annan frånvaro, per timmar enligt schemat. |  |  |
| 53 | `app/Modules/Payroll/Domain/Engine/Steps/Vacation.php:163` | Semester: semesterersättning som betalas med varje lön; Skatteverket kallar den engångsbelopp men löpande utbetalningar beskattas enligt tabell. Företaget väljer (inställningen Skatt på semesterersättning med varje lön). |  |  |
| 54 | `app/Modules/Payroll/Domain/Engine/HourlyValue.php:16` | Timvärde (HourlyValue): sjuklönelagen 6 § talar om lönen under en genomsnittlig vecka; omräkningen månadslön × 12 / 52 och timvärdet för avdrag måste bekräftas (avtal kan avvika). |  |  |
| 55 | `app/Modules/Agreements/database/data/agreements/livsmedelsforetagen-livs-bageri/MEMBER-2025-04-01.json:151` | Livs/bageri: BG_FORSKJUTEN har inga tidsfönster; faktabladet anger bara beloppet (LA § 3), inte när förskjuten tid uppstår. |  |  |
| 56 | `app/Modules/Agreements/database/data/agreements/livsmedelsforetagen-livs-bageri/MEMBER-2025-04-01.json:153` | Livs/bageri: inga övertidskoder. Bageri § 4 ger +50 % de två första timmarna på vardag och +100 % därefter samt på sön- och helgdag, men söndagsdygnet (lördag 11.00 till måndag) och helgdagsdygnet (från 18.00 dagen före) kräver egna dagtyper. Mertid betalas som övertid (huvudavtalet § 3.3). |  |  |
| 57 | `app/Modules/Agreements/database/data/agreements/livsmedelsforetagen-livs-bageri/MEMBER-2025-04-01.json:154` | Livs/bageri: raden "vuxen med ett års anställning efter 18 år" är förenklad till fyllda 18 år och 12 månaders anställning; anställningsmånader före 18 år ska inte räknas. |  |  |
| 58 | `app/Modules/Agreements/database/data/agreements/livsmedelsforetagen-livs-bageri/MEMBER-2025-04-01.json:155` | Livs/bageri: avtalet anger inte från vilken dag ett nytt ålderssteg gäller; PERIOD_END (periodens slut) är ett antagande. |  |  |
| 59 | `app/Modules/Agreements/database/data/agreements/livsmedelsforetagen-livs-bageri/MEMBER-2025-04-01.json:160` | Livs/bageri: servispersonal på konditori eller café med servering kan höra till Gröna Riks i stället; det avgörs per företag och anställning. |  |  |
| 60 | `app/Modules/Agreements/database/data/agreements/livsmedelsforetagen-livs-bageri/MEMBER-2025-04-01.json:190` | Livs/bageri: om minimibeloppet för semester gäller per semesterår eller per lönekörning. |  |  |
| 61 | `app/Modules/Agreements/database/data/agreements/svenskhandel-unionen-tjansteman/MEMBER-2025-05-01.json:225` | Handels tjänstemän (Unionen): minimilönen gäller från fyllda 18 år; avtalet anger inte från vilken dag åldern räknas (PERIOD_END är ett antagande). |  |  |
| 62 | `app/Modules/Agreements/database/data/agreements/svenskhandel-unionen-tjansteman/MEMBER-2025-05-01.json:226` | Handels tjänstemän: hur tidigare anställningar i samma företag ska läggas samman ("ett års sammanlagd anställning"; här räknas från anställningsdatum). |  |  |
| 63 | `app/Modules/Agreements/database/data/agreements/svenskhandel-unionen-tjansteman/MEMBER-2025-05-01.json:229` | Handels tjänstemän: när OB-fönster överlappar (lördag efter midsommarafton träffar både 300- och 150-fönstret) anger avtalet inte vilket som gäller; antas att helgdagsfönstret ersätter det ordinarie. |  |  |
| 64 | `app/Modules/Agreements/database/data/agreements/svenskhandel-unionen-tjansteman/MEMBER-2025-05-01.json:230` | Handels tjänstemän: "till 00 närmast följande vardag" tolkas som till kl. 00.00 när närmast följande vardag börjar. |  |  |
| 65 | `app/Modules/Agreements/database/data/agreements/svenskhandel-unionen-tjansteman/MEMBER-2025-05-01.json:231` | Handels tjänstemän: löneavdrag per timme är 1/167 av månadslönen (HT § 11.3); delaren är inte satt som timlönedelare eftersom avtalet anger den för löneavdrag, inte som timlön. |  |  |
| 66 | `app/Modules/Agreements/database/data/agreements/frisorforetagarna-handels-frisor/MEMBER-2025-04-01.json:64` | Frisöravtalet: fulltexten 2025–2027 är inte publik; beloppen kommer från Handels sammanfattning (HS) och mekaniken från 2023-texten (F23). Avtalet kan inte verifieras förrän en 2025-text finns (versionen kan inte användas i lönen). |  |  |
| 67 | `app/Modules/Agreements/database/data/agreements/frisorforetagarna-handels-frisor/MEMBER-2025-04-01.json:65` | Frisöravtalet: giltighetstiden 2025-04-01 – 2027-03-31 är overifierad; HS bekräftar bara lönestegen 1 april 2025 och 1 april 2026. |  |  |
| 68 | `app/Modules/Agreements/database/data/agreements/frisorforetagarna-handels-frisor/MEMBER-2025-04-01.json:68` | Frisöravtalet: obehörigs basbelopp 2025 är 15 423 kr enligt HS men 15 433 kr på en annan Handels-sida; 15 423 kr gäller tills det verifierats. |  |  |
| 69 | `app/Modules/Agreements/database/data/agreements/frisorforetagarna-handels-frisor/MEMBER-2025-04-01.json:70` | Frisöravtalet: garantilön under provanställning (87 % av behörigas garantilön, 2023-text) saknas. |  |  |
| 70 | `app/Modules/Agreements/database/data/agreements/frisorforetagarna-handels-frisor/MEMBER-2025-04-01.json:71` | Frisöravtalet: inga OB- eller övertidskoder. OB beräknas enligt 2023-texten på 87 % av garantilönen (behöriga) eller garantilönen (obehöriga), inte på timlönen, vilket nuvarande satstyper inte kan uttrycka; 2025-texten är inte publik. |  |  |
| 71 | `app/Modules/Agreements/database/data/agreements/frisorforetagarna-handels-frisor/MEMBER-2025-04-01.json:72` | Frisöravtalet: övertid är enligt 2023-texten timlön plus 50 % på garantilönen; mertidsersättning från 2026-04-01 har en bas som inte är publik. |  |  |
| 72 | `app/Modules/Agreements/database/data/agreements/frisorforetagarna-handels-frisor/MEMBER-2025-04-01.json:73` | Frisöravtalet: delaren 173 och deltidsfaktorn 4,325 kommer från 2023-texten och måste bekräftas mot 2025-texten. |  |  |
| 73 | `app/Modules/Agreements/database/data/agreements/frisorforetagarna-handels-frisor/MEMBER-2025-04-01.json:81` | Frisöravtalet: semester 13 % inklusive provision enligt 2023-texten (overifierat); provision med garanti saknas; frisörbranschens extra pension 0,500 % (Fora) räknas inte ännu, åldersgränsen ska kontrolleras. |  |  |
| 74 | `app/Modules/Agreements/database/data/agreements/visita-unionen/MEMBER-2025-06-01.json:390` | Visita–Unionen: varje påbörjad halvtimme OB betalas som full halvtimme (VU § 21); gäller det per pass eller per period? |  |  |
| 75 | `app/Modules/Agreements/database/data/agreements/visita-unionen/MEMBER-2025-06-01.json:396` | Visita–Unionen: Unionens hängavtalsinformation för 2025 har inte hittats; ingen hängavtalsvariant finns. |  |  |
| 76 | `app/Modules/Agreements/database/data/agreements/visita-unionen/MEMBER-2025-06-01.json:410` | Visita–Unionen: kostavdrag mot Skatteverkets värde för lunch eller middag; semestertillägg 0,8 % per dag, 12,72 % av rörlig lön och 4,6 %-regeln räknas inte ännu; minsta antal timmar för jour och beredskap kontrolleras inte. |  |  |
| 77 | `app/Modules/Agreements/database/data/agreements/svenskhandel-handels-lager-ehandel/MEMBER-2025-04-01.json:150` | Lager och e-handel: inga övertidskoder; satserna (50, 70, 100 %, § 13) finns i faktabladet men det anges inte om procenten betalas utöver timlönen. |  |  |
| 78 | `app/Modules/Agreements/database/data/agreements/svenskhandel-handels-lager-ehandel/MEMBER-2025-04-01.json:151` | Lager och e-handel: avtalet anger inte från vilken dag ett nytt ålderssteg gäller; PERIOD_END är ett antagande. |  |  |
| 79 | `app/Modules/Agreements/database/data/agreements/svenskhandel-handels-lager-ehandel/MEMBER-2025-04-01.json:156` | Lager och e-handel: hängavtalets namn och eventuell avgift har inte hittats; ingen hängavtalsvariant finns. |  |  |
| 80 | `app/Modules/Agreements/database/data/agreements/svenskhandel-handels-lager-ehandel/MEMBER-2025-04-01.json:194` | Lager och e-handel: semester 13 % och garantibelopp per dag för heltid; deltid (faktor 1 796) räknas inte; förstärkt pensionsavsättning (§ 19.9): sats inte hittad. |  |  |
| 81 | `app/Modules/Agreements/database/data/agreements/visita-hrf-grona-riks/HANGAVTAL-2025-04-01.json:387` | Gröna Riks, hängavtal: grupp 1N (nattreceptionist) har bara timlön i tabellen; exakt vilka anställningar som räknas som nattreceptionist, och förhållandet till minimitimlön × 1,055 för viss nattpersonal (GR § 9 p. 1 st. 2). |  |  |
| 82 | `app/Modules/Agreements/database/data/agreements/visita-hrf-grona-riks/HANGAVTAL-2025-04-01.json:388` | Gröna Riks, hängavtal: avrundning av OB och övertid till påbörjad halvtimme tillämpas när pass delas upp; per pass eller per period? |  |  |
| 83 | `app/Modules/Agreements/database/data/agreements/visita-hrf-grona-riks/HANGAVTAL-2025-04-01.json:390` | Gröna Riks, hängavtal: övertid och mertid delas per tillfälle (a, b, c); NONOX räknar de två första timmarna per dag, före och efter passet sammanlagt. Per dag eller per tillfälle? |  |  |
| 84 | `app/Modules/Agreements/database/data/agreements/visita-hrf-grona-riks/HANGAVTAL-2025-04-01.json:432` | Gröna Riks, hängavtal: kostavdrag räknas mot Skatteverkets värde för lunch eller middag (vilket schablonvärde gäller?); mertid, röda dagar och sjuklön med OB är inte data i versionen. |  |  |
| 85 | `app/Modules/Agreements/database/data/agreements/visita-hrf-grona-riks/HANGAVTAL-2025-04-01.json:432` | Gröna Riks, hängavtal: hängavtalets administrationsavgift 0,51 % tas på samma underlag som premierna. |  |  |
| 86 | `app/Modules/Agreements/database/data/agreements/visita-hrf-grona-riks/MEMBER-2025-04-01.json:378` | Gröna Riks, medlem: avrundning av OB och övertid till påbörjad halvtimme; per pass eller per period? |  |  |
| 87 | `app/Modules/Agreements/database/data/agreements/visita-hrf-grona-riks/MEMBER-2025-04-01.json:380` | Gröna Riks, medlem: övertid och mertid per dag eller per tillfälle (de två första timmarna). |  |  |
| 88 | `app/Modules/Agreements/database/data/agreements/visita-hrf-grona-riks/MEMBER-2025-04-01.json:423` | Gröna Riks, medlem: kostavdrag mot Skatteverkets värde för lunch eller middag (vilket schablonvärde?); mertid, röda dagar och sjuklön med OB är inte data i versionen. |  |  |
| 89 | `app/Modules/Agreements/database/data/agreements/visita-hrf-grona-riks/MEMBER-2025-04-01.json:423` | Gröna Riks, medlem: noteringen om hängavtalets administrationsavgift 0,51 % (underlag som premierna) finns också i medlemsversionens not. |  |  |
| 90 | `app/Modules/Agreements/database/data/agreements/svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json:348` | Detaljhandeln: om ordinarie tid 00.00–06.00 en vardagsmorgon ska ha OB 70 % (avtalet säger "efter kl. 20.00" och har ingen natt-OB). |  |  |
| 91 | `app/Modules/Agreements/database/data/agreements/svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json:352` | Detaljhandeln: att "förändring" vid ålder betyder födelsemånaden (uppflyttning till nytt minimilönesteg från den 1:a i månaden, D1 § 7.2 C Anm. 2). |  |  |
| 92 | `app/Modules/Agreements/database/data/agreements/svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json:358` | Detaljhandeln: om övertid 00.00–06.00 en vardagsmorgon är 70 %. |  |  |
| 93 | `app/Modules/Agreements/database/data/agreements/svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json:359` | Detaljhandeln: om OB också ska dras tillbaka när övertiden tas ut som komptid (avtalet ger bara den procentuellt högsta av OB och övertid). |  |  |
| 94 | `app/Modules/Agreements/database/data/agreements/svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json:360` | Detaljhandeln: lägsta ersättningen på fridag (minst 3 timmar, § 9.1) betalas med dagens övertidssats; § 9.1 anger ingen annan sats. |  |  |
| 95 | `app/Modules/Agreements/database/data/agreements/svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json:363` | Detaljhandeln: hängavtalets namn och eventuell avgift har inte hittats; ingen hängavtalsvariant finns. |  |  |
| 96 | `app/Modules/Agreements/database/data/agreements/svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json:404` | Detaljhandeln: semester 13 % och garantibelopp per dag för heltid; för deltid (semesterlönefaktor 1 796) varnar NONOX i stället för att räkna; förstärkt pensionsavsättning (§ 17.14): sats inte hittad. |  |  |
| 97 | `app/Modules/Agreements/Support/AgreementSickPayExtension.php:69` | Avtalad sjuklön (AgreementSickPayExtension): vilka veckor som är representativa ("genomsnittligen"); om helgdagar räknas i månadens arbetstimmar (Gröna Riks); Detaljhandelns karenstid värderad per timme; kost och bostad i avtalets månadslön; deltidssjukskrivning från dag 15. |  |  |
| 98 | `app/Modules/Agreements/Support/AgreementSickPayExtension.php:355` | Avtalad sjuklön: Detaljhandelsavtalet § 13.1–13.2 s. 42–43 (noteringar om vad NONOX ännu inte räknar som avtalet säger). |  |  |
| 99 | `app/Modules/Agreements/Support/AgreementSickPayExtension.php:451` | Avtalad sjuklön: kost och bostad till Skatteverkets värde i "månadslön" (VU § 23.3, HT § 10.3.6) läses inte ur förmånerna ännu. |  |  |
| 100 | `app/Modules/Agreements/Support/PremiumBasis.php:24` | Premieunderlag (PremiumBasis): Collectums format är inte byggda; om filerna kräver avtal med Collectum (ADR-033 fråga 6) och vad "kontant bruttolön" omfattar (här: tabell- eller engångsbeskattad lön utan förmåner). |  |  |
| 101 | `app/Modules/Agreements/Domain/TimeRounding.php:13` | Tidsavrundning (TimeRounding): per avtal, om regeln gäller per pass, per tillfälle eller per period; NONOX tillämpar den per pass för OB och på periodens summa för övertid. |  |  |
| 102 | `app/Modules/Agreements/Domain/AgreementCostsStep.php:24` | Avtalskostnader (AgreementCostsStep): premieunderlaget för varje försäkring ("lönesumma"/"premieunderlag"), om TGL har tak per månad, och åldrarna för varje premie. |  |  |
| 103 | `app/Modules/Agreements/Domain/AgreementPayTerms.php:148` | Avtalad lön (AgreementPayTerms): vad garantin mäts mot när den anställda var frånvarande en del av månaden. |  |  |
| 104 | `app/Modules/Agreements/Domain/AgreementPayTerms.php:167` | Avtalad lön: vilket av Skatteverkets måltidsvärden som gäller för avtalets kost per arbetsdag (benefit_code i datat). |  |  |
| 105 | `app/Modules/Planning/PlanningSettings.php:43` | Planering (PlanningSettings): löneart för månadsavlönades tid utöver planen; null betyder att den inte skickas och visas som avvikelse (se PayrollBasis). |  |  |
| 106 | `app/Modules/Planning/PlanningSettings.php:62` | Planering: alla värden och paragrafer i arbetstidskontrollerna mot gällande lagtext i arbetstidslagen, och hur 24-timmars- och sjudagarsperioderna räknas innan kontrollerna beskrivs som mer än en varning. |  |  |
| 107 | `app/Modules/Planning/PlanningSettings.php:87` | Planering: alla värden mot AFS 2023:2 (minderåriga) med kapitel och paragraf, inklusive vilka gränser som gäller under skoltid och lov. |  |  |
| 108 | `app/Modules/Planning/Domain/PayrollBasis.php:109` | Löneunderlag från Planering (PayrollBasis): om sjukdagar räknas som ledighet enligt 7 § andra stycket arbetstidslagen (NONOX räknar all godkänd frånvaro på schemalagd dag). |  |  |
| 109 | `app/Modules/Planning/Domain/PayrollBasis.php:175` | Löneunderlag från Planering: helgdagar utan planerat pass och uppdelningen mellan mertid och övertid under dagmetod. |  |  |
| 110 | `app/Modules/Planning/Domain/OvertimeKinds.php:48` | Övertidsarter (OvertimeKinds): (1) vilka tidsavsnitt som är övertid efter nettning (högst ersatta arter först kan betala mer än avtalet kräver); (2) om Gröna Riks "två första timmarna" gäller per dag eller per tillfälle; (3) om Detaljhandelns minimum på tre timmar en fridag gäller när nettningen bara lämnar en del av dagen som övertid, och att den betalas med dagens övertidssats; (4) om OB också dras tillbaka när övertiden tas ut som komptid. |  |  |
| 111 | `app/Modules/Planning/Domain/WorkingTimeChecks.php:15` | Arbetstidskontroller (WorkingTimeChecks): förenklingar som alla är TODO på regeln själv: dygnsvila är glappet mellan två pass, veckovila och veckotimmar använder kalenderveckan (måndag 00.00), fyraveckorstaket för övertid räknas på kalenderveckor, årstaken kontrolleras inte på en plan. |  |  |
| 112 | `app/Modules/Planning/Domain/OvertimeTerms.php:29` | Övertidsvillkor (OvertimeTerms): om en sen start kvittas mot ett senare slut samma dag (NONOX räknar högst dagens nettoöverskott som övertid), minimum tre timmar för övertid som inte ansluter, och den order eller det godkännande avtalen kräver (attest av dagen räknas som godkännandet efteråt). |  |  |

### Regler med status `researched` eller okontrollerade

Alla versioner i `rules.json` importeras okontrollerade (filens `note`: "Every version is imported unverified"). Datafilerna innehåller ingen annan statusmarkering än `researched` på två regler, och koden läser inte markeringen: spärren är att regeln inte är kontrollerad under **Lön → Regler**.

| Nr | Regel | Fil | Status/kommentar | Konsultens beslut | Kommentar |
|---|---|---|---|---|---|
| R1 | `benefit.fuel.factor` (faktor 1,2) | `app/Modules/Payroll/database/data/2026/rules.json` rad 663 | `status: researched`, läst 2026-11-06; fortsätter in i 2027 (`continued`). **Se Del 5 (LON-19 till LON-26).** |  |  |
| R2 | `benefit.car.scope` (bilar efter 2021-07-01, miljöbilar efter 2022-07-01, sex år, fyra prisbasbelopp) | `2026/rules.json` rad 648 | `status: researched`, läst 2026-11-06; fortsätter in i 2027. Se LON-16. |  |  |
| R3 | Alla övriga 45 regelnycklar i 2026-filen (filen har 48 versioner av 47 nycklar) | `2026/rules.json` | Importerade okontrollerade; måste kontrolleras av en namngiven person innan användning (LON-01). |  |  |
| R4 | 13 regelnycklar i 2027-filen (14 versioner): `employer_contribution.*`, `special_payroll_tax.pension`, `sink.rate` (20 %), `prisbasbelopp` (59 600 kr), `per_diem.domestic.full/half/night`, `vacation.qualifying_absence_days`, `agi.schema_version` | `2027/rules.json` | Importerade okontrollerade; värden för lagstadgade belopp tagna ur gällande lagtext (`note`: propositionen för 2027 var inte lagd 2026-11-02). |  |  |
| R5 | 18 regler som är "pending" för 2027 (publiceras av myndigheterna senare): `inkomstbasbelopp`, `benefit.meal.free_day/breakfast/lunch_or_dinner`, `benefit.car.formula`, `per_diem.domestic.meal_reductions`, `per_diem.foreign.by_country`, `mileage.own_car_tax_free_per_mil`, `mileage.company_car_fossil/electric`, `gift.tax_free_limits`, `wellness.tax_free_limit`, `agi.deadlines`, `tax.one_off`, `pension.saf_lo`, `pension.itp1`, `insurance.fora.arbetare`, `insurance.fora.tjansteman` | `2027/rules.json` (`pending`) | Saknas medvetet i 2027-filen. En lönekörning med utbetalning 2027 stoppas tills de finns och är kontrollerade. |  |  |
| R6 | `per_diem.foreign.by_country` (utlandstraktamente) | `2026/rules.json` rad 3 (`note`: "Missing until researched") | Står i `RuleSet::REQUIRED_KEYS` men saknar värden även för 2026. Se LON-01. |  |  |
| R7 | Pensionsregler `pension.saf_lo`, `pension.itp1`, `insurance.fora.arbetare`, `insurance.fora.tjansteman` | `2026/rules.json` rad 726, 742, 794, 818 | Tillagda 2026-11-02 för Avtal; används bara av anställningar bundna till ett avtal; TODO enligt tabellen (nr 1–5). Se LON-17. |  |  |


---

## Det som inte kunde räknas i förväg

Följande har ingen uträknad siffra i checklistan; jämför mot appens egen rapport eller källan:

1. LON-14: semesterlöneskuldens belopp, sparade och utbetalda semesterdagar vid semesterårets slut, och semesterersättning vid anställningens slut. Beloppen beror på dagsvärdet i inställningen och på intjänade dagar; de kan kontrolleras mot **Lön → Semesterskuld** men inte räknas ut ur regelfilerna.
2. LON-05 gränsfallet 499,99 + 500,00 kr: det är oklart om fältet **eller belopp** tillåts på lönearten **Timlön**.
3. LON-30 b: hur ett arbetsschema med 7,65 timmar per dag läggs in; beloppet för hel dag respektive del av dag skiljer 2,36 kr.
4. LON-29 f och g: veckonettningen av övertid och Detaljhandelns samtidiga OB/övertid bygger på hur schemat läggs; siffrorna förutsätter att resten av veckan stämmer med schemat.
5. Skatt enligt tabell har lästs ur projektets kopia av tabell 33 (2026); den ska jämföras med Skatteverkets publicerade tabell.
6. 2027: belopp som Regeringen/Skatteverket inte publicerat (se R5) kan inte räknas.

---

## Avvikelser

| Nr | Fall | Beskrivning | Allvarlighet | Åtgärd |
|---|---|---|---|---|
|  |  |  |  |  |
|  |  |  |  |  |
|  |  |  |  |  |
|  |  |  |  |  |
|  |  |  |  |  |

---

## Godkännande

Modulen Lön (inklusive arbetsgivardeklaration, Avtal och OB/övertid i Planering) är granskad och godkänd för att tas ur Beta.

Godkännandet gäller bara den här modulen och bara den NONOX-version som anges nedan. Drivmedelsförmånen (Del 5, regeln `benefit.fuel.factor`) ska ha ett eget ställningstagande här:

- Drivmedelsförmånens regel (faktor 1,2, avgift på marknadsvärdet) är kontrollerad mot K1–K4: [ ] Ja  [ ] Nej, se Avvikelser

| Fält | Värde |
|---|---|
| Namn | ____________________ |
| Titel/auktorisation | ____________________ |
| Datum | ____________________ |
| NONOX-version | ____________________ |
| Underskrift | ____________________ |

- [ ] Godkänd
- [ ] Godkänd med villkor (ange villkoren): ______________________________________________
- [ ] Ej godkänd (ange skäl): ______________________________________________
