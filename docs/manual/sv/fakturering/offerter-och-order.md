---
beskrivning: Skriv offerter och order, skicka dem som PDF och gör dem till order och fakturor när kunden tackar ja, utan att skriva något två gånger.
ordning: 60
roller: [owner, admin, accountant, bookkeeper]
rutter: [invoicing.quotes, invoicing.quotes.create, invoicing.quotes.show, invoicing.quotes.pdf, invoicing.orders, invoicing.orders.create, invoicing.orders.show, invoicing.orders.pdf]
sokord: [offert, anbud, prisförslag, order, beställning, orderbekräftelse, makulera]
---

# Offerter och order

En **offert** är ett prisförslag till en kund. En **order** är det kunden har beställt, till exempel en catering till fredag. I NONOX går en offert vidare till en order och en order vidare till en faktura, och raderna följer med hela vägen.

Du hittar dem under **Försäljning → Offerter** och **Försäljning → Order**. Ingenting bokförs förrän fakturan skapas.

![En skickad offert. När kunden tackar ja blir den en order eller en faktura.](/bilder/manual/fakturering/offerter-och-order.webp)

## Så här skriver du en offert

1. Gå till **Försäljning → Offerter** och tryck på **Ny offert**.
2. Välj **Kund**.
3. Kontrollera **Datum** och **Giltig till**. Offerten gäller 30 dagar från början.
4. Fyll i **Er referens** och **Vår referens** om du vill.
5. Kontrollera **Valuta**. Den är kundens valuta från början, och priserna på raderna skrivs i den.
6. Fyll i raderna på samma sätt som på en faktura: artikelnummer eller fri text, antal, enhet, à-pris utan moms, rabatt och moms. **Enhet** väljs i företagets enhetslista. En artikel får kundens pris för offertens datum, se [Priser på fakturor, offerter och order](/manual/fakturering/priser-pa-raderna).
7. Skriv ett **Meddelande (frivilligt)** om kunden ska få veta något mer, till exempel vad som ingår.
8. Tryck på **Spara**. Offerten får ett nummer, till exempel *Offert 12*.

En order skriver du likadant under **Försäljning → Order** med **Ny order**. I stället för **Giltig till** har en order ett **Leveransdatum**.

## Så här skickar du offerten

1. Öppna offerten.
2. Kontrollera e-postadressen i fältet överst. Den är hämtad från kunden.
3. Tryck på **Skicka offerten** (eller **Skicka orderbekräftelse** för en order).

Kunden får dokumentet som PDF. Vill du se det först trycker du på **Öppna PDF**.

## När kunden tackar ja

Öppna offerten och välj:

- **Kunden tackade ja – gör order**: en ny order skapas med samma rader, priser och valuta och dagens datum. Offerten får status *Godkänd, blev order*.
- **Gör faktura**: ett fakturautkast skapas direkt från offerten eller ordern, med samma priser och valuta och dagens datum som fakturadatum. En faktura i annan valuta än kronor får Riksbankens kurs, som för andra fakturor i utländsk valuta. För en order blir orderns leveransdatum fakturans leveransdatum. Offerten eller ordern får status *Fakturerad*.

Har offerten gått ut frågar NONOX först vilka priser som ska gälla: **Behåll offertens priser** eller **Hämta dagens priser**, som först visar vad som skulle ändras. Läs [Priser på fakturor, offerter och order](/manual/fakturering/priser-pa-raderna).

Fakturautkastet öppnas så att du kan kontrollera det. Tryck sedan på **Skapa fakturan** som vanligt. Läs [Skapa en faktura](/manual/fakturering/skapa-en-faktura).

> [!NOTE]
> Momsbehandlingen – försäljning utomlands eller omvänd betalningsskyldighet – hämtas från kunden när fakturautkastet skapas. Kontrollera den på utkastet om kunden har ändrats sedan offerten skrevs.

## Makulera

Tackar kunden nej, eller ska ordern inte levereras, trycker du på **Makulera** och sedan **Ja, makulera**. Dokumentet får status *Makulerad* och finns kvar i listan, men kan inte ändras eller göras till faktura.

## Status

| Status | Betyder |
|---|---|
| **Öppen** | Kan ändras, skickas och gå vidare. |
| **Godkänd, blev order** | Offerten blev en order. |
| **Fakturerad** | Ett fakturautkast är skapat från dokumentet. |
| **Makulerad** | Avbruten. |

Bara en öppen offert eller order kan ändras. Överst på dokumentet finns länkar mellan offerten, ordern och fakturan, så att du alltid ser vad som hänt.

## Listan

I listorna ser du nummer, kund, datum, giltighet eller leverans, belopp och status. Välj **Öppna** för att bara se det som väntar på svar eller leverans. Nyckeltalet **Order att fakturera** på fakturasidan visar vad öppna order är värda, och vad öppna offerter är värda.

## Vanliga frågor

**Kan jag ändra en offert som jag har skickat?**
Ja, så länge den är öppen. Spara och skicka den igen.

**Kan en offert bli flera fakturor, till exempel en delfaktura?**
Nej, en offert eller order blir en faktura. Vill du dela upp den ändrar du raderna på fakturautkastet och skriver en ny faktura för resten.

**Följer priserna med om en prislista har ändrats sedan offerten skrevs?**
Ja. Kunden har tackat ja till offertens priser, så de följer med till ordern och fakturan oförändrade. Vill du ha dagens priser trycker du på **Uppdatera priser** på ordern eller fakturautkastet.

**Kan jag skriva en offert i euro?**
Ja. Välj **Valuta** på offerten. Priserna skrivs då i euro, summan avrundas inte till hela kronor, och PDF:en visar valutan.

**Bokförs något när jag gör en order?**
Nej. Bokföringen sker först när fakturan skapas.

## Läs också

- [Skapa en faktura](/manual/fakturering/skapa-en-faktura)
- [Priser på fakturor, offerter och order](/manual/fakturering/priser-pa-raderna)
- [Artiklar](/manual/fakturering/artiklar)
- [Avtalsfakturering](/manual/fakturering/avtalsfakturering)
- [Logga och färger på fakturan](/manual/fakturering/logga-och-farger)
