---
beskrivning: Skanna med mobilen – ta emot, plocka, flytta, inventera och slå upp varor med telefonens kamera eller en handskanner, även utan nät.
ordning: 52
roller: [owner, admin, accountant, bookkeeper, auditor, stock_clerk]
modul: inventory
rutter: [inventory.scanner]
sokord: [skanna, skanner, streckkod, ean, qr-kod, mobil, app, lagerapp, handskanner, streckkodsläsare]
---

# Skanna med mobilen

**Skanna** är lagret i telefonen. Du håller telefonen i ena handen och skannar artiklar och hyllor. Allt du gör sparas i telefonen först och skickas till NONOX så fort det finns nät.

![Skanna i mobilen: ta emot, plocka, flytta, inventera och slå upp.](/bilder/manual/lager/skanna.webp)

Du öppnar Skanna under **Lager → Skanna**, eller på adressen `/lager/skanna`.

## Lägg Skanna på hemskärmen

Skanna är en del av NONOX. Du laddar inte ner något från App Store eller Google Play.

1. Öppna NONOX i telefonens webbläsare och logga in.
2. Gå till **Lager → Skanna**.
3. **iPhone:** tryck på dela-knappen i Safari och välj **Lägg till på hemskärmen**.
   **Android:** öppna menyn i Chrome och välj **Installera app** eller **Lägg till på startskärmen**.

Skanna blir en egen app på hemskärmen, med en egen ikon och namnet **Skanna**, bredvid NONOX om du har lagt till den också. Den öppnas direkt i skanningsläget och är låst till skanningen: där finns ingen väg vidare till resten av NONOX. Lagerpersonalen behöver alltså bara Skanna på sin telefon. Öppnar du Skanna i webbläsaren eller inifrån NONOX-appen finns länken tillbaka till NONOX kvar.

Appen zoomar inte när du dubbeltrycker eller trycker i ett fält, men du kan nypa med två fingrar för att se större. Trycker du på en artikelbild öppnas den i helskärm.

Första gången du öppnar appen loggar du in i den. Hemskärmsappar på iPhone sparar sin inloggning separat från Safari.

Öppnar du Skanna på en dator visas en QR-kod. Skanna den med telefonens kamera så öppnas sidan i telefonen.

> [!TIP]
> Har du redan lagt NONOX på hemskärmen kan du hålla fingret på ikonen och välja **Skanna lager**.

## Startsidan

Startsidan har fem stora knappar:

- [Ta emot](/manual/lager/skanna-ta-emot) – varor in från en leverantör.
- [Plocka](/manual/lager/skanna-plocka) – hämta varorna på en plocklista.
- [Flytta](/manual/lager/skanna-flytta-och-sla-upp) – flytta varor mellan två hyllor.
- [Inventera](/manual/lager/skanna-inventera) – räkna hylla för hylla.
- [Slå upp](/manual/lager/skanna-flytta-och-sla-upp#sla-upp) – vad är det här, och var finns det?

Överst finns skannfältet. Det har alltid fokus, så en handskanner skriver direkt i det. Du kan också trycka på kameran eller skriva koden själv.

## Vad Skanna förstår

| Du skannar | Skanna hittar |
|---|---|
| EAN-kod på en vara | Artikeln med den EAN-koden |
| Kassakod, SKU eller artikelnummer | Artikeln |
| Hyllans etikett (`L:HL-A-03-2`) | Lagerstället |
| En hyllkod du skriver själv (`HL-A-03-2`) | Lagerstället |

En EAN-kod med en nolla först hittar också samma vara utan nollan.

## Grönt och rött

Varje skanning ger ett svar direkt:

- **Grönt blink och ett kort pip** – rätt.
- **Rött blink, två låga toner och vibration** – fel. Texten under skannfältet säger vad som är fel.

Stäng av ljudet med högtalarknappen uppe till höger.

## Vem får använda Skanna

Alla som får se lagret kan öppna Skanna och slå upp varor. För att ta emot, plocka, flytta och inventera behövs rätten att hantera varor. [Lagerpersonal](/manual/lager/roller-och-behorigheter) har den, men ser aldrig priser eller lagervärden. Skanna visar inga priser för någon.

## Läs också

- [Utan nät](/manual/lager/skanna-utan-nat)
- [Handskanner och kamera](/manual/lager/skanna-handskanner)
- [Lagerställen](/manual/lager/lagerstallen)
