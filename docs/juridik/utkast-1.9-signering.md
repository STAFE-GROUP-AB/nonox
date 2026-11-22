# Juridisk version 1.9: text om signering (utkast)

**UTKAST till juridisk version 1.9 – inte publicerat och inte granskat av jurist.** Skrivet
2026-11-19 i paket S16 (ADR-046 tillägg 2026-11-18, lucka 11). Texten nedan ska föras in i
personuppgiftsbiträdesavtalet och integritetspolicyn när version 1.9 publiceras (planerat
2026-12-05). `LegalPageController::VERSION` höjs inte här; den höjs en gång när version 1.9 slås
ihop. En jurist ska granska formuleringarna innan de används. Inget här är juridisk rådgivning.

Bakgrund: signeringen gäller sedan ADR-046-tillägget alla dokumenttyper (offerter, avtal, ÄTA,
kundportalens godkännanden och egna PDF-dokument i modulen Signering), med flera undertecknare,
kontroll av undertecknaren, fält i dokumentet, bevarandetider och radering. Biträdesavtalet
beskriver i dag bara "signering av offerter och avtal" i CRM. Behandlingen i sig beskrivs i
`docs/gdpr/registerforteckning.md` och `docs/gdpr/lagringstider.md`.

## A. Personuppgiftsbiträdesavtalet, avsnitt 1 (Behandlingen)

**Ändamål**, ny mening efter uppräkningen av modulerna:

> Med signering (i kärnan och i modulen Signering): att skicka den personuppgiftsansvariges
> dokument, till exempel offerter, avtal, ändrings- och tilläggsarbeten och egna PDF-dokument,
> för elektronisk underskrift till en eller flera undertecknare och kopiemottagare, att kontrollera
> undertecknaren med en kod, att spara bevisen om underskriften och att gallra dem när
> bevarandetiden har gått ut.

**Kategorier av registrerade**, ersätter "den som signerar eller avböjer en offert eller ett
avtal" under CRM och läggs till för alla:

> Med signering: undertecknare och kopiemottagare av den personuppgiftsansvariges dokument, och
> personer som nämns i ett dokument som skickas för signering.

**Kategorier av uppgifter**, ersätter "Med signering i CRM: …":

> Med signering: e-postadressen länken eller kopian gick till, namn och titel, namnteckning eller
> initialer (krypterade), uppgifter som undertecknaren fyller i fält i dokumentet, tidpunkter,
> IP-adress och webbläsare, hur undertecknaren kontrollerades (kontrollsummor av koder; en åtkomstkod ur ett massutskick sparas krypterad, så att den kan läsas av tjänsten, bara tills signeringen har skickats, se nedan),
> händelserna i en hashkedja, ett eventuellt skäl att avböja, och dokumentet med dess versioner
> och kontrollsummor. Ett dokument som den personuppgiftsansvarige laddar upp kan innehålla
> vilka uppgifter som helst; den personuppgiftsansvarige bestämmer innehållet och ändamålet.
>
> Med massutskick för signering: undertecknarnas e-postadresser, frivilliga namn, språk och åtkomstkoder ur en fil som den personuppgiftsansvarige laddar upp. Filen sparas inte; uppgifterna sparas krypterade bara tills signeringen har skickats eller massutskicket har avslutats. Det som finns kvar är en resultatlista utan adresser, som raderas efter tolv månader.

**Lagringstid**, ny mening (eller i bilagan om lagringstider):

> Signeringar sparas så länge den personuppgiftsansvarige har valt inom ramarna i tjänsten:
> räkenskapsinformation till och med den 31 december sju år efter utgången av det räkenskapsår
> då affärshändelsen bokfördes (bokföringslagen 7 kap. 2 §), andra signerade dokument 7–25 år
> efter signeringen (förvalt 10 år), ett signerat avtal så länge det gäller och därefter samma
> tid räknat från dess slut, och signeringar som inte blev klara 12 månader efter att de stängdes. Därefter raderas
> de automatiskt, om inte den personuppgiftsansvarige har satt en bevarandespärr.

## B. Personuppgiftsbiträdesavtalet, avsnitt 7 (Bistånd)

Ny punkt om den registrerades rättigheter:

> För signering tillhandahåller biträdet funktioner för att söka fram allt om en undertecknare
> eller kopiemottagare på e-postadress, ta ut ett registerutdrag med de filer personen har
> signerat, och radera det som inte måste sparas. Är en signering fortfarande ute när en
> undertecknare begär radering dras den tillbaka, så att ingen längre kan signera den; de andra
> mottagarnas uppgifter raderas inte. Signerade dokument som är räkenskapsinformation
> eller bevis om ett avtal raderas inte inom bevarandetiden; tjänsten visar då skälet
> (dataskyddsförordningen artikel 17.3 b eller e) och loggar avslaget. Den personuppgiftsansvarige
> bedömer begäran och svarar den registrerade.

## C. Integritetspolicyn, avsnittet om när {foretag} är personuppgiftsbiträde

Ersätter exemplet "till exempel när någon signerar en offert eller ett ändrings- eller
tilläggsarbete (ÄTA) på en arbetsorder":

> till exempel när någon signerar ett dokument som företaget har skickat via NONOX (en offert,
> ett avtal, ett ändrings- eller tilläggsarbete eller ett eget dokument), eller får en kopia av
> det

och en ny mening sist i stycket:

> Ett signerat dokument kan behöva sparas i flera år efter signeringen, eftersom det kan vara
> räkenskapsinformation eller bevis om ett avtal. Vill du få ut eller radera uppgifter om en
> signering vänder du dig till det företag som skickade dokumentet.

## C2. Kontrollsidan (ADR-046 tillägg, lucka 6, paket S12)

Gäller först när kontrollsidan slås på i den driftade tjänsten, vilket väntar på svaret på frågan om
betrodda tjänster enligt eIDAS. Sidan är avstängd för varje företag tills företaget själv slår på den.

**Personuppgiftsbiträdesavtalet, avsnitt 1 (Behandlingen), ny mening efter signeringen:**

> Med kontrollsidan, om företaget har slagit på den: svar till den som har en kopia av ett
> signerat dokument om att filen är oförändrad, med företagets namn, antal undertecknare och
> kontrollsätt, när alla hade signerat och om filen är förseglad.

**Integritetspolicyn, ny mening om besökare på de öppna sidorna:**

> Besöker du kontrollsidan skickar din webbläsare bara filens kontrollsumma. Vi sparar inte
> kontrollsumman. Din IP-adress används för att begränsa antalet kontroller och finns kvar högst
> en minut, och i webbserverns åtkomstlogg som annan trafik (artikel 6.1 f).

De gällande texterna i `docs/juridik/personuppgiftsbitradesavtal.md` och `integritetspolicyn` ändras inte
före versionsbytet; en ändring kräver höjd `LegalPageController::VERSION`.

## C3. Underskrift hos extern tjänst (ADR-046 tillägg, lucka 12, paket S17)

Gäller först när funktionen slås på i den driftade tjänsten, vilket väntar på ett avtal med en tjänst
och på svaren om avancerade och kvalificerade underskrifter. Vid egen drift slår den som driver
installationen på den själv.

**Personuppgiftsbiträdesavtalet, avsnitt 1 (Behandlingen), ny mening efter signeringen:**

> Med underskrift hos en extern tjänst som den personuppgiftsansvarige har valt och har avtal med:
> att skicka undertecknaren till tjänsten, att skicka tjänsten en kontrollsumma som räknats fram ur
> dokumentet, att lägga in tjänstens underskrift och undertecknarens certifikat i det signerade
> dokumentet och att spara certifikatets namn, utfärdare, serienummer och giltighetstid som bevis.

**Integritetspolicyn, ny mening i avsnittet om när {foretag} är personuppgiftsbiträde:**

> Skriver du under hos en extern tjänst för underskrift som avsändaren använder, loggar du in hos
> tjänsten och skriver under där enligt tjänstens egna villkor. Ditt certifikat, med de uppgifter
> tjänsten har lagt i det, läggs in i det signerade dokumentet som alla mottagare får.

## D. Underbiträden

Ingen ändring i version 1.9. En tidsstämpeltjänst (lucka 5) får bara en kontrollsumma av
dokumentet och inga personuppgifter. En signeringstjänst som får undertecknarens identitet
(lucka 12, till exempel BankID) ska namnges i `docs/juridik/underbitraden.md` innan den slås på.
Med paket S17 lägger företaget själv in tjänsten och har eget avtal med den; tjänsten är då en
mottagare som företaget har valt och inte driftbolagets underbiträde. Sluter driftbolaget i stället
avtalet (ägarbeslut 2 och 17 i ADR-046-tillägget: avgifter via NONOX Connect) ska tjänsten namnges
där innan funktionen slås på i den driftade tjänsten.

## E. Användarvillkoren (läggs till vid versionsbytet, ändra inte de gällande villkoren förrän då)

Ny mening i avsnittet om tillåten användning:

> Massutskick för signering får inte användas för marknadsföring utan stöd i marknadsföringslagen eller för skräppost.

## Frågor till juristen

- TODO(legal-verify): standardtiden 10 år för signerade dokument som inte är
  räkenskapsinformation (den allmänna preskriptionstiden, preskriptionslagen 2 §) mot
  lagringsminimeringen i artikel 5.1 e.
- TODO(legal-verify): om alla signerade avtal i CRM är räkenskapsinformation (bokföringslagen
  5 kap. 7 §), eller bara de som har betydelse för en affärshändelse.
- TODO(legal-verify): om 12 månader räcker för en signering där några men inte alla hann signera,
  särskilt en som gick ut när två av tre hade signerat: en part som signerat kan hävda ett bindande
  anbud enligt avtalslagen.
- TODO(legal-verify): om en betalning som bokförs enligt kontantmetoden i ett senare räkenskapsår
  flyttar golvet (NONOX räknar med den, för säkerhets skull).
- TODO(legal-verify): om ett uppladdat dokument som aldrig skickas kan gallras efter 90 dagar.
- TODO(legal-verify): om en signeringstjänst med BankID är biträde eller självständigt
  personuppgiftsansvarig.
- TODO(legal-verify): om svaret på kontrollsidan (företagets namn, antal undertecknare och
  kontrollsätt, till den som har filen) är personuppgifter för den som har filen, särskilt när
  företagsnamnet är en enskild näringsidkares namn, och om intresseavvägningen enligt artikel 6.1 f
  håller (företagets och mottagarens intresse av att kunna kontrollera ett dokument mot den
  registrerades intresse).
- TODO(legal-verify): om kontrollsidan är en tjänst för validering som är en betrodd tjänst enligt
  eIDAS artikel 3.16 i lydelsen enligt förordning (EU) 2024/1183 (se också artikel 3.41); verifiera
  punkten mot den konsoliderade texten.
- TODO(legal-verify): om en tjänst för underskrift (CSC API v2) som företaget själv har avtal med är
  företagets personuppgiftsbiträde eller självständigt personuppgiftsansvarig, och vad som gäller
  om driftbolaget sluter avtalet och tar betalt via NONOX Connect (underbiträde, eller förmedling).
- TODO(legal-verify): om meningarna i C3 räcker som information till undertecknaren (artikel 13 och 14),
  när tjänsten lägger ett personnummer i certifikatet och certifikatet följer med till alla mottagare.
