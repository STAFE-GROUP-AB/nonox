# Juridisk utredning av öppna TODO(legal-verify) i NONOX

> **AI-utredning av offentliga källor, inte juridisk rådgivning. Ska granskas av jurist innan den ändrar beteende i systemet.**

Datum: 2026-11-02. Utredare: agenten `jurist` (AI), med fyra forskningsagenter för valuta/moms, lön, arbetstid/avtal och bokföring/GDPR/AI.

## Metod och begränsningar

- Frågorna kommer från 289 träffar på `TODO(legal-verify)` i `app`, `docs`, `database`, `PAYROLL_MODULE.md`, `PLANNING_MODULE.md` och `AGENTS.md`, från `docs/juridik/fragor-till-jurist.md`, systemdokumentationens avsnitt 8, ADR:ernas avsnitt om juridiska frågor och GitHub-ärendena #7, #8, #12 och #17 (etikett `juridik`). Dubbletter är sammanslagna.
- 44 frågor är utredda mot källor (prioriterade: de som ändrar hur programmet räknar eller bokför pengar). Ett 30-tal är **inte utredda** i den här omgången; de står i avsnitt 10 med var de finns.
- Källor i första hand: riksdagen.se (SFS-text, propositioner), bfn.se, bas.se, fora.se, collectum.se, av.se, parternas publicerade avtalstexter 2025–2027, Skatteverkets webbsidor.
- **Kunde inte öppnas:** EUR-Lex (sidan kom tom; direktivhänvisningar markeras *ej verifierad*), Skatteverkets rättsliga vägledning (blockerad; ställningstaganden lästes via FAR Online), BFN:s PDF:er för K2/K3 i fulltext (sammanfattningar via FAR Online och BFN:s vägledning Bokföring), Collectums premiesida 2026, prop. 2025/26:282 lästes till ungefär 55 procent. Inga AD-domar om avrundning eller genomsnittsvecka hittades.
- Citat är högst 25 ord. Där källorna är tysta står det, med den mest försvarbara läsningen och en säkerhetsnivå.

## Sammanfattning

| Id | Område | Fråga (kort) | Säkerhet | Koden stämmer? |
|---|---|---|---|---|
| V1 | Valuta | Kurs och datum för momsens beskattningsunderlag i utländsk valuta | Medel | Nej (datum) |
| V2 | Valuta | Moms i kronor på faktura i annan valuta | Hög | Ja |
| V3 | Valuta | Bokföringskurs vid transaktionen | Medel | Ja |
| V4 | Valuta | Omvärdering till balansdagens kurs | Medel | Delvis |
| V5 | Valuta | Kursdifferenser på 3960/7960 | Hög | Ja |
| V6 | Valuta | OSS-deklaration i euro | Hög | Nej |
| V7 | Moms | OSS-tröskeln 10 000 euro | Hög | Nej (saknas) |
| M1 | Moms | Ören stryks per ruta | Medel | Ja |
| M2 | Moms | Livsmedelsmoms 6 % 2026-04-01–2027-12-31 | Hög | Okänt |
| M3 | Moms/bokföring | Kontantmetoden: obetalda fakturor vid årets slut | Hög | Nej (saknas) |
| M4 | Fakturering | Påminnelseavgift och dröjsmålsränta | Hög | Ja |
| B1 | Bokföring | Underlag efter bokföring | Hög | Ja |
| B2 | Bokföring | `.eml` som räkenskapsinformation | Hög | Ja |
| B3 | Bokföring | Minsta längd på räkenskapsår | Hög | Nej |
| B4 | Bokföring | Vem får använda K1/K2/K3 | Hög | Nej |
| B5 | Bokföring | BAS-namn 7510–7570, 3960/7960 | Hög | Ja |
| B6 | Bokföring | BAS-licensen och öppet förråd | Medel | Nej (risk) |
| L1 | Lön | Genomsnittsvecka vid oregelbunden arbetstid (karens) | Medel | Delvis |
| L2 | Lön | Tio karensavdrag, återinsjuknande, rest av karens | Hög | Nej |
| L3 | Lön | Förlorad OB i sjuklönen | Medel | Ja |
| L4 | Lön | Avtalssjuklön dag 15–90 (tjänstemän) | Hög | Delvis |
| L5 | Lön | Övertid och komptid semesterlönegrundande | Hög | Nej |
| L6 | Lön | Januari med fjolårets skattetabell (SFL 11 kap. 16 §) | Hög | Nej |
| L7 | Lön | SINK 20 % från 2027 | Hög | Ja |
| L8 | Lön/GDPR | Bevarande av löneunderlag och frånvaro | Medel | Ja (försiktigt) |
| L9 | Lön | Semesterersättning: skatt och löpande utbetalning | Medel | Okänt |
| L10 | Lön | Avrundning av arbetsgivaravgifter | Medel | Okänt |
| A1 | Arbetstid | Övertid och mertid: per dag mot schemat, beräkningsperiod | Hög | Nej |
| A2 | Arbetstid | Avrundning av OB och övertid | Medel | Delvis |
| A3 | Arbetstid | Minderårigas arbetstid (AFS 2023:2 kap. 8) | Hög | Okänt |
| K1 | Avtal | Upphovsrätt till kollektivavtal | Medel | Okänt |
| K2 | Avtal | Hängavtalens namn och avgifter | Låg | Okänt |
| K3 | Avtal | Fora: filformat, avtal, kontant bruttolön | Medel | Ja (troligen) |
| K4 | Avtal | TSL-rad för Handels (utan Huvudavtal) | Medel | Okänt |
| K5 | Avtal | Collectum: ITP 1/2, åldrar, rapporteringsväg | Medel | Okänt |
| P1 | Personalliggare | Bevarandetid | Hög | Nej |
| P2 | Personalliggare | Uppgifter per person, arbetsgivarens identitet 2027 | Medel | Nej (saknas fält) |
| P3 | Personalliggare | Branscher | Hög | Delvis (bygg saknas) |
| P4 | Personalliggare | XML/API för överföring från 2027-07-01 | Medel | Okänt (ej publicerat) |
| G1 | GDPR | Art. 18 mot bokföringsplikten | Låg | Nej |
| G2 | GDPR | Frånvaro bakom sjuklön som räkenskapsinformation | Medel | Ja (försiktigt) |
| G3 | AI-förordningen | Förslag om lönegrupp och bilaga III 4 b | Medel | Nej (risk) |
| G4 | AI-förordningen | Kvittoläsaren | Medel | Ja |
| G5 | AI-förordningen | Datum enligt förordning (EU) 2026/1744 | Medel | Okänt |

Säkerhet: **Hög 21, Medel 21, Låg 2.** Koden avviker (Nej eller Delvis) i 20 av 44, stämmer i 14 och är okänd i 10. 33 frågegrupper är inte utredda (avsnitt 10).

## Topp 10: där koden sannolikt avviker från lagen

1. **L6 – Januarilönen blockeras.** SFL 11 kap. 16 § andra stycket tillåter skatteavdrag i januari enligt den tabell som senast tillämpades året innan. Koden blockerar körningen och hänvisar till fel paragraf (10 kap. 16 §). `app/Modules/Payroll/Actions/PayrollRuns.php:269`.
2. **L5 – Övertid och komptid räknas inte in i semesterlönen.** `OVERTID` och `KOMPTID` har `vacation: false`. Enligt semesterlagen 16 b § (procentregeln) ingår all förfallen lön utom tre uppräknade undantag. Undantag kräver avtal (Handels tjänstemän och Visita–Unionen har det). `app/Modules/Payroll/Domain/PayCodeCatalog.php:21-23`.
3. **L2 – Karensavdragen räknas och förs vidare fel.** Lagen räknar *tillfällen med karensavdrag* under tolv månader före den nya sjuklöneperioden (inte sjukperioder före löneperioden), och en karens som inte ryms dras på följande dagar i samma sjuklöneperiod och vid återinsjuknande inom fem dagar. Koden kapar och släpper resten. `ContextBuilder.php:146`, `Engine/Steps/SickPay.php:37`.
4. **A1 – Övertid räknas per kalendervecka.** Lagen och alla tre utredda avtal mäter övertid mot den ordinarie (schemalagda) tiden per dag och stämmer av ordinarie tid över avtalets beräkningsperiod. `app/Modules/Planning/Domain/PayrollBasis.php:99`.
5. **A2 – Övertid avrundas på periodens summa.** Gröna Riks avrundar per tillfälle, Visita–Unionen per dag; Detaljhandelsavtalet har ingen avrundning. Summan per period ger för lite. `app/Modules/Agreements/Domain/TimeRounding.php:13`.
6. **V6/V7 – OSS i euro och tröskeln saknas.** ML 22 kap. 42 § kräver belopp i euro; Skatteverket anger ECB-kursen för periodens sista dag. Tröskeln 10 000 euro (99 680 kr) prövas inte. `app/Domain/Vat/OssReport.php:19`, `OssRates.php:17`.
7. **P1 – Personalliggaren gallras ett år för tidigt vid brutet räkenskapsår.** Skatteförfarandeförordningen 9 kap. 5 § räknar två år efter utgången av kalenderåret då *beskattningsåret* gick ut. Gallringen går inte att ångra. `app/Modules/StaffLedger/Actions/PruneStaffLedger.php:24`.
8. **M3 – Kontantmetoden: obetalda fakturor bokas inte vid årets slut.** BFL 5 kap. 2 § kräver det och ML 7 kap. 16 § och 33 § styr momsen. Bokslutet har bara en TODO. `InvoiceBooking.php:38`, `SupplierInvoiceBooking.php:36`.
9. **V1 – Valutakursen för momsen tas på fakturadatum.** ML 8 kap. 21 § anger kursen när den beskattningsgrundande händelsen inträffar (normalt leveransen). Riksbankens mittkurs som sådan godtas troligen. `app/Actions/Invoicing/SaveDraftInvoice.php:40`.
10. **G1 – Leverantörsfakturor stoppas vid begränsad behandling.** Bokföringsplikten (BFL 4 kap. 1 §, 5 kap. 6 §) talar för att registrering och bokföring ska tillåtas och att bara annan användning spärras. `app/Actions/Privacy/RestrictProcessing.php:26`, ärende #7.

Fler avvikelser: B3 (räkenskapsår minst 6 månader, lagen har ingen minsta längd), B4 (alla K-regelverk tillåts för alla), B6 (BAS-listan i ett öppet förråd), L1 (4 veckors referensperiod mot propositionens 1–3 månader), L4 (105/45-dagarsregeln och dagstaket saknas), P2 (arbetsgivarens identitet från 2027-07-01), G3 (lönegruppsförslag kan vara högrisk).

## 1. Valuta (ADR-025)

Förkortning: ML = mervärdesskattelag (2023:200), https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/mervardesskattelag-2023200_sfs-2023-200/ (läst 2026-11-02).

### V1. Kurs och datum för momsens beskattningsunderlag
- **Var:** `app/Actions/Invoicing/SaveDraftInvoice.php:40`, `app/Support/Currency/RiksbankRates.php:25`, ADR-025:178.
- **Svar:** Paragrafen koden citerar är rätt: ML 8 kap. 21 §. Omräkning sker med den senaste genomsnittskursen på den mest representativa valutamarknaden i Sverige eller med ECB:s senaste kurs, och kursen tas "när den beskattningsgrundande händelsen inträffar". 8 kap. 22 § styr korskurser via euro. Riksbankens mittkurs bör räknas som den representativa marknadens kurs (Skatteverkets ställningstagande 2013-02-27 godtog mittkurs eller ECB-kurs; läst via FAR, kan vara inaktuellt). Datumet är däremot tidpunkten för händelsen (leverans, eller betalning vid förskott), inte fakturadatumet.
- **Grund:** ML 8 kap. 21–22 §§; direktiv 2006/112/EG art. 91 (*ej verifierad på EUR-Lex*); https://www.faronline.se/dokument/skatteverket/stallningstaganden2/2013/skvstallningst2013022702/
- **Säkerhet:** Medel (Hög på paragrafen, Medel på mittkursen och på datumet i praktiken).
- **Konsekvens:** Ta kursen på leveransdatum (`delivery_date` finns på fakturan) och på betalningsdagen vid förskott, med senast publicerade kurs på eller före dagen. Behåll möjligheten att ändra kursen per faktura. Om leveransdatum och fakturadatum ligger nära är skillnaden liten, men regeln ska följas.
- **Jurist:** Ja, bekräfta Skatteverkets nuvarande syn på mittkurs och på datum när faktura utfärdas före leverans.

### V2. Moms i kronor på faktura i annan valuta
- **Var:** `app/Domain/Invoicing/InvoicePdf.php:21`, ADR-025:167.
- **Svar:** ML 17 kap. 29 §: oavsett valuta ska momsbeloppet även anges i svenska kronor. Totalbeloppet i kronor krävs inte enligt den paragrafen. 7 kap. 28 § säger att köparen använder kronbeloppet.
- **Säkerhet:** Hög.
- **Konsekvens:** PDF:en visar redan momsen och summan i kronor. Ändra hänvisningen i TODO:n till 17 kap. 29 §.

### V3. Bokföringskurs vid transaktionen
- **Var:** `RiksbankRates.php:25`, ADR-025.
- **Svar:** BFNAR 2013:2 punkt 2.4 anger avistakursen på transaktionsdagen; punkt 2.5 godtar en approximation (t.ex. genomsnittskurs) om resultatet inte avviker väsentligt. K3 kap. 30 har samma huvudregel (ej öppnad i fulltext).
- **Grund:** BFN vägledning Bokföring, https://bfn.se/wp-content/uploads/vl13-2-bokforing.pdf ; https://www.faronline.se/dokument/rattserien/redovisa-ratt/v/rr_valutakursdifferenser/ (läst 2026-11-02).
- **Säkerhet:** Medel. **Konsekvens:** Riksbankens mittkurs på transaktionsdagen stämmer.

### V4. Omvärdering vid bokslut
- **Var:** `app/Domain/Closing/CurrencyRevaluation.php:35`, `ClosingChecklist.php:172`, ADR-025:172.
- **Svar:** ÅRL 4 kap. 13 § *tillåter* omräkning till balansdagens kurs. K2 gör den obligatorisk för långfristiga fordringar (11.8), kortfristiga fordringar (13.5) och skulder (17.8), med terminskurs om posten är säkrad. K3 kap. 30: monetära poster till balansdagskurs. Ingen nuvarande K2-regel om "lägsta av" hittades. Att boka den orealiserade differensen sista dagen och återföra den första dagen nästa år är en teknik som inte är förbjuden. För kontantmetoden finns ingen uttrycklig text; balansdagens kurs är den rimliga läsningen.
- **Grund:** ÅRL, https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/arsredovisningslag-19951554_sfs-1995-1554/ ; K2-punkterna via FAR (BFNAR 2016:10 ej öppnad i fulltext).
- **Säkerhet:** Medel (Låg för kontantmetoden).
- **Konsekvens:** Fakturametoden stämmer. Gör varningen i bokslutslistan till en tydlig uppmaning för K2/K3-företag (den är obligatorisk). Omvärdera även de obetalda fakturor ett kontantmetodsföretag bokar vid årets slut (se M3). Valutaterminer finns inte i NONOX; notera det.

### V5. Kursdifferenser på 3960/7960
- **Var:** `RiksbankRates.php:25`, ADR-025:181.
- **Svar:** K2 punkt 4.4: vinster på poster av rörelsekaraktär är övriga rörelseintäkter, förluster övriga rörelsekostnader. K3 30.7: rörelse- eller finansiell post efter vad som ligger bakom. BAS 2026: 3960 "Valutakursvinster på fordringar och skulder av rörelsekaraktär", 7960 motsvarande förluster.
- **Grund:** BAS kontoplan 2026, https://www.bas.se/wp-content/uploads/2026/04/BAS_kontoplan_2026_v2.pdf
- **Säkerhet:** Hög. **Konsekvens:** Stämmer för kund- och leverantörsfakturor. Lån i valuta hör till klass 8 (inte byggt).

### V6. OSS-deklaration i euro
- **Var:** `app/Domain/Vat/OssReport.php:19`, ADR-025:175.
- **Svar:** ML 22 kap. 42 §: belopp i en särskild mervärdesskattedeklaration "ska anges i euro". Perioden är kalenderkvartal (35 §), deklaration och betalning senast sista dagen i månaden efter (43–44 §§), betalning i euro. Skatteverket anger ECB:s kurs för sista dagen i redovisningsperioden; publiceras ingen kurs den dagen gäller nästa publiceringsdag (direktivet, *ej verifierad*). Rättelser görs i en senare deklaration inom tre år. Inget stöd hittades för att e-tjänsten räknar om kronor till euro.
- **Grund:** ML 22 kap. 35, 42–44 §§; https://www.skatteverket.se/foretag/moms/deklareramoms/ansokomattredovisadistansforsaljningionestopshoposs/deklareraochbetalamomsionestopshop.4.40cab8f8197edf03e644dee.html
- **Säkerhet:** Hög (Låg för vilket kvartal en försäljning hör till).
- **Konsekvens:** Räkna om summorna per land och sats till euro med ECB-kursen för kvartalets sista dag (nästa publiceringsdag som reserv) och visa både kronor och euro. ECB-kursen behövs som ny kurskälla.
- **Jurist:** Ja, för periodtillhörigheten.

### V7. OSS-tröskeln
- **Var:** `app/Domain/Vat/OssRates.php:17`.
- **Svar:** Tröskeln är 10 000 euro (99 680 kr enligt Skatteverket), en gemensam summa för distansförsäljning av varor och tele-, sändnings- och elektroniska tjänster till konsumenter i andra EU-länder, innevarande och föregående kalenderår (exklusive moms; *direktivets art. 59c ej verifierad*).
- **Grund:** https://www.skatteverket.se/foretag/moms/sarskildamomsregler/handelmedandralander/omsattningstroskelviddistansforsaljninginomeu.4.18e1b10334ebe8bc80005545.html
- **Säkerhet:** Hög. **Konsekvens:** Visa summan mot 99 680 kr och varna när den passeras.

## 2. Moms och fakturering

### M1. Ören per momsruta
- **Var:** `app/Domain/Vat/VatReturn.php:18`, ADR-010:33.
- **Svar:** Ingen gällande primärtext hittades. FAR och en äldre bilaga till Skatteverkets blankett säger att ören faller bort (strykning, inte avrundning). Momsen räknas i kronor och ören under vägen; på fakturan får momsbeloppet inte avrundas, bara slutsumman (öresavrundning).
- **Säkerhet:** Medel. **Konsekvens:** Koden stryker ören per ruta: stämmer. Kontrollera mot Skatteverkets tekniska beskrivning för filen/API:t.

### M2. Livsmedelsmomsen 6 %
- **Var:** `app/Modules/Inbox/Support/DailyReportVoucher.php:26`, ärende #17.
- **Svar:** ML 9 kap. 19 § (SFS 2026:118): 6 % på livsmedel (utom alkohol) från 2026-04-01. SFS 2026:119 återinför 12 % från 2028-01-01, alltså 6 % till och med 2027-12-31. Restaurang och catering (9 kap. 5 §) och hotell (9 kap. 4 §) ligger kvar på 12 %. Livsmedelsförsäljning redovisas i ruta 12.
- **Säkerhet:** Hög.
- **Konsekvens:** Satserna 25/12/6/0 räcker, men vilken sats en artikel eller dagsrapportrad har beror på datum. Lägg in satser per datum för livsmedel och kontrollera artiklar som byter sats 2028-01-01.

### M3. Kontantmetoden vid räkenskapsårets slut
- **Var:** `app/Domain/Invoicing/InvoiceBooking.php:38`, `app/Domain/Payables/SupplierInvoiceBooking.php:36`, ADR-011, ADR-013:63.
- **Svar:** BFL 5 kap. 2 § tredje stycket: vid räkenskapsårets utgång ska samtliga obetalda fordringar och skulder bokföras. ML 7 kap. 16 §: moms på fordringar som är obetalda vid årets slut redovisas i den period då räkenskapsåret slutar; 7 kap. 33 §: ingående moms dras i den perioden eller när betalning sker tidigare. Kreditinstitut och vissa finansiella företag är undantagna.
- **Grund:** https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/bokforingslag-19991078_sfs-1999-1078/
- **Säkerhet:** Hög.
- **Konsekvens:** Bygg ett bokslutssteg som bokar alla obetalda kund- och leverantörsfakturor med moms per sista dagen (balansdagens kurs för valuta) och återför dem nästa år så att betalningen inte bokas dubbelt. Momsen hamnar i årets sista momsperiod.

### M4. Påminnelseavgift och dröjsmålsränta
- **Var:** `app/Actions/Invoicing/SendReminder.php:26`, ADR-011:72, AGENTS.md:329.
- **Svar:** Lag (1981:739) om ersättning för inkassokostnader: påminnelseavgift bara om den avtalats senast när skulden uppstod (2 §), högst 60 kr (4 §); förseningsersättning 450 kr mellan näringsidkare (4 a §). Räntelagen: ränta från förfallodagen om den är bestämd i förväg (3 §), annars 30 dagar efter krav (4 §); referensränta + 8 procentenheter (6 §). Referensräntan är 2,00 % för 2026-07-01–2026-12-31, alltså 10,00 %.
- **Grund:** https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/lag-1981739-om-ersattning-for-inkassokostnader_sfs-1981-739/ ; https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/rantelag-1975635_sfs-1975-635/ ; https://www.riksbank.se/sv/rantor-och-valutakurser/referensranta/
- **Säkerhet:** Hög.
- **Konsekvens:** Att inte ta ut något är lagligt. Bygger man det: avgiften kräver avtalsvillkor (t.ex. på kunden), räntesatsen ska läsas från Riksbankens halvårsvärde. Hur förseningsersättningen samspelar med påminnelseavgiften är inte verifierat.

## 3. Bokföring och bokslut

### B1. Underlag efter bokföring
- **Var:** `app/Actions/Accounting/AttachToVoucher.php:27`, ADR-007:90.
- **Svar:** Tillåtet. BFL 5 kap. 6 § medger att en verifikation kompletteras; BFNAR 2013:2 punkt 5.8: det mottagna innehållet får inte ändras, och det ska framgå när, av vem och vad som lagts till. Övriga ändringar görs som rättelse (5 kap. 9 §, punkt 5.15).
- **Grund:** BFL; https://bfn.se/wp-content/uploads/vl13-2-bokforing.pdf (uppdaterad 2024-09-16).
- **Säkerhet:** Hög. **Konsekvens:** Stämmer så länge bilagor bara läggs till och loggas.

### B2. `.eml` som räkenskapsinformation
- **Var:** ärende #17, `docs/juridik/regelverk.md:63`, `app/Modules/Inbox/Console/PruneInboxCommand.php:25`.
- **Svar:** Godtagbart. Sedan lag 2024:342 ska elektroniska handlingar bevaras i det format och med det innehåll de hade när de kom till företaget (BFL 7 kap. 1 §). BFN nämner mejl uttryckligen. Att konvertera till PDF är en överföring (7 kap. 6 §).
- **Säkerhet:** Hög. **Konsekvens:** Spara rå `.eml` oförändrad, med kontrollsumma och bilagor; en bifogad faktura-PDF sparas som den kom. Frågan om ett avvisat underlag kan vara räkenskapsinformation (gallring efter 7 dagar) är inte utredd.

### B3. Minsta längd på räkenskapsår
- **Var:** `app/Domain/Accounting/FiscalYearRules.php:18`, AGENTS.md §6.2.
- **Svar:** BFL 3 kap. 1 § anger tolv månader; 3 kap. 3 § tillåter kortare tid eller högst arton månader när bokföringsskyldigheten börjar eller året läggs om, och förkortning när skyldigheten upphör. Det finns ingen lagstadgad minsta längd. Omläggning kräver i regel Skatteverkets tillstånd (3 kap. 6 §).
- **Säkerhet:** Hög.
- **Konsekvens:** `MIN_MONTHS = 6` saknar stöd. Tillåt 1–18 månader för första, ändrat och sista räkenskapsåret. AGENTS.md §6.2 bör ändras genom ett ADR (paragrafen ligger i §6, inte §1).

### B4. K1, K2 och K3
- **Var:** `app/Actions/Company/CreateCompany.php:118`.
- **Svar:** K2 (BFNAR 2016:10) får användas av mindre företag, dvs. inte "större företag" enligt ÅRL 1 kap. 3 § (noterade, eller fler än ett av: över 50 anställda, över 40 mkr balansomslutning, över 80 mkr nettoomsättning, två år i rad). K2 för årsbokslut är BFNAR 2017:3. Enligt bfn.se gäller nya versioner per räkenskapsår som börjar efter visst datum. K1 (förenklat årsbokslut, BFL 6 kap. 3 §) finns bara för enskilda näringsidkare (BFNAR 2006:1) och ideella föreningar och registrerade trossamfund (BFNAR 2010:1), vid nettoomsättning normalt högst 3 mkr. Inget K1 för HB. K3 är alltid tillåtet; större företag måste ha K3.
- **Grund:** https://www.bfn.se/redovisningsregler/vagledningar/k-regelverk/ ; ÅRL 1 kap. 3 §.
- **Säkerhet:** Hög.
- **Konsekvens:** Validera: K1 bara för EF och ideell förening/trossamfund med omsättning ≤ 3 mkr; K2 inte för större företag (fråga om storlek eller varna); K3 alltid. Versionera K-reglerna per räkenskapsårets start.

### B5. BAS-namn
- **Var:** `app/Modules/Payroll/Domain/PayCodeCatalog.php:90`, `docs/systemdokumentation/README.md:1887`.
- **Svar:** BAS kontoplan 2026 (fil "v2", rubrik v 1.1): 7510 Arbetsgivaravgifter 31,42 %, 7519 Arbetsgivaravgifter för semester- och löneskulder, 7530 Särskild löneskatt, 7570 Premier för arbetsmarknadsförsäkringar, 3960/7960 som i V5.
- **Säkerhet:** Hög. **Konsekvens:** 7570 stämmer. 7510 heter i BAS "Arbetsgivaravgifter 31,42 %" och 7519 "...för semester- och löneskulder"; justera namnen i katalogen om de ska följa BAS exakt.

### B6. BAS-licensen
- **Var:** `database/data/bas/README.md:14`, AGENTS.md:227, frågor till jurist 29.
- **Svar:** BAS allmänna villkor (version 2026-04-14) gäller den betalda maskinläsbara filen: licenstagaren får bygga in den i sitt eget system för vidarelicensering (4.3), rätten är personlig (4.4), ingen spridning utöver det (4.5) utan skriftligt godkännande, och BAS-kontogruppen har alla rättigheter (6.1). Ett öppet AGPL-förråd omfattas inte. Om kontonamnen i sig har katalogskydd (upphovsrättslagen 49 §) är inte verifierat.
- **Grund:** https://www.bas.se/wp-content/uploads/2026/04/Allmanna-Villkor-2026.pdf
- **Säkerhet:** Medel.
- **Konsekvens:** Lägg inte hela BAS-listan eller ett väsentligt utdrag i förrådet utan skriftligt medgivande från BAS-kontogruppen. Begränsa mallarna till de konton systemet själv behöver, eller be om tillstånd. **Jurist: ja.**

## 4. Lön, skatt och AGI

Förkortningar: SjLL = lag (1991:1047) om sjuklön, https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/lag-19911047-om-sjuklon_sfs-1991-1047/ ; SemL = semesterlag (1977:480), https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/semesterlag-1977480_sfs-1977-480/ ; SFL = skatteförfarandelag (2011:1244), https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/skatteforfarandelag-20111244_sfs-2011-1244/ (alla lästa 2026-11-02). Karensreformens proposition är **prop. 2017/18:96**, https://lagen.nu/prop/2017/18:96

### L1. Genomsnittsvecka vid oregelbunden arbetstid (karensavdrag)
- **Var:** `app/Modules/Agreements/Support/AgreementSickPayExtension.php:48`, `app/Modules/Payroll/Domain/Engine/HourlyValue.php:16`.
- **Svar:** SjLL 6 § säger bara att karensavdraget är 20 % av sjuklönen för en genomsnittlig vecka. Propositionen (s. 28–29): veckan är måndag–söndag; utgå från sysselsättningsgraden i avtalet; vid varierande tid får historiska uppgifter om arbetade timmar användas, och "en jämförelseperiod om en till tre månader" bör i de flesta fall räcka; annars skälighet; regelbunden mertid räknas med. Ett schema kan användas om det är representativt. Handels och Visita räknar snitt per månad eller förläggningscykel ("representativ period" hos Visita). Omräkningen månadslön × 12 / 52 kommer från praxis (arbetsgivarorganisationers räkneexempel), inte från lagen. Ingen AD-dom hittades.
- **Säkerhet:** Medel.
- **Konsekvens:** Delvis rätt. Höj minsta referensperiod från 4 veckor till minst en månad, eller avtalets förläggningscykel. Finns inget representativt schema: använd arbetade timmar de senaste 1–3 månaderna. × 12 / 52 kan stå kvar som förval.

### L2. Tio karensavdrag, återinsjuknande och rest av karens
- **Var:** `app/Modules/Payroll/Support/Run/ContextBuilder.php:146`, `app/Modules/Payroll/Domain/Engine/Steps/SickPay.php:37`.
- **Svar:** SjLL 6 § tredje stycket: har avdrag gjorts vid tio tillfällen under de senaste tolv månaderna görs inga fler. Det är *tillfällen med avdrag* som räknas; ett avdrag som delas över två perioder vid återinsjuknande räknas som ett (prop. s. 37, 69). Tolv månader räknas bakåt från den nya sjuklöneperiodens början. Återinsjuknande inom fem kalenderdagar: perioderna räknas ihop till 14 dagar och inget nytt karensavdrag görs (7 § tredje stycket). Räcker inte sjuklönen för hela karensen fortsätter avdraget "till dess att karensen är fullgjord" (prop. s. 35): resten dras på följande dagar i samma sjuklöneperiod och i ett återinsjuknande inom fem dagar, men aldrig mer än dagens sjuklön och aldrig in i en orelaterad senare period.
- **Säkerhet:** Hög.
- **Konsekvens:** Räkna karenstillfällen (inte sjukperioder) under tolv månader före första dagen i den nya sjuklöneperioden. För över en karensrest mellan dagar och lönebesked inom samma sjuklöneperiod och till återinsjuknande inom fem dagar; släpp den annars.

### L3. Förlorad OB i sjuklönen
- **Var:** `SickPay.php:25-37`, `AgreementSickPayExtension.php`.
- **Svar:** SjLL 6 §: 80 % av lön och andra anställningsförmåner som arbetstagaren gått miste om; OB enligt schemat är en sådan förmån. Handels § 15.4, Gröna Riks § 20.4.3.2 och Visita–Unionen § 23 säger uttryckligen 80 % av den OB som arbetstagaren gått miste om (övertid och mertid ingår inte). Gröna Riks "representativ period" gäller längden på karensen vid oregelbunden tid, inte OB-beloppet. AD 1993 nr 71 nämns som stöd i allmänhet (ej läst).
- **Säkerhet:** Medel (Hög för avtalen).
- **Konsekvens:** Koden betalar den OB schemat skulle ha gett på sjukdagarna: stämmer. Snitt-OB används bara i genomsnittsveckan för karens, vilket också är rimligt.

### L4. Avtalssjuklön dag 15–90 (tjänstemän)
- **Var:** `AgreementSickPayExtension.php:48`, avtalsfilerna `svenskhandel-unionen-tjansteman` och `visita-unionen`.
- **Svar:** Handels tjänstemannaavtal § 10.3.6 och Visita–Unionen § 23.3 är lika: gränsen är 10 prisbasbelopp / 12 per månad. Under gränsen: avdrag per kalenderdag 90 % × månadslön × 12 / 365. Över: 90 % × 10 pbb / 365 + 10 % × (månadslön × 12 − 10 pbb) / 365. Dagsavdraget får inte överstiga månadslön × 12 / 365. Månadslönen inkluderar fasta tillägg och förmåner. Längd (§ 10.3.7): grupp 1 (anställd minst ett år i följd, eller kom direkt från anställning med 90 dagars sjuklön) till dag 90, grupp 2 till dag 45. Vid flera sjukfall inom tolv månader: högst 105 (grupp 1) respektive 45 (grupp 2) dagar räknat från den aktuella sjukperiodens början. Vilken dag ettårsprövningen görs säger avtalen inte; sjukperiodens början är den rimliga läsningen.
- **Grund:** https://www.av.se/globalassets/filer/arbetsmiljoarbete-och-inspektioner/avtal-utstationering/svensk-handel-handelns-tjanstemannaavtal-kollektivavtal-2025-2027.pdf ; https://visita.se/app/uploads/2025/07/Visita-Unionen-2025-2027.pdf
- **Säkerhet:** Hög.
- **Konsekvens:** Avtalsdatan har redan 10 pbb, 90/10 och grupperna 12 månader → dag 90, annars dag 45. Saknas: taket 105/45 dagar per tolv månader, dagstaket och "kom direkt från anställning med 90 dagars sjuklön". Kontrollera att månadslönen tar med fasta tillägg och förmåner.

### L5. Övertid och komptid i semesterlöneunderlaget
- **Var:** `app/Modules/Payroll/Domain/PayCodeCatalog.php:21-23`, `app/Modules/Payroll/Support/CompTimeLedger.php:27`.
- **Svar:** SemL 16 b § (procentregeln): 12 % av all förfallen lön under intjänandeåret, med tre undantag (semesterlön, lön för semesterlönegrundande frånvarodagar enligt 17–17 b §§, viss permitteringslön). Utbetald övertid och utbetald komptid ingår alltså. Under sammalöneregeln (16 a §) ges 12 % på rörliga lönedelar; att övertid räknas dit är en tolkning (Medel). Handels tjänstemannaavtal 9.4.1 och Visita–Unionen: "I övertids- och restidsersättning ingår semesterlön", alltså undantagna. Detaljhandelsavtalet och Gröna Riks kontrollerades inte på den punkten.
- **Säkerhet:** Hög för lagen och de två tjänstemannaavtalen.
- **Konsekvens:** `vacation: false` på `OVERTID` och `KOMPTID` strider mot lagen när inget avtal undantar dem. Sätt förvalet till semesterlönegrundande och låt avtalet (data) undanta, som i Handels tjänstemän och Visita–Unionen.

### L6. Januari med fjolårets skattetabell
- **Var:** `app/Modules/Payroll/Actions/PayrollRuns.php:269`, PAYROLL_MODULE.md:293, systemdokumentationen:1386.
- **Svar:** Regeln finns i SFL **11 kap. 16 § andra stycket**: under januari får skatteavdrag göras enligt den skattetabell som senast tillämpades under föregående beskattningsår. 10 kap. 16 §, som koden citerar, gäller ränta och utdelning till begränsat skattskyldiga.
- **Säkerhet:** Hög.
- **Konsekvens:** Rätta hänvisningen. I januari: använd den tabell som senast tillämpades för den anställda året innan, i stället för att blockera. Blockera först från februari om nytt beslut saknas.

### L7. SINK 2027
- **Var:** `app/Modules/Payroll/database/data/2027/rules.json:109`.
- **Svar:** 7 § lag (1991:586) i lydelse SFS 2025:1356: 20 % från 2027-01-01 på inkomster som tas emot efter 2026-12-31; sjöinkomst 15 %. 2026 gäller 22,5 % (SFS 2025:1355).
- **Säkerhet:** Hög. **Konsekvens:** Stämmer. Satsen ska väljas efter utbetalningsdag, inte arbetsperiod.

### L8. Bevarande av löneunderlag och frånvaro
- **Var:** `app/Modules/Payroll/Actions/EraseEmployee.php:32`, PAYROLL_MODULE.md:430, ärende #8.
- **Svar:** BFL 7 kap. 2 §: räkenskapsinformation bevaras till och med sjunde året efter utgången av det kalenderår då räkenskapsåret avslutades. BFNAR 2013:2 punkt 5.3: underlag för löneutbetalning ingår i verifikationen bara om det innehåller verifikationsuppgifter som inte redan finns i en fullständig sammanställning (lönebesked, lönelista). SFL 39 kap. 3 §: dokumentation för AGI i skälig omfattning; ingen uttrycklig tid hittades. Omprövning på begäran till och med sjätte året efter beskattningsåret (SFL 66 kap. 7 §), efterbeskattning inom sex år (66 kap. 27 §). SjLL har ingen bevarandeplikt; 18 § om tystnad om hälsa. **Källorna pekar åt olika håll** (se G2).
- **Säkerhet:** Medel.
- **Konsekvens:** Behåll per lönebesked frånvarokod, datum, timmar och belopp i sju år (räkenskapsinformation, och behövs för omprövning). Spara inte läkarintyg eller diagnoser. Personnumret behövs lika länge. Den nuvarande lösningen (frånvaro sparas, kontaktuppgifter tas bort direkt) är försiktig och försvarbar. **Jurist: ja.**

### L9. Semesterersättning: skatt och löpande utbetalning
- **Var:** `app/Modules/Payroll/Domain/Engine/Steps/Vacation.php:67`, `:163`.
- **Svar:** Skatteverket räknar semesterersättning som exempel på engångsbelopp (lön som inte avser en bestämd period eller inte betalas regelbundet); sidan anger ingen paragraf. Semesterersättning i stället för ledighet får enligt SemL 5 § avtalas bara för anställning som väntas vara högst tre månader; 28 § ger ersättning när anställningen upphör; annat kräver kollektivavtal (2 §, ej kontrollerad).
- **Grund:** https://www.skatteverket.se/foretag/arbetsgivare/arbetsgivaravgifterochskatteavdrag/skatteavdrag/engangsbelopp.4.361dc8c15312eff6fd3225e.html
- **Säkerhet:** Medel.
- **Konsekvens:** Engångsbelopp vid slutlön stämmer med Skatteverket. Löpande semesterersättning med varje lön: tillåt bara vid anställning högst tre månader med sådan överenskommelse eller när kollektivavtalet medger det; varna annars. För löpande utbetalning kan tabellskatt vara rimlig eftersom den betalas regelbundet; låt inställningen stå kvar.

### L10. Avrundning av arbetsgivaravgifter
- **Var:** `app/Modules/Payroll/Domain/Engine/Steps/EmployerContributions.php:21`, `app/Modules/Payroll/Support/Agi/AgiDeclaration.php:24`.
- **Svar:** Skatteverkets ställningstagande 2017-06-08 (läst via FAR): SAL och SFL saknar avrundningsregler och arbetsgivaren väljer metod; underlaget redovisas i hela kronor; ören stryks per avgiftssats och beloppen summeras.
- **Grund:** https://www.faronline.se/dokument/skatteverket/stallningstaganden2/2017/skvstallningst2017060803/
- **Säkerhet:** Medel (ställningstagandet är från 2017).
- **Konsekvens:** Avrundning per lönebesked är tillåten, men AGI bör redovisa underlag i hela kronor per person med ören strukna, och avgiften per sats på summerat underlag. Visa skillnaden mot den bokförda avgiften som en avrundningspost. Kontrollera Skatteverkets aktuella tekniska beskrivning.

## 5. Arbetstid

Arbetstidslagen (1982:673), https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/arbetstidslag-1982673_sfs-1982-673/ (läst 2026-11-02). Avtalstexter 2025–2027: Detaljhandelsavtalet https://www.handels.se/globalassets/avtalsrorelsen/ag-hang/da110.pdf , Gröna Riks https://visita.se/app/uploads/2025/05/Grona-riksen-2025-2027.pdf , Visita–Unionen https://www.av.se/globalassets/filer/arbetsmiljoarbete-och-inspektioner/avtal-utstationering/visita-besoksnaringens-tjanstemannaavtal-kollektivavtal-2025-2027.pdf

### A1. Övertid och mertid: referensperiod
- **Var:** `app/Modules/Planning/Domain/PayrollBasis.php:48`, `:99`, `app/Modules/Planning/PlanningSettings.php:40`, `:59`, `app/Modules/Planning/Domain/WorkingTimeChecks.php:15`, systemdokumentationen:1863.
- **Svar:** ATL 5 §: högst 40 timmar i veckan, eller i genomsnitt under högst fyra veckor. 7 §: övertid är tid utöver ordinarie arbetstid (eller avtalets ordinarie tid). 8 §: allmän övertid högst 48 timmar per fyra veckor eller 50 timmar per kalendermånad, och 200 timmar per år; 8 a §: extra övertid 150 timmar. 10 §: mertid för deltid, högst 200 timmar per år; 10 b §: högst 48 timmar per sjudagarsperiod i snitt under fyra månader. 13 §: 11 timmars dygnsvila; 13 a §: nattarbete; 14 §: 36 timmars veckovila. Avtalen: Detaljhandel § 6.1 snitt 38 h 15 min över högst 52 veckor, § 9.2 övertid räknas per dag före och efter ordinarie arbetstid, § 9.3 deltidares övertid över 38 h 15 min per vecka eller utanför arbetsplatsens normala tider, § 9.5 mertid får samma tillägg som övertid från 2026-04-01. Gröna Riks § 9.1 snitt 40 h över 8/16/26 veckor, § 12.1.1 all tid utöver ordinarie arbetstid är övertid, § 12.2.1 mertid upp till 40 h i snitt över beräkningsperioden. Visita–Unionen § 10.1 snitt över 8/16/26 veckor, § 11.1 övertid är arbete utöver den ordinarie dagliga arbetstiden och bara om den beordrats eller godkänts.
- **Säkerhet:** Hög.
- **Konsekvens:** Kalenderveckans överskott är fel bas. För heltid: övertid = arbetad tid utanför dagens schemalagda pass, och bara beordrad eller godkänd. Ordinarie tid stäms av över avtalets beräkningsperiod, så kortare veckor kvittar längre. Mertid per avtal (Gröna Riks snitt över perioden, Detaljhandel 38 h 15 min per vecka plus utanför normala tider). Kontrollera gränserna 48 h/4 veckor eller 50 h/kalendermånad och 200 h/år.

### A2. Avrundning av OB och övertid
- **Var:** `app/Modules/Agreements/Domain/TimeRounding.php:13`, avtalsfilerna för Gröna Riks (`MEMBER` rad 225, `HANGAVTAL` rad 235) och Visita–Unionen (rad 367).
- **Svar:** Gröna Riks § 13 (OB): varje påbörjad halvtimme betalas som full, knutet till tid i OB-fönstret, alltså per pass. Gröna Riks § 12.1.2 (övertid): del av halvtimme ersätts som full halvtimme; en tillfällig förlängning på högst tio minuter är inte övertid; per tillfälle. Visita–Unionen § 21 (OB) som Gröna Riks; § 11.1: övertid före och efter passet "en viss dag" läggs ihop och varje påbörjad halvtimme räknas, alltså per dag. Detaljhandeln har ingen avrundningsregel för OB eller övertid. Ingen AD-dom eller partsvägledning hittades.
- **Säkerhet:** Medel (Hög för texten, Medel för tolkningen per tillfälle).
- **Konsekvens:** OB per pass stämmer. Övertid avrundad på periodens summa underbetalar: avrunda per dag (Visita–Unionen) eller per tillfälle efter tiominutersgränsen (Gröna Riks); ingen avrundning för Handels. Lägg avrundningens nivå (pass, dag, tillfälle, period) och tiominutersgränsen som data per avtal.

### A3. Minderårigas arbetstid
- **Var:** `app/Modules/Planning/PlanningSettings.php:84`, PLANNING_MODULE.md:79.
- **Svar:** AFS 2023:2 kap. 8 "Minderårigas arbetsmiljö" (ersatte AFS 2012:3). Yngre och äldre barn (under 13 och 13–15): 2 h per skoldag, 7 h per skolfri dag, 12 h per skolvecka; i lov om minst en vecka 7 h/dag och 35 h/vecka; inget arbete 20–06; 14 h dygnsvila; två lediga dagar per sju, minst 36 h i följd; fyra veckor sammanhängande ledigt per år. Ungdom (har gått ut nian och fyller 16 i år, till 18): högst 8 h/dag och 40 h/vecka; ledigt 22–06 eller 23–07; 12 h dygnsvila (11 h i vissa fall); två lediga dagar per sju. Alla minderåriga: aldrig 00–05, rast minst 30 min efter högst 4,5 h. Lästes på av.se:s sammanfattning, inte paragraferna i AFS.
- **Grund:** https://www.av.se/arbetsmiljoarbete-och-inspektioner/arbetsgivarens-ansvar-for-arbetsmiljon/minderariga-arbetstagare/arbetstider-for-minderariga/
- **Säkerhet:** Hög. **Konsekvens:** Jämför värdena i `PlanningSettings` med listan. Observera att "ungdom" bestäms av skolgång, inte bara ålder: ett fält för om personen gått ut grundskolan behövs.

## 6. Kollektivavtal, Fora och Collectum

### K1. Upphovsrätt till kollektivavtal
- **Var:** ADR-033:244 ff., systemdokumentationen avsnitt 8.
- **Svar:** Upphovsrättslagen (1960:729) 9 § undantar bara författningar, myndighetsbeslut och yttranden samt officiella översättningar. Kollektivavtal är privata avtal och omfattas inte. Texten kan skyddas som litterärt verk om den är originell (1 §). Siffror, procent och regler som idéer är fakta. Risken är 49 § (katalogskydd för sammanställningar av ett stort antal uppgifter eller en väsentlig investering, 15 år) vid kopiering av hela lönetabeller. Citat enligt god sed är tillåtet (22 §). Ingen rättspraxis om kollektivavtal hittades.
- **Grund:** https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/lag-1960729-om-upphovsratt-till-litterara-och_sfs-1960-729/
- **Säkerhet:** Medel.
- **Konsekvens:** Fortsätt med egna formuleringar och egen struktur, korta citat med källa och inga PDF:er eller ordagranna klausuler i förrådet. Fråga parterna om tillstånd för hela lönetabeller. **Jurist: ja.**

### K2. Hängavtalens namn och avgifter
- **Var:** avtalsfilerna `svenskhandel-handels-detaljhandel` rad 171, `svenskhandel-handels-lager-ehandel` rad 156, `visita-unionen` rad 373, `docs/avtal/grona-riks.md:220`.
- **Svar:** HRF (Gröna Riks), enligt Foras prislista: fast avgift 4 440 kr per år (1 500 kr om avtalet tecknats före 2018-01-01) plus 0,510 % av hela lönen för arbetare under 65 (koden har `admin_fee_bp` 51, vilket stämmer med procentsatsen). Handels och Unionen–Visita: avgifter hittades inte. Handels lägger Detaljhandels- och Lager och E-handelsavtalen i en mapp som tyder på att de används som hängavtalstext, men sidan säger det inte. Via Fora betalar hängavtalsföretag "övriga"-satserna (0,0525 % Handelsrådet, 0,55 % TSL).
- **Säkerhet:** Låg. **Konsekvens:** Lägg in den fasta avgiften för HRF. Övriga frågor kvarstår; fråga Handels och Unionen direkt.

### K3. Fora: fil, avtal och kontant bruttolön
- **Var:** `app/Modules/Agreements/Support/PremiumBasis.php:24`, `docs/avtal/gap-analys.md:90`, ADR-033 fråga 6.
- **Svar:** Filspecifikation 1.37, bilaga 2.0, API-specifikation 1.6 och testverktyg är publika. Inget krav på leverantörsavtal eller certifiering hittades (ej bekräftat med Fora). Kontant bruttolön är all lön före skatt som betalats ut i perioden, redovisad den månad den betalas, för alla arbetare från 13 år. Ingår: OB, övertid, beredskap, jour, bonus, provision, semesterlön, semesterersättning och sjuklön från arbetsgivaren. Ingår inte: förmåner, traktamenten, kostnadsersättningar, bilersättning, dricks, arvoden, vinstandelar och optioner.
- **Grund:** https://www.fora.se/for-leverantorer-av-lonesystem/rapportering-av-loner-for-arbetare/utvecklardokumentation/ ; https://www.fora.se/for-leverantorer-av-lonesystem/berakna-kontant-utbetald-bruttolon/
- **Säkerhet:** Medel (Hög för definitionen).
- **Konsekvens:** Koden (tabell- och engångsskattad lön, utan förmåner) stämmer i stort. Kontrollera att skattefria traktamenten och kostnadsersättningar inte följer med och att åldersgränsen 13 år används. Bekräfta med Fora att inget avtal krävs innan filen byggs.

### K4. TSL-rad för Handels
- **Var:** `app/Modules/Payroll/database/data/2026/rules.json:757`, `docs/avtal/detaljhandelsavtalet.md:191`.
- **Svar:** Foras prislista 2026: TSL med Huvudavtal 0,34 % (medlem i Svenskt Näringsliv) / 0,74 % (övriga); med Omställningsavtal 0,15 % / 0,55 %; på lön under 37 000 kr/mån, plus moms. Handels anslöt sig inte till Huvudavtalet 2024 och ingen senare ändring hittades, så omställningsavtalets rad bör gälla för Handels-området. Fora nämner inte Handels uttryckligen (egen slutsats). Övrigt 2026: AGS, FPT och premiebefrielse utan avgift; TGL 0,10 % under 37 000 kr; TFA 0,01 %; extra pension Detaljhandel, Lager och E-handel samt Restaurang och café 0,800 % för 25–65 år; Handelsrådet 0,035 % / 0,0525 %.
- **Grund:** https://www.fora.se/foretag/kostnad-och-faktura/prislistor/prislista-2026---foretag-med-kollektivavtal/ ; https://www.handels.se/aktuellt/2023/handels-ansluter-inte-till-huvudavtalet-2024/
- **Säkerhet:** Medel.
- **Konsekvens:** Välj TSL-rad per avtal (Handels: omställningsavtal) och per medlemskap; lägg på moms på TSL. Bekräfta med Fora.

### K5. Collectum och ITP
- **Var:** `rules.json:705`, `:781` (2026), `docs/avtal/handelns-tjanstemannaavtal.md:124`.
- **Svar:** Född 1979 eller senare normalt ITP 1, född 1978 eller tidigare ITP 2; företaget kan ha ITP 1 för alla. ITP 1-premier från månaden personen fyller 25 till månaden före 66 (enligt avtalat.se 2025, Alectas villkor och Visita–Unionen § 1.4); 4,5 % upp till 52 125 kr/mån och 30 % över (2026 ej verifierat hos Collectum). ITP 1 rapporterar faktisk bruttolön varje månad; ITP 2 årslön vid ändring. Filväg via Collectums portal enligt deras kravspecifikation, med pilotkund och gemensam granskning av en produktionsfil; Collectum får stoppa filer. API-väg finns för nyanmälan, lönerapportering, avanmälan och flytt; leverantören måste anslutas först.
- **Grund:** https://collectum.se/en/startpage/itp-occupational-pension/itp-itpk-and-tgl/whats-included-in-the-itp ; https://collectum.se/foretag/for-lonesystems-leverantorer/nyutveckling-av-itp-modul ; https://collectum.se/administration/stod-kring-rapportering/rapportera-via-apier
- **Säkerhet:** Medel.
- **Konsekvens:** Sätt gränsen 1979 som ändringsbart förval och slutåldern till månaden före 66 för ITP 1 (kontrollera mot koden). Ingen formell licens krävs, men en anslutningsprocess; planera för pilotkund.

## 7. Personalliggare

Propositionen är prop. 2025/26:282 *Effektivare kontrollmöjligheter i systemen för rot, rut, grön teknik och personalliggare*, inlämnad 2026-06-09, föreslås träda i kraft 2027-01-01, betänkande 2026/27:SkU3. **Riksdagen hade inte beslutat och ingen SFS fanns 2026-11-02.** https://www.riksdagen.se/sv/dokument-och-lagar/dokument/proposition/effektivare-kontrollmojligheter-i-systemen-for-rot_hd03282/ ; skatteförfarandeförordningen (2011:1261) https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/skatteforfarandeforordning-2011_sfs-2011-1261/

### P1. Bevarandetid
- **Var:** `app/Modules/StaffLedger/Actions/PruneStaffLedger.php:24`, `docs/gdpr/lagringstider.md:34`, ärende #12.
- **Svar:** Skatteförfarandeförordningen 9 kap. 5 §: liggaren bevaras i två år efter utgången av det kalenderår då verksamhetens beskattningsår gick ut. Propositionen ändrar inte det.
- **Säkerhet:** Hög.
- **Konsekvens:** Koden räknar från kalenderåret då liggaren fördes och gallrar därför ett år för tidigt vid brutet räkenskapsår (en post i augusti 2027 i räkenskapsåret juli 2027–juni 2028 ska finnas kvar till och med 2030). Räkna från räkenskapsårets slut. Automatisk gallring efteråt är förenlig med lagen (två år är en minsta tid) och med artikel 5.1 e. Rätta före första gallringen; den går inte att ångra.

### P2. Uppgifter per person
- **Var:** `app/Modules/StaffLedger/Actions/SavePerson.php:33`, `docs/gdpr/konsekvensbedomning-personalliggare.md:21`, `:43`.
- **Svar:** Skatteförfarandeförordningen 9 kap. 5 §: för näringsidkaren namn och personnummer, samordningsnummer, organisationsnummer eller utländskt nummer; för varje verksam person namn och personnummer, samordningsnummer eller utländskt nummer; start- och sluttid för varje arbetspass per verksamhetsdag, registrerade i omedelbar anslutning. Ingen roll krävs. Nytt i SFL 39 kap. 11 a § från 2027-07-01 enligt propositionen: arbetsgivarens identitet när den inte är näringsidkaren; detaljer i förordning. Skatteverket har haft ett utkast till föreskrifter (SKVFS 2026:XX, dnr 8-136159-2026) på remiss; innehållet kunde inte läsas.
- **Säkerhet:** Medel (Hög för nuvarande regler).
- **Konsekvens:** Lägg till ett fält för arbetsgivarens identitet (namn och nummer) per person eller pass. Samla inte in roll eller annat som inte krävs.

### P3. Branscher
- **Var:** `app/Modules/StaffLedger/Domain/BusinessType.php:12`.
- **Svar:** SFL 39 kap. 11 §: restaurang, fordonsservice, livsmedels- och tobaksgrossister, kropps- och skönhetsvård och tvätterier. Bygg regleras särskilt (11 a §, som numreras om). Propositionen lägger inte till branscher (avsnitt 7.3 avvisar krav på elektronisk liggare utanför bygg).
- **Säkerhet:** Hög. **Konsekvens:** v1-listan stämmer; bygg saknas och är planerat till v2. Tills dess ska byggföretag inte tro att modulen räcker.

### P4. Elektronisk överföring från 2027-07-01
- **Var:** ADR-022:162, ärende #12.
- **Svar:** Nytt SFL 39 kap. 12 a §: en elektronisk liggare ska vara utformad så att uppgifterna kan överföras elektroniskt till Skatteverket, på begäran (t.ex. vid kontrollbesök), från 2027-07-01 och inte för äldre uppgifter. Formatet bestäms i Skatteverkets föreskrifter. Inget XML-schema eller API för 2027 är publicerat; bara det äldre schemat för bygg finns (https://www.skatteverket.se/download/18.5a85666214dbad743ff56e5/1452519264124/beskrivning_schema.pdf).
- **Säkerhet:** Medel. **Konsekvens:** Behåll exporten utbytbar och följ Skatteverkets föreskrifter.

## 8. GDPR

### G1. Begränsning (art. 18) mot bokföringsplikten
- **Var:** `app/Actions/Privacy/RestrictProcessing.php:26`, ärende #7.
- **Svar:** BFL 4 kap. 1 § (alla affärshändelser ska bokföras), 5 kap. 6 § (verifikation för varje affärshändelse) och 7 kap. (bevarande). Art. 18.2 tillåter utöver lagring bara samtycke, rättsliga anspråk, annans rättigheter eller viktigt allmänintresse; rättslig förpliktelse (6.1 c) nämns inte, så spänningen är verklig. Men 18.1 d gäller bara invändningar enligt art. 21.1, och art. 21 gäller inte behandling enligt 6.1 c. Många leverantörer är juridiska personer och utanför GDPR. IMY skriver att uppgifter som sparas enligt bokföringslagen inte får användas till annat än bokföring (https://www.imy.se/privatperson/dataskydd/dina-rattigheter/begransning/). Inget beslut från EDPB eller IMY om just den här konflikten hittades.
- **Säkerhet:** Låg.
- **Konsekvens:** Att stoppa registreringen riskerar bokföringsbrott. Tillåt registrering, bokföring och lagring av leverantörsfakturan med en varning och markering; spärra annan användning (utskick, nya beställningar, avtal); logga bedömningen. Betalning av en mottagen faktura är också en rättslig skyldighet och bör tillåtas. **Jurist: ja.**

### G2. Frånvaro bakom sjuklön
- **Var:** `app/Modules/Payroll/Actions/EraseEmployee.php:32`, ärende #8, `docs/gdpr/lagringstider.md`.
- **Svar:** Två läsningar. (1) BFNAR 2013:2 punkt 5.3: om lönebeskedet visar datum, vad (sjuklön, karensavdrag, period), belopp och anställd är frånvaroposterna bakom inte räkenskapsinformation, och sju år gäller inte dem (BFN noterar att andra regler kan kräva längre tid). (2) De behövs för att förklara bokförda belopp och för omprövning sex år bakåt (SFL 66 kap.). SFL 39 kap. 3 § och skatteförfarandeförordningen 9 kap. 1 § kan ge sju år för skatteunderlag (ej verifierat).
- **Grund:** https://bfn.se/wp-content/uploads/vl13-2-bokforing.pdf ; SFL.
- **Säkerhet:** Medel.
- **Konsekvens:** Se till att lönebeskedet och lönelistan bär frånvarokod, datum, timmar och belopp i sju år. Då kan själva frånvaroposterna i frånvaroregistret gallras tidigare om ni vill, men att spara dem (som i dag) är det försiktiga valet. Läkarintyg och diagnoser ska inte sparas. **Jurist: ja.**

## 9. AI-förordningen

Förordning (EU) 2024/1689. EUR-Lex kunde inte öppnas i fulltext; uppgifterna om 2026/1744 kommer från EUR-Lex register och sekundära källor.

### G3. Förslag om lönegrupp (bilaga III punkt 4 b)
- **Var:** ADR-033:190, ADR-018:104, `docs/gdpr/konsekvensbedomning-ai.md:55`, `:74`.
- **Svar:** Bilaga III 4 b omfattar AI-system avsedda att fatta beslut som påverkar villkoren i arbetsrelaterade förhållanden. Ett förslag om lönegrupp styr lönen och ligger därför troligen inom punkten. Undantaget i art. 6.3 d (förberedande uppgift) kräver att systemet inte väsentligt påverkar beslutet; ett färdigt förslag gör det sannolikt. Ett system som profilerar personer är enligt art. 6.3 alltid högrisk. Den som åberopar undantaget måste dokumentera bedömningen (6.4) och registrera systemet (49.2).
- **Säkerhet:** Medel.
- **Konsekvens:** Säkrare utformning: låt modellen bara läsa ut fält ur anställningsavtalet (befattning, datum, timmar) och låt deterministiska regler plus en människa välja lönegruppen. Annars: dokumentera art. 6.3-bedömningen och registrera. **Jurist: ja.**

### G4. Kvittoläsaren
- **Var:** ADR-018:125, `docs/juridik/regelverk.md:96`.
- **Svar:** Ren utläsning av fält utan bedömning av personen faller troligen utanför bilaga III (art. 6.3 a, snäv procedurmässig uppgift), så länge den inte används för att övervaka eller bedöma enskilda anställda.
- **Säkerhet:** Medel. **Konsekvens:** Ändringen 2026-11-01 (ingen bedömning av inköpet) stämmer med det. Bygg inte rapporter per anställd ovanpå anteckningarna.

### G5. Datum efter förordning (EU) 2026/1744
- **Var:** ADR-018:69, frågor till jurist 18–19.
- **Svar:** Förordning (EU) 2026/1744 finns i EUR-Lex register. Enligt skälen gäller kapitel III avsnitt 1–3 för system enligt art. 6.2/bilaga III från 2 december 2027 och för bilaga I från 2 augusti 2028. Enligt sekundära källor (Gibson Dunn m.fl.) publicerades den 2026-07-24, trädde i kraft 2026-07-27, art. 50 gäller från 2026-08-02 och märkningskravet i 50.2 har övergångstid till 2026-12-02 för system som släppts före 2026-08-02. Art. 113 och art. 50 lästes inte i EUR-Lex. Om art. 4 (AI-kunnighet) ändrats är inte utrett.
- **Säkerhet:** Medel. **Konsekvens:** Datumen i ADR-018 stämmer med det som gick att se. Verifiera i EUR-Lex.

## 10. Inte utredda i den här omgången

Frågorna nedan står kvar som `TODO(legal-verify)`. De är grupperade; id:t används i `fragor.json`.

| Id | Fråga | Var |
|---|---|---|
| X1 | En behandlingshistorikpost per SIE-import | `app/Actions/Accounting/ImportSie.php:123` |
| X2 | SIE-typkoderna "I" och "S" | `app/Actions/Accounting/ExportSie.php:135` |
| X3 | Resultatkonto per företagsform vid årsskifte, 2512 och 2898, HB/KB | `CarryForwardBalances.php:123`, `app/Domain/Closing/ClosingAccounts.php:15`, ADR-010:61 |
| X4 | Skatt vid bokslut (20,6 %) och utdelning mot fritt eget kapital (ABL 17 kap.) | `app/Actions/Closing/YearEndClosing.php:73`, `:283` |
| X5 | Inventarier: mindre värde, moms vid försäljning, avskrivning i avyttringsmånaden, 30/20-regeln | `app/Actions/Assets/FixedAssets.php:80`, `:284`, `app/Domain/Assets/DepreciationPlan.php:17` |
| X6 | Årsredovisning K2: radställning, noter, jämförelseår, 20,6 % på obeskattade reserver, iXBRL-taxonomi | `app/Domain/AnnualReport/*`, AGENTS.md:351 |
| X7 | INK2: BAS-koppling, justeringar, prefix 16 | `app/Domain/IncomeTax/Ink2.php:19`, `:61`, `Ink2Codes.php:15` |
| X8 | Momsrutor per konto, rutorna 06–08, 37, 38, 41, eSKDUpload 6.0 | `VatBoxes.php:15`, `EskdFile.php:14`, ADR-010:33, `:40` |
| X9 | Kvartalsmoms vid brutet räkenskapsår, datum för årsmoms | `VatPeriods.php:15`, `VatDeadlines.php:24` |
| X10 | Periodisk sammanställning: period, avrundning, teckenkodning | `app/Domain/Vat/EuSalesList.php:23` |
| X11 | OSS-satser per land | `app/Domain/Vat/OssRates.php:17` |
| X12 | EU- och exportförsäljning: konton, rutor, fakturatexter, VIES, trepartshandel | `ForeignSale.php:17`, `IssueInvoice.php:141`, `:165`, `CheckVatNumber.php:25`, ADR-016:49, `:51` |
| X13 | Omvänd byggmoms: fakturakrav, 3231, ruta 41 | `IssueInvoice.php:141`, `InvoiceBooking.php:56` |
| X14 | ROT och RUT: andelar, tak, kontantmetoden, nekade belopp, personnummer | `app/Domain/Invoicing/TaxReduction.php:15`, `IssueInvoice.php:216`, `RegisterTaxReductionPayment.php:27`, `docs/gdpr/README.md:26` |
| X15 | Dagsrapport: dricks med kort, presentkort (enfunktions-/flerfunktionsvoucher), öresavrundning | `DailyReportVoucher.php:18`, ärende #17 |
| X16 | Bankgiro/plusgiro i Peppol enligt SFTI | `app/Support/Peppol/UblInvoiceParser.php:19` |
| X17 | Avvisade inkorgsunderlag som räkenskapsinformation (7 dagar) | `PruneInboxCommand.php:25`, `docs/gdpr/konsekvensbedomning-inkorg.md:33` |
| X18 | Skattetabell vid inkomst med ören, obligatoriskt skatteavdrag utan skatteuppgift | `WithholdingTax.php:23`, `WithholdingTaxStep.php:23` |
| X19 | AGI: deklarationsdag vid omsättning över 40 mkr, enskild firma, API, XSD i öppet förråd | `AgiDeadlines.php:18`, `AgiSubmissions.php:43` |
| X20 | Semester: värde per dag (29 §), sparade dagar, 26 a §, procentregelns avdrag, skuldvärdering, deltidsmetod, lördagar (3 a §) | `VacationLedger.php:107`, `:240`, `:306`, `CloseVacationYear.php:41`, `Steps/Vacation.php:37`, `:140`, `Calendar/VacationDays.php:26`, `Calendar/SwedishHolidays.php:26` |
| X21 | Retroaktiv lön: frånvaro och semester i redan utbetalda månader | `ContextBuilder.php:375`, ADR-012:212 |
| X22 | Traktamente, pension, särskild löneskatt till skattekontot | `Steps/Allowances.php:22`, `Steps/Pension.php:22`, `PayrollRuns.php:598` |
| X23 | Utmätning och förbehållsbelopp | `Steps/NetItems.php:16` |
| X24 | Arbetsgivarintygets fält | `Http/EmployerCertificateController.php:29` |
| X25 | Bankernas krav på lönefiler; kontroll vid byte av lönekonto | `SalaryPaymentFile.php:30`, `ConfirmBankChange.php:22` |
| X26 | Avtalsdata: ålderssteg (vilken dag), sammanlagd anställningstid, överlappande OB-fönster, övertid utöver timlön (Lager, bageri), frisöravtalets 2025-text, kostavdragets schablonvärde, förstärkt pensionsavsättning | avtalsfilerna under `app/Modules/Agreements/database/data/agreements/`, `AgreementPayTerms.php:148`, `:167`, `AgreementCostsStep.php:24`, `docs/avtal/*` |
| X27 | 2027 års regelvärden efter budgetpropositionen (prop. 2026/27:1) och Skatteverkets januariuppdatering | `app/Modules/Payroll/database/data/2027/rules.json:3`, `:365` |
| X28 | Helgdagar mot SFS | `app/Domain/Calendar/SwedishHolidays.php:21` |
| X29 | Årsredovisningens frist sju månader (ÅRL 8 kap. 3 §) | `app/Domain/Firm/FirmWorkspace.php:73`, ADR-028:207 |
| X30 | Fortnox villkor för API-flytt, PAXml | `app/Migration/Fortnox/FortnoxOAuth.php:19`, `MovePayrollData.php:42`, `docs/migration/leaving-fortnox.md:93` |
| X31 | Övriga GDPR-frågor: konsekvensbedömningar, foto på kiosken, art. 14 i inkorgen, gemensam adress, dataskyddsombud, fackavgift (art. 9), revisorns läsning, lagringstider i `PrunePersonalDataCommand`, pseudonymiserat konto, säkerhetskopior, incidentansvarig | `docs/gdpr/*`, `DeleteAccount.php:35`, `EraseRegisterEntry.php:33`, `PrunePersonalDataCommand.php:29`, ADR-021:38, frågor till jurist 1–16 |
| X32 | AI: Stafes roll (art. 3.3, 2.12, 25), art. 4, art. 50.2, rollkedjan vid kundens nyckel | ADR-018:60, `:89`, `:156`, `:173`, frågor till jurist 3, 17, 19, 20 |
| X33 | NIS2, tillgänglighetslagen, CRA, ViDA, Enable Banking/FI, Bolagsverkets data, ADR-020-jämförelser, AGPL-friskrivning, avtalstexterna i `docs/juridik` | `docs/juridik/*`, ADR-034:191–205, ADR-035:187, ADR-020:35 |

## 11. Att göra härnäst

1. Rätta de tre punkter som är både säkra och enkla: L6 (januari och paragraf), B3 (minsta räkenskapsår), P1 (gallring från räkenskapsårets slut, före första körningen).
2. Ta med L2, L5, A1 och A2 till en lönekunnig person; de ändrar belopp på lönebesked.
3. Bygg M3 (kontantmetodens bokslut) och V6/V7 (OSS i euro).
4. Låt en jurist pröva G1, G2, G3, B6 och K1.
5. Kontrollera de källor som inte gick att öppna: EUR-Lex (direktivet och 2026/1744), Skatteverkets rättsliga vägledning, BFNAR 2016:10 och 2012:1 i fulltext, Collectum 2026, riksdagens beslut om prop. 2025/26:282.

## Andra, oberoende kontroll (2026-11-02)

Varje avvikelse ovan kontrollerades av en andra AI-agent som bara fick frågan och kodens plats, inte slutsatsen, och som själv läste primärkällorna. Det som bekräftades rättas direkt och dokumenteras (systemdokumentation, ADR, manual), märkt "enligt AI-utredning 2026-11-02, juristgranskning återstår". Osäkra punkter ändras inte i koden.

| Id | Andra kontrollen | Säkerhet | Åtgärd |
|---|---|---|---|
| V1 | Bekräftad avvikelse | Medel | Rättas (fix-moms): kursen tas på leveransdagen. |
| V4 | Bekräftad avvikelse | Hög | Rättas (fix-bokslut): omvärdering även med kontantmetoden och delkrediterade fakturor; checklistan stoppar igen men kan bockas av för hand. |
| V6 | Bekräftad avvikelse | Medel | Rättas (fix-moms): OSS i euro med ECB:s kurs vid kvartalets slut. |
| V7 | Bekräftad avvikelse | Hög | Rättas (fix-moms): tröskeln 99 680 kr och val av beskattning i köparens land. |
| M3 | Bekräftad avvikelse | Hög | Rättas (fix-bokslut): obetalda fakturor bokförs vid årets slut med kontantmetoden. |
| B3 | Bekräftad avvikelse | Hög | Rättas (fix-foretag): ingen minsta längd; enskild firma följer kalenderåret. |
| B4 | Bekräftad avvikelse | Hög | Rättas (fix-foretag): K1/K2/K3 kontrolleras mot företagsformen. |
| B6 | Osäker | Låg | Beslut hos ägaren: skriftligt tillstånd från BAS-kontogruppen. |
| L1 | Bekräftad avvikelse | Hög | Rättas (fix-sjuklon): historik 1–3 månader före sjukperioden, avtalens timvärden. |
| L2 | Bekräftad avvikelse | Hög | Rättas (fix-sjuklon): tak per sjukperiod, rest förs vidare, avdrag räknas bakåt från periodens början. |
| L4 | Bekräftad avvikelse | Hög | Rättas (fix-sjuklon): tak 105/45 dagar, dagstak, lönebegrepp enligt avtalet. |
| L5 | Bekräftad avvikelse | Hög | Rättas (fix-overtid): övertid och komptid semesterlönegrundande enligt lag och avtal. |
| L6 | Ingen avvikelse | Hög | Ingen avvikelse i sak (regeln är frivillig). Felaktigt lagrum rättat till 11 kap. 16 § andra stycket. |
| A1 | Bekräftad avvikelse | Hög | Rättas (fix-overtid): frånvaro räknas som arbetad tid, beräkningsperiod enligt lag/avtal, övertid per dag där avtalet säger det, tillägg för timavlönade. |
| A2 | Bekräftad avvikelse | Hög | Rättas (fix-overtid): övertid avrundas per dag eller tillfälle, inte på periodens summa. |
| P1 | Bekräftad avvikelse | Hög | Rättas (fix-personalliggare): bevarandetiden räknas från räkenskapsårets slut. |
| P2 | Bekräftad avvikelse | Hög | Rättas (fix-personalliggare): utländska identitetsnummer. 2027 års krav är inte beslutat ännu. |
| P3 | Ingen avvikelse | Hög | Ingen avvikelse. |
| G1 | Osäker | Medel | Osäker: läggs som öppen fråga till juristen (issue #7). |
| G3 | Osäker | Låg | Osäker: kräver skriftlig bedömning enligt art. 6.4; öppen fråga till juristen. |
