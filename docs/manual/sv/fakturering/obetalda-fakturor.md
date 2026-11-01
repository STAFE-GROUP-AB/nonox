---
beskrivning: Se vilka kunder som är skyldiga vad, följ upp förfallna fakturor och stäm av kundfordringarna. Och vad du gör när en kund inte betalar.
ordning: 150
roller: [owner, admin, accountant, bookkeeper]
sokord: [kundreskontra, reskontra, obetalda fakturor, kundfordringar, 1510, förfallna, kundförlust, osäker fordran, avskriva faktura, inkasso]
---

# Obetalda fakturor och kundreskontran

Kundreskontran är listan över vad dina kunder är skyldiga dig. NONOX har ingen separat reskontrasida; i stället syns det obetalda på flera ställen, var och ett med en länk vidare till fakturan.

## Var du ser det obetalda

- **Översikt** – nyckeltalet **Obetalda kundfakturor** visar hur många som är obetalda och hur många som har förfallit. Under **Att göra** står *… kundfakturor har förfallit* med summan, eller en enskild förfallen faktura med kund och belopp.
- **Försäljning → Fakturor** – nyckeltalet **Obetalt hos kunder** och filtren **Obetalda** och **Förfallna**. Listan visar förfallodag och belopp för varje faktura.
- **Register → Kunder** – kolumnen **Obetalt** visar vad varje kund är skyldig just nu.
- **Sök** – skriv till exempel *obetald* eller ett fakturanummer i sökningen. Läs [Sök](/manual/kom-igang/sok).

## Så här följer du upp förfallna fakturor

1. Gå till **Försäljning → Fakturor** och välj **Förfallna**.
2. Öppna fakturan och kontrollera under **Betalningar** att ingen betalning saknas. Har pengarna kommit, registrera dem. Läs [Inbetalningar](/manual/fakturering/inbetalningar).
3. Är fakturan fortfarande obetald, skicka en påminnelse med **Skicka påminnelse**. Läs [Påminnelser](/manual/fakturering/paminnelser).

## Stäm av kundfordringarna

Med fakturametoden ska saldot på konto 1510 Kundfordringar vara lika med summan av det som är obetalt på dina fakturor (ROT- och RUT-delen ligger för sig på 1513). Det är en bra kontroll inför ett momsbokslut eller ett årsbokslut.

1. Öppna **Bokföring → Rapporter** och ta fram kontoanalysen för konto 1510 per det datum du vill kontrollera. Läs [Kontoanalys och huvudbok](/manual/rapporter/kontoanalys-och-huvudbok).
2. Jämför saldot med **Obetalt hos kunder**.
3. Stämmer det inte, leta efter verifikationer på 1510 som inte kommer från en faktura eller inbetalning, till exempel en betalning som bokförts för hand.

Med kontantmetoden finns inga kundfordringar i bokföringen under året, bara i fakturalistan.

## När en kund inte betalar

NONOX har ingen inkassofunktion och ingen knapp för att skriva bort en faktura som kundförlust.

- **Inkasso** sköter du med ett inkassobolag utanför NONOX.
- **En faktura som aldrig kommer att betalas** ska inte krediteras – en kreditfaktura betyder att kunden aldrig var skyldig något. En konstaterad kundförlust bokför du i stället med en vanlig verifikation, och fakturan står kvar som obetald i NONOX.

> [!IMPORTANT]
> När en fordran räknas som en konstaterad kundförlust, och när momsen på den får dras av, bestäms av Mervärdesskattelagen och Skatteverkets regler. Kontrollera alltid med din redovisningskonsult innan du bokför en kundförlust. Läs [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation).

## Vanliga frågor

**Kan jag ta ut en lista över alla obetalda fakturor per ett visst datum?**
Filtret **Obetalda** visar läget just nu. För ett tidigare datum använder du kontoanalysen för 1510.

**Fakturan står som obetald fast kunden har betalat.**
Betalningen är inte registrerad. Registrera den på fakturan eller läs in bankens fil. Läs [Inbetalningar](/manual/fakturering/inbetalningar).

## Läs också

- [Inbetalningar](/manual/fakturering/inbetalningar)
- [Påminnelser](/manual/fakturering/paminnelser)
- [Kontoanalys och huvudbok](/manual/rapporter/kontoanalys-och-huvudbok)
- [Ordlista](/manual/ordlista/ordlista)
