---
beskrivning: Lägg till dem du köper av med bankgiro, plusgiro eller IBAN och ett förvalt konto, hämta uppgifter från Bolagsverket och kontrollera momsnumret i VIES.
ordning: 10
roller: [owner, admin, accountant, bookkeeper]
rutter: [payables.suppliers]
sokord: [leverantör, leverantörsregister, ny leverantör, bankgiro, plusgiro, iban, vies, hämta uppgifter, bolagsverket]
---

# Leverantörsregistret

I leverantörsregistret har du alla du köper av: grossisten, hyresvärden, elbolaget. När leverantören finns med bankgiro och konto ifyllt går varje faktura från den snabbt att registrera, och betalningen hamnar på rätt ställe.

Registret finns under **Register → Leverantörer**. För varje leverantör ser du vad ni köpt för i år och vad som är obetalt just nu.

![Leverantörsregistret med bankgiro, konto, köpt i år och obetalt.](/manual/bilder/leverantorer/leverantorsregistret.webp)

## Så här lägger du till en leverantör

1. Gå till **Register → Leverantörer** och klicka på **Ny leverantör**. Formuläret visar vilket nummer leverantören får.
2. Skriv **Namn** och, för en svensk leverantör, **Organisationsnummer**.
3. Klicka på **Hämta uppgifter** om knappen finns, så fylls namn och adress i från Bolagsverket (se nedan).
4. Fyll i hur leverantören vill ha betalt: **Bankgiro**, **Plusgiro** eller, för en utländsk leverantör, **IBAN (utländsk leverantör)** och **BIC**.
5. Ange **Betalningsvillkor, dagar**. Det styr förfallodagen när du lämnar den tom på en faktura.
6. Skriv ett konto i **Konto för fakturorna**, till exempel 4010 för varuinköp. Kontot föreslås på varje ny faktura från leverantören.
7. Klicka på **Spara**.

Leverantören får nästa nummer i registrets egen nummerserie. Numren återanvänds aldrig.

## Kontroller som görs när du sparar

- **Bankgiro och plusgiro** kontrolleras mot kontrollsiffran. Är en siffra fel får du veta det direkt, så att ingen betalning går till ett felskrivet nummer.
- **Organisationsnumret** kontrolleras på samma sätt.
- **Momsregistreringsnumret** ska börja med landskoden, till exempel SE556677889901.
- **IBAN** ska börja med landskoden och **BIC** vara 8 eller 11 tecken.
- **Kontot** måste finnas och vara aktivt i kontoplanen.

## Hämta uppgifter från Bolagsverket

När NONOX har kopplats till Bolagsverkets register visas knappen **Hämta uppgifter** vid organisationsnumret. Den hämtar namn, adress, postnummer och ort.

- Numret kontrolleras innan något skickas.
- Svaret är ett förslag. Tomma fält fylls i; har du redan skrivit något annat frågar NONOX om du vill **Använd registrets uppgifter** eller **Behåll mina**.
- Är företaget avregistrerat, eller pågår konkurs eller likvidation, står det i rutan. Kontrollera då att du har rätt nummer.
- Inget sparas förrän du klickar på **Spara**.

Syns inte knappen har den som driftar er NONOX inte lagt in uppgifter från Bolagsverket. Fyll då i fälten för hand.

## Kontrollera momsnumret i VIES

För en leverantör i ett annat EU-land är det klokt att kontrollera momsregistreringsnumret i EU:s register VIES, till exempel före ett inköp där du själv ska redovisa momsen.

1. Spara leverantören med **Momsregistreringsnummer** ifyllt.
2. Öppna leverantören igen med **Ändra**.
3. Klicka på **Kontrollera i VIES** under momsnumret.

Svaret visas som **Giltigt i VIES** eller **Ogiltigt i VIES**, med det namn VIES har och ett **Konsultationsnummer**. Kontrollen sparas i behandlingshistoriken, så att du i efterhand kan visa att den gjordes. Ett ogiltigt svar stoppar ingenting för en leverantör; det är du som bedömer vad det betyder. Läs mer i [Kontrollera momsnummer i VIES](/manual/moms/vies).

## Ändra en leverantör

Klicka på **Ändra** på leverantörens rad, ändra och spara. Ändringen påverkar bara nya fakturor. En faktura som redan är registrerad behåller leverantörens uppgifter som de var den dagen, så att betalningen och bokföringen alltid går att följa.

## Personuppgifter och GDPR

En enskild firma är en person, så leverantörsregistret kan innehålla personuppgifter. I formuläret för en befintlig leverantör finns:

- **Ladda ner registerutdrag (JSON)** – när personen begär ut sina uppgifter.
- **Begränsa behandling** – uppgifterna sparas men kan inte användas för nya dokument förrän du klickar på **Häv begränsad behandling**.
- **Radera enligt GDPR** – finns leverantören i bokföringen anonymiseras den i registret i stället, och fakturorna behåller sina uppgifter. De är räkenskapsinformation som ska sparas i sju år.

## Vanliga frågor

**En tolkad faktura säger att leverantören inte finns, men den finns ju.**
NONOX letar efter leverantören på organisationsnummer, momsnummer, bankgiro, plusgiro, IBAN, e-postdomän och sist namnet. Har leverantören bytt bankgiro eller skriver namnet annorlunda kan träffen missas. Välj då leverantören i listan i stället för att lägga till den igen. Ett liknande namn föreslås som "Menade du …?".

**Kan jag ta bort en leverantör?**
Nej, bara anonymisera den enligt GDPR. Fakturorna från den är räkenskapsinformation.

## Läs också

- [Registrera en leverantörsfaktura](/manual/leverantorer/registrera-en-leverantorsfaktura)
- [Betalfiler till banken](/manual/leverantorer/betalfiler)
- [Inköp från utlandet och omvänd betalningsskyldighet](/manual/leverantorer/inkop-fran-utlandet)
