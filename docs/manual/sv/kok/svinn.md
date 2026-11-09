---
beskrivning: Registrera det som slängs eller skänks med orsak, på sidan eller med mobilen, väg tallrikssvinnet och rätta en felaktig rad.
ordning: 30
roller: [owner, admin, accountant, bookkeeper, kitchen_staff]
modul: kitchen
rutter: [kitchen.waste]
sokord: [svinn, matsvinn, kassation, slänga, skänka, tallrikssvinn, utgånget]
---

# Svinn

Svinnloggen visar vad som har slängts eller skänkts och varför. Varorna dras från lagret direkt. Ingen verifikation bokförs: inköpet är redan en kostnad, och svinnet syns i lagervärdet vid bokslutet och i avvikelserapporten.

![Svinnloggen med orsak, mängd och kostnad för varje rad.](/bilder/manual/kok/svinn.webp)

Registrera bara det som har slängts eller ska slängas nu. Varor som väntar på att slängas registreras när de slängs.

## Registrera svinn

1. Gå till **Kök → Svinn**.
2. Under **Vad slängs**, välj **En råvara** eller **En rätt eller ett grundrecept**. En rätt dras från lagret enligt receptet.
3. Välj vad det är, skriv **Mängd** och välj **Orsak**.
4. Välj **Registrera svinnet**.

Orsakerna är **Utgånget datum**, **Fördärvat (kylfel, mögel, skadat)**, **Beredningssvinn utöver receptet**, **Lagat men inte sålt**, **Fellagat eller felbeställt**, **Skänkt** och **Övrigt**. Vid **Skänkt** skriver du den mottagande organisationen. Vid **Övrigt** skriver du vad som hände.

> [!NOTE]
> Skatteverket anser att skänkt mat nära bäst före-datum normalt inte har något beskattningsunderlag. NONOX bokar ingen moms för skänkt mat. Den som får se kostnader ser kostnadsvärdet för det som skänks.

## Med mobilen

Under **Lager → Skanna** finns rutan **Svinn**. Välj **Råvara** eller **Rätt**, skanna eller välj varan, ange antal och orsak och välj **Registrera svinn**. Det fungerar utan nät och skickas en gång när telefonen har nät. Se [Skanna](/manual/lager/skanna).

![Skanna i mobilen med rutan Svinn.](/bilder/manual/kok/svinn-mobil.webp)

## Tallrikssvinn

Mat som gästerna lämnat är redan såld. Väg den under **Tallrikssvinn** och välj **Spara vikten**. Inget dras från lagret.

## Rätta en rad

Loggen kan inte ändras. Välj **Rätta** på raden: varorna läggs tillbaka i lagret och en rättelserad läggs till. En rad kan bara rättas en gång.
