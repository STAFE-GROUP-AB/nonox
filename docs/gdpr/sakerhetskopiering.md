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
| **Återställningstest** | Varje vecka (söndag 04:40) återställs den senaste kopian automatiskt i en tillfällig databas och kontrolleras, se nedan. Första testet gjordes 2026-11-01 och godkändes. |

### Vad det betyder

- Kopiorna skyddar mot fel i programmet, misstag och en skadad databas.
- Kopian på den andra platsen skyddar också mot att servern eller serverleverantören går förlorad. Mellan två överföringar kan som mest ungefär ett halvt dygns ändringar gå förlorade.
- Kopiorna på tjänstens server skyddar inte mot ett intrång där angriparen kommer åt både tjänsten och kopiorna; kopian på den andra platsen gör det bara om servern inte kan radera eller skriva över den. TODO: dokumentera hur länge kopiorna sparas på den andra platsen och att tjänstens server inte kan radera dem.
- Utan applikationsnyckeln går krypterade fält och filer inte att läsa, inte heller efter en återställning. Nyckeln måste därför förvaras lika säkert som kopiorna, och på en annan plats än dem.
- Kunden kan själv när som helst ladda ner SIE4 och ett arkivpaket per räkenskapsår. Vi rekommenderar att kunden gör det efter varje bokslut.

## Återställningstest

Ett återställningstest visar att kopian går att läsa tillbaka, inte bara att den finns. Skriptet `scripts/restore-test.sh` gör det så här:

1. Den senaste kopian av databasen och filerna dekrypteras till en tillfällig katalog som bara tjänsten kan läsa.
2. Databasen återställs med `pg_restore` i ett nytt, tillfälligt PostgreSQL-kluster. Klustret tar bara emot anslutningar via en lokal socket. Den riktiga databasen och de riktiga filerna rörs inte.
3. `php artisan backup:verify-restore` kontrollerar kopian:
   - hur många rader de viktigaste tabellerna har, och hur många migreringar kopian ligger efter koden;
   - bokföringen i varje företag, på samma sätt som `ledger:verify`: hashkedjan, att varje verifikation balanserar och att numreringen saknar luckor;
   - att varje underlag till en verifikation finns och har samma kontrollsumma (SHA-256) som när det laddades upp. Filen läses med applikationsnyckeln, så testet visar också att nyckeln fungerar;
   - personalliggarens hashkedja per lokal, på samma sätt som `personalliggare:verify`;
   - att filen till varje leverantörsfaktura finns.
4. Klustret stoppas och allt tillfälligt raderas, också om testet avbryts.

Testet är underkänt om något saknas, har ändrats eller inte går att läsa, och om kopian inte innehåller några företag. Resultatet skrivs som en rad i en logg på servern, med datum, resultat, vilka kopior som testades och hur lång tid det tog.

### Utförda tester

| Datum | Kopia | Resultat | Tid |
|---|---|---|---|
| 2026-11-01 | Nattens kopia 2026-11-01 03:20 | Godkänd: ett företag, kedjorna och filerna intakta | 2 sekunder |

Testerna efter detta loggas automatiskt på servern. Den här tabellen uppdateras när något avviker, och minst en gång per kvartal.

### Så testar du din egen installation

Kör du NONOX själv fungerar samma skript på din server, med PostgreSQL och `gpg` installerade:

```
NONOX_BACKUP_PASSPHRASE_FILE=/sökväg/till/lösenfras \
  scripts/restore-test.sh nonox-db.dump.gpg nonox-files.tar.gz.gpg
```

Kopian av databasen ska vara gjord med `pg_dump -Fc` och kopian av filerna ska vara ett tar.gz-arkiv av `storage/app/private`. Båda får vara okrypterade eller krypterade med `gpg`. Anger du en katalog i stället för två filer testas det senaste paret i den. Skriptet behöver köras med samma `APP_KEY` som installationen, annars går filerna inte att läsa.

## Förbättringar

| Åtgärd | Status |
|---|---|
| En krypterad kopia på en annan plats i Sverige | **Genomförd**, två gånger per dygn till en dator i Stafes egen regi |
| Återställningstest: en kopia återställs i en tillfällig databas, bokföringen, personalliggaren och underlagen kontrolleras, och resultat och tidsåtgång loggas | **Genomförd**, varje vecka sedan 2026-11-01 |
| Återställningstest på en helt separat server, så att också en förlorad server är prövad | Planerad |
| Skriftlig rutin för återställning: vem som gör vad, i vilken ordning och hur kunderna informeras | Planerad |
| Mål för hur mycket data som högst får gå förlorad (RPO) och hur snabbt tjänsten ska vara igång igen (RTO) | Inte beslutat |

## Äldre kopior

TODO(legal-verify): äldre säkerhetskopior från tiden före den nuvarande lösningen kan finnas kvar hos en molnlagringstjänst utanför tjänstens server. Det ska utredas vilka kopior som finns, var de lagras, om de innehåller kunduppgifter, om de ska raderas, och om förekomsten ska dokumenteras som en incident (artikel 33.5). Se [frågor till jurist](../juridik/fragor-till-jurist.md).
