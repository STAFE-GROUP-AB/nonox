---
beskrivning: Koppla företagets bank med BankID, låt transaktionerna komma in av sig själva och stäm av bankkontona mot bokföringen.
ordning: 0
roller: [owner, admin, accountant, bookkeeper]
sokord: [bank, bankkonto, kontoutdrag, öppen bankdata, PSD2, bankavstämning]
---

# Bank

Pengarna på banken och pengarna i bokföringen ska vara samma sak. Här lär du dig att stämma av bankkontona mot bokföringen varje månad, att läsa in bankens filer och – med modulen Bank – att koppla banken med BankID så att transaktioner och saldon hämtas av sig själva.

Avsnittet är för dig som sköter den löpande bokföringen. Bankavstämningen finns under **Bokföring → Bankavstämning**. Modulen Bank får ett eget menyval, **Bank**, när den är påslagen.

> [!NOTE]
> Bankavstämningen fungerar alltid, med eller utan bankkoppling: utan koppling skriver du själv in saldot från kontoutdraget. Bankkopplingen är en modul som måste slås på, och den kräver att den som driftar NONOX har ett avtal med en leverantör av öppen bankdata. I den driftade tjänsten på nonox.app erbjuds den inte än. Läs mer i [Bankkoppling](/manual/bank/bankkoppling).

## Bankavstämning

- [Bankavstämning](/manual/bank/bankavstamning) – stäm av ett bank- eller kassakonto mot kontoutdraget, steg för steg.
- [Skillnader i bankavstämningen](/manual/bank/skillnader-i-avstamningen) – vad du gör när saldona inte stämmer.
- [Skattekontot](/manual/bank/skattekontot) – stäm av konto 1630 mot skattekontot hos Skatteverket och bokför det som saknas (Beta).

## Bankens filer

- [Bankfiler](/manual/bank/bankfiler) – vilka filer från banken NONOX läser och skapar, och vad som inte går än.

## Bankkoppling (modulen Bank)

- [Bankkoppling](/manual/bank/bankkoppling) – koppla banken med BankID och välj vilket konto i bokföringen varje bankkonto hör till.
- [Matcha banktransaktioner](/manual/bank/matcha-transaktioner) – bekräfta NONOX förslag och se vad som saknas i bokföringen.
- [Förnya eller koppla bort banken](/manual/bank/forny-eller-koppla-bort) – medgivandet, påminnelser och inställningar.

## Läs också

- [Inbetalningar](/manual/fakturering/inbetalningar) – kundbetalningar från bankens fil eller bankkopplingen.
- [Betalfiler](/manual/leverantorer/betalfiler) – betala leverantörer med en fil till banken.
- [Moduler](/manual/installningar/moduler) – slå på och av moduler.
