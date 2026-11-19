---
beskrivning: Hur länge signerade och osignerade dokument sparas, bevarandespärr, registerutdrag för en undertecknare och radering av det som inte måste sparas.
ordning: 30
roller: [owner, admin]
sokord: [bevarandetid, gallring, gallra, bevarandespärr, radera, radering, registerutdrag, GDPR, dataskydd, räkenskapsinformation, arkivering, sju år, tio år, undertecknare, personuppgifter]
---

# Bevarande och radering av signeringar

Allt som skickas för signering, offerter, avtal, ÄTA och egna dokument, sparas så länge det behövs och gallras sedan automatiskt. Under **Inställningar för företaget → Signering** väljer du hur länge signerade dokument sparas, ser vilka signeringar som har bevarandespärr, tar ut ett registerutdrag för en undertecknare och raderar det som inte måste sparas. Fliken finns för ägare och administratörer, med eller utan modulen Signering. Redovisningskonsulten ser samma flik, men bara bevarandespärrarna.

![Bevarande av signeringar under Inställningar för företaget → Signering.](/bilder/manual/signering/bevarande.webp)

## Hur länge sparas en signering

När en signering är klar, avböjd, tillbakadragen eller har gått ut får den en bevarandeklass och ett datum som den sparas till och med. Du ser dem under **Bevarande** på signeringens sida i modulen Signering.

| Bevarandeklass | Vad | Sparas |
|---|---|---|
| **Räkenskapsinformation** | Signerade offerter, avtal och ÄTA | Minst till och med 31 december sju år efter slutet av det räkenskapsår då affärshändelsen bokfördes, och minst lika länge som andra signerade dokument. Tiden kan inte kortas. |
| **Annat signerat dokument** | Egna dokument som har signerats | Så många år efter signeringen som du har valt, 7 till 25 år. Standard är 10 år. |
| **Inte signerat** | Avböjda, tillbakadragna och utgångna, även om någon hann signera | 12 månader efter att signeringen stängdes. |

Ett eget dokument som du har laddat upp men aldrig skickat tas bort 90 dagar efter uppladdningen.

Tiden kan bli längre efter signeringen, aldrig kortare:

- När en faktura, en betalning, en kreditfaktura eller en kundförlust från offerten (också via en arbetsorder som gjorts från den), avtalet eller ÄTA:t bokförs i ett senare räkenskapsår flyttas datumet till 31 december sju år efter det räkenskapsåret.
- Ett signerat avtal gallras inte så länge avtalet gäller. När det har upphört sparas signeringen minst lika många år som andra signerade dokument, räknat från slutdagen.

Natten efter sista dagen tar NONOX bort hela signeringen: dokumentet, signeringsbevisen, uppgifterna om undertecknarna, fälten, händelserna och kopiemottagarna. I behandlingshistoriken står att den togs bort, med bevarandeklassen och datumet men utan namn eller adresser.

> [!NOTE]
> En signerad offert, ett avtal eller ett ÄTA är oftast räkenskapsinformation, eftersom det visar en affärshändelse. Bokföringslagen kräver att det sparas i sju år efter slutet av det räkenskapsår då affärshändelsen bokfördes. Signaturen är också bevis om avtalet, och därför sparas den minst lika länge som andra signerade dokument.

## Så här ändrar du tiden för andra signerade dokument

1. Öppna **Inställningar för företaget** och välj fliken **Signering**.
2. Skriv antal år under **Bevarandetid för andra signerade dokument (år)**, mellan 7 och 25.
3. Tryck på **Spara bevarandetid**.

Den nya tiden gäller dokument som signeras från och med nu. Ett datum som redan är satt flyttas aldrig tidigare.

## Markera ett eget dokument som räkenskapsinformation

Är ett eget dokument underlag för bokföringen, till exempel ett köpeavtal, öppna signeringen under **Signering → Alla signeringar** och tryck på **Markera som räkenskapsinformation** under **Bevarande**. Dokumentet sparas då minst till och med 31 december sju år efter räkenskapsårets slut. Det går inte att ångra.

## Bevarandespärr

En signering med bevarandespärr gallras inte och kan inte raderas, till exempel under en skatterevision eller en tvist. Det fungerar för offerter, avtal och ÄTA även utan modulen Signering.

## Så här sätter du en bevarandespärr

Bevarandespärr kan sättas och hävas av ägare, administratörer och redovisningskonsulter. Bevarandetiderna och registerutdraget ändrar bara ägare och administratörer.

1. Öppna **Inställningar för företaget** och välj fliken **Signering**.
2. Skriv en del av rubriken under **Sök signering att spärra**, till exempel offertens nummer, och tryck på **Sök**.
3. Välj ett skäl under **Skäl för bevarandespärr**: **Skatterevision**, **Tvist** eller **Annat skäl**.
4. Tryck på **Sätt bevarandespärr** vid rätt signering.

Alla spärrar står under **Bevarandespärrar**. Tryck på **Häv bevarandespärr** när spärren inte behövs längre; signeringen gallras då när bevarandetiden har gått ut. Med modulen Signering kan du också sätta och häva spärren under **Bevarande** på signeringens sida. En konfidentiell signering spärras från sin egen sida.

## Registerutdrag och radering för en undertecknare

När någon som har fått ett dokument för signering vill veta vad ni har sparat om hen, eller vill att det raderas, gör du så här:

1. Öppna **Inställningar för företaget** och välj fliken **Signering**.
2. Skriv den exakta e-postadressen under **E-postadress** i **Registerutdrag och radering för en undertecknare** och tryck på **Sök**.
3. Listan visar varje signering där adressen är undertecknare eller kopiemottagare, och under **Vid radering** om den raderas eller varför den sparas.
4. Tryck på **Hämta registerutdrag**. Du får en ZIP-fil med uppgifterna och de dokument och signeringsbevis personen har signerat. Andra undertecknares uppgifter är inte med.
5. Ska uppgifterna raderas, tryck på **Radera det som får raderas** och bekräfta.

![Registerutdrag och radering för en undertecknare.](/bilder/manual/signering/registerutdrag.webp)

Det här raderas: personens egna uppgifter i signeringar som ingen har signerat. En signering som är ute dras först tillbaka, så att ingen kan signera den längre och avsändaren ser att den är tillbakadragen. De andra mottagarnas uppgifter finns kvar och gallras med signeringen. Är personen den enda undertecknaren raderas hela signeringen. Det här sparas: signerade dokument inom sin bevarandetid, signerade avtal som fortfarande gäller och signeringar med bevarandespärr. Skälet står i listan, med lagrummet, och avslaget skrivs i behandlingshistoriken. Ingen signering ändras bara delvis.

> [!WARNING]
> Radering går inte att ångra. Svara den som frågade och berätta vad som sparas och varför.

Adressen sparas aldrig i behandlingshistoriken, bara en kontrollsumma av den.

## Känsliga dokument

Ett eget dokument kan innehålla vad som helst, till exempel ett anställningsavtal. Använd konfidentiell signering för sådana dokument, så ser bara du och de som hanterar signering dem, och be inte om känsliga uppgifter, som hälsa, i fält som undertecknaren fyller i. Se [Skicka ett dokument för signering](/manual/signering/skicka-dokument).

## Läs också

- [Alla signeringar](/manual/signering/alla-signeringar)
- [Personuppgifter](/manual/installningar/personuppgifter)
- [Signera offerter och avtal](/manual/crm/signering)
