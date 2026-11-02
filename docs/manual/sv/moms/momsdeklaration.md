---
beskrivning: Granska momsdeklarationens rutor för en period, se vilka konton varje ruta kommer från, ladda ner filen till Skatteverket, bokför momsen i serie M och lås perioden.
ordning: 10
roller: [owner, admin, accountant]
rutter: [vat.index, vat.file]
sokord: [momsdeklaration, momsrapport, momsrutor, ruta 49, moms att betala, moms att få tillbaka, momsavräkning, 2650, serie M, eskd, skattedeklaration, rätta momsdeklaration, kompletterande momsredovisning, tillägg]
---

# Momsdeklarationen

Momsdeklarationen räknas fram ur bokföringen för varje redovisningsperiod. Du granskar rutorna, lämnar deklarationen hos Skatteverket och bokför sedan momsen i NONOX, så att momskontona nollställs och perioden kan låsas.

Sidan finns under **Bokföring → Moms**.

![Momsdeklarationen för en månad, ruta för ruta, med länkar till kontona bakom varje ruta.](/bilder/manual/moms/momsdeklaration.webp)

## Så här gör du varje period

1. Bokför allt som hör till perioden: fakturor, leverantörsfakturor, kvitton och dagsrapporter. Stäm gärna av banken.
2. Gå till **Bokföring → Moms** och välj **Räkenskapsår**.
3. Klicka på perioden. En period som redan är bokförd har en bock.
4. Granska rutorna. Klicka på **Visa konton** vid en ruta för att se vilka konton och belopp den består av.
5. Klicka på **Ladda ner fil till Skatteverket** och lämna deklarationen hos Skatteverket. Vill du ha en kopia, klicka på **Skriv ut eller spara som PDF**.
6. När deklarationen är lämnad: klicka på **Bokför momsen** och sedan **Ja, bokför momsen**.
7. Klicka på **Lås till och med …** och **Ja, lås perioden**, så att inget kan bokföras i perioden i efterhand.

> [!IMPORTANT]
> Kontrollera alltid rutorna i Skatteverkets e-tjänst innan du skriver under. Det är du som lämnar deklarationen, inte NONOX.

## Rutorna

Tabellen visar **Ruta**, **Vad** och **Kronor**. Rutorna 05, 10, 11, 12 och 48 visas alltid; övriga bara när de har ett belopp. Sist står ruta **49**, **Moms att betala** eller **Moms att få tillbaka**.

Beloppen är i hela kronor – ören tas inte med, så som Skatteverket vill ha dem. Vilka konton som blir vilken ruta beskrivs i [Hur momsen hamnar i rätt ruta](/manual/moms/moms-och-konton).

Rutan räknas fram av de bokförda verifikationerna. Utkast räknas aldrig med.

### Gå från ruta till verifikation

**Visa konton** visar kontona bakom rutan. Vill du se de enskilda verifikationerna, öppna kontot i huvudboken under **Bokföring → Rapporter** för samma period. Läs [Kontoanalys och huvudbok](/manual/rapporter/kontoanalys-och-huvudbok).

## Filen till Skatteverket

**Ladda ner fil till Skatteverket** ger en fil med rutorna i det format Skatteverket tar emot för momsdeklarationer, så att du inte behöver skriva in beloppen för hand.

> [!NOTE]
> Filformatet och kopplingen mellan konton och rutor är ännu inte juridiskt kontrollerade i NONOX. Rutorna 06, 07, 08 och 37 har inga konton ännu och fylls aldrig i. Har företaget sådan försäljning eller sådana inköp, fyll i dem i Skatteverkets e-tjänst och fråga din redovisningskonsult.

## Det här händer i bokföringen

**Bokför momsen** gör en verifikation i serie M på periodens sista dag, med texten "Momsredovisning" och perioden:

- Varje momskonto som hör till en ruta (utgående moms i rutorna 10–12, 30–32 och 60–62 och ingående moms i ruta 48) nollställs för perioden: utgående moms i debet, ingående moms i kredit.
- Nettot förs till **2650 Redovisningskonto för moms** – i kredit när ni ska betala, i debet när ni ska få tillbaka.
- Ören som rutorna i hela kronor inte tar med bokförs på **3740 Öres- och kronutjämning**. Det blir alltid mindre än en krona per ruta.
- Vilande moms (2618, 2628, 2638 och 2648) och andra momskonton som inte hör till någon ruta lämnas orörda. Sidan visar dem under **Momskonton som inte ingår i deklarationen**. Läs [Vilande moms](/manual/moms/moms-och-konton).

Rutorna sparas tillsammans med verifikationen och kan inte ändras. Betalningen till eller från skattekontot bokför du som vanligt när den sker.

## Om något bokförs i perioden efteråt

Bokförs en verifikation i en period vars moms redan är bokförd, till exempel ett kvitto som kom in sent, ligger dess moms kvar på momskontot. Sidan visar då **Bokför tillägget till momsen**:

1. Kontrollera rutorna. De visar nu hela perioden, med det som bokförts efteråt.
2. Klicka på **Bokför tillägget** och sedan **Ja, bokför tillägget**. Det blir en ny verifikation i serie M med texten "Kompletterande momsredovisning", som bara för skillnaden till 2650 (och ören till 3740). Den dateras periodens sista dag, eller dagens datum om perioden har låsts sedan dess.
3. Lämna en rättad momsdeklaration för **hela** perioden hos Skatteverket, med rutorna på sidan. Ladda gärna ner filen igen. NONOX lämnar inte rättelsen åt dig.

De rättade rutorna sparas som en ny version. Den första versionen finns kvar och ändras aldrig.

> [!NOTE]
> Enligt granskning 2026-11-02, juristgranskning återstår (AUD-MOMS-5, #63).

Moms på försäljning till privatpersoner i andra EU-länder (OSS) ligger på 2619 och flyttas inte till 2650. Läs [OSS](/manual/moms/oss).

## Lås perioden

När momsen är deklarerad bör perioden låsas. Knappen **Lås till och med** låser hela bokföringen till och med periodens sista dag. Ett lås kan bara flyttas framåt, aldrig tillbaka. Läs [Lås en period](/manual/bokforing/las-en-period).

## Vem får göra vad?

- Se momsdeklarationen: **Ägare**, **Administratör** och **Redovisningskonsult**.
- Bokföra momsen: samma roller, som också får bokföra verifikationer.
- Låsa perioden: de som får låsa perioder – ägare, administratör och redovisningskonsult.

## Vanliga frågor

**Det står att något har bokförts i perioden efteråt.**
Momsen var redan bokförd när någon bokförde mer i perioden. Rutorna på sidan visar nu det nya läget och skiljer sig från det som bokfördes. Bokför tillägget enligt avsnittet **Om något bokförs i perioden efteråt** ovan, och rätta deklarationen hos Skatteverket. Lås perioden när du är klar, så händer det inte igen.

**Sidan säger att företaget inte är momsregistrerat.**
Ändra **Momsredovisning** under företagets uppgifter. Läs [Momsperiod och bokföringsmetod](/manual/moms/momsperiod-och-bokforingsmetod).

**Knappen Bokför momsen syns inte.**
Antingen är perioden redan bokförd, eller så finns det ingen moms att redovisa i perioden, eller så saknar du rätt att bokföra. Har något bokförts i perioden efteråt heter knappen **Bokför tillägget**.

**När ska deklarationen vara inne?**
Översikten visar momsen för den period som står på tur och sista dagen att deklarera och betala den. För helårsmoms beror dagen på bolagsform och EU-handel under året. Läs [Momsperiod och bokföringsmetod](/manual/moms/momsperiod-och-bokforingsmetod).

**Påverkar momsen bokslutet?**
Ja. Bokslutets checklista kräver att alla momsperioder som slutar i året är bokförda. Läs [Checklistan för bokslutet](/manual/bokslut/checklistan).

## Läs också

- [Hur momsen hamnar i rätt ruta](/manual/moms/moms-och-konton)
- [Periodisk sammanställning](/manual/moms/periodisk-sammanstallning)
- [Lås en period](/manual/bokforing/las-en-period)
