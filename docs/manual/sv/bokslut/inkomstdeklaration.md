---
beskrivning: Inkomstdeklarationen ur bokföringen – INK2 för aktiebolag, INK4 för handelsbolag och NE-bilagan för enskild firma när årets satser är kontrollerade, med SRU-filer.
ordning: 100
roller: [owner, admin, accountant]
rutter: [accounting.incomeTax, accounting.incomeTax.sru]
sokord: [inkomstdeklaration, ink2, ink2r, ink2s, ne-bilaga, ne, enskild firma, ink4, ink4r, ink4s, ink4du, handelsbolag, kommanditbolag, delägare, räntefördelning, expansionsfond, egenavgifter, schablonavdrag, sru, sru-fil, deklaration aktiebolag, skattemässigt resultat, filöverföring, skattemässiga justeringar, schablonintäkt, periodiseringsfond, statslåneränta, särskild löneskatt, pensionskostnader, tjänstepension]
---

# Inkomstdeklaration och SRU-filer

Sidan **Bokföring → Inkomstdeklaration** visar den deklaration som gäller företagets bolagsform:

- **Aktiebolag och ekonomiska föreningar**: Inkomstdeklaration 2 (INK2) med räkenskapsschemat (INK2R) och skattemässiga justeringar (INK2S). Se [Aktiebolag: Inkomstdeklaration 2](#aktiebolag-inkomstdeklaration-2).
- **Enskild firma**: NE-bilagan, som du lämnar med din egen Inkomstdeklaration 1. Se [Enskild firma: NE-bilagan](#enskild-firma-ne-bilagan).
- **Handelsbolag och kommanditbolag**: Inkomstdeklaration 4 (INK4R, INK4S och en INK4DU per delägare). Se [Handelsbolag: Inkomstdeklaration 4](#handelsbolag-inkomstdeklaration-4).

Ägarna i ett aktiebolag lämnar ofta blankett K10 med sin egen deklaration; underlaget finns på en egen sida, se [K10-underlag för ägarna](/manual/bokslut/k10).

NONOX fyller i deklarationen fält för fält ur bokföringen, räknar fram det skattemässiga resultatet med det du anger och gör SRU-filerna till Skatteverket. Ingenting bokförs. En ideell förening eller stiftelse deklarerar direkt i Skatteverkets e-tjänst.

## Aktiebolag: Inkomstdeklaration 2

![Inkomstdeklarationen med skattemässiga justeringar och deklarationen fält för fält.](/bilder/manual/bokslut/inkomstdeklaration.webp)

### Så här gör du

1. Gör bokslutet först. Läs [Checklistan för bokslutet](/manual/bokslut/checklistan).
2. Gå till **Bokföring → Inkomstdeklaration** och välj räkenskapsår.
3. Kontrollera **Bokfört resultat** och **Bokförd skatt**.
4. Under **Skattemässiga justeringar**, fyll i de belopp som gäller företaget. Belopp i hela kronor, utan minustecken:
   - **Andra bokförda kostnader som inte ska dras av**
   - **Nedskrivning av finansiella tillgångar som inte ska dras av**
   - **Utdelning som inte ska tas upp**
   - **Andra bokförda intäkter som inte ska tas upp**
   - **Beräknad schablonintäkt på periodiseringsfonder** – har företaget periodiseringsfonder står ett förslag under fältet. Lämna fältet tomt för att använda förslaget. Skriv ett annat belopp om förslaget inte stämmer, eller 0 om fältet inte ska fyllas i.
   - **Andra ej bokförda intäkter som ska tas upp**
   - **Andra ej bokförda kostnader som ska dras av**
   - **Outnyttjat underskott från föregående år**
5. Har företaget tjänstepension? Kontrollera **Underlag för särskild löneskatt på pensionskostnader** och **Pensionskostnader (som ingår i p. 3.8)**. NONOX föreslår beloppen från kontona 7400–7499. Ändra underlaget om något inte ska ingå, till exempel en gruppsjukförsäkring. Blir underlaget negativt fyller du i **Negativt underlag för särskild löneskatt på pensionskostnader** i stället – aldrig båda.
6. Svara på **Har en konsult hjälpt till med årsredovisningen?** och **Har årsredovisningen reviderats?**
7. Klicka på **Spara**. Sidan visar **Överskott av näringsverksamhet** eller **Underskott av näringsverksamhet**.
8. Granska **Deklarationen, fält för fält**.
9. Klicka på **Ladda ner SRU-filer**.
10. Packa upp ZIP-filen och lämna båda filerna, INFO.SRU och BLANKETTER.SRU, i Skatteverkets e-tjänst Filöverföring.
11. Kontrollera uppgifterna i e-tjänsten innan du skriver under.

Skatten på årets resultat läggs tillbaka av sig själv – den är inte avdragsgill.

### Det här gör NONOX

- **INK2R** fylls fält för fält från BAS-kontointervall, i hela kronor. Årets resultat är resultaträkningens, även om det inte är bokat på 2099.
- **INK2S** börjar med det bokförda resultatet, lägger tillbaka den bokförda skatten och räknar in dina justeringar. Överskott eller underskott förs till INK2 punkt 1.1 eller 1.2.
- **Schablonintäkt på periodiseringsfonder** (punkt 4.6 a) föreslås ur bokföringen: periodiseringsfonderna vid årets början (konto 2110–2149) gånger statslåneräntan vid utgången av november året innan. Räntan är lägst 0,5 %. Är räkenskapsåret kortare eller längre än tolv månader räknas beloppet om i samma mån. Exempel: fonder på 100 000 kr och räntan 2,55 % ger 2 550 kr för ett år som slutar 2026. Beloppet ökar överskottet och följer med i SRU-filen. Finns inte räntan för året i NONOX ser du en varning, och då fyller du i beloppet själv.
- **Särskild löneskatt på pensionskostnader**: underlaget på sidan 1 (punkt 1.4, eller 1.5 om det är negativt) och pensionskostnaderna i punkt 4.21 föreslås från kontona 7400–7499. Den särskilda löneskatten själv räknas inte med. Fälten ändrar inte överskottet; Skatteverket tar ut skatten på underlaget. Står det pensionskostnader i 4.21 men inget underlag visar sidan en varning.
- **Justeringarna** sparas för året, så att du kan komma tillbaka och ändra dem.
- Varje nedladdning av SRU-filerna skrivs i behandlingshistoriken.

### Det här fyller du i själv i e-tjänsten

Övriga punkter på INK2S – till exempel delägarrätter, andelar i handelsbolag och fastigheter – fyller NONOX inte i. Gäller de företaget lägger du in dem i Skatteverkets e-tjänst. Fråga din redovisningskonsult.

## Enskild firma: NE-bilagan

![NE-bilagan för en enskild firma: bokfört resultat, schablonavdraget för egenavgifter och överskottet, med det du fyller i och bilagan fält för fält.](/bilder/manual/bokslut/inkomstdeklaration-ne.webp)

> **NE-bilagan är spärrad tills årets satser är kontrollerade.** SRU-filen och räkningen av räntefördelning, periodiseringsfond, expansionsfond och schablonavdrag öppnas först när en människa har kontrollerat satserna för inkomståret. Till dess visar sidan att procentsatserna inte är kontrollerade, och du fyller i bilagan i Skatteverkets e-tjänst. Inkomstdeklaration 4 för handelsbolag berörs inte av spärren.

Har du enskild firma deklarerar du verksamheten på NE-bilagan i din egen Inkomstdeklaration 1. NONOX fyller i balansräkningen (B1–B16) och resultaträkningen (R1–R11) ur bokföringen och räknar fram överskottet eller underskottet (R47 eller R48) med det du anger.

### Så här gör du

1. Gör bokslutet först. Läs [Checklistan för bokslutet](/manual/bokslut/checklistan).
2. Gå till **Bokföring → Inkomstdeklaration** och välj året.
3. Under **Om dig och verksamheten**, välj vilket århundrade du är född på vid **Du är född på**. Det behövs för personnumret i filen och för vilken procentsats schablonavdraget för egenavgifter har. Skriv **Verksamhetens art** och kryssa i det som gäller, till exempel **Det här är det första året med näringsverksamheten**. Har du bokfört en bil- eller bostadsförmån, skriv kontot i **Konton där bil- eller bostadsförmån är bokförd (R3)**: kontot flyttas då från R1 eller R2 till R3. Är förmånen inte bokförd fyller du i den i R15.
4. Under **Räntefördelning (R30, R31)**, fyll i **Kapitalunderlag för räntefördelning** och **Sparat fördelningsbelopp från förra året** (ruta 8011 på förra årets NE-bilaga; NONOX fyller i det om förra årets NE finns här). Är underlaget lägre än minus 500 000 kronor är negativ räntefördelning tvingande. NONOX räknar den och drar först av det sparade beloppet. Kan underlaget vara så lågt enligt förra årets bokslut men du inte har fyllt i det, visas en varning.
   Positiv räntefördelning är frivillig. Under **Du kan räntefördela högst** står det högsta beloppet (högst överskottet i R29). Skriv det belopp du väljer i **Positiv räntefördelning du väljer att göra**, eller 0. Tills du har valt görs ingen. Det belopp du räntefördelar beskattas som inkomst av kapital, och då blir underlaget för din allmänna pension och din sjukpenninggrundande inkomst lägre. Det du inte använder sparas till nästa år (8011). Det första året med verksamheten räknas ingen positiv räntefördelning fram ur kapitalunderlaget.
5. Under **Periodiseringsfond (R32, R34)**, fyll i en återföring och den avsättning du vill göra. Under fältet står det högsta beloppet, 30 % av överskottet i R33. Har du bokfört avsättningen på 8810–8819, föreslår NONOX samma belopp i R13 så att den inte dras av två gånger. En fond ska återföras senast det sjätte året efter avsättningen, den äldsta först.
6. Under **Expansionsfond (R36, R37)**, fyll i kapitalunderlaget vid årets slut, fonden vid årets början och den ökning eller minskning du vill göra. Fonden får vid årets slut vara högst 125,94 % av underlaget. Blir den större, eller finns inget positivt underlag, visar NONOX hur mycket som ska återföras i R37.
7. Under **Egenavgifter (R40, R41, R43)**, fyll i förra årets avdrag (R43 på förra årets NE-bilaga) och de påförda egenavgifterna i slutskattebeskedet. NONOX räknar fram **R43 Årets beräknade avdrag för egenavgifter**: 25 % av R42, 10 % om du hade fyllt 67 år när året började (för 2026: född 1938–1958) eller om du kryssar i **Du har haft hel ålderspension hela året, eller hel sjuk- eller aktivitetsersättning någon del av året**, och 20 % vid passiv näringsverksamhet. Är du född 1937 eller tidigare betalar du inga egenavgifter, och då blir det inget avdrag. Skriv ett annat belopp om du vill.
8. Fyll i de andra justeringarna som gäller dig, under de hopfällbara rubrikerna.
9. Klicka på **Spara** och granska **NE-bilagan, fält för fält**.
10. Klicka på **Ladda ner NE-bilagan som SRU-fil** och följ **Så lämnar du NE-bilagan**:
    1. Packa upp ZIP-filen. Byt inte namn på filerna.
    2. Logga in i Skatteverkets e-tjänst Inkomstdeklaration 1 med e-legitimation.
    3. Under Bilagor väljer du Importera vid Enskild näringsverksamhet (NE) och väljer BLANKETTER.SRU.
    4. Kontrollera uppgifterna, för över beloppen till Inkomstdeklaration 1 och skriv under:

| NE-bilagan | Inkomstdeklaration 1 |
|---|---|
| R30 Positiv räntefördelning | 11.1 |
| R31 Negativ räntefördelning | 11.2 |
| R36 Ökning av expansionsfond | 12.1 |
| R37 Minskning av expansionsfond | 12.2 |
| R38 Egna pensionspremier | 10.6 |
| R45 Allmänt avdrag | 14.1 |
| R47 Överskott | 10.1 (aktiv) eller 10.3 (passiv) |
| R48 Underskott | 10.2 (aktiv) eller 10.4 (passiv), och nästa år R24 |

> [!WARNING]
> Lämna inte en filöverförd deklaration som bara innehåller näringsuppgifterna. Den kan inte kompletteras med en pappersdeklaration, och den senast lämnade deklarationen gäller. Importera NE-bilagan i din egen deklaration i stället.

### Det här gör NONOX

- **B1–B16 och R1–R11** fylls ur bokföringen i hela kronor. B10 Eget kapital är tillgångarna minus skulderna. Försäljning på ett konto i momsruta 42 hamnar i R2 Momsfria intäkter, och är företaget inte momsregistrerat hamnar all försäljning där. Finansiella poster följer BAS koppling för NE: vissa konton alltid i R4 eller R8, de andra efter kontots eget tecken. Ett övertrasserat bankkonto räknas som låneskuld (B13), och en momsfordran netto på 26xx som övrig fordran (B8). Blir en rad ändå negativ visas en varning. Är företaget inte momsregistrerat men säljer för mer än 120 000 kronor visas en varning om momsregistrering. Med förenklat årsbokslut (K1) förs obeskattade reserver och avsättningar till B16.
- **Räntefördelning**, **periodiseringsfond**, **expansionsfond** och **schablonavdraget för egenavgifter** räknas fram med årets procentsatser, som finns i NONOX med källa. Saknas de för året visas en varning, och då räknas de inte ut och ingen fil kan laddas ner.
- **Ett tak** avrundas nedåt till hela kronor.
- **Århundradet** du anger sparas krypterat. I behandlingshistoriken står vilka fält som sparades, aldrig beloppen.

## Handelsbolag: Inkomstdeklaration 4

![Inkomstdeklaration 4 för ett handelsbolag med delägarna, de skattemässiga justeringarna och en bilaga INK4DU per delägare.](/bilder/manual/bokslut/inkomstdeklaration-ink4.webp)

Ett handelsbolag eller kommanditbolag betalar ingen inkomstskatt själv. Det lämnar Inkomstdeklaration 4 med räkenskapsschemat (INK4R), skattemässiga justeringar (INK4S) och en bilaga INK4DU för varje delägare med delägarens andel av överskottet eller underskottet.

### Så här gör du

1. Gör bokslutet först.
2. Gå till **Bokföring → Inkomstdeklaration** och välj året.
3. Under **Delägare**, klicka på **Lägg till delägare** för varje delägare. Fyll i **Nummer** (delägarens konton i BAS: 1 är 2010–2019, 2 är 2020–2029, 3 är 2030–2039 och 4 är 2040–2049), **Namn**, **Personnummer med århundrade** eller, om delägaren är ett företag, kryssa i **Delägaren är ett företag (juridisk person)** och fyll i **Organisationsnummer**. Fyll i **Andel, %**. Klicka på **Spara delägarna**. Ett nytt år börjar med förra årets delägare; de är inte sparade för året förrän du klickar på **Spara delägarna**. Ett sparat personnummer visas med bara de fyra sista siffrorna: lämna fältet tomt för att behålla det, eller skriv ett nytt. BAS har konton för fyra delägare, så har bolaget fler fyller du i INK4 i Skatteverkets e-tjänst. När räkenskapsåret är stängt kan delägarna inte ändras. **Registerutdrag (JSON)** vid en sparad delägare ger det NONOX har sparat om delägaren.
4. Under **Skattemässiga justeringar (INK4S)**, fyll i det som gäller bolaget. **4.16 Pensionskostnader** föreslås från kontona 7400–7499.
5. Svara på frågorna om konsult och revision och klicka på **Spara**.
6. Granska **Deklarationen, fält för fält**, med en INK4DU per delägare.
7. Klicka på **Ladda ner SRU-filer** och lämna INFO.SRU och BLANKETTER.SRU i Skatteverkets e-tjänst Filöverföring. Knappen visas när delägarnas andelar blir 100 %.

### Det här gör NONOX

- **INK4R** fylls ur bokföringen enligt BAS koppling för INK4. Delägarnas eget kapital: ingående kapital vid årets början, nettouttag eller nettotillskott, årets resultat och utgående kapital. Stämmer nettouttaget inte med uttagen och insättningarna på delägarnas konton visas en varning.
- **INK4DU**: varje delägares andel av överskottet eller underskottet (avrundad nedåt, resten till den sista delägaren), tillskott och uttag från delägarens konton. Har en delägare andra bokningar på sitt eget kapital under året, till exempel en insättning på x0, visas kontona i en varning. Under **Tillskott, uttag och nedsättning för delägaren** kan du skriva in tillskott och uttag själv, och **Andel avdrag arbetsgivaravgift (8105)** om bolaget har gjort regional nedsättning av arbetsgivaravgifterna.
- **Person- och organisationsnummer** kontrolleras och sparas krypterade, och skrivs bara i filen till Skatteverket.

### Det här gör NONOX inte

- **N3A och N3B.** Varje delägare deklarerar sin del själv: en fysisk person på blankett N3A, ett aktiebolag eller annan juridisk person på N3B. Där görs också räntefördelning, periodiseringsfond och egenavgifter. De blanketterna tar NONOX inte fram.
- **Första sidan av Inkomstdeklaration 4** kan inte lämnas med filöverföring. Den behövs bara om bolaget redovisar särskild löneskatt, avkastningsskatt eller fastighetsskatt; då lämnas den på papper eller i e-tjänsten Inkomstdeklaration 2, 3 och 4. NONOX varnar när bokföringen visar pensionskostnader eller fastighetsskatt.

## När knappen inte finns

Fältkoderna i SRU-filerna gäller en viss beskattningsperiod och kontrolleras för varje ny period. För en period vars koder inte är kontrollerade än står det att **fältkoderna … inte är kontrollerade ännu**, och inga filer kan laddas ner. Använd då siffrorna på sidan och fyll i deklarationen direkt i Skatteverkets e-tjänst.

Företagets organisationsnummer behövs för filerna.

> [!NOTE]
> Kopplingen mellan BAS-konton och deklarationens fält är ännu inte juridiskt kontrollerad mot den officiella kopplingen, särskilt för balansräkningen i INK2. Resultaträkningen i INK2 och INK4 följer BAS SRU-kopplingar från 2024 och NE-bilagan den från 2023, och avrundningen av räntefördelning, periodiseringsfond och egenavgifter ska kontrolleras. Låt din redovisningskonsult granska deklarationen. Förslagen till schablonintäkt och till underlaget för särskild löneskatt bygger på en granskning 2026-11-02; juristgranskning återstår. Underlaget enligt lagen om särskild löneskatt är inte alltid detsamma som den bokförda kostnaden.

## Vanliga frågor

**Sidan säger "Inte för den här företagsformen".**
NONOX tar fram Inkomstdeklaration 2, NE-bilagan och Inkomstdeklaration 4. En ideell förening eller stiftelse deklarerar i Skatteverkets e-tjänst.

**Påverkar justeringarna bokföringen?**
Nej. De är bara skattemässiga och sparas med deklarationen. Den skatt du bokför i bokslutet anger du själv.

## Läs också

- [Skatt och årets resultat](/manual/bokslut/bokslutsverifikationer)
- [Årsredovisning enligt K2](/manual/bokslut/arsredovisning)
- [Arkivpaketet](/manual/bokslut/arkivpaket)
