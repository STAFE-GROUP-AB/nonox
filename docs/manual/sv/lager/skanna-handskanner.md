---
beskrivning: Skanna med telefonens kamera, en Bluetooth-skanner eller en handskanner med sladd, och vilka streckkoder Skanna läser.
ordning: 58
roller: [owner, admin, accountant, bookkeeper, stock_clerk]
modul: inventory
rutter: [inventory.scanner]
sokord: [handskanner, bluetooth, streckkodsläsare, kamera, ean-13, code 128, qr-kod, etikett]
---

# Handskanner och kamera

Skanna läser koder på tre sätt. Välj det som passar dig.

## Kameran

Tryck på kameraknappen bredvid skannfältet. Håll koden i rutan. Kameran stannar öppen, så du kan skanna flera varor efter varandra. Stäng med **Stäng kameran**.

Första gången frågar telefonen om NONOX får använda kameran. Svara ja. Svarade du nej ändrar du det i webbläsarens inställningar.

## Handskanner eller Bluetooth-skanner

De flesta handskannrar fungerar som ett tangentbord: de skriver koden och trycker Enter. Skanna har alltid fokus i skannfältet, så du behöver inte trycka någonstans först.

1. Koppla skannern till telefonen med Bluetooth, eller anslut den med sladd.
2. Öppna Skanna.
3. Skanna.

Skannern ska vara inställd på att avsluta med Enter. Det är så de flesta är från fabriken.

> [!TIP]
> Telefonens eget tangentbord visas inte medan du skannar, så det täcker inte skärmen. Vill du skriva en kod själv trycker du på tangentbordsknappen.

## Koder Skanna läser

- **EAN-13 och EAN-8** – streckkoden på de flesta varor. En UPC-kod (12 siffror) läses också.
- **Code 128** – vanlig på etiketter för hyllor och egna artikelnummer.
- **QR-kod** – till exempel på hyllans etikett.

## Etiketter för hyllor

En hyllas etikett innehåller `L:` och hyllans kod, till exempel `L:HL-A-03-2`. `L:` gör att Skanna aldrig blandar ihop en hylla med en vara. Under koden står hyllans kod i klartext, så att den går att läsa och skriva in för hand.

En egen artikeletikett utan EAN innehåller kassakoden, eller `A:` och artikelnumret.

## Läs också

- [Skanna med mobilen](/manual/lager/skanna)
- [Lagerställen](/manual/lager/lagerstallen)
