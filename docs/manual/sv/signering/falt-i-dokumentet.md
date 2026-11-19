---
beskrivning: Placera fält för underskrift, datum, namn och andra uppgifter i PDF-dokumentet innan du skickar det, med musen, med tangentbordet eller med ett formulär i millimeter.
ordning: 15
roller: [owner, admin, accountant, bookkeeper, sales]
modul: signing
rutter: [signing.new]
sokord: [fält, fält i dokumentet, underskriftsfält, initialer, datum, namn, kryssruta, lista, alternativ, flytta fält, dra, tangentbord, placera, obligatoriskt]
---

# Fält i dokumentet

I guiden **Skicka dokument** kan du placera fält direkt i PDF-dokumentet: var undertecknaren ska skriva under, datera, skriva sitt namn eller fylla i något annat. Steget heter **Fält i dokumentet** och kommer efter **Undertecknare**. Det är frivilligt. Utan fält skriver undertecknarna under på sidan under dokumentet, som förut.

![Steget Fält i dokumentet, med en underskrift och ett datum placerade på sidan.](/bilder/manual/signering/falt-i-dokumentet.webp)

## Så här gör du

1. Gå igenom guiden till **Undertecknare** och skriv **E-post** för var och en. Tryck på **Nästa**.
2. Under **Fält i dokumentet** visas dokumentets sidor. Välj **Typ**, **Undertecknare**, **Sida** och **Plats på sidan** under **Lägg till fält** och tryck på **Lägg till fält**. Fältet hamnar på platsen och är markerat.
3. Du kan också dra en fälttyp från listan **Fälttyper att dra till sidan** och släppa den på sidan. Välj först **Undertecknare** under **Lägg till fält**: fältet hör till den som är vald där.
4. Flytta fältet genom att dra i det. Dra i det mörka hörnet nere till höger för att ändra storleken.
5. Under **Fält 1** (eller det nummer fältet har) ändrar du **Fältets namn**, **Undertecknare** och **Sida**, och väljer om fältet är obligatoriskt. Fältets namn visas för undertecknaren.
6. Tryck på **Nästa** och gå vidare som vanligt. Under **Granska och skicka** står hur många fält som placerats.

När dokumentet är skickat går fälten inte att ändra. Behöver något ändras drar du tillbaka signeringen och skickar dokumentet på nytt.

## Fälttyper

| Typ | Vem fyller i | Vad undertecknaren gör |
|---|---|---|
| Underskrift | Undertecknaren | Ritar sin underskrift eller skriver sitt namn |
| Initialer | Undertecknaren | Ritar eller skriver sina initialer |
| Datum | NONOX | Ingenting. Fältet fylls med signeringsdagen, ÅÅÅÅ-MM-DD |
| Namn | NONOX | Ingenting. Fältet fylls med namnet undertecknaren anger |
| Text | Undertecknaren | Skriver en text, högst 500 tecken och aldrig mer än som får plats i rutan |
| Tal | Undertecknaren | Skriver ett tal, med de gränser du anger |
| Kryssruta | Undertecknaren | Kryssar i eller låter bli |
| Lista | Undertecknaren | Väljer ett av dina alternativ |
| Alternativ | Undertecknaren | Väljer ett av flera alternativ som hör ihop |

Varje fält hör till en undertecknare. Det är bara hen som kan fylla i det. Undertecknare som signerar senare ser tidigare undertecknares ifyllda fält, låsta, och sina egna fält som tomma rutor.

## Inställningar per typ

- **Obligatoriskt: undertecknaren måste fylla i fältet.** Ibockat som standard. Datum och Namn fylls i av NONOX och har ingen sådan ruta.
- **Text:** **Längsta text (tecken)**, högst 500.
- **Tal:** **Minst**, **Högst** och **Decimaler**, 0 till 6.
- **Lista:** **Alternativ, ett per rad**, 1 till 30 olika alternativ på högst 100 tecken.
- **Alternativ:** ett alternativ är en knapp. Alternativ med samma **Grupp** är ett val: undertecknaren väljer ett av dem. Alla alternativ i en grupp hör till samma undertecknare och har olika **Alternativets värde**. Skriv samma grupp, bokstäver, siffror, - eller _, på varje knapp som ska höra ihop.

## Varje undertecknare behöver en underskrift

Har du placerat fält måste varje undertecknare ha minst ett fält av typen **Underskrift**. Annars stannar guiden vid steget och säger vilken undertecknare som saknar ett, och samma krav gäller när fält skickas med på annat sätt. Du kan placera högst 200 fält i ett dokument.

## Utan mus: tangentbord och formulär

Allt som går att göra med musen går också att göra utan den. På en mobil ligger sidorna först och formuläret under dem.

![Steget Fält i dokumentet på en mobil.](/bilder/manual/signering/falt-i-dokumentet-mobil.webp)

- Tabba till ett fält på sidan. **Piltangenterna** flyttar det 1 mm, och **Skift** med en piltangent flyttar det 10 mm. **Alt** med en piltangent ändrar storleken: höger och ned gör fältet större. **Delete** tar bort fältet. En skärmläsare får höra var fältet hamnade.
- Under **Fält 1** (eller fältets nummer) finns **Plats och storlek (millimeter)** med **Från vänster**, **Från överkanten**, **Bredd** och **Höjd**. Måtten räknas från sidans övre vänstra hörn. Skriv ett mått och lämna fältet, så flyttas fältet.
- Under **Flytta fältet** finns knappar som flyttar fältet 1 eller 10 mm åt valfritt håll.
- Under **Fälten** står alla fält i en lista. Tryck på ett fälts namn för att välja det, eller på **Ta bort** för att ta bort det.

Fältet hålls alltid inom sidan och blir aldrig mindre än typen kräver. Ett datum måste ha plats för hela datumet, och ett namn eller en underskrift för ett vanligt namn.

## Det här kan du behöva veta

- **Sidorna visas i din webbläsare.** NONOX visar dem med en inbyggd läsare som hämtas från NONOX självt, inte från något annat ställe. Går det inte att visa sidorna står det att förhandsvisningen inte kunde laddas, och du kan fortfarande placera fält med formuläret.
- **Kräver PDF-motorn.** Sidornas mått mäts av PDF-motorn. Saknas den på den här installationen står det **Fält i dokumentet kräver PDF-motorn**, och dokumentet kan skickas utan fält. Se [egen drift](/egen-drift).
- **Roterade sidor.** På en sida som är roterad i PDF-filen går det inte att placera fält. Sidan är markerad med **roterad, inga fält kan placeras här**.
- **Offerter och avtal.** Fält fungerar också när du skickar en offert eller ett avtal med **Fler undertecknare**, så länge modulen Signering är påslagen. Utan fält ser undertecknaren sidan som förut.

Om initialer på varje sida ger ett högre bevisvärde är inte klarlagt. Kontrollera med din rådgivare om du behöver dem av det skälet; i NONOX är de en bekvämlighet.

## Läs också

- [Skicka ett dokument för signering](/manual/signering/skicka-dokument)
- [Alla signeringar](/manual/signering/alla-signeringar)
- [Signering](/manual/signering/oversikt)
