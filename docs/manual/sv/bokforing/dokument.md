---
beskrivning: Företagets avtal, försäkringar, tillstånd och policyer med versioner, taggar, kopplingar, lagringstid och bevarandespärr. Filerna lagras krypterade.
ordning: 78
roller: [owner, admin, accountant, bookkeeper, auditor, payroll_admin]
rutter: [documents.index, documents.show, documents.all]
sokord: [dokument, dokumentarkiv, avtal, försäkring, tillstånd, policy, lagringstid, bevarandespärr, gallring, versioner, personaldokument, räkenskapsinformation]
---

# Dokument

Under **Dokument** i menyn finns företagets egna dokument: hyresavtal, försäkringsbrev, tillstånd, policyer och annat som inte hör till en faktura eller en verifikation. Varje dokument har versioner, taggar, kopplingar till kunder, leverantörer och projekt, och en lagringstid som NONOX håller reda på. Filerna lagras krypterade, och varje fil får en kontrollsumma (SHA-256) när den laddas upp.

![Dokument med företagets avtal, försäkringar och tillstånd, och hur länge vart och ett ska sparas.](/bilder/manual/bokforing/dokument.webp)

## Ladda upp ett dokument

1. Välj **Ladda upp dokument**.
2. Välj filen. Det går att ladda upp PDF, bilder (JPG, PNG och WebP), textfiler och Word-, Excel- och OpenDocument-dokument på högst 10 MB.
3. Fyll i **Rubrik** och **Kategori**. Välj en kategori i listan eller skriv en egen; dina egna kategorier finns sedan i listan. Skriv inte hälsouppgifter i rubriken; rubriken på ett personaldokument lagras krypterad.
4. Välj under **Sparas som** hur länge dokumentet ska sparas (se nedan).
5. Svara på **Innehåller dokumentet personuppgifter?**.
6. Skriv eventuellt **Taggar**, skilda med kommatecken, och välj **Spara dokumentet**.

Sök med **Sök i rubrik, taggar och text**. Sökningen hittar ord i rubriken, i taggarna och i texten i en PDF. Filtren **Kategori** och **Sparas som** står kvar i adressen, så att du kan spara eller dela en vy.

## Hur länge ett dokument sparas

| Sparas som | Hur länge |
|---|---|
| Räkenskapsinformation | Sju år efter utgången av det kalenderår då räkenskapsåret avslutades (bokföringslagen 7 kap. 2 §). Välj **Räkenskapsår**. Dokumentet kan inte raderas före det. |
| Avtal | Medan avtalet löper och det antal år du väljer under **Spara antal år efter att avtalet upphört**. Lämna **Avtalet upphör** tomt om avtalet löper tills vidare; då kan dokumentet inte raderas, eller sparas som något annat, förrän du anger ett slutdatum. |
| Personal | Dokument om anställda. Bara den som får se känsliga löneuppgifter ser och sparar dem. |
| Övrigt | Kan raderas när som helst av den som får hantera dokument. NONOX raderar aldrig något automatiskt. |

Med **Spara minst till och med** kan du förlänga tiden för ett dokument, som längst 30 år efter regelns datum (eller efter i dag när klassen inte har någon regel). Ett datum du har valt kan du senare ändra eller ta bort, så att tiden kortas igen, men aldrig under regeln. Så länge sjuårstiden för räkenskapsinformation gäller går dokumentet inte att spara som något annat, flytta till ett räkenskapsår som slutade tidigare eller, för ett avtal, kryssa av som räkenskapsinformation, varken i ett steg eller i flera, och ett dokument flyttas aldrig till eller från **Personal**: ladda i stället upp det på nytt i rätt klass. Välj därför räkenskapsår med omsorg: ett för sent år går inte att rätta tillbaka till ett tidigare så länge sjuårstiden gäller.

> **Tips:** Ett avtal kan vara räkenskapsinformation, till exempel ett leasing- eller hyresavtal som visar en affärshändelse (bokföringslagen 1 kap. 2 §). Kryssa då i **Avtalet är räkenskapsinformation** och välj **Räkenskapsår**. Avtalet sparas i minst sju år efter räkenskapsåret och kommer med i årets arkivpaket.

> **Tips:** Räkenskapsinformation från år innan företaget började med NONOX: lägg upp räkenskapsåret under **Räkenskapsår**, eller spara dokumentet som **Övrigt** med **Spara minst till och med** satt till sista dagen det ska sparas.

## Dokumentets sida

Välj rubriken i listan för att öppna dokumentet. Där visas den aktuella versionen och alla tidigare versioner med kontrollsumma.

![Ett dokument med den aktuella versionen, versionerna, kopplingarna och bevarandet.](/bilder/manual/bokforing/dokument-sidan.webp)

- **Ladda upp en ny version**: den nya versionen blir aktuell. De tidigare versionerna finns kvar och kan inte ändras.
- **Ändra uppgifter**: rubrik, kategori, lagringstid, taggar och personuppgifter.
- **Kopplingar**: välj under **Koppla till** en kund, leverantör, ett projekt, en anläggningstillgång eller, med modulen Arbetsorder, en arbetsorder, och sedan **Lägg till koppling**. Ett personaldokument kan kopplas till en anställd.
- **Underlag till verifikationer** (räkenskapsinformation): skriv verifikationens serie och nummer, till exempel A12, och välj **Lägg som underlag**. Samma fil blir underlag till verifikationen, utan kopia, och verifikationen ändras inte.

## Bevarandespärr och radering

En **bevarandespärr** hindrar att ett dokument raderas, oavsett lagringstid, till exempel under en skatterevision eller en tvist. Välj **Skäl** (Skatterevision, Tvist eller Annat skäl) och **Sätt bevarandespärr**, och **Häv bevarandespärren** när den inte behövs längre. Spärren måste hävas innan dokumentet kan raderas. Ägare, administratörer och redovisningskonsulter kan sätta och häva spärren; på personaldokument gör löneadministratören det.

**Radera dokumentet** tar bort dokumentet med alla versioner. Knappen finns bara när lagringstiden har gått ut och ingen bevarandespärr är satt; annars visar sidan varför dokumentet ska sparas och från vilket datum det kan raderas. Radering är också vägen när någon begär att deras personuppgifter raderas: där bokföringslagen kräver att dokumentet sparas går det inte att radera förrän tiden har gått ut. Är samma fil underlag till en verifikation finns filen kvar där.

> **Obs:** Varje uppladdning, ändring, koppling, spärr, radering och öppnad fil skrivs i behandlingshistoriken, utan dokumentets rubrik eller innehåll.

## Vem kan göra vad

| Roll | Se | Ladda upp, ändra och radera | Bevarandespärr |
|---|---|---|---|
| Ägare, administratör, redovisningskonsult | Ja | Ja | Ja |
| Bokförare | Ja | Ja | Nej |
| Revisor | Ja | Nej | Nej |
| Attestant | Nej | Nej | Nej |
| Löneadministratör (modulen Lön) | Bara personaldokument | Bara personaldokument | Bara personaldokument |

Personaldokument kräver dessutom behörigheten att se känsliga löneuppgifter, som löneadministratören har. Den som inte har den ser inte att dokumenten finns. Är modulen Lön avstängd kan ägaren se, ladda ner och radera personaldokumenten, till exempel för att svara på en begäran om registerutdrag eller radering; varje gång skrivs i behandlingshistoriken.

## Alla dokument

Under **Alla dokument** (länken överst på sidan Dokument) ser du företagets egna dokument och filerna i övriga delar av NONOX i en lista, nyast först: underlag på verifikationer, leverantörsfakturor, bilagor i Inkorgen, dokument för NONOX Avtal, PDF:er som skickats för signering och signeringsbevis, filer i kundportalen, godkända protokoll från Styrelserum och lönebesked. Ingen fil kopieras: rubriken öppnar filen där den ligger, och **Hör till** visar vad den hör till, till exempel verifikationen eller leverantörsfakturan.

![Alla dokument, med företagets egna dokument och filerna i övriga delar av NONOX.](/bilder/manual/bokforing/dokument-alla.webp)

Välj **Källa** för att bara se en del, och sök under **Sök i rubriken**. Både sökningen och filtret står kvar i adressen. Sökningen läser inte krypterade rubriker, som personaldokument och dokument för NONOX Avtal.

Varje källa visas bara för den som får öppna den i sin egen del, med samma behörighet som där:

| Källa | Vem ser den |
|---|---|
| Företagets dokument | Den som får se dokument; personaldokument kräver dessutom behörigheten att se känsliga löneuppgifter |
| Underlag på verifikationer | Den som får se verifikationer |
| Leverantörsfakturor | Den som får se leverantörsfakturor |
| Inkorgen | Den som får se Inkorgen |
| Kollektivavtal (NONOX Avtal) | Den som får hantera avtal och använda NONOX AI; dokument om en anställd kräver dessutom behörigheten att se känsliga löneuppgifter |
| Signering | Den som får se fakturering (för offerter) eller CRM (för avtal) |
| Kundportalen | Den som får se kundportalen |
| Styrelserum, protokoll | Den som får se möten i Styrelserum |
| Lönebesked | Den som får se lönebesked och dessutom känsliga löneuppgifter |

Ägare, redovisningskonsulter och revisorer ser lönebesked i lönearkivet, inte här: här visas de bara för den som får se känsliga löneuppgifter, till exempel löneadministratören.

En medlem som är begränsad till vissa kostnadsställen eller projekt ser inte sidan alls. Den som inte får se någon källa får ett meddelande om att sidan inte är tillgänglig för dem.

## Registerutdrag

Registerutdraget för en kund, leverantör eller anställd tar med dokumenten som är kopplade till personen: rubrik, klass, lagringstid, versioner med datum och kontrollsumma, och adressen till varje fil för den som får öppna den. Bara dokument som den som tar ut utdraget får se tas med; personaldokument kräver behörigheten att se känsliga löneuppgifter. Utdraget anger hur många dokument som inte togs med, utan rubrik.

## Arkivpaketet

Dokument som hör till ett räkenskapsår kommer med i årets [arkivpaket](/manual/bokslut/arkivpaket) under `dokument/`, med alla versioner och en förteckning med varje versions kontrollsumma från uppladdningen. Får den som skapar paketet inte se personaldokument står det i förteckningen och i LASMIG.txt hur många som inte togs med.

## Läs också

- [Underlag](/manual/bokforing/underlag)
- [Arkivpaket](/manual/bokslut/arkivpaket)
