# Handelns tjänstemannaavtal (Svensk Handel – Unionen och Akademikerförbunden)

Nyckel: `svenskhandel-unionen-tjansteman` · Kollektiv: tjänstemän · Våg 1 ·
Segment: butikschefer, kontor och administration i handelsföretag, e-handel

## Källor

- **HT**: Handelns tjänstemannaavtal 2025–2027 (fulltext, publicerad av
  Akavia):
  https://www.akavia.se/siteassets/03-rad-och-stod/kollektivavtal-privat-sektor/svensk-handel/svensk-handel--tjanstemannaavtalet-2025-2027.pdf
  — texten hänvisar till svenskhandel.se för aktuell version.
- **UN**: Unionens hängavtalsnytt 2025:
  https://www.unionen.se/sites/default/files/files/Handelns_tjmavtal_Svensk%20Handel_H%C3%A4ngavtalsnytt2025.pdf
- **ITP26**, **F26**: se `visita-unionen.md`.

Status: **avläst, inte verifierad**.

## Parter och giltighet

| Fält | Värde | Källa |
|---|---|---|
| Parter | Svensk Handel – Unionen och Akademikerförbunden | HT omslag |
| Giltighet | **2025-05-01 – 2027-04-30**, därefter ett år i taget | HT § 17 s. 58 |
| Tillämpning | företag anslutna till Svensk Handel, efter skriftlig begäran från en part; inte företagsledande | HT § 1.1–1.3 s. 8 |
| Hängavtal | Unionen publicerar ändringar för hängavtalsföretag (UN) | UN |

## Lön och delare

- **Minimilöner** (endast Unionens löneavtal, bilaga 3 och 4; fyllda 18 år):

| | 2025-05-01 | 2026-05-01 |
|---|---|---|
| kortare anställningstid än ett år | 21 767 kr | 22 355 kr |
| ett års sammanlagd anställning i företaget | 23 927 kr | 24 573 kr |

  HT bilaga 3 § 1.3 s. 73 (samma i bilaga 4 § 1.4 s. 83). Deltid
  proportionellt. Introduktionslön 18–23 år: lägst 75 % av 18-årsnivån i
  högst 12 månader (§ 1.4 s. 73–74). Ingen tabell under 18 år. Akademikernas
  löneavtal (bilaga 6) har inga minimilöner.
- Lönerevision: 3,0 % (2025) / 2,7 % (2026); garanterat 550 / 520 kr/mån
  (s. 72–73).
- **Löneavdrag per timme: 1/167** av månadslönen (ändrat från 1/175 2025),
  hela halvtimmar (§ 11.3 s. 40; UN s. 3).
- Delmånad: månadslön × anställningsdagar / kalenderdagar (§ 12 s. 46).

## Förskjuten arbetstid (i stället för OB) (§ 5.3, s. 17)

Ersättning per timme = månadslönen delad med:

| Tid | Delare |
|---|---|
| mån–fre 18–24 | 600 |
| mån–lör 00–07 | 400 |
| lör 07 – sön 24 | 300 |
| fr.o.m. 07 trettondagen, 1 maj, nationaldagen, Kristi himmelsfärd, alla helgons dag till 00 närmast följande vardag | 300 |
| fr.o.m. 18 skärtorsdagen och nyårsafton, fr.o.m. 07 pingst-, midsommar- och julafton, till 00 närmast följande vardag | 150 |

Förskjuten arbetstid och övertidsersättning kan inte ges samtidigt.
→ Rate-typ `DIVISOR_OF_MONTHLY` per fönster; storhelgsfönster som sträcker
sig "till närmast följande vardag" kräver kalenderlogik (gap G5).

## Övertid (§ 4)

| Tid | Ersättning | Komptid |
|---|---|---|
| kl. 06–20 helgfria mån–fre | månadslön / **94** | 1,5 tim |
| annan tid (inkl. arbetsfria vardagar, midsommar-, jul-, nyårsafton) | månadslön / **72** | 2 tim |

- Deltid: månadslönen räknas upp till heltid.
- **Mertid har utmönstrats 2025**: deltidsanställda får övertidsersättning
  för tid utöver sin ordinarie tid (UN s. 3).
- Endast fulla halvtimmar; övertid som inte ansluter: minst 3 tim; anspråk inom
  3 månader (§ 4.2.3, 4.2.4, 4.2.7).
- Undantag: skriftligt avtal om högre lön och/eller **5 eller 3 extra
  semesterdagar** (§ 4.1.1, 4.1.3 s. 13–14); minst 12 min daglig förberedelse
  ger 3 extra dagar (§ 4.1.2).

## Arbetstid

- Högst 40 tim i genomsnitt per helgfri vecka under högst tre månader
  (ändrat 2025), lokalt upp till 12 mån; intermittent treskift 38, kontinuerligt
  36 tim (bilaga 1 § 2.1–2.2 s. 60).
- **Arbetstidsförkortning fr.o.m. 2026-01-01**: en betald ledig dag per
  kalenderår för heltid, proportionellt för deltid; arbetsgivaren förlägger
  (§ 11.4.1 s. 41).

## Semester (§ 9)

- Intjänandeår 1 april – 31 mars. 25 dagar (+3 eller +5 enligt § 4). Betalda
  dagar = dagar × anställningsdagar / kalenderdagar, avrundat uppåt.
- Deltid: nettodagar = dagar/vecka × dagar / 5; tillägg och avdrag på bruttodagar.
- **Semesterlön**: aktuell månadslön + semestertillägg per betald dag
  **0,8 %** av månadslön + fasta tillägg och **0,5 %** av rörlig lön utbetald
  under intjänandeåret (provision, bonus, förskjuten tid). Rörlig lön som
  arbetsgivaren beslutar, som inte är väsentlig och betalas högst 4 ggr/år
  undantas (nytt 2025). 0,5 % räknas upp om färre dagar tjänats in än rätten
  (§ 9.4.1 s. 27–28).
- Semesterlönegrundande frånvaro: genomsnittlig dagsinkomst = utbetald rörlig
  lön / (anställningsdagar − semesterdagar − hela frånvarodagar) (§ 9.4.2).
- Semesterersättning: **4,6 %** av månadslön per outtagen dag + tilläggen;
  visstid ≤ 3 mån: 12,5 % av lönen (§ 9.4.4). Obetald dag: avdrag 4,6 %.

## Sjuklön (§ 10)

- Dag 1–14: timavdrag. Upp till 20 % av veckoarbetstiden (karens):
  månadslön × **12,2** / (52 × veckoarbetstid) per timme; därefter t.o.m. dag
  14: 20 % av samma värde (§ 10.3.2 s. 32–33). Förlorad ersättning för
  förskjuten tid betalas med 80 % efter karens. Efter 10 karensavdrag på 12
  mån: ingen ytterligare karens (§ 10.3.4). Månadslön inkluderar snitt av
  provision och bonus (§ 10.3.5).
- **Dag 15 och framåt** (§ 10.3.6 s. 34–35): gräns 10 × prisbasbelopp / 12;
  upp till gränsen 90 % × månadslön × 12 / 365 per dag; över: 90 % × 10 PBB /
  365 + 10 % × (månadslön × 12 − 10 PBB) / 365; tak fast lön × 12 / 365.
  Varaktighet t.o.m. dag 90 vid minst 1 års anställning, annars dag 45; tak 105
  resp. 45 dagar per 12 mån (§ 10.3.7 s. 36).
- OBS: gränsen är **10 prisbasbelopp**, inte 7,5 inkomstbasbelopp.

## Pension och försäkringar

- ITP, TGL, TFA, Huvudavtal om trygghet/omställning 2022 (HT s. 6).
  Deltidspension +0,2 % fr.o.m. 2025-05-01, totalt **1,7 %** (HT s. 7).
- ITP1 2026, TFA och TRR: se `visita-unionen.md` (samma premier).
  ITP-åldrar: pensionsålder 66 (ITP1) / 65 (ITP2) enligt HT § 1.4; gränsen
  mellan ITP1 och ITP2 per födelseår ej verifierad → `TODO(legal-verify)`.

## I NONOX: semester, pension, försäkringar och tillägg (A9–A12, 2026-11-02)

- Semester: sammalöneregeln, semestertillägg 0,8 % per dag; 0,5 % av rörlig lön och 4,6 %-regeln räknas inte.
- Pension och försäkringar: `pension.itp1`, `insurance.fora.tjansteman`. ITP 2 och gränsen mellan ITP 1 och ITP 2 per födelseår räknas inte (TODO(legal-verify)).
- Premierna kontrolleras som lönens regler (Lön → Regler); avtalsfilen pekar bara på dem. Gyllene lönespecar: `tests/Fixtures/agreements/payslips/svenskhandel-unionen-tjansteman.json`.

## I NONOX: övertid, mertid och semestergrund (fix-overtid, 2026-11-02)

Enligt AI-utredning 2026-11-02, juristgranskning återstår.

- Övertid räknas per dag mot den ordinarie dagliga arbetstiden (`overtime_method: DAILY`, § 4.2.1–4.2.4 s. 14–15); bara hela halvtimmar per dag (`rounding.overtime.per: DAY`, § 4.2.3 s. 14).
- Övertidskoderna är inte semesterlönegrundande (`vacation_basis: false`, § 9.4.1 s. 28: i övertids- och restidsersättning ingår semesterlön). Om det också gäller utbetald komptid är inte utrett (TODO(legal-verify)).
