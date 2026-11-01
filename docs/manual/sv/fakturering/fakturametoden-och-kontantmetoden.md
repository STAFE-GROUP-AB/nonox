---
beskrivning: Hur NONOX bokför kundfakturor och inbetalningar med fakturametoden och med kontantmetoden, och vilka konton och verifikationsserier som används.
ordning: 50
roller: [owner, admin, accountant, bookkeeper]
sokord: [bokföringsmetod, fakturametod, kontantmetod, bokslutsmetod, kundfordringar, 1510, serie B, serie C]
---

# Fakturametoden och kontantmetoden

Du bokför aldrig en kundfaktura för hand i NONOX. Fakturan och betalningen bokförs automatiskt – men *när* det sker beror på vilken bokföringsmetod företaget har. Metoden väljer du under **Inställningar för företaget**, i fältet **Bokföringsmetod** under *Moms och bokföring*.

> [!IMPORTANT]
> Vilken metod företaget får använda styrs av Bokföringslagen och Skatteverkets regler, inte av NONOX. Är du osäker, fråga din redovisningskonsult.

## Fakturametoden

Med fakturametoden bokförs fakturan när den skapas, och betalningen när den kommer.

**När fakturan skapas** – en verifikation i serie B (Kundfakturor), daterad på fakturadatumet:

| Konto | Debet | Kredit |
|---|---|---|
| 1510 Kundfordringar | hela beloppet | |
| Intäktskontot, till exempel 3001 | | beloppet utan moms |
| 2611 Utgående moms 25 % (2621 för 12 %, 2631 för 6 %) | | momsen |
| 3740 Öres- och kronutjämning | | öresavrundningen |

Öresavrundningen kan också hamna på debetsidan om totalen avrundades nedåt.

**När betalningen kommer** – en verifikation i serie C (Kundinbetalningar), daterad på betaldagen:

| Konto | Debet | Kredit |
|---|---|---|
| 1930 Företagskonto/checkkonto/affärskonto | det som betalats | |
| 1510 Kundfordringar | | det som betalats |

## Kontantmetoden

Med kontantmetoden bokförs ingenting när fakturan skapas. På fakturans sida står det *Vid betalning (kontantmetoden)* under **Bokförd**.

**När betalningen kommer** – en verifikation i serie C, daterad på betaldagen. Bankkontot debiteras, och intäkt, moms och öresavrundning krediteras direkt. Betalar kunden bara en del fördelar NONOX betalningen på intäkt och moms i samma proportion som på fakturan.

> [!WARNING]
> Med kontantmetoden ska fakturor som är obetalda när räkenskapsåret slutar bokföras som fordringar i bokslutet. NONOX gör inte det automatiskt i dag. Gå igenom obetalda fakturor vid årets slut och kontrollera med din redovisningskonsult hur de ska bokföras.

## Gäller för alla fakturor

- En kreditfaktura bokförs på samma sätt men med omvända tecken, så att den tar ut originalet.
- Försäljning till andra EU-länder, export och omvänd betalningsskyldighet bokförs på sina egna intäktskonton utan svensk moms. Läs [Försäljning till andra EU-länder och export](/manual/fakturering/forsaljning-till-utlandet) och [Omvänd betalningsskyldighet för byggtjänster](/manual/fakturering/omvand-betalningsskyldighet-bygg).
- Ett ROT- eller RUT-avdrag bokförs som en fordran på Skatteverket på 1513. Läs [ROT- och RUT-avdrag](/manual/fakturering/rot-och-rut).
- Verifikationerna är vanliga bokförda verifikationer. De går inte att ändra eller ta bort; de syns i verifikationslistan och har en länk tillbaka till fakturan.

## Vanliga frågor

**Kan jag byta metod mitt i året?**
Fältet går att ändra i inställningarna, men fakturor som redan är skapade bokförs enligt metoden som gällde då. Byt inte metod utan att ha pratat med din redovisningskonsult.

**Betalningen kom in på ett annat bankkonto än 1930.**
Betalningar som registreras på fakturan eller läses in från bankens fil bokförs på 1930. Kom pengarna till ett annat konto bokför du en flytt mellan kontona med en vanlig verifikation. Läs [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation).

## Läs också

- [Skapa en faktura](/manual/fakturering/skapa-en-faktura)
- [Inbetalningar](/manual/fakturering/inbetalningar)
- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
- [Kontoanalys och huvudbok](/manual/rapporter/kontoanalys-och-huvudbok)
