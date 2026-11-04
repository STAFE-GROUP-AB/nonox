---
beskrivning: Hitta nya kunder i företagsregistret med öppna data från SCB och Bolagsverket – sök på namn, bransch, ort och bolagsform, eller hitta företag som liknar dina bästa kunder.
ordning: 60
roller: [owner, admin, accountant, bookkeeper, auditor, sales]
modul: crm
rutter: [crm.prospecting]
sokord: [prospektering, företagsregister, leads, nya kunder, sni, bransch, liknande kunder, drömkunder, målgrupp, företagsdatabas, scb, bolagsverket, urval, sök företag, organisationsnummer, fritextsök, per sida]
---

# Prospektera i företagsregistret

Under **CRM → Prospektera** söker du bland Sveriges företag och lägger till dem du vill sälja till som prospekt, ett i taget eller många på en gång. Du kan också låta NONOX hitta företag som liknar dina bästa kunder. Uppgifterna kommer från SCB:s och Bolagsverkets öppna data, och det kostar ingenting.

![Träffarna i företagsregistret med bransch, ort och registreringsdatum, och knapparna för att lägga till dem som prospekt eller i en ringlista.](/bilder/manual/crm/prospektera.webp)

## Så här hittar du ett visst företag

1. Öppna **CRM → Prospektera**. Fliken **Sök i registret** är vald.
2. Skriv en del av namnet eller början på organisationsnumret i **Sök på namn eller organisationsnummer**, till exempel *kodkraft* eller *559999-6*. Stora och små bokstäver spelar ingen roll, och du kan skriva organisationsnumret med eller utan bindestreck.
3. Träffarna visas när du slutar skriva. Du behöver skriva minst två tecken.

![Fältet Sök på namn eller organisationsnummer överst bland filtren, med en del av ett företagsnamn ifyllt.](/bilder/manual/crm/prospektera-sok.webp)

Sökningen gäller hela registret, så du behöver inte välja något filter. Väljer du också filter, till exempel en bransch eller en ort, visas bara de företag som stämmer med både sökningen och filtren. Sökningen fungerar på samma sätt under fliken **Liknar mina bästa kunder**.

## Så här söker du fram företag

1. Öppna **CRM → Prospektera**. Fliken **Sök i registret** är vald.
2. Välj en bransch under **Bransch (SNI)**. Vill du vara mer exakt väljer du sedan en kod under **Detaljerad bransch**.
3. Skriv en **Ort** eller början på ett postnummer under **Postnummer börjar med**, och välj **Bolagsform** om du vill.
4. Vill du bara ha nya eller gamla företag fyller du i **Registrerat från** och **Registrerat till**.
5. Träffarna visas direkt under filtren, 25 i taget. Vill du se fler på en gång väljer du 50, 100 eller 200 under **Visa per sida**.

Finns **Län**, **Kommun**, **Anställda, minst** och **Omsättning, minst** i filtren kan du också söka på storlek och region. De uppgifterna finns bara om den som driftar NONOX har läst in SCB:s API (se nedan).

## Så här lägger du till prospekt

1. Tryck på **Lägg till som prospekt** vid ett företag, eller markera flera och tryck på **Lägg till markerade**. Med **Lägg till alla träffar** lägger du till alla, högst 500 åt gången.
2. Välj vem som ska ansvara för de nya prospekten under **Ansvarig för nya prospekt**.
3. Vill du ringa dem direkt kryssar du i **Gör också en ringlista**, skriver **Ringlistans namn** och väljer **Säljare som ringer**. Se [Ringlistor](/manual/crm/ringlistor).

Prospektet får namn, organisationsnummer, bransch och adress från registret, och källan **Företagsregistret**. Ett företag som redan finns bland dina kunder och prospekt visas inte i sökningen och läggs aldrig till två gånger.

## Så här hittar du företag som liknar dina bästa kunder

1. Välj fliken **Liknar mina bästa kunder**.
2. Under **Dina bästa kunder** ser du de kunder som har fakturerats mest de senaste tolv månaderna. De fem första är valda. Kryssa i de kunder du vill ha fler av.
3. Välj hur lika företagen ska vara: under **Bransch** samma SNI-kod (fem siffror), samma huvudgrupp (två siffror) eller ingen bransch alls, och om de ska ha **Ungefär lika många anställda** och ligga i **Samma län** (eller **Samma ort**).
4. Vid varje träff står vilken av dina kunder den liknar och varför, till exempel "samma bransch, samma ort".

![Företag som liknar de bästa kunderna, med en förklaring vid varje träff om vilken kund det liknar och på vad.](/bilder/manual/crm/prospektera-liknande.webp)

NONOX använder ingen AI här. Jämförelsen görs bara på uppgifterna i registret: bransch, storleksklass och region. En kund måste ha ett organisationsnummer som finns i registret för att kunna jämföras.

## Vilka uppgifter som finns

| Uppgift | Källa | Finns alltid |
|---|---|---|
| Namn, organisationsnummer, bolagsform, postadress och ort | SCB | Ja |
| Bransch (SNI 2025, upp till fem koder) | SCB | Ja |
| Om företaget är i drift (registrerat för moms, F-skatt eller som arbetsgivare) | SCB | Ja |
| Reklamspärr | SCB | Ja |
| Registreringsdatum, avregistrering, konkurs, likvidation och rekonstruktion | Bolagsverket | Ja |
| Antal anställda och omsättning (i storleksklasser), län, kommun, telefon och e-post, spärr mot telefonförsäljning (sådana företag tas aldrig med i en ringlista) | SCB:s API | Bara om den som driftar NONOX har en API-nyckel |
| Styrelse, VD, betalningsanmärkningar och kreditbetyg | Kreditupplysningsföretag | Nej, inte än |

Uppgifterna uppdateras en gång i veckan. Under listan står när de senast hämtades. Ett företag som har försvunnit ur SCB:s register tas bort vid nästa uppdatering.

> [!NOTE]
> Registret innehåller bara juridiska personer: aktiebolag, handelsbolag, föreningar, stiftelser och liknande. Enskilda firmor är privatpersoner och finns inte med, och NONOX hämtar inga namn på styrelseledamöter eller andra personer. Antal anställda och omsättning anges som en klass, till exempel 5–9 anställda, eftersom SCB inte lämnar ut de exakta siffrorna.

## Bra att veta

- **Bara företag i drift** visas om du inte kryssar i **Visa även företag som inte är i drift**. Då syns även vilande, avregistrerade och företag i konkurs eller likvidation, med en markering.
- **Reklamspärr.** Företag som har frånsagt sig reklam i SCB:s register visas inte om du inte kryssar i **Visa företag med reklamspärr**. Respektera spärren i dina utskick.
- **Många träffar.** Fler än 10 000 träffar visas som "Fler än 10 000 företag". Smalna av med fler filter eller en sökning.
- **Dela en sökning.** Sökningen, filtren och **Visa per sida** står i sidans adress. Du kan spara den som bokmärke eller skicka den till en kollega, och bakåtknappen tar dig tillbaka till förra sökningen.
- **Kontakta inte.** Har registret ett telefonnummer eller en e-postadress som finns på er lista över personer som har bett att inte bli kontaktade läggs företaget inte till. Se [GDPR i CRM](/manual/crm/gdpr-i-crm).
- **Kontaktpersoner** lägger du till själv på kortet, med rättslig grund. Den som du lägger till ska få information om behandlingen inom en månad. Se [GDPR i CRM](/manual/crm/gdpr-i-crm).
- **Alla som har CRM** kan söka i registret, även en revisor. Att lägga till prospekt och göra ringlistor kräver att du får ändra i CRM.
- **Registret är tomt?** Då har den som driftar NONOX inte läst in det än. Sidan visar i så fall kommandot som läser in det.

## Läs också

- [Ringlistor](/manual/crm/ringlistor)
- [Bevakning av kunder](/manual/crm/bevakning)
- [Företag och personer](/manual/crm/foretag-och-personer)
- [GDPR i CRM](/manual/crm/gdpr-i-crm)
