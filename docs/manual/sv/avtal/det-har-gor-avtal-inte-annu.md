---
beskrivning: En ärlig lista över vad NONOX Avtal ännu inte räknar – OB från klockslag, komptid, delar av avtalets semester- och sjuklöneregler, ITP 2 och lokala OB-tider.
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
- **Delar av avtalets semesterregler.** Avtalets procentsats, semestertillägg och lägsta semesterlön per dag räknas (se [Semester enligt avtalet](/manual/avtal/semester-enligt-avtal)). Rörlig lön i tjänstemannaavtalen, 4,6 %-regeln, garantibeloppet för deltid i handeln, extra semesterdagar och semesterskulden räknas inte enligt avtalet.
- **Avtalets sjuklöneregler.** Sjuklön räknas enligt sjuklönelagen. Sjuklön med OB, och avtalens regler för sjuklön efter arbetsgivarperioden, räknas inte.
- **Delar av pension och försäkringar.** SAF-LO, ITP 1 och avtalsförsäkringarna räknas (se [Pension och avtalsförsäkringar](/manual/avtal/pension-och-forsakringar)). ITP 2, sjukpension och premiebefrielse i ITP 1, TGL för tjänstemän och frisörbranschens extra pension räknas inte. NONOX skickar inget till Fora eller Collectum; du laddar ner ett underlag.
- **Provision i Frisöravtalet.** Satserna finns inte i någon publik text. Provision, jour, beredskap, restid och kostavdrag räknas när avtalet har dem som data; se [Provision, tillägg och kostavdrag](/manual/avtal/provision-tillagg-och-kost).
- **Lokala avtal om OB-tider och arbetstid.** Avvikande satser kan läggas in och används efter kontroll ([Lokala avtal](/manual/avtal/lokala-avtal)), men andra OB-tider, en annan beräkningsperiod eller komptid sparas bara och räknas inte.
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
