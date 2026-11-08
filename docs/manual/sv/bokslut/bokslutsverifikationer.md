---
beskrivning: Omvärdera fakturor i utländsk valuta, bokför årets skatt och boka årets resultat till eget kapital den sista dagen i räkenskapsåret.
ordning: 60
roller: [owner, admin, accountant]
sokord: [bokslutsverifikation, skatteberäkning, periodiseringsfond, avsättning, återföring, 8811, 8819, 2120, omvärdering, balansdagens kurs, valutakurs, 3960, 7960, årets skatt, inkomstskatt, bolagsskatt, 8910, 2512, årets resultat, 8999, 2099, 2019, boka resultatet]
---

# Skatt och årets resultat

När allt annat i året är bokfört återstår två bokslutsverifikationer: årets skatt och årets resultat. Båda bokförs den sista dagen i räkenskapsåret, från steg **2. Bokslutsverifikationer** under **Bokföring → Bokslut**.

Bokför först allt annat, sedan skatten och sist årets resultat.

Före skatten kan två verifikationer till komma:

- med kontantmetoden: de fakturor som är obetalda den sista dagen. Läs [Obetalda fakturor vid årets slut (kontantmetoden)](/manual/bokslut/kontantmetoden-vid-arets-slut).
- har företaget obetalda fakturor i utländsk valuta den sista dagen: omvärderingen till kursen den dagen.

![Under Bokslut bokför du skatt och årets resultat.](/bilder/manual/bokslut/checklistan.webp)

## Omvärdering av fakturor i utländsk valuta

Kund- och leverantörsfakturor i euro, dollar eller annan valuta som är obetalda den sista dagen i räkenskapsåret ska värderas till kursen den dagen (K2 punkt 13.5 och 17.8, K3 punkt 30.3). NONOX använder Riksbankens kurs. Steget visas bara när sådana fakturor finns.

En faktura som är delvis betald eller delvis krediterad värderas för det som återstår.

Med kontantmetoden bokför du först de obetalda fakturorna vid årets slut. Då hamnar de på 1510 och 2440 till fakturans kurs, och sedan värderas de här som med fakturametoden.

### Så här bokför du omvärderingen

1. Lägg upp nästa räkenskapsår om det inte finns. Omvärderingen återförs på dess första dag.
2. Gå till **Bokföring → Bokslut** när årets sista dag har passerat.
3. Under **Omvärdering av valutafordringar och valutaskulder** visas varje obetald faktura med det som återstår i valutan, det bokförda värdet i kronor, värdet till dagens kurs och skillnaden.
4. Klicka på **Bokför omvärderingen** och bekräfta.

**I bokföringen:** en verifikation i serie A den sista dagen, med skillnaden på 1510 Kundfordringar och 2440 Leverantörsskulder mot 3960 (kursvinst) eller 7960 (kursförlust), och en omvänd verifikation den första dagen i nästa år. Återföringen gör att en betalning nästa år fortfarande räknas mot fakturans egen kurs.

Saknar Riksbanken kurs för en valuta den dagen bokför du omvärderingen för hand med en vanlig verifikation, och en återföring första dagen i nästa år. Kryssa sedan i **Omvärderingen är bokförd för hand** vid punkten i [checklistan](/manual/bokslut/checklistan). Punkten stoppar bokslutet tills omvärderingen är bokförd eller bekräftad.

Bankkonton i utländsk valuta finns inte i NONOX – alla bankkonton förs i kronor – så det finns inga banksaldon i valuta att omvärdera.

> [!NOTE]
> Att fordringar och skulder i utländsk valuta ska värderas till balansdagens kurs är kontrollerat enligt en AI-utredning 2026-11-02; juristgranskning återstår. Att Riksbankens mittkurs duger som kurs, och att omvärderingen återförs på nästa års första dag, är inte juridiskt kontrollerat. Stäm av med din redovisningskonsult.

## Årets skatt

Gäller företag som själva betalar inkomstskatt. För **enskild firma, handelsbolag och kommanditbolag** bokförs ingen skatt i företaget: överskottet beskattas hos ägaren eller delägarna i deras egna deklarationer. Sidan visar då bara en upplysning om det, och ingen verifikation skapas.

### Förslag till skatt för aktiebolag och ekonomiska föreningar

Steget är märkt **Beta**. NONOX räknar fram ett förslag till årets inkomstskatt från bokföringen och från det du har fyllt i under **Bokföring → Inkomstdeklaration**. Ingenting bokförs förrän du har granskat beräkningen och bekräftat den. Förslaget är inte granskat av en redovisningskonsult, och särreglerna för ekonomiska föreningar, till exempel kooperativ utdelning och återbäring, hanteras inte. Låt din redovisningskonsult granska förslaget.

Först svarar du på frågan **Är företaget ett privatbostadsföretag, till exempel en bostadsrättsförening, eller ett investmentföretag?** med **Nej, inget av dem**, **Ja, privatbostadsföretag** eller **Ja, investmentföretag**. Svaret sparas för räkenskapsåret och kan ändras tills skatten är bokförd.

- Ett privatbostadsföretag är en förening eller ett aktiebolag som till klart övervägande del upplåter bostäder åt sina medlemmar eller delägare i hus som det äger (inkomstskattelagen 2 kap. 17 §) – till exempel de flesta bostadsrättsföreningar. Det tar inte upp inkomster och drar inte av utgifter som hör till fastigheten (39 kap. 25 §) och får inte sätta av till periodiseringsfond (30 kap. 1 §).
- Ett investmentföretag har egna skatteregler (39 kap. 14 §) och får inte heller sätta av till periodiseringsfond.

Svarar du ja räknar NONOX inte fram någon skatt, och du bokför den med ett eget belopp (se nedan). Förslaget visas bara när du har svarat **Nej, inget av dem**.

Beräkningen visas rad för rad med kolumnerna **Rad**, **Varifrån** och **Belopp**:

| Rad | Vad det är |
|---|---|
| **Bokfört resultat före skatt** | Årets resultat i bokföringen, med redan bokförd skatt borträknad. |
| Justeringar i inkomstdeklarationen | Till exempel **Andra bokförda kostnader som inte ska dras av** (fält 7653), **Andra bokförda intäkter som inte ska tas upp** (fält 7754), **Beräknad schablonintäkt på periodiseringsfonder** (fält 7654) och **Outnyttjat underskott från föregående år** (fält 7763). Kolumnen **Varifrån** visar om beloppet är **Ifyllt under Inkomstdeklaration** eller ett **Förslag ur bokföringen**. |
| **Återföring av periodiseringsfond** | Gamla periodiseringsfonder som tas tillbaka i år. |
| **Överskott före årets avsättning** | Summan hittills. |
| **Avsättning till periodiseringsfond** | Det du väljer att sätta av i år. |
| **Skattemässigt överskott** eller **Skattemässigt underskott** | Det som skatten räknas på. |
| **Beskattningsbar inkomst, avrundad nedåt till helt tiotal kronor** | Inkomstskattelagen 1 kap. 7 §. |
| **Inkomstskatt 20,6 %** | Skatten för året, med länken **Källa** till lagtexten (inkomstskattelagen 65 kap. 10 §). |

Förslaget ur bokföringen för kostnader som inte får dras av är summan på 6072 Representation, ej avdragsgill, 6982 Föreningsavgifter, ej avdragsgilla, 6992 Övriga externa kostnader, ej avdragsgilla och 8423 Räntekostnader för skatter och avgifter. Skattefria intäkter är 8314 Skattefria ränteintäkter. Andra justeringar fyller du i under Inkomstdeklaration; ett belopp du fyller i där ersätter förslaget, även 0.

### Periodiseringsfond

- **Återföring av periodiseringsfond, kr** – fälten visas när företaget har periodiseringsfonder vid årets början. Den äldsta fonden återförs först. En fond ska återföras senast det sjätte beskattningsåret efter avsättningen (inkomstskattelagen 30 kap. 7 §), och det beloppet är förslaget; det går inte att bekräfta mindre. Året läses ur BAS-kontot: 2120 är fonden för 2020, 2126 för 2026 och så vidare. Finns fondens år som räkenskapsår i NONOX räknas de sex åren i företagets räkenskapsår, annars i kalenderår. En fond som återförs helt återförs på öret, så att kontot blir noll.
- **Avsättning till periodiseringsfond, kr** – frivilligt. Högst 25 procent av överskottet före avsättningen (inkomstskattelagen 30 kap. 5 §). Klicka på **Använd högsta avsättning** för att fylla i det högsta beloppet, eller lämna 0.

Beräkningen räknas om medan du skriver. Knappen för att bekräfta är avstängd tills den nya beräkningen visas.

### Så här bokför du skatten

1. Fyll i justeringarna under **Bokföring → Inkomstdeklaration** först, om det finns några.
2. Gå till **Bokföring → Bokslut** och steget **2. Bokslutsverifikationer**.
3. Granska raderna under **Årets skatt** och de gula varningarna, om det finns några.
4. Fyll i återföring och avsättning till periodiseringsfond om det gäller företaget.
5. Klicka på **Bekräfta och bokför skatten**. Rutan som öppnas räknar upp varje rad som bokförs – återföring, avsättning och skatt med belopp och konton – eller säger att ingen verifikation skapas. Bekräfta.

Har skatten ändrats sedan beräkningen visades, till exempel av en ny verifikation, bokförs ingenting och du får granska beräkningen igen. Steget kan inte göras om när det är bekräftat.

Knappen visas inte, och en röd ruta säger varför, när

- frågan om privatbostadsföretag eller investmentföretag inte är besvarad,
- NONOX saknar skattesats för året,
- det redan finns skatt bokförd på 8900–8989 i året (uppskjuten skatt på 8940–8949, som ett K3-företag bokför under [Uppskjuten skatt](/manual/bokslut/uppskjuten-skatt), räknas inte),
- företaget har periodiseringsfonder vid årets början men schablonintäkten (fält 7654) saknas, eftersom statslåneräntan för året inte finns i NONOX – fyll då i den under Inkomstdeklaration (inkomstskattelagen 30 kap. 6 a §),
- återföringen är lägre än det som ska återföras i år (30 kap. 7 §). Har du redan återfört en del för hand räknas den av; resten bokför du för hand.

**I bokföringen:** en verifikation i serie A den sista dagen i året med

- återföringen: fondkontot (till exempel 2120) i debet mot 8819 Återföring från periodiseringsfond i kredit,
- avsättningen: 8811 Avsättning till periodiseringsfond i debet mot årets fondkonto (2120 plus årets sista siffra, till exempel 2126 för 2026) i kredit,
- skatten: 8910 Skatt som belastar årets resultat i debet mot 2512 Beräknad inkomstskatt i kredit.

Blir det ingen skatt och ingen fond sparas noll utan verifikation. Saknade konton läggs till med sitt BAS-namn.

Förslaget kan inte bokföras om det redan finns skatt bokförd på 8900–8989 i året, och delen om periodiseringsfond visas inte om en fond redan är bokförd i året.

> [!WARNING]
> En bokförd verifikation kan inte ändras. Blev skatten fel rättar du verifikationen med en rättelseverifikation. Läs [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation).

> [!NOTE]
> Reglerna kommer från inkomstskattelagen, Skatteverket och BAS-kontoplanen enligt en AI-utredning 2026-11-06; juristgranskning återstår. Särskilt hur året för en fond läses ur BAS-kontot och om skatten ska avrundas till hela kronor är inte juridiskt kontrollerat. Privatbostadsföretag och investmentföretag får inte göra avsättning till periodiseringsfond (30 kap. 1 §); det kontrollerar inte NONOX. Du ansvarar för att beloppen stämmer med deklarationen.

### Bokför ett eget belopp

Under **Bokför ett eget belopp i stället** kan du skriva skatten själv i **Inkomstskatt, kr** och klicka på **Bokför skatten**. Det är så skatten bokförs för **ideella föreningar och stiftelser**, som har egna skatteregler och inte får något förslag. Blir det ingen skatt, skriv 0.

## Årets resultat

Resultatet efter skatt flyttas från resultaträkningen till eget kapital, så att resultaträkningen går jämnt ut.

### Så här bokar du resultatet

1. Kontrollera att allt annat i året är bokfört, och att skatten är bokförd.
2. Sidan visar **Kvar att boka** med belopp och konton.
3. Klicka på **Boka årets resultat** och bekräfta.

**I bokföringen:** resultatet bokförs på **8999 Årets resultat** mot:

- **2099 Årets resultat** för aktiebolag och föreningar,
- **2019 Årets resultat** för enskild firma, handelsbolag och kommanditbolag.

Saknas något av kontona i kontoplanen läggs det till med sitt BAS-namn. Båda verifikationerna bokförs i serie A och kan inte ändras.

## Om du bokför något i året efteråt

Bokför du något mer i året efter att resultatet är bokat, visar sidan **Kvar att boka** igen med mellanskillnaden. Boka den på samma sätt. Därför är det bäst att vänta med resultatet tills allt annat är klart.

## Vanliga frågor

**Ska skatten bokföras innan inkomstdeklarationen är klar?**
Skatten bokförs i bokslutet, som en beräknad skatt. Inkomstdeklarationen visar det skattemässiga resultatet som skatten bygger på.

**Vi har förlust – vad gör jag?**
Förslaget visar **Skattemässigt underskott** och ingen skatt. Klicka på **Bekräfta och bokför skatten**; noll sparas utan verifikation. Boka sedan resultatet som vanligt. Ett underskott kan dras av kommande år i fält 7763 i inkomstdeklarationen.

**Kan jag ändra skatten efteråt?**
Inte här – skatten sparas en gång. Rätta verifikationen om beloppet blev fel. Läs [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation).

> [!NOTE]
> Hur ideella föreningar och stiftelser ska hantera skatt och konton i bokslutet är inte juridiskt kontrollerat i NONOX; de bokför skatten med ett eget belopp. Fråga din redovisningskonsult.

## Läs också

- [Checklistan för bokslutet](/manual/bokslut/checklistan)
- [Obetalda fakturor vid årets slut (kontantmetoden)](/manual/bokslut/kontantmetoden-vid-arets-slut)
- [Stäng räkenskapsåret](/manual/bokslut/stang-aret)
- [Inkomstdeklaration 2 och SRU-filer](/manual/bokslut/inkomstdeklaration)
