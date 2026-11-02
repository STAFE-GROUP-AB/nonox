---
beskrivning: Fakturera i euro, dollar eller annan valuta. Fakturan bokförs i kronor med Riksbankens kurs, och kursvinst eller kursförlust bokförs när kunden betalar.
ordning: 125
roller: [owner, admin, accountant, bookkeeper]
sokord: [valuta, euro, eur, usd, dollar, pund, gbp, nok, dkk, växelkurs, valutakurs, kursdifferens, kursvinst, kursförlust, 3960, 7960, riksbanken, iban]
---

# Faktura i utländsk valuta

Du kan fakturera en kund i euro, dollar, pund, norska eller danska kronor eller en annan valuta. Fakturan skrivs i kundens valuta. NONOX räknar om den till kronor med Riksbankens kurs och bokför den i kronor. När kunden betalar registrerar du hur många kronor som kom in, och skillnaden mot den bokförda kursen bokförs som kursvinst eller kursförlust.

> [!IMPORTANT]
> Vilken kurs momsen ska räknas med, och vad som ska stå i kronor på fakturan, är markerat för juridisk kontroll i NONOX. Stäm av med din redovisningskonsult innan du fakturerar i utländsk valuta första gången.

## Så här ställer du in kunden

1. Gå till **Register → Kunder** och öppna kunden med **Ändra**.
2. Skriv valutan i **Valuta för fakturor (t.ex. SEK, EUR, USD)**, till exempel *EUR*.
3. Tryck på **Spara**.

Nya fakturor till kunden börjar då i den valutan. Du kan ändra valutan på varje faktura.

## Så här fakturerar du

1. Skapa en faktura som vanligt: **Försäljning → Fakturor → Ny faktura**. Läs [Skapa en faktura](/manual/fakturering/skapa-en-faktura).
2. Välj kunden. **Valuta** fylls i med kundens valuta.
3. Kontrollera **Fakturadatum**. **Kurs, kronor per EUR** fylls i med Riksbankens kurs för fakturadatumet, och under fältet står vilken dag kursen gäller. Du kan ändra kursen.
4. Skriv raderna med priser i valutan, utan moms. Summorna längst ner visas i valutan.
5. Tryck på **Skapa fakturan** och **Ja, skapa**.

Ett artikelpris är i kronor. Väljer du en artikel på en faktura i annan valuta fylls benämning, enhet och moms i, men priset skriver du själv.

## Det här står på fakturan

- Rader, moms och summa i valutan. Summan avrundas inte till hela enheter – öresavrundning gäller bara fakturor i kronor.
- Under summan, rubriken **I svenska kronor**: momsen per momssats och summan i kronor, och kursen med dag och källa.
- Ditt företags IBAN och BIC, om de finns i [företagets uppgifter](/manual/installningar/foretagets-uppgifter) under **Betalning**. Utländska kunder kan sällan betala till bankgiro.

## Så här registrerar du betalningen

1. Öppna fakturan.
2. Fyll i **Betaldag**.
3. Fyll i **Belopp i EUR** – det kunden har betalat. Det som återstår är förifyllt.
4. **Kronor in på banken** är förifyllt med Riksbankens kurs på betaldagen. Ändra det till beloppet på kontoutdraget.
5. Läs raden under fälten. Den säger hur mycket kundfordran minskar och om det blir en kursvinst eller kursförlust.
6. Klicka på **Registrera betalningen**.

Betalar kunden i flera omgångar minskar valutabeloppet för varje betalning, och det som återstår visas i valutan. Läser du in bankens fil med inbetalningar tar NONOX beloppet i valutan från filen. Läs [Inbetalningar](/manual/fakturering/inbetalningar).

## Det här händer i bokföringen

**Fakturan** bokförs i kronor till kursen på fakturan. Varje rad räknas om till kronor, och momsen räknas på kronorna. Momsdeklarationen, rapporterna och SIE-filen är i kronor.

**Betalningen** blir en verifikation i serie C:

| Konto | Debet | Kredit |
|---|---|---|
| 1930 Företagskonto | kronorna som kom in | |
| 1510 Kundfordringar | | den bokförda andelen |
| 3960 Valutakursvinster … | | om det kom in mer |
| 7960 Valutakursförluster … | om det kom in mindre | |

Med samma kurs blir det ingen differensrad. Den sista betalningen tar med allt som återstår, så att 1510 går jämnt ut. Saknas 3960 eller 7960 i kontoplanen läggs kontot till med sitt BAS-namn.

Enligt kontantmetoden bokförs intäkten och momsen vid betalningen, till fakturans kurs, och skillnaden mot banken på 3960 eller 7960.

## Kreditfaktura och påminnelse

En [kreditfaktura](/manual/fakturering/kreditfaktura) får samma valuta och samma kurs som fakturan den krediterar, så att kundfordran tas ut exakt. En [påminnelse](/manual/fakturering/paminnelser) gäller det som återstår i valutan.

## Vid bokslutet

Är fakturan obetald den sista dagen i räkenskapsåret ska den värderas till kursen den dagen. Det gör du i bokslutet. Läs [Skatt och årets resultat](/manual/bokslut/bokslutsverifikationer).

## Det här går inte än

- ROT- och RUT-avdrag i annan valuta än kronor.
- Offerter, order och avtalsfakturor i utländsk valuta.
- Bankkonton i annan valuta än kronor.
- Överbetalningar. Ett belopp i valutan som är större än det som återstår kan inte registreras.

## Vanliga frågor

**Kursen fylls inte i.**
Hittar NONOX ingen kurs hos Riksbanken för valutan, eller går Riksbanken inte att nå just då, får du ett meddelande när du sparar. Skriv kursen själv, till exempel från bankens kurslista.

**Kan jag ändra kursen när fakturan är skapad?**
Nej. Kursen hör till fakturan, liksom beloppen. Blev något fel krediterar du fakturan och skapar en ny.

**Kunden betalade avgifter till sin bank, så det kom in mindre.**
Registrera hela beloppet i valutan som betalt och kronorna som kom in. Skillnaden bokförs som kursförlust. Vill du hellre bokföra avgiften som bankkostnad rättar du verifikationen med din redovisningskonsult.

## Läs också

- [Försäljning till andra EU-länder och export](/manual/fakturering/forsaljning-till-utlandet)
- [Kunder](/manual/fakturering/kunder)
- [Inbetalningar](/manual/fakturering/inbetalningar)
- [Leverantörsfakturor i utländsk valuta](/manual/leverantorer/utlandsk-valuta)
