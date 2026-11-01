---
beskrivning: Registrera en kreditnota från en leverantör och kvitta den mot en obetald faktura, eller registrera pengarna när leverantören betalar tillbaka.
ordning: 70
roller: [owner, admin, accountant, bookkeeper]
sokord: [kreditnota, kreditfaktura, leverantörskredit, kvitta, kvittning, återbetalning, minusfaktura, retur]
---

# Kreditnotor från leverantörer

En kreditnota är leverantörens sätt att minska det du är skyldig, till exempel efter en retur, en prisjustering eller en felaktig faktura. I NONOX registrerar du den nästan som en vanlig faktura, och sedan antingen kvittar du den mot en faktura du inte har betalat eller registrerar pengarna när leverantören betalar tillbaka.

## Så här registrerar du en kreditnota

1. Gå till **Inköp → Leverantörsfakturor** och klicka på **Ny leverantörsfaktura**.
2. Ladda upp kreditnotan och välj **Leverantör**.
3. Kryssa i **Det här är en kreditnota**.
4. Skriv beloppen **som de står på kreditnotan**, utan minustecken: **Att betala, inkl. moms**, **Varav moms** och raderna utan moms.
5. Kontera raderna på samma konton som den ursprungliga fakturan, så att kostnaden minskar där den bokfördes.
6. Klicka på **Registrera fakturan** och **Ja, registrera**.

Kreditnotan ska sedan [attesteras](/manual/leverantorer/attest) precis som en faktura.

## Kvitta mot en faktura

Har du en attesterad, obetald faktura från samma leverantör kan kreditnotan dras av från den. Inga pengar flyttas.

1. Öppna den attesterade kreditnotan.
2. Under **Kvitta mot en faktura**, välj fakturan i listan.
3. Klicka på **Kvitta**.

Fakturans belopp att betala minskar med kreditnotans belopp, och kreditnotan blir reglerad. Resten av fakturan betalar du som vanligt.

## Registrera en återbetalning

Betalar leverantören tillbaka pengarna i stället:

1. Öppna den attesterade kreditnotan.
2. Under **Registrera återbetalning**, fyll i **Betaldag** och **Belopp**.
3. Klicka på **Registrera betalningen**.

Pengarna från leverantören bokförs på 1930 Företagskonto i serie E.

## Det här händer i bokföringen

En kreditnota bokförs med omvända belopp mot en faktura:

- **Vid registreringen** (fakturametoden), serie D: 2440 Leverantörsskulder i debet, och kostnadskontona och 2641 Ingående moms i kredit.
- **Kvittningen**, serie E: skulden på 2440 för fakturan och kreditnotan tar ut varandra. Ingen bankrad.
- **Återbetalningen**, serie E: 1930 Företagskonto i debet och 2440 Leverantörsskulder i kredit.

Enligt kontantmetoden bokförs kostnaden och momsen först när kreditnotan regleras, på samma sätt som för fakturor.

## Det här går inte

- En kreditnota kan inte tas med i en betalfil. Kvitta den eller registrera återbetalningen.
- Kreditnotor och fakturor i utländsk valuta kan inte kvittas mot varandra ännu. Registrera betalningarna var för sig.
- Kreditnotan och fakturan måste vara från samma leverantör, och båda måste vara attesterade och oreglerade.
- En kreditnota kan inte läggas in i anläggningsregistret.

## Vanliga frågor

**Listan under Kvitta mot en faktura är tom.**
Det finns ingen attesterad, obetald faktura från samma leverantör. Attestera fakturan först, eller registrera en återbetalning om leverantören betalar tillbaka.

**Kreditnotan gäller ett inköp från ett annat EU-land.**
Välj samma alternativ under **Vem redovisar momsen?** som på den ursprungliga fakturan. Momsen bokförs då tillbaka på samma konton och rutor.

## Läs också

- [Registrera en leverantörsfaktura](/manual/leverantorer/registrera-en-leverantorsfaktura)
- [Attest](/manual/leverantorer/attest)
- [Registrera en betalning](/manual/leverantorer/registrera-en-betalning)
