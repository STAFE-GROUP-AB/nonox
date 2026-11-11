---
beskrivning: Jämför kalkylerad och rapporterad tid per produkt och arbetssteg, se var det köar och vad ordrarna väntar på. Så läser du efterkalkylen.
ordning: 70
roller: [owner, admin, accountant]
modul: manufacturing
rutter: [manufacturing.analysis]
sokord: [efterkalkyl, utfall, avvikelse, spridning, flaskhals, kö, kapacitet, väntar på material, väntar på arbete]
---

# Efterkalkyl

Efterkalkylen visar vad ni kalkylerade mot vad som rapporterades i verkstaden, per produkt och arbetssteg. Den visar också var det köar och vad öppna order väntar på. Du ser den under **Tillverkning → Efterkalkyl** om du har behörigheten att analysera tillverkning.

![Efterkalkylen med kalkyl mot utfall per produkt och arbetssteg, köer och order som väntar.](/bilder/manual/tillverkning/efterkalkyl.webp)

> [!NOTE]
> Efterkalkylen är bara per produkt och arbetssteg. Det finns ingen vy per person, och rapporterna går inte att jämföra mellan personer.

## Kalkyl mot utfall

Välj perioden högst upp: färdiga order de senaste 3, 6, 12 eller 24 månaderna. För varje produkt och arbetssteg ser du:

- **Kalkyl** och **Utfall**: de planerade minuterna mot de rapporterade. Utfallet är tid som rapporterats på surfplattan eller angetts när ordern färdigmeldades. Tid som bara fyllts i från planen räknas inte, och antalet order utan rapporterad tid står inom parentes.
- **Avvikelse**: utfall minus kalkyl, i procent av kalkylen. Rött är över kalkylen, grönt under.
- **Spridning**: den minsta, mellersta och största avvikelsen på en enskild order. En liten total avvikelse kan dölja stora svängningar mellan order.
- **Utveckling**: jämför den nyare halvan av ordrarna med den äldre, i procentenheter. Den visas först från fyra order. **Försämras** betyder att steget tar allt längre tid än kalkylen, och kalkylen kan behöva justeras.

## Köer mot kapacitet

Här ser du återstående planerad tid på öppna order, per arbetssteg. **Dagar i kö** är återstående tid delad med stegets kapacitet per dag, som du anger under [Arbetssteg](/manual/tillverkning/arbetssteg). Steget med längst kö är flaskhalsen. Steg utan angiven kapacitet visas sist, efter återstående tid.

## Största överskridanden

Här står de arbetssteg där utfallet mest översteg kalkylen på färdiga order, i minuter, med hur många order som låg över.

## Väntar på material och väntar på arbete

Öppna order där någon markerat ett arbetssteg som väntande visas i två listor. De har olika åtgärder: väntar på material rättas av inköp och leveranser, väntar på arbete av planering och bemanning. Under varje lista står hur många markeringar som gjorts under perioden och hur länge arbetsstegen sammanlagt väntat. Se [Verkstadsterminal](/manual/tillverkning/verkstadsterminal).

## Bra att veta

- Efterkalkylen ändrar ingenting i bokföringen eller i kalkylerna på offerter och order.
- Beloppen i stycklistorna ändras inte av utfallet. Justera stycklistan själv när du sett att ett steg alltid tar längre tid.
- Order som färdigmeldats utan att någon rapporterat tid på ett steg räknas inte i det stegets jämförelse.

## Läs också

- [Verkstadsterminal](/manual/tillverkning/verkstadsterminal)
- [Stycklistor](/manual/tillverkning/stycklistor)
- [Arbetssteg](/manual/tillverkning/arbetssteg)
