---
beskrivning: När du har betalat en leverantörsfaktura i internetbanken registrerar du betalningen i NONOX, så bokförs den i serie E och fakturan blir betald.
ordning: 50
roller: [owner, admin, accountant, bookkeeper]
sokord: [betala faktura, betalning, betald, delbetalning, serie E, 1930, 2440, markera som betald]
---

# Registrera en betalning

Betalar du en leverantörsfaktura direkt i internetbanken – i stället för med en [betalfil](/manual/leverantorer/betalfiler) – registrerar du betalningen på fakturan i NONOX. Då bokförs den och fakturan försvinner från listan över det som ska betalas.

Fakturan måste vara [attesterad](/manual/leverantorer/attest) först.

## Så här gör du

1. Gå till **Inköp → Leverantörsfakturor** och välj **Att betala**.
2. Klicka på fakturans nummer.
3. Under **Betala till** ser du bankgiro eller plusgiro och OCR-nummer, eller meddelandet att skriva. Betala i internetbanken.
4. Under **Registrera betalning**, fyll i **Betaldag** – dagen pengarna lämnade kontot. Dagens datum är förifyllt.
5. Kontrollera **Belopp**. Det som är kvar att betala är förifyllt.
6. Klicka på **Registrera betalningen**.

När hela beloppet är betalt får fakturan status **Betald**. Betalningen visas under **Betalningar** på fakturan med en länk till verifikationen.

## Delbetalningar

Betalar du bara en del skriver du det beloppet. Fakturan står kvar under **Att betala** med det som återstår under **Kvar att betala**, och du registrerar nästa betalning på samma sätt. Ett belopp större än det som återstår går inte att registrera.

## Det här händer i bokföringen

Varje betalning blir en verifikation i serie E på betaldagen, från 1930 Företagskonto.

Enligt **fakturametoden** är kostnaden och momsen redan bokförda, så betalningen flyttar bara skulden till banken:

| Konto | Debet | Kredit |
|---|---|---|
| 2440 Leverantörsskulder | beloppet | |
| 1930 Företagskonto | | beloppet |

Enligt **kontantmetoden** bokförs kostnaden och momsen först nu. Betalningen bokför sin del av fakturans kostnadsrader och moms direkt mot 1930, och fakturafilen blir underlag till den första betalningens verifikation.

Är fakturan i en annan valuta bokförs också en eventuell kursvinst eller kursförlust. Läs [Fakturor i utländsk valuta](/manual/leverantorer/utlandsk-valuta).

> [!NOTE]
> En betalning kan inte ändras eller tas bort efteråt. Blev betaldagen eller beloppet fel rättar du verifikationen. Läs [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation).

## Vanliga frågor

**Vi betalar från ett annat konto än 1930.**
Betalningar som registreras här bokförs alltid från 1930. Betalar ni från ett annat konto, rätta verifikationen så att rätt bankkonto krediteras.

**Fakturan finns redan i en betalfil.**
Då registrerar du inte betalningen här. Markera betalfilen som betald när banken har betalat, så bokförs alla betalningar i filen. Läs [Betalfiler till banken](/manual/leverantorer/betalfiler).

**Hur stämmer jag av att allt är betalt?**
Obetalda leverantörsfakturor ska vid varje månads- och årsskifte vara lika med saldot på 2440. Det är en punkt på bokslutets checklista. Läs [Checklistan för bokslutet](/manual/bokslut/checklistan).

## Läs också

- [Betalfiler till banken](/manual/leverantorer/betalfiler)
- [Bankavstämning](/manual/bank/bankavstamning)
- [Kreditnotor från leverantörer](/manual/leverantorer/kreditnotor)
