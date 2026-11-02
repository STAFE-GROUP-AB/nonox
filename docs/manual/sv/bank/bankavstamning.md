---
beskrivning: Stäm av ett bank- eller kassakonto mot kontoutdraget steg för steg – med saldot du skriver in eller med bankens saldo från bankkopplingen.
ordning: 50
roller: [owner, admin, accountant, bookkeeper]
rutter: [accounting.bankReconciliation]
sokord: [bankavstämning, avstämning, stämma av, kontoutdrag, 1930, kassa, saldo, månadsavstämning]
---

# Bankavstämning

En bankavstämning visar att saldot på bankkontot i bokföringen är detsamma som saldot hos banken. Gör den varje månad: en skillnad är nästan alltid något som saknas eller är bokfört fel, och den är mycket lättare att hitta medan månaden är färsk.

Avstämningen finns under **Bokföring → Bankavstämning**. Den fungerar för alla bank- och kassakonton i kontoplanen mellan 1900 och 1999.

![Bankavstämningen för företagskontot 1930: saldot enligt kontoutdraget mot bokföringen.](/bilder/manual/bank/bankavstamning.webp)

## Det du behöver

- Kontoutdraget från banken, eller saldot per den sista dagen i månaden. Med [bankkopplingen](/manual/bank/bankkoppling) behövs inget kontoutdrag – NONOX har bankens saldo.
- Att månadens verifikationer är bokförda, även kundinbetalningar och leverantörsbetalningar.

## Så här gör du

1. Gå till **Bokföring → Bankavstämning**.
2. Välj kontot överst, till exempel **1930**. Under varje konto står när det senast stämdes av, eller *Inte avstämt*.
3. Kontrollera **Datum på kontoutdraget**. Från början är det sista dagen i förra månaden.
4. Skriv saldot i **Saldo enligt kontoutdraget, kr**, till exempel *125 000,00*. Är banken kopplad trycker du i stället på **Använd bankens saldo** (se nedan).
5. Titta på de fyra rutorna:
   - **Saldo i bokföringen** – kontots saldo per datumet.
   - **Inte hos banken ännu** – summan av raderna du har bockat för.
   - **Ska stå på kontoutdraget** – bokföringen minus det som inte syns hos banken än.
   - **Skillnad** – kontoutdraget minus det som ska stå där. Står det **Stämmer** är du klar.
6. Finns en skillnad, gå igenom raderna i listan. Bocka i **Ej hos banken** på det som är bokfört men ännu inte syns på kontoutdraget, till exempel en betalning du bokförde den 30:e som banken drar den 2:a.
7. Finns det fortfarande en skillnad, skriv vad den beror på i **Förklaring till skillnaden**. Läs [Skillnader i bankavstämningen](/manual/bank/skillnader-i-avstamningen).
8. Tryck på **Spara avstämningen**.

Du ser *Konto 1930 stämmer per …. Avstämningen är sparad.*, eller att den är sparad med en skillnad. Avstämningen hamnar under **Tidigare avstämningar** med saldon, antal rader som inte var hos banken, skillnaden och vem som gjorde den.

## Raderna i listan

Listan visar det som är bokfört på kontot **sedan förra avstämningen** (eller sedan räkenskapsårets början, första gången), plus de rader som förra avstämningen markerade som *Ej hos banken*. Varje rad har datum, en länk till verifikationen, text och belopp.

Det du bockar för som **Ej hos banken** följer med till nästa avstämning och är förbockat där. När raden har kommit med på kontoutdraget tar du bort bocken.

## Med bankkopplingen

Är bankkontot kopplat med modulen Bank, och hör det till kontot du stämmer av, visas en ruta med **Saldo enligt banken** för datumet.

- Har banken rapporterat ett saldo just den dagen visas det.
- Annars räknar NONOX fram saldot från närmaste saldo banken har rapporterat och transaktionerna däremellan, och skriver *Räknat från bankens saldo … och transaktionerna däremellan.*

Tryck på **Använd bankens saldo**. Saldot fylls i, och de bokförda raderna som ingen transaktion från banken är matchad mot bockas i som **Ej hos banken**. Kontrollera förslaget och spara som vanligt. Ju fler transaktioner du har matchat, desto bättre blir förslaget. Läs [Matcha banktransaktioner](/manual/bank/matcha-transaktioner).

## Påminnelse

Under **Att göra** på Översikt står **Stäm av företagskontot för …** när konto 1930 inte är avstämt till och med slutet av förra månaden.

## Det här händer i bokföringen

Ingenting. Avstämningen sparas och skrivs i behandlingshistoriken, men den bokför inget. Det som behöver rättas bokför du med en verifikation.

## Vanliga frågor

**Jag får "Datumet ligger inte i något räkenskapsår."**
Avstämningsdatumet måste ligga i ett räkenskapsår i NONOX. Läs [Räkenskapsår](/manual/bokforing/rakenskapsar).

**Mitt bankkonto syns inte bland kontona.**
Bara aktiva konton mellan 1900 och 1999 visas. Lägg till eller aktivera kontot i [kontoplanen](/manual/bokforing/kontoplan).

**Jag sparade en avstämning med fel saldo.**
Gör en ny avstämning för samma datum med rätt saldo och spara den. Den senaste avstämningen är den som gäller, och båda syns i historiken.

**Vem kan stämma av?**
Den som får bokföra verifikationer: ägare, administratör, redovisningskonsult och bokförare.

## Läs också

- [Skillnader i bankavstämningen](/manual/bank/skillnader-i-avstamningen)
- [Matcha banktransaktioner](/manual/bank/matcha-transaktioner)
- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
- [Bokslut och årsredovisning](/manual/bokslut)
