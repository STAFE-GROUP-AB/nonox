---
beskrivning: Vilka filer från och till banken NONOX hanterar – inbetalningar (camt.054) in, betalfiler (pain.001) ut och bankens svar på betalfilerna (pain.002, camt.054, camt.053) – och vad som inte går än.
ordning: 40
roller: [owner, admin, accountant, bookkeeper]
sokord: [bankfil, camt.054, camt.053, pain.001, pain.002, statusrapport, BgMax, LB, ISO 20022, XML, kontoutdrag, importera bankfil]
---

# Bankfiler

Bankerna i Sverige byter till filer enligt standarden ISO 20022, ett XML-format som ser likadant ut i alla banker. NONOX hanterar flera sådana filer: de som du **läser in** från banken – kundernas inbetalningar och bankens svar på dina betalfiler – och den som du **skapar** i NONOX och laddar upp i banken för att betala leverantörer.

![Läs in bankens fil med inbetalningar.](/bilder/manual/fakturering/inbetalningar.webp)

## Filer du läser in

### Inbetalningar (camt.054)

Bankens avisering av inbetalningar, *BankToCustomerDebitCreditNotification* eller camt.054, innehåller de betalningar som har kommit in på kontot, med OCR-nummer, meddelande och betalarens namn.

Läs in den under **Försäljning → Inbetalningar**. NONOX föreslår fakturan för varje inbetalning på OCR-numret, och du bokför de som stämmer. Läs [Inbetalningar](/manual/fakturering/inbetalningar).

- Bara insättningar som banken har bokfört läses. Uttag i filen hoppas över.
- En betalning som redan har lästs in från en tidigare fil, eller som har kommit via bankkopplingen, läses aldrig in igen.
- Filen sparas som den kom från banken, med en kontrollsumma.

### Bankens svar på betalfiler (pain.002, camt.054, camt.053)

När du har skickat en betalfil svarar banken med en statusrapport (pain.002) om vilka betalningar den tog emot eller avvisade, och med en debiteringsavisering (camt.054) eller ett kontoutdrag (camt.053) när pengarna har dragits.

Läs in dem under **Inköp → Betalfiler**. NONOX hittar varje betalning på referensen i betalfilen, bokför det som är betalt och visar det som avvisades med bankens orsak. Läs [Bankens svar på betalfilen](/manual/leverantorer/bankens-svar).

- Ur ett kontoutdrag tas bara de rörelser som hör till dina betalfiler. Kortköp och andra uttag lämnas.
- Samma fil kan inte läsas in två gånger, och samma betalning bokförs bara en gång.

## Filer du skapar

### Betalfiler till leverantörer (pain.001)

För att betala leverantörsfakturor skapar NONOX en betalfil i formatet pain.001 som du laddar upp i internetbanken och signerar där. Läs [Betalfiler](/manual/leverantorer/betalfiler).

## Det som inte går än

- **Kontoutdrag (camt.053)** läses bara för bankens svar på betalfiler, inte för bankavstämningen. I bankavstämningen skriver du saldot från kontoutdraget, eller använder bankens saldo om banken är kopplad. Läs [Bankavstämning](/manual/bank/bankavstamning).
- **BgMax** för inbetalningar och **LB** för leverantörsbetalningar – Bankgirots äldre format – läses och skapas inte. Bankerna ersätter dem med camt.054 och pain.001.

> [!TIP]
> Vill du slippa hämta filer över huvud taget? Med modulen Bank hämtar NONOX transaktioner och saldon direkt från banken, om den som driftar NONOX har ett avtal med en leverantör av öppen bankdata. På nonox.app erbjuds modulen inte än. Läs [Bankkoppling](/manual/bank/bankkoppling).

## Vanliga frågor

**Var hittar jag camt.054 i min internetbank?**
Det skiljer sig mellan bankerna, och tjänsten kan behöva beställas. Leta efter filer för inbetalningar eller bankgiroinbetalningar i ISO 20022- eller XML-format, eller fråga banken.

**Kan jag läsa in en CSV-fil eller en PDF från banken?**
Nej. Bankens transaktioner läses in som camt.054 eller hämtas med bankkopplingen.

## Läs också

- [Inbetalningar](/manual/fakturering/inbetalningar)
- [Betalfiler](/manual/leverantorer/betalfiler)
- [Bankkoppling](/manual/bank/bankkoppling)
- [Bankavstämning](/manual/bank/bankavstamning)
