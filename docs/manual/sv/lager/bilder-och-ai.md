---
beskrivning: Lägg till bilder på en artikel, ta foto direkt med mobilen och låt NONOX AI föreslå benämning, beskrivning, varumärke och egenskaper från bilderna.
ordning: 25
roller: [owner, admin, accountant, bookkeeper]
modul: inventory
rutter: [invoicing.articles, inventory.articles]
sokord: [bild, bilder, foto, kamera, mobil, huvudbild, bildtext, NONOX AI, AI, fyll i, snabbregistrera, beskrivning, HEIC]
---

# Bilder och NONOX AI på artiklar

När Lager är påslaget kan varje artikel ha bilder. Den första bilden är huvudbild och syns i artikellistorna, på plocklistan och i API:t. Har ni också NONOX AI påslaget kan AI:n titta på bilderna och föreslå artikelns uppgifter.

## Lägg till bilder

1. Öppna artikeln under **Register → Artiklar** och klicka på **Ändra**.
2. Klicka på **Ta foto** för att fotografera med mobilens kamera, eller på **Välj bilder** för att välja en eller flera bilder.
3. Bilden sparas direkt på en sparad artikel. På en ny artikel sparas bilderna när du sparar artikeln.

Under fliken **Enkel** ser du bilderna som en rad med små bilder. Under **Avancerad** kan du ändra dem:

- **Ordning** – dra bilderna, eller använd **← Bakåt** och **Framåt →**. Den som ligger först är huvudbild.
- **Bildtext** – en kort text om vad bilden visar. Den läses upp av skärmläsare.
- **Ta bort** – bilden tas bort när du har bekräftat. Den går inte att få tillbaka.

En artikel kan ha högst 12 bilder. Bilder i JPG, PNG, WebP och HEIC (från iPhone) går att använda, högst 12 MB var. NONOX förminskar bilden till högst 1 600 pixlar på längsta sidan och sparar den krypterad. Kamerans uppgifter i bilden, till exempel var den togs, sparas inte.

> [!NOTE]
> Kan servern inte läsa HEIC säger NONOX det. Ställ då in kameran på JPEG: **Inställningar → Kamera → Format → Mest kompatibelt**.

## Fyll i med NONOX AI

Knappen **Fyll i med NONOX AI** finns när NONOX AI är påslaget, en API-nyckel är inlagd och du får både ändra artiklar och använda AI. Den kräver minst en bild.

1. Lägg till en eller flera bilder på varan. Ta gärna en bild på etiketten eller streckkoden också.
2. Klicka på **Fyll i med NONOX AI**. Det brukar ta högst 20 sekunder.
3. En tabell visar varje förslag bredvid det som står i formuläret nu. Tomma fält är förbockade. Det som redan står ändras bara om du bockar i det.
4. Klicka på **Använd valda**. Fälten fylls i, men ingenting sparas.
5. Kontrollera uppgifterna och klicka på **Spara**.

AI:n kan föreslå benämning, beskrivning, varumärke, modell, färger, material, egenskaper (till exempel storlek och skick), vikt och mått om de står på varan, och EAN-kod om hela streckkoden går att läsa. En EAN-kod med fel kontrollsiffra tas bort innan du ser förslaget. Egenskaper läggs bara till; en egenskap som artikeln redan har ändras aldrig.

AI:n föreslår aldrig pris, inköpspris eller konto.

> [!IMPORTANT]
> Bilderna skickas till den AI-leverantör ni har valt, med er egen nyckel och på ert konto. Läs mer under [NONOX AI](/manual/installningar/nonox-ai). Behandlingshistoriken visar att en analys gjordes, vilken leverantör och modell som användes, men aldrig bilderna eller svaret.

## Snabbregistrera med kamera

För att lägga upp en ny vara direkt från mobilen:

1. Gå till **Lager → Artiklar** och klicka på **Snabbregistrera med kamera**.
2. Klicka på **Ta foto** och fotografera varan.
3. Har ni NONOX AI börjar AI:n titta på bilden direkt. Bocka i det du vill använda och klicka på **Använd valda**.
4. Fyll i artikelnummer och pris och klicka på **Spara**. Artikeln blir lagerförd och bilden sparas med den.

## Vem får göra vad

| Roll | Se bilder | Ändra bilder | Fyll i med NONOX AI |
|---|---|---|---|
| Ägare, administratör | Ja | Ja | Ja |
| Redovisningskonsult, bokförare | Ja | Ja | Ja |
| Revisor | Ja | Nej | Nej |
| Lagerpersonal | Ja | Nej | Nej |

## Läs också

- [Artiklar i lager](/manual/lager/artiklar)
- [Plocklista](/manual/lager/plocklista)
