---
beskrivning: Fakturera kunder i andra EU-länder och utanför EU – utan svensk moms, med rätt konto, momsruta och fakturatext. Även trepartshandel och OSS.
ordning: 120
roller: [owner, admin, accountant, bookkeeper]
sokord: [EU-försäljning, export, utlandet, unionsintern leverans, omvänd skattskyldighet, reverse charge, trepartshandel, OSS, distansförsäljning, VIES]
---

# Försäljning till andra EU-länder och export

Säljer du till en kund utomlands blir fakturan oftast utan svensk moms. NONOX behöver då veta vilken sorts försäljning det är, för det bestämmer intäktskontot, rutan i momsdeklarationen och texten som ska stå på fakturan. Du väljer det på kunden en gång, och det följer med till varje faktura.

> [!IMPORTANT]
> Reglerna för försäljning utomlands finns i Mervärdesskattelagen. Konton, momsrutor och fakturatexter i NONOX är markerade för juridisk kontroll. Stäm av med din redovisningskonsult eller Skatteverket innan du fakturerar utomlands första gången.

![En faktura för tjänster till ett företag i ett annat EU-land, utan svensk moms.](/bilder/manual/fakturering/forsaljning-till-utlandet.webp)

## Sorterna

Välj i fältet **Försäljning utomlands**, på kunden eller på fakturan:

| Val | Konto | Ruta | Text på fakturan (i korthet) |
|---|---|---|---|
| **Varor till företag i annat EU-land** | 3108 | 35 | Unionsintern leverans av varor, undantagen från moms. |
| **Tjänster till företag i annat EU-land** | 3308 | 39 | Omvänd betalningsskyldighet. |
| **Export av varor utanför EU** | 3105 | 36 | Export av varor, undantagen från moms. |
| **Tjänster till kund utanför EU** | 3305 | 40 | Tjänsten är omsatt utanför Sverige. |
| **Trepartshandel inom EU, som mellanman** | 3107 | 38 | Trepartshandel, köparen redovisar momsen. |
| **Privatperson i annat EU-land (OSS)** | 3106 | – | Moms enligt köparlandets regler, redovisas via OSS. |

Texten skrivs på fakturan på svenska och engelska. Kontot läggs till i kontoplanen om det saknas.

## Så här ställer du in kunden

1. Gå till **Register → Kunder** och öppna kunden med **Ändra** (eller tryck på **Ny kund**).
2. Skriv kundens land i **Land (landskod, t.ex. SE, DE, US)**, till exempel *DE*.
3. För företag i ett annat EU-land: skriv **Momsregistreringsnummer** med landskoden först, till exempel *DE123456789*.
4. Välj sorten i **Försäljning utomlands**.
5. Tryck på **Spara**.
6. För företag i ett annat EU-land: tryck på **Kontrollera i VIES** för att kontrollera momsnumret i EU:s register.

## Så här fakturerar du

1. Skapa en faktura till kunden. **Försäljning utomlands** är redan valt, och texten under fältet säger vilket konto som används och vilken text som skrivs ut.
2. Fyll i raderna. Momsen sätts till 0 % på alla rader när du sparar (utom för OSS, se nedan).
3. Tryck på **Skapa fakturan** och **Ja, skapa**.

## Det som kontrolleras

Fakturan stoppas när du skapar den om

- kunden inte har ett annat EU-land än Sverige som land, vid försäljning inom EU,
- kunden har Sverige eller ett EU-land som land, vid export,
- kunden saknar momsregistreringsnummer från sitt eget land, vid försäljning till företag inom EU (för Grekland börjar numret på EL),
- kundens momsnummer var ogiltigt vid den senaste kontrollen i VIES, eller
- ditt företag saknar momsregistreringsnummer, vid försäljning till företag inom EU.

## Privatpersoner i andra EU-länder (OSS)

Väljer du **Privatperson i annat EU-land (OSS)** avgör tröskelvärdet vilken moms fakturan får:

- **Under 99 680 kronor om året**, i år och i fjol, och utan att företaget valt beskattning i köparens land: svensk moms. Fakturan bokförs som en vanlig försäljning i Sverige och hamnar i den vanliga momsdeklarationen.
- **Över gränsen, eller när företaget har valt det**: raderna får momssatsen i kundens land när du sparar, och momsen bokförs på 2619 i stället för på de svenska momskontona. Den redovisas i OSS-deklarationen, inte i den svenska momsdeklarationen.

Fakturan visar hur mycket företaget har sålt till privatpersoner i andra EU-länder i år och varnar när gränsen närmar sig. NONOX har bara ländernas normala momssats, inte de sänkta. Läs [OSS](/manual/moms/oss).

Underlag för OSS och den periodiska sammanställningen finns under **Bokföring → EU-rapporter**. Läs [Moms och EU](/manual/moms).

## Det här händer i bokföringen

Under fakturametoden bokförs fakturan i serie B med 1510 Kundfordringar i debet och kontot för sorten i kredit, utan utgående moms (för OSS med momsen på 2619, och under tröskelvärdet med svensk moms som en vanlig försäljning). Försäljning till företag i andra EU-länder kommer med i den periodiska sammanställningen.

## Vanliga frågor

**Kan jag fakturera i euro?**
Ja. Fakturan skrivs i euro och bokförs i kronor med Riksbankens kurs. Läs [Faktura i utländsk valuta](/manual/fakturering/faktura-i-utlandsk-valuta).

**Kan jag ha ROT, RUT eller omvänd betalningsskyldighet för byggtjänster på samma faktura?**
Nej, välj det ena eller det andra.

**Hur visar jag att varorna har lämnat Sverige?**
Det gör du utanför NONOX, med till exempel fraktsedlar. Spara dem som underlag. Fråga din redovisningskonsult vad som krävs.

## Läs också

- [Kunder](/manual/fakturering/kunder)
- [Skapa en faktura](/manual/fakturering/skapa-en-faktura)
- [Faktura i utländsk valuta](/manual/fakturering/faktura-i-utlandsk-valuta)
- [Momsdeklarationen](/manual/moms/momsdeklaration)
- [Moms och EU](/manual/moms)
