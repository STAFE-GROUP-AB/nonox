# Allmänna villkor för NONOX

Version {version} · gäller från {datum}

Villkoren gäller när ett företag (**Kunden**) använder tjänsten NONOX som {foretag} ({orgnr}) driftar på {webbplats}. Den som godkänner villkoren intygar att hen får företräda Kunden. Tjänsten är till för företag, föreningar och andra som använder den i sin verksamhet, inte för konsumenter.

Villkoren gäller bara den driftade tjänsten. Den som laddar ner NONOX och kör programmet själv gör det enligt licensen AGPL-3.0 och ansvarar själv för driften, se [NONOX i egen drift]({egendrift}).

<!-- TODO(legal-verify): att konsumenttjänstlagen och annan konsumentskyddande lag inte gäller. Kunden är alltid ett företag; en enskild firma drivs av en fysisk person men använder tjänsten i sin näringsverksamhet och är då inte konsument. Bekräfta att meningen "inte för konsumenter" räcker, eller om registreringen ska stoppa den som inte har ett organisationsnummer eller en enskild firma. -->

## 1. Tjänsten

NONOX är ett bokförings-, fakturerings- och lönesystem med öppen källkod (AGPL-3.0). NONOX är ett initiativ med öppen källkod, och programvaran tillhandahålls i befintligt skick. Vi driftar den, säkerhetskopierar Kundens uppgifter dagligen och installerar uppdateringar.

I tjänsten ingår alla moduler och NONOX API, som Kunden kan använda för att koppla egna program och AI-assistenter till sina uppgifter (avsnitt 7). Kopplingar till andra aktörer som tar betalt av oss när Kunden använder dem, NONOX Connect, är ett tillägg som Kunden själv väljer och betalar för separat (avsnitt 5).

## 2. Kundens ansvar

NONOX är ett verktyg. Kunden ansvarar för sin bokföring, sina deklarationer, sina betalningar och sina beslut i tjänsten, precis som med alla bokföringsprogram. Vi ansvarar inte för att Kundens uppgifter är riktiga.

Tjänsten räknar till exempel ut moms, lön, skatt och bokslut efter de regler vi har byggt in. Reglerna kan innehålla fel, hinna bli inaktuella eller inte passa Kundens situation. Det tjänsten räknar fram är därför ett underlag som Kunden ska kontrollera, inte ett besked om vad som är rätt.

**Granska innan något skickas.** Kunden ska granska alla siffror och uppgifter innan något lämnas till Skatteverket, Bolagsverket, en bank eller någon annan, till exempel en momsdeklaration, en arbetsgivardeklaration, en årsredovisning eller en betalfil. Innan tjänsten själv lämnar något till en myndighet eller skapar en betalfil ber den användaren bekräfta **"Jag har granskat siffrorna"**. Med bekräftelsen intygar användaren, för Kundens räkning, att uppgifterna är granskade och att Kunden står för dem. Utan bekräftelsen skickar tjänsten ingenting och skapar ingen betalfil. Filer som Kunden laddar ner och själv lämnar in, till exempel en SIE- eller SRU-fil, granskar Kunden på samma sätt innan de lämnas. Vem som bekräftade och när sparas i behandlingshistoriken.

Kunden ansvarar för vilka användare som får tillgång och med vilken roll, och för att uppgifter om kunder, leverantörer och anställda behandlas lagligt. Det gäller också en redovisningsbyrå eller annan som Kunden ger tillgång: den bekräftar och skickar för Kundens räkning.

Skickar Kunden utskick eller mejl med mall från CRM ansvarar Kunden för att de följer marknadsföringslagen och dataskyddsförordningen: att det finns stöd i lag för att skicka till varje mottagare, till exempel samtycke eller kundundantaget, att innehållet är korrekt och att den som inte vill ha fler utskick tas bort. Utskick får inte användas för skräppost, och inte till köpta eller hyrda adresslistor eller till adresser som samlats in automatiskt, till exempel från webbplatser. Vi får pausa Kundens utskick när mottagarna klagar eller adresser studsar över de gränser som e-posttjänsterna tillämpar, eller vid missbruk, och meddelar Kunden när det sker.

## 3. Personuppgifter

När Kunden lägger in personuppgifter är Kunden personuppgiftsansvarig och vi personuppgiftsbiträde. [Personuppgiftsbiträdesavtalet]({pub}) är en del av dessa villkor och godkänns när företaget skapas. Hur vi själva behandlar personuppgifter beskrivs i [integritetspolicyn]({integritet}).

Företagsregistret för prospektering i CRM är vårt eget, och för det är vi personuppgiftsansvariga. När Kunden för över uppgifter därifrån till sitt CRM ansvarar Kunden för den behandlingen.

## 4. Pris och betalning

Priset för tjänsten framgår av {webbplats}#priser vid beställningen och avser ett år i taget, exklusive moms. I priset ingår alla moduler och NONOX API. NONOX Connect ingår inte, se avsnitt 5. Prisändringar meddelas minst 60 dagar i förväg.

## 5. NONOX Connect

NONOX Connect är kopplingar till andra aktörer som tar betalt av oss när Kunden använder dem, till exempel e-faktura genom Peppol, Skatteverkets tjänster för deklarationer och skattekontot, och bankkopplingar. Vilka kopplingar som finns, och vad var och en kostar, visas i tjänsten.

- **Kunden väljer.** En koppling är avstängd tills Kunden själv slår på den, och Kunden kan stänga av den när som helst. En avstängd koppling kostar ingenting.
- **Pris: leverantörens pris plus 10 %.** För varje koppling betalar Kunden det som leverantören tar betalt av oss för Kundens användning, till exempel per meddelande, per konto eller per månad, och ett påslag på 10 % av det beloppet. Innan Kunden slår på en koppling visar vi leverantörens pris och påslaget var för sig. Fakturan anger samma sak. Priserna är exklusive moms.
- **Om leverantören ändrar sitt pris** ändras Kundens pris i samma mån. Vi meddelar Kunden så snart vi har fått beskedet och, när leverantören ger oss den tiden, minst 30 dagar innan det nya priset gäller. Kunden kan stänga av kopplingen innan dess. Påslaget på 10 % ändras bara med 60 dagars varsel, som andra prisändringar.
- **Betalning.** Användningen faktureras i efterskott.
- **Leverantörens tjänst.** Kopplingen fungerar bara när leverantörens tjänst gör det, och vi ansvarar inte för leverantörens tjänst. För vissa kopplingar måste Kunden själv godkänna leverantörens villkor eller lämna ett medgivande, till exempel med BankID hos banken eller hos Skatteverket.

<!-- TODO(legal-verify): hur fasta avgifter som leverantören tar ut för hela tjänsten (till exempel en månadsavgift för en accesspunkt) fördelas mellan kunderna, så att "leverantörens pris" går att kontrollera; om priset i utländsk valuta räknas om efter en angiven kurs; faktureringsintervall (månad eller kvartal); när avgiften börjar tas ut för kopplingar som redan används under piloten. Prismodellen är beslutad i ADR-064 (ägarbeslut 8). -->
<!-- TODO(legal-verify): kontrollera om Skatteverket tar betalt för sina API:er. Gör det inte det blir priset för Skatteverkskopplingen 0 kr plus 10 % = 0 kr; bekräfta att det är avsikten (ägarbeslut 8 räknar Skatteverket till NONOX Connect), eller lyft ut Skatteverket ur exemplen. -->
<!-- Marknadsföringslagen 10 §: de publika sidorna säger nu, bredvid "Alla moduler ingår", att NONOX Connect är ett tillägg som kostar leverantörens pris plus 10 % (2026-11-06). -->

## 6. Betafunktioner

Funktioner som är märkta Beta i tjänsten är nya och inte färdigprövade. De ingår i priset om inget annat anges. En Betafunktion kan innehålla fler fel än tjänsten i övrigt, och den kan ändras eller tas bort. Tar vi bort en Betafunktion meddelar vi det i tjänsten i förväg, så att Kunden hinner spara det Kunden behöver. Granska det en Betafunktion tar fram extra noga.

## 7. NONOX API och AI-assistenter

Med NONOX API kan Kunden koppla egna program, integrationer och AI-assistenter till sina uppgifter, med en nyckel eller en app som Kunden själv skapar, väljer behörigheter för och kan återkalla när som helst.

En AI-assistent som Kunden kopplar genom NONOX:s MCP-server kan läsa det som nyckeln tillåter, skapa utkast och lägga till kunder, artiklar och leverantörer. Den kan aldrig bokföra, skicka eller betala något; det gör en person i NONOX. MCP-servern är en Betafunktion.

Vilken AI-assistent och vilken leverantör Kunden använder väljer Kunden själv. Leverantören tar emot uppgifterna enligt Kundens eget avtal med den, se [integritetspolicyn]({integritet}) och [biträdesavtalet]({pub}). Vi ansvarar inte för vad ett program eller en AI-assistent som Kunden har kopplat gör med uppgifterna, eller för dess svar och förslag.

<!-- TODO(legal-verify): stycket om MCP-servern stryks före publicering om servern inte har släppts när versionen börjar gälla (ägarbeslut 18: släpps först när juristens text om AI-leverantörer finns i integritetspolicyn). -->

## 8. Kundens uppgifter

Kundens uppgifter tillhör Kunden. Kunden kan när som helst exportera all bokföring som SIE4 och som arkivpaket. Efter uppsägning kan Kunden exportera sina uppgifter i 90 dagar; därefter raderas de, med undantag för det vi enligt lag måste spara.

## 9. Tillgänglighet

Vi strävar efter hög tillgänglighet men kan inte garantera att tjänsten alltid är tillgänglig. Planerat underhåll görs helst utanför kontorstid.

## 10. Ansvar

Vi ansvarar bara för skada som vi har orsakat med uppsåt eller genom grov vårdslöshet. För annan skada ansvarar vi inte, varken direkt eller indirekt skada, till exempel utebliven vinst, förseningsavgifter, skattetillägg, ränta eller andra krav från en myndighet.

Vi ansvarar inte heller för följder av
- uppgifter som Kunden eller Kundens användare har lagt in, godkänt eller bekräftat, och inlämningar och betalningar som en användare har bekräftat med "Jag har granskat siffrorna",
- program, AI-assistenter, banker, myndigheter och andra leverantörer som Kunden har kopplat till tjänsten eller använder genom NONOX Connect,
- att tjänsten inte är tillgänglig.

Ingenting i villkoren begränsar vårt ansvar för skada som vi har orsakat med uppsåt eller genom grov vårdslöshet, eller ansvar som följer av tvingande lag, till exempel ansvaret mot den som personuppgifterna gäller enligt dataskyddsförordningen.

Är tjänsten väsentligt felaktig eller otillgänglig, och rättar vi inte felet inom skälig tid efter att Kunden har påpekat det, får Kunden säga upp avtalet med omedelbar verkan och få tillbaka den del av årsavgiften som avser tiden efter uppsägningen.

<!-- TODO(legal-verify): Ägarbeslut 10 (2026-11-06): ansvar bara vid grov vårdslöshet eller uppsåt, ingen försäkring. Taket "det belopp Kunden betalat de senaste tolv månaderna" i version 1.5 är borttaget. Skäl: enligt svensk rätt gäller en ansvarsbegränsning normalt inte när skadan orsakats med uppsåt eller genom grov vårdslöshet, och det är de enda fall som nu återstår. Ett tak skulle då antingen sakna verkan eller läsas som ett försök att begränsa ansvar som inte får begränsas, och en oklar klausul tolkas mot den som skrivit den. Pröva om ett tak ändå bör stå kvar. -->
<!-- TODO(legal-verify): avtalslagen 36 §. Att friskriva sig från allt ansvar vid normal vårdslöshet för en betald tjänst som sköter bokföring, säkerhetskopior och personuppgifter kan jämkas som oskäligt, även mellan näringsidkare, särskilt mot små företag och enskilda firmor med svag förhandlingsposition. Motvikter i texten: kravet på granskning och bekräftelse, rätten att exportera allt när som helst, rätten att säga upp och få tillbaka avgift vid väsentligt fel (stycket ovan, förslag som kräver ägarens godkännande) och vid ändring till nackdel (avsnitt 12). Pröva om det räcker, och om normal vårdslöshet i kärnåtaganden (säkerhetskopior, säkerhet) ska undantas. -->
<!-- TODO(legal-verify): dataskyddsförordningen artikel 82.4–82.5: ansvaret mot den registrerade kan inte avtalas bort, men regressen mellan Kunden och oss kan regleras. Bekräfta att friskrivningen gäller regressen och att biträdesavtalet (som går före villkoren i frågor om personuppgifter, punkt 10) inte säger något annat. -->
<!-- TODO(legal-verify): om en reklamationsfrist för skadeståndskrav ska läggas till (till exempel sex månader från det att Kunden märkte skadan). -->

## 11. Uppsägning

Avtalet löper ett år i taget och förlängs automatiskt. Det kan sägas upp när som helst före förnyelsen. Vi kan säga upp avtalet om Kunden väsentligt bryter mot villkoren.

## 12. Ändringar, överlåtelse och tvist

Vi kan ändra villkoren med 60 dagars varsel. Varsel lämnas med e-post till Kundens ägare och på {webbplats}. Fram till dess gäller den tidigare versionen för befintliga kunder. Fortsätter Kunden att använda tjänsten efter dagen då ändringen börjar gälla, gäller den nya versionen. Är ändringen till nackdel för Kunden får Kunden säga upp avtalet till den dagen och få tillbaka den del av en förbetald avgift som avser tiden därefter.

Vi får överlåta avtalet, och biträdesavtalet, till ett annat bolag i samma koncern som tar över driften av tjänsten. Överlåtelsen meddelas på samma sätt och med samma varsel som en ändring av villkoren, och Kunden får då säga upp avtalet på samma sätt.

Svensk lag gäller. Tvist avgörs av svensk allmän domstol.

Kontakt: {foretag}, {adress}, {epost}.

<!-- TODO(legal-verify): överlåtelseklausulen gäller framtida byten. Det byte av driftbolag som aviseras med version 1.6 sker samtidigt som klausulen börjar gälla och vilar därför på kundens godkännande, se docs/status/integritetspolicy-skatteverket-avisering.md. Bekräfta att godkännande genom fortsatt användning räcker. -->
<!-- TODO(legal-verify): texten är ett utkast och ska granskas av jurist innan den används mot betalande kunder. -->
