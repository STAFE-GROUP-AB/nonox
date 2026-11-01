---
beskrivning: En checklista för att kontrollera att flytten är komplett – saldobalans per år, antal verifikationer, kundfordringar, leverantörsskulder, banken och lönen.
ordning: 80
roller: [owner, admin, accountant]
sokord: [stämma av, avstämning, kontrollera flytt, saldobalans, jämföra, kontroll, stämmer inte, verifiera flytt]
---

# Stäm av

När allt är inläst jämför du några siffror med det gamla programmet. Stämmer de, stämmer allt. Avstämningen tar ungefär en halvtimme och är ditt kvitto på att flytten är komplett. Spara gärna en kopia av det du jämför.

NONOX har redan jämfört varje konto med SIE-filen när den lästes in, och läser inte in något som inte stämmer. Avstämningen här kontrollerar att du har fått med **allt**: alla år, alla verifikationer och det som inte finns i SIE-filen.

## Checklista

### 1. Saldobalansen för varje år

Ta fram saldobalansen per räkenskapsår under **Bokföring → Rapporter** och jämför med saldobalansen i det gamla programmet. Varje konto ska ha samma ingående balans, samma rörelse under året och samma utgående balans. Se [Kontoanalys och huvudbok](/manual/rapporter/kontoanalys-och-huvudbok).

### 2. Antalet verifikationer

Jämför antalet verifikationer per serie och år. Rapporten efter inläsningen visar hur många som lästes in. Fick serierna nya nummer i NONOX finns filens ursprungliga nummer sparade med varje verifikation.

### 3. Mellan åren

Har du läst in flera år: kontrollera att varje års utgående balanser är samma som nästa års ingående balanser.

### 4. Kundfordringar och leverantörsskulder

Saldot på **1510 Kundfordringar** ska vara samma som summan av obetalda kundfakturor på flyttdagen, och saldot på **2440 Leverantörsskulder** samma som summan av obetalda leverantörsfakturor. Kommer du från Fortnox gör NONOX den jämförelsen åt dig i steg 5 på flyttsidan.

### 5. Banken

Kontrollera att saldot på företagskontot (oftast 1930) stämmer med kontoutdraget på flyttdagen. Gör en bankavstämning i NONOX. Se [Bankavstämning](/manual/bank/bankavstamning).

### 6. Momsen

Kontrollera att momsen för de perioder som redan är deklarerade är densamma i NONOX som i deklarationerna som lämnades.

### 7. Lönen

Om ni har anställda: flytten av lönedata är markerad som klar, och semestersaldona stämmer med det gamla systemet. Se [Flytta lönen](/manual/flytta/flytta-lonen).

### 8. Underlagen

Underlagen för alla år finns sparade, antingen kopplade till verifikationerna (från Fortnox) eller i ditt eget arkiv.

## Om något inte stämmer

- **Ett konto skiljer sig** – kontrollera att alla år är inlästa och att inget har bokförts i det gamla programmet efter exporten.
- **Färre verifikationer** – kontrollera att filen exporterades efter den sista bokföringen i det gamla programmet.
- **1510 eller 2440 skiljer sig** – kontrollera att det senaste året är inläst och att inga fakturor har betalats efter exporten.
- **Banken skiljer sig** – leta efter transaktioner runt flyttdagen som är bokförda i fel system eller inte alls.

Behöver något rättas efter inläsningen görs det med en [rättelseverifikation](/manual/bokforing/ratta-en-verifikation), precis som för allt annat som är bokfört. Ta gärna hjälp av din redovisningskonsult.

## Läs också

- [Efter flytten](/manual/flytta/efter-flytten)
- [Bankavstämning](/manual/bank/bankavstamning)
- [Kontoanalys och huvudbok](/manual/rapporter/kontoanalys-och-huvudbok)
