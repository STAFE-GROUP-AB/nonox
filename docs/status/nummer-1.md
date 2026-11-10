# Vägen till nummer 1: status per paket

Målet beskrivs i uppdraget "nummer 1 för svenska småföretag med personal". Varje paket redovisas med bevis.
Inget räknas som klart utan ett test, en CI-körning, en fixtur eller ett körningsprotokoll från en testtjänst.
Mänskliga beslut finns i [manskliga-beslut.md](manskliga-beslut.md).

## Prioriterad ordning (ägaren 2026-11-08)

Arbetet tas i den här ordningen. Ingenting längre ner börjar innan det ovanför är klart, om inte ägaren säger något annat. Nya önskemål läggs sist, inte före.

1. **Spec nummer-1, S1** (pågår): S1-P4 och S1-P12 klara; kvar S1-P9, sedan S1-P10 och S1-P14. S1-P13 (större aktiebolag) läggs utanför S1 enligt beslut S1-H1.
2. **Aktiebok och styrelserum** (ADR-070, `aktiebok-och-styrelserum`).
3. **Körjournal och kvitto i mobilen** (`korjournal-och-kvitto-i-mobilen`).
4. **Recept och svinn** för restaurang (`recept-och-svinn`).
5. **Arbetsorder och ÄTA** för hantverkare (`arbetsorder-och-ata`).
6. **Kundportal** (`kundportal`).
7. **Flytt från fler program** (`flytt-fran-fler-program`).
8. **Prova utan att registrera** (`prova-utan-att-registrera`).
9. **Statussida och säkerhet** (`statussida-och-sakerhet`).
10. **Egna moduler** (`egna-moduler`), med en ADR om säkerheten först.
11. **Tillverkning, inköpsordrar och kundorder** (ADR-071), direkt efter S1 enligt ägaren 2026-11-09, i den här ordningen: L1 Trä- och möbelavtalet (`tra-och-mobelavtalet`), lönereglerna för 2027 och en inläsningsmall för lönedata från andra program; M1 enheter på artiklar (§12); M2 stycklistor med arbetssteg och kalkyl på offert och order (§3–§4, §6); M3 inköpsorder och inköpsförslag (§2, §5); M4 tillverkningsorder med färdigvarulager (§8); M5 verkstadsterminal, avvikelser och efterkalkyl (§7); M6 planeringskalender (§13); M7 storskärm i verkstaden (`storskarm-verkstad`); därefter branschsidan "Tillverkande företag". M1–M4 ska vara klara i mitten av januari 2027, M5–M7 i februari.
12. **NONOX SaaS: återkommande intäkter och nyckeltal** (ADR-080): MRR, ARR, MRR-förändring (ny, utökning, minskning, förlorad), churn per kund och per intäkt, net revenue retention, kohorter, ARPA och livstidsvärde, byggt på avtalsfakturering, avtalsregistret och bokföringen. Plus stöd för Stripe i NONOX Connect (import av prenumerationer, fakturor och utbetalningar).
13. **Datum för version 1.0** (ägaren 2026-11-09): rätta kvarvarande "1 januari 2027" till 1 februari 2027 (ADR-038, en äldre ändringsloggpost); görs i samma push som S1-P9.
14. **Aktuella bilder i manualen** (ägaren 2026-11-09): gå igenom manualens skärmbilder mot dagens skärmar och ta om de som är inaktuella, först Företagsguiden. Skärmbilderna tas från demodata i en ny `nonox_demo_*`-databas.
15. **Onboarding efter godkännande i kön** (ägaren 2026-11-10, byggs nu parallellt med S1): personligt välkomstmejl utifrån svaren i kön (tidigare program, bransch, anställda), förifyllt `/kom-igang`, Kom igång-listan anpassad efter bransch och program, högst två uppföljningsmejl (ingen inloggning efter 2 dagar, inget inflyttat efter 5 dagar, med avregistrering), och en lista i /hq över hur långt varje godkänt företag har kommit. Avsändare nonox.app, svar till andreas@stafegroup.com, undertecknat av Andreas, ingen bokningslänk.
16. **Länktext på startsidan** (ägaren 2026-11-10): "Så fungerar det i manualen →" blir "Läs mer i manualen →"; följer med i samma push som S1-P9.

Ägarens ordning 2026-11-09 för det som pågår: (a) skärmbilder till de nya manualartiklarna, (b) databasanvändarna och demodatabasen på nonox.app, (c) status på önskelistan i färdplanen, (d) NONOX SaaS med Stripe.

Ägarens beslut 2026-11-08:
- "Prova utan att registrera" (ADR-077) körs i en egen demodatabas. Spärrarna i produktionsdatabasen får inga undantag. Bara den särskilda demodatabasen får tömmas eller byggas om, och påslag i produktion kräver ägarens bekräftelse vid driftsättningen.
- Separata databasanvändare (ADR-079 steg 2): en ägare som bara kör migreringar, en appanvändare utan rätt att ändra tabeller, spärrar eller funktioner, och en läsanvändare. Omställningen av nonox.app görs i ett underhållsfönster enligt en runbook.

Önskemål som inte byggs nu: föreningar, bordsbokning, installationsanvisningar och skript för egen server (`egen-server-guide`, ägaren 2026-11-09), koppling till webbutik (`kassa-och-ehandel`, kommer senare). Byggs inte: webbshop, eget kassasystem.

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

---

Package: Spår A 2026-11-07, sanna publika påståenden
Status: done
Evidence: `tests/Feature/PublicClaimsGuardTest.php` (påståendet att allt ingår kräver noten om NONOX Connect; NE lovas inte medan parametrarna är okontrollerade), `tests/Feature/Connect/McpServerTest.php` (MCP avstängt som standard, ingen rutt). Två granskningsomgångar, inga kvarvarande fynd av medelhög eller högre allvarlighet. Hela sviten 6 338 gröna efter sammanslagning.
Rule ids covered: marknadsföringslagen 10 § (noten bredvid påståendet), ägarbeslut 18.
Public claim now allowed: inget nytt.
Public claim still not allowed: NE-bilagan som SRU-fil, AI-assistenter via MCP.
Open legal-verify items: `docs/juridik/regelverk.md` anger Stafe Development AB som driftbolag till 2027-01-11; importen från Fortnox är inte provad mot ett riktigt konto.
Human decisions needed: inga nya.
Next package: —

---

Package: S2-1.1, koncernmodell och behörighet (ADR-060, tillägg 2026-11-07)
Status: done (kod, inget som syns för användaren)
Evidence: `tests/Tenancy/CorporateGroupTenancyTest.php`, `tests/Feature/Group/CorporateGroupTest.php`. Tre granskningsomgångar med fokus på tenancy; alla fynd rättade (innehav som en koncern vilar på, roller som överlever medlemskap, röstorakel, låsordning, ägarbyte). Hela sviten 6 338 gröna.
Rule ids covered: S2-R1d, S2-R10, rättelse C7.
Public claim now allowed: inget nytt.
Public claim still not allowed: "Koncernredovisning", "konsoliderad koncernrapport".
Open legal-verify items: användar-id i andra bolags behandlingshistorik (`docs/gdpr/registerforteckning.md`).
Human decisions needed: S2-D1, S2-H5, S2-H6 (punkt 20 i manskliga-beslut.md).
Next package: S2-1.2, översiktsskärmen.

---

Package: F10 paket 1, JSON-export av bokföringens kärna (ADR-065, Proposed)
Status: done (kod, inget som syns för användaren)
Evidence: `tests/Feature/Portability/{JsonDumpWriterTest,JsonDumpHardeningTest,SchemaPortabilityTest}.php` (31 tester), publicerat schema `docs/portability/nonox-dump.schema.json`. Två säkerhetsgranskningar; alla fynd rättade. Hela sviten 6 338 gröna.
Rule ids covered: —
Public claim now allowed: inget nytt.
Public claim still not allowed: "Exportera all data", import till en ny installation.
Open legal-verify items: export av en kunds lön och CRM-uppgifter av en konsult (F10-3).
Human decisions needed: F10-2, F10-3 (F10-1 beslutad 2026-11-07, se ADR-065).
Next package: F10 paket 2, exportjobb, kryptering och nedladdning.

---

Package: S1-P1, regelverk per räkenskapsår (ADR-059, tillägg 2026-11-07, A.2.1)
Status: done (kod och inställningar)
Evidence: `tests/Feature/Company/FrameworkPerFiscalYearTest.php` och `AnnualReportGoldenTest`. Där visas att 2026 års K2-årsredovisning är byte för byte densamma sedan 2027 bytt till K3. En oberoende granskning och två omkontroller; alla fynd rättade:
- bytet når året som håller på att stängas;
- NE-bilagan följer årets regelverk;
- låsordningen mot fastställelsen;
- ingen K2-bekräftelse utan ett verkligt byte.
Rule ids covered: S1-R1.4, K3 1.4/1.6.
Public claim now allowed: inget nytt (K3-årsredovisningen vägras fortfarande).
Public claim still not allowed: "Årsredovisning enligt K3".
Open legal-verify items: inga nya.
Human decisions needed: inga.
Next package: S1-P4 komponenter (väntar på H4), S1-P6 K3-uppställning.

---

Package: S1-P2, storleksprövning och K3-regler som data (ADR-059, A.2.2, A.2.5)
Status: done (kod, inget som syns för användaren ännu)
Evidence: `tests/Unit/AnnualReport/K3/{K3RulesTest,K3CompanySizeTest}.php`, `tests/Feature/AnnualReport/K3/K3CompanySizeFiguresTest.php` och `tests/Tenancy/PriorYearSizeFigureTenancyTest.php`.
- BFN:s exempel 1–3 ur Vägledning Gränsvärden (2026-09-14) är tester.
- F-S1-a ger större för 2025 och 2026.
- En oberoende granskning och en omkontroll; alla fynd rättade:
  - samma två gränsvärden två år i rad;
  - nystartat företag är mindre först efter en persons bekräftelse;
  - medelantalet anställda avrundas till hela personer;
  - exakt jämförelse av omräknad omsättning;
  - stiftelser och ideella föreningar avgörs av en person.
Rule ids covered: S1-R9, R9.1, R9.2, R1.3 första stycket, R1.4.
Public claim now allowed: inget nytt.
Public claim still not allowed: att NONOX avgör om ett företag är större.
Open legal-verify items:
- avrundning av medelantalet före jämförelsen med gränsvärdet, och uppåt vid x,5;
- vilka företagsformer ÅRL 1:3 tredje stycket omfattar (BFL 2:2–3);
- ÅRL:s lydelser före 2016 och K2 1.1C före 2026.
Human decisions needed: inga (H8 besvarad av BFN).
Next package: S1-P8 kassaflödesanalys, S1-P11.

---

Package: S1-P3, redovisningsprinciper för K3 (ADR-059, A.2.3)
Status: done (kod, ingen skärm ännu)
Evidence: `tests/Feature/AnnualReport/K3/{AccountingPoliciesTest,AccountingPoliciesFirstYearTest}.php`, `tests/Unit/AnnualReport/K3/K3PolicyKeyTest.php` och `tests/Tenancy/AccountingPolicyTenancyTest.php`. K3 25.3, 23.31 och kommentaren till 10.2 är kontrollerade mot BFN:s text. En oberoende granskning och två omkontroller; alla fynd rättade:
- svaren om övergången gäller bara sitt eget år;
- principbyte enligt K3 10.2 och 23.37 spärrar K3-dokumentet, men inte en start efter "inga" eller kassaflödesanalysen;
- en ändring av det första räkenskapsåret flyttar principerna;
- fastställda år skyddas.
Rule ids covered: S1-R2, R5, R6, R7.1, R7.6, R10, K3 10.2, 23.37.
Public claim now allowed: inget nytt.
Public claim still not allowed: "Årsredovisning enligt K3".
Open legal-verify items: inga nya. Fastställelsedagen är ett fält som går att ändra (uppföljning i S1-P11).
Human decisions needed: H4 (komponentpolicyn är inte byggd).
Next package: S1-P11.

---

Package: S1-P6, K3-uppställning och utskrivna belopp (ADR-059, A.4)
Status: done (kod, inget som syns för användaren ännu)
Evidence: `tests/Feature/AnnualReport/K3/{K3ScheduleFixtureTest,K3UnbalancedSheetTest}.php` och `tests/Unit/AnnualReport/K3/K3ScheduleTest.php`.
- F-S1-a:s resultaträkning (2025, 2026) och balansräkning (tre datum) stämmer på öret och som utskrivna hela kronor.
- K2-utskriften är byte för byte oförändrad.
- Antalet frågor är 8 för både K2 och K3.
- En oberoende granskning mot ÅRL bilaga 1–2 och K3 kap. 4, 5 och 29, och en omkontroll; alla fynd rättade:
  - 225x ligger under Övriga avsättningar för skatter;
  - 1081 ligger under utvecklingsutgifter;
  - en verklig obalans spärrar K3-balansräkningen;
  - rubriken heter "Fordringar".
- Hela sviten 6 533 gröna.
Rule ids covered: S1-R4.6, R8.2, R10.
Public claim now allowed: inget nytt.
Public claim still not allowed: "Årsredovisning enligt K3".
Open legal-verify items:
- särskild uppgift om koncernföretag (bilaga 2 p. 14, 15 och 17);
- uppdelningen av inköp på handelsvaror och råvaror;
- placeringen av restkonton mot BAS 2026 (A12);
- var avrundningsdifferensen i balansräkningen ska ligga.
Human decisions needed: H9 (konto 2089 för fonden för utvecklingsutgifter, kontroll mot BAS 2026), H10.
Next package: S1-P7 förändringar i eget kapital.

---

Package: S1-P7, förändringar i eget kapital enligt K3 6.3 (ADR-059, A.1 p. 5, A.4)
Status: done (kod, inget som syns för användaren ännu)
Evidence: `tests/Feature/AnnualReport/K3/K3EquityStatementTest.php` (25 tester) och `tests/Unit/AnnualReport/K3/K3EquityStatementTest.php`.
- F-S1-a:s förändringar i eget kapital 2025 och 2026 stämmer på öret, rad för rad och kolumn för kolumn.
- Raderna följer K3 6.3 a–e. En rest som inte passar någon rad spärrar årsredovisningen med en svensk förklaring som namnger konton och verifikationer. "Övriga förändringar" skrivs aldrig ut.
- Avrundningsdifferensen i PrintedFigures är nu en spärr med förklaring i stället för ett undantag.
- Rättelseverifikationer nettas mot det de rättar. En rättelse av ett tidigare års verifikation som ändrar eget kapital spärrar tills rad b används (S1-P12).
- Utdelning känns igen bara när 2898 ensamt bär samma belopp som minskningen; utdelning ett förlustår fungerar.
- K2-utskriften är byte för byte oförändrad. En fråga, oberoende av antalet verifikationer.
- En oberoende granskning (K3 6.3–6.5 och ÅRL 6:2 andra stycket kontrollerade ordagrant mot BFN och riksdagen) och två omkontroller; alla P1/P2 rättade.
- Hela sviten 6 561 gröna, phpstan och pint rena.
Rule ids covered: S1-R8.3.
Public claim now allowed: inget nytt.
Public claim still not allowed: "Årsredovisning enligt K3".
Open legal-verify items:
- övergången K2→K3 och rättelse av fel från tidigare år på rad b (H7, S1-P12);
- nyemission och återbetalt tillskott som egna rader under d;
- 2093 och 2898 som kännetecken för tillskott och utdelning;
- kvarstående 2099 visas som omföring utan stämmobeslut;
- skatteeffekter som redovisas i eget kapital (K3 kap. 29) saknar källa och spärrar.
Follow-ups: S1-P14 frågeplanen voucher_rows × vouchers utan statistik (gäller även AnnualAccounts::ledger()); S1-P12 ska ta K3-kolumnerna från K3EquityStatement::columns(); ordalydelsen för en bakåtdaterad rättelse.
Human decisions needed: H7, H9, H10.
Next package: S1-P8 kassaflödesanalys.

---

Package: S1-P8, kassaflödesanalys enligt K3 kapitel 7, indirekt metod (ADR-059, A.4)
Status: done (kod, inget som syns för användaren ännu)
Evidence: `tests/Feature/AnnualReport/K3/CashFlowStatementTest.php` och `tests/Unit/AnnualReport/K3/CashFlowPrintedFiguresTest.php`; ny fixtur F-S1-c (mindre företag utan kassaflödesanalys).
- F-S1-a:s kassaflödesanalys 2025 och 2026 stämmer på öret mot expected.json; analysen stämmer alltid mot förändringen i likvida medel (1900–1999) eller vägras.
- Investerings- och finansieringsflöden visas brutto (7.11); checkräkningskrediten netto på egen rad (7.12 b). Räntor och utdelningar separat (7.14), betald inkomstskatt separat (7.17), utbetald utdelning under finansiering (7.16).
- Transaktioner utan betalning spärrar med namngivna verifikationer: köp mot lån, leverantörskredit som inte är betald via leverantörsreskontran samma år, omvandling av lån till tillskott, kvittning. Orealiserade valutakursdifferenser spärrar tills 7.13 är byggd.
- Policy och storlek: `none` ger ingen analys för ett mindre företag men vägras för ett större företag, okänd storlek och varje ekonomisk förening (bostadsrättsförening, ÅRL 2:1); `direct` vägras.
- Ett fast antal frågor (19 med föregående år), säkert även med inaktuell statistik. Alla frågor är filtrerade på företag.
- En oberoende granskning mot K3 kapitel 7 och ÅRL 2:1 och 6:5 samt tre omkontroller; alla P1/P2 rättade.
- F-S1-a: maskin M2 bokförd direkt mot bank, betald ränta med minustecken, serie E utan lucka (Lead-godkänt). Organisationsnummer i F-S1-a och F-S2-a utbytta mot repots befintliga testnummer.
- Hela sviten 6 672 gröna, phpstan och pint rena.
Rule ids covered: S1-R2, S1-R2.1.
Public claim now allowed: inget nytt.
Public claim still not allowed: "Årsredovisning enligt K3", "Kassaflödesanalys".
Open legal-verify items:
- kontomappningen (1800–1899 som investering, 1970/1990 som likvida medel, 2893 som lån, inkomstskattekonton, utdelnings-, ränte- och valutaintervall);
- 1660–1689 och 2860–2879 spärrar tills kategorin är bestämd (1680 är vanligt);
- checkräkningskrediten netto enligt 7.12 b;
- radtexter och vilken rad som tar en avrundningsrest.
Follow-ups: S1-P14 K3EquityStatement-frågans plan med inaktuell statistik; en korrekt ombokad betalningsverifikation får fakturan att se obetald ut (spärrar, fel belopp skrivs aldrig ut).
Human decisions needed: H4, H7, H9, H10.
Next package: S1-P9.

---

Package: Utanför specen — likviditetsprognos med löner, moms, skattekonto och avtalsfakturor (ADR-027, tillägg 2026-11-08)
Status: done (syns på Översikten → statistik, "Likviditet och prognos")
Evidence: `tests/Feature/Overview/LiquidityForecastFlowsTest.php` (21 tester), `tests/Unit/Overview/LiquidityForecastTest.php`, `tests/js/overview-charts.test.mjs`.
- Prognosen räknar nu kundfakturor (återstående belopp), leverantörsfakturor, avtalsfakturor och utkast, nettolön och skatter från lönekörningarna, moms per förfallodag, F-skatt när månadsbeloppet är ifyllt och saldot på 1630.
- Snittresultatet tar bort exakt det som räknas för sig: lönemodulens kostnader utom pension, särskild löneskatt och avtalsförsäkring; 8900–8998 och 7530–7539 när F-skatten räknas; intäkter från avtalsfakturor netto efter kreditfakturor.
- Kontantmetoden: momsen dateras efter väntad betalning, och momsandelen av öppna kund- och leverantörsfakturor räknas.
- Lön som inte är bokförd visas bara för roller med payroll:view. Fast antal frågor, tenancy-test.
- Ett bygge, en kall granskning (3 bekräftade beloppsfel), två omkontroller; allt rättat. Hela sviten efter sammanslagning: 6 695 gröna, phpstan och pint rena.
Kända begränsningar (ADR-027): OSS-moms dateras efter svensk momsdag; pensionsfiltret går på konto, inte lönearts typ; särskild löneskatt på pension utanför lönemodulen; horisonten är tre månader (ägarbeslut om 6/12 väntar).
Next package: CRM-affär → offert (granskas om), S1-P4, S1-P5, S1-P11.

---

Package: S1-P5, förslag på uppskjuten skatt enligt K3 kapitel 29 (ADR-059)
Status: done (beta, Bokslut → Uppskjuten skatt för K3-år i AB och ekonomisk förening)
Evidence: `tests/Feature/Closing/DeferredTaxTest.php`, `tests/Unit/Closing/DeferredTaxProposalTest.php`, `tests/Tenancy/DeferredTaxTenancyTest.php`.
- Skillnad per post, skattepliktig eller avdragsgill enligt teckentabellen i 29.14, skatt avrundad till öre; skattesatsen för året efter balansdagen ur CorporateTaxRules.
- F-S1-a: 904 752,00 / 995 227,20 / 1 085 702,40 på 2240 och förändringen 90 475,20 på 8940, på öret.
- Obeskattade reserver (2100–2199) kan inte bli en post, spärrat i kod och med en CHECK i databasen. Bokförda poster är låsta av en trigger.
- H6: en skattefordran föreslås aldrig; en person anger beloppet med motivering, utgångsläge 0, aldrig över det beräknade. Gäller alla avdragsgilla skillnader (bredare än beslutet, flaggat för konsulten).
- Bokförs bara efter bekräftelse, via BookVoucher (serie A, källa CLOSING, sista dagen), en gång per år, före resultatdispositionen; bekräftelsen jämför ett fingeravtryck av hela förslaget.
- Nästa år: "Hämta posterna från föregående år" kopierar posterna okontrollerade; en tom lista som skulle återföra en befintlig skuld kräver ett uttryckligt kryss.
- Skatteförslaget (TaxProposal) räknar bort bara det belopp som den uppskjutna skatten själv bokade; manuell bokning på 894x spärrar som förut, även för K2.
- Kall granskning (2 P2, 5 P3) och två omkontroller, allt rättat. Hela sviten efter sammanslagning: 6 754 gröna, phpstan och pint rena.
Rule ids covered: S1-R4, R4.1–R4.4, R4.6; R4.5 som en persons belopp.
Public claim now allowed: inget nytt (ändringsloggen säger "för räkenskapsår som följer K3 (beta)").
Public claim still not allowed: "Årsredovisning enligt K3", "Kassaflödesanalys".
Open legal-verify items: H5 (hänvisningarna i 29.19 och 29.25); BAS 2240/1370/8940; kvittning enligt 29.32; 29.20 och 29.21 lämnas åt personen; beslutsdatum för skattesatser (`decided_on` saknas i rules.json, risk för felaktig spärr).
Follow-ups: koppla P4:s komponentregister till `BuildingCarryingAmounts`; visa beloppet på Bokslut → Regelverk (P11) som information; ingen checklistpunkt gör uppskjuten skatt obligatorisk ännu.
Human decisions needed: H5, H9, H10.
Next package: S1-P4 (granskas), S1-P11 (omkontroll), därefter S1-P12.

---

Package: CRM-affär → offert (lucka 1, sammanhängande flöde)
Status: done (CRM → Affärer, "Ny offert" på en öppen affär)
Evidence: `tests/Feature/Crm/DealQuoteLinkTest.php` (25 tester).
- "Ny offert" gör en offert med en rad från affärens namn och värde, eller från en offertmall, och kopplar den till affären. Order och faktura som följer offerten kopplas också, och förväljs när affären vinns ("Faktura (utkast)" visas).
- Momsen på offertraden följer samma regel som fakturan (`DefaultVat`): 0 när företaget inte är momsregistrerat, vid omvänd skattskyldighet och vid momsfri försäljning utomlands. Ett test jämför offertens och fakturans moms för varje typ av utlandsförsäljning.
- Bara öppna affärer ändras, och bara tomma kopplingar fylls. En andra "Ny offert" ersätter kopplingen och rensar den gamla offertens order och faktura; ett dubbelklick från ett inaktuellt kort gör högst en offert.
- Fast antal frågor på affärskortet, tenancy-test (egen mall med en annan företags affär ger 404).
- Kall granskning (1 hög, 3 medel, 3 låga) och en omkontroll; allt rättat, sista lilla felet (radfel i mallväljaren syntes inte) rättat av Lead. Hela sviten efter sammanslagning: 6 779 gröna, phpstan och pint rena.
Kända begränsningar: offertrader från en mall behåller mallens moms även för kunder med omvänd skattskyldighet (samma som offerter i övrigt; följs upp i kärnans offerter, SaveSalesDocument); offerter kan inte ha OSS-satser; `DefaultVat` och SaveDraftInvoice har samma villkor på två ställen (skyddas av testet).
Next package: S1-P11 (rättar NEW-1), S1-P4 (rättar granskningen), därefter S1-P12.

---

Package: S1-P11, Bokslut → Regelverk (K2 eller K3 per räkenskapsår, ADR-059)
Status: done (beta, `/bokslut/regelverk`)
Evidence: `tests/Feature/AnnualReport/K3/ClosingFrameworkReviewTest.php`, `tests/Feature/AnnualReport/K3/FrameworkChangeChainTest.php` (granskarens fem kedjetester), övriga K3-tester.
- Sidan går igenom K2 1.1–1.1C för ett räkenskapsår och visar resultatet innan något sparas: får använda K2, måste använda K3, eller uppgifter saknas (blockerar aldrig).
- Byte till K2 prövas för valt år och alla senare år inom låset; ett senare år bedöms som om året före redan är K2, så ett år som bara kräver K3 för att året före är K3 hindrar inte bytet.
- Okänt undantag i 1.1C ger "uppgifter saknas", aldrig "måste använda K3". Bekräftelsen av K2-villkoren i bokslutet spärras bara när året måste använda K3.
- Svaren sparas alltid (även av redovisningskonsulten); bara byte till K2 kan spärras.
- Kall granskning (1 P1, flera P2/P3), två omkontroller; allt rättat. Instabila `JsonDumpWriterTest` (letade efter "1212" som id:n också kan innehålla) rättad på samma gren. Hela sviten efter sammanslagning: 6 830 gröna, phpstan och pint rena.
Public claim now allowed: inget nytt. Public claim still not allowed: "Årsredovisning enligt K3", "Kassaflödesanalys".
Open legal-verify items: 1.1B b "färdigställd" årsredovisning; 1.1A b, c och f frågas i stället för att läsas ur data.
Follow-ups: visa uppskjuten skatt (S1-P5) som information på Regelverk.
Next package: S1-P4 (sista regeln: inget byte till K2 efter komponentuppdelning), därefter S1-P12.

---

Package: ADR-071, tillverkning, inköpsordrar och kundorder (plan)
Status: queued, sist i den prioriterade ordningen nedan (ägaren 2026-11-08: byggs som moduler, men först när punkterna före är klara)
Evidence: `docs/adr/ADR-071-manufacturing-purchase-orders-and-customer-ordering.md`, färdplanen (`stycklistor-och-tillverkning`, `inkopsorder`, `produktionsterminal`, `orderportal-for-kunder`).
Byggordning, varje steg med regelunderlag där bokföring eller personuppgifter berörs, tester först, kall granskning och omkontroll före sammanslagning:
1. Inköpsordrar i lagermodulen (utkast, förslag från beställningspunkt, PDF och mejl, inleverans mot order, avstämning mot leverantörsfaktura).
2. Modulen Tillverkning: stycklistor i flera nivåer med versioner, arbetssteg med timkostnad, orderns egen stycklista, intern kalkyl och marginal på offert och order.
3. Materialkoll för hela antalet och inköpsförslag per leverantör från en order.
4. Tillverkningsordrar, reservationer och lagerrörelserna "Uttag till tillverkning" och "Inleverans från tillverkning". Väntar på regelbladet `docs/specs/rules/tillverkning.md` (ÅRL 4:9, K2, K3, BAS) och redovisningskontrollen.
5. Verkstadsterminalen och analys per produkt och arbetssteg.
6. Kunder lägger order i kundportalen (status "Inkommen", granskning, bekräftelse).
7. Preliminärt leveransdatum ur material, kö och historik.
8. Analys per person, bara efter regelbladet `docs/specs/rules/produktionsuppfoljning.md` (GDPR, MBL) och juristens svar.
Next package: inget här förrän punkt 1–10 i den prioriterade ordningen är klara. Ett påbörjat utkast stoppades 2026-11-08; arbetet börjar om från ADR-071 när det är dags.

---

Package: S1-P4, komponentavskrivning för K3 (ADR-059)
Status: done (beta, Anläggningsregister → Dela upp i komponenter)
Evidence: `tests/Feature/Assets/ComponentsTest.php`, `tests/Feature/Assets/ComponentsReviewTest.php`, `tests/Unit/Assets/ComponentSplitTest.php`, `tests/Feature/AnnualReport/K3/ComponentScheduleFixtureTest.php`, `tests/Tenancy/FixedAssetComponentTenancyTest.php`.
- En tillgång i ett K3-år kan delas upp i komponenter (mark, stomme, tak, installationer m.m.) med egna nyttjandeperioder. Restvärde och total nyttjandeperiod föreslås, och kontot för en komponent ska ligga i 1100–1299.
- Uppdelningen kräver att registrets avskrivningar är bokförda fram till uppdelningsdagen; låst period och stängt år vägras. Har inget bokförts i registret ges rätt råd beroende på om historiken finns utanför registret.
- Byte till K2 eller K1 vägras för år där en aktiv komponent (utom mark) skrivs av. Uppdelning och byte av regelverk låser alla berörda år mot varandra.
- Kall granskning (flera P2), två omkontroller; allt rättat. Hela sviten efter sammanslagning: 6 901 gröna, phpstan och pint rena.
Public claim now allowed: inget nytt. Public claim still not allowed: "Årsredovisning enligt K3", "Kassaflödesanalys".
Follow-ups: koppla registret till uppskjuten skatt på byggnader (S1-P5, `BuildingCarryingAmounts`); omräknade jämförelsetal för komponenter i S1-P12.
Next package: S1-P12 (övergång K2 → K3).

---

Package: Körjournal och kvitto i mobilen (prioritet 3, ADR-072)
Status: done (Min sida → Körjournal, Bokföring → Körjournal, Inkorg → Fota kvitto; avstängt tills företaget slår på det)
Evidence: `tests/Feature/Mileage/*`, `tests/Feature/Inbox/ReceiptUploadTest.php`, `tests/Tenancy/MileageTenancyTest.php`.
- Anställda skriver in resor på Min sida och skickar en månad som milersättning; den godkänns under Lön → Ansökningar och betalas med lönen. Skattefri milersättning för förmånsbil bara i förarens egen förmånsbil; poolbil och annans förmånsbil blir skattepliktig lön.
- Varje resa prissätts efter reglerna för det år den kördes. 2027 års belopp (2,50 kr/km egen bil, 0,95/1,20 kr/km förmånsbil) följer IL 12 kap. 5 §. Ruta 050 kryssas även för förmånsbil.
- Rättelser prissätts som raden de rättar; en betald eller bokförd resa kan inte ändras (databasspärr). Ägaren till en enskild firma får ett verifikationsutkast (debet valt kostnadskonto, kredit 2018).
- Fota kvitto: kvittot går till inkorgen och kopplas en gång till verifikationen; inget bokförs före godkännande. Ingen GPS.
- Kall granskning (1 P1, 6 P2, flera P3) och omkontroll; allt rättat, även omkontrollens tre P3. Rättat i regelbladet efter citatkontroll: KJ-R13 och momshänvisningen.
Open legal-verify items: L4, L6, L8/L9 (konto för ägarens avdrag), L12–L16, prissättning efter resans år.
Next package: Arbetsorder och ÄTA (prioritet 5) när signeringsflytten i kundportalens gren är granskad; aktieboken (prioritet 2) när granskningen är klar.

---

Package: Arbetsorder och ÄTA (prioritet 5, ADR-074)
Status: done (modul Arbetsorder, `/arbetsorder`; på väg på startsidan tills en riktig skärmbild finns)
Evidence: `tests/Feature/WorkOrders/*`, `tests/Feature/Invoicing/TaxReductionWorkDetailsTest.php`, `tests/Feature/Signing/*`, `tests/Tenancy/WorkOrdersTenancyTest.php`.
- Arbetsorder med tid och material som aldrig ändras (motpost vid fel), lageruttag och vidarefakturering av en leverantörsfakturarad en gång; fakturaunderlag blir fakturautkast.
- ROT och RUT bara på arbete, arbetade timmar och typ av arbete på fakturan, flera köpare inom var sitt tak; en kreditfaktura återför varje köpares eget avdrag. Varje faktura tar de timmar som inte redovisats på en tidigare faktura för samma arbetsorder. Personnummer skrivs bara ut på fakturor utfärdade från denna version.
- ÄTA med pris innan arbetet, låst när det skickas, godkänns via länk eller på plats med kod till kundens egen e-post; muntligt godkännande och arbete utan svar (KtjL 8 §) sparar sin PDF. Utgångna ÄTA markeras av ett schemalagt jobb.
- Signeringen flyttad från CRM till kärnan (`app/Domain/Signing`); offertlänkar fungerar utan CRM, och länkar som stängdes när CRM var av spärras vid driftsättning. Integritetspolicy och biträdesavtal version 1.7 (gäller 2027-01-18).
- Bokslutssteget Pågående arbeten skapar bara verifikationsutkast.
- Kall granskning (2 P1, 5 P2, 10 P3), omkontroll och signeringsgranskning; allt rättat.
Open legal-verify items: L1–L16 i regelbladet, ROT-faktura utan nya timmar (HFL 9 § p. 4), personnummer på ROT-fakturan (L6).
Next package: aktiebok (prioritet 2), kök (prioritet 4), kundportal (prioritet 6) när deras granskningar är klara.

---

Package: Aktiebok och styrelserum (prioritet 2, ADR-070)
Status: done (modul för aktiebolag, beta; kan bara slås på när `NONOX_REGISTER_HASH_KEY` finns på servern)
Evidence: `tests/Feature/Boardroom/*`, `tests/Tenancy/TenancyIsolationTest.php`.
- Aktieboken som en händelselogg som bara kan skrivas till (databasspärr), med löpnummer och hashkedja där person- och id-nummer bara ingår som HMAC med en egen nyckel; `share-register:verify` varje natt. Varje händelse prövas mot hela historiken och vid varje datum då aktieboken ändras.
- Fullständig aktiebok som granskningsexemplar (5:10) och en frivillig kopia utan personnummer; förbehåll ur bolagsordningen skrivs ut. Jämförelse mot 2081 utan bokföring.
- Stämmor och styrelsemöten med dagordning (låst numrering efter kallelse), kallelsedatum som råd, protokoll "Godkänt i NONOX" låsta som PDF med SHA-256, justerare enligt 7:48 och 8:24, röstlängd i stämmoprotokollet. Byrå får föra boken först med registrerat bemyndigande (5:9).
- Kall granskning (5 P2, 9 P3), omkontroll; allt rättat.
Open legal-verify items: L1–L10 i regelbladet, 2082 för oregistrerat aktiekapital, omvänd split med rest.
Follow-ups: samma HMAC-skydd för personalliggarens hashkedja (i drift; kräver versionerad övergång).

---

Package: Recept och svinn, modulen Kök (prioritet 4, ADR-073)
Status: done (modul Kök, `/kok`)
Evidence: `tests/Feature/Kitchen/*`, `tests/Feature/Inventory/*`, `tests/Tenancy/KitchenTenancyTest.php`.
- Gemensam strukturmotor i lagret (versioner per datum, underrecept, loopspärr, spill), som tillverkningen (ADR-071) återanvänder; sparade versioner kan inte ändras (databasspärr).
- Lagerslag med eget kontopar; råvaror bokförs på 1410/4910 vid bokslut, och ett kontopar med saldo tas alltid med. 97 %-regeln bara för K2-år, avstängd från början.
- Recept, meny, svinn (även i skannern), avvikelser per lagerplats, personalmat som förslag till lönen med tak per måltid; sena måltider in i nästa öppna lönekörning samma år. Ingen verifikation per svinn eller måltid, ingen moms på personalmat.
- Kall granskning (1 P1, 8 P2, 10 P3), två omkontroller; allt rättat.
Open legal-verify items: L1–L11 i regelbladet, sen förmån i en senare månads AGI.

---

Package: Kundportal (prioritet 6, ADR-075)
Status: done men spärrad (`NONOX_CUSTOMER_PORTAL`) tills juristen svarat på L1, L2, L4 och L5
Evidence: `tests/Feature/Portal/*`, `tests/Feature/Invoicing/IssuedInvoicePdfTest.php`, `tests/Feature/Signing/*`, `tests/Tenancy/PortalTenancyTest.php`.
- Egen inloggning för kundens kontakter (egen guard och cookie, kod per målgrupp, inbjudan med länk och kod), allt avgränsat till företag och kund.
- Fakturor, krediteringar, påminnelser, kontoutdrag, betalningsuppgifter, "Jag har betalat", frågor och bestridanden med bilagor, offerter som godkänns med samma bevis som signering.
- Fakturans PDF sparas när den utfärdas eller först behövs och används överallt. Signeringen ligger i kärnan.
- Kall granskning (4 P2, 12 P3), två omkontroller; allt rättat. Portalen kan inte slås på med synkron kö.

---

Package: Statussida och säkerhet (prioritet 9, ADR-078)
Status: done (`/status`, `/.well-known/security.txt`; säkerhetspolicyn dold tills `NONOX_SECURITY_POLICY_PUBLISHED` slås på efter juristens granskning)
Evidence: `tests/Feature/Status/*`, `tests/Feature/Hq/HqStatusIncidentsTest.php`.
- Publik status för appen, databasen, kön, schemalagda jobb och integritetskontrollen (bara "har körts"), bank och Peppol efter globala signaler; driftstörningar under /hq.
- security.txt enligt RFC 9116 med utgångsdatum 364 dagar efter bekräftelse.
- Kall granskning (2 P1, 7 P2, 6 P3), två omkontroller; allt rättat.
Follow-ups: koppla `ledger:verify`-larmet till mejl eller Nightwatch på nonox.app; backupens heartbeat.

---

Package: Prova utan att registrera (prioritet 8, ADR-077)
Status: done men avstängd (`demo.enabled` av överallt); påslag i produktion kräver demodatabas, köarbetare och ägarens ja
Evidence: `tests/Feature/Demo/*`, `tests/Feature/DemoRebuild/*`.
- Ägarens beslut: demon körs i en egen databas (`<huvuddatabas>_demo`, samma server). Produktionsdatabasens spärrar har inga undantag.
- En besökare får ett eget demoföretag i två timmar; allt utåt (mejl, HTTP, Peppol, bank, betalfiler, API-nycklar, AI) är spärrat, demofiler har egna mappar, ingen IP-adress sparas. Demodatabasen byggs om i stället för att rader raderas, bara när ingen demo pågår.
- Kall granskning i två omgångar (5 P1 i första designen, 5 P1 i den nya), omkontroller; allt rättat.
Driftsättning: `docs/drift/demodatabasen.md` (migrering med `--database=demo` vid varje deploy, `queue:work demo`, TRUSTED_PROXIES).

---

Package: Flytt från fler program (prioritet 7, ADR-076)
Status: done (`/bokforing/las-in-uppgifter`; flyttguiderna per program dolda tills juristen granskat dem)
Evidence: `tests/Feature/Import/*`, `tests/Tenancy/RegisterImportTenancyTest.php`.
- Kunder, leverantörer och artiklar från CSV eller Excel med egen kolumnkoppling och mallar (Spiris, Bokio delvis); kontroller av org-/personnummer, bankgiro och plusgiro; dubbletter hoppas över och samma fil kan köras igen.
- Obetalda fakturor läses in utan verifikation, behåller sitt gamla nummer ("Faktura X (tidigare program)"), kan inte skickas eller få NONOX-PDF och stäms av mot 1510/2440 inklusive NONOX egna öppna fakturor. Betalning matchas på gammalt OCR eller nummer; betalfiler får unika id per fil och plats.
- Underlag i ZIP med kontroller mot farliga sökvägar och filer. Nytt paket: openspout/openspout (MIT).
- Kall granskning (1 P1, 7 P2), tre omkontroller; allt rättat.
Open legal-verify items: obetalda fakturor för företag med kontantmetoden (spärrat), moms vid bortskrivning.

---

Package: Egna moduler, steg 1–3 (prioritet 10, ADR-079)
Status: done (koden); omställningen av nonox.app till separata databasanvändare väntar på ägarens beslut om separat webbanvändare och ett underhållsfönster
Evidence: `tests/Feature/Security/*`, `tests/Feature/Modules/ModuleRegistryHardeningTest.php`.
- Modulregistret: en modul kan inte skriva över en annan eller ta kärnans behörigheter och roller; kontrollen görs innan något startar; tredjepartsmoduler heter `leverantör.namn`.
- Databasroller (av tills `DB_MIGRATION_USERNAME` sätts): ägare för migreringar, appanvändare utan rätt att ändra tabeller eller spärrar, läsanvändare. Rättighetsbeslut för alla 280 tabeller. Spärrfunktionerna låses till `public, pg_temp` efter varje migrering. Signerade offerter, personalliggarens poster inom lagringstiden och signeringsbevis kan inte raderas i databasen.
- Runbook `docs/drift/databasroller.md`: kräver att PHP-FPM, kö och schemaläggare körs som en egen användare utan skrivrätt till koden.
- Kall granskning i fyra omgångar; allt rättat. Säkerhetshålen med tillfälliga tabeller och utfärdade fakturor som blev utkast stängdes och driftsattes separat (0.119).
Follow-ups: steg 4–10 (appar, SDK) väntar på ägarens frågor 1–4 och 10; spärrar på `fiscal_years`, låsta perioder och nummerräknare.

---

Package: S1-P12, övergång från K2 till K3 (ADR-059)
Status: done (beta, Bokslut → Övergång till K3)
Evidence: `tests/Feature/AnnualReport/K3/K3TransitionTest.php`, `tests/Feature/AnnualReport/K3/K3TransitionReliefTest.php`, `tests/Tenancy/K3TransitionTenancyTest.php`, fixture `tests/Fixtures/s1/f-s1-b/`.
- För det första K3-året läggs skillnaderna mot K2 upp (uppskjuten skatt, avsättningar m.m.); avstämningen av eget kapital och texten om jämförbarheten går till årsredovisningen. Efter bekräftelse bokförs de en gång på årets första dag mot balanserad vinst (källa `K3_TRANSITION`); K2-året ändras inte.
- Omräkningar som K3 förbjuder vid övergången vägras; lättnadsregeln för uppskjuten skatt kräver ett skäl och redovisas alltid. En helt återförd övergång kan ogiltigförklaras, bara i sitt eget öppna år.
- Övergångsraden kan inte raderas och förslagets rader låses när övergången bokförs (databasspärr + rättighetsbeslut).
- Redovisningskontroll, kall granskning och omkontroller; allt rättat. Hela sviten efter sammanslagning: 8 183 gröna (det enda röda var en omigrerad demodatabas i testmiljön, 80/80 gröna efter migrering), phpstan och pint rena.
Public claim now allowed: inget nytt. Public claim still not allowed: "Årsredovisning enligt K3", "Kassaflödesanalys".
Next package: S1-P9, S1-P10, S1-P13, S1-P14.

---

Package: NONOX SaaS, steg 1 (prioritet 12, ADR-080)
Status: done (modul NONOX SaaS, `/aterkommande-intakter`; avstängd tills företaget slår på den; Stripe är steg 2)
Evidence: `tests/Unit/Saas/*`, `tests/Feature/Saas/*`, `tests/Tenancy/SaasTenancyTest.php`.
- MRR, ARR, månadens brygga (ny, utökning, minskning, paus, churn, återaktivering, valuta, källa av eller på), NRR/GRR, kohorter och ARPA ur avtalsfakturering och CRM-avtal. Bryggan går jämnt upp på öret per kund och månad.
- Versioner, rättelser och stängda månader är append-only i databasen; en stängd månad rättas bara i första öppna månad. `saas:verify` räknar om stängda månader varje natt. Ingen fritext i raderna som inte kan raderas; texterna hämtas från källan när sidan visas.
- "Gäller från" på avtalsfakturering; framtida ändringar, flyttade datum och avslut av pausade avtal versionsförs rätt. Möjlig dubbelräkning (CRM-avtal + avtalsfakturering utan koppling) visas under Källor och kan markeras som separata avtal.
- Kall granskning (1 P1, 6 P2, 6 P3) och fyra omkontroller med nya fynd varje gång; allt rättat. Hela sviten efter sammanslagning: 8 298 gröna; de 21 röda var den kända låsningen i databasrollernas testharness, 39/39 gröna när filerna körs ensamma. phpstan och pint rena.
Open legal-verify items: GDPR-avvägning för modulen, lagringstid för raderna, om biträdesavtalets ändamålslista ska nämna återkommande intäkter.
Follow-ups: CRM `RenewAgreements` skriver ingen version vid förnyelse av icke-automatiska avtal; egen deadline-typ i Mina datum i stället för Bokslut; steg 2 (Stripe-import) måste göra alla externa anrop utanför ledger-låset.
Next package: steg 2, Stripe i NONOX Connect (läsnyckel), därefter steg 3a (utbetalningar och avgifter).
