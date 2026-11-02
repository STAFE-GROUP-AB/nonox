---
beskrivning: Skriv en faktura med kund, datum och rader, spara den som utkast och skapa den när den är klar. Så får den nummer, OCR-nummer och bokförs.
ordning: 30
roller: [owner, admin, accountant, bookkeeper]
rutter: [invoicing.invoices.index, invoicing.invoices.create, invoicing.invoices.edit]
sokord: [ny faktura, kundfaktura, fakturera, utkast, fakturanummer, fakturarad, rabatt, öresavrundning]
---

# Skapa en faktura

En faktura i NONOX börjar som ett utkast som du kan ändra hur mycket du vill. När du trycker på **Skapa fakturan** får den ett fakturanummer och ett OCR-nummer, och den bokförs. Efter det går den aldrig att ändra – blev något fel krediterar du den.

Fakturorna finns under **Försäljning → Fakturor**.

![En ny faktura: kund, datum, ROT eller RUT, omvänd betalningsskyldighet och raderna.](/bilder/manual/fakturering/skapa-en-faktura.webp)

![Ett utkast till faktura med kund, datum och rader.](/bilder/manual/fakturering/utkast.webp)

## Så här gör du

1. Gå till **Försäljning → Fakturor** och tryck på **Ny faktura**.
2. Välj **Kund**. Har du inga kunder ännu finns länken **Lägg till en kund**. Kundens momsbehandling följer med, till exempel försäljning utomlands eller omvänd betalningsskyldighet.
3. Kontrollera **Fakturadatum**. Det är dagens datum från början.
4. Lämna **Förfallodag** tom om kundens betalningsvillkor ska gälla, eller välj ett eget datum. Förfallodagen kan inte vara före fakturadatum.
5. Fyll i **Leveransdatum** om leveransen skedde en annan dag än fakturadatum. Tomt betyder samma dag som fakturadatum.
6. Fyll i **Er referens** (kundens kontaktperson) och **Vår referens** om du vill.
7. Fyll i raderna under **Rader**. Skriv ett artikelnummer i **Artikel** för att hämta artikeln, eller skriv en egen rad med **Benämning**, **Antal**, **Enhet**, **À-pris** och **Moms**. Priserna är utan moms. Tryck på **+ Lägg till rad** för fler rader och på **×** för att ta bort en.
8. Skriv eventuellt en rabatt i procent i **Rabatt %** på en rad.
9. Skriv ett **Meddelande på fakturan (frivilligt)** om kunden ska få veta något mer.
10. Tryck på **Spara som utkast** om du vill fortsätta senare. Utkastet ligger kvar under **Fakturor** med filtret **Utkast**.
11. När allt stämmer trycker du på **Skapa fakturan**. NONOX frågar en gång till: *Fakturan får ett nummer, bokförs och kan sedan inte ändras, bara krediteras.* Tryck på **Ja, skapa**.

Du kommer till fakturans sida, där du skickar den till kunden. Läs [Skicka fakturan och öppna PDF:en](/manual/fakturering/skicka-en-faktura).

## Summorna

Under raderna räknas summorna om medan du skriver:

- **Summa exkl. moms** – alla rader efter rabatt.
- **Moms 25 %**, **Moms 12 %** och så vidare – momsen räknas per momssats på summan av raderna med den satsen, så att öresdifferenser mellan raderna inte uppstår.
- **Öresavrundning** – totalbeloppet avrundas alltid till hela kronor, och skillnaden visas här.
- **Att betala** – det kunden ska betala.

När du sparar räknar NONOX om allt på servern, så det som sparas är alltid rätt räknat.

## Det här händer i bokföringen

Vad som bokförs, och när, beror på företagets bokföringsmetod:

- **Fakturametoden**: fakturan bokförs när du skapar den, i serie B (Kundfakturor). Kundfordran bokförs på 1510, försäljningen på radernas intäktskonton, momsen på 2611, 2621 eller 2631 och öresavrundningen på 3740.
- **Kontantmetoden**: ingenting bokförs när fakturan skapas. Försäljningen och momsen bokförs när betalningen kommer.

Läs mer i [Fakturametoden och kontantmetoden](/manual/fakturering/fakturametoden-och-kontantmetoden).

## Det som kontrolleras när du skapar fakturan

- Varje rad behöver en benämning och ett antal, och fakturan behöver minst en rad och ett belopp.
- Under fakturametoden måste fakturadatumet ligga i ett öppet räkenskapsår och får inte ligga i en låst period. Annars stoppas fakturan med ett meddelande om datumet.
- Fakturanumret är löpande utan luckor, och fakturor och kreditfakturor delar samma nummerserie.
- En faktura till ett företag på mer än 4 000 kronor inklusive moms, och alla fakturor till företag i andra EU-länder, måste ha kundens adress: gatuadress, postnummer och ort. Saknas den stoppas fakturan, och du fyller i adressen på kunden under [Kunder](/manual/fakturering/kunder). Det står i mervärdesskattelagen 17 kap. 24 §. Kreditfakturor och fakturor till privatpersoner (utom ROT) stoppas inte.
- En rad med **0 %** moms på en faktura i Sverige får ett skäl, som skrivs ut på fakturan tillsammans med beloppet utan moms: välj till exempel **Fastighet, uthyrning av lokal eller bostad** eller **Utanför momsen, till exempel utlägg** i listan under momssatsen. Väljer du inget skrivs *Undantagen från skatteplikt för mervärdesskatt* ut. Lagen kräver att en faktura utan moms säger varför (mervärdesskattelagen 17 kap. 24 §).
- Är företaget inte momsregistrerat stoppas en faktura med moms, om du inte bockar i **Ta ändå ut moms på fakturan**. Moms som tas ut på en faktura ska nämligen betalas in till Skatteverket, också om företaget inte är registrerat (mervärdesskattelagen 16 kap. 23 §). En faktura utan moms får texten om varför företaget inte tar ut moms, som du väljer under [Företagets uppgifter](/manual/installningar/foretagets-uppgifter).

## Listan över fakturor

Under **Försäljning → Fakturor** ser du nyckeltal överst och sedan alla fakturor. Filtrera med **Alla**, **Utkast**, **Obetalda**, **Förfallna** och **Betalda**. Ett utkast visas som *Utkast* i stället för ett nummer, och en kreditfaktura är märkt *(kredit)*. Tryck på numret för att öppna fakturan, eller utkastet för att fortsätta skriva.

## Vanliga frågor

**Kan jag ändra en faktura som jag redan har skapat?**
Nej. En skapad faktura är bokförd och räkenskapsinformation. Kreditera den och skapa en ny. Läs [Kreditera en faktura](/manual/fakturering/kreditfaktura).

**Kan jag ta bort ett utkast?**
Det finns ingen knapp för att ta bort ett fakturautkast i dag. Ett utkast har inget nummer och syns inte i bokföringen, så det gör ingen skada att det ligger kvar.

**Kunden saknas i listan när jag ska välja kund.**
En kund med begränsad behandling enligt GDPR kan inte väljas. Läs [Kunder](/manual/fakturering/kunder).

**Kan jag fakturera i euro?**
Ja. Välj valutan i **Valuta** på fakturan, eller ställ in den på kunden. Läs [Faktura i utländsk valuta](/manual/fakturering/faktura-i-utlandsk-valuta).

## Läs också

- [Skicka fakturan och öppna PDF:en](/manual/fakturering/skicka-en-faktura)
- [Artiklar](/manual/fakturering/artiklar)
- [Offerter och order](/manual/fakturering/offerter-och-order)
- [Lås en period](/manual/bokforing/las-en-period)
