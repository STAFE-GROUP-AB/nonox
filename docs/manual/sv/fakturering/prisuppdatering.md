---
beskrivning: Ändra många priser på en gång, med en procent, ett belopp, en önskad täckningsgrad, två indextal eller från en fil, och se varje nytt pris innan något sparas.
ordning: 26
roller: [owner, admin, accountant]
rutter: [pricing.updates, pricing.updates.show]
sokord: [prisuppdatering, täckningsgrad, marginal, leverantör, kundgrupp, prishöjning, prisändring, höja priser, sänka priser, indexuppräkning, KPI, index, importera priser, exportera prislista, excel, csv]
---

# Prisuppdatering

Med en prisuppdatering ändrar du många priser i ett steg: grundpriserna i artikelregistret, priserna i en eller flera prislistor, eller båda. Du väljer hur priserna ändras och från vilket datum, och ser varje nytt pris innan något sparas.

Prisuppdateringen finns under **Register → Prislistor → Prisuppdatering**. Alla som ser priser kan se tidigare uppdateringar; för att göra en behövs rollen ägare, administratör eller redovisningskonsult.

![Formuläret för en ny prisuppdatering, med tidigare uppdateringar nedanför.](/bilder/manual/fakturering/prisuppdatering.webp)

## Så här gör du en prisuppdatering

1. Gå till **Register → Prislistor** och tryck på **Prisuppdatering**.
2. Välj under **Hur priserna ändras**: **Procent**, **Fast belopp**, **Mål för täckningsgrad**, **Indexuppräkning** eller **Från fil**. **Mål för täckningsgrad** syns bara för den som får se inköpspriser.
3. Fyll i **Gäller från**. Det kan vara i dag eller ett datum längre fram.
4. Kryssa i under **Vilka priser**: **Grundpriserna i artikelregistret**, en eller flera prislistor, eller båda.
5. Välj under **Vilka artiklar**: **Alla aktiva artiklar**, **Ett nummerintervall**, **Nummer eller benämning innehåller** eller **Valda artiklar**.
6. Vill du begränsa urvalet väljer du en **Leverantör** eller en **Kundgrupp** (se nedan).
7. Skriv ändringen: **Ändring i procent** (4 höjer med 4 %, -10 sänker med 10 %), **Ändring i kronor**, **Önskad täckningsgrad i procent**, eller **Gammalt indextal** och **Nytt indextal**.
8. Välj **Avrundning**, till exempel hela kronor eller så att priset slutar på 9.
9. Tryck på **Förhandsgranska**. Ingenting är ändrat ännu.

Ett nummerintervall med bara siffror jämförs som tal, så 20 kommer före 100.

## Filtrera på leverantör och kundgrupp

- **Leverantör** begränsar uppdateringen till artiklar vars standardleverantör är den du väljer. Det gäller både grundpriser och prislistor, och går att kombinera med **Vilka artiklar**.
- **Kundgrupp** begränsar uppdateringen till kundgruppens prislista och de kampanjer som riktar sig till gruppen. Kryssar du inte i någon lista tas alla de listorna med; kryssar du i listor måste de höra till gruppen. Grundpriserna tillhör ingen kundgrupp, så kryssa ur **Grundpriserna i artikelregistret** när du väljer en kundgrupp.
- Filtren gäller inte uppdateringar **Från fil**, där filen själv säger vilka priser som ändras.

## Sätt priset från en täckningsgrad

Med **Mål för täckningsgrad** skriver du vilken täckningsgrad priserna ska få, till exempel 30. NONOX räknar då ut priset från artikelns inköpspris så att 30 % av priset exklusive moms blir kvar efter inköpspriset. Det är samma täckningsgrad och samma inköpspris som **Lägsta täckningsgrad** använder, så ett pris du sätter här hamnar aldrig under golvet du valt med samma procent.

- Priset blir inköpspriset delat med (1 minus täckningsgraden). Inköpspris 70 kr och 30 % ger 100 kr exklusive moms.
- I en prislista med priser inklusive moms räknas täckningsgraden på priset utan moms, och sedan läggs momsen på: 100 kr blir 125 kr med 25 % moms.
- **Avrundning** gäller sist, så en avrundning uppåt kan ge en något högre täckningsgrad och en avrundning nedåt en något lägre.
- Täckningsgraden kan vara från 0 upp till men inte med 100 %.
- Priser i en annan valuta än kronor ändras inte, eftersom inköpspriset är i kronor.
- Artiklar som saknar inköpspris (eller har 0 kr) hoppas över. De visas i förhandsgranskningen som **Kan inte ändras** med orsaken *Artikeln saknar inköpspris*.

## Förhandsgranska och genomför

Förhandsgranskningen visar varje pris med det gamla och det nya beloppet, ändringen i procent och från vilket datum det gäller. Överst står hur många priser som ändras, hur många som inte kan ändras och den genomsnittliga ändringen.

![Förhandsgranskningen med gamla och nya priser, och priser som inte kan ändras med orsaken.](/bilder/manual/fakturering/prisuppdatering-forhandsgranskning.webp)

- Under **Visa** väljer du **Priser som ändras**, **Priser som inte kan ändras** eller **Oförändrade och utelämnade**.
- Ta bort krysset under **Med** för ett pris som inte ska ändras.
- En varning under statusen betyder att priset hamnar **Under inköpspris**, **Ändras mer än 25 %** eller att **Avrundningen flyttar mer än 2 %**. Varningarna stoppar inget.
- **Ladda ner som CSV** ger hela listan som fil.

Tryck på **Genomför** när du är nöjd. Du får först se hur många priser som ändras och från vilket datum.

## Så ändras priserna

**Ett pris som redan gäller skrivs aldrig över.** I en prislista läggs det nya priset in som ett nytt pris från datumet du valt, och det gamla gäller till och med dagen före, precis som med **Nytt pris från datum** i listan. Ett pris som slutade ett visst datum får behålla sitt slutdatum i det nya priset. Så kan du alltid se vad varan kostade en viss dag.

**Grundpriserna** ändras i artikelregistret och finns i artikelns prishistorik. Gäller uppdateringen i dag ändras de direkt. Gäller den längre fram står uppdateringen som **Väntar på datum**, och grundpriserna skrivs natten till den dagen. Priserna i prislistorna läggs in direkt med sitt startdatum.

En lista som följer grundpriset med en procent följer med av sig själv. Bara priser du har lagt in i en lista ändras av prisuppdateringen.

Har ett pris ändrats av någon annan efter förhandsgranskningen hoppas det över, och du får veta hur många det gällde.

## Varför kan ett pris inte ändras?

I kolumnen **Status och fel** står orsaken, till exempel:

- **Priset är en rabatt på ordinarie pris och följer det redan.** En rabatt i procent ändras när det ordinarie priset ändras.
- **Ett annat pris börjar redan samma dag.** Ändra det i listan, eller återställ den uppdatering som lade in det.
- **Ett fast belopp i kronor ändrar inte priser i en annan valuta.** Använd en procent för listor i euro.
- **Artikeln saknar inköpspris, så priset kan inte räknas från en täckningsgrad.** Lägg in inköpspriset i artikelregistret och gör om uppdateringen.
- **Priset blir lägre än noll.** En sänkning med ett belopp får inte bli större än priset.

## Indexuppräkning

Har ni avtalat att priserna följer ett index skriver du in det gamla och det nya indextalet. Priserna räknas om med kvoten mellan dem: med 400 och 412,5 blir 100 kr 103,13 kr, före avrundningen. NONOX hämtar inga indextal. Skriv in talen från det index ert avtal hänvisar till, och kontrollera hur avtalet säger att uppräkningen ska göras.

## Priser från en fil

Välj **Från fil** och, under **Filen gäller**, grundpriserna eller en prislista. Filen kan vara CSV eller Excel (.xlsx) med en rubrikrad och de här kolumnerna:

| Kolumn | Måste finnas | Vad den innehåller |
|---|---|---|
| Artikelnummer | ja | Artikelns nummer |
| Pris | ja, eller Rabatt % | Det nya priset i listans valuta, till exempel *1 095,50* |
| Rabatt % | ja, eller Pris | En rabatt på ordinarie pris, bara i en prislista |
| Enhet | nej | Enheten priset gäller, annars artikelns egen |
| Från antal | nej | Priset gäller från det antalet, för en antalstrappa |
| Gäller från | nej | Ett eget startdatum för raden, i dag eller senare, som 2027-01-01 |

Kolumnerna Benämning och Gäller till får finnas i filen men läses inte. Det går bra med decimalkomma och mellanslag som tusentalsavgränsare. En fil får ha högst 10 000 rader med priser och vara högst 12 MB; dela upp en större fil.

Varje rad visas i förhandsgranskningen med radnumret, och en rad som inte kan läsas förklaras, till exempel att artikeln inte finns, att priset inte går att läsa eller att samma artikel finns på en tidigare rad.

> [!TIP]
> Exportera listan först, ändra priserna i ditt kalkylprogram och läs in filen igen. Öppna listan under **Register → Prislistor** och tryck på **CSV** eller **Excel** vid **Exportera:**. Exporten har samma kolumner som inläsningen, med de priser som gäller nu och de som kommer. Ett pris som redan gäller har ingen dag under **Gäller från**, så det nya priset börjar på dagen du väljer i formuläret.

## Återställ en uppdatering

Har du gjort fel trycker du på **Återställ** på uppdateringens sida. Det går till och med dagen uppdateringen börjar gälla. Därefter kan fakturor och offerter redan ha fått de nya priserna, så då gör du en ny uppdatering i stället.

Inget raderas när du återställer. De priser uppdateringen lade in arkiveras, de gamla priserna gäller igen, och grundpriset får tillbaka sitt gamla belopp med en ny rad i historiken. Ett pris som någon annan har ändrat efteråt lämnas som det är.

## Bra att veta

- En prisuppdatering bokför ingenting och ändrar aldrig ett pris på en faktura, offert eller order som redan finns.
- Uppladdade filer sparas med uppdateringen i två år och raderas sedan. Vad som ändrades finns kvar och kan laddas ner som CSV.
- En förhandsgranskning som aldrig genomförs rensas efter sju dagar.
