---
beskrivning: Se alla in- och utcheckningar, registrera en glömd utcheckning och rätta fel tid eller fel person. Inget tas bort – rättelsen blir en ny post.
ordning: 70
roller: [owner, admin, staff_ledger_manager]
modul: staff_ledger
rutter: [staff-ledger.ledger]
sokord: [liggaren, rätta, rättelse, ändra tid, fel person, dubbeltryck, manuell registrering, glömt checka ut, glömt checka in]
---

# Liggaren och rättelser

**Personalliggare → Liggaren** visar varje in- och utcheckning, med tid, namn, person- eller samordningsnummer (eller utländskt ID-nummer), lokal och hur registreringen gjordes. Liggaren kan bara skrivas till. Ingenting i den kan ändras eller tas bort, inte ens av ägaren. Fel rättas med en ny post som visas bredvid originalet.

![Liggaren med varje in- och utcheckning och knappen Rätta.](/bilder/manual/personalliggare/liggaren-och-rattelser.webp)

## Hitta i liggaren

Välj period med **Från** och **Till**, välj en **Lokal** eller **Alla lokaler**, och sök med **Sök namn**. Kolumnen **Sätt** visar hur registreringen gjordes:

- **Kiosk med kod** eller **Kiosk med QR-kod**
- **Mobil, självrapporterad position**
- **Manuellt av chef**, med orsaken och vem som registrerade

Vissa poster har en märkning, till exempel **Skickad i efterhand** när kiosken var utan internet, **Enhetens klocka gick fel** eller **Position uppgiven av telefonen, ej verifierad**. Märkningen ändrar inte tiden; den visar bara att något är värt att känna till.

## Så här registrerar du en glömd in- eller utcheckning

1. Välj **Registrera manuellt**.
2. Välj **Person** och **Lokal**.
3. Välj **Incheckning** eller **Utcheckning** under **In eller ut**.
4. Fyll i rätt **Tid**.
5. Skriv en **Orsak**, till exempel *Glömde checka ut vid stängning*.
6. Välj **Spara i liggaren** och bekräfta.

Registreringen visas alltid som manuell, med ditt namn och orsaken.

## Så här rättar du en registrering

Använd rättelse när en registrering har fel tid, gjordes för fel person eller blev dubbel.

1. Hitta posten och välj **Rätta**.
2. Välj vad som är fel under **Vad är fel?**:
   - **Tiden är fel** – fyll i **Rätt tid**.
   - **Registreringen skulle inte ha gjorts (fel person, dubbeltryck)**.
3. Skriv en **Orsak**.
4. Välj **Spara rättelsen** och bekräfta.

Rättelsen visas direkt under den ursprungliga posten, med typ, orsak, vem som rättade och när. En post som rättats som felaktig visas överstruken. Den finns kvar, men räknas inte i passen.

> [!WARNING]
> En manuell registrering eller en rättelse kan inte tas bort. Blev rättelsen fel gör du en ny rättelse.

## Varför kan inget tas bort?

En personalliggare ska visa hur det faktiskt var, även när något blev fel. Därför sparar NONOX allt, och varje post får en kontrollsumma som bygger på den förra. Ändras eller försvinner en post går kedjan sönder, och det upptäcks vid kontrollen varje natt. Vid ett kontrollbesök ser Skatteverket både originalet och rättelsen, och att de är markerade som **Rättad eller manuell**.

## Vanliga frågor

**En anställd glömde checka ut i går och checkade ut på morgonen i stället för in.**
Kiosken checkar ut den som fortfarande är incheckad, så morgonens tryck blev en utcheckning. Rätta tiden på den utcheckningen till när personen gick i går, med **Tiden är fel**. Har personen inte checkat in efteråt registrerar du morgonens incheckning med **Registrera manuellt**.

**Vem kan rätta?**
Ägare, administratörer och personalansvariga. Redovisningskonsulter och revisorer kan läsa liggaren men inte rätta.

**Ser jag personnummer i sin helhet?**
De visas med de fyra sista siffrorna dolda. Har du behörighet finns knappen **Visa** i kolumnrubriken. Det sparas i behandlingshistoriken.

## Läs också

- [Översikten och avvikelser](/manual/personalliggare/oversikten-och-avvikelser)
- [Kontrollbesök](/manual/personalliggare/kontrollbesok)
- [Glömt att checka ut](/manual/personal/glomt-checka-ut) – det din personal läser
