---
beskrivning: Lägg in timmar för OB, storhelg och övertid med avtalets lönearter, så räknar NONOX beloppet med avtalets sats och visar version och källa på lönebeskedet.
ordning: 50
roller: [owner, payroll_admin]
modul: agreements
sokord: [OB, OB-tillägg, obekväm arbetstid, natt-OB, storhelg, övertid, övertidsersättning, mertid, avtalets sats, timlönedelare]
---

# OB och övertid enligt avtalet

När företaget är bundet av ett avtal i NONOX finns avtalets egna lönearter för OB, storhelg och övertid, till exempel **OB-tillägg (Gröna Riks)** och **Natt-OB (Gröna Riks)**. Lägger du in timmar med en sådan löneart behöver du inte räkna ut beloppet: NONOX tar avtalets sats för arbetsdagen och räknar fram det.

## Så här lägger du in OB eller övertid

1. Öppna månadens lönekörning under **Lön → Lönekörningar**.
2. Under **Timmar och tillägg**: välj **Anställd**.
3. Välj avtalets **Löneart**, till exempel **OB-tillägg (Gröna Riks)**.
4. Skriv **Antal timmar**. Lämna beloppet tomt.
5. Klicka på **Lägg till** och sedan **Beräkna**.

Timmarna kan också komma från en fil från ert schemasystem. Koppla då filens OB- och övertidskoder till avtalets lönearter, så prissätts raderna på samma sätt med radens eget datum. Se [Löneunderlag från schemasystem](/manual/lon/loneunderlag-fran-schemasystem).

> [!IMPORTANT]
> Timmarna måste redan vara uppdelade per typ: så många timmar OB, så många timmar natt-OB och så vidare. NONOX räknar ännu inte ut OB från arbetspassens klockslag. Det gör många schemasystem, och då är filen det enklaste sättet.

## Så räknas beloppet

Avtalets sats kan ha olika form:

- **Kronor per timme** – till exempel ett fast OB-belopp per timme. Beloppet blir timmar gånger satsen.
- **Procent av timlönen** – till exempel storhelgsersättning. För timavlönade är timlönen anställningens timlön. För månadsavlönade räknas timlönen fram från månadslönen med avtalets delare, eller med avtalets formel för deltid; därför behöver anställningen ha **Arbetstid, timmar per vecka** ifylld.
- **Timlön plus tillägg** – för övertid där avtalet säger att tillägget betalas utöver timlönen.

Satsen tas från det lönesteg som gäller på arbetsdagen: radens datum när den kommer från en fil, annars den första dagen i lönekörningens månad.

## Det här står på lönebeskedet

Varje avtalsrad visar antal timmar gånger satsen och under den en förklaring: **Sats enligt** avtalet, **version från** ett datum, **steg från** ett datum, och **Källa** med dokument och sida. Både du och den anställda kan alltså se varifrån beloppet kommer.

## Det här händer i bokföringen

Avtalets lönearter har samma skatt, arbetsgivaravgift, semestergrundande lön och konton som systemets **OB-tillägg** och **Övertid**. De bokförs med lönen i serie L, som övrig lön. Se [Lönefil till banken och bokföring av lönen](/manual/lon/lonefil-och-bokforing).

## När raden stoppar lönekörningen

En avtalsrad som inte kan prissättas rätt stoppar beräkningen i stället för att betalas med fel belopp. Vanliga skäl:

- **Ingen kontrollerad version av avtalet gäller** för datumet. Se [Versioner och kontroll](/manual/avtal/versioner-och-kontroll).
- **Lönearten hör inte till avtalet** som anställningen är knuten till.
- **OB eller övertid ingår i lönen** enligt anställningens avtalsuppgifter. Ta bort raden eller ändra uppgiften. Se [Avtal, lönegrupp och branschvana per anställd](/manual/avtal/avtal-per-anstalld).
- **Avtalet har ingen sats** för datumet.
- **Timlönen, månadslönen eller veckoarbetstiden saknas** på anställningen.
- **Avtalsdatan saknar delare från månadslön till timlön.** Ange då timpriset på raden via filen, eller belopp för hand.

Utan modulen Avtal prissätts en avtalsart aldrig med hela timlönen. Stänger ni av Avtal med sådana rader i en öppen lönekörning stoppas den tills raderna är borttagna eller har belopp.

## Storhelg och andra dagtyper

Vissa satser gäller bara vissa dagar, till exempel storhelgsersättning på jul- och nyårsafton. Kommer en sådan rad från en fil med ett datum som inte är en sådan dag enligt avtalskalendern får lönekörningen en varning. Kontrollera raden; varningen stoppar inte lönen.

## Vanliga frågor

**Måste jag använda avtalets lönearter?**
Nej. Du kan fortfarande använda systemets **OB-tillägg** och **Övertid** med belopp som du räknat själv. Men då får raden ingen förklaring med källa, och NONOX kan inte kontrollera satsen.

**Kan jag skriva in ett eget belopp på en avtalsrad?**
Ja. Skriver du ett belopp används det, och avtalets sats räknas inte.

**Räknar NONOX mertid för deltidsanställda?**
Inte ännu. Se [Det här gör Avtal inte ännu](/manual/avtal/det-har-gor-avtal-inte-annu).

## Läs också

- [Gör en lönekörning](/manual/lon/lonekorning)
- [Löneunderlag från schemasystem](/manual/lon/loneunderlag-fran-schemasystem)
- [Versioner och kontroll av avtalets värden](/manual/avtal/versioner-och-kontroll)
- [Avtal, lönegrupp och branschvana per anställd](/manual/avtal/avtal-per-anstalld)
