---
beskrivning: Registrera kundens betalning på fakturan, eller läs in bankens fil med inbetalningar (camt.054) så kopplas de till fakturorna på OCR-numret.
ordning: 140
roller: [owner, admin, accountant, bookkeeper]
rutter: [invoicing.incomingPayments]
sokord: [inbetalning, betalning, kundbetalning, delbetalning, camt.054, bankfil, BgMax, OCR, avprickning, matcha betalning]
---

# Inbetalningar

När kunden har betalat prickar du av fakturan. Det kan du göra på två sätt: registrera betalningen direkt på fakturan, eller läsa in bankens fil med inbetalningar så att NONOX kopplar dem till rätt fakturor på OCR-numret. Med [bankkopplingen](/manual/bank/bankkoppling) påslagen kommer inbetalningarna in av sig själva.

Hur betalningen bokförs beror på bokföringsmetoden. Läs [Fakturametoden och kontantmetoden](/manual/fakturering/fakturametoden-och-kontantmetoden).

![Sidan Inbetalningar, där du läser in bankens fil med inbetalningar.](/manual/bilder/fakturering/inbetalningar.webp)

## Så här registrerar du en betalning för hand

1. Öppna fakturan under **Försäljning → Fakturor**. Filtret **Obetalda** visar de som väntar på betalning.
2. Under **Registrera betalning** fyller du i **Betaldag**, dagen pengarna kom in på kontot.
3. Kontrollera **Belopp**. Det är förifyllt med det som återstår. Ändra det om kunden har betalat en del.
4. Tryck på **Registrera betalningen**.

Har kunden betalat allt får fakturan status **Betald**. Har kunden betalat en del ser du *… är registrerat. Kvar att betala: …*, och fakturan är fortfarande obetald med resten kvar. Varje betalning syns under **Betalningar** med datum, belopp och en länk till verifikationen.

## Så här läser du in bankens fil

Banken kan ge dig en fil med de inbetalningar som har kommit in, en *avisering av inbetalningar* i formatet ISO 20022 camt.054. Hur du hämtar den skiljer sig mellan bankerna; leta i internetbanken efter filer för inbetalningar eller bankgiroinbetalningar i XML-format.

1. Hämta filen från internetbanken.
2. Gå till **Försäljning → Inbetalningar**.
3. Dra filen till rutan under **Läs in bankens fil**, eller tryck på **välj fil**. Filen får vara högst 10 MB.
4. NONOX läser filen och visar varje inbetalning med bokföringsdag, betalare och referens, belopp och den faktura NONOX föreslår.
5. Gå igenom förslagen. Är ett förslag fel väljer du rätt faktura i listan på raden, eller **Ingen faktura, hanteras för hand**.
6. Bocka i de inbetalningar du vill bokföra. De med ett förslag är redan ibockade.
7. Tryck på **Bokför valda**.

Varje vald inbetalning bokförs på bankens bokföringsdag i serie C, precis som när du registrerar en betalning på fakturan, och fakturan prickas av.

## Hur NONOX föreslår en faktura

- Har kunden betalat med fakturans **OCR-nummer** föreslås den fakturan.
- Har kunden skrivit ett meddelande letar NONOX efter ett OCR-nummer bland siffrorna i meddelandet.
- Förslaget görs bara om beloppet inte är större än det som återstår på fakturan.

Allt är förslag. Ingenting bokförs förrän du trycker på **Bokför valda**.

## Samma betalning bokförs aldrig två gånger

Varje inbetalning sparas med bankens egen referens. Läser du in en fil som innehåller betalningar från en tidigare fil hoppas de över, och du ser hur många som redan fanns. Det gäller också betalningar som redan har kommit in via bankkopplingen.

Under **Tidigare filer** finns de senaste filerna, och med bankkopplingen påslagen även **Från banken (NONOX Bank)**. Varje inläst fil sparas som den kom från banken.

## Det som inte går än

- **Överbetalningar.** Ett belopp som är större än det som återstår kan inte registreras på fakturan. Registrera det fakturan ska ha och bokför resten för hand med en vanlig verifikation, till exempel som en skuld till kunden. Fråga din redovisningskonsult vilket konto som passar.
- **En betalning för flera fakturor.** Välj **Ingen faktura, hanteras för hand** och registrera betalningen på varje faktura för sig, på respektive fakturas sida.
- **Betalningar i utländsk valuta.**
- **BgMax-filer.** NONOX läser bara camt.054. Bankgirots äldre BgMax-format ersätts av camt.054 hos bankerna.

## Vanliga frågor

**Jag får "Filen går inte att läsa som en avisering av inbetalningar (camt.054)".**
Filen har ett annat format, till exempel ett kontoutdrag (camt.053), en PDF eller en BgMax-fil. Hämta aviseringen av inbetalningar i ISO 20022-format från banken.

**En inbetalning har ingen faktura i listan.**
Fakturan kanske redan är betald eller krediterad, eller så är beloppet större än det som återstår. Bara obetalda fakturor kan väljas.

**Kan jag ångra en registrerad betalning?**
Inte från fakturan. Betalningen är bokförd och kan inte tas bort. Du kan rätta verifikationen med en rättelseverifikation, men det ändrar inte fakturans status eller det som står som betalt på den. Kontrollera därför beloppet och fakturan noga innan du registrerar, och ta hjälp av din redovisningskonsult om det redan har blivit fel. Läs [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation).

**Betalningen kom till ett annat bankkonto än 1930.**
Inbetalningar bokförs på 1930. Har pengarna kommit till ett annat konto, bokför en flytt mellan kontona med en vanlig verifikation.

## Läs också

- [Bankkoppling](/manual/bank/bankkoppling)
- [Obetalda fakturor och kundreskontran](/manual/fakturering/obetalda-fakturor)
- [Skicka fakturan och öppna PDF:en](/manual/fakturering/skicka-en-faktura)
- [Bankavstämning](/manual/bank/bankavstamning)
