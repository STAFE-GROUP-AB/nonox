---
beskrivning: Räkna fram vecka för vecka vad som behöver köpas in eller tillverkas, utifrån prognos, kundordrar, stycklistor och lager, och gör förslagen till utkast.
ordning: 31
roller: [owner, admin, accountant, bookkeeper, auditor]
modul: inventory
rutter: [inventory.mrp]
sokord: [behovsplanering, MRP, materialbehov, planerade order, nettobehov, ledtid, säkerhetslager, släpp nu, försenad, flytta fram, överskott, stycklista]
---

# Behovsplanering

Behovsplaneringen räknar vecka för vecka ut vad varje artikel behöver, och föreslår inköp och tillverkning som täcker det i tid. Den utgår från prognosen, öppna kundordrar, stycklistor, det som finns i lager och det som redan är beställt eller under tillverkning. Förslagen kallas planerade order. Du väljer själv vilka som ska bli utkast. Behovsplaneringen beställer, släpper och bokför ingenting.

![Behovsplaneringen med planerade order: artikel, typ, antal, när det ska beställas och när det behövs, och råd.](/bilder/manual/lager/behovsplanering.webp)

## Innan du börjar

- Lager ska vara påslaget.
- Artiklarna du vill planera ska vara lagerförda och ha planeringsmetoden **Behovsplanering (MRP)**. Ange också ledtid, säkerhetslager och partistorlek. Se [Prognos och planeringsdata](/manual/lager/prognos).
- Lägg gärna en prognos under **Lager → Prognos**. Utan prognos planeras bara det som kundordrar och tillverkning behöver.
- Med Tillverkning påslaget planeras en artikel som har en stycklista som gäller i dag som tillverkning. Annars planeras den som inköp.

## Så här kör du behovsplaneringen

1. Gå till **Lager → Behovsplanering**.
2. Ange **Antal veckor (högst 52)**. Standard är 13 veckor.
3. Klicka på **Kör behovsplanering**.

Du får meddelandet **Behovsplaneringen är klar** med antalet planerade order, hur många av dem som ska åtgärdas och hur många råd om befintliga order det blev. Varje körning ersätter förslagen från den förra. Körningar äldre än 30 dagar tas bort tillsammans med sina planerade order. Utkast som du har skapat finns kvar som vanliga order.

## Så här räknar NONOX

Planeringen går vecka för vecka från måndagen i den här veckan. Det som är försenat, till exempel en kundorder som skulle ha levererats förra veckan, räknas in i den första veckan. Det som ligger efter sista veckan räknas inte.

För varje artikel och vecka:

1. **Behov från försäljningen** är det största av prognosen och kundordrarna den veckan, inte summan. Kundordrarna räknas in i prognosen. Är prognosen 10 och kundordrarna 6 är behovet 10. Är kundordrarna 12 är behovet 12.
2. Till det läggs komponenter som tillverkning behöver: det som öppna tillverkningsorder ännu inte har tagit ut, och det som planerad tillverkning av en annan artikel behöver enligt stycklistan.
3. **Tillgängligt** är det som finns i lager plus det som kommer: öppna inköpsorder (även utkast) och öppna tillverkningsorder (även utkast), på det datum de väntas. I lager räknas hela saldot, även batcher som har gått ut eller är spärrade, precis som i inköpsförslaget.
4. Räcker det inte till behovet eller säkerhetslagret flyttar NONOX först fram den del som behövs av en order som redan kommer senare. Du får rådet **Flytta fram**. Resten av ordern ligger kvar på sitt datum.
5. Hamnar saldot ändå under säkerhetslagret blir det en planerad order. Den räcker till säkerhetslagret, höjs till **Minsta parti** och avrundas uppåt till **Partimultipel**.
6. Den planerade ordern behövs på veckans måndag, eller i dag i den första veckan. Den ska beställas eller startas ledtiden före det.

En kundorder räknas på det leveransdatum som är bekräftat för kunden. Finns inget, gäller orderns leveransdatum och annars orderdatumet. En inköpsorder räknas på radens väntade datum, annars orderns önskade leveransdatum, annars orderdatumet plus ledtiden. En tillverkningsorder räknas på det slutdatum som produktionsplanen beräknar, annars på **Klart senast**.

Artiklar planeras nivå för nivå. Den färdiga produkten planeras först. Dess planerade tillverkning blir sedan behov av komponenterna på startdagen, med spill enligt stycklistan. Bara artiklar med planeringsmetoden Behovsplanering (MRP) planeras. Kapaciteten räknas inte: hur verkstaden hinner med visar [Planeringen för verkstaden](/manual/tillverkning/planering) för de tillverkningsorder du skapar.

## Så här läser du de planerade orderna

Varje rad under **Planerade order** visar:

- **Typ**: **Inköp** eller **Tillverkning**.
- **Antal** i artikelns lagerenhet.
- **Beställ eller starta**: dagen då ordern ska läggas eller tillverkningen starta.
- **Behövs**: dagen och veckan då varorna behövs.
- **Leverantör**: artikelns standardleverantör, för inköp.
- **Råd**: **Släpp nu** när ordern ska läggas inom en vecka. **Försenad** när beställningsdagen redan har passerat. Då står det också när varorna tidigast kan komma med ledtiden.
- **Täcker**: klicka för att se vilka behov raden täcker, till exempel en kundorder, prognosen utöver kundordrarna, en tillverkningsorder, planerad tillverkning av en annan artikel eller påfyllning till säkerhetslagret.

Välj **Bara inköp**, **Bara tillverkning** eller **Bara de som ska åtgärdas** för att visa färre rader.

## Så här skapar du utkast av förslagen

1. Kryssa i de planerade order du vill använda.
2. Klicka på **Skapa utkast av de valda** och bekräfta.

Inköp blir ett utkast till inköpsorder per leverantör, med varje rad väntad den dag den behövs. Tillverkning blir ett utkast till tillverkningsorder per rad, med planerad start och **Klart senast**. Raden visar **Utkast** och en länk till ordern. Granska och skicka inköpsordern som vanligt under [Inköpsorder](/manual/lager/inkopsorder), och släpp tillverkningsordern under [Tillverkningsorder](/manual/tillverkning/tillverkningsorder).

En planerad order utan leverantör kan inte bli en inköpsorder. Ange en standardleverantör på artikeln och kör om behovsplaneringen. Du kan bara skapa utkast från den senaste körningen.

Nästa körning räknar utkasten som beställda, så samma behov föreslås inte igen.

## Råd om befintliga order

Under **Råd om befintliga order** står order som redan finns:

- **Flytta fram**: så mycket av ordern behövs tidigare än den väntas. Kolumnen **Behövs** visar när. Behövs bara en del står det också hur mycket ordern är på, och då kan du dela ordern. Hör med leverantören eller flytta tillverkningen.
- **Överskott**: så mycket av ordern behövs inte under perioden, inte ens för säkerhetslagret, räknat på lagret och de order som redan finns. Minska eller skjut upp den om du håller med. En order kallas aldrig överskott när en planerad order föreslås för samma behov.

NONOX ändrar aldrig ordern själv.

## Vem får göra vad

| Roll | Se behovsplaneringen | Köra och skapa utkast |
|---|---|---|
| **Ägare** | Ja | Ja |
| **Administratör** | Ja | Ja |
| **Redovisningskonsult** | Ja | Nej |
| **Bokförare** | Ja | Ja, inköp |
| **Revisor** | Ja | Nej |
| **Attestant** | Nej | Nej |
| **Lagerpersonal** | Nej | Nej |

För att skapa utkast krävs också rätt att hantera inköpsorder respektive tillverkningsorder. En bokförare kan skapa utkast till inköpsorder men inte till tillverkningsorder.

## Det här händer i bokföringen

Ingenting. Planerade order är förslag, inte bokföring, och skapar inga verifikationer.

## Bra att veta

- [Inköpsförslag](/manual/lager/inkopsforslag) kan fortfarande visa artiklar som behovsplaneras, eftersom det räknar med beställningspunkt och öppna kundordrar. Utkast som du har skapat från behovsplaneringen räknas som beställda på båda ställena, så inget räknas två gånger.
- Behovsplaneringen sparas per företag. Ett annat företag med samma artikelnummer har sin egen.
- Samma underlag ger alltid samma förslag. Varje körning sparar en kontrollsumma av underlaget.
- Varje körning och varje omvandling till utkast sparas i behandlingshistoriken med antal och id-nummer, men inga namn.

## Läs också

- [Prognos och planeringsdata](/manual/lager/prognos)
- [Inköpsförslag](/manual/lager/inkopsforslag)
- [Inköpsorder](/manual/lager/inkopsorder)
- [Roller och behörigheter](/manual/lager/roller-och-behorigheter)
