---
beskrivning: Gör en budget per konto och månad, med kostnadsställe och projekt om du vill. Börja tomt, som i fjol eller med en kopia, och läs in från kalkylark.
ordning: 60
roller: [owner, admin, accountant, bookkeeper, auditor]
rutter: [accounting.budgets, accounting.budgets.show]
sokord: [budget, budgetera, plan, prognos, månadsbudget, årsbudget, kalkylark, excel, csv, fjolåret, budgetversion]
---

# Budget

En budget är din plan för året: vad du räknar med att sälja och vad det får kosta, månad för månad. NONOX jämför sedan planen med bokföringen i [Budget mot utfall](/manual/rapporter/budget-mot-utfall). Budgeten är bara en plan bredvid bokföringen. Den bokförs aldrig och ändrar inget i verifikationerna eller rapporterna.

Du gör budgeten per resultatkonto (3000–8998) och månad i räkenskapsåret. Vill du följa upp en restaurang, en avdelning eller ett uppdrag för sig kan en rad också ha ett [kostnadsställe eller projekt](/manual/bokforing/kostnadsstallen-och-projekt).

![Budgeten för ett konsultföretag, konto för konto och månad för månad.](/bilder/manual/rapporter/budget.webp)

## Så här gör du en budget

1. Gå till **Bokföring → Budget** och välj **Ny budget**.
2. Skriv ett **Namn**, till exempel "Budget 2027", och välj **Räkenskapsår**.
3. Under **Börja med** väljer du hur budgeten ska fyllas från början:
   - **Tom budget** – du fyller i allt själv.
   - **Som i fjol** – förra räkenskapsårets utfall per konto och månad, avrundat till hela kronor. Kräver att det finns ett år före.
   - **Kopia av en budget** – alla rader från en annan budget, också från ett annat år eller en låst budget. Välj den under **Budget att kopiera**.
4. Vid **Som i fjol** och kopia kan du skriva en **Förändring i procent**, till exempel `5` för fem procent mer eller `-2,5` för lite mindre. Beloppen räknas om och avrundas till hela kronor.
5. Välj **Skapa budget**. Budgeten öppnas direkt.

Den första budgeten för ett räkenskapsår blir den som rapporterna jämför med. Har du flera, till exempel en försiktig och en optimistisk, väljer du **Använd i rapporterna** på den som ska gälla.

## Fyll i budgeten

Budgeten är ett rutnät med ett konto per rad och en månad per kolumn. Varje rad är märkt **Intäkt** eller **Kostnad**, och du skriver beloppet som ett positivt tal i kronor:

- **Intäkt:** konton i klass 3 och de finansiella intäkterna 8000–8399, till exempel ränteintäkter på 8310.
- **Kostnad:** alla andra resultatkonton, till exempel räntekostnader på 8410. Väntar du dig en intäkt på ett sådant konto skriver du den med minus.

Raden **Resultat** längst ner lägger ihop intäkterna och drar av kostnaderna medan du skriver. Konto 8999 Årets resultat budgeteras inte, eftersom det är själva resultatet.

1. Under **Lägg till rad** väljer du ett **Konto**, och om du vill ett **Kostnadsställe** och ett **Projekt**. Välj **Lägg till rad**.
2. Skriv beloppen månad för månad. Eller skriv årets belopp i kolumnen **Summa** och tryck <kbd>Enter</kbd>, så fördelas det jämnt över månaderna i hela kronor. Kronor som blir över hamnar i de första månaderna.
3. Välj **Spara**. Ingenting sparas förrän du gör det, och sidan visar "Du har ändringar som inte är sparade" så länge något väntar.

Tangentbordet fungerar som i verifikationen:

| Tangent | Gör |
|---|---|
| <kbd>Enter</kbd> eller <kbd>↓</kbd> | Samma månad på nästa rad |
| <kbd>Skift</kbd>+<kbd>Enter</kbd> eller <kbd>↑</kbd> | Samma månad på raden ovanför |
| <kbd>Tab</kbd> | Nästa månad |
| <kbd>Ctrl</kbd>+<kbd>Enter</kbd> | Spara |

## Som i fjol och förändring i procent

**Som i fjol** på en rad fyller raden med förra årets utfall för samma månad. Under **Som i fjol** bredvid rutnätet kan du skriva en **Förändring i procent** som gäller både knappen på en rad och **Fyll alla rader som i fjol**. En rad utan kostnadsställe eller projekt får hela kontots utfall. En rad med kostnadsställe eller projekt får bara utfallet på det.

## Klistra in och läs in från kalkylark

- **Klistra in ett block:** kopiera rutor i ett kalkylark och klistra in dem i en ruta i budgeten. Rutorna fylls åt höger och nedåt från den rutan.
- **Läs in en fil eller rader med konton:** under **Läs in från kalkylark eller CSV** väljer du en **CSV-fil** eller klistrar in rader under **Eller klistra in rader**, och väljer **Läs in**. Första raden kan vara rubriker: `Konto`, `Kostnadsställe`, `Projekt` och en kolumn per månad som `2027-01`. Utan rubriker är första kolumnen kontot och resten årets månader i ordning. Raderna hamnar i rutnätet, så spara efteråt.
- **Ladda ner som CSV** ger budgeten som en fil i samma format, som Excel öppnar direkt. Ändra i Excel och läs in filen igen.

Är något fel i filen läses ingenting in. Sidan listar då varje rad som behöver rättas, till exempel ett konto som inte finns i kontoplanen eller ett belopp som inte går att läsa.

## Lås en budget

När budgeten är beslutad kan du välja **Lås budgeten**. En låst budget kan inte ändras: både NONOX och databasen vägrar ändringar i den. Den kan bara användas i rapporterna, kopieras eller tas bort: vill du ändra något gör du en ny budget som en **Kopia av en budget**.

## Ta bort en budget

Välj **Ta bort** i listan under **Bokföring → Budget**. Budgeten och alla dess rader försvinner. Bokföringen påverkas inte, men budgeten går inte att få tillbaka. Att den togs bort, av vem och när, står kvar i [behandlingshistoriken](/manual/bokforing/behandlingshistorik).

En låst budget för ett räkenskapsår som är stängt går inte att ta bort. Den kan ha legat till grund för bedömningar i årsredovisningen, till exempel en nedskrivningsprövning, och ska då sparas.

## Vem får göra vad

Ägare, administratörer och redovisningskonsulter gör, ändrar, låser och tar bort budgetar. Bokförare och revisorer kan se dem och rapporten Budget mot utfall. Attestanter ser inte budgeten.

## Bra att veta

- Budgeten gäller resultatkonton, 3000–8998. Balans- och likviditetsbudget finns inte ännu.
- Belopp lagras i öre, så det du skriver blir exakt det som sparas.
- Budgeten följer inte med i SIE-filen.

## Läs också

- [Rullande prognos](/manual/rapporter/prognos)
- [Budget mot utfall](/manual/rapporter/budget-mot-utfall)
- [Kostnadsställen och projekt](/manual/bokforing/kostnadsstallen-och-projekt)
- [Resultatrapporten](/manual/rapporter/resultatrapport)
