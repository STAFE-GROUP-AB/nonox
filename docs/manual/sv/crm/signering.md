---
beskrivning: Skicka en offert eller ett avtal för signering med namnteckning. Kunden signerar i mobilen eller datorn utan konto, och offerten blir en order.
ordning: 27
roller: [owner, admin, accountant, bookkeeper, sales]
modul: crm
rutter: [crm.signing.file]
sokord: [signera, signering, e-signatur, underskrift, namnteckning, digital signatur, elektronisk underskrift, godkänna offert, signeringsbevis, avtal]
---

# Signera offerter och avtal

Skicka en offert eller ett avtal till kunden för signering. Kunden får ett mejl med en personlig länk, läser dokumentet och skriver under med sin namnteckning, med musen, fingret eller en penna. Kunden behöver inget konto. När en offert är signerad blir den en order, och affären du har kopplat vinns.

![Sidan där kunden läser offerten och skriver under med sin namnteckning.](/bilder/manual/crm/signera-offert.webp)

Signeringen kräver modulen CRM. Det är en **enkel elektronisk underskrift**, inte BankID: den visar att den som hade tillgång till mejlet godkände just det dokumentet vid en viss tid, men inte vem personen är. Behöver du säker identifiering, signera med BankID någon annanstans och ladda upp avtalet som PDF.

## Så här skickar du en offert för signering

1. Öppna offerten under **Fakturering → Offerter**. Den ska vara öppen.
2. Under **Signering**, skriv **E-post till den som ska signera**.
3. Välj gärna **Sista dag att signera (frivilligt)**. Annars gäller länken till offertens giltighetsdag, eller i 30 dagar. Den gäller aldrig längre än 90 dagar.
4. Välj **Affär som vinns vid signering** om offerten hör till en affär. Har kunden bara en öppen affär är den redan vald.
5. Bocka i **Gör också ett fakturautkast när offerten är signerad** om du vill fakturera direkt.
6. Tryck på **Skicka för signering**.

Mejlet går ut med företagets namn som avsändare, och svarar kunden går svaret till företagets e-postadress. Länken kan användas en gång. Skickar du igen slutar den tidigare länken att gälla.

![Signering på offerten, med länkar som har skickats, deras status och signeringsbeviset.](/bilder/manual/crm/signering.webp)

## Det här ser kunden

Kunden ser offertens rader, momsen, summan att betala, giltighetstiden och villkoren, och kan öppna PDF:en. Längst ned skriver kunden sitt namn och, om hen vill, sin titel, och väljer hur hen vill skriva under:

- **Rita namnteckningen** i rutan. **Ångra senaste draget** och **Rensa** finns om det blir fel.
- **Skriv mitt namn**: kunden skriver sitt namn igen som underskrift. Det fungerar också helt utan mus och pekskärm.

Kunden bockar i **Jag har läst och godkänner offerten.** och trycker på **Signera**. Den som inte vill godkänna kan i stället trycka på **Vill du inte godkänna? Avböj** och skriva varför.

![Signeringssidan i mobilen.](/bilder/manual/crm/signera-offert-mobil.webp)

## När kunden har signerat

- Offerten blir en **order**, precis som när du trycker på **Kunden tackade ja – gör order**, och ett fakturautkast om du valde det.
- Affären du kopplade markeras som **vunnen**, med offerten, ordern och fakturan kopplade.
- På kundens kort loggas anteckningen "Offert … signerades" med vem som signerade.
- Du som skickade, och affärens ansvarige, får en notis.
- Kunden och företaget får ett mejl med PDF:en och **signeringsbeviset**.

Allt det görs som du som skickade länken, med dina behörigheter. Har du inte längre rätt att skapa order görs det som företagets ägare. Går något inte att göra automatiskt säger notisen det, och du gör ordern för hand.

Avböjer kunden loggas det på kundens kort med skälet, och du får en notis. Offerten är fortfarande öppen.

## Signeringsbeviset

Signeringsbeviset är en PDF som visar vilket dokument som signerades, dess kontrollsumma (SHA-256), vem som signerade, när, hur (ritad namnteckning eller skrivet namn), från vilken IP-adress och webbläsare, och alla händelser: skickad, öppnad, signerad. Själva PDF:en som kunden såg ändras aldrig. Den som vill kontrollera att det är samma dokument räknar fram dess SHA-256 och jämför med beviset.

Under **Signering** på offerten eller avtalet hittar du **PDF:en som skickades**, **Signeringsbevis** och **Händelser** för varje länk.

## Om offerten ändras

En offert som har ändrats efter att länken skickades går inte att signera: kunden ser att dokumentet har ändrats, och länken återkallas. Skicka en ny länk när offerten är klar. Så blir ordern alltid det som kunden faktiskt godkände.

## Återkalla en länk

Tryck på **Återkalla** vid länken under **Signering**. Kunden ser då att länken är återkallad.

## Avtal

Ett avtal i avtalsregistret skickas för signering på samma sätt, från avtalets sida. Se [Avtalsregister](/manual/crm/avtalsregister). Är avtalet signerat går villkoren inte längre att ändra.

## Vem får göra vad

- Att skicka en **offert** för signering kräver att du får skapa och skicka offerter, som för att mejla en offert. En **Säljare** kan inte skapa offerter och kan därför inte heller skicka dem för signering.
- Att skicka ett **avtal** kräver att du får ändra i CRM. Det kan en säljare.

## Bra att veta

- **Personuppgifter.** Ditt företag är ansvarigt för uppgifterna om den som signerar: namn, titel, namnteckning, tidpunkt, IP-adress, webbläsare och e-postadressen. Sidan säger det till den som signerar. Namnteckningen lagras krypterad.
- **Hur länge.** Signerade offerter och avtal med bevis sparas som det de hör till: blir offerten en order eller faktura är den räkenskapsinformation och sparas i sju år. Länkar som aldrig signerades tas bort ett år efter att de stängdes. Se [GDPR i CRM](/manual/crm/gdpr-i-crm).
- **Bevisvärdet** av en enkel elektronisk underskrift är inte prövat för varje slags avtal. Är du osäker, fråga din jurist.
- **API.** Offerter kan skickas för signering och statusen läsas genom API:t, och webhooken `quote.signed` talar om när en offert har signerats. Se [utvecklarsidorna](/utvecklare).

## Läs också

- [Offertmallar](/manual/crm/offertmallar)
- [Avtalsregister](/manual/crm/avtalsregister)
- [Säljtratt och affärer](/manual/crm/saljtratt-och-affarer)
- [GDPR i CRM](/manual/crm/gdpr-i-crm)
