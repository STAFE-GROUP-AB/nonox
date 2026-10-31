# Personalliggare: så sätter du upp en kiosk

Personalliggaren i NONOX är gratis och ingår i systemet. Personalen checkar in
och ut på en vanlig surfplatta, dator eller mobil vid entrén. Kiosken fungerar
även när internet ligger nere: allt sparas på enheten och skickas när nätet
är tillbaka.

## 1. Slå på modulen

1. Gå till **Företag → Moduler**.
2. Slå på **Personalliggare**.
3. Under **Inställningar** kan du välja efter hur många timmar en glömd
   utcheckning påminns (förval 12), när ett arbetspass räknas som långt och
   hur mycket kioskens klocka får skilja sig från serverns.

## 2. Lägg till lokalen

1. Gå till **Personalliggare → Lokaler och kiosker** och välj **Lägg till lokal**.
2. Fyll i namn, adress och verksamhet (restaurang, frisör, fordonsservice,
   tvätteri eller grossist).
3. Välj hur personalen får checka in:
   - **Kiosk med namn och kod** (förvalt)
   - **Personlig QR-kod** som kioskens kamera läser (förvalt)
   - **Mobil med platskontroll** (avstängt från början). Kräver lokalens
     latitud och longitud och en radie i meter.

Delar ni lokal med ett annat företag för varje företag sin egen liggare, och
varje kiosk hör till ett enda företag.

## 3. Lägg till personalen

1. Gå till **Personalliggare → Personal** och välj **Lägg till person**.
2. Fyll i namn och person- eller samordningsnummer (tolv siffror). För inhyrd
   personal: kryssa i **Inhyrd personal** och fyll i arbetsgivarens namn och
   organisationsnummer.
3. Välj kioskens språk för personen: svenska, engelska, arabiska, persiska,
   somaliska, spanska, thailändska eller tigrinja.
4. Välj en **kod** med fyra siffror tillsammans med personen. Enkla koder som
   1234 och 0000 godtas inte.
5. Vill personen ha foto på kiosken: välj **Lägg till foto** och kryssa i att
   personen har samtyckt. Fotot är frivilligt och kan tas bort när som helst.
6. Skriv ut **QR-kortet** om personen vill checka in med QR-kod, eller låt
   personen öppna **Min incheckning** i mobilen (kräver en inloggning med
   rollen *Personal i personalliggaren*).
7. Markera minst en person som **Chef**. Chefens kod öppnar
   kontrollbesöksvyn på kiosken.

## 4. Koppla surfplattan

1. Under lokalen: välj **Lägg till kiosk** och ge den ett namn, till exempel
   *Entrén*.
2. NONOX visar en kopplingskod, en länk och en QR-kod. Koden gäller i 15
   minuter och kan bara användas en gång.
3. Öppna länken på surfplattan (eller skanna QR-koden med den). Kiosken
   kopplas direkt.
4. **Lägg till sidan på hemskärmen** (Safari: Dela → Lägg till på
   hemskärmen; Chrome: meny → Installera app). Då öppnas kiosken i
   helskärm och startar även utan internet.
5. Ställ surfplattan så att den inte går i viloläge, och låt den sitta i
   laddaren. På iPad: använd gärna *Guidad åtkomst* så att bara kiosken kan
   användas.

Tappar ni bort surfplattan: välj **Spärra** vid kiosken. Den kan då inte
längre checka in någon. En ny surfplatta kopplas som en ny kiosk.

## 5. Så checkar personalen in

- Tryck på ditt namn och skriv din kod. Klart – kiosken visar *Välkommen* och
  tiden. Samma sak när du går: kiosken vet att du är incheckad och checkar ut.
- Eller tryck **Skanna QR-kod** och visa ditt kort eller din mobil för
  kameran.
- Fem fel kod i rad låser personen på kiosken i fem minuter.

## 6. Om något blir fel

Inget i liggaren kan ändras eller tas bort. Fel rättas med en ny post:

- **Glömt checka ut:** gå till **Personalliggare → Liggaren**, välj
  **Registrera manuellt**, välj *Utcheckning*, rätt tid och skriv orsaken.
- **Fel tid eller fel person:** välj **Rätta** på posten och välj *Tiden är
  fel* eller *Registreringen skulle inte ha gjorts*. Den ursprungliga posten
  finns kvar och visas bredvid rättelsen.

## 7. När Skatteverket kommer

- **På kiosken:** tryck **Skatteverket är här** och skriv en chefs kod. Du
  ser vem som är incheckad nu och liggaren för den senaste månaden, med
  sökning. **Ladda ner till USB** sparar en ZIP-fil med liggaren, även utan
  internet.
- **I NONOX:** tryck **Skatteverket är här** i personalliggarens meny. Välj
  period och lokal, sök på namn eller personnummer och ladda ner PDF, CSV
  eller XML.
- Varje gång vyn öppnas eller något laddas ner sparas det i
  behandlingshistoriken.

Elektronisk överföring direkt till Skatteverket blir möjlig när Skatteverket
publicerat hur det ska gå till (från 1 juli 2027).
