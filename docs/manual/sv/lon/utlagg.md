---
beskrivning: De anställda skickar in utlägg med kvitto på Min sida, du godkänner med konto, utlägget bokförs med kvittot och betalas tillbaka med nästa lön.
ordning: 120
roller: [owner, payroll_admin]
modul: payroll
rutter: [payroll.expenses.receipt]
sokord: [utlägg, kvitto, ersättning för utlägg, privat betalt, återbetalning, kvittohantering]
---

# Utlägg med kvitto

Ett utlägg är när en anställd har betalat något för företagets räkning med egna pengar, till exempel citroner till baren eller en kabel till kassan. Den anställda skickar in kvittot på Min sida, du godkänner det, och pengarna betalas tillbaka med nästa lön. Kvittot sparas som underlag i bokföringen.

![Ansökningar och utlägg som väntar på godkännande.](/bilder/manual/lon/utlagg.webp)

## Så här skickar den anställda in ett utlägg

På Min sida väljer den anställda **Lägg in ett utlägg**, tar en bild av kvittot eller väljer en PDF, och fyller i datum, vad som köptes, belopp och moms. Med NONOX AI påslaget räcker det att skicka kvittot; AI:n läser resten. Hjälpen för de anställda finns under [För anställda](/manual/personal).

## Så här godkänner du ett utlägg

1. Öppna **Lön → Ansökningar**. Utläggen finns under **Utlägg att godkänna**.
2. Klicka på **Visa kvittot** och jämför med uppgifterna.
3. Kontrollera eller rätta **Datum**, **Vad utlägget gäller**, **Belopp** och **Moms**.
4. Välj **Konto**, det kostnadskonto köpet ska bokföras på.
5. Klicka på **Godkänn**.

Ska utlägget inte godkännas: klicka på **Avslå**, skriv **Skäl, som den anställde ser** och klicka på **Avslå utlägget**.

Du kan inte godkänna ditt eget utlägg när **Den som beräknar får inte attestera** är på i löneinställningarna.

## Med NONOX AI

Är modulen NONOX AI på läser AI:n kvittot direkt när det skickas: datum, vad som köptes, belopp och moms, och föreslår ett kostnadskonto ur er kontoplan.

- **NONOX AI läser kvittot…** visas medan det pågår. Sidan uppdateras av sig själv.
- **Läst av NONOX AI** betyder att förslaget är ifyllt. Kontrollera det mot kvittot.
- **NONOX AI kunde inte läsa allt** betyder att något saknas. Läs AI:ns anteckning och fyll i det som fattas.
- Med **Läs kvittot igen** får AI:n försöka en gång till.

En obetald faktura, något som inte är ett köp eller en oläslig bild nekas redan när den anställda skickar den, med en förklaring till den anställda.

> [!NOTE]
> AI:n föreslår, du bestämmer. Inget bokförs förrän en person har godkänt utlägget, och AI:n bedömer aldrig köpet eller personen. Läs mer om [NONOX AI](/manual/installningar/nonox-ai).

## Det här händer i bokföringen

**När du godkänner** bokförs utlägget direkt som en verifikation i serie **L** på kvittots datum, med kvittot som underlag:

| Konto | Debet | Kredit |
|---|---|---|
| Kostnadskontot du valde | belopp utan moms | |
| 2641 Debiterad ingående moms | momsen | |
| 2890 Övriga kortfristiga skulder | | hela beloppet |

**I nästa lönekörning** betalas beloppet tillbaka med nettolönen, som en rad **Utlägg** på lönebeskedet, och bokförs mot 2890 i lönens verifikation. Utlägget är skattefritt för den anställda och ger ingen arbetsgivaravgift.

Ett utlägg knyts till den lönekörning som betalar det och betalas aldrig två gånger. När lönen markeras som utbetald blir utlägget **Utbetalt** på den anställdas Min sida.

## Vanliga frågor

**Var hittar jag kvittot i efterhand?**
På utläggets verifikation i serie L, under underlag. Se [Underlag](/manual/bokforing/underlag).

**Kan vi stänga av lönemodulen med obetalda utlägg?**
Nej. Ett godkänt utlägg som en lönekörning har tagit med men som inte är utbetalt hindrar att modulen stängs av.

**Får vi göra avdrag för momsen?**
Bara om kvittot uppfyller kraven för avdrag och köpet hör till den momspliktiga verksamheten. Är du osäker, lämna fältet **Moms** tomt eller fråga er redovisningskonsult.

## Läs också

- [Förmåner, traktamente och milersättning](/manual/lon/formaner-traktamente-och-milersattning)
- [Min sida för de anställda](/manual/lon/min-sida-for-anstallda)
- [NONOX AI](/manual/installningar/nonox-ai)
- [Underlag](/manual/bokforing/underlag)
