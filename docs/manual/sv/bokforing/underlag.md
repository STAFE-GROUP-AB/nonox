---
beskrivning: Spara kvitton och fakturor bredvid verifikationen – dra in en fil, fota med mobilen eller låt NONOX AI läsa underlaget och föreslå raderna.
ordning: 50
roller: [owner, admin, accountant, bookkeeper]
rutter: [accounting.vouchers.attachment]
sokord: [kvitto, bilaga, verifikationsunderlag, fota kvitto, ladda upp, pdf, AI, tolka kvitto, skanna]
---

# Underlag

Underlaget är kvittot, fakturan eller avtalet som visar vad som har hänt. Varje verifikation ska ha ett, och i NONOX sparas det direkt på verifikationen. Du kan dra in en fil, fota kvittot med mobilen och – om företaget har NONOX AI – låta underlaget fylla i verifikationen åt dig.

## Så här lägger du till underlag när du bokför

1. Öppna en ny verifikation eller ett utkast.
2. Under **Underlag**: dra in filen i rutan, eller välj **välj filer**. I mobilen kan du i stället välja **Fota underlaget**, som öppnar kameran.
3. Filen visas i listan med texten **Sparas när du sparar eller bokför**.
4. Bokför eller spara som utkast. Då sparas filen på verifikationen.

Du kan lägga till flera filer på samma verifikation. I ett utkast som redan är sparat sparas en ny fil direkt, och du får beskedet **Underlaget … är sparat.**

## Vilka filer som fungerar

- PDF eller bild i formaten JPG, PNG eller WebP.
- Högst 10 MB per fil.

NONOX kontrollerar vad filen faktiskt innehåller, inte bara vad den heter. En fil som inte är en PDF eller en bild stoppas med **Underlaget måste vara en PDF eller en bild (JPG, PNG eller WebP).** Stoppas en fil när du bokför, bokförs inte heller verifikationen. Den blir alltså aldrig bokförd utan sitt kvitto av misstag.

## Underlaget på en bokförd verifikation

Öppna verifikationen från **Bokföring → Verifikationer**. Underlaget visas bredvid raderna: bilder och PDF:er direkt på sidan, med **Öppna i nytt fönster** under. Har verifikationen flera filer väljer du vilken du vill se i listan.

Under förhandsvisningen står filens **Kontrollsumma**. Den räknades ut när filen sparades och visar att filen är densamma i dag. Den nattliga [kontrollen av bokföringen](/manual/bokforing/kontroll-av-bokforingen) jämför den med filen.

### Lägg till ett kvitto som kom senare

Har du bokfört utan underlag kan du lägga till det efteråt: öppna verifikationen och dra in filen i rutan **Dra hit en fil, eller välj fil** i underlagspanelen.

> [!NOTE]
> Att underlag får läggas till på en redan bokförd verifikation, så länge verifikationen själv är oförändrad, är ännu inte juridiskt kontrollerat av NONOX. Fråga din redovisningskonsult om du är osäker.

### Ta bort underlag

På ett utkast väljer du **Ta bort** bredvid filen. På en bokförd verifikation går det inte: underlaget är en del av räkenskapsinformationen och sparas så länge bokföringen sparas. Har du lagt till fel fil, lägg till den rätta också. Båda finns kvar, och behandlingshistoriken visar vem som lade till vad och när.

## NONOX AI läser underlaget

När modulen NONOX AI är påslagen för företaget, och din roll får använda den, läses underlaget så fort det är uppladdat eller fotograferat.

1. Dra in eller fota underlaget i en ny verifikation.
2. Rutan **NONOX AI** räknar ned medan underlaget analyseras. Det brukar ta högst 20 sekunder.
3. Datum, text och rader fylls i med AI:ns förslag, och du får beskedet **NONOX AI har föreslagit verifikationen. Kontrollera konton och belopp innan du sparar eller bokför.**
4. Jämför varje uppgift med underlaget, ändra det som behövs och bokför.

AI:n sparar och bokför aldrig något själv – den fyller bara i formuläret. Har den kommentarer, till exempel om något den inte kunde läsa, står de i rutan. Vill du låta den försöka igen väljer du **Analysera**.

Ser underlaget ut som en obetald leverantörsfaktura säger rutan det och erbjuder **Registrera som leverantörsfaktura**. Då flyttas filen till en ny leverantörsfaktura i stället, där den får förfallodag, betalning och leverantör. Bokför den bara som verifikation om den redan är betald. Se [Registrera en leverantörsfaktura](/manual/leverantorer/registrera-en-leverantorsfaktura).

Hur modulen slås på och vilken AI-nyckel som används står i [NONOX AI](/manual/installningar/nonox-ai).

## Vanliga frågor

**Kan jag skicka kvitton med e-post i stället?**
Ja, om företaget använder modulen Inkorg. Se [Inkorgen](/manual/leverantorer/inkorgen).

**Behöver jag spara pappersoriginalet?**
Det beror på vad som gäller för just ditt underlag enligt bokföringslagen. Fråga din redovisningskonsult; NONOX ger inga råd om det.

**Vem får lägga till underlag?**
Ägare, administratörer, redovisningskonsulter och bokförare. Revisorer och attestanter kan se underlaget men inte lägga till något.

## Läs också

- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
- [Hitta och läsa verifikationer](/manual/bokforing/verifikationer)
- [NONOX AI](/manual/installningar/nonox-ai)
- [Arkivpaket](/manual/bokslut/arkivpaket)
