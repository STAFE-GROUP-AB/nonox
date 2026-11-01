---
beskrivning: Skapa ett arkivpaket per räkenskapsår med SIE-fil, rapporter, underlag, fakturor, moms, löner och behandlingshistorik – läsbart utan NONOX och kontrollerbart med kontrollsummor.
ordning: 110
roller: [owner, admin, accountant, auditor]
rutter: [accounting.fiscalYears.archive]
sokord: [arkivpaket, arkivera, arkivering, spara bokföringen, sju år, räkenskapsinformation, export, backup, zip, kontrollsumma, sha-256]
---

# Arkivpaketet

Bokföringslagen kräver att räkenskapsinformationen sparas i sju år efter utgången av det kalenderår då räkenskapsåret avslutades. Arkivpaketet är en ZIP-fil med allt som räknas som räkenskapsinformation för ett år, i format som går att läsa utan NONOX. Ladda ner det efter bokslutet och spara det på ett säkert ställe.

Arkivpaket skapas under **Bokföring → Räkenskapsår**.

## Så här skapar du ett arkivpaket

1. Gå till **Bokföring → Räkenskapsår**.
2. Klicka på **Skapa arkivpaket** vid året.
3. Paketet byggs i bakgrunden. Sidan visar **Arkivpaketet skapas…** och uppdateras själv när det är klart. Ett stort år kan ta några minuter.
4. Klicka på **Ladda ner arkivpaket**. Storleken står i knappen.

Har du bokfört något i året efteråt, eller vill du ha ett nytt paket efter bokslutet, klicka på **Skapa nytt arkivpaket**. Tidigare paket finns kvar. Ett år kan bara ha ett paket under arbete åt gången.

## Det här finns i paketet

Paketet innehåller en mapp med företagets organisationsnummer och årets datum i namnet:

| Mapp | Innehåll |
|---|---|
| `sie/` | Årets SIE4-fil, samma som SIE-exporten. |
| `rapporter/` | Saldobalans, resultatrapport, balansrapport, huvudbok och verifikationslista för hela året, som CSV och PDF. Långa rapporter delas i flera PDF-filer. |
| `verifikationer/` | Underlagen till varje bokförd verifikation, under sina ursprungliga namn. |
| `kundfakturor/` | Utfärdade kund- och kreditfakturor med fakturadatum under året, som PDF. |
| `leverantorsfakturor/` | Leverantörsfakturorna med fakturadatum under året, som de laddades upp. |
| `moms/` | Rutor och belopp för varje redovisad momsperiod och filen till Skatteverket. |
| `lon/` | Lönebesked för attesterade lönekörningar med utbetalning under året, lönebesked från ett tidigare lönesystem och arbetsgivardeklarationerna. |
| `behandlingshistorik/` | Allt som gjorts i företaget från årets första dag till dess paketet skapades, som CSV och JSON. |
| `systemdokumentation/` | NONOX systemdokumentation i den version som gällde när paketet skapades. |

Dessutom:

- `LASMIG.txt` som förklarar paketet på svenska,
- `manifest.json` med storlek och kontrollsumma (SHA-256) för varje fil,
- `SHA256SUMS.txt` som går att kontrollera med vanliga verktyg.

## Kontrollera att paketet är helt

Varje fil har en kontrollsumma. Har en enda byte ändrats stämmer den inte längre. På en Mac eller i Linux kan du kontrollera alla filer i den uppackade mappen med `shasum -a 256 -c SHA256SUMS.txt` eller `sha256sum -c SHA256SUMS.txt`. Paketets egen kontrollsumma sparas i NONOX behandlingshistorik när paketet skapas, och varje nedladdning loggas.

## Hur länge och var?

- Spara paketet i minst sju år efter utgången av det kalenderår då räkenskapsåret avslutades.
- Spara det på ett ställe du kontrollerar, gärna på två ställen.
- Paketet ersätter inte att räkenskapsinformationen finns kvar i NONOX. Flyttar du från NONOX eller arkiverar företaget, se till att du har paketen för alla år som omfattas.

> [!NOTE]
> Om paketet räcker som arkiv för alla slag av räkenskapsinformation – till exempel avtal och andra handlingar som inte finns i NONOX – är inte juridiskt kontrollerat. Spara sådant som du har utanför NONOX på samma sätt.

## Vem får skapa arkivpaket?

**Ägare**, **Administratör**, **Redovisningskonsult** och **Revisor**.

## Vanliga frågor

**Kan jag läsa paketet utan NONOX?**
Ja. Rapporterna är PDF och CSV, fakturorna PDF, underlagen i sina ursprungliga format och SIE-filen går att läsa in i alla svenska bokföringsprogram.

**Skapandet misslyckades.**
Ett felmeddelande visas vid året. Försök igen med **Skapa arkivpaket**. Kvarstår felet, kontakta den som driftar er NONOX.

## Läs också

- [SIE-filer](/manual/bokforing/sie)
- [Stäng räkenskapsåret](/manual/bokslut/stang-aret)
- [Nytt räkenskapsår och ingående balanser](/manual/bokslut/nytt-rakenskapsar)
