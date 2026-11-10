---
beskrivning: Lägg upp stegen i verkstaden, som Kapning och Montering, med vad en timme kostar, så räknar stycklistorna fram arbetskostnaden.
ordning: 10
roller: [owner, admin, accountant, auditor]
modul: manufacturing
rutter: [manufacturing.operations]
sokord: [arbetssteg, moment, timkostnad, kapacitet, verkstad, maskin]
---

# Arbetssteg

Ett arbetssteg är ett moment i verkstaden, till exempel Kapning, Hyvling, Montering, Slipning eller Lackering. Varje steg har en timkostnad. I stycklistan anger du hur många minuter steget tar, och NONOX räknar om minuterna till arbetskostnad.

![Arbetsstegen med timkostnad, kapacitet per dag och hur många stycklistor som använder dem.](/bilder/manual/tillverkning/arbetssteg.webp)

## Så här lägger du upp ett arbetssteg

1. Gå till **Tillverkning → Arbetssteg** och välj **Nytt arbetssteg**.
2. Skriv **Namn**, till exempel Montering.
3. Skriv **Timkostnad (kr)**, vad en timme i steget kostar företaget.
4. Skriv gärna **Kapacitet, timmar per dag**: hur många bemannade timmar steget har en vanlig arbetsdag, till exempel 16 för två personer.
5. Välj **Spara arbetssteget**.

## Timkostnaden

Timkostnaden är företagets egen siffra. Räkna med lön, arbetsgivaravgifter och semester, och gärna maskinen som används i steget. NONOX visar aldrig någon enskild persons lön i kalkylen.

En ändrad timkostnad gäller nästa gång kalkylen räknas. Det du redan har skickat till en kund ändras inte.

## Arkivera

Ett arbetssteg som inte används längre arkiverar du med **Arkivera**. Stycklistor som redan har steget behåller det, men nya rader kan inte välja det. **Visa arkiverade** visar dem igen, och **Använd igen** tar tillbaka ett steg.
