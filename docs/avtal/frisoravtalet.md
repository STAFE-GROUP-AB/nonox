# Frisöravtalet (Frisörföretagarna – Handels), arbetare

Nyckel: `frisorforetagarna-handels-frisor` · Kollektiv: arbetare · Våg 1 ·
Segment: frisör, barberare (skönhet/nagel/hudvård: se nedan)

## Källor och status

- **HS**: Handels sammanfattning av avtalet 2025–2027 (primär men bara
  sammanfattning): https://www.handels.se/avtalsrorelse/avtal-frisor/
- **F23**: Officiell fulltext 2023–2025:
  https://frisor.se/media/ohnjlatu/ha2023-200-friso-r-inlaga.pdf
- **F26**: Fora, prislista 2026,
  https://www.fora.se/foretag/kostnad-och-faktura/prislistor/prislista-2026---foretag-med-kollektivavtal/

**Fulltexten för 2025–2027 är inte publik** (Handels: "Hela avtalet kommer på
Mina sidor"). Belopp för 2025/2026 nedan kommer från HS; mekanik märkt
**[2023]** är hämtad ur F23 och måste bekräftas mot 2025-texten innan
verifiering. Avtalet kan därför **inte verifieras** i NONOX förrän en
2025-text finns att jämföra mot (ADR-033 fråga 4).

## Parter och giltighet

| Fält | Värde | Källa |
|---|---|---|
| Parter | Frisörföretagarna – Handelsanställdas förbund | F23 omslag; F26 |
| Giltighet | 2025-04-01 – 2027-03-31 | **overifierad** (sekundärt: kollektivavtalet.se, arbetet.se); HS bekräftar lönesteg 1/4 2025 och 1/4 2026 |
| Föregående | 2023-04-01 – 2025-03-31 | F23 § 22 s. 47 |
| Skönhet, nagel, hud | ingen arbetsgivarorganisation; arbetsgivare tecknar direkt med Handels (https://www.handels.se/vara-branscher/frisor/). Separat avtalstext ej hittad | |
| Hängavtal | text ej hittad | |
| Hyrstol | regleras inte i avtalet (F23 § 2) — hyrstolsfrisör är inte anställd och hör inte till lönen | |

## Lönemodell: basbelopp + provision, med garanti

[2023] F23 § 6 mom 1 s. 15: lönen = **basbelopp + arbetsprovision**
(procent av inarbetat belopp **exklusive moms**), summan får aldrig
understiga **garantilönen**; därtill branschvanetillägg, tjänstetidstillägg
och försäljningsprovision. Ordinarie pris gäller om den anställda
konsekvent får arbeta till rabatt.

| Belopp (HS) | 2025-04-01 | 2026-04-01 |
|---|---|---|
| Behörig frisör, basbelopp | 16 735 kr | 16 860 kr |
| Behörig frisör, garantilön | 28 713 kr | 29 574 kr |
| Behörig frisör, provision | 17,67 % | 17,77 % |
| Branschvana 1 (efter 5 000 tim) | 500 kr | 555 kr |
| Branschvana 2 (ytterligare 3 000 tim + mästarbrev) | 295 kr | 320 kr |
| Behörig barberare, basbelopp | 13 388 kr | 13 488 kr |
| Behörig barberare, garantilön | 24 980 kr | 25 730 kr |
| Behörig barberare, provision | 17,67 % | 17,77 % |
| Barberare branschvana 1 / 2 | 435 / 260 kr | 485 / 280 kr |
| Obehörig frisör/barberare, basbelopp | 15 423 kr | 15 613 kr |
| Obehörig, garantilön | 20 099 kr | 20 702 kr |
| Obehörig, provision | 10,35 % | 10,45 % |
| Tjänstetidstillägg (efter > 5 år hos samma arbetsgivare) | höjs med 65 kr över två år till 804 kr | |
| Övriga lönekategorier | +3,4 % | +3,0 % |

- Obehörig basbelopp 2025: HS anger 15 423 kr; en annan Handels-sida 15 433 kr.
  Konflikt → 15 423 tills verifierat.
- **Försäljningsprovision** [2023]: 7 % av egen varuförsäljning exkl. moms;
  inte för receptionister och servicebiträden (§ 6 mom 1.5).
- Garantilön under provanställning [2023]: 87 % av behörigas garantilön.
- Deltid proportionellt; "garantilönen är rörlig och justeras vid frånvaro
  samt mertid" [2023].
- **Delare** [2023]: heltid **173**; deltid veckotimmar × 4,325 (§ 6a mom 7 s. 28).
- Fast lön får avtalas skriftligt; garantilönen är golv och OB betalas utöver
  (§ 6a mom 8) [2023].
- **All lön, även provision och OB, betalas månaden efter arbetet, senast den
  25:e** (§ 6a mom 3) [2023] → provisionsunderlaget för period N betalas i
  körning N+1.
- Receptionister/servicebiträden har timlönetabeller; 2025/26-belopp ej
  hittade.

### Beräkning per månad (för motorn)

```
provision    = inarbetat_belopp_exkl_moms × provisionssats
fast         = basbelopp + branschvanetillägg + tjänstetidstillägg
lön          = max(fast + provision, garantilön × sysselsättningsgrad, justerad för frånvaro/mertid)
+ försäljningsprovision 7 % × egen varuförsäljning exkl. moms
```

Underlaget (inarbetat belopp per frisör) kommer från kassasystemet → ny
input `TURNOVER` per anställd och månad (gap G14). `TODO(legal-verify)`:
exakt hur garantin justeras för frånvaro och mertid i 2025-texten.

## Frånvaro [2023]

1/173 av basbeloppet (frisör) eller fast lön per frånvarotimme (§ 6b mom 1 s. 29).

## OB (§ 7) [2023]

| Fönster | Sats |
|---|---|
| vardagar efter 18.00 | 50 % |
| lördag 12–13 | 70 % |
| lördag efter 13 samt sön- och helgdag | 100 % |

- Bas: behörig frisör/barberare **87 % av garantilönen** per timme (2024:
  139,65 kr frisör, 121,49 kr barberare); obehörig: garantilönen (2024:
  112,36 kr); övriga: fast timlön.
- Nyårs- och midsommarafton = lördag; julafton = söndag (§ 5 mom 2:9).
- OB och övertid betalas inte samtidigt; den högre gäller (mom 3).
- OB som ledighet för heltid: 30 / 45 / 60 min per timme (mom 4).

## Övertid och mertid

- Övertid [2023]: timlön + **50 % tillägg beräknat på garantilönen** (fast
  timlön för övriga), eller 90 min ledigt per timme (§ 8 mom 2–3 s. 31).
- Deltid: övertid över 40 tim/vecka eller 9 tim/dag (§ 8 mom 5).
- Mertid [2023]: basbelopp + provision för timmarna upp till 40 tim/vecka.
- **Fr.o.m. 2026-04-01**: deltidsanställda får mertidsersättning "i samma nivå
  som övertidsersättningen för heltidsanställda" (HS). Beräkningsbasen är inte
  publik → **overifierad**.
- Utebliven vila: ordinarie lön + 50 % på garantilönen (§ 5 mom 5) [2023].

## Arbetstid [2023]

- 40 tim/vecka i snitt, schemaperiod upp till 52 veckor; årsarbetstid 1 992
  tim (2024 och 2025); 2026 ej hittad. Max 9 tim/dag, minst 16 lediga lördagar.
- **ATF**: upp till 10 betalda timmar/år för behörig frisör på heltid, betalt
  med garantilön; intjänas med dagar × 0,0274 (§ 10 mom 10 s. 40–41).

## Sjuklön [2023]

- Lagens 14 dagar; karens högst 8 tim för heltid, proportionellt (tabell
  40 tim → 8,0 … 10 tim → 2,0).
- **Behörig och obehörig frisör: sjuklön 93 % av garantilönen**; övriga 80 %
  av 1/173 av månadslönen eller av timlönen (§ 9 mom 4 s. 34).
- VAB: 10 % av sjuklöneunderlaget i högst 5 dagar/år (§ 9 mom 6).

## Semester [2023]

- **25 bruttodagar** för alla, även deltid (§ 10 mom 1 s. 36).
- **Semesterlön och semesterersättning 13 %** av bruttoinkomsten under
  intjänandeåret (1/4–31/3) **inklusive provision**, exklusive mottagen
  semesterlön (§ 10 mom 2 s. 38).
- Semesterlönegrundande frånvaro: dagsinkomst = bruttolön (inkl. provision,
  övertid, OB) / arbetade dagar (mom 5).
- Semesterlönegaranti per dag (heltid, ≥ 3 mån): 1 649 kr behörig, 1 154 kr
  obehörig (2024); 2025/26 ej hittade; utvidgad till barberare 2025
  (sekundärt).
- Tjänstetidstillägg kan bytas mot upp till 5 extra semesterdagar, var och en
  +0,5 % (mom 1.3, 2.1).
- Avdrag under semester: 1/173 av basbeloppet per semestertimme (mom 6).

## Pension och försäkringar

- AGS, Avtalspension SAF-LO, TFA, TGL, FPT, AGB, omställning (F23 § 20 mom 1).
- **Extra pension för frisörbranschen** (F26): 0,500 % av lönen för 22–65 år
  (0,475 % extrapension + 0,025 % premiebefrielse) utöver SAF-LO. HS anger
  "5 procent för dig som är mellan 25 och 65 år" → åldersgränsen skiljer sig;
  följ Fora för fakturering, `TODO(legal-verify)`.
- SAF-LO 2026 (F26): 4,400 % fakturerat upp till 7,5 IBB/12 ("men 4,5 procent
  förs över"), 30 % över; 22–65 år.
- Fora 2026 arbetare (F26): AGS 0, FPT 0, TGL 0,10 %, TFA 0,01 %, AGB 0,
  TSL 0,15 % (medlem i Svenskt Näringsliv) / 0,55 % (övriga).
- Friskvårdsbidrag 3 200 kr (2025), 3 400 kr (2026) (HS) — skattefri förmån
  inom gränsen `wellness.tax_free_limit`.
