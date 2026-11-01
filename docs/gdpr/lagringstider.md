# Lagringstider och gallring

| Uppgifter | Hur länge | Varför | Hur |
|---|---|---|---|
| Räkenskapsinformation: verifikationer, fakturor, underlag, lönebesked, AGI, behandlingshistorik | 7 år efter utgången av det kalenderår då räkenskapsåret avslutades | Bokföringslagen 7 kap. 2 § | Kan inte raderas i systemet; arkivpaket per år |
| Användarkonto (namn, e-post, medlemskap) | Tills användaren raderar kontot | Avtal | "Radera mitt konto" på profilen: kontot raderas, eller pseudonymiseras om bokföring eller behandlingshistorik hänvisar till det (då finns bara användar-id:t kvar, med namnet "Raderad användare") |
| Inloggningskoder | 1 dygn | Behövs bara för inloggningen | `privacy:prune` varje natt |
| Inbjudningar som inte accepterats | 14 dagar | Inbjudan går ut efter 14 dagar och saknar ändamål därefter | `privacy:prune` |
| Nycklar till Fortnox vid avbruten flytt | 30 dagar utan aktivitet | Saknar ändamål | `privacy:prune` tömmer nycklarna; vid avslutad flytt direkt |
| Avslagna ansökningar om frånvaro och avslagna utlägg med kvitto | 1 år efter beslutet | Underlag om beslutet ifrågasätts; aldrig räkenskapsinformation | `privacy:prune` |
| Säkerhetskopior av databas och filer | 7 dagar | Återställning vid fel; en raderad uppgift försvinner ur kopiorna inom 7 dagar | Krypterade, lagras bara i Sverige; äldre kopior raderas automatiskt varje natt |
| Inkorgen: meddelanden som lett till bokföring (`.eml` och bilagor) | Som räkenskapsinformationen | Underlag till verifikationerna (BFL 7 kap.) | Kopplas till verifikationen; tas inte bort av gallringen |
| Inkorgen: avvisade meddelanden | 7 dagar efter avvisningen | Hinner ångras om avvisningen var fel; har aldrig bokförts | `inbox:prune` varje natt |
| Inkorgen: e-post till den gemensamma adressen som inget företag hämtat | 90 dagar | Kan inte knytas till något företag | `inbox:prune` |
| Inkorgen: meddelanden i karantän som ingen hanterat | 90 dagar efter mottagandet | Väntar på beslut | `inbox:prune` |
| Inkorgen: skräppost från avsändare som inte är godkända | Sparas inte alls | Behövs inte | Tas bort vid mottagandet; bara en rad i behandlingshistoriken utan innehåll |
| Sessioner | 12 timmar, kortare vid inaktivitet | Säkerhet | Mellanprogram |
| Passkeys (publik nyckel, namn, senast använd) | Tills användaren tar bort den, den återställs eller kontot raderas | Säkerhet vid inloggning | Profil → Passkeys; återställning av ägare eller administratör; "Radera mitt konto" tar bort alla |
| Senast öppnade i sökningen (titel, adress, tid) | Högst 50 rader per användare och företag | Visa det användaren nyss öppnat | Äldre rader trängs undan; tas bort med företaget och när användaren raderar sitt konto |
| Uppgifter, kommentarer och checklistor | Så länge uppgiften finns | Arbetet mellan byrå och kund | Tas bort med uppgiften eller företaget; inte räkenskapsinformation |
| Notiser och sparade vyer | Tills användaren raderar sitt konto | Visa användaren vad som hänt | "Radera mitt konto" |
| Personalliggare: kioskens kopia för offlineläge | Personnummer och de senaste 31 dagarnas pass, ersätts vid varje ny hämtning | Kontrollbesök även utan internet | Krypterad på kiosken (AES-256-GCM) med en nyckel som bara öppnas med en chefs kod |
| Kunder och leverantörer | Tills de raderas eller anonymiseras på begäran | Avtal | Radera enligt GDPR i registret |
| Löneunderlag från schemasystem (originalfilen, PAXml eller CSV, med personnummer, och filens rader) | Medan lönekörningen är öppen: tills filen tas bort. När lönekörningen är attesterad: som räkenskapsinformationen, 7 år efter utgången av det kalenderår då räkenskapsåret avslutades | Underlag för lönen och lönebokföringen (Bokföringslagen 7 kap. 2 §). TODO(legal-verify): om originalfilen måste sparas eller om de godkända raderna räcker som underlag | "Ta bort filen" på filens sida i en öppen lönekörning tar bort filen, dess rader och de godkända raderna i körningen; i en attesterad lönekörning kan filen inte tas bort. Ingen automatisk gallring än |
| Anställdas uppgifter efter avslutad anställning | Kontakt-, bank- och fritextuppgifter: tills radering begärs. Namn och personnummer: 7 år efter utgången av det kalenderår då räkenskapsåret för den sista lönen avslutades | Bokföringslagen 7 kap. 2 §; lönebesked och AGI måste kunna rättas | "Radera enligt GDPR" på den anställda: allt raderas om personen aldrig fått lön, annars tas kontakt- och bankuppgifter bort direkt och namn och personnummer när lagringstiden gått ut |
| Personalliggare: in- och utcheckningar med namn, personnummer och arbetsgivare | 2 år efter utgången av det kalenderår då liggaren fördes (antaget, se nedan) | Skatteförfarandelagen 39 kap.; kontrollbesök | `privacy:prune` tar bort poster från hashkedjans äldsta ände per lokal och sparar kedjans nya början |
| Personalliggare: personer i registret (namn, personnummer, kod som kontrollvärde, QR-nyckel, e-post) | Så länge personen arbetar; arkiverade personer tas bort när de inte längre finns i någon kvarvarande post | Behövs för incheckningen och för att liggaren ska kunna visas | `privacy:prune` |
| Personalliggare: foto på kiosken | Tills samtycket återkallas eller personen tas bort | Samtycke, frivilligt per person | "Ta bort foto" under Personal; filen raderas direkt |
| Personalliggare: platskontroll vid mobil incheckning | Positionen sparas aldrig, bara avståndet i meter på posten | Kontroll av att personen är på plats | – |
| Kunder och leverantörer med begränsad behandling (art. 18) | Sparas men används inte för nya dokument | Den registrerades begäran | "Begränsa behandling" i registret |

TODO(legal-verify): personalliggarens bevarandetid (antaget två år efter utgången av det kalenderår då liggaren fördes, 39 kap. skatteförfarandelagen och prop. 2025/26:282); perioderna för avslagna ansökningar och anställdas uppgifter efter anställningen; gallringen av avvisade inkorgsmeddelanden efter 7 dagar; hur länge en ROT/RUT-köpares personnummer ska sparas.
