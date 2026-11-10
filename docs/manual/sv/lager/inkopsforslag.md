---
beskrivning: Se vad som behöver beställas utifrån lagersaldo, beställningspunkter, öppna kundordrar och redan beställda varor, och gör inköpsorder per leverantör.
ordning: 28
roller: [owner, admin, accountant, bookkeeper, approver, auditor]
modul: inventory
rutter: [inventory.purchaseProposals]
sokord: [inköpsförslag, beställningsförslag, beställningspunkt, brist, saknas, påfyllning, restnoterat, behov]
---

# Inköpsförslag

Inköpsförslaget visar vilka lagerartiklar som behöver beställas och hur mycket. Du väljer vad du vill beställa, och NONOX gör ett utkast till inköpsorder per leverantör.

![Inköpsförslaget med det som finns i lager, det som är reserverat för kundordrar, det som är på väg och förslaget i inköpsenheten.](/bilder/manual/lager/inkopsforslag.webp)

## Hur förslaget räknas

För varje lagerartikel räknar NONOX:

- **I lager** – saldot på alla lagerställen.
- **Reserverat** – det som öppna kundordrar behöver. Det som redan har plockats med en klarmarkerad plocklista räknas inte.
- **På väg** – det som står på inköpsorder som inte har tagits emot än, också utkast. Datumet är det tidigaste väntade.
- **Saknas** – reserverat minus i lager minus på väg, om det blir mer än noll.

En artikel med en **beställningspunkt** fylls på när det som finns och är på väg, minus det reserverade, ligger vid eller under punkten. Då föreslås beställningskvantiteten, eller det som behövs för att komma tillbaka till punkten om det är mer. Finns det också en brist föreslås det största av de två.

Förslaget står i artikelns inköpsenhet och avrundas uppåt. Förpackningar, par, set och annat som räknas i hela enheter avrundas till hela enheter, så att du alltid beställer hela förpackningar. Beställningspunkten och beställningskvantiteten sätter du på artikeln under **Lager → Artiklar**.

## Så här gör du inköpsorder av förslaget

1. Gå till **Beställningar → Inköpsförslag**.
2. Bocka ur det du inte vill beställa, och ändra antalet om du vill.
3. Välj leverantör för artiklar som saknar en. Förvald är artikelns leverantör.
4. Klicka på **Skapa inköpsordrar för det som saknas**.

Det blir ett utkast per leverantör, med källan **Inköpsförslag**. Inget skickas förrän du har öppnat varje order, kontrollerat den och skickat den. Se [Inköpsorder](/manual/lager/inkopsorder).

> [!NOTE]
> Utkasten räknas genast som på väg, så samma artikel föreslås inte igen.

## Läs också

- [Inköpsorder](/manual/lager/inkopsorder)
- [Artiklar i lager](/manual/lager/artiklar) – beställningspunkt och beställningskvantitet.
