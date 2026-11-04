---
beskrivning: Bokslutets checklista – vad NONOX kontrollerar själv i bokföringen, vad du bekräftar och hur du kommer vidare när en punkt inte är klar.
ordning: 10
roller: [owner, admin, accountant]
rutter: [accounting.closing]
sokord: [bokslut, checklista, ingående balanser, bokslutschecklista, årsbokslut, stänga året, avstämning, kassainventering, kundfordringar, leverantörsskulder, skattekonto]
---

# Checklistan för bokslutet

Bokslutet görs under **Bokföring → Bokslut**, ett räkenskapsår i taget, i fyra steg: **1. Checklista**, **2. Bokslutsverifikationer**, **3. Stäng året** och **4. Resultatdisposition**. Den här artikeln handlar om checklistan – året kan inte stängas förrän varje punkt är klar eller inte gäller företaget.

Överst på sidan väljer du räkenskapsår och ser **Resultat före skatt**, hur många punkter som är klara i **Checklistan** och om året är **Öppet** eller **Stängt**.

![Bokslutets checklista med resultatet före skatt och det som återstår.](/bilder/manual/bokslut/checklistan.webp)

## Punkterna NONOX kontrollerar själv

De här punkterna bockas av av sig själva när bokföringen visar att de är klara. Är en punkt inte klar finns länken **Gå dit** till sidan där du åtgärdar den.

| Punkt | Klar när |
|---|---|
| **Inga utkast i året** | Alla verifikationer i året är bokförda. Bokför eller ta bort utkasten. |
| **Momsen är redovisad** | Alla momsperioder som slutar i året är bokförda. Ett kalenderkvartal som fortsätter efter årets slut räknas till nästa år. Punkten räknar upp de som saknas. |
| **Ingående balanser stämmer med förra året** | Årets ingående balanser är lika med de utgående balanserna för året före. Gäller inte det första året i NONOX. Skiljer de sig, uppdatera dem under **Bokföring → Räkenskapsår** – läs [När de ingående balanserna inte längre stämmer](/manual/bokforing/rakenskapsar#nar-de-ingaende-balanserna-inte-langre-stammer). |
| **Bank och kassa är avstämda** | Varje bank- och kassakonto med saldo eller rörelse är avstämt utan skillnad den sista dagen i året. |
| **Avskrivningarna är bokförda** | Alla tillgångar i anläggningsregistret är avskrivna till och med årets sista månad. |
| **Periodiseringarna är bokförda** | Alla periodiseringar är bokförda till och med årets sista månad. |
| **Obetalda fakturor vid årets slut är bokförda** | Bara med kontantmetoden: kund- och leverantörsfakturor som är obetalda den sista dagen är bokförda i steg 2. Gäller inte med fakturametoden eller om inga fakturor är obetalda. Läs [Obetalda fakturor vid årets slut](/manual/bokslut/kontantmetoden-vid-arets-slut). |
| **Valutafordringar och valutaskulder är omvärderade** | Obetalda kund- och leverantörsfakturor i utländsk valuta är värderade till kursen den sista dagen, i steg 2. Gäller inte utan sådana fakturor. Med kontantmetoden bokför du först de obetalda fakturorna. |
| **Årets skatt är bokförd** | Skatten är bokförd i steg 2, eller noll angivet. Gäller inte enskild firma, handelsbolag och kommanditbolag. |
| **Årets resultat är bokfört** | Årets resultat är bokat i steg 2. |

## Punkterna du bekräftar

De här kan NONOX inte se själv. Kryssa i **Klart eller gäller inte** när punkten är gjord – eller när den inte gäller företaget.

- **Kassan är inventerad** – räkna kontanterna den sista dagen och jämför med kassakontot.
- **Kundfordringar stämmer** – obetalda kundfakturor den sista dagen ska vara lika med saldot på 1510. **Visa reskontran** öppnar reskontran för den sista dagen med saldot och differensen. Skriv bort fordringar som inte kommer att betalas som kundförlust på fakturan; läs [Obetalda fakturor och kundreskontran](/manual/fakturering/obetalda-fakturor).
- **Leverantörsskulder stämmer** – obetalda leverantörsfakturor den sista dagen ska vara lika med saldot på 2440.
- **Skattekontot är avstämt** – saldot på 1630 ska vara lika med skattekontot hos Skatteverket den sista dagen.
- **Löner och semesterskuld är bokförda** – löner, skatter, avgifter och semesterlöneskuld för året.
- **Varulagret är inventerat** – räkna lagret och bokför förändringen.

Bockarna kan tas bort igen så länge året är öppet. NONOX sparar vem som kryssade och när.

## Bokfört för hand

Två av punkterna som NONOX kontrollerar själv kan du också bekräfta: **Obetalda fakturor vid årets slut är bokförda** och **Valutafordringar och valutaskulder är omvärderade**. Har du bokfört verifikationen själv – till exempel för att Riksbanken saknar kurs för en valuta den sista dagen – kryssar du i **Bokfört för hand** eller **Omvärderingen är bokförd för hand** vid punkten. Då räknas punkten som klar. Kryssa bara i när verifikationen verkligen är bokförd.

Vid **Ingående balanser stämmer med förra året** finns i stället fältet **Skäl till att skillnaden är avsiktlig** och knappen **Skillnaden är avsiktlig**. Årsredovisningslagen kräver att ingående balans stämmer med förra årets utgående balans, men tillåter en avvikelse om det finns särskilda skäl (2 kap. 4 §). Kryssa bara i om det finns särskilda skäl för skillnaden. Skriv skälet när du kryssar i. I årsredovisningen ska du lämna en upplysning i en not om skälet och om hur skillnaden påverkar företagets ställning och resultat (årsredovisningslagen 5 kap. 5 §). Ändras skillnaden efteråt, till exempel av en ny verifikation i förra året, räknas bekräftelsen inte längre och punkten blir ogjord igen.

## Så här tar du dig igenom checklistan

1. Gå till **Bokföring → Bokslut** och välj året.
2. Börja uppifrån. Klicka på **Gå dit** vid varje punkt som inte är klar, åtgärda och kom tillbaka.
3. Gör de manuella kontrollerna och kryssa i **Klart eller gäller inte**.
4. När allt utom skatt och resultat är klart, gå till [Skatt och årets resultat](/manual/bokslut/bokslutsverifikationer).

> [!TIP]
> Ta fram saldobalansen under **Bokföring → Rapporter** för årets sista dag när du stämmer av 1510, 2440 och 1630. Läs [Kontoanalys och huvudbok](/manual/rapporter/kontoanalys-och-huvudbok).

## Kontantmetoden

Bokför företaget enligt kontantmetoden är obetalda kund- och leverantörsfakturor inte bokförda på 1510 och 2440 under året. De bokförs i bokslutet, i ett eget steg. Gör det innan du bockar av **Kundfordringar stämmer** och **Leverantörsskulder stämmer**. Läs [Obetalda fakturor vid årets slut (kontantmetoden)](/manual/bokslut/kontantmetoden-vid-arets-slut).

## Vem får göra bokslutet?

Sidan kräver rätten att hantera räkenskapsår: **Ägare**, **Administratör** och **Redovisningskonsult**.

## Läs också

- [Skatt och årets resultat](/manual/bokslut/bokslutsverifikationer)
- [Obetalda fakturor vid årets slut (kontantmetoden)](/manual/bokslut/kontantmetoden-vid-arets-slut)
- [Bankavstämning](/manual/bank/bankavstamning)
- [Momsdeklarationen](/manual/moms/momsdeklaration)
- [Periodiseringar](/manual/bokslut/periodiseringar)
