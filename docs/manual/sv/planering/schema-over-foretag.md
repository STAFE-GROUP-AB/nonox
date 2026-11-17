---
beskrivning: Se veckans pass i flera av dina företag bredvid varandra, med summor per företag och varning när samma person har krockande pass i två av dem.
ordning: 36
roller: [owner, admin, payroll_admin, scheduler]
modul: planning
rutter: [companies.schedule]
sokord: [kedja, flera företag, schema över företag, krock, samma person, två arbetsgivare, avdelning, enhet, summor]
---

# Schema över företag

Driver du flera företag, till exempel flera caféer eller restauranger, kan du se veckans pass i alla på en sida. Sidan finns under **Mina företag → Schema över företag**, och i menyn bara om du har rätt att se schemat i minst ett företag.

![Veckan i flera företag bredvid varandra, med summor överst och en rad per företag.](/bilder/manual/planering/schema-over-foretag.webp)

Sidan är till för att läsa. Här går det inte att lägga, flytta eller ta bort pass. Varje företag är en egen arbetsgivare med egna anställda, egen lön och egen budget, så ändringar görs alltid i företaget självt.

## Vilka företag som visas

Du ser ett företag bara om du är medlem i det, Planering är påslaget där och din roll får se schemat: **Ägare**, **Administratör**, **Löneadministratör** eller **Schemaläggare**. Ett företag där du bara är **Anställd**, eller där Planering är avstängt, syns inte. Det syns inte heller att det finns.

Under **Företag i schemat** bockar du för de företag du vill se. Är inget ibockat visas alla du får se. Klicka på **Visa alla** för att ta bort urvalet.

### Spara ett urval som vy

1. Bocka för företagen.
2. Skriv ett namn i **Spara urvalet som vy**, till exempel "Innerstaden".
3. Klicka på **Spara vy**.

Vyn visas under **Sparade vyer**. Klicka på namnet för att välja samma företag igen, eller på **×** för att ta bort vyn. En vy ändrar inte vad du får se: lämnar du ett företag, eller förlorar rätten att se schemat där, försvinner det ur vyn.

## Veckan

Bläddra med **‹** och **›**, eller klicka på **Idag**. Överst står summor för de valda företagen: **Pass** (med antal öppna), **Planerad tid**, **Uppskattad kostnad** och **Krockar mellan företag**. Kostnaden är samma uppskattning som i företagets eget schema, inte lön.

Varje företag har ett eget block med dagarna i kolumner. Ett pass visar tid, person och avdelning. Utkast har streckad kant och ordet **utkast**. Överst i blocket står passen, öppna pass, planerad tid och kostnaden för företaget den veckan.

## Öppna ett pass i sitt företag

Klicka på ett pass för att byta till företaget och öppna dagen i dess schema. Klicka på **Öppna schemat i företaget** för att öppna veckan. Du byter då företag på samma sätt som i företagsväljaren, och bytet loggas. Du ser en bekräftelse: "Du arbetar nu i …".

## Krockar mellan företag

Samma person kan jobba i två av dina företag. NONOX känner igen henne bara om hon har **samma inloggning** i båda, det vill säga att båda anställningarna är kopplade till samma användare. Namn, personnummer eller e-postadress räcker inte.

Då varnar sidan när:

- två pass överlappar varandra, eller
- det är för kort tid mellan ett pass i det ena företaget och ett i det andra. Gränsen är dygnsvilan i företagens inställningar, och gäller den strängare av de två.

Varningen visas bara för företag du själv får se schemat i. Har personen ett pass i ett företag du inte får se, får du ingen varning och ingen ledtråd om att det finns. Det är en varning, ingenting hindrar planeringen. Hur arbetstidsreglerna gäller för en person med flera arbetsgivare i samma koncern är inte kontrollerat, så bedöm varje fall själv.

Ändra passet i det företag där det ligger, under **Planering → Schema**.

## Enheter i ett företag

Har ett företag flera enheter, till exempel flera lokaler, lägger du dem som **avdelningar** under **Planering → Inställningar** (och kopplar dem till en lokal i personalliggaren om den modulen är på). I [Schemat](/manual/planering/schemat) kan du filtrera på avdelning och lokal, och under veckan står **Per avdelning** med antal pass, öppna pass, planerad tid och kostnad för varje avdelning.

## Det som inte finns

NONOX har ingen gemensam personalpool, inget utlån av personal mellan företag och ingen gemensam budget för flera företag. Varje företag är en egen arbetsgivare, och sådant kräver en egen juridisk bedömning.
