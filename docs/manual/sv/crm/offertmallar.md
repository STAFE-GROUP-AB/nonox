---
beskrivning: Spara offerter du skickar ofta som mallar, med artiklar ur artikelregistret, villkor och giltighet, och gör en ny offert från mallen med ett tryck.
ordning: 28
roller: [owner, admin, accountant, bookkeeper]
modul: crm
rutter: [crm.quoteTemplates]
sokord: [offertmall, mall, standardoffert, prislista, offert, artiklar]
---

# Offertmallar

En offertmall är en offert du skickar ofta: samma artiklar, samma villkor och samma giltighet. Gör en ny offert från mallen så är allt ifyllt, med dagens priser från artikelregistret.

![Offertmallar med mallens rader, inledning och villkor.](/bilder/manual/crm/offertmallar.webp)

Mallarna kräver modulen CRM och att du får skapa offerter.

## Så här gör du en mall

1. Öppna **CRM → Offertmallar** och tryck på **Ny mall**.
2. Skriv **Namn** och **Giltig i antal dagar**.
3. Skriv gärna **Inledning (frivilligt)** och **Villkor (frivilligt)**. De blir offertens meddelande och står på PDF:en.
4. Lägg till raderna. Välj en **Artikel**, eller **Fri rad** och skriv benämning, pris och moms själv. Ange **Antal**, **Enhet** och **Rabatt %** om du vill.
5. Tryck på **Spara mallen**.

## Priser från artikelregistret

En rad med en artikel sparar inget pris. När du gör en offert från mallen hämtas artikelns namn, enhet, pris och moms från artikelregistret den dagen. Ändrar du priset på artikeln gäller det nya priset i nästa offert. Vill du ha en egen benämning skriver du den i **Egen benämning (frivilligt)**.

Har en artikel i mallen arkiverats går det inte att göra en offert från mallen förrän du har ändrat den, så att ingen offert får ett gammalt pris.

## Så här gör du en offert från en mall

1. Välj mall under **Ny offert från mall**. Den finns på **Fakturering → Offerter**, på kundens kort och på en öppen affärs kort.
2. På **Offerter** väljer du också kund. På kundens och affärens kort är kunden redan vald.
3. Tryck på **Skapa offert**.

Offerten öppnas, daterad i dag och giltig så många dagar som mallen säger. Kontrollera den, ändra om du vill och skicka den, eller skicka den för signering. Se [Signera offerter och avtal](/manual/crm/signering).

## Bra att veta

- Arkivera en mall du inte längre använder med **Arkivera**. Den finns kvar under **Arkiverade** och kan återställas.
- En **Säljare** kan inte skapa offerter och ser därför inte mallarna.

## Läs också

- [Signera offerter och avtal](/manual/crm/signering)
- [Säljtratt och affärer](/manual/crm/saljtratt-och-affarer)
- [Offerter och order](/manual/fakturering/offerter-och-order)
