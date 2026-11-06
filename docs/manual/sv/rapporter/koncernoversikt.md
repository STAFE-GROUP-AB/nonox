---
beskrivning: Resultat och balans för flera av dina företag bredvid varandra med en summa, och förslag på vad som kan elimineras. Ett internt underlag, inte en koncernredovisning.
ordning: 80
roller: [owner, admin, accountant, bookkeeper, auditor]
rutter: [group.overview]
sokord: [koncern, koncernöversikt, holdingbolag, dotterbolag, flera bolag, koncernintern, eliminering, ägarandel, sammanställning]
---

# Koncernöversikt

**Koncernöversikt** är för dig som äger flera företag, till exempel ett holdingbolag och några dotterbolag. Du samlar företagen i en egen **koncern** och ser deras resultat och balans bredvid varandra, med en summa längst till höger.

> [!IMPORTANT]
> Koncernöversikten är en **intern översikt för ditt eget underlag**. Den är ingen koncernredovisning enligt årsredovisningslagen. Ingenting konsolideras, ingenting bokförs och inget i företagens bokföring ändras. Sidan säger det överst, även när du skriver ut den.

Du öppnar sidan under **Mina företag → Koncernöversikt** i sidomenyn, eller med **Koncernöversikt** i företagsmenyn. Menyvalet finns när du är med i fler än ett företag.

## Du ser bara det du får se

En koncern är din egen lista med företag. Den ger dig ingen tillgång till något nytt:

- Ett företag visas bara om du är med i det **och** din roll där får se rapporter. Det gäller ägare, administratörer, redovisningskonsulter, bokförare och revisorer.
- Tas du bort ur ett företag, eller byter till en roll som inte får se rapporter, försvinner företaget ur koncernen direkt. Sidan skriver då **Ett företag i koncernen visas inte** utan att säga vilket.
- Ingen annan ser dina koncerner.

## Så här skapar du en koncern

1. Skriv ett namn under **Ny koncern** och välj **Skapa koncern**.
2. Du kommer till fliken **Koncernen och markeringar**. Välj ett företag under **Lägg till företag** och välj **Lägg till**. Upprepa för varje företag.
3. Skriv vid behov in **Ägarandel, procent (frivilligt)**. Andelen visas bara under företagets namn i tabellerna. Den påverkar inga belopp.
4. Gå till fliken **Resultat** eller **Balans**.

Du byter namn med **Spara namnet**. **Ta bort koncernen** tar bara bort din lista och dina markeringar, aldrig något i bokföringen.

## Resultat och balans

Välj flik: **Resultat**, **Balans** eller **Elimineringsförslag**. Överst väljer du:

- **Räkenskapsår** – alla räkenskapsår som något av företagen har, med hur många företag som har det. Förvalt är det år som flest företag har.
- **Till och med** – sista dagen i perioden. Perioden börjar alltid när räkenskapsåret börjar.

Tabellen har en kolumn per företag och **Summa** längst till höger. Kontona är grupperade som i [resultatrapporten](/manual/rapporter/resultatrapport) och [balansrapporten](/manual/rapporter/balansrapport). Företagen har sina egna kontoplaner, så kontona läggs ihop på kontonummer.

På balansen finns raden **Resultat som ännu inte är bokfört mot eget kapital**, precis som i balansrapporten, så att tillgångarna och eget kapital med skulder är lika stora i varje företag.

> [!NOTE]
> Alla företag i summan måste ha samma räkenskapsår, med samma första och sista dag. Ett företag med ett annat räkenskapsår tas inte med i siffrorna. Det står i stället under **Dessa företag ingår inte i siffrorna** med orsaken, och du kan välja det andra året i listan **Räkenskapsår**.

## Koncerninterna konton och elimineringsförslag

Företag i en koncern har fordringar, skulder, försäljning och andelar mellan sig. I en sammanställning räknas de dubbelt om man inte tar hänsyn till dem. Du markerar kontona, och NONOX räknar ut vad som skulle kunna elimineras.

Under **Koncernen och markeringar → Koncerninterna konton** väljer du **Företag**, **Konto** och **Vad kontot innehåller**, och sedan **Markera konto**:

| Innehåll | Exempel på konto |
|---|---|
| **Andelar i koncernföretag** | 1310 Andelar i koncernföretag |
| **Fordran på koncernföretag** | Konto för fordringar hos koncernföretag |
| **Skuld till koncernföretag** | Konto för skulder till koncernföretag |
| **Eget kapital i dotterföretag** | 2081 Aktiekapital i dotterbolaget |
| **Försäljning till koncernföretag** | Intäktskontot du använder för försäljning till bolag i koncernen |
| **Inköp från koncernföretag** | Kostnadskontot du använder för inköp från bolag i koncernen |

**Föreslå markeringar** markerar åt dig de konton vars namn innehåller "koncern". Granska dem och ta bort det som inte stämmer med **Ta bort**.

På fliken **Elimineringsförslag** står tre par:

| Par | Jämförs |
|---|---|
| **Koncerninterna fordringar och skulder** | Fordringar mot skulder |
| **Koncernintern försäljning och inköp** | Försäljning mot inköp |
| **Andelar och eget kapital i dotterföretag** | Andelar mot dotterföretagens eget kapital |

För varje par visas båda sidorna, **Kan elimineras** (det mindre beloppet) och **Skillnad**. En skillnad betyder att företagen inte har bokfört samma belopp, eller att beloppen har olika tidpunkter, och den bör du reda ut. Under **Summor före och efter förslagen** ser du tillgångar, eget kapital och skulder och rörelsens intäkter med och utan förslagen.

> [!WARNING]
> Elimineringsförslagen är bara förslag. De bokförs inte, och NONOX avgör inte om en skillnad är en internvinst, en goodwill eller ett fel. Det bedömer du, eller din redovisningskonsult.

## Hämta och skriv ut

- **Hämta som CSV** ger en fil som Excel öppnar direkt, med samma kolumner som på skärmen.
- **Skriv ut** skriver ut sidan utan menyer och val. Varningen om att det är en intern översikt följer med.

## Bra att veta

- Bara bokförda verifikationer räknas, som i alla rapporter.
- Dina val sparas i adressen, så du kan bokmärka en vy.
- Alla val går att göra med tangentbordet.

## Läs också

- [Resultatrapporten](/manual/rapporter/resultatrapport)
- [Balansrapporten](/manual/rapporter/balansrapport)
- [Mina företag](/manual/byra/mina-foretag)
