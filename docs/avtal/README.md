# NONOX Avtal — kollektivavtal i lönen

Status: **specifikation, förslag** (2026-11-01). Beslut i
`docs/adr/ADR-033-nonox-avtal.md`. Ingen kod är skriven.

NONOX Avtal är en modul (`agreements`, beroende av `payroll`) som låter
lönen räkna enligt det kollektivavtal företaget är bundet av: minimilöner,
OB, övertid och mertid, semester, sjuklön, pension och avtalsförsäkringar.
Avtalen levereras som **versionerad, källhänvisad data** — aldrig som kod och
aldrig som avtalstext — och en lönekörning stoppas tills versionen som gäller
är verifierad av en namngiven person.

## Filer

| Fil | Innehåll |
|---|---|
| `README.md` | den här filen: avtal, prioritet, underhåll, verifiering |
| `datamodell.md` | JSON-format för avtalsregler, tabeller, hur motorn läser dem |
| `gap-analys.md` | vad lönemotorn kan i dag, vad som saknas, byggordning |
| `grona-riks.md` | Gröna Riks (Visita–HRF) inkl. hängavtal |
| `visita-unionen.md` | Besöksnäringens tjänstemannaavtal |
| `detaljhandelsavtalet.md` | Detaljhandeln (Svensk Handel–Handels) |
| `handelns-tjanstemannaavtal.md` | Svensk Handel–Unionen/Akademikerförbunden |
| `lager-och-e-handelsavtalet.md` | Lager och E-handel (Svensk Handel–Handels) |
| `bageri.md` | Livsmedelsavtalet + tilläggsavtal bageriföretag |
| `frisoravtalet.md` | Frisörföretagarna–Handels |

## 1. Avtal i omfång

Giltighet enligt avtalstexterna (se respektive fil för sida/paragraf).

### Våg 1 — café, restaurang, butik, frisör, bageri

| Prio | Avtal | Parter | Kollektiv | Giltighet | Hängavtal | Segment |
|---|---|---|---|---|---|---|
| 1 | **Gröna Riks** | Visita – HRF | arbetare | 2025-04-01 – 2027-03-31 | ja, eget hängavtal hos HRF: högre minimilöner, bara generell höjning, administrationsavgift 0,51 % | café, restaurang, bar, hotell |
| 2 | **Besöksnäringens tjänstemannaavtal** | Visita – Unionen | tjänstemän | 2025-06-01 – 2027-05-31 | ja, via Unionen (2025 års hängavtalsinfo ej hittad); TRR 0,80 % i st. f. 0,40 % | restaurang-/hotellchef, administration |
| 3 | **Detaljhandelsavtalet** | Svensk Handel – Handels | arbetare | 2025-04-01 – 2027-03-31 | ja (text under Handels `ag-hang`); namn/avgift ej hittat; högre TSL-premie | butik |
| 4 | **Handelns tjänstemannaavtal** | Svensk Handel – Unionen, Akademikerförbunden | tjänstemän | 2025-05-01 – 2027-04-30 | ja, via Unionen | butikschef, kontor i handel |
| 5 | **Lager- och E-handelsavtalet** | Svensk Handel – Handels | arbetare | 2025-04-01 – 2027-03-31 | som Detaljhandel | e-handel, lager |
| 6 | **Livsmedelsavtalet + Tilläggsavtal bageriföretag** | Livsmedelsföretagen – Livs | arbetare | 2025-04-01 – 2027-03-31 | ja, utan avgift till Livs | bageri, konditori (produktion) |
| 7 | **Frisöravtalet** | Frisörföretagarna – Handels | arbetare | 2025-04-01 – 2027-03-31 (overifierad; fulltext 2025 ej publik) | text ej hittad; skönhet/nagel tecknar direkt med Handels | frisör, barberare |

Konditori/café med servering: servispersonalens avtal (Livs eller
Visita–HRF) är oklart och avgörs per företag (`bageri.md`). Därför ska avtal
kunna väljas **per anställning**, inte bara per företag.

### Våg 2 — små tjänste- och konsultföretag

| Avtal | Parter | Kollektiv | Giltighet | Anmärkning |
|---|---|---|---|---|
| Tjänstemannaavtalet | Almega Tjänsteföretagen – Unionen | tjänstemän | t.o.m. 2027-04-30 (24 mån) | minimilöner efter ålder (20 år 21 325 kr 2025, 21 858 kr 2026; 24 år 24 482 / 25 094 kr), ingen OB, en ATF-dag fr.o.m. 2026. Källa: Unionens hängavtalsnytt 2025, https://www.unionen.se/sites/default/files/files/Almega_Tj%C3%A4nstef%C3%B6retagen_Tjmavtalet_H%C3%A4ngavtalsnytt2025.pdf (dokumentet bär även rubriken "Medieföretagen MIA fd. HTF" — kontrollera variant) |
| IT/Tech-avtalet | TechSverige – Unionen, Sveriges Ingenjörer, Akavia | tjänstemän | 2025-04-01 – 2027-03-31 | 5,5 % över två revisioner; minimilöner ej hittade. https://via.tt.se/pressmeddelande/3856706/techsverige-har-traffat-nytt-kollektivavtal-for-ittech |
| Kompetensföretagen, Revision & Konsult | Almega – Unionen m.fl. | tjänstemän | ej verifierat | egna minimilöner (sekundärt) |

Våg 2 kräver främst övertid med delare, "övertid ingår i lönen", sjuklön dag
15–90/45, sammalöneregel med 0,8 % och ITP1 — samma mekanik som
tjänstemannaavtalen i våg 1.

### Våg 3 — bygg och hantverk

| Avtal | Parter | Giltighet | Anmärkning |
|---|---|---|---|
| Byggavtalet | Byggföretagen – Byggnads | 2025-05-01 – 2027-04-30 | grundlön yrkesarbetare 196 kr/tim (34 104 kr/mån), 203 kr/tim fr.o.m. 2026-05-01; ackord; https://www.byggnads.se/49e5fe/siteassets/avtalsrorelsen/2025/hangavtalsinformation-och-overenskommelser/hangavtalsinformation-byggavtalet-2025-2027.pdf s. 3 |
| Installationsavtalet | Installatörsföretagen – Elektrikerförbundet | 2025-05-01 – 2027-04-30 | minimilöner per branschår 21 759–32 611 kr (2025); OB kap 7 § 9; https://www.in.se/globalassets/dokument/arbetsgivarguiden/publik/kollektivavtal/installationsavtalet-2025-2027.pdf |
| Teknikinstallationsavtalet VVS & Kyl | Installatörsföretagen – Byggnads | 2025-05-01 – 2027-04-30 | lägsta lön 205 kr/tim; https://www.byggnads.se/4a2cb1/siteassets/avtalsrorelsen/2025/hangavtalsinformation-och-overenskommelser/hangavtalsinformation-teknikinstallationsavtalet-2025-2027.pdf s. 3 |
| Kollektivavtal för Måleriyrket | Måleriföretagen – Byggnads | 2025-05-01 – 2027-04-30 | lägsta tidlön 205,25 kr (211,45 fr.o.m. 2026-05-01), semesterlön 13,2 %, mätningsavgift via Fora; https://www.byggnads.se/49c2a8/siteassets/avtalsrorelsen/2025/hangavtalsinformation-och-overenskommelser/hangavtalsinformation-kollektivavtal-for-maleriyrket-2025-2027.pdf s. 3–4 |

Våg 3 kräver ackord, restid, avtalstraktamente och ID06-koppling till
personalliggaren — egen gapanalys när vågen startar.

### Utanför omfång

FTP-planen (försäkringsbranschen, FAO) — inte relevant för NONOX målgrupp.
Förväxla inte med FPT (föräldrapenningtillägg, 0 % 2026).

## 2. Gemensamma premier 2026

Gäller alla arbetaravtal ovan (Svenskt Näringsliv–LO), källa
https://www.avtalat.se/media/10ql04hg/2026-premier_asl_22-12-2025.pdf:

| Premie | Medlem | Hängavtal |
|---|---|---|
| Avtalspension SAF-LO, 22 år t.o.m. månaden före 65 | 4,50 % ≤ 52 125 kr/mån, 30,0 % över | samma |
| AGS, AGB, PBF, FPT | 0 % | 0 % |
| TFA (hela lönen) | 0,01 % | 0,01 % |
| TGL (≤ 37 000 kr/mån) | 0,10 % | 0,10 % |
| TSL, huvudavtal | 0,34 % | 0,74 % |
| TSL, enbart omställningsavtal | 0,15 % | 0,55 % |

Tjänstemän (ITP1, Collectum/Fora/avtalat.se): ålderspension 4,5 % ≤ 52 125
kr/mån och 30 % upp till 208 500 kr/mån från 25 år; TFA 0,02 %; TRR 0,40 %
(hängavtal 0,80 %); TGL som kronbelopp. Basbelopp 2026: IBB 83 400 kr
(https://regeringen.se/artiklar/2025/11/inkomstbasbelopp-och-inkomstindex-for-ar-2026-faststallt/),
PBB 59 200 kr.

Särskilda tillägg per avtal: Gröna Riks hängavtal +0,51 %
administrationsavgift; Frisör +0,500 % extrapension (Fora 2026).

## 3. Prioritet och byggordning

1. **Gröna Riks** först: flest kunder (café/restaurang), OB i fasta kronor och
   halvtimmesavrundning är det enklaste att få rätt, och hängavtalsvarianten
   visar att `variant` fungerar.
2. **Detaljhandel** och **Lager/E-handel**: procent-OB, åldersminimilöner,
   mertid från 2026-04-01.
3. **Visita–Unionen** och **Handelns tjm**: tjänstemannamekanik (övertid med
   delare, sjuklön dag 15+, semestertillägg 0,8 %).
4. **Bageri**: OB-tabell per dag och tid, helgdagsbegrepp med julafton,
   arbetstidskonto.
5. **Frisör**: provision med garanti — kräver omsättning per frisör som input
   och att 2025 års avtalstext kan verifieras.

Motorarbetet i ordning finns i `gap-analys.md` §5.

## 4. Underhåll

Avtalsdata blir fel på fasta datum. Underhållet är en schemalagd uppgift med
ansvarig, inte något man gör "när någon märker".

| När | Vad | Exempel |
|---|---|---|
| **1 januari** varje år | nya premier för SAF-LO, ITP, AGS, TFA, TGL, TSL/TRR; nya basbelopp (IBB, PBB) | `pension.saf_lo` 2027, `insurance.fora.arbetare` 2027 |
| **1 april / 1 maj / 1 juni** | nya lönesteg inom avtalsperioden: minimilöner, OB-belopp, garantibelopp, kostavdrag | Gröna Riks och Detaljhandel 1 april, Handelns tjm 1 maj, Visita–Unionen 1 juni |
| **1 januari** | ATF-timmar som ökar per kalenderår | Visita–Unionen 8 tim 2026, 12 tim 2027 |
| **Avtalsrörelsen 2027** | nya avtal: de flesta i våg 1 löper ut 2027-03-31 (Gröna Riks, Detaljhandel, Lager, bageri), 2027-04-30 (Handelns tjm, bygg) och 2027-05-31 (Visita–Unionen). Medlingsinstitutet: över 500 löneavtal (81 %) omförhandlas 2027 (https://www.mi.se/nyheter/2026/de-forhandlar-flest-avtal/) | ny version per avtal med nytt `valid_from` |
| Löpande | rättelser, lokala tolkningar, nya hängavtalsversioner (HRF publicerade en ny hängavtalsfil 2026-03) | ny version, aldrig ändring av verifierad |

Om ett nytt avtal inte är klart när det gamla löper ut gäller det gamla
normalt tills vidare (t.ex. Gröna Riks § 30: ett år i taget). Då förlängs
`valid_to` på den verifierade versionen (det enda fältet triggern tillåter att
ändra, som för `payroll_rule_versions`), och nya belopp läggs som ny version
med retroaktiv lön när avtalet träffats.

## 5. Verifiering

Samma disciplin som lönereglerna (PAYROLL_MODULE.md B.6):

1. Varje värde i en avtalsfil har en källa (dokument + sida/paragraf).
   Värden som bara finns i sekundärkälla eller sammanfattning markeras
   `unverified` och gör versionen overifierbar.
2. Importerad version är **overifierad**. En namngiven person — installationen
   (`agreements:verify`) eller företagets egen löneansvarige — jämför mot
   källan och verifierar. `verified_by` och `verified_at` sparas; företagets
   egen kontroll är en rad i `agreement_rule_verifications` (append-only).
3. En verifierad version kan inte ändras eller tas bort (Postgres-trigger);
   rättelse = ny version.
4. **En lönekörning blockeras** när en anställning är bunden till ett avtal
   och det saknas verifierad version för betalningsdagen och periodens
   arbetsdagar. Ingen reserv till äldre version eller generiska regler.
5. Varje lönerad som avtalet påverkat bär versionens id och källa i sin
   förklaring.
6. Gyllene lönespecar per avtal körs i CI. Ett nytt lönesteg utan uppdaterade
   fixturer får inte gå igenom.

## 6. Vad dokumenten i den här mappen är

Sammanställningar av **fakta** (belopp, tider, procentsatser, datum) och vår
egen beskrivning av hur de ska räknas, med hänvisning till parternas
publicerade texter. Ingen avtalstext är kopierad. Alla värden är avlästa
2026-11-01 och **inte verifierade**; de får inte användas i en lönekörning
förrän verifieringen i §5 är gjord. Juridiska frågor: ADR-033.
