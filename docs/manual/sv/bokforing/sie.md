---
beskrivning: Läs in bokföringen från ett annat program med en SIE4-fil i två steg, och ladda ner din egen bokföring som SIE-fil för revisorn eller ett annat program.
ordning: 140
rutter: [accounting.sieImports.index, accounting.sieImports.show, accounting.fiscalYears.sie]
sokord: [SIE, SIE4, sie-fil, importera bokföring, exportera bokföring, flytta bokföring, revisor, .se-fil]
---

# SIE-filer

SIE är det format alla svenska bokföringsprogram använder för att lämna över bokföring till varandra. En SIE4-fil innehåller ett räkenskapsår: kontoplanen, de ingående och utgående balanserna och varje verifikation. I NONOX läser du in sådana filer när du flyttar hit, och du laddar ner din egen när revisorn eller redovisningsbyrån vill ha den – eller när du vill ta med dig bokföringen härifrån.

## Läs in en SIE-fil

Inläsningen sker i två steg. Först granskas filen och du får en rapport; ingenting ändras. Först när du har läst rapporten och bekräftat bokförs verifikationerna.

### Så här gör du

1. Ta fram en SIE-fil av **typ 4** i ditt gamla program, en per räkenskapsår. Filen brukar ha "SIE4" i namnet och sluta på `.se`.
2. Kontrollera att du står i rätt företag. Sidan säger vilket företag filen läses in i.
3. Gå till **Bokföring → Läs in SIE-fil**.
4. Välj filen under **SIE-fil** och välj **Granska filen**.
5. Läs rapporten (se nedan). Står det **Filen kan inte läsas in som den är**, rätta det som står i listan och ladda upp filen igen.
6. Välj **Läs in filen** och sedan **Ja, bokför … verifikationer**.
7. Vänta tills det står **Filen är inläst**. En stor fil kan ta en stund; du kan lämna sidan under tiden.

Filen får vara högst 50 MB.

### Rapporten

Under **Det här finns i filen** ser du:

- **Företag** och organisationsnummer enligt filen.
- **Räkenskapsår** – med **skapas här** om året inte finns i NONOX än.
- **Verifikationer** – hur många, och hur många rader.
- **Ingående balanser** – hur många konton, eller att de **finns redan här och stämmer**.
- **Kontrollsumma** – om filen har en och om den stämmer, alltså att filen inte har ändrats sedan den skapades.
- **Skapad av** – programmet som gjorde filen.

Därefter visar rapporten vad som kommer att hända:

- **Serier och nummer** – varje serie i filen, och om verifikationerna **behåller sina nummer** eller **får nya nummer från …** (då sparas filens nummer). En serie som inte finns här skapas.
- **Konton som läggs till i kontoplanen** – konton som används i filen men saknas här. Konton som inte används läggs inte till.
- **Kostnadsställen som läggs till** och **Projekt som läggs till** – från filens dimension 1 och 6.

Under **Bra att veta** står sådant som inte hindrar inläsningen men som du bör känna till, till exempel att filen saknar kontrollsumma, att ett konto heter något annat här (namnet här behålls) eller att året redan har verifikationer så att samma affär kan bli bokförd två gånger.

### Vanliga hinder

Inläsningen stoppas, och ingenting ändras, om till exempel:

- filen inte är av typ 4,
- filen gäller ett annat organisationsnummer än företaget,
- filen eller några av verifikationerna redan är inlästa,
- räkenskapsåret är stängt här, eller har andra datum än i filen,
- året inte ligger direkt före eller efter de år som redan finns,
- de ingående balanserna skiljer sig från dem som redan finns här,
- filen har verifikationer i en [låst period](/manual/bokforing/las-en-period),
- en verifikation inte går ihop eller har ett datum utanför året.

Gäller filen ett annat av dina företag erbjuder rapporten att byta dit och granska filen där. Finns företaget inte än kan du skapa det med uppgifterna från filen.

### Det här händer i bokföringen

- Verifikationerna blir bokförda med samma serier som i filen, och med sina gamla nummer när det går. De går inte att ändra eller ta bort efteråt, bara [rättas](/manual/bokforing/ratta-en-verifikation), precis som allt annat som är bokfört.
- De ingående balanserna i filen blir årets ingående balanser.
- Rader som enligt filen har lagts till eller tagits bort i efterhand sparas som historik till verifikationerna.
- Efter inläsningen jämförs varje kontos utgående balans och resultat med filen. Stämmer något inte läses ingenting in, och du ser vilka konton som skiljer. När allt stämmer står det **varje konto stämmer med filen**.
- Inläsningen skrivs i [behandlingshistoriken](/manual/bokforing/behandlingshistorik).

> [!TIP]
> Har du flera år: börja med det år som redan finns här, oftast det pågående, och fortsätt sedan bakåt ett år i taget.

Under **Tidigare filer** på samma sida ser du alla filer som har laddats upp, med status som **Granskad, väntar på dig**, **Inläst** eller **Misslyckades**.

Flyttar du hela bokföringen från ett annat program finns en guide steg för steg under [Flytta till NONOX](/manual/flytta).

## Ladda ner en SIE-fil

1. Gå till **Bokföring → Räkenskapsår**.
2. Välj **Ladda ner SIE-fil** på det år du vill ha.

Filen är en SIE4-fil för hela räkenskapsåret med kontoplanen, ingående och utgående balanser, resultat för året och året före, kostnadsställen och projekt samt alla bokförda verifikationer. Utkast är inte med, eftersom de inte är bokförda. Filen har en kontrollsumma, så att den som läser in den kan se att den är oförändrad. Varje nedladdning skrivs i behandlingshistoriken.

## Vem får göra vad

Ägare, administratörer och redovisningskonsulter kan läsa in SIE-filer. Samma roller och revisorer kan ladda ner dem.

## Vanliga frågor

**Mitt gamla program gör bara SIE typ 1, 2 eller 3.**
De typerna innehåller inte verifikationerna, bara saldon. Leta efter "SIE4" eller "SIE typ 4" i exporten.

**Rapporten varnar för att året redan har verifikationer.**
Kontrollera att du inte har bokfört samma affärer här som finns i filen, annars blir de bokförda två gånger.

**Filen har andra dimensioner än kostnadsställen och projekt.**
NONOX har kostnadsställen (dimension 1) och projekt (dimension 6). Rader med andra dimensioner läses in utan dem, och rapporten säger hur många.

**Kan jag läsa in en fil igen om det blev fel?**
En fil som har lästs in kan inte läsas in igen; det hindras för att inget ska bokföras dubbelt. Misslyckades inläsningen har ingenting ändrats, och du kan ladda upp filen igen när felet är rättat.

## Läs också

- [Flytta till NONOX](/manual/flytta)
- [Räkenskapsår](/manual/bokforing/rakenskapsar)
- [Verifikationsserier](/manual/bokforing/verifikationsserier)
- [Arkivpaket](/manual/bokslut/arkivpaket)
