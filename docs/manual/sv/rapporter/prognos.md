---
beskrivning: Gör en rullande prognos från en brytmånad – utfallet hittills och resten av året med budgeten, ett snitt eller fjolåret – och se de kommande tolv månaderna över årsskiftet.
ordning: 62
roller: [owner, admin, accountant, bookkeeper, auditor]
rutter: [accounting.budgets.rolling]
sokord: [prognos, rullande prognos, rullande 12 månader, forecast, brytmånad, helårsprognos, snitt, fjolåret, omprognos, likviditetsplan]
---

# Rullande prognos

En prognos svarar på frågan "hur ser året ut nu, med det vi vet i dag?". Den tar bokföringens utfall för de månader som har gått och räknar fram resten av året. Där budgeten är planen du gjorde i början av året, är prognosen planen uppdaterad med verkligheten.

Prognosen är bara en plan bredvid bokföringen, precis som [budgeten](/manual/rapporter/budget). Den bokförs aldrig och ändrar inget i verifikationerna eller rapporterna.

![En prognos med utfall till och med brytmånaden och prognos för resten av året.](/bilder/manual/rapporter/prognos.webp)

## Så här gör du en prognos

1. Gå till **Bokföring → Budget** och välj **Ny prognos**.
2. Välj **Räkenskapsår** och **Utfall till och med**, brytmånaden. Bara månader som har tagit slut går att välja, från månaden före räkenskapsåret (då är hela året prognos) till årets sista månad. Har företaget låst perioder föreslår NONOX den sista låsta månaden, som är färdigbokförd; annars den senaste avslutade månaden. Ett **Namn** föreslås efter brytmånaden, till exempel "Prognos oktober 2026". Är brytmånaden inte låst säger formuläret att den kan sakna verifikationer som bokförs i efterhand.
3. Välj **Metod för intäkter** och **Metod för kostnader** (se nedan). Från början räknas intäkterna med **Budgeten** och kostnaderna med **Snittet av senaste månaderna**. Har räkenskapsåret ingen budget som används i rapporterna räknas också intäkterna med snittet.
4. Ändra om du vill **Månader i snittet** (1–12, normalt 3) och **Förändring mot i fjol i procent**, som används av metoden **Samma månad i fjol**. Under fälten står hur många månader snittet faktiskt räknas på, och om det saknas ett föregående räkenskapsår, så att **Samma månad i fjol** ger 0.
5. Välj **Skapa prognos**. Prognosen räknas fram och öppnas direkt.

Den första prognosen för ett räkenskapsår blir den som [Rullande 12 månader](#rullande-12-manader) läser. Har du flera väljer du **Använd i Rullande 12 månader** på den som ska gälla.

## De tre metoderna

Varje månad efter brytmånaden räknas med en av tre enkla regler, som du kan räkna om för hand:

| Metod | Så räknas månaden |
|---|---|
| **Budgeten** | Samma månad i den budget som används i rapporterna för räkenskapsåret. En rad som inte finns i budgeten får 0. |
| **Snittet av senaste månaderna** | Radens utfall de senaste månaderna till och med brytmånaden, delat med antalet månader och avrundat till hela kronor. Månader i föregående räkenskapsår räknas med, så snittet fungerar också i början av året. Finns det färre månader i bokföringen räknas snittet på dem som finns. |
| **Samma månad i fjol** | Radens utfall samma månad ett år tidigare, ändrat med procentsatsen och avrundat till hela kronor. |

Snittet och fjolåret räknas utan bokslutets poster: verifikationerna som bokslutet gör för räkenskapsåret, som årets skatt, uppskjuten skatt och bokslutsdispositioner, och deras återföringar första dagen nästa år. De hör till årsskiftet och upprepas inte varje månad. Månatliga avskrivningar och periodiseringar räknas med. I utfallsmånaderna visas allt som är bokfört. Årets skatt och bokslutsdispositioner kommer alltså inte med i prognosmånaderna: skriv in dem för hand i årets sista månad. Skatt och bokslutsdispositioner som bokförts med en vanlig verifikation eller lästs in med SIE räknas däremot med i snittet och fjolåret; skriv över de raderna för hand om de ger fel bild.

Metoden för intäkter gäller konton i klass 3 och de finansiella intäkterna 8000–8399. Metoden för kostnader gäller alla andra resultatkonton. En enskild rad kan få en egen metod i kolumnen **Metod** i rutnätet. **Standard** betyder att raden följer metoden för intäkter eller kostnader.

## Rutnätet

Prognosen visas som budgeten: ett konto per rad och en månad per kolumn, med intäkter och kostnader som positiva belopp och **Resultat** längst ner.

- Kolumnerna märkta **Utfall** under månaden är grå. De kommer från bokföringen och kan inte ändras.
- Kolumnerna märkta **Prognos** visar i grått vad radens metod ger. Skriv ett eget belopp i en ruta för att ändra månaden för hand. Rutan markeras, och beloppet gäller i stället för metoden också när prognosen räknas om. Töm rutan så gäller metoden igen. **Följ metoden** tömmer alla ändrade rutor på raden.
- Ändra brytmånad, metoder eller antal månader under **Så räknas prognosen**.
- Välj **Spara och räkna om** (eller <kbd>Ctrl</kbd>+<kbd>Enter</kbd>). NONOX läser utfallet från bokföringen igen, så en verifikation som har bokförts sent för en tidigare månad kommer med, och räknar om varje månad efter brytmånaden.

Utfall på ett kostnadsställe eller projekt hamnar på budgetens rad för det kostnadsstället eller projektet. Finns ingen sådan rad räknas det på kontots rad utan kostnadsställe och projekt. Varje belopp räknas på en rad, aldrig två.

Under **Lägg till rad** kan du lägga till ett konto som varken har utfall eller budget, till exempel en ny kostnad du vet kommer. Skriv beloppen för månaderna efter brytmånaden och spara. En rad utan belopp försvinner när prognosen räknas om.

## Nästa månad: flytta brytmånaden eller gör en ny prognos

En rullande prognos uppdateras när ännu en månad är bokförd. Du har två sätt:

- Flytta brytmånaden: välj en senare månad under **Utfall till och med** i prognosen och välj **Spara och räkna om**. Månader du hade ändrat för hand som nu är utfall försvinner, eftersom utfallet gäller.
- Gör en ny prognos: välj **Bokföring → Budget**, sedan **Ny prognos**, och välj den gamla under **Utgå från en tidigare prognos**. Radernas egna metoder och månaderna du har ändrat för hand efter den nya brytmånaden följer med. Den gamla prognosen finns kvar, så du kan jämföra hur bilden har ändrats.

## Rullande 12 månader

**Rullande 12 månader** visar de kommande tolv månaderna, också över årsskiftet. Gå till **Bokföring → Budget** och välj **Rullande 12 månader**.

![De kommande tolv månaderna, över årsskiftet.](/bilder/manual/rapporter/rullande-12-manader.webp)

- Varje månad läses ur den prognos som används för sitt räkenskapsår, annars ur den budget som används i rapporterna. Under **Varifrån siffrorna kommer** står vilken prognos eller budget varje del kommer ifrån, och för en prognos till och med vilken månad den har utfall.
- Ligger brytmånaden mer än en månad efter bokföringen står det "Prognosen är äldre än bokföringen. Räkna om den eller gör en ny."
- Sidan börjar med månaden efter brytmånaden i årets prognos, eller med innevarande månad när prognosen är äldre än bokföringen. Bläddra en månad bakåt eller framåt med pilarna.
- Intäkter visas positiva och kostnader negativa, som i [resultatrapporten](/manual/rapporter/resultatrapport). Månader till och med prognosens brytmånad är utfall och visas i grått.
- En månad utan prognos eller budget är tom, och sidan säger vilka månader det gäller. Lägg upp nästa räkenskapsår och gör en budget för det, så fylls de i.

## Lås en prognos

Välj **Lås prognosen** när den är beslutad, till exempel inför ett styrelsemöte. En låst prognos kan inte ändras eller räknas om, men du kan göra en ny prognos från den. En prognos tas bort under **Bokföring → Budget** som en budget, och en låst prognos för ett stängt räkenskapsår sparas.

## Vem får göra vad

Ägare, administratörer och redovisningskonsulter gör, räknar om, låser och tar bort prognoser. Bokförare och revisorer kan se dem och Rullande 12 månader. Attestanter ser inte prognoserna.

## Bra att veta

- Prognosen gäller resultatkonton, 3000–8998. Det är en resultatprognos, inte en likviditetsprognos. Likviditeten ser du under [Statistik och prognos](/manual/kom-igang/oversikten) på översikten.
- Belopp lagras i öre. Utfallsmånaderna är bokföringens belopp exakt; metoderna avrundar till hela kronor.
- Prognosen är inget AI. Den räknas med de tre reglerna ovan och ingenting annat.
- **Ladda ner som CSV** ger prognosen som en fil i samma format som budgeten.

## Läs också

- [Budget](/manual/rapporter/budget)
- [Budget mot utfall](/manual/rapporter/budget-mot-utfall)
- [Kostnadsställen och projekt](/manual/bokforing/kostnadsstallen-och-projekt)
