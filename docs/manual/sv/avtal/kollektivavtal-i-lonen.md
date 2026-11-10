---
beskrivning: Vad ett kollektivavtal betyder för lönen, vilka avtal NONOX har data för, skillnaden mellan medlem och hängavtal, och vad modulen gör.
ordning: 10
roller: [owner, admin, accountant, payroll_admin]
modul: agreements
sokord: [kollektivavtal, hängavtal, medlem arbetsgivarorganisation, Visita, Svensk Handel, Handels, HRF, Unionen, Livs, Frisörföretagarna, TMF, GS-facket, Träindustriavtalet, avtalsdata]
---

# Kollektivavtal i lönen – vad modulen gör

Ett kollektivavtal är ett avtal mellan en arbetsgivarorganisation och ett fackförbund om lön och villkor. Ett företag blir bundet på ett av två sätt:

- **Medlem i arbetsgivarorganisationen** – företaget är medlem i till exempel Visita eller Svensk Handel och följer branschens avtal.
- **Hängavtal** – företaget har skrivit avtal direkt med fackförbundet. Villkoren är ofta desamma som branschavtalet, men lönerna kan skilja sig.

Avtalet bestämmer bland annat minimilöner per lönegrupp och ålder, OB-tillägg för kvällar, nätter och helger, övertidsersättning, semester, sjuklön och pension. Allt det som avtalet säger mer än lagen ska lönen följa.

![Lönekörningen räknar OB och övertid enligt avtalet.](/bilder/manual/avtal/ob-och-overtid.webp)

## Vad Avtal gör i dag

- **Avtalets värden som data med källa.** Minimilöner, OB- och övertidssatser, lönegrupper och avtalets dagtyper (till exempel storhelg) levereras som versionerad data. Varje värde har en källa: dokument och sida i parternas publicerade text. NONOX innehåller aldrig själva avtalstexten.
- **Kontroll innan användning.** En version används först när en namngiven person har jämfört den med källorna. Se [Versioner och kontroll](/manual/avtal/versioner-och-kontroll).
- **Bindning och anställning.** Du väljer vilket avtal företaget är bundet av och hur, och anger avtal, lönegrupp och branschvana på varje anställning.
- **OB och övertid prissätts.** Timmar för OB och övertid, inmatade eller från en fil från schemasystemet, får avtalets sats för arbetsdagen. Se [OB och övertid enligt avtalet](/manual/avtal/ob-och-overtid).
- **Minimilönen kontrolleras.** Varje lönebesked jämförs med avtalets minimilön för lönegruppen, åldern och branschvanan. Ligger lönen under blir det en varning på lönekörningen – lönen ändras aldrig av sig själv.
- **Avtalskalender.** Helgdagar, aftnar och avtalets dagtyper med klockslag räknas fram för varje år.
- **NONOX AI** kan läsa hängavtal och anställningsavtal och föreslå inställningarna. Se [NONOX AI föreslår avtal och lönegrupp](/manual/avtal/nonox-ai).

Det som inte är byggt ännu, till exempel OB räknad direkt från arbetspassens klockslag, avtalets semester- och sjuklöneregler och avtalsförsäkringar, står i [Det här gör Avtal inte ännu](/manual/avtal/det-har-gor-avtal-inte-annu).

## Avtalen NONOX har data för

| Avtal | Parter | Gäller för | Varianter |
|---|---|---|---|
| Gröna Riks | Visita – HRF | Arbetare på café, restaurang, bar och hotell | Medlem och hängavtal |
| Besöksnäringens tjänstemannaavtal | Visita – Unionen | Tjänstemän i restaurang och hotell, till exempel chefer och administration | Medlem |
| Detaljhandelsavtalet | Svensk Handel – Handels | Arbetare i butik | Medlem |
| Handelns tjänstemannaavtal | Svensk Handel – Unionen och Akademikerförbunden | Tjänstemän i handeln | Medlem |
| Lager- och E-handelsavtalet | Svensk Handel – Handels | Arbetare i lager och e-handel | Medlem |
| Livsmedelsavtalet med tilläggsavtal för bageriföretag | Livsmedelsföretagen – Livs | Arbetare i bageri och konditori (produktion) | Medlem |
| Frisöravtalet | Frisörföretagarna – Handels | Frisörer och barberare | Medlem, kan inte kontrolleras |
| Träindustriavtalet | Trä- och Möbelföretagen – GS-facket | Arbetare i trä- och möbelindustrin, till exempel snickerier och möbelfabriker | Medlem; versionen från 2025 kan inte kontrolleras |

Versionerna gäller avtalsperioden som började 2025. För Träindustriavtalet finns även perioden 2023–2025; se [Träindustriavtalet](/manual/avtal/traindustriavtalet). Exakta datum står under **Lön → Avtal → Versioner och kalender**.

> [!NOTE]
> Frisöravtalets och Träindustriavtalets texter för 2025–2027 är inte publika. Värdena kommer från en sammanfattning, så versionen kan inte kontrolleras mot en primär källa och kan därför inte användas i lönen. Tills en version med källa finns anger du lönen på raderna själv.

För de avtal där bara medlemsvarianten finns som data kan du inte välja hängavtal ännu. Är ni bundna genom hängavtal till ett av dem, kontakta oss.

## Ett avtal per företag eller per anställd?

Ett företag kan vara bundet av flera avtal, till exempel Gröna Riks för personalen i köket och Besöksnäringens tjänstemannaavtal för restaurangchefen. Därför anger du avtal **per anställning**. En anställd som inte omfattas av något avtal, som en delägare eller en chef utanför avtalet, lämnas utan avtal och får varken avtalsregler eller varningar.

## Vem gör vad

- Ägaren och löneadministratören väljer avtal, anger avtalsuppgifter på anställningarna och kontrollerar versioner.
- Administratören och redovisningskonsulten kan se sidorna.

## Läs också

- [Välj avtal för företaget](/manual/avtal/valj-avtal)
- [Versioner och kontroll av avtalets värden](/manual/avtal/versioner-och-kontroll)
- [OB och övertid enligt avtalet](/manual/avtal/ob-och-overtid)
- [Det här gör Avtal inte ännu](/manual/avtal/det-har-gor-avtal-inte-annu)
