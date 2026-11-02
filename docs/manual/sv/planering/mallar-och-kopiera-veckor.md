---
beskrivning: Spara en vanlig vecka som mall, bygg rullande scheman på upp till åtta veckor, och kopiera en vecka framåt med eller utan personerna.
ordning: 30
roller: [owner, admin, payroll_admin, scheduler]
modul: planning
rutter: [planning.templates]
sokord: [mall, schemamall, grundschema, rullande schema, kopiera vecka, återkommande schema, veckomall]
---

# Mallar och kopiera veckor

De flesta veckor liknar varandra. I stället för att lägga samma pass varje vecka kopierar du en vecka framåt, eller sparar den som en mall och lägger ut mallen när du behöver den. Allt som kopieras eller läggs ut blir utkast, så inget syns för personalen förrän du publicerar.

![Mallarna. En mall läggs ut på en eller flera veckor som utkast.](/bilder/manual/planering/mallar.webp)

## Så här kopierar du en vecka

1. Gå till **Planering → Schema** och öppna veckan du vill kopiera, i veckovyn.
2. Klicka på **Kopiera veckan**.
3. Välj **Till veckan som börjar**.
4. Fyll i **Antal veckor i följd**, från 1 till 8.
5. Låt **Med personerna (annars blir passen öppna)** vara ikryssad om samma personer ska ha passen. Annars blir alla pass öppna pass.
6. Klicka på **Kopiera**.

Pass som redan finns i målveckan kopieras inte en gång till. Kopian får inte hamna i veckorna som kopieras.

## Så här sparar du en vecka som mall

1. Öppna veckan i **Planering → Schema**.
2. Klicka på **Spara som mall**.
3. Skriv **Mallens namn**, till exempel "Vanlig vecka".
4. Välj om personerna ska följa med.
5. Klicka på **Spara mallen**.

## Så här skapar du en mall från början

1. Gå till **Planering → Mallar** och klicka på **Ny mall**.
2. Skriv **Namn** och **Antal veckor**, från 1 till 8. Ett rullande schema har flera veckor.
3. Skriv **Anteckningar** om du vill, och klicka på **Spara**.
4. Välj mallen i listan och lägg till passen ett i taget: **Vecka** (när mallen har flera veckor), **Veckodag**, **Starttid**, **Sluttid**, **Rast (minuter)**, **Person** eller **Öppet pass**, **Avdelning** och **Kompetens**. Klicka på **Lägg till pass** för varje.

## Så här lägger du ut en mall

I schemat:

1. Öppna veckan mallen ska börja i och klicka på **Lägg ut mall**.
2. Välj **Mall**.
3. Fyll i hur många gånger i följd mallen ska läggas ut, från 1 till 8, med början i veckan du har öppen.
4. Välj om personerna ska följa med.
5. Klicka på **Lägg ut**.

Under **Planering → Mallar** gör du likadant under **Lägg ut på schemat**: välj **Från veckan som börjar**, **Antal gånger i följd** och **Med personerna**, och klicka på **Lägg ut**.

En mall med flera veckor läggs ut vecka för vecka i ordning. Pass som redan finns läggs inte ut igen.

## Vanliga frågor

**Ändras schemat om jag ändrar mallen?**
Nej. Det som redan är utlagt ligger kvar som det är. Ändringen gäller nästa gång du lägger ut mallen.

**Vad händer med passen om jag tar bort mallen?**
Inget. Pass som redan lagts ut på schemat finns kvar.

## Läs också

- [Schemat](/manual/planering/schemat)
- [Publicera schemat](/manual/planering/publicera-schemat)
