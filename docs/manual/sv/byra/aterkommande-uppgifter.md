---
beskrivning: Lägg upp uppgifter som kommer tillbaka varje månad, kvartal eller år, som moms och arbetsgivardeklaration, så skapar NONOX dem av sig själv.
ordning: 70
roller: [owner, admin, accountant, bookkeeper]
rutter: [tasks.recurring]
sokord: [återkommande uppgift, varje månad, rutin, moms varje månad, AGI varje månad, kvartal, årlig, mall]
---

# Återkommande uppgifter

Mycket av byråns arbete kommer tillbaka: moms varje månad, arbetsgivardeklarationen, kvartalsrapporten till kunden. Lägg upp det en gång som en återkommande uppgift, så skapar NONOX en ny uppgift för varje period.

Återkommande uppgifter läggs upp per företag. Byt till rätt företag först.

![Återkommande uppgifter som skapas varje period.](/bilder/manual/byra/aterkommande-uppgifter.webp)

## Så här gör du

1. Öppna kundens företag.
2. Gå till **Uppgifter → Återkommande**.
3. Under **Ny återkommande uppgift**, skriv en **Rubrik**, till exempel *Moms*.
4. Välj **Hur ofta**: **Varje månad**, **Varje kvartal** eller **Varje år**.
5. Välj **Sista dag (dag i månaden)**, en dag mellan 1 och 31.
6. Välj **Typ** och **Ansvarig**.
7. Skriv gärna en **Beskrivning (valfri)**.
8. Välj **Spara**.

## Så räknar NONOX ut sista dag

Uppgiften skapas en gång per period, med sista dag i månaden **efter** perioden. Du väljer vilken dag.

| Hur ofta | Period | Exempel med dag 12 |
|---|---|---|
| **Varje månad** | Månaden | Oktober får sista dag 12 november. |
| **Varje kvartal** | Kvartalet | Juli–september får sista dag 12 oktober. |
| **Varje år** | Kalenderåret | Året får sista dag 12 januari året efter. |

Väljer du en dag som inte finns i månaden, till exempel 31, blir det månadens sista dag.

> [!IMPORTANT]
> NONOX bestämmer inte Skatteverkets datum åt dig. Kontrollera vilka datum som gäller för just ditt företag, och välj dagen därefter.

## När uppgiften skapas

NONOX går igenom de återkommande uppgifterna tidigt varje morgon och skapar periodens uppgift när perioden har börjat. Det blir aldrig mer än en uppgift per period. Uppgiften får rubriken och perioden som namn, till exempel *Moms – oktober 2026*. Den ansvarige får en notis, och uppgiften syns under **Uppgifter** och i **Att göra** under **Mina företag**.

## Stoppa en återkommande uppgift

Välj **Stoppa** och bekräfta. Inga nya uppgifter skapas, men de som redan har skapats finns kvar. En stoppad uppgift visas som **Stoppad** i listan.

## Läs också

- [Uppgifter](/manual/byra/uppgifter)
- [Månadsstängning](/manual/byra/manadsstangning)
- [Momsdeklaration](/manual/moms/momsdeklaration)
