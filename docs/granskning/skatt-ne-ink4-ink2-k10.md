# Granskningschecklista: Inkomstdeklaration (INK2, NE, INK4, K10 och SRU-filer)

| | |
|---|---|
| Modul | Inkomstdeklaration ur bokföringen: INK2/INK2R/INK2S för aktiebolag och ekonomiska föreningar, NE-bilagan för enskild firma, INK4 för handelsbolag och kommanditbolag, K10-underlag för ägare i fåmansföretag, samt SRU-filerna (INFO.SRU och BLANKETTER.SRU) till Skatteverket |
| Version av NONOX som granskas | ______________________ (fylls i av granskaren) |
| Granskare (namn) | ______________________ |
| Datum | ______________________ |
| Kod/ADR som testas | app/Domain/IncomeTax/ (Ink2.php, Ink2Codes.php, Ne.php, NeCodes.php, Ink4.php, Ink4Codes.php, K10.php, K10Parameters.php, K10Books.php, K10Sheet.php, K10Codes.php, TaxAllocationReserves.php, IncomeTaxParameters.php, Periods.php), app/Actions/IncomeTax/ (IncomeTaxReturns.php, BusinessIncomeTaxReturns.php, K10Worksheets.php), app/Support/Sru/SruFile.php, database/data/income-tax/2026.json och 2027.json, database/data/k10/2026.json, database/data/corporate-tax/rules.json, docs/adr/ADR-053-ne-and-ink4.md, docs/adr/ADR-056-tax-in-the-closing.md, docs/adr/ADR-062-k10-underlag.md |
| Manualavsnitt | docs/manual/sv/bokslut/inkomstdeklaration.md, docs/manual/sv/bokslut/k10.md, docs/manual/sv/bokslut/bokslutsverifikationer.md |

Skatten som föreslås och bokförs i bokslutet (8910/2512, periodiseringsfond) granskas i docs/granskning/bokslut.md. Den här listan gäller deklarationsunderlaget: fält för fält, justeringar, beräkningar och filerna.

## Förutsättningar

- **Testbolag:** "Testbolag Skatt AB", påhittat organisationsnummer 556999-0012 (klarar Luhn). Enskild firma: påhittat personnummer 700101-1233 (klarar Luhn, påhittat; byt vid behov till annat påhittat nummer). Inga riktiga personnummer i testet.
- **Räkenskapsår 2026-01-01 till 2026-12-31** (beskattningsperiod 2026P4) om inget annat anges. Aktiebolag med K2, enskild firma med K1 eller K2, handelsbolag med K2.
- **Databas:** egen testdatabas. Kör aldrig `migrate:fresh`, `db:wipe` eller tester med `RefreshDatabase` mot den.
- **Användare:** Redovisningskonsult (rätt `fiscal-years:manage`). Deklarationen finns under **Bokföring → Inkomstdeklaration** (/inkomstdeklaration). K10 finns på /inkomstdeklaration/k10.
- **Fältkoder och perioder:** bara beskattningsperioden 2026P4 är kontrollerad i koden (`VERIFIED_PERIODS = ['2026P4']` i Ink2Codes, Ink4Codes, NeCodes och K10Codes). Det är den period som ett kalenderår som slutar 2026-12-31 hamnar i, och även år som slutar 2026-09-01 till 2026-12-31 (period P4 = september-december). Andra perioder ger ingen SRU-fil.
- **Parametrar:** NE kräver parameterfilen database/data/income-tax/{år}.json; ingen fil är markerad `verified: true` (beslut 2026-11-06). 2026 är framtagen 2026-11-05 men inte kontrollerad, och NE och INK4 för 2026 är spärrade i drift tills konsulten har kontrollerat värdena i fall 16 nedan och flaggan sätts till `true` med namn och datum. 2027 är också spärrad. K10 kräver database/data/k10/{år}.json; endast 2026 (status `researched`).
- **Alla skattesatser och tal** i den här listan är hämtade från kodens datafiler. Samtliga har status `researched`, alltså inte juridiskt kontrollerade, och ska behandlas som "Konsulten verifierar".
- **Belopp** i hela kronor om inget annat anges.

## Fall

### SKA-01 INK2 grundfall: aktiebolag med bokförd skatt
- Förutsättningar: "Testbolag Skatt AB", K2. Resultat före skatt 500 000 kr (intäkt 600 000, kostnad 100 000). Skatt bokförd i bokslutet: 8910 D 103 000 mot 2512 K 103 000 (20,6 %). Resultatet efter skatt 397 000 är bokat (8999 mot 2099). Inga justeringar och inga periodiseringsfonder.
- Steg i appen:
  1. **Bokföring → Inkomstdeklaration**, välj 2026.
  2. Kontrollera **Bokfört resultat** och **Bokförd skatt**.
  3. Klicka **Spara** utan justeringar. Läs **Överskott av näringsverksamhet** och **Deklarationen, fält för fält**.
- Förväntat resultat (app/Domain/IncomeTax/Ink2.php, Ink2Codes.php):
  - Bokfört resultat efter skatt 397 000 (fält 7450, årets vinst); skatt 103 000 (7651, INK2S 4.3 a); bokfört resultat i INK2S 7650 = 397 000. Skatten läggs tillbaka automatiskt.
  - Skattemässigt överskott (7670) = 397 000 + 103 000 = 500 000. På INK2 sida 1: 7104 Överskott = 500 000. Datum 7011 = 20260101 och 7012 = 20261231.
  - Nollvärden utelämnas ur filen.
  - Konsulten verifierar: att fälten 7450, 7650, 7651, 7670 och 7104 stämmer med Skatteverkets fältförteckning för beskattningsperiod 2026P4 (INK2R/INK2S SKV 2002-34-01-26-04) och att INK2R:s balansräkning (BAS-intervallen) är kontrollerad mot BAS SRU-koppling; balansintervallen är inte kontrollerade (TODO i app/Domain/IncomeTax/Ink2Codes.php rad 16).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-02 INK2: justeringar som föreslås ur bokföringen och ifylld justering som ersätter förslaget
- Förutsättningar: Som SKA-01, men bokföringen innehåller 6072 Representation, ej avdragsgill 4 000 kr (kostnad) och 8314 Skattefria ränteintäkter 1 500 kr (intäkt) i resultatet. Resultat före skatt 500 000, bokförd skatt 103 515 (se BOK-15 i bokslut.md), resultat efter skatt 396 485.
- Steg i appen:
  1. Öppna Inkomstdeklaration och läs fälten **Andra bokförda kostnader som inte ska dras av** och **Andra bokförda intäkter som inte ska tas upp**.
  2. Lämna dem tomma, spara, läs överskottet.
  3. Skriv 0 i **Andra bokförda kostnader som inte ska dras av** och spara.
- Förväntat resultat:
  - Förslag: 7653 = 4 000 (6072 + 6982 + 6992 + 8423), 7754 = 1 500 (8314).
  - Skattemässigt överskott = 396 485 + 103 515 + 4 000 - 1 500 = 502 500 (7670).
  - Med 0 ifyllt i 7653 (ifyllt belopp ersätter förslaget, även 0): 396 485 + 103 515 - 1 500 = 498 500.
  - Fält som är tomma behåller förslaget, 0 är ett eget värde.
  - Konsulten verifierar: att kontona 6072, 6982, 6992 och 8423 bara innehåller icke avdragsgilla kostnader och 8314 bara skattefria intäkter (TODO i app/Domain/IncomeTax/Ink2.php rad 212; IL 16 kap. 2 §, 9 kap. 8 och 9 §§, 8 kap. 7 §).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-03 INK2: övriga justeringar och tecken
- Förutsättningar: Som SKA-01 (skattemässigt utgångsöverskott 500 000).
- Steg i appen: Fyll i justeringarna nedan och spara.

| Fält | Etikett i appen | Belopp | Tecken |
|---|---|---|---|
| 7652 | Nedskrivning av finansiella tillgångar som inte ska dras av | 2 000 | + |
| 7753 | Utdelning som inte ska tas upp | 5 000 | - |
| 7665 | Andra ej bokförda intäkter som ska tas upp | 1 000 | + |
| 7764 | Andra ej bokförda kostnader som ska dras av | 3 000 | - |
| 7763 | Outnyttjat underskott från föregående år | 10 000 | - |

- Förväntat resultat:
  - Skattemässigt överskott = 500 000 + 2 000 - 5 000 + 1 000 - 3 000 - 10 000 = 485 000.
  - Negativa belopp kan inte skrivas med minustecken (fält utan minustecken).
  - Justeringar ändrar inte bokföringen; de sparas för året och ändringar skrivs i behandlingshistoriken (`income_tax.saved`, belopp som fältnamn och summor).
  - Konsulten verifierar: att bara de åtta listade justeringarna finns (TODO i app/Domain/IncomeTax/Ink2.php rad 28: delägarrätter, andelar i handelsbolag m.fl. punkter på INK2S fylls i hos Skatteverket) och att tecknen stämmer mot INK2S.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-04 INK2: schablonintäkt på periodiseringsfonder (fält 7654)
- Förutsättningar: Som SKA-01. Ingående balans 2026-01-01 på 2120 Periodiseringsfond 2020: kredit 100 000 kr.
- Steg i appen: Läs fältet **Beräknad schablonintäkt på periodiseringsfonder** utan ifyllt värde. Gör om med ett kortare räkenskapsår 2026-01-01 till 2026-09-30 (9 månader), med ett räkenskapsår som slutar 2027-12-31, och med värdet 0 ifyllt.
- Förväntat resultat (app/Domain/IncomeTax/TaxAllocationReserves.php; IL 30 kap. 6 a §):
  - Helår 2026: statslåneräntan 30 november 2025 = 2,55 %: 100 000 x 2,55 % = 2 550 kr (räknat som (10 000 000 öre x 255 x 12 + 60 000) / 120 000 = 255 000 öre).
  - Nio månader: (10 000 000 x 255 x 9 + 60 000) / 120 000 = 191 250,5 öre, nedåt 191 250 öre = 1 912,50 kr, som fältet visar avrundat halvt uppåt: 1 913 kr. (Beskattningsperiod för räkenskapsår som slutar 2026-09-30: 2026P4.)
  - Räkenskapsår som slutar 2027-12-31: statslåneräntan för 2026 finns inte i NONOX: varning "Företaget har periodiseringsfonder vid årets början, men statslåneräntan för året finns inte här. Räkna fram schablonintäkten (fält 7654) och fyll i den." och inget SRU (2027P4 inte kontrollerad).
  - Lägsta ränta 0,5 % (50 hundradels procent) tillämpas om räntan är lägre. Fält 0 ifyllt ersätter förslaget.
  - Schablonintäkten bokas aldrig, den rapporteras bara på INK2S 4.6 a.
  - Konsulten verifierar: statslåneräntan 1,96 % (nov 2024) och 2,55 % (nov 2025) mot Skatteverket, att fondens ingående saldo är lika med gjorda avdrag, avrundningen och månadsberäkningen vid avvikande längd (TODO i app/Domain/IncomeTax/TaxAllocationReserves.php rad 28).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-05 INK2: pensionskostnader och underlag för särskild löneskatt
- Förutsättningar: Som SKA-01. Konto 7410 Pensionsförsäkringspremier D 120 000 kr under 2026.
- Steg i appen: Läs **Underlag för särskild löneskatt på pensionskostnader** och **Pensionskostnader (som ingår i p. 3.8)**. Gör om med 7410 K 20 000 netto (kreditsaldo i intervallet 7400-7499). Fyll i 7132 manuellt med 100 000 och läs 7133.
- Förväntat resultat:
  - Debetsaldo 120 000: 7132 = 120 000 och 8022 = 120 000. Skattemässigt överskott ändras inte (fälten är bara information).
  - Kreditsaldo 20 000: 7133 (negativt underlag) = 20 000, 7132 tomt, 8022 = 0.
  - Med 7132 ifyllt föreslås inget 7133 (bara en av dem får fyllas i).
  - Står pensionskostnader i 8022 men underlaget är tomt: varning "Pensionskostnader redovisas i punkt 4.21, men underlaget för särskild löneskatt (punkt 1.4 eller 1.5) är tomt. Kontrollera underlaget."
  - NONOX räknar inte själva särskilda löneskatten.
  - Konsulten verifierar: att underlaget enligt lag (1991:687) 2 § inte alltid är den bokförda pensionskostnaden (tryggandelagen-avsättningar, administrationsavgifter, gruppsjukförsäkring) (TODO i app/Domain/IncomeTax/Ink2.php rad 207).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-06 INK2: underskott
- Förutsättningar: Bokfört resultat före skatt -80 000 kr, ingen skatt.
- Steg i appen: Öppna Inkomstdeklaration, spara, läs resultatet och fälten.
- Förväntat resultat: INK2R förlust (7550) = 80 000. INK2S 7750 (förlust) = 80 000 och 7770 (underskott) = 80 000. INK2 sida 1: 7114 Underskott = 80 000. Sidan visar **Underskott av näringsverksamhet**. Underskottet kan nästa år föras in i 7763.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-07 INK2: SRU-filer, innehåll och form
- Förutsättningar: SKA-01 sparad. Företaget har postnummer, ort och organisationsnummer ifyllda.
- Steg i appen:
  1. Klicka **Ladda ner SRU-filer** och packa upp ZIP-filen.
  2. Öppna INFO.SRU och BLANKETTER.SRU i en textredigerare som visar teckenkodning.
  3. Pröva utan ort, och med organisationsnummer med färre än tio siffror.
  4. Pröva som Bokförare.
- Förväntat resultat (app/Support/Sru/SruFile.php, app/Actions/IncomeTax/IncomeTaxReturns.php):
  - INFO.SRU: raderna `#DATABESKRIVNING_START`, `#PRODUKT SRU`, `#SKAPAD <ÅÅÅÅMMDD TTMMSS>`, `#PROGRAM NONOX Ekonomisystem`, `#FILNAMN BLANKETTER.SRU`, `#DATABESKRIVNING_SLUT`, `#MEDIELEV_START`, `#ORGNR 165569990012`, `#NAMN <bolagets namn>`, `#ADRESS ...`, `#POSTNR <siffror>`, `#POSTORT ...`, `#EMAIL`/`#TELEFON` om ifyllda, `#MEDIELEV_SLUT`.
  - BLANKETTER.SRU: tre block `#BLANKETT INK2-2026P4`, `#BLANKETT INK2R-2026P4`, `#BLANKETT INK2S-2026P4`, vart och ett med `#IDENTITET 165569990012 <datum tid>`, `#NAMN ...`, `#UPPGIFT <kod> <belopp>` och `#BLANKETTSLUT`; filen slutar `#FIL_SLUT`. Exempel: `#UPPGIFT 7011 20260101`, `#UPPGIFT 7012 20261231`, `#UPPGIFT 7104 500000`, `#UPPGIFT 7670 500000`.
  - Teckenkodning ISO 8859-1, varje post på en egen rad slutad med LF (inte CRLF), belopp i hela kronor utan inledande nollor, texter utan radbrytningar. Å, ä, ö i bolagsnamnet ska vara byte E5, E4, F6.
  - Ort saknas: "Fyll i företagets postnummer och ort under Företagsuppgifter först." Organisationsnumret saknas eller har fel längd: "Företagets organisationsnummer behövs för deklarationen."
  - Bokförare nekas. Varje nedladdning skrivs i behandlingshistoriken (`income_tax.sru_exported` med period).
  - Konsulten verifierar: att filstrukturen och identitetsraden stämmer med Skatteverkets "Teknisk information om filöverföring" (aktuell version) och att filerna går igenom e-tjänsten Filöverföring utan fel (görs av granskaren med en riktig inloggning; kunde inte provas här). Kunde inte beräkna: mottagarens validering.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-08 Beskattningsperiod som inte är kontrollerad
- Förutsättningar: Fem testbolag med räkenskapsår som slutar enligt tabellen (inte samma bolag).
- Steg i appen: Klicka **Ladda ner SRU-filer** för varje.
- Förväntat resultat (app/Domain/IncomeTax/Periods.php: P1 januari-april, P2 maj-juni, P3 juli-augusti, P4 september-december; endast 2026P4 är kontrollerad):

| Räkenskapsår slutar | Period | Förväntat |
|---|---|---|
| 2025-12-31 | 2025P4 | Nekas: "Fältkoderna för beskattningsperiod 2025P4 är inte kontrollerade ännu. Fyll i deklarationen i Skatteverkets e-tjänst." |
| 2026-04-30 | 2026P1 | Nekas (2026P1) |
| 2026-06-30 | 2026P2 | Nekas (2026P2) |
| 2026-08-31 | 2026P3 | Nekas (2026P3) |
| 2026-09-30 eller 2026-12-31 | 2026P4 | Fil skapas |
| 2027-12-31 | 2027P4 | Nekas |

  - Siffrorna på sidan visas ändå, så att användaren kan fylla i deklarationen i e-tjänsten.
  - Konsulten verifierar: att 2026P4 är den enda period vars fältkoder kontrollerats och att det är rätt att nekas för övriga, samt att räkenskapsår som slutar före 2026-09-01 inte får fil ens om deras koder är oförändrade.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-09 INK2R mot BAS: stickprov av kontokopplingen
- Förutsättningar: Ett testbolag med ett konto i varje rad nedan, belopp 1 000 kr. Resultat- och balanskonton valda enligt tabellen.
- Steg i appen: Spara och läs **Deklarationen, fält för fält**. Kontrollera varje fältkod.
- Förväntat resultat (app/Domain/IncomeTax/Ink2Codes.php):

| Konto | Förväntat INK2R-fält |
|---|---|
| 1220 Inventarier (D) | 7215 Maskiner, inventarier |
| 1510 Kundfordringar (D) | 7251 Kundfordringar |
| 1930 Bank (D) | 7281 Kassa och bank |
| 2081 Aktiekapital (K) | 7301 Bundet eget kapital |
| 2350 Banklån (K) | 7352 Skulder till kreditinstitut |
| 2440 Leverantörsskulder (K) | 7365 Leverantörsskulder |
| 2512 Beräknad inkomstskatt (K) | 7368 Skatteskulder |
| 3010 Försäljning (K) | 7410 Nettoomsättning |
| 4010 Inköp (D) | 7511 Råvaror och förnödenheter |
| 5010 Lokalhyra (D) | 7513 Övriga externa kostnader |
| 7010 Löner (D) | 7514 Personalkostnader |
| 8410 Räntekostnader (D) | 7522 Räntekostnader |
| 8910 Skatt (D) | 7528 Skatt på årets resultat |

  - Fritt eget kapital inklusive årets resultat: 7302.
  - Kontona 4900-4909, 4930-4939 och 4990-4999 behandlas olika i årsredovisningen och i deklarationen: i INK2R ligger de i förändring av lager (7411/7510) respektive 4980-4989 i handelsvaror (7512), medan årsredovisningens K2-uppställning (K2Schedule) har 4900-4939 och 4980-4999 som Råvaror och förnödenheter. Granskaren kontrollerar vilket som är rätt.
  - Konsulten verifierar: att kontointervallen följer BAS egen SRU-koppling för INK2 (INK2_P1_intervall-241119); balansräkningens intervall är kontrollerade mot BAS kontogrupper men inte mot den tabellen, och 48xx, 4921-4929, 85xx-87xx, 880x och 8812-8818 hålls i närmaste rad (TODO i app/Domain/IncomeTax/Ink2Codes.php rad 16).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-10 NE-bilagan: grundfall med räntefördelning, periodiseringsfond och egenavgifter
- Förutsättningar: Enskild firma (personnummer 700101-1233, född 1970), K1 eller K2, räkenskapsår 2026-01-01 till 2026-12-31. Bokfört resultat 400 000 kr. Parametrar för 2026 (statslåneränta 2,55 %). Verksamheten har inte startat i år.
- Steg i appen:
  1. **Bokföring → Inkomstdeklaration**, välj 2026. Under **Om dig och verksamheten**, välj **Du är född på** 1900-talet.
  2. Under **Räntefördelning (R30, R31)**, fyll i **Kapitalunderlag för räntefördelning** 300 000 och **Positiv räntefördelning du väljer att göra** 25 650.
  3. Under **Periodiseringsfond (R32, R34)**, fyll i avsättning 112 305.
  4. Spara och läs **NE-bilagan, fält för fält**.
- Förväntat resultat (app/Domain/IncomeTax/Ne.php, database/data/income-tax/2026.json):
  - Positiv fördelningsränta = statslåneräntan 2,55 % + 6 procentenheter = 8,55 %. Högsta positiva räntefördelning = 300 000 x 8,55 % = 25 650 (visas under "Du kan räntefördela högst").
  - R30 = 25 650 (fält 7708). R33 = 400 000 - 25 650 = 374 350. Högsta periodiseringsfond = 30 % x 374 350 = 112 305 (avrundat nedåt), R34 = 112 305 (fält 7709). R35 = 374 350 - 112 305 = 262 045.
  - Ingen expansionsfond: R42 = 262 045. Schablonavdrag för egenavgifter (född 1970, 25 %): 262 045 x 25 % = 65 511,25 => 65 511 (R43, fält 7714). Överskott (R47, fält 7630) = 262 045 - 65 511 = 196 534.
  - Datum 7011 = 20260101, 7012 = 20261231. Personnummer i filen tolv siffror: 197001011233.
  - Konsulten verifierar: att 8,55 % är rätt (statslåneränta + 6 procentenheter, IL 33 kap. 3 §), att taket 30 % för periodiseringsfond gäller enskild näringsidkare (IL 30 kap. 6 §), och avrundningen nedåt för tak (TODO i app/Domain/IncomeTax/Ne.php rad 35).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-11 NE-bilagan: negativ räntefördelning (gränsfall) och sparat belopp
- Förutsättningar: Som SKA-10, men kapitalunderlaget och sparat belopp enligt tabellen. Negativ fördelningsränta = max(statslåneräntan 2,55 %; 0,5 %) + 1 procentenhet = 3,55 %. Tröskeln är -500 000 kr.
- Steg i appen: Fyll i **Kapitalunderlag för räntefördelning** och **Sparat fördelningsbelopp från förra året** enligt tabellen och läs R30 och R31.
- Förväntat resultat:

| Kapitalunderlag | Sparat från förra året | Positivt max (från underlag + sparat) | R31 (negativ) | Sparas till nästa år (8011) |
|---|---|---|---|---|
| -500 000 | 0 | 0 | 0 (inte lägre än -500 000) | 0 |
| -500 001 | 0 | 0 | 500 001 x 3,55 % = 17 750,04 => 17 750 | 0 |
| -600 000 | 0 | 0 | 600 000 x 3,55 % = 21 300 | 0 |
| -600 000 | 5 000 | 0 | 21 300 - 5 000 = 16 300 | 0 |
| -600 000 | 30 000 | 30 000 - 21 300 = 8 700 | 0 | 8 700 |
| 3 000 000 (resultat 100 000, valt belopp 150 000) | 0 | 3 000 000 x 8,55 % = 256 500 | 0 | R30 begränsas till 100 000; sparas 256 500 - 100 000 = 156 500 |
| 300 000, första året med verksamheten | 0 | 0 (ingen positiv räntefördelning första året) | 0 | 0 |

  - Negativ räntefördelning är tvingande under -500 000 och räknas även om användaren inte fyllt i något; saknas underlag men förra årets balans tyder på lågt underlag: varning. R31 adderas till inkomsten i R33.
  - Positiv räntefördelning är aldrig förvald: utan val blir R30 = 0 och hela maxbeloppet sparas.
  - Det finns ingen golvgräns på 50 000 kr (borttagen genom 2024:1131 för år som börjar efter 2024-12-31; Skatteverkets broschyr SKV 306 utg. 17 nämner den fortfarande).
  - Konsulten verifierar: tröskeln -500 000 (IL 33 kap. 4 §), att sparat belopp får kvittas mot negativt fördelningsbelopp (33 kap. 7 §), att ett sparat belopp efter negativ räntefördelning får användas för positiv (endast SKV 2196 säger det), och första-årsregeln (Skatteverkets ställningstagande 2026-01-26, dnr 8-16818-2026). TODO i app/Domain/IncomeTax/Ne.php rad 35.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-12 NE-bilagan: schablonavdrag för egenavgifter efter ålder och typ
- Förutsättningar: R42 = 262 045 (som SKA-10) för varje rad. Parametrar 2026.
- Steg i appen: För varje rad: ändra födelseår/århundrade (personnumret) och rutorna för passiv verksamhet och hel ålderspension/sjukersättning, och läs R43.
- Förväntat resultat (Ne::selfEmploymentRate; ålder = 2026 - födelseår - 1 för år 2026):

| Förutsättning | Sats | R43 |
|---|---|---|
| Född 1970, aktiv | 25 % | 262 045 x 25 % = 65 511 |
| Född 1959, aktiv (ålder 66) | 25 % | 65 511 |
| Född 1958, aktiv (ålder 67) | 10 % | 262 045 x 10 % = 26 204,5 => 26 204 |
| Född 1955, aktiv | 10 % | 26 204 |
| Född 1937, aktiv | 0 % | 0 (betalar inga egenavgifter) |
| Född 1970, passiv näringsverksamhet | 20 % | 262 045 x 20 % = 52 409 |
| Född 1965, har hel ålderspension | 10 % | 26 204 |
| Född 1937, passiv | okänt | Fältet lämnas för manuell ifyllnad (null) |

  - R43 kan skrivas över manuellt. Saknas födelseår/århundrade eller parametrar för året: R43 räknas inte.
  - Konsulten verifierar: att ålderskontrollen (födelseår, inte födelsedag), 25 % / 10 % / 20 %, och särregeln för född 1937 eller tidigare stämmer med IL 16 kap. 30 §, SAL 3 kap. 15 och 16 §§ och övergångsbestämmelser (TODO i app/Domain/IncomeTax/Ne.php rad 420). Notera särskilt person född 1958 vars 67-årsdag ligger sent på året 2025 jämfört med den som fyller 67 efter 1 januari 2026.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-13 NE-bilagan: expansionsfond
- Förutsättningar: Som SKA-10. R35 = 262 045 före expansionsfond.
- Steg i appen: Under **Expansionsfond (R36, R37)**: fyll i kapitalunderlag vid årets slut, fond vid årets början och ökning/minskning enligt tabellen.
- Förväntat resultat (IL 34 kap., tak 125,94 %):

| Kapitalunderlag | Fond vid årets början | Önskad ökning | Resultat |
|---|---|---|---|
| 200 000 | 100 000 | 200 000 | Tak 200 000 x 125,94 % = 251 880; utrymme 251 880 - 100 000 = 151 880; R36 = 151 880 (begränsas, och högst R35 262 045) |
| 50 000 | 100 000 | 0 | Tak 50 000 x 125,94 % = 62 970; fonden är för stor; tvingande återföring (R37) = 100 000 - 62 970 = 37 030 |
| 0 eller negativt | 100 000 | 0 | Tak 0; hela fonden, 100 000, återförs |

  - Expansionsfondsskatt 20,6 % (parametern i datafilen) redovisas enligt sidans beskrivning.
  - Konsulten verifierar: taket 125,94 % (IL 34 kap. 6 och 7 §§, 2026), och avrundning nedåt av tak.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-14 NE-bilagan: SRU-fil, parametrar saknas och inkomst-År utan kontroll
- Förutsättningar: Enskild firma, SKA-10 sparad. Dessutom ett räkenskapsår som slutar 2027-12-31.
- Steg i appen: Klicka **Ladda ner NE-bilagan som SRU-fil**. Öppna BLANKETTER.SRU. Pröva utan århundrade och för 2027.
- Förväntat resultat:
  - Blocket `#BLANKETT NE-2026P4` med `#IDENTITET 197001011233 ...` (tolv siffror), fält som `#UPPGIFT 7630 196534` (överskott), `#UPPGIFT 7708 25650`, `#UPPGIFT 7709 112305`, `#UPPGIFT 7714 65511`, 7011/7012-datum; ingen K1-flagga 7023 om K1, annars `7023 X`. Filen har samma form som INK2-filen (SKA-07).
  - Utan århundrade: "Ange vilket århundrade du är född på, så att personnumret blir rätt i filen." Personnumret som inte klarar Luhn: "Personnumret stämmer inte. Skriv tolv siffror med århundradet, till exempel 19121212-1212."
  - 2027: "Procentsatserna för inkomstår 2027 är inte kontrollerade i NONOX än, så NE-bilagan kan inte räknas klart. Fyll i den i Skatteverkets e-tjänst." (parameterfilen är `verified: false`).
  - Århundradet sparas krypterat; behandlingshistoriken visar fältnamn, aldrig belopp.
  - Konsulten verifierar: att NE importeras via Inkomstdeklaration 1 (Bilagor, Importera) och inte via fristående filöverföring (en fil med enbart näringsuppgifter kan inte kompletteras), och värdena i 2026-filen (fall 16 i tabellen nedan); först därefter sätts `verified: true` (ADR-053).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-15 INK4 för handelsbolag: delägarnas andelar och avrundning
- Förutsättningar: Handelsbolag (testbolag, K2), 2026. Bokfört resultat 100 001 kr. Inga justeringar. Delägare 1 (60 %) och 2 (40 %) med nummer 1 och 2 (BAS-block 2010-2019 respektive 2020-2029). Personnummer: 197001011233 respektive 196501011230 (påhittade, klarar Luhn) eller delägare som företag med organisationsnummer.
- Steg i appen:
  1. **Bokföring → Inkomstdeklaration**. Under **Delägare**, **Lägg till delägare** för varje, **Spara delägarna**.
  2. Läs **Deklarationen, fält för fält** med en INK4DU per delägare.
  3. Gör om med tre delägare 33,33 / 33,33 / 33,34 och resultat 100 000, samt med resultat -100 001 (underskott), och med andelar som inte blir 100 %.
- Förväntat resultat (app/Domain/IncomeTax/Ink4.php, `ink4du`):
  - Delägare 1: 100 001 x 60 % = 60 000,6 => 60 000 (nedåt). Delägare 2 (sista) tar resten: 100 001 - 60 000 = 40 001. Summa 100 001.
  - Tre delägare, resultat 100 000: 100 000 x 33,33 % = 33 330 respektive 33 330, sista får 100 000 - 66 660 = 33 340. Summa 100 000.
  - Underskott -100 001: samma fördelning på beloppen 60 000 och 40 001 i fält 8102 (underskott); överskott i 8101.
  - Andelar som inte blir 100 %: knappen för SRU-filer visas inte.
  - Fält per delägare: 8100 delägarens identitet, 8101/8102 andel, 8103 tillskott, 8104 uttag, 8105 avdrag arbetsgivaravgift.
  - Handelsbolaget betalar ingen skatt själv; 8900-8989 har ingen rad på INK4R. Personnummer och organisationsnummer kontrolleras (Luhn) och sparas krypterade; felmeddelanden: "Personnumret stämmer inte ..." / "Organisationsnumret stämmer inte ...", "Andelen ska vara mellan 0,01 och 100 %.".
  - Konsulten verifierar: fördelningen med rest på sista delägaren (IL 5 kap.; TODO i app/Domain/IncomeTax/Ink4.php rad 34), och att fördelningen enligt avtalet (inte bara andelstal) är det som Skatteverket förväntar.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-16 INK4: räkenskapsschema, SRU och vad NONOX inte gör
- Förutsättningar: Handelsbolag med SKA-15 och 74xx-pensionskostnad 50 000 kr.
- Steg i appen: Läs INK4R, INK4S och varningar. Ladda ner SRU-filer. Läs texten om N3A, N3B och första sidan.
- Förväntat resultat:
  - INK4S 8022 Pensionskostnader föreslås: 50 000.
  - Varning att pensionskostnader eller fastighetsskatt kräver första sidan av INK4, som inte kan lämnas med filöverföring.
  - BLANKETTER.SRU innehåller `#BLANKETT INK4R-2026P4`, `INK4S-2026P4` och en `INK4DU-2026P4` per delägare, samma uppbyggnad som SKA-07.
  - NONOX tar inte fram N3A (fysisk person) eller N3B (juridisk person): där görs räntefördelning, periodiseringsfond och egenavgifter. Högst fyra delägare i BAS-blocken (nummer 1-4). Ändring av delägare i stängt år: "Räkenskapsåret är stängt. Delägarna för året kan inte ändras, eftersom de hör till årets deklaration."
  - Konsulten verifierar: att BAS SRU-kopplingen 2024 (INK4_P1 Intervall-241119) gäller för 2026 och att 48xx, 4921-4929 och 85xx-87xx i närmaste rad är rätt (TODO i app/Domain/IncomeTax/Ink4Codes.php rad 17).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-17 K10: gränsbelopp (reglerna från inkomstår 2026)
- Förutsättningar: Aktiebolag (fåmansföretag), 100 aktier. Ägare 1 äger 100 aktier. Löneunderlag året före (2025): 1 000 000 kr. Ägarens egen kontanta ersättning 2025: 600 000 kr. Omkostnadsbelopp 500 000 kr. Sparat utdelningsutrymme 0. Inga andra fåmansföretag (0 %). Ingen utdelning. Parametrar för 2026: inkomstbasbelopp 2025 = 80 600 kr, 2026 = 83 400 kr, statslåneränta 2,55 %.
- Steg i appen:
  1. **Bokföring → Inkomstdeklaration**, länken **K10-underlag för ägarna**, välj **Inkomstår 2026**.
  2. Under **Företaget 2026**, fyll i **Antal aktier i företaget vid årets ingång** 100 och kryssa **Företaget är ett fåmansföretag och ägarnas aktier är kvalificerade**. **Spara året**.
  3. **Lägg till ägare**: namn "Ägare Test", aktier 100. Under **Det bara ägaren vet**: omkostnadsbelopp 500 000, sparat utdelningsutrymme 0, kontant ersättning 600 000, andra fåmansföretag 0. **Spara ägaren**.
  4. Klicka **Visa underlaget**.
- Förväntat resultat (app/Domain/IncomeTax/K10.php, K10Parameters.php, database/data/k10/2026.json):
  - 1.1 Grundbelopp = 4 x 80 600 = 322 400 (fördelat på alla aktier: 100 av 100 = 322 400).
  - Lönebaserat utrymme: 4.9 = 1 000 000 x 100/100 = 1 000 000. 4.10 = 8 x 80 600 = 644 800. 4.11 = 1 000 000 - 644 800 = 355 200. 4.12 = 50 % = 177 600. Tak 50 x 600 000 = 30 000 000, inte bindande. 1.2 = 177 600.
  - 1.3 Ränta på omkostnadsbelopp: (500 000 - 100 000) x (2,55 % + 9 procentenheter = 11,55 %) = 400 000 x 11,55 % = 46 200.
  - 1.4 Sparat = 0. 1.6 Gränsbelopp = 322 400 + 177 600 + 46 200 + 0 = 546 200.
  - Utan utdelning sparas hela 546 200 till nästa år.
  - Konsulten verifierar: att grundbelopp 4 inkomstbasbelopp, lönedel 50 % över 8 inkomstbasbelopp, omkostnadsränta statslåneränta + 9 procentenheter över 100 000 kr, och 90 inkomstbasbelopp-gräns stämmer med IL 57 kap. 11, 16 och 20 a §§ i lydelse SFS 2025:1361 (status `researched`), samt att inkomstbasbeloppen (80 600 för 2025, 83 400 för 2026) är rätt.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-18 K10: utdelning mot gränsbeloppet
- Förutsättningar: SKA-17. Beslutad utdelning bokförd på 2898 under 2026 (eller ifylld manuellt under **Utdelning från företaget under 2026**). Gränsbelopp 546 200.
- Steg i appen: Fyll i utdelningen enligt tabellen och läs punkterna 2.1-2.10 och **Sparat utdelningsutrymme till nästa år**.
- Förväntat resultat (app/Domain/IncomeTax/K10.php; kapital = 2/3):

| Utdelning | 2.3 (tjänst, över gräns, högst 90 x 83 400 = 7 506 000) | 2.4 / 2.6 (outnyttjat, sparas) | 2.7 (inom gräns) | 2.8 (2/3 av 2.7) | 2.9 | 2.10 (kapital, beskattat) |
|---|---|---|---|---|---|---|
| 700 000 | 700 000 - 546 200 = 153 800 | 0 | 546 200 | 546 200 x 2/3 = 364 133,33 => 364 133 | 700 000 - 153 800 - 546 200 = 0 | 364 133 |
| 300 000 | 0 | 546 200 - 300 000 = 246 200 | 300 000 | 200 000 | 0 | 200 000 |
| 546 200 (exakt gränsen) | 0 | 0 | 546 200 | 364 133 | 0 | 364 133 |
| 0 | 0 | | | | | Sparar hela 546 200 |

  - Gränsfall: utdelning över 7 506 000 + gränsbeloppet: tjänstedelen begränsas till 7 506 000 och resten hamnar i 2.9 (kapital). NONOX räknar bara på ägaren, inte på närstående tillsammans.
  - Sparat till nästa år: efter utdelning = 2.6; utan utdelning = hela 1.6.
  - Konsulten verifierar: avrundningen (belopp som bygger gränsbeloppet nedåt till hela kronor, skattepliktig 2/3 till närmaste krona; TODO i app/Domain/IncomeTax/K10.php rad 25) och andelen två tredjedelar kapital (IL 57 kap. 20 §).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-19 K10: tak 50 gånger, minoritetsägare och delat grundbelopp
- Förutsättningar: Som SKA-17 men enligt tabellen.
- Steg i appen: Ändra **Din kontanta ersättning** och **Antal aktier vid årets ingång** och **Ägarandelar i andra fåmansföretag, %** enligt raderna.
- Förväntat resultat:

| Förutsättning | Beräkning | 1.6 Gränsbelopp |
|---|---|---|
| Egen ersättning 3 000 (tak 50 x 3 000 = 150 000) | 1.2 = min(177 600; 150 000) = 150 000 | 322 400 + 150 000 + 46 200 = 518 600 |
| 50 av 100 aktier, omkostnadsbelopp 500 000 | 1.1 = 322 400 x 50/100 = 161 200. Löneunderlag 1 000 000 x 50/100 = 500 000; 4.11 = 500 000 - 644 800 = -144 800 (negativ); 4.12 = 0; 1.2 = 0. 1.3 = 46 200 | 161 200 + 0 + 46 200 = 207 400 |
| 100 av 100 aktier och 50 % ägande i ett annat fåmansföretag | Grundbelopp delas efter ägarandelar: 322 400 x 10 000/(10 000 + 5 000) = 214 933,33 => 214 933 | 214 933 + 177 600 + 46 200 = 438 733 |

  - Negativt 4.11 lämnas inte i SRU-filen (4.11-4.13 utelämnas) tills Skatteverkets krav på negativt värde i fält 4586 kontrollerats.
  - Makar: lönebaserat utrymme räknas gemensamt för makarna (4.13 delas efter aktieantal); konsulten bygger eget fall och kontrollerar mot Skatteverkets exempel: två ägare med 50 aktier vardera, löneunderlag 1 000 000: gemensamt 4.9 = 1 000 000 x 100/100 = 1 000 000, 4.11 = 355 200, 4.12 = 177 600, 4.13 per make = 177 600 x 50/100 = 88 800).
  - Konsulten verifierar: tak 50 x högsta kontanta ersättningen till ägaren eller en närstående (om högsta enskilda lön eller summan; TODO i docs/adr/ADR-062-k10-underlag.md) och delning av grundbeloppet enligt 11 a §.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-20 K10: det NONOX inte räknar, år utan parametrar, löneunderlag ur bokföringen
- Förutsättningar: Som SKA-17.
- Steg i appen: Kryssa i **Ägaren har sålt eller gett bort aktier**; **Ägaren hade aktier hela 2025 och köpte fler under året**; välj Inkomstår 2027; läs löneunderlaget som hämtas ur bokföringen (7000-7289 utom upplupna löner, eller lönemodulens körningar) och jämför med hand.
- Förväntat resultat:
  - För sålda/köpta aktier visar sidan att K10 ska fyllas i hos Skatteverket; ingen beräkning görs för punkt 1.5, 2.5 och avsnitt C, eller för separat löneunderlag (4.1-4.4 och 4.5-4.8). Flera aktieslag och särskilt kvalificerade aktier stöds inte.
  - Inkomstår 2027: "Beloppen och procentsatserna för inkomstår 2027 finns inte i NONOX, så K10 kan inte räknas. Använd Skatteverkets e-tjänst." (ingen K10-fil).
  - Löneunderlag: kontant ersättning ur lönekörningar (lön, tillägg, engångsbelopp; inte förmåner eller skattepliktiga delar av traktamente) eller ur lönekontona; avvikelse mellan de två visas båda. Styrelsearvoden (7240) räknas inte med.
  - Konsulten verifierar: att BAS-kontona innehåller bara kontant ersättning enligt 17 §, hur kostnadsersättningar (7322, 7324, 7332) och 731x ska behandlas, om styrelsearvoden till anställda styrelseledamöter ska ingå, och om en utdelning kan disponeras på beslutsdagen (TODO i app/Domain/IncomeTax/K10Books.php rad 40 och 45).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SKA-21 K10: SRU-fil, personuppgifter och behörighet
- Förutsättningar: SKA-17 sparad. Ägarens personnummer påhittat 197001011233.
- Steg i appen: Fyll i **Personnummer med århundrade**. Klicka **Ladda ner SRU-fil**. Prova utan personnummer. Klicka **Registerutdrag (JSON)**. Klicka **Ta bort det sparade personnumret** och **Ta bort** ägaren. Prova som Bokförare.
- Förväntat resultat:
  - Filen innehåller `#BLANKETT K10-2026P4`, `#IDENTITET 197001011233 ...`, bolagets organisationsnummer (16 + tio siffror) och fälten för 1.1, 1.2, 1.3, 1.6, 4.9-4.12 med kodtabell K10_SKV2110-39-04-26-01. Nollbelopp utelämnas.
  - Utan personnummer: "Fyll i ägarens personnummer för att få en SRU-fil. Det behövs bara för filen." Utan ort: "Fyll i postnummer och ort under Företagsuppgifter först." Utan organisationsnummer: "Bolagets organisationsnummer behövs för K10."
  - Personnumret sparas krypterat och visas med fyra sista siffrorna; registerutdraget ger allt NONOX har om ägaren; Ta bort raderar ägaren och underlaget. Behandlingshistoriken visar fält, aldrig namn, personnummer eller belopp.
  - Konsulten verifierar: om e-tjänsten Inkomstdeklaration 1 tar emot en K10-SRU-fil eller bara Filöverföring, om fält 4530 ska vara tio siffror eller 16 + tio, rättslig grund för att bolaget behåller ägarnas K10-uppgifter och gallring (docs/gdpr; ADR-062).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

## Öppna TODO(legal-verify) i koden

Sökning: `grep -rn "TODO(legal-verify)" app/Domain/IncomeTax app/Actions/IncomeTax app/Support/Sru` (inga träffar i app/Actions/IncomeTax eller app/Support/Sru). Datafilerna database/data/corporate-tax/rules.json, database/data/income-tax/2026.json, 2027.json och database/data/k10/2026.json har ingen sådan text men statusen `researched` (och `verified: true`/`false`) på alla värden.

| nr | fil:rad | frågan (sammanfattning) | Konsultens beslut (Godkänd / Ändra / Avslag) | Kommentar |
|---|---|---|---|---|
| 1 | app/Domain/IncomeTax/Ink2.php:28 | Bara justeringarna i Ink2Codes::ADJUSTMENTS stöds; delägarrätter, andelar i handelsbolag och övriga punkter på INK2S fylls i för hand i Skatteverkets tjänst. | | |
| 2 | app/Domain/IncomeTax/Ink2.php:207 | Underlaget för särskild löneskatt (lag 1991:687 2 §) är inte alltid bokförd pensionskostnad (tryggandelagen, administrationsavgifter); BAS 74xx är bara förslag. | | |
| 3 | app/Domain/IncomeTax/Ink2.php:212 | Att 6072, 6982, 6992 och 8423 bara innehåller icke avdragsgilla kostnader, och 8314 bara skattefria intäkter (ADR-056). | | |
| 4 | app/Domain/IncomeTax/Ink2Codes.php:16 | Balansräkningens intervall är kontrollerade mot BAS kontogrupper men inte mot BAS SRU-kopplingen; saknad utgåva 2025/2026; konton som tabellen inte listar (48xx, 4921-4929, 85xx-87xx, 880x, 8812-8818) hålls i närmaste rad. | | |
| 5 | app/Domain/IncomeTax/Ink4Codes.php:17 | Utgåva 2025/2026 av BAS SRU-tabell för INK4; konton 48xx, 4921-4929, 85xx-87xx i närmaste rad. | | |
| 6 | app/Domain/IncomeTax/Ink4.php:34 | Fördelningen av överskottet på delägare med rest till sista delägaren (IL 5 kap.). | | |
| 7 | app/Domain/IncomeTax/NeCodes.php:14 | Intervallen följer BAS SRU-koppling för NE (NE_EJ_K1 Intervall-231002); ingen utgåva 2025 eller 2026 fanns; kontrollera mot gällande. | | |
| 8 | app/Domain/IncomeTax/NeCodes.php:104 | BAS-tabellen placerar 774x, 779x och 79xx i R8 och 885x i både R9 och R10; här ligger 885x i R10 och 77xx-78xx som BAS inte listar i R10. | | |
| 9 | app/Domain/IncomeTax/Ne.php:35 | Avrundning av varje beräknat belopp; att sparat fördelningsbelopp efter negativ räntefördelning får användas för positiv; schablon för passiv ägare född 1937 eller tidigare. | | |
| 10 | app/Domain/IncomeTax/Ne.php:420 | Passiv ägare född 1937 eller tidigare (lag 1990:659 övergångsbestämmelse 5): satsen är null och ägaren fyller i R43. | | |
| 11 | app/Domain/IncomeTax/TaxAllocationReserves.php:28 | Statslåneräntorna (1,96 % nov 2024, 2,55 % nov 2025), att ingående saldo på 2110-2149 är gjorda avdrag, avrundning halvt uppåt, månadsräkningen för bruten tidsperiod. | | |
| 12 | app/Domain/IncomeTax/K10.php:25 | Avrundning: gränsbeloppets delar nedåt till hela kronor, skattepliktiga två tredjedelar till närmaste krona; varken lag eller blankett anger hur. | | |
| 13 | app/Domain/IncomeTax/K10.php:120 | Om Numeriskt_B tar emot ett negativt värde i fält 4586 (negativt 4.11 utelämnas med 4.12 och 4.13). | | |
| 14 | app/Domain/IncomeTax/K10Books.php:40 | Att BAS-kontona bara innehåller kontant ersättning enligt 17 §; om skattepliktiga kostnadsersättningar (7322, 7324, 7332), 731x och utdelningsbeslutets datum behandlas rätt. | | |
| 15 | app/Domain/IncomeTax/K10Books.php:45 | Styrelsearvoden (7240) lämnas utanför; om arvoden till styrelseledamöter som är anställda, eller arvoden beskattade som tjänst, ska ingå i löneunderlaget. | | |
| 16 | database/data/income-tax/2026.json:4 | Parametrar för NE 2026 (statslåneränta 2,55 %, +6 / +1 procentenheter, tröskel -500 000, 30 %, 125,94 %, 20,6 %, schablonavdrag 25/10/20 %) har `verified: false` sedan 2026-11-06. Konsulten kontrollerar värdena mot källorna i filen; vid Godkänd sätts `verified: true` och NE/INK4 för 2026 öppnas. | | |
| 17 | database/data/k10/2026.json | Alla parametrar (4 och 8 inkomstbasbelopp, 50 %, 50x, 100 000 kr, +9 procentenheter, 2/3, 90 inkomstbasbelopp, inkomstbasbelopp 80 600 och 83 400, statslåneränta 2,55 %) har status `researched`. | | |
| 18 | database/data/corporate-tax/rules.json | Bolagsskattesats 20,6 % samt reglerna och justeringskontona som används i INK2S-förslagen har status `researched`. | | |

Ytterligare öppna punkter i ADR (ADR-053, ADR-062; fylls i av konsulten): hur fältet 4530 ska skrivas, om e-tjänsten Inkomstdeklaration 1 tar K10-SRU, om 50x-taket gäller högsta enskilda lön eller summan, rättslig grund och gallring för ägaruppgifter.

## Avvikelser

| nr | fall | beskrivning | allvarlighet | åtgärd |
|---|---|---|---|---|
| | | | | |
| | | | | |
| | | | | |

## Godkännande

Modulen Inkomstdeklaration (INK2, NE, INK4, K10 och SRU-filer) är granskad och godkänd för att tas ur Beta.

| | |
|---|---|
| Namn | ______________________ |
| Titel/auktorisation | ______________________ |
| Datum | ______________________ |
| NONOX-version | ______________________ |
| Underskrift | ______________________ |

[ ] Godkänd   [ ] Godkänd med villkor   [ ] Ej godkänd

Villkor/anmärkningar: ________________________________________________

Godkännandet gäller bara modulen Inkomstdeklaration (INK2, NE, INK4, K10 och SRU-filer) och inte andra moduler.
