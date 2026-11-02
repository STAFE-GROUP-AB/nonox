---
beskrivning: En ärlig lista över vad NONOX Avtal ännu inte räknar – övertid för timanställda, delar av sjuklönereglerna, semester, pension, försäkringar och lokala avtal.
ordning: 70
roller: [owner, admin, accountant, payroll_admin]
modul: agreements
sokord: [begränsningar avtal, saknas avtal, lokalt avtal, komptid, mertid, avtalsförsäkringar, Fora, ITP avtal, semester enligt avtal]
---

# Det här gör Avtal inte ännu

NONOX Avtal byggs steg för steg. Den här sidan säger rakt ut vad modulen inte gör än, så att du vet vad du själv behöver hålla koll på. I varje version av ett avtal finns dessutom en lista, **Det här räknar NONOX inte ännu**, för just det avtalet. Läs den under **Lön → Avtal → Versioner och kalender**.

## Inte byggt ännu

- **OB och övertid utan Planering.** OB från arbetspassens klockslag, övertid och mertid mot schemat, komptid och sjuklön med OB räknas när modulen [Planering](/manual/planering) är på. Utan den måste timmarna för OB och övertid redan vara uppdelade, inmatade per typ eller från en fil från schemasystemet.
- **Övertidstillägg för timanställda.** Tid utöver schemat betalas som timlön. Ger avtalet ett övertids- eller mertidstillägg lägger du in det själv.
- **Flera slags övertid ur schemat.** Planering skickar periodens övertid på en löneart. Övertid i anslutning till ordinarie tid och annan övertid, minst tre timmar och "förlängning högst tio minuter" skiljs inte åt.
- **Komptid vid anställningens slut.** Outtagen komptid betalas inte ut av sig själv; registrera en utbetalning under **Lön → Komptid**. Detaljhandelns och Lagers komptidsfaktorer finns inte som data än.
- **Avtalets semesterregler.** Semester räknas enligt semesterlagen och era löneinställningar. Ger avtalet högre semestertillägg eller en högre procentsats, lägg in skillnaden själv som en rad i lönekörningen.
- **Delar av avtalets sjuklöneregler.** Sjuklön med OB enligt schemat och tjänstemännens avdrag från dag 15 räknas, se [Sjuklön med OB enligt schemat](/manual/avtal/sjuklon-med-ob). Karens räknad i timmar enligt avtalet, avtalens egna timvärden i sjuklönen och taket 105 dagar per tolv månader räknas inte.
- **Tjänstepension och avtalsförsäkringar per avtal.** Pensionen räknas med den pensionsplan du lägger upp under **Lön → Anställda**, inte automatiskt från avtalet. Avtalsförsäkringar och rapporter till försäkringsbolagen görs inte.
- **Provision, fasta tillägg och kostavdrag** enligt avtalet.
- **Lokala avtal.** Ett lokalt avtal som avviker från branschavtalet, till exempel andra OB-tider, kan inte läggas in. NONOX AI läser inte lokala avtal.
- **Vila och arbetstid.** Modulen kontrollerar inte dygns- och veckovila eller arbetstidslagens regler.

## Avtal och varianter som saknas

- Bara avtalen i listan under [Kollektivavtal i lönen](/manual/avtal/kollektivavtal-i-lonen) finns som data.
- Hängavtalsvarianten finns bara för Gröna Riks.
- Frisöravtalet kan inte kontrolleras förrän avtalstexten för perioden är publik, och kan därför inte användas i lönen.

## Så här gör du under tiden

- Lägg in det NONOX inte räknar som rader under **Timmar och tillägg** i lönekörningen, med belopp du har räknat fram enligt avtalet.
- Läs listan **Det här räknar NONOX inte ännu** för ert avtal, och stäm av lönen mot avtalet en gång när ni börjar, till exempel för en anställd med OB och semester.
- Är du osäker på hur avtalet ska tillämpas, fråga arbetsgivarorganisationen, facket eller er redovisningskonsult.

## Läs också

- [Kollektivavtal i lönen](/manual/avtal/kollektivavtal-i-lonen)
- [OB och övertid enligt avtalet](/manual/avtal/ob-och-overtid)
- [Semester](/manual/lon/semester)
- [Arbetsgivaravgifter och tjänstepension](/manual/lon/arbetsgivaravgifter-och-pension)
