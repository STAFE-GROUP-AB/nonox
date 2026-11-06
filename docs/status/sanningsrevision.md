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
