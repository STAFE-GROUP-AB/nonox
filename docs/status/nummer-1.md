# Vägen till nummer 1: status per paket

Målet beskrivs i uppdraget "nummer 1 för svenska småföretag med personal". Varje paket redovisas med bevis.
Inget räknas som klart utan ett test, en CI-körning, en fixtur eller ett körningsprotokoll från en testtjänst.
Mänskliga beslut finns i [manskliga-beslut.md](manskliga-beslut.md).

## Statuskontroll 2026-11-07

Kontrollerad mot koden av sanningsrevisorn, med underlag i [statuskontroll-2026-11-07.md](statuskontroll-2026-11-07.md). Arbetet styrs från och med i dag av [spec:en](../specs/nummer-1-spec.md), och filerna ägs enligt [ownership.md](../specs/ownership.md).

| Område | Enligt koden 2026-11-07 |
|---|---|
| NE-bilaga, INK4 | ADR-053 är **Accepted**. NE-bilagan är **spärrad**: parametrarna för 2026 har `verified: false` tills en människa har kontrollerat dem (beslut 2026-11-06). INK4 är inte spärrad. |
| Moms-API | Steg 1, testtjänst och pilotanvändare. Avslag från Skatteverket på driftsatt tjänst 2026-11-06, eftersom AI Reports Sweden AB saknar F-skatt och momsregistrering. Begäran om byte till GrantPilot AB är skickad 2026-11-07. |
| AGI-API | **Byggt** bakom pilotspärren (paket B2). Bevis från testtjänsten saknas. |
| Skattekonto, kundhändelser | **Byggda** bakom pilotspärren (paket B3, B4). Bevis från testtjänsten saknas. |
| iXBRL | Nedladdning för aktiebolag i beta (E.2). Inlämning till Bolagsverket finns inte. |
| K3-årsredovisning | **Inte byggd.** Bara spärren och K2-facit (F.1). Regelbladet `docs/specs/rules/s1.md` är klart. |
| Koncern | Intern koncernöversikt **byggd** (G.1). Konsoliderad rapport och koncernredovisning enligt ÅRL är inte byggda. Regelbladet `docs/specs/rules/s2.md` är klart och citatkontrollerat. |
| MCP-servern | ADR-063 är godkänd (beslut 18) med villkoret att den släpps först när juristens text finns i integritetspolicyn. Slutpunkten svarade utan spärr; rättas i paketet "publika påståenden 2026-11-07". |
| Peppol, bankläsning | Byggda, ingen leverantör påslagen på nonox.app. |

Byggt 2026-11-06 men inte listat nedan förrän nu: ADR-064 (NONOX API och NONOX Connect), villkor och integritetspolicy version 1.6, "Jag har granskat siffrorna", bekräftelse av K2-villkoren i bokslutet, konsultens granskningslistor i `docs/granskning/`, rättelsebatchen kl. 13.30 och spärren av NE för 2026. Allt driftsattes i 7ce7f858 och är grönt i CI.

## Statuskontroll 2026-11-06 (ersatt av 2026-11-07)

| Område | Enligt uppdraget | Enligt koden |
|---|---|---|
| NE-bilaga, INK4 | Levererad, ADR-053 står som Proposed | Stämmer |
| Moms-API | Steg 1, testtjänst och pilotanvändare | Stämmer. Driftsatt på nonox.app (112ade38) med testnycklar och `SKATTEVERKET_PILOT_USERS` |
| Peppol | Byggd | Stämmer. Ingen leverantör påslagen på nonox.app; står redan som "kommer snart" |
| Bankläsning | Byggd | Byggd, men **ingen leverantör påslagen på nonox.app**. Stod ändå som live på de publika sidorna, vilket rättas i spår A |
| AGI-API | XML finns, API saknas | Stämmer |
| Skattekonto, kundhändelser, iXBRL, K3-årsredovisning, koncern | Inte byggda | Stämmer |

---

Package: B1, momsrutor 06, 07, 08 och 37
Status: done (kod) / blocked (testtjänstbevis)
Evidence: `app/Domain/Vat/VatBoxes.php` kopplar redan:
- ruta 06 till 3401–3403 (uttag)
- ruta 07 till underlaget räknat ur VMB-momsen på 2616, 2626 och 2636
- ruta 08 till 3913
- ruta 37 och 38 till ett konto som företaget självt knyter till rutan (`accounts.vat_box`); BAS har inget konto för dem
`MomsPayload::FIELDS` skickar alla fyra till Skatteverket. Testerna i `tests/Feature/Vat` täcker beräkningen.
Public claim now allowed: "Momsdeklarationen räknas fram med alla rutor."
Public claim still not allowed: "Momsdeklarationen lämnas direkt till Skatteverket." Det gäller bara pilotanvändare i testtjänsten.
Open legal-verify items: `VatBoxes` (koppling konto–ruta mot aktuell BAS-momsrapport; negativ marginal i ruta 07; försäljning av anläggningstillgång i ruta 05; 2649 blandad verksamhet)
Human decisions needed: 1 (test-BankID), 4, 5
Next package: B2, AGI steg 2

---

Package: A, sanningsrevision
Status: done
Evidence: `docs/status/sanningsrevision.md`. LandingPage-, IndustryPages-, StartPage-, Changelog- och ManualTest gröna. En oberoende granskning (marknadsrätt, fakta, kod) gav fyra fynd, och alla är rättade.
Public claim now allowed: jämförelsen med Accounted, med uppgifter enbart från deras egna sidor; "Läser Peppol-fakturor i inkorgen".
Public claim still not allowed: att bankkoppling, Peppol eller direktinlämning till Skatteverket finns i nonox.app; att NONOX ensamt erbjuder öppen källkod och egen drift.
Open legal-verify items: Björn Lundéns bankkoppling ("Ingår i Total" eller tillägg); vilka villkor som gäller för Accounteds "Allt ingår".
Human decisions needed: 8 (pris för integrationer)
Next package: B2

---

Package: B2, AGI steg 2 (ADR-054)
Status: partial (byggt och granskat; bevis från testtjänsten saknas)
Evidence: `tests/Feature/Payroll/AgiSkatteverketTest.php` (43 tester), med svar byggda från RAML för AGD inlämning 1.7.7 och hantera redovisningsperiod 1.2.8. En oberoende granskning gav åtta fynd, och alla är rättade. En omkontroll fann två rester, och båda är rättade.
Public claim now allowed: inget nytt. Funktionen är bakom pilotspärren.
Public claim still not allowed: "Arbetsgivardeklarationen lämnas direkt till Skatteverket."
Open legal-verify items: integritetspolicy och personuppgiftsbiträdesavtal för AGI till Skatteverket. Fyra frågor till Skatteverket (provider-verify) om ersättning av utkast, organisationscertifikat eller e-legitimation, formatet på scope och formatet på händelsetext.
Human decisions needed: 1, 4, 5
Next package: C

---

Package: C, Peppol och bank inför lansering
Status: done (kod) / blocked (avtal)
Evidence: `tests/Feature/Banking/BankingLaunchTest.php`, `tests/Feature/Peppol/{PeppolLaunchTest,PeppolReceiptTest}.php` och `tests/Feature/Hq/HqIntegrationsTest.php`: 260 gröna i katalogerna för bank, Peppol, inkorg och HQ. En oberoende granskning gav fyra fynd, och alla är rättade. Nödstoppen heter `NONOX_BANKING_PAUSED` och `NONOX_PEPPOL_PAUSED`. Checklistorna finns i `docs/status/lansering-bank.md` och `docs/status/lansering-peppol.md`. ADR-055 (betalningsinitiering, Proposed) har inte lett till någon kod.
Public claim now allowed: inget nytt förrän avtalen är tecknade.
Public claim still not allowed: bankkoppling och Peppol som live.
Open legal-verify items: listade i båda lanseringsdokumenten.
Human decisions needed: 2, 3, 8
Next package: D1

---

Package: D1, skatt i bokslutet (ADR-056)
Status: done (kod), Beta
Evidence: `tests/Feature/Closing/TaxProposalTest.php` (27 tester); AUD-BOK-1 i `K2FullYearTest` är nu ett riktigt test. En oberoende skattegranskning mot IL (1999:1229) gav åtta fynd. Det allvarligaste: privatbostadsföretag fick ett förslag på hela resultatet. Alla åtta är rättade och kontrolleras nu igen.
Public claim now allowed: "Bokslutet föreslår årets skatt för aktiebolag, och en person bekräftar den innan något bokförs."
Public claim still not allowed: att skatteberäkningen är granskad av en redovisningskonsult; att särreglerna för ekonomiska föreningar hanteras.
Open legal-verify items: listade i ADR-056 (fondkontots år, sexårsräkningen, innehållet på kontona 6072, 6982, 6992, 8423 och 8314, avrundning, föreningsregler).
Human decisions needed: 7
Next package: D2, importmoms för varor från länder utanför EU

---

Package: D2, importmoms för varor från länder utanför EU (ADR-057)
Status: done
Evidence: `tests/Feature/Vat/ImportVatTest.php` (32 tester), bland dem Skatteverkets eget exempel: 105 000 + 12 600 ger ruta 50 = 117 600, ruta 60 och 48 = 29 400. En oberoende momsgranskning mot ML (2023:200) och Skatteverkets sida om import bekräftade beräkningen. Den gav fyra fynd, och alla är rättade.
Granskningen hittade också ett fel som fanns sedan tidigare. Ruta 20 och 23 tog inte med BAS 2026-kontona 4075–4077 och 4065–4067. Felet är rättat, och ändringsloggen har fått en post av typen Rättat.
Public claim now allowed: "Import av varor från länder utanför EU: du skriver in beloppen från Tullverkets tullräkning och NONOX föreslår verifikationen."
Public claim still not allowed: att tullräkningen läses in automatiskt; att centraliserad tullklarering hanteras.
Open legal-verify items: motkontona 4080 och 4540; vilken period ett ändrat beslut från Tullverket hör till; blandad verksamhet.
Human decisions needed: 7
Next package: B3, kundhändelser

---

Package: B4, skattekontot (ADR-054 steg 3)
Status: partial (byggt och granskat; bevis från testtjänsten saknas)
Evidence: `tests/Feature/TaxAccount/SkatteverketSkattekontoTest.php` (30 tester), med svar från RAML för Skattekonto 2.1.0. En oberoende granskning gav fyra fynd. Det viktigaste: arbetsgivaravgiften föreslogs på 2731 när lönemodulen bokför på 2730. Alla fyra är rättade, och kopplingstabellen är skyddad mot ändring av en trigger i databasen.
Public claim now allowed: inget nytt. Funktionen är bakom pilotspärren.
Public claim still not allowed: "Skattekontot stäms av automatiskt mot Skatteverket."
Open legal-verify items: om skattekontoutdraget ska sparas som verifikation; preliminärskatt och ränta för EF, HB och KB; om intäktsräntan är skattefri; om en skuld hos Kronofogden ligger kvar på 1630.
Human decisions needed: 1, 4, 5
Next package: B3, kundhändelser

---

Package: B3, kundhändelser (ADR-054 steg 4)
Status: partial (byggt och granskat; bevis från testtjänsten saknas)
Evidence: `tests/Feature/Skatteverket/KundhandelserTest.php` och `tests/Tenancy/SkatteverketEventTenancyTest.php`, sammanlagt 30 tester. Svaren är byggda efter svarsschemat i RAML för Kundhändelser 4.0.5, eftersom exemplen där är bortkommenterade. En oberoende granskning gav två fynd, och båda är rättade. Händelserna visas bara för den som själv hämtade dem (Allmänna villkor §2 och §5.1). En begäran räknas som väntande bara om den är den senaste i sin kedja (`kopplingsId`).
Public claim now allowed: inget nytt. Funktionen är bakom pilotspärren.
Public claim still not allowed: "Skatteverkets meddelanden visas i NONOX."
Open legal-verify items: om händelserna får delas med andra användare i företaget; rättslig grund; lagringstid i två år; vem som är mottagare och personuppgiftsansvarig (villkoren 7.4).
Human decisions needed: 1, 4, 5
Next package: E, digital årsredovisning (iXBRL)

---

Package: E.1–E.2, digital årsredovisning (ADR-058)
Status: partial (E.2 byggt och granskat; E.3–E.5 återstår)
Evidence: `tests/Feature/AnnualReport/IxbrlAnnualReportTest.php` och `IxbrlRoundingTest.php`. Testerna kontrollerar att varje belopp i PDF:en är märkt i iXBRL-filen med samma värde. Med taxonomipaketet utpekat är varje begrepp kontrollerat mot taxonomin. En oberoende granskning gav fem fynd, och alla är rättade. Ett av fynden ändrar avrundningen i årsredovisningen: nu är varje summa summan av de avrundade raderna.
Public claim now allowed: "Årsredovisningen kan laddas ner som iXBRL-fil (Beta), avsedd för digital inlämning."
Public claim still not allowed: "Lämna årsredovisningen digitalt till Bolagsverket."
Open legal-verify items: var en avrundningsdifferens i balansräkningen ska ligga; detaljer ur Bolagsverkets tillämpningsanvisning (beslut 15).
Human decisions needed: 12, 13, 14, 15
Next package: E.3

---

Package: F.1, spärr för K3 och K2-villkoren (ADR-059)
Status: done
Evidence: `tests/Feature/AnnualReport/{K3GuardTest,AnnualReportGoldenTest}.php` och `tests/Feature/Company/K2ConditionsTest.php`. Facit för K2-utskriften i HTML och iXBRL ligger i `tests/Fixtures/annual-report/`. En oberoende granskning fann ingen väg kvar till en falsk K2-rapport. Den gav fyra fynd om formuleringar, och alla är rättade.
Public claim now allowed: inget nytt.
Public claim still not allowed: "Årsredovisning enligt K3."
Open legal-verify items: juristgranskning av villkors- och vägranstexterna; den lydelse av punkt 1.1 som gällde före 2026.
Human decisions needed: 16
Next package: F.2

---

Package: G.1, koncernöversikt (ADR-060)
Status: done
Evidence: `tests/Feature/Group/GroupOverviewTest.php` och `tests/Tenancy/GroupTenancyTest.php`, 22 tester. En oberoende granskning fann ingen läcka mellan företag. Den gav två mindre fynd, och båda är rättade. Ett läckage som uppstod i rättelsen, där man kunde se om en annan användares koncern fanns, är också stängt.
Public claim now allowed: "Koncernöversikt för egna bolag, som intern rapport med förslag på elimineringar."
Public claim still not allowed: "Koncernredovisning."
Open legal-verify items: att BAS-konton föreslås genom att namnet innehåller "koncern".
Human decisions needed: inga nya.
Next package: G.2 (beslut krävs)

---

Package: H.1 och H.1b, övertidsarter och OB eller övertid (ADR-033)
Status: done (kod), juridisk granskning återstår
Evidence: `tests/Unit/Planning/OvertimeKindsTest.php` (13 tester) och `tests/Feature/Agreements/AgreementOvertimeKindsTest.php` (9 tester), med belopp räknade för hand. Varje värde hänvisar till avsnitt och sida i Gröna Riksavtalet 2025–2027 och i Detaljhandelsavtalet. En granskning och en omkontroll gav sammanlagt nio fynd, och alla är rättade.
Felet i Detaljhandeln, där både OB och övertid betalades, är rättat. Ändringsloggen har fått en post av typen Rättat.
Public claim now allowed: "Övertid och mertid delas upp i avtalets egna arter för Gröna Riks och Detaljhandeln."
Public claim still not allowed: att avtalen är kompletta (se `docs/manual/sv/avtal/det-har-gor-avtal-inte-annu.md`).
Open legal-verify items: listade i ADR-033 (kvittning, de två första timmarna, 00–06, minimum på fridag, OB vid komptid).
Human decisions needed: 7
Next package: H, nästa luckor (ålderstillägg, helgdagar, semester)

---

Package: D4, förmånsvärde för bil (ADR-061), och rättelse av drivmedelsförmånen
Status: done (kod), Beta
Evidence: `tests/Feature/Payroll/CarBenefitTest.php` (21 tester) och `FuelBenefitTest.php` (5 tester). Skatteverkets exempel ger exakt 72 045 kr. En oberoende granskning mot IL 61 kap. bekräftade formeln och 2026 års värden. Den gav fyra fynd om registret och prestandan, och alla är rättade.
Under arbetet hittades ett fel som finns i produktion i dag: drivmedelsförmånen gav arbetsgivaravgifter på värdet × 1,2 i stället för på marknadsvärdet, och ruta 098 skrevs inte. Felet är rättat och har fått en post i ändringsloggen av typen Rättat.
Public claim now allowed: "Bilförmån räknas automatiskt enligt 61 kap. inkomstskattelagen (Beta)."
Public claim still not allowed: att drivmedelsförmånen räknas automatiskt (den skrivs in för hand), och att bilar som blev skattepliktiga före juli 2021 hanteras.
Open legal-verify items: listade i ADR-061.
Human decisions needed: 7, 17
Next package: —

---

Package: D5, betalfiler i utländsk valuta (ADR-014 och ADR-025)
Status: done (kod) för Handelsbanken och Nordea
Evidence: `tests/Feature/Payables/ForeignPaymentFilesTest.php` (60 tester). Utfilerna för betalningar i kronor är kontrollerat identiska med de tidigare för alla sex bankerna. En oberoende granskning fann inget som kan styra om pengar till fel mottagare. Den gav sex förslag på härdning, och alla är gjorda: snävare gräns för automatisk bokning, betalningskod från 120 000 kr, kontroll av IBAN med mod-97, teckenuppsättningen för SWIFT, en valuta per fil och land från ögonblicksbilden. Under härdningen hittades och rättades också ett eget fel i XML-filen när ett block innehöll flera betalningar.
Public claim now allowed: "Leverantörsfakturor i tio utländska valutor kan betalas med betalfil hos Handelsbanken och Nordea."
Public claim still not allowed: övriga banker; att filerna är provade i bankernas testmiljöer.
Open legal-verify items: hur gränsen för betalningsrapportering ska mätas; XSD-validering och testfiler hos bankerna.
Human decisions needed: inga nya.
Next package: —

---

Package: D3, K10-underlag (ADR-062)
Status: done (kod), Beta
Evidence: `tests/Feature/IncomeTax/K10Test.php` (43 tester) och `tests/Tenancy/K10TenancyTest.php`, med Skatteverkets egna exempel. En oberoende skattegranskning mot IL 57 kap. i lydelsen enligt SFS 2025:1361 gav fem fynd. Det viktigaste var att lönekörningarna ersatte bokföringen för hela året. Alla fem är rättade.
Public claim now allowed: "K10-underlag enligt 3:12-reglerna från 2026, med SRU-fil (Beta)."
Public claim still not allowed: K10 för inkomstår 2025, avsnitt C (försäljning), K10A och K13.
Open legal-verify items: listade i ADR-062 (avrundning, vilka konton som räknas som kontant ersättning, utbetald eller bokförd lön, 50-gångersspärren, när en utdelning kan disponeras).
Human decisions needed: 7
Next package: —

---

Package: E.3, validering av iXBRL i CI
Status: done (utan Bolagsverkets testmiljö)
Evidence: `scripts/fetch-xbrl-taxonomy.sh` (SHA-256 `47e56bca68c052b751ed8e0759fa9b04a6fa09b1d2b47ae2df4187d0ea2fe559`) och `scripts/validate-ixbrl-arelle.sh`. Arelle 2.47.4 ger 0 fel och 0 varningar på båda exempelfilerna. Jobbet stoppar bygget vid fel.
Public claim now allowed: inget nytt.
Public claim still not allowed: att Bolagsverket har godkänt filerna.
Open legal-verify items: —
Human decisions needed: 12, 13, 15
Next package: E.4

---

Package: I.1, MCP-server på NONOX Connect (ADR-063)
Status: done (kod), Beta, väntar på beslut
Evidence: `tests/Feature/Connect/McpServerTest.php` och `tests/Tenancy/McpTenancyTest.php`. Säkerhetsgranskningen fann inga allvarliga brister. Två mindre brister är rättade.
Public claim now allowed: inget förrän beslut 18 är fattat.
Public claim still not allowed: att AI-assistenter kan bokföra, skicka eller betala.
Open legal-verify items: AI-leverantörer som mottagare (beslut 18).
Human decisions needed: 18
Next package: —
