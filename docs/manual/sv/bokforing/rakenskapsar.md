---
beskrivning: Räkenskapsåren i NONOX – ett kortare eller längre första år, nästa år, balanser som förs över vid årsskiftet och SIE-fil per år.
ordning: 120
rutter: [accounting.fiscalYears]
sokord: [räkenskapsår, bokföringsår, brutet räkenskapsår, första räkenskapsåret, förlängt räkenskapsår, årsskifte, nytt år, ingående balans]
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
- Det får vara mellan 6 och 18 månader långt.
- Det går bara så länge företaget har ett enda räkenskapsår och det är öppet. **Ändra datum** syns bara då.

> [!IMPORTANT]
> Ändra datumen innan du börjar bokföra, så att inga verifikationer hamnar utanför året. Den nedre gränsen på 6 månader är ännu inte juridiskt kontrollerad av NONOX – bokföringslagen sätter gränser för hur långt ett första räkenskapsår får vara. Fråga din redovisningskonsult om ditt första år blir kortare än ett halvår.

## Så här skapar du nästa räkenskapsår

1. Gå till **Bokföring → Räkenskapsår**.
2. Välj **Skapa nästa räkenskapsår**.

Det nya året börjar dagen efter att det senaste slutar och är tolv månader. Åren kan därför aldrig överlappa eller lämna ett glapp. Du behöver ett nytt år innan du kan bokföra något med ett datum efter det gamla årets slut.

## Vid årsskiftet: för över balanserna

När ett år är slut ska tillgångarnas, skuldernas och det egna kapitalets saldon bli [ingående balanser](/manual/ordlista/ordlista#ingaende-balans) i nästa år.

1. Välj **För över balanser till …** på det gamla året.
2. Läs texten och välj **Ja, för över**.

Saldona på balanskontona (klass 1 och 2) förs över. Ett resultat som inte har bokförts mot eget kapital förs till Årets resultat, så att de ingående balanserna alltid går ihop. Finns nästa år inte skapas det. Har du bokfört något i det gamla året efteråt för du över igen: nästa års ingående balanser ersätts då, så länge nästa år är öppet.

Hur hela bokslutet går till, och hur ett år stängs, står i [Bokslut och årsredovisning](/manual/bokslut).

## Ladda ner SIE-fil för ett år

Välj **Ladda ner SIE-fil** på året. Filen innehåller hela årets bokföring: kontoplan, ingående och utgående balanser och alla bokförda verifikationer. Se [SIE-filer](/manual/bokforing/sie).

## Arkivpaket

**Skapa arkivpaket** gör en ZIP-fil med allt som räknas som räkenskapsinformation för året, i format som går att läsa utan NONOX. Se [Arkivpaket](/manual/bokslut/arkivpaket).

## Vem får göra vad

Alla som arbetar i bokföringen ser räkenskapsåren. Ägare, administratörer och redovisningskonsulter kan skapa år, ändra det första årets datum och föra över balanser. SIE-filen och arkivpaketet kan också revisorer ladda ner.

## Vanliga frågor

**Mitt företag har brutet räkenskapsår, till exempel maj–april.**
Det fungerar. Åren följer den månad räkenskapsåret börjar, och etiketten blir till exempel "2026/2027".

**Kan jag ta bort ett räkenskapsår?**
Nej. Ett år med bokföring ska sparas, och NONOX har ingen funktion för att ta bort ett år.

**Kan jag öppna ett stängt år igen?**
Nej. Ett år stängs under **Bokföring → Bokslut**, och det går inte att ångra.

## Läs också

- [Lås en period](/manual/bokforing/las-en-period)
- [SIE-filer](/manual/bokforing/sie)
- [Bokslut och årsredovisning](/manual/bokslut)
- [Arkivpaket](/manual/bokslut/arkivpaket)
