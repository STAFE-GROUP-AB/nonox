# Granskningschecklista: Kundfakturor (fakturering, reskontra och inbetalningar)

| | |
|---|---|
| Modul | Kundfakturor: faktura, OCR, kreditfaktura, påminnelse, kundförlust, ROT/RUT, försäljning utomlands, fakturametoden och kontantmetoden, kundreskontra och inbetalningar |
| Version av NONOX som granskas | ______________________ |
| Granskare (namn) | ______________________ |
| Datum | ______________________ |
| Kod/ADR som testas | `app/Domain/Invoicing/InvoiceCalculator.php`, `app/Domain/Invoicing/InvoiceBooking.php`, `app/Domain/Invoicing/Ocr.php`, `app/Domain/Invoicing/TaxReduction.php`, `app/Domain/Invoicing/ForeignSale.php`, `app/Domain/Invoicing/FullInvoice.php`, `app/Domain/Invoicing/IncomingPaymentMatcher.php`, `app/Domain/Invoicing/VatExemptionReason.php`, `app/Actions/Invoicing/` (SaveDraftInvoice, IssueInvoice, CreditInvoice, RegisterPayment, RegisterRefund, SendReminder, WriteOffInvoice, RegisterTaxReductionPayment, RegisterTaxReductionRepayment, BookIncomingPayments, ImportBankFile, CheckVatNumber), `app/Domain/Vat/OssThreshold.php`, `docs/adr/ADR-011-invoicing.md`, `docs/adr/ADR-025-exchange-differences.md`, `docs/adr/ADR-015-incoming-payment-files.md` |
| Manualavsnitt | `docs/manual/sv/fakturering/` (skapa-en-faktura, skicka-en-faktura, kreditfaktura, paminnelser, obetalda-fakturor, inbetalningar, rot-och-rut, forsaljning-till-utlandet, fakturametoden-och-kontantmetoden, faktura-i-utlandsk-valuta, omvand-betalningsskyldighet-bygg, kunder) |

Beslut 7 i `docs/status/manskliga-beslut.md`: en namngiven auktoriserad redovisningskonsult granskar riktiga fall med en checklista per modul. Den här checklistan gäller bara modulen Kundfakturor. Momsdeklarationens rutor, OSS-rapporten och den periodiska sammanställningen granskas i `docs/granskning/moms.md`.

## Förutsättningar

- **Testbolag**: "Testbolag Fakt AB", organisationsnummer 556000-0001 (giltig kontrollsiffra enligt Luhn), momsregistreringsnummer SE556000000101, F-skatt, momsredovisning **Varje månad**, regelverk K2. Räkenskapsår kalenderår 2026, öppet. Kontoplan BAS 2026. **Bokföringsmetod** **Fakturametoden** om inget annat anges. Bankgiro angivet under **Inställningar för företaget**. Allt data är påhittat, inga riktiga bolag eller personer.
- **Testkunder** (**Register → Kunder**, adress `/kunder`): "Testkund AB" (påhittat organisationsnummer 556000-0019, giltigt enligt Luhn), betalningsvillkor 30 dagar, full adress (Testgatan 1, 111 11 Teststad). "Testperson Privat" (privatperson, påhittat personnummer 19121212-1212, ett känt testnummer) för ROT/RUT. Utländska kunder anges i respektive fall.
- **Roll**: Ägare, Administratör, Redovisningskonsult eller Bokförare i testbolaget.
- **Plan/modul**: kundfakturor ingår i grundsystemet. Peppol-utskick ingår inte i den här granskningen.
- **E-post**: i ett testföretag skickas inga mejl, fakturan märks bara som skickad. Granska mejlutskick separat med en riktig testadress vid behov.
- **Demodata**: egen testdatabas (`nonox_demo_*`). Ett nytt testbolag per fall eller en tom period gör att summor och nummer inte blandas ihop mellan fallen. Kör aldrig `migrate:fresh` eller liknande.
- **Så skapar du en faktura**: **Försäljning → Fakturor** (adress `/fakturor`), **Ny faktura** (adress `/fakturor/ny`), välj **Kund**, kontrollera **Fakturadatum** och **Förfallodag**, fyll i raderna (**Benämning**, **Antal**, **Enhet**, **À-pris**, **Moms**, **Rabatt %**), **Spara som utkast**, **Skapa fakturan** och **Ja, skapa**. Fakturans sida visar status, **Bokförd**, **Betalningar** och åtgärderna **Skicka med e-post**, **Registrera betalning**, **Kreditera**, **Skicka påminnelse** och **Kundförlust**.
- **Så läser du bokföringen**: på fakturans sida under **Bokförd** finns en länk till verifikationen. Fakturor bokförs i serie B, inbetalningar och återbetalningar i serie C.
- **Avrundningsreglerna som koden följer** (`app/Domain/Invoicing/InvoiceCalculator.php`): raden = antal x à-pris, minus rabatt, avrundad halv upp till hela öre. Momsen räknas per momssats på summan av raderna med den satsen, avrundad halv upp till hela öre. Totalen avrundas halv upp (bort från noll) till hela kronor på fakturor i kronor, differensen är öresavrundningen på 3740. Fakturor i annan valuta avrundas inte till hela enheter.
- **Betaldag, tak och andelar för ROT och RUT** (`app/Domain/Invoicing/TaxReduction.php`): 30 % för ROT och 50 % för RUT från 2026-01-01, tak 50 000 kr för ROT och 75 000 kr för ROT och RUT tillsammans per kund och år, avdraget avrundas nedåt till hela kronor på arbetskostnaden inklusive moms. Alla dessa värden är markerade `TODO(legal-verify)`.

---

### FAK-01 Grundfall: faktura med 25 % moms, fakturanummer och OCR-nummer

- Förutsättningar: Testkund AB med 30 dagars betalningsvillkor. Fakturametoden. Inga tidigare fakturor.
- Steg i appen:
  1. **Försäljning → Fakturor → Ny faktura**, kund Testkund AB, fakturadatum 2026-09-15, förfallodag tom.
  2. Rad: Benämning "Konsulttimmar", Antal 10, Enhet tim, À-pris 1 250,00, Moms 25 %.
  3. **Skapa fakturan**, **Ja, skapa**. Notera fakturanumret och OCR-numret på fakturans sida och i PDF:en (**Öppna PDF**).
  4. Öppna verifikationen under **Bokförd**.
- Förväntat resultat: rad 10 x 1 250,00 kr = 12 500,00 kr. Moms 12 500,00 kr x 25 % = 3 125,00 kr. Totalt 15 625,00 kr, ingen öresavrundning (redan hela kronor). Förfallodag 2026-09-15 + 30 dagar = 2026-10-15. Verifikation i serie B, daterad 2026-09-15: 1510 debet 15 625,00; 3001 kredit 12 500,00; 2611 kredit 3 125,00. Fakturanumret är nästa löpnummer utan luckor; OCR-numret är fakturanumret följt av en Luhn-kontrollsiffra (`Ocr::forInvoiceNumber`). Exempel: nummer 1029 ger OCR 10298 (1029: dubbla varannan siffra från höger, 9 x 2 = 18 -> 9, 2, 0 x 2 = 0, 1, summa 9 + 2 + 0 + 1 = 12, kontrollsiffra (10 - 12 mod 10) mod 10 = 8). Fler exempel: nummer 1 ger 18, nummer 2 ger 26, nummer 1000 ger 10009, nummer 1030 ger 10306, nummer 9999 ger 99994. Kontrollera att det OCR-nummer fakturan visar följer samma regel för det faktiska numret, att ett felskrivet OCR-nummer (till exempel 10299) inte klarar kontrollsiffran, och att PDF:en visar bankgiro, OCR och sista betaldag. Efter att fakturan skapats går den inte att ändra.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-02 Flera momssatser och öresavrundning

- Förutsättningar: kund Testkund AB.
- Steg i appen: skapa en faktura 2026-09-16 med tre rader: (1) "Vara A", Antal 3, À-pris 199,99, Moms 25 %, intäktskonto 3001. (2) "Vara B", Antal 1, À-pris 49,50, Moms 12 %, intäktskonto 3002. (3) "Vara C", Antal 1, À-pris 20,00, Moms 6 %, intäktskonto 3003. Skapa fakturan och öppna verifikationen.
- Förväntat resultat: rad 1: 3 x 199,99 = 599,97. Rad 2: 49,50. Rad 3: 20,00. Summa exkl. moms 599,97 + 49,50 + 20,00 = 669,47. Moms 25 %: 599,97 x 25 % = 149,9925, avrundad till 149,99. Moms 12 %: 49,50 x 12 % = 5,94. Moms 6 %: 20,00 x 6 % = 1,20. Summa moms 149,99 + 5,94 + 1,20 = 157,13. Före avrundning 669,47 + 157,13 = 826,60; avrundat till 827,00; öresavrundning +0,40. Att betala 827,00. Verifikation serie B: 1510 debet 827,00; 3001 kredit 599,97; 3002 kredit 49,50; 3003 kredit 20,00; 2611 kredit 149,99; 2621 kredit 5,94; 2631 kredit 1,20; 3740 kredit 0,40. Debet 827,00 = kredit 599,97 + 49,50 + 20,00 + 149,99 + 5,94 + 1,20 + 0,40 = 827,00.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-03 Rabatt, decimalantal och avrundning av rader

- Förutsättningar: kund Testkund AB.
- Steg i appen: skapa en faktura 2026-09-17 med två rader, båda 25 % moms på konto 3001: (1) Antal 3, À-pris 123,45, Rabatt % 10. (2) Antal 1,5, À-pris 199,95, Rabatt % 7,5. Skapa fakturan och öppna verifikationen.
- Förväntat resultat: rad 1: 3 x 123,45 = 370,35; rabatt 10 % ger 333,315, avrundad halv upp till 333,32. Rad 2: 1,5 x 199,95 = 299,925; rabatt 7,5 % ger 277,430..., avrundad till 277,43. Summa exkl. moms 333,32 + 277,43 = 610,75. Moms 610,75 x 25 % = 152,6875, avrundad till 152,69. Före avrundning 610,75 + 152,69 = 763,44; avrundat nedåt till 763,00; öresavrundning -0,44. Verifikation serie B: 1510 debet 763,00; 3740 debet 0,44; 3001 kredit 610,75; 2611 kredit 152,69. Debet 763,44 = kredit 763,44. Kontrollera också att en rabatt över 100 % eller en rabatt som inte är ett tal avvisas, och att ett antal med fler än tre decimaler avvisas (`InvoiceCalculator::discount` och `quantity`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-04 Gränsfall för öresavrundning: halva kronor, negativa belopp, noll

- Förutsättningar: kund Testkund AB.
- Steg i appen: skapa fyra fakturor 2026-09-18 med en rad 25 % på 3001: (a) à-pris 10,00. (b) à-pris 9,98. (c) à-pris 9,99. (d) à-pris 0,00. Skapa en kreditfaktura mot (a) för hela beloppet (se FAK-06).
- Förväntat resultat: (a) 10,00 + moms 2,50 = 12,50; avrundas halv upp till 13,00; öresavrundning +0,50 (3740 kredit 0,50). (b) moms 9,98 x 25 % = 2,495, avrundad till 2,50; 9,98 + 2,50 = 12,48; avrundas till 12,00; öresavrundning -0,48 (3740 debet 0,48). (c) moms 9,99 x 25 % = 2,4975, avrundad till 2,50; 9,99 + 2,50 = 12,49; avrundas till 12,00; öresavrundning -0,49. (d) en faktura utan belopp stoppas ("fakturan behöver minst en rad och ett belopp"). Kreditfakturan mot (a): -10,00 - 2,50 = -12,50, avrundas bort från noll till -13,00; öresavrundning -0,50 (3740 debet 0,50), så originalet och krediten tar ut varandra exakt: 1510 hamnar på 0. Konsulten verifierar att halv upp bort från noll (även för negativa belopp) är den metod som bolaget tillämpar enligt god redovisningssed och att öresavrundningen får bokföras på 3740.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-05 Datum: förfallodag, spärrar för period och räkenskapsår

- Förutsättningar: Testkund AB med 30 dagars betalningsvillkor. Perioden till och med 2026-08-31 är låst (**Lås till och med** under Moms eller Lås period). Räkenskapsåret 2026 är öppet.
- Steg i appen:
  1. Ny faktura med fakturadatum 2026-01-31, förfallodag tom. Spara som utkast och läs förfallodagen.
  2. Samma utkast med förfallodag 2026-01-30. Spara.
  3. Ny faktura med fakturadatum 2026-08-15 (i låst period). Försök skapa den.
  4. Ny faktura med fakturadatum 2027-01-05 (nästa räkenskapsår som inte finns). Försök skapa den.
  5. Fyll i **Leveransdatum** 2026-09-20 på en faktura daterad 2026-09-25.
- Förväntat resultat: (1) förfallodag 2026-01-31 + 30 dagar = 2026-03-02. (2) avvisas: "Förfallodagen kan inte vara före fakturadatum." (3) avvisas med meddelande om datumet, eftersom fakturametoden kräver ett öppet räkenskapsår och en period som inte är låst. (4) avvisas, det finns inget öppet räkenskapsår för datumet. (5) PDF och faktura visar leveransdatum 2026-09-20. Tomt leveransdatum betyder samma dag som fakturadatum.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-06 Kreditfaktura: hel kreditering av obetald faktura

- Förutsättningar: faktura enligt FAK-01 (15 625,00 kr, obetald). Fakturanumret är N.
- Steg i appen:
  1. Öppna fakturan, under **Kreditera** klicka **Kreditera fakturan** och **Ja, förbered kreditfaktura**.
  2. Kontrollera utkastet (samma kund och rader, meddelandet "Kreditering av faktura N.").
  3. Klicka **Skapa kreditfakturan** och **Ja, skapa**.
  4. Öppna originalets och kreditfakturans sidor. Skapa därefter en ny faktura och läs dess nummer.
- Förväntat resultat: kreditfakturan har negativa belopp: -12 500,00 + moms -3 125,00 = -15 625,00. Verifikation serie B: 1510 kredit 15 625,00; 3001 debet 12 500,00; 2611 debet 3 125,00. Originalet får status **Krediterad**, kreditfakturan **Betald**, ingen väntar på betalning. Originalets sida visar **Krediterad med** med en länk, kreditfakturans **Krediterar**. Kreditfakturan får nästa löpnummer i samma nummerserie som fakturorna (N + 1), och nästa vanliga faktura N + 2: ingen lucka. Knappen **Kreditera** syns inte längre på originalet. Kreditfakturan följer originalets valuta, kurs, ROT/RUT-val och försäljning utomlands.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-07 Delkreditering och kreditering av betald faktura med återbetalning

- Förutsättningar: tre fakturor på 15 625,00 kr enligt FAK-01 (kalla dem F1, F2, F3).
- Steg i appen:
  1. F1 (obetald): kreditera men ändra raden till 2 000,00 netto. **Skapa kreditfakturan**.
  2. F2: registrera full betalning 15 625,00 (se FAK-08), kreditera sedan hela fakturan. Öppna kreditfakturan: **Betala tillbaka till kunden**, fyll i **Betaldag** och **Belopp** 15 625,00, klicka **Registrera återbetalningen**.
  3. F3: registrera delbetalning 5 000,00, kreditera hela fakturan, betala tillbaka 5 000,00 till kunden.
  4. På F1: försök kreditera mer än vad som återstår (se andra delkrediteringen: 14 000,00 netto).
- Förväntat resultat:
  - Steg 1: kredit 2 000,00 netto, moms 2 000,00 x 25 % = 500,00, totalt -2 500,00. Serie B: 1510 kredit 2 500,00; 3001 debet 2 000,00; 2611 debet 500,00. F1 står kvar som **Obetald** med 15 625,00 - 2 500,00 = 13 125,00 att betala, och **Krediterat** visar 2 500,00.
  - Steg 2: kreditfakturan -15 625,00 får status **Att återbetala**; beloppet är en skuld till kunden (kredit på 1510). Återbetalningen bokförs i serie C: 1510 debet 15 625,00; 1930 kredit 15 625,00. Kreditfakturan blir **Betald**.
  - Steg 3: den krediterade 15 625,00 minskar först det som återstår att betala, 10 625,00; de 5 000,00 kunden redan betalat blir pengar att få tillbaka. Återbetalning: 1510 debet 5 000,00; 1930 kredit 5 000,00. 1510 för F3 är 0 efteråt.
  - Steg 4: en kredit som är större än det som ännu inte är krediterat avvisas (13 125,00 återstår; 14 000,00 netto + 3 500,00 moms = 17 500,00 > 13 125,00).
  - Konsulten verifierar att kreditfaktura på betald faktura och återbetalning bokförs så (BFL 4 kap. 1 §, 5 kap. 1 §; ML 17 kap. 22 §) och att kundreskontran och 1510 stämmer efter varje steg (se FAK-23).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-08 Inbetalning: del, hel och för stor betalning

- Förutsättningar: faktura på 15 625,00 kr enligt FAK-01, obetald.
- Steg i appen:
  1. Öppna fakturan, under **Registrera betalning** sätt **Betaldag** 2026-10-05 och **Belopp** 5 000,00. Klicka **Registrera betalningen**.
  2. Registrera därefter belopp 10 626,00.
  3. Registrera belopp 0,00.
  4. Registrera belopp 10 625,00 med betaldag 2026-10-12.
- Förväntat resultat: (1) meddelande om att 5 000,00 är registrerat och att 10 625,00 återstår; fakturan är fortfarande **Obetald**. Verifikation serie C, 2026-10-05: 1930 debet 5 000,00; 1510 kredit 5 000,00. (2) avvisas: "Beloppet är större än det som återstår, 10 625,00 kr." (3) avvisas: "Skriv ett belopp större än noll." (4) verifikation serie C, 2026-10-12: 1930 debet 10 625,00; 1510 kredit 10 625,00. Fakturan blir **Betald**. 1510 för fakturan är 0. En registrerad betalning går inte att ta bort från fakturan.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-09 Kontantmetoden: del- och slutbetalning av en faktura med moms

- Förutsättningar: **Bokföringsmetod** satt till **Kontantmetoden**. Faktura enligt FAK-01 (12 500,00 + moms 3 125,00 = 15 625,00).
- Steg i appen:
  1. Skapa fakturan och kontrollera att **Bokförd** visar "Vid betalning (kontantmetoden)" och att ingen verifikation finns.
  2. Registrera betalning 5 000,00 (2026-10-05).
  3. Registrera slutbetalning 10 625,00 (2026-10-12).
- Förväntat resultat: vid skapandet bokförs ingenting. Delbetalning 5 000,00 av totalt 15 625,00 = 32 %: intäkt 12 500,00 x 32 % = 4 000,00 och moms 3 125,00 x 32 % = 1 000,00. Verifikation serie C 2026-10-05: 1930 debet 5 000,00; 3001 kredit 4 000,00; 2611 kredit 1 000,00. Slutbetalningen tar det som återstår av varje rad: 3001 12 500,00 - 4 000,00 = 8 500,00; 2611 3 125,00 - 1 000,00 = 2 125,00. Verifikation serie C 2026-10-12: 1930 debet 10 625,00; 3001 kredit 8 500,00; 2611 kredit 2 125,00. Tillsammans 3001 = 12 500,00, 2611 = 3 125,00. Konsulten verifierar ML 7 kap. 16 § och 17 kap. 24 § p. 11 (TODO i `InvoiceBooking.php:49`, ärende #52).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-10 Kontantmetoden: öresfördelning vid delbetalning

- Förutsättningar: kontantmetoden. Faktura 2026-10-01: 1 000,00 + moms 250,00 = 1 250,00.
- Steg i appen: registrera betalning 333,33 (2026-10-10), sedan slutbetalning 916,67 (2026-10-20).
- Förväntat resultat: första betalningen 333,33 av 1 250,00: 3001 1 000,00 x 333,33 / 1 250,00 = 266,664, avrundad till 266,66; 2611 250,00 x 333,33 / 1 250,00 = 66,666, avrundad till 66,67; summa 266,66 + 66,67 = 333,33, ingen rest. Verifikation: 1930 debet 333,33; 3001 kredit 266,66; 2611 kredit 66,67. Slutbetalningen tar resten: 3001 1 000,00 - 266,66 = 733,34; 2611 250,00 - 66,67 = 183,33; summa 916,67. Verifikation: 1930 debet 916,67; 3001 kredit 733,34; 2611 kredit 183,33. Tillsammans 3001 = 1 000,00 och 2611 = 250,00 exakt. Om delbetalningarna skulle ge en rest läggs den på den största raden så att verifikationen balanserar (`InvoiceBooking::cashShare`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-11 Påminnelse: ingen avgift och ingen dröjsmålsränta

- Förutsättningar: faktura 15 625,00 med förfallodag 2026-10-15, delbetalad med 5 000,00. Dagens datum (eller testdatumet) är efter förfallodagen.
- Steg i appen:
  1. **Försäljning → Fakturor**, filter **Förfallna**, öppna fakturan.
  2. Kontrollera adressen under **Skicka med e-post** och klicka **Skicka påminnelse** under **Påminnelser**.
  3. Skicka en andra påminnelse.
  4. Försök skicka påminnelse på en betald faktura, en ej förfallen och en kreditfaktura.
- Förväntat resultat: meddelandet "Påminnelse 1 är skickad till … Ny sista betaldag: …" där sista betaldag är avsändningsdagen + 10 dagar (`SendReminder::DAYS_TO_PAY`). Påminnelsen anger det som återstår, 10 625,00 kr (15 625,00 - 5 000,00), OCR-numret och fakturan som bilaga; ingen påminnelseavgift och ingen dröjsmålsränta läggs på. Den andra påminnelsen får nummer 2. Inget bokförs. Knappen **Skicka påminnelse** syns inte för betald, ej förfallen eller kreditfaktura. I ett testföretag skickas inget mejl. Konsulten bedömer: att NONOX inte tar ut avgift eller ränta är rätt hållning tills reglerna är fastställda (räntelagen 6 §: dröjsmålsränta är referensränta plus åtta procentenheter, och avtalad påminnelseavgift; Inkassolagen), och om manualens text om att fakturera avgift och ränta separat är tillräcklig. Konsulten verifierar (TODO `SendReminder.php:26`).
- Kunde inte beräknas här: dröjsmålsränta, eftersom NONOX inte räknar den och referensräntan för halvåret måste hämtas från Riksbanken.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-12 Kundförlust: hel och delvis (fakturametoden)

- Förutsättningar: fakturametoden. Två fakturor på 15 625,00 (12 500,00 + moms 3 125,00): F1 obetald, F2 delbetald med 5 000,00.
- Steg i appen: öppna F1, gå till **Kundförlust**, fyll i **Bokförs den** 2026-12-15 och **Varför fordringen är förlorad** ("Kunden försatt i konkurs"), klicka **Skriv bort** och bekräfta. Gör samma sak på F2. Registrera sedan en sen betalning på F1 om appen tillåter.
- Förväntat resultat: F1: hela 15 625,00 skrivs bort. Verifikation serie B: 1510 kredit 15 625,00; 6351 debet 12 500,00; 2611 debet 3 125,00. Förlusten är 15 625,00 - 3 125,00 = 12 500,00. F2: återstår 10 625,00. Momsandel 3 125,00 x 10 625,00 / 15 625,00 = 2 125,00; förlust 10 625,00 - 2 125,00 = 8 500,00. Verifikation: 1510 kredit 10 625,00; 6351 debet 8 500,00; 2611 debet 2 125,00. Fakturorna får status Kundförlust och räknas inte som obetalda; reskontran stämmer med 1510. Momsdeklarationen för december minskar ruta 10 med 3 125 + 2 125 = 5 250, men ruta 05 (underlaget) minskar inte eftersom ingen intäktsrad rörs; momssidan visar då en varning om "Utgående moms utan försäljning i ruta 05" med ett negativt belopp. Konsulten bedömer: att underlaget i ruta 05 ska minskas vid konstaterad kundförlust och att varningstexten (som säger "mer i underlag", med negativt tal) blir begriplig. Under kontantmetoden bokförs ingenting och fakturan stängs bara. Konsulten verifierar när en förlust är konstaterad, konto 6351 och momsåterföringen (ML 8 kap. 16 §, 7 kap. 43-44 §§; TODO `WriteOffInvoice.php:42`, `InvoiceBooking.php:61`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-13 ROT-avdrag: faktura, kundens betalning och Skatteverkets betalning

- Förutsättningar: fakturametoden. Kunden Testperson Privat med personnummer. Ingen tidigare ROT/RUT till kunden.
- Steg i appen:
  1. Ny faktura 2026-09-20, **ROT- eller RUT-avdrag** = **ROT-avdrag**, **Fastighetsbeteckning eller BRF** "Teststad Testen 1:1".
  2. Rad 1: "Snickeriarbete", Antal 1, À-pris 40 000,00, Moms 25 %, bocka i **Arbete**. Rad 2: "Material", Antal 1, À-pris 8 000,00, Moms 25 %, ingen bock.
  3. **Spara som utkast**, kontrollera beloppen, **Skapa fakturan**.
  4. Registrera kundens betalning 45 000,00 (2026-10-05).
  5. Under **ROT-avdrag hos Skatteverket** registrera **Betaldag** 2026-11-10 och **Belopp** 15 000,00, **Registrera Skatteverkets betalning**.
- Förväntat resultat: arbetskostnad 40 000,00 + moms 10 000,00 = 50 000,00. ROT 30 % x 50 000,00 = 15 000,00. Hela fakturan: netto 40 000,00 + 8 000,00 = 48 000,00; moms 12 000,00; totalt 60 000,00; kunden betalar 60 000,00 - 15 000,00 = 45 000,00. Fakturan visar "Avgår ROT-avdrag", arbetskostnaden inklusive moms, fastigheten och texten att avdraget förutsätter att Skatteverket godkänner det. Verifikation serie B: 1510 debet 45 000,00; 1513 debet 15 000,00; 3001 kredit 48 000,00; 2611 kredit 12 000,00. Kundens betalning serie C: 1930 debet 45 000,00; 1510 kredit 45 000,00. Skatteverkets betalning serie C: 1930 debet 15 000,00; 1513 kredit 15 000,00. Båda ordningarna får gå. Under kontantmetoden stoppas fakturan ("ROT- och RUT-avdrag fungerar än så länge bara med fakturametoden"). En kund utan personnummer eller en faktura utan arbetsrad avvisas med meddelande. Konsulten verifierar andelen 30 %, taket, nedåtavrundningen, vilka kostnader som är arbete och fakturakraven (TODO `TaxReduction.php:30`, `IssueInvoice.php:383`, `FullInvoice.php:29`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-14 RUT-avdrag, nedåtavrundning, tak och datumgräns

- Förutsättningar: fakturametoden. Kunden Testperson Privat.
- Steg i appen:
  1. RUT-faktura (**RUT-avdrag**), en arbetsrad: Antal 1, À-pris 666,67, Moms 25 %. Skapa.
  2. ROT-faktura med arbetskostnad 200 000,00 netto, 25 % moms, en arbetsrad. Spara som utkast och läs avdraget.
  3. Skapa först på samma kund tidigare fakturor med ROT-avdrag 40 000,00 och RUT-avdrag 30 000,00 (förfallodag i 2026). Ny ROT-faktura med arbetskostnad inklusive moms 20 000,00, sedan en ny RUT-faktura med arbetskostnad inklusive moms 20 000,00.
  4. ROT-faktura med förfallodag 2025-12-31, därefter med förfallodag 2026-01-01.
  5. ROT-faktura med arbetskostnad 123,45 inklusive moms (arbetsrad 98,76 + moms 24,69).
- Förväntat resultat: (1) moms 666,67 x 25 % = 166,6675, avrundad till 166,67; arbetskostnad inklusive moms 833,34; RUT 50 % x 833,34 = 416,67 nedåt till 416,00. Totalt 666,67 + 166,67 = 833,34, avrundat till 833,00 (öresavrundning -0,34); kunden betalar 833,00 - 416,00 = 417,00. Verifikation: 1510 debet 417,00; 1513 debet 416,00; 3740 debet 0,34; 3001 kredit 666,67; 2611 kredit 166,67. Debet 833,34 = kredit 833,34. (2) arbetskostnad inklusive moms 250 000,00; 30 % = 75 000,00, begränsat till ROT-taket 50 000,00. (3) kvarvarande utrymme: totalt 75 000,00 - (40 000,00 + 30 000,00) = 5 000,00; ROT-utrymme min(5 000,00; 50 000,00 - 40 000,00 = 10 000,00) = 5 000,00. Ny ROT: 30 % x 20 000,00 = 6 000,00, begränsat till 5 000,00. Ny RUT: 50 % x 20 000,00 = 10 000,00, begränsat till 5 000,00 (totaltaket). (4) förfallodag 2025-12-31: ingen regel finns för datumet, utkastet avvisas med "Det finns ingen regel för ROT-avdrag på förfallodagen." (andelen finns bara från 2026-01-01); förfallodag 2026-01-01 ger 30 %. (5) 30 % x 123,45 = 37,035, nedåt till hela kronor = 37,00 (RUT 50 % ger 61,00). Konsulten verifierar andelar och tak för varje år fakturorna kan betalas, att avdraget hör till betalningsåret, att taket bara kontrolleras mot bolagets egna fakturor och att nedåtavrundning till hela kronor är rätt (TODO `TaxReduction.php:30`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-15 Kreditering av faktura med ROT-avdrag

- Förutsättningar: ROT-faktura enligt FAK-13 (arbete 50 000,00 inklusive moms, avdrag 15 000,00, totalt 60 000,00).
- Steg i appen:
  1. Kreditera hela fakturan innan Skatteverket betalat (Skatteverkets betalning ännu inte registrerad).
  2. På en annan sådan faktura: delkreditera, ändra till en arbetsrad på 10 000,00 netto (12 500,00 inklusive moms).
  3. På en tredje faktura: registrera Skatteverkets betalning 15 000,00 först, kreditera sedan hela fakturan.
- Förväntat resultat: (1) hel kredit tar exakt avdraget som stod på fakturan, 15 000,00: serie B: 1510 kredit 45 000,00; 1513 kredit 15 000,00; 3001 debet 48 000,00; 2611 debet 12 000,00. (2) delkredit: andel = 15 000,00 x 12 500,00 / 50 000,00 = 3 750,00, avrundad nedåt till hela kronor = 3 750,00. Kredit netto 10 000,00 + moms 2 500,00 = 12 500,00; kundens del 12 500,00 - 3 750,00 = 8 750,00. Serie B: 1510 kredit 8 750,00; 1513 kredit 3 750,00; 3001 debet 10 000,00; 2611 debet 2 500,00. Krediten som tar sista arbetet tar resten av avdraget (15 000,00 - 3 750,00 = 11 250,00). (3) eftersom Skatteverket redan betalat blir avdraget en skuld till Skatteverket i stället för en mindre fordran: serie B: 1510 kredit 45 000,00; 2890 kredit 15 000,00; 3001 debet 48 000,00; 2611 debet 12 000,00. Debet 60 000,00 = kredit 60 000,00. Fakturans sida påminner om att anmäla ändringen till Skatteverket utan dröjsmål, före och efter krediteringen. När kunden fått tillbaka pengarna registreras Skatteverkets återbetalning på kreditfakturan under **ROT-avdrag hos Skatteverket**. Konsulten verifierar konto 2890 och lag (2009:194) 13-14 §§ (TODO `TaxReduction.php:50`, ärende #49).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-16 Försäljning till företag i annat EU-land, varor (3108)

- Förutsättningar: kunden "Testkund GmbH", land DE, momsregistreringsnummer DE123456789, **Försäljning utomlands** = **Varor till företag i annat EU-land**, full adress. Testbolaget har momsregistreringsnummer.
- Steg i appen:
  1. På kunden klicka **Kontrollera i VIES**.
  2. Skapa faktura 2026-09-21, en rad 50 000,00, Moms sätts till 0 % av NONOX. Skapa fakturan.
  3. Upprepa med en kund med land SE men samma försäljningsval, en kund utan momsregistreringsnummer från sitt land, och (om VIES-kontrollen gav ogiltigt) kunden med ogiltigt nummer.
  4. Ta bort testbolagets momsregistreringsnummer och försök skapa en EU-faktura.
- Förväntat resultat: fakturan är utan moms, totalt 50 000,00, texten "Unionsintern leverans av varor, undantagen från moms. / Intra-Community supply of goods, exempt from VAT." Verifikation serie B: 1510 debet 50 000,00; 3108 kredit 50 000,00. Fakturan hamnar i ruta 35 i momsdeklarationen och i periodisk sammanställning (se MOMS-25). Under kontantmetoden bokförs den ändå vid fakturan; betalningen flyttar bara 1510 till banken. Steg 3 och 4: fakturan stoppas när kundens land inte är ett annat EU-land, när kunden saknar momsregistreringsnummer från sitt land, när kundens nummer var ogiltigt vid senaste VIES-kontrollen, och när det egna momsregistreringsnumret saknas. Kundens fullständiga adress krävs alltid vid EU-försäljning. Konsulten verifierar (TODO `IssueInvoice.php:325`, `CheckVatNumber.php:25`, `ForeignSale.php:23`) kraven på VIES-kontroll och bevis för att varorna lämnat Sverige, fakturatexten och ML 7 kap. 29-30 §§ (ärende #50).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-17 Export, tjänster utanför EU, tjänster inom EU och trepartshandel

- Förutsättningar: kund "Test Inc" i US, kund "Testkund GmbH" i DE (tjänster), kund "Testkund SA" i FR med momsnummer FR12345678901.
- Steg i appen: fakturor 2026-09-22 med **Försäljning utomlands**: **Export av varor utanför EU** 20 000,00; **Tjänster till kund utanför EU** 4 000,00; **Tjänster till företag i annat EU-land** 7 000,00; **Trepartshandel inom EU, som mellanman** 12 000,00. Försök också att fakturera export till en kund med land DE, och en tjänst till EU-kund med land SE.
- Förväntat resultat: serie B: export 1510 debet 20 000,00; 3105 kredit 20 000,00 (text "Export av varor, undantagen från moms. / Export of goods, exempt from VAT."). Tjänster utanför EU: 3305 4 000,00 ("Tjänsten är omsatt utanför Sverige. / Supply of services outside Sweden, not subject to Swedish VAT."). Tjänster inom EU: 3308 7 000,00 ("Omvänd betalningsskyldighet. / Reverse charge."). Trepartshandel: 3107 12 000,00, kontot skapas och kopplas till ruta 38 första gången; texten hänvisar till artikel 141 i mervärdesskattedirektivet. Ingen moms på någon av dem. Rutor: 36, 40, 39 och 38 (se MOMS-12). Export till kund i DE och EU-tjänst till kund i SE avvisas. Manualen säger att trepartshandelns text lyder "Trepartshandel, köparen redovisar momsen", men koden skriver "Unionsintern trepartshandel, förenklingsregeln i artikel 141 i mervärdesskattedirektivet. Omvänd betalningsskyldighet." Notera avvikelsen mellan manual och kod. Konsulten verifierar kontona, rutorna och texterna (TODO `ForeignSale.php:23`, `:96`, `:148`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-18 Omvänd betalningsskyldighet för byggtjänster och spärrar

- Förutsättningar: kunden "Testbygg AB" med momsregistreringsnummer SE556000000201 och **Omvänd betalningsskyldighet för byggtjänster** ikryssad. Testbolaget har momsregistreringsnummer.
- Steg i appen:
  1. Faktura 2026-09-23, rad 30 000,00. Skapa.
  2. Samma kund utan momsregistreringsnummer: försök skapa.
  3. Bocka i ROT-avdrag på en sådan faktura, och försäljning utomlands på en annan.
  4. Kreditera fakturan i steg 1.
- Förväntat resultat: fakturan blir utan moms, texten "Omvänd betalningsskyldighet för byggtjänster" och båda momsregistreringsnumren skrivs ut. Verifikation serie B: 1510 debet 30 000,00; 3231 kredit 30 000,00. Ruta 41 = 30 000. Steg 2 avvisas (saknat nummer). Steg 3 avvisas (kan inte kombineras). Steg 4: kreditfakturan behåller originalets val (1510 kredit 30 000,00; 3231 debet 30 000,00). Konsulten verifierar fakturatexten, kraven och konto 3231 (TODO `IssueInvoice.php:301`, `InvoiceBooking.php:74`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-19 Faktura i euro och kursdifferens vid betalning

- Förutsättningar: kunden "Testkund GmbH" enligt FAK-16 med **Valuta för fakturor** EUR.
- Steg i appen:
  1. Faktura 2026-09-24, **Valuta** EUR, **Kurs, kronor per EUR** ändrad till 11,2000 (granskaren anger kursen, normalt Riksbankens). Rad 1 000,00 EUR. Skapa.
  2. Registrera betalning: **Betaldag** 2026-10-08, **Belopp i EUR** 400,00, **Kronor in på banken** 4 460,00.
  3. Registrera slutbetalning: **Belopp i EUR** 600,00, **Kronor in på banken** 6 780,00.
  4. Försök registrera 600,01 EUR på en ny identisk faktura.
- Förväntat resultat: fakturan i euro avrundas inte till hela enheter; 1 000,00 EUR x 11,2000 = 11 200,00 kr. Verifikation serie B: 1510 debet 11 200,00; 3108 kredit 11 200,00. Betalning 1: bokförd andel 11 200,00 x 400,00 / 1 000,00 = 4 480,00 kr; in på banken 4 460,00; kursförlust 4 480,00 - 4 460,00 = 20,00 kr. Serie C: 1930 debet 4 460,00; 7960 debet 20,00; 1510 kredit 4 480,00. Betalning 2 (den sista tar det som återstår): 11 200,00 - 4 480,00 = 6 720,00 kr; in på banken 6 780,00; kursvinst 6 780,00 - 6 720,00 = 60,00 kr. Serie C: 1930 debet 6 780,00; 1510 kredit 6 720,00; 3960 kredit 60,00. Debet 6 780,00 = kredit 6 780,00. Fakturan blir **Betald**, 1510 går jämnt ut. Steg 4: avvisas, beloppet är större än det som återstår. Fakturan visar under "I svenska kronor" momsen i kronor, kursen med dag och källa, samt IBAN och BIC om de finns. Konsulten verifierar om Riksbankens kurs får användas för momsen (artikel 91 och 230 i mervärdesskattedirektivet, ML 8 kap. 21 §; TODO `SaveDraftInvoice.php:54`, `InvoicePdf.php:21`) och kontona 3960 och 7960.
- Kunde inte beräknas här: Riksbankens och ECB:s verkliga kurser vid granskningstillfället. Fallet använder en kurs som granskaren anger.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-20 Fullständig faktura: kundens adress över 4 000 kr

- Förutsättningar: kunden "Testkund Utan Adress AB" med organisationsnummer men utan gatuadress, postnummer och ort. Kunden "Testperson Utan Adress" (privatperson) utan adress.
- Steg i appen:
  1. Faktura till företaget, rad netto 3 200,00 + 25 % moms = 4 000,00. Skapa.
  2. Faktura till företaget, rad netto 3 200,80 + 25 % moms 800,20 = 4 001,00. Skapa.
  3. Kreditfaktura mot den första. Skapa.
  4. Faktura till privatpersonen på 10 000,00 (ingen ROT). Skapa.
  5. Faktura till kund i annat EU-land utan adress, 100,00. Skapa.
- Förväntat resultat: (1) skapas, 4 000,00 överstiger inte 4 000,00 (gränsen är större än 4 000,00 inklusive moms, `FullInvoice::SIMPLIFIED_LIMIT_ORE` = 400 000 öre). (2) stoppas, 4 001,00 överstiger gränsen och kundens adress saknas; meddelandet hänvisar till att fylla i adressen under Kunder (ML 17 kap. 24 §). (3) skapas, kreditfakturor stoppas inte. (4) skapas, privatpersoner (utom vid ROT) stoppas inte. (5) stoppas, alla fakturor till EU-företag kräver adress. Konsulten verifierar gränsen 4 000 kr, att kund utan organisations-, momsregistrerings- eller personnummer behandlas som privatperson och att varje ROT-arbete räknas som byggtjänst (TODO `FullInvoice.php:29`, ärende #55).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-21 Rad med 0 % moms: skäl på fakturan och företag som inte är momsregistrerat

- Förutsättningar: kund Testkund AB. Ett andra testbolag som inte är momsregistrerat (**Momsredovisning** = **Inte momsregistrerad**), med **Momsbefrielse** satt under **Företagets uppgifter**.
- Steg i appen:
  1. Faktura i testbolaget med en rad 1 000,00 och Moms 0 %. Välj i listan under momssatsen **Fastighet, uthyrning av lokal eller bostad**. Skapa och öppna PDF.
  2. Samma faktura men utan att välja skäl.
  3. Rad med skäl **Utanför momsen, till exempel utlägg**.
  4. I det ej momsregistrerade bolaget: skapa en faktura med 25 % moms utan att bocka i **Ta ändå ut moms på fakturan**, och sedan med ikryssning.
- Förväntat resultat: (1) fakturan skrivs ut med skälet och beloppet utan moms (1 000,00 kr), totalt 1 000,00. (2) fakturan skrivs ut med standardtexten "Undantagen från skatteplikt för mervärdesskatt". (3) fakturan skrivs ut med texten för utlägg. (4) utan ikryssning stoppas fakturan; med ikryssning skapas den med moms (1 000,00 + 250,00) och den utgående momsen visas som en varning på sidan Moms (se MOMS-21) eftersom den ska betalas till staten (ML 16 kap. 23 §). Fakturan utan moms från ett ej momsregistrerat bolag bär texten för valt skäl till momsbefrielse. Konsulten verifierar texterna och hänvisningarna (ML 10 kap., 17 kap. 24 § p. 13; TODO `VatExemptionReason.php:19`, ärendena #54 och #51).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-22 Inbetalningar från bankens fil (camt.054) och OCR-matchning

- Förutsättningar: fakturan från FAK-01 (OCR-nummer enligt FAK-01, 15 625,00 kr, obetald) och en andra obetald faktura. Skapa en testfil i formatet ISO 20022 camt.054 med mönster från `tests/Fixtures/camt/bankgiro-credits.xml` (samma uppbyggnad, påhittade bolag, tomma eller påhittade IBAN). Ange fakturans verkliga OCR-nummer.
- Steg i appen:
  1. **Försäljning → Inbetalningar** (adress `/inbetalningar`). Dra filen till rutan under **Läs in bankens fil**.
  2. Granska förslagen. Filen innehåller: (a) en inbetalning 15 625,00 med OCR-nummer som referens; (b) en inbetalning 5 000,00 med meddelandet "Betalning OCR-NUMMER" där OCR-nummer är den andra fakturans OCR; (c) en inbetalning 3 000,00 med meddelandet "Faktura NN" där NN är fakturanumret, inte OCR-numret; (d) en inbetalning 20 000,00 med den första fakturans OCR (större än det som återstår); (e) en debetpost.
  3. Bocka i förslagen, klicka **Bokför valda**.
  4. Läs in samma fil igen.
  5. Bokför (c) för hand genom att välja fakturan i listan.
- Förväntat resultat: (a) föreslår fakturan; (b) föreslår den andra fakturan eftersom meddelandet innehåller dess OCR-nummer; (c) föreslår ingen faktura (fakturanumret är inte ett OCR-nummer); (d) föreslår ingen faktura eftersom beloppet är större än det som återstår; (e) debetposten tas inte med. Efter **Bokför valda**: serie C på bankens bokföringsdag, 1930 debet 15 625,00; 1510 kredit 15 625,00 för (a), och motsvarande för (b), och fakturorna prickas av. Steg 4: de redan inlästa betalningarna hoppas över och sidan visar hur många som redan fanns. Steg 5: bokförs som en vanlig betalning. Ingenting bokförs före **Bokför valda**. Konsulten verifierar att OCR-nummer och bankens referenser tolkas rätt och att överbetalningar (större belopp än återstående) inte kan bokföras utan manuell hantering, vilket manualen anger som "det som inte går än".
- Kunde inte beräknas här: de exakta kontosiffrorna och bankens referenser i granskarens testfil.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-23 Kundreskontra mot konto 1510

- Förutsättningar: ett antal fakturor, krediteringar, betalningar och en kundförlust enligt tidigare fall (fakturametoden).
- Steg i appen:
  1. **Bokföring → Reskontra** (adress `/reskontra`), välj **Kundreskontra (1510)** och en dag efter de senaste händelserna.
  2. Välj en dag mitt i händelseförloppet (till exempel en dag efter en faktura men före dess betalning).
  3. Bokför en vanlig verifikation direkt på 1510: 1510 debet 100,00; 3001 kredit 100,00 (utan faktura). Öppna reskontran igen.
- Förväntat resultat: (1) sidan visar fakturorna som var obetalda den dagen, deras summa, saldot på 1510 samma dag och differensen 0,00: texten "Reskontran stämmer med kontot." (2) reskontran byggs om från fakturans datum och datumen för betalningar, kreditfakturor och kundförluster, så den visar samma sak för det datumet även när senare betalningar kommit. (3) differensen blir 100,00 och texten säger "Reskontran stämmer inte med kontot. Leta efter verifikationer som är bokförda direkt på kontot, eller fakturor som inte är bokförda." ROT/RUT-delen ligger på 1513 och ingår inte i 1510-avstämningen. Under kontantmetoden finns inga kundfordringar i bokföringen under året.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### FAK-24 Kontantmetoden och EU-försäljning, årsskiftet

- Förutsättningar: kontantmetoden. Faktura 2026-12-20 på 15 625,00 (12 500,00 + 3 125,00), betalas 2027-01-10. Faktura till EU-företag (se FAK-16) 2026-12-21 på 50 000,00, betalas 2027-01-15.
- Steg i appen: skapa fakturorna i december 2026, registrera betalningarna i januari 2027 (kräver att räkenskapsår 2027 finns). Granska bokföringen i december och januari och momsrutorna för perioderna.
- Förväntat resultat: den inhemska fakturan bokförs inte i december; betalningen 2027-01-10 bokför 1930 debet 15 625,00; 3001 kredit 12 500,00; 2611 kredit 3 125,00 i räkenskapsår 2027. EU-fakturan bokförs 2026-12-21 (1510 debet 50 000,00; 3108 kredit 50 000,00) och betalningen 2027-01-15 flyttar bara 1510 till 1930. Obetalda fakturor den 2026-12-31 ska bokföras som fordringar i bokslutet (NONOX gör det under **Bokföring → Bokslut**, se bokslutsmodulens granskning). Momsrutorna: december 2026 ruta 35 = 50 000, ruta 05 och 10 = 0; januari 2027 ruta 05 = 12 500, ruta 10 = 3 125. Konsulten verifierar ML 7 kap. 16 § med 17 kap. 24 § p. 11 samt 7 kap. 29-30 §§ (TODO `InvoiceBooking.php:49`, ärendena #50 och #52).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

---

## Öppna TODO(legal-verify) i koden

Samtliga träffar på `grep -rn "TODO(legal-verify)" app/Domain/Invoicing app/Actions/Invoicing app/Livewire/Invoicing` (inga träffar finns i `app/Livewire/Invoicing`). Kolumnen Konsultens beslut fylls i med Godkänd, Ändra eller Avslag.

| Nr | Fil:rad | Frågan (sammanfattning) | Konsultens beslut | Kommentar |
|---|---|---|---|---|
| 1 | app/Actions/Invoicing/CheckVatNumber.php:25 | Krävs en VIES-kontroll före varje momsfri EU-försäljning, och hur gammal får kontrollen vara? | | |
| 2 | app/Actions/Invoicing/IssueInvoice.php:301 | Fakturakraven för omvänd betalningsskyldighet vid byggtjänster (ML 17 kap.) och fakturatexten. | | |
| 3 | app/Actions/Invoicing/IssueInvoice.php:325 | Måste ett giltigt momsnummer kontrolleras i VIES före utfärdandet, och vilket bevis krävs för att varor lämnat Sverige? | | |
| 4 | app/Actions/Invoicing/IssueInvoice.php:383 | ROT och RUT under kontantmetoden (stöds inte) och vilka uppgifter fakturan och begäran ska innehålla. | | |
| 5 | app/Actions/Invoicing/RegisterTaxReductionPayment.php:27 | Godkänner Skatteverket bara en del av avdraget är resten kundens att betala; återföring till kunden hanteras inte och bokförs för hand. | | |
| 6 | app/Actions/Invoicing/SaveDraftInvoice.php:54 | Räknas Riksbankens mittkurs som "senaste genomsnittskurs på den mest representativa valutamarknaden i Sverige" (ML 8 kap. 21 §)? Skatteverket nämner ECB-kursen och Nasdaq OMX Stockholms mittkurs. Fakturadatumet används för förskott och byggtjänster med omvänd betalningsskyldighet. | | |
| 7 | app/Actions/Invoicing/SendReminder.php:26 | Påminnelseavgift och dröjsmålsränta tas inte ut. När får de tas ut (avtalat i förväg, högsta avgift, räntan enligt räntelagen)? | | |
| 8 | app/Actions/Invoicing/WriteOffInvoice.php:42 | När är en förlust konstaterad enligt god redovisningssed? Befarade förluster (6352) hanteras inte. | | |
| 9 | app/Domain/Invoicing/ForeignSale.php:23 | Kontona (3108, 3308, 3105, 3305, 3106 med moms på 2619), rutorna (35, 39, 36, 40, 38), fakturatexterna enligt ML 17 kap. och att tjänst utanför EU hör till ruta 40. | | |
| 10 | app/Domain/Invoicing/ForeignSale.php:96 | Fakturatexten för trepartshandel; en skattejurist bekräftar slutlig formulering. | | |
| 11 | app/Domain/Invoicing/ForeignSale.php:148 | Följer mellanmannens försäljning vid trepartshandel (ruta 38) samma regel som övrig EU-försäljning, alltså bokförd vid fakturan? Tills vidare bokförs den vid betalning under kontantmetoden. | | |
| 12 | app/Domain/Invoicing/FullInvoice.php:29 | Är en kund utan organisations-, moms- eller personnummer en privatperson, och är varje ROT-arbete en byggtjänst? | | |
| 13 | app/Domain/Invoicing/InvoiceBooking.php:49 | Under kontantmetoden ska obetalda fakturor vid årets slut bokas som fordringar (hör till bokslutet). | | |
| 14 | app/Domain/Invoicing/InvoiceBooking.php:61 | Kontot 6351 Konstaterade förluster på kundfordringar mot BAS 2026. | | |
| 15 | app/Domain/Invoicing/InvoiceBooking.php:74 | Konto 3231 och momsdeklarationens ruta 41 för omvänd betalningsskyldighet vid byggtjänster. | | |
| 16 | app/Domain/Invoicing/InvoicePdf.php:21 | Direktivets artikel 230 tillåter faktura i valfri valuta om momsen anges i nationell valuta enligt artikel 91; bekräfta motsvarande regel i ML 17 kap. och om även totalen i kronor krävs. | | |
| 17 | app/Domain/Invoicing/TaxReduction.php:30 | Alla värden: andelarna (30 % ROT, 50 % RUT från 2026-01-01), taken (50 000 kr ROT, 75 000 kr ROT och RUT tillsammans per person och år), nedåtavrundning till hela kronor och vilka kostnader som räknas som arbete. Taket kontrolleras bara mot bolagets egna fakturor. | | |
| 18 | app/Domain/Invoicing/TaxReduction.php:50 | Konto 2890 för skulden till Skatteverket när ett utbetalt avdrag krediteras (ärende #49). | | |
| 19 | app/Domain/Invoicing/VatExemptionReason.php:19 | Ordalydelsen för skälen till 0 % moms och om "Utanför momsen" (till exempel vidarefakturerat utlägg) beskrivs rätt. | | |

### Regler markerade "juristgranskning återstår" (AUD-FAKT) i koden

Dessa har ingen `TODO(legal-verify)`-etikett men är uttryckligen markerade som ogranskade. Konsulten tar ställning till dem tillsammans med fallen ovan.

| Nr | Fil:rad | Regel | Fall | Konsultens beslut | Kommentar |
|---|---|---|---|---|---|
| A1 | app/Actions/Invoicing/IssueInvoice.php:49 | Kreditering av betald eller delbetald faktura; återbetalning och att reskontran stämmer med 1510 (AUD-FAKT-13 #47, AUD-FAKT-8 #53) | FAK-07 | | |
| A2 | app/Actions/Invoicing/IssueInvoice.php:248 och RegisterTaxReductionRepayment.php:28 | ROT/RUT-avdrag som kreditering tar tillbaka efter utbetalning (AUD-FAKT-9 #49) | FAK-15 | | |
| A3 | app/Actions/Invoicing/IssueInvoice.php:272 och app/Domain/Company/VatExemption.php:10 | Momsbefrielse och moms som tas ut trots att bolaget inte är registrerat (AUD-FAKT-5 #51) | FAK-21 | | |
| A4 | app/Actions/Invoicing/RegisterPayment.php:161, WriteOffInvoice.php:40, InvoiceBooking.php:244 | Konstaterad kundförlust och momsåterföring (AUD-FAKT-12 #57) | FAK-12 | | |
| A5 | app/Domain/Invoicing/InvoiceBooking.php:29 och :204 | Kontantmetoden: slutbetalningen tar resten av varje rad (AUD-FAKT-3 #52) | FAK-09, FAK-10 | | |
| A6 | app/Domain/Invoicing/InvoiceBooking.php:33 och ForeignSale.php:146 | EU-försäljning bokförs vid fakturan även under kontantmetoden (AUD-FAKT-4 #50) | FAK-16, FAK-24 | | |
| A7 | app/Domain/Invoicing/FullInvoice.php:16 | Fullständig faktura över 4 000 kr (AUD-FAKT-7 #55) | FAK-20 | | |
| A8 | app/Domain/Invoicing/TaxReduction.php:28 | Avdragets år följer betalningen; förfallodagen som förväntad betaldag (AUD-FAKT-10 #56) | FAK-14 | | |
| A9 | app/Domain/Invoicing/VatExemptionReason.php:13 | Skäl till 0 % moms på fakturan (AUD-FAKT-6 #54) | FAK-21 | | |

Raderna i `app/Domain/Closing/UnpaidInvoices.php` och `LedgerReconciliation.php` (som också bär AUD-FAKT-markeringar) hör till bokslutsmodulen.

## Avvikelser

| Nr | Fall | Beskrivning | Allvarlighet | Åtgärd |
|---|---|---|---|---|
| | | | | |
| | | | | |
| | | | | |

## Godkännande

Modulen Kundfakturor är granskad och godkänd för att tas ur Beta.

| | |
|---|---|
| Namn | ______________________ |
| Titel/auktorisation | ______________________ |
| Datum | ______________________ |
| NONOX-version | ______________________ |
| Underskrift | ______________________ |

[ ] Godkänd   [ ] Godkänd med villkor   [ ] Ej godkänd

Villkor eller skäl: ______________________________________________

Godkännandet gäller bara modulen Kundfakturor.
