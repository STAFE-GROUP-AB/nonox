---
beskrivning: Översikten är startsidan i NONOX – nyckeltal, en lista över det som väntar på dig, de senaste verifikationerna, Kom igång-listan och statistik med prognos.
ordning: 30
rutter: [dashboard, dashboard.gettingStarted]
sokord: [startsida, dashboard, översikt, att göra, nyckeltal, prognos, statistik, likviditet, kom igång]
---

# Översikten

**Översikt** är det första du ser efter inloggningen. Den svarar på två frågor: hur går det för företaget just nu, och vad behöver jag göra? Allt du ser beror på din roll; den som inte får se rapporter ser till exempel inte statistiken.

![Översikten för en restaurang: pengar på banken, obetalda fakturor, att betala, momsen och det som behöver göras.](/bilder/manual/kom-igang/oversikten.webp)

## Nyckeltal

Överst finns fyra nyckeltal. Var och en visas bara för den som får se underlaget bakom den.

- **Företagskonto** – saldot på företagets bankkonto i bokföringen, och hur långt kontot är avstämt mot banken (**Avstämt t.o.m.** ett datum, eller **Inte avstämt ännu**).
- **Obetalda kundfakturor** – vad kunderna är skyldiga, och hur många fakturor som har förfallit.
- **Att betala** – obetalda leverantörsfakturor och hur många som har förfallit.
- **Moms** – momsen för den pågående eller senaste perioden, och sista dagen att lämna deklarationen.

## Att göra

Under **Att göra** samlar NONOX allt som väntar på någon med din roll, i den ordning det brådskar: först det som har en sista dag (och det som redan har passerat den), sedan till exempel moms, arbetsgivardeklarationer, förfallna fakturor, fakturor att attestera och betala, bankavstämning, inkorgen, lön och verifikationer som ligger som utkast. Klicka på **Gör det nu** för att komma direkt till uppgiften.

Är listan tom står det **Inget som väntar**.

## Pengar som inte är dina

Kortet **Pengar som inte är dina** visar hur mycket av pengarna på banken som är moms, personalskatt och arbetsgivaravgifter, och vad som blir **Kvar efter moms och skatter**. Det bygger på det som är bokfört, och obokförda fakturor och kvitton syns inte. Kortet visas när Företagsguiden är påslagen, för den som får se rapporterna. Klicka på **Så räknar vi** för att se varje konto i [Mina datum](/manual/kom-igang/mina-datum#pengar-som-inte-ar-dina).

## Från Skatteverket

Väntar Skatteverket på något från företaget visar översikten kortet **Från Skatteverket**, till exempel **1 händelse från Skatteverket väntar på företaget.** Antalet bygger på kundhändelserna som du själv senast hämtade från Skatteverket; kollegor ser sina egna. Klicka på **Läs händelserna** för att läsa dem på [Mina datum](/manual/kom-igang/mina-datum#fran-skatteverket). Kortet visas bara när installationen är kopplad till Skatteverket, för den som får se rapporterna, och försvinner när händelserna är markerade som hanterade.

## Senaste verifikationer

De fem senast bokförda verifikationerna. Rör en verifikation bank eller kassa (konto 1900–1999) visas hur mycket pengar som kom in (plus) eller gick ut (minus). Övriga verifikationer, till exempel en kundfaktura som inte är betald än, visas utan tecken eftersom inga pengar har rört sig. Klicka på **Visa alla** för hela listan.

## Kom igång-listan

I ett nytt företag finns kortet **Kom igång** med fem steg i en klok ordning. Ett steg som är klart bockas av av sig självt:

1. **Skapa företaget** – klart när du ser listan.
2. **Fyll i adress och bankgiro** – behövs på fakturorna så att kunderna vet vart de ska betala. Se [Företagets uppgifter](/manual/installningar/foretagets-uppgifter).
3. **Bjud in din redovisningskonsult eller en kollega** – valfritt. Se [Bjud in en användare](/manual/kom-igang/bjud-in-en-anvandare).
4. **Flytta in bokföringen från ditt gamla program** – med en SIE-fil. Se [Flytta till NONOX](/manual/flytta).
5. **Skicka din första faktura** – se [Skapa en faktura](/manual/fakturering/skapa-en-faktura).

Klicka på **Dölj** när du inte behöver listan längre. Den döljs bara för dig och bara i det här företaget. Vill du se den igen klickar du på **Visa Kom igång igen** längst ner på översikten.

## Statistik och prognos

Fliken **Statistik och prognos** visar räkenskapsåret i diagram, hämtat ur den bokförda bokföringen:

- intäkter och kostnader per månad, jämfört med förra året,
- resultat per månad och ackumulerat,
- saldo på bank och kassa med en prognos för de närmaste månaderna,
- de största kunderna och de största kostnaderna i år,
- moms per månad.

Varje diagram kan visas som en tabell, och varje diagram har en mening som sammanfattar det.

### Så räknas prognosen

Prognosen är en enkel räkning, ingen AI och ingen garanti. Den visar slutet av den här månaden och de två nästa. Den börjar med dagens saldo på bank och kassa (konto 1900–1999) och lägger sedan till eller drar ifrån varje del den månad pengarna väntas komma eller gå. Belopp som redan har förfallit räknas i den här månaden.

- **Kundfakturor**: det som är kvar att betala på obetalda kundfakturor, på förfallodagen. En delbetald faktura räknas med det som är kvar, och en krediterad faktura räknas inte.
- **Leverantörsfakturor**: det som är kvar att betala på obetalda leverantörsfakturor, på förfallodagen.
- **Avtalsfakturor**: de kommande fakturorna från aktiva avtal och utkast som ett avtal har skapat, med moms, på förfallodagen enligt kundens betalningsvillkor. Ett pausat avtal räknas inte. Har ett avtals nästa fakturadatum redan passerat räknas den fakturan en gång, från i dag, och inte en gång för varje missat datum. Ett utkast som förföll för mer än 30 dagar sedan räknas inte.
- **Lön och arbetsgivarskatter**: när lönemodulen är på och har en lönekörning de senaste tre månaderna. Nettolönen räknas på utbetalningsdagen för lönekörningar som är beräknade, attesterade eller utbetalda men inte bokförda. Skatt och arbetsgivaravgifter räknas den dag de ska betalas till Skatteverket. En månad utan lönekörning räknas som den senaste lönekörningen, så den uppskattade lönen fortsätter i upp till tre månader efter den senaste lönekörningen. Lönekörningar som inte är bokförda syns bara för den som får se lönerna; andra ser i stället uppskattningen.
- **Moms**: momsen för varje momsperiod vars sista dag att deklarera och betala infaller i prognosen, med det som är bokfört hittills, och momsen på kommande avtalsfakturor i de perioderna. Moms som är redovisad men inte betald (konto 2650) räknas också. Använder företaget kontantmetoden bokförs momsen när fakturan betalas, så då räknas momsen på avtalsfakturor och på obetalda kund- och leverantörsfakturor i den period de väntas bli betalda, det vill säga på förfallodagen.
- **Skattekontot**: F-skatten, om du har fyllt i **Debiterad preliminärskatt per månad (frivilligt)** under företagets uppgifter, och saldot på skattekontot (konto 1630). Pengar på skattekontot och moms att få tillbaka betalar skatterna först; det som blir över räknas inte som pengar på banken.
- **Snittresultat**: det genomsnittliga resultatet för de tre senaste hela månaderna; för den här månaden bara de dagar som är kvar.

Snittresultatet räknar inte med det som prognosen redan räknar för sig, så att inget kommer med två gånger. När lönen räknas från lönekörningarna tas lönekostnaderna från lönekörningarna bort ur snittet. Pension, särskild löneskatt och avtalsförsäkringar (konto 7400–7499, 7530–7539 och 7570–7579) betalas inte av lönekörningen och stannar därför kvar i snittet. När F-skatten räknas tas skattekostnaden (konto 8900–8998) och den särskilda löneskatten (konto 7530–7539) bort, eftersom de betalas med F-skatten. Intäkterna från avtalsfakturor tas alltid bort ur snittet, minus deras kreditfakturor, eftersom de kommande fakturorna räknas för sig.

Håller du muspekaren över en prognosmånad i diagrammet visar rutan delarna som flyttar linjen. I tabellen under **Visa som tabell** står varje del i en egen kolumn.

## Bra att veta

- Siffrorna sparas en kort stund för att sidan ska vara snabb, och kan vara upp till tio minuter gamla. När du bokför något räknas de om. Klicka på pilen **Ladda om översikten** för att räkna om direkt; tiden för senaste uppdateringen står bredvid.
- Arbetar du med en redovisningsbyrå visar kortet **Din redovisningsbyrå** vem som är ansvarig konsult och **Vad byrån har gjort** i företaget de senaste dagarna, hämtat ur behandlingshistoriken. Kortet visas för den som får se behandlingshistoriken. Läs mer under [För redovisningsbyråer](/manual/byra).

## Läs också

- [Hitta i NONOX](/manual/kom-igang/hitta-i-nonox)
- [Sök](/manual/kom-igang/sok)
- [Byt företag](/manual/kom-igang/byt-foretag)
- [Kontoanalys och huvudbok](/manual/rapporter/kontoanalys-och-huvudbok)
