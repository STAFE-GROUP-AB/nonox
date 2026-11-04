---
beskrivning: Räkenskapsåren i NONOX – ett kortare eller längre första år, nästa år, balanser som förs över vid årsskiftet och SIE-fil per år.
ordning: 120
rutter: [accounting.fiscalYears]
sokord: [räkenskapsår, automatiskt nytt år, bokföringsår, brutet räkenskapsår, första räkenskapsåret, förlängt räkenskapsår, förkortat räkenskapsår, kalenderår, enskild firma, årsskifte, nytt år, ingående balans, inaktuella ingående balanser, uppdatera ingående balanser]
---

# Räkenskapsår

Ett räkenskapsår är den period som bokföringen och bokslutet gäller, oftast tolv månader. Varje verifikation hör till det räkenskapsår som dess datum ligger i, och numreringen börjar om varje år. Du ser och hanterar åren under **Bokföring → Räkenskapsår**.

![Räkenskapsåren, med SIE-fil, arkivpaket och överföring av balanser.](/bilder/manual/bokforing/rakenskapsar.webp)

## Vad du ser

Under **Dina räkenskapsår** finns ett år per rad, det senaste först. Året som pågår är märkt **Pågår nu**. Bredvid datumen står om året är **Öppet** eller **Stängt**. I ett stängt år går det inte att bokföra något.

Beroende på din roll finns också länkarna **Ladda ner SIE-fil**, **Skapa arkivpaket**, **För över balanser till …** och **Ändra datum** på raderna.

## Ditt första räkenskapsår

Det första året skapas när företaget skapas, utifrån den månad räkenskapsåret börjar. Började företaget mitt i ett år kan det första året vara kortare eller längre än tolv månader.

### Så här ändrar du det första årets datum

1. Gå till **Bokföring → Räkenskapsår**.
2. Välj **Ändra datum** på året.
3. Fyll i **Första dag** och **Sista dag**.
4. Välj **Spara**.

Reglerna i NONOX:

- Året ska börja den första dagen i en månad och sluta den sista dagen i en månad.
- Det får vara högst 18 månader långt. Det finns ingen nedre gräns: ett första år kan vara en enda månad.
- En enskild firma ska ha kalenderår. Dess första år ska därför sluta den 31 december, men kan börja i vilken månad som helst.
- Det går bara så länge företaget har ett enda räkenskapsår och det är öppet, eller nästa år har skapats automatiskt och ännu är tomt. **Ändra datum** syns bara då.

> [!IMPORTANT]
> Ändra datumen innan du börjar bokföra, så att inga verifikationer hamnar utanför året. Reglerna följer bokföringslagen 3 kap.: när bokföringsskyldigheten börjar eller räkenskapsåret läggs om får året vara kortare än tolv månader eller förlängas till högst arton. En jurist har ännu inte granskat hur NONOX tillämpar reglerna.

## Nästa räkenskapsår skapas automatiskt

NONOX skapar nästa räkenskapsår av sig själv 30 dagar innan det senaste året slutar. Det nya året börjar dagen efter att det senaste slutar, är tolv månader och börjar i den månad som ditt räkenskapsår börjar. Det gäller även när ditt första år var kortare eller längre än tolv månader. Åren kan därför aldrig överlappa eller lämna ett glapp, och du kan fakturera och bokföra på årets första dag utan att göra något.

Ägaren, administratörer och redovisningskonsulter får en notis: "Räkenskapsåret … är skapat. Det förra året stängs fortfarande i bokslutet." Samma sak står under **Att göra** på Översikt tills det nya året börjar.

Skulle du spara ett utkast, bokföra, fakturera eller registrera en betalning med ett datum i nästa år innan det har skapats, skapas året direkt, så länge datumet ligger högst 30 dagar fram i tiden. Det gäller också för den som inte själv får hantera räkenskapsår, till exempel en bokförare. Tas utkastet bort finns året kvar. Ett datum längre fram, eller i ett år längre bort, går inte att bokföra förrän året finns.

Det här görs **inte** automatiskt:

- Det gamla året stängs inte. Bokslutet gör du som tidigare under **Bokföring → Bokslut**.
- Balanserna förs inte över. Tills du gör det har det nya året inga ingående balanser, och rapporterna för det nya året visar bara årets egna verifikationer.
- Räkenskapsåret läggs aldrig om. Ska ett år bli kortare eller längre än tolv månader, eller börja i en annan månad, är det ditt beslut, och för de flesta företag krävs Skatteverkets tillstånd (bokföringslagen 3 kap. 6 §). Har du ändrat den månad räkenskapsåret börjar i företagsinställningarna, eller har en enskild firma ännu brutet år, skapar NONOX inget år automatiskt.

Är det ditt första räkenskapsår kan du fortfarande ändra dess datum, även om nästa år redan har skapats automatiskt, så länge inget har bokförts i det nya året. Det automatiskt skapade året tas då bort, och nästa år skapas igen efter de nya datumen när det är dags. Finns ett utkast, en verifikation eller ingående balanser i det nya året, eller har någon skapat det för hand, går det inte.

> [!NOTE]
> I behandlingshistoriken står det att året skapades automatiskt och om det var morgonkörningen eller en bokföring som gjorde det. En jurist har ännu inte granskat att året får skapas i förväg.

## Så här skapar du nästa räkenskapsår själv

1. Gå till **Bokföring → Räkenskapsår**.
2. Välj **Skapa nästa räkenskapsår**.

Det nya året börjar dagen efter att det senaste slutar och är tolv månader. Finns redan ett år som inte har börjat säger NONOX det, i stället för att skapa året efter det.

En enskild firma som har brutet räkenskapsår sedan tidigare får en varning på sidan. De år som redan finns ändras inte. Nästa år som skapas slutar den 31 december, så att firman går över till kalenderår. Året efter det är ett helt kalenderår.

## Vid årsskiftet: för över balanserna

När ett år är slut ska tillgångarnas, skuldernas och det egna kapitalets saldon bli [ingående balanser](/manual/ordlista/ordlista#ingaende-balans) i nästa år.

1. Välj **För över balanser till …** på det gamla året.
2. Läs texten och välj **Ja, för över**.

Saldona på balanskontona (klass 1 och 2) förs över. Ett resultat som inte har bokförts mot eget kapital förs till Årets resultat, så att de ingående balanserna alltid går ihop. Finns nästa år inte skapas det. Nästa års ingående balanser ersätts varje gång du för över, så länge nästa år är öppet och bokföringen inte är låst från dess första dag.

## När de ingående balanserna inte längre stämmer

Bokför du något i det gamla året efter att balanserna förts över – en sen leverantörsfaktura, en rättelse eller en inläst SIE-fil – stämmer nästa års ingående balanser inte längre med det gamla årets utgående balanser. Enligt årsredovisningslagen (2 kap. 4 §) ska de stämma.

NONOX säger till på tre ställen:

- Överst under **Bokföring → Räkenskapsår** och i **Bokföring → Rapporter** för det nya året: "De ingående balanserna för 2027 stämmer inte längre med de utgående balanserna för 2026 (2 konton skiljer)."
- Under **Att göra** på Översikt: **Uppdatera ingående balanser för …**
- I bokslutets checklista för det nya året: **Ingående balanser stämmer med förra året**.

![Varningen om ingående balanser som inte längre stämmer, med skillnaden per konto.](/bilder/manual/bokforing/inaktuella-ingaende-balanser.webp)

### Så här uppdaterar du dem

1. Välj **Visa skillnaden per konto** för att se vilka konton som skiljer och med hur mycket.
2. Välj **Uppdatera ingående balanser**.
3. Läs texten och välj **Ja, uppdatera**.

De ingående balanserna räknas om på samma sätt som när du för över balanserna. Finns det fler år efter, till exempel 2028 som fått sina balanser från 2027, uppdateras de också, i tur och ordning. Inga verifikationer ändras: ingående balanser är inga verifikationer. Ändringen sparas i behandlingshistoriken med varje kontos belopp före och efter.

NONOX uppdaterar aldrig de ingående balanserna av sig själv, eftersom det ändrar siffrorna i rapporterna för det nya året. Det är du som bestämmer när.

Det går inte att uppdatera när det nya året är stängt, eller när bokföringen är låst från det nya årets första dag. Är det gamla året fortfarande öppet förs balanserna ändå över på nytt när du stänger det under **Bokföring → Bokslut**.

Det första året i NONOX jämförs inte med något: dess ingående balanser kommer från en SIE-fil eller från ett annat program, och det finns inget år före att jämföra med.

> [!NOTE]
> Ingående balanser har inga kostnadsställen eller projekt, så skillnaden visas bara per konto. En jurist har ännu inte granskat att uppdateringen vägras när det nya årets första dag är låst.

Hur hela bokslutet går till, och hur ett år stängs, står i [Bokslut och årsredovisning](/manual/bokslut).

## Ladda ner SIE-fil för ett år

Välj **Ladda ner SIE-fil** på året. Filen innehåller hela årets bokföring: kontoplan, ingående och utgående balanser och alla bokförda verifikationer. Se [SIE-filer](/manual/bokforing/sie).

## Arkivpaket

**Skapa arkivpaket** gör en ZIP-fil med allt som räknas som räkenskapsinformation för året, i format som går att läsa utan NONOX. Se [Arkivpaket](/manual/bokslut/arkivpaket).

## Vem får göra vad

Alla som arbetar i bokföringen ser räkenskapsåren. Ägare, administratörer och redovisningskonsulter kan skapa år, ändra det första årets datum och föra över balanser. SIE-filen och arkivpaketet kan också revisorer ladda ner.

## Vanliga frågor

**Mitt företag har brutet räkenskapsår, till exempel maj–april.**
Det fungerar för aktiebolag, ekonomiska föreningar och stiftelser, och för handels- och kommanditbolag där ingen fysisk person beskattas för bolagets inkomst. Åren följer den månad räkenskapsåret börjar, och etiketten blir till exempel "2026/2027". En enskild firma ska ha kalenderår.

**Jag läser in en SIE-fil med ett kort räkenskapsår.**
Det går bra. NONOX läser in år av alla längder upp till 18 månader. Är företaget en enskild firma och året i filen slutar en annan dag än den 31 december läses året in som det är, med en varning i granskningen.

**Kan jag ta bort ett räkenskapsår?**
Nej. Ett år med bokföring ska sparas, och NONOX har ingen funktion för att ta bort ett år.

**Kan jag öppna ett stängt år igen?**
Nej. Ett år stängs under **Bokföring → Bokslut**, och det går inte att ångra.

## Läs också

- [Lås en period](/manual/bokforing/las-en-period)
- [SIE-filer](/manual/bokforing/sie)
- [Bokslut och årsredovisning](/manual/bokslut)
- [Arkivpaket](/manual/bokslut/arkivpaket)
