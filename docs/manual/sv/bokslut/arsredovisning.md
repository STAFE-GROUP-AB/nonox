---
beskrivning: Upprätta årsredovisningen enligt K2 för aktiebolag och ekonomiska föreningar, och för ett mindre aktiebolag vars räkenskapsår följer K3 (beta). Siffrorna kommer från bokföringen; du skriver texterna och laddar ner en PDF.
ordning: 90
roller: [owner, admin, accountant]
rutter: [accounting.annualReport, accounting.annualReport.show, accounting.annualReport.pdf, accounting.annualReport.ixbrl]
sokord: [årsredovisning, k2, k3, bfnar 2012:1, egen formulering, rapport över förändringar i eget kapital, förvaltningsberättelse, resultaträkning, balansräkning, noter, flerårsöversikt, underskrifter, bolagsverket, ixbrl, digital inlämning, utdelning, fritt eget kapital, försiktighetsregeln]
---

# Årsredovisning enligt K2

Aktiebolag och ekonomiska föreningar ska upprätta en årsredovisning. I NONOX tas den fram enligt K2-regelverket direkt ur bokföringen; följer räkenskapsåret K3 läser du [Om räkenskapsåret följer K3 (beta)](#om-rakenskapsaret-foljer-k3-beta). Du skriver förvaltningsberättelsens texter, föreslår utdelning och anger vem som skriver under – siffrorna, uppställningarna och noterna gör NONOX.

Sidan finns under **Bokföring → Årsredovisning**.

![Årsredovisningen: texterna och underskrifterna till vänster, förhandsvisningen till höger.](/bilder/manual/bokslut/arsredovisning.webp)

## Om räkenskapsåret följer K3 (beta)

Följer räkenskapsåret K3 (BFNAR 2012:1) tar NONOX fram årsredovisningen för ett **mindre aktiebolag** enligt K3. Den är en beta: låt en redovisningskonsult granska den innan den skrivs under. Sidan visar då **Beta** överst, och under texterna två rutor till: **Uppgifter för K3** och **Noter**. Regelverket väljer du under [Bokslut → Regelverk](/manual/bokslut/regelverk). Är det företagets första år med K3 bokför du först [övergången från K2](/manual/bokslut/overgang-till-k3).

![Årsredovisning för ett aktiebolag som följer K3: texterna, Uppgifter för K3 och förhandsvisningen.](/bilder/manual/bokslut/arsredovisning-k3.webp)

Årsredovisningen enligt K3 innehåller, i den här ordningen:

- **Förvaltningsberättelse** med verksamheten, väsentliga händelser, ägare med mer än tio procent, flerårsöversikten med regelverket för varje år och förslaget till resultatdisposition.
- **Resultaträkning** och **Balansräkning** i K3:s uppställning, med föregående år. Är det första året med K3 står föregående år som det redovisades enligt K2; det räknas inte om, och en not om jämförbarhet säger det. Under **Avsättningar för skatter** står **varav uppskjuten skatt**.
- **Rapport över förändringar i eget kapital**, med övergången till K3 på en egen rad.
- **Kassaflödesanalys**, om du har valt en under Regelverk.
- **Noter**, med notnumret vid raderna i räkningarna, **Underskrifter** och **Fastställelseintyg**.

### Det här hindrar årsredovisningen

K3 tillämpas helt eller inte alls. Finns något som NONOX inte stöder visas rutan **Det här hindrar årsredovisningen** med regeln bakom varje skäl, till exempel **(S1-R6)**, och ingen årsredovisning tas fram. I stället för förhandsvisningen står **Ingen förhandsvisning ännu**. Vanliga skäl:

- Företaget är ett **större företag**, eller storleken går inte att avgöra. Årsredovisningen för ett större företag kommer i ett senare steg.
- Företaget är en **ekonomisk förening**. Årsredovisningen enligt K3 görs bara för aktiebolag än så länge.
- En princip under Regelverk saknas, eller är en som NONOX inte stöder: värdering till verkligt värde (kapitel 12), aktiveringsmodellen för utvecklingsutgifter, finansiella leasingavtal i balansräkningen eller låneutgifter i anskaffningsvärdet.
- Bokföringen har saldo på konton för aktiverade utvecklingsutgifter eller fond för utvecklingsutgifter, fast kostnadsföringsmodellen är vald.
- Det är första året med K3 och övergången är inte bokförd.
- Förändringarna i eget kapital eller kassaflödesanalysen går inte att ställa upp, eller balansräkningen går inte ihop.
- Kvittningsrätt för uppskjuten skatt är bekräftad. Årsredovisningen visar ännu inte uppskjuten skattefordran och skatteskuld kvittade.

### Frågor och Uppgifter för K3

Det bokföringen inte kan svara på frågar NONOX efter i rutan **Frågor att besvara**. Så länge det finns frågor är förhandsvisningen ett utkast, och PDF:en går inte att ladda ner.

1. Under **Uppgifter för K3**, svara **Ja** eller **Nej** på frågorna, till exempel om företaget har åtaganden för koncern- eller intresseföretag.
2. Ange **Medelantal anställda, heltidstjänster**.
3. Under **Ägare med mer än tio procent**, klicka på **Lägg till ägare** för varje ägare med mer än tio procent av andelarna eller rösterna. För en fysisk person anges bara namnet, aldrig personnummer. Finns ingen sådan ägare, kryssa i rutan utan att lägga till någon rad.
4. Fyll i de texter som gäller, till exempel **Skulder som förfaller efter mer än fem år, kr**. En not utan uppgifter tas inte med.
5. Klicka på **Spara uppgifterna för K3**.

När alla frågor är besvarade står **Alla frågor är besvarade** och **Ladda ner PDF** visas.

### Skriv en not med egna ord

Noterna tas fram ur bokföringen och dina svar. Under **Noter** kan du skriva en not med egna ord, till exempel när en fråga gäller formuleringen, som en tillgångs nyttjandeperiod.

![Listan över noter med en not med egen formulering.](/bilder/manual/bokslut/arsredovisning-k3-noter.webp)

1. Klicka på **Skriv med egna ord** vid noten.
2. Skriv texten, med en tom rad mellan styckena, och ett **Skäl**. Skälet syns bara på sidan, inte i årsredovisningen.
3. Klicka på **Spara texten**.

Noten märks **Egen formulering**, och i förhandsvisningen står det vid noten. I PDF:en står bara din text. Siffrorna i noten och om noten tas med alls styrs fortfarande av bokföringen. Ändras bokföringen efteråt märks noten **Inaktuell**: läs igenom texten och spara den igen. Med **Använd NONOX text** går du tillbaka till den text NONOX tar fram. Frågor om bokföringen, till exempel en övergång som inte är bokförd, går inte att besvara med en egen text.

iXBRL-filen görs bara för K2. Bokföringen, bokslutet och [inkomstdeklarationen](/manual/bokslut/inkomstdeklaration) fungerar som vanligt.

### Större eller mindre företag

Vilka regler som gäller enligt K3 beror på om företaget är ett större eller ett mindre företag enligt årsredovisningslagen. Ett företag är större om **samma två** av tre gränsvärden har överskridits under vart och ett av de två räkenskapsår som närmast föregår årsredovisningens år: medelantalet anställda mer än 50, balansomslutningen mer än 40 miljoner kronor och nettoomsättningen mer än 80 miljoner kronor. Året som årsredovisningen gäller räknas inte. Har företaget till exempel överskridit gränsvärdena för anställda och nettoomsättning det ena året men för balansomslutning och nettoomsättning det andra, är det bara nettoomsättningen som är överskriden båda åren, och företaget är mindre.

NONOX förbereder den bedömningen inför årsredovisningen enligt K3 och hämtar siffrorna för de två föregående åren så här:

- **Nettoomsättning och balansomslutning** läses ur bokföringen för de räkenskapsår som finns i NONOX. Är ett år kortare eller längre än tolv månader räknas nettoomsättningen om till tolv månader. Balansomslutningen räknas inte om.
- **Medelantal anställda** hämtas från årsredovisningarna för de två föregående åren. Antalet räknas i heltidstjänster. Ange det exakta medelantalet, avrunda inte – NONOX avrundar själv till ett helt tal innan det jämförs med gränsvärdet, som i Bokföringsnämndens exempel där 9,4 blir 9.
- För räkenskapsår före företagets första år i NONOX behöver NONOX samma tre siffror från de tidigare årsredovisningarna och frågar efter dem.

Ett nystartat företag är alltid mindre de två första åren, oavsett företagsform. Det gäller i NONOX först när någon har bekräftat när företagets första räkenskapsår började – att siffror saknas räcker inte, och en bekräftelse som inte stämmer med år som redan finns i NONOX godtas inte. Saknas siffror för något av åren, eller är företaget en ideell förening eller en stiftelse (som ska räkna med bidrag, gåvor och medlemsavgifter i nettoomsättningen), gissar NONOX inte. Då avgör en människa storleken.

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
För ett mindre aktiebolag tar NONOX fram årsredovisningen enligt K3 som en beta. Står det **Det här hindrar årsredovisningen** visar rutan vad som behöver ändras. Se [Om räkenskapsåret följer K3 (beta)](#om-rakenskapsaret-foljer-k3-beta).

**En siffra ser fel ut.**
Kontrollera kontot i saldobalansen. Ligger beloppet på ett konto som hamnar på en annan rad än du väntat, prata med din redovisningskonsult. Rätta bokföringen och spara om – PDF:en byggs alltid från det som är bokfört.

## Läs också

- [Skatt och årets resultat](/manual/bokslut/bokslutsverifikationer)
- [Resultatdisposition](/manual/bokslut/resultatdisposition)
- [Inkomstdeklaration 2 och SRU-filer](/manual/bokslut/inkomstdeklaration)
