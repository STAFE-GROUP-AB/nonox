---
beskrivning: Ta emot varor från en leverantör, allt på en gång eller i delar, med eller utan koppling till leverantörsfakturan.
ordning: 30
roller: [owner, admin, accountant, bookkeeper, stock_clerk]
modul: inventory
rutter: [inventory.receipts]
sokord: [inleverans, ta emot varor, godsmottagning, följesedel, leverans, restorder, delleverans, inköp lager]
---

# Inleverans

En inleverans är det du väntar på från en leverantör. När varorna kommer tar du emot dem, och då ökar saldot på det lagerställe där de läggs. Inleveransen bokför ingenting. Inköpet bokförs som vanligt med leverantörsfakturan.

![En inleverans som tas emot: hur mycket som kom och på vilken plats det läggs.](/bilder/manual/lager/inleverans.webp)

## Så här skapar du en inleverans

1. Gå till **Lager → Inleveranser** och klicka på **Ny inleverans**.
2. Välj **Leverantör** om du vill. Har du redan registrerat leverantörsfakturan kan du koppla den under **Leverantörsfaktura**.
3. Fyll i **Referens**, till exempel följesedelns nummer.
4. Lägg till en rad per artikel: artikel, antal och plats. Lämnar du platsen tom används artikelns standardplats.
5. Ange **Inköpspris/st** om det skiljer sig från artikelns inköpspris.
6. Klicka på **Skapa inleverans**.

Bara artiklar som är markerade som lagerförda kan tas emot.

## Så här tar du emot varorna

1. Klicka på **Ta emot** vid inleveransen.
2. Under **Kom nu** står det som återstår. Ändra antalet om bara en del kom.
3. Kontrollera platsen och datumet.
4. Klicka på **Ta emot**.

Kom bara en del står inleveransen som **Delvis inlevererad**. Resten tar du emot senare på samma sätt. När allt har kommit står den som **Inlevererad**.

> [!NOTE]
> Lagerpersonal ser inga priser. När de skapar en inleverans används artikelns inköpspris som kostnad. Den som får se priser kan ändra det innan varorna tas emot.

## Makulera

En inleverans där inget har tagits emot kan makuleras. Det som redan är mottaget ligger kvar i lagret; skicka tillbaka varor med en retur till leverantören under **Lager → Artiklar**.

## Läs också

- [Leverantörsfakturor](/manual/leverantorer)
- [Lagervärde vid bokslut](/manual/lager/lagervarde-vid-bokslut) – hur inköpspriset påverkar lagervärdet.
