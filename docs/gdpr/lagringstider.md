# Lagringstider och gallring

| Uppgifter | Hur länge | Varför | Hur |
|---|---|---|---|
| Räkenskapsinformation: verifikationer, fakturor, underlag, lönebesked, AGI, behandlingshistorik | 7 år efter utgången av det kalenderår då räkenskapsåret avslutades | Bokföringslagen 7 kap. 2 § | Kan inte raderas i systemet; arkivpaket per år |
| Inloggningskoder | 1 dygn | Behövs bara för inloggningen | `privacy:prune` varje natt |
| Inbjudningar som inte accepterats | 30 dagar | Saknar ändamål därefter | `privacy:prune` |
| Nycklar till Fortnox vid avbruten flytt | 30 dagar utan aktivitet | Saknar ändamål | `privacy:prune` tömmer nycklarna; vid avslutad flytt direkt |
| Avslagna ansökningar om frånvaro och avslagna utlägg med kvitto | 1 år efter beslutet | Underlag om beslutet ifrågasätts; aldrig räkenskapsinformation | `privacy:prune` |
| Sessioner | 12 timmar, kortare vid inaktivitet | Säkerhet | Mellanprogram |
| Kunder och leverantörer | Tills de raderas eller anonymiseras på begäran | Avtal | Radera enligt GDPR i registret |
| Anställdas uppgifter efter avslutad anställning | Som räkenskapsinformationen för lönebesked; övrigt TODO | Bokföringslagen, skatteförfarandelagen | TODO |

TODO(legal-verify): perioderna för avslagna ansökningar och anställdas uppgifter efter anställningen; hur länge en ROT/RUT-köpares personnummer ska sparas.
