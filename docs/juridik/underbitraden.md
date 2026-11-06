# Underbiträden

Version {version} · gäller från {datum}

De här underbiträdena behandlar personuppgifter för {foretag} när vi driftar NONOX på {webbplats}. Vi meddelar ändringar minst 30 dagar i förväg.

| Underbiträde | Vad | Var |
|---|---|---|
| Vultr Holdings | Server och databas som tjänsten körs på, och krypterade säkerhetskopior | Stockholm, Sverige |
| Laravel Holdings (Laravel Forge) | Administration av servern och driftsättning; har teknisk åtkomst till servern men lagrar inga uppgifter ur tjänsten | USA (EU–US Data Privacy Framework) |
| Mailgun Technologies (EU-region) | Utskick av e-post: inloggningskoder, fakturor, påminnelser, lönebesked; mottagning av e-post till inkorgen (in.nonox.app) när modulen Inkorg är påslagen; med modulen CRM: länkar för signering och kopior av signerade dokument, mötesinbjudningar, mottagning till +crm-adressen och, när vi har slagit på dem, utskick och mejl med mall från en egen avsändardomän med leveranshändelser (levererat, studs, klagomål) | EU |
| Europeiska kommissionen (VIES) | Kontroll av momsregistreringsnummer, bara när du begär det | EU |
| Bolagsverket | Hämtning av namn, adress och företagsform med ett organisationsnummer, bara när du begär det eller när du fyller i numret i ett tomt formulär. För en enskild firma är numret ägarens personnummer. Bolagsverket är en myndighet och källa, inte vårt underbiträde | Sverige |

**Bara om företaget själv slår på det:**

| Tjänst | Vad | Var |
|---|---|---|
| OpenAI, Anthropic eller Google (företagets eget val och egen nyckel) | Tolkning av kvitton och leverantörsfakturor i AI-modulen | Kan vara utanför EU/EES |
| Fortnox | Källa vid flytt från Fortnox; vi läser bara | Sverige |
| Enable Banking Oy (modulen Bank) | Läser saldon och transaktioner på de bankkonton företaget kopplar med BankID; NONOX kan inte betala | Finland (EU) |

**Inte underbiträden, men mottagare om företaget tillåter det (koppling till Google- eller Microsoft-kalender i CRM, inte tillgänglig ännu):** när en användare kopplar sin egen Google- eller Microsoft-kalender skriver NONOX användarens planerade CRM-aktiviteter dit (som standard bara typ och företag). Google och Microsoft är då företagets eller användarens egen leverantör, inte vårt underbiträde. Det kan bara ske om företaget har slagit på det under CRM:s inställningar. Google LLC och Microsoft Corporation är certifierade under EU–US Data Privacy Framework, och uppgifterna kan lagras utanför EU/EES.

**Inte underbiträde: Skatteverket.** När en användare loggar in hos Skatteverket från NONOX skickar NONOX momsdeklarationen och arbetsgivardeklarationen dit och hämtar svar, skattekontot och Skatteverkets meddelanden om företaget. Skatteverket är en myndighet och självständigt personuppgiftsansvarig för sin behandling, inte vårt underbiträde. Uppgifterna från Skatteverket tas bara emot på servrarna i Sverige. Se [integritetspolicyn]({integritet}), avsnitt 4.

**Inte underbiträden, men mottagare om företaget kopplar dem: AI-assistenter (inte tillgängliga ännu).** När ett företag kopplar en AI-assistent, till exempel Claude, ChatGPT eller Cursor, till NONOX genom NONOX API och MCP-servern, får assistentens leverantör det som assistenten läser med företagets nyckel. Leverantören är företagets eget val och behandlar uppgifterna enligt företagets avtal med den, inte vårt underbiträde. Den kan finnas utanför EU/EES. Se [integritetspolicyn]({integritet}), avsnitt 4.

**Kalenderlänk i CRM:** en kalenderlänk som en användare prenumererar på hämtas av den kalendertjänst användaren själv väljer. Den tjänsten är användarens eget val och inte vårt underbiträde.

<!-- Förberett för Peppol e-faktura (ADR-050). Läggs in i tabellen "Bara om företaget själv slår på det" när avtalet med operatören är tecknat, samtidigt som versionen höjs (LegalPageController::VERSION) och kunderna får 30 dagars besked:
| Qvalia Group AB (modulen Peppol e-faktura) | Peppol-operatör (accesspunkt): skickar företagets kundfakturor som e-faktura via Peppol, tar emot leverantörsfakturor till företaget och registrerar företaget som mottagare i Peppols adressbok efter sin egen kontroll av vem företaget är | Sverige (EU) |
TODO(legal-verify): underbiträdesavtal (DPA) med operatören och var operatören lagrar uppgifterna; om operatören är personuppgiftsansvarig för sin kundkännedom. -->
<!-- TODO(legal-verify): Google och Microsoft som mottagare på företagets instruktion och inte underbiträden, överföringsgrunden, och om ett privat Google- eller Microsoft-konto ska tillåtas (ADR-049). -->

<!-- TODO(legal-verify): Enable Banking som underbiträde eller företagets egen leverantör, DPA, och om Stafe behöver registreras hos Finansinspektionen (ADR-034, frågor till jurist 30–31). Modulen Bank erbjuds inte i den driftade tjänsten förrän det är klart. -->
<!-- TODO(legal-verify): Bolagsverket som mottagare av personnummer vid hämtning av företagsuppgifter, och villkoren för vidareanvändning (ADR-035, frågor till jurist 32–34). -->
<!-- TODO(legal-verify): bekräfta Laravel Forges certifiering under EU–US Data Privacy Framework och avtal (DPA) med varje underbiträde. -->
