---
beskrivning: Koppla ditt Stripe-konto med en begränsad nyckel som bara kan läsa, så räknas prenumerationerna i MRR, och koppla Stripe-kunderna till kundregistret.
ordning: 172
roller: [owner, admin, accountant]
modul: saas
rutter: [saas.stripe]
sokord: [Stripe, prenumerationer, begränsad nyckel, restricted key, rk_live, webhook, NONOX Connect, MRR från Stripe]
---

# Stripe i Återkommande intäkter

Säljer du abonnemang genom Stripe kan NONOX läsa prenumerationerna och räkna dem i MRR, rörelserna, kohorterna och churn, tillsammans med dina avtal och din avtalsfakturering. NONOX läser bara: ingenting ändras i Stripe, inga pengar flyttas, och ingenting bokförs från Stripe än.

![Sidan Stripe med priset i NONOX Connect och kopplingen till Stripe-kontot.](/bilder/manual/fakturering/stripe-i-aterkommande-intakter.webp)

## Det här behövs

- Modulen **NONOX SaaS** påslagen.
- Kopplingen till Stripe påslagen för installationen. Är den inte det står det på sidan **Stripe** att kopplingen inte finns än.
- Stripe påslaget i **NONOX Connect**. Priset är leverantörens pris plus 10 %. NONOX betalar inget till Stripe för kopplingen, så priset blir 0 kr i månaden. Dina avgifter till Stripe betalar du som förut direkt till Stripe.
- För att slå på NONOX Connect behöver du behörighet att ändra inställningar. För att koppla och koppla från Stripe behöver du dessutom behörighet att hantera återkommande intäkter (Ägare, Administratör eller Redovisningskonsult med inställningar).
- Du är personuppgiftsansvarig för dina Stripe-kunder. Kontrollera att din egen integritetspolicy nämner att du följer upp försäljningen och att du använder ett affärssystem för det.

## Så här skapar du nyckeln i Stripe

1. Logga in i Stripe och gå till API-nycklar.
2. Välj **Create restricted key** (skapa begränsad nyckel).
3. Ge nyckeln **Read** för Account, Customers, Products, Prices, Coupons, Subscriptions och Events.
4. Låt allt annat stå på **None**, särskilt betalmetoder, Checkout, Connect och all skrivbehörighet (**Write**).
5. Kopiera nyckeln. Den börjar med `rk_live_`.

Stripe beskriver begränsade nycklar på sin egen sida om restricted API keys (docs.stripe.com/keys/restricted-api-keys).

> [!WARNING]
> Klistra aldrig in din hemliga nyckel (den börjar med `sk_`). Den ger full åtkomst till ditt Stripe-konto, och NONOX vägrar ta emot den.

## Så här kopplar du Stripe

1. Gå till **Återkommande intäkter → Stripe**.
2. Tryck på **Slå på Stripe i NONOX Connect**, läs priset och tryck på **Ja, slå på**.
3. Klistra in nyckeln i **Begränsad nyckel** och tryck på **Koppla Stripe**.

NONOX provar att läsa varje del som behövs. Saknas en behörighet säger NONOX vilken, och ingenting sparas. Nyckeln sparas krypterad och visas aldrig igen; på sidan står bara de fyra sista tecknen. Ett Stripe-konto kan vara kopplat till ett företag i taget.

Sedan läser NONOX in kunder, produkter, priser, kuponger och prenumerationer. Det kan ta några minuter. Efter det läses ändringar in varje timme. Under **Inläsningar** ser du varje inläsning, hur många anrop den gjorde och hur många nya versioner av prenumerationer den gav. Stannar en inläsning för att Stripe inte svarar fortsätter nästa inläsning där den slutade.

## Hur prenumerationerna räknas

- En prenumeration räknas från sitt startdatum med det pris den har när NONOX läser in den. Historiken före kopplingen hittar NONOX inte på. En senare ändring räknas från den dag NONOX läser in den.
- Priset räknas om till en månad: ett årspris delas med 12 och ett veckopris räknas om till en genomsnittlig månad. Moms räknas inte med.
- Återkommande rabatter dras av, och en rabatt som gäller ett visst antal månader slutar när den slutar i Stripe. En rabatt som bara gäller en gång räknas inte.
- En provperiod räknas som 0 kr tills den är slut. En pausad prenumeration räknas som paus.
- En uppsagd prenumeration räknas till slutet av den betalda perioden.
- Förbrukningsbaserade priser räknas inte i MRR. Priser med trappsteg räknas inte än; de står under **Källor**.
- Belopp i andra valutor räknas om till kronor med Riksbankens kurs vid månadens slut.

## Kunder i Stripe

NONOX kopplar en Stripe-kund till en av dina kunder så här:

| Det här stämmer | Det händer |
|---|---|
| Ditt kundnummer står i Stripe-kundens metadata som `nonox_customer_number` | Kopplas direkt och visas under **Kopplade automatiskt, att granska** |
| Momsregistreringsnumret i Stripe är detsamma som kundens, eller ett svenskt momsnummer innehåller kundens organisationsnummer | Kopplas direkt och visas för granskning |
| Samma e-postadress som exakt en kund | Visas som **Förslag** |
| Samma namn och land som exakt en kund | Visas som **Förslag** |

![Kunder i Stripe med en automatisk koppling att granska, ett förslag och Stripe-kunder som inte är kopplade.](/bilder/manual/fakturering/stripe-i-aterkommande-intakter-kunder.webp)

Dina kunder kan själva ändra skatte-id i Stripes kundportal. Därför visas också en koppling via momsregistreringsnummer för granskning, och du bekräftar den med **Stämmer**.

Hittar en regel två eller fler kunder bestämmer NONOX ingenting. För varje Stripe-kund kan du:

- trycka på **Stämmer** när en automatisk koppling är rätt,
- trycka på **Koppla till förslaget**,
- skriva ett **Kundnummer** och trycka på **Koppla**,
- trycka på **Skapa kund** för att lägga upp kunden i kundregistret med namn, e-post, adress och momsnummer från Stripe,
- trycka på **Koppla bort**. Då räknas Stripe-kunden för sig och kopplas inte automatiskt igen.

För en Stripe-kund som inte är kopplad finns också **Registerutdrag**, som laddar ner det NONOX har om Stripe-kunden, och **Radera uppgifterna**, som tar bort namn, e-post, adress och skatte-id i NONOX när personen begär det. Är Stripe-kunden kopplad finns uppgifterna i kundens eget registerutdrag.

Raderar eller anonymiserar du en kund i NONOX töms Stripe-kundens uppgifter här och läses inte in igen. Ta också bort kunden i Stripe om personen har begärt radering, eftersom uppgifterna annars finns kvar där. Tas en kund bort i Stripe töms dess uppgifter här direkt.

En Stripe-kund som inte är kopplad räknas som en egen kund i MRR tills du kopplar den, och står under **Källor**. Kopplar du den senare flyttas dess hela historik till kunden. Stängda månader ändras inte; skillnaden visas som **Rättelse** i den första öppna månaden, se [Stäng en månad i Återkommande intäkter](/manual/fakturering/stang-en-manad-i-aterkommande-intakter).

## Webhook (valfritt)

Utan webhook kommer ändringar i Stripe fram inom en timme. Med en webhook kommer de fram inom någon minut.

1. Lägg till en händelsemottagare (event destination) i Stripe med adressen som står under **Webhook (valfritt)**.
2. Välj händelserna under **Händelser att välja i Stripe**.
3. Kopiera signeringshemligheten, som börjar med `whsec_`, klistra in den i **Signeringshemlighet** och tryck på **Spara**.

NONOX kontrollerar Stripes signatur på varje händelse och läser sedan prenumerationen eller kunden från Stripe med din nyckel, så en falsk händelse kan inte ändra några siffror.

## Byta nyckel eller koppla från

- **Byt nyckel**: klistra in en ny begränsad nyckel för samma Stripe-konto. Låt sedan den gamla gå ut i Stripe.
- **Koppla från Stripe**: nyckeln och signeringshemligheten raderas direkt. Prenumerationerna räknas inte från den dagen, och det syns som en egen rad i rörelserna, inte som churn. Låt också nyckeln gå ut i Stripe. 90 dagar efter frånkopplingen raderas de inlästa prenumerationerna, produkterna, priserna, kupongerna och händelserna, och Stripe-kundernas namn, e-post, adress och skatte-id töms. Siffrorna i Återkommande intäkter, som bara har id och belopp, finns kvar.

Svarar Stripe att nyckeln inte gäller längre avslutar NONOX kopplingen på samma sätt, och de som hanterar återkommande intäkter får ett meddelande i NONOX.

## Läs också

- [Återkommande intäkter](/manual/fakturering/aterkommande-intakter)
- [Stäng en månad i Återkommande intäkter](/manual/fakturering/stang-en-manad-i-aterkommande-intakter)
