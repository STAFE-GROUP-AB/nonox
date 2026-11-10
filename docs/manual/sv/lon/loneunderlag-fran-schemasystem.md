---
beskrivning: Läs in timmar, OB och tillägg från ert schema- eller tidsystem som en PAXml- eller CSV-fil, koppla koderna till lönearter och godkänn raderna.
ordning: 70
roller: [owner, payroll_admin]
modul: payroll
rutter: [payroll.runs.imports.show]
sokord: [PAXml, lönefil import, löneunderlag, schemasystem, tidsystem, CSV lön, importera timmar, OB-fil, mall löneunderlag, lönedata från annat program]
---

# Löneunderlag från schemasystem (PAXml och CSV)

Många restauranger, caféer och butiker räknar timmar och OB i ett schema- eller tidsystem. I stället för att skriva av siffrorna läser du in systemets lönefil i lönekörningen. Raderna blir **förslag** som du granskar och godkänner; inget kommer med i lönen förrän en person har godkänt det.

NONOX läser:

- **PAXml**, den svenska standarden för löneunderlag, i versionerna 1.0 och 2.0–2.3.
- **CSV**, semikolonseparerad med rubrikraden `anstallningsnummer;personnummer;loneart;benamning;datum;datum_tom;antal;apris;belopp`. `loneart` och antingen anställningsnummer eller personnummer måste finnas. Decimalkomma eller decimalpunkt går bra. Kommer timmarna från ett annat lön- eller tidprogram som inte kan göra PAXml, klicka på **Hämta mall för CSV** bredvid filfältet i lönekörningen och klistra in programmets export under rubrikraden.

Filen får vara högst 10 MB.

![Ett inläst löneunderlag från ett schemasystem i lönekörningen. Beloppen är demodata.](/bilder/manual/lon/loneunderlag-fran-schemasystem.webp)

## Så här läser du in en fil

1. Exportera lönefilen för månaden från ert schemasystem.
2. Öppna månadens lönekörning i NONOX. Den måste vara öppen, inte attesterad.
3. Under **Löneunderlag från schemasystem**: välj **Källsystem**. Första gången väljer du **Nytt system…** och skriver systemets namn.
4. Välj filen under **Fil (PAXml eller CSV, högst 10 MB)** och klicka på **Läs in filen**.
5. Du kommer till filens sida. Följ stegen nedan.

## Så här kopplar du koderna första gången

Varje schemasystem har sina egna koder för timlön, OB och tillägg. Första gången en kod dyker upp ser du rutan **Koppla koder till lönearter**.

1. Välj för varje kod vilken löneart den ska bli, till exempel **Timlön**, **OB-tillägg** eller **Övertid**.
2. Ska raderna med en kod inte med i lönen, välj **Läs inte in raderna med den här koden**.

Kopplingen sparas för källsystemet och används automatiskt för nästa fil från samma system. Du ändrar den senare under **Företagsinställningar → Moduler → Lön**, i rutan **Löneunderlag från schemasystem**.

## Så här granskar och godkänner du raderna

Överst ser du hur många rader filen har och hur många som är att godkänna, har anmärkning, är godkända eller inte läses in. Välj vy: **Att godkänna**, **Med anmärkning**, **Godkända**, **Läses inte in** eller **Alla**.

1. Gå igenom raderna under **Att godkänna**: anställd, kod, datum, antal, à-pris och belopp.
2. Godkänn rad för rad med **Godkänn**, eller alla på en gång med **Godkänn N rader utan anmärkning** och **Ja, godkänn**.
3. Avvisa en rad som är fel med **Avvisa**. En godkänd rad kan du ångra med **Ångra**.
4. Gå tillbaka till lönekörningen och klicka på **Beräkna**.

Varje godkänd rad läggs in i lönekörningen under **Timmar och tillägg**. Tar du bort en sådan rad i lönekörningen blir den ett förslag igen på filens sida.

## Anställda som inte hittades

NONOX hittar den anställda på anställningsnumret, och annars på personnumret. Rader för personer som inte hittas kan inte godkännas och visas under **Anställda som inte hittades**. Lägg till personen eller rätta anställningsnumret under **Lön → Anställda**, och klicka sedan på **Läs in filen igen**.

**Läs in filen igen** läser den sparade filen med dagens kopplingar och anställda. Godkända rader behålls; alla andra rader från filen ersätts.

## Det här läses aldrig in från en fil

För att lönen ska bli rätt och känsliga uppgifter skyddas läser NONOX inte allt i filen:

- **Frånvaro** (sjukdom, vård av barn, semester, tjänstledighet och liknande). Frånvaro registreras i lönekörningen, där sjuklön och avdrag räknas. Se [Frånvaro, sjuklön och karensavdrag](/manual/lon/franvaro-och-sjuklon).
- **Schematid och saldon** (flex, komp och liknande).
- **Personuppgifter, bankkonton, scheman och resor** som filen kan innehålla. Sidan visar vad som lämnades oläst.
- **Negativa värden.** Rättelser skriver du in för hand.

En rad med timmar men utan à-pris och belopp kan bara vara timlön. OB eller övertid utan pris stoppas, eftersom de annars skulle betalas med hela timlönen. Undantaget är när modulen Avtal är på och raden är kopplad till en av avtalets lönearter; då prissätts den med avtalets sats för radens datum. Se [OB och övertid enligt avtalet](/manual/avtal/ob-och-overtid).

## Säkerhet

- Originalfilen sparas krypterad. Bara den som har behörighet att se känsliga löneuppgifter kan klicka på **Hämta originalfilen**, och varje hämtning loggas.
- Personnummer används bara för att hitta rätt anställd och sparas aldrig på raderna.
- Samma fil kan bara läsas in en gång per månad.

## Vanliga frågor

**Vi vill inte ha filen kvar. Kan vi ta bort den?**
Ja, så länge lönekörningen är öppen: klicka på **Ta bort filen** och **Ja, ta bort**. Filen tas bort med alla sina rader, även de godkända raderna i lönekörningen.

**Filen säger en PAXml-version vi inte känner igen.**
NONOX läser den på samma sätt som version 2.3 och visar en upplysning. Kontrollera raderna extra noga.

**Kan vi skicka filen automatiskt varje månad?**
Inte ännu. Filen läses in för hand i varje lönekörning.

## Läs också

- [Gör en lönekörning](/manual/lon/lonekorning)
- [Timlön och tidrapporter](/manual/lon/timlon-och-tidrapporter)
- [OB och övertid enligt avtalet](/manual/avtal/ob-och-overtid)
