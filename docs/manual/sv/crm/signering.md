---
beskrivning: Skicka en offert eller ett avtal för signering med namnteckning. Kunden signerar i mobilen eller datorn utan konto, och offerten blir en order.
ordning: 27
roller: [owner, admin, accountant, bookkeeper, sales]
modul: crm
rutter: [signing.file]
sokord: [signera, signering, e-signatur, underskrift, namnteckning, digital signatur, elektronisk underskrift, godkänna offert, signeringsbevis, avtal]
---

# Signera offerter och avtal

Skicka en offert eller ett avtal till kunden för signering. Kunden får ett mejl med en personlig länk, läser dokumentet och skriver under med sin namnteckning, med musen, fingret eller en penna. Kunden behöver inget konto. När en offert är signerad blir den en order, och affären du har kopplat vinns.

![Sidan där kunden läser offerten och skriver under med sin namnteckning.](/bilder/manual/crm/signera-offert.webp)

Offerter kan signeras med eller utan modulen CRM. Avtal finns i CRM och kräver den. Med CRM på kan du också koppla en affär som vinns när kunden har signerat. Signeringen är en **enkel elektronisk underskrift**, inte BankID: den visar att den som hade tillgång till mejlet godkände just det dokumentet vid en viss tid, men inte vem personen är. Behöver du säker identifiering, signera med BankID någon annanstans och ladda upp avtalet som PDF.

## Så här skickar du en offert för signering

1. Öppna offerten under **Fakturering → Offerter**. Den ska vara öppen.
2. Under **Signering**, skriv **E-post till den som ska signera**.
3. Välj gärna **Sista dag att signera (frivilligt)**. Annars gäller länken till offertens giltighetsdag, eller i 30 dagar. Den gäller aldrig längre än 90 dagar.
4. Med CRM på: välj **Affär som vinns vid signering** om offerten hör till en affär. Har kunden bara en öppen affär är den redan vald.
5. Bocka i **Gör också ett fakturautkast när offerten är signerad** om du vill fakturera direkt.
6. Tryck på **Skicka för signering**.

Mejlet går ut med företagets namn som avsändare, och svarar kunden går svaret till företagets e-postadress. Länken kan användas en gång. Skickar du igen slutar den tidigare länken att gälla.

![Signering på offerten, med länkar som har skickats, deras status och signeringsbeviset.](/bilder/manual/crm/signering.webp)

## Det här ser kunden

Kunden ser offertens rader, momsen, summan att betala, giltighetstiden och villkoren, och kan öppna PDF:en. Längst ned skriver kunden sitt namn och, om hen vill, sin titel, och väljer hur hen vill skriva under:

- **Rita namnteckningen** i rutan. **Ångra senaste draget** och **Rensa** finns om det blir fel.
- **Skriv mitt namn**: kunden skriver sitt namn igen som underskrift. Det fungerar också helt utan mus och pekskärm.

Kunden bockar i bekräftelsen och trycker på **Signera**. För ett företag lyder den "Jag har läst och godkänner offerten och har rätt att godkänna den för …" med företagets namn; för en privatperson "Jag har läst och godkänner offerten." Exakt den text som visades sparas med underskriften och står i signeringsbeviset.

Under knappen står vad som sparas, att ditt företag är personuppgiftsansvarigt, varför och hur länge uppgifterna sparas, och vilka rättigheter den som signerar har.

Den som inte vill godkänna kan i stället trycka på **Vill du inte godkänna? Avböj** och skriva varför. Då sparas bara tidpunkten och skälet, inte IP-adressen eller webbläsaren, och länken med skälet tas bort efter ett år. På kundens kort står bara att mottagaren avböjde; skälet ser du under **Signering**.

![Signeringssidan i mobilen.](/bilder/manual/crm/signera-offert-mobil.webp)

## När kunden har signerat

- Offerten blir en **order**, precis som när du trycker på **Kunden tackade ja – gör order**, och ett fakturautkast om du valde det.
- Affären du kopplade markeras som **vunnen**, med offerten, ordern och fakturan kopplade.
- På kundens kort loggas anteckningen "Offert … signerades" med vem som signerade.
- Du som skickade, och affärens ansvarige, får en notis.
- Kunden och företaget får ett mejl med PDF:en och **signeringsbeviset**.

Allt det görs som du som skickade länken, med dina behörigheter. Har du inte längre rätt att skapa order görs det som företagets ägare. Går något inte att göra automatiskt säger notisen det, under **Signering** står **Signerad – ordern är inte skapad**, och du gör ordern för hand. Kunden får sin kopia ändå.

En signerad offert går inte att ändra eller skicka för signering igen.

Avböjer kunden loggas det på kundens kort med skälet, och du får en notis. Offerten är fortfarande öppen.

## Signeringsbeviset

Signeringsbeviset är en PDF som visar vilket dokument som signerades, dess kontrollsumma (SHA-256), vem som signerade, när, hur (ritad namnteckning eller skrivet namn), från vilken IP-adress och webbläsare, och alla händelser: skickad, öppnad, signerad. Själva PDF:en som kunden såg ändras aldrig. Den som vill kontrollera att det är samma dokument räknar fram dess SHA-256 och jämför med beviset.

Under **Signering** på offerten eller avtalet hittar du **PDF:en som skickades**, **Signeringsbevis** och **Händelser** för varje länk.

## Om offerten ändras

Sista dag att signera kan inte vara efter offertens giltighetsdag. En offert som har ändrats efter att länken skickades går inte att signera: kunden ser att dokumentet har ändrats, och länken återkallas. Skicka en ny länk när offerten är klar. Så blir ordern alltid det som kunden faktiskt godkände.

## Återkalla en länk

Tryck på **Återkalla** vid länken under **Signering**. Kunden ser då att länken är återkallad.

## Fler undertecknare

Ska fler än en person skriva under, till exempel både VD och ordförande hos kunden, trycker du på **Fler undertecknare** under **Signering**. Det fungerar för offerter och avtal.

1. Under **Undertecknare**, skriv **E-post** för var och en och välj **Steg**. Tryck på **Lägg till undertecknare** för fler. Högst 10 undertecknare.
2. Välj steg så här:
   - **I tur och ordning**: ge undertecknarna steg 1, 2, 3 och så vidare. Den i steg 2 får sin länk först när den i steg 1 har signerat.
   - **Samtidigt**: ge dem samma steg. Alla i steget får sin länk på en gång, och nästa steg får sin länk när alla i steget före har signerat.
3. Vill du att någon ska få en kopia utan att signera, till exempel kundens ekonomiavdelning eller din revisor, tryck på **Lägg till kopiemottagare**. Högst 10. Bocka i **Meddela kopiemottagarna redan nu att dokumentet är skickat** om de ska få veta det direkt.
4. Välj sista dag, affär och fakturautkast som för en undertecknare. Affären vinns och fakturautkastet görs först när alla har signerat.
5. Tryck på **Skicka till undertecknarna**. **En undertecknare** tar dig tillbaka till det vanliga formuläret.

![Signering med två undertecknare i tur och ordning och en kopiemottagare.](/bilder/manual/crm/fler-undertecknare.webp)

Under **Signering** visas undertecknarna tillsammans, med vilket steg som är öppet och vem som har signerat. Varje undertecknare signerar på sin egen länk och får sin egen kopia med sitt signeringsbevis.

- **När alla har signerat** blir offerten en order, eller avtalet signerat, precis som med en undertecknare. Undertecknarna och kopiemottagarna får dokumentet och en sammanställning med vem som signerade och när. Företaget får dokumentet med det fullständiga signeringsbeviset för varje undertecknare. IP-adress, webbläsare, e-postadress och namnteckning står bara i det fullständiga beviset.
- **Medan någon återstår** går offerten inte att ändra eller skicka igen, så att alla signerar samma dokument. Har sista dag att signera passerat utan att alla har signerat kan offerten ändras och skickas på nytt. Offerten kan inte heller godkännas i kundportalen medan den väntar på flera.
- **Om någon avböjer** avslutas signeringen för alla. De som inte har signerat ser att en annan undertecknare har avböjt, och de som redan har signerat får ett mejl om att dokumentet inte blir giltigt. Skälet ser bara du. Offerten är öppen igen och kan ändras och skickas på nytt.
- **Dra tillbaka** avslutar signeringen för alla, till exempel om något i offerten måste ändras. Alla länkar slutar gälla, och den som öppnar en ser att dokumentet har dragits tillbaka.

## Avtal

Ett avtal i avtalsregistret skickas för signering på samma sätt, från avtalets sida. Se [Avtalsregister](/manual/crm/avtalsregister). Är avtalet signerat går villkoren inte längre att ändra.

## Vem får göra vad

- Att skicka en **offert** för signering kräver att du får skapa och skicka offerter, som för att mejla en offert. En **Säljare** kan inte skapa offerter och kan därför inte heller skicka dem för signering.
- Att skicka ett **avtal** kräver att du får ändra i CRM. Det kan en säljare.

## Bra att veta

- **Personuppgifter.** Ditt företag är ansvarigt för uppgifterna om den som signerar: namn, titel, namnteckning, tidpunkt, IP-adress, webbläsare och e-postadressen. Sidan säger det till den som signerar. Namnteckningen lagras krypterad.
- **Hur länge.** Signerade offerter och avtal med bevis sparas som det de hör till: blir offerten en order eller faktura är den räkenskapsinformation och sparas i sju år. Länkar som aldrig signerades tas bort ett år efter att de stängdes. Se [GDPR i CRM](/manual/crm/gdpr-i-crm).
- **Bevisvärdet** av en enkel elektronisk underskrift är inte prövat för varje slags avtal. Är du osäker, fråga din jurist.
- **Avtal med formkrav.** Vissa avtal måste skrivas under på papper, till exempel köp av fast egendom (jordabalken 4 kap. 1 §). Sådana avtal signeras inte här.
- **API.** Offerter kan skickas för signering och statusen läsas genom API:t, och webhooken `quote.signed` talar om när en offert har signerats. Se [utvecklarsidorna](/utvecklare).

## Läs också

- [Offertmallar](/manual/crm/offertmallar)
- [Avtalsregister](/manual/crm/avtalsregister)
- [Säljtratt och affärer](/manual/crm/saljtratt-och-affarer)
- [GDPR i CRM](/manual/crm/gdpr-i-crm)
