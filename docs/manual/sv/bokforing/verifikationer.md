---
beskrivning: Hitta en verifikation på nummer eller text, läs raderna med kontosaldo, och kopiera en gammal verifikation till en ny.
ordning: 60
rutter: [accounting.vouchers.index, accounting.vouchers.show]
sokord: [verifikationslista, sök verifikation, kopiera verifikation, grundbok, verifikationsnummer, A12]
---

# Hitta och läsa verifikationer

Under **Bokföring → Verifikationer** finns allt som har bokförts i företaget, med den senaste först. Härifrån söker du upp en verifikation, läser den rad för rad med underlaget bredvid och kopierar den när samma sak ska bokföras igen.

![Verifikationerna för ett café, med årets resultat, pengar på bank och kassa och kontrollen av bokföringen överst.](/bilder/manual/bokforing/verifikationer.webp)

## Listan

Överst på sidan visas några nyckeltal för den som får se rapporterna: **Resultat hittills** i år (intäkter minus kostnader och skatt, även efter att årets resultat bokats i bokslutet), **Pengar på bank och kassa** och momsen på momskontona. Under dem står resultatet av den senaste [kontrollen av bokföringen](/manual/bokforing/kontroll-av-bokforingen).

I listan ser du för varje verifikation:

- **Ver** – nummer med serie, till exempel A12, eller märket **Utkast**.
- **Datum** – verifikationens datum.
- **Beskrivning** – texten, och märket **Rättad** om den har rättats.
- **Belopp** – summan av debet.

Utkasten ligger alltid överst, därefter de bokförda med det senaste datumet först. Listan visar 50 verifikationer per sida.

## Så här hittar du en verifikation

1. Välj räkenskapsår i listan till höger. Från början visas det år som pågår.
2. Skriv i sökfältet. Du kan söka på text ur beskrivningen, till exempel "hyra", eller på nummer: `A12` eller bara `12`.
3. Vill du bara se bokförda verifikationer eller bara utkast väljer du **Bokförda** eller **Utkast** i stället för **Alla**.

Dina val sparas i adressen, så du kan bokmärka en sökning eller skicka länken till en kollega som också har tillgång till företaget.

> [!TIP]
> Du hittar också verifikationer med sökningen högst upp i appen, och från rapporterna: varje verifikationsnummer i [huvudboken och kontoanalysen](/manual/rapporter/kontoanalys-och-huvudbok) leder hit.

## En verifikation i detalj

Öppna en verifikation genom att välja numret eller texten. Sidan visar:

- **Datum**, **Serie** och **Status** – för en bokförd verifikation vem som bokförde den och när.
- Raderna med **Konto**, **Kostnadsställe** och **Projekt** (när de används), **Radtext**, **Debet**, **Kredit** och **Kontosaldo** – kontots saldo efter raden.
- **Kontrollsumma** längst ned, för en bokförd verifikation.
- Underlaget bredvid raderna, med förhandsvisning (se [Underlag](/manual/bokforing/underlag)).

Varje konto på raderna är en länk till kontots huvudbok för räkenskapsåret, så du ser vad mer som hänt på kontot.

Är verifikationen rättad står det **Den här verifikationen är rättad med** följt av rättelsens nummer. På rättelsen står **Det här är en rättelse av** och originalets nummer. Skapades en anläggningstillgång när verifikationen bokfördes listas den också här.

## Så här kopierar du en verifikation

När samma sak ska bokföras igen – hyran, ett abonnemang, en återkommande överföring – behöver du inte skriva om raderna.

1. Öppna verifikationen du vill utgå från.
2. Välj **Kopiera till ny verifikation**.
3. En ny verifikation öppnas med samma rader och serie, dagens datum och texten "Kopia av A12: …". Ändra datum, text och belopp.
4. Lägg till det nya underlaget och bokför.

Underlaget följer inte med i kopian, eftersom det hör till den gamla affären.

## Bra att veta

- Vad du kan göra med en verifikation beror på dess status. Ett utkast kan du fortsätta med eller ta bort (se [Utkast](/manual/bokforing/utkast)). En bokförd verifikation kan du bara [rätta](/manual/bokforing/ratta-en-verifikation) eller kopiera.
- Revisorer och attestanter kan läsa verifikationerna men inte lägga in eller ändra något.
- Vill du se verifikationerna för en viss period samlade, med kontosaldo per rad, använder du [Verifikationslistan](/manual/rapporter/verifikationslista) under **Rapporter**.

## Läs också

- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
- [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation)
- [Kontoanalys och huvudbok](/manual/rapporter/kontoanalys-och-huvudbok)
- [Verifikationslistan](/manual/rapporter/verifikationslista)
