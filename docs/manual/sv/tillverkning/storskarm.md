---
beskrivning: En tv på väggen i verkstaden som visar dagens arbete, vad som är försenat och vad som ska levereras, utan att någon är inloggad vid den. Så kopplar du skärmen.
ordning: 50
roller: [owner, admin, accountant, auditor, scheduler]
modul: manufacturing
rutter: [manufacturing.wallScreen.admin, manufacturing.wallScreen.preview]
sokord: [storskärm, tv, väggskärm, tavla, informationsskärm, dashboard, verkstad, leveranser, försenat, andon]
---

# Storskärm i verkstaden

Storskärmen är en sida för en tv på väggen i verkstaden. Den visar vad som ska göras i dag, vad som är försenat och vad som ska levereras de närmaste dagarna, så att alla i verkstaden ser samma läge. Ingen behöver vara inloggad vid skärmen: den kopplas en gång med en engångskod och hämtar sedan läget av sig själv.

Skärmen bygger på den senaste planen under **Tillverkning → Planering**. Se [Planering](/manual/tillverkning/planering). Den kräver modulen Tillverkning, men inte verkstadsterminalen.

![Storskärmen med dagens arbete, försenade order, leveranser och det som väntar.](/bilder/manual/tillverkning/storskarm.webp)

## Det här visar skärmen

- **I dag** – de tillverkningsorder som planen har arbete på i dag, med produkt, antal, arbetssteg, arbetsstation och klockslag. Det som pågår just nu är markerat, och varje order har planens trafikljus.
- **Försenat** – order som planen räknar med blir klara efter det datum ni har lovat, med hur många arbetsdagar det skiljer och om ordern väntar på material eller kapacitet.
- **Ska levereras** – det som är lovat de närmaste fem arbetsdagarna, med planens leveransdatum och bedömning: I tid, På gränsen eller Försenad.
- **Väntar och stopp** – arbetssteg som någon har markerat som väntande på verkstadsterminalen, arbetsstationer som står still och stopp som är planerade senare i dag.

Skärmen hämtar läget var 30:e sekund. Tappar den anslutningen visar den det senaste läget och skriver från vilken tid det är.

## Det här visar skärmen aldrig

Skärmen syns för alla i verkstaden, även för besökare. Därför visar den:

- inga namn på personer: inte vem som gör vad, inte vem som är frånvarande och inte hur mycket någon har arbetat,
- inga kundnamn: en order visas med sitt nummer och sin produkt,
- inte orsaken som skrivits in när ett arbetssteg väntar, eftersom den kan nämna en person, bara att steget väntar och på vad,
- inga belopp, timkostnader eller kalkyler.

## Så här kopplar du en skärm

1. Gå till **Tillverkning → Storskärm**.
2. Under **Skärmar** skriver du ett namn, till exempel Tv:n i verkstaden, och väljer **Lägg till skärm**. NONOX visar en kopplingskod och en QR-kod.
3. Öppna länken i tv:ns webbläsare, eller skanna QR-koden, och skriv koden om den inte fylls i. Koden gäller i 15 minuter och kan bara användas en gång.
4. Ställ in webbläsaren på helskärm och låt sidan vara öppen. Skärmen kommer ihåg kopplingen, också efter att den startats om.

![Sidan Storskärm med det skärmen visar och de kopplade skärmarna.](/bilder/manual/tillverkning/storskarm-installning.webp)

Vill du se vad skärmen visar just nu väljer du **Visa tavlan**. Då öppnas samma tavla i din egen webbläsare.

## Spärra eller ta bort en skärm

En kopplad skärm är kopplad tills den spärras; kopplingen går inte ut av sig själv. Välj **Spärra** om en skärm har flyttats eller kommit bort. Den slutar visa tavlan direkt och måste kopplas på nytt med en ny kod. **Ta bort** tar bort skärmen ur listan.

> [!TIP]
> Vilken tv eller skärm som helst med en webbläsare fungerar, till exempel en smart-tv, en liten dator eller en surfplatta på ett stativ. Skärmen behöver bara nå NONOX över internet.

## Vem kan göra vad

Alla som ser Tillverkning (ägare, administratörer, redovisningskonsulter, revisorer och schemaläggare) kan öppna sidan **Storskärm** och se tavlan. Ägare, administratörer och schemaläggare, som får planera verkstaden, lägger till, spärrar och tar bort skärmar.
