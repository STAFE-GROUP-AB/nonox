---
beskrivning: Följ varje affär genom säljtratten med värde, väntat avslut och ett nästa steg, och avsluta den som vunnen eller förlorad.
ordning: 22
roller: [owner, admin, accountant, bookkeeper, sales]
modul: crm
rutter: [crm.deals, crm.deals.show]
sokord: [affär, affärer, säljtratt, pipeline, kanban, steg, sannolikhet, vunnen, förlorad, förlustorsak, vinn tillbaka, offert, order]
---

# Säljtratt och affärer

En **affär** är en försäljning du arbetar med: ett ramavtal, ett projekt, en order. Den hör till en kund eller ett prospekt och har ett värde, ett datum då du räknar med att den avslutas och en ansvarig säljare. Affären går genom stegen i **säljtratten** tills den är **vunnen** eller **förlorad**.

![Säljtratten med en kolumn per steg, antal, värde och viktat värde, och ett kort per affär.](/bilder/manual/crm/saljtratten.webp)

## Säljtratten

Under **CRM → Affärer** ser du säljtratten. Varje kolumn är ett steg, med antalet affärer, deras värde och det **viktade** värdet: värdet gånger stegets sannolikhet. Varje kort visar affären, kunden, värdet, väntat avslut och nästa steg.

- Är nästa steg försenat, eller saknas det, står det i rött.
- Har väntat avslut passerat står datumet i rött.

Flytta en affär till ett annat steg genom att dra kortet dit, eller välj steg på kortet och tryck på **Flytta**. I mobilen visas ett steg i taget: välj steg med knapparna överst.

![Säljtratten i mobilen med ett steg i taget och Flytta på varje kort.](/bilder/manual/crm/saljtratten-mobil.webp)

Filtrera på **Ansvarig** (välj **Mina affärer** för dina egna) och, om företaget har flera, på **Säljtratt**. **Utan nästa steg** visar de affärer som ingen har planerat något för. Filtren sparas i adressen.

## Så här skapar du en affär

1. Tryck på **Ny affär** i säljtratten, eller under **Affärer** på kundens kort.
2. Sök fram kunden eller prospektet och skriv en **Rubrik**.
3. Fyll i **Värde i kronor, utan moms**, **Väntat avslut**, **Steg** och **Ansvarig**.
4. Planera **Nästa steg**: typ, vad och när.
5. Tryck på **Skapa affären**.

## Nästa steg är obligatoriskt

En öppen affär ska alltid ha ett planerat nästa steg, så att ingen affär faller mellan stolarna. Därför:

- En ny affär skapas alltid med sitt första steg.
- När du klarmarkerar affärens sista planerade steg frågar NONOX efter nästa, och det går inte att hoppa över. Planera nästa steg, så klarmarkeras det gamla samtidigt. Eller tryck på **Avsluta affären** och markera den som vunnen eller förlorad.
- Affärer som ändå saknar ett nästa steg visas under **Att göra** på översikten och i rött i säljtratten.

Det gäller i affärens kort, på kundens kort och under **Mina aktiviteter**.

![Affärens kort med värde, viktat värde, steg, nästa steg och historik.](/bilder/manual/crm/affarens-kort.webp)

## Offert från affären

1. Öppna en öppen affär och tryck på **Ny offert**. Är kunden ett prospekt görs det till kund först, om du får skapa kunder.
2. Offerten öppnas med en rad: affärens namn som benämning och affärens värde som pris, med 25 % moms. Ändra raderna och skicka offerten som vanligt.
3. Vill du utgå från en offertmall väljer du **Ny offert från mall** och trycker på **Skapa offert**.

Offerten kopplas till affären. Blir den en order eller en faktura följer de med affären, så att **Offert**, **Order** och **Faktura** redan är valda när du trycker på **Vunnen**. Det kräver samma behörighet som att skapa offerter.

## Vunnen affär

1. Öppna affären och tryck på **Vunnen**.
2. Justera värdet om ni kom överens om något annat.
3. Koppla gärna **Offert**, **Order** eller **Faktura** från bokföringen. Har du gjort offerten från affären är de redan valda. Då räknas fakturan in i säljarens mål, och kortet länkar dit.
4. Är det ett prospekt kan du bocka i att göra det till kund, om du får skapa kunder.
5. Tryck på **Markera som vunnen**.

## Förlorad affär och vinn tillbaka

1. Öppna affären och tryck på **Förlorad**.
2. Välj **Orsak**. Den krävs, så att det går att se vad affärer förloras på.
3. Skriv en kommentar om du vill.
4. Kontrollera datumet under **Vinn tillbaka**. Det är förifyllt sex månader fram (företagets inställning). Lämna det tomt om du inte vill ha någon påminnelse.
5. Tryck på **Markera som förlorad**.

På datumet får den ansvarige en uppgift, **Vinn tillbaka**, under Mina aktiviteter. Datumet går att ändra eller ta bort på affären med **Ändra datum**. En kund som du sätter som **Förlorad** på kundkortet får samma påminnelse. Har kunden vunnits tillbaka i en annan affär före datumet kommer ingen påminnelse.

Är mottagaren en fysisk person, även en enskild firma, får du bara mejla eller sms:a erbjudanden om personen har samtyckt, eller om personen är kund, erbjudandet gäller era egna liknande varor eller tjänster och personen fick tacka nej när adressen samlades in och kan göra det i varje utskick (19 § marknadsföringslagen). Mejl till ett företags adress ska visa vem som skickar och hur mottagaren tackar nej (20 §). Ringer du en privatperson bör numret kontrolleras mot NIX. En person som är märkt Kontakta inte får ingen påminnelse, och inte heller en kund med begränsad behandling eller en kund som är inaktiv eller anonymiserad i kundregistret.

## Inställningar

Under **CRM → Inställningar** ändrar den som har behörighet säljtrattarna och orsakerna:

- Byt namn på steg, ändra ordning och sannolikhet, och lägg till steg. En säljtratt kan ha högst sju steg.
- Ett steg med affärer tas bort genom att du väljer vilket steg affärerna ska flyttas till.
- Lägg till flera säljtrattar, till exempel en för service och en för nyförsäljning, och välj vilken som är förvald. En säljtratt utan öppna affärer kan arkiveras.
- Lägg till, byt namn på och arkivera orsaker till förlorade affärer.
- Ställ in hur många månader efter en förlust påminnelsen kommer.

## Bra att veta

- Alla säljare ser alla affärer i företaget, så att en kollega kan ta över. **Mina affärer** är ett filter.
- Värdet är alltid utan moms.
- Varje flytt mellan steg sparas i affärens historik.
- Rubriken och kommentaren kan nämna personer. De finns med i registerutdraget och tas bort när kunden anonymiseras. Se [GDPR i CRM](/manual/crm/gdpr-i-crm).

## Läs också

- [Prognos och mål](/manual/crm/prognos-och-mal)
- [Översikt för försäljningen](/manual/crm/instrumentpanel)
- [Aktiviteter och nästa steg](/manual/crm/aktiviteter-och-nasta-steg)
