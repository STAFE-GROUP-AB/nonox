---
beskrivning: Ändrings- och tilläggsarbeten (ÄTA) som kunden godkänner med pris innan arbetet görs – med signatur, muntligt eller utfört utan kundens besked.
ordning: 162
roller: [owner, admin, accountant, bookkeeper]
modul: work_orders
rutter: [workOrders.show, workOrders.changeOrderPdf]
sokord: [ÄTA, ändringsarbete, tilläggsarbete, konsumenttjänstlagen, signera, godkännande, muntligt, utfört utan besked, kontaktförsök, försäkring]
---

# ÄTA – ändrings- och tilläggsarbeten

Ett ÄTA är en ändring eller ett tillägg till arbetsordern. Kunden ska godkänna priset innan arbetet görs. ÄTA numreras per arbetsorder: ÄTA 1, ÄTA 2 och så vidare.

## Skapa och prissätt

1. Öppna arbetsordern och klicka på **Nytt ÄTA** under **ÄTA**.
2. Skriv **Vad gäller ändringen?**, välj **Grund** och beskriv **Vad ska göras**.
3. Välj **Prisform** och skriv **Arbete exklusive moms**, **Material exklusive moms** och **Övrigt exklusive moms**. Vid ungefärligt pris skriver du också **Övre gräns inklusive moms**.
4. Skriv **Påverkan på tiden** och **Betalning** om det behövs, och klicka på **Spara ÄTA**.

ÄTA:t får arbetsorderns ROT eller RUT. Betalar till exempel en försäkring en del kan du bocka ur avdraget för just det ÄTA:t. Momsen följer alltid arbetsordern. Under **PDF** ser du ÄTA:t som kunden ser det, med moms, beräknat avdrag och vad kunden betalar.

## Kundens svar

- **Skicka för signering** – kunden får en länk i e-post, läser ÄTA:t och skriver under med namnteckning. Innehållet och priset låses när det skickas. När kunden har signerat är ÄTA:t **Godkänt**. Kan kunden signera direkt på plats, se [Arbetsorder på plats](/manual/fakturering/arbetsorder-pa-plats).
- **Godkänt muntligt** – skriv vem som svarade, hur och när. Ett muntligt godkännande är ett svagare bevis och visas så.
- **Kunden avböjde** – ÄTA:t blir **Avböjt**.
- **Återkalla** – ni drar tillbaka ÄTA:t.

Kunden får alltid en kopia av ett godkänt ÄTA som PDF i e-post.

## Utfört utan kundens besked

Ibland går kunden inte att nå, till exempel när ett rör läcker bakom en vägg. Konsumenttjänstlagen 8 § säger när arbetet ändå får, eller ska, göras.

1. Klicka på **Försök att nå kunden** och registrera varje försök med kanal och tid.
2. Klicka på **Utfört utan besked**, välj **Skäl enligt 8 § konsumenttjänstlagen** och skriv **Motivering**.

Det går inte utan minst ett registrerat försök. NONOX bedömer inte om priset är lågt nog; det gör ni. Kunden får ÄTA:t som PDF i e-post.

> [!NOTE]
> Ett beslutat ÄTA – godkänt, avböjt, utfört utan besked eller återkallat – kan aldrig ändras. Det sparas som räkenskapsinformation i sju år, också om modulen stängs av.
