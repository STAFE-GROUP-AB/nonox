# Personuppgiftsbiträdesavtal

Version {version} · gäller från {datum}

Avtalet ingås mellan företaget som använder NONOX (**den personuppgiftsansvarige**) och {foretag} ({orgnr}) (**biträdet**), och gäller när biträdet behandlar personuppgifter för den personuppgiftsansvariges räkning i tjänsten NONOX, enligt artikel 28 i dataskyddsförordningen (GDPR). Avtalet gäller bara den tjänst som biträdet driftar. När NONOX körs i egen drift är biträdet inte personuppgiftsbiträde, se [NONOX i egen drift]({egendrift}).

## 1. Behandlingen

| | |
|---|---|
| **Ändamål** | Att tillhandahålla tjänsten: bokföring, fakturering, leverantörsreskontra, moms, bokslut, lön, arkivering och, när den personuppgiftsansvarige slår på respektive modul, personalliggare, mottagning av e-post till inkorgen, tolkning av underlag med AI och CRM (kunder, kontaktpersoner, affärer, avtalsregister och signering av offerter och avtal). |
| **Kategorier av registrerade** | Den personuppgiftsansvariges anställda, kunder, leverantörer, kontaktpersoner och användare. Med personalliggaren: alla som checkar in i den personuppgiftsansvariges lokaler, även inhyrd personal och personal hos andra arbetsgivare. Med inkorgen: avsändare av e-post och andra personer som nämns i meddelanden och bilagor (tredje man). Med signering i CRM: den som signerar eller avböjer en offert eller ett avtal (namn, titel, namnteckning, tidpunkt, IP-adress, webbläsare och e-postadress). |
| **Kategorier av uppgifter** | Namn, kontaktuppgifter, personnummer, bankuppgifter, fakturor och underlag, lön, skatt, förmåner, utlägg, frånvaro. Med signering i CRM: namnteckning, IP-adress, webbläsare. |
| **Personalliggare** | Namn, person- eller samordningsnummer, arbetsgivarens namn och organisationsnummer, tidpunkter för in- och utcheckning per lokal, rättelser med skäl, e-postadress om den anges, PIN-kod (lagras bara som kontrollvärde, aldrig i klartext) och personlig QR-kod. Foto bara om personen har samtyckt; det tas bort när samtycket återkallas. Vid mobil incheckning med platskontroll sparas bara avståndet till lokalen i meter, aldrig positionen. |
| **Inkorg** | Avsändarens namn och e-postadress, ämne, datum, meddelandets text och bilagor i original. Meddelanden och bilagor kan innehålla vilka personuppgifter som helst om avsändaren och om tredje man, till exempel namn på kunder, anställda eller privatpersoner på kvitton och fakturor. Biträdet styr inte vad avsändare skickar. |
| **AI-tolkning** | Om den personuppgiftsansvarige slår på NONOX AI skickas de underlag den väljer att låta AI läsa (leverantörsfakturor, kvitton, verifikationsunderlag, anställdas utlägg om det valts särskilt, och inkorgens dokument om det valts i inkorgens inställningar) till den AI-leverantör den personuppgiftsansvarige valt, se punkt 6. |
| **Känsliga uppgifter** | Sjukfrånvaro (uppgift om hälsa) och, om den personuppgiftsansvarige anger det, medlemskap i Svenska kyrkan för skatteberäkning. Tjänsten har ingen särskild löneart för fackföreningsavgift. Om den personuppgiftsansvarige drar fackavgift på lönen, eller skriver om fackligt medlemskap i en löneart, en anteckning eller ett underlag, behandlas en uppgift om fackligt medlemskap (artikel 9.1). Den personuppgiftsansvarige ansvarar då för att det finns stöd för behandlingen och bör inte skriva sådana uppgifter i fritext. Inkorgens meddelanden kan innehålla känsliga uppgifter som avsändaren själv har skickat. Foton i personalliggaren används bara för att personen ska känna igen sig själv på kiosken. De behandlas inte med teknik för ansiktsigenkänning. |
| **Varaktighet** | Så länge avtalet om tjänsten gäller, och därefter enligt punkt 8. |

<!-- TODO(legal-verify): om ett nettolöneavdrag för fackavgift kräver stöd i artikel 9.2 b och kollektivavtal, och om tjänsten bör varna när en löneart eller anteckning nämner facket. Om foton på kiosken kan räknas som biometriska uppgifter (artikel 4.14) när de bara visas och inte jämförs maskinellt. -->

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
