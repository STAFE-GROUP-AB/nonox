---
beskrivning: Skicka en offert eller ett avtal för signering med namnteckning. Kunden signerar i mobilen eller datorn utan konto, och offerten blir en order.
ordning: 27
roller: [owner, admin, accountant, bookkeeper, sales]
modul: crm
rutter: [signing.file]
sokord: [signera, signering, e-signatur, underskrift, namnteckning, digital signatur, elektronisk underskrift, godkänna offert, signeringsbevis, avtal, engångskod, åtkomstkod, kontroll av undertecknaren, låst länk]
---

# Signera offerter och avtal

Skicka en offert eller ett avtal till kunden för signering. Kunden får ett mejl med en personlig länk, läser dokumentet och skriver under med sin namnteckning, med musen, fingret eller en penna. Kunden behöver inget konto. När en offert är signerad blir den en order, och affären du har kopplat vinns.

![Sidan där kunden läser offerten och skriver under med sin namnteckning.](/bilder/manual/crm/signera-offert.webp)

Offerter kan signeras med eller utan modulen CRM. Avtal finns i CRM och kräver den. Med CRM på kan du också koppla en affär som vinns när kunden har signerat. Signeringen är en **enkel elektronisk underskrift**, inte BankID: den visar att den som hade tillgång till mejlet godkände just det dokumentet vid en viss tid, men inte vem personen är. Behöver du säker identifiering, signera med BankID någon annanstans och ladda upp avtalet som PDF.

## Så här skickar du en offert för signering

1. Öppna offerten under **Fakturering → Offerter**. Den ska vara öppen.
2. Under **Signering**, skriv **E-post till den som ska signera**.
3. Välj gärna **Sista dag att signera (frivilligt)**. Annars gäller länken till offertens giltighetsdag, eller i 30 dagar. Dagen ska vara mellan 3 och 90 dagar fram, och aldrig efter offertens giltighetsdag. Mer om det under Sista dag och påminnelser nedan.
4. Med CRM på: välj **Affär som vinns vid signering** om offerten hör till en affär. Har kunden bara en öppen affär är den redan vald.
5. Bocka i **Gör också ett fakturautkast när offerten är signerad** om du vill fakturera direkt.
6. Under **Påminnelser** väljer du om NONOX ska påminna den som ska signera, och hur ofta.
7. Tryck på **Skicka för signering**.

Mejlet går ut med företagets namn som avsändare, och svarar kunden går svaret till företagets e-postadress. Länken kan användas en gång. Skickar du igen slutar den tidigare länken att gälla.

## Kontrollera den som signerar

Länken i mejlet är det enda som behövs för att öppna dokumentet. Vidarebefordras mejlet kan alltså någon annan öppna det. Vill du minska den risken väljer du **Kontroll av undertecknaren** innan du skickar:

- **Ingen kontroll, bara länken**: som förut. Det är förvalt för offerter och avtal.
- **Kod till e-posten**: när undertecknaren öppnar länken trycker hen på **Skicka kod** och får en kod med sex siffror till sin e-post. Koden skrivs in under **Kod från e-posten** och gäller i 10 minuter. Först därefter visas dokumentet.
- **Åtkomstkod som du lämnar själv**: du skriver en **Åtkomstkod** och ger den till undertecknaren på annat sätt, till exempel per telefon. Tryck på **Föreslå kod** för att få en slumpad kod med tio tecken som är lätt att läsa upp. En egen kod ska ha minst 6 tecken, eller minst 8 om den bara har siffror, och vanliga koder som 12345678 godtas inte. Använd inte personnummer, telefonnummer eller annat som någon kan ta reda på. NONOX skickar aldrig koden och sparar bara en hash av den, så du kan inte se den igen efteråt.

Med **Fler undertecknare** väljer du kontroll för varje undertecknare för sig.

![Signering med valet Kontroll av undertecknaren och en länk som väntar på en kod till e-posten.](/bilder/manual/crm/signering-kontroll.webp)

Undertecknaren ser först en sida som bara visar ditt företags namn, inte dokumentet:

![Sidan där undertecknaren ber om en kod innan dokumentet visas.](/bilder/manual/crm/signera-kod.webp)

Så fungerar koderna:

- En kod per e-post kan användas en gång. Den som trycker på **Skicka kod** två gånger inom en minut får inget nytt mejl; koden som redan skickats gäller. Högst tre koder i timmen.
- Länken räknas som öppnad först när undertecknaren har klarat kontrollen.
- Ett testföretag i NONOX skickar inga mejl, så där kan ingen kod per e-post skickas. Undertecknaren ser det på sidan; använd en åtkomstkod när du provar i ett testföretag.
- Efter kontrollen kan undertecknaren läsa och signera i samma webbläsare i 30 minuter. Öppnas länken i en annan webbläsare behövs en ny kontroll.
- Efter för många felaktiga försök låses länken: tio fel med koder per e-post, fem fel med åtkomstkoden. Undertecknaren ser att hen ska kontakta dig, och du får en notis. Under **Signering** står då att länken är låst. Tryck på **Lås upp** om du vet att det var undertecknaren själv som skrev fel; annars kan du skicka dokumentet på nytt.
- Varje kod som skickas, varje lyckad och misslyckad kontroll och varje låsning och upplåsning blir en händelse i **Händelser**, i händelsekedjan, och står i signeringsbeviset. Själva koden sparas aldrig i klartext.

Kontrollen minskar risken att någon annan använder en vidarebefordrad länk. Den gör inte underskriften säkrare än en **enkel elektronisk underskrift** och identifierar inte personen. På signeringsbeviset står vilken kontroll som användes och när undertecknaren klarade den.

![Signering på offerten, med länkar som har skickats, deras status och signeringsbeviset.](/bilder/manual/crm/signering.webp)

## Det här ser kunden

Kunden ser offertens rader, momsen, summan att betala, giltighetstiden och villkoren, och kan öppna PDF:en. Längst ned skriver kunden sitt namn och, om hen vill, sin titel, och väljer hur hen vill skriva under:

- **Rita namnteckningen** i rutan. **Ångra senaste draget** och **Rensa** finns om det blir fel.
- **Skriv mitt namn**: kunden skriver sitt namn igen som underskrift. Det fungerar också helt utan mus och pekskärm.

Kunden bockar i bekräftelsen och trycker på **Signera**. För ett företag lyder den "Jag har läst och godkänner offerten och har rätt att godkänna den för …" med företagets namn; för en privatperson "Jag har läst och godkänner offerten." Exakt den text som visades sparas med underskriften och står i signeringsbeviset.

Under knappen står vad som sparas, att ditt företag är personuppgiftsansvarigt, varför och hur länge uppgifterna sparas, och vilka rättigheter den som signerar har.

Den som inte vill godkänna kan i stället trycka på **Vill du inte godkänna? Avböj** och skriva varför. Då sparas bara tidpunkten och skälet, inte IP-adressen eller webbläsaren, och länken med skälet tas bort efter ett år. På kundens kort står bara att mottagaren avböjde; skälet ser du under **Signering**.

![Signeringssidan i mobilen.](/bilder/manual/crm/signera-offert-mobil.webp)

### När dokumentet har fält

Ett dokument kan ha fält som en viss undertecknare ska fylla i: **Underskrift**, **Initialer**, **Datum**, **Namn**, **Text**, **Tal**, **Kryssruta**, **Lista** och **Alternativ**. Fälten placeras i PDF:en innan den skickas och kan inte ändras efteråt. Fält kräver PDF-motorn; utan den står "Kräver PDF-motorn". Själva platserna för fälten bestäms när dokumentet skickas; verktyget för att dra ut fält på sidorna kommer i en senare version.

Den som signerar ser då dokumentets sidor som bilder i stället för PDF:en i en ruta. De egna fälten är markerade på sidan, och under varje sida står samma fält som vanliga formulärfält i läsordning, så att de går att fylla i med tangentbordet och läsas av en skärmläsare. **Nästa obligatoriska fält** hoppar till nästa obligatoriska fält som fortfarande är tomt. Ett klick på en markering på sidan flyttar till fältet.

- **Datum** fylls i med dagens datum när personen signerar, och **Namn** med namnet hen skriver. De går inte att skriva i.
- **Underskrift** får samma namnteckning som personen ritar eller skriver längst ned.
- **Initialer** ritas i en mindre ruta under **Dina initialer**, eller skrivs med **Skriv mina initialer** (högst tio bokstäver). Samma initialer sätts i alla initialfält.
- **Text** har en högsta längd, **Tal** kan ha ett minsta och ett största värde och ett antal decimaler (decimalkomma går bra), **Lista** och **Alternativ** tar bara de val som finns.

Allt kontrolleras igen av NONOX när personen trycker på **Signera**, och det som inte stämmer visas vid fältet. Texten skrivs alltid inom fältets ruta, med mindre teckenstorlek om den är lång; får den inte plats ens då står "Texten får inte plats i fältet". Datum, namn och underskrift fylls alltid i, och ett långt namn skrivs med mindre text. Emoji går inte att skriva i fälten, eftersom de inte kan skrivas in i PDF:en. Ingen kan fylla i någon annans fält: värden för andra fält tas inte emot, och när signeringen går igenom loggas det i händelserna.

Sidorna görs om till bilder strax efter att dokumentet har skickats. Öppnar någon länken innan det är klart står "Sidorna kan inte visas som bilder just nu"; fälten listas ändå under varje sida och PDF:en går att öppna.

När en undertecknare har signerat stämplas värdena in i PDF:en som en ny version, utan att något i den tidigare versionen ändras. Nästa undertecknare ser den versionen, med de tidigare undertecknarnas uppgifter i dokumentet och listade under sidan som **ifyllt av Undertecknare 1**. Fält som någon senare ska fylla i syns som streckade rutor med **Undertecknare 2** och så vidare, utan namn eller e-postadress. Signerar två personer i samma steg samtidigt får den som kommer sist ladda om sidan och läsa dokumentet igen. Den färdiga PDF:en som alla får med e-post, och som du öppnar under **Signering**, är versionen med allas uppgifter.

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

Signeringsbeviset är en PDF som visar vilket dokument som signerades, dess kontrollsumma (SHA-256), vem som signerade, när, hur (ritad namnteckning eller skrivet namn), vilken kontroll av undertecknaren som användes, från vilken IP-adress och webbläsare, och alla händelser: skickad, öppnad, kod skickad, kontroll klarad, signerad. Själva PDF:en som kunden såg ändras aldrig. Den som vill kontrollera att det är samma dokument räknar fram dess SHA-256 och jämför med beviset.

Under **Signering** på offerten eller avtalet hittar du **PDF:en som skickades**, **Signeringsbevis** och **Händelser** för varje länk.

## Händelsekedjan och den nattliga kontrollen

Varje händelse i en signering länkas till den förra med en kontrollsumma (SHA-256), och den första länken utgår från dokumentets egen kontrollsumma. Ändras eller tas en händelse bort i efterhand går kedjan inte längre ihop. När signeringen är klar, avböjd eller tillbakadragen sparas kedjans sista länk, och den skrivs också i behandlingshistoriken, som aldrig kan ändras.

Varje natt räknar NONOX om alla kedjor och jämför dokumenten och signeringsbevisen med sina kontrollsummor. Under **Händelser** står resultatet av den senaste kontrollen:

- **Händelsekedjan och dokumenten var oförändrade vid kontrollen** med tid: allt stämmer.
- **Kontrollen … hittade en avvikelse i händelsekedjan eller dokumenten**: något har ändrats utanför NONOX. Kontakta den som driftar NONOX; signeringen har inte ändrats av någon i appen.
- **Händelsekedjan har inte kontrollerats än**: kontrollen har inte körts sedan signeringen skickades.

För en avslutad signering står också **Händelsekedjans sista länk** med sin kontrollsumma.

## Sista dag och påminnelser

Sista dag att signera ligger mellan 3 och 90 dagar från idag, och slutar vid dagens slut. Väljer du ingen gäller offertens giltighetsdag, eller 30 dagar. Är offerten giltig kortare tid än 3 dagar blir dess sista giltiga dag också sista dag att signera.

![Signering med en påminnelse som har skickats och formuläret för att ändra sista dag.](/bilder/manual/crm/signering-paminnelser.webp)

**Påminnelser.** Under **Påminnelser** är **Påminn de som ska signera automatiskt, mellan 08 och 18 på dagtid** ibockat från början. Välj **Hur ofta**: varje dag, var 2:e, var 3:e (förvalt) eller var 7:e dag, eller **En gång, två dagar före sista dag**. NONOX skickar högst 10 påminnelser till en person, bara mellan 08 och 18 svensk tid, bara till den vars steg är öppet, och aldrig efter sista dag. Den som har signerat, avböjt eller fått länken återkallad påminns inte. Skickar du med mindre än två dagar kvar till sista dag går den första påminnelsen ut vid nästa körning inom tiderna 08–18. Tar du bort bocken skickas inga automatiska påminnelser. Dokument som skickas från API:t eller som ÄTA får inga automatiska påminnelser, men du kan använda **Påminn nu**.

Varje påminnelse är ett eget mejl med en ny, personlig länk. De äldre länkarna gäller fram till sista dag. Under **Signering** står hur många påminnelser en undertecknare har fått och när den senaste skickades, och under **Händelser** står varje påminnelse.

**Påminn nu.** Vid en undertecknare vars steg är öppet trycker du på **Påminn nu** och bekräftar. Samma person kan påminnas för hand högst en gång i timmen, och påminnelserna för hand räknas med i de högst 10 som en person kan få. En undertecknare vars steg inte har öppnats än får sin länk först när steget före har signerat.

**Ändra sista dag.** Så länge dokumentet är ute trycker du på **Ändra sista dag**, väljer **Ny sista dag att signera** och trycker på **Spara ny sista dag**. Dagen räknas från idag, med samma gränser som när du skickade: 3–90 dagar, och inte efter offertens giltighetsdag. Du kan välja både en senare och en tidigare dag. De som kan signera just nu får ett mejl om den nya dagen. Ändringen sparas som en händelse med den gamla och den nya tiden.

**När sista dag har gått.** Dokumentet kan inte längre signeras, och den som öppnar länken ser att den har gått ut. NONOX noterar utgången en gång under **Händelser** (**Gick ut**) och du som skickade får en notis. En offert kan då ändras och skickas på nytt. Sista dag kan inte ändras för ett dokument som har gått ut, eftersom ett nytt utskick är vägen vidare.

Påminnelserna går som mejl från företaget genom NONOX och räknas mot företagets timgräns för mejl, men stoppas aldrig av den när signeringen väl har börjat. Är företaget ett testföretag lämnar inga mejl NONOX.

## Om offerten ändras

En offert som har ändrats efter att länken skickades går inte att signera: kunden ser att dokumentet har ändrats, och länken återkallas. Skicka en ny länk när offerten är klar. Så blir ordern alltid det som kunden faktiskt godkände.

## Återkalla en länk

Tryck på **Återkalla** vid länken under **Signering**. Kunden ser då att länken är återkallad.

## Fler undertecknare

Ska fler än en person skriva under, till exempel både VD och ordförande hos kunden, trycker du på **Fler undertecknare** under **Signering**. Det fungerar för offerter och avtal.

1. Under **Undertecknare**, skriv **E-post** för var och en och välj **Steg**. Tryck på **Lägg till undertecknare** för fler. Högst 10 undertecknare.
2. Välj steg så här:
   - **I tur och ordning**: ge undertecknarna steg 1, 2, 3 och så vidare. Den i steg 2 får sin länk först när den i steg 1 har signerat.
   - **Samtidigt**: ge dem samma steg. Alla i steget får sin länk på en gång, och nästa steg får sin länk när alla i steget före har signerat.
3. Vill du att någon ska få en kopia utan att signera, till exempel kundens ekonomiavdelning eller din revisor, tryck på **Lägg till kopiemottagare**. Högst 10. Bocka i **Meddela kopiemottagarna redan nu att dokumentet är skickat** om de ska få veta det direkt.
4. Välj sista dag, påminnelser, affär och fakturautkast som för en undertecknare. Affären vinns och fakturautkastet görs först när alla har signerat.
5. Tryck på **Skicka till undertecknarna**. **En undertecknare** tar dig tillbaka till det vanliga formuläret.

> [!NOTE]
> Är modulen Signering påslagen, och får du skicka där, öppnar **Fler undertecknare** i stället guiden under **Signering → Skicka dokument** för samma offert eller avtal, med samma val. Se [Skicka ett dokument för signering](/manual/signering/skicka-dokument).

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
