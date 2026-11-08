---
beskrivning: Skriv recept för rätter och grundrecept med nettomängd, svinn och utbyte, och se kostnaden per portion och marginalen mot menypriset.
ordning: 10
roller: [owner, admin, accountant, bookkeeper, auditor, kitchen_staff]
modul: kitchen
rutter: [kitchen.recipes.index, kitchen.recipes.create, kitchen.recipes.show]
sokord: [recept, portionskostnad, kalkyl, grundrecept, sås, fond, svinn, utbyte, allergener]
---

# Recept

Ett recept säger vad en rätt eller ett grundrecept görs av. NONOX räknar fram vad satsen och varje portion kostar, och marginalen mot menypriset.

## Rätt eller grundrecept

- En **rätt** är en artikel på menyn med menypriset. Receptet ger ett antal **portioner**.
- Ett **grundrecept** är en sås, fond eller deg som används i andra recept. Det är en lagerförd artikel, och receptet ger en mängd i artikelns enhet, till exempel 1 kg.

Ett grundrecept kan användas i ett annat recept i flera nivåer. Ett recept som till slut skulle innehålla sig självt går inte att spara.

## Skriv ett recept

1. Gå till **Kök → Recept** och välj **Nytt recept**.
2. Välj artikeln under **Receptet gör**, skriv **Namn** och hur mycket receptet **Ger**, i portioner eller i artikelns enhet.
3. Välj **Gäller från**.
4. Lägg till ingredienserna med **Nettomängd**, **Enhet** och **Svinn %**.
5. Skriv **Tillagning** och **Allergener** om du vill, och välj **Spara receptet**.

**Nettomängd** är det som hamnar i rätten. **Svinn %** är skal, ben och putsning i procent av bruttomängden. NONOX räknar bruttomängden, det som tas ur lagret, som nettomängd × 100 / (100 − svinn %). En gul lök med 400 g netto och 12 % svinn blir 455 g brutto.

Du kan skriva gram för en artikel i kg och ml, cl eller dl för en artikel i liter. Andra enheter, till exempel cl av en flaska på 70 cl, behöver en omräkning på artikeln: öppna artikeln, fliken **Avancerad**, och lägg till den under **Omräkningar för recept**.

En rätt med utbyte i portioner kan inte vara ingrediens i ett annat recept. Ett grundrecept som används i andra recept ger alltid en mängd i artikelns enhet.

## Ändra ett recept

Välj **Ändra receptet**. Varje ändring sparas som en ny version från ett datum. Ett datum före i dag, eller före den senaste inventeringen, ändrar siffror som redan visats; då bockar du i att receptet ska gälla bakåt i tiden, och det noteras i behandlingshistoriken. Försäljning före datumet räknas med den version som gällde då, så förra månadens siffror ändras inte. Under **Versioner** ser du alla versioner. **Inaktivera receptet** gör att rättens försäljning inte längre räknas via receptet.

## Kostnaden

Kostnaden räknas från lagrets värde på varje råvara enligt företagets värderingsmetod, samma siffra som i bokföringen. Bredvid står **Senaste inköp**, och en rad markeras när de skiljer mer än 5 %. Saknar en råvara pris står **Saknar pris**, och portionskostnaden visas som ofullständig, aldrig som noll.

För en rätt visas **Täckningsbidrag per portion** och **Råvarukostnad i procent** mot menypriset exklusive moms. Siffrorna är en kalkyl, inte lagervärderingen. Ett grundrecept i kylen värderas till ingredienskostnad, exklusive arbete.

> [!NOTE]
> Allergenerna är en anteckning för köket. NONOX lämnar ingen allergeninformation till gäster.
