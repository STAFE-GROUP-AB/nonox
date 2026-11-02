---
beskrivning: Se vilka kunder som är skyldiga vad, följ upp förfallna fakturor och stäm av kundfordringarna. Och vad du gör när en kund inte betalar.
ordning: 150
roller: [owner, admin, accountant, bookkeeper]
sokord: [kundreskontra, reskontra, obetalda fakturor, kundfordringar, 1510, förfallna, kundförlust, osäker fordran, avskriva faktura, inkasso]
---

# Obetalda fakturor och kundreskontran

Kundreskontran är listan över vad dina kunder är skyldiga dig. Det obetalda syns på flera ställen, var och ett med en länk vidare till fakturan, och under **Bokföring → Reskontra** ser du reskontran en viss dag jämförd med konto 1510.

![Fakturalistan filtrerad på obetalda fakturor, med förfallodag och status.](/bilder/manual/fakturering/obetalda-fakturor.webp)

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

1. Öppna **Bokföring → Reskontra**, välj **Kundreskontra (1510)** och dagen du vill kontrollera. Sidan visar fakturorna som var obetalda den dagen, summan, saldot på 1510 samma dag och differensen. Leverantörsreskontran mot 2440 väljer du i samma lista.
2. Står det *Reskontran stämmer med kontot* är du klar.
3. Stämmer det inte, leta efter verifikationer på 1510 som inte kommer från en faktura eller inbetalning, till exempel en betalning som bokförts för hand. Läs [Kontoanalys och huvudbok](/manual/rapporter/kontoanalys-och-huvudbok).

Reskontran byggs upp från fakturans datum och datumen för betalningar, kreditfakturor och kundförluster, så den visar samma sak för ett datum även när senare betalningar har kommit in.

Med kontantmetoden finns inga kundfordringar i bokföringen under året, bara i fakturalistan.

## När en kund inte betalar

NONOX har ingen inkassofunktion. **Inkasso** sköter du med ett inkassobolag utanför NONOX.

**En faktura som aldrig kommer att betalas** ska inte krediteras – en kreditfaktura betyder att kunden aldrig var skyldig något. Skriv i stället bort den som kundförlust:

1. Öppna fakturan och gå till **Kundförlust**.
2. Fyll i **Bokförs den** och **Varför fordringen är förlorad**, till exempel *Kunden försatt i konkurs*.
3. Tryck på **Skriv bort** och bekräfta.

NONOX bokför en verifikation i serie B: 1510 Kundfordringar minskar med det som var obetalt, momsen på den delen dras tillbaka från utgående moms (till exempel 2611), och resten bokförs som förlust på 6351 Konstaterade förluster på kundfordringar. Fakturan får status *Kundförlust* och räknas inte längre som obetald, så reskontran fortsätter att stämma med 1510. Med kontantmetoden bokförs ingenting, eftersom varken intäkten eller momsen var bokförd; fakturan stängs bara.

Betalar kunden ändå senare, registrerar du betalningen på fakturan som vanligt. Momsen på det som kommer in redovisas då igen, och resten minskar förlusten på 6351.

> [!IMPORTANT]
> När en fordran räknas som en konstaterad kundförlust bestäms av god redovisningssed och Skatteverkets regler (mervärdesskattelagen 8 kap. 16 § och 7 kap. 43–44 §§). Befarade förluster, som ännu inte är konstaterade, bokför du för hand. Kontrollera alltid med din redovisningskonsult. Funktionen är kontrollerad enligt en granskning 2026-11-02; juristgranskning återstår.

## Vanliga frågor

**Kan jag ta ut en lista över alla obetalda fakturor per ett visst datum?**
Ja, under **Bokföring → Reskontra**. Välj dagen, så visas fakturorna som var obetalda då.

**Fakturan står som obetald fast kunden har betalat.**
Betalningen är inte registrerad. Registrera den på fakturan eller läs in bankens fil. Läs [Inbetalningar](/manual/fakturering/inbetalningar).

## Läs också

- [Inbetalningar](/manual/fakturering/inbetalningar)
- [Påminnelser](/manual/fakturering/paminnelser)
- [Kontoanalys och huvudbok](/manual/rapporter/kontoanalys-och-huvudbok)
- [Ordlista](/manual/ordlista/ordlista)
