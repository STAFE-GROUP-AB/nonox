# Granskningschecklista: Utländsk valuta och kursdifferenser

| | |
|---|---|
| Modul | Fakturor i utländsk valuta (kund och leverantör), Riksbankens kurser, realiserade kursdifferenser (3960/7960), orealiserade differenser vid bokslut (omvärdering), ECB-kurs för OSS |
| Version av NONOX som granskas | ______________________ |
| Granskare (namn) | ______________________ |
| Datum | ______________________ |
| Kod/ADR som testas | `app/Support/Currency/ExchangeRate.php`, `app/Support/Currency/RiksbankRates.php`, `app/Support/Currency/EcbRates.php`, `app/Support/Currency/EuroRate.php`, `app/Domain/Closing/CurrencyRevaluation.php`, `app/Domain/Invoicing/InvoiceBooking.php`, `app/Domain/Invoicing/InvoiceCalculator.php`, `app/Domain/Payables/SupplierInvoiceBooking.php`, `app/Actions/Invoicing/SaveDraftInvoice.php`, `app/Actions/Payables/SaveSupplierInvoice.php`, `app/Actions/Payables/PaySupplierInvoice.php`, `app/Domain/Vat/OssReport.php`, `docs/adr/ADR-025-exchange-differences.md` |
| Manualavsnitt | `docs/manual/sv/fakturering/faktura-i-utlandsk-valuta.md`, `docs/manual/sv/leverantorer/utlandsk-valuta.md`, `docs/manual/sv/bokslut/bokslutsverifikationer.md`, `docs/manual/sv/bokslut/checklistan.md`, `docs/manual/sv/moms/oss.md` |

## Förutsättningar

- **Testbolag:** aktiebolag, "Testbolag VAL AB", K2, momsregistrerat, kalenderår som räkenskapsår. Räkenskapsåret 2026 är öppet; räkenskapsåret 2027 finns (krävs för återföringen, VAL-12). Fakturametoden om inget annat anges.
- **Kurser i fallen:** för att fallen ska kunna upprepas skriver du kursen själv (**Kurs, kronor per EUR**) i stället för att acceptera den förifyllda kursen från Riksbanken. Undantagen är VAL-02 och VAL-12, där Riksbankens riktiga kurs är själva provet. Kurserna i exemplen (11,2345; 10,9000; 11,5000; 11,3310) är påhittade.
- **Kontoplan:** BAS 2026. Konto 3960 "Valutakursvinster på fordringar och skulder av rörelsekaraktär" och 7960 "Valutakursförluster på fordringar och skulder av rörelsekaraktär" finns i alla tre mallar; saknas de läggs de till med sitt BAS-namn första gången de behövs (loggas som `account.created`).
- **Kund och leverantör:** kunden "Kund Euro AB" (Sverige, momsplikt 25 %, valuta EUR) och leverantören "Lieferant GmbH" (DE) enligt LEV-checklistan. Alla uppgifter påhittade.
- **Så räknar NONOX (kodläsning):** belopp lagras i hela öre (kronor) respektive hundradelar (valuta). Omräkning = belopp i valutans hundradelar × kurs (miljondelar), avrundat halv upp (`ExchangeRate::toOre`). Kursen anges med högst sex decimaler.
- **Jämför bokföring mot verifikationen** efter varje fall (länk på fakturan/betalningen).

---

### VAL-01 Inmatning av kurs: format och gränser

- Förutsättningar: ny leverantörsfaktura i EUR (Lieferant GmbH), fakturadatum 2026-10-20, **Att betala i EUR** 1 000,00.
- Steg i appen: skriv i fältet **Kurs, kronor per EUR** i tur och ordning: `11,2345`, `11.2345`, `11,2345123`, `0`, `-1`, `abc`, tomt, `1 000,5` (med mellanslag) och `1000000`.
- Förväntat resultat:
  - `11,2345` och `11.2345` tolkas lika: 11,234500 kr per EUR (11 234 500 miljondelar). Under fältet visas kursen som `11,234500`.
  - `11,2345123` (sju decimaler) godtas inte. `0` godtas inte (kursen måste vara större än noll). `-1` och `abc` godtas inte. Tomt fält ger "Skriv kursen i kronor per EUR, till exempel 9,8264."
  - `1 000,5` tolkas som 1000,500000 (mellanslag tas bort) men ger sedan en avvikelse mot beloppet i kronor (VAL-07).
  - `1000000` (sju siffror före decimalen) godtas inte; högst sex heltalssiffror.
  - 1 000,00 EUR × 11,2345 = 11 234,50 kr (100 000 hundradelar × 11 234 500 / 1 000 000 = 1 123 450 öre).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### VAL-02 Riksbankens kurs: förifyllning, helger och framtida datum

- Förutsättningar: nätverksåtkomst till Riksbankens öppna API (SWEA, serie `SEKEURPMI`). Inget manuellt kursinslag.
- Steg i appen:
  1. Ny leverantörsfaktura i EUR. Skriv **Fakturadatum** 2026-10-16 (fredag). Läs förifylld **Kurs** och datumet som står under fältet.
  2. Ändra **Fakturadatum** till 2026-10-17 (lördag) och till 2026-10-18 (söndag).
  3. Ändra datumet till en dag efter dagens datum.
  4. Stäng av nätverket eller använd en valuta Riksbanken saknar (till exempel `XXX`).
- Förväntat resultat:
  - Steg 1: kursen är Riksbankens mittkurs (daglig, kronor per EUR) för 2026-10-16. Jämför med riksbank.se (Sök räntor och valutakurser). Kursen hämtas från Riksbanken och sparas för alltid i cache för den dagen.
  - Steg 2: lördag och söndag har ingen kurs; NONOX tar den senast publicerade före dagen, alltså fredagens (2026-10-16), och visar det datumet under fältet. Upp till tio dagar bakåt letas, så att långa helger hanteras.
  - Steg 3: ett datum i framtiden får dagens (senast publicerade) kurs.
  - Steg 4: ingen kurs hittas. Du får skriva kursen själv; sparandet nekas utan kurs.
  - Konsulten verifierar: att Riksbankens mittkurs (bokförd med publiceringsdagens värde) är godtagbar som bokföringskurs (`TODO(legal-verify)` i `RiksbankRates.php`).
  - Känd begränsning: valutor som Riksbanken anger per 100 enheter (till exempel JPY) justeras inte (VAL-15).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### VAL-03 Kundfaktura i EUR: radberäkning och belopp i kronor

- Förutsättningar: kunden "Kund Euro AB" med **Valuta för fakturor** EUR (**Register → Kunder → Ändra**).
- Steg i appen:
  1. **Försäljning → Fakturor → Ny faktura**. Välj kunden. **Valuta** fylls i med EUR.
  2. **Fakturadatum** 2026-10-20. **Leveransdatum** 2026-10-20. **Kurs, kronor per EUR** 11,2345.
  3. Rad 1: "Konsulttid", antal 10, pris 95,00 (EUR), moms 25 %. Rad 2: "Licens", antal 1, pris 120,50, moms 25 %.
  4. Läs summorna i valutan och rubriken **I svenska kronor**. Tryck **Skapa fakturan** och **Ja, skapa**.
- Förväntat resultat:
  - I valutan: rad 1 = 950,00 EUR; rad 2 = 120,50 EUR; netto 1 070,50 EUR. Moms 25 % = 1 070,50 × 0,25 = 267,625 → 267,63 EUR (halv upp). Totalt 1 338,13 EUR. Summan avrundas inte till hela enheter (ingen öresavrundning).
  - I kronor: rad 1 = 950,00 × 11,2345 = 10 672,775 → 10 672,78 kr; rad 2 = 120,50 × 11,2345 = 1 353,757... räknat exakt 12 050 hundradelar × 11 234 500 / 10^6 = 135 375,725 öre → 135 376 öre = 1 353,76 kr. Netto i kronor 10 672,78 + 1 353,76 = 12 026,54 kr.
  - Momsen räknas på kronraderna, inte omräknad från EUR-momsen: 12 026,54 × 25 % = 3 006,635 → 3 006,64 kr. (Jämför: EUR-momsen 267,63 × 11,2345 = 3 006,75 kr. Skillnaden 0,11 kr beror på avrundning och är avsiktlig enligt ADR-025.) Total i kronor 12 026,54 + 3 006,64 = 15 033,18 kr, utan öresavrundning.
  - Verifikation (serie B): 1510 debet 15 033,18; 3001-konto för försäljning kredit 12 026,54; 2611 kredit 3 006,64. Debet 15 033,18 = kredit 15 033,18.
  - Momsrapporten (kronor): utgående moms ruta 10 ökar med 3 006,64; försäljning ruta 05 med 12 026,54.
  - På fakturan står, under summan i valutan, **I svenska kronor** med momsen per sats och summan, och kursen med dag och källa (manuell). Fakturan visar företagets IBAN/BIC om de finns.
  - Konsulten verifierar: att omräkning av momsunderlaget med den kursen uppfyller mervärdesskattelagen 8 kap. 21 § och att fakturan, om kraven i 17 kap., ska visa beloppet i kronor (ADR-025, `TODO(legal-verify)`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### VAL-04 Vilket datum kursen tas för kundfaktura (leveransdatum)

- Förutsättningar: som VAL-03, men med Riksbankens kurs (rör inte kursfältet).
- Steg i appen:
  1. Fakturadatum 2026-10-20, **Leveransdatum** tomt. Läs datumet under kursfältet.
  2. Fyll i **Leveransdatum** 2026-10-14. Läs kursen och datumet igen.
  3. Fyll i ett leveransdatum i framtiden.
  4. Skriv en egen kurs som råkar vara lika med Riksbankens.
- Förväntat resultat:
  - Steg 1: kursen gäller fakturadatumet (2026-10-20), som då räknas som leveransdag.
  - Steg 2: kursen byts till Riksbankens kurs för 2026-10-14 (momsen uppstår vid leveransen, ML 7 kap. 3-4 §§). Kursfältet fylls i på nytt när leveransdatumet ändras.
  - Steg 3: dagens kurs används.
  - Steg 4: källan förblir "riksbank" om den skrivna kursen är lika med Riksbankens, annars "manuell".
  - Konsulten verifierar: att leveransdatum är rätt dag för omräkning av momsunderlaget, och förskottsfakturor (momsen uppstår då betalningen kommer in, ML 7 kap. 7 §) och byggtjänster med omvänd betalningsskyldighet (fakturadatum, 7 kap. 8 §): koden använder samma regel för alla (`TODO(legal-verify)` i `SaveDraftInvoice.php`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### VAL-05 Kundfaktura i EUR: betalning i två delar med kursvinst och kursförlust

- Förutsättningar: fakturan från VAL-03 är skapad (total 15 033,18 kr; 1 338,13 EUR).
- Steg i appen:
  1. Öppna fakturan. **Betaldag** 2026-11-10, **Belopp i EUR** 669,06, **Kronor in på banken** 7 600,00. **Registrera betalningen**.
  2. Andra betalningen: **Betaldag** 2026-11-17, **Belopp i EUR** 669,07 (förifyllt, det som återstår), **Kronor in på banken** 7 500,00.
  3. Försök registrera en tredje betalning på 0,01 EUR.
- Förväntat resultat:
  - Betalning 1: andel av fakturan = 15 033,18 × 669,06 / 1 338,13 = 7 516,5... exakt 1 503 318 × 66 906 / 133 813 = 751 653 öre = 7 516,53 kr. Kronor in 7 600,00. Skillnad 7 600,00 - 7 516,53 = 83,47 (mer in än bokfört = kursvinst). Verifikation (serie C): 1930 debet 7 600,00; 1510 kredit 7 516,53; 3960 kredit 83,47. Debet 7 600,00 = kredit 7 600,00.
  - Betalning 2 (rest av valutan): tar allt som återstår: 15 033,18 - 7 516,53 = 7 516,65 kr. Kronor in 7 500,00; 7 500,00 - 7 516,65 = -16,65 (mindre in = kursförlust). Verifikation (serie C): 1930 debet 7 500,00; 7960 debet 16,65; 1510 kredit 7 516,65. Debet 7 516,65 = kredit 7 516,65.
  - 1510 för fakturan går ut på 0,00 (15 033,18 debet, 7 516,53 + 7 516,65 = 15 033,18 kredit). Fakturan blir Betald.
  - Sammanlagt kursresultat: vinst 83,47 - förlust 16,65 = nettovinst 66,82.
  - Steg 3: nekas (inget återstår; överbetalning i valuta stöds inte).
  - Samma kurs som bokförd (kronor in = bokförd andel) ger ingen differensrad.
  - Om bankens inbetalningsfil (camt.054) läses in används beloppet i valutan (`InstdAmt`) som utländskt belopp och de bokförda kronorna som kronor in; en betalning som matchas på OCR utan valutabelopp reglerar det som återstår.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### VAL-06 Kreditfaktura till kundfaktura i valuta

- Förutsättningar: en ny kundfaktura i EUR som VAL-03 (ännu obetald, kurs 11,2345).
- Steg i appen:
  1. Skapa en kreditfaktura (**Kreditfaktura** från fakturan, se manualen "Kreditfaktura") för hela fakturan. Försök ändra valuta eller kurs.
- Förväntat resultat:
  - Kreditfakturan får samma valuta och samma kurs som originalet och tar ut kundfordran exakt: verifikation med omvända belopp, 1510 kredit 15 033,18; försäljning debet 12 026,54; 2611 debet 3 006,64. 1510 netto 0,00.
  - Valuta och kurs kan inte ändras på kreditfakturan (den följer alltid originalet, även om utkastet säger annat).
  - Ingen kursdifferens bokförs.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### VAL-07 Leverantörsfaktura i valuta: toleransen en krona mellan valuta och kronor

- Förutsättningar: ny leverantörsfaktura (Lieferant GmbH), **Valuta** EUR, fakturadatum 2026-10-20.
- Steg i appen: **Att betala i EUR** 1 000,00, **Kurs** 11,2345. Skriv **Att betala i kronor** (en gång per rad): 11 234,50; 11 235,50; 11 235,51; 11 233,50; 11 233,49.
- Förväntat resultat (1 000,00 EUR × 11,2345 = 11 234,50 kr):
  - 11 234,50: godtas (avvikelse 0,00).
  - 11 235,50: godtas (avvikelse exakt 1,00 kr; gränsen är högst 100 öre).
  - 11 235,51: nekas: "1 000,00 EUR till kursen 11,234500 blir 11 234,50 kr, men beloppet i kronor är 11 235,51 kr."
  - 11 233,50: godtas (avvikelse 1,00 kr). 11 233,49: nekas på samma sätt.
  - Avrundningsprov: **Att betala i EUR** 33,33 och **Kurs** 11,234567 ger 3 333 × 11,234567 = 37 444,97 öre → 37 445 öre = 374,45 kr (halv upp); 374,45 godtas, 376,00 godtas inte (avvikelse 1,55 kr).
  - Konsulten bedömer om en avvikelse på upp till en krona mellan valutabelopp × kurs och bokfört belopp är acceptabel för bokföringens riktighet.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### VAL-08 Leverantörsbetalning i EUR: ingen differens vid samma kurs, delbetalning, avrundning

- Förutsättningar: en attesterad leverantörsfaktura på 3,00 EUR, kurs 11,2345, **Att betala i kronor** 33,70 (300 hundradelar × 11,2345 = 3 370,35 öre → 3 370 öre = 33,70 kr), rad 4535 med 25 % (moms på kronbeloppet), fakturametoden.
- Steg i appen: betala tre gånger, 1,00 EUR vardera (**Belopp i EUR** 1,00), och skriv i **Kronor som banken drog** respektive 11,23; 11,23; 11,24. Gör ett annat försök med 11,30 på en ny faktura.
- Förväntat resultat:
  - Varje delbetalning på 1,00 EUR reglerar 33,70 × 1,00 / 3,00 = 11,2333 → 11,23 kr (halv upp). Betalning 1: 2440 debet 11,23; 1930 kredit 11,23; ingen differensrad.
  - Betalning 2: samma, 2440 debet 11,23; 1930 kredit 11,23.
  - Betalning 3 (hela resten av valutan): tar allt som återstår: 33,70 - 11,23 - 11,23 = 11,24. 2440 debet 11,24; 1930 kredit 11,24. 2440 går ut på 0,00 trots avrundning.
  - Med 11,30 i den första betalningen: 11,30 - 11,23 = 0,07 mer drogs än bokfört = kursförlust: 2440 debet 11,23; 7960 debet 0,07; 1930 kredit 11,30.
  - Betalning av mer än det som återstår i valutan nekas: "Beloppet är större än det som återstår, 1,00 EUR." Betalning av 0 eller negativt nekas: "Skriv ett belopp i EUR större än noll."
  - Betalning i kronor på en SEK-faktura med ifyllt valutabelopp nekas: "Fakturan är i kronor."
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### VAL-09 Kontantmetoden med valutafaktura

- Förutsättningar: kontantmetoden. En attesterad leverantörsfaktura på 1 000,00 EUR, kurs 11,2345, 11 234,50 kr, rad 4010 med 8 987,60 kr och moms 2 246,90 kr (Varav moms), alltså 8 987,60 + 2 246,90 = 11 234,50. Betalas i två delar: 400,00 EUR (bank drog 4 560,00) och 600,00 EUR (bank drog 6 600,00).
- Steg i appen: registrera, attestera, betala.
- Förväntat resultat:
  - Registreringen bokför ingenting.
  - Betalning 1: bokförd andel 11 234,50 × 400 / 1 000 = 4 493,80. Kostnaden och momsen fördelas på den andelen: 4010: 8 987,60 × 4 493,80 / 11 234,50 = 3 595,04 kr; 2641: 2 246,90 × 4 493,80 / 11 234,50 = 898,76 kr; 3 595,04 + 898,76 = 4 493,80. Differensen mot banken 4 560,00 - 4 493,80 = 66,20 förlust på 7960. Serie E: 4010 debet 3 595,04; 2641 debet 898,76; 7960 debet 66,20; 1930 kredit 4 560,00. Debet 4 560,00 = kredit 4 560,00.
  - Betalning 2 (rest): 4010 debet 8 987,60 - 3 595,04 = 5 392,56; 2641 debet 2 246,90 - 898,76 = 1 348,14; summa 6 740,70. Banken drog 6 600,00: vinst 140,70 på 3960. Serie E: 4010 debet 5 392,56; 2641 debet 1 348,14; 1930 kredit 6 600,00; 3960 kredit 140,70. Debet 6 740,70 = kredit 6 740,70.
  - Sammanlagt: kostnad 8 987,60, moms 2 246,90 (båda till fakturans kurs, inte betalningsdagens kurs), 1930 11 160,00 totalt, nettokursvinst 74,50.
  - Konsulten verifierar att momsen följer fakturans kurs och inte betalkursen (ADR-025: "VAT follows the rate of the taxable event").
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### VAL-10 Omvärdering vid bokslut: kundfordran och leverantörsskuld

- Förutsättningar: 2026 är det räkenskapsår som omvärderas, och 2027 finns som räkenskapsår. Testet görs efter 2026-12-31 (annars kan inte Riksbankens kurs för sista dagen hämtas), eller på ett redan avslutat år. Fakturametoden.
  - Kundfaktura "KF-1": 1 000,00 EUR, kurs 11,2345, bokförd 11 234,50 kr, obetald vid årsskiftet.
  - Leverantörsfaktura "LF-1": 500,00 EUR, kurs 10,9000, bokförd 5 450,00 kr (500,00 × 10,90), obetald vid årsskiftet.
  - Riksbankens kurs för 2026-12-31 (eller senast tidigare dag): för att fallet ska kunna föregripas räknar vi med 11,5000 (påhittad); rätt kurs hämtar du själv på riksbank.se, och du byter tal i förväntat resultat.
- Steg i appen:
  1. **Bokföring → Bokslut** efter årets sista dag. Under **Omvärdering av valutafordringar och valutaskulder**: läs raderna.
  2. Klicka på **Bokför omvärderingen** och bekräfta.
  3. Öppna verifikationerna i serie A och bokslutets checklista (**Valutafordringar och valutaskulder är omvärderade**).
- Förväntat resultat (kurs 11,5000):
  - KF-1: värde 1 000,00 × 11,5000 = 11 500,00 kr; bokfört 11 234,50; skillnad +265,50 (kursvinst, fordran blir större).
  - LF-1: värde 500,00 × 11,5000 = 5 750,00 kr; bokfört 5 450,00; skuld större = -300,00 (kursförlust).
  - Verifikation 2026-12-31, serie A: 1510 debet 265,50; 2440 kredit 300,00; 3960 kredit 265,50; 7960 debet 300,00. Debet 565,50 = kredit 565,50. Nettoeffekt på resultatet: +265,50 - 300,00 = -34,50 (nettoförlust).
  - Återföring 2027-01-01 med omvända tecken: 1510 kredit 265,50; 2440 debet 300,00; 3960 debet 265,50; 7960 kredit 300,00. En betalning 2027 räknas därmed fortfarande mot fakturans egen kurs (11,2345 respektive 10,9000).
  - Checklistan: punkten blir OK. Omvärderingen bokförs en gång per år (ADR-025); försök bokföra den en gång till och anteckna utfallet.
  - Konsulten verifierar: K2 (BFNAR 2016:10) punkt 13.5 och 17.8 (omräkning av fordringar och skulder till balansdagens kurs), att Riksbankens mittkurs duger som balansdagskurs (normalfallet är bankens köp- respektive säljkurs) och att återföring första dagen är godtagbar (`TODO(legal-verify)` i `CurrencyRevaluation.php`, ADR-025).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### VAL-11 Omvärdering av delvis betald faktura

- Förutsättningar: leverantörsfaktura "LF-2" på 1 000,00 EUR, kurs 11,2345, bokförd 11 234,50 kr. 400,00 EUR betalades 2026-11-20 (bank drog 4 560,00, bokförd andel 4 493,80, kursförlust 66,20). Obetald del 600,00 EUR vid årsskiftet. Slutkurs 11,5000.
- Steg i appen: bokför omvärderingen (som VAL-10) för den fakturan.
- Förväntat resultat:
  - Kvar i valutan: 600,00 EUR. Bokfört värde kvar: 11 234,50 - 4 493,80 = 6 740,70 kr.
  - Värde till slutkursen: 600,00 × 11,5000 = 6 900,00 kr. Skuld större: 6 740,70 - 6 900,00 = -159,30 (kursförlust).
  - Verifikation: 2440 kredit 159,30; 7960 debet 159,30 (tillsammans med övriga fakturor i omvärderingen).
  - Betalningar och kreditfakturor fram till sista dagen dras av, så ett belopp som betalats i januari ingår inte.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### VAL-12 Omvärdering med kontantmetoden

- Förutsättningar: kontantmetoden. Obetald kundfaktura i EUR vid årsskiftet (som KF-1). Slutkurs 11,5000.
- Steg i appen:
  1. Försök **Bokför omvärderingen** innan steget "Obetalda fakturor vid årets slut är bokförda" är gjort.
  2. Bokför det steget först. Bokför sedan omvärderingen.
- Förväntat resultat:
  - Steg 1: NONOX bokför inte omvärderingen förrän de obetalda fakturorna är bokförda på 1510/2440 (eller bekräftade som bokförda).
  - Steg 2: fakturorna bokförs först till bokningskursen (1510 debet 11 234,50 mot intäkt och moms), omvärderingen därefter som VAL-10 (+265,50 vinst).
  - Båda stegen får sin återföring första dagen i nästa år.
  - Konsulten bedömer: BFL 5 kap. 2 § kräver att obetalda fordringar och skulder bokförs vid årets utgång även med kontantmetoden; omvärderingen gäller de bokförda posterna.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### VAL-13 Riksbanken saknar kurs vid bokslutet: bokförs för hand

- Förutsättningar: en fordran i en valuta som Riksbanken inte har kurs för (testa med en valuta ur listan som saknar serie) eller när nätverket är avstängt.
- Steg i appen:
  1. Öppna **Bokföring → Bokslut**. Läs avsnittet för omvärdering.
  2. Bokför omvärderingen med en vanlig verifikation och en återföring 1 januari. Kryssa i **Omvärderingen är bokförd för hand** i checklistan.
- Förväntat resultat:
  - Steget visar vilka valutor som saknar kurs och bokför ingenting automatiskt.
  - Checklistans punkt stoppar bokslutet tills omvärderingen är bokförd eller bekräftad; efter kryssningen släpper den, med person och tid i behandlingshistoriken (`closing.checked`).
  - Kryssen släpper upp igen om året öppnas på nytt.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### VAL-14 Öresavrundning gäller bara kronor

- Förutsättningar: en kund i SEK och kunden "Kund Euro AB" i EUR.
- Steg i appen:
  1. SEK-faktura: en rad 100,40 kr netto, moms 25 %. Skapa.
  2. EUR-faktura: en rad 100,40 EUR netto, moms 25 %, kurs 11,2345. Skapa.
- Förväntat resultat:
  - SEK: moms 100,40 × 25 % = 25,10; summa 125,50; öresavrundning till hela kronor (halv upp) ger 126,00 kr; avrundningen +0,50 bokförs på 3740 Öres- och kronutjämning: 1510 debet 126,00; 3001 kredit 100,40; 2611 kredit 25,10; 3740 kredit 0,50. Debet 126,00 = kredit 126,00.
  - EUR: moms 100,40 × 25 % = 25,10 EUR; summa 125,50 EUR utan avrundning. Kronor: rad 100,40 × 11,2345 = 1 127,9... exakt 10 040 × 11 234 500 / 10^6 = 112 798,38 öre → 112 798 öre = 1 127,98 kr; moms 1 127,98 × 25 % = 281,995 → 282,00 kr; total 1 409,98 kr; ingen öresavrundning (ingen rad på 3740).
  - Konsulten bedömer om det är rätt att kronbeloppet på en valutafaktura inte avrundas till hela kronor.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### VAL-15 Valutor utan två decimaler och per 100 enheter (JPY, ISK): känd begränsning

- Förutsättningar: ett försök att registrera en leverantörsfaktura i JPY.
- Steg i appen: **Valuta** JPY, **Att betala i JPY** 100 000, kurs enligt Riksbankens serie `SEKJPYPMI` (jämför på riksbank.se om kursen anges per 1 eller per 100 JPY), **Att betala i kronor** enligt din beräkning.
- Förväntat resultat:
  - NONOX lagrar alla valutor som hundradelar och slår upp Riksbankens kurs utan att justera för att vissa valutor anges per 100 enheter (ADR-025, "Not built yet"). Konsulten kontrollerar om den förifyllda kursen blir hundra gånger för stor eller för liten för JPY.
  - Jag kunde inte beräkna ett förväntat värde: Riksbankens kvoteringsenhet för SEKJPY måste kontrolleras på riksbank.se. Om kursen anges per 100 JPY blir 100 000 JPY = 1 000 × den kursen, inte 100 000 × den. Förs det in utan justering får användaren ett fel i den ena eller den andra riktningen; toleranskontrollen (en krona) fångar det eftersom beloppet i kronor skrivs separat.
  - JPY finns inte i listan över valutor som kan betalas via betalfil (se LEV-24).
  - Kandidat till brist: ingen varning visas för valutor med annan skala. Bedöm allvarlighet.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### VAL-16 OSS: ECB-kurs för försäljning i kronor (omräkning till euro)

- Förutsättningar: bolaget har ansökt om beskattning i köparens land (OSS) (**Inställningar → Företagsuppgifter**, se `oss.md`). En kundfaktura i SEK till en privatperson i Tyskland: netto 100 000,00 kr, tysk moms 19 %, fakturadatum 2026-09-14. ECB:s referenskurs för kvartalets sista dag 2026-09-30 (påhittat tal här): 11,3310 kr per EUR.
- Steg i appen: **Bokföring → EU-rapporter**, avsnittet OSS, kvartal 3 2026. Läs tabellen och kurserna under tabellen.
- Förväntat resultat:
  - Underlag i euro: 100 000,00 kr / 11,3310 = 8 825,35 EUR (10 000 000 öre × 10^6 / 11 331 000 = 882 534,9 → 882 535 cent, halv upp).
  - Moms i euro: 8 825,35 × 19 % = 1 676,8165 → 1 676,82 EUR (167 682 cent; beräknad på eurounderlaget per land och sats).
  - I kronor (som bokfört på 2619): moms 100 000,00 × 19 % = 19 000,00 kr på 2619; underlaget 100 000,00 kr på 3106.
  - Fakturor i euro räknas med fakturans eurobelopp; andra valutor räknas om med ECB-kursen för kvartalets sista dag, och publicerades ingen den dagen används nästa publicerade kurs. Saknas kurs än visas bara kronorna.
  - Konsulten verifierar: att rätt kurs är ECB:s för sista dagen i redovisningsperioden, att nästa publicerade kurs gäller om ingen publicerats (rådets direktiv 2006/112/EG art. 369h, ej läst) och avrundningen av euromomsen per land och sats (`TODO(legal-verify)` i `OssReport.php`).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### VAL-17 Gränser: vad som inte stöds i valuta

- Förutsättningar: testbolaget.
- Steg i appen: försök (a) skapa en ROT- eller RUT-faktura i EUR; (b) skapa en offert, order eller avtalsfaktura i EUR; (c) kvitta en kreditnota i EUR mot en faktura i EUR (LEV-23); (d) registrera ett bankkonto i EUR; (e) betala mer än det som återstår i valutan på en kundfaktura.
- Förväntat resultat:
  - (a) "ROT- och RUT-avdrag räknas i kronor. Fakturera i SEK för att använda avdraget."
  - (b) valuta finns inte på offerter, order eller avtal; de är i kronor.
  - (c) nekas ("kan inte kvittas ännu").
  - (d) bankkonton i annan valuta än kronor stöds inte; därför finns ingen banksaldoomvärdering (K2 14.6). Konsulten bedömer om det är rimligt för bolag med eurokonto.
  - (e) nekas: överbetalning i valuta stöds inte.
  - Manualens avsnitt "Det här går inte än" ska stämma med detta.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

---

## Öppna TODO(legal-verify) i koden

Hämtat med `grep -rn "TODO(legal-verify)"` över `app/Support/Currency`, `app/Domain/Closing/CurrencyRevaluation.php`, `app/Actions/Invoicing/SaveDraftInvoice.php`, `app/Domain/Invoicing/InvoiceBooking.php`, `app/Domain/Invoicing/InvoiceCalculator.php`, `app/Domain/Payables/SupplierInvoiceBooking.php`, `app/Domain/Vat/OssReport.php` och ADR-025. Markeringar med "juristgranskning återstår" tas med.

| Nr | Fil:rad | Frågan | Konsultens beslut (Godkänd / Ändra / Avslag) | Kommentar |
|---|---|---|---|---|
| 1 | app/Support/Currency/RiksbankRates.php:25 | Vilken kurs en utländsk leverantörsfaktura bokförs till (Riksbankens mittkurs på fakturadatumet) och att differenser vid betalning hör hemma på 3960/7960. | | |
| 2 | app/Actions/Invoicing/SaveDraftInvoice.php:54 | Om Riksbankens mittkurs räknas som "senast fastställda genomsnittskurs på den mest representativa valutamarknaden i Sverige" (ML 8 kap. 21 §); Skatteverket nämner ECB-kursen och Nasdaq OMX Stockholms mittkurs. Fakturadatum används även för förskottsfakturor (7 kap. 7 §) och byggtjänster med omvänd betalningsskyldighet (7 kap. 8 §). | | |
| 3 | app/Actions/Invoicing/SaveDraftInvoice.php:43 | (Markering "enligt AI-utredning 2026-11-02, juristgranskning återstår") Kursen tas den dag momsen uppstår: leveransdatum, annars fakturadatum (ML 8 kap. 21 §, 7 kap. 3-4 §§). | | |
| 4 | app/Domain/Closing/CurrencyRevaluation.php:39 | Om Riksbankens mittkurs är en godtagbar balansdagskurs (bankens köpkurs för fordringar och säljkurs för skulder är normalfallet), och återföringen första dagen i nästa år. | | |
| 5 | app/Domain/Closing/CurrencyRevaluation.php:30 | (Markering "AI-utredning, juristgranskning återstår") K2 (BFNAR 2016:10) punkt 13.5 och 17.8 samt K3 (BFNAR 2012:1) punkt 30.3: omvärdering till balansdagens kurs är ett krav, även med kontantmetoden. | | |
| 6 | app/Domain/Vat/OssReport.php:32 | Vilken kurs som gäller när ECB inte publicerar någon på kvartalets sista dag (nästa publicerade används; art. 369h läst ej), avrundning av euromomsen per land och sats, vilket kvartal en försäljning hör till (fakturadatum), och rättelser av tidigare kvartal. | | |
| 7 | app/Domain/Vat/OssReport.php:29 | (Markering "enligt AI-utredning 2026-11-02, juristgranskning återstår") Omräkning till euro med ECB-kursen och moms i euro per land och sats, halv upp. | | |
| 8 | app/Domain/Invoicing/InvoiceBooking.php:49 | Under kontantmetoden: kundfakturor som är obetalda vid årets slut ska bokföras som fordringar; det hör till bokslutet. | | |
| 9 | app/Domain/Payables/SupplierInvoiceBooking.php:45 | Motsvarande för leverantörsfakturor under kontantmetoden (se LEV-07). | | |
| 10 | docs/adr/ADR-025-exchange-differences.md:208 | För kundfakturor: vilken paragraf i ML 17 kap. som motsvarar direktivets art. 230, och om beloppet i kronor också måste stå på fakturan. | | |
| 11 | docs/adr/ADR-025-exchange-differences.md:213 | Riksbankens mittkurs som balansdagskurs; återföring första dagen. | | |
| 12 | docs/adr/ADR-025-exchange-differences.md:224 | Om Riksbankens mittkurs uppfyller ML 8 kap. 21 § alternativ 1; ECB-kurs som alternativ för momsunderlaget (8 kap. 22 §) är inte inbyggt. | | |
| 13 | docs/adr/ADR-025-exchange-differences.md:230 | Förskottsfakturor och byggtjänster med omvänd betalningsskyldighet använder leverans- eller fakturadatum som övriga fakturor. | | |
| 14 | docs/adr/ADR-025-exchange-differences.md:234 | Att realiserade kursdifferenser på leverantörsskulder hör hemma på 3960/7960 och inte bland finansiella poster i klass 8. | | |
| 15 | app/Actions/Invoicing/SaveDraftInvoice.php:322 | (Markering "juristgranskning återstår", AUD-FAKT-1, #48) Fördelning av ROT-/RUT-avdraget vid kreditfaktura (gäller inte valuta i sig men sitter i samma fil). | | |

Obs: rad 9 är samma fråga som rad 1 i leverantörsfakturachecklistan och granskas på ett ställe. Rad 15 är inte valutaspecifik; den tas med för att den ligger i samma fil.

## Avvikelser

| Nr | Fall | Beskrivning | Allvarlighet | Åtgärd |
|---|---|---|---|---|
| | | | | |
| | | | | |
| | | | | |

## Godkännande

Modulen **Utländsk valuta och kursdifferenser** är granskad och godkänd för att tas ur Beta.

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
