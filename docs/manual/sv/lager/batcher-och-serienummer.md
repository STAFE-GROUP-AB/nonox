---
beskrivning: Följ en artikel per batch eller serienummer, med bäst före-datum, plocka det som går ut först och spåra en batch till leverantören och de kunder som har fått den.
ordning: 35
roller: [owner, admin, accountant, bookkeeper, stock_clerk]
modul: inventory
rutter: [inventory.lots]
sokord: [batch, partinummer, serienummer, bäst före, spårbarhet, återkallning, FEFO, bäst före-datum, utgångsdatum, parti]
---

# Batcher och serienummer

Vissa varor behöver du kunna följa en och en: mat och kosmetika som har bäst före-datum, eller maskiner där varje enhet har ett serienummer. Då slår du på **Spårning** på artikeln. Varje inleverans får då ett batchnummer, eller varje enhet ett serienummer, och lagret vet vad som ligger var, per batch.

![Batcher med bäst före-datum och hur mycket som finns kvar i lager.](/bilder/manual/lager/batcher-och-serienummer.webp)

## Slå på spårning på en artikel

1. Öppna artikeln under **Fakturering → Artiklar** och gå till fliken **Avancerad**.
2. Se till att **Lagerförd** är ikryssad.
3. Välj **Batch** eller **Serienummer** under **Spårning**.
4. Kryssa i **Kräv bäst före-datum** om varje ny batch måste ha ett.

![Spårning på artikeln, med valet att kräva bäst före-datum.](/bilder/manual/lager/sparning-pa-artikeln.webp)

Spårningen kan bara ändras när artikeln inte har något saldo. Har den varor i lager får du ett felmeddelande. Inventera då ner den till noll först, så att varje enhet får sitt batchnummer eller serienummer när den tas emot på nytt.

## Ta emot en batch eller serienummer

Vid **Lager → Inleveranser → Ta emot** visas extra fält på rader med spårade artiklar:

- **Batch:** skriv **Batchnummer** och **Bäst före**. Kommer samma batch i flera leveranser skriver du samma batchnummer.
- **Serienummer:** skriv ett serienummer per rad i rutan. Antalet serienummer ska vara lika med antalet som kom.

Ett serienummer är en enhet och kan bara ligga på ett ställe i taget. Skriver du ett serienummer som redan finns i lager får du ett felmeddelande.

## Plocka det som går ut först

När du gör en plocklista föreslår NONOX batcher i den ordning de går ut: den med tidigast bäst före-datum först, sedan batcher utan datum, och batcher som redan har gått ut sist. Är det lika går den äldsta batchen först. På varje rad står batchen eller serienumret.

Vill du plocka en annan batch väljer du den under **Plocka från** på raden. Bara batcher som finns på radens plats kan väljas.

Vid kassation (**Kassera** under **Lager → Artiklar**) börjar NONOX med den batch som går ut först, även om den redan har gått ut. Vid en flytt följer batcherna med till det nya lagerstället. Under **Batch eller serienummer** i flyttformuläret kan du välja en bestämd batch.

> [!NOTE]
> Plocklistans PDF visar också batch eller serienummer på varje rad.

## Inventera per batch

En inventering räknar en artikel med spårning per batch och plats. Hittar du en batch som systemet inte har, väljer du artikeln under **Hittat något mer?** och skriver batchnumret (och bäst före-datumet om batchen är ny).

## Spåra en batch

Gå till **Lager → Batcher**. Där finns varje batch och serienummer med bäst före-datum och saldo. Sök på batchnummer, serienummer eller artikel, eller visa bara de som går ut inom 30 dagar.

Klicka på **Spåra** på en rad:

- **Kom in** visar inleveransen, leverantören och platsen.
- **Gick ut** visar plocklistor med order eller faktura och kund, kassan, kassation och uttag till tillverkning.
- **Återkallningslista** samlar de kunder som har fått batchen, med antal och senaste datum.
- **Finns kvar** visar var i lagret resten ligger.

![En batch spårad från leverantören till kunderna som har fått den.](/bilder/manual/lager/spara-en-batch.webp)

> [!WARNING]
> NONOX visar vad lagret vet och gör ingenting för att uppfylla lagens krav på spårbarhet. För livsmedel och andra varor med särskilda krav är det du som ansvarar för att uppgifterna räcker.

## Värdet vid bokslutet ändras inte

Lagervärdet räknas som förut per artikel, med FIFO eller vägt genomsnitt, inte per batch. Spårningen ändrar alltså inte vad som bokförs vid bokslutet. Se [Lagervärde vid bokslut](/manual/lager/lagervarde-vid-bokslut).

## Kontrollera att saldona stämmer

Kommandot `php artisan inventory:verify` kontrollerar att saldot per batch och plats är summan av batchens rörelser, och att artikelns saldo är summan av dess batcher.

## Läs också

- [Inleverans](/manual/lager/inleverans)
- [Plocklista](/manual/lager/plocklista)
- [Inventering](/manual/lager/inventering)
