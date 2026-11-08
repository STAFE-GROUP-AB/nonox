---
beskrivning: Fakturera med ROT- eller RUT-avdrag – markera arbetskostnaden, låt NONOX räkna avdraget och registrera Skatteverkets utbetalning när den kommer.
ordning: 100
roller: [owner, admin, accountant, bookkeeper]
sokord: [ROT, RUT, rotavdrag, rutavdrag, skattereduktion, husarbete, hushållsnära tjänster, fastighetsbeteckning, 1513]
---

# ROT- och RUT-avdrag

När du utför ROT- eller RUT-arbete åt en privatperson betalar kunden bara en del av arbetskostnaden. Resten begär företaget från Skatteverket. I NONOX väljer du avdraget på fakturan och markerar vilka rader som är arbete, så räknar NONOX avdraget, skriver ut det på fakturan och bokför Skatteverkets del som en fordran.

> [!IMPORTANT]
> Vilket arbete som ger rätt till avdrag, hur stort det är och vilka tak som gäller bestäms av Skatteverket. NONOX räknar i dag med 30 % av arbetskostnaden för ROT och 50 % för RUT, och med ett tak på 50 000 kr för ROT och 75 000 kr för ROT och RUT tillsammans per person och år. Kontrollera alltid aktuella regler hos Skatteverket eller med din redovisningskonsult innan du fakturerar.

![En faktura med ROT-avdrag till en privatperson.](/bilder/manual/fakturering/rot-och-rut.webp)

## Innan du börjar

- Kunden behöver ett **Personnummer (privatperson)** i kundregistret. Läs [Kunder](/manual/fakturering/kunder).
- Företaget måste använda **fakturametoden**. Med kontantmetoden går det inte att skapa en faktura med ROT eller RUT i NONOX än.

## Så här gör du

1. Skapa en ny faktura till kunden under **Försäljning → Fakturor** med **Ny faktura**.
2. Välj **ROT-avdrag** eller **RUT-avdrag** i fältet **ROT- eller RUT-avdrag**.
3. För ROT: skriv **Fastighetsbeteckning eller BRF**, till exempel *Uppsala Kåbo 1:23*. Bor kunden i en bostadsrätt skriver du föreningens organisationsnummer och lägenhetsnumret.
4. Skriv **Arbetade timmar** och **Typ av arbete**, till exempel *4* och *byte av golvbrunn*. Skatteverket frågar efter båda när du begär utbetalningen, även vid fast pris.
5. Fyll i raderna. En kolumn **Arbete** har kommit till. Bocka i den på de rader som är arbetskostnad. Material, resor och annat lämnar du utan bock.
6. Tryck på **Spara som utkast**. NONOX räknar avdraget på arbetsraderna inklusive moms, avrundat nedåt till hela kronor, och drar av det från det kunden ska betala.
7. Kontrollera beloppen och tryck på **Skapa fakturan** och **Ja, skapa**.

På fakturan står **Avgår ROT-avdrag** (eller RUT-avdrag) med beloppet, arbetskostnaden inklusive moms, fastigheten, arbetade timmar, typen av arbete, köparens namn och personnummer och texten att avdraget förutsätter att Skatteverket godkänner det – annars betalar köparen resten. Personnumret skrivs ut eftersom Skatteverket räknar upp det bland det fakturan ska visa.

Delar flera köpare på avdraget, till exempel makar, gör du det på en arbetsorder (se [Arbetsorder](/manual/fakturering/arbetsorder)). Fakturan visar då varje köpare med sitt avdrag, och varje köpare har sitt eget tak.

## Taket per kund och år

Avdraget hör till det år kunden **betalar** fakturan, inte det år den skrivs. NONOX räknar därför med förfallodagen som betaldag: procentsatsen den dagen, och det avdrag kunden redan har fått på **ert företags** fakturor som betalas samma år. Avdraget minskas om taket skulle överskridas. När kunden betalar kontrollerar NONOX avdraget igen mot den verkliga betaldagen. Betalade kunden ett annat år och blir avdraget då ett annat, visar fakturans sida hur mycket du högst kan begära hos Skatteverket; mellanskillnaden betalar kunden. Kunden kan ha använt en del av taket hos andra företag; det kan NONOX inte veta. Fråga kunden om du är osäker.

## Det här händer i bokföringen

När fakturan skapas bokförs den i serie B:

| Konto | Debet | Kredit |
|---|---|---|
| 1510 Kundfordringar | det kunden ska betala | |
| 1513 Kundfordringar – delad faktura | avdraget | |
| Intäktskonton och utgående moms | | hela försäljningen och momsen |

Kontot 1513 läggs till i kontoplanen om det saknas. Kundens betalning bokförs som vanligt mot 1510.

## När Skatteverket betalar

NONOX skickar inte begäran till Skatteverket. Du begär utbetalningen i Skatteverkets e-tjänst för rot och rut när kunden har betalat sin del. När pengarna kommer:

1. Öppna fakturan.
2. Under **ROT-avdrag hos Skatteverket** (eller RUT-avdrag) ser du *Begärt … mottaget …*.
3. Fyll i **Betaldag** och **Belopp**. Beloppet är förifyllt med det som återstår.
4. Tryck på **Registrera Skatteverkets betalning**.

Betalningen bokförs i serie C: 1930 i debet och 1513 i kredit. Du kan registrera Skatteverkets betalning före eller efter kundens.

> [!WARNING]
> Godkänner Skatteverket bara en del av avdraget ska kunden betala resten. NONOX flyttar inte den delen tillbaka till kunden; bokför det för hand med en vanlig verifikation och fakturera kunden. Kontrollera hur med din redovisningskonsult.

## Vanliga frågor

**Jag får "ROT och RUT gäller privatpersoner. Fyll i kundens personnummer."**
Kunden saknar personnummer i kundregistret. Lägg till det och spara utkastet igen.

**Jag får "Markera vilka rader som är arbetskostnad."**
Bocka i **Arbete** på minst en rad.

**Kan jag kombinera ROT eller RUT med omvänd betalningsskyldighet eller försäljning utomlands?**
Nej. Avdragen gäller arbete i Sverige åt privatpersoner.

**Fakturan blev fel. Hur krediterar jag?**
Som vanligt. Kreditfakturan tar ut både kundens del och avdraget. Avdraget räknas inte om: krediterar du hela fakturan tas exakt det avdrag bort som stod på fakturan, även om taket begränsade det. Krediterar du en del av arbetet tas samma andel av avdraget bort. Läs [Kreditera en faktura](/manual/fakturering/kreditfaktura).

**Skatteverket har redan betalat ut avdraget, och nu ska fakturan krediteras.**
Det går att kreditera. Den del som Skatteverket redan har betalat blir en skuld till Skatteverket, på konto 2890. Du ska anmäla ändringen till Skatteverket utan dröjsmål när kunden har fått tillbaka pengarna. Skatteverket beslutar sedan om återbetalning. NONOX påminner om det på fakturans sida innan du krediterar, och på kreditfakturans sida efteråt. När du har betalat tillbaka registrerar du betalningen på kreditfakturan, under **ROT-avdrag hos Skatteverket**.

**Jag har begärt utbetalning men inte fått pengarna, och nu krediterar jag.**
Då minskar kreditfakturan det du begär av Skatteverket på 1513. Ändra eller dra tillbaka begäran i Skatteverkets e-tjänst Rot och rut.

## Läs också

- [Skapa en faktura](/manual/fakturering/skapa-en-faktura)
- [Kunder](/manual/fakturering/kunder)
- [Inbetalningar](/manual/fakturering/inbetalningar)
- [Omvänd betalningsskyldighet för byggtjänster](/manual/fakturering/omvand-betalningsskyldighet-bygg)
