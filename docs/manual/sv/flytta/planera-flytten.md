---
beskrivning: Välj en flyttdag som gör att varje deklaration hamnar helt i ett av systemen, och ta ut allt ur det gamla programmet innan du börjar.
ordning: 10
roller: [owner, admin, accountant]
sokord: [flyttdag, brytdag, cut-over, exportera, SIE-export, planera, checklista, förbereda flytt]
---

# Planera flytten

En bra flytt börjar med två saker: en flyttdag och en mapp med allt från det gamla programmet. Med dem på plats är resten mest att läsa in filer.

## Välj flyttdag

Flyttdagen är dagen då du slutar bokföra i det gamla programmet och börjar i NONOX.

- **Flytta direkt efter att en momsdeklaration är inlämnad.** Då hamnar varje momsperiod helt i ett av systemen. En momsperiod som är halvt i två system är det som oftast ställer till det.
- **Har ni anställda:** flytta efter att arbetsgivardeklarationen för den senaste lönen är inlämnad, och innan nästa lön räknas.
- **Mitt i året går bra.** Hela årets bokföring följer med, så rapporter och bokslut blir rätt. Se [Byta mitt i året](/manual/flytta/byta-mitt-i-aret).
- **Vid årsskiftet är enklast.** Då stänger du det sista året i det gamla programmet och börjar ett nytt i NONOX.

> [!IMPORTANT]
> Bokför ingenting mer i det gamla programmet efter att du har exporterat. Ett år som redan är inläst i NONOX kan inte läsas in en gång till med samma verifikationer, så det som bokförs i det gamla programmet efteråt kommer inte med.

## Ta ut allt ur det gamla programmet

Det här är det viktigaste steget. Det du exporterar nu är ditt eget, och det är din räkenskapsinformation som ska sparas i sju år.

1. **Bokföringen som SIE4-fil, en fil för varje räkenskapsår.** Ta med alla år som finns, även de som är stängda. Står det bara "SIE" i programmet, välj den som innehåller verifikationer (typ 4, ibland kallad 4E eller 4I).
2. **Alla underlag:** kvitton, leverantörsfakturor och avtal som hör till verifikationerna. Spara dem gärna sorterade per år.
3. **Alla kundfakturor som PDF**, och en lista över obetalda kund- och leverantörsfakturor på flyttdagen.
4. **Kund-, leverantörs- och artikelregistret** som fil, om det går.
5. **Om ni har anställda:** personallistan, årets summor per anställd (lön, skatt, arbetsgivaravgifter), semestersaldona och lönebeskeden som PDF.
6. **Kontoutdrag** för bankkontot fram till flyttdagen, så att du kan stämma av banken.

Kommer du från Fortnox kan NONOX hämta bokföringen, registren och underlagen direkt; se [Från Fortnox](/manual/flytta/fran-fortnox). Spara ändå en egen export.

> [!TIP]
> Spara exporten på minst två ställen, till exempel på datorn och i en molntjänst. När exporten ligger hos dig är du inte längre beroende av det gamla abonnemanget för att uppfylla kravet på att spara räkenskapsinformationen.

## Förbered NONOX

1. [Skapa företaget](/manual/kom-igang/skapa-ett-foretag) om det inte finns. Välj samma **Räkenskapsåret börjar i** och samma **Bokföringsmetod** som i det gamla programmet.
2. Kontrollera under **Bokföring → Räkenskapsår** att det pågående räkenskapsåret har samma första och sista dag som i det gamla programmet. Skiljer de sig, till exempel för att företaget har ett förlängt eller förkortat första år, ändra datumen med **Ändra datum**. Gör det innan du läser in något: datumen går bara att ändra så länge det är företagets enda räkenskapsår och det är öppet. Se [Räkenskapsår](/manual/bokforing/rakenskapsar).
3. Fyll i adress och bankgiro under [Företagets uppgifter](/manual/installningar/foretagets-uppgifter).
4. Slå på modulen **Lön** om ni har anställda; se [Moduler](/manual/installningar/moduler).

## Läs också

- [Från Fortnox](/manual/flytta/fran-fortnox)
- [Från ett annat program](/manual/flytta/fran-andra-program)
- [Byta mitt i året](/manual/flytta/byta-mitt-i-aret)
