---
beskrivning: Blev en faktura fel? Kreditera den med en kreditfaktura som tar ut den, i bokföringen och för kunden, och skicka sedan en ny.
ordning: 80
roller: [owner, admin, accountant, bookkeeper]
sokord: [kreditfaktura, kreditera, kreditnota, makulera faktura, fel på faktura, ta bort faktura]
---

# Kreditera en faktura

En skapad faktura kan inte ändras eller tas bort, eftersom den är bokförd. Blev något fel – fel kund, fel pris, fel datum – krediterar du den i stället. Kreditfakturan är en faktura med negativa belopp som tar ut originalet, både i bokföringen och för kunden. Sedan skriver du en ny, rätt faktura.

![En kreditfaktura som krediterar en obetald faktura.](/bilder/manual/fakturering/kreditfaktura.webp)

## Så här gör du

1. Öppna fakturan under **Försäljning → Fakturor**.
2. Under **Kreditera** trycker du på **Kreditera fakturan** och sedan **Ja, förbered kreditfaktura**.
3. Ett utkast till kreditfaktura öppnas, med samma kund, referenser och rader som originalet, och meddelandet *Kreditering av faktura 1029.* Överst står *Det här är en kreditfaktura. Beloppen dras av från kundens faktura när den skapas.*
4. Kontrollera utkastet. Du kan ändra fakturadatum och meddelande. Låt raderna vara som de är: originalet stängs helt när kreditfakturan skapas, oavsett beloppet på kreditfakturan.
5. Tryck på **Skapa kreditfakturan** och sedan **Ja, skapa**.
6. Skicka kreditfakturan till kunden på dess sida, med **Skicka**, så att kunden vet att den gamla fakturan inte ska betalas.
7. Skriv en ny faktura med rätt uppgifter om kunden ska ha en. Läs [Skapa en faktura](/manual/fakturering/skapa-en-faktura).

När kreditfakturan är skapad får originalet status **Krediterad** och kreditfakturan status **Betald**. Ingen av dem väntar längre på betalning. På originalets sida står **Krediterad med** och en länk, och på kreditfakturans sida står **Krediterar**.

## Det här händer i bokföringen

Kreditfakturan bokförs som en faktura men med omvända tecken. Under fakturametoden blir det en verifikation i serie B som tar bort kundfordran på 1510 och minskar försäljning och utgående moms med samma belopp som originalet. Under kontantmetoden bokförs ingenting, eftersom originalet aldrig bokfördes. Kreditfakturan får nästa nummer i samma nummerserie som fakturorna.

## Bara obetalda fakturor

NONOX krediterar bara en faktura som är skapad och helt obetald, och bara en gång. Knappen **Kreditera** syns inte om:

- kunden har betalat hela fakturan eller en del av den,
- fakturan redan har en kreditfaktura, eller
- fakturan själv är en kreditfaktura.

> [!IMPORTANT]
> Har kunden redan betalat och ska ha pengar tillbaka går det inte att göra med en kreditfaktura i NONOX i dag. Bokför återbetalningen med en vanlig verifikation och spara underlaget. Fråga din redovisningskonsult om du är osäker. Läs [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation).

## Vanliga frågor

**Kan jag kreditera bara en del av fakturan?**
Kreditfakturan tar alltid ut hela originalet, och originalet stängs. Ska kunden betala en del skriver du en ny faktura för den delen.

**Ett kreditfakturautkast ligger kvar som jag inte vill använda.**
Utkastet påverkar ingenting så länge du inte skapar det. Originalet är fortfarande obetalt och kan betalas eller påminnas som vanligt, men det går inte att förbereda en ny kreditfaktura medan utkastet finns. Öppna utkastet från originalets sida, under **Krediterad med**, när du vill använda det.

**Kreditfakturan får ett eget nummer – blir det en lucka i fakturanumren?**
Nej. Fakturor och kreditfakturor delar nummerserie, och numren delas ut utan luckor.

## Läs också

- [Skapa en faktura](/manual/fakturering/skapa-en-faktura)
- [Fakturametoden och kontantmetoden](/manual/fakturering/fakturametoden-och-kontantmetoden)
- [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation)
