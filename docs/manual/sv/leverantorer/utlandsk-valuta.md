---
beskrivning: Registrera en leverantörsfaktura i euro, dollar eller annan valuta med Riksbankens kurs, och bokför kursvinst eller kursförlust när den betalas.
ordning: 90
roller: [owner, admin, accountant, bookkeeper]
sokord: [valuta, euro, eur, usd, dollar, växelkurs, valutakurs, kursdifferens, kursvinst, kursförlust, 3960, 7960, riksbanken]
---

# Fakturor i utländsk valuta

Får du en faktura i euro, dollar eller en annan valuta registrerar du den i den valutan. NONOX räknar om den till kronor med Riksbankens kurs och bokför den i kronor. När du sedan betalar skriver du hur många kronor banken faktiskt drog, och skillnaden mot den bokförda kursen bokförs som kursvinst eller kursförlust.

![En faktura i euro med kurs, betalning och kursdifferens.](/bilder/manual/leverantorer/utlandsk-valuta.webp)

## Så här registrerar du fakturan

1. Registrera fakturan som vanligt: **Inköp → Leverantörsfakturor → Ny leverantörsfaktura**.
2. Fyll i **Fakturadatum** först.
3. I **Valuta**, skriv eller välj valutans tre bokstäver, till exempel EUR eller USD.
4. Fyll i **Att betala i EUR, inkl. moms** – beloppet som står på fakturan.
5. **Kurs, kronor per EUR** fylls i med Riksbankens kurs för fakturadatumet. Under fältet står vilket datum kursen gäller. Du kan ändra den.
6. **Att betala i kronor, inkl. moms** räknas fram. Kontera raderna i kronor, utan moms, som vanligt.
7. Registrera fakturan.

Beloppet i kronor måste vara valutabeloppet gånger kursen, med högst en krona i avvikelse.

## Så här registrerar du betalningen

Har du Handelsbanken eller Nordea kan en faktura i utländsk valuta tas med i en betalfil, en valuta per fil. Läs [Betalfiler](/manual/leverantorer/betalfiler). Hos övriga banker betalar du den i internetbanken och registrerar betalningen på fakturan.

1. Öppna den attesterade fakturan.
2. Fyll i **Betaldag**.
3. Fyll i **Belopp i EUR** – det du betalar av fakturan. Det som återstår är förifyllt.
4. **Kronor som banken drog** är förifyllt med Riksbankens kurs på betaldagen. Ändra det till beloppet på kontoutdraget.
5. Läs raden under fälten. Den säger hur mycket skulden minskar och om det blir en kursvinst eller kursförlust.
6. Klicka på **Registrera betalningen**.

Betalar du i flera omgångar minskar valutabeloppet för varje betalning, och det som återstår i valutan visas bredvid kronorna.

## Det här händer i bokföringen

**Fakturan** bokförs i kronor till kursen på fakturan, precis som en faktura i kronor.

**Betalningen** blir en verifikation i serie E:

| Konto | Debet | Kredit |
|---|---|---|
| 2440 Leverantörsskulder | den bokförda andelen | |
| 1930 Företagskonto | | kronorna banken drog |
| 7960 Valutakursförluster … | om du betalade mer | |
| 3960 Valutakursvinster … | | om du betalade mindre |

Med samma kurs blir det ingen differensrad. Den sista betalningen tar med allt som återstår, så att 2440 går jämnt ut. Saknas 3960 eller 7960 i kontoplanen läggs kontot till med sitt BAS-namn.

Enligt kontantmetoden bokförs kostnaden och momsen vid betalningen till fakturans kurs, och skillnaden på samma sätt.

## Det här går inte än

- Fakturor i utländsk valuta kan bara tas med i betalfiler hos Handelsbanken och Nordea, och bara om leverantörens uppgifter är kompletta. Hos övriga banker betalar du i internetbanken.
- Kreditnotor och fakturor i utländsk valuta kan inte kvittas mot varandra.

> [!NOTE]
> Att Riksbankens mittkurs på fakturadatumet är rätt kurs att bokföra till, och att kursdifferenser hör hemma på 3960 och 7960, är något NONOX ännu inte har fått juridiskt kontrollerat. Kontrollera med din redovisningskonsult, särskilt för momsens beskattningsunderlag.

## Vanliga frågor

**Kursen fylls inte i.**
Hittar NONOX ingen kurs hos Riksbanken för valutan, eller går Riksbanken inte att nå just då, lämnas fältet tomt. Skriv kursen själv, till exempel från bankens avräkning.

**Kan jag fakturera kunder i utländsk valuta?**
Ja. Läs [Faktura i utländsk valuta](/manual/fakturering/faktura-i-utlandsk-valuta).

**Vad händer med en obetald faktura vid bokslutet?**
Obetalda fakturor i utländsk valuta värderas till kursen den sista dagen i räkenskapsåret. Läs [Skatt och årets resultat](/manual/bokslut/bokslutsverifikationer).

## Läs också

- [Registrera en betalning](/manual/leverantorer/registrera-en-betalning)
- [Inköp från utlandet och omvänd betalningsskyldighet](/manual/leverantorer/inkop-fran-utlandet)
- [Kontoanalys och huvudbok](/manual/rapporter/kontoanalys-och-huvudbok)
