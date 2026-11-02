---
beskrivning: Gör egna etikettmallar för artiklar och lagerställen, för A4-etikettark eller etikettskrivare, och se direkt hur etiketten blir.
ordning: 62
roller: [owner, admin, accountant]
modul: inventory
rutter: [inventory.labels]
sokord: [etikettstudio, etikettmall, mall etikett, etikettark, etikettskrivare, rulle, marginal, avstånd, layout etikett, förhandsvisning etikett, standardmall]
---

# Etikettstudion

I etikettstudion bestämmer du hur etiketterna ser ut: storleken, om de skrivs ut på ett A4-ark eller en rulle, och vad som står på dem. Medan du ändrar ser du en förhandsvisning i skala med en av dina egna artiklar eller platser.

![Etikettstudion med en prisetikett och förhandsvisningen i skala.](/bilder/manual/lager/etikettstudio.webp)

Gå till **Lager → Inställningar** och klicka på **Öppna etikettstudion**.

När du slår på Lager får du två mallar att börja med:

- **Prisetikett 70 × 37 mm (A4-ark)**: 24 etiketter per ark med namn, pris, momstext, streckkod och artikelnummer.
- **Hyllplats 99 × 38 mm (A4-ark)**: 14 etiketter per ark med platskod, namn, QR-kod och streckkod.

## Så här gör du en ny mall

1. Klicka på **Ny artikelmall** eller **Ny platsmall**.
2. Ge mallen ett **Namn**.
3. Välj vad den **Skrivs ut på**:
   - **A4-ark**: ett ark med etiketter i en vanlig skrivare.
   - **Etikettskrivare (rulle)**: en etikett per sida, för en etikettskrivare.
4. För A4-ark väljer du **Ark**:
   - ett **A4-etikettark** med färdiga mått. Etikettens storlek bestäms då av arket.
   - **Vanligt A4-papper**: etiketterna läggs i mitten med streckade linjer att klippa efter.
   - **Eget ark**: ange kolumner, rader, marginalerna till första etiketten och avståndet mellan etiketterna.
5. Välj **Storlek**, eller **Egen storlek** och ange bredd och höjd i millimeter.
6. Välj **Layout**:
   - **Centrerad**: allt i mitten.
   - **Vänsterställd**: allt till vänster.
   - **Anpassa storleken automatiskt**: priset (på en platsetikett koden) och namnet blir så stora som får plats.
7. Kryssa i det som ska **Visas på etiketten**.
8. Klicka på **Spara**.

> [!TIP]
> Mät ditt etikettark om du är osäker. Du behöver etikettens bredd och höjd, avståndet från papperets kant till första etiketten och avståndet från en etiketts kant till nästa etiketts kant.

## Det här kan stå på etiketten

| Val | Artikel | Plats |
|---|---|---|
| Namn | Artikelnamnet, högst två rader | Platsens namn |
| Artikelnummer / Platskod | "Art.nr 1001" | Koden i stort, till exempel HL-A-03-2 |
| Pris inkl. moms | Priset med moms, till exempel 149 kr | – |
| Texten "inkl. moms" | "inkl. moms 25 %" | – |
| Jämförpris | Pris per kilo, liter eller meter | – |
| Streckkod | EAN när artikeln har ett giltigt EAN, annars Code 128 | Code 128 |
| QR-kod | Samma innehåll som streckkoden | `L:` och platskoden |
| SKU, Varumärke | Från artikelns avancerade uppgifter | – |
| Valda egenskaper | De egenskaper du väljer, till exempel Färg: Blå | – |
| Företagets logotyp | Logotypen från företagets utseende | Samma |
| Egen text | Samma text på varje etikett | Samma |

Jämförpriset räknas fram om artikeln säljs per kilo, liter eller meter, eller om den har en vikt. Annars visas det inte.

## Förhandsvisningen och varningarna

Förhandsvisningen visar etiketten så som den skrivs ut. Under den står vad du bör tänka på, till exempel:

- namnet är för långt och kortas av,
- innehållet får inte plats på höjden,
- QR-koden blir mindre än 10 mm och kan vara svår att läsa,
- streckkoden blir för smal för att läsas säkert,
- marginalerna tar för stor del av etiketten.

Varningarna hindrar dig inte från att spara. Men ett rutnät som inte får plats på A4, eller etiketter som skulle överlappa, går inte att spara. Då står felet i rött.

För ett A4-ark ser du också hur etiketterna hamnar på arket.

Vill du se den riktiga PDF:en klickar du på **Provsida som PDF**. Den visar den sparade mallen med några av dina artiklar eller platser.

## Kopiera, standard och ta bort

- **Kopiera** gör en kopia som du kan ändra.
- **Gör till standard** gör mallen förvald när någon skriver ut. Varje sort (artikel och plats) har en standardmall.
- **Ta bort** tar bort mallen. Den sista mallen av en sort kan inte tas bort. Tar du bort standardmallen blir nästa mall standard.

## Vem får ändra mallarna

Ägare, administratörer och redovisningskonsulter. Alla som får hantera varor kan skriva ut med mallarna.

## Läs också

- [Skriv ut etiketter](/manual/lager/etiketter)
- [Roller och behörigheter](/manual/lager/roller-och-behorigheter)
