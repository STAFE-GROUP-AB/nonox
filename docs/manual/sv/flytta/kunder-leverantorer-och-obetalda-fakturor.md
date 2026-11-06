---
beskrivning: Så får du in kunder, leverantörer och artiklar i NONOX, och så hanterar du kund- och leverantörsfakturor som är obetalda på flyttdagen.
ordning: 60
roller: [owner, admin, accountant, bookkeeper]
sokord: [kundregister, leverantörsregister, artiklar, obetalda fakturor, kundfordringar, leverantörsskulder, 1510, 2440, gamla fakturor, reskontra, öppna poster]
---

# Kunder, leverantörer och obetalda fakturor

SIE-filen innehåller bokföringen men inte registren, och inte fakturorna som fakturor. Det här är hur du tar hand om dem.

## Registren

**Från Fortnox** hämtar NONOX kunder, leverantörer och artiklar åt dig; se [Från Fortnox](/manual/flytta/fran-fortnox).

**Från andra program** lägger du in dem i NONOX:

- Börja med de kunder du fakturerar oftast och de leverantörer du betalar oftast. Resten lägger du in när de behövs.
- Under **Register → Kunder**, **Register → Leverantörer** och **Register → Artiklar**. Se [Kunder](/manual/fakturering/kunder).
- Har installationen **Hämta uppgifter** hämtas namn och adress från Bolagsverket med organisationsnumret, så du slipper skriva av dem.

Har ni en utvecklare kan registren också läggas in med NONOX öppna API; se [NONOX API](/manual/installningar/nonox-connect).

## Obetalda kundfakturor

En kundfaktura som är obetald på flyttdagen finns redan med i bokföringen: den ligger som saldo på kontot **1510 Kundfordringar** genom SIE-filen. Du behöver inte skapa om den i NONOX.

När kunden betalar bokför du betalningen som en verifikation mot 1510:

| Konto | Debet | Kredit |
|---|---|---|
| 1930 Företagskonto/checkkonto/affärskonto | belopp | |
| 1510 Kundfordringar | | belopp |

Se [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation). Fakturan finns inte som faktura i NONOX, så betalningen kan inte matchas mot den automatiskt; bokför den för hand.

Alla nya fakturor skapar du i NONOX, med nytt OCR-nummer och nytt fakturanummer. Se [Skapa en faktura](/manual/fakturering/skapa-en-faktura).

## Obetalda leverantörsfakturor

På samma sätt ligger obetalda leverantörsfakturor som saldo på **2440 Leverantörsskulder**. När du betalar en av dem bokför du betalningen mot 2440:

| Konto | Debet | Kredit |
|---|---|---|
| 2440 Leverantörsskulder | belopp | |
| 1930 Företagskonto/checkkonto/affärskonto | | belopp |

Nya leverantörsfakturor registrerar du i NONOX. Se [Registrera en leverantörsfaktura](/manual/leverantorer/registrera-en-leverantorsfaktura).

> [!TIP]
> Spara listan över obetalda fakturor från flyttdagen. När saldot på 1510 och 2440 har gått ner till det som hör till fakturor skapade i NONOX, är alla gamla fakturor betalda.

> [!NOTE]
> Använder företaget kontantmetoden bokförs fakturor först när de betalas, så obetalda fakturor ligger normalt inte som saldo på 1510 och 2440. Fråga då din redovisningskonsult hur betalningarna av de gamla fakturorna ska bokföras.

## Varför inte skapa om de gamla fakturorna?

En faktura som skapas i NONOX bokförs. Skapar du om en faktura som redan finns i den inlästa bokföringen blir den bokförd två gånger. Att låta de gamla fakturorna betalas klart mot saldot är enklare och säkrare, och bokföringen blir exakt densamma.

## Läs också

- [Stäm av](/manual/flytta/stam-av)
- [Kunder](/manual/fakturering/kunder)
- [Inbetalningar](/manual/fakturering/inbetalningar)
- [Från Fortnox](/manual/flytta/fran-fortnox)
