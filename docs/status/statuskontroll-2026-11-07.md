# Statuskontroll 2026-11-07

Spår A, sanningsrevisorn. Underlag: grenen `spec/track-a/2026-11-07`, som utgår från `74089da4` och därmed innehåller `7ce7f858` (driftsatt 2026-11-06). Specen (`8bba0bff`, `docs/specs/nummer-1-spec.md`) ligger i en annan gren och lästes ur den commit:en.

Jämförelsen gäller `docs/status/nummer-1.md`: tabellen "Statuskontroll 2026-11-06" och varje paket under den. Varje rad jämförs med koden, inte med vad filen själv säger.

## 1. Hur kontrollen gjordes

- Testfilerna som paketen hänvisar till finns alla. Jag körde dem en och en mot en egen databas (`nonox_test_truth`, `PGTZ=UTC`, inga filer med `RefreshDatabase`). Antalet gröna tester står i tabellen i avsnitt 3.
- En samlad körning av katalogerna Vat, Group, IncomeTax, AnnualReport, Closing, Skatteverket, TaxAccount, Connect, Agreements, Banking, Peppol, Hq, Company, Tenancy och Unit/Planning gav 3206 gröna, 69 röda, 1 hoppad. **Alla 69 röda är miljöfel i min arbetskopia:** `public/build/manifest.json` saknas, så varje test som ritar en sida ger 500 (`ViteManifestNotFoundException`). De är inte fel i koden. GitHub Actions är grönt på `main` för `74089da4` (körning 42315511320) och för `7ce7f858` (42254725440).
- Testet av iXBRL mot Bolagsverkets taxonomi hoppas över lokalt (`BOLAGSVERKET_TAXONOMY_DIR` saknas). CI kör det alltid. Arelle-körningen (E.3) har jag inte kunnat köra, bara läst jobbet i `.github/workflows/ci.yml` och skripten `scripts/fetch-xbrl-taxonomy.sh` och `scripts/validate-ixbrl-arelle.sh`.
- Vad som körs på servern kan jag inte se härifrån. Allt som gäller driftsättning (testnycklar, pilotanvändare, att Moms-API är påslaget) bygger på koden och på commit-historiken.

## 2. Tabellen "Statuskontroll 2026-11-06" mot koden

| Rad i tabellen | Vad filen säger | Vad koden säger | Verdikt |
|---|---|---|---|
| NE-bilaga, INK4 | Levererad, ADR-053 står som Proposed. "Stämmer". | ADR-053 har status **Accepted** sedan 2026-11-05/06 (b3ddab0b). Raden är gammal. Dessutom är NE-bilagan **spärrad** sedan 40e6a725: `database/data/income-tax/2026.json` har `verified: false`, och `BusinessIncomeTaxReturns.php:233` stoppar nedladdningen av SRU-filen när parametrarna saknas. Det finns ingen fil för något annat år än 2026 och 2027 (båda overifierade), så NE-filen kan i dag inte laddas ner för något år. INK4 är inte spärrad av parametrar, bara av kodperioden `2026P4`, som är kontrollerad. | **Fel i filen.** Raden ska säga Accepted och nämna spärren. |
| Moms-API | Steg 1, testtjänst och pilotanvändare. Driftsatt (112ade38) med testnycklar och `SKATTEVERKET_PILOT_USERS`. | Koden stämmer: `config/services.php:45` läser `SKATTEVERKET_PILOT_USERS`, och merge-commit `112ade38` finns. Att den är driftsatt med testnycklar går inte att se i koden. | Stämmer i koden. Driftsättningen **overifierad härifrån**. |
| Peppol | Byggd. Ingen leverantör påslagen. | Stämmer. `Features.php`: `peppol` är `SOON`. `NONOX_PEPPOL_PAUSED` finns i `config/services.php:204`. Manualen säger att modulen inte är påslagen på nonox.app. | Stämmer. |
| Bankläsning | Byggd, ingen leverantör påslagen. Rättas i spår A. | Stämmer. `bank_feed` är `SOON`, README, manual och färdplan säger att modulen inte erbjuds på nonox.app. Rättningen gjordes 2026-11-06 (sanningsrevision punkt 1–6). | Stämmer. |
| AGI-API | "XML finns, API saknas". "Stämmer". | **Gammal rad.** AGI-API:t är byggt (`app/Support/Skatteverket/Agd`, `app/Modules/Payroll/Actions/AgiSkatteverketUploads.php`, 44 gröna tester) och ligger bakom pilotspärren. Paketet B2 längre ner i samma fil säger det rätta. | **Fel i filen.** Tabellen och paketen säger emot varandra. |
| Skattekonto, kundhändelser, iXBRL, K3-årsredovisning, koncern | "Inte byggda". "Stämmer". | Gammal rad för fyra av fem. **Byggda:** skattekontot (`TaxAccountLedger`, 29 av 30 tester gröna, ett rött är miljöfel), kundhändelser (`app/Support/Skatteverket/Kundhandelser`, `KundhandelserTest`), iXBRL-nedladdning (`resources/views/annual-report/ixbrl*.blade.php`, beta), koncernöversikt (`app/Domain/Group`, ADR-060). **Inte byggt, stämmer:** K3-årsredovisning (`K3GuardTest` visar att den vägrar). Koncernredovisning enligt ÅRL är inte byggd, bara den interna översikten. | **Fel i filen** för fyra av fem. |

Sammanfattning: tabellen är skriven före kvällens paket och uppdaterades inte. Tre av sex rader är fel eller föråldrade. Paketen under tabellen stämmer i stort med koden (avsnitt 3).

## 3. Paket för paket

Antal tester är det Pest rapporterar för filen (data­mängder räknas, så det kan skilja sig från antalet `it(`-rader). "Röd (Vite)" betyder att ett test som ritar en sida föll på att `public/build` saknas i min arbetskopia.

| Paket | Filen säger | Koden säger | Verdikt |
|---|---|---|---|
| B1, momsrutor 06, 07, 08, 37 | Konton 3401–3403 (06), VMB-moms 2616/2626/2636 (07), 3913 (08), `accounts.vat_box` för 37 och 38. `MomsPayload::FIELDS` skickar alla fyra. | `app/Domain/Vat/VatBoxes.php` rad 15–22 och 52–54 stämmer. `MomsPayload::FIELDS` har 6, 7, 8 (rutan 37 kommer via `vat_box`). | Stämmer. |
| A, sanningsrevision | Klart, fyra fynd rättade. | `docs/status/sanningsrevision.md` finns med två revisioner. Se nedan för vad som sedan dess blivit fel. | Stämmer. |
| B2, AGI steg 2 | 43 tester. | 44 gröna. | Stämmer (ett test till). |
| C, Peppol och bank inför lansering | 260 gröna i bank, Peppol, inkorg, HQ. Nödstopp `NONOX_BANKING_PAUSED`, `NONOX_PEPPOL_PAUSED`. | Nödstoppen finns i `config/services.php`. `BankingLaunchTest` 12, `PeppolLaunchTest` 7, `PeppolReceiptTest` 10, `HqIntegrationsTest` 2 gröna + 1 röd (Vite). Siffran 260 kontrollerade jag inte. | Stämmer, siffran oprövad. |
| D1, skatt i bokslutet | 27 tester, ADR-056. | 27 gröna. ADR-056 är Proposed. | Stämmer. |
| D2, importmoms | 32 tester. | 32 gröna. ADR-057 Accepted. | Stämmer. |
| B4, skattekontot | 30 tester. | 29 gröna + 1 röd (Vite). | Stämmer. |
| B3, kundhändelser | 30 tester (Feature + Tenancy). | 26 + 1 röd (Vite) + 3 tenancy gröna = 30. | Stämmer. |
| E.1–E.2, iXBRL | Beta, E.3–E.5 återstår. | Nedladdning finns. Taxonomitestet hoppas över lokalt, CI kör det. Inlämning till Bolagsverket finns inte. `ixbrl` är `SOON` i `Features.php`. | Stämmer. |
| F.1, K3-spärr och K2-villkor | Klart. | `K3GuardTest` 6, `AnnualReportGoldenTest` 2, `K2ConditionsTest` 8 gröna. Facit i `tests/Fixtures/annual-report/`. ADR-059 är Proposed. | Stämmer. |
| G.1, koncernöversikt | 22 tester. | 13 + 9 = 22 (2 + 3 röda är Vite). ADR-060 Accepted för steg 1. | Stämmer. |
| H.1 och H.1b, övertidsarter | 13 + 9 tester. | 13 + 9 gröna. | Stämmer. |
| D4, bilförmån | 21 + 5 tester. | 21 (2 röda är Vite) + 7. FuelBenefitTest har fått två tester till sedan texten skrevs. | Stämmer, siffran 5 är gammal. |
| D5, betalfiler i valuta | 60 tester, Handelsbanken och Nordea. | 60 gröna. | Stämmer. |
| D3, K10 | 43 tester + tenancy. | 43 + 2 gröna. Parametrarna har status `researched` (`database/data/k10/2026.json`) och räknas ändå (`K10Parameters::SOURCED`). | Stämmer. Se anmärkningen om skillnaden mot NE nedan. |
| E.3, iXBRL i CI | Arelle 2.47.4, 0 fel. | Skript och CI-jobb finns. Resultatet av en körning kunde jag inte reproducera. | Struktur stämmer, **körningen oprövad**. |
| I.1, MCP-server | "Beta, väntar på beslut". Beslut 18 behövs. | **Gammal rad.** Beslut 18 är fattat 2026-11-06 (`manskliga-beslut.md`, ADR-063 Accepted), med villkoret att servern släpps först när juristens text om AI-leverantörer finns i integritetspolicyn. Policyn (`docs/juridik/integritetspolicy.md:66-70`) säger fortfarande "den är inte tillgänglig ännu" och har en `TODO(legal-verify)`. Slutpunkten `api/mcp` är registrerad utan någon spärr (`ConnectServiceProvider.php:187`). 16 + 1 tester gröna. | **Fel i filen** (statusen), och en **avvikelse mot ägarbeslutet** (se sanningsrevisionen, fynd 2). |

Anmärkning om NE mot K10: K10-parametrarna räknas med status `researched`, medan NE-parametrarna kräver `verified: true` och därför är spärrade. Båda säger "inte kontrollerad av människa" i sina filer. Skillnaden är avsiktlig i koden (40e6a725), men inget publikt ställe nämner den.

## 4. Byggt men inte listat i nummer-1.md

Allt nedan finns i koden och i ändringsloggen men saknar paket i `nummer-1.md`:

- **ADR-064, NONOX API och NONOX Connect** (ägarbeslut 8). Namnbytet, det betalda tillägget och kostnadsnotisen vid "alla moduler ingår". Accepted 2026-11-06. Ingen paketpost.
- **Villkor, integritetspolicy, personuppgiftsbiträdesavtal och underbiträden version 1.6** (gäller från 2027-01-11) i `docs/juridik/`.
- **"Jag har granskat siffrorna"** (`app/Support/Review/ReviewConfirmation.php`), använd av `SendVatReturnToSkatteverket`, `CreatePaymentBatch`, `AgiSkatteverketUploads` och `SalaryPaymentFile`.
- **Bekräftelse av K2-villkoren i bokslutet** (ägarbeslut 16, `K2ReconfirmationTest`, 10 gröna).
- **Konsultens granskningslistor** per modul i `docs/granskning/` (elva filer).
- **Rättelsebatchen** "Fler kontroller i lön, SIE-inläsning, OCR och moms" (ändringsloggen 2026-11-06 13:30).
- **Spärren av NE och INK4 för 2026** tills en människa har kontrollerat parametrarna (`NeTest`: "keeps 2026 blocked in production until a human has checked its parameters").
- **ADR-062 (K10), ADR-063 (MCP), ADR-061 (bilförmån)** har paket (D3, I.1, D4), men **ADR-060** och **ADR-064** ska stå i ADR-indexet i `docs/agents/QUICKSTART.md` (indexet listar 064 men saknar 059–061). Det är en dokumentationsfråga, inte en sanningsfråga.

## 5. Listat som klart men inte klart

- **Inget paket är påstått klart utan kod.** Men tre paket säger "bevis från testtjänsten saknas" (B2, B3, B4), och det stämmer: det finns inga körningsprotokoll från Skatteverkets testtjänst i repot. Inget av dem får därför kallas klart enligt spec 5.1.
- **D2** säger "done" och har ingen öppen beta-markering, men ADR-057 listar öppna `TODO(legal-verify)` (motkontona 4080 och 4540). Det står i paketet. Ingen avvikelse.
- **E.3** säger "done (utan Bolagsverkets testmiljö)". Det är ärligt, men "done" gäller bara att CI-jobbet finns och var grönt när texten skrevs.

## 6. Spec-punkterna mot koden

Vad som redan finns, i en rad per punkt. "Finns" betyder att spec-ytan i stort sett är täckt, "delvis" att en del finns, "saknas" att ingenting finns.

| Punkt | Läge | Vad som finns | Vad som saknas |
|---|---|---|---|
| S1 K3 | **delvis (bara spärr)** | `app/Domain/Company/ReportingFrameworkRules.php`, K3-vägran i `AnnualReportPage`, ADR-059 (Proposed), `K3GuardTest` (6), `K2ConditionsTest` (8), `K2ReconfirmationTest` (10), K2-villkor vid val av ramverk. | Allt som spec S1 kräver: K3-rapport, komponentavskrivning, uppskjuten skatt, kassaflödesanalys, övergång K2 till K3, notbibliotek. Ramverksbyte med ändringslogg per räkenskapsår är inte kontrollerat. |
| S2 Koncern | **delvis (steg 1)** | `app/Domain/Group/{GroupOverview,GroupAccess,MarkKind}`, `/koncern`, ADR-060 (Accepted för steg 1), `GroupOverviewTest` (13), `GroupTenancyTest` (9), manualen `rapporter/koncernoversikt.md`. Märkningen "Internt underlag, ingen koncernredovisning" finns. | Steg 2: förvärvsanalys, goodwill, minoritet, koncernredovisning. Ägarandel visas men räknas inte in i konsolideringen. |
| F1 Sammankopplade flöden | **delvis** | Enskilda länkar finns: offert till order till faktura (`quotes_orders`), schema och personalliggare till löneunderlag (`planning_payroll`), dagsrapport till utkast (`daily_reports`, inkorgen), utkast i leverantörsflödet. | Ingen kedjevy per objekt, inget "skapa nästa dokument" överallt, inga bakåtlänkar enhetligt, ingen kedja "kundnära till pengar" med bankmatchning end to end. |
| F2 Avtal i lönen | **delvis** | Modulen Avtal (`app/Modules/Agreements`): versionerade regelpaket med källa, `VerifyAgreementVersion` (kontroll per företag av en namngiven användare), övertidsarter för Gröna Riks och Detaljhandeln (H.1), personalliggare (`app/Modules/StaffLedger`), luckor beskrivna i `docs/manual/sv/avtal/det-har-gor-avtal-inte-annu.md`. | Statusen `draft/verified-by-operator/retired` på operatörsnivå (i dag kontrollerar varje företag själv). Ålderstillägg, helgdagar och semester enligt avtalet. Export till Fora och Collectum (finns inte). Räknespår (trace) per lönerad är inte verifierat. |
| F3 Direktinlämning | **delvis (byggt mot testtjänsten)** | Moms (`Support/Skatteverket/Moms`), AGI (`Agd`), skattekonto (`Skattekonto`), kundhändelser (`Kundhandelser`), frist­kalender (`app/Domain/Deadlines`, Mina datum). ADR-054 Accepted. Testerna är gröna. | Körningsprotokoll från testtjänsten (spec: "a test-service run log for each stage"). Enskild firma med anställda i AGI (manualen säger "stöds inte ännu"). Produktionsavtal. |
| F4 Likviditetsprognos | **delvis** | `app/Domain/Overview/LiquidityForecast.php`: tre månader, kundfakturor, leverantörsfakturor och snittresultat, utan AI. | 13 veckor, märkning bokfört/väntat/antaget, tröskel, scenarier, `forecast_*`-tabeller, lön, moms och preliminärskatt som egna poster. |
| F5 Siffror på vanlig svenska | **saknas** | Bara byggstenar: `app/Domain/Insights/BusinessKpis.php`, `Overview/Statistics.php`. | Periodjämförelse med förklarande meningar, regelmotor för drivkrafter, AI-omformulering med kontroll av tal. |
| F6 CRM mot huvudboken | **finns i stort** | `app/Modules/Crm/Domain/{KeyFigures,AccountFigures}`: fakturerat 12 mån, öppet, förfallet, betaldagar, marginal ur bokföringen, `CustomerAccounts`. | Varning vid ny affär om kunden har förfallna fakturor, lönsamhet per kund med kostnader, pipeline bredvid prognos, rollstyrd synlighet av belopp (inte kontrollerat). |
| F7 CRM som fyller sig själv | **delvis** | Mejl loggas (`LogInboundEmail`, ADR-048), kalenderlänk (ADR-049, synk åt båda hållen är `SOON`), uppgifter ur SCB och Bolagsverket (ADR-047, `registry:import`). | Möten loggade ur ansluten kalender, flaggning av affärer utan aktivitet som förslag, dubblettdetektering och sammanslagning med historik. |
| F8 GDPR i praktiken | **delvis** | `PrunePersonalDataCommand`, `PruneCrm`, `PruneStaffLedger`, `EraseCrmData`, `Support/Privacy/DataSubjectExport` (registerutdrag), `docs/gdpr/{registerforteckning,lagringstider}.md`, `DataSubjectExportTest`. | `retention_rules`/`retention_runs`/`subject_requests`, registerutdrag som PDF, åtkomstlogg per person, flöde för radering med svar, genererad registerförteckning, test som fångar nya personkolumner. |
| F10 Export och portabilitet | **delvis** | `Support/Archive/ArchivePackageBuilder` (arkivpaket per räkenskapsår), SIE4 ut, `CrmExport`, registerutdrag. | Full export som bakgrundsjobb med JSON-schema, krypterad nedladdning, import av JSON-dumpen i en ny installation, rundtur-test. |
| F11 AI förbereder, människa godkänner | **delvis (byggstenar)** | AI-utkast och förslag på flera ställen: `Modules/Ai`, `TaxAccountProposals`, MCP-utkast (`Connect/Mcp`), "Jag har granskat siffrorna" (`ReviewConfirmation`), AI bara med egen nyckel. | Gemensam godkännandeinkorg, `proposals`-modellen, tre lägen per företag, regler som lärs av avslag, test att ingen förslagstyp kan skriva utan beslut. |

(F9 är utanför specen och granskades inte.)

## 7. Åtgärder för Lead

1. Uppdatera tabellen i `nummer-1.md`: ADR-053 är Accepted, AGI-API, skattekonto, kundhändelser, iXBRL och koncernöversikt är byggda (bakom pilotspärr eller beta), och I.1 har fått beslut. Lägg in poster för ADR-064, villkor 1.6, "Jag har granskat siffrorna", K2-bekräftelsen och NE-spärren.
2. Läs `docs/status/sanningsrevision.md`, avsnittet "Granskning 2026-11-07": tre publika påståenden är fel mot koden (NE-bilagan, MCP, färdplanens "Planerat"), och flera platser saknar kostnadsnotisen för NONOX Connect.
