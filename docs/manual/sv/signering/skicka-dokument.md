---
beskrivning: Ladda upp en PDF och skicka den för signering i guiden, med undertecknare i vald ordning, kontroll av undertecknaren, sista dag, påminnelser och konfidentiell signering.
ordning: 10
roller: [owner, admin, accountant, bookkeeper, sales]
modul: signing
rutter: [signing.new]
sokord: [ladda upp pdf, skicka för signering, guide, undertecknare, i ordning, samtidigt, kopiemottagare, åtkomstkod, konfidentiell, anställningsavtal]
---

# Skicka ett dokument för signering

Här laddar du upp en PDF och skickar den till de som ska signera. Guiden har fem steg: **Dokument**, **Undertecknare**, **Kontroller**, **Tid och påminnelser** och **Granska och skicka**. Du kommer också hit från **Fler undertecknare** på en offert eller ett avtal. Då är dokumentet redan valt och guiden börjar vid steg 2.

![Första steget i guiden, där du laddar upp en PDF.](/bilder/manual/signering/skicka-dokument.webp)

## Så här gör du

1. Öppna **Signering → Skicka dokument**.
2. Välj **PDF-fil**, skriv gärna en **Rubrik (frivilligt)** och välj vid behov vilken kund eller leverantör dokumentet hör till. Tryck på **Ladda upp och fortsätt**.
3. Under **Undertecknare** skriver du **E-post** för var och en och väljer **Steg**. Tryck på **Alla samtidigt** om alla ska signera på en gång, eller **I ordning** om de ska signera en i taget i den ordning de står. Tryck på **Lägg till undertecknare** för fler. Högst 10.
4. Vill du att någon ska få en kopia utan att signera, tryck på **Lägg till kopiemottagare**. Högst 10.
5. Tryck på **Nästa**. Under **Kontroller** väljer du **Kontroll av undertecknaren** för varje undertecknare.
6. Tryck på **Nästa**. Under **Tid och påminnelser** väljer du **Sista dag att signera (frivilligt)** och om NONOX ska påminna automatiskt. Här kan du också välja **Konfidentiellt**.
7. Tryck på **Nästa**, kontrollera uppgifterna och tryck på **Skicka för signering**.

Du kommer till sidan för signeringen, och de som är i första steget får sin länk i ett mejl.

## Vilken PDF som godtas

Filen kontrolleras innan den sparas, på vad den verkligen är och inte på vad webbläsaren säger. Den ska vara en riktig PDF på högst 20 MB och 100 sidor, utan lösenord. En PDF med skript, bifogade filer, formulär av typen XFA eller åtgärder som skickar uppgifter vidare eller öppnar andra filer eller media godtas inte, och du får veta varför. NONOX rensar aldrig en sådan fil, eftersom det skulle ändra det undertecknarna ser. Spara den som en vanlig PDF och ladda upp den på nytt.

Filen sparas oförändrad i den privata lagringen, och det är exakt den filen som undertecknarna ser och signerar. Ett dokument med fel ändras därför inte: du laddar upp ett nytt. Kontrollen görs av PDF-motorn. Saknas den på den här installationen står det **Kräver PDF-motorn**, och inget dokument kan laddas upp. Se [egen drift](/egen-drift).

Ett dokument som du har laddat upp men inte skickat finns kvar under **Uppladdade dokument som inte är skickade** i första steget. Tryck på **Välj** för att fortsätta med det, eller på **Ta bort** om du laddade upp fel fil. Filen raderas då. Ett dokument som har skickats går inte att ta bort.

## Undertecknare och steg

Undertecknare med samma steg signerar samtidigt. Den som har ett högre steg får sin länk först när alla i steget före har signerat. Ge en ensam undertecknare steg 1. Går någon inte att nå, eller avböjer någon, avslutas signeringen för alla.

## Kontroller

För ett eget dokument är **Kod till e-posten** förvalt: undertecknaren får först en kod med sex siffror till sin e-post innan dokumentet visas. Du kan i stället välja **Ingen kontroll, bara länken** eller **Åtkomstkod som du lämnar själv**, där du skriver en **Åtkomstkod** och ger den på annat sätt. **Föreslå kod** ger en slumpad kod. För offerter och avtal är ingen kontroll förvald. Mer om kontrollerna finns i [Signera offerter och avtal](/manual/crm/signering#kontrollera-den-som-signerar).

En kontroll minskar risken att någon annan använder en vidarebefordrad länk. Den gör inte underskriften säkrare än en enkel elektronisk underskrift.

## Sista dag och påminnelser

Sista dag att signera ligger mellan 3 och 90 dagar fram, och är 30 dagar om du inte väljer. NONOX kan påminna automatiskt, mellan 08 och 18 på dagtid, högst 10 gånger per person. Se [Signera offerter och avtal](/manual/crm/signering#sista-dag-och-paminnelser).

## Konfidentiellt

Bocka i **Konfidentiellt** för dokument som inte ska vara synliga för alla, till exempel ett anställningsavtal. Gör signeringen konfidentiell. I NONOX syns den då bara för dig och för dem som har behörigheten att hantera signering (som standard ägare, administratörer och redovisningskonsulter), och företagets kopia med alla signeringsbevis går bara till dig. Valet går inte att ändra efteråt. Undertecknarna och kopiemottagarna får dokumentet som vanligt.

Bara dokument som du har laddat upp under **Signering** kan vara konfidentiella, inte offerter, avtal eller ÄTA.

Revisorn ser inte konfidentiella signeringar. Den som hanterar signering visar dem för revisorn vid behov. TODO(legal-verify): ska revisorn kunna se konfidentiella signeringar skrivskyddat (ABL 9 kap. 6 §).

> [!IMPORTANT]
> Ett dokument kan innehålla personuppgifter om anställda och andra. Företaget är personuppgiftsansvarigt för innehållet och NONOX behandlar det för företagets räkning. Ta bara med det som behövs för avtalet. Ta inte med personnummer, hälsouppgifter eller andra känsliga uppgifter om de inte måste finnas där, och gör signeringen konfidentiell om dokumentet inte ska ses av alla som får se signeringar. Se [Personuppgifter](/manual/installningar/personuppgifter).

## Läs också

- [Alla signeringar](/manual/signering/alla-signeringar)
- [Signera offerter och avtal](/manual/crm/signering)
- [Signering](/manual/signering/oversikt)
