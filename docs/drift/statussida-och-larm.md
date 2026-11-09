# Statussidan och larm

För den som driftar NONOX. Se [ADR-078](../adr/ADR-078-status-page-and-security-policy.md).

## Larm som måste kopplas på den driftade tjänsten

| Vad | Hur det ser ut | Vart det ska gå | Läge |
|---|---|---|---|
| Den nattliga bokföringskontrollen hittar avvikelser | `ledger:verify --all` avslutas med kod 3 och skriver `Log::error` ("ledger:verify hittade avvikelser i bokföringen", bara företagens id). Den publika statussidan visar bara att kontrollen har körts. | Mejl eller Nightwatch till den driftansvarige. Utan det vet ingen om avvikelsen förrän någon tittar i HQ → Driftstatus. | TODO: koppla loggkanalen (`LOG_STACK`/Nightwatch) på den driftade tjänsten |
| Kontrollen kraschar | Annan avslutskod än 0 och 3; statussidan visar "kunde inte genomföras" | Samma kanal | TODO |
| Säkerhetskopia och återställningstest | `php artisan status:heartbeat backup` efter varje kopia, och `NONOX_STATUS_HEARTBEAT=1` på det veckovisa testet | Statussidan visar raderna först när skripten rapporterar | TODO: lägg in anropen |

## Rutiner

- Skriv en händelse i HQ → Driftstatus när något är fel som de automatiska kontrollerna inte ser.
- Bekräfta säkerhetskontakten i HQ → Inställningar minst en gång om året (security.txt gäller i 364 dagar efter bekräftelsen).
