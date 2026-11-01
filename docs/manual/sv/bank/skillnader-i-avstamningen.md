---
beskrivning: Bankavstämningen stämmer inte? Så letar du upp skillnaden, rättar den med en verifikation och sparar en avstämning med förklaring när något återstår.
ordning: 60
roller: [owner, admin, accountant, bookkeeper]
sokord: [skillnad, differens, avstämning stämmer inte, bankavgift, saknad betalning, dubbelbokning, förklaring]
---

# Skillnader i bankavstämningen

Står det ett belopp i rutan **Skillnad** när du stämmer av banken finns det något som banken och bokföringen inte är överens om. Här är de vanligaste orsakerna och hur du hittar dem.

## Så här letar du upp skillnaden

1. **Kontrollera saldot och datumet.** Är **Datum på kontoutdraget** samma dag som saldot du skrev av? Står det minus framför ett negativt saldo?
2. **Bocka för det som inte har nått banken.** Gå igenom raderna i listan och bocka i **Ej hos banken** på betalningar du har bokfört men som banken drar senare.
3. **Leta efter beloppet.** Finns en rad i listan, eller en transaktion på kontoutdraget, med exakt samma belopp som skillnaden? Då är det ofta den som saknas på ena sidan.
4. **Leta efter det dubbla.** Är skillnaden lika stor som en rad i listan kan raden vara bokförd två gånger. Är den dubbelt så stor som en rad kan raden vara bokförd med fel tecken – insättning i stället för uttag.
5. **Jämför transaktion för transaktion.** Med [bankkopplingen](/manual/bank/matcha-transaktioner) ser du under **Bank** vilka transaktioner som **Saknas i bokföringen**. Utan koppling jämför du kontoutdraget med listan rad för rad.

## Vanliga orsaker

| Orsak | Så rättar du |
|---|---|
| Bankavgift, ränta eller kortavgift som inte är bokförd | Bokför den med en verifikation, med kontoutdraget som underlag. |
| Kundbetalning som inte är registrerad | Registrera den på fakturan eller läs in bankens fil. Läs [Inbetalningar](/manual/fakturering/inbetalningar). |
| Leverantörsbetalning som inte är bokförd | Bokför betalningen. Läs [Betalfiler](/manual/leverantorer/betalfiler). |
| Betalning bokförd på fel konto, till exempel 1940 i stället för 1930 | Rätta verifikationen. Läs [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation). |
| Verifikation bokförd två gånger, eller med fel belopp | Rätta den med en rättelseverifikation. |
| Betalning bokförd i en annan månad än banken drog den | Bocka i **Ej hos banken**, eller kontrollera datumet. |
| Ingående saldot är fel | Kontrollera årets ingående balans på kontot. Läs [Räkenskapsår](/manual/bokforing/rakenskapsar). |

En bokförd verifikation kan aldrig ändras eller tas bort i NONOX. Det som är fel rättar du alltid med en ny verifikation, och båda syns i bokföringen.

## När du har rättat

Bokför rättelsen med ett datum till och med avstämningsdatumet. Då kommer den med i **Saldo i bokföringen** när du öppnar avstämningen igen, och skillnaden blir mindre.

## Spara med en skillnad

Ibland går skillnaden inte att reda ut på en gång, till exempel när du väntar på ett underlag från banken. Då kan du ändå spara avstämningen:

1. Skriv vad skillnaden beror på i **Förklaring till skillnaden**, till exempel *Bankavgift 75 kr, underlag saknas*.
2. Tryck på **Spara avstämningen**.

Utan förklaring sparas inte avstämningen; du får beskedet *Förklara skillnaden på … innan avstämningen sparas.* Under **Tidigare avstämningar** syns skillnaden, och förklaringen visas när du håller muspekaren över beloppet. Kontot är märkt *med skillnad* tills en senare avstämning stämmer.

> [!WARNING]
> Bokför inte skillnaden mot ett "skräpkonto" bara för att få avstämningen att stämma. Hitta orsaken och bokför den. Är du osäker, fråga din redovisningskonsult.

## Läs också

- [Bankavstämning](/manual/bank/bankavstamning)
- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
- [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation)
- [Matcha banktransaktioner](/manual/bank/matcha-transaktioner)
