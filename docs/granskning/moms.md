# Granskningschecklista: Moms (momsdeklaration, EU-handel, import, Skatteverket)

| | |
|---|---|
| Modul | Moms: momsdeklarationens rutor, omvänd betalningsskyldighet, import, EU-rapporter (periodisk sammanställning, OSS), momsavräkning, momsperioder, filen till Skatteverket och kopplingen till Skatteverkets testtjänst |
| Version av NONOX som granskas | ______________________ |
| Granskare (namn) | ______________________ |
| Datum | ______________________ |
| Kod/ADR som testas | `app/Domain/Vat/VatBoxes.php`, `app/Domain/Vat/VatReturn.php`, `app/Domain/Vat/VatPeriods.php`, `app/Domain/Vat/VatDeadlines.php`, `app/Domain/Vat/ImportVat.php`, `app/Domain/Vat/EskdFile.php`, `app/Domain/Vat/EuSalesList.php`, `app/Domain/Vat/EuTrade.php`, `app/Domain/Vat/OssRates.php`, `app/Domain/Vat/OssThreshold.php`, `app/Domain/Vat/OssReport.php`, `app/Domain/Vat/SalesVatRules.php`, `app/Support/Skatteverket/Moms/` (MomsPayload, MomsApi, MomsPeriod), `app/Support/Skatteverket/Redovisare.php`, `app/Livewire/Vat/VatReturns.php`, `app/Livewire/Vat/EuReports.php`, `app/Livewire/Vat/SkatteverketPanel.php`, `docs/adr/ADR-010-reports-vat-year-end.md`, `docs/adr/ADR-054-skatteverket-apis.md` (steg 1), `docs/adr/ADR-057-import-vat.md` |
| Manualavsnitt | `docs/manual/sv/moms/` (oversikt, momsdeklaration, momsperiod-och-bokforingsmetod, moms-och-konton, omvand-betalningsskyldighet, importmoms, periodisk-sammanstallning, oss, vies) |

Beslut 7 i `docs/status/manskliga-beslut.md`: en namngiven auktoriserad redovisningskonsult granskar riktiga fall med en checklista per modul. Den här checklistan gäller bara modulen Moms. Fakturornas egen bokföring (kundfakturor, kreditfakturor, ROT/RUT) granskas i `docs/granskning/kundfakturor.md`.

## Förutsättningar

- **Testbolag**: "Testbolag Moms AB", organisationsnummer 556000-0001 (giltig kontrollsiffra enligt Luhn), momsregistreringsnummer SE556000000101. Allt data är påhittat. Inga riktiga bolag, personer eller personnummer.
- **Regelverk**: K2. **Räkenskapsår**: kalenderår 2026 (2026-01-01 till 2026-12-31), öppet. Kontoplan BAS 2026 (grundmallen).
- **Momsredovisning**: **Varje månad** om inget annat anges i fallet. **Bokföringsmetod**: **Fakturametoden** om inget annat anges. Rutan "Beskattningsunderlaget för moms är över 40 miljoner kronor om året" är inte ibockad.
- **Roll**: den som granskar är Ägare, Administratör eller Redovisningskonsult i testbolaget (de roller som får se och bokföra momsen).
- **Plan/modul**: momsen ingår i grundsystemet, ingen modul behöver slås på. Fall MOMS-23 och MOMS-24 kräver att operatören har lagt in Skatteverkets testnycklar (`SKATTEVERKET_*` i `.env`) och att granskarens e-postadress står i `SKATTEVERKET_PILOT_USERS` (eller att listan är tom). Se ADR-054.
- **En tom period per fall**: rutorna i varje fall räknas för en tom period. Skapa ett nytt testbolag (eller använd en tom månad) för varje fall, annars summeras rutorna med tidigare fall i samma månad. Månaderna som anges är förslag.
- **Demodata**: använd en egen testdatabas (`nonox_demo_*`), aldrig en databas med riktiga uppgifter. Kör aldrig `migrate:fresh` eller liknande.
- **Så bokför du de handbokförda exemplen**: **Bokföring → Verifikationer → Ny verifikation** (adress `/verifikationer`). Fyll i datum inom den period fallet gäller, beskrivning och raderna konto, debet, kredit. Verifikationen ska balansera. Beloppen nedan är i kronor och anges utan moms om inget annat sägs.
- **Så läser du rutorna**: **Bokföring → Moms** (adress `/moms`). Välj **Räkenskapsår**, klicka på perioden och läs tabellen **Ruta / Vad / Kronor**. **Visa konton** vid en ruta visar kontona bakom. Rutorna 05, 10, 11, 12 och 48 visas alltid, övriga bara när de har ett belopp.
- **Avrundningsregeln som koden följer** (`app/Domain/Vat/VatReturn.php`, metoden `boxes()`): varje ruta visas i hela kronor genom att ören tas bort (`intdiv`, mot noll, även för negativa belopp). Ruta 49 = summan av de avrundade utgående momsrutorna (10, 11, 12, 30, 31, 32, 60, 61, 62) minus den avrundade ruta 48. Konsulten verifierar att ören ska tas bort och inte avrundas, se TODO i `VatReturn.php:24`.
- **Rutornas kontointervall** (`app/Domain/Vat/VatBoxes.php`, konstanten `BOXES`): 05 = 3000–3003, 3005–3099, 3914; 06 = 3401–3403; 07 = härleds ur 2616/2626/2636; 08 = 3913; 10 = 2610–2613 och 2616; 11 = 2620–2623 och 2626; 12 = 2630–2633 och 2636; 20 = 4075–4077 och 4515–4517; 21 = 4535–4537; 22 = 4531–4533; 23 = 4065–4067 och 4415–4417; 24 = 4425–4427; 30/31/32 = 2614/2624/2634; 35 = 3108; 36 = 3105; 37 och 38 = egna konton kopplade via **Momsruta** (3107 för 38); 39 = 3308; 40 = 3305; 41 = 3231; 42 = 3004; 48 = 2640–2647 och 2649; 50 = 4085–4087 och 4545–4547; 60/61/62 = 2615/2625/2635. Konsulten verifierar hela tabellen mot BAS momsrapport och Skatteverkets rutdefinitioner (TODO `VatBoxes.php:33`).

---

### MOMS-01 Grundfall: försäljning med 25 % moms

- Förutsättningar: testbolaget enligt ovan, perioden september 2026 är tom.
- Steg i appen:
  1. Gå till **Bokföring → Verifikationer** och välj **Ny verifikation**.
  2. Datum 2026-09-15, beskrivning "Försäljning, grundfall". Rader: 1930 debet 15 625,00; 3001 kredit 12 500,00; 2611 kredit 3 125,00. Bokför.
  3. Gå till **Bokföring → Moms**, välj räkenskapsår 2026 och perioden **September 2026**.
  4. Klicka på **Visa konton** vid ruta 05 och ruta 10.
- Förväntat resultat: moms 12 500,00 kr x 25 % = 3 125,00 kr. Ruta 05 = 12 500 (konto 3001). Ruta 10 = 3 125 (konto 2611). Ruta 48 = 0. Ruta 49 = 3 125 - 0 = 3 125, rubriken **Moms att betala**. Källa: `VatBoxes::BOXES` (05, 10).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-02 Flera momssatser och ingående moms i samma period

- Förutsättningar: perioden september 2026 är tom.
- Steg i appen:
  1. Bokför fyra verifikationer i september 2026 enligt tabellen.
  2. Öppna **Bokföring → Moms**, perioden **September 2026**.

  | Verifikation | Rader |
  |---|---|
  | A försäljning 25 % | 1930 debet 12 500,00; 3001 kredit 10 000,00; 2611 kredit 2 500,00 |
  | B försäljning 12 % | 1930 debet 5 600,00; 3002 kredit 5 000,00; 2621 kredit 600,00 |
  | C försäljning 6 % | 1930 debet 2 120,00; 3003 kredit 2 000,00; 2631 kredit 120,00 |
  | D inköp 25 % | 5410 debet 7 500,00; 2641 debet 1 875,00; 1930 kredit 9 375,00 |
- Förväntat resultat: moms A 10 000,00 x 25 % = 2 500,00; B 5 000,00 x 12 % = 600,00; C 2 000,00 x 6 % = 120,00; D 7 500,00 x 25 % = 1 875,00. Ruta 05 = 10 000 + 5 000 + 2 000 = 17 000. Ruta 10 = 2 500. Ruta 11 = 600. Ruta 12 = 120. Ruta 48 = 1 875. Ruta 49 = 2 500 + 600 + 120 - 1 875 = 1 345, **Moms att betala**. Detta underlag återanvänds i MOMS-16, MOMS-22 och MOMS-23.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-03 Ören i rutorna och momsavräkningens öresjustering

- Förutsättningar: perioden oktober 2026 är tom.
- Steg i appen:
  1. Bokför i oktober 2026: (a) 1930 debet 1 543,20; 3001 kredit 1 234,56; 2611 kredit 308,64. (b) 5410 debet 399,96; 2641 debet 99,99; 1930 kredit 499,95.
  2. Öppna **Bokföring → Moms**, perioden **Oktober 2026**.
  3. Klicka på **Bokför momsen** och sedan **Ja, bokför momsen**.
  4. Öppna den nya verifikationen i serie M.
- Förväntat resultat: moms (a) 1 234,56 x 25 % = 308,64. Moms (b) 399,96 x 25 % = 99,99. Rutor med ören borttagna: ruta 05 = 1 234 (av 1 234,56), ruta 10 = 308 (av 308,64), ruta 48 = 99 (av 99,99). Ruta 49 = 308 - 99 = 209. Verifikationen i serie M, datum 2026-10-31, text "Momsredovisning" och perioden: 2611 debet 308,64; 2641 kredit 99,99; 2650 kredit 209,00; 3740 debet 0,35. Kontroll: debet 308,64 + 0,35 = 308,99, kredit 99,99 + 209,00 = 308,99. Öresdifferensen 0,35 = 209,00 - (308,64 - 99,99 = 208,65) = 0,35, och är mindre än en krona per ruta (`VatReturn::settlementRows()`). Om ören i stället avrundades skulle rutorna bli 1 235, 309 och 100: Konsulten verifierar: att ören ska bortses och inte avrundas, och att ruta 49 är differensen av de hela kronorna (Skatteverkets anvisning för ifyllnad av momsdeklarationen).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-04 Kreditering som ger negativa rutor

- Förutsättningar: perioden november 2026 är tom. Ingen annan försäljning i perioden.
- Steg i appen:
  1. Bokför 2026-11-10: 3001 debet 1 000,50; 2611 debet 250,13; 1510 kredit 1 250,63 (en kreditering av en faktura från en tidigare period).
  2. Öppna **Bokföring → Moms**, perioden **November 2026**, och klicka på **Bokför momsen**, **Ja, bokför momsen**.
- Förväntat resultat: moms 1 000,50 x 25 % = 250,125, avrundad till 250,13. Ruta 05 = -1 000 (kredit minus debet = -1 000,50, ören bortagna mot noll). Ruta 10 = -250 (-250,13). Ruta 49 = -250, rubriken **Moms att få tillbaka**. Verifikation serie M 2026-11-30: 2611 kredit 250,13; 2650 debet 250,00; 3740 debet 0,13. Kontroll: debet 250,00 + 0,13 = 250,13 = kredit 250,13. Konsulten verifierar: att negativa belopp i ruta 05 och 10 får lämnas så (Skatteverkets anvisning för kreditnotor), samt att ören bortfaller mot noll även för negativa belopp.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-05 Moms att få tillbaka

- Förutsättningar: perioden december 2026 är tom.
- Steg i appen:
  1. Bokför 2026-12-05: 1930 debet 2 500,00; 3001 kredit 2 000,00; 2611 kredit 500,00.
  2. Bokför 2026-12-08: 5410 debet 20 000,00; 2641 debet 5 000,00; 2440 kredit 25 000,00.
  3. Öppna **Bokföring → Moms**, perioden **December 2026**, klicka på **Bokför momsen**, **Ja, bokför momsen**.
- Förväntat resultat: utgående moms 2 000,00 x 25 % = 500,00; ingående moms 20 000,00 x 25 % = 5 000,00. Ruta 05 = 2 000. Ruta 10 = 500. Ruta 48 = 5 000. Ruta 49 = 500 - 5 000 = -4 500, **Moms att få tillbaka**. Verifikation serie M 2026-12-31: 2611 debet 500,00; 2641 kredit 5 000,00; 2650 debet 4 500,00. Debet 5 000,00 = kredit 5 000,00. Ingen rad på 3740.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-06 Försäljning på momspliktigt konto utan moms hamnar i ruta 42

- Förutsättningar: perioden augusti 2026 är tom.
- Steg i appen:
  1. Bokför 2026-08-12: 1930 debet 5 000,00; 3001 kredit 5 000,00 (ingen utgående moms på verifikationen).
  2. Bokför 2026-08-13 en omföring: 3001 debet 1 000,00; 3990 kredit 1 000,00.
  3. Öppna **Bokföring → Moms**, perioden **Augusti 2026**, och **Visa konton** vid ruta 05 och 42.
- Förväntat resultat: första verifikationen har ingen utgående moms och bara konton för ruta 05, så försäljningen räknas som momsfri: ruta 42 = 5 000, och ruta 05 får inte 5 000 (`VatReturn::placeSales`, regeln i ärende #62). Omföringen 3001 till 3990 räknas efter kontot: ruta 05 = -1 000 (kredit minus debet på 3001). Total ruta 05 = -1 000, ruta 42 = 5 000. Konsulten bedömer: om en negativ ruta 05 efter en omföring mellan intäktskonton är rimlig, eller om omföringen ska behandlas som skattefri omklassificering (BFL, Skatteverkets anvisning). Beloppen i fallet är påhittade; avgörandet är om beteendet är godtagbart.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-07 Försäljning på intäktskonto utan ruta, och såld inventarie

- Förutsättningar: perioden juli 2026 är tom.
- Steg i appen:
  1. Bokför 2026-07-10 (a): 1930 debet 1 250,00; 3990 kredit 1 000,00; 2611 kredit 250,00.
  2. Bokför 2026-07-11 (b): 1930 debet 1 750,00; 3990 kredit 1 500,00; 2611 kredit 250,00.
  3. Bokför 2026-07-12 (c): 1930 debet 12 500,00; 1220 kredit 10 000,00; 2611 kredit 2 500,00.
  4. Öppna **Bokföring → Moms**, perioden **Juli 2026**.
- Förväntat resultat: underlaget härleds ur momsen: 250,00 / 25 % = 1 000,00. (a) lägger 1 000 i ruta 05. (b) lägger bara 1 000 i ruta 05 (momsen räcker till 1 000,00), de övriga 500,00 på 3990 räknas inte med. (c) har ingen intäktsrad, så ruta 10 får 2 500 men ruta 05 inget, och sidan visar rutan **Utgående moms utan försäljning i ruta 05** med beloppet 10 000 kr (2 500,00 / 25 % = 10 000,00). Summa: ruta 05 = 1 000 + 1 000 = 2 000, ruta 10 = 250 + 250 + 2 500 = 3 000, ruta 49 = 3 000. Konsulten verifierar: att en såld anläggningstillgång ska redovisas i ruta 05 till sitt pris och att varningen räcker (TODO `VatBoxes.php:125`), och att en helkronas differens per verifikation får räknas som avrundning (TODO `VatReturn.php:133`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-08 Uttag (ruta 06), vinstmarginalbeskattning (ruta 07) och frivillig uthyrning (ruta 08)

- Förutsättningar: perioden maj 2026 är tom.
- Steg i appen:
  1. Bokför 2026-05-10 (uttag): 2013 debet 2 500,00; 3401 kredit 2 000,00; 2612 kredit 500,00.
  2. Bokför 2026-05-11 (VMB): 1930 debet 10 000,00; 3211 kredit 9 200,00; 2616 kredit 800,00.
  3. Bokför 2026-05-12 (uthyrning): 1930 debet 15 000,00; 3913 kredit 12 000,00; 2613 kredit 3 000,00.
  4. Öppna **Bokföring → Moms**, perioden **Maj 2026**.
- Förväntat resultat: uttag 2 000,00 x 25 % = 500,00 på 2612. VMB: momsen 800,00 / 25 % = 3 200,00 i underlag. Uthyrning 12 000,00 x 25 % = 3 000,00 på 2613. Ruta 06 = 2 000. Ruta 07 = 3 200. Ruta 08 = 12 000. Ruta 05 = 0 (3401, 3211 och 3913 räknas inte dit). Ruta 10 = 500 + 800 + 3 000 = 4 300. Ruta 49 = 4 300. Konsulten verifierar: att ruta 07 är marginalen utan moms (VMB-momsen / satsen) och inte försäljningspriset, att negativ marginal hanteras (TODO `VatBoxes.php:91`), och att kontona 2612 och 2613 hör till ruta 10 (Skatteverkets fältbeskrivning).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-09 Omvänd betalningsskyldighet: inköp av byggtjänst i Sverige

- Förutsättningar: leverantören "Testunderentreprenör AB" finns som leverantör. Perioden juni 2026 är tom.
- Steg i appen:
  1. Gå till **Inköp → Leverantörsfakturor → Ny leverantörsfaktura**.
  2. Välj leverantören, fakturadatum 2026-06-10, under **Vem redovisar momsen?** välj **Tjänster i Sverige, omvänd betalningsskyldighet (till exempel bygg)**. Belopp utan moms 40 000,00. Registrera.
  3. Öppna den bokförda verifikationen och sedan **Bokföring → Moms**, perioden **Juni 2026**.
- Förväntat resultat: moms 40 000,00 x 25 % = 10 000,00. Verifikationen: 4425 debet 40 000,00; 2647 debet 10 000,00; 2614 kredit 10 000,00; 2440 kredit 40 000,00. Ruta 24 = 40 000. Ruta 30 = 10 000. Ruta 48 = 10 000. Ruta 49 = 10 000 - 10 000 = 0. Varuvarianten (**Varor i Sverige, omvänd betalningsskyldighet**) ger kostnad på 4415 och ruta 23 i stället för ruta 24. Källa: `app/Domain/Payables/VatTreatment.php`. Konsulten verifierar: att 2647 är rätt konto för ingående moms vid omvänd betalningsskyldighet och att bygg- och varuinköp ska i ruta 24 respektive 23 med utgående moms i 30.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-10 Omvänd betalningsskyldighet: försäljning av byggtjänst (ruta 41)

- Förutsättningar: kunden "Testbygg AB" med momsregistreringsnummer SE556000000201 finns. Perioden juni 2026 är tom. Testbolaget har momsregistreringsnummer.
- Steg i appen:
  1. **Register → Kunder**, öppna kunden och bocka i **Omvänd betalningsskyldighet för byggtjänster**. Spara.
  2. **Försäljning → Fakturor → Ny faktura**, välj kunden, fakturadatum 2026-06-15, en rad 30 000,00 utan moms. **Skapa fakturan**, **Ja, skapa**.
  3. Öppna **Bokföring → Moms**, perioden **Juni 2026**.
  4. Upprepa steg 2 med en kund som saknar momsregistreringsnummer (men har omvänd betalningsskyldighet markerad).
- Förväntat resultat: fakturan blir utan moms och får texten "Omvänd betalningsskyldighet för byggtjänster" samt båda momsregistreringsnumren. Verifikationen: 1510 debet 30 000,00; 3231 kredit 30 000,00. Ruta 41 = 30 000. Ruta 05 påverkas inte. Ruta 49 påverkas inte. Steg 4: fakturan går inte att skapa, ett meddelande säger att momsregistreringsnumret saknas. Konsulten verifierar: fakturatexten, kravet på båda numren, konto 3231 och ruta 41 (TODO `app/Actions/Invoicing/IssueInvoice.php:301`, `app/Domain/Invoicing/InvoiceBooking.php:74`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-11 Inköp från andra EU-länder och från länder utanför EU (ruta 20, 21, 22)

- Förutsättningar: leverantörerna "Testlieferant GmbH" (DE) och "Test Services Ltd" (utanför EU) finns. Perioden mars 2026 är tom.
- Steg i appen:
  1. Registrera tre leverantörsfakturor under **Inköp → Leverantörsfakturor → Ny leverantörsfaktura**, fakturadatum 2026-03-10, under **Vem redovisar momsen?**: (a) **Varor från ett annat EU-land**, 20 000,00. (b) **Tjänster från ett annat EU-land**, 6 000,00. (c) **Tjänster från ett land utanför EU**, 8 000,00.
  2. Öppna **Bokföring → Moms**, perioden **Mars 2026**.
- Förväntat resultat: (a) 4515 debet 20 000,00; 2645 debet 5 000,00; 2614 kredit 5 000,00; 2440 kredit 20 000,00. (b) 4535 debet 6 000,00; 2645 debet 1 500,00; 2614 kredit 1 500,00. (c) 4531 debet 8 000,00; 2645 debet 2 000,00; 2614 kredit 2 000,00. Ruta 20 = 20 000. Ruta 21 = 6 000. Ruta 22 = 8 000. Ruta 30 = 5 000 + 1 500 + 2 000 = 8 500. Ruta 48 = 8 500. Ruta 49 = 8 500 - 8 500 = 0. Konsulten verifierar: att tjänster från länder utanför EU hör till ruta 22 (inte 24) och kontotilldelningen (TODO `app/Domain/Vat/EuTrade.php:26`, `VatBoxes.php:33`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-12 Försäljning utan svensk moms (ruta 35, 36, 39, 40) och trepartshandel (ruta 37, 38)

- Förutsättningar: kunder enligt FAK-16 och FAK-17 (en kund i DE med momsnummer, en i US). Egen kostnadskonto 4519 skapat under **Bokföring → Kontoplan** (adress `/kontoplan`) med **Momsruta** satt till 37. Perioden april 2026 är tom.
- Steg i appen:
  1. Skapa fakturor 2026-04-10 med **Försäljning utomlands**: **Varor till företag i annat EU-land** 50 000,00; **Export av varor utanför EU** 20 000,00; **Tjänster till företag i annat EU-land** 7 000,00; **Tjänster till kund utanför EU** 4 000,00; **Trepartshandel inom EU, som mellanman** 12 000,00.
  2. Bokför för hand 2026-04-11 mellanmannens inköp: 4519 debet 9 000,00; 2440 kredit 9 000,00.
  3. Öppna **Bokföring → Moms**, perioden **April 2026**.
- Förväntat resultat: ruta 35 = 50 000 (konto 3108). Ruta 36 = 20 000 (3105). Ruta 39 = 7 000 (3308). Ruta 40 = 4 000 (3305). Ruta 38 = 12 000 (3107, skapas och kopplas till ruta 38 av NONOX första gången). Ruta 37 = 9 000 (4519, kopplat till ruta 37). Ingen moms bokförs, så ruta 10 = 0, ruta 49 = 0. Källa: `VatBoxes::BOXES`, `ForeignSale::account()`. Konsulten verifierar: kontona, rutorna och fakturatexterna (TODO `app/Domain/Invoicing/ForeignSale.php:23`, `:148`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-13 Importmoms från tullräkning (ADR-057), grundfall

- Förutsättningar: leverantören "Test Supplier Ltd" (utanför EU) finns. Perioden februari 2026 är tom.
- Steg i appen:
  1. Registrera leverantörsfaktura 2026-02-03 med **Vem redovisar momsen?** satt till **Varor från ett land utanför EU (import)**, 105 000,00 kr, kostnad på 4080 (fältet **Varav moms** finns inte).
  2. **Bokföring → Verifikationer → Ny verifikation**, öppna **Importmoms från tullräkning**. **Tullräkningens datum** 2026-02-10, **Vad är varorna?** **Handelsvaror**. En rad: **Momssats** 25 %, **Tullvärde** 105 000,00, **Tull och avgifter** 12 600,00, **Bikostnader i Sverige** 0,00.
  3. Välj **Fyll i rader för importmoms**, kontrollera raderna under **Rader**, bokför.
  4. Öppna **Bokföring → Moms**, perioden **Februari 2026**.
- Förväntat resultat: underlag 105 000,00 + 12 600,00 + 0,00 = 117 600,00. Moms 117 600,00 x 25 % = 29 400,00. Rader: 4085 debet 117 600,00; 2615 kredit 29 400,00; 4080 kredit 117 600,00; 2645 debet 29 400,00. Ruta 50 = 117 600. Ruta 60 = 29 400. Ruta 48 = 29 400. Ruta 49 = 29 400 - 29 400 = 0. Resultatet av tullverifikationen är noll (4085 debet 117 600 mot 4080 kredit 117 600); varorna bokförs från leverantörens faktura och tullen (12 600) från speditörens eller Tullverkets egen räkning. Exemplet motsvarar Skatteverkets exempel i manualen (tullvärde 105 000, tull 12 600, moms 29 400). Konsulten verifierar: underlagets delar (ML 8 kap. 24-27 §§), perioden (tullräkningens datum, ML 7 kap. 27 och 41 §§) och motkonto 4080 (TODO `ImportVat.php:29`, `:95`-`:135`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-14 Import: flera momssatser, avrundning, ändrat beslut och fel konto

- Förutsättningar: perioden mars 2026 är tom. Leverantörerna och tullräkningarna nedan är påhittade.
- Steg i appen:
  1. Öppna **Importmoms från tullräkning**, tullräkningens datum 2026-03-18, **Handelsvaror**. Rad 1: **Momssats** 25 %, **Tullvärde** 20 000,00, tull 0,00, bikostnader 0,00. Välj **Lägg till momssats**. Rad 2: 12 %, tullvärde 1 334,50, tull 0,00, bikostnader 100,00. **Fyll i rader för importmoms**, bokför.
  2. Ny tullräkning 2026-03-19, en rad 25 %, tullvärde 1 000,00, tull och avgifter 1,01. Bokför.
  3. Ändrat beslut: ny tullräkning, kryssa i **Ändrat beslut som sänker underlaget**, **Datum för Tullverkets ändrade beslut** 2026-03-25, tullvärde 10 000,00, 25 %. Bokför.
  4. Registrera en leverantörsfaktura och kontera den på 4085.
- Förväntat resultat:
  - Steg 1: bas 25 % = 20 000,00; bas 12 % = 1 334,50 + 100,00 = 1 434,50. Moms 20 000,00 x 25 % = 5 000,00; 1 434,50 x 12 % = 172,14. Rader: 4085 debet 20 000,00; 2615 kredit 5 000,00; 4086 debet 1 434,50; 2625 kredit 172,14; 4080 kredit 21 434,50; 2645 debet 5 172,14. Kontroll: debet 20 000,00 + 1 434,50 + 5 172,14 = 26 606,64; kredit 5 000,00 + 172,14 + 21 434,50 = 26 606,64.
  - Steg 2: bas 1 001,01; moms 1 001,01 x 25 % = 250,2525, avrundad halv upp till 250,25. Rader: 4085 debet 1 001,01; 2615 kredit 250,25; 4080 kredit 1 001,01; 2645 debet 250,25.
  - Steg 3: raderna bokförs åt andra hållet med beslutets datum: 4085 kredit 10 000,00; 2615 debet 2 500,00; 4080 debet 10 000,00; 2645 kredit 2 500,00.
  - Rutor för mars 2026 (steg 1-3): ruta 50 = 20 000 + 1 434 + 1 001 - 10 000 = 12 435 (i öre 2 000 000 + 143 450 + 100 101 - 1 000 000 = 1 243 551, ören borta). Ruta 60 = (5 000,00 + 250,25 - 2 500,00) = 2 750,25, ruta 60 = 2 750. Ruta 61 = 172. Ruta 48 = (5 172,14 + 250,25 - 2 500,00) = 2 922,39, ruta 48 = 2 922. Ruta 49 = 2 750 + 172 - 2 922 = 0.
  - Steg 4: NONOX varnar för att leverantörens faktura inte ska konteras på 4085 (annars räknas varorna två gånger i ruta 50).
  - Konsulten verifierar: att momsen avrundas per momssats på summan av underlagen, att ett sänkande beslut hör till beslutets period och inte till tullräkningens (TODO `ImportVat.php:33`, ADR-057 rad 70), samt full avdragsrätt (TODO `ImportVat.php:37`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-15 Vilande moms (2618) och OSS-moms (2619) ingår inte i rutorna

- Förutsättningar: perioden januari 2026 är tom.
- Steg i appen:
  1. Bokför 2026-01-12: 1930 debet 1 250,00; 3001 kredit 1 000,00; 2618 kredit 250,00 (vilande utgående moms 25 %).
  2. Bokför 2026-01-13: 1930 debet 1 190,00; 3106 kredit 1 000,00; 2619 kredit 190,00 (OSS, Tyskland 19 %).
  3. Öppna **Bokföring → Moms**, perioden **Januari 2026**, och klicka på **Bokför momsen**.
  4. Bokför i februari 2026 (2026-02-02) en omföring: 2618 debet 250,00; 2611 kredit 250,00. Öppna perioden **Februari 2026**.
- Förväntat resultat: januari: ruta 05 = 1 000 (3001), ruta 10 = 0. Under **Momskonton som inte ingår i deklarationen** visas 2618 med 250,00 kredit. 2619 visas inte alls och ingår i ingen ruta. Inga momskonton som ingår i deklarationen har rört sig, så ruta 49 = 0. Momsavräkningen flyttar i inget fall 2618 eller 2619 till 2650. Konsulten noterar vad sidan gör i januari (knappen **Bokför momsen** kan saknas när det inte finns moms att redovisa, se manualens fråga "Knappen Bokför momsen syns inte"). Februari: ruta 10 = 250, ruta 05 = 0 (ruta 05 följer intäktskontot, och det bokfördes i januari). Konsulten bedömer: om underlaget (ruta 05, januari) och momsen (ruta 10, februari) får hamna i olika perioder vid vilande moms, eller om underlaget ska följa med momsen. Källa: `VatBoxes::VAT_ACCOUNTS`, `OssRates::VAT_ACCOUNT`.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-16 Bokför momsen i serie M och lås perioden

- Förutsättningar: underlaget från MOMS-02 (perioden september 2026) ligger bokfört. Ingen tidigare momsverifikation för perioden.
- Steg i appen:
  1. Öppna **Bokföring → Moms**, perioden **September 2026**.
  2. Klicka på **Bokför momsen** och **Ja, bokför momsen**. Öppna verifikationen.
  3. Klicka på **Lås till och med 2026-09-30** och **Ja, lås perioden**.
  4. Försök bokföra en ny verifikation med datum 2026-09-30. Försök sedan låsa till ett tidigare datum, 2026-08-31.
- Förväntat resultat: verifikation i serie M, datum 2026-09-30, text "Momsredovisning" och perioden. Rader: 2611 debet 2 500,00; 2621 debet 600,00; 2631 debet 120,00; 2641 kredit 1 875,00; 2650 kredit 1 345,00. Debet 2 500 + 600 + 120 = 3 220,00; kredit 1 875 + 1 345 = 3 220,00. Ingen rad på 3740 (inga ören). Knappen **Bokför momsen** försvinner, perioden får en bock. Efter låset går ingen bokföring att göra på datum till och med 2026-09-30, och låset går inte att flytta bakåt. Rutorna är sparade med verifikationen och ändras inte. 2650 har saldo 1 345,00 kredit.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-17 Tillägg när något bokförs efter att momsen bokförts

- Förutsättningar: en tom, olåst period där verifikationen från MOMS-01 är bokförd (ruta 10 = 3 125).
- Steg i appen:
  1. Öppna **Bokföring → Moms**, perioden, klicka på **Bokför momsen**, **Ja, bokför momsen**.
  2. Bokför i samma period ett sent kvitto: 5410 debet 1 000,00; 2641 debet 250,00; 1930 kredit 1 250,00.
  3. Öppna perioden igen. Klicka på **Bokför tillägget** och **Ja, bokför tillägget**.
  4. Lås perioden.
- Förväntat resultat: efter steg 2 visar sidan **Bokför tillägget till momsen**, och rutorna visar hela perioden: ruta 10 = 3 125, ruta 48 = 250, ruta 49 = 3 125 - 250 = 2 875. Tilläggsverifikationen i serie M, text "Kompletterande momsredovisning", periodens sista dag: 2641 kredit 250,00; 2650 debet 250,00. Den första verifikationen (2611 debet 3 125,00; 2650 kredit 3 125,00) ändras inte. Summa 2650 = 3 125,00 kredit - 250,00 debet = 2 875,00 kredit = ruta 49. De rättade rutorna sparas som en ny version, den första finns kvar. Om perioden hade låsts mellan steg 2 och 3 skulle tillägget dateras dagens datum. Konsulten verifierar: att en rättad deklaration för hela perioden ska lämnas (SFL 26 kap. 11 §) och att tilläggsverifikationen är en godtagbar bokföring av rättelsen. NONOX lämnar inte rättelsen åt användaren.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-18 Momsperioder: månad, kvartal, år och brutet räkenskapsår

- Förutsättningar: tre testbolag eller inställningen ändras mellan kontrollerna. **Företagsinställningar → Uppgifter och personer**, rubriken **Moms och bokföring**, fältet **Momsredovisning**.
- Steg i appen:
  1. **Varje månad**, räkenskapsår 2026: öppna **Bokföring → Moms** och räkna perioderna.
  2. **Varje kvartal**, räkenskapsår 2026-05-01 till 2027-04-30 (brutet): öppna **Bokföring → Moms**, räkenskapsår 2026/2027.
  3. **Varje månad**, första räkenskapsåret 2026-03-15 till 2026-12-31 (kort första år).
  4. **En gång per år**, räkenskapsår 2026.
  5. **Inte momsregistrerad**.
- Förväntat resultat: (1) 12 perioder, januari-december 2026, varje period en hel kalendermånad. (2) 5 perioder: 2026-04-01 till 2026-06-30, 2026-07-01 till 2026-09-30, 2026-10-01 till 2026-12-31, 2027-01-01 till 2027-03-31 och 2027-04-01 till 2027-06-30. Första och sista kvartalet är hela kalenderkvartal och visas under båda angränsande räkenskapsår (`VatPeriods::of`). Kvartalet april-juni 2026 är en deklaration; momsverifikationen daterad 2026-06-30 hör till räkenskapsåret som innehåller 2026-06-30. (3) 10 perioder, mars-december 2026; mars-perioden är 2026-03-01 till 2026-03-31, alltså även dagar före räkenskapsårets start. (4) 1 period lika med räkenskapsåret, 2026-01-01 till 2026-12-31. (5) Inga perioder; sidan säger att företaget inte är momsregistrerat. Konsulten verifierar: att månad och kvartal är hela kalenderperioder även vid brutet år och att helårsmoms följer räkenskapsåret (SFL 26 kap. 10 och 11 §§, TODO/ärende #59).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-19 Sista dag för deklaration och betalning

- Förutsättningar: **Översikt** visar sista dag för perioden som står på tur. Ändra **Momsredovisning**, bolagsform och kryssrutan för 40 miljoner under **Företagsinställningar → Uppgifter och personer** mellan kontrollerna.
- Steg i appen:
  1. Ställ in enligt tabellen. Läs sista dag i **Översikt** (rutan för momsen).

  | Inställning | Period | Förväntad sista dag | Härledning |
  |---|---|---|---|
  | Varje månad, högst 40 Mkr | januari 2026 | 2026-03-12 (torsdag) | 12:e i andra månaden efter |
  | Varje månad, högst 40 Mkr | juni 2026 | 2026-08-17 (måndag) | augusti: 17:e |
  | Varje månad, högst 40 Mkr | oktober 2026 | 2026-12-14 (måndag) | 12 dec är lördag, nästa vardag |
  | Varje månad, högst 40 Mkr | november 2026 | 2027-01-18 (måndag) | januari: 17:e (söndag), nästa vardag |
  | Varje månad, högst 40 Mkr | december 2026 | 2027-02-12 (fredag) | 12:e |
  | Varje månad, över 40 Mkr | januari 2026 | 2026-02-26 (torsdag) | 26:e i månaden efter |
  | Varje månad, över 40 Mkr | november 2026 | 2026-12-28 (måndag) | december: 27:e (söndag), nästa vardag |
  | Varje kvartal | Q1 2026 | 2026-05-12 (tisdag) | |
  | Varje kvartal | Q2 2026 | 2026-08-17 (måndag) | augusti: 17:e |
  | Varje kvartal | Q3 2026 | 2026-11-12 (torsdag) | |
  | Varje kvartal | Q4 2026 | 2027-02-12 (fredag) | |
  | Enskild firma, helår 2026, ingen EU-handel | | 2027-05-12 (onsdag) | |
  | AB, helår, räkenskapsår slutar 2026-12-31, e-tjänst | | 2027-08-17 (tisdag) | |
  | AB, räkenskapsår slutar 2026-06-30, e-tjänst | | 2027-01-18 (måndag) | 17 jan är söndag |
  | AB, räkenskapsår slutar 2026-04-30, e-tjänst | | 2026-12-14 (måndag) | 12 dec är lördag |
  | AB, räkenskapsår slutar 2026-08-31, e-tjänst | | 2027-04-12 (måndag) | |
  | AB, helår 2026, med EU-handel under året | | 2027-02-26 (fredag) | 26:e i andra månaden efter |
  | Handelsbolag, helår 2026 | | 2027-02-26 (fredag) | |
- Förväntat resultat: datumen i tabellen, beräknade med `VatDeadlines::for()`. Konsulten verifierar varje datum mot SFL 26 kap. 26, 33, 33 a och 33 b §§ och Skatteverkets sida "När ska jag deklarera moms" (TODO i `VatDeadlines.php`, ärende #60). Appen visar bara dagen för perioden som står på tur (**Översikt**); övriga rader är dagar som koden beräknar och som konsulten jämför mot lagtexten. Särskilt: att räkenskapsår som slutar januari-april får dagarna i tabellen, och att dödsbon, byråanstånd, 33 c § och 26 kap. 28 § inte hanteras (anges i klassens dokumentation).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-20 Kontantmetoden och momsen

- Förutsättningar: **Bokföringsmetod** satt till **Kontantmetoden** i testbolaget. Månadsmoms. Perioderna september och oktober 2026 är tomma.
- Steg i appen:
  1. Kundfaktura 2026-09-28: 12 500,00 + moms 3 125,00 = 15 625,00 (rad på 3001). Kunden betalar 2026-10-05 (**Försäljning → Fakturor**, **Registrera betalning**).
  2. Leverantörsfaktura 2026-09-20: 4 000,00 + moms 1 000,00 (svensk, 25 %), kostnad 5410. Betala 2026-10-10.
  3. Kundfaktura till EU-företag (**Varor till företag i annat EU-land**, se FAK-16) 2026-09-29, 50 000,00. Betald 2026-10-20.
  4. Leverantörsfaktura **Varor från ett annat EU-land** 2026-09-22, 20 000,00. Betald 2026-10-25.
  5. Öppna **Bokföring → Moms**, perioderna **September 2026** och **Oktober 2026**.
- Förväntat resultat:
  - Steg 1: ingenting bokförs 2026-09-28. Betalningen 2026-10-05 bokför: 1930 debet 15 625,00; 3001 kredit 12 500,00; 2611 kredit 3 125,00. September: ruta 05 = 0, ruta 10 = 0. Oktober: ruta 05 = 12 500, ruta 10 = 3 125.
  - Steg 2: kostnad och moms bokförs vid betalningen 2026-10-10: ruta 48 = 1 000 i oktober (4 000,00 x 25 % = 1 000,00).
  - Steg 3: EU-försäljningen bokförs vid fakturan trots kontantmetoden: 1510 debet 50 000,00; 3108 kredit 50 000,00 (2026-09-29). September: ruta 35 = 50 000. Betalningen 2026-10-20 flyttar bara 1510 till 1930 och ger ingen ruta i oktober.
  - Steg 4: EU-förvärvet av varor bokförs vid fakturan: 4515 debet 20 000,00; 2645 debet 5 000,00; 2614 kredit 5 000,00; 2440 kredit 20 000,00 (2026-09-22). September: ruta 20 = 20 000, ruta 30 = 5 000, ruta 48 = 5 000.
  - September ruta 49 = 5 000 (ruta 30) - 5 000 (ruta 48) = 0. Oktober ruta 49 = 3 125 - 1 000 = 2 125.
  - Konsulten verifierar: ML 7 kap. 16 § och 17 kap. 24 § p. 11 (kontantmetod), 7 kap. 29-30 §§ (unionsintern leverans bokförs vid fakturan) och att förvärv av varor från EU inte omfattas av kontantmetoden (ML 7 kap. 26 och 40 §§; ärendena #50 och #52). Obetalda fakturor vid årets slut under kontantmetoden granskas i bokslutsmodulen.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-21 Företag som inte är momsregistrerat

- Förutsättningar: **Momsredovisning** satt till **Inte momsregistrerad**. Räkenskapsår 2026.
- Steg i appen:
  1. Öppna **Bokföring → Moms**.
  2. Bokför 2026-03-10: 1930 debet 1 250,00; 3001 kredit 1 000,00; 2611 kredit 250,00 (moms som debiterats trots att företaget inte är registrerat). Öppna **Bokföring → Moms** igen.
  3. Försök skapa en faktura med moms på ett bolag utan momsregistrering (**Försäljning → Fakturor → Ny faktura**) utan att bocka i **Ta ändå ut moms på fakturan**.
- Förväntat resultat: sidan säger att företaget inte är momsregistrerat och visar inga perioder. Efter steg 2 visas den utgående momsen 250,00 kr som en varning (moms som tagits ut ska betalas till staten, ML 16 kap. 23 §). Fakturan med moms stoppas i steg 3. Konsulten verifierar texten och att beloppet är rätt (TODO/ärende #51, `app/Livewire/Vat/VatReturns.php:133`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-22 Filen till Skatteverket (eSKD)

- Förutsättningar: underlaget från MOMS-02 (september 2026).
- Steg i appen:
  1. Öppna **Bokföring → Moms**, perioden **September 2026**.
  2. Klicka på **Ladda ner fil till Skatteverket**. Öppna filen i en textredigerare.
  3. Upprepa för perioden i MOMS-05 (december 2026, moms att få tillbaka) och för en period utan något belopp.
- Förväntat resultat: filnamnet `moms_5560000001_202609.xml`. Innehållet, med radbrytning LF, teckenkodning ISO-8859-1 deklarerad:

  ```
  <?xml version="1.0" encoding="ISO-8859-1"?>
  <!DOCTYPE eSKDUpload PUBLIC "-//Skatteverket, Sweden//DTD Skatteverket eSKDUpload-DTD Version 6.0//SV" "https://www1.skatteverket.se/demoeskd/eSKDUpload_6p0.dtd">
  <eSKDUpload Version="6.0">
    <OrgNr>556000-0001</OrgNr>
    <Moms>
      <Period>202609</Period>
      <ForsMomsEjAnnan>17000</ForsMomsEjAnnan>
      <MomsUtgHog>2500</MomsUtgHog>
      <MomsUtgMedel>600</MomsUtgMedel>
      <MomsUtgLag>120</MomsUtgLag>
      <MomsIngAvdr>1875</MomsIngAvdr>
      <MomsBetala>1345</MomsBetala>
    </Moms>
  </eSKDUpload>
  ```
  Decemberfilen (MOMS-05) har `<ForsMomsEjAnnan>2000</ForsMomsEjAnnan>`, `<MomsUtgHog>500</MomsUtgHog>`, `<MomsIngAvdr>5000</MomsIngAvdr>` och `<MomsBetala>-4500</MomsBetala>`. En tom period har bara `<MomsBetala>0</MomsBetala>`. Rutor med belopp 0 utelämnas, rutorna ligger i den ordning `EskdFile::ORDER` anger. Ladda upp filen i Skatteverkets e-tjänst (Momsdeklaration, ladda upp fil) eller i dess testmiljö. Konsulten verifierar: elementnamn och ordning mot eSKDUpload 6.0, formatet på OrgNr (med bindestreck), att Period är periodens sista månad (ÅÅÅÅMM) och hanteringen av negativt belopp (TODO `EskdFile.php:14`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-23 Skatteverkets testtjänst: kontrollera, skicka utkast, skriv under, hämta status (ADR-054 steg 1)

- Förutsättningar: operatören har lagt in testnycklar och sätter `SKATTEVERKET_ENVIRONMENT` till test. Granskarens e-postadress finns i `SKATTEVERKET_PILOT_USERS`. Testbolagets organisationsnummer måste vara ett som Skatteverkets testtjänst känner igen: använd ett organisationsnummer ur Skatteverkets testdata och ändra testbolaget till det (kan inte avgöras här, se nedan). Underlaget från MOMS-02, period september 2026, är bokfört men momsen är ännu inte bokförd.
- Steg i appen:
  1. Öppna **Bokföring → Moms**, perioden **September 2026**, rutan **Lämna deklarationen till Skatteverket**. Kontrollera bannern "Testmiljö: NONOX är kopplat till Skatteverkets testtjänst. Ingenting lämnas på riktigt."
  2. Klicka på **Logga in hos Skatteverket med e-legitimation** och logga in med en testidentitet.
  3. Klicka på **Låt Skatteverket kontrollera**.
  4. Klicka på **Skicka till Skatteverket**, bocka i **Jag har granskat siffrorna** och klicka på **Ja, skicka utkastet**.
  5. Klicka på **Skriv under hos Skatteverket**, skriv under i testtjänsten.
  6. Tillbaka i NONOX: klicka på **Hämta status från Skatteverket**.
  7. Skicka utkastet en gång till (steg 4) innan det är undertecknat.
  8. Klicka på **Logga ut från Skatteverket**.
- Förväntat resultat: det som skickas är rutorna i hela kronor, med Skatteverkets fältnamn (`MomsPayload::FIELDS`): `momspliktigForsaljning` 17000; `momsForsaljningUtgaendeHog` 2500; `momsForsaljningUtgaendeMedel` 600; `momsForsaljningUtgaendeLag` 120; `ingaendeMomsAvdrag` 1875; `summaMoms` 1345 (ruta 49 skickas alltid, övriga bara när de har belopp). Redovisare för organisationsnummer 556000-0001 är `165560000001` och perioden `202609` (periodens sista månad). Steg 3: Skatteverkets egna fel och varningar visas, ett fel måste rättas, en varning stoppar inte. Steg 4: **Ja, skicka utkastet** är avstängd tills rutan är ikryssad; utkastet sparas och låses, en signeringslänk visas (bara en länk till en skatteverket.se-adress). Steg 5: deklarationen är inte lämnad förrän den undertecknats. Steg 6: kvittensnummer och inlämningstid visas först efter underskrift; beslut jämförs med det NONOX skickade och texten blir "Skatteverkets beslut stämmer med det NONOX skickade." om ruta 49 är 1 345. Steg 7: det låsta utkastet låses upp och ersätts (en ny version). Steg 8: inloggningen försvinner direkt. Inget bokförs av att skicka: momsen bokförs som i MOMS-16. Testtjänsten lämnar inget på riktigt. Konsulten verifierar: Skatteverkets kontrollkoder och texter i testtjänsten, och att texterna i appen (utkast är inte inlämning, utkastet tas bort efter 30 dagar, loggning hos Skatteverket) stämmer med Skatteverkets villkor (ADR-054, "Allmänna villkor").
- Kunde inte beräknas här: Skatteverkets svar, kontrollkoder, kvittensnummer och beslutade belopp kommer från testtjänsten och kan inte härledas ur koden. Notera dem i kommentaren.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-24 Skatteverkets testtjänst: spärrar, fel och behörighet

- Förutsättningar: som MOMS-23.
- Steg i appen:
  1. Öppna **Bokföring → Moms** utan att vara inloggad hos Skatteverket och klicka på **Låt Skatteverket kontrollera**.
  2. Som en användare som inte står i `SKATTEVERKET_PILOT_USERS`: öppna samma sida.
  3. Ändra testbolagets organisationsnummer till ett ogiltigt (fel kontrollsiffra) och försök skicka.
  4. Logga in med en testidentitet som saknar behörighet för bolaget (inte firmatecknare eller ombud) och försök skicka.
  5. Ändra **Momsredovisning** till **Varje kvartal** och kontrollera att perioden som skickas är kvartalets sista månad.
  6. Kontrollera identifieraren för en enskild firma: ett testbolag med bolagsform Enskild firma och "organisationsnummer" (personnummer) 811228-9874.
  7. Läs sidans text om att NONOX Connect inte får tillgång till innehållet.
- Förväntat resultat: (1) meddelandet "Logga in hos Skatteverket med e-legitimation först." (2) panelen visas inte och inloggningsvägarna avvisas (`Skatteverket::isAvailableTo`). (3) meddelandet "Företagets organisationsnummer är inte giltigt. Rätta det under företagets uppgifter." (4) Skatteverkets 403 med Skatteverkets egen text. (5) kvartal Q3 2026 skickas som period `202609`. (6) identifieraren är `198112289874` (århundradet och de tio siffrorna för personnummer och samordningsnummer; `Redovisare::of`). Ett tiosiffrigt nummer med "-" för någon som är 100 år eller äldre läses ett århundrade fel (TODO `Redovisare.php:47`, ADR-054 rad 141). (7) ADR-054 rad 124: ingen data från Skatteverkets API:er lämnas vidare till Connect tills Skatteverket svarat. Konsulten verifierar: behörighetskraven och att inga produktionsnycklar finns på nonox.app (ADR-054, "Open questions").
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-25 Periodisk sammanställning: innehåll och fil

- Förutsättningar: kunder (utländska företag, se FAK-16): "Testkund GmbH" DE123456789, "Testkund SA" FR12345678901, "Testkund BV" NL123456789B01, alla med land satt och **Försäljning utomlands** vald. Testbolaget har momsregistreringsnummer SE556000000101. Månadsmoms, marsperioden 2026 är tom.
- Steg i appen:
  1. Skapa fakturor: (1) 2026-03-05, Testkund GmbH, **Varor till företag i annat EU-land**, 100 000,00. (2) 2026-03-20, kreditfaktura mot (1), -10 000,00. (3) fakturadatum 2026-04-03 med **Leveransdatum** 2026-03-28, Testkund GmbH, **Tjänster till företag i annat EU-land**, 25 000,00. (4) 2026-03-12, Testkund SA, **Trepartshandel inom EU, som mellanman**, 12 000,00. (5) 2026-03-14, Testkund BV, varor, 1 234,50.
  2. Öppna **Bokföring → EU-rapporter**, under **Periodisk sammanställning** välj **Period** mars 2026 (adress `/eu-rapporter`).
  3. Fyll i **Ansvarig person** (till exempel "Test Person"), **Telefon** ("0700000000") och **E-post (frivillig)**. Klicka på **Ladda ner fil**.
  4. Jämför summorna med rutorna 35, 38 och 39 i momsdeklarationen för mars och april.
- Förväntat resultat: rader per köparens momsregistreringsnummer, i ordning, kronor avrundade halv upp: `DE123456789`: varor 100 000 - 10 000 = 90 000; tjänster 25 000 (räknas till mars via leveransdatum). `FR12345678901`: trepartshandel 12 000. `NL123456789B01`: varor 1 234,50 blir 1 235. Filen (CRLF, UTF-8): rad 1 `SKV574008;`, rad 2 `556000000101;2603;Test Person;0700000000;<e-post>`, sedan
  ```
  DE123456789;90000;;25000;
  FR12345678901;;12000;;
  NL123456789B01;1235;;;
  ```
  Filnamn `periodisksammanstallning55600000012603.txt`. Varje nedladdning skrivs i behandlingshistoriken. Jämförelse med momsdeklarationen: ruta 35 mars = 100 000 - 10 000 + 1 234,50 = 91 234,50 i öre, visad som 91 234; summan av de avrundade raderna i filen är 90 000 + 1 235 = 91 235, en krona mer. Ruta 38 mars = 12 000. Ruta 39: tjänsten bokfördes på fakturadatum 2026-04-03, så ruta 39 för mars = 0 och ruta 39 för april = 25 000, medan den periodiska sammanställningen för tjänsten följer leveransdatum (mars). Konsulten bedömer: att varor, trepartshandel och tjänster periodiseras som i appen (SFL 35 kap. 2 § andra stycket, ML 7 kap. 29-30 §§), om skillnaden mellan ruta 39 (fakturadatum) och sammanställningen (leveransdatum) är godtagbar eller bör följa samma datum, och om kronorna ska avrundas halv upp eller på annat sätt (TODO `EuSalesList.php:41`, ärende #65).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-26 Periodisk sammanställning: månad eller kvartal

- Förutsättningar: som MOMS-25. Faktura 2026-02-10 med varor 400 000,00 (kvartal 1 2026), inga andra varufakturor 2025.
- Steg i appen:
  1. **Bokföring → EU-rapporter**, välj **Period** kvartal 1 2026 när varor ingår och inget beslut är angivet.
  2. Ange under **Företagets uppgifter** att Skatteverket har beslutat om kvartalsredovisning för varor (**Skatteverket har beslutat att periodisk sammanställning för varor får lämnas per kvartal**), **Gäller från och med** 2026-01-01. Öppna kvartal 1 2026 igen.
  3. Lägg till varufaktura 2025-11-20 på 500 000,01 (kvartal 4 2025) och öppna kvartal 1 2026.
  4. Ändra den till exakt 500 000,00 och öppna kvartal 1 2026 igen.
  5. Ett kvartal med enbart tjänster: välj en månad i stället.
- Förväntat resultat: (1) en varning "Försäljning av varor redovisas per månad. Kvartal får användas först när Skatteverket har beslutat det och beslutet är angivet under Företagsuppgifter. Välj en månad."; **Ladda ner fil** går inte att använda. (2) kvartalet tillåts (400 000,00 är högst 500 000,00 i kvartalet och i varje av de fyra kvartalen före); filen får periodkod `26-1` och filnamnet `periodisksammanstallning556000000126K1.txt` (bindestrecket i `26-1` byts mot `K`, `EuSalesList::filename()`). (3) varning: "Varorna var värda 500 000,01 kr under kvartal 4 2025, mer än 500 000 kr. Då upphör beslutet om kvartal och varorna redovisas per månad. Välj en månad." (4) exakt 500 000,00 är tillåtet, gränsen är strikt större än (`EuSalesList::QUARTERLY_GOODS_LIMIT_ORE`). (5) bara tjänster i en månadsperiod visar påminnelsen "Tjänster redovisas per kvartal. Välj en månad bara om Skatteverket har beslutat att företaget ska lämna sammanställningen per månad." Konsulten verifierar: SFL 35 kap. 3-5 och 7 §§, gränsen 500 000 kr och att tjänster per kvartal inte stoppas.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-27 OSS: tröskelvärdet 99 680 kr och val av beskattning i köparens land

- Förutsättningar: kunden "Testperson Tyskland" med land DE, **Försäljning utomlands** satt till **Privatperson i annat EU-land (OSS)**. Företaget har inte valt beskattning i köparens land. Ingen försäljning till EU-privatpersoner 2025. Alla belopp utan moms.
- Steg i appen: skapa fakturor i ordning (varje med en rad, momsen sätts av NONOX):
  1. 2026-02-10, 60 000,00.
  2. 2026-05-10, 39 680,00.
  3. 2026-06-01, 1 000,00.
  4. 2026-07-01, 500,00.
  5. Granska bannern i fakturaeditorn före varje faktura (summan i år och i fjol).
  6. 2027-01-15, 100,00 (året efter).
  7. **Inställningar → Företagsuppgifter**: bocka i **Har ansökt om beskattning i köparens land (OSS) eller är registrerat för OSS** med **Från och med** 2026-02-01 på ett separat testbolag och fakturera 60 000,00 den 2026-02-10.
- Förväntat resultat: tröskeln är 99 680,00 kr (`OssThreshold::AMOUNT_ORE` = 9 968 000 öre). (1) summa efter fakturan 60 000,00 är högst tröskeln: svensk moms 25 %: 15 000,00 på 2611, total 75 000,00. (2) summa efter 60 000,00 + 39 680,00 = 99 680,00, lika med tröskeln, fortfarande svensk moms: 39 680,00 x 25 % = 9 920,00; varning i editorn eftersom 99 680,00 är över 80 % av tröskeln (79 744,00). Gränsen för varningen: 79 744,00 ger varning, 79 743,99 ger ingen (`WARN_PERCENT` 80). (3) summa 100 680,00 överstiger tröskeln, så fakturan får tyska momssatsen 19 % (`OssRates`): 1 000,00 x 19 % = 190,00; verifikation 1510 debet 1 190,00; 3106 kredit 1 000,00; 2619 kredit 190,00. Editorn säger att den och alla senare får momsen i kundens land. (4) 500,00 x 19 % = 95,00 på 2619. (6) 2027: föregående års försäljning (101 180,00) överstiger tröskeln, så även den första fakturan 2027 får 19 %: 100,00 x 19 % = 19,00. (7) valt beskattning i köparens land: 60 000,00 x 19 % = 11 400,00 på 2619 från första fakturan efter angivet datum. Konsulten verifierar: beloppet 99 680 kr och att "högst" är rätt gräns (ML 6 kap. 62-65 §§), att fakturadatumet avgör året, att OSS-registrering räknas som val enligt 6 kap. 64 § och att andra tjänster än elektroniska/TBE-tjänster inte räknas (TODO `OssThreshold.php:27`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### MOMS-28 OSS-underlag per kvartal i kronor och euro

- Förutsättningar: fakturorna 3 och 4 från MOMS-27 (juni och juli 2026) är skapade. ECB:s referenskurs för 2026-06-30 är känd för granskaren (här används kursen 11,0000 kr per euro som exempel; använd verklig kurs vid granskningen).
- Steg i appen:
  1. **Bokföring → EU-rapporter**, under **OSS, försäljning till privatpersoner i andra EU-länder** välj **Kvartal** kvartal 2 2026 och sedan kvartal 3 2026.
  2. Klicka på **Ladda ner underlaget (CSV)**.
  3. Skapa två fakturor 2026-08-01 (båda OSS-fakturor över tröskeln) på 33,33 vardera.
- Förväntat resultat: kvartal 2: endast faktura 3 (faktura 2 är under tröskeln och har svensk moms, den ingår inte). Rad Land DE, Momssats 19,00, underlag 1 000,00 kr, moms 190,00 kr. Euro vid kurs 11,0000: underlag 1 000,00 x 100 / 11,0000 = 9 090,91 -> 90,91 EUR (9 091 cent); moms 9 091 x 19 % = 1 727,29 -> 17,27 EUR (1 727 cent). Kontroll: 190,00 kr / 11,0000 = 17,27 EUR. Kvartal 3 (faktura 4 och de två fakturorna i augusti): rapporten summerar underlaget per land och sats, 500,00 + 33,33 + 33,33 = 566,66 kr, och räknar momsen på summan: 566,66 x 19 % = 107,6654, avrundad till 107,67 kr. Bokföringen räknade moms per faktura: 500,00 x 19 % = 95,00 och 33,33 x 19 % = 6,3327, avrundad till 6,33, två gånger, tillsammans 95,00 + 6,33 + 6,33 = 107,66 kr på 2619. Det är 0,01 kr mellan rapporten och konto 2619. Konsulten bedömer om den öresskillnaden är godtagbar (manualen säger att kronorna "kan skilja sig lite"). CSV-filen har semikolon, decimalkomma, inga tusentalsavgränsare (till exempel `1000,00`) och raderna `Land;Momssats;Beskattningsunderlag SEK;Moms SEK;Beskattningsunderlag EUR;Moms EUR` samt en rad `Summa` och en rad `Kurs;SEK per EUR;11,0000;ECB 2026-06-30`. Konsulten verifierar: momssatserna per land och datum (kontrollera varje land i `OssRates::STANDARD` mot EU:s TEDB, särskilt Finland 25,5 % från 2024-09-01, Estland 24 % från 2025-07-01, Rumänien 21 % från 2025-08-01, Slovakien 23 % från 2025-01-01), vilken kurs som gäller när ECB inte publicerat på kvartalets sista dag, och avrundningen av euromomsen per land och sats (TODO `OssRates.php:17`, `OssReport.php:32`). NONOX har bara normalsatser: sänkta satser stöds inte.
- Kunde inte beräknas här: de verkliga ECB-kurserna och TEDB-satserna för granskningstillfället. Beloppen i euro ovan gäller exemplet med kursen 11,0000.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

---

## Öppna TODO(legal-verify) i koden

Samtliga träffar på `grep -rn "TODO(legal-verify)" app/Domain/Vat app/Support/Skatteverket app/Livewire/Vat` (inga träffar finns i `app/Livewire/Vat`), samt regelkällor i `ImportVat::SOURCES` som har status `TODO(legal-verify)`, samt öppna punkter i ADR-054 steg 1 och ADR-057. Kolumnen Konsultens beslut fylls i med Godkänd, Ändra eller Avslag.

| Nr | Fil:rad | Frågan (sammanfattning) | Konsultens beslut | Kommentar |
|---|---|---|---|---|
| 1 | app/Domain/Vat/VatBoxes.php:33 | Kopplingen mellan konton och rutor följer BAS 2026:s kontonamn. Bekräfta mot aktuell BAS momsrapport och Skatteverkets rutdefinitioner. | | |
| 2 | app/Domain/Vat/VatBoxes.php:91 | VMB: en negativ marginal redovisas som 0 per försäljning, men en kreditering som tar periodens VMB-moms under noll begränsas inte. | | |
| 3 | app/Domain/Vat/VatBoxes.php:125 | Försäljning av anläggningstillgång hör hemma i ruta 05 till sitt pris, men priset bokförs mot tillgångskontot, så momsen visas utan underlag (varning). | | |
| 4 | app/Domain/Vat/VatBoxes.php:140 | Konto 2649 Ingående moms, blandad verksamhet läses in i ruta 48. Bekräfta att saldot är den avdragsgilla delen och inte ett belopp som ännu ska fördelas. | | |
| 5 | app/Domain/Vat/VatReturn.php:24 | Bekräfta att ören tas bort (inte avrundas) per ruta och att ruta 49 är differensen av de avrundade rutorna. | | |
| 6 | app/Domain/Vat/VatReturn.php:133 | Underlaget härleds som moms / sats och är bara så exakt som momsen avrundades. En helkronas differens per verifikation accepteras som avrundning. | | |
| 7 | app/Domain/Vat/EskdFile.php:14 | Elementnamn och ordning följer eSKDUpload 6.0 enligt tredjepartsdokumentation. Validera i Skatteverkets testtjänst, inklusive format på OrgNr och att Period är periodens sista månad (ÅÅÅÅMM). | | |
| 8 | app/Domain/Vat/EuSalesList.php:41 | Varor som faktureras senare än den 15:e i månaden efter leveransen, fortlöpande tjänster över flera perioder, tjänster betalda i förskott, kvartal med både varor (med beslut) och tjänster, avrundning till hela kronor och filens teckenkodning (ISO-8859-1) är inte säkerställda. | | |
| 9 | app/Domain/Vat/EuTrade.php:19 | Räknas distansförsäljning av varor via OSS (3106) som leverans av varor mellan EU-länder för helårsmomsens förfallodag? Räknas inte i dag. | | |
| 10 | app/Domain/Vat/EuTrade.php:26 | Ruta 22 (tjänster köpta utanför EU) räknas som EU-handel eftersom artikel 196 gäller varje leverantör som inte är etablerad i Sverige. Omvänd betalningsskyldighet inom Sverige (ruta 24, 41) räknas inte. | | |
| 11 | app/Domain/Vat/ImportVat.php:29 (även ADR-057 rad 90) | BAS 2026 saknar motkonto för importunderlaget; NONOX använder 4080 och 4540. Bekräfta att det är godtagbart. | | |
| 12 | app/Domain/Vat/ImportVat.php:33 (även ADR-057 rad 70) | Ett sänkande beslut från Tullverket bokförs på beslutets datum. Hör det till beslutets period eller tullräkningens ursprungsperiod? | | |
| 13 | app/Domain/Vat/ImportVat.php:37 (även ADR-057 rad 93) | Full avdragsrätt antas. Blandad verksamhet eller varor som inte används i beskattad verksamhet kan ge lägre avdrag (ML 13 kap.). Användaren ändrar raden på 2645. | | |
| 14 | app/Domain/Vat/ImportVat.php:95 | Regelkälla "base": underlaget är tullvärdet, tull och andra avgifter (ML 8 kap. 24-25 §§). Status TODO(legal-verify). | | |
| 15 | app/Domain/Vat/ImportVat.php:103 | Regelkälla "incidental_costs": bikostnader fram till första bestämmelseorten (ML 8 kap. 26-27 §§). Status TODO(legal-verify). | | |
| 16 | app/Domain/Vat/ImportVat.php:111 | Regelkälla "period": utgående och ingående moms vid import redovisas för den period då tullräkningen ställdes ut (ML 7 kap. 27 och 41 §§). Status TODO(legal-verify). | | |
| 17 | app/Domain/Vat/ImportVat.php:119 | Regelkälla "boxes": fält 50, 60-62 och avdrag i fält 48 (Skatteverket, Köpa varor från länder utanför EU). Status TODO(legal-verify). | | |
| 18 | app/Domain/Vat/ImportVat.php:127 | Regelkälla "method": importmomsen redovisas för tullräkningens period oavsett bokslutsmetod eller faktureringsmetod. Status TODO(legal-verify). | | |
| 19 | app/Domain/Vat/ImportVat.php:135 | Regelkälla "rate": momssatsen vid import är densamma som vid försäljning i Sverige. Status TODO(legal-verify). | | |
| 20 | app/Domain/Vat/OssRates.php:17 | Varje landssats och datum måste kontrolleras mot EU-kommissionens TEDB. Sänkta satser saknas. | | |
| 21 | app/Domain/Vat/OssReport.php:32 | Vilken kurs gäller när ECB inte publicerar på kvartalets sista dag (nästa publicerade används, artikel 369h inte läst), avrundning av euromomsen per land och sats, vilken period en försäljning hör till (fakturadatum) och rättelser av tidigare kvartal. | | |
| 22 | app/Domain/Vat/OssThreshold.php:27 | Beloppet 99 680 kr och dess källa, att året tas från fakturadatum, att OSS-registrering räknas som val enligt 6 kap. 64 §, och att alla fakturor markerade för OSS är distansförsäljning av varor eller TBE-tjänster. | | |
| 23 | app/Support/Skatteverket/Redovisare.php:47 | Ett tiosiffrigt personnummer lagrat med "-" för en enskild näringsidkare som är 100 år eller äldre läses ett århundrade fel. | | |
| 24 | docs/adr/ADR-054-skatteverket-apis.md:124 | Får NONOX Connect lämna innehåll från Skatteverkets API:er (kvittenser, beslutade belopp, kontrollresultat) till en tredjepartsapp? Ingen data lämnas vidare tills Skatteverket svarat. | | |
| 25 | docs/adr/ADR-054-skatteverket-apis.md:135 | Integritetspolicy och registerförteckning ska beskriva inloggningen hos Skatteverket och personuppgifterna innan produktionsnycklar sätts. | | |
| 26 | docs/adr/ADR-054-skatteverket-apis.md:139 (TODO(provider-verify)) | Avgränsare för scope (mellanslag) och förnyelsebegäran: bekräfta i testtjänsten. | | |
| 27 | docs/adr/ADR-054-skatteverket-apis.md:141 | Samma som nr 23 (personnummer med "-" för 100 år eller äldre). | | |

Övrigt som påverkar rutorna men granskas i `docs/granskning/kundfakturor.md`: `app/Domain/Invoicing/ForeignSale.php:23` (konton och rutor för försäljning utomlands), `:96` (fakturatext trepartshandel) och `:148` (trepartshandel och kontantmetoden), samt `app/Domain/Invoicing/InvoiceBooking.php:74` (konto 3231 och ruta 41).

Rader i ADR-054 från och med rad 292 (arbetsgivardeklaration, skattekonto och kundhändelser) hör till andra moduler och ingår inte här.

## Avvikelser

| Nr | Fall | Beskrivning | Allvarlighet | Åtgärd |
|---|---|---|---|---|
| | | | | |
| | | | | |
| | | | | |

## Godkännande

Modulen Moms är granskad och godkänd för att tas ur Beta.

| | |
|---|---|
| Namn | ______________________ |
| Titel/auktorisation | ______________________ |
| Datum | ______________________ |
| NONOX-version | ______________________ |
| Underskrift | ______________________ |

[ ] Godkänd   [ ] Godkänd med villkor   [ ] Ej godkänd

Villkor eller skäl: ______________________________________________

Godkännandet gäller bara modulen Moms.
