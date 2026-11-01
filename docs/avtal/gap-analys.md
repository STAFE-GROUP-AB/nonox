# NONOX Avtal — gapanalys mot lönemotorn

Datum: 2026-11-01. Jämför vad avtalen i våg 1 kräver (se avtalsfilerna i
den här mappen) med lönemotorn i `app/Modules/Payroll` som den ser ut på
`main` i dag. Radhänvisningar gäller den versionen.

Kort sagt: motorn räknar **lagens** lön korrekt (sjuklönelagen,
semesterlagen, skatt, avgifter, en pensionsplan) men **läser inget avtal**.
Allt som är avtalsspecifikt — OB, övertid, mertid, minimilöner, storhelg,
avtalsförsäkringar — måste i dag räknas för hand och matas in som belopp.

## 1. Det som finns och går att bygga vidare på

| Område | Var | Kommentar |
|---|---|---|
| Ren beräkningskedja i steg | `Domain/Engine/PayrollCalculator.php:30-45` | 12 steg i fast ordning. Nya avtalssteg kan läggas in här (eller registreras av Avtal-modulen). |
| Kontext utan databas | `Domain/Engine/PayrollContext.php:26-51` | Saknar fält för avtal; ett `?AgreementRuleSet` läggs till här. |
| Versionerade, verifierade regler som stoppar körningen | `Domain/Rules/RuleSet.php:81-113`, migration `2026_10_30_180000_create_payroll_rule_versions_table.php` (trigger), `2026_10_30_210000_create_payroll_rule_verifications.php` | Exakt det mönster avtalsversioner ska följa (B.6). |
| Avtalstabell och koppling på anställningen | `Models/CollectiveAgreement.php:13-34`, `database/migrations/2026_10_30_170000_create_payroll_tables.php:28-35` och `:100` | `rules jsonb` finns, men **ingen kod läser den**: `Support/Run/ContextBuilder.php:52-96` bygger kontexten utan att ladda avtalet. |
| Löneart för OB och övertid | `Domain/PayCodeCatalog.php:21-22` | En löneart `OVERTID` och en `OBTILLAGG`. OB är semestergrundande, övertid inte — stämmer för de flesta avtal men ska styras av avtalet. Endast **en** övertidsart (ingen enkel/kvalificerad). |
| Prissättning av timrader | `Domain/Engine/Steps/Additions.php:30-35` | Timmar utan sats prissätts med anställningens timlön. OB i kr/tim eller % av timlön kräver att någon annan räknar fram satsen. |
| Helgdagskalender | `Support/Calendar/SwedishHolidays.php:57-85` | Lagens helgdagar inkl. midsommardagen och alla helgons dag. Aftnar finns bara för semesterdagsberäkning (`:41-54`), inte som egna dagtyper. Saknar påskafton, trettondedagsafton, valborg, dagen före Kristi himmelsfärd. |
| Sjuklön och karens | `Domain/Engine/Steps/SickPay.php:34-106` | Lagens regler. Underlaget är timvärdet av lönen (`:50`, `:83`) — **OB och tillägg ingår inte**, vilket sjuklönelagen 6 § ("anställningsförmåner") och avtalen kräver. Ingen sjuklön dag 15–90 (tjänstemannaavtal). |
| Timvärde | `Domain/Engine/HourlyValue.php:25-48` | Månadslön × 12 / 52 / veckotimmar. Avtal anger ofta egen delare (månadslön/174, /165 o.s.v.) — måste kunna styras. |
| Semester | `Domain/Engine/Steps/Vacation.php:42-196`, `Support/Vacation/VacationLedger.php:501` | Lagens 25 dagar eller `vacation_days_per_year` per anställning; 12 % och 0,43 %. Avtalens högre procentsatser, högre tillägg, extra dagar efter ålder och minsta semesterlön per dag saknas. |
| Pension | `Domain/Engine/Steps/Pension.php:28-65`, `Actions/SavePensionPlan.php:30-34` | En sats under och en över 7,5 IBB per månad. Gränsen 7,5 är en konstant i koden (`:28`). Saknar ålder från vilken premie betalas, ITP2 (förmånsbestämd), och avtalsförsäkringar (AGS, TFA, TGL, omställning). |
| Arbetsschema | `Support/Run/ContextBuilder.php:155-169` | Timmar per veckodag; roterande scheman använder bara första veckan (TODO i koden). **Inga klockslag.** |
| Tidrapporter | migration `create_payroll_tables.php:285-295` | `date` + `hours` + löneart. **Inga start- och sluttider.** |
| Personalliggaren | `StaffLedger/Domain/Shift.php:14-27`, `StaffLedger/Actions/TimeProposals.php:46-57` | Passen har in- och utcheckning med klockslag, men förslaget till tidrapport plattas till timmar per dag (`:56`). Klockslagen finns alltså — de försvinner på vägen till lönen. |

## 2. Det som saknas

Storlek: **S** ≈ dagar, **M** ≈ 1–2 veckor, **L** ≈ 3+ veckor, inklusive
tester och gyllene lönespecar.

| # | Mekanik | Krävs av | Saknas i dag | Storlek |
|---|---|---|---|---|
| G1 | **Avtalsregler som data**: `agreement_rule_versions`, verifiering, import, JSON-schema, blockering i checklistan | alla | Tabellen `collective_agreements` finns men är oanvänd | M |
| G2 | **Pass med klockslag** (`work_shifts`) från manuell inmatning, personalliggaren och schema | alla med OB | Tidrapporter har bara timmar (`create_payroll_tables.php:290`) | M |
| G3 | **Passdelning i tidsfönster** (`ShiftSplitter`): dela pass över midnatt, dygnsbrytning, veckodag, dagtyp, OB-fönster; överlapp (högsta gäller / kumulativt); raster | Gröna Riks, Detaljhandel, Lager, Frisör, Bageri | Finns inte | L |
| G4 | **Avrundning av tid**: varje påbörjad halvtimme som full (OB i Gröna Riks och Visita–Unionen; övertid i Gröna Riks och Visita–Unionen), endast hela halvtimmar (övertid och löneavdrag i Handelns tjänstemannaavtal), förlängning ≤ 10 min är inte övertid (Gröna Riks) | Gröna Riks, Visita–Unionen, Handelns tjm | Finns inte | S |
| G5 | **Avtalskalender**: storhelger och aftnar med klockslag (påskafton, midsommarafton, julafton, nyårsafton, trettondedagsafton, dagen före Kristi himmelsfärd, valborg) | Gröna Riks, Detaljhandel, Lager, Bageri | `SwedishHolidays` har lagens helgdagar, aftnar bara som semesterregel (`:41-54`) | S |
| G6 | **OB-prissättning**: kr/tim, % av egen timlön, % av gruppens minimilön, satssteg per datum | alla med OB | `Additions` prissätter bara med timlön (`Additions.php:30`) | M |
| G7 | **Övertid och mertid**: tröskel mot schema och heltid per dag/vecka, stegar (enkel/kvalificerad, delare som /94 och /72, procent), mertid för deltid, komptid som saldo och uttag | alla | En löneart `OVERTID`, timmar och sats matas in för hand (`PayCodeCatalog.php:21`) | L |
| G8 | **Komptidssaldo**: ackumulator, uttag som frånvaro, utbetalning vid anställningens slut | alla | Ingen ackumulator för komptid (`Steps/Accumulators.php`) | M |
| G9 | **Minimilönekontroll** per grupp, ålder (14–20 år), branschvana (månader med minst 44 tim i Detaljhandel, 75 %/1 300 tim per år i Gröna Riks, 5 000/3 000 tim i Frisör) och steg per datum, som varning eller stopp | Gröna Riks, Detaljhandel, Lager, Frisör, Bageri | Finns inte. `employees.birth_date` finns; branschvana och lönegrupp saknas på anställningen | M |
| G10 | **OB och tillägg i sjuklön**: sjuklönen ska räknas på det den sjuke skulle ha tjänat enligt schemat, inklusive OB | alla med OB (lag + avtal) | `SickPay.php:83` räknar bara timvärdet av grundlönen | M (kräver G2–G3 för schemalagda pass) |
| G11 | **Sjuklön dag 15–90** (tjänstemannaavtal: viss procent av lönen under/över 7,5 IBB) | Visita–Unionen, Handelstjänstemän | `SickPay.php:103-105` ger bara en varning efter dag 14 | S |
| G12 | **Karens i tid** (20 % av genomsnittlig veckoarbetstid, ingen sjuklön under karensen; oregelbunden tid som snitt över representativ period) och avtalens timvärden (månadslön × 12,2 / 52 / veckotid i Handelns tjm, × 12 / 52 i Visita–Unionen, 80 % av förlorad OB, frisör 93 % av garantilön) | Detaljhandel, Lager, Gröna Riks, Visita–Unionen, Handelns tjm, Frisör | Lagens beräkning på genomsnittsvecka (`SickPay.php:84-85`) | S |
| G13 | **Avtalets semesterregler**: 12,72 % (Gröna Riks), 13 % (Detaljhandel, Lager, Frisör), 13,2 % (bageri); sammalöneregel med 0,8 % + 12,72 %/0,5 % rörligt (tjänstemannaavtalen); garantibelopp per dag efter ålder/branschvana; semesterlönefaktor 1 796 för deltid; 4,6 %-regeln för semesterersättning och obetald dag; sparad semester 0,5 %/0,53 %; extra dagar vid övertidsundantag | alla våg 1 | Lagens värden ur `rules.json`; dagar per anställning manuellt | M |
| G14 | **Provision med garanti** (frisör): provisionsunderlag per period, garantilön, semesterlön på provision | Frisöravtalet | Bara `BONUS` som engångsbelopp | M |
| G15 | **Tillägg utanför OB-tabellen**: anställningstidstillägg (Detaljhandel, Lager), frysrumstillägg och skiftformstillägg (Lager, bageri), förskjuten tid i kr/tim (bageri), jour och beredskap med delare (Visita–Unionen), röda dagar och ATF-timmar som saldo (Gröna Riks, Visita–Unionen, Frisör, Handelns tjm), tidbank 10 min/vecka (Lager), minst 3 tim övertid på fridag | Detaljhandel, Lager, Gröna Riks, Visita–Unionen, bageri | Finns inte | S |
| G16 | **Kostavdrag enligt avtal** (nettoavdrag 55/56 kr per arbetsdag, månadsbelopp, tilläggstabell vid högre pris) och kostförmån = schablon − avdrag | Gröna Riks, Visita–Unionen | Kostförmån till Skatteverkets värde finns (`PayCodeCatalog.php:34-36`) | S |
| G17 | **Avtalsdelare** (timlön 1/166, 1/173, 1/174, 1/164; avdrag 1/167; dagsavdrag 4,6 %; övertid /94 och /72; förskjuten tid /600–/150) | alla våg 1 | Fast 12/52 i `HourlyValue.php:43-47` | S |
| G18 | **Pension per avtal**: SAF-LO och ITP1 med åldersgräns och regelversion per år (inte konstant), ITP2 som extern premie | alla | Konstant 7,5 IBB (`Pension.php:28`), ingen åldersgräns, mallar med fasta satser (`SavePensionPlan.php:30-34`) | M |
| G19 | **Avtalsförsäkringar** (AGS, TFA, TGL, omställning) som arbetsgivarkostnad per lönesumma, bokförd på 7570-serien/2740, och underlag för rapportering till Fora | alla | Finns inte | M |
| G20 | **Roterande scheman** (fler veckor), behövs för tröskel för övertid och för sjuklön enligt schema | restaurang, butik | Bara vecka 1 används (`ContextBuilder.php:155-169`) | S |
| G21 | **Lokala avvikelser** (overrides) per företag med revision och förklaring | alla | Finns inte | S |
| G23 | **Fora-rapport** per anställd och månad (arbetare) och årlig lönesumma (tjänstemän) | alla arbetaravtal | Finns inte | M |
| G22 | **Förklaring** per avtalsrad: fönster, timmar, sats, version, källa | alla | `PayslipLineResult::explanation` finns och räcker; avtalsstegen måste fylla den | S |

## 3. PAXml-import (byggs av en annan agent)

Ett företag som flyttar från ett annat lönesystem eller använder ett
externt schemasystem kan leverera **redan delade** rader (OB-timmar per
art, övertid per art, frånvaro) i PAXml. Det påverkar byggordningen:

- Sådana rader blir `payroll_inputs` med `source = IMPORT` och en
  avtalskod, och går **förbi** `ShiftSplitter` (G3). Om raden saknar belopp
  prissätts den med avtalets satser (G6).
- Därför kan G1 + G6 + G7-prissättning ge värde **före** G2–G3: kunder med
  externt schema får rätt OB-belopp redan när importen finns.
- Importen behöver en mappning från källsystemets löneartskoder till
  avtalskoderna i `pay_codes` (datamodell §2). Den mappningen ska ligga i
  Avtal-modulens inställningar, inte i importen.
- Dubbelräkning måste stoppas: en arbetsdag som har både importerade OB-rader
  och egna pass i `work_shifts` ger stopp i checklistan.

## 4. Utanför lönespecen men nödvändigt

- **Rapportering till Fora**: arbetarnas bruttolön ska rapporteras **varje
  månad per anställd** (personnummer, uppmärkningskod, kontant bruttolön i
  utbetalningsmånaden), senast sista dagen månaden efter utbetalningsmånaden,
  som JSON-fil eller via API
  (https://www.fora.se/foretag/rapportera-loner-och-premier/sa-rapporterar-du-loner-for-arbetare/,
  https://www.fora.se/for-leverantorer-av-lonesystem/rapportering-av-loner-for-arbetare/sa-fungerar-rapportering/).
  Tjänstemännens lönesumma rapporteras en gång per år i januari. Det här är
  en egen exportuppgift (G23, storlek M) som kan byggas direkt på godkända
  lönekörningar; Foras JSON-schema ska hämtas före bygget.
  **Collectum** (ITP-anmälan och lön): `TODO(legal-verify)` format och kanal
  (ADR-033 fråga 6).
- **AGI**: OB, övertid, provision och tillägg är kontant lön (fält 011) och
  påverkar inte fältkoderna. Avtalsförsäkringar och pensionspremier är inte
  lön och redovisas inte i AGI; särskild löneskatt deklareras på annat sätt
  (`Pension.php` bokför redan 7530/2514).
- **Bokföring**: avtalsförsäkringar behöver standardkonton (BAS 7570
  Premier för arbetsmarknadsförsäkringar, motkonto 2740). Kontrolleras mot
  BAS 2026 innan de läggs i `PayCodeCatalog::ACCOUNTS`.

## 5. Föreslagen byggordning

| Steg | Innehåll | Gap | Storlek | Ger |
|---|---|---|---|---|
| A1 | Modul `agreements`, tabeller, JSON-schema, import, verifiering, blockering, Lön → Avtal-sida, bindning per företag och anställning | G1, G21 | M | Grunden. Inga belopp ändras ännu. |
| A2 | Avtalskalender och dagtyper | G5 | S | Behövs av allt med storhelg. |
| A3 | Prissättning av avtalskoder (OB, övertid, mertid) från inputs med timmar — manuellt eller PAXml | G6, G17, G22 | M | Rätt belopp för kunder med extern schema-/OB-data. |
| A4 | Minimilönekontroll (varning) | G9 | S–M | Snabb nytta: fångar för låg lön för unga i butik och restaurang. |
| A5 | `work_shifts` + personalliggaren levererar pass med klockslag + manuell passinmatning | G2 | M | Klockslagen följer med till lönen. |
| A6 | `ShiftSplitter` + avrundning → OB-rader | G3, G4 | L | Automatisk OB för café, restaurang, butik. |
| A7 | Övertid/mertid mot schema, komptidssaldo, roterande scheman | G7, G8, G20 | L | |
| A8 | Sjuklön med OB enligt schema, dag 15–90, karens för oregelbunden tid | G10, G11, G12 | M | |
| A9 | Avtalets semesterregler | G13 | M | |
| A10 | Pension per avtal och avtalsförsäkringar, Fora-rapport (JSON/API) och Collectum-underlag | G18, G19, G23 | M–L | Rätt kostnad och bokföring. |
| A11 | Provision med garanti (frisör), fasta tillägg, måltidsavdrag | G14, G15, G16 | M | Frisör och restaurang fullt. |
| A12 | Avtalsfiler våg 1 + gyllene lönespecar (minst 10 per avtal: dag/kväll/natt/helg/storhelg, ung/vuxen, deltid med mertid, övertid, sjuk med OB, semester) | — | M per avtal | |

Våg 2 (tjänstemän Almega/Unionen, IT/konsult) behöver i stort sett bara
A1, A7 (övertid med delare och "övertid ingår"), A8 (dag 15–90) och A10
(ITP). Våg 3 (bygg, installation, måleri) kräver ackord, restidsersättning,
traktamente enligt avtal och arbetstidsförkortning — egen gapanalys när
vågen startar.

Status 2026-11-01: A1–A4 är byggda (systemdokumentation §5f). A1 utan
JSON-schema i CI utöver valideringen vid inläsning och testerna, och utan
lokala avvikelser (`overrides` finns som kolumn men används inte ännu, G21).
A3 prissätter redan uppdelade OB- och övertidsrader; mertid är inte data
ännu. Kvar: A5–A12.

## 6. Risker

- **Fel avtal på anställningen** är det vanligaste felet i praktiken.
  Avtal-sidan ska visa för varje anställd vilket avtal, vilken grupp och
  vilken minimilön som gäller, och checklistan ska varna för anställda utan
  avtal i ett företag som är bundet.
- **Tolkning**: avtal har undantag och lokala överenskommelser som inte går
  att koda. Allt som inte finns i datamodellen ska gå att lägga som manuell
  rad med förklaring, och modulen ska aldrig ge sken av att ha räknat det
  den inte räknar.
- **Underhåll**: se README §4. En missad lönerevision 1 april ger fel lön
  för alla kunder med det avtalet. Blockeringen (ingen verifierad version)
  är skyddet.
