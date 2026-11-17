---
beskrivning: Låt NONOX föreslå vem som ska ta de öppna passen, med skälen till varje förslag. Du godkänner det du vill ha, och passen blir utkast i schemat.
ordning: 38
roller: [owner, admin, payroll_admin, scheduler]
modul: planning
rutter: [planning.proposals]
sokord: [schemaförslag, föreslå schema, automatiskt schema, fyll öppna pass, bemanna, rättvis fördelning, jämn fördelning, kvällspass, helgpass, obekväm arbetstid]
---

# Schemaförslag

Har du öppna pass i schemat kan NONOX föreslå vem som ska ta dem. Förslaget följer reglerna för vilotid och arbetstid, hoppar över den som är frånvarande eller har sagt att tiden inte passar, och fördelar timmar, kvällar och helger så jämnt som möjligt. Varje förslag visar varför. Inget ändras i schemat förrän du godkänner.

![Ett förslag för en vecka: de öppna passen har en föreslagen person med skälen under, ikryssade för att godkännas.](/bilder/manual/planering/schemaforslag.webp)

## Så här gör du

1. Lägg först in de pass som behövs, utan person. Lägg ut en mall eller kopiera en vecka med rutan **Med personerna (annars blir passen öppna)** urkryssad, eller lägg till pass med **Öppet pass (ingen ännu)** i schemat.
2. Gå till **Planering → Schemaförslag**.
3. Välj perioden under **Från** och **Till**. Ett förslag gäller högst fyra veckor och tidigast från idag.
4. Öppna **Vad väger mest** om du vill ändra hur förslaget väljer (se nedan).
5. Tryck på **Gör förslag**.
6. Läs igenom passen. Kryssa ur de förslag du inte vill ha.
7. Tryck på **Godkänn markerade**, eller **Godkänn alla**.
8. Öppna schemat, titta igenom veckan och publicera den som vanligt.

De godkända passen får personen som utkast. Ingen får besked och inget syns på Min sida förrän du publicerar. Ett pass som redan var publicerat som öppet pass blir ett utkast med en opublicerad ändring.

## Det här tar förslaget hänsyn till

Förslaget föreslår aldrig någon som

- inte är anställd den dagen,
- saknar den kompetens som passet kräver,
- har angett **Kan inte jobba** för tiden,
- har godkänd frånvaro den dagen, hel eller delvis,
- redan har ett pass som överlappar,
- skulle få en ny varning för vilotid eller arbetstid: dygnsvila, veckovila, rast, timmar per vecka eller övertid på fyra veckor, och reglerna för minderåriga när de är påslagna under **Inställningar**.

Kan ingen ta passet står **Inget förslag** och skälet, till exempel "Ingen ledig med kompetensen Bartender: 1 är frånvarande, 1 har redan ett pass då." Du kan ändå lägga passet på någon för hand i schemat, och då får du varningen där.

Bland dem som kan väljer förslaget den som jämnar ut fördelningen bäst. Skälen står under personens namn:

- **Under sin avtalade tid i perioden** – personen har färre timmar i perioden än sin avtalade veckoarbetstid.
- **Färre timmar än snittet de senaste veckorna** – planerade timmar jämfört med avtalad tid, mot snittet för dem som kunde ta passet.
- **Färre kvällar, nätter och helger än snittet** – för ett pass på obekväm tid.
- **Jobbar gärna den tiden** – personen har angett **Jobbar gärna** på Min sida.
- **Brukar arbeta på avdelningen** – den avdelning personen har arbetat mest på.
- **Ger mertid över den avtalade veckotiden** – en deltidsanställd skulle få mer än sin avtalade tid den veckan. Förslaget lägger sådana pass sist.

Lönen påverkar aldrig vem som föreslås. Att välja den som kostar minst skulle gynna yngre personal med lägre lön, och det kan vara diskriminering. Därför läser förslaget aldrig lönen.

## Vad väger mest

Under **Vad väger mest** väljer du hur mycket varje sak ska väga: **Mycket**, **Något**, **Lite** eller **Inte alls**. Det du väljer sparas och gäller nästa förslag också.

- **Jämn fördelning** (standard Mycket)
- **Avtalad tid först** (standard Mycket)
- **Önskemål** (standard Något)
- **Samma avdelning som vanligt** (standard Lite)
- **Undvik mertid** (standard Lite)

**Veckor bakåt för jämn fördelning** säger hur många veckor före perioden som räknas, standard 8. **Obekväm tid vardagar** är företagets egen gräns för kvällar och nätter, standard 19:00–07:00. Lördagar, söndagar och helgdagar räknas alltid som obekväm tid. Samma gräns gäller för alla, oavsett kollektivavtal, så att fördelningen går att jämföra.

## Om schemat ändras

Ändras ett pass i perioden efter att du gjort förslaget, eller har någon fått godkänd frånvaro, ändrat sin tillgänglighet eller fått ett pass som krockar, går förslaget inte att godkänna. Du ser **Schemat har ändrats sedan förslaget gjordes. Gör ett nytt förslag.** Tryck på **Gör förslag** igen.

Ett förslag kan godkännas en gång. Godkänner du bara en del och vill fylla resten, gör ett nytt förslag.

## Bra att veta

- Förslaget fyller bara pass som redan finns. Det lägger inte till nya pass och tar inte bort några.
- Samma schema och samma inställningar ger samma förslag.
- Sidan visar skälen, aldrig någon poäng, och ingen lista över personalen rangordnas.
- Förslagen sparas i 90 dagar och tas sedan bort automatiskt.
- Bara den som får planera ser schemaförslagen. Personalen ser ingenting förrän passen är publicerade.
- Reglerna för vilotid och arbetstid är inte granskade av en jurist än. Kontrollera dem under **Inställningar** mot ert kollektivavtal.
- Har företaget kollektivavtal kan det krävas att ni förhandlar med facket innan ni börjar använda schemaförslag (11 § medbestämmandelagen). Det är arbetsgivarens ansvar. Fråga er arbetsgivarorganisation om ni är osäkra.

## Läs också

- [Schemat](/manual/planering/schemat)
- [Publicera schemat](/manual/planering/publicera-schemat)
- [Tillgänglighet](/manual/planering/tillganglighet) – när personalen kan, inte kan eller gärna jobbar.
- [Frånvaro i schemat](/manual/planering/franvaro-i-schemat)
- [Lönekostnad och varningar](/manual/planering/lonekostnad-och-varningar) – reglerna för vilotid och arbetstid.
