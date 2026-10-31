# Lagringstider och gallring

| Uppgifter | Hur länge | Varför | Hur |
|---|---|---|---|
| Räkenskapsinformation: verifikationer, fakturor, underlag, lönebesked, AGI, behandlingshistorik | 7 år efter utgången av det kalenderår då räkenskapsåret avslutades | Bokföringslagen 7 kap. 2 § | Kan inte raderas i systemet; arkivpaket per år |
| Inloggningskoder | 1 dygn | Behövs bara för inloggningen | `privacy:prune` varje natt |
| Inbjudningar som inte accepterats | 14 dagar | Inbjudan går ut efter 14 dagar och saknar ändamål därefter | `privacy:prune` |
| Nycklar till Fortnox vid avbruten flytt | 30 dagar utan aktivitet | Saknar ändamål | `privacy:prune` tömmer nycklarna; vid avslutad flytt direkt |
| Avslagna ansökningar om frånvaro och avslagna utlägg med kvitto | 1 år efter beslutet | Underlag om beslutet ifrågasätts; aldrig räkenskapsinformation | `privacy:prune` |
| Säkerhetskopior av databas och filer | 7 dagar | Återställning vid fel; en raderad uppgift försvinner ur kopiorna inom 7 dagar | Krypterade, lagras bara i Sverige; äldre kopior raderas automatiskt varje natt |
| Inkorgen: meddelanden som lett till bokföring (`.eml` och bilagor) | Som räkenskapsinformationen | Underlag till verifikationerna (BFL 7 kap.) | Kopplas till verifikationen; tas inte bort av gallringen |
| Inkorgen: avvisade meddelanden | 90 dagar efter avvisningen | Underlag om avvisningen ifrågasätts; har aldrig bokförts | `inbox:prune` varje natt |
| Inkorgen: e-post till den gemensamma adressen som inget företag hämtat | 30 dagar | Kan inte knytas till något företag | `inbox:prune` |
| Inkorgen: meddelanden i karantän eller som inte hanterats | Tills de godkänns eller avvisas | Väntar på beslut | Avvisa i inkorgen; gallras sedan efter 90 dagar |
| Sessioner | 12 timmar, kortare vid inaktivitet | Säkerhet | Mellanprogram |
| Kunder och leverantörer | Tills de raderas eller anonymiseras på begäran | Avtal | Radera enligt GDPR i registret |
| Anställdas uppgifter efter avslutad anställning | Kontakt-, bank- och fritextuppgifter: tills radering begärs. Namn och personnummer: 7 år efter utgången av det kalenderår då räkenskapsåret för den sista lönen avslutades | Bokföringslagen 7 kap. 2 §; lönebesked och AGI måste kunna rättas | "Radera enligt GDPR" på den anställda: allt raderas om personen aldrig fått lön, annars tas kontakt- och bankuppgifter bort direkt och namn och personnummer när lagringstiden gått ut |
| Kunder och leverantörer med begränsad behandling (art. 18) | Sparas men används inte för nya dokument | Den registrerades begäran | "Begränsa behandling" i registret |

TODO(legal-verify): perioderna för avslagna ansökningar och anställdas uppgifter efter anställningen; gallringen av avvisade inkorgsmeddelanden efter 90 dagar; hur länge en ROT/RUT-köpares personnummer ska sparas.
