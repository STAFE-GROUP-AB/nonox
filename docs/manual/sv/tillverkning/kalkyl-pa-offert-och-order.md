---
beskrivning: Välj en stycklista på en offert- eller orderrad, ändra den för just den raden och se material, arbete, täckningsbidrag och marginal direkt.
ordning: 30
roller: [owner, admin, accountant, auditor]
modul: manufacturing
rutter: [manufacturing.settings]
sokord: [kalkyl, marginal, täckningsbidrag, offert, order, omkostnader, pålägg, variant]
---

# Kalkyl på offert och order

När du väljer en stycklista på en rad i en offert eller en order kopieras den till raden. Kortet **Stycklistor och kalkyl** visar då material, arbete och omkostnader per enhet, vad raden kostar, täckningsbidraget och marginalen mot radens belopp.

![Kortet Stycklistor och kalkyl på en offert, med en rad vars stycklista är ändrad för raden.](/bilder/manual/tillverkning/kalkyl-pa-offert.webp)

Kalkylen syns bara här i NONOX. Den kommer aldrig med på kundens PDF eller i mejlet till kunden.

## Så här väljer du en stycklista på en rad

1. Spara offerten eller ordern med raderna. Kalkylen räknas på de sparade raderna.
2. Under **Stycklistor och kalkyl** väljer du **Stycklista** för raden. Har radens artikel en stycklista är den redan vald.
3. Välj **Hämta stycklistan**.

## Ändra för just den här raden

Med **Ändra för raden** ändrar du kopian: en annan skiva, två extra beslag, fler minuter i montering, eller rader som läggs till och tas bort. Välj **Spara radens stycklista**. Raden märks **ändrad för raden**.

Ändringar på raden ändrar aldrig mallen under **Tillverkning → Stycklistor**, och ingen ny stycklista skapas. **Ta bort** tar bort kopian från raden.

## Spara som ny stycklista

Vill du använda varianten igen väljer du **Spara som ny stycklista**. Välj produkt, namn och från vilket datum den gäller. Har produkten redan en stycklista blir det en ny version av den, annars en ny stycklista. Raden behåller sin kopia.

## Från offert till order

När kunden tackar ja och offerten blir en order följer radernas stycklistor med till ordern, med ändringarna. Offerten behåller sin egen kopia.

## Summan för dokumentet

Längst ner står belopp, kostnad, täckningsbidrag och marginal för raderna med stycklista. Rader utan stycklista, som frakt, har ingen kostnad här och räknas inte med.

## Omkostnadspålägg

Under **Tillverkning → Inställningar** skriver du **Omkostnadspålägg %**, för lokaler, maskiner och administration. Pålägget läggs på material och arbete i kalkylen. Tomt betyder inget pålägg.

> [!NOTE]
> Kalkylen är en siffra för prissättning, inte lagervärdering, och den bokförs inte.
