---
beskrivning: Underlaget för varulagret vid bokslutet – lagervärde enligt FIFO eller vägt genomsnittspris, lägsta värdets princip och bokföring av lagerförändringen.
ordning: 80
roller: [owner, admin, accountant, bookkeeper, auditor]
modul: inventory
rutter: [inventory.reports]
sokord: [lagervärde, varulager bokslut, lagerförändring, FIFO, vägt genomsnitt, lägsta värdets princip, nettoförsäljningsvärde, inkurans, 1460, 4960, 1410, 4910, 97-procentsregeln, lagerslag]
---

# Lagervärde vid bokslut

Vid bokslutet ska varulagret tas upp till sitt värde på balansdagen. NONOX räknar fram värdet ur alla rörelser i lagret och kan bokföra skillnaden mot det som står på lagerkontot.

![Underlaget för varulagret vid bokslutet: lagervärdet, det som är bokfört på lagerkontot och lagerförändringen att bokföra.](/bilder/manual/lager/lagervarde-vid-bokslut.webp)

## Så räknas värdet

Värdet är antalet i lager gånger anskaffningsvärdet. Anskaffningsvärdet räknas med den metod företaget har valt under **Lager → Inställningar**:

- **Först in, först ut (FIFO)** – det som finns kvar räknas som det du köpte senast. Har du köpt 10 st för 100 kr och sedan 10 st för 120 kr, och sålt 15, är de 5 som finns kvar värda 5 × 120 = 600 kr.
- **Vägt genomsnittspris** – varje inköp läggs till sitt pris och varje uttag tar med sig genomsnittet just då. I exemplet ovan är genomsnittet 110 kr, och de 5 som finns kvar är värda 550 kr.

Sist in, först ut är inte tillåtet och finns inte i NONOX.

### Lägsta värdets princip

Lagret får inte tas upp högre än vad varorna kan säljas för. Har du angett ett **nettoförsäljningsvärde** på artikeln och det är lägre än anskaffningsvärdet, används det lägre värdet. Raden visas då som nedskriven. Se [Artiklar i lager](/manual/lager/artiklar).

> [!NOTE]
> NONOX gör inget schablonmässigt avdrag för inkurans. Har du varor som inte går att sälja, ange ett nettoförsäljningsvärde på dem eller kassera dem.

## Se lagervärdet

Gå till **Lager → Rapporter → Lagervärde**. Välj dag och metod. Du ser antal och värde per artikel och per plats. Du kan välja vilken dag som helst bakåt i tiden.

## Bokför lagerförändringen

1. Inventera lagret först. Se [Inventering](/manual/lager/inventering).
2. Gå till **Lager → Rapporter → Bokslut** och välj räkenskapsåret.
3. Jämför **Lagervärde** med **Bokfört på** lagerkontot. Skillnaden står under **Lagerförändring att bokföra**.
4. Klicka på **Spara lagervärdet och bokför förändringen**.

NONOX sparar värderingen med alla rader som underlag och bokför skillnaden på årets sista dag, en verifikation med ett kontopar per lagerslag:

- **Handelsvaror**, varor du köper in och säljer vidare: 1460 Lager av handelsvaror mot 4960 Förändring av lager av handelsvaror.
- **Råvaror och förnödenheter**, det du lagar mat av eller tillverkar av: 1410 Lager av råvaror mot 4910 Förändring av lager av råvaror.

Ökar lagret debiteras lagerkontot; minskar det krediteras det. Lagerslaget väljs per artikel under **Lagerslag**, och **Lagerslag för artiklar utan eget** under **Lager → Inställningar**, där du också väljer kontona. Har du aldrig valt lagerslag bokförs allt som förut på handelsvarornas konton.

## 97-procentsregeln

Ett företag som följer K2 kan under **Lager → Inställningar** välja **Ta upp lagret till 97 procent av anskaffningsvärdet (K2 punkt 12.5)**. Den är avstängd från början. Regeln gäller bara när ingen vara är nedskriven till nettoförsäljningsvärde; annars stoppar NONOX bokningen. Varor som ska kasseras måste ha flyttats bort från lagret för att slängas. Ligger de kvar, värderade till noll, får regeln inte användas för något i lagret (K2 punkt 12.18).

Det går bara efter årets sista dag och innan årets resultat är bokat. Ändras lagret eller bokföringen efteråt kan du bokföra igen; då bokförs bara den nya skillnaden.

## Checklistan vid bokslut

När Lager är påslaget heter punkten **Varulagret är inventerat och värderat**. Den blir klar när värderingen är sparad. Har du räknat och bokfört lagret på annat sätt kan du bocka för den för hand som förut.

> [!IMPORTANT]
> Vilka värderingsmetoder ett K2-företag får använda är inte slutligt kontrollerat. Fråga din redovisningskonsult om du är osäker.

## Läs också

- [Checklistan](/manual/bokslut/checklistan)
- [Bokslutsverifikationer](/manual/bokslut/bokslutsverifikationer)
