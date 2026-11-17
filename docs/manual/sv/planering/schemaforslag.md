---
beskrivning: Låt NONOX föreslå vem som ska ta de öppna passen, och vilka nya pass bemanningsbehovet kräver inom budgeten, med skälen. Du godkänner det du vill ha, och passen blir utkast i schemat.
ordning: 38
roller: [owner, admin, payroll_admin, scheduler]
modul: planning
rutter: [planning.proposals]
sokord: [schemaförslag, föreslå schema, automatiskt schema, fyll öppna pass, bemanna, rättvis fördelning, jämn fördelning, kvällspass, helgpass, obekväm arbetstid, nya pass, bemanningsbehov, passlängd, budget]
---

# Schemaförslag

Har du öppna pass i schemat kan NONOX föreslå vem som ska ta dem. Har du bemanningsmål under **Bemanningsbehov** kan förslaget också lägga till de pass som saknas, inom veckans budget för personalkostnad. Förslaget följer reglerna för vilotid och arbetstid, hoppar över den som är frånvarande eller har sagt att tiden inte passar, och fördelar timmar, kvällar och helger så jämnt som möjligt. Varje förslag visar varför. Inget ändras i schemat förrän du godkänner.

![Ett förslag för en vecka: de öppna passen har en föreslagen person med skälen under, ikryssade för att godkännas.](/bilder/manual/planering/schemaforslag.webp)

## Så här gör du

1. Lägg in de pass som behövs, utan person. Lägg ut en mall eller kopiera en vecka med rutan **Med personerna (annars blir passen öppna)** urkryssad, eller lägg till pass med **Öppet pass (ingen ännu)** i schemat. Vill du att förslaget också lägger till pass, kryssa i **Föreslå nya pass utifrån bemanningsbehovet** (se nedan).
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
- har angett **Kan inte jobba** för tiden, någon av de dagar passet går över (ett nattpass kontrolleras mot båda dagarna),
- har godkänd frånvaro den dagen, hel eller delvis,
- redan har ett pass som överlappar,
- skulle få en varning för vilotid eller arbetstid med passet: dygnsvila före eller efter passet, veckovila, rast, timmar per vecka eller övertid på fyra veckor, och reglerna för minderåriga när de är påslagna under **Inställningar**. Det gäller också när varningen fanns redan innan: har någon redan för många timmar en vecka får hen inga fler pass den veckan.

Kan ingen ta passet står **Inget förslag** och skälet, till exempel "Ingen ledig med kompetensen Bartender: 1 är frånvarande, 1 har redan ett pass då." Du kan ändå lägga passet på någon för hand i schemat, och då får du varningen där.

Bland dem som kan väljer förslaget den som jämnar ut fördelningen bäst. Skälen står under personens namn:

- **Under sin avtalade tid i perioden** – personen har färre timmar i perioden än sin avtalade veckoarbetstid.
- **Färre timmar än snittet de senaste veckorna** – planerade timmar jämfört med avtalad tid, mot snittet för dem som kunde ta passet.
- **Färre kvällar, nätter och helger än snittet** – för ett pass på obekväm tid.
- **Jobbar gärna den tiden** – personen har angett **Jobbar gärna** på Min sida.
- **Brukar arbeta på avdelningen** – den avdelning personen har arbetat mest på.
- **Ger mertid över den avtalade veckotiden** – en deltidsanställd skulle få mer än sin avtalade tid den veckan. Förslaget lägger sådana pass sist, men stoppar dem inte. Taket för mertid per år (200 timmar enligt arbetstidslagen) kontrolleras inte i förslaget, eftersom det räknas på arbetad tid. Håll koll på det själv.

Lönen påverkar aldrig vem som föreslås. Att välja den som kostar minst skulle gynna yngre personal med lägre lön, och det kan vara diskriminering. Därför läser förslaget aldrig lönen.

## Nya pass utifrån bemanningsbehovet

Kryssa i **Föreslå nya pass utifrån bemanningsbehovet** så tittar förslaget också på bemanningsmålen under [Bemanningsbehov](/manual/planering/bemanningsbehov). Där schemat har färre personer än målet en viss timme lägger förslaget till nya pass, och föreslår vem som ska ta dem på samma sätt som för de öppna passen.

![Nya pass i ett förslag: märkta Nytt pass, med hur många timmar med för få de täcker och vem som föreslås.](/bilder/manual/planering/schemaforslag-nya-pass.webp)

- **Passlängder (timmar)** är de längder ett nytt pass får ha, i hela timmar mellan 2 och 10, till exempel `4, 6, 8`. Förslaget börjar vid den första timmen som saknar folk och väljer den längd som täcker flest timmar med för få, sedan den som ger minst tid utan behov, sedan den kortaste. Ett nytt pass slutar senast vid midnatt.
- **Rast i långa nya pass (minuter)** läggs på ett nytt pass en gång för varje gång det annars skulle bli längre än gränsen för arbete utan rast under **Inställningar** (standard fem timmar), alltså en rast i ett pass på 6, 8 eller 10 timmar. Det gäller också när varningen för raster är avstängd: avstängd tar bara bort varningen. Rasten står vid passet, till exempel "· 30 min rast", men placeras inte på en viss tid, precis som i bemanningsbehovet.
- Öppna pass som redan finns räknas som bemannade, eftersom förslaget fyller dem först. Kan ingen ta ett öppet pass räknas det ändå som bemannat, så förslaget lägger inget nytt pass för samma timmar. Den timmen saknar då fortfarande folk: ett öppet pass som förslaget inte kan fylla lämnar luckan kvar. Lägg passet på någon själv eller ändra det.
- Får ingen av passlängderna plats runt en timme som saknar folk (till exempel en timme strax före midnatt med bara långa pass), eller har förslaget redan lagt många nya pass samma dag, står det i förslaget hur många timmar av behovet som blev kvar.
- Har en avdelning egna bemanningsmål läggs de nya passen på den avdelningen. Hela företagets mål används bara när ingen avdelning har egna, så att samma behov inte räknas två gånger, och förslaget säger till när det händer. Arkiverade avdelningar får inga nya pass.
- Ett nytt pass står med **Nytt pass** och skälet "Nytt pass: täcker 3 timmar med för få i schemat". När du godkänner det blir det ett utkast i schemat med personen.

Det du väljer sparas och gäller nästa förslag också.

### Inom budgeten

Har veckan en [budget för personalkostnad](/manual/planering/budget) läggs bara de nya pass till som ryms i den. Ett nytt pass får samma uppskattade kostnad vem som än tar det: passets timmar gånger den genomsnittliga uppskattade kostnaden per timme för de pass som redan är planerade på avdelningen (annars hela företaget). De öppna passen som förslaget fyller räknas av först. Sedan går de pass först som täcker flest timmar med för få, och vid lika många det tidigaste, så att en fredag eller lördag inte blir utan bara för att den kommer sist i veckan. Ett pass som inte ryms står med **Inget förslag** och "Ryms inte i veckans budget för personalkostnad. Lägg passet själv om det ändå behövs." Kostnaden avgör aldrig vem som föreslås, bara hur många nya pass som ryms.

Överst i förslaget står ungefär vad de nya passen kostar per vecka. Det är en uppskattning, som budgeten. Finns det ingen budget läggs alla nya pass som behövs till. Har inget planerat pass en uppskattad kostnad än går budgeten inte att kontrollera, och det står då i förslaget.

### När behovet bara finns per dag

Utan kassa och utan egen timprofil vet bemanningsbehovet bara hur många personaltimmar dagen behöver, inte vilka timmar. Då lägger förslaget nya pass bara för de timmar som har en lägsta bemanning, och skriver hur många timmar som är kvar: "Behovet finns bara per dag för …". Lägg dem själv, eller ange en egen timprofil under **Bemanningsbehov**.

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

Ett nytt pass går inte att godkänna när det redan har börjat, och budgeten kontrolleras igen när du godkänner: har den sänkts så att de nya passen inte längre ryms, gör ett nytt förslag. Ett förslag kan godkännas en gång. Godkänner du bara en del och vill fylla resten, gör ett nytt förslag.

## Bra att veta

- Utan **Föreslå nya pass utifrån bemanningsbehovet** fyller förslaget bara pass som redan finns. Det tar aldrig bort några pass.
- Samma schema och samma inställningar ger samma förslag.
- Sidan visar skälen, aldrig någon poäng, och ingen lista över personalen rangordnas.
- Förslagen sparas i 90 dagar och tas sedan bort automatiskt.
- Bara den som får planera ser schemaförslagen. Personalen ser ingenting förrän passen är publicerade.
- Reglerna för vilotid och arbetstid är inte granskade av en jurist än. Kontrollera dem under **Inställningar** mot ert kollektivavtal.
- Har företaget kollektivavtal kan det krävas att ni förhandlar med facket innan ni börjar använda schemaförslag (11 § medbestämmandelagen), och facket ska hållas informerat om hur verksamheten och personalpolitiken utvecklas (19 § medbestämmandelagen). Det är arbetsgivarens ansvar. Fråga er arbetsgivarorganisation om ni är osäkra.
- Förslaget räknar på hur arbetet fördelas mellan de anställda. Det kan kräva en konsekvensbedömning enligt dataskyddsförordningen innan ni börjar använda det. Det är inte utrett ännu; fråga den som sköter dataskyddet hos er.

## Läs också

- [Schemat](/manual/planering/schemat)
- [Publicera schemat](/manual/planering/publicera-schemat)
- [Tillgänglighet](/manual/planering/tillganglighet) – när personalen kan, inte kan eller gärna jobbar.
- [Frånvaro i schemat](/manual/planering/franvaro-i-schemat)
- [Bemanningsbehov](/manual/planering/bemanningsbehov) – målen som de nya passen räknas från.
- [Budget för personalkostnad](/manual/planering/budget) – veckans budget.
- [Lönekostnad och varningar](/manual/planering/lonekostnad-och-varningar) – reglerna för vilotid och arbetstid.
