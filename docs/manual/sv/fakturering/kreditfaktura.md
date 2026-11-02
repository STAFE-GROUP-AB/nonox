---
beskrivning: Blev en faktura fel, eller ska kunden få ett prisavdrag? Kreditera hela den eller en del av den, även när den är betald, och betala tillbaka det kunden har betalat.
ordning: 80
roller: [owner, admin, accountant, bookkeeper]
sokord: [kreditfaktura, kreditera, kreditnota, delkreditera, delkreditering, prisavdrag, återbetalning, betala tillbaka, kundsaldo, betald faktura, makulera faktura, fel på faktura, ta bort faktura]
---

# Kreditera en faktura

En skapad faktura kan inte ändras eller tas bort, eftersom den är bokförd. Blev något fel – fel kund, fel pris, fel datum – krediterar du den i stället. Kreditfakturan är en faktura med negativa belopp som tar ut originalet, både i bokföringen och för kunden. Sedan skriver du en ny, rätt faktura.

![En kreditfaktura som krediterar en obetald faktura.](/bilder/manual/fakturering/kreditfaktura.webp)

## Så här gör du

1. Öppna fakturan under **Försäljning → Fakturor**.
2. Under **Kreditera** trycker du på **Kreditera fakturan** och sedan **Ja, förbered kreditfaktura**.
3. Ett utkast till kreditfaktura öppnas, med samma kund, referenser och rader som originalet, och meddelandet *Kreditering av faktura 1029.* Överst står *Det här är en kreditfaktura. Beloppen dras av från kundens faktura när den skapas.*
4. Kontrollera utkastet. Du kan ändra fakturadatum och meddelande. Ska bara en del krediteras ändrar du raderna: ta bort rader, eller ändra antal eller pris, så att kreditfakturan visar det kunden ska slippa betala.
5. Tryck på **Skapa kreditfakturan** och sedan **Ja, skapa**.
6. Skicka kreditfakturan till kunden på dess sida, med **Skicka**, så att kunden vet att den gamla fakturan inte ska betalas.
7. Skriv en ny faktura med rätt uppgifter om kunden ska ha en. Läs [Skapa en faktura](/manual/fakturering/skapa-en-faktura).

Kreditfakturan minskar originalet med sitt eget belopp. Krediterar du hela fakturan får originalet status **Krediterad** och kreditfakturan status **Betald**, och ingen av dem väntar längre på betalning. Krediterar du en del står originalet kvar som **Obetald** med det som återstår, och kan betalas och påminnas som vanligt. Under **Krediterat** ser du hur mycket som är krediterat och vad som är kvar. På originalets sida står **Krediterad med** och en länk, och på kreditfakturans sida står **Krediterar**.

## Det här händer i bokföringen

Kreditfakturan bokförs som en faktura men med omvända tecken. Under fakturametoden blir det en verifikation i serie B som minskar kundfordran på 1510 och försäljning och utgående moms med kreditfakturans belopp. Kundreskontran och konto 1510 visar samma belopp efteråt. Under kontantmetoden bokförs ingenting när kreditfakturan skapas. När kunden betalar resten bokförs betalningens del av försäljningen och momsen, och när du betalar tillbaka till kunden bokförs återbetalningens del. Kreditfakturan får nästa nummer i samma nummerserie som fakturorna.

## Betalda och delvis betalda fakturor

Du kan kreditera en faktura även när kunden har betalat, till exempel för ett prisavdrag eller en reklamation. Momslagen kräver en kreditfaktura som hänvisar till fakturan, oavsett om den är betald.

- Har kunden betalat en del minskar kreditfakturan först det som återstår att betala.
- Det kunden redan har betalat av det krediterade beloppet blir pengar som kunden ska få tillbaka. Kreditfakturan får då status **Att återbetala**, och beloppet står som en skuld till kunden i kundreskontran och med kredit på 1510.

### Betala tillbaka till kunden

1. Betala kunden från banken.
2. Öppna kreditfakturan.
3. Under **Betala tillbaka till kunden** fyller du i **Betaldag** och **Belopp**. Beloppet är förifyllt med det som återstår. Är fakturan i utländsk valuta fyller du i beloppet i valutan och kronorna som gick ut från banken.
4. Tryck på **Registrera återbetalningen**.

Återbetalningen bokförs i serie C: 1510 i debet och 1930 i kredit. Under kontantmetoden bokförs i stället återbetalningens del av försäljningen och momsen mot 1930, eftersom de bokfördes när kunden betalade. När allt är återbetalt får kreditfakturan status **Betald**.

## När går det inte att kreditera?

En kreditfaktura får inte vara större än det som ännu inte är krediterat av fakturan. Knappen **Kreditera** syns inte om:

- hela fakturan redan är krediterad,
- det redan finns ett utkast till kreditfaktura för den, eller
- fakturan själv är en kreditfaktura.

## Vanliga frågor

**Kunden har betalat. Kan jag ändå kreditera?**
Ja. Läs avsnittet **Betalda och delvis betalda fakturor** ovan.

**Kan jag kreditera bara en del av fakturan?**
Ja. Ändra raderna i utkastet så att de visar det som ska krediteras. Originalet minskar med kreditfakturans belopp, och resten står kvar att betala.

**Ett kreditfakturautkast ligger kvar som jag inte vill använda.**
Utkastet påverkar ingenting så länge du inte skapar det. Originalet är fortfarande obetalt och kan betalas eller påminnas som vanligt, men det går inte att förbereda en ny kreditfaktura medan utkastet finns. Öppna utkastet från originalets sida, under **Krediterad med**, när du vill använda det.

**Kreditfakturan får ett eget nummer – blir det en lucka i fakturanumren?**
Nej. Fakturor och kreditfakturor delar nummerserie, och numren delas ut utan luckor.

## Läs också

- [Skapa en faktura](/manual/fakturering/skapa-en-faktura)
- [Fakturametoden och kontantmetoden](/manual/fakturering/fakturametoden-och-kontantmetoden)
- [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation)
