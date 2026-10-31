# Dataskydd (GDPR) i NONOX

Dokumentationen som dataskyddsförordningen kräver för den driftade tjänsten på nonox.app. Den som kör NONOX själv blir själv personuppgiftsansvarig (för sin egen verksamhet) eller personuppgiftsbiträde (för sina kunder) och behöver motsvarande dokument.

| Dokument | Krav |
|---|---|
| [Registerförteckning](registerforteckning.md) | Artikel 30 |
| [Konsekvensbedömning för lön](konsekvensbedomning-lon.md) | Artikel 35 |
| [Incidentrutin](incidentrutin.md) | Artikel 33 och 34 |
| [Lagringstider och gallring](lagringstider.md) | Artikel 5.1 e, bokföringslagen 7 kap. |
| [Integritetspolicy, villkor, PUB-avtal, underbiträden](../juridik/) | Artikel 13, 14 och 28 |

## Vad systemet gör för de registrerades rättigheter

| Rättighet | I NONOX |
|---|---|
| Information (art. 13/14) | Integritetspolicyn på /integritetspolicy, länkad från inloggningen och varje sidfot. Anställda informeras på Min sida när AI läser kvitton. |
| Tillgång och portabilitet (art. 15/20) | Profil → Mina uppgifter (eget konto, JSON). Registerutdrag per kund, leverantör och anställd för den som hanterar dem. Alla utdrag loggas. |
| Rättelse (art. 16) | Uppgifterna redigeras i registren. Bokförda verifikationer rättas med rättelseverifikation. |
| Radering (art. 17) | Kund och leverantör: raderas om de aldrig förekommit i bokföringen, annars anonymiseras registret och fakturorna behåller sina uppgifter (art. 17.3 b, bokföringslagen). Nattlig gallring av det som saknar ändamål. |
| Begränsning och invändning (art. 18/21) | Hanteras manuellt: inaktivera posten och notera beslutet. TODO: en egen markering. |

## Tekniska och organisatoriska åtgärder (art. 32)

Se avsnitt 4 i [personuppgiftsbiträdesavtalet](../juridik/personuppgiftsbitradesavtal.md) och systemdokumentationen. I korthet:
- **Kryptering:** personnummer, bankuppgifter och känsliga fritexter krypteras.
- **Inloggning:** engångskod till e-post, med tak per adress.
- **Sessioner:** löper ut efter 12 timmar, och användaren kan logga ut från alla andra enheter.
- **Åtkomst:** behörigheter per roll. Känsliga löneuppgifter kräver en egen behörighet och läsning loggas. Ett bankbyte kräver en andra persons bekräftelse.
- **Spårbarhet:** behandlingshistorik som inte kan ändras.
- **Gränssnitt:** säkerhetsrubriker på varje svar, och varje sida är låst till sitt företag.
- **Säkerhetskopior:** varje natt, krypterade (AES-256), bara i Sverige, sparas 7 dagar.

## Egen drift

Kör ett företag NONOX på en egen server är företaget självt personuppgiftsansvarigt för allt i systemet, och Stafe Development AB är inte personuppgiftsbiträde. Då gäller inte personuppgiftsbiträdesavtalet eller listan över underbiträden på nonox.app. Det här behöver den som driftar själv ta hand om:

- **Server och plats:** välj en server inom EU/EES och teckna personuppgiftsbiträdesavtal med leverantören.
- **E-post:** inloggningskoder och fakturor skickas via den e-posttjänst som anges i `.env`. Leverantören blir ett underbiträde.
- **Säkerhetskopior:** kryptera dem, lagra dem inom EU/EES, och bestäm hur länge de sparas. Se lagringstiderna i [lagringstider.md](lagringstider.md).
- **Krypteringsnyckeln** (`APP_KEY`) skyddar personnummer, bankuppgifter och sparade filer. Förvara en kopia säkert. Utan den går uppgifterna inte att läsa, inte heller i en säkerhetskopia.
- **AI-modulen** är avstängd tills företaget slår på den med en egen nyckel. Leverantören kan finnas utanför EU/EES.
- **Gallring:** schemalägg `php artisan schedule:run` så att `privacy:prune` körs varje natt.
- **Uppdateringar:** installera nya versioner, eftersom de kan innehålla säkerhetsrättningar.

