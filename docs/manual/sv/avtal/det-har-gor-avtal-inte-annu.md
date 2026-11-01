---
beskrivning: En ärlig lista över vad NONOX Avtal ännu inte räknar – OB från klockslag, komptid, avtalets semester- och sjuklöneregler, pension, försäkringar och lokala avtal.
ordning: 70
roller: [owner, admin, accountant, payroll_admin]
modul: agreements
sokord: [begränsningar avtal, saknas avtal, lokalt avtal, komptid, mertid, avtalsförsäkringar, Fora, ITP avtal, semester enligt avtal]
---

# Det här gör Avtal inte ännu

NONOX Avtal byggs steg för steg. Den här sidan säger rakt ut vad modulen inte gör än, så att du vet vad du själv behöver hålla koll på. I varje version av ett avtal finns dessutom en lista, **Det här räknar NONOX inte ännu**, för just det avtalet. Läs den under **Lön → Avtal → Versioner och kalender**.

## Inte byggt ännu

- **OB räknad från arbetspassens klockslag.** Timmarna för OB, natt-OB och storhelg måste redan vara uppdelade, inmatade per typ eller från en fil från schemasystemet. NONOX delar inte upp ett pass med start- och sluttid i avtalets tidsfönster, och avrundar inte OB-tid enligt avtalet.
- **Övertid och mertid mot schemat.** NONOX räknar inte ut vilka timmar som är övertid eller mertid; du anger dem.
- **Komptid.** Valet **Tas ut som komptid** sparas på anställningen, men det finns inget komptidssaldo.
- **Avtalets semesterregler.** Semester räknas enligt semesterlagen och era löneinställningar. Ger avtalet högre semestertillägg eller en högre procentsats, lägg in skillnaden själv som en rad i lönekörningen.
- **Avtalets sjuklöneregler.** Sjuklön räknas enligt sjuklönelagen. Sjuklön med OB, och avtalens regler för sjuklön efter arbetsgivarperioden, räknas inte.
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
