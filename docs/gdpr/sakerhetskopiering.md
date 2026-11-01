# Säkerhetskopiering

Senast uppdaterad 2026-11-01. Gäller den driftade tjänsten på nonox.app. Den som kör NONOX själv ansvarar för sina egna säkerhetskopior (se [README](README.md#egen-drift)).

Krav: förmågan att återställa tillgängligheten och tillgången till personuppgifter i rimlig tid vid en incident, och regelbunden testning av åtgärderna (artikel 32.1 c och d). Räkenskapsinformation ska bevaras i sju år och i ett varaktigt och läsbart skick (bokföringslagen 7 kap. 1–2 §§).

## Så ser det ut i dag

| | |
|---|---|
| **Vad** | Hela databasen (PostgreSQL, `pg_dump`) och de privata filerna: underlag, kvitton, lönebesked, inkorgens meddelanden och arkivpaket. |
| **När** | Varje natt. |
| **Kryptering** | Kopiorna krypteras (AES-256) innan de sparas. Filerna är dessutom redan krypterade i vila med applikationsnyckeln, liksom personnummer och bankuppgifter i databasen. |
| **Var** | På tjänstens server, och dessutom på en annan plats, se nedan. Båda i Sverige. |
| **Hur länge** | 7 dagar. Äldre kopior raderas automatiskt varje natt. En uppgift som raderas i tjänsten finns alltså kvar i kopiorna i högst 7 dagar. |
| **Kopia på annan plats** | Två gånger per dygn förs en kopia över till en krypterad dator som Stafe själv äger och sköter, på en annan ort i Sverige. Platsen anges inte offentligt. Ingen annan leverantör är inblandad. |
| **Återställningstest** | **Inte dokumenterat ännu.** |

### Vad det betyder

- Kopiorna skyddar mot fel i programmet, misstag och en skadad databas.
- Kopian på den andra platsen skyddar också mot att servern eller serverleverantören går förlorad. Mellan två överföringar kan som mest ungefär ett halvt dygns ändringar gå förlorade.
- Kopiorna på tjänstens server skyddar inte mot ett intrång där angriparen kommer åt både tjänsten och kopiorna; kopian på den andra platsen gör det bara om servern inte kan radera eller skriva över den. TODO: dokumentera hur länge kopiorna sparas på den andra platsen och att tjänstens server inte kan radera dem.
- Utan applikationsnyckeln går krypterade fält och filer inte att läsa, inte heller efter en återställning. Nyckeln måste därför förvaras lika säkert som kopiorna, och på en annan plats än dem.
- Kunden kan själv när som helst ladda ner SIE4 och ett arkivpaket per räkenskapsår. Vi rekommenderar att kunden gör det efter varje bokslut.

## Förbättringar

| Åtgärd | Status |
|---|---|
| En krypterad kopia på en annan plats i Sverige | **Genomförd**, två gånger per dygn till en dator i Stafes egen regi |
| Återställningstest varje kvartal: en kopia återställs på en separat server, `ledger:verify` och `personalliggare:verify` körs, och resultat, tidsåtgång och avvikelser dokumenteras | Planerad |
| Skriftlig rutin för återställning: vem som gör vad, i vilken ordning och hur kunderna informeras | Planerad |
| Mål för hur mycket data som högst får gå förlorad (RPO) och hur snabbt tjänsten ska vara igång igen (RTO) | Inte beslutat |

## Äldre kopior

TODO(legal-verify): äldre säkerhetskopior från tiden före den nuvarande lösningen kan finnas kvar hos en molnlagringstjänst utanför tjänstens server. Det ska utredas vilka kopior som finns, var de lagras, om de innehåller kunduppgifter, om de ska raderas, och om förekomsten ska dokumenteras som en incident (artikel 33.5). Se [frågor till jurist](../juridik/fragor-till-jurist.md).
