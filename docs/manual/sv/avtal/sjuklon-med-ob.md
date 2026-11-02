---
beskrivning: Sjuklön som tar med den OB personen skulle ha fått enligt schemat, avtalets timvärde och karens i tid, karensavdrag vid oregelbunden arbetstid, och tjänstemännens avdrag från dag 15.
ordning: 55
roller: [owner, payroll_admin]
modul: agreements
sokord: [sjuklön OB, sjuklön schema, karensavdrag oregelbunden, karens timanställd, karens i tid, timvärde sjuklön, 12,2/52, 4,6 procent, sjuk dag 15, sjuklön dag 15-90, sjukavdrag kalenderdag, 105 dagar, direkt övergång, fasta tillägg, förlorad OB, anställningsförmåner]
---

# Sjuklön med OB enligt schemat

Den som är sjuk ska enligt sjuklönelagen få 80 procent av den lön och de förmåner hen går miste om, och OB är en sådan förmån. När modulerna [Avtal](/manual/avtal) och [Planering](/manual/planering) är på räknar NONOX sjuklönen från det publicerade schemat.

## Det här krävs

- Avtal och Planering är på.
- Anställningen är knuten till ett avtal, med en kontrollerad version.
- Avtalet säger att OB ingår i sjuklönen. Det gör Gröna Riks, Visita–Unionen, Detaljhandeln, Lager och e-handel och Handels tjänstemän.
- Den sjuka hade publicerade pass de dagarna.
- Frånvaron är registrerad som sjukdom i lönen. Se [Frånvaro, sjuklön och karensavdrag](/manual/lon/franvaro-och-sjuklon).

## Så räknas det

**Timmarna.** En sjukdag med publicerade pass räknas med passens timmar, rasterna avdragna. Det gäller även en lördag eller en dag som inte finns i lönens vanliga veckoschema.

**OB.** NONOX delar upp passen i avtalets OB-tider, precis som när tiden attesteras, och prissätter dem med avtalets sats för dagen. Sjuklönen för OB blir en egen rad på lönespecen, **Sjuklön 80 % av OB enligt schemat**. Ingår OB i lönen enligt anställningen räknas ingen OB.

**Karensavdraget** är 20 procent av sjuklönen för en genomsnittlig vecka, med den OB personen brukar få. Har anställningen ingen veckoarbetstid, som ofta för timanställda med oregelbundna tider, räknar NONOX fram en genomsnittlig vecka:

1. I första hand den attesterade tiden de tre månaderna före sjukperioden. Veckor innan personen började, veckor före den första attesterade tiden och veckor med bara frånvaro, till exempel semester, räknas inte.
2. Finns ingen attesterad tid används det publicerade schemat från den vecka sjukdomen börjar, under beräkningsperioden i Planering men minst fyra veckor. Bara veckor med pass räknas; en vecka utan publicerade pass är inte noll timmar.
3. Finns varken attesterad tid eller schema stoppas lönekörningen med ett meddelande. Lägg då in en veckoarbetstid på anställningen.

Lönespecens förklaring visar avtalet, källan, perioden och om snittet kommer från attesterad tid eller schemat.

## Avtalets timvärde och karens i tid

Flera avtal räknar sjuklönen på sitt eget sätt. NONOX följer avtalet för månadsavlönade:

| Avtal | Timvärde i sjukavdrag och sjuklön | Karens |
|---|---|---|
| Visita–Unionen | månadslön × 12 / (52 × veckoarbetstid) | 20 % av veckoarbetstiden |
| Handels tjänstemän | månadslön × 12,2 / (52 × veckoarbetstid) | 20 % av veckoarbetstiden |
| Gröna Riks | månadslön / månadens arbetstimmar | 20 % av veckoarbetstiden |
| Detaljhandeln | 4,6 % av månadslönen per hel sjukdag, 1/166 per timme en del av dagen | 20 % av den veckoarbetstid ni kommit överens om i förväg |

Månadens arbetstimmar i Gröna Riks tas från de publicerade passen, annars från anställningens veckoschema utan helgdagar. I Detaljhandeln krävs en veckoarbetstid på anställningen. Det NONOX ännu inte kan räkna som avtalet säger blir en notering på lönespecen, så att du kan kontrollera det: Detaljhandelns karenstid räknas per timme och inte per hel dag, fasta tillägg är inte med i dagsavdraget, och en månad med fler än 22 schemadagar.

## Tjänstemän från dag 15

För tjänstemän i Visita–Unionen och Handels tjänstemannaavtal betalar arbetsgivaren en del av lönen även efter de första 14 dagarna. Från dag 15 drar NONOX lönen per kalenderdag enligt avtalet i stället för per timme:

- 90 procent av årslönen delad med 365, för lönen upp till 10 prisbasbelopp om året,
- 10 procent för lönen över gränsen,
- aldrig mer än en dags lön.

Det gäller till dag 90 för den som varit anställd minst ett år när sjukperioden började, eller som kom direkt från en anställning med sjuklön till dag 90. Annars gäller det till dag 45. Raden heter **Sjukavdrag dag 15–90 enligt avtal**. Prisbasbeloppet tas från lönereglerna.

- **Taket på tolv månader**: från dag 15 betalas högst 105 dagar (45 dagar för den kortare gruppen) under de tolv månaderna före sjukperiodens första dag. Därefter dras vanligt sjukavdrag, och lönespecen säger att taket är nått.
- **Månadslönen** i avdraget är lönen plus fasta tillägg och snittet av provision och bonus på attesterade lönespecar de senaste tolv månaderna. Högsta avdrag per dag räknas på den fasta lönen, alltså lönen plus de fasta tilläggen.
- **Kost och bostad** räknas också in enligt avtalen, men det gör NONOX inte ännu. Lönespecen får en notering.
- **Deltidssjuk från dag 15**: NONOX drar hela avdraget per kalenderdag och skriver en notering. Avtalen säger inget om deltid, så kontrollera avdraget.

### Så här anger du övergång och fasta tillägg

1. Öppna **Lön → Avtal** och klicka på personen under anställningarna.
2. Kryssa i **Kom direkt från en anställning med sjuklön till dag 90** om det stämmer.
3. Skriv **Fasta tillägg per månad** i kronor, till exempel 1 500. Lämna tomt om det inte finns några.
4. Klicka på **Spara**.

## Utan Planering

Är Planering av räknas timmarna från frånvaron och lönens veckoschema, utan OB från schemat. Avtalets timvärde, karens i tid och avdraget från dag 15 gäller ändå, eftersom de bara behöver avtalet.

> [!IMPORTANT]
> Värdena kommer från avtalens texter och är okontrollerade tills någon har jämfört dem med källan. Beräkningen bygger på NONOX egen genomgång av sjuklönelagen, förarbetena och avtalen den 2 november 2026; en jurist har inte granskat den än. Hur en representativ period för oregelbunden arbetstid ska väljas är inte heller kontrollerat. Är du osäker, fråga arbetsgivarorganisationen eller er redovisningskonsult.

## Läs också

- [Frånvaro, sjuklön och karensavdrag](/manual/lon/franvaro-och-sjuklon)
- [OB och övertid i lönen](/manual/planering/ob-och-overtid-i-lonen)
- [Det här gör Avtal inte ännu](/manual/avtal/det-har-gor-avtal-inte-annu)
