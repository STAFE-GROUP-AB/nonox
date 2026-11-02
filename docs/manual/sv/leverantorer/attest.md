---
beskrivning: Så godkänner du en leverantörsfaktura innan den betalas, vem som får attestera och hur kravet på att det ska vara någon annan fungerar.
ordning: 40
roller: [owner, admin, approver]
rutter: [payables.invoices.show]
sokord: [attestera, godkänna faktura, attestant, dualitet, fyraögonsprincipen, segregation of duties, attestflöde]
---

# Attest

Att [attestera](/manual/ordlista/ordlista#attest) en faktura är att intyga att den stämmer: att varorna eller tjänsterna är levererade och att beloppet är rätt. I NONOX måste varje registrerad leverantörsfaktura attesteras innan den kan betalas – både när du registrerar en betalning för hand och när den ska med i en betalfil.

![En leverantörsfaktura som väntar på attest, med betalningsuppgifter och kontering.](/bilder/manual/leverantorer/attest.webp)

## Så här attesterar du

1. Gå till **Inköp → Leverantörsfakturor** och välj **Att attestera**.
2. Klicka på fakturans nummer. Fakturan visas bredvid uppgifterna och konteringen.
3. Kontrollera att varorna eller tjänsterna är levererade och att beloppet stämmer.
4. Klicka på **Attestera fakturan**.

Fakturan får status **Attesterad, att betala** och visas under **Att betala**. Ditt namn står under **Attesterad av**.

## Vem får attestera?

Rätten att attestera har rollerna **Ägare**, **Administratör** och **Attestant**. Attestanten är en roll för den som bara ska godkänna fakturor och betalningar, till exempel en ägare som inte sköter bokföringen själv. En redovisningskonsult eller bokförare kan registrera och betala fakturor men inte attestera dem. Läs mer om rollerna i [Roller och behörigheter](/manual/kom-igang/roller-och-behorigheter).

## Någon annan måste attestera

Som standard får den som registrerade en faktura inte attestera den. Det är en grundregel i intern kontroll: två personer ska ha sett varje utbetalning. Samma regel gäller betalfiler – den som skapade filen får inte godkänna den.

Har du registrerat fakturan själv står det på fakturan att någon annan med rätt att attestera behöver godkänna den.

### Företag med en person

Är du ensam i företaget finns det ingen annan som kan attestera. Då kan en ägare eller administratör stänga av kravet:

1. Gå till **Inköp → Leverantörsfakturor**.
2. Längst ned, under **Attest**, klicka på **Tillåt att samma person registrerar och attesterar (för företag med en person)**.

Därefter får den som registrerade en faktura också attestera den, och den som skapade en betalfil också godkänna den. Vill ni slå på kravet igen klickar du på **Kräv att någon annan attesterar**. Varje ändring skrivs i behandlingshistoriken.

> [!NOTE]
> Stäng bara av kravet om ni verkligen är en person. Med flera i företaget är kravet ett skydd mot både misstag och oegentligheter.

## Det här händer i bokföringen

Attesten bokför ingenting. Fakturan är redan bokförd när den registrerades (fakturametoden). Attesten sparas med person och tid och skrivs i behandlingshistoriken, och den går inte att ta tillbaka.

## Vanliga frågor

**Fakturan var fel, men jag har redan attesterat.**
Attesten kan inte ångras. Betala inte fakturan; be leverantören om en kreditnota och registrera den. Läs [Kreditnotor från leverantörer](/manual/leverantorer/kreditnotor).

**Ska kreditnotor också attesteras?**
Ja. En kreditnota attesteras som en faktura innan den kan kvittas eller betalas tillbaka.

**Får jag ett meddelande när något väntar på attest?**
Fakturor som väntar på attest syns under **Att göra** på översikten för den som får attestera.

## Läs också

- [Registrera en betalning](/manual/leverantorer/registrera-en-betalning)
- [Betalfiler till banken](/manual/leverantorer/betalfiler)
- [Bjud in en användare](/manual/kom-igang/bjud-in-en-anvandare)
