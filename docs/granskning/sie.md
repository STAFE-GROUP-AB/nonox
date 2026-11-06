# Granskningschecklista: SIE4 (export, import och ingående balanser)

| | |
|---|---|
| Modul | SIE4-export, tvåstegsinläsning av SIE4, kontrollsumma (#KSUMMA), teckentabell CP437, ingående balanser, serier och verifikationsnummer |
| Version av NONOX som granskas | ______________________ |
| Granskare (namn) | ______________________ |
| Datum | ______________________ |
| Kod/ADR som testas | `app/Support/Sie/` (SieWriter, SieReader, SieTokenizer, SieAmount, SieDate, SieChecksum, Cp437), `app/Actions/Accounting/ExportSie.php`, `app/Actions/Accounting/ImportSie.php`, `app/Actions/Accounting/AnalyseSieFile.php`, `app/Actions/Accounting/ConfirmSieImport.php`, `app/Domain/Accounting/SieImport/SieImportAnalyser.php`, `docs/adr/ADR-008-sie-and-opening-balances.md`, fixturer `tests/Fixtures/sie/export-2026.se` och `tests/Fixtures/sie/import-2026.se`, tester `tests/Feature/Accounting/SieExportTest.php` och `tests/Feature/Accounting/SieImportTest.php` |
| Manualavsnitt | `docs/manual/sv/bokforing/sie.md`, `docs/manual/sv/flytta/flera-ar-och-ingaende-balanser.md`, `docs/manual/sv/flytta/stam-av.md` |

## Förutsättningar

- **Specifikation:** SIE filformat utgåva 4C (SIE-gruppen, 2025-08-06). Konsulten jämför mot den, eller mot ett annat bokföringsprograms SIE-läsare.
- **Fixturer:** de två filerna i `tests/Fixtures/sie/` används som referens. Kopiera dem till en egen mapp; öppna dem aldrig i en textredigerare som sparar om dem (teckentabellen och radslut ska bevaras). Båda är syntetiska. Jag har kontrollerat med NONOX läsare att båda har giltig kontrollsumma: `export-2026.se` har `#KSUMMA 1785016976`, `import-2026.se` har `#KSUMMA 965058874`.
- **Testbolag för export (SIE-01 till SIE-05):** aktiebolag, "Exempelbolaget i Malmö AB" (juridiskt namn), organisationsnummer 556334-3689 (passerar kontrollsiffran), adress Storgatan 1, 211 20 Malmö, telefon 040-123 45, BAS 2026, K2.
- **Testbolag för import (SIE-06 och framåt):** ett nytt aktiebolag med samma organisationsnummer 556334-3689 och namn, **utan** bokförda verifikationer. Räkenskapsåret 2026-01-01 till 2026-12-31 finns (skapas när bolaget skapas, annars läggs det upp under **Bokföring → Räkenskapsår**). Användare med rollen Ägare.
- **Aldrig mot verklig data:** använd inte ett bolag med riktig bokföring.
- **Inläsning:** **Bokföring → Läs in SIE-fil**. **Nedladdning:** **Bokföring → Räkenskapsår**, **Ladda ner SIE-fil** på önskat år.
- **Redigera en kopia av filen:** raderna `#KSUMMA` (den första, ensam på sin rad, och den sista med ett tal) tas bort innan en fil ändras för hand, annars blir kontrollsumman fel och inläsningen stoppas av den orsaken i stället för den du provar. Då ger filen bara varningen "Filen saknar kontrollsumma...". Behåll CRLF och teckentabell 437 (använd ett verktyg som `iconv -f cp437 -t cp437` eller en hex-editor).

> Alla belopp i exemplen kommer ur fixturerna eller är påhittade.

---

### SIE-01 Exportera ett räkenskapsår (referensbokföring)

- Förutsättningar: testbolaget för export. Ställ in datorns datum eller kör fallet så att "rättelseverifikationen" får dagens datum; datumet i den rättande verifikationen blir dagen du rättar.
- Steg i appen:
  1. Skapa räkenskapsåret **2025** (2025-01-01 till 2025-12-31). Bokför i 2025-02-01 verifikation: 1930 debet 50 000,00 och 2081 kredit 50 000,00.
  2. I räkenskapsåret **2026**: lägg in ingående balanser 1930 debet 50 000,00 och 2081 kredit 50 000,00 (genom att föra över från 2025).
  3. Bokför i serie A, 2026-03-15, "Faktura 1001 – Kund i Öresund AB": 1930 debet 1 250,00; 3001 kredit 1 000,00 med radtext `Konsulttjänst "Projekt Ö"`; 2611 kredit 250,00.
  4. Bokför i serie A, 2026-04-02, "Försäljning": 6110 debet 500,00; 1930 kredit 500,00.
  5. Rätta den verifikationen (orsak "Fel konto") med **Rätta en verifikation**.
  6. Bokför i serie B, 2026-05-01, "Försäljning": 1930 debet 200,00; 3001 kredit 200,00.
  7. Spara ett utkast (belopp 999,00) som inte bokförs.
  8. **Bokföring → Räkenskapsår** och **Ladda ner SIE-fil** för 2026.
- Förväntat resultat (jämför med `tests/Fixtures/sie/export-2026.se`, bortsett från rader som `#GEN` (datum och signatur), `#PROGRAM` (version) och `#KSUMMA`):
  - `#FLAGGA 0`, `#FORMAT PC8`, `#SIETYP 4`, `#ORGNR 556334-3689`, `#FNAMN "Exempelbolaget i Malmö AB"`, `#ADRESS "" "Storgatan 1" "211 20 Malmö" "040-123 45"`, `#FTYP AB`, `#RAR 0 20260101 20261231`, `#RAR -1 20250101 20251231`, `#KPTYP BAS2026`, `#VALUTA SEK`.
  - `#KONTO` och `#KTYP` för varje konto i kontoplanen (fixturen har 108 konton; ditt antal är kontoplanens antal), där tillgång = `T`, skuld/eget kapital = `S`, intäkt = `I`, kostnad = `K`.
  - Ingående balans: `#IB 0 1930 50000.00` och `#IB 0 2081 -50000.00` (inga fler `#IB`).
  - Utgående balans: `#UB 0 1930 51450.00`, `#UB 0 2081 -50000.00`, `#UB 0 2611 -250.00`, `#UB -1 1930 50000.00`, `#UB -1 2081 -50000.00`.
  - Resultat: `#RES 0 3001 -1200.00` (intäkter -1 000,00 och -200,00). 6110 saknas eftersom rättelsen tar ut posten (+500,00 och -500,00 = 0,00).
  - Fyra verifikationer: A1 (3 rader), A2, A3 (rättelsen av A2, datum = rättelsedagen) och B1. Utkastet (999,00) finns inte med.
  - Beräkning av `#UB 0 1930`: 50 000,00 + 1 250,00 - 500,00 + 500,00 + 200,00 = 51 450,00.
  - Radtexten är `Konsulttjänst \"Projekt Ö\"` (citattecken skrivs `\"`).
  - Filen slutar med `#KSUMMA` och ett heltal.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SIE-02 Internt sammanhang i en exporterad fil

- Förutsättningar: filen från SIE-01.
- Steg i appen/kontroll:
  1. Summera alla `#TRANS` i varje verifikation.
  2. Summera alla `#IB 0`, alla `#UB 0` och alla `#RES 0`.
  3. Jämför `#UB -1` med `#IB 0` konto för konto.
- Förväntat resultat:
  - Varje verifikation summerar till 0,00 (A1: 1 250,00 - 1 000,00 - 250,00; A2: 500,00 - 500,00; A3: -500,00 + 500,00; B1: 200,00 - 200,00).
  - Summa `#IB 0` = 50 000,00 - 50 000,00 = 0,00.
  - Summa `#UB 0` + summa `#RES 0` = 51 450,00 - 50 000,00 - 250,00 - 1 200,00 = 0,00 (före bokslut ligger årets resultat i `#RES`, inte i `#UB`).
  - `#UB -1 1930` = 50 000,00 = `#IB 0 1930`; `#UB -1 2081` = -50 000,00 = `#IB 0 2081`.
  - Nollsaldon utelämnas (§5.17): 6110 finns varken i `#RES` eller `#UB`.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SIE-03 Teckentabell CP437, ersättningstecken och citat

- Förutsättningar: testbolaget. Bokför fyra verifikationer i 2026 med dessa beskrivningar (raderna balanserar mot 1930):
  - `Inköp – 100 € (Łódź)`
  - `Hyra C:\`
  - `Kaffe ’bönor’` (typografiska apostrofer)
  - en beskrivning med ett tabbtecken eller radbrytning mitt i texten (klistra in från Word).
- Steg i appen: ladda ner SIE-filen och öppna den i en hex-editor (eller `xxd`).
- Förväntat resultat:
  - Tecken som finns i CP437 skrivs som en byte: ö = `94`, Ö = `99`, å = `86`, ä = `84`, é = `82`, ü = `81`, ó = `A2`. Fixturen visar det: `Malm` följs av byte `94`.
  - `–` blir `-`; `€` blir `EUR`; `’` blir `'`; `Ł` och `ź` (saknas i CP437 och saknar ersättare) blir `?`. Texten blir alltså `Inköp - 100 EUR (?ód?)`.
  - `Hyra C:\` blir `Hyra C:/` (ett omvänt snedstreck sist eller före ett citattecken skulle annars läsas som ett citat, §5.7).
  - Styrtecken (tabb, radbrytning) blir blanksteg. Filen har CRLF som radslut på varje rad.
  - Konsulten verifierar mot SIE 4C §5.7-5.8 att ersättningarna är godtagbara för revisor och mottagande program.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SIE-04 Kontrollsumma (#KSUMMA): beräkning och motkontroll

- Förutsättningar: `export-2026.se` (eller filen från SIE-01) och, om möjligt, ett oberoende SIE-program eller SIE-gruppens verifieringsverktyg.
- Steg i appen/kontroll:
  1. Läs in `export-2026.se` i ett annat program med SIE-validering, eller i NONOX (SIE-06).
  2. Räkna CRC-32 (polynom EDB88320, förinställd FFFFFFFF, inverterad) över alla etiketter och fält mellan de två `#KSUMMA`-raderna: fältens innehåll i CP437, men inte blanksteg, citattecken, klamrar eller `\` före `"`.
- Förväntat resultat:
  - Slutlig `#KSUMMA` är decimaltalet 1785016976 för fixturen `export-2026.se`. (NONOX skriver talet i decimal, som i specifikationens exempel.)
  - För `import-2026.se` är talet 965058874.
  - Ett annat program ska läsa in filen utan kontrollsummefel. Konsulten verifierar att algoritmen följer SIE 4C §10.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SIE-05 Kontrollsumma: ändrad fil, avskuren fil, saknad kontrollsumma

- Förutsättningar: testbolaget för import. Tre kopior av `import-2026.se`.
- Steg i appen:
  1. Kopia 1: ändra ett tecken i en radtext (till exempel "Avgift" till "Avgiff"). Ladda upp via **Bokföring → Läs in SIE-fil** och **Granska filen**.
  2. Kopia 2: ta bort den sista raden (`#KSUMMA 965058874`).
  3. Kopia 3: ta bort båda `#KSUMMA`-raderna.
  4. Originalet: **Granska filen**.
- Förväntat resultat:
  - Kopia 1: stoppad (blockerande): "Kontrollsumman (#KSUMMA) stämmer inte. Filen har ändrats eller skadats efter att den skapades." **Läs in filen** går inte att välja.
  - Kopia 2: stoppad: "Filen har en inledande kontrollsumma (#KSUMMA) men ingen avslutande. Filen verkar vara ofullständig."
  - Kopia 3: inte stoppad, men en varning: "Filen saknar kontrollsumma, så det går inte att se om den har ändrats på vägen. Kontrollera avstämningen efteråt extra noga." Rapporten visar kontrollsumma "saknas".
  - Originalet: kontrollsumma "stämmer".
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SIE-06 Inläsning, steg 1: rapporten (granskning utan ändring)

- Förutsättningar: testbolaget för import (inga verifikationer). Filen `import-2026.se`.
- Steg i appen:
  1. **Bokföring → Läs in SIE-fil**. Välj filen under **SIE-fil** och klicka på **Granska filen**.
  2. Läs rapporten under **Det här finns i filen** och det som kommer att hända. Ändra ingenting.
- Förväntat resultat:
  - **Företag:** Exempelbolaget i Malmö AB, 556334-3689. **Räkenskapsår:** 2026-01-01 till 2026-12-31. **Skapad av:** Annat bokföringsprogram 3.1.
  - **Verifikationer:** 3. Rader: 7 (A1 har 3 rader, A2 har 2, K5 har 2). Raden `#BTRANS` (1 930, 1 205,00) är en borttagen rad och räknas inte som rad i verifikationen; `#RTRANS` är en tillagd rad och räknas, den efterföljande identiska `#TRANS` hoppas över. Rapporten visar 1 tillagd och 1 borttagen rad som historik. Antecknar rapporten andra antal, skriv dem under Kommentar.
  - **Ingående balanser:** 2 konton (1930: 10 000,00; 2081: -10 000,00). `#IB -1 1930 0.00` ignoreras.
  - **Kontrollsumma:** stämmer.
  - **Serier och nummer:** serie A, 2 verifikationer, behåller sina nummer (1 och 2; numren är exakt 1..2). Serie K, 1 verifikation med nummer 5: numren är inte 1..n, så verifikationen får nytt nummer från 1 (K1) och filens nummer 5 sparas i mappningen.
  - **Konton som läggs till:** bara konton som filen använder och som saknas i din kontoplan (1510, 1930, 2081, 2611, 3001, 6999 används; 4010 används inte och läggs inte till).
  - **Kostnadsställen som läggs till:** 10 "Restaurang Malmö", 20 "Restaurang Lund" och 30 (används på en rad men saknar `#OBJEKT`, kallas då "30"). **Projekt som läggs till:** P1 "Ombyggnad kök".
  - **Bra att veta:** (a) "Filen har dimension 7. Här finns bara kostnadsställen (1) och projekt (6), så 1 rader läses in utan de objekten." (raden `6999` med `{1 "30" 7 "1"}`); (b) att några kontonamn heter något annat än i kontoplanen ("Företagskonto" mot "Företagskonto/checkkonto/affärskonto" och liknande), namnen här behålls; (c) inga blockerare. `#GEN 20270115` ligger efter dagens datum men kontrolleras inte.
  - Ingenting har ändrats i bokföringen efter steg 2.
  - Konsulten verifierar att inget på sidan nu kan läsas in innan "Läs in filen" bekräftas (två steg).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SIE-07 Inläsning, steg 2: bokföring och avstämning

- Förutsättningar: SIE-06 gjord, inga blockerare.
- Steg i appen:
  1. Klicka på **Läs in filen** och **Ja, bokför 3 verifikationer**.
  2. Vänta tills det står **Filen är inläst**.
  3. Öppna **Bokföring → Verifikationer**, rapporterna Saldobalans och Verifikationslista för 2026, samt behandlingshistoriken.
- Förväntat resultat:
  - "3 verifikationer är bokförda i räkenskapsåret 2026, och varje konto stämmer med filen."
  - Verifikationer: A1 (2026-01-10): 1510 debet 1 250,00; 3001 kredit 1 000,00 (kostnadsställe 10 och projekt P1); 2611 kredit 250,00. A2 (2026-01-20): 1930 debet 1 250,00; 1510 kredit 1 250,00. K1 (2026-02-01, filens K5): 6999 debet 50,00 (kostnadsställe 30; dimension 7 saknas); 1930 kredit 50,00.
  - Saldobalans 2026 (IB + rörelse = UB): 1930: 10 000,00 + 1 200,00 = 11 200,00 (1 250,00 - 50,00); 1510: 0,00; 2081: -10 000,00; 2611: -250,00; 3001: -1 000,00; 6999: 50,00. Summan av kolumnen UB + resultat = 0,00 (11 200,00 - 10 000,00 - 250,00 - 1 000,00 + 50,00 = 0,00).
  - Verifikationerna är bokförda (POSTED) och går inte att ändra eller ta bort; bara rättas.
  - Verifikation A2 har rad-historik: en borttagen rad (1930, 1 205,00, text "Fel belopp", signatur KK) och en tillagd rad (1510, -1 250,00, datum 2026-01-21). Registreringsdatum och signatur för A1 är 2026-01-11/KK.
  - Behandlingshistoriken har en post `sie.analysed` och en `sie.imported` (fil, SHA-256, år, antal verifikationer 3, antal nya konton), inte en post per verifikation.
  - Försök läsa in samma fil igen: "Den här filen är redan inläst."
  - Konsulten verifierar: att en post per inläsning (i stället för per verifikation) räcker som behandlingshistorik (`TODO(legal-verify)` i `ImportSie.php`) och att `imported_vouchers` ger spårbarhet till filens serie, nummer, registreringsdatum och signatur.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SIE-08 Blockerande fel i filen (ett fel i taget)

- Förutsättningar: kopior av `import-2026.se` utan `#KSUMMA`-rader (se Förutsättningar). Testbolaget för import. Ladda upp varje kopia och läs rapporten. En avvikelse per kopia.
- Steg i appen: för varje rad i tabellen, ändra den angivna raden, ladda upp, klicka på **Granska filen**.
- Förväntat resultat:

| Ändring | Förväntat meddelande eller beteende |
|---|---|
| `#SIETYP 4` ändras till `#SIETYP 2` | "Filen är av SIE-typ 2. Bara typ 4 innehåller verifikationerna, så ta fram en SIE4-fil i stället." |
| `#ORGNR 556334-3689` ändras till `556036-0793` | "Filen gäller organisationsnummer 556036-0793, men det här företaget har 556334-3689. Ladda upp filen i rätt företag." Har du ett annat företag med det numret erbjuds byte. |
| Raden `#RAR 0 20260101 20261231` tas bort | "Filen saknar räkenskapsår (#RAR 0)." |
| Verifikation A1: `1250.00` ändras till `1205.00` på raden för 1510 | "Verifikation A1 balanserar inte: debet 1 205,00 och kredit 1 250,00." (debet 1 205,00; kredit 1 000,00 + 250,00 = 1 250,00) |
| Verifikation A1: datumet `20260110` ändras till `20270110` | "Verifikation A1 har datumet 2027-01-10, som ligger utanför räkenskapsåret." |
| A1 får bara en rad (ta bort två) | "Verifikation A1 har färre än två rader." |
| Verifikation A2 får numret 1 | "Serie A har samma verifikationsnummer flera gånger i filen." |
| `#TRANS 6999` ändras till `#TRANS 699` på en rad | "rad N: kontot "699" är inte ett fyrsiffrigt kontonummer." (blockerar). |
| Beloppet `1250.00` ändras till `1250.005` | "beloppet "1250.005" går inte att läsa." (högst två decimaler, SIE §5.9) |
| Beloppet `1250.00` ändras till `1250,00` (komma) | Samma felmeddelande: SIE kräver punkt som decimaltecken. |
| Ett belopp med 17 siffror före punkten | Går inte att läsa (högst 16 siffror kronor). |
| `#OBJEKT 1 "10"` ändras till en kod på 21 tecken | "Kostnadsställe ... har en för lång kod. En kod får ha högst 20 tecken." |
| Serien i `#VER A 1` ändras till `ABCD` | "Serien "ABCD" har ett för långt namn. En serie får ha högst tre tecken." |
| En rad utan inledande `#` eller med ett citattecken som inte avslutas | "Rad N: raden börjar inte med en etikett som #VER eller #KONTO." respektive "...(ett citattecken eller en klammer är inte avslutad)." |

  - I alla fall blir **Läs in filen** inte valbar och inget ändras i bokföringen.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SIE-09 Samma fil eller samma verifikationer två gånger

- Förutsättningar: filen från SIE-07 är inläst.
- Steg i appen:
  1. Ladda upp originalfilen igen.
  2. Gör en kopia av filen med en ändrad radtext men oförändrade serier och nummer (utan `#KSUMMA`-rader), ladda upp.
  3. Gör en kopia där verifikationerna får nya nummer (A 11, A 12, K 15) och ladda upp.
- Förväntat resultat:
  - Steg 1: "Den här filen är redan inläst."
  - Steg 2: stoppad med två meddelanden: "2 verifikationer i serie A är redan inlästa, till exempel A1." och "1 verifikationer i serie K är redan inlästa, till exempel K5." (serie K jämförs på filens ursprungliga nummer 5, inte det nya K1). Dubblettkontrollen utgår från filens serie och nummer i samma år.
  - Steg 3: stoppas inte av dubblettkontrollen. Rapporten varnar: "Räkenskapsåret har redan 3 bokförda verifikationer här. Verifikationerna i filen läggs till efter dem. Kontrollera att samma affärer inte blir bokförda två gånger." Konsulten bedömer om varningen räcker som skydd mot dubbel bokföring; kontrollen bygger på nummer, inte på innehåll.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SIE-10 Fil som är skriven i UTF-8 i stället för CP437

- Förutsättningar: en kopia av `import-2026.se` konverterad till UTF-8 (`iconv -f cp437 -t utf-8`) med oförändrade `#KSUMMA`-rader.
- Steg i appen: ladda upp och klicka på **Granska filen**.
- Förväntat resultat:
  - Varning: "14 rader är skrivna i UTF-8 i stället för teckentabell 437. De läses ändå rätt." (14 rader med åäö i fixturen: #PROGRAM, #GEN, #FNAMN, fem #KONTO-rader (1930, 2611, 3001, 4010, 6999), #DIM 1, #DIM 7, två #OBJEKT, #VER A 1 och den radtext som innehåller Ö; räkna med `grep -cP '[^\x00-\x7F]'` på den konverterade filen.)
  - Kontrollsumman stämmer fortfarande (raderna räknas om till CP437 före summering).
  - Namn som "Företagskonto" visas rätt, inte som `FÃ¶retagskonto`.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SIE-11 Ingående balanser: redan finns, stämmer eller skiljer sig

- Förutsättningar: bolag där 2026 redan har ingående balanser.
- Steg i appen:
  1. Bolag A: ingående balanser 1930 debet 10 000,00 och 2081 kredit 10 000,00. Ladda upp `import-2026.se` utan `#KSUMMA`-rader och läs rapporten.
  2. Bolag B: ingående balanser 1930 debet 9 000,00 och 2081 kredit 9 000,00. Ladda upp samma fil.
- Förväntat resultat:
  - Bolag A: rapporten säger att de ingående balanserna "finns redan här och stämmer"; inga nya balanser skrivs.
  - Bolag B: stoppas: "Räkenskapsåret har redan ingående balanser här, och de skiljer sig från filens." (9 000,00 mot 10 000,00).
  - Om ett konto har balans i NONOX som saknas i filen räknas det också som en skillnad (jämförelsen gäller hela mängden, nollor undantagna).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SIE-12 Ingående balanser som inte går jämnt ut (kandidat till brist)

- Förutsättningar: kopia av `import-2026.se` utan `#KSUMMA`-rader. Ändra `#IB 0 2081 -10000.00` till `#IB 0 2081 -9000.00`. Ändra `#UB 0 2081` till `-9000.00` så att filens egen avstämning stämmer (summa IB = 1 000,00).
- Steg i appen: **Granska filen**, sedan försök läsa in.
- Förväntat resultat (förväntad rätt ordning, hur det borde vara):
  - Inläsningen ska stoppas eller minst varnas: ingående balanser måste summera till 0,00. Här är summan 10 000,00 - 9 000,00 = 1 000,00.
  - Vad koden gör: jag har inte funnit någon kontroll i `SieImportAnalyser.php` eller `ImportSie.php` som summerar `#IB` till noll (avstämningen jämför bara `#UB` och `#RES` mot rörelserna). Det är en kandidat till brist: skriv utfallet under Kommentar och för in det som avvikelse om filen läses in.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SIE-13 Avstämningen hoppas över när filen saknar #UB och #RES (kandidat till vilseledande text)

- Förutsättningar: kopia av `import-2026.se` utan `#KSUMMA`-rader och utan alla rader som börjar med `#UB` eller `#RES`.
- Steg i appen: granska, läs in, läs slutmeddelandet.
- Förväntat resultat:
  - Filen läses in (balanserade verifikationer) men det finns inget att stämma av mot. I koden hoppas jämförelsen över när filen saknar `#UB` respektive `#RES` (`checked_closing` och `checked_results` blir falska).
  - Slutmeddelandet i appen lyder ändå "3 verifikationer är bokförda i räkenskapsåret 2026, och varje konto stämmer med filen." Det påstår en avstämning som inte gjorts. Kandidat till brist (`app/Actions/Accounting/ImportSie.php` runt `reconcile()` och `resources/views/livewire/accounting/sie-import-report.blade.php:14`). Konsulten bedömer allvarlighet.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SIE-14 Räkenskapsår: ordning, överlapp, stängt år, låst period

- Förutsättningar: bolag med räkenskapsår 2026 (öppet).
- Steg i appen: gör varje ändring i en kopia (utan `#KSUMMA`-rader) och ladda upp.
  1. Ändra `#RAR 0` till 2023 (alla verifikationsdatum till 2023): ett år som inte ligger direkt före 2026.
  2. Ändra `#RAR 0` till 20260201 20270131 (överlappar 2026 med andra datum).
  3. Stäng räkenskapsåret 2026 i NONOX och läs in originalets år.
  4. Lås perioden till och med 2026-01-31 (under **Bokföring → Räkenskapsår**) och läs in originalet.
  5. Ändra `#RAR 0` till 20250101 20251231 (året före) med motsvarande verifikationsdatum.
  6. Ett bolag som är enskild firma med räkenskapsår 2026-07-01 till 2027-06-30.
- Förväntat resultat:
  - 1: "Räkenskapsåret 2023-01-01 – 2023-12-31 ligger inte direkt före eller efter dina räkenskapsår här. Läs in åren i tur och ordning, närmast först."
  - 2: "Filens räkenskapsår 2026-02-01 – 2027-01-31 överlappar räkenskapsåret 2026-01-01 – 2026-12-31 här, som har andra datum. Ändra datumen under Räkenskapsår så att de stämmer med filen."
  - 3: "Räkenskapsåret ... är stängt här. Det går inte att läsa in något i ett stängt år."
  - 4: "Perioden till och med 2026-01-31 är låst, och filen har verifikationer i den." (A1 daterad 2026-01-10 och A2 2026-01-20 ligger i den låsta perioden.)
  - 5: godkänns; räkenskapsåret 2025 skapas (direkt före 2026) och loggas med `source: sie_import`.
  - 6: varning: "Räkenskapsåret ... slutar inte den 31 december. En enskild firma ska ha kalenderår som räkenskapsår. Året läses in som det är, men kontrollera det med din redovisningskonsult." (BFL 3 kap. 1 §.)
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SIE-15 Serier och verifikationsnummer när det redan finns bokföring i året

- Förutsättningar: bolaget har redan tre bokförda verifikationer i serie A under 2026 (A1-A3, datum i februari) och ingen annan serie.
- Steg i appen: ladda upp `import-2026.se` (utan `#KSUMMA`-rader) och läs rapporten, läs in.
- Förväntat resultat:
  - Rapporten: serie A behåller inte numren; verifikationerna får nummer från 4 (A4 och A5), filens nummer 1 och 2 sparas i mappningen. Serie K skapas ("Inläst serie K") om den inte fanns; K5 blir K1.
  - Varning om att året redan har 3 bokförda verifikationer.
  - Numreringen är lucklös: A1-A5 och K1.
  - Ingående balanser skrivs bara om året saknar sådana; annars gäller SIE-11.
  - Avstämningen efter inläsningen jämför kontosaldon mot filens `#UB` och `#RES`. Eftersom året redan hade andra verifikationer stämmer saldona inte, och inläsningen ska rullas tillbaka med en lista över konton som skiljer (så läser jag `reconcile()` i `ImportSie.php`; det är inte provat här). Gör också en kopia av filen utan `#UB`- och `#RES`-rader: då hoppas avstämningen över och verifikationerna läggs till (det är vad testet `adds vouchers after those already posted` gör). Konsulten bedömer att båda utfallen är begripliga för användaren och att inget bokförs vid rullning tillbaka.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SIE-16 Kontoplan: nya konton, typ och SRU

- Förutsättningar: bolag vars kontoplan saknar 6999 (annars ta bort kontot först om det saknar transaktioner). Filen `import-2026.se` utan `#KSUMMA`-rader.
- Steg i appen: granska, läs in, öppna **Bokföring → Kontoplan**.
- Förväntat resultat:
  - Konto 6999 "Särskilda avgifter" skapas med filens namn, typ Kostnad (`K`) och SRU-kod 7515 (`#SRU 6999 7515`). Skapade konton loggas.
  - Konton i filen som inte används (4010) skapas inte.
  - Befintliga konton behåller sitt namn och sin typ (en annan `#KTYP` i filen ändrar ingenting). Konsulten bedömer om det räcker, eftersom avstämningen använder bolagets typer för att avgöra balans- eller resultatkonto.
  - Konto med färre eller fler än fyra siffror i filen blockerar inläsningen.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SIE-17 Rundtur: export, inläsning i nytt bolag, export igen

- Förutsättningar: filen från SIE-01. Ett nytt tomt bolag med samma organisationsnummer och namn, med räkenskapsåren 2025 och 2026.
- Steg i appen:
  1. Läs in `export-2026.se`-filen från SIE-01 i det nya bolaget (SIE-06 och SIE-07).
  2. Ladda ner SIE-filen för 2026 från det nya bolaget.
  3. Jämför de två filerna rad för rad (till exempel med `diff` efter att ha tagit bort identifieringsraderna).
  4. Jämför saldobalans och verifikationslista i båda bolagen.
- Förväntat resultat:
  - Filerna är identiska utom `#PROGRAM`, `#GEN`, `#ORGNR`, `#FNAMN`, `#ADRESS`, `#FTYP` och `#KSUMMA`.
  - Verifikationerna A1, A2, A3 och B1 har samma nummer, datum, beskrivning, rader och belopp; registreringsdatum och signatur ("Anna Andersson") kommer från filen.
  - Saldobalansen är densamma: 1930 51 450,00; 2081 -50 000,00; 2611 -250,00; 3001 -1 200,00; 6110 0,00.
  - Ingående balanser 2026 är 50 000,00 / -50 000,00 i båda.
  - Rättelsen A3 och den rättade verifikationen A2 är länkade (corrects/corrected_by) i det ursprungliga bolaget; i det nya bolaget är de två vanliga bokförda verifikationer, eftersom SIE inte bär länken. Konsulten bedömer att det är godtagbart.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### SIE-18 Filstorlek och stora filer

- Förutsättningar: en syntetisk SIE-fil (genererad, ingen verklig data) på 49 MB och en på 51 MB.
- Steg i appen: ladda upp båda via **Bokföring → Läs in SIE-fil**.
- Förväntat resultat:
  - 49 MB laddas upp och granskas (läsningen sker rad för rad utan att läsa hela filen i minnet). 51 MB nekas ("Filen får vara högst 50 MB").
  - Exakt felmeddelande kunde inte avgöras ur koden; anteckna texten under Kommentar.
  - Observation: `AGENTS.md` §9.2 kräver att 100 MB-filer ska kunna hanteras strömmande; uppladdningsgränsen i ADR-008 och manualen är 50 MB. Konsulten bedömer om gränsen är rimlig för ert användningsfall.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

---

## Öppna TODO(legal-verify) i koden

Hämtat med `grep -rn "TODO(legal-verify)"` över `app/Support/Sie`, `app/Actions/Accounting/ExportSie.php`, `app/Actions/Accounting/ImportSie.php`, `app/Actions/Accounting/AnalyseSieFile.php`, `app/Actions/Accounting/ConfirmSieImport.php` och `app/Domain/Accounting/SieImport/`. Inga markeringar finns i `app/Support/Sie` eller i `SieImport/`. Inga regler med status "researched/unverified" i regeldata gäller SIE.

| Nr | Fil:rad | Frågan | Konsultens beslut (Godkänd / Ändra / Avslag) | Kommentar |
|---|---|---|---|---|
| 1 | app/Actions/Accounting/ExportSie.php:135 | SIE:s bolagstyper `I` och `S` avser föreningar och stiftelser som bedriver näring. Stämmer bolagstyperna `I` (ideell förening) och `S` (stiftelse) för alla föreningar och stiftelser, eller bara de som bedriver näring? (`#FTYP`) | | |
| 2 | app/Actions/Accounting/ImportSie.php:127 | En enda behandlingshistorikpost per inläsning och inte en per verifikation; `imported_vouchers` håller varje verifikations ursprung. Räcker det som behandlingshistorik enligt BFNAR 2013:2? | | |

## Avvikelser

| Nr | Fall | Beskrivning | Allvarlighet | Åtgärd |
|---|---|---|---|---|
| | | | | |
| | | | | |
| | | | | |

## Godkännande

Modulen **SIE4 (export, import och ingående balanser)** är granskad och godkänd för att tas ur Beta.

| | |
|---|---|
| Namn | ______________________ |
| Titel/auktorisation | ______________________ |
| Datum | ______________________ |
| NONOX-version | ______________________ |
| Underskrift | ______________________ |

[ ] Godkänd   [ ] Godkänd med villkor   [ ] Ej godkänd

Villkor eller skäl: ____________________________________________

Godkännandet gäller enbart den här modulen.
