---
beskrivning: Lägg en prognos för efterfrågan per artikel och vecka eller månad, skriv in den själv eller låt NONOX räkna fram den ur lagrets historik, och sätt ledtid, säkerhetslager och partistorlek på artikeln.
ordning: 29
roller: [owner, admin, accountant, bookkeeper, auditor]
modul: inventory
rutter: [inventory.forecast]
sokord: [prognos, efterfrågan, behovsplanering, MRP, ledtid, säkerhetslager, partistorlek, partimultipel, minsta parti, planeringsmetod, vecka, historik]
---

# Prognos och planeringsdata

Prognosen visar hur mycket du räknar med att sälja eller använda av varje lagerförd artikel, vecka för vecka eller månad för månad. Du skriver in den själv, eller låter NONOX räkna fram den ur vad som har tagits ut ur lagret. Prognosen är underlaget för behovsplaneringen. Den skapar inga beställningar och bokför ingenting.

![Prognosen för de kommande 13 veckorna: ett värde per artikel och vecka, med genomsnittet ur historiken i kolumnen Snitt per vecka.](/bilder/manual/lager/prognos.webp)

## Så här läser du prognosen

Gå till **Lager → Prognos**. Varje rad är en lagerförd artikel och varje kolumn en vecka. Visningen är 13 veckor som standard. Du kan visa upp till 52 veckor, eller byta till **Per månad** (högst 12 månader).

- Mängderna står i artikelns lagerenhet.
- En ruta med vit bakgrund är **Inskrivet**: någon har skrivit värdet.
- En ruta med blå bakgrund är **Beräknat från historik**.
- **Snitt per vecka** (eller månad) är det NONOX skulle räkna fram ur historiken just nu, så att du kan jämföra med det du har skrivit.
- **Summa** är allt i de perioder du ser.

## Så här skriver du in prognosen

1. Gå till **Lager → Prognos**.
2. Sök fram artikeln om du vill, och välj **Per vecka** eller **Per månad**.
3. Skriv antalet i rutan för perioden, till exempel `24` eller `2,5`.
4. Lämna rutan. Värdet sparas direkt och du får meddelandet **Prognosen är sparad.**
5. Töm rutan för att ta bort värdet. Du får då meddelandet **Prognosen är borttagen.**

Du kan lägga prognos från den här veckan (eller månaden) och 52 veckor, eller 12 månader, framåt. En vecka börjar på en måndag. Bara lagerförda artiklar kan ha prognos.

Skriver du över ett beräknat värde blir det inskrivet. Finns det både ett veckovärde och ett månadsvärde för samma tid gäller veckovärdet.

## Så här räknar NONOX fram prognosen ur historiken

1. Gå till **Lager → Prognos** och sök fram de artiklar du vill räkna för. Tom sökning gäller alla lagerförda artiklar.
2. Ange **Historik att räkna på, månader**. Standard är 3.
3. Kryssa i **Skriv över även inskrivna värden** bara om du vill ersätta det du själv har skrivit.
4. Klicka på **Beräkna från historik** och bekräfta.

NONOX tar de senaste hela månaderna före den pågående månaden. Det som har tagits ut genom plock, kassaförsäljning, receptförsäljning och uttag till tillverkning räknas ihop, minus det som kommit tillbaka genom samma slags rörelser. Flytt, kassation, eget bruk, arbetsorder, returer och inventering räknas inte som efterfrågan. Summan delas med antalet dagar i perioden och multipliceras med 7 för en vecka, eller delas med antalet månader för en månad, och avrundas till närmaste tusendel. Du kan räkna efter själv.

Alla perioder i visningen får samma värde. En artikel som inte har något uttag i perioden får inga beräknade värden, och beräknade värden som fanns tas bort. Inskrivna värden behålls om du inte har kryssat i att de ska skrivas över. Är det fler än 2 000 artiklar i urvalet ber NONOX dig söka fram färre.

> [!NOTE]
> Genomsnittet tar inte hänsyn till säsong eller trend. Skriv in säsongen själv, till exempel julförsäljningen, och låt historiken fylla resten.

## Så här anger du planeringsdata på artikeln

1. Öppna artikeln under **Register → Artiklar** och välj fliken **Avancerad**.
2. Kryssa i **Lagerförd**. Då visas **Planeringsdata**.
3. Välj **Planeringsmetod**: **Ingen planering**, **Beställningspunkt** eller **Behovsplanering (MRP)**. Alla artiklar börjar på Ingen planering.
4. Ange **Ledtid, dagar**: från beställning eller start till färdig vara i lager, 0 till 365 dagar.
5. Ange **Säkerhetslager**: saldot du vill ha kvar som buffert.
6. Ange **Minsta parti** och **Partimultipel** om du bara kan beställa eller tillverka vissa mängder. Med en multipel på 12 beställs 12, 24, 36 och så vidare. Tomt betyder ingen gräns.
7. Klicka på **Spara**.

![Planeringsdata på artikelns flik Avancerad: planeringsmetod, ledtid, säkerhetslager, minsta parti och partimultipel.](/bilder/manual/lager/planeringsdata.webp)

Mängderna står i artikelns lagerenhet. Planeringsdata ändrar inget i inköpsförslaget: [Inköpsförslag](/manual/lager/inkopsforslag) räknar som förut med beställningspunkt och beställ antal. Artiklar med planeringsmetoden **Behovsplanering (MRP)** planeras vecka för vecka under [Behovsplanering](/manual/lager/behovsplanering).

## Vem får göra vad

| Roll | Se prognosen och planeringsdata | Ändra, räkna fram ur historik |
|---|---|---|
| **Ägare** | Ja | Ja |
| **Administratör** | Ja | Ja |
| **Redovisningskonsult** | Ja | Nej |
| **Bokförare** | Ja | Ja |
| **Revisor** | Ja | Nej |

Den som bara får se kan öppna prognosen och planeringsfälten på artikeln, men rutorna är låsta.

## Det här händer i bokföringen

Ingenting. Prognosen och planeringsdata är inte bokföring och skapar inga verifikationer.

## Bra att veta

- Prognosen sparas per företag. Ett annat företag med samma artikelnummer har sin egen.
- Varje ändring av prognosen sparas i behandlingshistoriken med artikel, period och mängd, men inga namn.
- Prognosen tas inte bort när en vecka har gått. Gamla veckor visas inte, men de finns kvar.

## Läs också

- [Artiklar i lager](/manual/lager/artiklar)
- [Behovsplanering](/manual/lager/behovsplanering)
- [Inköpsförslag](/manual/lager/inkopsforslag)
- [Roller och behörigheter](/manual/lager/roller-och-behorigheter)
