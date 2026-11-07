---
beskrivning: Upprätta årsredovisningen enligt K2 för aktiebolag och ekonomiska föreningar. Siffrorna kommer från bokföringen; du skriver texterna och laddar ner en PDF.
ordning: 90
roller: [owner, admin, accountant]
rutter: [accounting.annualReport, accounting.annualReport.show, accounting.annualReport.pdf, accounting.annualReport.ixbrl]
sokord: [årsredovisning, k2, k3, förvaltningsberättelse, resultaträkning, balansräkning, noter, flerårsöversikt, underskrifter, bolagsverket, ixbrl, digital inlämning, utdelning, fritt eget kapital, försiktighetsregeln]
---

# Årsredovisning enligt K2

Aktiebolag och ekonomiska föreningar ska upprätta en årsredovisning. I NONOX tas den fram enligt K2-regelverket direkt ur bokföringen. Du skriver förvaltningsberättelsens texter, föreslår utdelning och anger vem som skriver under – siffrorna, uppställningarna och noterna gör NONOX.

Sidan finns under **Bokföring → Årsredovisning**.

![Årsredovisningen: texterna och underskrifterna till vänster, förhandsvisningen till höger.](/bilder/manual/bokslut/arsredovisning.webp)

## Om företaget använder K3

NONOX tar bara fram årsredovisningar enligt K2. Har företaget valt **K3 – huvudregelverket** under **Inställningar för företaget** visar sidan rubriken **Årsredovisning enligt K3 finns inte ännu** i stället för formuläret, och förhandsvisningen, PDF:en och iXBRL-filen går inte att ta fram. En årsredovisning enligt K2 skulle inte stämma med det regelverk företaget har valt.

Bokföringen, bokslutet och [inkomstdeklarationen](/manual/bokslut/inkomstdeklaration) fungerar som vanligt. Så här gör du årsredovisningen:

1. Gör bokslutet i NONOX som vanligt.
2. Ladda ner årets SIE-fil under **Bokföring → Räkenskapsår** (**Ladda ner SIE-fil**).
3. Läs in filen i ett program som stöder K3, eller lämna den till din redovisningskonsult.

Har företaget valt K3 av misstag, och uppfyller det villkoren för K2, kan en ägare eller administratör ändra regelverket under **Inställningar för företaget**. Läs villkoren noga – sedan 2026 får vissa mindre företag inte längre använda K2. Se [Företagets uppgifter](/manual/installningar/foretagets-uppgifter).

### Större eller mindre företag

Vilka regler som gäller enligt K3 beror på om företaget är ett större eller ett mindre företag enligt årsredovisningslagen. Ett företag är större om det har överskridit mer än ett av tre gränsvärden under vart och ett av de två räkenskapsår som närmast föregår årsredovisningens år: medelantalet anställda mer än 50, balansomslutningen mer än 40 miljoner kronor och nettoomsättningen mer än 80 miljoner kronor. Året som årsredovisningen gäller räknas inte.

NONOX förbereder den bedömningen inför årsredovisningen enligt K3 och hämtar siffrorna så här:

- **Nettoomsättning och balansomslutning** läses ur bokföringen för de räkenskapsår som finns i NONOX. Är ett år kortare eller längre än tolv månader räknas nettoomsättningen om till tolv månader. Balansomslutningen räknas inte om.
- **Medelantal anställda** hämtas från fältet **Medelantal anställda** i årets årsredovisning.
- För räkenskapsår före företagets första år i NONOX behövs samma tre siffror från de tidigare årsredovisningarna. De läggs in för hand, och varje ändring loggas.

Saknas siffror för något av de två åren, eller har företaget överskridit två gränsvärden båda åren men inte samma två, gissar NONOX inte. Då avgör en människa storleken.

## Innan du börjar

Gör bokslutet först, så att årets skatt och resultat är bokförda. Läs [Checklistan för bokslutet](/manual/bokslut/checklistan). Siffrorna läses om ur bokföringen varje gång årsredovisningen visas, så den är alltid i takt med det som är bokfört.

## Så här gör du

1. Gå till **Bokföring → Årsredovisning** och välj räkenskapsår.
2. Under **Texter och underskrifter**, fyll i:
   - **Allmänt om verksamheten** – vad företaget gör och var det har sitt säte.
   - **Väsentliga händelser under året** – lämna tomt om inget särskilt har hänt.
   - **Kommentar till nettoomsättningen** – visas när omsättningen har ändrats med mer än 30 procent mellan två år i flerårsöversikten. K2 kräver då en kommentar, och du kan inte spara utan den. Förklara kort varför, till exempel en ny stor kund eller ett avtal som upphört.
   - **Föreslagen utdelning, kr** – högst det fria egna kapitalet, som står under fältet. Det är balanserade vinstmedel plus årets resultat. Försöker du föreslå mer får du ett felmeddelande, eftersom aktiebolagslagen (17 kap. 3 §) inte tillåter det. Utdelningen ska också vara försvarlig med hänsyn till bolagets behov av eget kapital och likviditet (försiktighetsregeln). Det bedömer styrelsen – NONOX kan inte räkna fram det.
   - **Medelantal anställda**.
   - **Ort** och **Datum för underskrift**.
   - **Underskrifter** – en per rad: namn, roll. Till exempel "Anna Andersson, Styrelseledamot".
3. Under **Noter som du fyller i**, fyll i det som gäller företaget. Lämna resten tomt – en not utan uppgifter tas inte med.
   - **Ställda säkerheter, kr** – till exempel en företagsinteckning som banken har som säkerhet för ett lån. För inteckningar skriver du inteckningarnas belopp, för övriga säkerheter tillgångens bokförda värde. Har företaget lån hos en bank påminner sidan om det här.
   - **Eventualförpliktelser, kr** – summan av till exempel borgen och garantier som inte står i balansräkningen, och vad de gäller.
   - **Åtaganden för koncern- och intresseföretag** – säkerheter, garantier och andra åtaganden för till exempel ett dotterbolag.
   - **Väsentliga händelser efter räkenskapsårets slut** – vad som har hänt och vilken ekonomisk effekt det får. Kryssa i **Den ekonomiska effekten går inte att uppskatta** om det inte går att säga.
4. Efter stämman, fyll i **Fastställelseintyg**: **Fastställelsedag**, **Den som intygar** och **Roll** (styrelseledamot eller verkställande direktör). **Stämmans beslut om vinsten eller förlusten** kan du lämna tomt om du har bokat [resultatdispositionen](/manual/bokslut/resultatdisposition) – då skrivs beslutet ut därifrån.
5. Klicka på **Spara**.
6. Granska **Förhandsvisning av årsredovisningen**.
7. Klicka på **Ladda ner PDF**.
8. För ett aktiebolag kan du också klicka på **Ladda ner som iXBRL (Beta)**. Läs mer under iXBRL-filen nedan.

## Det här innehåller årsredovisningen

- **Förvaltningsberättelse** med dina texter, en flerårsöversikt (nettoomsättning, resultat efter finansiella poster och soliditet, för upp till fyra år), förändringar i eget kapital och förslag till resultatdisposition.
- **Förändringar i eget kapital** är en tabell med en kolumn per post (aktiekapital, reservfond, balanserat resultat och årets resultat). Den visar beloppen vid årets ingång, den utdelning och det belopp som årsstämman beslutade att balansera i ny räkning, årets resultat, övriga förändringar som en nyemission, och beloppen vid årets utgång. Utdelningen och dispositionen hämtas från [resultatdispositionen](/manual/bokslut/resultatdisposition).
- **Resultaträkning** uppställd efter kostnadsslag, med föregående år.
- **Balansräkning** med föregående år.
- **Noter** om redovisningsprinciper, medelantal anställda och anläggningstillgångar, och de noter du fyller i: ställda säkerheter, eventualförpliktelser, åtaganden för koncern- och intresseföretag och väsentliga händelser efter räkenskapsårets slut.
- **Underskrifter**.
- **Fastställelseintyg** sist, för kopian som skickas till Bolagsverket. Årsredovisningslagen (8 kap. 3 §) kräver att en styrelseledamot eller VD intygar att resultat- och balansräkningen har fastställts, när det skedde och vad stämman beslutade om vinsten eller förlusten. Saknas något står det streckade rader att fylla i för hand.

Raderna bildas av BAS-kontointervall och följer K2:s uppställning. Checkräkningskredit (konto 2480), förskott från kunder (2420) och leverantörsskulder (2440) står på egna rader, liksom förbättringsutgifter på annans fastighet (1120) och pågående nyanläggningar (1180, 1280). Indelningen är densamma som i inkomstdeklarationens räkenskapsschema (INK2R). I resultaträkningen är en förändring av lager av egna produkter och pågående arbeten (4940–4959, 4970–4979) en rörelseintäkt. Förändring av lager av handelsvaror (4960–4969) och inköpskonton som du markerat som handelsvaror i [kontoplanen](/manual/bokforing/kontoplan) står under Handelsvaror. Övriga inköp står under Råvaror och förnödenheter. Årets resultat i balansräkningen är resultaträkningens resultat, även om det inte är bokat på 2099 än. Finns föregående år inte i NONOX används årets ingående balanser som jämförelse i balansräkningen.

Alla belopp står i hela kronor. Varje rad avrundas till närmaste krona (en halv krona avrundas uppåt i belopp), och varje summa är summan av de avrundade raderna, så att summorna stämmer med det som står ovanför. Blir tillgångarna och eget kapital och skulder olika efter avrundningen, oftast med en krona, läggs skillnaden på raden **Övriga skulder** under kortfristiga skulder. I tabellen över förändringar i eget kapital läggs en sådan skillnad oftast på raden Balanseras i ny räkning.

## iXBRL-filen (beta)

Bolagsverket tar emot årsredovisningar digitalt i formatet iXBRL: en webbsida där varje belopp och text är märkt så att en dator kan läsa dem. **Ladda ner som iXBRL (Beta)** ger dig samma årsredovisning som PDF:en i det formatet. Beloppen kommer från samma uträkning som PDF:en, så de två visar samma siffror.

- **NONOX lämnar inte in filen åt dig än.** Det kräver ett avtal mellan NONOX och Bolagsverket, och det arbetet pågår. Filen är till för att du ska kunna se den och, när det blir möjligt, lämna in den.
- **Fastställelseintyget skrivs under hos Bolagsverket**, med e-legitimation, inte i NONOX. Filen innehåller intyget först när **Fastställelsedag** är ifylld, med Bolagsverkets egen formulering. Namn, roll och datum för underskriften fylls i hos Bolagsverket.
- **Bara aktiebolag** än så länge. För en ekonomisk förening visas inte knappen.
- **Alla rader går inte att märka upp ännu.** Märkningen följer den svenska taxonomin för årsredovisningar i K2-aktiebolag från taxonomier.se (version 2024-09-12). Finns det något i årsredovisningen som taxonomin inte har en motsvarighet till, skapas ingen fil. Du får i stället ett meddelande som säger vad det gäller, till exempel:
  - **Maskiner och inventarier** – NONOX visar en rad, taxonomin har två (maskiner och andra tekniska anläggningar, och inventarier, verktyg och installationer).
  - **Reservfond och annat bundet eget kapital**, och **skulder till koncern-, intresse- och gemensamt styrda företag** – taxonomin delar dem i flera poster.
  - **Övriga förändringar** i tabellen över förändringar i eget kapital – taxonomin vill veta om det var en nyemission, ett aktieägartillskott eller något annat.
  - Noterna om **immateriella anläggningstillgångar**, **pågående nyanläggningar** och **åtaganden för koncern- och intresseföretag**.
  - En tom text under **Allmänt om verksamheten**.

> [!NOTE]
> iXBRL-filen är en beta. Bolagsverkets tekniska anvisningar för filen har inte gått att läsa i sin helhet, så tecken, avrundning och vissa uppgifter kan behöva ändras innan filen kan lämnas in. Lämna tills vidare in årsredovisningen på det sätt Bolagsverket anvisar.

## Det här gör du utanför NONOX

- **Revisionsberättelse** – har företaget revisor läggs den till utanför systemet.
- **Inlämning till Bolagsverket** – NONOX lämnar inte in årsredovisningen åt dig än. Du kan ladda ner den som iXBRL-fil (se ovan), men tills vidare lämnar du in den på det sätt Bolagsverket anvisar.

> [!NOTE]
> Radindelningen, kontointervallen, standardtexterna, antalet år i flerårsöversikten och skattesatsen i soliditetsberäkningen är ännu inte juridiskt kontrollerade i NONOX. Låt din redovisningskonsult eller revisor granska årsredovisningen innan den skrivs under.

## Vanliga frågor

**Sidan säger "Inte för den här företagsformen".**
Årsredovisningen görs här för aktiebolag och ekonomiska föreningar. En enskild firma upprättar ingen årsredovisning utan ett förenklat årsbokslut.

**Vi använder K3.**
Årsredovisningen i NONOX följer K2. Sidan visar då **Årsredovisning enligt K3 finns inte ännu**. Se [Om företaget använder K3](#om-foretaget-anvander-k3).

**En siffra ser fel ut.**
Kontrollera kontot i saldobalansen. Ligger beloppet på ett konto som hamnar på en annan rad än du väntat, prata med din redovisningskonsult. Rätta bokföringen och spara om – PDF:en byggs alltid från det som är bokfört.

## Läs också

- [Skatt och årets resultat](/manual/bokslut/bokslutsverifikationer)
- [Resultatdisposition](/manual/bokslut/resultatdisposition)
- [Inkomstdeklaration 2 och SRU-filer](/manual/bokslut/inkomstdeklaration)
