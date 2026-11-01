---
beskrivning: I vilken ordning du läser in flera räkenskapsår, hur ingående balanser följer med från SIE-filen och vad du gör när NONOX säger att ett år inte ligger intill.
ordning: 40
roller: [owner, admin, accountant]
sokord: [ingående balans, IB, utgående balans, UB, flera år, tidigare år, historik, räkenskapsår, ordning, ligger inte direkt före]
---

# Flera år och ingående balanser

När du flyttar vill du oftast ha med flera års bokföring, så att du kan jämföra med tidigare år och visa historiken för revisorn. Du läser in ett år i taget, en SIE-fil per år, och åren måste hänga ihop.

## I vilken ordning

Räkenskapsåren i NONOX ska ligga i en obruten följd. Därför läser du in åren **närmast först**:

1. När du skapade företaget skapade NONOX det räkenskapsår som pågår. Börja med det året, om du har bokfört i det i det gamla programmet.
2. Läs sedan in året före, och året före det, ett år i taget bakåt.

Försöker du läsa in ett år som inte ligger direkt före eller efter de år som finns, stoppar rapporten och säger att räkenskapsåret **ligger inte direkt före eller efter dina räkenskapsår här**. Läs då in åren däremellan först.

> [!TIP]
> Flyttar du vid årsskiftet och ännu inte har bokfört något i det nya året? Börja med det senast avslutade året, som ligger direkt före det nya, och fortsätt bakåt.

## Ingående balanser

Den **ingående balansen** är saldot på varje balanskonto, till exempel bank, kundfordringar och eget kapital, den första dagen i räkenskapsåret. Se [Ordlista: Ingående balans](/manual/ordlista/ordlista#ingaende-balans).

- **Från SIE-filen.** Varje SIE4-fil innehåller årets ingående balanser. De läses in tillsammans med verifikationerna. Du behöver inte skriva in dem.
- **Inte som verifikationer.** Ingående balanser sparas för sig per år och konto, precis som i SIE-filen. De syns i rapporterna men får inget verifikationsnummer, och följer med när du exporterar en SIE-fil från NONOX.
- **Mellan åren.** Har du läst in ett år och det nästa, ska det tidigare årets utgående balanser vara samma som nästa års ingående. Det kontrollerar du i [avstämningen](/manual/flytta/stam-av).
- **Om de inte stämmer.** Har ett år redan ingående balanser i NONOX som skiljer sig från filens, stoppar rapporten. Det kan hända om du har fört över balanser vid årsskiftet i NONOX innan du läste in filen. Fråga din redovisningskonsult vilka balanser som är rätt.

## Bara det senaste året?

Du behöver inte läsa in alla år. Det räcker med det år du fortsätter i, så får du rätt ingående balanser och hela årets verifikationer. Äldre år är bra att ha för jämförelser i rapporterna, men det viktiga är att du har dem sparade som SIE-filer och arkiv i sju år. Se [Efter flytten](/manual/flytta/efter-flytten).

## Utan SIE-fil

Kan det gamla programmet inte exportera SIE4, eller har du bara papper, finns ingen inläsning av ingående balanser för hand i NONOX i dag. Prata med din redovisningskonsult om hur balanserna ska föras in. Kontrollera först om programmet kan exportera SIE i någon form; nästan alla svenska program kan.

## Vanliga frågor

**Kan jag läsa in ett stängt år?**
Ett år som är stängt i det gamla programmet går bra att läsa in. Ett år som är stängt i NONOX går däremot inte att läsa in något i.

**Det står att filens räkenskapsår överlappar ett år här med andra datum.**
Räkenskapsåret i NONOX har andra första och sista dagar än i filen. Är det företagets första och enda år, ändra datumen under **Bokföring → Räkenskapsår** så att de stämmer med filen, och granska filen igen.

**Vad händer vid nästa årsskifte?**
Då förs de utgående balanserna över som ingående balanser till nästa år i NONOX. Se [Räkenskapsår](/manual/bokforing/rakenskapsar).

## Läs också

- [Från ett annat program](/manual/flytta/fran-andra-program)
- [Byta mitt i året](/manual/flytta/byta-mitt-i-aret)
- [Räkenskapsår](/manual/bokforing/rakenskapsar)
- [SIE-filer](/manual/bokforing/sie)
