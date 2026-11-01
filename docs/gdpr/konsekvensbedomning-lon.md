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
| Läckage i databasen och på disken | Personnummer, bankuppgifter, anteckningar och skäl krypteras i databasen. Filer krypteras i vila. |
| Uppgifter till tredje land | AI-läsning av kvitton är avstängd tills företaget uttryckligen väljer den, och de anställda informeras på Min sida. |
| Uppgifter sparas för länge | Avslagna ansökningar gallras efter ett år; övrigt enligt bokföringslagen (se lagringstider). |
| Felkoppling av inloggning | Inloggning kopplas till ett personalkort bara för medlemmar med rollen anställd. |

## Kvarstående risk

- **Filer** (lönebesked, AGI-filer, lönefiler, kvitton) krypteras i vila på serverns privata disk. Det görs med applikationsnyckeln (AES-256-CBC med MAC, `App\Support\Files\EncryptedAdapter`, standard enligt `config/filesystems.php`). Äldre okrypterade filer krypteras med `php artisan files:encrypt`. Det som återstår:
  - Livewires tillfälliga uppladdningar ligger okrypterade på disken tills Livewire raderar dem efter 24 timmar. Den sparade kopian är krypterad.
  - Den som kör NONOX själv kan stänga av krypteringen (`PRIVATE_FILES_DRIVER=local`) och ansvarar då för diskkryptering.
  - Förloras applikationsnyckeln går filerna inte att läsa, inte heller i en säkerhetskopia.
- **Revisorer** kan läsa enskilda lönebesked men inte frånvaroregistret, och varje läsning loggas. Förslaget finns i [ADR-021](../adr/ADR-021-auditor-access-to-payslips.md), som ännu inte är godkänt. TODO(legal-verify): om revisorns läsning kräver en egen rättslig grund.
- **Fackföreningsavgift.** Lönemodulen har ingen löneart för fackavgift. Görs avdraget som nettolöneavdrag eller skrivs i fritext behandlas uppgift om fackligt medlemskap (artikel 9.1) utan särskilt skydd. TODO(legal-verify).
- **AI-läsning av kvitton:** se [konsekvensbedömningen för AI](konsekvensbedomning-ai.md), särskilt AI:ns anteckningar om kvitton som ser privata ut.
