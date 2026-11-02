---
beskrivning: Lägg upp lager, zoner, hyllor och fack med korta koder, så att plocklistan visar vägen genom lagret och etiketterna får en adress.
ordning: 10
roller: [owner, admin, accountant, bookkeeper]
modul: inventory
rutter: [inventory.locations]
sokord: [lagerställe, lagerplats, hylla, fack, pallplats, zon, ställ, lageradress, platskod]
---

# Lagerställen

Ett lagerställe är en plats där varor ligger. Platserna bildar ett träd i upp till fyra nivåer:

![Lagerställena som ett träd: lagret, zonerna, hyllorna och facken med saldot på varje plats.](/bilder/manual/lager/lagerstallen.webp)

| Nivå | Till exempel | Kod |
|---|---|---|
| **Lager** | Butiken, förrådet, centrallagret | HL |
| **Zon** | Gång A, kylrum | A |
| **Hylla/ställ** | Hylla 3 | 03 |
| **Pallplats/fack** | Fack 2 | 2 |

Varje plats har en kort kod. NONOX bygger hela koden från platserna ovanför, till exempel **HL-A-03-2**. Den koden står på plocklistan och blir platsens adress på etiketter. Du behöver inte använda alla nivåer. En liten butik kan ha ett lager med några hyllor.

## Så här lägger du upp ett lager

1. Gå till **Lager → Lagerställen** och klicka på **Nytt lager**.
2. Skriv en kort **Kod**, till exempel HL, och ett **Namn**, till exempel Huvudlager.
3. Fyll i adressen om du vill.
4. Klicka på **Spara**.

## Så här lägger du till hyllor och fack

1. Klicka på **Lägg till under** vid den plats där den nya ska ligga.
2. Nivån föreslås automatiskt. Ändra den om du vill hoppa över en nivå.
3. Skriv koden, till exempel 03, och klicka på **Spara**.

> [!TIP]
> Skriv siffror med samma antal tecken, till exempel 01, 02 … 10. NONOX sorterar ändå 2 före 10, men koderna blir lättare att läsa på etiketterna.

## Ändra och inaktivera

- **Ändra** byter kod, namn eller adress. Byter du koden på en zon får alla hyllor och fack under den den nya koden.
- **Inaktivera** döljer platsen i listor och formulär. Det går bara när platsen och allt under den är tom. Flytta varorna först.

En plats kan inte flyttas till en annan förälder. Lägg i stället upp en ny plats och flytta varorna dit.

## Läs också

- [Plocklista](/manual/lager/plocklista) – hur koderna styr vägen genom lagret.
- [Artiklar i lager](/manual/lager/artiklar) – standardplats per artikel.
- [Skriv ut etiketter](/manual/lager/etiketter) – etiketter med platskoden för en plats eller en hel hylla.
