# Dataskydd (GDPR) i NONOX

Dokumentationen som dataskyddsförordningen kräver för den driftade tjänsten på nonox.app. Den som kör NONOX själv blir själv personuppgiftsansvarig (för sin egen verksamhet) eller personuppgiftsbiträde (för sina kunder) och behöver motsvarande dokument.

| Dokument | Krav |
|---|---|
| [Registerförteckning](registerforteckning.md) | Artikel 30 |
| [Konsekvensbedömning för lön](konsekvensbedomning-lon.md) | Artikel 35 |
| [Konsekvensbedömning för personalliggaren (mall för kunden)](konsekvensbedomning-personalliggare.md) | Artikel 35 och 28.3 f |
| [Konsekvensbedömning för inkorgen (kort mall)](konsekvensbedomning-inkorg.md) | Artikel 35 och 28.3 f |
| [Konsekvensbedömning för NONOX AI (kort mall)](konsekvensbedomning-ai.md) | Artikel 35 och 28.3 f |
| [Säkerhetskopiering](sakerhetskopiering.md) | Artikel 32.1 c och d |
| [Incidentrutin](incidentrutin.md) | Artikel 33 och 34 |
| [Lagringstider och gallring](lagringstider.md) | Artikel 5.1 e, bokföringslagen 7 kap. |
| [Integritetspolicy, villkor, PUB-avtal, underbiträden](../juridik/) | Artikel 13, 14 och 28 |
| [AI i NONOX](../juridik/ai.md), [regelverk](../juridik/regelverk.md), [frågor till jurist](../juridik/fragor-till-jurist.md) | Översikt och öppna frågor |

## Personnummer

Personnummer får behandlas utan samtycke bara när det är klart motiverat med hänsyn till ändamålet, vikten av en säker identifiering eller något annat beaktansvärt skäl (3 kap. 10 § lagen (2018:218) med kompletterande bestämmelser till EU:s dataskyddsförordning, dataskyddslagen). NONOX behandlar personnummer bara där en lagstadgad skyldighet kräver en säker identifiering:

| Var | Skyldighet |
|---|---|
| Lön och anställda | Arbetsgivardeklaration på individnivå (AGI) och skatteavdrag enligt skatteförfarandelagen (2011:1244) |
| Personalliggare | Identifiering av den som arbetar enligt 39 kap. skatteförfarandelagen |
| ROT- och RUT-avdrag på kundfakturor | Köparens personnummer i begäran om utbetalning till Skatteverket. TODO(legal-verify): exakt lagrum (lagen (2009:194) om förfarandet vid skattereduktion för hushållsarbete) och hur länge numret ska sparas. |
| Kunder och leverantörer som är enskilda näringsidkare | Organisationsnumret är ägarens personnummer. Det står på fakturor och i bokföringen enligt bokföringslagen och mervärdesskattelagen. |

Personnummer krypteras i databasen, maskeras i listor där det går, och visning i klartext i personalliggaren loggas.

TODO(legal-verify): att varje användning ovan är "klart motiverad" i lagens mening.

## Dataskyddsombud (artikel 37)

Stafe Development AB har inget dataskyddsombud. Ett ombud krävs bland annat när kärnverksamheten består av behandling i stor omfattning av särskilda kategorier av uppgifter (artikel 37.1 c). Som biträde behandlar tjänsten uppgifter om hälsa (sjukfrånvaro i lönen) åt många kunder. Kärnverksamheten är dock ett bokförings- och lönesystem, och omfattningen är i dag liten. Bedömningen är därför att ett ombud inte krävs ännu. Bedömningen ska göras om när antalet kunder med lön växer. Artikel 37.1 gäller både personuppgiftsansvariga och biträden.

TODO(legal-verify): om artikel 37.1 c gäller för ett biträde vars tjänst behandlar hälsouppgifter åt många arbetsgivare, och från vilken omfattning.

## Ansvar och kontakt

- **Incidentansvarig:** TODO(legal-verify): namn och kontaktväg, se [incidentrutinen](incidentrutin.md). Säkerhetsrapporter tas emot enligt [SECURITY.md](../../SECURITY.md).

## Vad systemet gör för de registrerades rättigheter

| Rättighet | I NONOX |
|---|---|
| Information (art. 13/14) | Integritetspolicyn på /integritetspolicy, länkad från inloggningen och varje sidfot. Anställda informeras på Min sida när AI läser kvitton. |
| Tillgång och portabilitet (art. 15/20) | Profil → Mina uppgifter (eget konto, JSON). Registerutdrag per kund, leverantör, anställd och person i personalliggaren för den som hanterar dem (JSON), och per avsändare till inkorgen (JSON eller CSV: datum, ämne, status och bilagornas namn för varje meddelande från adressen). Utdraget ur personalliggaren innehåller alla registreringar och rättelser om personen, men aldrig pinkod eller QR-nyckel; fotot beskrivs (sparat eller inte, när samtycket lämnades). Alla utdrag loggas. |
| Rättelse (art. 16) | Uppgifterna redigeras i registren. Bokförda verifikationer rättas med rättelseverifikation. |
| Radering (art. 17) | Eget konto: Profil → Radera mitt konto. Går inte så länge personen äger ett företag som inte är arkiverat. Medlemskap, inloggningar, koder, inbjudningar, passkeys, senast öppnade i sökningen, sparade vyer, notiser och profilbild tas bort. Kontot raderas helt om inget i bokföringen eller behandlingshistoriken hänvisar till det, annars pseudonymiseras det ("Raderad användare", ingen inloggning), så att det fortfarande syns att samma person gjorde det som bokfördes (art. 17.3 b, bokföringslagen 7 kap.). Kund och leverantör: raderas om de aldrig förekommit i bokföringen, annars anonymiseras registret och fakturorna behåller sina uppgifter. Anmälan till kön (ADR-038): den som anmält sig ber om radering genom att svara på bekräftelsemejlet; HQ raderar anmälan med alla svar under HQ → anmälan → Radera anmälan (passkey krävs, loggas utan personuppgifter). Nattlig gallring av det som saknar ändamål. |
| Begränsning och invändning (art. 18/21) | Hanteras manuellt: inaktivera posten och notera beslutet. TODO: en egen markering. |

## Tekniska och organisatoriska åtgärder (art. 32)

Se avsnitt 4 i [personuppgiftsbiträdesavtalet](../juridik/personuppgiftsbitradesavtal.md) och systemdokumentationen. I korthet:
- **Kryptering:** personnummer, bankuppgifter och känsliga fritexter krypteras.
- **Inloggning:** engångskod till e-post, med tak per adress. Den som kan se sjukfrånvaro, lönebesked eller fullständiga personnummer bekräftar dessutom med en passkey, och ägaren kan kräva passkey för alla i företaget (ADR-029).
- **Sessioner:** löper ut efter 12 timmar, och användaren kan logga ut från alla andra enheter.
- **Åtkomst:** behörigheter per roll. Känsliga löneuppgifter kräver en egen behörighet och läsning loggas. Ett bankbyte kräver en andra persons bekräftelse.
- **Spårbarhet:** behandlingshistorik som inte kan ändras.
- **Krypterad chatt (ADR-045):** det som skrivs krypteras i webbläsaren (AES-256-GCM med en nyckel som härleds ur koden och aldrig når servern), hålls bara krypterat i arbetsminnet medan chatten pågår och raderas direkt när chatten avslutas, efter 30 minuter utan meddelanden eller senast efter 8 timmar; en tillfällig kopia av arbetsminnet kan innehålla den krypterade texten tills den skrivs över. Varken innehållet eller chattens id sparas i databasen, behandlingshistoriken eller applikationens loggar. Webbserverns logg visar, som för alla sidor, tid, IP-adress och att chatten anropades.
- **Gränssnitt:** säkerhetsrubriker på varje svar, och varje sida är låst till sitt företag.
- **Filer:** krypteras i vila med applikationsnyckeln (`EncryptedAdapter`).
- **Säkerhetskopior:** varje natt, krypterade (AES-256), bara i Sverige, sparas 7 dagar. Två gånger per dygn förs dessutom en krypterad kopia till en annan plats i Sverige. Varje vecka återställs den senaste kopian i en tillfällig databas och kontrolleras automatiskt, se [säkerhetskopiering](sakerhetskopiering.md#återställningstest).

## Egen drift

Kör ett företag NONOX på en egen server är företaget självt personuppgiftsansvarigt för allt i systemet, och Stafe Development AB är inte personuppgiftsbiträde. Då gäller inte personuppgiftsbiträdesavtalet eller listan över underbiträden på nonox.app. Det här behöver den som driftar själv ta hand om:

- **Server och plats:** välj en server inom EU/EES och teckna personuppgiftsbiträdesavtal med leverantören.
- **E-post:** inloggningskoder och fakturor skickas via den e-posttjänst som anges i `.env`. Leverantören blir ett underbiträde.
- **Säkerhetskopior:** kryptera dem, lagra dem inom EU/EES, och bestäm hur länge de sparas. Se lagringstiderna i [lagringstider.md](lagringstider.md). Testa att de går att återställa med `scripts/restore-test.sh`, se [säkerhetskopiering](sakerhetskopiering.md#så-testar-du-din-egen-installation).
- **Krypteringsnyckeln** (`APP_KEY`) skyddar personnummer, bankuppgifter och sparade filer. Förvara en kopia säkert. Utan den går uppgifterna inte att läsa, inte heller i en säkerhetskopia.
- **AI-modulen** är avstängd tills företaget slår på den med en egen nyckel. Leverantören kan finnas utanför EU/EES.
- **Gallring:** schemalägg `php artisan schedule:run` så att `privacy:prune` körs varje natt.
- **Uppdateringar:** installera nya versioner, eftersom de kan innehålla säkerhetsrättningar.

