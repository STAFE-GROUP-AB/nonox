---
beskrivning: En ärlig lista över vad NONOX Avtal ännu inte räknar – OB och övertid utan Planering, delar av sjuklöne- och semesterreglerna, ITP 2 och lokala OB-tider.
ordning: 70
roller: [owner, admin, accountant, payroll_admin]
modul: agreements
sokord: [begränsningar avtal, saknas avtal, lokalt avtal, komptid, mertid, avtalsförsäkringar, Fora, ITP avtal, semester enligt avtal]
---

# Det här gör Avtal inte ännu

NONOX Avtal byggs steg för steg. Den här sidan säger rakt ut vad modulen inte gör än, så att du vet vad du själv behöver hålla koll på. I varje version av ett avtal finns dessutom en lista, **Det här räknar NONOX inte ännu**, för just det avtalet. Läs den under **Lön → Avtal → Versioner och kalender**.

## Inte byggt ännu

- **OB och övertid utan Planering.** OB från arbetspassens klockslag, övertid och mertid mot schemat, komptid och sjuklön med OB räknas när modulen [Planering](/manual/planering) är på. Utan den måste timmarna för OB och övertid redan vara uppdelade, inmatade per typ eller från en fil från schemasystemet.
- **Övertidstillägg för timanställda utan Planering.** Med Planering räknas Gröna Riks och Detaljhandelns tillägg för timanställda per beräkningsperiod, se [OB och övertid i lönen](/manual/planering/ob-och-overtid-i-lonen). Utan Planering lägger du in tillägget själv.
- **Övertidsarter i andra avtal än Gröna Riks och Detaljhandeln.** För Gröna Riks och Detaljhandeln delar Planering upp övertid och mertid per art (se [OB och övertid i lönen](/manual/planering/ob-och-overtid-i-lonen)). För övriga avtal går periodens övertid på en löneart.
- **OB och komptid samtidigt i Detaljhandeln.** När övertid på OB-tid betalas i pengar räknar NONOX bara det högsta av OB och övertid. Tas övertiden ut som komptid betalas OB:n ändå; kontrollera mot avtalet.
- **Övertid före 6 november 2026 i Detaljhandeln.** Perioder som skickades till lönen före den 6 november 2026 betalade både OB och övertid för samma tid. Se över tidigare perioder med övertid efter 18.15 och rätta i nästa lönekörning.
- **Undantag från mertidsersättning i Detaljhandeln.** Mertid som beror på att den anställda själv bytt pass, eller som täcker någon annans frånvaro högst två dagar, ger ingen mertidsersättning. NONOX vet inte orsaken; ta bort tillägget från lönekörningen i de fallen.
- **Helgdagar utan pass.** En helgdag utan planerat pass räknas inte som arbetad tid när övertid räknas.
- **Komptid vid anställningens slut.** Outtagen komptid betalas inte ut av sig själv; registrera en utbetalning under **Lön → Komptid**. Lagers komptidsfaktorer finns inte som data än.
- **Delar av avtalets semesterregler.** Avtalets procentsats, semestertillägg och lägsta semesterlön per dag räknas (se [Semester enligt avtalet](/manual/avtal/semester-enligt-avtal)). Rörlig lön i tjänstemannaavtalen, 4,6 %-regeln, garantibeloppet för deltid i handeln, extra semesterdagar och semesterskulden räknas inte enligt avtalet.
- **Delar av avtalets sjuklöneregler.** Sjuklön med OB enligt schemat och tjänstemännens avdrag från dag 15 räknas, se [Sjuklön med OB enligt schemat](/manual/avtal/sjuklon-med-ob). Karens i timmar, avtalens timvärden och taket 105 dagar per tolv månader räknas också. Kost och bostad i månadslönen från dag 15, Detaljhandelns karenstid per hel dag och deltidssjukskrivning från dag 15 räknas inte fullt ut; lönespecen får då en notering. Frisöravtalets sjuklön på garantilönen räknas inte.
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
