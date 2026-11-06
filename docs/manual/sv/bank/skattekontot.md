---
beskrivning: Hämta saldot och transaktionerna på skattekontot från Skatteverket, stäm av konto 1630 och bokför det som saknas, en transaktion i taget.
ordning: 55
roller: [owner, admin, accountant, bookkeeper, auditor]
rutter: [accounting.taxAccount]
sokord: [skattekonto, skattekontot, 1630, avstämning skattekonto, skatteverket, saldo skattekonto, intäktsränta, kostnadsränta, utbetalningsspärr, kronofogden]
---

# Skattekontot

Konto 1630 i bokföringen ska ha samma saldo som företagets skattekonto hos Skatteverket. Under **Bokföring → Skattekontot** hämtar NONOX saldot och transaktionerna direkt från Skatteverket, ställer dem bredvid 1630 och visar vad som saknas i bokföringen. Det som saknas bokför du en transaktion i taget, och bara när du själv har bekräftat det.

Sidan är märkt **Beta**. Den visas bara när installationen är kopplad till Skatteverket.

> [!NOTE]
> På nonox.app är kopplingen inte påslagen än. Den är byggd mot Skatteverkets testtjänst och provas där av ett fåtal pilotanvändare. Till dess stämmer du av 1630 mot skattekontot på Mina sidor hos Skatteverket.

## Så här stämmer du av

1. Öppna **Bokföring → Skattekontot**.
2. Klicka på **Logga in hos Skatteverket med e-legitimation**. Du loggar in hos Skatteverket, inte i NONOX, till exempel med BankID. Inloggningen gäller i ungefär en timme. Gäller din inloggning bara momsdeklarationen eller arbetsgivardeklarationen behöver du logga in igen; NONOX ber då om tillgång till alla tjänsterna.
3. Välj **Avstämningsdag** och **Transaktioner från och med**. Från början är avstämningsdagen i dag och startdagen den första dagen i räkenskapsåret.
4. Klicka på **Hämta från Skatteverket**. Du kan hämta fem gånger i minuten; sedan ber NONOX dig vänta en minut.

Är avstämningsdagen i dag visar Skatteverket saldot när skattekontot senast uppdaterades, och NONOX jämför med 1630 den dagen. Ett tidigare datum ger saldot den dagen. Skatteverket visar transaktioner högst 915 dagar bakåt.

Från bokslutets checklista, punkten **Skattekontot är avstämt**, kommer du hit med räkenskapsårets sista dag som avstämningsdag.

## Saldot

Rutan **Saldo** visar tre belopp bredvid varandra:

- **Saldo hos Skatteverket**: skattekontots saldo. Minus betyder att företaget är skyldigt pengar.
- **Saldo på 1630 i NONOX**: saldot på konto 1630 samma dag, med ingående balans.
- **Skillnad**: 1630 minus Skatteverkets saldo. Står det **Konto 1630 stämmer med skattekontot hos Skatteverket** är du klar.

Under beloppen står **Preliminär ränta hos Skatteverket**, **Saldo hos Kronofogden**, **OCR-nummer för inbetalning**, **Nästa avstämningsdag** och när skattekontot uppdaterades. Den preliminära räntan är inte bokförd på skattekontot än och ingår inte i saldot. En skuld som har lämnats till Kronofogden visas för sig och jämförs inte med 1630.

## Transaktionerna

Tabellen **Transaktioner hos Skatteverket** visar varje transaktion och om den finns i bokföringen:

- **Bokförd (A12)**: en verifikation ändrar 1630 med samma belopp samma dag (transaktionsdagen eller ränteberäkningsdagen).
- **Bokförd tillsammans med dagens andra transaktioner (L4)**: flera transaktioner samma dag är tillsammans en verifikation. Det är vanligt för arbetsgivardeklarationen, där skattekontot visar avdragen skatt och arbetsgivaravgift för sig men lönen bokför dem på en rad mot 1630.
- **Bokförd från skattekontot (A13)**: du har bokfört den här.
- **Saknas i bokföringen**: NONOX hittar ingen verifikation för den.
- **Bokförs för hand**: transaktionen gäller Kronofogden eller har inget belopp hos Skatteverket.

NONOX jämför försiktigt: bara exakt samma belopp och samma dag räknas. Är något bokfört en annan dag, till exempel en arbetsgivardeklaration som bokfördes på löndagen, står transaktionen som **Saknas i bokföringen** och verifikationen under **Bokfört på 1630 men inte på skattekontot**. Titta på båda listorna innan du bokför något.

**Kommande transaktioner** är beslutade skatter och avgifter som Skatteverket bokför på förfallodagen, till exempel nästa månads preliminärskatt. De har inte påverkat saldot än och bokförs inte nu. **Utbetalningsspärrar** är spärrar som företaget själv har begärt, så att pengar står kvar på skattekontot.

## Bokför det som saknas

1. Välj konto i listan bredvid transaktionen. De vanligaste kontona står först.
2. Klicka på **Bokför** och läs vad som händer.
3. Klicka på **Ja, bokför**.

NONOX bokför en verifikation i serie A, daterad den dag transaktionen kom upp på skattekontot: 1630 mot kontot du valde. Beskrivningen är Skatteverkets text, till exempel **Skattekontot: Moms dec 2025**. Verifikationen går inte att ändra efteråt, bara [rätta](/manual/bokforing/ratta-en-verifikation). Samma transaktion kan aldrig bokföras två gånger. Har du rättat verifikationen står transaktionen som **Saknas i bokföringen** igen, med **Bokfördes tidigare som …, som sedan har rättats.**, och du kan bokföra den på nytt. Den rättade verifikationen och en rättelse som bara vänder den räknas inte i avstämningen.

För vissa texter föreslår NONOX ett konto, och skriver **NONOX föreslår konto … utifrån Skatteverkets text. Kontrollera det.** Förslagen bygger på texterna i Skatteverkets egna exempel:

| Skatteverkets text | Förslag |
|---|---|
| Inbetalning bokförd … | 1930 Företagskonto |
| Moms … (till exempel Moms okt 2025) | 2650 Redovisningskonto för moms |
| Arbetsgivaravgift … (med månad) | 2730 eller 2731, se nedan |
| Avdragen skatt … (med månad) | 2710 Personalskatt |
| Debiterad preliminärskatt | 2518 Betald F-skatt |
| Intäktsränta | 8314 Skattefria ränteintäkter |

Ett konto förväljs bara när det finns och är aktivt i företagets kontoplan. Annars står det **I BAS bokförs det här vanligen på konto …, som inte finns i kontoplanen. Välj konto själv.** 2518 finns till exempel inte i den kontoplan NONOX lägger upp från början; lägg till kontot i [kontoplanen](/manual/bokforing/kontoplan) om du vill använda det.

För arbetsgivaravgiften väljer NONOX det konto där skulden faktiskt ligger. Lönemodulen bokför arbetsgivaravgifterna som skuld på 2730, så med lönemodulen påslagen och 2730 i kontoplanen föreslås 2730. Annars föreslås det av 2730 och 2731 som finns i kontoplanen, och finns båda det som har ett kreditsaldo (en skuld att kvitta). Säger saldona inget föreslås inget konto.

Debiterad preliminärskatt och intäktsränta föreslås bara för aktiebolag, föreningar och stiftelser. I en enskild firma eller ett handelsbolag är skatten ägarnas egen, och då väljer du konto själv. Kostnadsränta, slutlig skatt och allt annat väljer du också själv, till exempel 8423 Räntekostnader för skatter och avgifter för kostnadsränta.

> [!IMPORTANT]
> Underlaget för verifikationen är skattekontoutdraget. Spara utdraget från Mina sidor hos Skatteverket som underlag till verifikationen.

## Vad sparas?

Uppgifterna från Skatteverket sparas inte i NONOX: de hämtas när du klickar och visas. Bara när du bokför en transaktion sparas Skatteverkets id för den, datumet, beloppet och kontot, så att den aldrig bokförs två gånger. Det står också i [behandlingshistoriken](/manual/bokforing/behandlingshistorik).

Uppgifterna får bara hämtas för bokföring, redovisning och uppföljning, och bara av den som företräder företaget eller har fått företagets uppdrag att göra det. Skatteverket kontrollerar din behörighet vid varje hämtning. Skatteverket loggar alla anrop från NONOX (auditloggning) och sparar loggarna i upp till 5 år för att förebygga och utreda säkerhetsincidenter. **Logga ut från Skatteverket** avslutar inloggningen i NONOX direkt.

## Vem får göra vad?

- Den som får se rapporter, till exempel en revisor, kan hämta skattekontot och se avstämningen.
- Den som får bokföra verifikationer kan också bokföra det som saknas.
- Hos Skatteverket behöver du företräda företaget eller vara ett ombud som företaget har utsett och som är registrerat hos Skatteverket. Annars svarar Skatteverket att du saknar behörighet.

## Vanliga frågor

**Skatteverket säger att tjänsten inte går att nå.** Skattekontot har ett servicefönster klockan 03.00–05.00. Försök igen senare.

**Varför syns inte bankkontot för återbetalningar?** NONOX hämtar inte utbetalningskontot. Det behövs inte för att stämma av 1630.

## Läs också

- [Bankavstämning](/manual/bank/bankavstamning) – stäm av bankkontot på samma sätt.
- [Momsdeklarationen](/manual/moms/momsdeklaration) – momsen som sedan dras på skattekontot.
- [Arbetsgivardeklaration](/manual/lon/arbetsgivardeklaration) – skatt och avgifter för lönerna.
