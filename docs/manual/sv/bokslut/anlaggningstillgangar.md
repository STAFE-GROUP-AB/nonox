---
beskrivning: Lägg in inventarier, datorer, maskiner och bilar i anläggningsregistret, och bokför avskrivningarna enligt plan med ett klick i månaden.
ordning: 30
roller: [owner, admin, accountant]
rutter: [accounting.fixedAssets]
sokord: [anläggningsregister, anläggningstillgång, inventarier, avskrivning, avskrivningsplan, planenlig avskrivning, nyttjandeperiod, restvärde, 1220, 1229, 7832, bokfört värde, byggnad, mark, komponent, komponentavskrivning, 1110, 1119, 1130, 7820]
---

# Anläggningsregistret och avskrivningar

En maskin, en dator eller en bil som används i flera år kostnadsförs inte på en gång. Den bokförs som en tillgång och skrivs av – kostnaden fördelas – över de år den används. Anläggningsregistret håller ordning på varje tillgång och bokför avskrivningarna åt dig varje månad.

Registret finns under **Bokföring → Anläggningar**.

![Anläggningsregistret med bokfört värde och månadens avskrivning.](/bilder/manual/bokslut/anlaggningstillgangar.webp)

## Så fungerar det

- **Inköpet** bokförs som vanligt när tillgången köps, på ett tillgångskonto som 1220 Inventarier och verktyg. Det kan du göra med en leverantörsfaktura eller en verifikation – och då kan tillgången skapas i registret samtidigt. Läs [Anläggningstillgång direkt från inköpet](/manual/bokslut/tillgang-fran-inkop).
- **Registret** bokför avskrivningarna, en gång i månaden.
- **Försäljning eller utrangering** bokförs också från registret. Läs [Sälja eller utrangera en tillgång](/manual/bokslut/salja-eller-utrangera).

## Så här lägger du in en tillgång

1. Gå till **Bokföring → Anläggningar** och klicka på **Ny tillgång**.
2. Skriv **Benämning**, till exempel "Espressomaskin".
3. Välj **Slag**: **Inventarier och verktyg**, **Datorer**, **Maskiner och andra tekniska anläggningar**, **Bilar och andra transportmedel**, **Byggnader** eller **Mark**. Kontona och en nyttjandeperiod fylls i efter slaget. Mark skrivs aldrig av, så för mark behövs ingen nyttjandeperiod och inga avskrivningskonton.
4. Fyll i **Anskaffningsdag** och **Anskaffningsvärde, kr** – priset utan moms.
5. Fyll i **Restvärde, kr** – det tillgången väntas vara värd när den är avskriven. Oftast 0.
6. Fyll i **Nyttjandeperiod, månader** – hur länge den ska skrivas av – och **Första avskrivningsmånad**.
7. Kontrollera **Tillgångskonto**, **Ack. avskrivningar** och **Avskrivningskonto**, och välj **Kostnadsställe** om ni använder sådana.
8. Flyttar du en tillgång hit från ett annat program, fyll i **Redan avskrivet, kr**.
9. Klicka på **Spara**.

Nyttjandeperioden är företagets egen bedömning av hur länge tillgången används. Förslaget är bara en utgångspunkt.

### Kontona

| Slag | Tillgång | Ackumulerade avskrivningar | Avskrivning |
|---|---|---|---|
| Inventarier och verktyg | 1220 | 1229 | 7832 |
| Datorer | 1250 | 1259 | 7835 |
| Maskiner och andra tekniska anläggningar | 1210 | 1219 | 7831 |
| Bilar och andra transportmedel | 1240 | 1249 | 7834 |
| Byggnader | 1110 | 1119 | 7820 |
| Mark | 1130 | – | – |

Kontona kan ändras per tillgång. Saknas något i kontoplanen visar registret vilka och knappen **Lägg till kontona**. Kontona för byggnader och mark föreslås först när registret har en byggnad eller mark.

## Så här bokför du månadens avskrivningar

1. Gå till **Bokföring → Anläggningar**.
2. Under **Månadens avskrivning**, välj **Månad**. Förra månaden är förvald. Rutan visar vad som ska skrivas av per tillgång.
3. Klicka på **Bokför avskrivningar**.

Står det **Inget att skriva av för …** är allt redan bokfört, eller så har ingen tillgång börjat skrivas av.

## Det här händer i bokföringen

Avskrivningen bokförs som en verifikation i serie A den sista dagen i månaden: avskrivningskontot i debet och kontot för ackumulerade avskrivningar i kredit, per tillgång.

Avskrivningen är **linjär enligt plan**: det som ska skrivas av – anskaffningsvärdet minus restvärdet – fördelas jämnt över nyttjandeperioden. För varje månad räknas hur mycket som ska vara avskrivet vid månadens slut, och skillnaden mot det som redan är avskrivet bokförs. Därför blir summan exakt, och en missad månad tas igen nästa gång.

En månads avskrivning för en tillgång bokförs bara en gång.

## Dela upp en tillgång i komponenter

Ett företag som följer K3 ska dela upp en tillgång i komponenter när delarna förbrukas olika fort och skillnaden är väsentlig, och skriva av varje komponent för sig. En byggnad delas alltid upp i mark och betydande komponenter, till exempel stomme, tak, fasad och stammar. Andra tillgångar delar företaget upp när det bedömer att det behövs. NONOX sätter ingen beloppsgräns; bedömningen är företagets.

Följer företaget K3 visar registret en påminnelse om varje byggnad som inte är uppdelad, och raden har knappen **Dela upp i komponenter**. Under K2 finns inga komponenter: där läggs byggnad och mark in som två tillgångar.

1. Bokför avskrivningarna till och med månaden före uppdelningen i registret. Är de redan bokförda utanför registret, ändra i stället planen – **Första avskrivningsmånad**, **Nyttjandeperiod, månader** och **Redan avskrivet, kr** – så att den stämmer med det som redan är avskrivet i bokföringen. Flytta inte första avskrivningsmånaden för att slippa bokföra månader som inte är bokförda någonstans: de skulle då aldrig skrivas av.
2. Klicka på **Dela upp i komponenter** på tillgångens rad. Rutan **Komponenter** öppnas. För en byggnad är **Mark**, **Stomme**, **Tak**, **Fasad** och **Stammar och installationer** ifyllda som förslag.
3. Kontrollera **Gäller från** – den första dagen i en månad. Förvalt är månaden efter den senast bokförda avskrivningen.
4. Fyll i **Anskaffningsvärde, kr** för varje komponent. Tillsammans ska de bli tillgångens anskaffningsvärde; **Kvar att fördela** visar skillnaden.
5. Fyll i **Återstående nyttjandeperiod, månader** för varje del som skrivs av. **Total nyttjandeperiod, månader** är den period som står i årsredovisningens principnot; lämnar du den tom räknas den ut som den återstående perioden plus månaderna sedan tillgångens första avskrivningsmånad. Har tillgången ett restvärde fördelas det på delarna i förhållande till anskaffningsvärdet så snart alla delar har ett anskaffningsvärde; du kan ändra **Restvärde, kr** efteråt. När du har skrivit ett restvärde själv fördelas restvärdena inte om, inte heller när ett anskaffningsvärde ändras.
6. Lämna **Redan avskrivet, kr** tomt, så fördelas det som är avskrivet på delarna som skrivs av i förhållande till deras anskaffningsvärde. Vet du varje dels egen historik kan du fylla i den för alla delar i stället.
7. Kontrollera **Tillgångskonto**. Mark föreslås på 1130. Kontot ska vara ett konto för materiella anläggningstillgångar mellan 1100 och 1299, aldrig ett konto för ackumulerade avskrivningar.
8. Klicka på **Granska uppdelningen**. Rutan visar varje komponents anskaffningsvärde, restvärde och återstående nyttjandeperiod och vad som händer. Klicka på **Dela upp tillgången**.

Uppdelningen kan inte göras i en låst period eller ett stängt räkenskapsår.

Använd **Lägg till komponent** och **Ta bort** för att ändra antalet komponenter. En tillgång har minst två komponenter och högst en markkomponent.

> [!WARNING]
> Uppdelningen går inte att ångra. Avskrivningar som redan är bokförda ändras inte. Så länge komponenterna skrivs av kan räkenskapsåret inte bytas tillbaka till K2.

### Det här händer vid uppdelningen

- Tillgången får markeringen **Uppdelad i komponenter** och skrivs inte av längre. Komponenterna visas under den, med **Komponent i** och tillgångens nummer.
- Varje komponent skrivs av på sitt **redovisade värde** – anskaffningsvärdet minus det som redan är avskrivet och restvärdet – jämnt under den återstående nyttjandeperioden från **Gäller från**. Ingen månad före uppdelningen bokförs i efterhand.
- **Mark skrivs aldrig av**. Registret visar **Skrivs inte av** på raden.
- Har en komponent ett annat tillgångskonto än tillgången, till exempel mark på 1130 när byggnaden ligger på 1110, bokförs en omföring av dess anskaffningsvärde den dag uppdelningen gäller från. Annars bokförs ingen verifikation.
- En komponent säljs eller utrangeras för sig med **Sälj eller utrangera**. Tillgången som är uppdelad kan inte bokas bort som helhet.

## Registret

Listan visar för varje tillgång **Anskaffningsvärde**, **Avskrivet** och **Bokfört värde**, och om den är **Fullt avskriven**, **Avyttrad**, **Uppdelad i komponenter** eller **Skrivs inte av**. Klicka på en tillgång för att se **Avskrivningsplan** månad för månad. Skapades tillgången från ett inköp står det **Anskaffad med verifikation** med en länk.

När en månad har bokförts för en tillgång kan bara benämning och anteckning ändras. Detsamma gäller en tillgång som är uppdelad och dess komponenter.

## Avskrivningarna och bokslutet

Bokslutets checklista kontrollerar att alla tillgångar är avskrivna till och med årets sista månad. Läs [Checklistan för bokslutet](/manual/bokslut/checklistan). Noten om anläggningstillgångar i årsredovisningen hämtar sina siffror från bokföringen.

> [!NOTE]
> Registret följer bara avskrivningsplanen. Skattemässiga avskrivningar av inventarier och överavskrivningar hanteras inte. Fråga din redovisningskonsult om företaget ska skriva av mer skattemässigt än enligt plan.

## Vem får använda registret?

Den som får hantera räkenskapsår: **Ägare**, **Administratör** och **Redovisningskonsult**.

## Läs också

- [Anläggningstillgång direkt från inköpet](/manual/bokslut/tillgang-fran-inkop)
- [Sälja eller utrangera en tillgång](/manual/bokslut/salja-eller-utrangera)
- [Årsredovisning enligt K2](/manual/bokslut/arsredovisning)
