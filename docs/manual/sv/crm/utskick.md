---
beskrivning: Nyhetsbrev till ett segment av kontaktpersoner, med avregistrering i varje mejl och klick per länk. Vem som klickar eller öppnar mäts inte.
ordning: 46
roller: [owner, admin]
modul: crm
rutter: [crm.mailings, crm.mailings.show, crm.segments]
sokord: [utskick, nyhetsbrev, segment, målgrupp, e-postmarknadsföring, avregistrera, klick, kampanj]
---

# Utskick

Ett utskick är ett nyhetsbrev till ett segment av era kontaktpersoner. NONOX visar innan du skickar vilka som får det och varför de andra inte får det, lägger ert företags uppgifter och en avregistreringslänk i varje mejl, och räknar klick per länk. Vem som klickar eller öppnar mejlet vet NONOX inte.

![Ett skickat utskick med mottagare, levererade, studsade, klick och avregistreringar, och klick per länk.](/bilder/manual/crm/utskick.webp)

Utskick kan göras av **Ägare** och **Administratör**.

## Segment

Ett utskick går alltid till ett **segment**. Ett segment är ett sparat filter som beskriver vilka du vill nå, till exempel "aktiva kunder i Stockholm med taggen kaffe". NONOX tar med kontaktpersonerna på de kunder och prospekt som stämmer med filtret. Överst på sidan **Segment** finns rutan **Så fungerar segment**; välj **Visa** om den är stängd.

![Sidan Segment med rutan Så fungerar segment öppen: vad ett segment är, tre exempel och vilka som aldrig får utskicket.](/bilder/manual/crm/segment.webp)

### Ett filter, inte en lista

Ett segment är ingen fast lista med namn. Vilka som är med räknas fram när du skickar utskicket, det vill säga när du väljer **Ja, skicka**. Det gäller också ett schemalagt utskick: mottagarna bestäms när du väljer **Ja, skicka**, inte när det går iväg. Den som avregistrerar sig efter det hoppas ändå över.

Det betyder att du kan använda samma segment många gånger. En kund som får taggen i morgon kommer med i nästa utskick, och en kund som har slutat stämma med filtret följer inte med.

### Tre exempel

| Du vill nå | Så fyller du i segmentet |
|---|---|
| Alla kunder i Stockholm med taggen Kaffe | **Status**: Aktiv kund. **Ort**: Stockholm. **Taggar**: kaffe. |
| Prospekt där du är ansvarig | **Status**: Prospekt. **Ansvarig säljare**: du själv. |
| Beslutsfattare hos byggföretag | **Roll**: Beslutsfattare. **SNI-kod börjar med**: 41. |

### Så här gör du ett segment

1. Öppna **CRM → Utskick** och välj **Segment**.
2. Välj **Nytt segment** och ge det ett **Namn** som säger vilka det gäller.
3. Fyll i de filter du behöver: **Status**, **Typ**, **Roll**, **Rättslig grund**, **Taggar**, **Ansvarig säljare**, **SNI-kod börjar med** och **Ort**. Ett filter som du lämnar tomt tar med alla.
4. Titta i rutan **Personer i segmentet**. Den visar hur många som är med just nu, hur många av dem som kan få ett utskick och varför de andra inte kan det.
5. Välj **Spara segmentet**.

Alla filter du fyller i måste stämma. Inom ett filter räcker ett av valen: kryssar du både **Prospekt** och **Aktiv kund** kommer båda med. Under **Taggar** skriver du flera taggar med kommatecken emellan, och då räcker det att kunden har en av dem.

### Vilka som aldrig får utskicket

Alla i ett segment får inte utskicket. NONOX lämnar alltid utanför:

- den som saknar e-postadress eller är markerad som inaktiv;
- den som är markerad **Kontakta inte** eller har **begränsad behandling**, eftersom personen har invänt eller bett er att inte använda uppgifterna;
- den som har **avregistrerat sig** från utskick, vars adress står på spärrlistan eller har studsat;
- en privatperson, en enskild firma eller en kund utan organisationsnummer som inte har samtyckt till e-postreklam, eftersom marknadsföringslagen kräver samtycke för e-postreklam till en fysisk person (se [Samtycke och marknadsföringslagen](#samtycke-och-marknadsforingslagen));
- den vars kund är begränsad, inaktiv eller anonymiserad i kundregistret;
- en adress som redan finns bland mottagarna, så att ingen får samma mejl två gånger.

Du behöver inte göra något själv för att de ska lämnas utanför. Rutan **Personer i segmentet** och rutan **Mottagare** i utskicket visar hur många som lämnas utanför och av vilket skäl.

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

- En kontaktperson med **Samtycke till e-postreklam** och ett datum får utskick. Datumet sätts när personen kryssar i rutan för nyhetsbrev i ett [webbformulär](/manual/crm/webbformular), eller när du fyller i det på personen. Den vanliga rättsliga grunden "samtycke" räcker inte: den kan gälla bara att ni får kontakta personen.
- En kontaktperson på ett **aktiebolag eller en annan juridisk person** får utskick med stöd av berättigat intresse. NONOX känner igen en juridisk person på organisationsnumret, så kunden behöver ha ett organisationsnummer. Ett enkelt bolag är inte en juridisk person.
- En **privatperson** eller en **enskild firma** behöver samtycke. Det gäller också en kund utan organisationsnummer.
- **Kundundantaget**: kryssa i rutan om utskicket går till befintliga kunder som är privatpersoner, ni fick adressen när de köpte, utskicket gäller era egna liknande varor eller tjänster och de kunde säga nej när ni fick adressen. Undantaget gäller bara en person vars e-postadress är kundens adress i kundregistret, eller som är märkt **Kund, 19 § andra stycket** med ett datum på sin sida. Resten kan NONOX inte kontrollera; det är ert ansvar att det stämmer.

> [!NOTE]
> Reglerna är NONOX tolkning och är inte juridiskt granskade än. Är du osäker, fråga en jurist.

## Avregistrering

Varje utskick har en länk **Avregistrera dig från utskicken** och ett avregistreringshuvud som låter mejlprogrammet avregistrera med ett klick. Den som avregistrerar sig:

- märks **Inga utskick** på sin sida och får inga fler utskick;
- hamnar på spärrlistan, så att adressen inte får utskick även om personen raderas och läggs till igen;
- kan fortfarande få vanliga mejl som inte är marknadsföring.

Den som markerar ett utskick som skräppost hanteras som en avregistrering.

## Statistik

För varje utskick ser du **Mottagare**, **Skickade**, **Levererade**, **Studsade**, **Klick** och **Avregistrerade**, och under **Klick per länk** hur många gånger varje länk har klickats. Klick räknas per länk. NONOX vet inte vem som klickade, och öppningar mäts inte: länkarna i utskicket är desamma för alla mottagare. Levererade och studsade räknas när e-posttjänsten rapporterar dem.

## Automatisk paus

Anmäler fler än 0,3 procent av mottagarna era utskick som skräppost (och minst tre mottagare), eller studsar fler än 5 procent av adresserna (och minst tio), räknat på de senaste 30 dagarna, pausas utskicken. Ägaren och administratörerna får ett mejl. Gå igenom segmenten och samtyckena, och välj sedan **Återuppta utskicken** under **CRM → Utskick**.

En paus för skräppostanmälningar kan ni häva själva en gång på 30 dagar. Pausas utskicken för anmälningar igen inom den tiden kan bara NONOX häva pausen; kontakta supporten. En paus för studsar kan ni alltid häva själva.

En adress som studsar får inga fler utskick förrän den har ändrats.

## Bra att veta

- Varje utskick visar ert företags namn, organisationsnummer, **postadress** och e-post, och en länk till er integritetspolicy. Fyll i adressen under **Företagsinställningar** och **Länk till er integritetspolicy** under **CRM → Inställningar** innan du skickar.
- Utskicket går från NONOX egen avsändardomän för utskick, med "ert företag via NONOX" som avsändare. Svaren går till ert företags e-postadress. I NONOX molntjänst är utskick av tills den domänen är på plats; sidan säger det.
- En bild i texten visas som sin bildtext: ett utskick hämtar inga bilder utifrån. Fält som `{namn}` kan inte stå i en länkadress.
- Ber någon att slippa utskick på annat sätt, välj **Avregistrera från utskick** på personens sida.
- Stora utskick går ut i omgångar under några timmar.
- Mottagarnas adresser tas bort 90 dagar efter utskicket; kvar finns bara en kontrollsumma som gör att avregistreringslänken fortfarande fungerar. Efter tre år tas också den bort. Antalen finns kvar.
- Ett testföretag skickar aldrig något.

## Läs också

- [Mejlmallar](/manual/crm/mejlmallar)
- [Webbformulär](/manual/crm/webbformular)
- [GDPR i CRM](/manual/crm/gdpr-i-crm)
