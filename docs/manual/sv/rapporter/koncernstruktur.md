---
beskrivning: Moderföretag, dotterföretag och vem som äger vad, med kapital, röster, datum och räkenskapsårens status. Här skapar du koncernen, registrerar innehav och ger rollen att läsa sammanställningen.
ordning: 85
roller: [owner, admin, accountant, auditor]
rutter: [group.corporate]
sokord: [koncern, koncernstruktur, moderföretag, dotterföretag, innehav, ägarandel, kapital, röster, bestämmande inflytande, förvärv, avyttring, gör om till koncern, koncernroll]
---

# Koncernstruktur

**Koncernstruktur** visar en koncern som flera företag delar: vem som är moderföretag, vilka företag som ingår och vem som äger vad. Du öppnar sidan under **Mina företag → Koncernstruktur** i sidomenyn. Menyvalet finns när du är med i fler än ett företag, och när du har rätt att se en koncern, till exempel genom rollen att läsa dess sammanställning.

![Koncernstruktur: moderföretaget och dotterföretagen med ägande, datum och räkenskapsårens status.](/bilder/manual/rapporter/koncernstruktur.webp)

> [!NOTE]
> NONOX prövar inte om företagen utgör en koncern enligt aktiebolagslagen eller årsredovisningslagen. Strukturen bygger på de innehav ni själva har registrerat.

> [!NOTE]
> Strukturen är underlag för koncernens interna rapporter. Den ändrar ingenting i något företags bokföring och ger ingen koncernredovisning enligt årsredovisningslagen.

Koncernstruktur är något annat än [Koncernöversikt](/manual/rapporter/koncernoversikt). Koncernöversikten är din egen lista med företag och finns bara för dig. En koncern i Koncernstruktur delas av alla företag i den, och vem som får se vad avgörs av behörigheterna i företagen.

## Vem ser koncernen

En koncern syns bara för dig i två fall:

- Du får se rapporter i **alla** företag som ingår. Det gäller ägare, administratörer, redovisningskonsulter, bokförare och revisorer. Är du med i bara några av företagen, eller har en roll som inte får se rapporter i ett av dem, ser du inte koncernen alls, och sidan avslöjar inte ens att den finns.
- Du har fått rollen att läsa koncernens sammanställning och är fortfarande med i moderföretaget. Då ser du företagens namn, vem som äger vad och ägd andel av kapitalet, men inga uppgifter om enskilda företag: inga organisationsnummer, röster, datum eller räkenskapsår.

Åtkomsten räknas om varje gång du öppnar sidan. Lämnar du ett företag, eller byter till en roll som inte får se rapporter, försvinner koncernen direkt.

## Tabellen

Tabellen visar moderföretaget överst och dotterföretagen under det företag som äger dem. För varje företag står:

| Kolumn | Betyder |
|---|---|
| **Ägs av** | Vilket företag i koncernen som äger det |
| **Kapital** | Ägarens andel av kapitalet |
| **Röster** | Ägarens andel av rösterna |
| **Bestämmande inflytande** | Vad det bestämmande inflytandet grundar sig på. Det anger en person, och det räknas inte fram av rösterna |
| **Förvärvat** och **Avyttrat** | Förvärvsdagen och avyttringsdagen, om innehavet har avyttrats |
| **Räkenskapsår** | Det räkenskapsår företaget är i nu, med **Öppet** eller **Stängt** |
| **Låst till** | Sista dagen som är låst för bokföring, eller **Ingen låsning** |

Ett innehav med **Inget bestämmande inflytande** markeras på raden med **Inte dotterföretag genom detta innehav**, även för den som bara läser sammanställningen.

Ett avyttrat innehav ligger kvar i listan med sitt datum. Ett företag som är avyttrat räknas inte med i perioder efter avyttringen.

## Skapa en koncern

Du måste vara ägare eller administratör i moderföretaget.

1. Skriv ett namn under **Ny koncern**, välj **Moderföretag** och välj **Skapa koncern**.
2. Registrera innehaven i dotterföretagen, se nedan.
3. Lägg till företagen med **Lägg till**.

Ett företag kan vara moderföretag i en enda koncern.

### Registrera innehav

Ett företag kan läggas till först när ett företag i koncernen har ett registrerat innehav i det. Under **Registrera innehav** väljer du:

1. **Ägare**, det företag som äger aktierna. Du måste ha behörighet att ändra företagets uppgifter.
2. **Ägt företag**.
3. **Andel av kapitalet, procent** och **Andel av rösterna, procent**.
4. **Bestämmande inflytande**, vad inflytandet grundar sig på. Välj **Inget bestämmande inflytande** om innehavet inte ger det.
5. **Förvärvsdag** och, om innehavet har avyttrats, **Avyttringsdag (frivilligt)**.

Välj **Spara innehav**. Finns redan ett innehav i samma företag ändras det i stället. Innehavet skrivs i det ägande företagets eget register.

### Lägg till och ta bort företag

Under **Lägg till företag** väljer du företaget och **Lägg till**. Du måste vara ägare eller administratör i företaget och i alla företag som redan ingår. Företaget måste redovisa i samma valuta som moderföretaget, och innehaven i det får inte tillsammans ge mer än 100 procent av rösterna.

Du tar bort ett företag med **Ta bort** på raden, och bekräftar att sammanställningen då räknas utan företaget. Moderföretaget kan inte tas bort, och ett företag som äger andra i koncernen tas bort först när de är borta.

## Gör om en tidigare lista till koncern

Har du en egen lista under [Koncernöversikt](/manual/rapporter/koncernoversikt) kan du göra om den. Under **Gör om en tidigare lista till koncern** väljer du **Lista** och **Moderföretag** och sedan **Gör om till koncern**. Koncernen får listans namn och innehåller först bara moderföretaget. Registrera innehaven och lägg till övriga företag som ovan. Listan finns kvar och inget i bokföringen ändras.

## Vem får läsa sammanställningen

Under **Vem får läsa sammanställningen** ger du en person i moderföretaget rollen att läsa koncernens sammanställning, med **Ge rollen**. Rollen ger aldrig tillgång till något enskilt företag. Du tar bort den med **Ta bort**.

> [!WARNING]
> Den som läser sammanställningen och även ser moderföretagets egna rapporter kan räkna ut dotterföretagens summor. Ge rollen bara till den du litar på med det.

Rollen gäller bara så länge personen är med i moderföretaget. Lämnar personen företaget och kommer tillbaka måste rollen ges på nytt.

## Bra att veta

- Skapa, lägga till och ta bort företag, registrera innehav och ge roller kan bara göras av den som är ägare eller administratör i alla berörda företag. Övriga ser tabellen men inte knapparna.
- Varje ändring skrivs i behandlingshistoriken i de berörda företagen, utan att nämna de andra företagen.
- Alla val går att göra med tangentbordet.

## Läs också

- [Koncernöversikt](/manual/rapporter/koncernoversikt)
- [Mina företag](/manual/byra/mina-foretag)
