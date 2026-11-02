---
beskrivning: Bokförde du fel? Så rättar du med en rättelseverifikation, och därför går en bokförd verifikation aldrig att ändra eller ta bort.
ordning: 70
roller: [owner, admin, accountant, bookkeeper]
sokord: [rätta, rättelse, rättelseverifikation, ändra verifikation, ta bort verifikation, makulera, fel konto, fel belopp, vända, återföra]
---

# Rätta en verifikation

En bokförd verifikation kan aldrig ändras eller tas bort – inte av dig, inte av en administratör och inte ens direkt i databasen. Har det blivit fel rättar du det i stället med en [rättelseverifikation](/manual/ordlista/ordlista#rattelseverifikation): en ny verifikation som vänder alla rader i den gamla. Båda finns kvar och pekar på varandra, så att alla i efterhand kan se vad som hände.

![En rättelseverifikation: den bokför om beloppet till rätt konto och hänvisar till verifikationen den rättar.](/bilder/manual/bokforing/rattelse.webp)

## Så här rättar du

1. Öppna verifikationen under **Bokföring → Verifikationer**.
2. Välj **Rätta verifikationen**.
3. Skriv varför i **Varför rättas den?**, till exempel "Fel konto" eller "Dubbelbokförd".
4. Kontrollera **Datum för rättelsen**. Det är dagens datum från början.
5. Välj **Bokför rättelsen**.

Rättelsen bokförs direkt och du kommer till den. Du får beskedet att originalet är rättat, till exempel **A12 är rättad med A31.**

## Så här bokför du rätt efteråt

Rättelsen tar bort hela den gamla verifikationen ur bokföringen. Var bara en del fel – ett konto, ett belopp – bokför du det rätta som en ny verifikation:

1. Gå tillbaka till originalet. Länken står överst i rättelsen: **Det här är en rättelse av …**
2. Välj **Kopiera till ny verifikation**.
3. Ändra det som var fel, lägg till underlaget och bokför.

## Det här händer i bokföringen

- Rättelsen får nästa nummer i **samma serie** som originalet, i det räkenskapsår som rättelsens datum hör till.
- Varje rad i originalet kommer tillbaka med debet och kredit omvända, med samma konton, kostnadsställen och projekt. Summan på varje konto blir alltså noll.
- Texten blir "Rättelse av A12:" följt av din förklaring.
- Originalet får märket **Rättad** i listan, och på dess sida står **Den här verifikationen är rättad med** och rättelsens nummer. Den länken är det enda i en bokförd verifikation som någonsin fylls i efteråt.
- Rättelsen och anledningen skrivs i [behandlingshistoriken](/manual/bokforing/behandlingshistorik).

## Därför går en bokförd verifikation inte att ändra

Bokföringslagen ställer krav på att bokföringen är varaktig och att rättelser går att följa i efterhand. NONOX löser det genom att aldrig ändra något som är bokfört. Det spärras på tre ställen:

- **Behörigheterna** – ingen roll har rätt att ändra eller ta bort en bokförd verifikation.
- **Programmet** – koden vägrar spara en ändring av en bokförd verifikation, dess rader eller dess underlag.
- **Databasen** – en spärr i själva databasen vägrar ändringar och borttagningar, även om någon skulle gå runt programmet.

Dessutom har varje verifikation en kontrollsumma som hänger ihop med den förra. Ändras något i efterhand bryts kedjan, och det syns vid nästa kontroll. Se [Kontroll av bokföringen](/manual/bokforing/kontroll-av-bokforingen).

> [!IMPORTANT]
> Ett utkast är inte bokfört och kan ändras och tas bort fritt. Är du osäker på en kontering, spara som utkast i stället för att bokföra. Se [Utkast](/manual/bokforing/utkast).

## Vanliga frågor

**Kan jag rätta en verifikation i ett räkenskapsår som är stängt?**
Inte med ett datum i det stängda året – rättelsen måste få ett datum i ett öppet år och efter en [låst period](/manual/bokforing/las-en-period). Välj ett datum i det pågående året. Om och hur ett fel i ett avslutat år ska rättas är en redovisningsfråga; prata med din redovisningskonsult.

**Det står att kontot är inaktiverat.**
Rättelsen använder samma konton som originalet, och ett av dem har inaktiverats sedan dess. Aktivera kontot under **Bokföring → Kontoplan**, bokför rättelsen och inaktivera det igen.

**Kan jag rätta en rättelse?**
Ja, en rättelse är en vanlig bokförd verifikation och kan rättas som alla andra. En verifikation kan bara rättas en gång; knappen **Rätta verifikationen** försvinner när den är rättad.

**Kan jag bara rätta den rad som var fel?**
Funktionen **Rätta verifikationen** vänder alltid hela verifikationen. Du kan också bokföra en egen justering som en ny verifikation, men den kopplas inte till originalet och originalet märks inte som rättat.

**Varför finns det ingen knapp för att ta bort en verifikation?**
Därför att det aldrig är tillåtet för en bokförd verifikation. Numreringen ska vara obruten, och en borttagen verifikation skulle lämna ett hål.

**Kan jag rätta en faktura eller en leverantörsfaktura här?**
Fakturor rättar du med en kreditfaktura under **Försäljning**, och leverantörsfakturor under **Inköp**. Se [Skapa en faktura](/manual/fakturering/skapa-en-faktura) och [Registrera en leverantörsfaktura](/manual/leverantorer/registrera-en-leverantorsfaktura).

## Läs också

- [Hitta och läsa verifikationer](/manual/bokforing/verifikationer)
- [Kontroll av bokföringen](/manual/bokforing/kontroll-av-bokforingen)
- [Behandlingshistorik](/manual/bokforing/behandlingshistorik)
- [Ordlista: rättelseverifikation](/manual/ordlista/ordlista#rattelseverifikation)
