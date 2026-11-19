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
> IP-adress och webbläsare, hur undertecknaren kontrollerades (bara kontrollsummor av koder),
> händelserna i en hashkedja, ett eventuellt skäl att avböja, och dokumentet med dess versioner
> och kontrollsummor. Ett dokument som den personuppgiftsansvarige laddar upp kan innehålla
> vilka uppgifter som helst; den personuppgiftsansvarige bestämmer innehållet och ändamålet.

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

## D. Underbiträden

Ingen ändring i version 1.9. En tidsstämpeltjänst (lucka 5) får bara en kontrollsumma av
dokumentet och inga personuppgifter. En signeringstjänst som får undertecknarens identitet
(lucka 12, till exempel BankID) ska namnges i `docs/juridik/underbitraden.md` innan den slås på.

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
