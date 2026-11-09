---
beskrivning: Stäng en månad så att MRR och rörelserna aldrig ändras i efterhand. Det som blir känt senare visas som rättelse i nästa öppna månad.
ordning: 171
roller: [owner, admin, accountant]
modul: saas
rutter: [saas.months, saas.corrections]
sokord: [stäng månad, månadsstängning, rättelse, som rapporterat, med rättelser, MRR, ändra i efterhand]
---

# Stäng en månad i Återkommande intäkter

En öppen månad räknas om varje gång ett avtal ändras. När du har rapporterat månadens MRR vill du att siffran står fast. Därför stänger du månaden: dess MRR och alla rörelser fryses och kan aldrig ändras igen, inte heller i databasen.

![Sidan Månader med knappen Stäng månad och listan över öppna och stängda månader.](/bilder/manual/fakturering/stang-en-manad-i-aterkommande-intakter.webp)

## Så här stänger du en månad

1. Gå till **Återkommande intäkter → Månader**.
2. Kortet **Stäng** och månadens namn visas när den första öppna månaden är slut.
3. Tryck på **Stäng månad**.
4. Läs vad som händer och tryck på **Ja, stäng månaden**.

Månaderna stängs i tur och ordning, och en månad kan stängas först när den är slut. Ingenting stängs av sig självt. Den 10:e i månaden efter påminner **Mina datum** om att månaden kan stängas.

## Rättelser

Får NONOX veta något efter att en månad är stängd som gäller den månaden, till exempel en prisändring med ett tidigare **Gäller från**, ändras inte den stängda månaden. Skillnaden bokas i stället som **Rättelse** i den första öppna månaden, och den står under **Återkommande intäkter → Rättelser** med kunden, beloppet, månaden den bokades i och månaden den gäller.

Exempel: mars är stängd med en kund på 500 kr. I april registreras en prissänkning till 400 kr som gäller från 1 mars. Mars står kvar på 500 kr. April börjar från 500 kr, får en rättelse på −100 kr och slutar på 400 kr.

## Som rapporterat eller med rättelser

På **Översikt** väljer du hur stängda månader visas i MRR per månad:

- **Som rapporterat** – som de var när de stängdes.
- **Med rättelser** – som de ser ut i dag, med rättelserna som har kommit efteråt. De märks **Stängd, med rättelser**. De lagrade månaderna ändras inte.

## Kontroll

Varje natt räknar NONOX om varje stängd månad ur avtalsversionerna som var kända när den stängdes, och jämför till öret. Resultatet står under **Kontroll** på **Månader**. Hittar kontrollen en avvikelse, kontakta supporten.

## Läs också

- [Återkommande intäkter](/manual/fakturering/aterkommande-intakter)
- [Avtalsfakturering](/manual/fakturering/avtalsfakturering)
