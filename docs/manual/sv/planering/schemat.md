---
beskrivning: Lägg passen i veckovyn eller dagvyn, flytta och ändra dem genom att dra, lägg ut öppna pass och använd filter och kortkommandon.
ordning: 20
roller: [owner, admin, payroll_admin, scheduler]
modul: planning
rutter: [planning.schedule]
sokord: [schema, veckoschema, dagschema, pass, nytt pass, flytta pass, öppet pass, rast, filter, dra och släpp]
---

# Schemat

Schemat finns under **Planering → Schema**. Varje rad är en person och varje kolumn en dag. Passen du lägger är utkast som bara ni som planerar ser, tills du [publicerar](/manual/planering/publicera-schemat) dem.

Schemat är byggt för surfplatta och dator. På mobilen visas det som en lista som du bara kan läsa.

![Veckovyn: en rad per person, öppna pass överst och helgdagar i kolumnernas rubriker.](/bilder/manual/planering/schema.webp)

![Dagvyn: passen på en tidslinje, där de dras, förlängs och ritas.](/bilder/manual/planering/schema-dag.webp)

## Veckan och dagen

- Bläddra med pilarna **‹** och **›**, eller klicka på **Idag**.
- Växla mellan **Vecka** och **Dag**. Klicka på en dag i veckans rubrikrad för att öppna den dagen.
- Under **Rader** väljer du om raderna ska vara **Personal** eller **Avdelningar**.
- Översta raden, **Öppna pass**, är passen som ingen har ännu. Med avdelningar som rader heter den **Utan avdelning**.
- Helgdagar står under datumet. Bredvid varje person står hur många timmar hen är planerad den veckan.

Längst ner står en summa per dag: **Tid**, **Kostnad**, **Försäljning** och **Personalkostnad** i procent av försäljningen. Läs mer i [Lönekostnad och varningar](/manual/planering/lonekostnad-och-varningar).

## Så här lägger du ett pass

1. Peka på rutan för personen och dagen och klicka på **+**. Eller markera rutan och tryck <kbd>Enter</kbd>.
2. Välj **Person**, eller **Öppet pass (ingen ännu)**.
3. Kontrollera **Datum** och fyll i **Starttid** och **Sluttid**.
4. Fyll i **Rast (minuter)**.
5. Välj **Avdelning** och, om ni har kompetenser, **Kompetens**.
6. Skriv en **Anteckning** om det behövs. Personen ser den på Min sida.
7. Klicka på **Spara**.

Passet läggs till som utkast. En sluttid före starttiden betyder att passet slutar nästa dag, till exempel 18:00–02:00. Ett pass kan vara högst 24 timmar, och rasten måste vara kortare än passet.

I dagvyn kan du också rita ett nytt pass direkt på en tom rad.

## Flytta och ändra

- **Flytta:** dra passet till en annan person eller dag.
- **Kopiera:** håll <kbd>Alt</kbd> när du drar.
- **Ändra tiden:** i dagvyn drar du i passets kanter.
- **Ändra något annat:** klicka på passet, så öppnas **Ändra pass**. Där ser du också passets uppskattade kostnad och eventuella varningar.

Ändrar du ett pass som redan är publicerat står det **Ändrat, inte publicerat** på det. Personen ser det gamla passet tills du publicerar igen, och får besked om ändringen först då.

### Ta bort eller ställa in ett pass

Öppna passet och klicka på **Ta bort** om det är ett utkast. Är passet publicerat heter knappen **Ställ in passet**: passet ställs in och personen får besked, om aviseringar är påslagna.

## Så ser du passets läge

- Ett utkast har streckad kant.
- En liten prick betyder att passet är ändrat sedan det publicerades.
- **!** betyder att passet har en varning, till exempel för kort vila. Antalet står efter om de är fler än en.
- En stjärna efter kostnaden, till exempel **1 234 kr \***, betyder att någon del av kostnaden saknas.
- **Kan**, **Kan inte** och **Gärna** i en ruta är det personen har sagt om dagen. Ligger ett pass på en tid personen har sagt inte passar får passet varningen **Har angett att den tiden inte passar.** Se [Tillgänglighet](/manual/planering/tillganglighet).

## Filter

Filtrera på **Avdelning**, **Kompetens** och **Lokal**. Kompetens visas när ni har kompetenser, och lokal när avdelningarna är kopplade till personalliggarens lokaler. Vecka, vy och filter står i adressen, så en länk öppnar samma vy.

## Kortkommandon

Klicka på **Kortkommandon** för att se dem i schemat.

| Tangent | Vad som händer |
|---|---|
| <kbd>←</kbd> <kbd>→</kbd> <kbd>↑</kbd> <kbd>↓</kbd> | Flytta mellan rutorna. |
| <kbd>Enter</kbd> | Öppna passet, eller lägg ett nytt pass i rutan. |
| <kbd>Delete</kbd> | Ta bort ett utkast. Ett publicerat pass ställs in. |
| <kbd>c</kbd> och <kbd>v</kbd> | Kopiera passet och klistra in det i en annan ruta. |

## Vanliga frågor

**En anställd syns inte i schemat.**
Schemat visar de anställda under **Lön → Anställda** som har en anställning under perioden. Kontrollera anställningens datum.

**Står det Tidigare anställd på ett pass?**
Passet tillhör någon som inte längre har en anställning under perioden. Flytta passet till någon annan eller ta bort det.

**Kan jag planera i mobilen?**
Nej, där kan du bara läsa schemat. Använd en surfplatta eller en dator.

## Läs också

- [Mallar och kopiera veckor](/manual/planering/mallar-och-kopiera-veckor)
- [Publicera schemat](/manual/planering/publicera-schemat)
- [Lönekostnad och varningar](/manual/planering/lonekostnad-och-varningar)
