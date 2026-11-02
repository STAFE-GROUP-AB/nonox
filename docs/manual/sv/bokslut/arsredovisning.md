---
beskrivning: Upprätta årsredovisningen enligt K2 för aktiebolag och ekonomiska föreningar. Siffrorna kommer från bokföringen; du skriver texterna och laddar ner en PDF.
ordning: 90
roller: [owner, admin, accountant]
rutter: [accounting.annualReport, accounting.annualReport.show, accounting.annualReport.pdf]
sokord: [årsredovisning, k2, förvaltningsberättelse, resultaträkning, balansräkning, noter, flerårsöversikt, underskrifter, bolagsverket, ixbrl, digital inlämning, utdelning, fritt eget kapital, försiktighetsregeln]
---

# Årsredovisning enligt K2

Aktiebolag och ekonomiska föreningar ska upprätta en årsredovisning. I NONOX tas den fram enligt K2-regelverket direkt ur bokföringen. Du skriver förvaltningsberättelsens texter, föreslår utdelning och anger vem som skriver under – siffrorna, uppställningarna och noterna gör NONOX.

Sidan finns under **Bokföring → Årsredovisning**.

![Årsredovisningen: texterna och underskrifterna till vänster, förhandsvisningen till höger.](/bilder/manual/bokslut/arsredovisning.webp)

## Innan du börjar

Gör bokslutet först, så att årets skatt och resultat är bokförda. Läs [Checklistan för bokslutet](/manual/bokslut/checklistan). Siffrorna läses om ur bokföringen varje gång årsredovisningen visas, så den är alltid i takt med det som är bokfört.

## Så här gör du

1. Gå till **Bokföring → Årsredovisning** och välj räkenskapsår.
2. Under **Texter och underskrifter**, fyll i:
   - **Allmänt om verksamheten** – vad företaget gör och var det har sitt säte.
   - **Väsentliga händelser under året** – lämna tomt om inget särskilt har hänt.
   - **Föreslagen utdelning, kr** – högst det fria egna kapitalet, som står under fältet. Det är balanserade vinstmedel plus årets resultat. Försöker du föreslå mer får du ett felmeddelande, eftersom aktiebolagslagen (17 kap. 3 §) inte tillåter det. Utdelningen ska också vara försvarlig med hänsyn till bolagets behov av eget kapital och likviditet (försiktighetsregeln). Det bedömer styrelsen – NONOX kan inte räkna fram det.
   - **Medelantal anställda**.
   - **Ort** och **Datum för underskrift**.
   - **Underskrifter** – en per rad: namn, roll. Till exempel "Anna Andersson, Styrelseledamot".
3. Efter stämman, fyll i **Fastställelseintyg**: **Fastställelsedag**, **Den som intygar** och **Roll** (styrelseledamot eller verkställande direktör). **Stämmans beslut om vinsten eller förlusten** kan du lämna tomt om du har bokat [resultatdispositionen](/manual/bokslut/resultatdisposition) – då skrivs beslutet ut därifrån.
4. Klicka på **Spara**.
5. Granska **Förhandsvisning av årsredovisningen**.
6. Klicka på **Ladda ner PDF**.

## Det här innehåller årsredovisningen

- **Förvaltningsberättelse** med dina texter, en flerårsöversikt (nettoomsättning, resultat efter finansiella poster och soliditet, för upp till fyra år), förändringar i eget kapital och förslag till resultatdisposition.
- **Förändringar i eget kapital** är en tabell med en kolumn per post (aktiekapital, reservfond, balanserat resultat och årets resultat). Den visar beloppen vid årets ingång, den utdelning och det belopp som årsstämman beslutade att balansera i ny räkning, årets resultat, övriga förändringar som en nyemission, och beloppen vid årets utgång. Utdelningen och dispositionen hämtas från [resultatdispositionen](/manual/bokslut/resultatdisposition).
- **Resultaträkning** uppställd efter kostnadsslag, med föregående år.
- **Balansräkning** med föregående år.
- **Noter** om redovisningsprinciper, medelantal anställda och anläggningstillgångar.
- **Underskrifter**.
- **Fastställelseintyg** sist, för kopian som skickas till Bolagsverket. Årsredovisningslagen (8 kap. 3 §) kräver att en styrelseledamot eller VD intygar att resultat- och balansräkningen har fastställts, när det skedde och vad stämman beslutade om vinsten eller förlusten. Saknas något står det streckade rader att fylla i för hand.

Raderna bildas av BAS-kontointervall och följer K2:s uppställning. Checkräkningskredit (konto 2480), förskott från kunder (2420) och leverantörsskulder (2440) står på egna rader, liksom förbättringsutgifter på annans fastighet (1120) och pågående nyanläggningar (1180, 1280). Indelningen är densamma som i inkomstdeklarationens räkenskapsschema (INK2R). I resultaträkningen är en förändring av lager av egna produkter och pågående arbeten (4940–4959, 4970–4979) en rörelseintäkt. Förändring av lager av handelsvaror (4960–4969) och inköpskonton som du markerat som handelsvaror i [kontoplanen](/manual/bokforing/kontoplan) står under Handelsvaror. Övriga inköp står under Råvaror och förnödenheter. Årets resultat i balansräkningen är resultaträkningens resultat, även om det inte är bokat på 2099 än. Finns föregående år inte i NONOX används årets ingående balanser som jämförelse i balansräkningen.

## Det här gör du utanför NONOX

- **Revisionsberättelse** – har företaget revisor läggs den till utanför systemet.
- **Inlämning till Bolagsverket** – digital inlämning (iXBRL) är på väg. Tills vidare lämnar du årsredovisningen på det sätt Bolagsverket anvisar.

> [!NOTE]
> Radindelningen, kontointervallen, standardtexterna, antalet år i flerårsöversikten och skattesatsen i soliditetsberäkningen är ännu inte juridiskt kontrollerade i NONOX. Låt din redovisningskonsult eller revisor granska årsredovisningen innan den skrivs under.

## Vanliga frågor

**Sidan säger "Inte för den här företagsformen".**
Årsredovisningen görs här för aktiebolag och ekonomiska föreningar. En enskild firma upprättar ingen årsredovisning utan ett förenklat årsbokslut.

**Vi använder K3.**
Årsredovisningen i NONOX följer K2. Ett K3-företag behöver göra årsredovisningen på annat sätt.

**En siffra ser fel ut.**
Kontrollera kontot i saldobalansen. Ligger beloppet på ett konto som hamnar på en annan rad än du väntat, prata med din redovisningskonsult. Rätta bokföringen och spara om – PDF:en byggs alltid från det som är bokfört.

## Läs också

- [Skatt och årets resultat](/manual/bokslut/bokslutsverifikationer)
- [Resultatdisposition](/manual/bokslut/resultatdisposition)
- [Inkomstdeklaration 2 och SRU-filer](/manual/bokslut/inkomstdeklaration)
