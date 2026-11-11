---
beskrivning: Tillverka en produkt mot lager eller till en kundorder, ta komponenterna ur lagret och ta emot de färdiga produkterna till tillverkningskostnaden.
ordning: 40
roller: [owner, admin, accountant, auditor]
modul: manufacturing
rutter: [manufacturing.productionOrders.index, manufacturing.productionOrders.create, manufacturing.productionOrders.show]
sokord: [tillverkningsorder, produktionsorder, arbetsorder verkstad, tillverka, färdigmelda, materialuttag, produkter i arbete, pia]
---

# Tillverkningsorder

En tillverkningsorder säger att verkstaden ska tillverka ett antal av en produkt. Ordern får en egen kopia av produktens stycklista. När den är frisläppt reserveras komponenterna, när den startar kan de tas ur lagret, och när den är färdig tas produkterna emot i lagret till vad de har kostat att tillverka.

![Listan över tillverkningsorder med produkt, antal, status och kundorder.](/bilder/manual/tillverkning/tillverkningsorder.webp)

## Så här skapar du en tillverkningsorder

1. Gå till **Tillverkning → Tillverkningsorder** och välj **Ny tillverkningsorder**.
2. Välj under **Tillverka** antingen **Mot lager, från en artikel** eller **Till en rad på en kundorder**.
3. Mot lager: välj **Produkt**. Till en kundorder: välj **Kundorder** och **Rad**. Radens antal och radens egen stycklista följer med; har raden ingen egen stycklista används produktens.
4. Skriv **Antal** och välj **Enhet**. Lämnar du antalet tomt för en kundorderrad används radens antal.
5. Välj **Tas emot på**, lagerstället där de färdiga produkterna ställs. Tomt betyder produktens standardplats.
6. Fyll i **Planerad start**, **Klart senast** och vid behov **Anteckningar för verkstaden**.
7. Välj **Skapa tillverkningsorder**. Ordern får ett nummer, till exempel TO-12, och är ett utkast.

Produkten måste vara lagerförd och ha en stycklista som gäller i dag. Från en kundorder kan du också gå direkt: i kortet **Stycklistor och kalkyl** på ordern finns **Skapa tillverkningsorder** under varje rad.

## Status

| Status | Betyder |
|---|---|
| **Utkast** | Ordern planeras. Antal och orderns stycklista kan ändras. |
| **Frisläppt** | Ordern ska göras. Komponenterna reserveras och räknas i inköpsförslaget. |
| **Startad** | Arbetet har börjat. Komponenter kan ha tagits ur lagret. |
| **Färdig** | Allt är uttaget och produkterna är mottagna i lagret. |
| **Makulerad** | Ordern gäller inte längre. Numret finns kvar. |

En frisläppt order kan tas tillbaka med **Tillbaka till utkast** så länge den inte har startat.

## Ordern och dess stycklista

![En startad tillverkningsorder med komponenter, uttaget och vad som finns i lager.](/bilder/manual/tillverkning/tillverkningsorder-ordern.webp)

Under **Komponenter** står vad ordern behöver av varje komponent, spill inräknat, vad som är **Uttaget**, vad som finns **I lager**, vad andra frisläppta och startade order har **Reserverat av andra**, vad som **Kommer** på inköpsorder och vad som **Saknas**. Saknas något visar sidan en länk till **Inköpsförslag**, där du kan beställa det. Se [Inköpsförslag](/manual/lager/inkopsforslag).

Medan ordern är ett utkast kan du välja **Ändra orderns stycklista** och byta komponenter, mängder, spill och minuter. Ändringen gäller bara ordern; produktens stycklista är oförändrad. Med **Ändra** ändrar du antal (bara i utkast), lagerställe, datum och anteckningar.

Under **Arbetssteg** står den planerade tiden för hela antalet och den tid som har rapporterats.

## Så här kör du ordern

1. Välj **Frisläpp** när ordern ska göras.
2. Välj **Starta**. Kryssa i **Ta ut alla komponenter ur lagret nu** om materialet tas ut direkt, och välj **Starta ordern**.
3. Har du inte tagit ut allt kan du välja **Ta ut komponenter** när som helst medan ordern är startad.
4. Välj **Färdigmeld** när produkterna är klara. Kontrollera **Färdig den** och **Antal tillverkade**.
5. Skriv den faktiska tiden under **Faktisk tid per arbetssteg, minuter**. Ett tomt fält betyder den planerade tiden, eller den tid som redan är rapporterad på arbetssteget.
6. Välj **Färdigmeld och ta emot i lagret**.

![Färdigmeldningen med datum, antal och faktisk tid per arbetssteg.](/bilder/manual/tillverkning/tillverkningsorder-fardigmeld.webp)

> [!WARNING]
> Uttag ur lagret och färdigmeldningen går inte att ändra efteråt. Ett uttag ångras bara genom att makulera ordern innan den är färdig.

Komponenterna tas i första hand från artikelns standardplats och sedan från andra lagerställen som har den. Finns det inte tillräckligt i lager stoppar NONOX uttaget, om inte företaget tillåter negativt lager.

## Tillverkningskostnaden

Under **Kostnad** står **Kalkyl**, räknad från orderns stycklista med dagens priser, bredvid **Utfall hittills**. För en färdig order står **Tillverkningskostnad**, det som produkterna togs emot till:

- **Material**: komponenterna till det värde de hade i lagret när de togs ut, enligt företagets värderingsmetod (FIFO eller vägt genomsnitt). En komponent som inte är lagerförd räknas till sitt kalkylpris.
- **Arbete**: minuterna per arbetssteg gånger arbetsstegets timkostnad.
- **Omkostnader**: bara om ni under **Tillverkning → Inställningar** har kryssat i **Räkna in pålägget i lagervärdet för tillverkade produkter och produkter i arbete**. Det är avstängt från början.

Kostnaden per styck är tillverkningskostnaden delad med antalet tillverkade.

## Det här händer i bokföringen

En tillverkningsorder bokförs inte. Materialet bokfördes när det köptes och lönerna när de betalades. Lagret möter bokföringen vid bokslutet, när lagret värderas:

- de färdiga produkterna ligger i lagret till sin tillverkningskostnad, som andra artiklar;
- order som är startade men inte färdiga på årets sista dag räknas som **Produkter i arbete**: det uttagna materialet plus det arbete som har rapporterats. De bokförs på ett eget kontopar, 1440 mot 4940 om du inte väljer andra konton under **Tillverkning → Inställningar**.

Se [Lagervärde vid bokslut](/manual/lager/lagervarde-vid-bokslut).

> [!IMPORTANT]
> Vilka kostnader som ska räknas in i egentillverkade varor och produkter i arbete, och vilka konton de ska bokföras på, är inte slutligt kontrollerat. Fråga din redovisningskonsult innan du bokför lagerförändringen.

## Makulera en order

Välj **Makulera**, skriv **Varför makuleras ordern?** och välj **Makulera ordern**. En startad order kräver en anledning. Komponenter som har tagits ut läggs tillbaka i lagret till det värde de lämnade med. Rapporterad arbetstid finns kvar på ordern.

## Vem ser vad

Ägare och administratörer skapar och kör tillverkningsorder. Redovisningskonsulter och revisorer ser dem men ändrar dem inte.

## Läs också

- [Stycklistor](/manual/tillverkning/stycklistor)
- [Arbetssteg](/manual/tillverkning/arbetssteg)
- [Kalkyl på offert och order](/manual/tillverkning/kalkyl-pa-offert-och-order)
- [Inköpsförslag](/manual/lager/inkopsforslag)
- [Lagervärde vid bokslut](/manual/lager/lagervarde-vid-bokslut)
