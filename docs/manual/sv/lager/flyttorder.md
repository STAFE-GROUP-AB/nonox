---
beskrivning: Flytta varor mellan två lager med en flyttorder. Varorna väntar på väg tills de tas emot, kan tas emot i delar och räknas med i lagervärdet hela tiden.
ordning: 45
roller: [owner, admin, accountant, bookkeeper, stock_clerk]
modul: inventory
rutter: [inventory.transfers]
sokord: [flyttorder, flytta mellan lager, lagerflytt, överföring, transit, på väg, mottagning, följesedel, flyttsedel, påfyllning av butik]
---

# Flyttorder

En flyttorder flyttar varor från ett lager till ett annat, till exempel från förrådet till butiken. Varorna tas från avsändaren när du skickar ordern och läggs in hos mottagaren när de har kommit fram. Däremellan är de **på väg**.

![En flyttorder på väg, med det som skickades och en ruta för vad som kom fram på varje rad.](/bilder/manual/lager/flyttorder.webp)

Flyttorder gäller flytt mellan två olika lager. Vill du flytta inom ett lager, till exempel från en hylla till ett fack, gör du en vanlig flytt i **Lager → Artiklar**.

## Så här gör du en flyttorder

1. Gå till **Lager → Flyttorder** och klicka på **Ny flyttorder**.
2. Välj platsen varorna ligger på under **Från** och platsen de ska till under **Till**. Platserna måste ligga i olika lager.
3. Välj artikel och skriv **Antal** på varje rad. Klicka på **Lägg till rad** för fler.
4. Klicka på **Spara utkast**.

Utkastet flyttar ingenting. Du kan ändra det med **Ändra** tills du skickar det.

## Så här skickar du

1. Öppna flyttordern med **Visa**.
2. Välj datum under **Skickas den**.
3. Klicka på **Skicka, varorna är på väg**.

Varorna dras från platsen de låg på och läggs på platsen **På väg** för de två lagren. Platsen skapas av NONOX första gången du skickar mellan två lager och syns inte i listorna över lagerställen. Det som är på väg finns kvar i artikelns totala saldo.

Har du inte tillräckligt i lagret på avsändarens plats kan ordern inte skickas. Då händer ingenting, och ordern är kvar som utkast.

## Så här tar du emot

1. Öppna flyttordern och skriv under **Kom fram** hur mycket som kom på varje rad. Från början står allt som skickades.
2. Välj datum under **Datum**.
3. Klicka på **Ta emot**.

Varorna läggs in på platsen som ordern anger. Kom bara en del fram kan du ta emot den delen, och resten är kvar på väg tills du tar emot den eller avbryter. Ordern är **Mottagen** när inget är kvar på väg.

## Avbryta

- Ett **utkast** kan avbrytas. Inget har flyttats.
- För det som fortfarande är på väg klickar du på **Avbryt resten, tillbaka till avsändaren**. Varorna läggs tillbaka på avsändarens plats. Har en del redan tagits emot blir ordern **Mottagen**, och på raderna står hur mycket som kom åter.

> [!NOTE]
> En skickad order kan inte ändras. Skickade du fel, avbryter du resten och gör en ny.

## Batch och serienummer

För en artikel med spårning följer batchen eller serienumret med hela vägen och hamnar hos mottagaren som samma batch eller serienummer. Väljer du ingen batch på raden tas varorna i den ordning de går ut: bäst före-datum först. Raden delas då i en rad per batch när ordern skickas. Se [Batcher och serienummer](/manual/lager/batcher-och-serienummer).

## Flyttdokumentet

**Skriv ut (PDF)** gör ett flyttdokument med avsändare, mottagare, raderna och en ruta att bocka för vid mottagningen. Det följer med varorna. Där står inga priser.

## Vad det gör med lagervärdet

Ingenting. Varorna kostar lika mycket på väg som på hyllan, och en flytt lägger aldrig på någon kostnad. Lagervärdet vid bokslutet räknas som förut, för hela företaget, och flyttorder bokför ingenting.

## Lager under beställningspunkten

Längst ned på sidan syns de lager som ligger vid eller under sin beställningspunkt, efter det du har satt på artiklarna. Det som är på väg till lagret räknas med. Så kan du planera en flytt från ett annat lager innan du köper in mer. Se [Beställningspunkt per lager](/manual/lager/artiklar#bestallningspunkt-per-lager).

## Läs också

- [Lagerställen](/manual/lager/lagerstallen)
- [Inköpsförslag](/manual/lager/inkopsforslag)
- [Plocklista](/manual/lager/plocklista)
