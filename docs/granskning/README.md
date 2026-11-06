# Granskning av NONOX: en checklista per modul

Det här är underlaget för beslut 7 i [`docs/status/manskliga-beslut.md`](../status/manskliga-beslut.md): en namngiven, auktoriserad redovisningskonsult går igenom riktiga fall modul för modul, skriver under, och märkningen Beta tas bort för varje modul som godkänns.

Dokumenten är skrivna för den som granskar. Du behöver inte läsa koden, men varje checklista pekar ut vilken kod och vilket ADR den prövar, så att en avvikelse går att spåra.

## 1. Vem granskar

- En namngiven, auktoriserad redovisningskonsult (eller för lön: en lönekonsult). Namn, titel och auktorisation skrivs i godkännandet längst ned i varje checklista.
- Granskaren ska vara oberoende av den som byggt modulen. Ägaren (Andreas Kviby) väljer granskaren; valet är öppet enligt beslut 7 (senast 2026-12-15).
- Samma person behöver inte granska alla moduler. Varje checklista signeras för sig.

## 2. Moduler och checklistor

| Modul | Checklista | Prövar främst |
|---|---|---|
| Moms, inklusive Skatteverkets testtjänst | [moms.md](moms.md) | ADR-054, ADR-057, `app/Domain/Vat` |
| Lön och arbetsgivardeklaration (AGI), kollektivavtal, OB och övertid, förmåner, drivmedelsförmån | [lon-agi.md](lon-agi.md) | ADR-012, ADR-033, ADR-061, `app/Modules/Payroll` |
| Kundfakturor och kundreskontra | [kundfakturor.md](kundfakturor.md) | ADR-011, `app/Domain/Invoicing` |
| Leverantörsfakturor och betalfiler | [leverantorsfakturor.md](leverantorsfakturor.md) | ADR-013 till ADR-016 |
| Bokslut: K2, spärr mot K3, skatt i bokslutet, periodiseringar, anläggningar | [bokslut.md](bokslut.md) | ADR-056, ADR-059, `app/Domain/Closing` |
| Årsredovisning K2, iXBRL och Arelle, villkor för K2 (BFNAR 2025:2) | [arsredovisning-k2.md](arsredovisning-k2.md) | ADR-058, ADR-059, `app/Domain/AnnualReport` |
| NE-bilaga, INK4, INK2, K10 | [skatt-ne-ink4-ink2-k10.md](skatt-ne-ink4-ink2-k10.md) | ADR-053, ADR-062, `app/Domain/IncomeTax` |
| Företagsguiden | [foretagsguiden.md](foretagsguiden.md) | ADR-051 |
| SIE-import och SIE-export | [sie.md](sie.md) | ADR-008, `app/Support/Sie` |
| Valuta | [valuta.md](valuta.md) | ADR-025, `app/Support/Currency` |

Rekommenderad ordning: moms, kundfakturor, leverantörsfakturor, SIE, valuta, bokslut, årsredovisning, skatt, lön, Företagsguiden. Då är de underlag som senare moduler bygger på granskade först.

## 3. Så går en granskning till

1. **Förbered.** Ägaren ställer i ordning ett testbolag per checklista (förutsättningarna står överst i varje fil) i en databas som inte innehåller riktiga uppgifter. Anteckna versionen av NONOX som granskas (versionsnumret i sidfoten eller `git describe`), och skriv in den i rubriktabellen.
2. **Gå igenom fallen i ordning.** Varje fall har förutsättningar, steg med de riktiga menynamnen, förväntat resultat med uträkning och ett resultatfält. Gör stegen, jämför med de förväntade siffrorna och kryssa *OK* eller *Avvikelse*. Skriv en kommentar vid varje avvikelse.
3. **Förväntade siffror är ett förslag, inte facit.** Siffrorna är beräknade ur reglerna i koden och lagtexten. Där en sats, tröskel eller ett datum är data som är märkt `TODO(legal-verify)` står det *Konsulten verifierar* vid siffran. Då är det granskaren som avgör vad som är rätt, mot källan som anges, och som skriver beslutet i kommentaren. En siffra som stämmer med appen men är fel mot lagen är en avvikelse.
4. **Avgör de öppna `TODO(legal-verify)`.** Varje checklista slutar med en tabell över alla öppna `TODO(legal-verify)` i modulens kod. Granskaren väljer *Godkänd*, *Ändra* eller *Avslag* för var och en. Ett *Ändra* eller *Avslag* är en avvikelse.
5. **Riktiga fall.** Utöver checklistans fall prövas minst tre anonymiserade fall från en riktig klients bokföring för modulen, när sådana finns. Avidentifiera enligt GDPR innan de förs in i NONOX, och skriv in vilka fall som prövades under *Avvikelser*. Inga riktiga uppgifter, personnummer eller bilagor lämnas in i detta arkiv.
6. **Skriv under.** Granskaren fyller i godkännandet längst ned i checklistan.

## 4. Så rapporteras en avvikelse

- Skriv den i tabellen *Avvikelser* i checklistan: fallnummer, vad som hände, vad som borde ha hänt, allvarlighet och förslag till åtgärd.
- Allvarlighet: **A** felaktig bokföring, deklaration eller utbetalning (stoppar godkännande); **B** felaktig men synlig och rättbar utan dataförlust, eller saknad varning; **C** text, format eller bruksfråga.
- Ägaren lägger varje avvikelse som ett ärende på GitHub med etiketten `granskning` och modulens namn, utan kunduppgifter. Ärendet länkar till checklistan och fallnumret.
- Efter rättelse kör granskaren om *just det fallet* och kryssar om i en ny kolumn eller i en kopia av checklistan med datum. Ändras bokföringsbeteende uppdateras även `docs/systemdokumentation/README.md`.

## 5. Hur man skriver under

Underskriften sitter längst ned i varje checklista, under *Godkännande*, och innehåller:

- **Namn** och **titel/auktorisation** (till exempel auktoriserad redovisningskonsult, FAR),
- **Datum**,
- **Version av NONOX som granskats** (samma som i rubriktabellen),
- **Underskrift**,
- ett av tre utfall: *Godkänd*, *Godkänd med villkor* (villkoren anges och måste vara uppfyllda innan märkningen tas bort) eller *Ej godkänd*.

Den påskrivna checklistan skannas och sparas av ägaren utanför det publika arkivet. I arkivet läggs bara en rad: modul, datum, version och utfall (se avsnitt 7). Granskarens personuppgifter förs inte in i arkivet utan samtycke.

## 6. Vad ett godkännande betyder

- Godkännandet gäller **den modulen, den versionen och de fall som prövats**. Det är ingen revision av bolag som använder NONOX och ersätter inte användarens eget ansvar enligt bokföringslagen.
- Märkningen **Beta tas bort för den godkända modulen och ingen annan.** Övriga moduler behåller sin märkning tills de är granskade.
- Godkännandet gäller tills koden för modulens bokföringsbeteende eller regeldata ändras på ett sätt som påverkar ett granskat fall. Då ska berörda fall köras om; ägaren och granskaren avgör omfattningen. Årliga regeländringar (skattetabeller, belopp, avgiftssatser) kräver ny granskning av de fall som använder dem.
- Ett villkorat godkännande tar inte bort märkningen förrän villkoren är uppfyllda och antecknade.
- Alla `TODO(legal-verify)` som granskaren avgjort med *Godkänd* kan stängas i koden. De som blev *Ändra* eller *Avslag* behålls öppna tills de är rättade.

## 7. Register över godkännanden

Ägaren för in en rad per underskriven checklista när den är klar. Tabellen är tom till dess.

| Modul | Granskare (namn) | Datum | NONOX-version | Utfall |
|---|---|---|---|---|
| | | | | |

## 8. Gemensamma förutsättningar

- Testbolag med syntetiska uppgifter. Org.nummer ska vara uppenbart påhittade och godkända av Luhn-kontrollen där fallet kräver det. Inga personnummer.
- Tidszon `Europe/Stockholm`. Belopp i svenska kronor om inget annat anges; i texterna skrivs de som `1 234,50 kr`.
- Kör aldrig mot en databas med riktiga kunders uppgifter, och töm inte en databas för att få en ren start. Skapa ett nytt testbolag i stället.
- Källor som granskaren kan behöva: Bokföringslagen, BFNAR 2013:2, BFNAR 2016:10 (K2), BFNAR 2025:2, ML, SFL, IL, SAL, Skatteverkets tekniska beskrivningar och skattetabeller, Bolagsverkets taxonomi, SIE-formatets specifikation. Reglerna är data i koden och anges med källa per fall.
