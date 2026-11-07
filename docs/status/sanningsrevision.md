# Sanningsrevision av de publika sidorna

Datum: 2026-11-06. Gäller den driftade tjänsten nonox.app och det som sägs om den på
startsidan, branschsidorna, sidorna "Alternativ till", README, jämförelsen
(`docs/migration/jamforelse.json`), färdplanen (`docs/fardplan/fardplan.json`) och manualen
(`docs/manual/sv`).

Utgångspunkt från driften: nonox.app har ingen leverantör av öppen bankdata
(`NONOX_BANKING_DRIVER` är tom) och ingen Peppol-operatör. Att lämna momsdeklarationen
direkt till Skatteverket är byggt (ADR-054 steg 1), men det används bara mot Skatteverkets
testtjänst och bara av de pilotanvändare som står i `SKATTEVERKET_PILOT_USERS`.

## Avvikelser och vad som gjordes

| # | Var | Avvikelse | Åtgärd |
|---|---|---|---|
| 1 | `app/Support/Landing/Features.php` | `bank_feed` ("Bankkoppling med BankID") stod som i drift, fast den inte går att slå på i den driftade tjänsten. | Ändrad till `SOON` med en kodkommentar: byggd (ADR-034) och vilande tills den som driftar har ett avtal med en leverantör av öppen bankdata. Orden "bankkoppling" och "kopplas med BankID" är nu förbjudna i påståenden på branschsidorna så länge funktionen är på väg (samma regel som för Peppol och kassakopplingar). Även direktinlämningen till Skatteverket har fått förbjudna ord. |
| 2 | README, avsnittet Bank (modul) | Beskrev bankkopplingen som en funktion i drift. | Rubriken säger nu "byggd, inte i drift på nonox.app" och en inledning förklarar varför. En rad under "På väg" säger att den slås på när avtalet finns. |
| 3 | README, "På väg" | Direktinlämningen till Skatteverket nämnde inte att den provas av pilotanvändare. | Texten säger nu att den provas i Skatteverkets testtjänst av ett fåtal pilotanvändare och att filen gäller till dess. |
| 4 | `jamforelse.json`, raden Bankkoppling | Raden var knuten till `bank_feed` som om funktionen var i drift. NONOX-värdet "egen drift" var redan ärligt. | Raden har inga funktionsnycklar längre, så den räknas inte som en funktion i drift. Värdet "egen drift" står kvar. |
| 5 | `fardplan.json`, Direktkoppling till Skatteverket | Stod som Planerat, fast momsdeklarationen är byggd och testas. | Status Pågår, med beskrivningen att steg 1 är byggt och provas av pilotanvändare i testtjänsten och att filen gäller till dess. |
| 6 | `fardplan.json`, Bankkoppling i drift | Sade bara "när de juridiska frågorna är besvarade", inte att det saknas avtal. | Säger nu att det saknas avtal med en leverantör av öppen bankdata och att du läser in bankens filer till dess. |
| 7 | `fardplan.json`, NE-bilaga och INK4 | Kontrollerat: de fanns inte på färdplanen. Färdplanen har ingen kolumn för det som är klart. | Ingen ändring. Leveransen står i ändringsloggen (2026-11-05). |
| 8 | Manualen: `installningar/moduler.md`, `installningar/bankmodulen.md`, `bank/bankkoppling.md`, `bank/bankfiler.md`, `fakturering/inbetalningar.md` | Modultabellen och bankmodulens sida sade inte att modulen saknas på nonox.app. Bankfiler och inbetalningar hänvisade till bankkopplingen som ett alternativ utan förbehåll. | En mening eller en notis i varje artikel säger att modulen inte erbjuds på nonox.app än och hänvisar till bankens filer. `bank/oversikt.md` och `bank/bankkoppling.md` hade redan förbehållet, och det skärptes i bankkoppling.md. |
| 9 | Manualen: `moms/momsdeklaration.md`, "Lämna deklarationen direkt till Skatteverket" | Beskrev direktinlämningen utan att säga att den inte är påslagen på nonox.app. | En notis säger att kopplingen inte är påslagen på nonox.app, att den provas av pilotanvändare i testtjänsten och att du laddar ner filen till dess. |
| 10 | Startsidan, förtroenderaden | "Läser Peppol-fakturor" kunde läsas som att e-faktura via Peppol fungerar, men den funktionen är på väg. Det som fungerar är att inkorgen läser Peppol-filer som kommer med e-post. | Ändrad till "Läser Peppol-fakturor i inkorgen". |
| 11 | Sidorna "Alternativ till …" (`AlternativeController`) | Den första frågan sade alltid att NONOX skiljer sig genom öppen källkod, att allt ingår i priset och att det går att köra själv. Det stämmer inte mot Accounted, som är öppen källkod (AGPL-3.0-or-later) och går att köra själv. | Svaret byggs nu ur raderna "Öppen källkod", "Alla moduler ingår i priset" och "Kör på egen server" i jämförelsen. En skillnad nämns bara där den andra leverantören inte har värdet "Ingår". |
| 12 | `jamforelse.json`, Björn Lundén | Priset var markerat som ej verifierat och byggde på en jämförelsesajt. | Kontrollerat mot bjornlunden.com/se/priser/ 2026-11-06: Total 349 kr/mån och Lundify Payroll från 39 kr per lönebesked, moms tillkommer. Källan är bytt och priset är verifierat. Priset blev detsamma. |
| 13 | `jamforelse.json`, Flera bolag under en inloggning | Alla leverantörer stod som "Anges ej". | Fortnox: tillval (Bolagshanteraren, pris på förfrågan). Bokio: ingår (flera företag under samma konto, en prisplan per företag). Spiris, Björn Lundén, Kleer och Accounted står kvar som "Anges ej". Källorna finns i `jamforelse-funktioner.md`. |
| 14 | `jamforelse.json`, ny leverantör | Accounted fanns inte med. | Tillagd med planen Auto, 199 kr/mån vid årsbetalning, som är 2 388 kr/år. Värdena kommer bara från Accounteds egna sidor och kodförråd, och "Anges ej" står där de inte säger något. Prisnoten säger att gratisplanen Open finns. Alla citat och källadresser finns i `jamforelse-funktioner.md`. Delningsbilden `public/og/alternativ-till-accounted.png` är ritad. |
| 15 | ADR-053 | Status var "Proposed", fast NE-bilagan och INK4 är sammanfogade (b3ddab0b) efter kodgranskning (19cf170c) och skattegranskning (c75122d5). | Status ändrad till Accepted. ADR:en säger uttryckligen att `TODO(legal-verify)`-punkterna är öppna och att inget är juridiskt verifierat. |
| 16 | Påståenden om att NONOX lämnar deklarationer direkt som konkurrenterna | Ingen sådan mening hittades på startsidan, branschsidorna, sidorna "Alternativ till", README eller i jämförelsen. Branschsidorna säger "fil till Skatteverket". Jämförelsen har ingen rad för direktinlämning. | Ingen mening behövde skrivas om. Accounted anger att momsdeklarationen "lämnas in med BankID". Därför har ingen rad lagts till som påstår att NONOX gör samma sak (se `jamforelse-funktioner.md`). |

## Kontrollerat utan avvikelse

- Peppol står redan som på väg överallt: i Features, README, färdplanen och manualen.
- Branschsidorna säger "laddas ner som fil till Skatteverket" om momsen. Personalliggaren
  "skickas inte direkt till Skatteverket ännu".
- Startsidans not "Kör du själv betalar du tjänster från andra, som bankkoppling, direkt
  till dem" stämmer.
- Startsidans kort Bank gäller bankavstämning och bankens filer, inte bankkopplingen.
- `bank/oversikt.md` och `bank/bankkoppling.md` sade redan att modulen inte erbjuds på nonox.app.

## Inte verifierat

- Raden Bankkoppling för Björn Lundén ("Ingår i Total") bygger på produktsidan.
  Prissidan listar "Bank & betalning" 49 kr/mån bland tilläggstjänsterna utan att säga vilka
  paket tillägget gäller. Värdet står kvar och kontrolleras vid nästa genomgång.
- Accounteds värde för "Alla moduler ingår i priset" bygger på leverantörens egen mening
  "Ett pris per företag. Allt ingår." Prissidan har styckpriser över vissa gränser, till
  exempel för fler bankkonton, betalningar och e-fakturor.
- Att pilotanvändare faktiskt använder Skatteverkets testtjänst på nonox.app har inte
  kontrollerats mot servern. Texten bygger på koden (`SKATTEVERKET_PILOT_USERS`) och ADR-054.
- Ingen leverantörssida har lästs i en riktig webbläsare. Sidorna lästes med en automatisk
  hämtning som sammanfattar dem, så citaten bör stämmas av mot sidan innan de används i
  marknadsföring.

## Oberoende granskning 2026-11-06 och rättelser

En granskare som inte gjort ändringarna läste dem ur marknadsrättslig synvinkel, som faktagranskare och som utvecklare. Fyra fynd bekräftades, och alla är rättade:

1. Raden "Alla moduler ingår i priset" visade "Ingår" för Accounted, men deras egen prissida anger gränser och styckpriser. Den står nu som "Anges ej", och skälet finns i `jamforelse-funktioner.md`.
2. Uppgiften om lön för Accounted citerade sidan för byråer. Den citerar nu prissidan, och prisnoten säger inte längre "utan avgift per lönebesked".
3. Citatet om BankID var fel. Nu används den exakta meningen från Accounteds vanliga frågor, och påståendet "lämnas in med BankID" är borttaget.
4. Texten om vad som skiljer NONOX från en annan leverantör räknade "Anges ej" som en skillnad. Nu räknas bara "Finns inte" som en skillnad. Meningen "Precis som …" visas bara när leverantören har både öppen källkod och egen drift.

Testet för alternativsidorna kontrollerar nu varje leverantörs märkning "ej verifierat" mot uppgifterna. Tidigare förutsatte det att Björn Lundéns pris var overifierat, men det priset är nu verifierat.

---

## Revision 2, 2026-11-06 (efter kvällens paket i nummer-1.md)

Alla paket i `docs/status/nummer-1.md` jämfördes med påståendena "Public claim now allowed" och "still not allowed" på startsidan, branschsidorna, sidorna "Alternativ till", README, jämförelsen, färdplanen och manualen.

| # | Var | Fynd | Åtgärd |
|---|---|---|---|
| 1 | `docs/branscher/bokforing-for-konsulter.json`, `for-redovisningsbyraer.json` | Båda sade att bokslutet "inte räknar fram skatten". Sedan D1 (ADR-056) föreslår bokslutet årets skatt för aktiebolag (beta). Byråsidan nämnde inte heller att årsredovisningen kan laddas ner som iXBRL-fil (beta, E.2). | Texten säger nu att skatten föreslås för aktiebolag (beta), att en person granskar och bekräftar, och att förslaget inte är granskat av en redovisningskonsult. Byråsidan säger att iXBRL-filen finns men att digital inlämning inte är byggd. |
| 2 | `docs/manual/sv/bokslut/oversikt.md` | Noten "NONOX räknar inte fram skatten" var föråldrad. | Skriven om till förslaget (beta) som du bekräftar, utan påstående om granskning. |
| 3 | `docs/manual/sv/bokslut/bokslutsverifikationer.md` | Förslaget till skatt sade inte att det är ogranskat av en konsult eller att föreningarnas särregler saknas ("still not allowed" i D1). | En mening tillagd. |
| 4 | `README.md`, Bokslut | Funktionslistan nämnde varken skattförslaget, iXBRL-nedladdningen eller att K3 saknas. | En mening för vart och ett, med förbehållen från nummer-1.md. Strängen som `IndustryPagesTest` letar efter är oförändrad. |
| 5 | `README.md`, "På väg" | Sade "Därefter arbetsgivardeklarationen, skattekontot och Skatteverkets meddelanden", som om de kom senare. De är byggda (B2, B4, B3) men bakom pilotspärren. | Texten säger att de är byggda mot testtjänsten, provas av pilotanvändare och inte är påslagna för vanliga användare. |
| 6 | `docs/fardplan/fardplan.json` | Kommentaren på "Direktkoppling till Skatteverket" sade att AGI, skattekonto och meddelanden kommer efter momsen. Färdplanen saknade K3, koncernredovisning och övertidsarter i fler avtal. | Kommentaren rättad. Tre punkter tillagda: Årsredovisning enligt K3 (Planerat), Koncernredovisning (Önskemål, "inte bestämt"), Övertidsarter i fler kollektivavtal (Planerat). Det som levererats i kväll (importmoms, skatt i bokslutet, iXBRL-nedladdning, koncernöversikt, övertidsarter) visas under "Klart nyligen" ur ändringsloggen, som har poster för alla. |
| 7 | `resources/views/start/partials/comparison.blade.php` | Raden "Bokslut och årsredovisning" visar "Ingår" för NONOX utan att säga att bara K2 stöds. | Fotnoten under jämförelsen säger att NONOX bara tar fram K2, inte K3. Radens namn är oförändrat eftersom branschsidorna refererar till det. |

Kontrollerat utan avvikelse:

- Inget på de publika sidorna påstår direktinlämning till Skatteverket som vanlig funktion, inlämning till Bolagsverket, bank eller Peppol som live, K3-årsredovisning, koncernredovisning eller kompletta kollektivavtal. `Features.php` har `skatteverket_api`, `ixbrl`, `bank_feed`, `peppol` som `SOON`, och förbjudna ord finns för dem.
- Manualen (momsdeklaration, AGI, skattekontot, Mina datum) säger redan att kopplingarna inte är påslagna på nonox.app och att de provas av pilotanvändare. Manualen om K3 och koncernöversikt har rätt förbehåll.
- Alla funktioner som står som `LIVE` i `Features.php` är nåbara för en vanlig kund på nonox.app. Det enda som är bakom `SKATTEVERKET_PILOT_USERS` är Skatteverkets API-kopplingar, och de står som `SOON`. Bank och Peppol är `SOON`. Modulerna slås på per företag.

Öppet, ändrades inte:

- Branschsidorna (butiker, restauranger, caféer) säger "övertidstillägg för timanställda räknas inte ännu" i Planering, medan `det-har-gor-avtal-inte-annu.md` säger att Planering räknar Gröna Riks och Detaljhandelns tillägg för timanställda. `docs/branscher/bokforing-for-butiker.json` har ändrats av en annan agent och branschsidorna för restauranger och caféer har samma mening, så de bör avstämmas mot koden av den som äger övertidsarbetet.
- Färdplanens punkt "Bilförmån räknas ut automatiskt" rördes inte, eftersom en annan agent arbetar med bilförmånen.
- Att Fortnox-flytten via API ("fortnox_import", LIVE) fungerar mot riktiga Fortnox-konton i den driftade tjänsten (registrerad integration) har inte kontrollerats.
- Testkörning med `DB_DATABASE=nonox_test_truth2`: LandingPageTest, IndustryPagesTest, Setup/StartPageTest, ManualTest, ChangelogTest, TranslationsTest, Waitlist/PublicSignupTest och ShareImagesTest, 161 gröna.

---

## Granskning 2026-11-07

Spår A, sanningsrevisorn. Utgångspunkt: grenen `spec/track-a/2026-11-07` (HEAD `74089da4`, som innehåller `7ce7f858`, driftsatt 2026-11-06). Granskat: startsidan och dess delar (`resources/views/start/**`), `LandingPage.php`, `Features.php`, branschsidorna (`docs/branscher`), sidorna "Alternativ till" (`AlternativeController`), jämförelsen, README:s funktionslista, färdplanen, ändringsloggen för 2026-11-06 och manualsidorna som ändrades i `40e6a725`. Underlaget för statusen finns i `docs/status/statuskontroll-2026-11-07.md`.

Ingen publik fil har ändrats. Förslagen till ersättningstext är till Lead. Testerna jag körde (se statuskontrollen) var gröna utom 69 som föll på att `public/build` saknas i arbetskopian; GitHub Actions är grönt på `main` för `74089da4`.

### Fel

| # | Var | Vad den säger | Verdikt | Bevis | Förslag på ersättningstext |
|---|---|---|---|---|---|
| 1 | `LandingPage.php:211` (kortet "Deklaration för enskild firma och handelsbolag"), `Features.php` `ne_return` (LIVE), `LandingPage::INCLUDED` ("Deklaration för aktiebolag, enskild firma och handelsbolag"), `README.md:88`, `jamforelse.json` raden "NE-bilaga för enskild firma (SRU)" (NONOX "Ingår"), `docs/branscher/for-redovisningsbyraer.json:104` och `for-ekonomikonsulter.json:91`, ändringsloggen 2026-11-05 ("Enskild firma får NE-bilagan ... som SRU-fil"), manualen `bokslut/inkomstdeklaration.md` | NE-bilagan med räntefördelning, periodiseringsfond, expansionsfond och schablonavdrag räknas fram och kan laddas ner som SRU-fil. | **fel** | `database/data/income-tax/2026.json` har `verified: false`, och det finns ingen fil för något annat år utom `2027.json` (också overifierad). `IncomeTaxParameters::for()` ger då `null`, och `BusinessIncomeTaxReturns.php:233` vägrar skapa NE-filen. `NeTest`: "keeps 2026 blocked in production until a human has checked its parameters". INK4 påverkas inte av spärren. Spärren står i inget publikt dokument. | Kort: "NE-bilagan för enskild firma tas fram ur bokföringen, men SRU-filen och räkningen av räntefördelning, periodiseringsfond, expansionsfond och schablonavdrag öppnas först när en människa har kontrollerat årets satser; till dess fyller du i bilagan i Skatteverkets e-tjänst. Inkomstdeklaration 4 för handelsbolag laddas ner som SRU-filer." Sätt `ne_return` till `SOON` eller ta bort NE ur `INCLUDED`, ändra jämförelseraden till "Anges ej", lägg en post av typen Rättat i ändringsloggen och en notis i manualens avsnitt "Enskild firma". |
| 2 | Ändringsloggen `2026-11-06-ai-assistenter-via-mcp`; manualen `installningar/nonox-connect.md` (rad 60–69, 112); `Guides.php:56` (utvecklarguiden `ai-assistenter`); `installningar/moduler.md:77`; `landing.blade.php:343`; `docs/juridik/villkor.md:61` | En AI-assistent kan kopplas till NONOX med MCP, "NONOX Connect har nu en MCP-server". | **fel** (mot ägarbeslut 18 och integritetspolicyn) | Beslut 18: MCP släpps först när juristens text om AI-leverantörer finns i policyn; "Blockerar: publika påståenden om AI-assistenter". Policyn (`integritetspolicy.md:66`) säger "den är inte tillgänglig ännu" och har en öppen `TODO(legal-verify)`. Slutpunkten `api/mcp` är registrerad utan spärr (`ConnectServiceProvider.php:187`). Ändringsloggen använder dessutom det gamla namnet "NONOX Connect". | Antingen spärra slutpunkten och dra tillbaka påståendena, eller vänta med att publicera. Ändringsloggen, om den står kvar: "NONOX API får en MCP-server i beta, så att en AI-assistent kan läsa och skapa utkast. Funktionen öppnas när integritetspolicyn har uppdaterats." Lead avgör om MCP ska bort ur landningssidan, manualen och villkoren till dess. |
| 3 | `fardplan.json`, punkten `betalfiler-valuta` (Planerat) | "Betala leverantörsfakturor i andra valutor med samma betalfil som i dag." | **fel** (understater) | D5 levererat: `ForeignPaymentFilesTest` 60 gröna, ändringsloggen 2026-11-06. | "Betalfiler i utländsk valuta för fler banker. Handelsbanken och Nordea finns; övriga banker betalar du i internetbanken." Eller ta bort punkten. |
| 4 | `fardplan.json`, punkten `bilforman` (Planerat) | "Ange bilens nybilspris ... så räknar NONOX ut förmånsvärdet." | **fel** (understater) | D4 levererat i beta: `CarBenefitTest` 21, `lon/formansbilar.md`, ändringsloggen. | Ta bort punkten, eller: "Bilförmån för bilar som blev skattepliktiga före 1 juli 2021, och drivmedelsförmån som räknas automatiskt." |
| 5 | `landing.blade.php:46` ("Inga tilläggsmoduler att köpa, vare sig du kör själv eller låter oss sköta driften.") | Inget extra att köpa. | **fel** (utelämnar kostnadsnotisen) | Ägarbeslut 8 och villkoren avsnitt 5: NONOX Connect kostar extra (leverantörens pris + 10 %, från 2027-01-11). Marknadsföringslagen 10 §. Prisavsnittet (rad 341, 343) har notisen, heroavsnittet inte. | Lägg till: "NONOX Connect (Peppol, Skatteverket, bank) är ett tillägg som kostar extra: leverantörens pris + 10 %, från 2027-01-11." |
| 6 | `AlternativeController.php:30` ("... 995 kr om året ..., alla delar inräknade") och `:59` ("att alla delar ingår i priset") | Alla delar ingår. | **fel** (utelämnar notisen) | Samma grund. Notisen finns bara i en liten rad på `alternative.blade.php:53`. | Rad 30: lägg till "NONOX Connect (Peppol, Skatteverket, bank) är ett tillägg som kostar extra: leverantörens pris + 10 %, från 2027-01-11." Rad 59: "att alla moduler ingår i priset (tillägget NONOX Connect kostar extra)". |
| 7 | `developers/home.blade.php:32` och den engelska raden ovanför ("alla moduler med i priset", "Gratis, utan tilläggsavgift") | Alla moduler i priset, ingen tilläggsavgift. | **fel** (utelämnar notisen) | Samma grund. | Lägg till: "NONOX Connect (Peppol, Skatteverket, bank) är ett separat tillägg som kostar extra. API:t, webhooks och MCP ingår i modulen NONOX API." samt en engelsk motsvarighet. |
| 8 | `docs/branscher/bokforing-for-konsulter.json:28` ("Allt nedan finns i NONOX i dag, och allt ingår i priset.") | Allt ingår. | **fel** (utelämnar notisen) | Övriga branschsidor har notisen i `industry.blade.php:76` och `:180`; ingressen här säger "allt ingår" utan den. | "Allt nedan finns i NONOX i dag, och modulerna ingår i priset; tillägget NONOX Connect kostar extra." |
| 9 | `docs/status/nummer-1.md`, tabellen "Statuskontroll 2026-11-06" | ADR-053 Proposed, AGI-API saknas, skattekonto/kundhändelser/iXBRL/koncern "inte byggda", I.1 väntar på beslut. | **fel** | `statuskontroll-2026-11-07.md` avsnitt 2 och 3. Filen är inte publik men styr vad som får sägas. | Se statuskontrollen. |

### Oklart, avgörs av Lead

| # | Var | Vad den säger | Verdikt | Bevis | Förslag |
|---|---|---|---|---|---|
| 10 | `landing.blade.php:343` | "från 2027-01-11 (kommer med version 1.0)" | **oklart** | Villkoren 1.6 gäller från 2027-01-11 men version 1.0 lanseras 2027-02-01 (beslut 9). Två datum i samma mening. | "Tillägg som kostar extra från 2027-01-11, när Peppol, Skatteverket och bank är påslagna." |
| 11 | `LandingPage.php` FAQ "Kan jag sluta när jag vill?" | "du exporterar all data med ett klick" | **oklart** | SIE4 ut och arkivpaket per räkenskapsår finns (`ArchivePackageBuilder`). En full företagsexport (spec F10) finns inte. | "Ja. Ingen bindningstid, och du exporterar bokföringen som SIE4 och arkivpaket per räkenskapsår." |
| 12 | `LandingPage.php` FAQ "Är NONOX verkligen gratis?" och `migration-guide.blade.php:125` | "Du betalar bara om du vill att vi sköter driften." | **oklart** | Utelämnar NONOX Connect för den som låter oss sköta driften. | Lägg till: "Peppol, Skatteverkets tjänster och bank kommer i tillägget NONOX Connect, leverantörens pris + 10 % från 2027-01-11." |
| 13 | `README.md:176` | "slås på när de juridiska frågorna är besvarade" | **oklart** | Rad 173, 200 och färdplanen säger att avtal saknas; rad 176 nämner det inte. | "slås på när det finns ett avtal med en leverantör av öppen bankdata." |
| 14 | `fardplan.json`, `juristgranskning` | "Nu granskar en jurist slutsatserna." | **oklart** | Inget i repot visar att en jurist granskar (beslut 6 är öppet). | Ta bort, eller "En jurist ska granska slutsatserna; det är inte påbörjat." |
| 15 | `fardplan.json`, `peppol-accesspunkt` | "kommer i version 1.0 genom Qvalia" | **oklart** | Nämner inte att Connect kostar extra. | Lägg till: "NONOX Connect, leverantörens pris + 10 %." |
| 16 | Ändringsloggen 2026-11-06 01:15 | Två poster med samma innehåll: `sanningsrevision-av-webbplatsen` och `bankkoppling-inte-i-drift`. | **oklart** | Ingen är fel. | Behåll en. |
| 17 | `docs/juridik/regelverk.md:5` | "Stafe Development AB ... driftar tjänsten på nonox.app" | **oklart** | Stämmer i dag; enligt beslut 19 (ändrat 2026-11-07) driver GrantPilot AB tjänsten från 2027-01-11. | Byt operatör i samma ändring som serverinställningen. |
| 18 | `Features.php` `fortnox_import` (LIVE) | Direkt flytt via Fortnox API | **oklart** | Inte kontrollerat mot ett riktigt Fortnox-konto. | Ingen ändring förrän det körts en gång. |

### Kontrollerat och i ordning

| Var | Påstående | Verdikt | Bevis |
|---|---|---|---|
| `landing.blade.php:341`, `:343`, `:416`, `industry.blade.php:76`, `:180`, `alternative.blade.php:53`, `migration-guide.blade.php:222`, FAQ "Är NONOX ett alternativ till Fortnox?" och "Vad skiljer NONOX ...", `moduler.md:70-77`, villkoren avsnitt 5 | Kostnadsnotisen för NONOX Connect står bredvid "Alla moduler ingår". Jämförelsetabellen visas alltid med en av dessa intill. | ok | Läst rad för rad. |
| Bank, Peppol, Skatteverket | Inget publikt ställe säger att de är i drift på nonox.app. `bank_feed`, `peppol`, `skatteverket_api`, `ixbrl` är `SOON` med förbjudna ord; README, färdplan och manual (`bank/skattekontot.md:13-16`, `fakturering/e-faktura-via-peppol.md:13`, `bank/bankkoppling.md:23`, `installningar/bankmodulen.md:27`) säger att de inte är påslagna. | ok | `Features.php`. |
| "Läser Peppol-fakturor i inkorgen" | Inkorgen läser Peppol-filer som kommer med e-post. | ok | `InboxParsersTest`. |
| K3 och koncernredovisning | Inget påstående; fotnoten i `comparison.blade.php:82`, README:88, färdplanen och ändringsloggen säger K2 och intern översikt. | ok | `K3GuardTest`, `GroupOverviewTest`. |
| iXBRL | README:198 och ändringsloggen: nedladdning (beta), ingen inlämning. | ok | `IxbrlAnnualReportTest`. |
| Skatt i bokslutet, K10, bilförmån, importmoms, kundhändelser, skattekonto, AGI-API | Beta eller pilot, utan påstående om konsult- eller juristgranskning. | ok | README:88, ändringsloggen, `bokslut/k10.md:14`, `lon/formansbilar.md:15`. |
| "Jag har granskat siffrorna" | Krävs före moms och AGI till Skatteverket, betalfil och lönefil. | ok | `ReviewConfirmation` i fyra Actions. |
| K2-bekräftelsen | Stämmer med beslut 16. | ok | `K2ReconfirmationTest` (10). |
| Övertidsarter, betalfiler i valuta | Begränsningarna (två avtal; Handelsbanken och Nordea) står i texterna. | ok | `OvertimeKindsTest`, `ForeignPaymentFilesTest`. |
| Version 1.0 den 1 februari 2027 | Samma datum överallt. | ok | Beslut 9. |

### Öppet för Lead

- Fynd 1 och 2 kan få en kund att tro att något finns som inte gör det (NE-filen) eller är släppt trots ett ägarbeslut (MCP). Rätta först.
- Fynd 5–8 är en mekanisk komplettering med samma mening.
- Jag har inte kunnat kontrollera mot servern att pilotspärren, testnycklarna och modulpåslagen är som koden säger.
