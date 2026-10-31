# Konsekvensbedömning: lönemodulen (artikel 35)

Senast uppdaterad 2026-11-01. TODO(legal-verify): ska granskas och fastställas av personuppgiftsansvarig och jurist.

## Behandlingen

Lönemodulen behandlar för varje kunds räkning anställdas personnummer, bankkonton, löner, skatt, förmåner, utlägg med kvitton och frånvaro. Sjukfrånvaro och vård av barn är uppgifter om hälsa (art. 9); medlemskap i Svenska kyrkan kan avslöja religiös övertygelse. Behandlingen sker i stor skala över många kunder, varför en konsekvensbedömning görs.

## Nödvändighet och proportionalitet

Uppgifterna krävs för att beräkna lön, skatt, arbetsgivaravgifter, sjuklön (sjuklönelagen) och semester, och för arbetsgivardeklarationen till Skatteverket (art. 9.2 b, rättslig förpliktelse). Inga uppgifter samlas in utöver det lönen kräver. Kyrkomedlemskap används bara för skatteberäkningen.

## Risker och åtgärder

| Risk | Åtgärd i systemet |
|---|---|
| Obehörig läser hälsouppgifter | Frånvaroorsak och anteckningar visas bara med behörigheten payroll:view-sensitive, som ägaren inte har som standard. Varje läsning loggas. E-post till personalen säger inte vad frånvaron gäller. |
| Lön betalas till fel konto (bedrägeri) | Byte av bankkonto kräver en andra persons bekräftelse; ingen lönefil skapas innan; ändringen loggas maskerad. |
| Läckage via webbläsaren | Personnummer och bankkonton skickas inte till sidans tillstånd; varje sida är låst till sitt företag. |
| Läckage i databasen | Personnummer, bankuppgifter, anteckningar och skäl krypteras. |
| Uppgifter till tredje land | AI-läsning av kvitton är avstängd tills företaget uttryckligen väljer den, och de anställda informeras på Min sida. |
| Uppgifter sparas för länge | Avslagna ansökningar gallras efter ett år; övrigt enligt bokföringslagen (se lagringstider). |
| Felkoppling av inloggning | Inloggning kopplas till ett personalkort bara för medlemmar med rollen anställd. |

## Kvarstående risk

Filer (lönebesked, AGI-filer, kvitton) lagras okrypterade på serverns privata disk. Åtgärd: diskkryptering på servern eller kryptering av filerna (TODO). Revisorer kan se enskilda lönebesked; beslut om det ska begränsas (TODO, ADR).
