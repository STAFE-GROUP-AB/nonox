---
beskrivning: Gör ett prisavtal med en kund för en period, med fasta priser, mängdpriser och rabatter, skicka det för signering och ändra det med en ny version.
ordning: 26
roller: [owner, admin, accountant, bookkeeper]
rutter: [pricing.agreements, pricing.agreements.show, pricing.agreements.pdf]
sokord: [prisavtal, kundavtal, ramavtal, avtalspris, kundpris, rabattavtal, förhandlat pris, avtalsrabatt, signera avtal]
---

# Prisavtal

Ett prisavtal är en kunds förhandlade priser under en period: ett fast pris per artikel, ett lägre pris från ett visst antal, eller en rabatt i procent. När avtalet är aktivt får kundens fakturor, offerter och order avtalets priser av sig själva när du väljer en artikel.

Prisavtalen finns under **Register → Prisavtal**. Du som bara ska se dem behöver rollen ägare, administratör, redovisningskonsult, bokförare eller revisor; för att göra och ändra prisavtal behövs ägare, administratör, redovisningskonsult eller bokförare. Med CRM påslaget kan även säljare göra prisavtal.

![Prisavtalen med kund, period, status och antal priser.](/bilder/manual/fakturering/prisavtal.webp)

## Så här gör du ett prisavtal

1. Gå till **Register → Prisavtal** och tryck på **Nytt prisavtal**.
2. Sök fram kunden på kundnummer eller namn och välj den.
3. Skriv en **Rubrik**, till exempel *Ramavtal 2027*.
4. Kontrollera **Valuta**. Den fylls i med kundens valuta. Avtalet prissätter bara fakturor, offerter och order i den valutan.
5. Fyll i **Gäller från** och, om avtalet ska sluta ett visst datum, **Gäller till och med**. Tomt slutdatum betyder tills vidare.
6. Kryssa i **En kampanj med lägre pris får gå före avtalets pris** om kunden ska få en billigare kampanj när den finns. Utan kryss gäller avtalets pris även under kampanjer.
7. Tryck på **Skapa utkast**. Avtalet får ett nummer, till exempel *PA-12*, och öppnas.

## Lägg in priserna

På avtalets sida trycker du på **Nytt pris** under **Priser i avtalet**.

1. Välj vad priset **Gäller**: **En artikel** eller **Alla artiklar**.
2. För en artikel: sök fram den och välj den. Välj **Enhet** om priset gäller en annan enhet än artikelns egen.
3. Skriv **Från antal** om priset ska gälla från ett visst antal. Lämnar du fältet tomt gäller priset från första enheten.
4. Välj under **Ange** om det är ett **Fast pris** eller en **Rabatt i procent**. Alla artiklar kan bara få en rabatt.
5. För en rabatt väljer du under **Rabatt på** om den räknas på **Ordinarie pris** eller på en av dina prislistor i avtalets valuta. Ordinarie pris är standardlistans pris för valutan, annars artikelns grundpris.
6. Tryck på **Spara**.

![Ett aktivt prisavtal med villkor, priser och hur det aktiverades.](/bilder/manual/fakturering/prisavtal-avtal.webp)

En artikels eget pris går före en rabatt på alla artiklar. Flera priser för samma artikel med olika **Från antal** blir en trappa, och hela antalet på raden får priset för det högsta steget det når. Samma artikel, enhet och antal kan bara finnas en gång i avtalet.

Medan avtalet är ett utkast kan du ändra och ta bort priser, och ändra villkoren med **Ändra villkor**. Med **Visa som PDF** ser du avtalet som kunden kommer att se det.

## Skicka för signering

Under **Signering** längst ned på avtalets sida skickar du avtalet till kunden. Fyll i e-postadressen och tryck på knappen för att skicka. Kunden får en länk, läser avtalet och skriver under med namnteckning, utan att behöva något konto. Signeringen fungerar som för offerter och avtal; läs [Signering](/manual/crm/signering).

När avtalet är skickat står det som **Väntar på underskrift**, och då går villkoren och priserna inte att ändra. När kunden har signerat blir avtalet **Aktivt**, och du får ett meddelande. Den signerade PDF:en och signeringsbeviset sparas av signeringen.

Signeringen är en enkel elektronisk underskrift. Läs vad det innebär i [Signering](/manual/signering/oversikt) innan du använder den för ett avtal.

Behöver något ändras innan kunden har signerat trycker du på **Tillbaka till utkast**. Alla länkar för signering slutar då att gälla, och du skickar avtalet igen när det är klart.

## Aktivera utan signering

Har kunden godkänt avtalet på annat sätt, till exempel skrivit under på papper eller godkänt det skriftligen på annat sätt, trycker du på **Aktivera utan signering** på ett utkast. Skriv **Skäl**, till exempel *Kunden skrev under på papper den 3 mars*, och tryck på **Aktivera avtalet**. Skälet sparas med avtalet, tillsammans med vem som aktiverade det och när. Spara gärna det underskrivna pappret som underlag. NONOX kontrollerar inte att kunden har godkänt avtalet; aktiveringen registrerar bara att du anger det. Skriv inte personnummer eller andra personuppgifter än nödvändigt i skälet.

Ett avtal kan inte skickas eller aktiveras utan minst ett pris, om slutdatumet redan har passerat, eller om kunden har ett annat prisavtal som gäller, eller väntar på underskrift, någon av samma dagar. Samma kontroll görs när kunden signerar: har avtalet hunnit löpa ut, eller har ett annat avtal för samma dagar börjat gälla, går det inte att signera och kunden ombeds kontakta dig. En länk för signering gäller som längst till avtalets sista dag.

## Ändra ett aktivt avtal

Priserna i ett avtal som har skickats eller gällt ändras aldrig, så att du alltid kan se vad som var avtalat en viss dag. Tryck i stället på **Ändra avtal**. Då görs en ny version som ett utkast, med ett nytt nummer och samma priser, som börjar gälla i morgon. Ändra priserna och datumet, och skicka eller aktivera den nya versionen. En ny version ändrar bara priserna i NONOX; om kunden behöver godkänna ändringen avgörs av ert avtal.

När den nya versionen blir aktiv blir den gamla **Ersatt** och gäller till och med dagen före. En ny version måste börja efter att den gamla började gälla, och den kan inte börja bakåt i tiden, för då skulle priser på dagar som redan har gått ändras. Signeras den efter sitt startdatum går den inte att aktivera; ta tillbaka den till utkast, välj ett nytt datum och skicka den igen. Statusarna visar vilka priser NONOX använder. De ändrar inte vad du och kunden har avtalat.

## Säg upp och upphörande

Tryck på **Säg upp** på ett aktivt avtal och välj **Sista dag avtalet gäller**. Dagen kan inte ligga bakåt i tiden. Avtalet står sedan som **Uppsagt** och prissätter dokument till och med den dagen. Hur och när uppsägningen ska meddelas kunden avgörs av det ni har kommit överens om.

Ett avtal med slutdatum blir **Upphört** morgonen efter sista dagen. Statusarna visar vilka priser NONOX använder. De ändrar inte vad du och kunden har avtalat. Översikt påminner under **Att göra** 30 dagar innan ett aktivt avtal löper ut, om ingen ny version redan är på väg.

Ett avtal som har upphört, sagts upp eller ersatts prissätter fortfarande dokument som är daterade inom dess period. Bara ett utkast kan tas bort, med **Ta bort utkastet**, och bara om det aldrig har skickats för signering; ett utkast som har skickats finns kvar med signeringens historik.

## Koppla till avtalsregistret

Med CRM påslaget kan ett prisavtal kopplas till ett avtal i [avtalsregistret](/manual/crm/avtalsregister) för samma kund. Välj det under **Koppla till avtal i avtalsregistret** när du gör eller ändrar utkastet. Prisavtalet slutar då samma dag som avtalet i avtalsregistret och följer med när det förlängs, och påminnelserna kommer från avtalsregistret i stället för från Översikt. På avtalet i avtalsregistret syns de kopplade prisavtalen under **Prisavtal**.

## Kundens flik Priser

På kundens flik **Priser**, under **Register → Kunder**, syns kundens prisavtal som gäller eller är på väg, och en länk för att göra ett nytt.

## Så väljs priset

Ett aktivt prisavtal går före kundens prislista. Har avtalet inget pris för en artikel används kundens prislista, en kampanj eller grundpriset som vanligt; läs [Prislistor](/manual/fakturering/prislistor). Varifrån priset kommer syns på raden; läs [Priser på fakturor, offerter och order](/manual/fakturering/priser-pa-raderna).

## Bra att veta

- Prisavtal bokför ingenting. Ett pris når bokföringen först när det står på en faktura som utfärdas, och ett pris på en faktura ändras aldrig av att ett avtal ändras.
- NONOX räknar inte upp priser efter index. Har ni avtalat om indexuppräkning gör du den med en ny version. En ny version ändrar bara priserna i NONOX; om kunden behöver godkänna ändringen avgörs av ert avtal.
- PDF:en innehåller bara priser och period. Uppsägningstid och andra villkor avtalar ni separat.
- Priserna i avtalet är exklusive moms.

## Vanliga frågor

**Kan en kund ha flera prisavtal?**
Ja, men bara ett som gäller en viss dag. Ett nytt avtal kan börja när det gamla slutar.

**Vad händer med en offert när avtalet ändras?**
Ingenting. En offert eller order behåller sina priser tills du själv trycker på Uppdatera priser.

## Läs också

- [Prislistor](/manual/fakturering/prislistor)
- [Priser på fakturor, offerter och order](/manual/fakturering/priser-pa-raderna)
- [Signering](/manual/signering/oversikt)
- [Kunder](/manual/fakturering/kunder)
