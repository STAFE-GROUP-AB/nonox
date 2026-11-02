---
beskrivning: Med kontantmetoden ska kund- och leverantörsfakturor som är obetalda den sista dagen i räkenskapsåret bokföras i bokslutet. Så gör du det i NONOX.
ordning: 55
roller: [owner, admin, accountant]
rutter: [accounting.closing]
sokord: [kontantmetoden, bokslutsmetoden, obetalda fakturor, kundfordringar, leverantörsskulder, 1510, 2440, årets slut, återföring, bokslut]
---

# Obetalda fakturor vid årets slut (kontantmetoden)

Med kontantmetoden bokförs en faktura när den betalas. Men den sista dagen i räkenskapsåret ska alla kund- och leverantörsfakturor som fortfarande är obetalda bokföras, så att fordringar och skulder finns med i bokslutet. Det står i bokföringslagen (5 kap. 2 §).

Fakturor till och från andra EU-länder som redan bokfördes när de skapades (försäljning till EU-företag och inköp av varor från EU) står redan på 1510 och 2440 och tas inte med här.

NONOX gör det i ett steg under **Bokföring → Bokslut**, i **2. Bokslutsverifikationer**. Steget visas bara för företag med kontantmetoden som har obetalda fakturor den sista dagen.

## Vad räknas som obetalt?

En faktura är obetald för det belopp som återstår den sista dagen: fakturans belopp minus betalningar och kreditfakturor till och med den dagen. En betalning som kommer efter årsskiftet räknas inte av – fakturan var obetald den sista dagen.

En faktura som är delvis krediterad är obetald för resten. En kreditfaktura för en betald faktura, där kunden ännu inte har fått pengarna tillbaka, bokförs som en skuld till kunden: kredit på 1510 och kreditfakturans del av försäljningen och momsen tillbaka.

## Så här bokför du de obetalda fakturorna

1. Registrera alla fakturor och betalningar som hör till året.
2. Lägg upp nästa räkenskapsår om det inte finns. Bokföringen återförs på dess första dag.
3. Gå till **Bokföring → Bokslut** när årets sista dag har passerat, och välj året.
4. Under **Obetalda fakturor vid årets slut** ser du varje obetald faktura med det som återstår.
5. Klicka på **Bokför obetalda fakturor** och bekräfta.

Gör det här steget innan du bokför momsen för årets sista period, och innan skatten och årets resultat.

## I bokföringen

Två verifikationer i serie A den sista dagen, och två omvända den första dagen i nästa år:

- **Kundfakturor:** 1510 Kundfordringar i debet mot intäktskonton, utgående moms och öresavrundning i kredit – samma konton som med fakturametoden.
- **Leverantörsfakturor:** kostnadskonton (med kostnadsställe och projekt) och ingående moms i debet mot 2440 Leverantörsskulder i kredit.

Återföringen den första dagen i nästa år gör att betalningen sedan bokförs som vanligt med kontantmetoden. Intäkten, kostnaden och momsen bokförs alltså bara en gång: i det gamla året genom bokslutet, och i det nya året tar återföringen och betalningen ut varandra.

Fakturor i utländsk valuta bokförs först till fakturans kurs. Sedan värderas de till kursen den sista dagen. Läs [Skatt och årets resultat](/manual/bokslut/bokslutsverifikationer).

## Momsen

Momsen bokförs på de vanliga momskontona den sista dagen. Om momsen för perioden med årets sista dag inte är bokförd än, kommer den med i den momsdeklarationen. Är perioden redan bokförd ligger momsen kvar på momskontona över årsskiftet och redovisas när fakturan betalas.

> [!NOTE]
> I vilken momsperiod momsen på obetalda fakturor ska redovisas med kontantmetoden, och om återföring första dagen i nästa år är rätt metod, är inte juridiskt kontrollerat i NONOX ännu. Regeln om att fakturorna ska bokföras är kontrollerad enligt en AI-utredning 2026-11-02; juristgranskning återstår. Stäm av med din redovisningskonsult.

## Checklistan

Punkten **Obetalda fakturor vid årets slut är bokförda** i [checklistan](/manual/bokslut/checklistan) stoppar bokslutet tills steget är bokfört. Den gäller inte med fakturametoden, eller om inga fakturor är obetalda.

Har du redan bokfört de obetalda fakturorna med en egen verifikation, kryssa i **Bokfört för hand** vid punkten. NONOX sparar vem som kryssade och när.

## Vanliga frågor

**Jag bokförde steget för tidigt och har en faktura till för året. Vad gör jag?**
Steget bokförs en gång per år. Bokför den fakturan med en egen verifikation den sista dagen och en återföring den första dagen i nästa år, eller rätta verifikationerna. Läs [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation).

**Vi är ett aktiebolag med fakturametoden. Berör det här oss?**
Nej. Med fakturametoden är fakturorna redan bokförda på 1510 och 2440.

## Läs också

- [Fakturametoden och kontantmetoden](/manual/fakturering/fakturametoden-och-kontantmetoden)
- [Checklistan för bokslutet](/manual/bokslut/checklistan)
- [Skatt och årets resultat](/manual/bokslut/bokslutsverifikationer)
- [Momsdeklarationen](/manual/moms/momsdeklaration)
