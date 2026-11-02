---
beskrivning: Skriv ut pris- och hyllkantsetiketter för artiklar, etiketter för det som kom in med en leverans och etiketter med koden på hyllor och fack.
ordning: 35
roller: [owner, admin, accountant, bookkeeper, stock_clerk]
modul: inventory
rutter: [inventory.articles, inventory.locations, inventory.receipts]
sokord: [etikett, etiketter, prisetikett, hyllkant, hyllkantsetikett, streckkod, ean, qr-kod, skriv ut etiketter, etikettskrivare, etikettark, ej utskrivna, platsetikett]
---

# Skriv ut etiketter

Du kan skriva ut etiketter för artiklar och för lagerställen. Etiketterna blir en PDF som öppnas i en ny flik. Skriv ut den på ett A4-etikettark i en vanlig skrivare, eller på en rulle i en etikettskrivare. Hur etiketten ser ut bestäms av en mall, se [Etikettstudion](/manual/lager/etikettstudio).

## Etiketter för artiklar

1. Gå till **Lager → Artiklar**.
2. Markera rutan framför de artiklar du vill ha etiketter för. Vill du bara ha en etikett klickar du på **Etikett** på artikelns rad.
3. Klicka på **Skriv ut etiketter**.
4. Välj **Mall**. Standardmallen är redan vald.
5. Välj **Antal etiketter**:
   - **En per artikel**,
   - **Enligt lagersaldo**: lika många som du har i lager, högst 500 per artikel,
   - **Samma antal för alla**: ange antalet.
6. Skriver du på ett ark som redan är delvis använt anger du **Börja på etikett nr**. Räkna radvis från övre vänstra hörnet.
7. Klicka på **Skriv ut**. PDF:en öppnas i en ny flik, och därifrån skriver du ut.

> [!TIP]
> Skriv ut PDF:en i **verklig storlek** (100 %), inte "anpassa till sidan". Annars hamnar etiketterna fel på arket.

### Ej utskrivna

Artiklar som aldrig har fått etiketter har märket **Ej utskriven** i listan. Välj **Visa → Ej utskrivna etiketter** för att bara se dem. Låt **Markera artiklarna som utskrivna** vara ikryssad när du skriver ut, så försvinner de ur listan.

### Vad står på etiketten

Det beror på mallen. Vanligast är namn, pris inklusive moms, texten "inkl. moms 25 %" och en streckkod.

- Har artikeln ett giltigt **EAN** blir streckkoden EAN-13 eller EAN-8. Kassor och handskannrar läser den direkt.
- Annars blir streckkoden Code 128 med artikelns **skannerkod**. Saknas den används `A:` och artikelnumret, till exempel `A:1001`.
- Är EAN-numret fel (kontrollsiffran stämmer inte) används Code 128 i stället, så att etiketten ändå går att skanna.

> [!NOTE]
> Lagerpersonal kan skriva ut etiketter men aldrig priser. Skriver någon med rollen Lagerpersonal ut en prisetikett blir den utan pris, momstext och jämförpris.

## Etiketter för det som kom in

1. Gå till **Lager → Inleveranser** och välj **Visa → Alla**.
2. Klicka på **Etiketter för det som kom in** vid inleveransen.
3. Under **Antal etiketter** står **Mottaget antal**: en etikett per mottagen vara. För en artikel som räknas i till exempel kilo blir det en etikett.
4. Klicka på **Skriv ut**.

## Etiketter för lagerställen

1. Gå till **Lager → Lagerställen**.
2. Klicka på **Etikett** vid platsen, eller på **Etiketter för allt under** för att få etiketter till platsen och alla platser under den, till exempel en hel hylla med fack.
3. Välj mall och klicka på **Skriv ut**.

Platsetiketten har platskoden i stort, platsens namn, en QR-kod och en streckkod. Båda koderna innehåller `L:` och platskoden, till exempel `L:HL-A-03-2`, så att skannern vet att det är en plats och inte en artikel.

> [!NOTE]
> En platskod med Å, Ä eller Ö kan inte bli en streckkod (Code 128). Då skrivs bara QR-koden ut. Studion varnar för det.

## Vem får skriva ut

Du behöver kunna hantera varor i lagret, det vill säga rollerna Ägare, Administratör, Redovisningskonsult, Bokförare eller Lagerpersonal. Revisorer kan inte skriva ut etiketter. Se [Roller och behörigheter](/manual/lager/roller-och-behorigheter).

## Läs också

- [Etikettstudion](/manual/lager/etikettstudio) – gör egna mallar.
- [Artiklar i lager](/manual/lager/artiklar) – EAN, skannerkod och egenskaper.
- [Lagerställen](/manual/lager/lagerstallen) – platskoderna som skrivs på etiketterna.
