# Personuppgiftsbiträdesavtal

Version {version} · gäller från {datum}

Avtalet ingås mellan företaget som använder NONOX (**den personuppgiftsansvarige**) och {foretag} ({orgnr}) (**biträdet**), och gäller när biträdet behandlar personuppgifter för den personuppgiftsansvariges räkning i tjänsten NONOX, enligt artikel 28 i dataskyddsförordningen (GDPR). Avtalet gäller bara den tjänst som biträdet driftar. När NONOX körs i egen drift är biträdet inte personuppgiftsbiträde, se [NONOX i egen drift]({egendrift}).

## 1. Behandlingen

| | |
|---|---|
| **Ändamål** | Att tillhandahålla tjänsten: bokföring, fakturering, leverantörsreskontra, moms, bokslut, lön, arkivering och, när den personuppgiftsansvarige slår på respektive modul, personalliggare, mottagning av e-post till inkorgen, tolkning av underlag med AI och CRM (kunder, kontaktpersoner, affärer, ringlistor och bevakning av registeruppgifter, avtalsregister och signering av offerter och avtal, loggning av e-post, utskick till segment, webbformulär som skapar prospekt, och användarnas kalenderlänkar och mötesinbjudningar) och arbetsorder (jobb hos kunder med tid, material, foton och ändrings- och tilläggsarbeten som kunden godkänner), aktiebok och styrelserum (aktieboken, bolagsordningen, stämmor och styrelsemöten med protokoll) och kundportalen, där kundernas kontaktpersoner loggar in och ser sin kunds fakturor, kontoutdrag och offerter, godkänner offerter och ställer frågor. |
| **Kategorier av registrerade** | Den personuppgiftsansvariges anställda, kunder, leverantörer, kontaktpersoner och användare. Med personalliggaren: alla som checkar in i den personuppgiftsansvariges lokaler, även inhyrd personal och personal hos andra arbetsgivare. Med inkorgen: avsändare av e-post och andra personer som nämns i meddelanden och bilagor (tredje man). Med CRM: kontaktpersoner hos kunder och prospekt, mottagare av utskick och mötesinbjudningar, de som skickar in webbformulär, tredje personer i loggade mejl, den som signerar eller avböjer en offert eller ett avtal, och användare som skapar kalenderlänkar. Med arbetsorder: kunder och deras ROT- och RUT-köpare, de som arbetar på arbetsordern och den som godkänner eller avböjer ett ÄTA. Med aktiebok och styrelserum: aktieägare, rättighetshavare och de som deltar i stämmor och styrelsemöten. |
| **Kategorier av uppgifter** | Namn, kontaktuppgifter, personnummer, bankuppgifter, fakturor och underlag, lön, skatt, förmåner, utlägg, frånvaro. Med CRM: roll, rättslig grund och samtycken (med formulärrutans text och version), aktiviteter med tid och plats, ringlistor med samtalens utfall och anteckningar, ändringar i registeruppgifter för bevakade kunder och prospekt, avtal i avtalsregistret, loggade mejl (text och adresser, krypterade), status per mottagare av utskick (skickat, levererat, studsat, klagomål, avregistrerat), antal klick per länk (aldrig per person), IP-adresser som hash i en räknare mot skräppost (högst tio minuter, aldrig i databasen) och en kontrollsumma av kalenderlänkens nyckel. Med signering i CRM: namn och titel, namnteckning (krypterad), tidpunkt, IP-adress, webbläsare, e-postadressen länken skickades till, ett eventuellt skäl att avböja och den visade PDF:en med kontrollsumma. Med arbetsorder: arbetsplatsens adress, fastighetsbeteckning eller bostadsrättsförening och lägenhetsnummer, portkod och nycklar (krypterade, tas bort när arbetsordern avslutas), tid per person och dag, foton från arbetsplatsen, och vid signering av ett ÄTA på plats vem som visade sidan, på vilken enhet och en kontrollsumma av koden till kundens e-post. ROT- och RUT-köparens personnummer stannar på kunden och skrivs ut på ROT- och RUT-fakturan. Med kundportalen: kontaktpersonens e-postadress och namn, vilka kunder personen har tillgång till, inloggningskoder (HMAC), händelser (inloggning med IP-adress och webbläsare, öppnade fakturor och nedladdningar), frågor och meddelanden med bilagor, "Jag har betalat" med datum och godkända eller avböjda offerter med samma bevis som signering. |
| **Personalliggare** | Namn, person- eller samordningsnummer, arbetsgivarens namn och organisationsnummer, tidpunkter för in- och utcheckning per lokal, rättelser med skäl, e-postadress om den anges, PIN-kod (lagras bara som kontrollvärde, aldrig i klartext) och personlig QR-kod. Foto bara om personen har samtyckt; det tas bort när samtycket återkallas. Vid mobil incheckning med platskontroll sparas bara avståndet till lokalen i meter, aldrig positionen. |
| **Aktiebok och styrelserum** | Aktieägares namn, personnummer (krypterat), organisationsnummer eller annat id-nummer med land, postadress och aktieinnehav, med varje ändring och uppgifterna vid tillfället; anteckningar om rättighetshavare och förköp. Namn och roll för dem som deltar i stämmor och styrelsemöten, ordförande, protokollförare och justerare, och protokollens beslut. Den personuppgiftsansvarige (bolaget) behandlar uppgifterna för att fullgöra skyldigheten att föra aktiebok (artikel 6.1 c, aktiebolagslagen 5 kap. 4 §); aktieboken bevaras så länge bolaget finns och minst tio år efter upplösningen, och uppgifter som tagits bort ur den minst tio år (5 kap. 3 §). Personnummer skrivs aldrig i behandlingshistoriken. |
| **Inkorg** | Avsändarens namn och e-postadress, ämne, datum, meddelandets text och bilagor i original. Meddelanden och bilagor kan innehålla vilka personuppgifter som helst om avsändaren och om tredje man, till exempel namn på kunder, anställda eller privatpersoner på kvitton och fakturor. Biträdet styr inte vad avsändare skickar. |
| **AI-tolkning** | Om den personuppgiftsansvarige slår på NONOX AI skickas de underlag den väljer att låta AI läsa (leverantörsfakturor, kvitton, verifikationsunderlag, anställdas utlägg om det valts särskilt, och inkorgens dokument om det valts i inkorgens inställningar) till den AI-leverantör den personuppgiftsansvarige valt, se punkt 6. |
| **Skatteverket** | När en användare som företräder den personuppgiftsansvarige själv loggar in hos Skatteverket från tjänsten och klickar, skickar biträdet för den personuppgiftsansvariges räkning momsdeklarationens rutor, arbetsgivardeklarationens fil (de anställdas personnummer, eller födelsetid, namn och adress för den som saknar svenskt nummer, lön, förmåner, avdragen skatt och kontaktpersonens namn, telefon och e-post) och den personuppgiftsansvariges identitet (för en enskild firma innehavarens personnummer) till Skatteverket. Biträdet tar emot Skatteverkets svar: kontrollresultat, länk till underskrift, kvittenser och beslut, arbetsgivardeklarationens status och händelser, skattekontots saldo, transaktioner och utbetalningsspärrar, och för en juridisk person Skatteverkets meddelanden om den (kundhändelser), vars fritext kan innehålla personuppgifter om företrädare, anställda och andra och uppgifter som omfattas av sekretess. Vad som sparas och hur länge står i [integritetspolicyn]({integritet}), avsnitt 4. |
| **Känsliga uppgifter** | Sjukfrånvaro (uppgift om hälsa) och, om den personuppgiftsansvarige anger det, medlemskap i Svenska kyrkan för skatteberäkning. Tjänsten har ingen särskild löneart för fackföreningsavgift. Om den personuppgiftsansvarige drar fackavgift på lönen, eller skriver om fackligt medlemskap i en löneart, en anteckning eller ett underlag, behandlas en uppgift om fackligt medlemskap (artikel 9.1). Den personuppgiftsansvarige ansvarar då för att det finns stöd för behandlingen och bör inte skriva sådana uppgifter i fritext. Inkorgens meddelanden kan innehålla känsliga uppgifter som avsändaren själv har skickat. Loggade mejl i CRM kan innehålla känsliga uppgifter som avsändaren eller mottagaren själv har skrivit. Foton i personalliggaren används bara för att personen ska känna igen sig själv på kiosken. De behandlas inte med teknik för ansiktsigenkänning. |
| **Varaktighet** | Så länge avtalet om tjänsten gäller, och därefter enligt punkt 8. |

<!-- TODO(legal-verify): om ett nettolöneavdrag för fackavgift kräver stöd i artikel 9.2 b och kollektivavtal, och om tjänsten bör varna när en löneart eller anteckning nämner facket. Om foton på kiosken kan räknas som biometriska uppgifter (artikel 4.14) när de bara visas och inte jämförs maskinellt. -->

**Personuppgiftsansvaret för uppgifter från och till Skatteverket.** Enligt Skatteverkets allmänna villkor för respektive API är Skatteverket personuppgiftsansvarigt för sin egen behandling och för att föra över uppgifterna till biträdets system. Därefter övertar mottagaren av uppgifterna (för arbetsgivardeklarationen slutanvändaren) personuppgiftsansvaret, och programvaruföretaget är dess personuppgiftsbiträde. Villkoren anger att programvaruföretaget bör avtala skriftligt om fördelningen av ansvaret. Det här avtalet är den överenskommelsen: för det som skickas till Skatteverket och det som tas emot därifrån är den personuppgiftsansvarige personuppgiftsansvarig och biträdet dess personuppgiftsbiträde, också när anropet görs av en användare som företräder den personuppgiftsansvarige, till exempel en anställd eller en redovisningskonsult. Skatteverket är inte underbiträde. Biträdet
- använder uppgifterna bara för de ändamål som Skatteverkets villkor tillåter: att hantera momsdeklarationen och arbetsgivardeklarationen, bokföring, redovisning och uppföljning av skattekontot, och återkoppling om den personuppgiftsansvariges ärenden hos Skatteverket,
- tar bara emot uppgifterna i en teknisk miljö som fysiskt finns inom EU/EES,
- visar Skatteverkets meddelanden (kundhändelser) bara för den användare som hämtade dem, i sin helhet,
- lämnar inte vidare kontrollresultat, kvittenser, beslut, skattekontot eller meddelandena genom NONOX API, varken genom API:et eller MCP-servern.

<!-- Källor: Allmänna villkor Arbetsgivardeklaration inlämning 7.3, Arbetsgivardeklaration hantera redovisningsperiod 7.3, Skattekonto 7.1 och 7.3, Kundhändelser 5.1–5.2, 7.1 och 7.4, Momsdeklaration 4.2 och 8.1. Momsvillkoren har inget avsnitt om personuppgiftsansvar.
TODO(legal-verify): att den personuppgiftsansvarige (företaget) och inte slutanvändaren som person, eller ett ombuds egen byrå, ska vara personuppgiftsansvarig efter överföringen; Kundhändelser 2 definierar "Mottagaren av uppgifter" som programvaruföretaget men 7.4 gör det till biträde för slutanvändaren.
TODO(legal-verify): att avtalsparten hos Skatteverket och biträdet ({foretag}) är samma bolag när versionen börjar gälla (Allmänna villkor Momsdeklaration 13.2: avtalet får inte överlåtas). -->

## 2. Instruktioner

Biträdet behandlar personuppgifterna endast enligt den personuppgiftsansvariges dokumenterade instruktioner. Avtalet, villkoren och de val den personuppgiftsansvarige gör i tjänsten utgör instruktionerna. Biträdet underrättar den personuppgiftsansvarige om en instruktion strider mot dataskyddsreglerna.

## 3. Sekretess

Den som arbetar för biträdet och har tillgång till personuppgifterna har tystnadsplikt och får bara behandla dem enligt instruktionerna.

För att driva tjänsten ser biträdets administratörer en översikt över företag och användare: företagets namn, organisationsnummer (inte för en enskild firma), påslagna moduler, antal medlemmar och bokförda verifikationer och senaste aktivitet, samt användarnas namn, e-post, roll och senaste inloggning. Översikten visar inga belopp, verifikationer, fakturor, personnummer eller uppgifter om anställda, går inte att ändra något i, och varje visning skrivs i behandlingshistoriken.

## 4. Säkerhet (artikel 32)

Biträdet vidtar bland annat dessa åtgärder:
- **Kryptering:** personnummer, bankuppgifter och andra känsliga uppgifter krypteras i databasen.
- **Inloggning och åtkomst:** inloggning sker med engångskod till e-post, och åtkomsten styrs per företag och roll. Rättigheten att se känsliga löneuppgifter är särskild, och den som har den eller kan se fullständiga personnummer bekräftar inloggningen med en passkey. Den personuppgiftsansvarige kan kräva passkey för alla användare i företaget.
- **Spårbarhet:** behandlingshistorik som inte kan ändras.
- **Säkerhetskopior:** varje natt säkerhetskopieras databasen och de uppladdade filerna. Kopiorna krypteras (AES-256), lagras bara i Sverige och sparas i 7 dagar. Varje vecka testas att den senaste kopian går att återställa.
- **Överföring:** krypterade förbindelser (TLS).
- **Uppdateringar:** regelbundna säkerhetsuppdateringar.

## 5. Underbiträden

Den personuppgiftsansvarige ger ett allmänt förhandstillstånd till de underbiträden som anges på [underbitradessidan]({underbitraden}). Biträdet meddelar minst 30 dagar i förväg innan ett underbiträde läggs till eller byts, och den personuppgiftsansvarige får invända. Biträdet ingår avtal med varje underbiträde med motsvarande skyldigheter och ansvarar för underbiträdets arbete.

## 6. Överföring till tredje land och AI-leverantörer

Personuppgifterna lagras på servrar i Sverige. Underbiträden utanför EU/EES anges på underbitradessidan, och överföringen sker med stöd av EU–US Data Privacy Framework eller EU-kommissionens standardavtalsklausuler.

**NONOX AI.** Modulen är avstängd tills den personuppgiftsansvarige själv slår på den, väljer AI-leverantör (OpenAI, Anthropic eller Google) och lägger in en egen API-nyckel. Därefter gäller följande:
- Ett underlag skickas till AI-leverantören först när en användare ber om en analys. Anställdas kvitton skickas bara om den personuppgiftsansvarige uttryckligen har valt det. Inkorgens dokument skickas bara om det har valts i inkorgens inställningar och ingen mall redan tolkar dokumentet.
- Med underlaget skickas företagets kontoplan. För en dagsrapport från ett kassasystem skickas rapportens rubriker och belopp, även när rapporten står i själva mejlet. Avsändare och ämne skickas inte, och inga andra uppgifter ur tjänsten skickas med.
- Anropet görs med den personuppgiftsansvariges egen nyckel och under den personuppgiftsansvariges eget avtal med AI-leverantören. Biträdet har inget avtal med AI-leverantören för den behandlingen. AI-leverantören är därför inte ett underbiträde till biträdet.
- AI-leverantören kan finnas utanför EU/EES. Överföringen sker på den personuppgiftsansvariges instruktion. Den personuppgiftsansvarige ansvarar för avtalet med leverantören och för att det finns stöd för överföringen.
- AI:n sparar och bokför aldrig något själv. Svaret är ett förslag som en person kontrollerar. Behandlingshistoriken visar att en analys gjorts, med leverantör, modell och antal token, men aldrig underlagets innehåll.

**AI-assistenter genom NONOX API.** När MCP-servern släpps kan den personuppgiftsansvarige skapa en API-nyckel för en AI-assistent som den själv väljer. Biträdet lämnar då, på den personuppgiftsansvariges instruktion, ut de uppgifter som nyckelns behörigheter tillåter till assistenten, som skickar dem till sin leverantör. Assistenten kan läsa, skapa utkast och lägga till kunder, artiklar och leverantörer, men aldrig bokföra, skicka eller betala något, och den får aldrig lönerna eller svaren från Skatteverket. Leverantören är inte underbiträde till biträdet. Den personuppgiftsansvarige ansvarar för sitt avtal med leverantören, för vilka behörigheter nyckeln får och för stödet för en överföring utanför EU/EES. Behandlingshistoriken visar vilka verktyg nyckeln har använt, men inte innehållet.

**Kalenderkoppling i CRM.** Om den personuppgiftsansvarige tillåter att användare kopplar sin Google- eller Microsoft-kalender skriver biträdet användarens planerade CRM-aktiviteter till den kalendern på den personuppgiftsansvariges instruktion. Google och Microsoft är då inte underbiträden till biträdet. De kan finnas utanför EU/EES; den personuppgiftsansvarige ansvarar för sitt avtal med leverantören och för stödet för överföringen.

<!-- TODO(legal-verify): rollfördelningen när kundens egen nyckel används: är AI-leverantören kundens eget biträde (artikel 28) och biträdet bara en förmedlare, och räcker kundens avtal med leverantören för överföringen enligt kapitel V (artikel 44 och följande)? -->

## 7. Bistånd

Biträdet hjälper den personuppgiftsansvarige att
- besvara begäranden från registrerade (tjänsten har utdrag och export),
- uppfylla skyldigheterna i artiklarna 32–36,
- göra konsekvensbedömningar.

Biträdet underrättar den personuppgiftsansvarige utan onödigt dröjsmål, och senast inom 36 timmar, efter att ha fått kännedom om en personuppgiftsincident, med de uppgifter som artikel 33.3 kräver.

## 8. När avtalet upphör

Den personuppgiftsansvarige kan exportera sina uppgifter i 90 dagar efter att avtalet upphört. Därefter raderar biträdet uppgifterna, inklusive kopior, inom 90 dagar. Det som lag kräver att biträdet själv sparar raderas inte.

## 9. Granskning

Biträdet ger den personuppgiftsansvarige den information som behövs för att visa att skyldigheterna i artikel 28 är uppfyllda. Biträdet möjliggör och bidrar till granskningar, med rimligt varsel.

## 10. Övrigt

Avtalet gäller före villkoren vid motstridighet i fråga om personuppgifter. Svensk lag gäller.

<!-- TODO(legal-verify): texten är ett utkast och ska granskas av jurist innan den används mot betalande kunder. -->
