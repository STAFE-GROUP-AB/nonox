---
beskrivning: Bokför momsen på varor du importerar från länder utanför EU, från Tullverkets tullräkning, så att rutorna 50, 60–62 och 48 i momsdeklarationen blir rätt.
ordning: 45
roller: [owner, admin, accountant, bookkeeper]
sokord: [import, importmoms, tullräkning, tullkvitto, tullverket, tullvärde, tull, varor utanför eu, kina, usa, storbritannien, norge, ruta 50, ruta 60, ruta 61, ruta 62, 2615, 2645, 4085, 4545, beskattningsunderlag vid import]
---

# Importmoms från tullräkning

Köper du varor från ett land utanför EU, till exempel Kina, USA, Storbritannien eller Norge, tar leverantören inte ut svensk moms. Är företaget momsregistrerat redovisar du i stället momsen på importen själv i momsdeklarationen, och drar av den som ingående moms i samma deklaration. Underlaget är inte leverantörens faktura utan Tullverkets tullräkning (eller tullkvitto).

![Formuläret Importmoms från tullräkning under Ny verifikation.](/bilder/manual/moms/importmoms.webp)

En import blir alltså två saker i NONOX:

1. **Leverantörens faktura**, som en vanlig leverantörsfaktura med **Varor från ett land utanför EU (import)**. Den bokför varornas kostnad och skulden till leverantören, utan moms.
2. **Tullräkningen**, som en verifikation från formuläret **Importmoms från tullräkning**. Den bokför momsen på importen.

Tullen och speditörens avgift är kostnader, inte moms. Dem bokför du från sina egna fakturor, till exempel på 5721 Tullkostnader och 5722 Speditionskostnader.

## Så här registrerar du leverantörens faktura

1. Gå till **Inköp → Leverantörsfakturor → Ny leverantörsfaktura**.
2. Under **Vem redovisar momsen?**, välj **Varor från ett land utanför EU (import)**. Fältet **Varav moms** försvinner, eftersom fakturan inte har någon svensk moms.
3. Är fakturan i en annan valuta, välj valuta och kurs som vanligt. Läs [Fakturor i utländsk valuta](/manual/leverantorer/utlandsk-valuta).
4. Kontera varorna på ett kostnadskonto, till exempel 4080 Import av handelsvaror eller 4010 Inköp av handelsvaror i Sverige, och registrera fakturan.

> [!WARNING]
> Kontera aldrig leverantörens faktura på 4085–4087 eller 4545–4547. De kontona är bara för verifikationen från tullräkningen och blir ruta 50, så varorna skulle räknas två gånger. NONOX säger till om du försöker.

## Så här bokför du tullräkningen

1. Gå till **Bokföring → Verifikationer** och välj **Ny verifikation**.
2. Öppna **Importmoms från tullräkning**.
3. Fyll i **Tullräkningens datum**. Momsen hör till den redovisningsperiod då Tullverket ställde ut tullräkningen, så verifikationen får det datumet.
4. Fyll i **Tullräkningens nummer (frivilligt)** och välj under **Vad är varorna?** om det är **Handelsvaror** (varor du säljer vidare) eller **Råvaror och material**.
5. Fyll i en rad per momssats, högst tre: **Momssats**, **Tullvärde**, **Tull och avgifter** och **Bikostnader i Sverige**. Välj **Lägg till momssats** om varorna har olika momssatser.
6. Välj **Fyll i rader för importmoms**. Datum, beskrivning och rader fylls i under **Rader**.
7. Kontrollera raderna, bifoga tullräkningen som underlag och bokför verifikationen som vanligt.

> [!IMPORTANT]
> Momssatsen vid import är samma som när varan säljs i Sverige. Har du flera varor med olika satser på samma tullräkning, dela upp beloppen per sats.

## Vad som räknas in i underlaget

Beskattningsunderlaget är summan av:

- **Tullvärdet** som Tullverket har fastställt. Det står på tullräkningen, i kronor. Frakt och försäkring fram till gränsen till EU ingår redan i det.
- **Tull och avgifter** som Tullverket tar ut vid importen, men inte momsen.
- **Bikostnader** fram till första bestämmelseorten i Sverige, till exempel frakt, försäkring och emballage, om de inte redan ingår i tullvärdet. Står en senare ort i Sverige eller i ett annat EU-land på fraktsedeln, räknas bikostnaderna dit.

Exempel från Skatteverket: Tullverket fastställer tullvärdet till 105 000 kronor och tullen är 12 600 kronor. Underlaget blir 117 600 kronor och momsen 25 % blir 29 400 kronor.

Är fakturan i dollar använder du ändå kronorna på tullräkningen. Det är tullvärdet som räknas, inte fakturans belopp.

## Det här händer i bokföringen

Verifikationen för tullräkningen 105 000 + 12 600 kronor:

| Konto | Debet | Kredit | Ruta |
|---|---:|---:|---|
| 4085 Import av handelsvaror, 25 % moms | 117 600 | | 50 |
| 2615 Utgående moms import av varor, 25 % | | 29 400 | 60 |
| 4080 Import av handelsvaror | | 117 600 | |
| 2645 Beräknad ingående moms på förvärv från utlandet | 29 400 | | 48 |

- **Underlaget** hamnar på det BAS-konto som hör till momssatsen: 4085, 4086 och 4087 för handelsvaror, 4545, 4546 och 4547 för råvaror och material. Det är de kontona som blir ruta 50.
- Samma belopp förs bort från 4080 Import av handelsvaror (eller 4540 Import av råvaror och material), så att verifikationen inte påverkar resultatet. Varornas kostnad kommer från leverantörens faktura.
- **Utgående moms** bokförs på 2615 (25 %), 2625 (12 %) eller 2635 (6 %), rutorna 60, 61 och 62.
- **Ingående moms** bokförs på 2645, ruta 48. Med full avdragsrätt kostar importmomsen alltså inget.

Saknas något av kontona i kontoplanen läggs det till med sitt BAS-namn.

Momsen redovisas i perioden för tullräkningens datum, både med fakturametoden och med kontantmetoden. Det spelar ingen roll när du betalar leverantören.

## Ändrat beslut från Tullverket

Om Tullverket ändrar sitt beslut så att underlaget blir lägre, gör så här: kryssa i **Ändrat beslut som sänker underlaget**, fyll i **Datum för Tullverkets ändrade beslut** och hur mycket underlaget minskar. Raderna bokförs då åt andra hållet, med beslutets datum. Blir underlaget högre fyller du i ökningen som en ny tullräkning.

> [!NOTE]
> Vilken redovisningsperiod ett ändrat beslut hör till, beslutets eller den ursprungliga tullräkningens, är inte kontrollerat ännu. Fråga din redovisningskonsult eller Skatteverket innan du bokför ett ändrat beslut i en senare period.

## Det här går inte än

- **Inte full avdragsrätt.** NONOX utgår från att hela momsen får dras av. Har företaget verksamhet utan avdragsrätt ändrar du raden på 2645 innan du bokför, och frågar din redovisningskonsult.
- **Import som är undantagen från moms.** Är importen undantagen från moms ska ingen importmoms redovisas, och formuläret ska inte användas. Fråga din redovisningskonsult eller läs Skatteverkets vägledning om du är osäker.
- **Importen om du inte är momsregistrerad.** Då betalar du momsen till Tullverket i stället, och den ska inte med i momsdeklarationen.
- **Centraliserad klarering** (när varorna klareras i ett annat EU-land) är inte särskilt hanterad.

> [!NOTE]
> Reglerna kommer från mervärdesskattelagen (2023:200) 8 kap. 24–27 §§ och 7 kap. 27 och 41 §§ samt Skatteverkets sida *Köpa varor från länder utanför EU*. Kontokopplingen är inte juridiskt kontrollerad ännu. Kontrollera alltid deklarationen i Skatteverkets e-tjänst innan du skriver under.

## Vanliga frågor

**Speditören har betalat tullen åt mig.**
Bokför speditörens faktura som en vanlig leverantörsfaktura. Tullen är en kostnad, och speditörens avgift har oftast svensk moms. Momsen på själva importen bokför du från tullräkningen, som speditören ska ge dig uppgifterna till.

**Hur ser jag att det blev rätt?**
Öppna momsdeklarationen för perioden och klicka på **Visa konton** vid ruta 50, 60 och 48. Läs [Momsdeklarationen](/manual/moms/momsdeklaration).

## Läs också

- [Inköp från utlandet och omvänd betalningsskyldighet](/manual/leverantorer/inkop-fran-utlandet)
- [Hur momsen hamnar i rätt ruta](/manual/moms/moms-och-konton)
- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
