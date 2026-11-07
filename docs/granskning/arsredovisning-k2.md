# Granskningschecklista: Årsredovisning K2

| | |
|---|---|
| Modul | Årsredovisning enligt K2 för aktiebolag och ekonomiska föreningar: villkor för K2, spärr mot K3 och K1, resultat- och balansräkning, flerårsöversikt, noter, utdelning, fastställelseintyg, PDF och iXBRL-fil (beta) |
| Version av NONOX som granskas | ______________________ (fylls i av granskaren) |
| Granskare (namn) | ______________________ |
| Datum | ______________________ |
| Kod/ADR som testas | app/Domain/AnnualReport/ (AnnualReportDocument.php, AnnualAccounts.php, K2Schedule.php, PrintedFigures.php, K2Taxonomy.php, PledgedAssetKind.php), app/Actions/AnnualReport/SaveAnnualReport.php, app/Http/Controllers/Accounting/AnnualReportController.php, app/Livewire/Accounting/AnnualReportPage.php, app/Support/Ixbrl/ (K2AnnualReportIxbrl.php, InlineXbrlWriter.php), app/Domain/Company/ReportingFrameworkRules.php, app/Domain/Closing/DividendLimit.php, scripts/fetch-xbrl-taxonomy.sh, scripts/validate-ixbrl-arelle.sh, tests/Fixtures/annual-report/, docs/adr/ADR-058-digital-annual-report.md, docs/adr/ADR-059-k3-annual-report.md, docs/adr/ADR-010 (rapporter, bokslut, K2-årsredovisning) |
| Manualavsnitt | docs/manual/sv/bokslut/arsredovisning.md, docs/manual/sv/bokslut/resultatdisposition.md, docs/manual/sv/installningar/foretagets-uppgifter.md |

Bokslutet (skatt, resultat, stängning) granskas i docs/granskning/bokslut.md. Den här listan gäller årsredovisningen som dokument.

## Förutsättningar

- **Testbolag** "Testbolag Årsredovisning AB", påhittat organisationsnummer 556999-0012 (klarar Luhn-kontrollen). Inga riktiga uppgifter, inga personnummer. Underskrifter görs med påhittade namn.
- **Regelverk:** K2, K2-villkoren bekräftade (se BOK-01 i bokslut.md). Fakturametoden. Räkenskapsår 2026-01-01 till 2026-12-31, föregående år 2025 finns och är stängt.
- **Plan/behörighet:** användare med rollen Redovisningskonsult (får hantera räkenskapsår). Årsredovisningen finns under **Bokföring → Årsredovisning** (/arsredovisning).
- **Databas:** egen testdatabas. Kör aldrig `migrate:fresh`, `db:wipe` eller tester med `RefreshDatabase` mot den.
- **Testdata (grunddata, används i flera fall).** Bokför följande för att få räkenskapsår 2026, belopp i kronor.

  Ingående balanser 2026-01-01 (utgående 2025): 1510 Kundfordringar D 100 000; 1930 Bank D 400 000; 2081 Aktiekapital K 25 000; 2091 Balanserad vinst K 175 000; 2350 Banklån K 220 000; 2440 Leverantörsskulder K 80 000. Summa D 500 000 = K 500 000.

  Rörelser 2026 (alla kontanta): 3010 Försäljning K 1 200 000; 4010 Inköp D 300 000; 5010 Lokalhyra D 240 000; 7010 Löner D 300 000; 8410 Räntekostnad D 10 000; bank D 1 200 000 - 300 000 - 240 000 - 300 000 - 10 000 = 350 000 netto. Skatt bokförd i bokslutet: 8910 D 72 100 mot 2512 K 72 100 (20,6 % x 350 000 = 72 100). Årets resultat bokat: 8999 D 277 900 mot 2099 K 277 900.

  Utgående balans 2026-12-31: 1510 100 000; 1930 400 000 + 350 000 = 750 000; tillgångar 850 000. Eget kapital 25 000 + 175 000 + 277 900 = 477 900; 2350 220 000; 2440 80 000; 2512 72 100. Skulder och eget kapital 477 900 + 220 000 + 80 000 + 72 100 = 850 000. Balans.

  Resultaträkning 2026 (kostnadsslag): Nettoomsättning 1 200 000; Råvaror och förnödenheter -300 000; Övriga externa kostnader -240 000; Personalkostnader -300 000; Rörelseresultat 360 000; Räntekostnader -10 000; Resultat efter finansiella poster 350 000; Resultat före skatt 350 000; Skatt på årets resultat -72 100; Årets resultat 277 900.

  Föregående år (2025) för jämförelse: nettoomsättning 1 000 000, resultat efter finansiella poster 150 000, balansomslutning 500 000, eget kapital 200 000.
- **Teknisk hjälp:** vissa fall kräver en person som kan köra skript. Skriv i kommentaren vem som körde dem.

## Fall

### ARS-01 Spärr: K3, K1 och andra bolagsformer får ingen årsredovisning
- Förutsättningar: Fyra testföretag: (a) aktiebolag K3, (b) aktiebolag K1 (om tillåtet att spara; annars hoppa över), (c) enskild firma, (d) handelsbolag. Alla med bokföring för 2026.
- Steg i appen:
  1. För varje företag: **Bokföring → Årsredovisning**, välj 2026.
  2. Försök öppna förhandsvisning /arsredovisning/{år}, PDF /arsredovisning/{år}/pdf och iXBRL /arsredovisning/{år}/ixbrl direkt i adressfältet.
  3. Läs texten på sidan.
  4. Byt (a) till K2 (kryssa i villkoren) och kontrollera att formuläret syns.
- Förväntat resultat (app/Domain/AnnualReport/AnnualReportDocument.php, `supports`/`refusal`; ADR-059 steg F.1):
  - (a) K3: sidan visar rubriken "Årsredovisning enligt K3 finns inte ännu" i stället för formuläret, med texten "NONOX tar ännu inte fram årsredovisningar enligt K3 (BFNAR 2012:1). Räkenskapsåret har regelverket K3, och en årsredovisning enligt K2 skulle inte stämma med det regelverket." Förhandsvisning, PDF och iXBRL ger inget dokument (avvisning, inte en K2-årsredovisning under K3-rubrik).
  - (b) K1: "NONOX tar fram årsredovisningar enligt K2. Räkenskapsåret har regelverket K1, som inte används för en årsredovisning."
  - (c) och (d): "Årsredovisningen görs här för aktiebolag och ekonomiska föreningar." (enskild firma upprättar förenklat årsbokslut).
  - Ett K3-företag får inte få en not 1 som säger att årsredovisningen upprättats enligt BFNAR 2016:10. Kontrollera att ingen sådan text kan produceras.
  - Konsulten verifierar: att spärren mot K3 är rätt hantering tills K3 byggs (K3 kap. 1.4 och 1.6: ett allmänt råd ska tillämpas i sin helhet, annars får det inte anges), och att alternativet manualen anvisar (SIE-fil till annat program) är godtagbart.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### ARS-02 Vilka företag får använda K2: storlek (ÅRL 1 kap. 3 §)
- Förutsättningar: Testbolag i grunddata. NONOX känner bara bolagsformen och visar villkoren; det kontrollerar inte storleken. Granskaren bedömer varje scenario mot lagtexten och jämför med texten som appen visar.
- Steg i appen:
  1. Öppna företagets uppgifter och välj **Regelverk för årsbokslut**: K2. Läs villkorstexten.
  2. Bedöm tabellen nedan och fyll i kolumnen "Utfall enligt konsulten".
- Förväntat resultat:
  - Texten för aktiebolag lyder (app/Domain/Company/ReportingFrameworkRules.php, `k2Conditions`): "K2 får bara användas av mindre företag. Ett företag är större om det under vart och ett av de två senaste räkenskapsåren har haft mer än ett av: fler än 50 anställda i medeltal, en balansomslutning över 40 miljoner kronor och en nettoomsättning över 80 miljoner kronor, eller om dess aktier eller andra värdepapper är noterade på en reglerad marknad."
  - Förväntade utfall enligt kodens regeltext (konsulten kontrollerar mot ÅRL 1 kap. 3 § första stycket 4-5):

| Scenario (båda två senaste åren) | Antal kriterier över gräns | Förväntat | Utfall enligt konsulten |
|---|---|---|---|
| 40 anställda, balansomslutning 20 Mkr, omsättning 60 Mkr | 0 | Mindre företag, K2 tillåtet (om inga andra spärrar) | |
| 60 anställda, balansomslutning 10 Mkr, omsättning 30 Mkr | 1 (anställda) | Mindre företag (högst ett över), K2 tillåtet | |
| 60 anställda, balansomslutning 45 Mkr, omsättning 30 Mkr | 2 | Större företag, K2 inte tillåtet | |
| 50 anställda (exakt), balansomslutning 40 Mkr (exakt), omsättning 80 Mkr (exakt) | 0 ("mer än" krävs) | Mindre företag | |
| 51 anställda och 41 Mkr balansomslutning ena året, under gränserna andra året | 0 för "båda åren" | Mindre företag | |
| Aktier noterade på reglerad marknad, små tal | särskilt | Större företag oavsett storlek | |

  - Gränsfallet "exakt på gränsen" ska ge mindre företag eftersom lagen säger "mer än".
  - Konsulten verifierar: att NONOX inte kontrollerar storleken (användaren bekräftar texten) är godtagbart, och att texten stämmer med ÅRL 1 kap. 3 §.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### ARS-03 K2-uteslutningar enligt BFNAR 2025:2 (punkt 1.1A-1.1C)
- Förutsättningar: Aktiebolag med K2, räkenskapsår som börjar efter 2025-12-31.
- Steg i appen: Öppna företagets uppgifter, välj **Regelverk för årsbokslut** K2, läs hela villkorstexten (fem stycken för AB: storlek, 1.1A, 1.1B, 1.1C, ikraftträdande). Bedöm scenarierna.
- Förväntat resultat:
  - Texten ska innehålla: 1.1A (publikt AB, moderföretag i en större koncern, moderföretag i mindre koncern som upprättar koncernredovisning, filialer i utlandet, aktierelaterade ersättningar, skuldebrev som kan regleras med egetkapitalinstrument, kryptotillgångar utom enstaka användning), 1.1B (väsentlig uppskjuten skatteskuld, byggnader som ger minst 75 procent av nettoomsättningen, bedöms på senaste färdiga årsredovisningen) och 1.1C (1.1B gäller inte om högst ett av tre under vart och ett av de två senaste åren: mer än 3 anställda i medeltal, balansomslutning över 1,5 miljoner kronor, nettoomsättning över 3 miljoner kronor; inte heller för ett företag som använde K2 föregående år och normalt inte omfattas av 1.1B).
  - Förväntade utfall (kodens text; konsulten bekräftar mot BFNAR 2016:10 efter 2025:2):

| Scenario | Beräkning | Förväntat | Utfall enligt konsulten |
|---|---|---|---|
| Fastighetsbolag, byggnader ger 80 % av omsättningen, 2 anställda, balansomslutning 20 Mkr, omsättning 3,2 Mkr båda åren | Över gräns: anställda nej, balans ja, omsättning ja = 2 av 3 | 1.1C ger inget undantag, 1.1B gäller: K2 inte tillåtet, K3 | |
| Samma men omsättning 2,9 Mkr | Över gräns: balans ja, övriga nej = 1 av 3 (högst ett) | 1.1B gäller inte: K2 tillåtet | |
| Samma som första, men bolaget tillämpade K2 föregående år och inte "normalt" omfattas av 1.1B | särregel | K2 får fortsätta (kontrollera vad "normalt" betyder: mer än några enstaka år) | |
| Publikt aktiebolag | 1.1A | K2 inte tillåtet | |
| AB med 0,5 miljoner kronor i kryptotillgångar som innehas | 1.1A | K2 inte tillåtet | |
| AB som någon enstaka gång tagit emot kryptovaluta som betalning | undantag i 1.1A | K2 tillåtet | |
| Bostadsrättsförening (ekonomisk förening) | 1.1A | K2 inte tillåtet | |
| AB som äger en filial i utlandet | 1.1A | K2 inte tillåtet | |
| AB med uppskjuten skatteskuld som är väsentlig, 5 anställda, balans 3 Mkr, omsättning 8 Mkr | 1.1B (skatteskuld) och 1.1C (anställda ja, balans ja, omsättning ja = 3 av 3) | K2 inte tillåtet | |

  - Ikraftträdande (app/Domain/Company/ReportingFrameworkRules.php): gäller räkenskapsår som börjar efter 2025-12-31; företag som startat efter 2025-06-30 med förlängt år som slutar 2026-12-31 eller senare redan för år som börjar efter 2025-06-30. För tidigare år gäller en äldre lydelse som inte visas.
  - Ekonomisk förening visar samma villkor med bostadsrättsföreningar uteslutna. Övriga bolagsformer visar bara storleksvillkoret.
  - Konsulten verifierar: texternas riktighet punkt för punkt (TODO i app/Domain/Company/ReportingFrameworkRules.php rad 60 och 65, källa https://www.bfn.se/wp-content/uploads/vl16-10-k2ar-kons2025.pdf), och att äldre lydelse bör visas för räkenskapsår som börjar före 2026-01-01.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### ARS-04 Resultaträkning och balansräkning mot bokföringen
- Förutsättningar: Grunddata ovan, bokslutet gjort (skatt och resultat bokat). Journalen och saldobalansen (Bokföring → Rapporter) tagna fram som jämförelse.
- Steg i appen:
  1. **Bokföring → Årsredovisning**, välj 2026. Fyll i **Allmänt om verksamheten** (påhittad text), **Medelantal anställda** 4, **Ort**, **Datum för underskrift** 2027-03-15 och en underskrift "Anna Test, Styrelseledamot". Spara.
  2. Läs **Förhandsvisning av årsredovisningen**: resultaträkning, balansräkning.
  3. Jämför varje rad med saldobalansen för 2026.
- Förväntat resultat (BAS-intervall i app/Domain/AnnualReport/K2Schedule.php):
  - Resultaträkning: Nettoomsättning 1 200 000 (3000-3799); Råvaror och förnödenheter -300 000 (4010 i 4000-4939); Övriga externa kostnader -240 000 (5010 i 5000-6999); Personalkostnader -300 000 (7010 i 7000-7699); Rörelseresultat 360 000 (1 200 000 - 300 000 - 240 000 - 300 000); Räntekostnader och liknande resultatposter -10 000 (8410 i 8400-8799); Resultat efter finansiella poster 350 000; Resultat före skatt 350 000; Skatt på årets resultat -72 100 (8910 i 8900-8989); Årets resultat 277 900.
  - Balansräkning: Kundfordringar 100 000 (1510 i 1500-1599); Kassa och bank 750 000 (1930 i 1900-1999); Summa tillgångar 850 000. Aktiekapital 25 000 (2081); Balanserat resultat 175 000 (2091 i 2090-2098); Årets resultat 277 900 (2099); Summa eget kapital 477 900. Övriga skulder till kreditinstitut (långfristiga) 220 000 (2350 i 2340-2359); Leverantörsskulder 80 000 (2440 i 2440-2449); Skatteskulder 72 100 (2512 i 2500-2599). Summa eget kapital och skulder 850 000.
  - Samma tal om årets resultat är bokat på 2099 eller ännu inte bokat (preview läser om från bokföringen varje gång). Kontrollera båda tillstånden.
  - Konsulten verifierar: uppställningsformen mot ÅRL bilaga 1 och 2 och BFNAR 2016:10 kap. 4, samt BAS-intervallen, särskilt 8000-8799 och 4900-4939/4980-4999 (TODO i app/Domain/AnnualReport/K2Schedule.php rad 12 och 34).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### ARS-05 Avrundning till hela kronor
- Förutsättningar: Ett testbolag med rörelser med ören: 3010 Försäljning K 100 000,50; 4010 Inköp D 40 000,50; 5010 Övriga externa D 20 000,50. Ingen annan rörelse. Resten av balansen valfri men balanserande.
- Steg i appen: Förhandsvisa årsredovisningen och läs resultat- och balansräkningen.
- Förväntat resultat (app/Domain/AnnualReport/PrintedFigures.php: nära hel krona, halv krona bort från noll; varje summa är summan av de avrundade raderna):
  - Nettoomsättning 100 000,50 skrivs 100 001. Råvaror 40 000,50 skrivs -40 001. Övriga externa 20 000,50 skrivs -20 001.
  - Rörelseresultat som summa av avrundade rader: 100 001 - 40 001 - 20 001 = 39 999. (Exakt belopp 39 999,50 hade avrundats till 40 000, men tryckt summa ska vara 39 999 så att tabellen stämmer.)
  - Hela balansräkningen balanserar efter avrundning; en eventuell skillnad på en eller ett par kronor läggs på raden "Övriga skulder" (kortfristiga, BAS 2400-2409, 2470-2479, 2490-2499, 2600-2859, 2870-2899), aldrig utelämnad.
  - Texten "Om inte annat särskilt anges redovisas alla belopp i hela kronor" finns med. Beräkningar för beslut (fritt eget kapital för utdelningsspärren) görs fortfarande på exakta belopp.
  - Konsulten verifierar: att avrundningsmetoden är godtagbar, och att en avrundningsskillnad får läggas på Övriga skulder (ingen regel i ÅRL, BFNAR 2016:10 eller BAS funnen; TODO i app/Domain/AnnualReport/PrintedFigures.php rad 25 och 34).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### ARS-06 Flerårsöversikt och soliditet
- Förutsättningar: Grunddata med år 2025 och 2026 i NONOX.
- Steg i appen: Läs **Flerårsöversikt** i förvaltningsberättelsen.
- Förväntat resultat (app/Domain/AnnualReport/AnnualAccounts.php, `multiYear`, `solidity`):
  - 2026: Nettoomsättning 1 200 000; Resultat efter finansiella poster 350 000; Soliditet = (eget kapital 477 900 + 0,794 x obeskattade reserver 0) / balansomslutning 850 000 = 0,56224 => 56,2 %.
  - 2025: Nettoomsättning 1 000 000; Resultat efter finansiella poster 150 000; Soliditet 200 000 / 500 000 = 40,0 %.
  - Med obeskattade reserver: eget kapital 400 000, obeskattade reserver 100 000, balansomslutning 1 000 000: (400 000 + 79 400) / 1 000 000 = 47,94 % => 47,9 %. (79 400 = 100 000 x 0,794, alltså 100 000 minus 20,6 % latent skatt.)
  - Balansomslutning 0: soliditet visas inte.
  - Upp till fyra år visas, så länge de finns i NONOX.
  - Konsulten verifierar: antal år i flerårsöversikten och nyckeltalets definition (TODO i app/Domain/AnnualReport/AnnualAccounts.php rad 158 och 223). Notera att skattesatsen 0,794 (= 1 - 20,6 %) är fast i koden och därför blir fel för år som har annan bolagsskattesats.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### ARS-07 Kommentar när nettoomsättningen ändras mer än 30 procent
- Förutsättningar: Grunddata. Ändra nettoomsättning för jämförelseåret 2025 eller 2026 enligt tabellen (via korrigerade rörelser i en kopia).
- Steg i appen: Försök spara årsredovisningen utan fältet **Kommentar till nettoomsättningen** och sedan med.
- Förväntat resultat (AnnualAccounts::netSalesVariations, SaveAnnualReport):

| Föregående år | Innevarande år | Beräkning (|ny - gammal| x 100 > 30 x |gammal|) | Kommentar krävs? |
|---|---|---|---|
| 1 000 000 | 1 300 000 | 300 000 x 100 = 30 000 000 mot 30 x 1 000 000 = 30 000 000, inte större | Nej (exakt 30 %) |
| 1 000 000 | 1 300 001 | 30 000 100 > 30 000 000 | Ja |
| 1 000 000 | 700 000 | 30 000 000 mot 30 000 000, inte större | Nej |
| 1 000 000 | 699 999 | 30 000 100 > 30 000 000 | Ja |
| 0 | 1 | föregående 0, nytt belopp skilt från 0 | Ja (förändring från noll räknas) |
| 0 | 0 | ingen förändring | Nej |

  - Vid krav utan text: "Nettoomsättningen har förändrats med mer än 30 procent mellan :from och :to. Kommentera förändringen (BFNAR 2016:10 punkt 5.5)." och sparandet nekas. Med text sparas den och visas i förvaltningsberättelsen.
  - Konsulten verifierar: om "mellan åren" avser varje par i flerårsöversikten eller bara innevarande år mot föregående (TODO i app/Domain/AnnualReport/AnnualAccounts.php rad 187), samt att gränsen 30 % och punkt 5.5 stämmer med BFNAR 2016:10 efter BFNAR 2025:2.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### ARS-08 Utdelningsförslag, fritt eget kapital och förändringar i eget kapital
- Förutsättningar: Grunddata (årets resultat bokat på 2099).
- Steg i appen:
  1. Fyll **Föreslagen utdelning, kr** med 100 000 och spara. Läs **Förslag till resultatdisposition** och **Förändringar i eget kapital**.
  2. Pröva 452 900 och 452 901.
  3. Pröva negativt belopp.
  4. Gör motsvarande för en ekonomisk förening (föreningsstämman).
- Förväntat resultat:
  - Fritt eget kapital = balanserat resultat 175 000 + årets resultat 277 900 = 452 900 kr, visat under fältet.
  - Utdelning 100 000: disposition "Balanseras i ny räkning" = 452 900 - 100 000 = 352 900.
  - 452 900 godtas; 452 901 nekas med meddelande att utdelningen är större än det fria egna kapitalet och att aktiebolagslagen 17 kap. 3 § inte tillåter det. Negativ utdelning: "Utdelningen kan inte vara negativ." För ekonomisk förening anges lagen om ekonomiska föreningar 12 kap. 3 §.
  - Förändringar i eget kapital (kolumner Aktiekapital | Balanserat resultat | Årets resultat | Totalt): Ingående 25 000 | 175 000 | 0 | 200 000; årets resultat | | | 277 900 | 277 900; Utgående 25 000 | 175 000 | 277 900 | 477 900. Efter att resultatdispositionen bokförts i 2027 visar 2027-rapporten utdelningen 100 000 och beloppet som balanserades i ny räkning.
  - Ingående totalt 200 000 + årets resultat 277 900 = utgående 477 900.
  - Konsulten verifierar: utdelningsspärren (ABL 17 kap. 3 §), att fritt eget kapital definieras som balanserat resultat plus årets resultat (BFNAR 2016:10 p. 5.8, ÅRL 6 kap. 2 §), och att försiktighetsregeln lämnas till styrelsen (TODO i app/Domain/Closing/DividendLimit.php rad 27).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### ARS-09 Noter
- Förutsättningar: Grunddata plus en tillgång på 1220 (10 000,00 kr, avskrivning 2 777,78 kr bokförd, se BOK-07 i bokslut.md; bokfört värde 7 222,22 kr), ett banklån, och balanserade uppgifter nedan.
- Steg i appen: Under **Noter som du fyller i**: **Ställda säkerheter, kr** 500 000 (typ företagsinteckning), **Eventualförpliktelser, kr** 100 000, **Åtaganden för koncern- och intresseföretag** med text, **Väsentliga händelser efter räkenskapsårets slut** med text och/eller rutan **Den ekonomiska effekten går inte att uppskatta**. Spara och läs notavsnittet. Tomma fält: kontrollera att inga tomma noter tas med. Lämna noter blanka i en kopia.
- Förväntat resultat:
  - Not Redovisningsprinciper, not om medelantal anställda (4) och not om anläggningstillgångar finns alltid när underlag finns. Anläggningstillgångar (Maskiner och inventarier): ackumulerat anskaffningsvärde 10 000 (ingående 0, inköp 10 000); ackumulerade avskrivningar -2 778 (2 777,78 avrundat); redovisat värde 7 222.
  - Ställda säkerheter 500 000 med slag (inteckning anges med nominellt belopp, övriga med bokfört värde), eventualförpliktelser 100 000 och övriga angivna noter visas bara när de fyllts i. Sidan påminner om säkerheter när företaget har bankskuld.
  - Konsulten verifierar: nothänvisningar och att standardtexter och noter räcker för K2-årsredovisning för ett mindre företag (TODO i app/Domain/AnnualReport/AnnualReportDocument.php rad 26),.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### ARS-10 Underskrifter och fastställelseintyg
- Förutsättningar: Grunddata.
- Steg i appen: Under **Texter och underskrifter**: **Ort**, **Datum för underskrift**, två underskrifter (en per rad: "namn, roll"). Under **Fastställelseintyg**: **Fastställelsedag** 2026-12-31 respektive 2027-05-20, **Den som intygar** "Beata Test", **Roll** "Styrelseledamot", **Stämmans beslut om vinsten eller förlusten** tomt respektive text. Spara och förhandsvisa.
- Förväntat resultat:
  - Fastställelsedag 2026-12-31 nekas: "Stämman kan fastställa räkningarna först efter räkenskapsårets slut, 2026-12-31." (dagen efter 2026-12-31 godtas, och datum 2027-01-01 är första tillåtna). Medelantal anställda kan inte vara negativt; belopp i fälten kan inte vara negativa ("Beloppet kan inte vara negativt.").
  - Fastställelseintyget (ÅRL 8 kap. 3 §): "Årsstämman" respektive "Föreningsstämman", fastställelsedag, vem som intygar, och beslutet. Är texten tom och resultatdispositionen bokad hämtas beslutet från den (utdelning 100 000, balanseras 352 900). Saknas uppgifter visas streckade rader.
  - Konsulten verifierar: Bolagsverkets krav på intyget och bestyrkandet (TODO i app/Domain/AnnualReport/AnnualReportDocument.php rad 109) och att underskriftsraderna räcker (ÅRL 2 kap. 7 §: samtliga styrelseledamöter och VD).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### ARS-11 PDF mot förhandsvisning
- Förutsättningar: Grunddata, fall ARS-04 till ARS-10 sparade.
- Steg i appen: Klicka **Ladda ner PDF**. Öppna PDF:en. Jämför varje tal med förhandsvisningen. Ändra ett belopp i bokföringen (bokför en verifikation) och ladda om sidan och PDF:en.
- Förväntat resultat:
  - PDF och förhandsvisning visar samma tal och texter (samma uträkning, ADR-002). Ny verifikation syns direkt i båda (siffrorna läses om vid varje visning); en nyligen ändrad bokföring efter att resultatet bokats visar differensen.
  - Rubriker: Förvaltningsberättelse, Resultaträkning, Balansräkning, Noter, Underskrifter, Fastställelseintyg. Alla tal i hela kronor, minus med minustecken, tusentalsavgränsare med mellanrum.
  - Teckensnitt och svenska tecken (å, ä, ö) renderas rätt; sidbrytningar klipper inte tabeller.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### ARS-12 iXBRL-fil (beta): när den skapas och när den vägras
- Förutsättningar: Aktiebolag med K2, grunddata. En kopia med en tillgång på konto 1220 (som ger raden "Maskiner och inventarier").
- Steg i appen: Klicka **Ladda ner som iXBRL (Beta)** på grunddata utan inventarier; upprepa med inventarier på 1220; upprepa med tom **Allmänt om verksamheten**; öppna adressen /arsredovisning/{år}/ixbrl för ett K3-företag och för en ekonomisk förening.
- Förväntat resultat (app/Support/Ixbrl/K2AnnualReportIxbrl.php):
  - Fil .xhtml (Content-Type application/xhtml+xml) hämtas när allt går att märka upp. Filen innehåller fastställelseintyget först när **Fastställelsedag** är ifylld, med Bolagsverkets formulering; namn, roll och underskriftsdatum fylls i hos Bolagsverket.
  - När något saknar taxonomimotsvarighet skapas ingen fil; ett felmeddelande listar vad: "iXBRL-filen kan inte skapas. Det här i årsredovisningen saknas eller har ingen motsvarighet i taxonomin för K2: ...". Kända fall: Maskiner och inventarier (NONOX visar en rad, taxonomin två), reservfond/bundet eget kapital, skulder till koncern-/intresseföretag, övriga förändringar i eget kapital, noterna om immateriella anläggningstillgångar, pågående nyanläggningar och åtaganden, tom text under Allmänt om verksamheten.
  - Ekonomisk förening och annan form än AB: "iXBRL-filen görs bara för aktiebolag än så länge." K3 eller K1: avvisning med regelverkets text.
  - NONOX lämnar inte in filen åt dig; texten säger det.
  - Konsulten verifierar: att begränsningen är ofarlig för kunden (en rad som alltid saknar taxonomimotsvarighet gör att många företag med inventarier inte får någon fil), och att det som skrivs i hjälptexten är sant. Notera hur många testbolag som faktiskt kan få en fil.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### ARS-13 iXBRL-filens riktighet och Arelle-validering
- Förutsättningar: En iXBRL-fil från ARS-12 (utan inventarier) sparad som rapport.xhtml. En teknisk person med Python/bash, `arelleCmdLine` (pip install arelle-release==2.47.4) och nätåtkomst till taxonomier.se.
- Steg i appen / i terminal:
  1. `scripts/fetch-xbrl-taxonomy.sh` (hämtar taxonomi-paket-2026-08-31.zip, kontrollerar SHA-256 47e56bca68c052b751ed8e0759fa9b04a6fa09b1d2b47ae2df4187d0ea2fe559). Notera mappen som skrivs ut.
  2. `scripts/validate-ixbrl-arelle.sh "<mapp>/../package.zip" rapport.xhtml` (paketet ligger som package.zip bredvid mappen).
  3. Läs loggen i arelle-logs/rapport.log. Öppna rapport.xhtml i en webbläsare och jämför sifferfakta med PDF:en.
  4. Kontrollera i XML att taggade belopp har `decimals="0"`, valuta SEK, rätt kontext (varaktighet 2026-01-01 till 2026-12-31, tillgångar och skulder per 2026-12-31) och att organisationsnumret använder den identifieringsmetod som anges.
- Förväntat resultat:
  - Skriptet skriver "rapport: 0 error(s)". Inga taxonomi-, beräknings-, kontext-, enhets- eller UTR-fel; inga varningar. (Så blev Arelle-körningen 2026-11-06 för filerna k2-loss.xhtml och k2-profitable.xhtml, ADR-058 tillägg E.3.)
  - Sifferfakta i XBRL är lika med PDF:ens belopp: nettoomsättning 1 200 000, årets resultat 277 900, summa tillgångar 850 000, summa eget kapital 477 900 (grunddata). Summor stämmer mot delposter (beräkningsrelationer, `--calc=c10d`).
  - Kunde inte beräkna: antal märkta fakta i just din fil (beror på noter och val).
  - Konsulten (tillsammans med teknisk person) verifierar: att Bolagsverket accepterar filen (inte provat; kontrollanropet mot Bolagsverkets test är inte gjort, ADR-058), tecken för kostnader och "Minskning av"-begrepp, decimaler, TR4-transformation, enhet för medelantal anställda, identifierarschema för organisationsnummer, och om taxonomin K2 AB 2024-09-12 gäller för räkenskapsår 2026 (TODO i app/Support/Ixbrl/InlineXbrlWriter.php rad 22 och 110, app/Domain/AnnualReport/K2Taxonomy.php rad 36 och 45 och 282, app/Support/Ixbrl/K2AnnualReportIxbrl.php rad 28).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### ARS-14 Ekonomisk förening
- Förutsättningar: Ekonomisk förening (testförening, ej bostadsrättsförening) med K2, grunddata anpassad (medlemsinsatser på 2083 i stället för aktiekapital).
- Steg i appen: Samma som ARS-04, ARS-08, ARS-10 och ARS-12.
- Förväntat resultat:
  - Rapporten tas fram, texten talar om "Föreningsstämman", utdelningsspärren hänvisar till lagen om ekonomiska föreningar 12 kap. 3 §, iXBRL-knappen visas inte (ADR-058: ingen taxonomi för ekonomisk förening i det som läses).
  - Konsulten verifierar: att uppställningen för ekonomiska föreningar (medlemsinsatser, förlagsinsatser, kooperativ utdelning och återbäring) är korrekt eller ska flaggas som ej stödd; manualen nämner att särregler för ekonomiska föreningar inte hanteras.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### ARS-15 Behörighet och åtkomst
- Förutsättningar: Användare med rollerna Bokförare, Attestant, Revisor, Redovisningskonsult; två företag A och B.
- Steg i appen: Försök öppna /arsredovisning, förhandsvisning, PDF och iXBRL som varje roll. Som användare i företag A, försök öppna ett räkenskapsårs-id som hör till B.
- Förväntat resultat:
  - Sidan och filerna kräver rätten `fiscal-years:manage` (ägare, administratör, redovisningskonsult): övriga roller nekas (HTTP 403). Annat företags räkenskapsår ska ge 404 (inte 403, tenancy-kravet i AGENTS.md 13.2); konsulten antecknar det faktiska utfallet.
  - Att spara, ladda ner PDF och iXBRL skrivs inte som bokföring, men åtgärder som sparar texter går att följa i behandlingshistoriken.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### ARS-16 Inlämning, taxonomi och vad NONOX inte gör
- Förutsättningar: Grunddata.
- Steg i appen: Läs texterna på sidan och i manualen om inlämning.
- Förväntat resultat:
  - NONOX lämnar inte in årsredovisningen till Bolagsverket; texten säger att det görs utanför systemet. Revisionsberättelse läggs till utanför systemet. Inlämning är tillåten men inte tvingande digitalt (ÅRL 8 kap. 3 b §).
  - iXBRL-taggningen följer taxonomin för K2 aktiebolag, version 2024-09-12, som ingår i taxonomipaketet 2026-08-31 (app/Domain/AnnualReport/K2Taxonomy.php: VERSION). Manualen nämner 2024-09-12.
  - Konsulten verifierar: vilken taxonomiversion Bolagsverket godtar för vilket räkenskapsår ("Gällande servicespecifikationer", ej läst; ADR-058) och att K2-taxonomin 2024-09-12 speglar BFNAR 2025:2 för räkenskapsår från 2026. Taxonomin för K3 AB har en revision 2026-09-09 med ändringar för BFNAR 2025:2 och BAS 2026 (ADR-059); om K2-taxonomin fått motsvarande revision ska den användas.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

## Öppna TODO(legal-verify) i koden

Sökning: `grep -rn "TODO(legal-verify)\|TODO(provider-verify)" app/Domain/AnnualReport app/Support/Ixbrl app/Actions/AnnualReport app/Http/Controllers/Accounting/AnnualReportController.php app/Livewire/Accounting/AnnualReportPage.php` samt app/Domain/Company/ReportingFrameworkRules.php (K2-villkoren; även i bokslut.md). I det första sökvägsurvalet ger sökningen 17 träffar; nr 18-21 är rader för K2-villkoren.

| nr | fil:rad | frågan (sammanfattning) | Konsultens beslut (Godkänd / Ändra / Avslag) | Kommentar |
|---|---|---|---|---|
| 1 | app/Domain/AnnualReport/AnnualReportDocument.php:26 | Följer årsredovisningen ÅRL och BFNAR 2016:10 så långt bokföringen räcker; notuppsättning (redovisningsprinciper, anställda, anläggningstillgångar, säkerheter, eventualförpliktelser, åtaganden, händelser efter året) och standardtexter ska kontrolleras före inlämning. | | |
| 2 | app/Domain/AnnualReport/AnnualReportDocument.php:109 | Bolagsverkets formkrav på fastställelseintyget och den bestyrkta kopian (ej läst 2026-11-02). | | |
| 3 | app/Domain/AnnualReport/AnnualAccounts.php:158 | Hur många år K2 kräver i flerårsöversikten, och om ett brutet eller förlängt första år kräver en not. | | |
| 4 | app/Domain/AnnualReport/AnnualAccounts.php:187 | Om "mellan åren" i 30 %-regeln avser varje par i översikten eller bara innevarande mot föregående år. | | |
| 5 | app/Domain/AnnualReport/AnnualAccounts.php:223 | Skattesatsen (20,6 %) för uppskjuten skatt på obeskattade reserver i soliditeten (fast 0,794 i koden). | | |
| 6 | app/Domain/AnnualReport/K2Schedule.php:12 | Radindelningen mot ÅRL bilaga 1 och 2 och BFNAR 2016:10, och BAS-intervallen mot officiell BAS-K2-koppling, särskilt 8000-8799. | | |
| 7 | app/Domain/AnnualReport/K2Schedule.php:34 | BAS 4900-4939 (råvaror, förnödenheter, halvfabrikat) och 4980-4999 hålls inom Råvaror och förnödenheter. | | |
| 8 | app/Domain/AnnualReport/PrintedFigures.php:25 | Vilken rad i K2-praxis en avrundningsdifferens i balansräkningen läggs på (Övriga skulder är ett val, ingen regel funnen). | | |
| 9 | app/Domain/AnnualReport/PrintedFigures.php:34 | Samma fråga för specifikationen av förändringar i eget kapital. | | |
| 10 | app/Domain/AnnualReport/K2Taxonomy.php:26 | Om en förteckning över begreppsnamn är ett bearbetat verk av taxonomin som kräver XBRL Internationals upphovsrättsmeddelande och länk. | | |
| 11 | app/Domain/AnnualReport/K2Taxonomy.php:36 | TODO(provider-verify): vilken taxonomiversion Bolagsverket godtar för vilket räkenskapsår. | | |
| 12 | app/Domain/AnnualReport/K2Taxonomy.php:45 | TODO(provider-verify): identifierarschemat för organisationsnumret enligt Bolagsverkets tillämpningsanvisning. | | |
| 13 | app/Domain/AnnualReport/K2Taxonomy.php:69 | Taxonomins finansiella poster är exklusive nedskrivningar och hålls separata, medan K2Schedule behåller BAS 8000-8399 samlade per slag; skatt 8900-8989 märks som skatt på årets resultat trots att 8980 har eget begrepp. | | |
| 14 | app/Domain/AnnualReport/K2Taxonomy.php:282 | TODO(provider-verify): vilka uppräknade värden tillämpningsanvisningen kräver och om Företagets säte (ej lagrat) krävs. | | |
| 15 | app/Support/Ixbrl/InlineXbrlWriter.php:22 | TODO(provider-verify): tecken på kostnader och "Minskning av"-begrepp, decimaler, vilket transformationsregister som godtas (TR4, 2020-02-12). | | |
| 16 | app/Support/Ixbrl/InlineXbrlWriter.php:110 | TODO(provider-verify): enhet för MedelantaletAnstallda (xbrli:pure tills vidare). | | |
| 17 | app/Support/Ixbrl/K2AnnualReportIxbrl.php:28 | TODO(provider-verify): Bolagsverkets tillämpningsanvisning 1.8 (krävd märkning, tillåten XHTML, storleksgräns, identifierare, kontext-id och period för intygsfakta). | | |
| 18 | app/Domain/Company/ReportingFrameworkRules.php:12 | Hela K-regelverksmatrisen. | | |
| 19 | app/Domain/Company/ReportingFrameworkRules.php:36 | K1 för HB/KB med enbart fysiska personer. | | |
| 20 | app/Domain/Company/ReportingFrameworkRules.php:60 | Sammanfattningen av 1.1A-1.1C och vilka uteslutningar som gäller andra bolagsformer. | | |
| 21 | app/Domain/Company/ReportingFrameworkRules.php:65 | Äldre lydelsen av punkt 1.1 för år före 2026-01-01. | | |

Dessutom öppna punkter i ADR-058 som inte har egen rad i koden (konsulten tar ställning): BOLFS 2018:1 4 § och BOLFS 2024:1 i Bolagsverkets text; om en karta över QNames är ett bearbetat verk; om Bolagsverket tar ekonomiska föreningar; hur representantbehörighet kontrolleras; om intyget ska skickas i samma fil eller separat.

## Avvikelser

| nr | fall | beskrivning | allvarlighet | åtgärd |
|---|---|---|---|---|
| | | | | |
| | | | | |
| | | | | |

## Godkännande

Modulen Årsredovisning K2 är granskad och godkänd för att tas ur Beta.

| | |
|---|---|
| Namn | ______________________ |
| Titel/auktorisation | ______________________ |
| Datum | ______________________ |
| NONOX-version | ______________________ |
| Underskrift | ______________________ |

[ ] Godkänd   [ ] Godkänd med villkor   [ ] Ej godkänd

Villkor/anmärkningar: ________________________________________________

Godkännandet gäller bara modulen Årsredovisning K2 (inklusive iXBRL-filen i beta) och inte andra moduler.
