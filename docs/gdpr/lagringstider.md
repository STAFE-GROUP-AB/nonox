# Lagringstider och gallring

| Uppgifter | Hur länge | Varför | Hur |
|---|---|---|---|
| Räkenskapsinformation: verifikationer, fakturor, underlag, lönebesked, AGI, behandlingshistorik | 7 år efter utgången av det kalenderår då räkenskapsåret avslutades | Bokföringslagen 7 kap. 2 § | Kan inte raderas i systemet; arkivpaket per år |
| Inloggningskoder | 1 dygn | Behövs bara för inloggningen | `privacy:prune` varje natt |
| Inbjudningar som inte accepterats | 14 dagar | Inbjudan går ut efter 14 dagar och saknar ändamål därefter | `privacy:prune` |
| Nycklar till Fortnox vid avbruten flytt | 30 dagar utan aktivitet | Saknar ändamål | `privacy:prune` tömmer nycklarna; vid avslutad flytt direkt |
| Avslagna ansökningar om frånvaro och avslagna utlägg med kvitto | 1 år efter beslutet | Underlag om beslutet ifrågasätts; aldrig räkenskapsinformation | `privacy:prune` |
| Säkerhetskopior av databas och filer | 7 dagar | Återställning vid fel; en raderad uppgift försvinner ur kopiorna inom 7 dagar | Krypterade, lagras bara i Sverige; äldre kopior raderas automatiskt varje natt |
| Sessioner | 12 timmar, kortare vid inaktivitet | Säkerhet | Mellanprogram |
| Kunder och leverantörer | Tills de raderas eller anonymiseras på begäran | Avtal | Radera enligt GDPR i registret |
| Anställdas uppgifter efter avslutad anställning | Kontakt-, bank- och fritextuppgifter: tills radering begärs. Namn och personnummer: 7 år efter utgången av det kalenderår då räkenskapsåret för den sista lönen avslutades | Bokföringslagen 7 kap. 2 §; lönebesked och AGI måste kunna rättas | "Radera enligt GDPR" på den anställda: allt raderas om personen aldrig fått lön, annars tas kontakt- och bankuppgifter bort direkt och namn och personnummer när lagringstiden gått ut |
| Personalliggare: in- och utcheckningar med namn, personnummer och arbetsgivare | 2 år efter utgången av det kalenderår då liggaren fördes (antaget, se nedan) | Skatteförfarandelagen 39 kap.; kontrollbesök | `privacy:prune` tar bort poster från hashkedjans äldsta ände per lokal och sparar kedjans nya början |
| Personalliggare: personer i registret (namn, personnummer, kod som kontrollvärde, QR-nyckel, e-post) | Så länge personen arbetar; arkiverade personer tas bort när de inte längre finns i någon kvarvarande post | Behövs för incheckningen och för att liggaren ska kunna visas | `privacy:prune` |
| Personalliggare: foto på kiosken | Tills samtycket återkallas eller personen tas bort | Samtycke, frivilligt per person | "Ta bort foto" under Personal; filen raderas direkt |
| Personalliggare: platskontroll vid mobil incheckning | Positionen sparas aldrig, bara avståndet i meter på posten | Kontroll av att personen är på plats | – |
| Kunder och leverantörer med begränsad behandling (art. 18) | Sparas men används inte för nya dokument | Den registrerades begäran | "Begränsa behandling" i registret |

TODO(legal-verify): personalliggarens bevarandetid (antaget två år efter utgången av det kalenderår då liggaren fördes, 39 kap. skatteförfarandelagen och prop. 2025/26:282); perioderna för avslagna ansökningar och anställdas uppgifter efter anställningen; hur länge en ROT/RUT-köpares personnummer ska sparas.
