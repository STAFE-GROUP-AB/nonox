---
beskrivning: Nyhetsbrev till ett segment av kontaktpersoner, med avregistrering i varje mejl och statistik över klick. Öppningar mäts inte.
ordning: 46
roller: [owner, admin]
modul: crm
rutter: [crm.mailings, crm.mailings.show, crm.segments]
sokord: [utskick, nyhetsbrev, segment, målgrupp, e-postmarknadsföring, avregistrera, klick, kampanj]
---

# Utskick

Ett utskick är ett nyhetsbrev till ett segment av era kontaktpersoner. NONOX visar innan du skickar vilka som får det och varför de andra inte får det, lägger ert företags uppgifter och en avregistreringslänk i varje mejl, och räknar klick på länkarna. Vem som öppnar mejlet mäts aldrig.

![Ett skickat utskick med mottagare, levererade, studsade, klick och avregistreringar, och klick per länk.](/bilder/manual/crm/utskick.webp)

Utskick kan göras av **Ägare** och **Administratör**.

## Segment

Ett segment väljer vilka ett utskick går till. Under **CRM → Utskick → Segment** gör du ett nytt segment med filter för status, typ, roll, rättslig grund, taggar, ansvarig säljare, SNI-kod och ort. Alla filter du fyller i måste stämma; inom ett filter räcker något av valen. Medan du ändrar visar rutan **Personer i segmentet** hur många som finns och hur många som kan få ett utskick.

![Segment.](/bilder/manual/crm/segment.webp)

## Så här skickar du

1. Öppna **CRM → Utskick** och välj **Nytt utskick**.
2. Välj **Segment**, skriv **Ämne** och **Text (Markdown)**. Fälten från [mejlmallarna](/manual/crm/mejlmallar) fungerar här också.
3. Välj **Spara utkast** och titta på **Förhandsvisning**.
4. Välj **Skicka test till mig** och läs mejlet i din egen inkorg.
5. Välj **Skicka eller schemalägg**. Fyll i **Skicka senare** om det ska gå vid en viss tid, och välj **Ja, skicka**.

![Ett utkast med segment, ämne och text, och hur många som får utskicket.](/bilder/manual/crm/skriv-ett-utskick.webp)

> [!WARNING]
> Ett skickat utskick går inte att ta tillbaka. **Avbryt utskicket** stoppar bara det som inte har gått än.

## Vem som får utskicket

Rutan **Mottagare** visar hur många som får utskicket, och under **Får det inte, och varför** hur många som lämnas utanför och av vilket skäl:

- ingen e-postadress, eller personen är inaktiv;
- **Kontakta inte**: personen har invänt eller har begränsad behandling;
- personen har **avregistrerat sig** från utskick, adressen står på spärrlistan eller har studsat;
- kunden är begränsad, inaktiv eller anonymiserad i kundregistret;
- **kräver samtycke**: se nedan;
- samma adress finns redan bland mottagarna.

Den som avregistrerar sig efter att utskicket har börjat hoppas över.

## Samtycke och marknadsföringslagen

E-postreklam till en **fysisk person** kräver samtycke enligt marknadsföringslagen 19 §. NONOX räknar så här:

- En kontaktperson med **samtycke** och ett datum får utskick.
- En kontaktperson på ett **aktiebolag eller en annan juridisk person** får utskick med stöd av berättigat intresse. NONOX känner igen en juridisk person på organisationsnumret, så kunden behöver ha ett organisationsnummer.
- En **privatperson** eller en **enskild firma** behöver samtycke. Det gäller också en kund utan organisationsnummer.
- **Kundundantaget**: kryssa i rutan om utskicket går till befintliga kunder som är privatpersoner, ni fick adressen när de köpte, utskicket gäller era egna liknande produkter och de kunde säga nej när ni fick adressen. NONOX kan inte kontrollera det själv; det är ert ansvar att det stämmer.

> [!NOTE]
> Reglerna är NONOX tolkning och är inte juridiskt granskade än. Är du osäker, fråga en jurist.

## Avregistrering

Varje utskick har en länk **Avregistrera dig från utskicken** och ett avregistreringshuvud som låter mejlprogrammet avregistrera med ett klick. Den som avregistrerar sig:

- märks **Inga utskick** på sin sida och får inga fler utskick;
- hamnar på spärrlistan, så att adressen inte får utskick även om personen raderas och läggs till igen;
- kan fortfarande få vanliga mejl som inte är marknadsföring.

Den som markerar ett utskick som skräppost hanteras som en avregistrering.

## Statistik

För varje utskick ser du **Mottagare**, **Skickade**, **Levererade**, **Studsade**, **Klickade** (antal mottagare som klickade) och **Avregistrerade**, och under **Klick per länk** hur många gånger varje länk har klickats. Statistiken visar aldrig vem som klickade, och öppningar mäts inte. Levererade och studsade räknas när e-posttjänsten rapporterar dem.

En adress som studsar får inga fler utskick förrän den har ändrats.

## Bra att veta

- Varje utskick visar ert företags namn, organisationsnummer, **postadress** och e-post. Fyll i adressen under **Företagsinställningar** innan du skickar.
- Utskicket går från NONOX med ert företags namn som avsändare. Svaren går till ert företags e-postadress.
- Stora utskick går ut i omgångar under några timmar.
- Mottagarnas adresser i statistiken tas bort 90 dagar efter utskicket, och vilka som klickade efter 13 månader. Antalen finns kvar, och avregistreringslänken i ett gammalt utskick fungerar fortfarande.
- Ett testföretag skickar aldrig något.

## Läs också

- [Mejlmallar](/manual/crm/mejlmallar)
- [Webbformulär](/manual/crm/webbformular)
- [GDPR i CRM](/manual/crm/gdpr-i-crm)
