---
beskrivning: Koppla andra program till NONOX med NONOX Connect – godkänn appar, skapa API-nycklar för egna skript och lägg till webhooks. Allt gäller ett företag i taget.
ordning: 40
roller: [owner, admin]
modul: connect
rutter: [connect.index, connect.authorize]
sokord: [API, integration, koppla, ansluta app, API-nyckel, token, webhook, kassasystem, e-handel, OAuth, Anslutningar]
---

# NONOX Connect

NONOX Connect är NONOX öppna API. Med det kan andra program läsa och skriva i bokföringen: ett kassasystem som skickar dagens försäljning, en webbutik som skapar fakturor, eller ett eget skript som hämtar rapporter. Det ingår utan extra kostnad.

Du behöver inte vara utvecklare för att använda det här. Den här artikeln beskriver det du gör i NONOX: slår på modulen, godkänner appar, skapar nycklar och lägger till webhooks. Hur API:t anropas står på utvecklarwebben, [/utvecklare](/utvecklare).

## Tre sätt att ansluta

| | Vad det är | För vem |
|---|---|---|
| **Anslutna appar** | Ett program som du godkänner. Appen skickar dig till NONOX, du väljer företag och godkänner med passkey. | Färdiga program, till exempel ett kassasystem. |
| **API-nycklar** | En nyckel för egna skript och integrationer, med de behörigheter du väljer och ett slutdatum. | Den som skriver en egen koppling. |
| **Webhooks** | NONOX meddelar ett annat program när något händer, till exempel att en faktura har betalats. | Program som ska reagera på händelser. |

## Så här kommer du igång

1. Slå på modulen **NONOX Connect** under **Inställningar för företaget → Moduler**. Se [Moduler](/manual/installningar/moduler).
2. Lägg till en passkey på din profil om du inte har en. Det krävs för att godkänna appar, skapa nycklar och lägga till webhooks. Se [Passkeys och inloggningar](/manual/installningar/passkeys-och-inloggningar).
3. Öppna **Anslutningar** i menyn.

## Godkänna en app

En app ansluts alltid från appen själv. Den skickar dig till en sida i NONOX där det står **vill ansluta till NONOX** och vad appen ber om att få göra.

1. Logga in i NONOX om du inte redan är det.
2. Läs under **Appen ber om att få** vad appen vill göra.
3. Välj företag under **Välj företag**.
4. Klicka på **Godkänn med passkey** och bekräfta med din passkey. Vill du inte ansluta klickar du på **Avböj**.

Du skickas sedan tillbaka till appen. Appen syns under **Anslutna appar**, med vem som godkände den och när den senast användes. Klicka på **Koppla bort** för att ta bort åtkomsten direkt.

> [!IMPORTANT]
> En app får aldrig mer än den som godkände den själv får göra i företaget, just nu. Står det att du inte själv har en behörighet får appen den inte heller. Byter du roll eller lämnar företaget följer appens åtkomst med.

## Skapa en API-nyckel

1. Öppna **Anslutningar** och gå till **API-nycklar**.
2. Ge nyckeln ett **Namn**, till exempel "Kassaexport".
3. Kryssa i de **Behörigheter** nyckeln ska ha. Välj så få som möjligt.
4. Välj ett datum vid **Gäller till och med**. Alla nycklar har ett slutdatum.
5. Fyll i **Tillåtna IP-adresser (valfritt, en per rad)** om nyckeln bara ska användas från vissa adresser.
6. Klicka på **Skapa med passkey** och bekräfta.
7. Kopiera nyckeln direkt och spara den på ett säkert ställe. Klicka sedan på **Jag har sparat den**.

> [!WARNING]
> Nyckeln visas bara en gång. NONOX sparar bara ett avtryck av den och kan inte visa den igen. Har du tappat bort den, återkalla den och skapa en ny.

Ägaren får ett mejl om varje ny nyckel. Klicka på **Återkalla** för att stänga av en nyckel direkt; allt som använder den slutar fungera.

## Lägga till en webhook

1. Öppna **Anslutningar** och gå till **Webhooks**.
2. Klicka på **Lägg till webhook**.
3. Skriv mottagarens **Adress (https)** och gärna en **Beskrivning (valfritt)**, och kryssa i vilka **Händelser** som ska skickas.
4. Klicka på **Lägg till med passkey** och bekräfta.
5. Kopiera hemligheten och ge den till mottagaren, så att den kan kontrollera att händelserna kommer från NONOX. Den visas bara en gång.

Händelserna är bland annat bokförd verifikation, mejlad faktura, betald faktura och attesterad leverantörsfaktura. De innehåller id, nummer, datum och belopp, inga personuppgifter. Under **Senaste leveranserna** ser du om mottagaren tog emot dem. Svarar mottagaren inte försöker NONOX igen i ungefär ett dygn, och stänger sedan av webhooken och mejlar ägaren. Ett företag kan ha högst tio webhooks.

## Inställningar

Under **Inställningar** på modulens kort under **Moduler** väljer du:

- **Vilka appar får be om åtkomst** – alla appar som är registrerade i installationen, eller bara de du markerar. En app som tas bort från listan förlorar åtkomsten direkt.
- **Tillåtna IP-adresser för hela API:t** – en tom lista tillåter alla adresser. Gäller både appar och API-nycklar.

## Testföretag

Vill du eller en utvecklare prova API:t utan att röra den riktiga bokföringen? Under **Testföretag för API:t** klickar du på **Skapa testföretag**. Det blir ett eget företag med påhittade kunder, leverantörer, verifikationer och fakturor och NONOX Connect påslaget. Det är tydligt märkt på varje sida och mejlar aldrig fakturor till någon. Du kan ha högst tre testföretag.

## Bra att veta

- Varje anslutning gäller ett företag. Har du flera företag ansluter du appen till vart och ett.
- Allt en app eller nyckel skriver i bokföringen sparas i behandlingshistoriken.
- Stänger du av modulen kopplas alla appar bort, alla nycklar återkallas och alla webhooks stängs av. Slår du på den igen måste apparna godkännas på nytt och nya nycklar skapas.
- Kör ditt företag NONOX själv behöver den som driftar servern förbereda modulen och registrera apparna innan de kan anslutas.

## Läs också

- [Utvecklare: API-nycklar](/utvecklare/api-nycklar)
- [Utvecklare: Webhooks](/utvecklare/webhooks)
- [Moduler](/manual/installningar/moduler)
- [Passkeys och inloggningar](/manual/installningar/passkeys-och-inloggningar)
