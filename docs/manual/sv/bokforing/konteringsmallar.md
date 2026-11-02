---
beskrivning: Skriv beloppet inklusive moms och välj en mall, så fyller NONOX i raderna och räknar ut momsen åt dig.
ordning: 30
roller: [owner, admin, accountant, bookkeeper]
sokord: [mall, konteringsmall, snabbkontering, inköp med moms, försäljning med moms, bankavgift, momsuppdelning]
---

# Konteringsmallar

De vanligaste verifikationerna ser nästan alltid likadana ut: ett inköp med 25 % moms, en kontantförsäljning, en bankavgift. Med en konteringsmall skriver du bara beloppet du betalade eller fick, inklusive moms, så fyller NONOX i raderna och räknar ut momsen.

![Mallarna öppnar du under Snabbare: använd en mall, ovanför raderna.](/bilder/manual/bokforing/bokfor-en-verifikation.webp)

## Så här gör du

1. Gå till **Bokföring → Verifikationer** och välj **Ny verifikation**.
2. Öppna **Snabbare: använd en mall**.
3. Välj vad det gäller under **Mall**.
4. Skriv beloppet under **Belopp inkl. moms**, till exempel `1 250,00`.
5. Välj **Fyll i rader**.
6. Gäller det ett inköp: välj kostnadskontot på den första raden, som är tom.
7. Kontrollera raderna och datumet, lägg till underlaget och bokför som vanligt.

Har du inte skrivit någon text i **Vad gäller det?** får verifikationen mallens namn som text. Byt gärna till något mer precist, till exempel vad du köpte och av vem.

> [!NOTE]
> Mallen ersätter alla rader som redan står i verifikationen. Välj mall först och fyll i resten sedan.

## Mallarna och raderna de ger

Alla mallar utgår från att pengarna gick in på eller ut från företagskontot, 1930.

| Mall | Debet | Kredit |
|---|---|---|
| **Inköp med 25 % moms** | Kostnadskontot du väljer (beloppet utan moms) och 2641 (momsen) | 1930 (hela beloppet) |
| **Inköp med 12 % moms** | Kostnadskontot du väljer och 2641 | 1930 |
| **Inköp med 6 % moms** | Kostnadskontot du väljer och 2641 | 1930 |
| **Inköp utan moms** | Kostnadskontot du väljer (hela beloppet) | 1930 |
| **Försäljning med 25 % moms** | 1930 (hela beloppet) | 3001 (beloppet utan moms) och 2611 (momsen) |
| **Försäljning med 12 % moms** | 1930 | 3002 och 2621 |
| **Försäljning med 6 % moms** | 1930 | 3003 och 2631 |
| **Bankavgift** | 6570 | 1930 |

Momsen räknas ut ur beloppet inklusive moms och avrundas till hela ören. Ett inköp på 1 250 kr med 25 % moms blir 1 000 kr kostnad och 250 kr moms.

## Exempel

Exempelbolaget AB betalar en reparation på 1 250 kr inklusive 25 % moms med företagskortet. Välj **Inköp med 25 % moms**, skriv `1250` och välj **Fyll i rader**. Raderna blir:

| Konto | Debet | Kredit |
|---|---:|---:|
| *(välj kostnadskonto)* | 1 000,00 | |
| 2641 Debiterad ingående moms | 250,00 | |
| 1930 Företagskonto/checkkonto/affärskonto | | 1 250,00 |

Skriv till exempel "5" eller "reparation" i det tomma kontofältet och välj kontot som passar.

## Bra att veta

- Gick betalningen via ett annat konto än 1930, till exempel kassan, byter du bara kontot på den raden efteråt.
- Är det en obetald leverantörsfaktura, registrera den hellre under **Inköp → Leverantörsfakturor**. Då får den förfallodag och betalning. Se [Registrera en leverantörsfaktura](/manual/leverantorer/registrera-en-leverantorsfaktura).
- Fakturerar du kunder med NONOX bokförs försäljningen när fakturan skapas. Försäljningsmallarna är till för försäljning som inte går via en faktura, till exempel kontant eller med kort i kassan.
- Mallarna är fasta; det går inte att spara egna mallar. Behöver du bokföra samma sak igen kan du kopiera en gammal verifikation, se [Hitta och läsa verifikationer](/manual/bokforing/verifikationer).

## Läs också

- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
- [Underlag](/manual/bokforing/underlag)
- [Momsdeklarationen](/manual/moms/momsdeklaration)
