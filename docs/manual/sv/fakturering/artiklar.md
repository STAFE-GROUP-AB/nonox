---
beskrivning: Lägg upp det du säljer som artiklar med pris, enhet, momssats och intäktskonto, så går fakturor och offerter fort att fylla i.
ordning: 20
roller: [owner, admin, accountant, bookkeeper]
rutter: [invoicing.articles]
sokord: [artikel, artikelregister, produkt, tjänst, pris, prislista, intäktskonto]
---

# Artiklar

En artikel är något du säljer, till exempel *Konsulttimme* eller *Lunch*, med pris och moms. När du skriver artikelnumret på en fakturarad fylls benämning, enhet, pris och moms i av sig själva. Du behöver inte ha artiklar – du kan alltid skriva fria rader direkt på fakturan.

Artiklarna finns under **Register → Artiklar**.

![Artiklarna med pris, moms, konto och hur mycket som sålts i år.](/bilder/manual/fakturering/artiklar.webp)

## Så här lägger du till en artikel

1. Gå till **Register → Artiklar** och tryck på **Ny artikel**.
2. Skriv ett **Artikelnummer**. Du väljer det själv, med siffror eller bokstäver, högst 30 tecken. Samma nummer kan inte användas två gånger.
3. Skriv **Benämning**, till exempel *Konsulttimme*. Den står på fakturaraden.
4. Skriv **Pris exkl. moms**, till exempel *149,00*.
5. Kontrollera **Enhet**. Från början står det *st*; skriv till exempel *tim* eller *kg* om det passar bättre.
6. Välj **Moms**: 25 %, 12 %, 6 % eller **Momsfri**.
7. Låt **Intäktskonto** stå på **Standard**, eller välj ett annat konto mellan 3000 och 3999.
8. Tryck på **Spara**.

## Vilket konto försäljningen bokförs på

Om du låter **Intäktskonto** stå på **Standard** väljer NONOX konto efter momssatsen, som i BAS-kontoplanen:

| Moms | Konto |
|---|---|
| 25 % | 3001 |
| 12 % | 3002 |
| 6 % | 3003 |
| Momsfri | 3004 |

Vill du följa upp en viss sorts försäljning för sig, till exempel catering skilt från lunch, lägger du upp ett eget intäktskonto i [kontoplanen](/manual/bokforing/kontoplan) och väljer det på artikeln.

> [!NOTE]
> Säljer du till ett annat EU-land, exporterar eller fakturerar med omvänd betalningsskyldighet bokförs försäljningen på kontot för just den sortens försäljning, oavsett vad artikeln säger. Läs [Försäljning till andra EU-länder och export](/manual/fakturering/forsaljning-till-utlandet).

## Listan över artiklar

Listan visar nummer, benämning med enhet, pris utan moms, momssats och konto. Kolumnen **Sålt** visar vad artikeln har sålts för i år, utan moms, och hur många enheter. Utkast räknas inte med.

Tryck på **Ändra** för att ändra en artikel. Ett nytt pris gäller fakturor du skriver efter ändringen; fakturor som redan finns behåller sina priser.

## Använda artiklar på en faktura

På fakturan, offerten, ordern eller avtalet skriver du artikelnumret i kolumnen **Artikel**. Webbläsaren föreslår artiklarna medan du skriver. När du lämnar fältet fylls raden i. Du kan sedan ändra benämning, antal, pris, rabatt och moms på just den raden.

## Vanliga frågor

**Kan jag ta bort en artikel?**
Det finns ingen knapp för att ta bort artiklar. Använd inte artikeln om den inte längre säljs; fakturor som redan har den påverkas inte.

**Ska jag skriva priset med eller utan moms?**
Utan moms. NONOX räknar momsen på fakturan.

**Varför får jag "Konto 3002 finns inte i kontoplanen"?**
Standardkontot för momssatsen saknas i företagets kontoplan. Lägg till kontot i kontoplanen, eller välj ett annat intäktskonto på artikeln.

## Läs också

- [Skapa en faktura](/manual/fakturering/skapa-en-faktura)
- [Offerter och order](/manual/fakturering/offerter-och-order)
- [Kontoplanen](/manual/bokforing/kontoplan)
