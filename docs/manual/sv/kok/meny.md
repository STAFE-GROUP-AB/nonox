---
beskrivning: Se varje rätt med portionskostnad, pris, täckningsbidrag och råvarukostnad, och vad kassan har sålt under en period.
ordning: 20
roller: [owner, admin, accountant, bookkeeper, auditor, kitchen_staff]
modul: kitchen
rutter: [kitchen.menu]
sokord: [meny, marginal, täckningsbidrag, råvarukostnad, food cost, sålda portioner]
---

# Meny

Under **Kök → Meny** står varje rätt som har ett recept i portioner.

![Menyn med pris, råvarukostnad och andel för varje rätt.](/bilder/manual/kok/meny.webp)

## Kolumnerna

- **Pris exkl. moms** – rättens pris från artikeln.
- **Portionskostnad** – från receptet med dagens råvarupriser.
- **Täckningsbidrag** – pris minus portionskostnad.
- **Råvarukostnad** – portionskostnaden i procent av priset. Rätter över målet under **Kök → Inställningar** är markerade.
- **Sålda portioner** – från kassan under perioden, med returer avdragna.
- **Snittpris i kassan** – vad rätten faktiskt sålts för exklusive moms, som skiljer sig från listpriset vid rabatter.
- **Intjänat bidrag** – försäljningen minus portionskostnaden för de sålda portionerna.

Välj period med **Sålt från** och **till och med**, och sortera med **Sortera**. Kökspersonal ser rätterna och antalet sålda, men inga priser.

Det sålda kommer från kassans försäljningsrader, inte från kassans Z-rapport. Utan Kassakopplingar är antalet sålda noll. NONOX ändrar aldrig ett pris.
