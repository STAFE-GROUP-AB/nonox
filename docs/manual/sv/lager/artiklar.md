---
beskrivning: Gör en artikel lagerförd och fyll i EAN, SKU, inköpspris, leverantör, egenskaper, beställningspunkt och standardplats under fliken Avancerad.
ordning: 20
roller: [owner, admin, accountant, bookkeeper]
modul: inventory
rutter: [inventory.articles]
sokord: [lagerförd artikel, EAN, GTIN, streckkod, SKU, skannerkod, inköpspris, beställningspunkt, vinstmarginalbeskattning, VMB, begagnat, egenskaper, utgången]
---

# Artiklar i lager

Lager använder samma artikelregister som fakturorna. Det finns ingen separat lagerartikel. I artikelformuläret finns två flikar: **Enkel** med det du behöver för att fakturera, och **Avancerad** med koder, mått, inköp och lagerfälten.

![Artiklarna i lager med bild, EAN-kod, saldo per lagerställe och inköpspris.](/bilder/manual/lager/artiklar.webp)

## Fliken Enkel

Artikelnummer, benämning, pris exklusive moms, enhet, moms och intäktskonto. Det räcker för att sälja artikeln på en faktura. Se [Artiklar](/manual/fakturering/artiklar).

## Fliken Avancerad

- **Status** – aktiv eller utgången. En utgången artikel finns kvar på gamla fakturor men föreslås inte på nya.
- **SKU** – er egen lagerkod, om den skiljer sig från artikelnumret.
- **EAN/GTIN** – streckkoden på förpackningen, 8, 12, 13 eller 14 siffror. NONOX kontrollerar kontrollsiffran, så en felskriven kod stoppas direkt. Samma kod kan bara finnas på en artikel.
- **Skannerkod** – en annan kod som skannern läser, till exempel er egen etikett.
- **Varumärke, modell och beskrivning.**
- **Egenskaper** – en lista med egenskap och värde, till exempel storlek: M eller skick: begagnad.
- **Färger och material** – skilj med komma.
- **Vikt och mått** – i gram och millimeter.
- **Inköpspris** – används som kostnad när inget annat pris anges vid inleveransen.
- **Momshantering** – vanlig moms eller vinstmarginalbeskattning (VMB) för begagnade varor.
- **Leverantör och leverantörens artikelnummer.**
- **Bilder** – fota varan med mobilen eller välj bilder. NONOX AI kan föreslå uppgifterna från bilderna. Se [Bilder och NONOX AI](/manual/lager/bilder-och-ai).

> [!IMPORTANT]
> Vinstmarginalbeskattning går att markera på artikeln, men fakturorna räknar ännu inte fram marginalen eller ruta 07 i momsdeklarationen. Det kommer i en senare version. Fram till dess bokför du marginalen som du gör i dag.

## Lagerfälten

När Lager är påslaget finns de här fälten längst ned under Avancerad:

1. Kryssa i **Lagerförd**. Då följs saldot per lagerställe och artikeln kan tas emot, plockas och inventeras.
2. Välj **Standardplats** – där artikeln oftast ligger. Inleveranser och plocklistor använder den när inget annat anges.
3. Ange **Beställningspunkt** och **Beställ antal**. När saldot når beställningspunkten syns artikeln under **Behöver beställas** på översikten.
4. Ange vid behov **Nettoförsäljningsvärde per st** med en förklaring. Det är vad varan kan säljas för minus försäljningskostnaderna. Är det lägre än inköpspriset värderas lagret till det vid bokslutet. Se [Lagervärde vid bokslut](/manual/lager/lagervarde-vid-bokslut).
5. Klicka på **Spara**.

En artikel som har varor i lager kan inte sluta vara lagerförd. Plocka, kassera eller inventera bort varorna först.

## Listan Lager → Artiklar

Här ser du de lagerförda artiklarna med saldo totalt och per lagerställe. Du kan söka på nummer, namn, EAN eller SKU, välja ett lager och visa bara artiklar under beställningspunkten eller med negativt saldo.

Från listan kan du också:

- **Flytta** varor till en annan plats. Totalen ändras inte.
- **Kassera** trasiga eller utgångna varor.
- Registrera en **retur** från en kund (in) eller till en leverantör (ut).

## Läs också

- [Bilder och NONOX AI](/manual/lager/bilder-och-ai)
- [Lagerställen](/manual/lager/lagerstallen)
- [Skriv ut etiketter](/manual/lager/etiketter) – pris- och hyllkantsetiketter med streckkod.
- [Inleverans](/manual/lager/inleverans)
