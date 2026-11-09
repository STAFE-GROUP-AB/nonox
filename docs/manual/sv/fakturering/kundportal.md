---
beskrivning: Låt dina kunder logga in och se sina fakturor, kontoutdrag och offerter, betala med rätt OCR-nummer och ställa frågor till dig.
ordning: 47
roller: [owner, admin, accountant, bookkeeper, auditor]
modul: portal
rutter: [portal.admin, portal.admin.questions]
sokord: [kundportal, kundinloggning, kundsida, mina fakturor, kontoutdrag, bestrida faktura, fråga om faktura, godkänna offert, OCR-nummer]
---

# Kundportal

I kundportalen loggar dina kunders kontaktpersoner in och ser sina fakturor och kreditfakturor, påminnelser och kontoutdrag, betalningsuppgifterna med OCR-nummer och sina offerter. De kan godkänna en offert, meddela att de har betalat, ställa frågor och bestrida en faktura. Du ser när en faktura har öppnats.

Kundportalen bär ditt företags logotyp och färg. Längst ned står *Kundportalen drivs med NONOX*, och den raden kan du stänga av.

> [!NOTE]
> Kundportal är en modul. Den är byggd men väntar på en juristgranskning av hur kundernas kontaktpersoners uppgifter får behandlas. Till dess står det i modullistan under **Inställningar för företaget → Moduler** att den inte kan slås på. Den kräver också att installationen kör sin kö i bakgrunden, så att inloggningskoderna skickas utan att svaret avslöjar vem som har en kundportal.

## Så fungerar inloggningen

Kontaktpersonerna blir inte användare i NONOX. De har en egen inloggning som bara når kundportalen, och de ser bara den kund de är inbjudna för.

- Du bjuder in en person. Hen får ett mejl med en länk som gäller i 14 dagar.
- När personen öppnar länken skickar vi en kod till den inbjudna adressen. Länken ensam räcker inte, så ett vidarebefordrat mejl ger ingen åtkomst.
- Sedan loggar personen in på `/kundportal` med en kod i mejlet varje gång. Inget lösenord.
- En inloggning avslutas efter 30 minuter utan aktivitet och efter 12 timmar.

Samma person kan vara kontakt för flera kunder, och för kunder hos flera företag. Då väljer hen kundportal efter inloggningen.

## Så här bjuder du in en kontakt

1. Öppna kunden under **Kunder**.
2. Tryck på **Bjud in** i rutan **Kundportal**.
3. Har du CRM påslaget kan du välja under **Kontaktperson i CRM**. Fakturamottagarna står först. Den som är markerad *Kontakta inte* går inte att välja.
4. Skriv **Namn** och **E-post**.
5. Bocka i **Får godkänna offerter** om personen får binda kunden. Ett godkännande i kundportalen gör offerten till en order. För en kund som är privatperson är rutan ifylld från början, för en kontaktperson hos ett företag är den tom.
6. Tryck på **Skicka inbjudan**.

På kontaktens rad kan du sedan ändra rätten med **Får godkänna offerter** eller **Får inte godkänna offerter**, och byta adress med **Ändra e-post**. Byter du adress blir den nya adressen en ny kontakt för kunden, med samma status och rätt att godkänna offerter. Den gamla adressen kan inte längre logga in i kundportalen, och den tidigare kontaktens inloggningar och uppgifter följer inte med. Var kontakten fortfarande inbjuden går inbjudan till den nya adressen.

Är kunden en privatperson kan du bara bjuda in kundens egen e-postadress, och bara en inloggning, eftersom fakturorna kan visa personuppgifter som ett personnummer för ROT- eller RUT-avdrag.

Bjuder du in samma person igen slutar den tidigare länken att gälla.

## Det här ser kunden

| Sida | Innehåll |
|---|---|
| Översikt | Att betala, varav förfallet, nästa förfallodag, öppna offerter och nya svar |
| Fakturor | Fakturor och kreditfakturor med status *Obetald*, *Förfallen*, *Delvis betald*, *Betald* eller *Krediterad* |
| Faktura | Raderna, momsen, betalningar, krediteringar, påminnelser, PDF och betalningsuppgifterna med knappar för att kopiera |
| Kontoutdrag | Öppna poster på ett valt datum, som sida och PDF |
| Offerter och ordrar | Offerter och ordrar som du har skickat, med PDF |
| Frågor | Kundens frågor och dina svar |
| Mina uppgifter | Namn, e-post, kundens portaler, uppgifterna som fil och *Ta bort min inloggning* |

Utkast visas aldrig. Fakturor som är bokförda som kundförlust visas inte heller. Betalda fakturor visas för de senaste 24 månaderna, och obetalda fakturor visas alltid, hur gamla de än är. Antalet månader ändrar du under **Inställningar** på sidan **Kundportal**.

Personnummer visas aldrig på kundportalens sidor.

### Fakturans PDF

Fakturans PDF sparas när fakturan skapas, och det är den som skickas med e-post, visas i kundportalen och kommer med i arkivpaketet. Ändrar du senare företagets adress, bankgiro eller logotyp ändras inte fakturor som redan har skickats. Fakturor som skapades innan PDF:en började sparas visas som *Kopia av fakturan, återskapad från fakturans uppgifter*; de andra som *Fakturan som den utfärdades*.

### Kontoutdraget

Kontoutdraget räknas fram från fakturorna och betalningarna som har kommit in. Det är en sammanställning och inte ett dokument som företaget har utfärdat, och det står på det.

## Betalningar

Kunden betalar som förut, med bankgiro, plusgiro eller IBAN och OCR-numret. Betalningen syns i kundportalen när du har registrerat den, eller när den har lästs in från banken.

Kunden kan trycka på **Jag har betalat** och ange ett datum. Det bokför ingenting. Du får en notis, och på fakturan ser du under **Kundportal** att kunden har meddelat att den är betald. Fakturorna som kunder har meddelat som betalda, men som ännu inte är betalda i bokföringen, står också överst på **Frågor från kunder**. Kunden ser att det är hens egen notering tills betalningen har kommit in.

Det går inte att betala med kort eller Swish i kundportalen.

## Frågor och bestridanden

Kunden kan skriva till dig om en faktura, en offert eller något annat, med upp till fem bifogade filer (PDF, JPEG eller PNG, högst 10 MB). Väljer kunden **Jag bestrider fakturan** markeras fakturan som bestridd.

1. Du får en notis när en kund skriver.
2. Öppna **Kundportal → Frågor från kunder**.
3. Välj frågan. Kundens bifogade filer öppnar du genom att trycka på dem.
4. Skriv i **Svar**, bifoga gärna filer, och tryck på **Skicka svar**.

Kunden får ett mejl om att du har svarat, med en länk. Själva svaret står bara i kundportalen, så ingenting om fakturan hamnar i ett mejl.

Med **Avsluta** stänger du frågan. Kunden kan öppna den igen genom att skriva. Avslutade frågor tas bort 24 månader efter att de avslutades, utom bestridanden och frågor där du har tryckt på **Spara längre**.

> [!WARNING]
> En bestriden faktura får en varning i rutan **Kundportal** och i rutan **Påminnelser** på fakturan. NONOX stoppar inte påminnelsen. Om en bestriden faktura får påminnas utreds av en jurist.

## Offerter

En kontakt som **Får godkänna offerter** kan godkänna en offert som du har skickat genom att skriva sitt namn och bocka i att hen har läst och godkänner den. Det är samma enkla elektroniska underskrift som när du skickar offerten för signering med en länk, med signeringsbevis. Offerten blir en order, och kunden får en kopia med e-post.

Har du skickat en länk för signering för samma offert slutar länken att gälla när kunden godkänner i kundportalen, och tvärtom. Läs mer i [Signera offerter och avtal](/manual/crm/signering).

## Kontakter, händelser och inställningar

Under **Kundportal** i menyn ser du:

- **Kontakter med tillgång**: alla kontakter, deras status och när de senast var inne. Här kan du **Stäng av** eller **Ta bort** en kontakt. En avstängd kontakt loggas ut direkt.
- **Händelser i kundportalen**: inloggningar och vad kunderna har öppnat.
- **Inställningar**: raden *Kundportalen drivs med NONOX*, hur många månader av betalda fakturor som visas, och en egen text som visas på sidan om personuppgifter.

## Personuppgifter

Ditt företag är personuppgiftsansvarigt för kontaktpersonernas uppgifter i kundportalen, och NONOX är personuppgiftsbiträde. Kundportalen sparar bara e-post och namn om personen, vilka kunder hen har tillgång till, frågorna och när hen loggar in och vad hen öppnar.

- En inbjudan som inte används tas bort efter 14 dagar.
- En kontakt som inte har loggat in på 12 månader får ett mejl. Loggar hen inte in inom 30 dagar tas kontakten bort.
- En avstängd kontakt tas bort efter 30 dagar.
- Händelser sparas i 24 månader.

Kontakten kan själv ladda ner sina uppgifter och ta bort sin inloggning under **Mina uppgifter**. Meddelanden finns då kvar hos dig med avsändaren *Borttagen kontakt*, och IP-adressen och webbläsaren på kontaktens inloggningar töms.

## Se också

- [Kunder](/manual/fakturering/kunder)
- [Påminnelser](/manual/fakturering/paminnelser)
- [Signera offerter och avtal](/manual/crm/signering)
