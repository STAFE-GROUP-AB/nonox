---
beskrivning: Vad bankmodulen gör, vad som krävs för att slå på den och vilka inställningar den har – hämtningar per dag och påminnelse innan medgivandet går ut.
ordning: 50
roller: [owner, admin, accountant]
modul: banking
sokord: [bank, bankkoppling, BankID, PSD2, öppen bankdata, kontoutdrag, transaktioner, medgivande, hämta transaktioner]
---

# Bankmodulen

Med modulen **Bank** kopplar du företagets bankkonton till NONOX med BankID. Transaktioner och saldon hämtas sedan automatiskt, i stället för att du laddar upp filer från banken. Det här är en kort beskrivning av modulen och dess inställningar; hur du kopplar en bank steg för steg står i [Bankkoppling](/manual/bank/bankkoppling).

## Det här gör modulen

- **Bara läsa.** NONOX kan läsa transaktioner och saldon, aldrig flytta pengar. Betalfiler signeras fortfarande i banken.
- **Ett bankkonto, ett konto i bokföringen.** Varje bankkonto kopplas till ett konto i kontoplanen, till exempel 1930.
- **Förslag, inte bokföring.** Transaktionerna föreslås mot bokförda rader, och inbetalningar mot obetalda kundfakturor. Bankens saldo fylls i [bankavstämningen](/manual/bank/bankavstamning). En person bekräftar alltid; modulen bokför aldrig själv.
- **Ett medgivande som går ut.** Banken lämnar ut uppgifter under en begränsad tid, högst 180 dagar. Sedan behöver medgivandet förnyas med BankID. Du kan koppla bort banken när som helst.

## Innan du slår på modulen

Modulen bygger på öppen bankdata (PSD2) via en leverantör som har avtal med bankerna. Därför måste den som driftar NONOX ha ett sådant avtal innan modulen går att slå på. Saknas det står det **Bankkopplingen är inte inställd på den här installationen** när du försöker slå på modulen.

Slå på modulen under **Inställningar för företaget → Moduler**; se [Moduler](/manual/installningar/moduler).

## Inställningar

Klicka på **Inställningar** på modulens kort:

- **Hämtningar per dag** – från 1 till 4. En bank får begränsa hur ofta uppgifter hämtas utan att du är inloggad, till fyra gånger per dag. Du kan alltid hämta för hand under **Bank**.
- **Påminn med e-post när ett medgivande snart går ut** och **Påminn så här många dagar före** – påminnelsen går till alla som får hantera Bank, och syns under **Att göra** på översikten.
- **Kopplade banker** – en lista över bankerna som är kopplade och till vilket datum medgivandet gäller. Själva kopplingarna och kontona hanterar du under **Bank** i menyn.

## Vem får göra vad

Ägare, administratörer och redovisningskonsulter kan koppla banker och hantera modulen. Bokförare och revisorer kan se det som har hämtats.

## Om du stänger av modulen

Inga fler transaktioner hämtas, men det som redan har hämtats finns kvar och kan inte ändras eller tas bort. Är bankkopplingar fortfarande aktiva påminner NONOX om att du kan koppla bort dem under **Bank**, om banken inte längre ska lämna ut uppgifter.

## Läs också

- [Bankkoppling](/manual/bank/bankkoppling)
- [Bankavstämning](/manual/bank/bankavstamning)
- [Moduler](/manual/installningar/moduler)
