---
beskrivning: Översikten är startsidan i NONOX – nyckeltal, en lista över det som väntar på dig, de senaste verifikationerna, Kom igång-listan och statistik med prognos.
ordning: 30
rutter: [dashboard, dashboard.gettingStarted]
sokord: [startsida, dashboard, översikt, att göra, nyckeltal, prognos, statistik, likviditet, kom igång]
---

# Översikten

**Översikt** är det första du ser efter inloggningen. Den svarar på två frågor: hur går det för företaget just nu, och vad behöver jag göra? Allt du ser beror på din roll; den som inte får se rapporter ser till exempel inte statistiken.

![Översikten för en restaurang: pengar på banken, obetalda fakturor, att betala, momsen och det som behöver göras.](/manual/bilder/kom-igang/oversikten.webp)

## Nyckeltal

Överst finns fyra nyckeltal. Var och en visas bara för den som får se underlaget bakom den.

- **Företagskonto** – saldot på företagets bankkonto i bokföringen, och hur långt kontot är avstämt mot banken (**Avstämt t.o.m.** ett datum, eller **Inte avstämt ännu**).
- **Obetalda kundfakturor** – vad kunderna är skyldiga, och hur många fakturor som har förfallit.
- **Att betala** – obetalda leverantörsfakturor och hur många som har förfallit.
- **Moms** – momsen för den pågående eller senaste perioden, och sista dagen att lämna deklarationen.

## Att göra

Under **Att göra** samlar NONOX allt som väntar på någon med din roll, i den ordning det brådskar: först det som har en sista dag (och det som redan har passerat den), sedan till exempel moms, arbetsgivardeklarationer, förfallna fakturor, fakturor att attestera och betala, bankavstämning, inkorgen, lön och verifikationer som ligger som utkast. Klicka på **Gör det nu** för att komma direkt till uppgiften.

Är listan tom står det **Inget som väntar**.

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

Prognosen är en enkel räkning, ingen AI och ingen garanti. Den börjar med dagens saldo på bank och kassa (konto 1900–1999). Till det läggs obetalda kundfakturor och dras obetalda leverantörsfakturor den månad de förfaller (förfallna räknas i den här månaden), och sedan det genomsnittliga resultatet för de tre senaste hela månaderna. Lön, skatt och moms som inte finns som leverantörsfakturor kommer bara med genom resultatet.

## Bra att veta

- Siffrorna sparas en kort stund för att sidan ska vara snabb, och kan vara upp till tio minuter gamla. När du bokför något räknas de om. Klicka på pilen **Ladda om översikten** för att räkna om direkt; tiden för senaste uppdateringen står bredvid.
- Arbetar du med en redovisningsbyrå visar kortet **Din redovisningsbyrå** vem som är ansvarig konsult och **Vad byrån har gjort** i företaget de senaste dagarna, hämtat ur behandlingshistoriken. Kortet visas för den som får se behandlingshistoriken. Läs mer under [För redovisningsbyråer](/manual/byra).

## Läs också

- [Hitta i NONOX](/manual/kom-igang/hitta-i-nonox)
- [Sök](/manual/kom-igang/sok)
- [Byt företag](/manual/kom-igang/byt-foretag)
- [Kontoanalys och huvudbok](/manual/rapporter/kontoanalys-och-huvudbok)
