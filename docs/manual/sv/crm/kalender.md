---
beskrivning: Få dina planerade samtal, möten och uppgifter i Google Kalender, Outlook eller Apples Kalender, lägg in ett enstaka möte och skicka en mötesinbjudan till kontaktpersonen.
ordning: 25
roller: [owner, admin, accountant, bookkeeper, auditor, sales]
modul: crm
rutter: [crm.calendar]
sokord: [kalender, ical, ics, prenumerera, google kalender, outlook, apple, iphone, synk, mötesinbjudan, inbjudan, webcal]
---

# Kalender

Dina planerade samtal, möten och uppgifter kan synas i din egen kalender, i telefonen eller på datorn. Du prenumererar på en hemlig kalenderlänk, och kalendern hämtar ändringarna på egen hand. Ett enstaka möte kan du också lägga in direkt, och du kan skicka mötet som inbjudan till kontaktpersonen.

![Kalendersidan med en nyskapad kalenderlänk.](/bilder/manual/crm/kalender.webp)

## Så här prenumererar du på din kalender

1. Öppna **CRM → Kalender**.
2. Tryck på **Skapa kalenderlänk**.
3. Tryck på **Kopiera länken**, eller på **Öppna i kalendern** om du är i telefonen eller på en Mac.
4. Lägg till länken i din kalender. Under **Så prenumererar du** står hur det går till i Google Kalender, Outlook och Apples Kalender.

Länken visas bara en gång. Behöver du den igen trycker du på **Skapa ny länk**; den gamla slutar då att fungera, och kalendrar som använder den måste prenumerera på den nya.

## Det här finns i kalendern

- Dina planerade samtal, möten och uppgifter med en tid i företaget, också affärernas nästa steg. Från 30 dagar bakåt till ett år framåt.
- Klara aktiviteter försvinner vid nästa uppdatering, och en flyttad aktivitet flyttas.
- Inte med: anteckningar, aktiviteter om någon vars behandling är begränsad, och ingenting ur bokföringen.

Hur ofta kalendern hämtar ändringarna bestämmer kalenderprogrammet. Google Kalender kan dröja flera timmar.

## Detaljer eller inte

Utan detaljer står det bara typen och företaget, till exempel "Möte: Exempelbolaget AB", och en länk till kundkortet i NONOX. En privatperson visas som "privatkund". Det är standard.

Med **Visa detaljer i kalendern** kommer också rubriken, platsen, affären, anteckningen och kontaktpersonens namn med.

> [!WARNING]
> Länken fungerar utan inloggning. Den som får tag i länken ser allt som står i kalendern, och kalendertjänsten (till exempel Google, Microsoft eller Apple) sparar det. Slå bara på detaljer om kalendern är din egen och inte delas med andra.

En kontaktperson som är markerad **Kontakta inte** nämns aldrig i kalendern, och anteckningen om personen följer inte med, oavsett vad du väljer.

Företaget kan stänga av detaljer för alla under **CRM → Inställningar**, med **Användarna får visa detaljer i sina kalendrar**.

## Så här stänger du av länken

1. Öppna **CRM → Kalender**.
2. Tryck på **Stäng av länken** och bekräfta.

Länken slutar fungera direkt. Den slutar också fungera när du lämnar företaget eller inte längre har behörighet till CRM.

## Lägg in ett enstaka möte

Vid varje planerat samtal, möte och uppgift på kundkortet och på kontaktpersonens sida finns **Lägg till i kalendern**. Du får en kalenderfil som du öppnar i ditt kalenderprogram. Den behöver ingen prenumeration.

![Ett planerat möte med Lägg till i kalendern och Skicka inbjudan.](/bilder/manual/crm/lagg-till-i-kalendern.webp)

## Så här skickar du en mötesinbjudan

1. Tryck på **Planera möte** på kundkortet.
2. Välj **Person**, skriv rubrik, välj **När**, **Längd i kalendern** och, om du vill, **Plats**.
3. Kryssa i **Skicka inbjudan till personen** och tryck på **Spara**.

Ett planerat möte kan du också skicka efteråt med **Skicka inbjudan**. Har mötet flyttats skickar du inbjudan igen, så uppdateras mötet i mottagarens kalender.

![Formuläret för ett nytt möte med längd, plats och inbjudan.](/bilder/manual/crm/planera-mote-med-inbjudan.webp)

Inbjudan är ett mejl med mötet som kalenderfil. Du står som avsändare av mötet, och ett svar går till din e-postadress. Rubrik, tid och plats följer med, aldrig anteckningen. Inbjudan loggas som ett mejl på kunden.

En inbjudan går inte att skicka till någon som är markerad **Kontakta inte**, vars adress finns på spärrlistan, eller till en kund med begränsad behandling eller som är inaktiv i kundregistret.

## Koppla Google Kalender eller Outlook

En koppling åt båda hållen till Google Kalender och Outlook (Microsoft 365) är byggd men inte påslagen ännu. Den kräver att den som driftar NONOX har registrerat tjänsten hos Google och Microsoft, och att företaget tillåter det under **CRM → Inställningar** med **Användarna får koppla sin Google- eller Microsoft-kalender**. Då visas **Koppla din kalender** på kalendersidan.

Med kopplingen skriver NONOX dina aktiviteter i din egen kalender. Flyttar du eller ställer in ett av dem där följer aktiviteten i NONOX med. Andra händelser i din kalender läses aldrig in. Ändras samma aktivitet både i NONOX och i kalendern gäller det som står i NONOX. Det sista planerade steget i en öppen affär stängs aldrig från kalendern.

## Bra att veta

- Tider visas i svensk tid.
- Kalenderlänken gäller ett företag. Arbetar du i flera företag skapar du en länk i varje.
- Längden på ett möte är en timme om du inte väljer något annat, och 15 minuter för samtal och uppgifter.
- Kalendern är en kopia. Aktiviteten ändrar du i NONOX.

## Läs också

- [Aktiviteter och nästa steg](/manual/crm/aktiviteter-och-nasta-steg)
- [Kontaktpersonens sida](/manual/crm/kontaktpersonens-sida)
- [GDPR i CRM](/manual/crm/gdpr-i-crm)
- [CRM](/manual/crm)
