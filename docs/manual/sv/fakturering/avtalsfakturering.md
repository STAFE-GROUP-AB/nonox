---
beskrivning: Lägg upp ett avtal så skapas samma faktura varje månad, kvartal, halvår eller år – som utkast att kontrollera, eller skickad automatiskt.
ordning: 70
roller: [owner, admin, accountant, bookkeeper]
rutter: [invoicing.agreements, invoicing.agreements.create, invoicing.agreements.show]
sokord: [avtal, avtalsfaktura, återkommande faktura, abonnemang, prenumeration, hyra, månadsfaktura, periodfaktura]
---

# Avtalsfakturering

Fakturerar du samma sak gång på gång – ett lunchavtal med ett kontor, hyran för en lokal, ett serviceavtal – lägger du upp ett **avtal**. Då skapar NONOX fakturorna åt dig varje månad, kvartal, halvår eller år. Varje faktura blir antingen ett utkast som du kontrollerar, eller skapas, bokförs och mejlas direkt.

Avtalen finns under **Försäljning → Avtal**.

![Ett avtal om löpande fakturering varje månad.](/bilder/manual/fakturering/avtalsfakturering.webp)

## Så här lägger du upp ett avtal

1. Gå till **Försäljning → Avtal** och tryck på **Nytt avtal**.
2. Skriv **Namn på avtalet**, till exempel *Lunchavtal kontoret*. Namnet syns bara för er, i listan.
3. Välj **Kund**.
4. Välj **Hur ofta**: **Varje månad**, **Varje kvartal**, **Varje halvår** eller **Varje år**.
5. Välj **Nästa fakturadatum**. Från början är det den första i nästa månad. Fakturorna dateras samma dag i månaden varje gång.
6. Välj **Sista dag (frivilligt)** om avtalet tar slut ett visst datum. Tomt betyder tills vidare.
7. Fyll i **Er referens** om kunden vill ha det på fakturan.
8. Bestäm om fakturorna ska skickas av sig själva. Bocka i **Skapa, bokför och skicka fakturan automatiskt** om de ska det. Då måste kunden ha en e-postadress.
9. Fyll i raderna. De faktureras likadant varje gång, och priserna är utan moms.
10. Skriv ett **Meddelande på fakturorna (frivilligt)** om du vill.
11. Tryck på **Spara avtalet**. Du ser när den första fakturan skapas.

## Vad som händer på fakturadagen

Varje morgon går NONOX igenom de aktiva avtal som ska faktureras den dagen:

- **Utan automatiskt utskick** blir fakturan ett utkast under **Försäljning → Fakturor**. Du kontrollerar det och trycker på **Skapa fakturan** och **Skicka**.
- **Med automatiskt utskick** skapas fakturan, bokförs och mejlas till kundens e-postadress.

Efter varje faktura flyttas **Nästa fakturadatum** fram ett steg. Har ett datum passerats utan att någon faktura skapats, till exempel om avtalet lades upp i efterhand, skapas en faktura för varje missat datum, högst tolv åt gången.

Fakturan skapas i namnet på den som lade upp avtalet och med den personens behörighet. Får den personen inte längre fakturera i företaget hoppar NONOX över avtalet. Ta i så fall över avtalet genom att skapa ett nytt.

> [!TIP]
> Behöver du fakturera tidigare än planerat, eller har morgonens körning inte gått än? Öppna avtalet och tryck på **Skapa fakturan nu** (eller **Skapa och skicka fakturan nu**). Knappen syns när avtalet ska faktureras.

## Pausa och avsluta

Öppna avtalet:

- **Pausa** – inga fakturor skapas förrän du trycker på **Aktivera**.
- **Avsluta avtalet** och sedan **Ja, avsluta** – inga fler fakturor skapas. Ett avtal med en sista dag avslutas av sig självt när nästa fakturadatum hamnar efter den dagen.

## Listan över avtal

Listan visar varje avtal med kund, hur ofta, nästa faktura, belopp och status (*Aktivt*, *Pausat* eller *Avslutat*). Överst står vad de aktiva avtalen motsvarar per månad, utan moms. På avtalets sida finns de senaste fakturorna som avtalet har skapat.

## Det här händer i bokföringen

Fakturorna från ett avtal är vanliga fakturor. De bokförs när de skapas, i serie B under fakturametoden, eller när de betalas under kontantmetoden. Läs [Fakturametoden och kontantmetoden](/manual/fakturering/fakturametoden-och-kontantmetoden).

## Vanliga frågor

**Om jag ändrar priset på avtalet, ändras redan skickade fakturor?**
Nej. Ändringen gäller nästa faktura.

**Kunden ska ha 25 % rabatt i tre månader.**
Lägg in rabatten på raden och ändra tillbaka när perioden är slut, eller ändra fakturautkastet de månader det gäller.

**Kan ett avtal ha ROT- eller RUT-avdrag?**
Nej, det går inte att välja på ett avtal. Kundens momsbehandling, till exempel försäljning utomlands, följer däremot med.

## Läs också

- [Skapa en faktura](/manual/fakturering/skapa-en-faktura)
- [Skicka fakturan och öppna PDF:en](/manual/fakturering/skicka-en-faktura)
- [Kunder](/manual/fakturering/kunder)
