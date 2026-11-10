# Träindustriavtalet (Trä- och Möbelföretagen – GS-facket), arbetare

Nyckel: `tmf-gs-traindustri` · Kollektiv: arbetare · Våg 4 (tillverkning) ·
Segment: snickeri, möbelindustri, trähusfabrik, båtbyggeri, trävaruindustri

I kön och på färdplanen kallas avtalet "Trä- och möbelavtalet" (`tra-och-mobelavtalet`).
Avtalets eget namn är **Träindustriavtalet**. Stoppmöbelindustriavtalet (samma parter)
och tjänstemannaavtalet för träindustrin (TMF – Unionen, Ledarna, Sveriges Ingenjörer)
är egna avtal och finns inte här.

## Källor

- **TI23**: Träindustriavtalet 2023-04-01 – 2025-03-31, avtalsboken som GS-facket
  publicerar,
  https://www.gsfacket.se/globalassets/dokument/avtalsbocker/avtalsbocker-2023-2026/traindustriavtalet-2023-04-01--2025-03-31.pdf
  (sha256 `c0739bb8…e70bb`). Sidor = PDF:ens sidor, en högre än det tryckta sidnumret.
  Den senaste fulltexten som är publik.
- **ADDA25**: Adda Inköpscentral, *Bilaga 05 – Arbetsrättsliga villkor 2025 för
  arbetstagare som omfattas av Träindustriavtalet* (upphandling Förskole- och
  skolbyggnader 2025-2, daterad 2025-07-04),
  https://www.adda.se/globalassets/2.-upphandling-ramavtal/4.-samhallsbyggnad/bygg-fastighet/forskole--och-skolbyggnader-2025-2/bilaga-05---arbetsrattsliga-villkor-2025-for-arbetstagare-som-omfattas-av-traindustriavtalet.pdf.
  En **sekundär** källa: upphandlingsvillkor som enligt bilagan är "hämtade från
  Träindustriavtalet". Gäller 2025-04-10 – 2026-03-31.
- **KA25**: kollektivavtalet.se, sammanfattning av Träindustriavtalet,
  https://www.kollektivavtalet.se/avtal/traindustriavtalet-gs (giltighet
  2025-04-01 – 2027-03-31). Sekundär.
- **P26**: premier 2026 för arbetare, https://www.avtalat.se/media/10ql04hg/2026-premier_asl_22-12-2025.pdf

Sökt men inte funnet 2026-11-10: fulltexten 2025–2027 hos GS-facket och TMF
(TMF:s avtal kräver medlemsinloggning; GS sökväg för 2025–2027 svarar med en
HTML-sida), utstationeringsavtalet hos Arbetsmiljöverket (bara version 2020-11-01).

Status: **avläst, inte verifierad**. Två versioner:

| Version | Källa för beloppen | Kan verifieras |
|---|---|---|
| `MEMBER-2023-04-01` (2023-04-01 – 2025-03-31) | TI23 | ja, av en namngiven person; en körning kan bara använda den när både perioden och utbetalningsdagen ligger till och med 2025-03-31 |
| `MEMBER-2025-04-01` (2025-04-01 – 2027-03-31) | ADDA25, mekaniken ur TI23 | **nej**: varje belopp har `unverified: true` |

En lönekörning med en anställning på avtalet stoppas därför för all lön från
2025-04-01, tills 2025 års text finns och en ny version utan `unverified` är
inlagd. Inga belopp för 2026-04-01 har hittats; de gissas inte.

## Lägsta löner (§ 1 mom 2) — timlön; månadslön = timlön × 175

| Grupp | År i branschen | 2023-04-01 | 2024-04-01 | 2025-04-01 (ADDA25) |
|---|---|---|---|---|
| Specialarbetare | 5:e och därefter | 174,60 | 182,90 | 190,05 |
| Yrkesarbetare | 3:e / 4:e / 5:e+ | 153,85 / 157,75 / 161,85 | 161,20 / 165,25 / 169,50 | 167,50 / 171,70 / 176,15 |
| Övriga arbetare (fyllt 18) | 1:a / 2:a / 3:e | 145,80 / 151,00 / 153,00 | 151,70 / 158,25 / 160,30 | 157,65 / 164,45 / 166,55 |
| Minderåriga 17 / 16 / under 16 | – | 123,10 / 111,25 / 98,70 | 128,95 / 116,55 / 103,40 | saknas |

Yrkesarbetare hör de två första åren till övriga arbetare (anm. c, TI23 s. 11–12),
så gruppen `YRKES` har övrigas belopp för 0–23 månaders branschvana.

## OB, kvälls-, natt- och helgarbete (§ 5 mom 1, TI23 s. 41) — kr per timme

| | Tid | 2023-04-01 | 2024-04-01 | 2025-04-01 (ADDA25) |
|---|---|---|---|---|
| a | mån–tor 17–24, tis–fre 05–06 | 36,50 | 37,65 | 38,80 |
| b | tis–fre 00–05 | 43,45 | 44,85 | 46,25 |
| c | fre 17 – mån 06 samt fridag enligt schema | 83,30 | 85,95 | 88,60 |
| d | kl. 17 dagen före trettondagen, Kristi himmelsfärd, alla helgons dag – kl. 06 dagen efter | 100,30 | 103,50 | 106,70 |
| e | kl. 17 dagen före påsk-, nyårs- och julhelgen, 1 maj och nationaldagen – kl. 06 dagen efter; kl. 17 dagen före midsommar- och pingstafton – kl. 06 efterföljande vardag | 210,85 | 217,60 | 224,35 |

- ADDA25 anger att OB-beloppen gäller "from 1 april 2019"; de är 3,05 % högre än
  TI23:s belopp från 2024-04-01 och läses därför som 2025 års. TODO(legal-verify).
- Ett belopp per timme: där fönstren överlappar gäller det högsta (`overlap: HIGHEST_ONLY`).
- OB betalas även när tiden är övertid (TI23 s. 36–37, 41).
- Fridag enligt schema (c) räknas inte av passdelningen; timmarna läggs in med `TMF_OB_C`.
- Nya kalenderdagar för spannen: dagen före nyårsafton, nationaldagen,
  pingstafton, midsommarafton och alla helgons dag, dagen efter midsommardagen,
  dagen före julafton (`DayCalendar::HELPER_DAYS`).

## Övertid och mertid (§ 5 mom 2, bilaga 1 p. 6)

| | 2023-04-01 | 2024-04-01 | 2025-04-01 (ADDA25) |
|---|---|---|---|
| Övertidstillägg per timme | 55,00 | 56,75 | 58,50 |
| Mertidstillägg per timme | 27,50 | 28,40 | 58,50 (avviker, se nedan) |

- Övertid per dag utöver heltidens dagliga mått (`overtime_method: DAILY`).
- Timavlönad: timlönen betalar timmen, koden `TMF_OT_TL` bara tillägget.
  Månadsavlönad: 1/175 av månadslönen + tillägget (`TMF_OT`, ny satsform
  `ORE_PER_HOUR` med `plus_hourly_wage: true`).
- Komptid: en timme per övertidstimme, och tillägget betalas ändå (§ 4 mom 12 s. 35).
- Allmän övertid högst 48 tim per fyra veckor eller 50 tim per kalendermånad
  och 250 tim per år (§ 4 mom 12 s. 35); inte data.

## Tillägg

| Kod | Vad | Sats | Källa |
|---|---|---|---|
| `TMF_SKIFT_2I` | intermittent två- och treskift | 5,3 % av ordinarie förtjänst | § 5 mom 3 s. 46 |
| `TMF_SKIFT_2K` | kontinuerligt tvåskift | 8,1 % | § 5 mom 3 s. 46 |
| `TMF_SKIFT_3K` | kontinuerligt treskift | 11,1 % | § 5 mom 3 s. 46 |
| `TMF_SKIFT_NATT` | ständig natt | 14,3 % | § 5 mom 3 s. 46 |
| `TMF_UTOM` | arbete utom verkstaden | 10 % av medeltimförtjänsten | § 6 mom 5 s. 50 |
| `TMF_HELGDAG` | helgdagslön, timavlönad | timlönen per timme dagen annars hade varit arbetstid | § 8 s. 54–55 |

## Semester (§ 3)

- Timavlönade: procentregeln, underlaget × 1,08 → **12,96 %**.
- Månadsavlönade: sammalöneregeln, semestertillägg **0,8 %** av månadslönen per
  betald dag; på rörliga lönedelar och övertid semesterlön enligt lag × 1,08.
- Lägsta semesterlön per dag efter tre månader, i proportion för deltid:
  1 588 / 1 207 kr (vuxna/minderåriga, semesteråret 2023/24), 1 639 / 1 246 kr
  (2024/25), 1 690 kr för vuxna 2025/26 (ADDA25).

## Sjuklön (§ 11, bilaga 1)

- Timavlönade: sjuklönelagen, OB enligt § 5 mom 1 i underlaget.
- Månadsavlönade: timvärde månadslön × 12 / (52 × veckoarbetstid), karens 20 %
  av veckoarbetstiden i tid. Avdraget från dag 15 (månadslön × 12 / 365 per
  kalenderdag) är inte data.

## Pension och försäkringar (§ 14)

Avtalspension SAF-LO, TGL, AGB, AGS, TFA och omställningsförsäkring:
`pension.saf_lo`, `insurance.fora.arbetare` (P26).

## Inte data (TODO(legal-verify) i filerna)

Ackord, restidsersättning, överflyttningsersättning, sommartid,
permitteringslön, arbetstidskonto (3,5 % 2023, 3,8 % enligt ADDA25),
lärlingslöner, feriearbetare, semesterersättning 5,4 % och ändrad
sysselsättningsgrad 4,6 % för månadsavlönade, skiftformstillägget på OB-beloppen,
medeltimförtjänst (bilaga 2), OB och premielön i helgdagslönen.

## Verifiering

En människa måste verifiera versionen innan den slås på. För 2023-versionen:
jämför varje värde med TI23 på angiven sida och markera den som kontrollerad
under Lön → Avtal → Versioner och kalender. För 2025-versionen: skaffa
avtalsboken 2025–2027 (GS-facket eller TMF), lägg in en ny fil utan
`unverified` och med båda lönestegen (2025-04-01 och 2026-04-01).
