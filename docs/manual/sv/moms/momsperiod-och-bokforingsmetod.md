---
beskrivning: Välj hur ofta företaget redovisar moms, och förstå vad fakturametoden och kontantmetoden betyder för när momsen hamnar i deklarationen.
ordning: 20
roller: [owner, admin]
sokord: [momsperiod, redovisningsperiod, månadsmoms, kvartalsmoms, årsmoms, helårsmoms, fakturametoden, kontantmetoden, bokslutsmetoden, momsregistrerad, momsregistreringsnummer]
---

# Momsperiod och bokföringsmetod

Två inställningar styr momsen i NONOX: hur ofta företaget redovisar moms, och om företaget bokför enligt fakturametoden eller kontantmetoden. Båda finns under företagets uppgifter, och båda ska stämma med det Skatteverket har beslutat för företaget.

![Momsredovisning, bokföringsmetod och regelverk under företagets uppgifter.](/bilder/manual/moms/momsperiod-och-bokforingsmetod.webp)

## Så här ändrar du inställningarna

1. Öppna **Företagsinställningar** och fliken **Uppgifter och personer**.
2. Under **Moms och bokföring**, välj **Momsredovisning**: **Varje månad**, **Varje kvartal**, **En gång per år** eller **Inte momsregistrerad**.
3. Välj **Bokföringsmetod**: **Fakturametoden** eller **Kontantmetoden**.
4. Kryssa i **Omsättningen är över 40 miljoner kronor om året (andra deklarationsdagar för moms)** om det gäller företaget.
5. Klicka på **Spara**.

Bara ägare och administratörer kan ändra uppgifterna. Är du osäker på vad som gäller, titta i besluten från Skatteverket eller fråga din redovisningskonsult.

## Momsperioden

Momsperioden avgör vilka perioder du ser under **Bokföring → Moms**:

- **Varje månad** – en deklaration per kalendermånad.
- **Varje kvartal** – en per kalenderkvartal.
- **En gång per år** – en för hela räkenskapsåret.
- **Inte momsregistrerad** – ingen momsdeklaration. Sidan **Moms** säger att företaget inte är momsregistrerat.

När företaget är momsregistrerat bildar NONOX momsregistreringsnumret av organisationsnumret. Det används på fakturor och i EU-rapporterna.

Byter du momsperiod gäller den nya indelningen för perioderna på sidan **Moms**. Perioder som redan är bokförda behåller sina rutor.

### Brutet räkenskapsår

Månader och kvartal är alltid hela kalendermånader och kalenderkvartal, även när räkenskapsåret är brutet. Börjar räkenskapsåret den 1 maj blir april–juni ett kvartal som hör till båda räkenskapsåren. Det är **en** deklaration, med kvartalets sista dag i deklarationsdagen och i filen till Skatteverket.

Kvartalet syns under båda räkenskapsåren på sidan **Moms**, med samma rutor. När du bokför momsen hamnar verifikationen på kvartalets sista dag, alltså i det räkenskapsår som den dagen hör till. Bokslutets checklista för det första året kräver inte kvartalet, eftersom det slutar efter årets slut; momsen för årets sista månad ligger kvar som en skuld på momskontot vid bokslutet.

Med **En gång per år** följer perioden räkenskapsåret, eftersom det är företagets beskattningsår.

> [!NOTE]
> Enligt granskning 2026-11-02, juristgranskning återstår (AUD-MOMS-6, #59).

## Fakturametoden och kontantmetoden

Bokföringsmetoden avgör **när** fakturor bokförs, och därmed i vilken momsperiod momsen hamnar.

| | Fakturametoden | Kontantmetoden |
|---|---|---|
| Kundfaktura | Bokförs när den skapas. Momsen hamnar i fakturans period. | Bokförs när kunden betalar. Momsen hamnar i betalningens period. |
| Leverantörsfaktura | Bokförs när den registreras, på fakturadatumet. | Bokförs när du betalar den. |

Med kontantmetoden visas på en registrerad leverantörsfaktura **Vid betalning (kontantmetoden)** i stället för en verifikation, och betalningen bokför både kostnaden och momsen.

> [!IMPORTANT]
> Med kontantmetoden ska obetalda kund- och leverantörsfakturor bokas upp vid räkenskapsårets slut. NONOX gör inte det automatiskt ännu. Bokför dem med en verifikation på årets sista dag och fråga din redovisningskonsult hur.

## Vanliga frågor

**Kan vi byta bokföringsmetod mitt i året?**
Inställningen går att ändra, men den påverkar bara fakturor som bokförs efter bytet. Om och när ett företag får byta metod avgör reglerna, inte NONOX. Prata med din redovisningskonsult först.

**Vilka deklarationsdagar gäller för oss?**
Översikten visar sista dag för månads- och kvartalsmoms. Kryssrutan för omsättning över 40 miljoner kronor ändrar vilka dagar som visas. För helårsmoms visas ingen dag.

## Läs också

- [Momsdeklarationen](/manual/moms/momsdeklaration)
- [Skapa ett företag](/manual/kom-igang/skapa-ett-foretag)
- [Registrera en leverantörsfaktura](/manual/leverantorer/registrera-en-leverantorsfaktura)
