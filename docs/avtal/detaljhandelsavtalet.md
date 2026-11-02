# Detaljhandelsavtalet (Svensk Handel – Handels), arbetare

Nyckel: `svenskhandel-handels-detaljhandel` · Kollektiv: arbetare · Våg 1 ·
Segment: butik, detaljhandel (inkl. lager i butik)

## Källa

- **D1**: Kollektivavtal Detaljhandeln 2025–2027, Handels utgåva "HA2025-110",
  2025-05. https://www.handels.se/globalassets/avtalsrorelsen/ag-hang/da110.pdf
  (hämtad 2026-11-01). Sidor = avtalets tryckta sidnummer.
- **P26**: Avtalat, "2026 Arbetsgivare med kollektivavtal – arbetare, Svenskt
  Näringsliv-LO", 2025-12-22.
  https://www.avtalat.se/media/10ql04hg/2026-premier_asl_22-12-2025.pdf
- Sammanfattning (inte avtalstext): https://www.handels.se/avtalsrorelse/avtal-butik-lager-e-handel/

Status för alla värden nedan: **avläst ur källan, inte verifierad** enligt
datamodell §4. Ingen version får användas i en lönekörning förrän en
namngiven person verifierat den.

## Parter och giltighet

| Fält | Värde | Källa |
|---|---|---|
| Parter | Svensk Handel – Handelsanställdas förbund | D1 s. 1 |
| Omfattning | butiks- och kontorspersonal, lagerpersonal och chaufförer inom detaljhandeln; lager i butik eller som betjänar en butik | D1 s. 1, § 1.1 s. 5 |
| Giltighet | 2025-04-01 – 2027-03-31, två månaders uppsägningstid | D1 § 18.1 s. 54 |
| Hängavtal | Handels publicerar avtalstexten under `/ag-hang/`; namn och eventuell avgift för hängavtal **ej hittat**. Hängavtalsföretag betalar högre omställningspremie (se Pension) | D1 URL; P26 |

## Lönebildning och delare

| Mekanik | Värde | Källa |
|---|---|---|
| Heltid | månadslön | § 7.3 A s. 27 |
| Deltid | timlön; rätt till månadslön om arbetstiden genomsnittsberäknas över minst 11 veckor | § 7.3 B.1–B.2 s. 27 |
| Timlön ur månadslön | **1/166** av månadslönen; helgdagsersättning ingår i timlönen | § 7.3 B.4–5 och Anm. s. 28 |
| Deltid månadslön | timmar/vecka × timlön × 166 / 38,25 | § 7.3 B.4 s. 28 |
| Heltid | i genomsnitt 38 tim 15 min/vecka, beräkningsperiod högst 52 veckor | § 6.1 s. 17 |
| Kortaste pass | 3 timmar (schemaregel, inte minsta betalning) | § 6.2 C s. 17 |
| Utbetalning | månadsvis i efterskott, skriftlig lönespecifikation | § 7.3 C s. 28 |

## Minimilöner (§ 7.2 C, s. 24–25)

Månadslön heltid / timlön (kr). Uppflyttning sker från den 1:a i månaden då
förändringen sker (Anm. 2 s. 25).

| Grupp | 2025-04-01 – 2026-03-31 | 2026-04-01 – 2027-03-31 |
|---|---|---|
| 16 år | 16 846 / 101,48 | 17 324 / 104,36 |
| 17 år | 17 256 / 103,95 | 17 734 / 106,83 |
| 18 år | 25 814 / 155,51 | 26 626 / 160,40 |
| 19 år | 26 134 / 157,43 | 26 946 / 162,33 |
| 1 års branschvana (efter 18) | 26 718 / 160,95 | 27 530 / 165,84 |
| 2 års branschvana | 27 054 / 162,98 | 27 866 / 167,87 |
| 3 års branschvana | 28 344 / 170,75 | 29 156 / 175,64 |

- 15 år: minst 80 %, 14 år: minst 70 % av 16-åringens nivå (Anm. 3 s. 25).
- Branschvana räknas från 18 år; en månad räknas om minst 44 timmar arbetats
  (ordinarie veckotid × 4,34 + mertid), eller vid minst 10 tim/vecka hela
  månaden. Samma detaljhandelsbransch räknas fullt, annan detaljhandel till
  hälften (§ 7.2 B.1–B.3 s. 22–23). → motorn behöver
  `industry_experience_from` och en räknare för branschvanemånader.
- Anställningstidstillägg och fasta tillägg utges **utöver** minimilönen
  (§ 7.2 A s. 22).
- **Anställningstidstillägg** efter 5 år i företaget (efter 18): 155 kr/mån
  eller 0,93 kr/tim från 2025-04-01; 160 kr eller 0,96 kr/tim från
  2026-04-01; från den 1:a i månaden då gränsen nås (§ 7.2 E s. 26).
- Urmakare har egna minimilöner per anställningsår (bilaga 3 s. 62–63):
  2025: 26 570 / 27 370 / 28 070 / 29 470 / 30 070; 2026: 27 382 / 28 182 /
  28 882 / 30 282 / 30 882.
- Lönerevision (påverkar retroaktiv lön, inte motorn): generellt 734 kr
  (4,42 kr/tim) 2025, 669 kr (4,03 kr/tim) 2026; lokal pott 314 resp. 286 kr
  (§ 7.4 C s. 29–30).

## OB (§ 8, s. 33)

Endast för **ordinarie** arbetstid. Procent av timlönen = 1/166 av fast
kontant månadslön + fasta tillägg.

| Fönster | Sats |
|---|---|
| mån–fre 18.15–20.00 | 50 % |
| mån–fre efter 20.00 | 70 % |
| lördag efter 12.00 | 100 % |
| söndag och helgdag | 100 % |
| jul-, nyårs- och midsommarafton | jämställs med lördag → 100 % efter 12.00 |

- Ingen särskild natt-OB; "efter kl. 20.00" täcker natten.
- Sammanfaller övertid/mertid med OB: **endast den procentuellt högsta**
  (Anm. s. 33) → `overlap: HIGHEST_ONLY` mellan OB och övertid.
- OB som ledighet (efter överenskommelse): 30 min per timme med 50 %, 45 min
  med 70 %, 1 tim med 100 % (§ 8.2 s. 34).
- Avrundningsregel för OB-tid: **finns inte i avtalet** → ingen avrundning.
- OB i sjuklön: ja, 80 % efter karens (§ 15.4). OB i semesterlön: ja, via
  13 %-regeln; semesterlönegrundande frånvaro inkluderar OB som normalt
  skulle ha utgetts (§ 14.7 Anm. 1 s. 47).

## Övertid och mertid (§ 9, s. 34–38)

Bas: timlön 1/166 av fast kontant månadslön + fasta tillägg. Tillägget
betalas **utöver** timlönen.

| Övertid för heltid | t.o.m. 2026-03-31 | fr.o.m. 2026-04-01 |
|---|---|---|
| första två timmarna per dag före/efter ordinarie tid | 50 % | **35 %** |
| övrig övertid vardag, efter 20.00 mån–fre, vardag som är fridag | 70 % | 70 % |
| lördag efter 12.00, jul-/nyårs-/midsommarafton | 100 % | 100 % |
| söndag, helgdag, fridag som infaller lördag eller afton | 100 % | 100 % |

- Övertid på fridag: ersättning för minst 3 timmar (§ 9.1 s. 35).
- Deltid: övertid först över 38 tim 15 min/vecka, eller tid utanför
  arbetsplatsens normala schema (§ 9.3 s. 36).
- Anspråk senast vid nästa ordinarie lönetillfälle (§ 9.4 s. 36).
- **Mertid** fr.o.m. 2026-04-01: kontant ersättning motsvarande
  övertidsersättningen i § 9.2. Ingen mertidsersättning vid byte på egen
  begäran eller när man täcker annans frånvaro högst två sammanhängande
  kalenderdagar per frånvaroperiod; högst 15 min/dag kan regleras med ledighet;
  anspråk inom två månader; mertid kan inte beordras (§ 9.5 s. 37).
  Mertidsersättning före 2026-04-01: avtalet säger inget → ingen.
- **Komptid** (§ 9.6 s. 37–38): 1 tim 30 min per timme (50 %), 1 tim 21 min
  (35 %, fr.o.m. 2026-04-01), 1 tim 45 min (70 %), 2 tim (100 %).
- Butikschef: övertid/OB kan ersättas med högre lön och/eller 5 extra
  semesterdagar, skriftligt (§ 7.2 H s. 27) → `overtime_compensation =
  INCLUDED_IN_SALARY`.

## Frånvaroavdrag (§ 13, s. 42–43)

- Heltid, hel dag (månad ≤ 22 schemalagda dagar): **4,6 %** av månadslön +
  fasta tillägg per dag; över 22 dagar: månadslön / schemalagda dagar.
- Heltid, del av dag: 1/166 per timme.
- Deltid med månadslön: dag = månadslön / schemalagda dagar; timme = 38,25 ×
  (månadslön + fasta tillägg) / 166 / timmar per vecka.
- Timavlönad: ingen lön för frånvaron.

→ Motorns `AbsenceDeductions` och `SickPay` (timvärde 12/52) räknar annorlunda
och måste kunna använda avtalets formel (gap G17).

## Semester (§ 14, s. 44–47)

- Intjänandeår 1 april – 31 mars; huvudsemester juni–augusti.
- Antal dagar: semesterlagen (25). Butikschef: +5 enligt § 7.2 H.
- **Semesterlön 13 %** av semesterlöneunderlaget för alla (procentregel),
  dock lägst garantibelopp (§ 14.6 A s. 45). Inget semestertillägg i
  procent per dag.
- **Garantibelopp per betald semesterdag** (heltid, minst 3 mån):
  fyllt 18 år 1 470 kr (fr.o.m. 2025-04-01) / 1 515 kr (2026-04-01);
  3 års branschvana 1 740 / 1 790 kr (§ 14.6 B.1 s. 45).
- Deltid: arbetade timmar under intjänandeåret × garantibelopp / **1 796**
  (semesterlönefaktorn); särskild formel vid in-/utträde under året
  (§ 14.6 B.3 s. 46).
- Visstid ≤ 3 mån: semesterersättning; semesterersättning får aldrig ingå i
  lönen (§ 14.4 s. 45).
- Schemalagd helgdagsafton/helgdag mån–fre under semester ≥ 5 dagar räknas som
  semesterdag (§ 14.3).

## Sjuklön (§ 15, s. 48–50)

- **Karens** = 20 % av i förväg överenskommen genomsnittlig veckoarbetstid;
  ingen sjuklön under karensen; högst 10 karensavdrag per 12 månader
  (§ 15.4 s. 49). OBS: avtalet räknar karens i **tid**, lagen i belopp.
- Månadslön: sjuklön = 80 % av löneavdraget enligt § 13.
- Timlön: 80 % av den lön som skulle ha utgetts.
- **OB och övriga tillägg (inte övertid) betalas med 80 % efter karensen.**
- Återinsjuknande inom 5 dagar = samma period. Visstid < 1 mån: sjuklön först
  efter 14 dagars anställning.

## Övrigt som påverkar lönespecen

- Restid: 60 % av timlönen (1/166), högst 6 tim/dag; 75 % fre 18–mån 06 och
  kring helger (§ 10.2 s. 39).
- Måltidsersättning vid resa 119 kr (2025) / 122 kr (2026); researvode utöver
  skattefritt traktamente 150 kr (hel) / 75 kr (halv) (§ 11 s. 39–40).
- Betald permission högst 1 dag efter 6 månader (§ 12).
- Beredskap: hänvisning till beredskapsavtal av 1989-04-20, inga belopp i texten
  (§ 17.6 s. 52) → ej i v1.

## Pension och försäkringar

Avtalet listar TGL, AGB, AGS, Avtalspension SAF-LO, TFA, omställnings-
försäkring för arbetare och FPT (§ 17.2 s. 52). Premier 2026 (P26):

| Premie | 2026 | Källa |
|---|---|---|
| Avtalspension SAF-LO | 4,50 % upp till 52 125 kr/mån (7,5 IBB), 30,0 % över; från månaden man fyller **22** till månaden före 65 | P26 |
| AGS, AGB, PBF, FPT (Afa) | 0 % 2026 | P26 |
| TFA | 0,01 % | P26 |
| TGL | 0,10 % (lön upp till 7,5 PBB) | P26 |
| Omställning (TSL) | huvudavtal 0,34 % (medlem) / 0,74 % (hängavtal); "ej huvudavtal" 0,15 % / 0,55 % | P26 |

- Handels anslöt inte till Huvudavtalet 2024
  (https://www.handels.se/aktuellt/2023/handels-ansluter-inte-till-huvudavtalet-2024/).
  Vilken TSL-rad som gäller: `TODO(legal-verify)` med Fora.
- "Förstärkt pensionsavsättning till Avtalspension SAF-LO" av 2017-04-01
  (§ 17.14): satsen **ej hittad** → `TODO(legal-verify)`.

## Öppna frågor

1. TSL-premierad för Handelsområdet (huvudavtal eller inte).
2. Förstärkt pensionsavsättning, sats.
3. Hängavtalets namn och eventuell administrationsavgift.
4. Vilken dag som avgör minimilönesteg vid ålder (avtalet: "från den första
   i månaden då förändring sker").

## I NONOX: semester, pension, försäkringar och tillägg (A9–A12, 2026-11-02)

- Semester: procentregeln för alla (`rule_monthly` PROCENTREGELN), 13 %, garantibelopp per dag 1 470 / 1 740 kr (2025-04-01) och 1 515 / 1 790 kr (2026-04-01) med 3 års branschvana, för heltid. Deltid (faktor 1 796) ger en varning i stället för en beräkning.
- Pension och försäkringar: `pension.saf_lo`, `insurance.fora.arbetare`. TODO(legal-verify): TSL-rad för Handels (inte anslutet till Huvudavtalet 2024) och förstärkt pensionsavsättning (§ 17.14).
- Premierna kontrolleras som lönens regler (Lön → Regler); avtalsfilen pekar bara på dem. Gyllene lönespecar: `tests/Fixtures/agreements/payslips/svenskhandel-handels-detaljhandel.json`.
