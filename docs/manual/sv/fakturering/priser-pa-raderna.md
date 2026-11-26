---
beskrivning: Så får raderna på fakturor, offerter och order kundens pris, varför ett pris blev som det blev, och hur du uppdaterar priserna på ett utkast.
ordning: 32
roller: [owner, admin, accountant, bookkeeper]
sokord: [pris, kundpris, prisavtal, prislista, kampanj, mängdpris, manuellt pris, uppdatera priser, varför detta pris, marginal, täckningsbidrag, täckningsgrad, under självkostnad, lägsta täckningsgrad]
---

# Priser på fakturor, offerter och order

När du skriver ett artikelnummer på en rad i en faktura, en offert eller en order hämtar NONOX kundens pris för dagen och valutan. Har kunden ett prisavtal eller en egen prislista används det priset, och räcker antalet till ett mängdpris får hela raden det priset. Har företaget inga prislistor får raden artikelns pris, precis som förut.

Priset **kopieras** till raden. Ändras en prislista eller ett avtal senare ändras inte raden, och en utfärdad faktura ändras aldrig.

![Ett fakturautkast där raderna har fått kundens priser. Etiketten under priset visar varifrån det kommer.](/bilder/manual/fakturering/priser-pa-raderna.webp)

## Varifrån priset kommer

Så fort företaget har en prislista eller ett prisavtal visar varje artikelrad en liten etikett under **À-pris**:

| Etikett | Betyder |
|---|---|
| **Prisavtal** | Kundens prisavtal. Ett avtal gäller före prislistor och kampanjer. |
| **Kundens prislista** | Kundens egen prislista, eller prislistan för kundens prisgrupp. |
| **Kampanj** | En kampanj som är lägre än kundens vanliga pris. |
| **Valutans prislista** | Standardlistan för dokumentets valuta. |
| **Grundpris** | Artikelns eget pris. |
| **Omräknat grundpris** | Artikeln har inget pris i valutan, så grundpriset är räknat om med Riksbankens kurs. Raden visar en varning. |
| **Manuellt pris** | Du har skrivit priset själv. |

Skriver du ett eget pris på en rad blir den **Manuellt pris**, och NONOX ändrar aldrig det priset. Tömmer du fältet hämtas priset igen när du sparar.

Ändrar du **Antal** eller **Enhet** på en rad där NONOX har hämtat priset medan du skriver får raden det pris som gäller för det nya antalet. Det gäller också **Moms**, men bara när priset kommer från en prislista med priser inklusive moms, eftersom momssatsen då ändrar priset utan moms. Ett pris du har skrivit, eller ett pris som redan var sparat på dokumentet, ändras aldrig av ett fält. Ändrar du **Moms** på en sparad rad vars pris kommer från en prislista med priser inklusive moms står *Priset hämtas när du sparar.* under fältet: när du sparar räknas priset utan moms om från det pris inklusive moms som raden fick, med den nya momssatsen, så att kunden inte betalar mer än det priset. Sparade priser ändras bara med **Uppdatera priser**. En rabatt du har skrivit står kvar. Kan priset inte räknas fram direkt står det *Priset hämtas när du sparar.* under fältet.

> [!NOTE]
> Priset som syns medan du skriver är ett förslag. När du sparar räknar NONOX fram priserna igen, och det är de priserna som sparas.

## Varför detta pris?

Tryck på etiketten under priset. Under raderna öppnas **Varför detta pris?** med:

- varifrån priset kommer, till exempel vilket prisavtal och från vilket antal mängdpriset gäller,
- om en avtalad rabatt ingår i à-priset eller om priset är omräknat från kronor,
- **Andra priser som prövades**, till exempel grundpriset, och varför de inte användes.

Tryck på **Stäng** när du är klar.

![Varför detta pris? för en rad med mängdpris från ett prisavtal.](/bilder/manual/fakturering/varfor-detta-pris.webp)

## Uppdatera priser på ett utkast

Ett utkast, en offert eller en order behåller sina priser när du sparar dem igen, också om en prislista har ändrats sedan. Byter du kund, datum eller valuta står det *Kund, datum eller valuta har ändrats. Priserna på raderna ändras inte av sig själva.*

Vill du ha dagens priser:

1. Tryck på **Uppdatera priser** under raderna.
2. Under **Dagens priser** ser du varje rad som skulle få ett nytt pris: **Nu**, **Nytt pris** och **Skäl**. Rader med ett manuellt pris finns inte med, de ändras aldrig.
3. Tryck på **Använd det nya priset**, eller till exempel **Använd de 3 nya priserna** när flera rader ändras. **Avbryt** stänger utan att ändra något.
4. Spara dokumentet för att behålla priserna.

Stämmer alla priser redan står det *Alla priser stämmer redan med dagens priser.*

![Uppdatera priser visar vad som skulle ändras innan något ändras.](/bilder/manual/fakturering/uppdatera-priser.webp)

## Rabatter

En avtalad rabatt ingår i **À-pris**: har kunden 10 % på en artikel för 100 kr står det 90,00 i fältet. **Rabatt %** är tomt och är till för en rabatt du skriver själv, som läggs ovanpå. Skriver du 15 % på raden blir beloppet 76,50 kr, både medan du skriver och på den sparade fakturan. Förklaringen under **Varför detta pris?** visar den avtalade rabatten.

## Offerter och order i en annan valuta

En offert eller order har en **Valuta**, som från början är kundens. Du kan välja SEK, EUR, USD, GBP, NOK, DKK eller CHF. Priserna på raderna skrivs i den valutan, och valutan följer med till ordern och till fakturan. Läs [Offerter och order](/manual/fakturering/offerter-och-order) och [Faktura i utländsk valuta](/manual/fakturering/faktura-i-utlandsk-valuta).

## Från offert till faktura

Kunden har tackat ja till offertens priser. När offerten blir en order, och ordern en faktura, följer priserna med oförändrade, också om en prislista har ändrats under tiden.

Har offerten gått ut (dagen i **Giltig till** har passerat) frågar NONOX först: *Offerten gällde till … Vilka priser ska gälla?* Välj **Behåll offertens priser**, eller **Hämta dagens priser** för att först se vad som skulle ändras. Tryck sedan på **Gör order med dagens priser** (eller **Gör faktura med dagens priser**). Priser som är skrivna för hand ändras inte. Du kan också trycka på **Uppdatera priser** på ordern eller fakturautkastet efteråt.

## Marginal och lägsta täckningsgrad

Den som får se kostnader (behörigheten att se kostnad och marginal: ägare, administratör, redovisningskonsult, bokförare och revisor) ser under raderna hur mycket affären ger. NONOX räknar kostnaden per rad från samma underlag som tillverkningskalkylen: artikelns värde i lagret, en egen stycklista, senaste inleveranspris eller artikelns inköpspris, omräknat till radens enhet.

- Ligger en rad under kostnaden står det i rött under **À-pris**, till exempel *Under självkostnad: raden ger 6 300,00 kr och kostar 7 600,00 kr.* Det är en varning, den hindrar ingenting.
- Under summan står **Täckningsbidrag** (försäljning minus kostnad) och **Täckningsgrad** (täckningsbidraget i procent av försäljningen). Rader som saknar känd kostnad räknas inte med, och det står hur många de är.
- Kostnad och marginal syns aldrig för kunden: inte på PDF:en, i kundportalen eller i API:et. Den som saknar behörigheten ser dem inte heller i NONOX.

![Ett fakturautkast där en rad ligger under kostnaden och en under lägsta täckningsgrad. Under summan står täckningsbidraget.](/bilder/manual/fakturering/marginal-pa-raderna.webp)

På en utfärdad faktura visar kortet **Täckningsbidrag** samma siffror. Marginalen räknas bara för dokument i kronor, eftersom kostnaderna är i kronor.

### Lägsta täckningsgrad

Företaget kan också ange en **lägsta täckningsgrad** under **Register → Prislistor**. Den är avstängd som standard. När den är på varnar NONOX för varje rad under gränsen, också för den som inte får se kostnader, men utan att säga vad något kostar. Läs [Prislistor](/manual/fakturering/prislistor).

Har företaget valt **Kräv godkännande innan dokumentet skickas** gäller det priser du har skrivit själv (**Manuellt pris**), en rabatt du har skrivit ovanpå ett pris från en lista, och rader utan artikel. Priser från prislistor och prisavtal, utan egen rabatt, varnar bara. Är hela dokumentets täckningsgrad under gränsen, till exempel för att en rad med minuspris (en rabattrad) dras av, krävs godkännande om en sådan rad, en rad med egen rabatt, ett handskrivet pris under gränsen eller en retur (en rad med minusantal) finns på dokumentet. En retur av en artikel som säljs på samma dokument tar tillbaka sin kostnad, högst så mycket som såldes och högst radens belopp, så en vanlig delretur ändrar inte täckningsgraden men en retur kan aldrig höja den. En rad med bara en frakt eller en kommentar på 0 kr stoppar ingenting. Då går det inte att skicka en offert eller order, eller skapa en faktura, förrän priserna är godkända:

1. Under raderna står *Priset på rad … ligger under lägsta täckningsgrad.* På sidan för dokumentet finns kortet **Priser under lägsta täckningsgrad**. Tryck på **Be om godkännande**.
2. Någon med behörigheten att godkänna priser under lägsta täckningsgrad öppnar dokumentet och trycker på **Godkänn priserna**, eller på **Avslå** med ett skäl i fältet **Skäl (krävs om du avslår)**.
3. Är priserna godkända kan dokumentet skickas eller skapas. Ändrar du en rad eller ett pris efteråt måste de godkännas på nytt.

Den som har skrivit eller ändrat dokumentet, eller begärt godkännandet, kan inte godkänna det själv. Det följer samma inställning som attest av leverantörsfakturor; ett företag med bara en person kan stänga av den.

![Kortet Priser under lägsta täckningsgrad på ett fakturautkast, med knappen Be om godkännande.](/bilder/manual/fakturering/pris-under-lagsta-tackningsgrad.webp)

En offert måste vara godkänd innan den skickas, skickas för signering eller godkänns av kunden, och en offert vars rader ändrats efter godkännandet kan inte bli en order förrän de godkänns på nytt. Kunden får bara veta att avsändaren måste titta på offerten. En skickad offert vars rader ändrats efter godkännandet, och därför inte är godkänd, visas inte i kundportalen, varken själva offerten eller PDF:en, förrän priserna är godkända igen.

Ett dokument i en annan valuta än kronor kan inte marginalkontrolleras, eftersom kostnaderna är i kronor. Slår företaget på att godkännande krävs gäller det därför varje pris på en artikelrad som är skrivet för hand i en annan valuta, hur högt det än är.

Fakturor som skapas och skickas automatiskt från ett avtal och kreditfakturor stoppas inte av lägsta täckningsgrad. Ett utkast från ett avtal som du skapar själv gör det.

## Vanliga frågor

**Ändras priset på en faktura jag redan har skapat om prislistan ändras?**
Nej. En utfärdad faktura går aldrig att ändra, och priserna på den är kopior.

**Vad händer med priserna om jag byter kund eller datum?**
Ingenting av sig självt. Raderna behåller priset som syns. Skiljer det sig från den nya kundens pris sparas raden som **Manuellt pris**. Tryck på **Uppdatera priser** om raderna ska få de nya priserna.

**Varför står det Manuellt pris på en rad där jag inte har ändrat något?**
Priset på raden skiljer sig från det NONOX räknar fram, till exempel för att raden skrevs innan prislistorna fanns. Tryck på **Uppdatera priser** om raden ska få dagens pris, eller töm fältet och spara.

**Varför ser jag ingen marginal på raderna?**
Marginalen visas bara för den som får se kostnader, och bara för dokument i kronor. Saknar artikeln ett inköpspris och ett värde i lagret finns ingen kostnad att räkna med.

**Kan alla se varför ett pris blev som det blev?**
Etiketten syns för alla som skriver fakturor. Förklaringen öppnas för den som får se priser, till exempel ägaren, redovisningskonsulten och bokföraren.

## Läs också

- [Skapa en faktura](/manual/fakturering/skapa-en-faktura)
- [Offerter och order](/manual/fakturering/offerter-och-order)
- [Artiklar](/manual/fakturering/artiklar)
- [Faktura i utländsk valuta](/manual/fakturering/faktura-i-utlandsk-valuta)
