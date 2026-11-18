---
beskrivning: Packa en plockad order i kollin med vikt och mått, skriv in transportör och spårningsnummer, skicka försändelsen och skriv ut följesedeln.
ordning: 45
roller: [owner, admin, accountant, bookkeeper, stock_clerk]
modul: inventory
rutter: [inventory.shipments]
sokord: [packa, kolli, kollin, försändelse, följesedel, leveranssedel, spårningsnummer, transportör, frakt, skicka varor, vikt]
---

# Packa och skicka

När en plocklista är klar ligger varorna framme. Nästa steg är att packa dem. Under **Lager → Försändelser** lägger du det plockade i kollin, skriver in vikt och mått, anger transportör och spårningsnummer och skriver ut en följesedel som följer med paketet. NONOX bokar inget här: varorna lämnade lagret redan när du klarmarkerade plocklistan.

![Försändelser med ett kolli som är packat med en batch, och det som är kvar att packa.](/bilder/manual/lager/forsandelser.webp)

## Så här gör du

1. Klarmarkera plocklistan med **Klar, dra från lagret**. Se [Plocklista](/manual/lager/plocklista).
2. Klicka på **Packa** vid plocklistan. Då öppnas försändelsen.
3. Klicka på **Lägg till kolli**. Skriv **Vikt (kg)**, **Längd (cm)**, **Bredd (cm)** och **Höjd (cm)** och klicka på **Spara mått**. Måtten är valfria.
4. Under **Lägg i kollit** väljer du vara och skriver **Antal**. Klicka på **Lägg i**. Under **Kvar att packa** ser du vad som återstår.
5. Ryms allt i ett paket klickar du på **Packa allt som är kvar i ett nytt kolli** i stället.
6. Skriv **Transportör** och **Spårningsnummer** för hand, välj **Skickad den** och klicka på **Skicka**.
7. Klicka på **Följesedel (PDF)** och skriv ut den.

Det går att skicka först när allt som plockades ligger i något kolli och inget kolli är tomt. En försändelse som är skickad kan inte ändras, men transportör och spårningsnummer kan du rätta.

## Batch och serienummer

Har en artikel spårning följer batchen eller serienumret med in i kollit och står på följesedeln. En vara som plockades från två batcher visas som två rader, och du lägger i varje batch för sig. Ett serienummer är en enhet och kan bara ligga i ett kolli. Så vet du efteråt vilka batcher och serienummer som gick i vilket paket. Se [Batcher och serienummer](/manual/lager/batcher-och-serienummer).

## Order och leverans

Är plocklistan gjord från en order visar försändelsen under **Levererat på order** hur mycket av varje artikel som har skickats. Räkningen går per artikel, inte per orderrad. Ordern ändras inte av det.

## Följesedeln

Följesedeln visar mottagaren, datum, ordern, transportör och spårningsnummer, och för varje kolli vikt, mått och innehåll med batch eller serienummer. Den har inga priser. Mottagarens adress sparas på försändelsen när du klickar på **Skicka**, så följesedeln ser likadan ut senare även om kundkortet ändras.

## Bra att veta

- Du kan ångra en försändelse som inte är skickad med **Makulera**. Kollin tas bort och plocklistan kan packas på nytt.
- NONOX är inte kopplat till någon transportör. Du skriver in spårningsnumret själv och bokar ingen frakt här.
- Det finns en försändelse per plocklista. Packa i flera omgångar genom att göra flera plocklistor.
- Du behöver rätt att hantera lagret (Lagerpersonal duger). Följesedeln har inga priser.

## Läs också

- [Plocklista](/manual/lager/plocklista)
- [Batcher och serienummer](/manual/lager/batcher-och-serienummer)
- [Offerter och order](/manual/fakturering/offerter-och-order)
