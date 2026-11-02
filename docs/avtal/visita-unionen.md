# Besöksnäringens tjänstemannaavtal (Visita – Unionen)

Nyckel: `visita-unionen` · Kollektiv: tjänstemän · Våg 1 · Segment: hotell,
restaurangkedjor, café med kontor/ledning (restaurangchef, administration,
receptionist som tjänsteman)

## Källor

- **VU**: Visita–Unionen 2025–2027.
  https://visita.se/app/uploads/2025/07/Visita-Unionen-2025-2027.pdf
  (kopia hos Arbetsmiljöverket:
  https://www.av.se/globalassets/filer/arbetsmiljoarbete-och-inspektioner/avtal-utstationering/visita-besoksnaringens-tjanstemannaavtal-kollektivavtal-2025-2027.pdf)
- **ITP26**: Collectum, aktuella premier och basbelopp,
  https://collectum.se/avtal-och-faktura/faktura-och-premier/aktuella-premier-och-basbelopp
- **F26**: Fora, prislista 2026,
  https://www.fora.se/foretag/kostnad-och-faktura/prislistor/prislista-2026---foretag-med-kollektivavtal/

Status: **avläst, inte verifierad**.

## Parter och giltighet

| Fält | Värde | Källa |
|---|---|---|
| Parter | Visita – Unionen | VU § 1 s. 5 |
| Omfattning | företag anslutna till Visita; inte företagsledande (t.ex. hotelldirektör); bisyssla undantagen utom sjuklön | VU § 1 s. 5 |
| Giltighet | **2025-06-01 – 2027-05-31** | VU § 30 s. 55 |
| Hängavtal | Unionens hängavtalsinformation för 2025 **ej hittad** (endast 2023-version). TRR 0,80 % i stället för 0,40 % för icke-medlemmar (F26) | |

## Delare och lön

- Timlön: **1/173** av månadslönen; viss nattpersonal **1/164** (§ 20 s. 40).
- **Minimilön** (löneavtal § 2 p. 4 s. 59), heltid, fyllda 20 år, fr.o.m. den
  1:a i månaden då åldern nås, gäller alla tjänstemän:
  23 657 kr (2025-06-01 – 2026-05-31), 24 296 kr (2026-06-01 – 2027-05-31).
  Ingen nivå för under 20 år.
- Lönerevision: pott 3,0 % (2025) / 2,7 % (2026); garanterat 469 / 483 kr/mån,
  deltid proportionellt; retroaktivt från 1 juni (löneavtal § 2 s. 59).
- Frånvaroavdrag (§ 14 s. 30–31): per timme 1/173 (natt 1/164); frånvaro över
  5 dagar (6 vid sexdagarsvecka) dras som dagslön månadslön × 12 / 365.

## OB (§ 21, s. 41) — fasta kronor, **steg 1 juni**

| Fönster | 2025-06-01 | 2026-06-01 |
|---|---|---|
| mån–fre 20–06; lör och midsommar-/jul-/nyårsafton 16–06; sön- och helgdag 06–06 | 26,68 kr | 27,48 kr |
| Natt-OB 01–06 utöver | 23,50 kr | 24,21 kr |

- Avrundning: **varje påbörjad halvtimme som full halvtimme**.
- Natt-OB gäller inte fast nattpersonal i hotellreception 01–06.
- OB kan ersättas med fast lönetillägg genom skriftlig överenskommelse,
  normalt för ett semesterår (21.3) → `ob_compensation = INCLUDED`.
- Ingen storhelgsregel (till skillnad från Gröna Riks § 14).
- Uteblivna fridagar ersätts som arbete på obekväm tid, om inte annat
  avtalats; gäller inte turist-/säsongsverksamhet (§ 10.5 s. 21).
- OB i sjuklön: 80 % efter karens (§ 23.3 s. 45). OB i semester: ingår i
  rörlig lön (12,72 %) om inte OB förekommit högst 60 dagar under
  intjänandeåret (s. 36).

## Övertid (§ 11, s. 24–27)

| Situation | Ersättning per timme | Komptid |
|---|---|---|
| i anslutning till ordinarie tid, upp till tre timmar | månadslön / **94** | 1,5 tim |
| övertid på annan tid | månadslön / **72** | 2 tim |

- Månadslön = aktuell fast månadslön; deltid räknas upp till heltidslön.
- Varje påbörjad halvtimme tas med; övertid som inte ansluter: minst 3 tim.
- Komptid om den anställda vill och verksamheten tillåter; upp till 75 tim/år
  kompenserad med ledighet kan återföras till övertidsutrymmet.
- Undantag (11.4): högre lön och/eller **5 extra semesterdagar**; avtal om
  minst 12 min daglig förberedelse utan lönejustering ger 3 extra dagar.
- Mer än 200 tim beordrad övertid per kalenderår kräver överenskommelse;
  anspråk inom 3 månader.
- **Mertid: ingen avtalsregel** → arbetstidslagens regler.
- Årsarbetstid (bilaga 8): överskott betalas med 55 % av ordinarie lön.

## Arbetstid

- 40 tim/vecka i snitt; fast nattreception 38 tim. Beräkningsperiod 8 v; 16 v
  vid varierande efterfrågan; 26 v för driftspersonal; upp till ett år lokalt
  (§ 10 s. 20). Max 11 schemalagda tim/dygn.
- **Arbetstidsförkortning** (10.10 s. 23): betald ledighet 4 tim per
  kalenderår; **8 tim fr.o.m. 2026-01-01, 12 tim fr.o.m. 2027-01-01**,
  proportionellt för deltid → ackumulator `atf_hours`.
- Röda dagar som Gröna Riks; outtagna betalas med 1/22 av månadslönen (§ 12).

## Semester (§ 16, s. 33–38)

- 25 dagar; +5 vid övertidsundantag, +3 vid förberedelsetid. Inga extra
  dagar efter ålder. Intjänandeår 1 april – 31 mars.
- Färre än 5 dagar/vecka: dagar/vecka × semesterdagar / 5, avrundat uppåt.
- **Sammalöneregeln**: aktuell månadslön + semestertillägg per betald dag
  **0,8 %** av månadslönen + fasta tillägg; plus **12,72 %** av rörlig lön
  utbetald under intjänandeåret (provision, bonus, OB, jour, beredskap).
  0,8 % betalas med lönen kring semestern, 12,72 % senast vid semesterårets
  slut. Övertid och restid innehåller redan semesterlön.
- Semesterersättning per outtagen dag: **4,6 %** av månadslönen + 0,8 % resp.
  12,72 %. Obetald semesterdag: avdrag 4,6 %. Enstaka dagar och anställning
  ≤ 3 mån: 12,72 % av lönen.
- Ändrad sysselsättningsgrad: månadslönen proportioneras till genomsnittet
  under intjänandeåret.

→ Motorns sammalöneregel (`Vacation.php:98-109`) använder lagens 0,43 % och
saknar rörlig del och 4,6 %-regeln (gap G13).

## Sjuklön (§ 23, s. 44–50)

- Dag 1–14: karens 20 % av veckoarbetstiden; avdrag per timme under karens
  **månadslön × 12 / (52 × veckoarbetstid)**; därefter t.o.m. dag 14 **20 %**
  av samma timvärde; plus 80 % av förlorad OB. Efter 10 karenstillfällen på
  12 månader dras karensen med 20 %-satsen (s. 46).
- Veckoarbetstid vid oregelbunden tid: genomsnitt per månad eller cykel,
  två decimaler; månadslön inkluderar fasta tillägg och snittet av rörlig lön
  (s. 47).
- **Dag 15 och framåt** (s. 46): avdrag per kalenderdag. Gräns =
  10 × prisbasbelopp / 12. Lön upp till gränsen: 90 % × månadslön × 12 / 365.
  Över: 90 % × 10 PBB / 365 + 10 % × (månadslön × 12 − 10 PBB) / 365. Avdraget
  får inte överstiga fast månadslön × 12 / 365. Kost- och bostadsförmån ingår
  till Skatteverkets värde.
- Varaktighet (s. 48): grupp 1 (minst 1 års anställning eller direkt från
  anställning med 90 dagars sjuklön) t.o.m. dag 90, högst 105 dagar per 12 mån;
  grupp 2 t.o.m. dag 45, högst 45 dagar.
- Läkarintyg från 7:e kalenderdagen.

## Övrigt som påverkar lönespecen

- Kost (§ 17 s. 39, bilaga 2 s. 64): 55 / 56 kr/dag (steg 1 juni), 2 913 /
  3 000 kr/mån, nettoavdrag; minskas med 1/30 per ledig dag om fritidskost
  undantar lediga dagar; tilläggstabell vid högre pris.
- Restid (§ 24): månadslön / 240 per timme; / 190 fre 18 – mån 06 och helger;
  hela halvtimmar.
- Jour (bilaga 3): månadslön / 600; fre 18–lör 07 / 400; lör 07–sön 24 / 300;
  storhelg / 150; minst 8 tim.
- Beredskap (bilaga 4): / 1 400; / 1 000; / 700; storhelg / 350; minst 4 tim;
  inkallad = övertid minst 2 tim.
- Föräldralön (§ 22): 11,23 % av månadslön upp till 10 PBB/12 + 90,13 % av
  delen över; 2–6 månader efter 1–5+ års anställning; minst 1 års anställning
  och minst 1 månads ledighet.
- VAB-avdrag: månadslön × 12 / (52 × veckoarbetstid) per timme (§ 15).

## Pension och försäkringar

- ITP, TGL, TFA, Huvudavtal om trygghet, omställning och anställningsskydd
  (bilaga 1 s. 63). Deltidspension: +0,2 % fr.o.m. 2025-06-01, totalt 1,7 %
  (§ 27 s. 53).
- **ITP1 2026** (ITP26): ålderspension 4,5 % upp till 52 125 kr/mån
  (7,5 IBB), 30 % 52 125–208 500 kr/mån; sjukpension 0,030 % / 0,108 %
  (gräns 49 333); premiebefrielse 0,125 % / 1,415 %. Premie fr.o.m. månaden
  man fyller **25 år** t.o.m. 66 år
  (https://www.avtalat.se/tjansteman/tjanstepensionen-itp/tjanstepensionen-itp-1/:
  "från och med att du fyller 25 år"). Collectums avgift 0,55 % av premien,
  högst 450 kr per år och anställning (avtalat.se).
- ITP1 för födda 1979 och senare, ITP2 för äldre: **inte angivet i VU** →
  `TODO(legal-verify)`.
- Fora 2026 tjänstemän (F26): TFA 0,02 %; TRR 0,40 % (medlem) / 0,80 % (övriga).
- TGL för tjänstemän är ett **kronbelopp**, inte procent: Alecta 32 kr/mån 2026
  (Livsmedelsföretagens cirkulär
  https://www.livsmedelsforetagen.se/app/uploads/2026/02/cirkular-avgifter-och-premier-for-ar-2026.pdf
  s. 4, arbetsgivarkälla) → `TODO(legal-verify)` mot Alecta.
- TRR betalas t.o.m. månaden före 67 år, 0,15 % återbetalas via Fora
  (https://www.avtalat.se/media/dmnlzmb3/2026-premier_itp-2.pdf).
- ITP2: förmånsbestämd, individuell premie beräknad av Alecta (samma källa) →
  inte automatisk beräkning i v1; kostnaden tas från fakturan.
- Rapportering till Fora för tjänstemän: lönesumma en gång per år i januari
  (https://www.fora.se/foretag/rapportera-loner-och-premier/sa-rapporterar-du-tjansteman-vd-och-agare/).

## I NONOX: semester, pension, försäkringar och tillägg (A9–A12, 2026-11-02)

- Semester: sammalöneregeln, `supplement_bp_per_day` 80; 12,72 % av rörlig lön (`variable_pay_percent_bp`) och 4,6 %-regeln räknas inte; +5 dagar vid övertidsundantag visas men räknas inte.
- Pension och försäkringar: `pension.itp1` (ålderspension 4,5 % / 30 % upp till 30 IBB från 25 år), `insurance.fora.tjansteman` (TFA 0,02 %, TRR 0,40 % / 0,80 %). ITP 2, sjukpension, premiebefrielse och TGL (kronbelopp) räknas inte.
- Tillägg: restid `/240` och `/190` (inte semestergrundande), jour `/600`, `/400`, `/300`, `/150` och beredskap `/1400`, `/1000`, `/700`, `/350` som SUPPLEMENT-koder. Minsta antal timmar kontrolleras inte.
- Kostavdrag: `VU_KOST` 55 kr (2025-06-01) och 56 kr (2026-06-01) per dag.
- Premierna kontrolleras som lönens regler (Lön → Regler); avtalsfilen pekar bara på dem. Gyllene lönespecar: `tests/Fixtures/agreements/payslips/visita-unionen.json`.
