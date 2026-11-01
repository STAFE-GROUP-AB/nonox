# Säkerhetskopiering

Senast uppdaterad 2026-11-01. Gäller den driftade tjänsten på nonox.app. Den som kör NONOX själv ansvarar för sina egna säkerhetskopior (se [README](README.md#egen-drift)).

Krav: förmågan att återställa tillgängligheten och tillgången till personuppgifter i rimlig tid vid en incident, och regelbunden testning av åtgärderna (artikel 32.1 c och d). Räkenskapsinformation ska bevaras i sju år och i ett varaktigt och läsbart skick (bokföringslagen 7 kap. 1–2 §§).

## Så ser det ut i dag

| | |
|---|---|
| **Vad** | Hela databasen (PostgreSQL, `pg_dump`) och de privata filerna: underlag, kvitton, lönebesked, inkorgens meddelanden och arkivpaket. |
| **När** | Varje natt. |
| **Kryptering** | Kopiorna krypteras (AES-256) innan de sparas. Filerna är dessutom redan krypterade i vila med applikationsnyckeln, liksom personnummer och bankuppgifter i databasen. |
| **Var** | På samma server som tjänsten, i Sverige. |
| **Hur länge** | 7 dagar. Äldre kopior raderas automatiskt varje natt. En uppgift som raderas i tjänsten finns alltså kvar i kopiorna i högst 7 dagar. |
| **Kopia på annan plats** | **Finns inte ännu.** |
| **Återställningstest** | **Inte dokumenterat ännu.** |

### Vad det betyder

- Kopiorna skyddar mot fel i programmet, misstag och en skadad databas.
- **De skyddar inte** mot att servern går förlorad, mot ett fel hos serverleverantören eller mot ett intrång där angriparen kommer åt både tjänsten och kopiorna. Då kan data gå förlorade.
- Kopiorna är krypterade. Eftersom de ligger på samma server skyddar krypteringen inte mot ett intrång som når både kopiorna och nycklarna.
- Utan applikationsnyckeln går krypterade fält och filer inte att läsa, inte heller efter en återställning. Nyckeln måste därför förvaras lika säkert som kopiorna, och på en annan plats än dem.
- Kunden kan själv när som helst ladda ner SIE4 och ett arkivpaket per räkenskapsår. Det är i dag kundens enda kopia utanför vår server, och vi rekommenderar att kunden gör det efter varje bokslut.

## Planerad förbättring (inte genomförd)

| Åtgärd | Status |
|---|---|
| En krypterad kopia varje natt hos en annan leverantör, i Sverige eller inom EU/EES, som tjänstens server inte kan radera eller skriva över | Planerad |
| Leverantören läggs till på [underbiträdessidan](../juridik/underbitraden.md), med 30 dagars förvarning enligt personuppgiftsbiträdesavtalet | Planerad, görs innan kopian tas i bruk |
| Återställningstest varje kvartal: en kopia återställs på en separat server, `ledger:verify` och `personalliggare:verify` körs, och resultat, tidsåtgång och avvikelser dokumenteras | Planerad |
| Skriftlig rutin för återställning: vem som gör vad, i vilken ordning och hur kunderna informeras | Planerad |
| Mål för hur mycket data som högst får gå förlorad (RPO) och hur snabbt tjänsten ska vara igång igen (RTO) | Inte beslutat |

## Äldre kopior

TODO(legal-verify): äldre säkerhetskopior från tiden före den nuvarande lösningen kan finnas kvar hos en molnlagringstjänst utanför tjänstens server. Det ska utredas vilka kopior som finns, var de lagras, om de innehåller kunduppgifter, om de ska raderas, och om förekomsten ska dokumenteras som en incident (artikel 33.5). Se [frågor till jurist](../juridik/fragor-till-jurist.md).
