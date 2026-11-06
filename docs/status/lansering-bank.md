# Lansering: Bank (öppen bankdata, bara läsning)

Modulen Bank ([ADR-034](../adr/ADR-034-nonox-banking.md), tillägg 2026-11-06) är byggd och vilande. På nonox.app finns ingen leverantör inställd, så modulen går inte att slå på. Den här sidan beskriver exakt vad som måste vara på plats innan den slås på, hur den slås på och kontrolleras, och hur den stängs av i en nödsituation.

NONOX kan bara **läsa** saldon och transaktioner. Betalningar görs fortfarande med betalfiler som signeras i banken ([ADR-014](../adr/ADR-014-payment-files.md)). Betalningsinitiering är inte byggd; alternativen finns i [ADR-055](../adr/ADR-055-payment-initiation.md) (förslag).

## 1. Det som en människa måste göra först

1. **Juridiska frågor besvarade skriftligt** (se avsnitt 6). Utan svar på fråga 30 och 31 i [frågor till jurist](../juridik/fragor-till-jurist.md) erbjuds modulen inte i den driftade tjänsten.
2. **Avtal med Enable Banking Oy** (leverantör av kontoinformationstjänst, Finland). Avtalet tecknas av den som driftar installationen (för nonox.app: Stafe eller det bolag som enligt [externa avtal](manskliga-beslut.md) tecknar för tjänsten). Avtalet ska omfatta kontoinformation (AIS) för svenska banker, inte betalningar.
3. **Personuppgiftsbiträdesavtal (DPA)** med Enable Banking, och besked om var uppgifterna behandlas. Lägg sedan in Enable Banking i tabellen i `docs/juridik/underbitraden.md` (raden finns, ta bort TODO-kommentaren), uppdatera `docs/gdpr/registerforteckning.md` och höj `LegalPageController::VERSION` och `EFFECTIVE` (en höjning per driftsättning; kunderna får besked enligt villkoren).
4. **Registrera applikationen** i Enable Bankings kontrollpanel: först i sandlådan, sedan i produktion. Ange omdirigeringsadressen `APP_URL/bank/klar` (för nonox.app: `https://nonox.app/bank/klar`). Ladda ner applikationens privata nyckel (`.pem`).
5. **Prova i Enable Bankings sandlåda** med de punkter som ADR-034 lämnar öppna (avsnitt 5) och anteckna resultatet i ADR-034.

## 2. Nycklar och miljövariabler

Sätts i serverns `.env` (via Forge, aldrig i repot):

| Variabel | Värde | Kommentar |
|---|---|---|
| `NONOX_BANKING_DRIVER` | `enable_banking` | Tom betyder låtsasbanken lokalt och Enable Banking i övrigt. Låtsasbanken (`fake`) vägras i produktion. |
| `ENABLE_BANKING_APPLICATION_ID` | applikationens id (uuid) | Från Enable Bankings kontrollpanel. Skickas som `kid` i JWT:n. |
| `ENABLE_BANKING_PRIVATE_KEY_PATH` | absolut sökväg till `.pem` | Utanför repot och webbroten, läsbar bara för webbserverns användare (t.ex. `/home/forge/.keys/enable-banking.pem`, `chmod 600`). |
| `ENABLE_BANKING_URL` | `https://api.enablebanking.com` | Standard. Sandlådan använder samma adress med en sandlådeapplikation. |
| `NONOX_BANKING_PAUSED` | tom | Nödstoppet, se avsnitt 4. |

Efter ändringen: `php artisan config:cache` på servern (artisan är tillåtet där, composer och npm är det inte).

Schemaläggaren måste köra (`schedule:run` varje minut): `bank:fetch` varje timme (:17) och `bank:remind-consent` dagligen 07:10. Kön måste köra (`FetchBankDataJob`).

## 3. Checklista för att slå på och kontrollera

1. `php artisan integrations:status` visar under BANKING `driver enable_banking`, `configured yes`, `paused no`. Samma sak syns i /hq → **Integrationer**.
2. Logga in som ägare i ett **eget testföretag** (inte en kund). Slå på **Bank** under **Inställningar för företaget → Moduler**. Går det inte: läs blockeringstexten (inte inställd, eller tillfälligt avstängd).
3. **Bank → Koppla en bank**: listan med banker ska visa svenska banker. Välj en bank där du har ett eget företagskonto.
4. Godkänn med BankID. Tillbaka i NONOX ska det stå *… är kopplad. Välj vilket konto i bokföringen varje bankkonto hör till, så hämtas transaktionerna.*
5. Koppla ett bankkonto till 1930 och tryck **Hämta nu**. Kontrollera att transaktionerna och saldot stämmer mot internetbanken, och att en andra hämtning inte ger några nya.
6. Kontrollera i loggen (`storage/logs`) att inga sessions-id, IBAN eller bankens svar har skrivits. Kontrollera behandlingshistoriken (`banking.connected`, `banking.fetched`).
7. **Koppla bort** banken och kontrollera i internetbanken att medgivandet är avslutat.
8. Vänta en timme och kör `php artisan integrations:status` igen: inga problem.
9. Först därefter: slå på för en pilotkund, och uppdatera de publika sidorna (görs av spåret för publika sidor, inte här).

## 4. Nödstopp (kill switch)

Sätt i `.env` och kör `php artisan config:cache`:

```
NONOX_BANKING_PAUSED=true            # pausar alla leverantörer
NONOX_BANKING_PAUSED=enable_banking  # pausar bara Enable Banking
```

Nycklarna ligger kvar. Medan pausen gäller:

- ingenting skickas till leverantören: `bank:fetch` köar inga hämtningar, köade hämtningar gör ingenting, ingen koppling kan startas eller slutföras;
- sidan **Bank** visar *Bankkopplingen är tillfälligt avstängd av den som driftar NONOX. …* och döljer **Koppla en bank**, **Hämta nu** och **Koppla igen med BankID**;
- modulen kan inte slås på för fler företag;
- bortkoppling fungerar, men bara i NONOX (leverantören kontaktas inte; bankens medgivande löper ut av sig självt);
- påminnelser om medgivanden skickas inte, men skickas när pausen tas bort;
- medgivanden som har passerat slutdatumet markeras ändå som utgångna (lokalt);
- matchning, bankavstämning och redan hämtade data påverkas inte.

Slå på igen: ta bort värdet (`NONOX_BANKING_PAUSED=`) och kör `php artisan config:cache`. Hämtningen fortsätter vid nästa timme och tar igen de missade dagarna (fem dagars överlapp, inga dubbletter).

## 5. Driftstörningar och övervakning

- En hämtning som misslyckas (ingen kontakt, tidsgräns, 429, 5xx) försöks igen efter 15 minuter, 1, 4, 12 timmar och sedan en gång per dygn. Andra fel (4xx) räknas också, men jobbet släpps inte tillbaka i kön.
- Efter tre fel i rad ser företagets ansvariga **Hämtningen från … fungerar inte** under **Att göra**.
- `php artisan integrations:status` avslutas med felkod 1 när något behöver åtgärdas (`--json` för maskinläsning). Lägg den gärna i övervakningen.
- Loggrad vid fel: `Banking: a fetch failed.` med företags-id, kopplings-id, leverantör, om felet går att försöka igen och antal fel i rad. Inga personuppgifter.

Att prova i sandlådan innan produktion (öppet i ADR-034):

- `TODO(provider-verify)`: omdirigeringens parametrar (`code`, `state`, `error`), felkropparnas exakta format och sessionsstatusarna.
- `TODO(provider-verify)`: Handelsbankens företagskonton (fullmakt "API Corporate", eventuellt PSU-Corporate-ID som NONOX inte skickar).
- `TODO(provider-verify)`: Länsförsäkringar kräver personnummer (`psu_id`) före godkännandet; NONOX skickar det inte.
- `TODO(provider-verify)`: om ett anrop som Enable Banking besvarar med 5xx ändå räknas mot bankens gräns på fyra hämtningar per dygn utan användaren närvarande.

## 6. Öppna juridiska frågor

Från ADR-034 och [frågor till jurist](../juridik/fragor-till-jurist.md):

- `TODO(legal-verify)` (fråga 30): måste den som driftar tjänsten och har avtalet med Enable Banking registreras hos Finansinspektionen som ombud, eller själv som kontoinformationstjänst enligt lag (2010:751) om betaltjänster, eller är företaget leverantörens direkta kund?
- `TODO(legal-verify)` (fråga 31): Enable Banking som underbiträde eller företagets egen leverantör, DPA och eventuell överföring utanför EU/EES.
- `TODO(legal-verify)` (fråga 31): är transaktioner som hämtats genom kontoinformation räkenskapsinformation i sig, och ska de då med i arkivpaketet (`export:archive`)? De tas inte med i dag.
- Särskilt för enskild firma med privatkonto: transaktionerna är personuppgifter om ägaren och motparterna.

## 7. Kända begränsningar

- Konton i annan valuta än företagets kan inte kopplas till bokföringen och hämtas därför inte.
- Hämtade transaktioner ingår inte i arkivpaketet än (väntar på fråga 31).
- Betalningar görs inte från NONOX (ADR-055).
