# Granskningschecklista: Bokslut

| | |
|---|---|
| Modul | Bokslut: regelverk (K1/K2/K3), checklista, periodiseringar, anläggningsregister och avskrivning, obetalda fakturor och valutaomvärdering vid årets slut, skatteförslag och periodiseringsfond, årets resultat, stängning och lås, ingående balanser, resultatdisposition |
| Version av NONOX som granskas | ______________________ (fylls i av granskaren) |
| Granskare (namn) | ______________________ |
| Datum | ______________________ |
| Kod/ADR som testas | app/Domain/Closing/ (ClosingChecklist.php, TaxProposal.php, CorporateTaxRules.php, ClosingAccounts.php, YearResult.php, DividendLimit.php, UnpaidInvoices.php, CurrencyRevaluation.php, K2Reconfirmation.php), app/Actions/Closing/YearEndClosing.php, app/Actions/Closing/ConfirmK2Conditions.php, app/Actions/Assets/FixedAssets.php, app/Domain/Assets/DepreciationPlan.php, app/Domain/Accruals/AccrualPlan.php, app/Actions/Accounting/CarryForwardBalances.php, app/Domain/Company/ReportingFrameworkRules.php, database/data/corporate-tax/rules.json, docs/adr/ADR-056-tax-in-the-closing.md, docs/adr/ADR-059-k3-annual-report.md (steg F.1, spärren mot K3), docs/adr/ADR-025 (utländsk valuta), docs/adr/ADR-010 (rapporter, moms, bokslut) |
| Manualavsnitt | docs/manual/sv/bokslut/oversikt.md, checklistan.md, periodiseringar.md, anlaggningstillgangar.md, tillgang-fran-inkop.md, salja-eller-utrangera.md, kontantmetoden-vid-arets-slut.md, bokslutsverifikationer.md, stang-aret.md, nytt-rakenskapsar.md, resultatdisposition.md, docs/manual/sv/installningar/foretagets-uppgifter.md |

Granskningen gäller bokslutet som beräkning och bokföring. Årsredovisningen granskas i docs/granskning/arsredovisning-k2.md och inkomstdeklarationen i docs/granskning/skatt-ne-ink4-ink2-k10.md.

## Förutsättningar

- **Testbolag** "Testbolag Bokslut AB", påhittat organisationsnummer 556999-0012 (klarar Luhn-kontrollen). Inga riktiga företagsuppgifter, inga personnummer.
- **Bolagsform och regelverk:** aktiebolag, regelverk K2, fakturametoden, räkenskapsår 2026-01-01 till 2026-12-31 om inget annat anges. Fall som kräver annan form anger det själva.
- **Databas:** egen testdatabas (inte någons riktiga data). Kör aldrig `migrate:fresh`, `db:wipe` eller testsviten med `RefreshDatabase` mot den.
- **Användare:** en användare med rollen Redovisningskonsult (får hantera räkenskapsår och låsa perioder), och en med rollen Bokförare (för behörighetsfallen).
- **Bokföringen** läggs in med **Bokföring → Verifikationer** (/verifikationer/ny). Bokslutet görs under **Bokföring → Bokslut** (/bokslut). Anläggningar finns på /anlaggningar, periodiseringar på /periodiseringar, räkenskapsår på /rakenskapsar.
- **Skatteårets satser:** 20,6 % (inkomstskattelagen 65 kap. 10 §) för räkenskapsår som börjar 2021-01-01 eller senare (database/data/corporate-tax/rules.json). Status i datafilen är `researched`, alltså inte juridiskt kontrollerad. Samtliga skattefall ska därför ses som "Konsulten verifierar".
- **Alla belopp** är hela kronor om inget annat står. Teckenregeln i bokföringsexemplen: D = debet, K = kredit.
- Tillägg för varje fall: notera i kommentaren om NONOX lämnar avvikande siffror eller texter. Avrundning och kontonummer ska kontrolleras mot BAS-kontoplanen.

## Fall

### BOK-01 K2-villkoren bekräftas före bokslutet (gränsdatum 2025-12-31)
- Förutsättningar: Företag med regelverk K2 (Inställningar → företagets uppgifter, "Regelverk för årsbokslut"). Företaget har aldrig bekräftat K2-villkoren (`k2_conditions_confirmed_at` är tomt, till exempel ett företag som lades upp före BFNAR 2025:2). Två räkenskapsår: 2025 (2025-01-01 till 2025-12-31) och 2026.
- Steg i appen:
  1. Gå till **Bokföring → Bokslut** och välj 2026.
  2. Läs rutan "Bekräfta villkoren för K2 innan du fortsätter". Läs villkorstexten.
  3. Försök boka skatt eller stänga året utan att bekräfta.
  4. Klicka på **Företaget uppfyller villkoren för K2**.
  5. Välj år 2025 och kontrollera att rutan och checklistepunkten saknas.
  6. Skapa ett brutet första räkenskapsår som börjar 2025-12-31 (om bolaget tillåter det) och ett som börjar 2026-01-01 och jämför.
- Förväntat resultat:
  - För 2026 (börjar efter 2025-12-31): punkten "K2-villkoren är bekräftade" finns i checklistan, status att göra, och steg 2-3 stoppas med texten "Bekräfta villkoren för K2 högst upp på sidan innan du fortsätter med bokslutet." (app/Actions/Closing/YearEndClosing.php, `ensureK2Confirmed`). Efter klick är punkten klar och rutan försvunnen. Bekräftelsen sparas en gång för företaget och skrivs i behandlingshistoriken (`company.k2_conditions_confirmed`).
  - För 2025 visas ingen K2-punkt (år som börjar 2025-12-31 eller tidigare omfattas inte, `K2Reconfirmation::LAST_OLD_DAY = 2025-12-31`). Ett år som börjar 2025-12-31 ger ingen punkt, ett som börjar 2026-01-01 ger punkt.
  - Konsulten verifierar: att villkorstexten i rutan (ReportingFrameworkRules::k2Conditions) stämmer med BFNAR 2016:10 punkt 1.1, 1.1A, 1.1B och 1.1C i lydelsen enligt BFNAR 2025:2 (källa: https://www.bfn.se/wp-content/uploads/vl16-10-k2ar-kons2025.pdf, s. 15-16 och övergångsreglerna), att tillämpningsdatum "räkenskapsår som börjar efter 31 december 2025" är rätt, att särregeln för företag som startat efter 30 juni 2025 med förlängt år är rätt återgiven, och att en bekräftelse per företag räcker (TODO i app/Domain/Closing/K2Reconfirmation.php rad 18).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-02 Vilka regelverk en bolagsform får välja
- Förutsättningar: Fem testföretag eller samma företag som ändras: aktiebolag, ekonomisk förening, enskild firma, handelsbolag, ideell förening. Användare med rätt att ändra företagets uppgifter.
- Steg i appen:
  1. Gå till företagets uppgifter, fältet **Regelverk för årsbokslut**.
  2. För varje bolagsform, försök välja K1, K2 och K3 och spara.
  3. Läs villkorstexterna som visas för K1 och K2 och kryssa i **Företaget uppfyller villkoren ovan**.
- Förväntat resultat (app/Domain/Company/ReportingFrameworkRules.php, `refusal`):
  - K1 godkänns för enskild firma och ideell förening.
  - K1 vägras för aktiebolag och ekonomisk förening med texten "K1 (förenklat årsbokslut) kan bara användas av enskilda firmor och ideella föreningar. ... kan välja K2 eller K3."
  - K1 vägras för handelsbolag och kommanditbolag med texten "K1 för handels- och kommanditbolag är inte kontrollerat ännu. Välj K2 eller K3."
  - K2 och K3 vägras inte på bolagsform (K3 kräver ingen bekräftelse).
  - K2-villkor: för aktiebolag visas storleksvillkoret (mer än 50 anställda, balansomslutning över 40 miljoner kronor, nettoomsättning över 80 miljoner kronor, mer än ett av tre under båda de två senaste åren, eller noterade värdepapper) plus uteslutningarna i 1.1A (publikt AB, moderföretag, filial utomlands, aktierelaterade ersättningar, konvertibler, kryptotillgångar), 1.1B (väsentlig uppskjuten skatteskuld, byggnader ger minst 75 % av nettoomsättningen) och 1.1C. För ekonomisk förening gäller samma med bostadsrättsföreningar uteslutna. För övriga bolagsformer visas bara storleksvillkoret.
  - K1-villkor: nettoomsättningen normalt högst 3 miljoner kronor per år.
  - Konsulten verifierar: hela matrisen (TODO i app/Domain/Company/ReportingFrameworkRules.php rad 12), särskilt att K1 för handels-/kommanditbolag med enbart fysiska personer ska vägras tills vidare (rad 36), samt vilka uteslutningar som gäller handelsbolag, stiftelse och ideell förening som upprättar årsredovisning under K2 (rad 60). Källor: BFNAR 2006:1 punkt 1.1-1.2, BFNAR 2016:10 punkt 1.1-1.1C, BFL 6 kap. 1 och 3 §, ÅRL 1 kap. 3 §.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-03 K3-företag i bokslutet
- Förutsättningar: Ett aktiebolag med regelverk K3 och en bokförd verksamhet 2026.
- Steg i appen:
  1. Gå till **Bokföring → Bokslut**, välj 2026 och gå igenom checklistan.
  2. Gå till **Bokföring → Årsredovisning**.
- Förväntat resultat:
  - Checklistan saknar punkten "K2-villkoren är bekräftade" (`K2Reconfirmation::applies` gäller bara K2). Bokslutet kan genomföras som vanligt.
  - Årsredovisningssidan visar rubriken "Årsredovisning enligt K3 finns inte ännu" och förhandsvisning, PDF och iXBRL är avstängda (docs/adr/ADR-059-k3-annual-report.md, steg F.1; AnnualReportDocument::refusal). Se ARS-01 för detaljerna.
  - Konsulten verifierar: att K3-företagets bokslut inte gör något K2-specifikt, till exempel att tillgångar och avskrivningar bokförs enligt plan (inget K3-krav på komponentavskrivning hanteras, ADR-059 avsnitt 1); notera sådant som avvikelse eller villkor.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-04 Checklistan och behörighet
- Förutsättningar: K2-aktiebolag med bekräftade K2-villkor (BOK-01). Bokför två verifikationer 2026 och lämna en som utkast. Momsperioder ej bokförda.
- Steg i appen:
  1. Som Redovisningskonsult: **Bokföring → Bokslut**, år 2026. Läs alla punkter och deras status.
  2. Bokför eller ta bort utkastet och se punkten **Inga utkast i året** ändras.
  3. Bocka av **Kassan är inventerad** med **Klart eller gäller inte**, ta bort bocken igen.
  4. Logga in som Bokförare och försök öppna /bokslut.
- Förväntat resultat:
  - Punkter i checklistan (app/Domain/Closing/ClosingChecklist.php): Inga utkast i året, Momsen är redovisad, Ingående balanser stämmer med förra året, Bank och kassa är avstämda, Avskrivningarna är bokförda, Periodiseringarna är bokförda, Obetalda fakturor vid årets slut är bokförda, Valutafordringar och valutaskulder är omvärderade, de sex manuella (Kassan är inventerad, Kundfordringar stämmer, Leverantörsskulder stämmer, Skattekontot är avstämt, Löner och semesterskuld är bokförda, Varulagret är inventerat), Årets skatt är bokförd, Årets resultat är bokfört.
  - Med ett utkast: punkten står som att göra med texten "1 verifikationer är utkast. Bokför eller ta bort dem." (räknaren är i klartext ":count verifikationer är utkast"). Med noll utkast: "Alla verifikationer i året är bokförda."
  - Punkter som inte gäller (inga anläggningar, inga periodiseringar, fakturametoden) har status "gäller inte" och blockerar inte.
  - Att bocka av och ta bort bocken fungerar bara medan året är öppet; vem och när sparas och skrivs i behandlingshistoriken (`closing.checked`, `closing.unchecked`).
  - Bokförare nekas åtkomst (bokslut kräver `fiscal-years:manage`: ägare, administratör, redovisningskonsult).
  - "Momsen är redovisad": med kvartalsmoms som löper över årsskiftet räknas bara perioder som slutar i året.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-05 Förutbetald kostnad (periodisering)
- Förutsättningar: K2-aktiebolag. En årsförsäkring på 12 000,00 kr exklusive moms, betald i juli 2026 och bokförd direkt på kostnadskontot 6310 (eller annat förslag i kontoplanen).
- Steg i appen:
  1. **Bokföring → Periodiseringar**, **Ny periodisering**.
  2. Slag **Förutbetald kostnad**. Benämning "Företagsförsäkring 2026-2027". Belopp exkl. moms 12 000. Första månad 2026-07, antal månader 12. Kostnadskonto 6310, balanskonto 1790.
  3. Kryssa i "Fakturan är bokförd direkt på resultatkontot. Flytta först hela beloppet till balanskontot." och **Spara**.
  4. Under **Månadens periodiseringar**, välj varje månad 2026-07 till 2026-12 i tur och ordning och klicka **Bokför periodiseringar**.
  5. Öppna **Bokföring → Bokslut** och läs punkten **Periodiseringarna är bokförda**. Gör om steg 4 men hoppa över 2026-12 först.
- Förväntat resultat:
  - Månadsdel: 12 000,00 / 12 = 1 000,00 kr per månad (app/Domain/Accruals/AccrualPlan.php: `intdiv(total * månader, antal)`, avrundning nedåt per ackumulerat belopp).
  - Flytten bokas en gång: 1790 D 12 000,00, 6310 K 12 000,00. Därefter varje månad 6310 D 1 000,00, 1790 K 1 000,00, sista dagen i månaden, serie A.
  - Efter 2026-12 är sex månader släppta: 6 x 1 000,00 = 6 000,00 kr. Kvar på 1790 per 2026-12-31: 12 000,00 - 6 000,00 = 6 000,00 kr. Nettokostnad på 6310 för 2026: 6 000,00 kr.
  - Hoppar du över december står punkten som att göra: "1 periodiseringar har månader kvar att bokföra till och med 2026-12". När december är bokförd: "Alla periodiseringar är bokförda till och med 2026-12" och punkten är klar. Samma steg kan inte bokföras två gånger; en missad månad tas igen nästa gång.
  - Konsulten verifierar: vilka poster ett K2-företag måste periodisera och om beloppsgräns finns (manualen lämnar det öppet; BFNAR 2016:10 kap. 2 om periodisering).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-06 Periodisering: avrundning och upplupen kostnad
- Förutsättningar: K2-aktiebolag.
- Steg i appen:
  1. **Ny periodisering**, slag **Förutbetald kostnad**, belopp 1 000,00 kr, första månad 2026-11, 3 månader, 6310/1790, utan flytt. Bokför 2026-11 och 2026-12.
  2. **Ny periodisering**, slag **Upplupen kostnad**, "El december", 4 800,00 kr, första månad 2026-12, kostnadskonto 5020, balanskonto 2990. Bokför månaden 2026-12 och sedan 2027-01.
- Förväntat resultat:
  - Förutbetald, avrundning: släppt per månad = `intdiv(100 000 öre x n / 3)`: nov 33 333 öre, dec 66 666 öre, jan 100 000 öre. Bokningar: november 333,33 kr, december 333,33 kr, januari (2027) 333,34 kr. Summa 1 000,00 kr, ingen öresförlust. Kvar på 1790 per 2026-12-31: 1 000,00 - 666,66 = 333,34 kr.
  - Upplupen kostnad: 2026-12-31 5020 D 4 800,00, 2990 K 4 800,00. Återföring 2027-01-01: 2990 D 4 800,00, 5020 K 4 800,00. Resultat 2026 belastas med 4 800,00 kr. Punkten i checklistan blockerar tills upplupen kostnad för året är bokförd.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-07 Anläggningstillgång och linjär avskrivning
- Förutsättningar: K2-aktiebolag, kontona 1220, 1229 och 7832 finns.
- Steg i appen:
  1. **Bokföring → Anläggningar**, **Ny tillgång**.
  2. Benämning "Espressomaskin", slag **Inventarier och verktyg**, anskaffningsdag 2026-03-15, anskaffningsvärde 10 000,00 kr, restvärde 0, nyttjandeperiod 36 månader, första avskrivningsmånad 2026-03. Spara.
  3. Bokför avskrivningarna månad för månad 2026-03 till 2026-12 med **Bokför avskrivningar**. Gör en andra provkörning med en kopia där du hoppar över mars-november och bokför bara 2026-12.
  4. Öppna tillgången och läs **Avskrivningsplan**.
- Förväntat resultat (app/Domain/Assets/DepreciationPlan.php: ackumulerat = `(avskrivningsbart x månader + livslängd/2) / livslängd`, avrundat halvt uppåt):
  - Ackumulerat efter månad 1: 1 000 000 x 1 / 36 = 27 777,78 öre, avrundat 27 778 öre. Efter 2: 55 555,56 öre, 55 556. Efter 3: 83 333,33 öre, 83 333. Efter 12: 333 333 öre. Efter 36: 1 000 000 öre.
  - Månadsbelopp: mars 277,78 kr, april 277,78 kr, maj 277,77 kr (83 333 - 55 556 = 27 777 öre).
  - För 2026 (mars-december, 10 månader): ackumulerat 1 000 000 x 10 / 36 = 277 777,78 öre, avrundat 277 778 öre = 2 777,78 kr. Verifikation(er) i serie A sista dagen i månaden: 7832 D, 1229 K. Bokfört värde 2026-12-31: 10 000,00 - 2 777,78 = 7 222,22 kr.
  - Provkörning 2 (bara december): en verifikation 2026-12-31 på 2 777,78 kr (missade månader tas igen). Samma slutsumma.
  - Summan av hela planen är exakt 10 000,00 kr. En månad bokförs bara en gång per tillgång; efter bokförd månad kan bara benämning och anteckning ändras.
  - Konsulten verifierar: att planenlig linjär avskrivning från inköpsmånaden är en godtagbar K2-metod och att avrundning halvt uppåt per ackumulerat belopp är godtagbar (TODO i app/Domain/Assets/DepreciationPlan.php rad 17: skattemässig avskrivning, räkenskapsenlig avskrivning, överavskrivning hanteras inte).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-08 Tillgång med restvärde, delår och checklistan
- Förutsättningar: K2-aktiebolag.
- Steg i appen:
  1. **Ny tillgång** "Maskin", slag **Maskiner och andra tekniska anläggningar**, anskaffningsvärde 60 000,00 kr, restvärde 6 000,00 kr, nyttjandeperiod 60 månader, första avskrivningsmånad 2026-10.
  2. Läs punkten **Avskrivningarna är bokförda** i bokslutets checklista innan och efter att du bokför oktober-december.
  3. Prova ogiltiga värden: anskaffningsvärde 0, restvärde 60 000, "Redan avskrivet" större än avskrivningsbart.
- Förväntat resultat:
  - Avskrivningsbart: 60 000 - 6 000 = 54 000,00 kr. Per månad 54 000 / 60 = 900,00 kr exakt. Oktober-december: 3 x 900,00 = 2 700,00 kr (7831 D, 1219 K). Bokfört värde 2026-12-31: 60 000 - 2 700 = 57 300,00 kr.
  - Checklistepunkten är "att göra" tills december är bokförd: ":count tillgångar har avskrivningar kvar att bokföra till och med 2026-12". Därefter "Alla tillgångar är avskrivna till och med 2026-12".
  - Valideringar: "Anskaffningsvärdet ska vara större än noll.", "Restvärdet ska vara mindre än anskaffningsvärdet.", "Gjorda avskrivningar kan inte vara större än det som ska skrivas av."
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-09 Sälja eller utrangera en tillgång; gräns för inventarier av mindre värde
- Förutsättningar: Espressomaskinen från BOK-07 med avskrivning bokförd till och med 2026-12 (ackumulerat 2 777,78 kr, bokfört värde 7 222,22 kr). Betalningskonto 1930.
- Steg i appen:
  1. **Bokföring → Anläggningar**, öppna tillgången, **Sälj eller utrangera**. Datum 2027-01-10, pris exkl. moms 8 000,00 kr, betalning till konto 1930, **Boka bort tillgången**.
  2. På en andra kopia av tillgången: pris 0 (kassering).
  3. Under **Tillgångar från inköp**, fyll i **Gräns för inventarier av mindre värde, kr** med 25 000 (påhittat värde, inte den lagliga gränsen), spara, och registrera en leverantörsfaktura med rad på 1220 om 20 000 kr och en om 25 000 kr och en om 30 000 kr.
- Förväntat resultat:
  - Försäljning: 1229 D 2 777,78; 1930 D 8 000,00; 1220 K 10 000,00; 3973 K 777,78 (vinst 8 000,00 - 7 222,22). Debet 10 777,78 = kredit 10 777,78. Tillgången står som Avyttrad, inga fler avskrivningar. Momsen på försäljningen bokförs inte av funktionen (manualen).
  - Kassering: 1229 D 2 777,78; 7973 D 7 222,22; 1220 K 10 000,00. Debet 10 000,00 = kredit 10 000,00.
  - Redan avyttrad tillgång: "Tillgången är redan avyttrad." Negativt pris: "Priset kan inte vara negativt."
  - Gränsen: rad på 20 000 kr får ingen tillgångsförslag, bara upplysning om att beloppet är under gränsen. Rad på 25 000 kr (lika med gränsen) får förslag, eftersom jämförelsen är "belopp < gräns" (resources/js/asset-candidates.js). Rad på 30 000 kr får förslag. Utan angiven gräns får varje rad förslag.
  - Konsulten verifierar: att vinst/förlust bokförs på 3973/7973, om avyttringsmånaden ska skrivas av och momsbehandlingen (TODO i app/Actions/Assets/FixedAssets.php rad 284), och att NONOX inte föreslår någon laglig gräns för inventarier av mindre värde (rad 80: IL 18 kap. 4 §, halvt prisbasbelopp, sambruk).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-10 Kontantmetoden: obetalda fakturor vid årets slut
- Förutsättningar: K2-aktiebolag med **kontantmetoden** (Inställningar). Nästa räkenskapsår 2027 finns. Kundfaktura 1 på 10 000,00 kr + 25 % moms = 12 500,00 kr, fakturerad 2026-12-10, obetald. Leverantörsfaktura 4 000,00 kr + 1 000,00 kr moms = 5 000,00 kr, registrerad 2026-12-05, obetald. Båda betalas 2027-01-10.
- Steg i appen:
  1. **Bokföring → Bokslut**, år 2026, efter 2026-12-31 (eller med systemdatum efter årets slut). Läs punkten **Obetalda fakturor vid årets slut är bokförda**.
  2. Under **Obetalda fakturor vid årets slut**, kontrollera listan, klicka **Bokför obetalda fakturor** och bekräfta.
  3. Registrera betalningarna 2027-01-10.
  4. Prova samma på ett företag med fakturametoden.
- Förväntat resultat (app/Domain/Closing/UnpaidInvoices.php, BFL 5 kap. 2 §):
  - Två verifikationer i serie A 2026-12-31: Kundfaktura 1510 D 12 500,00, intäktskonto K 10 000,00, utgående moms 2611 K 2 500,00. Leverantörsfaktura: kostnadskonto D 4 000,00, ingående moms 2641 D 1 000,00, 2440 K 5 000,00. Kundintäkten och kostnaden hamnar i 2026.
  - Två omvända verifikationer 2027-01-01 med samma belopp i motsatt riktning. När betalningarna bokförs 2027-01-10 tar återföring och betalning ut varandra, så att intäkt, kostnad och moms bara bokförs en gång (i 2026).
  - Checklistepunkten blir klar. Bokför du igen: "De obetalda fakturorna är redan bokförda. Rätta verifikationen om den blev fel."
  - Fakturametoden: punkten är "gäller inte" ("Gäller bara kontantmetoden. Med fakturametoden är fakturorna redan bokförda."). Utan nästa år: "Lägg upp nästa räkenskapsår först. Bokföringen av de obetalda fakturorna återförs på dess första dag." Före årets slut: "De obetalda fakturorna bokförs efter årets sista dag, när det är klart vad som betalades i tid."
  - Konsulten verifierar: återföring första dagen i nästa år som metod, och i vilken momsperiod momsen ska redovisas (TODO i app/Domain/Closing/UnpaidInvoices.php rad 32; Mervärdesskattelag 2023:200 7 kap.). Kontonummer för intäkt och moms följer fakturans konteringsrader (de är samma som fakturametoden).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-11 Kontantmetoden: delbetalning, kreditfaktura och betalning efter årsskiftet
- Förutsättningar: Som BOK-10. Kundfaktura 12 500,00 kr (10 000,00 + 2 500,00 moms), delbetald 5 000,00 kr 2026-12-20 och resten betald 2027-01-05. En andra kundfaktura på 6 250,00 kr (5 000,00 + 1 250,00) som delkrediteras med en kreditfaktura på 1 250,00 kr (1 000,00 + 250,00) 2026-12-28.
- Steg i appen: **Bokföring → Bokslut**, **Bokför obetalda fakturor**. Läs listan med belopp som återstår.
- Förväntat resultat:
  - Faktura 1: obetalt 12 500,00 - 5 000,00 = 7 500,00 kr (betalningen 2027-01-05 räknas inte av). Fördelas efter andel 7 500 / 12 500 = 60 %: 1510 D 7 500,00; intäkt K 6 000,00; moms K 1 500,00.
  - Faktura 2: 6 250,00 - 1 250,00 = 5 000,00 kr obetalt: 1510 D 5 000,00; intäkt K 4 000,00 (5 000 - 1 000); moms K 1 000,00 (1 250 - 250).
  - Sammanlagd 1510: 12 500,00 kr. Eventuell öresavrundning går på den största raden så att verifikationen balanserar (UnpaidInvoices::customerLines).
  - Konsulten verifierar fördelningen av moms och intäkt vid delbetalning (pro rata) och behandlingen av kreditfaktura för en betald faktura (skuld till kunden på 1510 kredit), manual kontantmetoden-vid-arets-slut.md.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-12 Omvärdering av fakturor i utländsk valuta
- Förutsättningar: K2-aktiebolag, fakturametoden. Nästa år finns. Kundfaktura EUR 1 000,00 bokförd till kursen 11,00 (11 000,00 kr) och leverantörsfaktura EUR 500,00 bokförd till kursen 11,00 (5 500,00 kr), båda obetalda 2026-12-31. Riksbankens kurs 2026-12-31 är hämtad av NONOX; för testet antas kursen 11,20 (skriv ner den riktiga kursen och räkna om).
- Steg i appen:
  1. **Bokföring → Bokslut**, efter årets slut. Läs punkten **Valutafordringar och valutaskulder är omvärderade**.
  2. Klicka **Bokför omvärderingen** och bekräfta.
  3. Prova utan kurs (en valuta Riksbanken saknar) och bocka "Omvärderingen är bokförd för hand".
- Förväntat resultat (app/Domain/Closing/CurrencyRevaluation.php):
  - Fordran: 1 000 x 11,20 = 11 200,00 kr, bokfört 11 000,00 kr, kursvinst +200,00 kr. Skuld: 500 x 11,20 = 5 600,00 kr, bokfört 5 500,00 kr, kursförlust 100,00 kr.
  - Verifikation 2026-12-31: 1510 D 200,00; 2440 K 100,00; 3960 K 200,00; 7960 D 100,00. Debet 300,00 = kredit 300,00. Återföring 2027-01-01 med omvända tecken.
  - Utan kurs: "Riksbanken har ingen kurs för :currencies den :date. Bokför omvärderingen för hand." och punkten kan bekräftas för hand. Kontantmetoden: omvärderingen kräver först att de obetalda fakturorna är bokförda. Före årets slut: "Omvärderingen görs efter årets sista dag, med kursen den dagen."
  - Kunde inte beräkna exakt: den faktiska Riksbankskursen per testdatum; konsulten räknar om med den kurs NONOX visar. Konsulten verifierar: att Riksbankens mittkurs får användas som balansdagskurs (K2 13.5 och 17.8, K3 30.3) och återföringen första dagen (TODO i app/Domain/Closing/CurrencyRevaluation.php rad 39).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-13 Skatteförslag, enkelt fall (20,6 %)
- Förutsättningar: K2-aktiebolag, K2-villkor bekräftade, regelverk och bolagsform enligt BOK-01. Inga periodiseringsfonder. Bokför intäkt 600 000,00 kr (3010) och kostnader 100 000,00 kr (5010) 2026. Resultat före skatt 500 000,00 kr. Inga justeringar under Inkomstdeklaration.
- Steg i appen:
  1. **Bokföring → Bokslut**, år 2026, steg **2. Bokslutsverifikationer**, avsnittet **Årets skatt**.
  2. Svara på frågan "Är företaget ett privatbostadsföretag, till exempel en bostadsrättsförening, eller ett investmentföretag?" med **Nej, inget av dem**.
  3. Läs tabellen (kolumnerna Rad, Varifrån, Belopp).
  4. Klicka **Bekräfta och bokför skatten** och läs bekräftelsetexten innan du bekräftar.
- Förväntat resultat (app/Domain/Closing/TaxProposal.php, CorporateTaxRules, rules.json):
  - Rader: "Bokfört resultat före skatt" 500 000; "Skattemässigt överskott" 500 000; "Beskattningsbar inkomst, avrundad nedåt till helt tiotal kronor" 500 000 (redan jämnt tiotal). Skatt: 500 000 x 20,6 % = 103 000,00 kr (500 000 x 2 060 / 10 000 = 103 000).
  - Verifikation serie A 2026-12-31: 8910 D 103 000,00; 2512 K 103 000,00. Saknade konton läggs till med BAS-namn ("Skatt som belastar årets resultat", "Beräknad inkomstskatt").
  - Dialogen räknar upp raderna ("skatt 103 000,00 kr på 8910 mot 2512") och säger att steget inte kan göras om. Efter bekräftelse är punkten **Årets skatt är bokförd** klar. Ett nytt försök: "Årets skatt är redan bokförd. Rätta verifikationen om den blev fel." Ändras böckerna mellan visning och bekräftelse: "Beräkningen har ändrats sedan den visades. Granska den igen innan du bekräftar."
  - Utan svar på frågan om privatbostadsföretag finns inget förslag (blocker "Svara först på frågan ...").
  - Konsulten verifierar: skattesatsen 20,6 % (IL 65 kap. 10 §) mot lagtexten, och att beloppet i öre (rundad inkomst x sats) är det som ska bokas, inte ett till hela kronor avrundat skattebelopp (TODO i app/Domain/Closing/TaxProposal.php rad 33).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-14 Skatteförslag: avrundning nedåt till tiotal och ören
- Förutsättningar: Som BOK-13 men resultat före skatt 123 457,00 kr (intäkt 223 457,00, kostnad 100 000,00). Inga justeringar.
- Steg i appen: Som BOK-13, läs raden "Beskattningsbar inkomst, avrundad nedåt till helt tiotal kronor" och skattebeloppet.
- Förväntat resultat:
  - Skattemässigt överskott 123 457; avrundat nedåt till tiotal (IL 1 kap. 7 §): 123 450. Skatt: 123 450 x 20,6 % = 25 430,70 kr (123 450 x 2 060 / 10 000 = 25 430,7). Verifikation: 8910 D 25 430,70; 2512 K 25 430,70.
  - Bokfört resultat efter skatt: 123 457,00 - 25 430,70 = 98 026,30 kr.
  - Gränsfall: överskott 10 kr eller mindre (t.ex. 9 kr) ger inkomst 0 och skatt 0: ingen verifikation, "årets skatt sparas som noll". Resultat före skatt med ören (123 457,49 kr och 123 457,50 kr) avrundas till hela kronor innan tiotalsavrundningen (Ink2::kronor, halvt uppåt: 123 457 respektive 123 458); båda ger 123 450.
  - Konsulten verifierar: avrundningsordningen (resultatet först till hela kronor, sedan nedåt till tiotal) och att skatten bokas i öre.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-15 Skatteförslag: justeringar från bokföringen (ej avdragsgilla kostnader, skattefria intäkter)
- Förutsättningar: Som BOK-13 med resultat före skatt 500 000,00 kr. I bokföringen ingår 6072 Representation, ej avdragsgill 4 000,00 kr (kostnad) och 8314 Skattefria ränteintäkter 1 500,00 kr (intäkt).
- Steg i appen: Läs raderna i skatteberäkningen. Gå sedan till **Bokföring → Inkomstdeklaration**, fyll i 0 i **Andra bokförda kostnader som inte ska dras av** och gå tillbaka till bokslutet.
- Förväntat resultat:
  - Förslag ur bokföringen: fält 7653 = 4 000 kr (6072 + 6982 + 6992 + 8423), fält 7754 = 1 500 kr (8314). Skattemässigt överskott = 500 000 + 4 000 - 1 500 = 502 500. Rundat 502 500. Skatt = 502 500 x 20,6 % = 103 515,00 kr.
  - Efter att du skrivit 0 i 7653 (ifyllt belopp ersätter förslaget, även 0): 500 000 - 1 500 = 498 500, rundat 498 500, skatt 498 500 x 20,6 % = 102 691,00 kr.
  - Kolumnen "Varifrån" visar "Förslag ur bokföringen" respektive "Ifyllt under Inkomstdeklaration".
  - Konsulten verifierar: att kontona 6072, 6982, 6992 och 8423 bara innehåller icke avdragsgilla kostnader och 8314 bara skattefria intäkter (8423 kan även innehålla avdragsgill ränta), enligt IL 16 kap. 2 §, 9 kap. 8 och 9 §§, 8 kap. 7 § (TODO i app/Domain/IncomeTax/Ink2.php rad 212 och i ADR-056).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-16 Periodiseringsfond: avsättning (högst 25 %)
- Förutsättningar: Som BOK-13, resultat före skatt 500 000,00 kr, inga justeringar, inga tidigare fonder.
- Steg i appen:
  1. I skatteberäkningen, klicka **Använd högsta avsättning**.
  2. Läs raderna och bekräfta.
  3. Pröva i en kopia: avsättning 125 001 och därefter 0.
  4. Pröva i en kopia med resultat före skatt 123 457 och klicka **Använd högsta avsättning**.
- Förväntat resultat (IL 30 kap. 5 §, TaxProposal::maxAllocationKronor):
  - Underlag 500 000; högsta avsättning 25 % = 125 000. Skattemässigt överskott efter avsättning = 500 000 - 125 000 = 375 000. Skatt = 375 000 x 20,6 % = 77 250,00 kr.
  - Verifikation serie A 2026-12-31: 8811 D 125 000,00; 2126 K 125 000,00 (konto 2120 + sista siffran i 2026 = 2126); 8910 D 77 250,00; 2512 K 77 250,00. Debet 202 250,00 = kredit 202 250,00.
  - 125 001: "Avsättningen kan vara högst 125 000,00 kr, 25 procent av överskottet före avsättningen (inkomstskattelagen 30 kap. 5 §)." (beloppsformat enligt appen)
  - Underlag 123 457: 25 % = 30 864,25, avrundat nedåt till 30 864 kr. Skatt efter avsättning: 123 457 - 30 864 = 92 593, rundat 92 590, x 20,6 % = 19 073,54 kr.
  - Är konto 2126 redan använt och 2136 används i stället ("nr 2"); är båda upptagna: "Båda BAS-kontona för årets periodiseringsfond har redan ett saldo. Bokför avsättningen för hand."
  - Konsulten verifierar: att högsta avsättning avrundas nedåt till hela kronor, att kontot följer räkenskapsårets slutår, och att varje års avsättning är egen fond (IL 30 kap. 3-5 §§; TODO i app/Domain/Closing/TaxProposal.php rad 33).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-17 Periodiseringsfond: obligatorisk återföring och schablonintäkt
- Förutsättningar: Som BOK-13. Ingående balans 2026-01-01 på konto 2120 (periodiseringsfond 2020) kredit 80 000,00 kr, ingen annan fond. Företaget har bara räkenskapsår 2026 i NONOX. Resultat före skatt 300 000,00 kr, inga ifyllda justeringar. Statslåneräntan 30 november 2025 är 2,55 % (app/Domain/IncomeTax/TaxAllocationReserves.php).
- Steg i appen:
  1. Läs förslagets rader och varningar. Läs fälten "Återföring av periodiseringsfond, kr" (förifyllt).
  2. Försök sänka återföringen till 79 999 och bekräfta.
  3. Bekräfta med 80 000.
- Förväntat resultat:
  - Fond 2120 hör till år 2020 (konto 2120 = 2020, 2126 = 2026, 2129 = 2019). 2026 - 2020 = 6 år, alltså obligatorisk återföring i år (IL 30 kap. 7 §: senast det sjätte beskattningsåret efter avdraget).
  - Schablonintäkt (fält 7654): 80 000 x 2,55 % = 2 040,00 kr för ett helt år (app: (8 000 000 öre x 255 x 12 + 60 000) / 120 000 = 204 000 öre).
  - Underlag = bokfört resultat 300 000 + schablonintäkt 2 040 + återföring 80 000 = 382 040. Högsta avsättning 25 % = 95 510 (382 040 x 0,25 = 95 510). Utan avsättning är skattemässigt överskott 382 040, rundat 382 040. Skatt = 382 040 x 20,6 % = 78 700,24 kr.
  - Verifikation: 2120 D 80 000,00; 8819 K 80 000,00; 8910 D 78 700,24; 2512 K 78 700,24.
  - 79 999: blockerat: "En periodiseringsfond ska återföras senast det sjätte beskattningsåret efter avsättningen ... Återför minst 80 000 kr i år." Ingen verifikation.
  - Saknas statslåneränta för året (testa med ett räkenskapsår som slutar 2027-12-31 med fond på ingångsbalansen): blockeras med "Schablonintäkten på periodiseringsfonderna saknas (inkomstskattelagen 30 kap. 6 a §). Fyll i fält 7654 under Inkomstdeklaration först." Efter ifyllt 7654 kan förslaget bekräftas. Året 2027P4 är dessutom en period vars fältkoder inte är kontrollerade (se SKA).
  - Delår: räkenskapsår 2026-01-01 till 2026-09-30 (9 månader) med samma fond: schablonintäkt = (8 000 000 x 255 x 9 + 60 000) / 120 000 = 153 000,5 öre, nedåt 153 000 öre = 1 530,00 kr. Obs: appen avrundar sedan öre till hela kronor halvt uppåt (Ink2::kronor), 1 530.
  - Konsulten verifierar: att fondens år läses ur BAS-kontot (siffran sist), att sex år räknas i företagets räkenskapsår om fondens år finns i NONOX och annars i kalenderår, att statslåneräntan 2,55 % (30 nov 2025) och minimiräntan 0,5 % stämmer (IL 30 kap. 6 a §; Skatteverket, Periodiseringsfond), och att schablonintäkten för delår ska korrigeras proportionellt (TODO i app/Domain/IncomeTax/TaxAllocationReserves.php rad 28).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-18 Förlustår och nollskatt
- Förutsättningar: Som BOK-13. Intäkt 100 000,00 kr, kostnad 180 000,00 kr: resultat före skatt -80 000,00 kr.
- Steg i appen: Skatteavsnittet, bekräfta; boka därefter årets resultat (BOK-20). Läs även **Bokföring → Inkomstdeklaration**.
- Förväntat resultat:
  - Raden visar "Skattemässigt underskott" -80 000, avrundad inkomst 0, skatt 0,00 kr. Bekräftelse sparar årets skatt som noll utan verifikation ("Ingen verifikation skapas; årets skatt sparas som noll").
  - Underskottet 80 000 kr kan nästa år föras in i fält 7763 (Outnyttjat underskott från föregående år) i inkomstdeklarationen.
  - Efter att skatten är sparad kan resultatet bokas: 2099 D 80 000,00; 8999 K 80 000,00 (förlust).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-19 Privatbostadsföretag, investmentföretag, ideell förening och manuellt belopp
- Förutsättningar: Tre testföretag: (a) aktiebolag där frågan besvaras "Ja, privatbostadsföretag", (b) "Ja, investmentföretag", (c) ideell förening. Resultat före skatt 200 000,00 kr.
- Steg i appen: Besvara frågan i skatteavsnittet. Läs röd ruta. Öppna "Bokför ett eget belopp i stället", skriv **Inkomstskatt, kr** och klicka **Bokför skatten**.
- Förväntat resultat:
  - (a) och (b): inget förslag och inga fält för periodiseringsfond; texter med inkomstskattelagen 39 kap. 25 § respektive 39 kap. 14 §. Bekräftelse nekas.
  - Eget belopp: 8910 D belopp; 2512 K belopp. Negativt belopp: "Skatten kan inte vara negativt."; belopp 0 sparar noll utan verifikation. Skatt redan bokförd eller resultat redan bokat: "Årets skatt är redan bokförd ..." respektive "Årets resultat är redan bokat. Bokför skatten före resultatet."
  - Ideell förening: ingen automatisk beräkning, bara manuellt belopp.
  - Konsulten verifierar: att eget belopp för AB/ekonomisk förening är tillåtet bredvid förslaget (det går att kringgå förslaget; NONOX kontrollerar inte beloppet mot INK2S), att ideell förening/stiftelse hanteras med eget belopp (manualen: inte juridiskt kontrollerat), och att kooperativ avdragsrätt för ekonomisk förening inte hanteras (ADR-056).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-20 Enskild firma, handelsbolag och kommanditbolag: ingen skatt i bolaget
- Förutsättningar: En enskild firma (K1 eller K2) och ett handelsbolag, resultat 150 000,00 kr före, ingen skatt bokförd.
- Steg i appen: **Bokföring → Bokslut**. Läs avsnittet för skatt. Boka årets resultat. Stäng året och boka därefter **Resultatdisposition** (steg 4) med datum i nya året.
- Förväntat resultat:
  - Skatteavsnittet säger att ägarna beskattas; ingen skattepunkt i checklistan och ingen verifikation. Boka årets resultat blir tillåtet utan skatt.
  - Resultatet bokas på 8999 D 150 000,00 / 2019 K 150 000,00 (ResultNumber 2019 för EF/HB/KB). Disposition: 2019 D 150 000,00, 2010 K 150 000,00. Ingen utdelning och inget utdelningsfält.
  - Konsulten verifierar: BAS-konton 2019 och 2010 för EF, HB och KB, där eget kapital för delägare i handelsbolag normalt förs per delägare (TODO i app/Domain/Closing/ClosingAccounts.php rad 15 och i app/Actions/Accounting/CarryForwardBalances.php rad 170).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-21 Boka årets resultat
- Förutsättningar: Som BOK-13: intäkt 600 000,00, kostnad 100 000,00, skatt 103 000,00 bokförd (BOK-13).
- Steg i appen:
  1. Sidan visar **Kvar att boka**. Klicka **Boka årets resultat** och bekräfta.
  2. Bokför därefter en ny kostnad 10 000,00 kr på 5010 med datum 2026-12-15 (året är öppet). Läs **Kvar att boka** igen och boka.
  3. Prova att boka resultatet innan skatten är bokförd (kopia).
- Förväntat resultat (app/Domain/Closing/YearResult.php):
  - Resultat före skatt 500 000, skatt 103 000, efter skatt 397 000 vinst. Verifikation serie A 2026-12-31: 8999 D 397 000,00; 2099 K 397 000,00. Efter bokning är **Årets resultat är bokfört** klar och resultaträkningen går jämnt ut.
  - Efterföljande kostnad 10 000: kvar att boka +10 000: 8999 K 10 000,00 och 2099 D 10 000,00 (differensverifikation). Slutligt 2099 K 387 000,00.
  - Skatten måste vara bokförd först (för bolagsformer som betalar egen skatt): "Bokför årets skatt först, eller noll om det inte blir någon skatt." Redan bokat och inget nytt: "Årets resultat är redan bokat."
  - Konsulten verifierar: att 8999 Årets resultat mot 2099 är rätt BAS-hantering och att differens-bokning vid senare transaktioner är godtagbar (ClosingAccounts docblock).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-22 Stänga året, lås och ingående balanser
- Förutsättningar: K2-aktiebolag med färdigt bokslut enligt BOK-13 och BOK-21. Räkenskapsår 2025 finns och är stängt, 2026 öppet.
- Steg i appen:
  1. Försök stänga 2026 med en obockad checklistepunkt (knappen **Stäng räkenskapsåret** ska vara avstängd, text "Bocka av hela checklistan först.").
  2. Som Bokförare: försök stänga. Som Redovisningskonsult men med 2025 öppet: försök stänga 2026.
  3. Klara checklistan och klicka **Stäng räkenskapsåret** och bekräfta.
  4. Gå till **Bokföring → Räkenskapsår**. Jämför ingående balanser 2027 med utgående 2026.
  5. Försök bokföra en verifikation daterad 2026-12-15.
- Förväntat resultat (YearEndClosing::close, CarryForwardBalances):
  - Spärrar: "Checklistan är inte klar: <punkter>", "Stäng tidigare räkenskapsår först.", Bokförare nekas (kräver `periods:lock` och `fiscal-years:manage`).
  - När året stängs: status Stängt med datum och person; balanser för alla balanskonton blir ingående balanser i 2027 (skapas om saknas). Resultatkonton (3000-8999) förs inte över: årets resultat står på 2099 (397 000 kredit i exemplet). Summan av ingående balanser är noll (debet = kredit).
  - Låset sätts till och med 2026-12-31 (`locked_until_date`). Bokföring 2026-12-15 nekas: "Räkenskapsåret ... är stängt. Det går inte att bokföra i det." Rättelse sker i det nya året.
  - Audit: `fiscal_year.closed` med etikett och nästa år.
  - Konsulten verifierar: att ingående balans stämmer med föregående utgående balans (ÅRL 2 kap. 4 § första stycket 7) och att låsning vid stängning inte hindrar rättelse i det nya året, samt att ingående balanser får föras över även när nästa års första period är låst (TODO i app/Actions/Accounting/CarryForwardBalances.php rad 44).
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-23 Ingående balanser som inte stämmer med förra året
- Förutsättningar: Två räkenskapsår 2025 (öppet) och 2026 med föroverförda balanser. Bokför en sen verifikation i 2025 efter att balanserna förts över.
- Steg i appen: **Bokföring → Bokslut**, år 2026, punkten **Ingående balanser stämmer med förra året**. Pröva **Skillnaden är avsiktlig** utan skäl, med skäl över 1 000 tecken och med giltigt skäl. Ändra sedan skillnaden och läs om punkten. Uppdatera de ingående balanserna under **Bokföring → Räkenskapsår**.
- Förväntat resultat:
  - Punkten står som att göra: ":count konto skiljer från de utgående balanserna för 2025. Uppdatera de ingående balanserna under Räkenskapsår."
  - Utan skäl: "Skriv skälet till att skillnaden är avsiktlig." Över 1 000 tecken: "Skälet får vara högst 1 000 tecken." Utan skillnad: "De ingående balanserna stämmer redan med förra årets utgående balanser."
  - Med skäl blir punkten klar med texten att skälet och en bedömning ska lämnas i en not (ÅRL 5 kap. 5 §). Ändras skillnaden därefter gäller bekräftelsen inte längre ("har ändrats sedan den bekräftades som avsiktlig").
  - Konsulten verifierar: ÅRL 2 kap. 4 § första och andra stycket (särskilda skäl) och att NONOX kräver notupplysning.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

### BOK-24 Resultatdisposition och utdelningsspärr
- Förutsättningar: Aktiebolaget från BOK-21/BOK-22: aktiekapital 25 000,00 kr (2081), balanserat resultat 0, årets resultat 397 000,00 kr på 2099, år 2026 stängt, 2027 finns.
- Steg i appen:
  1. **Bokföring → Bokslut**, välj det stängda året 2026. Steg **4. Resultatdisposition**.
  2. Datum för årsstämman 2027-05-20, **Utdelning, kr** 100 000, **Bokför resultatdispositionen**.
  3. Kopia: utdelning 397 001; därefter exakt 397 000.
  4. Kopia: förlustår: ingående balanserat resultat 2098 kredit 100 000,00 kr och årets förlust 30 000,00 kr på 2099 (debet), utdelning 70 001 och därefter 70 000.
  5. Försök disponera samma år en andra gång. Försök med datum utanför 2027.
  6. Enskild firma: fältet för utdelning ska saknas.
- Förväntat resultat (YearEndClosing::dispose, DividendLimit):
  - Vinstår, utdelning 100 000: 2099 D 397 000,00; 2098 K 297 000,00 (397 000 - 100 000); 2898 K 100 000,00. Debet 397 000 = kredit 397 000. Datum i 2027.
  - Fritt eget kapital = balanserat resultat 0 + årets resultat 397 000 = 397 000. Utdelning 397 001: avvisas med "Utdelningen ... är större än det fria egna kapitalet ... Aktiebolagslagen 17 kap. 3 § tillåter inte ...". 397 000 godtas (då 2098 = 0 och 2898 K 397 000).
  - Förlustår: fritt eget kapital = 100 000 - 30 000 = 70 000. 70 001 avvisas, 70 000 godtas: 2099 K 30 000,00; 2098 D 100 000,00 (förlusten 30 000 + utdelningen 70 000); 2898 K 70 000,00. Debet 100 000 = kredit 100 000.
  - Utan fritt eget kapital: "Det finns inget fritt eget kapital att dela ut."
  - Andra gången: "Resultatet för 2026 är redan disponerat." Fel år: "Datumet ska ligga i räkenskapsåret 2027." Öppet år: "Stäng räkenskapsåret innan resultatet disponeras." Negativ utdelning: "Utdelningen kan inte vara negativ." Inget resultat på 2099: "Det finns inget resultat att disponera på konto 2099."
  - Ekonomisk förening använder 12 kap. 3 § lagen om ekonomiska föreningar i meddelandet.
  - Konsulten verifierar: spärren mot det fria egna kapitalet (ABL 17 kap. 3 § första stycket; ekonomisk förening 12 kap. 3 §), att förändringar i bundet kapital efter balansdagen och redan gjorda värdeöverföringar (17 kap. 4 §) inte räknas (TODO i app/Domain/Closing/DividendLimit.php rad 27), och att försiktighetsregeln (17 kap. 3 § andra stycket) överlämnas till styrelsen och stämman.
- Resultat: [ ] OK  [ ] Avvikelse   Kommentar: ______

## Öppna TODO(legal-verify) i koden

Sökning: `grep -rn "TODO(legal-verify)" app/Domain/Closing app/Actions/Closing app/Actions/Assets app/Domain/Assets app/Domain/Accruals app/Domain/Company/ReportingFrameworkRules.php app/Actions/Accounting/CarryForwardBalances.php` (inga träffar i app/Actions/Accruals). Regeldata i database/data/corporate-tax/rules.json har status `researched` för samtliga regler och satser (inget är `verified`); de räknas som en gemensam rad nr 18.

| nr | fil:rad | frågan (sammanfattning) | Konsultens beslut (Godkänd / Ändra / Avslag) | Kommentar |
|---|---|---|---|---|
| 1 | app/Domain/Closing/K2Reconfirmation.php:18 | Räcker en bekräftelse av K2-villkoren per företag, eller ska den göras per räkenskapsår? (Följer ägarbeslut 16.) | | |
| 2 | app/Domain/Closing/CurrencyRevaluation.php:39 | Får Riksbankens mittkurs användas som balansdagskurs (banken köpkurs för fordringar och säljkurs för skulder är normalfallet), och är återföring första dagen i nästa år rätt metod? | | |
| 3 | app/Domain/Closing/ClosingAccounts.php:15 | Är kontona per bolagsform rätt, särskilt för HB/KB där eget kapital förs per delägare, och är 2512 och 2898 rätt konton för beräknad skatt och utdelning? | | |
| 4 | app/Domain/Closing/TaxProposal.php:33 | Fondkontots år (BAS 2120-2139) som kalenderåret då avdragsåret slutar, sex beskattningsår räknat i räkenskapsår, högsta avsättning avrundad nedåt till hela kronor och skatt bokförd i öre. | | |
| 5 | app/Domain/Closing/TaxProposal.php:195 | Hur sex beskattningsår räknas när ett år var kortare eller längre än tolv månader (hänvisar till rad 33). | | |
| 6 | app/Domain/Closing/DividendLimit.php:27 | Förändringar i bundet eget kapital efter balansdagen och redan gjorda värdeöverföringar räknas inte; försiktighetsregeln kan inte beräknas; samma spärr för ideella föreningar och stiftelser utan lagstöd. | | |
| 7 | app/Domain/Closing/UnpaidInvoices.php:32 | Återföring första dagen i nästa år som metod, och vilken momsperiod momsen på obetalda fakturor ska redovisas i (Mervärdesskattelag 2023:200, 7 kap.). | | |
| 8 | app/Actions/Closing/YearEndClosing.php:120 | Skatten för aktiebolag i det manuella steget räknas av användaren från skattemässigt resultat (INK2) och skiljer sig från bokfört resultat. | | |
| 9 | app/Actions/Assets/FixedAssets.php:80 | Inventarier av mindre värde får kostnadsföras direkt (IL 18 kap. 4 §, halvt prisbasbelopp exkl. moms, sambruk); NONOX föreslår ingen gräns. | | |
| 10 | app/Actions/Assets/FixedAssets.php:284 | Moms vid försäljning av inventarie och om avyttringsmånaden ska skrivas av lämnas till användaren. | | |
| 11 | app/Domain/Assets/DepreciationPlan.php:17 | Skattemässig avskrivning av inventarier (räkenskapsenlig avskrivning, 30- och 20-regeln) och överavskrivning på 2150/8850 hanteras inte; registret följer bara planen. | | |
| 12 | app/Actions/Accounting/CarryForwardBalances.php:44 | Överföring av ingående balanser när nästa års första period är låst: nekas utanför stängningen, tillåts som del av stängningen (konstruktionsval). | | |
| 13 | app/Actions/Accounting/CarryForwardBalances.php:170 | Rätt BAS-resultatkonto per bolagsform, särskilt HB/KB. | | |
| 14 | app/Domain/Company/ReportingFrameworkRules.php:12 | Hela K-regelverksmatrisen (K1/K2/K3 per bolagsform). | | |
| 15 | app/Domain/Company/ReportingFrameworkRules.php:36 | Får handelsbolag/kommanditbolag med enbart fysiska personer som delägare använda K1? Vägras tills vidare. | | |
| 16 | app/Domain/Company/ReportingFrameworkRules.php:60 | Klarspråkssammanfattningen av 1.1A-1.1C; vilka uteslutningar som gäller HB, KB, stiftelse och ideell förening under K2; om BFNAR 2017:3 ändrats på samma sätt. | | |
| 17 | app/Domain/Company/ReportingFrameworkRules.php:65 | Lydelsen av punkt 1.1 för räkenskapsår som börjar före 2026-01-01 (äldre text, ej läst); formuläret känner inte räkenskapsåret. | | |
| 18 | database/data/corporate-tax/rules.json | Satserna och reglerna (bolagsskatt 20,6 % och 21,4 % för 2019-2020, avrundning till tiotal, fondandel 25 %, sex år, BAS-konton, justeringskonton 6072/6982/6992/8423 och 8314) har status `researched`, inte juridiskt kontrollerade. | | |

Andra påpekanden som kommer från ADR-056 (fylls i av konsulten): att tidigare satser än 2019 saknas i datat; att ekonomiska föreningars särregler (kooperativ avdragsrätt, IL 39 kap.) inte hanteras; att svaret om privatbostadsföretag och investmentföretag är användarens eget.

## Avvikelser

| nr | fall | beskrivning | allvarlighet | åtgärd |
|---|---|---|---|---|
| | | | | |
| | | | | |
| | | | | |

## Godkännande

Modulen Bokslut är granskad och godkänd för att tas ur Beta.

| | |
|---|---|
| Namn | ______________________ |
| Titel/auktorisation | ______________________ |
| Datum | ______________________ |
| NONOX-version | ______________________ |
| Underskrift | ______________________ |

[ ] Godkänd   [ ] Godkänd med villkor   [ ] Ej godkänd

Villkor/anmärkningar: ________________________________________________

Godkännandet gäller bara modulen Bokslut och inte andra moduler.
