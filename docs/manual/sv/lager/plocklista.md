---
beskrivning: Gör en plocklista från en order, en faktura eller för hand, plocka i mobilen i den ordning du går genom lagret och dra varorna från lagret.
ordning: 40
roller: [owner, admin, accountant, bookkeeper, stock_clerk]
modul: inventory
rutter: [inventory.pickLists]
sokord: [plocklista, plocka, plockorder, utleverans, packa order, skicka varor, plockordning]
---

# Plocklista

En plocklista visar vad som ska hämtas och var det ligger. Raderna sorteras efter lagerställets kod, så att du går genom lagret en gång. Varje artikel tas först från sin standardplats och sedan från andra platser där det finns varor.

![En plocklista sorterad efter plats, med bild och EAN-kod på varje rad.](/bilder/manual/lager/plocklista.webp)

## Så här gör du en plocklista

1. Gå till **Lager → Plocklistor**.
2. Välj **Från order**, **Från faktura** eller **För hand**.
3. Välj ordern eller fakturan, eller lägg till artiklar och antal.
4. Klicka på **Skapa plocklista**.

Bara rader med lagerförda artiklar kommer med. Fria textrader, frakt och tjänster hoppas över. Det kan bara finnas en plocklista att plocka per order eller faktura.

## Så här plockar du

1. Öppna plocklistan i mobilen. Platsens kod står stort på varje rad.
2. Gå till platsen och hämta varorna.
3. Under **Plockat** står hela antalet. Ändra det om du hittade färre. Skriv 0 om inget fanns.
4. När allt är plockat klickar du på **Klar, dra från lagret**.

Då dras det plockade från lagret. En rad du inte har ändrat räknas som plockad i sin helhet.

> [!TIP]
> Vill du plocka på papper klickar du på **Skriv ut (PDF)**. Där finns en ruta att bocka för per rad.

Finns det inte tillräckligt i lagret visar plocklistan en rad utan plats för det som saknas. Ta emot varorna först, eller skriv 0 på den raden.

## Makulera

En plocklista som inte är klar kan makuleras. Inget dras då från lagret.

## Läs också

- [Offerter och order](/manual/fakturering/offerter-och-order)
- [Lagerställen](/manual/lager/lagerstallen)
