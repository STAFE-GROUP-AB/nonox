---
beskrivning: Sjuklön som tar med den OB personen skulle ha fått enligt schemat, karensavdrag vid oregelbunden arbetstid, och tjänstemännens avdrag från dag 15.
ordning: 55
roller: [owner, payroll_admin]
modul: agreements
sokord: [sjuklön OB, sjuklön schema, karensavdrag oregelbunden, karens timanställd, sjuk dag 15, sjuklön dag 15-90, sjukavdrag kalenderdag, förlorad OB, anställningsförmåner]
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

**Karensavdraget** är 20 procent av sjuklönen för en genomsnittlig vecka. NONOX räknar med den genomsnittliga OB som schemat ger per vecka. Har anställningen ingen veckoarbetstid, som ofta för timanställda med oregelbundna tider, räknas också timmarna i veckan fram från schemat. Snittet tas över en referensperiod som börjar måndagen i den vecka sjukdomen börjar. Den är lika lång som beräkningsperioden i Planering, men minst fyra veckor. Se [Rullande schema och beräkningsperiod](/manual/planering/rullande-schema).

Lönespecens förklaring visar avtalet, källan och referensperioden.

## Tjänstemän från dag 15

För tjänstemän i Visita–Unionen och Handels tjänstemannaavtal betalar arbetsgivaren en del av lönen även efter de första 14 dagarna. Från dag 15 drar NONOX lönen per kalenderdag enligt avtalet i stället för per timme:

- 90 procent av årslönen delad med 365, för lönen upp till 10 prisbasbelopp om året,
- 10 procent för lönen över gränsen,
- aldrig mer än en dags lön.

Det gäller till dag 90 för den som varit anställd minst ett år, annars till dag 45. Raden heter **Sjukavdrag dag 15–90 enligt avtal**. Prisbasbeloppet tas från lönereglerna.

## Utan Planering

Är Planering av, eller hade personen inga publicerade pass, räknas sjuklönen som förut: timmarna från frånvaron och lönens veckoschema, utan OB.

> [!IMPORTANT]
> Värdena kommer från avtalens texter och är okontrollerade tills någon har jämfört dem med källan. Hur en representativ period för oregelbunden arbetstid ska väljas, och avtalens tak på 105 dagar per tolv månader, är inte kontrollerat. Taket räknas inte. Är du osäker, fråga arbetsgivarorganisationen eller er redovisningskonsult.

## Läs också

- [Frånvaro, sjuklön och karensavdrag](/manual/lon/franvaro-och-sjuklon)
- [OB och övertid i lönen](/manual/planering/ob-och-overtid-i-lonen)
- [Det här gör Avtal inte ännu](/manual/avtal/det-har-gor-avtal-inte-annu)
