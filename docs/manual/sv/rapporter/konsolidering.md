---
beskrivning: Koncernens resultat- och balansräkning för en period, med dotterföretag från förvärvet till avyttringen och varje körning i en körlogg. Ett internt underlag, inte en koncernredovisning.
ordning: 82
roller: [owner, admin, accountant, bookkeeper, auditor]
rutter: [group.consolidation]
sokord: [koncern, konsolidering, konsoliderad, koncernrapport, dotterföretag, moderföretag, holdingbolag, körlogg, kontomappning, koncernkonto, förvärv, avyttring]
---

# Konsolidering

På **Mina företag → Konsolidering** räknar du fram en koncerns resultaträkning och balansräkning för en period: moderföretagets och dotterföretagens egen bokföring, summerad konto för konto. Varje körning sparas i en **Körlogg**, så att du kan visa samma siffror igen och kontrollera att de fortfarande stämmer med bokföringen.

Koncernen är den som företagen har satt upp under **Koncernstruktur**: moderföretaget, dotterföretagen som har bekräftats som medlemmar och innehaven med förvärvs- och avyttringsdag.

![Konsolidering med en körning för helåret, resultat- och balansräkning och körloggen.](/bilder/manual/rapporter/konsolidering.webp)

> [!IMPORTANT]
> Sidan säger överst **Intern koncernrapport, inte en koncernredovisning enligt ÅRL**, och det gäller också när du skriver ut. Siffrorna är ett internt underlag. Konsolideringen bokför ingenting och ändrar ingenting i något av företagens bokföring. Mellanhavanden mellan företagen dras bara bort när de är godkända som [elimineringar](/manual/rapporter/elimineringar) för exakt samma period; annars kan samma belopp finnas med två gånger, till exempel en försäljning från ett företag i koncernen till ett annat.

## Vem som ser vad

- Får du se rapporter i **alla** företag i koncernen ser du körningarna, vad varje körning läste per företag och kontomappningen.
- Har du fått rollen att läsa koncernens sammanställning, och är medlem i moderföretaget, ser du bara de konsoliderade siffrorna och vilka företag som ingår. Inga enskilda företags siffror. En körning visas bara när minst två företag ingår, och alla företag ingår under hela perioden med sin balansräkning; annars säger sidan **Körningen visas inte**. En period där ett dotterföretag förvärvas eller avyttras visas alltså inte för dig. Jämför du en period före en försäljning av ett dotterföretag med en period efter den, är skillnaden det sålda företagets balansräkning, som i alla koncernrapporter före och efter en försäljning. Du kan fortfarande jämföra perioder med varandra, och ser du också moderföretagets egna rapporter kan du räkna ut vad dotterföretagen står för. **Kontrollera mot bokföringen** visar också om något av företagen har bokfört sedan körningen, men inte vilket.
- Alla andra ser inte koncernen alls. Saknar du rätt att se rapporter i ett av företagen visas den inte, och sidan säger **Du ser ingen koncern**.

## Så här kör du en konsolidering

1. Gå till **Mina företag → Konsolidering**.
2. Välj **Koncern** om du ser fler än en.
3. Välj **Från och med** och **Till och med**. Förvalt är moderföretagets räkenskapsår. Perioden måste ligga inom ett av moderföretagets räkenskapsår. Dotterföretagets räkenskapsår måste omfatta de dagar det räknas med, annars tas det inte med.
4. Välj **Kör konsolidering**.

Körningen sparas och visas direkt under rubriken **Konsoliderat** med perioden. Ett meddelande säger att konsolideringen är klar och sparad i körloggen.

## Vad som räknas med

- **Moderföretaget** räknas med för hela perioden.
- **Ett dotterföretags intäkter och kostnader** räknas från förvärvsdagen och fram till dagen före avyttringen, efter verifikationernas datum.
- **Ett dotterföretags balansräkning** tas med om det ingår i koncernen periodens sista dag.
- Bara **bokförda** verifikationer och ingående balanser räknas. Utkast räknas aldrig.
- Ett dotterföretag vars räkenskapsår inte omfattar de dagar det ska räknas med, eller som inte ingick i koncernen någon dag under perioden, tas inte med. Sidan säger hur många företag som inte ingår och, om du får se alla företag, vilka och varför.

Under **Balansräkning** finns raden **Årets resultat som ännu inte är bokfört mot eget kapital**, så att tillgångar och eget kapital och skulder går ihop, precis som i företagens egna balansrapporter. Skiljer sig årets resultat i balansräkningen från periodens resultat i resultaträkningen säger sidan med hur mycket. Det beror på resultat före periodens början, ett dotterföretags resultat före förvärvet eller resultatet i ett dotterföretag som avyttrats under perioden.

Under **Elimineringar** visar körningen hur mycket som är eliminerat per slag, till exempel **Försäljning och inköp**, efter återföringar. Den som bara ser koncernens summor ser beloppet bara för ett slag som gäller minst två par av företagen, annars antalet poster. Finns inga godkända elimineringar för perioden står det **Inga elimineringar är godkända för den här perioden. Siffrorna är företagens summor.** Får du se alla företag leder **Förslag och koncernjournal för perioden** till [Elimineringar](/manual/rapporter/elimineringar).

Om du får se alla företag visar **Vad körningen läste** varje företag med **Resultat för** (dagarna som räknades), **Balans** (**ingår** eller **ingår inte**), antalet **Bokförda verifikationer** och **Status**.

## Preliminär eller låst

En körning är **Preliminär** när minst ett av företagen inte har stängt räkenskapsåret eller låst perioden till och med periodens sista dag. Siffrorna kan då ändras om någon bokför mer. När alla företag har låst perioden visar körningen **Låsta perioder**.

## Körloggen

**Körlogg** listar de senaste körningarna med när de gjordes, perioden, vem som körde dem och en **Kontrollsumma**. Välj **Visa** för att se en äldre körning.

Varje körning sparar vad den läste: per företag antalet bokförda verifikationer och kontrollsumman för den senast bokförda verifikationen, de ingående balanserna, kontoplanen, [koncernmarkeringarna](/manual/bokforing/koncernmarkeringar) och kontomappningen, och kontrollsumman för varje post i koncernjournalen som drogs. Samma indata ger alltid samma siffror. Körningar kan inte ändras eller tas bort.

Välj **Kontrollera mot bokföringen** på en körning för att se om den fortfarande stämmer:

- **Indata är oförändrade och ger samma siffror som när körningen gjordes.** Ingenting har ändrats.
- **Bokföringen, markeringarna eller kontomappningen har ändrats sedan körningen.** Någon har bokfört, ändrat en markering eller en mappning, eller låst en period. Kör en ny konsolidering för aktuella siffror.
- **Körningen stämmer inte med sina kontrollsummor.** Det som är sparat i körloggen har ändrats utanför NONOX. Kontakta den som driver NONOX.

## Kontomappning

Som standard hamnar varje konto på samma nummer i koncernen. Använder ett av företagen ett eget konto för något som ska ligga på ett annat konto i koncernen, lägger du in en mappning:

1. Under **Kontomappning**: välj **Företag**.
2. Skriv **Företagets konto** och **Koncernkonto**. Båda kontona måste vara i samma kontoklass, alltså börja på samma siffra.
3. Välj **Spara mappning**.

Mappningen gäller från nästa körning och ändrar ingenting i företagets bokföring. **Ta bort** tar bort mappningen efter en bekräftelse, och kontot hamnar då på sitt eget nummer igen. Bara den som är ägare eller administratör i alla företag i koncernen kan ändra kontomappningen.

## När konsolideringen inte går att köra

- **Moderföretaget har inget räkenskapsår som omfattar hela perioden.** Välj en period inom ett av moderföretagets räkenskapsår.
- **Ett företag i koncernen har en annan redovisningsvaluta än moderföretaget.** Konsolidering i flera valutor finns inte.
- **Företagen i koncernen har tillsammans registrerat mer än 100 % av rösterna i samma företag.** Rätta innehaven under Koncernstruktur först.
- **Den här körningen kan inte visas för den som bara ser koncernens summor, eftersom den har färre än två företag eller något företag har lämnats utanför eller bara ingår en del av perioden.** Du har bara rollen att läsa sammanställningen. Välj en period där alla företag ingår från första till sista dagen.

## Bra att veta

- Beloppen summeras konto för konto över företagen, som är olika juridiska personer. En skattefordran i ett företag kan därför minska en skatteskuld i ett annat, och ett negativt banksaldo i ett företag kan minska ett annat företags bankmedel. I företagens egna redovisningar kvittas sådana belopp inte.
- Ett koncernkonto får namnet från moderföretagets kontoplan, annars BAS-namnet. Finns kontot inte i någon av dem visas bara numret.

- Konsolideringen läser varje företags bokföring för sig och skriver aldrig något i den. Den läser bara de företag som är bekräftade medlemmar i koncernen.
- Det som gäller en riktig koncernredovisning, som förvärvsanalys, goodwill, minoritetsandelar och eliminering av internvinster, ingår inte. Elimineringarna av mellanhavanden är bara de som någon har godkänt. Kontrollera med din redovisningskonsult eller revisor om koncernen behöver en koncernredovisning.
