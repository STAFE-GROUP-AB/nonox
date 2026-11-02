---
beskrivning: Fakturera byggtjänster till ett byggföretag med omvänd betalningsskyldighet – utan moms, med båda momsnumren och bokfört på 3231.
ordning: 110
roller: [owner, admin, accountant, bookkeeper]
sokord: [omvänd betalningsskyldighet, omvänd skattskyldighet, byggmoms, byggtjänster, reverse charge, 3231, underentreprenör]
---

# Omvänd betalningsskyldighet för byggtjänster

När ett byggföretag köper byggtjänster av dig är det ofta köparen som ska redovisa momsen, inte du. Det kallas omvänd betalningsskyldighet. Fakturan skickas då utan moms, med en text om att köparen redovisar momsen och med både ditt och köparens momsregistreringsnummer.

> [!IMPORTANT]
> Vilka tjänster och vilka köpare som omfattas avgörs av Mervärdesskattelagen och Skatteverkets vägledning, inte av NONOX. Är du osäker, kontrollera med Skatteverket eller din redovisningskonsult innan du fakturerar.

![En faktura till ett byggföretag med omvänd betalningsskyldighet.](/bilder/manual/fakturering/omvand-betalningsskyldighet-bygg.webp)

## Innan du börjar

- Ditt företag måste vara momsregistrerat. Under **Inställningar för företaget** ska **Momsredovisning** vara något annat än *Inte momsregistrerad*; då räknar NONOX fram företagets momsregistreringsnummer från organisationsnumret.
- Kunden behöver ett **Momsregistreringsnummer** i kundregistret.

## Så här ställer du in kunden

1. Gå till **Register → Kunder** och öppna kunden med **Ändra**.
2. Fyll i kundens **Momsregistreringsnummer**, till exempel *SE556677889901*.
3. Bocka i **Omvänd betalningsskyldighet för byggtjänster**.
4. Tryck på **Spara**.

Nu blir varje ny faktura till kunden utan moms från början.

## Så här fakturerar du

1. Skapa en faktura till kunden. Rutan **Omvänd betalningsskyldighet för byggtjänster** under kunden är redan ibockad, och texten under den säger *Fakturan blir utan moms och försäljningen bokförs på 3231.*
2. Fyll i raderna som vanligt. Momsen sätts till 0 % på alla rader när du sparar.
3. Tryck på **Skapa fakturan** och **Ja, skapa**.

Vill du fakturera något annat än byggtjänster till samma kund – med vanlig moms – tar du bort bocken på just den fakturan innan du skapar den.

På fakturan står *Omvänd betalningsskyldighet för byggtjänster.* och *Köparen är skyldig att redovisa och betala momsen.*, samt säljarens och köparens momsregistreringsnummer.

## Det här händer i bokföringen

Under fakturametoden bokförs fakturan i serie B med 1510 Kundfordringar i debet och 3231 i kredit, utan utgående moms. Kontot 3231 läggs till i kontoplanen om det saknas. I momsdeklarationen redovisar NONOX kontot 3231 i ruta 41, *Försäljning när köparen är betalningsskyldig i Sverige*. Kontot och rutan är markerade för juridisk kontroll i NONOX, så stäm av dem med din redovisningskonsult. Läs [Momsdeklarationen](/manual/moms/momsdeklaration).

## Det som kontrolleras

Fakturan stoppas när du trycker på **Skapa fakturan** om

- företaget saknar momsregistreringsnummer,
- kunden saknar momsregistreringsnummer, eller
- fakturan också är markerad som försäljning utomlands eller har ROT- eller RUT-avdrag.

## Vanliga frågor

**Felmeddelandet säger att jag ska fylla i företagets momsregistreringsnummer under Företagsuppgifter, men jag hittar inget sådant fält.**
Numret räknas fram av NONOX. Öppna **Inställningar för företaget** och välj hur ofta företaget redovisar moms i **Momsredovisning**. Är det inställt på *Inte momsregistrerad* får företaget inget momsnummer.

**Jag köper byggtjänster med omvänd betalningsskyldighet. Var hittar jag det?**
Det gäller leverantörsfakturor. Läs [Registrera en leverantörsfaktura](/manual/leverantorer/registrera-en-leverantorsfaktura).

## Läs också

- [Kunder](/manual/fakturering/kunder)
- [Skapa en faktura](/manual/fakturering/skapa-en-faktura)
- [Momsdeklarationen](/manual/moms/momsdeklaration)
