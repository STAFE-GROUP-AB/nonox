---
beskrivning: NONOX Tillverkning – stycklistor med komponenter och arbetssteg, kalkyl med material, arbete och marginal, och tillverkningsorder som tar material ur lagret.
ordning: 0
roller: [owner, admin, accountant, auditor]
modul: manufacturing
rutter: [manufacturing.home]
sokord: [tillverkning, snickeri, produktion, stycklista, bom, arbetssteg, kalkyl, marginal, tillverkningsorder]
---

# Tillverkning

Med Tillverkning beskriver du vad dina produkter består av och hur lång tid de tar att göra. När du väljer en stycklista på en offert eller en order ser du direkt vad materialet och arbetet kostar och vilken marginal raden ger. Planeringen visar när ordrarna i verkstaden troligen blir klara.

Med tillverkningsorder styr du det som ska göras i verkstaden: komponenterna tas ur lagret och de färdiga produkterna tas emot i lagret till vad de har kostat att tillverka.

Tillverkning är en modul som kräver Lager. Den är avstängd tills ägaren slår på den. Tillverkning bokför inga verifikationer: kalkylen är en siffra för dig när du sätter pris, och tillverkningsorder möter bokföringen först när lagret värderas vid bokslutet.

## Kom igång

1. Slå på Lager och sedan Tillverkning under **Inställningar för företaget → Moduler**. Se [Moduler](/manual/installningar/moduler).
2. Lägg upp produkterna och materialet som vanliga artiklar. Materialet är lagerförda artiklar med inköpspris och lagerenhet, till exempel skivor i kvadratmeter och lack i liter.
3. Lägg upp stegen i verkstaden med vad en timme kostar under **Tillverkning → Arbetssteg**. Se [Arbetssteg](/manual/tillverkning/arbetssteg).
4. Skriv stycklistorna under **Tillverkning → Stycklistor**. Se [Stycklistor](/manual/tillverkning/stycklistor).
5. Välj stycklistan på raderna i en offert eller en order. Se [Kalkyl på offert och order](/manual/tillverkning/kalkyl-pa-offert-och-order).
6. Skapa tillverkningsorder för det som ska göras. Se [Tillverkningsorder](/manual/tillverkning/tillverkningsorder).
7. Ge personerna i verkstaden sina kompetenser och följ ordrarna under **Tillverkning → Planering**. Se [Planering](/manual/tillverkning/planering).

## Sidorna

- **Tillverkningsorder** – det som ska tillverkas, med komponenter, arbetssteg och kostnad.
- **Stycklistor** – varje produkt med komponenter, arbetssteg och kostnad per enhet.
- **Arbetssteg** – stegen i verkstaden med timkostnad, kapacitet, kompetens och väntetid.
- **Efterkalkyl** – kalkyl mot rapporterad tid per produkt och arbetssteg, köer och vad ordrarna väntar på. Se [Efterkalkyl](/manual/tillverkning/efterkalkyl).
- **Verkstadsterminal** – surfplattorna i verkstaden där personalen rapporterar produktionstid. Se [Verkstadsterminal](/manual/tillverkning/verkstadsterminal).
- **Planering** – tidslinje, ordrar, resurser, kalender och avvikelser, med prognos mot utlovat datum.
- **Inställningar** – omkostnadspålägget, om det också ska ingå i lagervärdet, och kontona för produkter i arbete.

På offerter och order finns kortet **Stycklistor och kalkyl** under raderna.

## Vem ser vad

Ägare och administratörer ändrar stycklistor, arbetssteg, tillverkningsorder och inställningar och sätter upp verkstadsterminalen. Redovisningskonsulter ser också efterkalkylen. Redovisningskonsulter och revisorer ser stycklistorna, tillverkningsorderna och kalkylen men ändrar dem inte. För att ändra stycklistan på en offert- eller orderrad behöver du dessutom få ändra offerten eller ordern.
