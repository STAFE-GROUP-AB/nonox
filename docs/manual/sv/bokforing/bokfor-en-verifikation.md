---
beskrivning: Lägg in en verifikation för hand – datum, text, rader i debet och kredit, kontosaldot och bokföringen som ger den ett nummer.
ordning: 10
roller: [owner, admin, accountant, bookkeeper]
rutter: [accounting.vouchers.create, accounting.vouchers.edit]
sokord: [ny verifikation, verifikat, kontera, bokföringsorder, manuell verifikation, debet, kredit, grundbok]
---

# Bokför en verifikation

En [verifikation](/manual/ordlista/ordlista#verifikation) är en bokförd affärshändelse: ett köp, en försäljning, en bankavgift. Den består av minst två rader, där summan av allt i debet är lika stor som summan av allt i kredit. Här lägger du in en verifikation för hand. Fakturor, inbetalningar, leverantörsfakturor, lön och moms bokförs av sig själva när du arbetar i de delarna av NONOX, så dem behöver du inte lägga in här.

![En ny verifikation: datum, vad den gäller, serie och raderna med konto, debet och kredit.](/manual/bilder/bokforing/bokfor-en-verifikation.webp)

## Så här gör du

1. Gå till **Bokföring → Verifikationer** och välj **Ny verifikation**.
2. Kontrollera **Datum**. Det är dagens datum från början; ändra det till dagen då affären hände.
3. Skriv vad det gäller i **Vad gäller det?**, till exempel "Kontorsmaterial, kvitto 4471".
4. Låt **Serie** stå på **A – Manuell**, om du inte har skäl att välja en annan (se [Verifikationsserier](/manual/bokforing/verifikationsserier)).
5. På första raden: skriv kontonumret eller en del av kontonamnet under **Konto** och välj kontot i listan.
6. Skriv beloppet under **Debet** eller **Kredit**. Tryck <kbd>Tab</kbd> eller <kbd>Enter</kbd> för att gå vidare; en ny tom rad dyker upp av sig själv.
7. Fortsätt tills raden under tabellen blir grön och säger **Debet och kredit är lika. Verifikationen kan bokföras.**
8. Lägg till kvittot under **Underlag** – dra in filen eller välj **Fota underlaget** i mobilen (se [Underlag](/manual/bokforing/underlag)).
9. Välj **Bokför** och sedan **Ja, bokför**.

Verifikationen får nu sitt nummer, till exempel A12, och du kommer till verifikationens egen sida. Vill du inte bokföra än väljer du **Spara som utkast** i stället (se [Utkast](/manual/bokforing/utkast)).

> [!WARNING]
> En bokförd verifikation kan aldrig ändras eller tas bort. Blir något fel rättar du det med en ny verifikation, se [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation).

## Exempel: ett kvitto på kontorsmaterial

Café Hörnet köper kontorsmaterial för 500 kr inklusive 25 % moms och betalar med företagskortet från företagskontot.

| Konto | Debet | Kredit |
|---|---:|---:|
| 6110 Kontorsmateriel | 400,00 | |
| 2641 Debiterad ingående moms | 100,00 | |
| 1930 Företagskonto/checkkonto/affärskonto | | 500,00 |

Kostnaden och momsen du får tillbaka står i debet, pengarna som lämnade banken i kredit. Exakt det här kan [konteringsmallen](/manual/bokforing/konteringsmallar) **Inköp med 25 % moms** fylla i åt dig: du skriver 500 och väljer kostnadskontot.

## Kolumnerna i raderna

Varje rad har de här fälten, i den här ordningen:

- **Konto** – skriv nummer eller namn, till exempel "1930" eller "bank", och välj i listan. Bara aktiva konton i [kontoplanen](/manual/bokforing/kontoplan) visas.
- **Kostnadsställe** och **Projekt** – syns bara när företaget har lagt upp kostnadsställen eller projekt (se [Kostnadsställen och projekt](/manual/bokforing/kostnadsstallen-och-projekt)). Fyll i dem på de rader du vill följa upp.
- **Radtext (frivillig)** – en egen text för raden, om verifikationens text inte räcker.
- **Debet** och **Kredit** – beloppet i kronor. Skriv det på en av sidorna, aldrig båda.
- **Kontosaldo** – kontots saldo efter raden. Det fyller du inte i; NONOX räknar ut det.

Belopp kan skrivas som `1234,50`, `1 234,50` eller `1234.50`, med högst två decimaler. Negativa belopp går inte – en minskning skriver du på den andra sidan i stället.

## Kontosaldot medan du skriver

När du har valt ett konto visas **Saldo nu** under fältet: vad kontot har för saldo fram till verifikationens datum, innan den här raden. Kolumnen **Kontosaldo** visar saldot efter raden. Har du samma konto på flera rader räknas de i tur och ordning.

Saldot visas på kontots normala sida: tillgångar och kostnader som debet, eget kapital, skulder och intäkter som kredit. Ett negativt saldo visas i varningsfärg – på 1930 betyder det att bokföringen säger att kontot är övertrasserat, vilket ofta avslöjar ett skrivfel. Bara bokförda verifikationer till och med datumet räknas, inte utkast.

Byter du datum räknas saldona om. Ligger datumet utanför alla räkenskapsår visas inget saldo.

## Raden som säger om det går ihop

Under raderna står hela tiden vad som saknas innan verifikationen kan bokföras:

- **Fyll i minst två rader.**
- **Välj ett konto på varje rad som har ett belopp.**
- **Ett belopp går inte att läsa. Skriv till exempel 1 234,50.**
- **Det skiljer … kr mellan debet och kredit.**
- **Debet och kredit är lika. Verifikationen kan bokföras.**

Knappen **Bokför** går bara att trycka på när raden är grön.

## Det här händer i bokföringen

När du väljer **Ja, bokför** kontrollerar NONOX allt en gång till på servern, i en och samma transaktion:

- att verifikationen har minst två rader och att debet och kredit är lika och större än noll,
- att datumet ligger i ett räkenskapsår som finns och är öppet,
- att datumet inte ligger i en [låst period](/manual/bokforing/las-en-period),
- att alla konton är aktiva.

Går allt igenom får verifikationen nästa lediga nummer i sin serie för räkenskapsåret, och numreringen blir obruten. Den får också en kontrollsumma som binder den till den förra verifikationen (se [Kontroll av bokföringen](/manual/bokforing/kontroll-av-bokforingen)), och bokföringen skrivs i [behandlingshistoriken](/manual/bokforing/behandlingshistorik). Stoppas något visas felet i rött ovanför eller under raderna, och ingenting bokförs.

## Vanliga frågor

**Varför går det inte att trycka på Bokför?**
Raden under tabellen är inte grön än – den säger vad som saknas. Syns knappen inte alls saknar din roll behörighet att bokföra. Spara då som utkast, så kan någon annan bokföra verifikationen.

**Det står "Det finns inget räkenskapsår som omfattar …".**
Datumet ligger före ditt första eller efter ditt sista räkenskapsår. Skapa nästa år under **Bokföring → Räkenskapsår**, se [Räkenskapsår](/manual/bokforing/rakenskapsar).

**Det står "Perioden till och med … är låst".**
Perioden är stängd, oftast för att momsen för den är deklarerad. Bokför på ett senare datum, se [Lås en period](/manual/bokforing/las-en-period).

**Kontot jag behöver finns inte i listan.**
Det är inte upplagt eller är inaktiverat. Lägg till det under **Bokföring → Kontoplan**, eller be någon med rätt behörighet att göra det.

**Kan jag lämna sidan mitt i?**
Ja, men det du skrivit sparas inte. Trycker du <kbd>Esc</kbd> med ifyllda rader frågar NONOX först **Lämna utan att spara?**. Välj **Spara som utkast** om du vill fortsätta senare.

## Läs också

- [Konteringsmallar](/manual/bokforing/konteringsmallar)
- [Kortkommandon och arbete i mobilen](/manual/bokforing/kortkommandon)
- [Underlag](/manual/bokforing/underlag)
- [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation)
- [Kontoanalys och huvudbok](/manual/rapporter/kontoanalys-och-huvudbok)
