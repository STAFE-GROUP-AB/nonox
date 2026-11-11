---
beskrivning: Skriv vad en produkt består av, komponenter med mängd och spill och arbetssteg med minuter, och se kostnaden per enhet för material, arbete och omkostnader.
ordning: 20
roller: [owner, admin, accountant, auditor]
modul: manufacturing
rutter: [manufacturing.boms.index, manufacturing.boms.create, manufacturing.boms.show]
sokord: [stycklista, bom, produktstruktur, komponent, spill, kap, version, delmontage]
---

# Stycklistor

En stycklista säger vad en produkt består av: komponenter med mängd och spill, och arbetssteg med minuter. NONOX räknar fram vad produkten kostar per enhet, uppdelat på material, arbete och omkostnader.

![Stycklistan för ett ekbord med komponenter, arbetssteg och kostnaden per styck.](/bilder/manual/tillverkning/stycklista.webp)

## Så här skriver du en stycklista

1. Gå till **Tillverkning → Stycklistor** och välj **Ny stycklista**.
2. Välj under **Stycklistan gör** vilken produkt det gäller. Produkten är en vanlig artikel med försäljningspriset.
3. Skriv **Namn** och **Gäller för antal**, hur många av produkten raderna räcker till. Oftast 1.
4. Lägg till komponenterna med **Lägg till komponent**. Välj artikel, skriv **Nettomängd** och välj **Enhet**. Skriv **Spill %** för kap och bortfall.
5. Lägg till arbetsstegen med **Lägg till arbetssteg** och skriv **Minuter** för hela antalet.
6. Välj **Spara stycklistan**.

## Mängd, enhet och spill

Nettomängden är det som blir kvar i produkten. Spillet är kap och bortfall i procent av bruttomängden, och bruttomängden är det som tas ur lagret. Med 10 % spill och 0,3 liter netto går det åt 0,333 liter.

Du kan skriva mängden i vilken enhet artikeln kan räknas om till, till exempel 3 dl lack när lacken lagras i liter, eller 250 dm² skiva när skivan lagras i kvadratmeter. NONOX sparar mängden i lagerenheten och visar det du skrev bredvid. Blir mängden noll i lagerenheten får du skriva en större mängd. Ändras mängden mer än 0,5 % när den räknas om får du en varning, men raden sparas. Se [Enheter på artiklar](/manual/fakturering/enheter-pa-artiklar).

## Flera nivåer

En komponent kan ha en egen stycklista, till exempel en låda i en byrå. Lådans material och arbete räknas då in i byråns kostnad, arbetet som arbete. En stycklista som skulle innehålla sig själv, direkt eller genom en annan stycklista, går inte att spara.

## Kostnaden

Varje komponent räknas med lagrets värde enligt företagets värderingsmetod när den finns i lager. Annars används dess egen stycklista, det senaste inköpspriset eller artikelns inköpspris, i den ordningen. Arbetet är minuterna gånger arbetsstegets timkostnad. Omkostnaderna är pålägget under **Tillverkning → Inställningar**, om ni har ett.

> [!NOTE]
> Kalkylen är en siffra för prissättning, inte lagervärdering. Lagervärdet vid bokslutet räknas som förut i Lager.

## Versioner

Varje ändring sparas som en ny version från ett datum, med **Ändra stycklistan**. Offerter och order behåller den stycklista de redan har. En version som ska gälla bakåt i tiden kräver att du bekräftar det. **Inaktivera stycklistan** gör att den inte kan väljas på nya rader; rader som redan har en kopia behåller den.

## Läs också

- [Tillverkningsorder](/manual/tillverkning/tillverkningsorder)
- [Kalkyl på offert och order](/manual/tillverkning/kalkyl-pa-offert-och-order)
- [Arbetssteg](/manual/tillverkning/arbetssteg)
