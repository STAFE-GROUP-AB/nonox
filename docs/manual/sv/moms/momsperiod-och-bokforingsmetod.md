---
beskrivning: Välj hur ofta företaget redovisar moms, och förstå vad fakturametoden och kontantmetoden betyder för när momsen hamnar i deklarationen.
ordning: 20
roller: [owner, admin]
sokord: [momsperiod, redovisningsperiod, månadsmoms, kvartalsmoms, årsmoms, helårsmoms, fakturametoden, kontantmetoden, bokslutsmetoden, momsregistrerad, momsregistreringsnummer]
---

# Momsperiod och bokföringsmetod

Två inställningar styr momsen i NONOX: hur ofta företaget redovisar moms, och om företaget bokför enligt fakturametoden eller kontantmetoden. Båda finns under företagets uppgifter, och båda ska stämma med det Skatteverket har beslutat för företaget.

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

> [!NOTE]
> Har företaget brutet räkenskapsår delas kvartalen ändå in i kalenderkvartal, och det första och sista kvartalet kan bli kortare. Hur ett sådant kvartal ska redovisas är inte juridiskt kontrollerat i NONOX – fråga din redovisningskonsult.

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
