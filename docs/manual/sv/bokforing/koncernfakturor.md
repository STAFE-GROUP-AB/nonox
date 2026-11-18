---
beskrivning: Leverera en faktura direkt till ett annat koncernföretags inkorg i NONOX, och gör ett motsvarande utkast i koncernföretaget för lån, ränta och koncernbidrag.
ordning: 116
roller: [owner, admin, accountant, bookkeeper]
sokord: [koncernfaktura, koncernintern faktura, internfakturering, koncernföretag, dotterbolag, moderbolag, koncernlån, koncernbidrag, motbokning, spegelverifikation]
---

# Koncernfakturor och motsvarande utkast

Använder flera företag i samma koncern NONOX behöver ni inte skriva in samma mellanhavande två gånger. En faktura från ett koncernföretag till ett annat kan **levereras** direkt till mottagarens inkorg, och ett lån, en ränta eller ett koncernbidrag som du har bokfört kan bli ett **utkast** i det andra företaget. NONOX bokför ingenting i det andra företaget åt er: där bokför företagets egna användare som vanligt, eller en mall för automatisk bokföring som de själva har slagit på.

Båda företagen måste vara med i samma koncern under **Mina företag → Koncernstruktur**, och kunden eller leverantören måste vara markerad som koncernföretag under [Koncernmarkeringar](/manual/bokforing/koncernmarkeringar).

![Koncernfakturor som företaget tar emot, levererade fakturor och motsvarande utkast på sidan Koncernmarkeringar.](/bilder/manual/bokforing/koncernfakturor.webp)

## Så här tar företaget emot koncernfakturor

En faktura levereras bara till företag som själva har valt att ta emot den, och bara från de koncernföretag de har valt.

1. Gå till **Bokföring → Koncernmarkeringar** i företaget som ska ta emot fakturorna.
2. Under **Ta emot koncernfakturor**: skriv **Koncernföretagets organisationsnummer** för företaget som skickar fakturorna. Är det redan markerat som koncernföretag bland leverantörerna finns det under **Förslag från leverantörerna**.
3. Välj **Ta emot fakturor**.

Inkorgen måste vara påslagen under **Företagsinställningar → Moduler**. Vill företaget inte längre ta emot fakturor från ett koncernföretag väljer du **Sluta ta emot**. Fakturor som redan har kommit ligger kvar i inkorgen.

![Ta emot koncernfakturor med moderbolaget i listan.](/bilder/manual/bokforing/ta-emot-koncernfakturor.webp)

Bara företagets ägare och administratörer kan ändra vilka koncernföretag som får leverera hit.

## Så här levererar du en faktura till ett koncernföretag

1. Utfärda fakturan som vanligt till kunden som är markerad som koncernföretag.
2. Öppna fakturan. Under **Leverera till koncernföretaget** står vilket koncernföretag kunden är markerad som.
3. Välj **Leverera till koncernföretaget** och bekräfta.

![En faktura till ett dotterbolag som är levererad till dotterbolagets inkorg.](/bilder/manual/fakturering/leverera-till-koncernforetag.webp)

Fakturan går som en e-faktura enligt Peppol BIS Billing 3.0 med PDF:en bifogad, samma fil som om den hade skickats via Peppol, men den lämnar aldrig NONOX. Hos mottagaren hamnar den i **Inkorg** under **Att granska**, märkt som en koncernfaktura, och tolkas och attesteras som vilken leverantörsfaktura som helst.

> [!WARNING]
> En levererad faktura går inte att ta tillbaka. Är något fel gör du en kreditfaktura och levererar den på samma sätt.

En faktura levereras en gång. Din bokföring påverkas inte: fakturan bokfördes när den utfärdades. NONOX sparar en kopia av den e-fakturafil som levererades, och den följer med i företagets [arkivpaket](/manual/bokslut/arkivpaket).

### Om fakturan inte kom fram

Kom fakturan inte fram står **Inte levererad** på fakturan och under **Levererade koncernfakturor**. NONOX säger inte varför, eftersom det är det andra företagets sak. Det kan bero på att mottagaren inte har valt att ta emot fakturor från er, att inkorgen inte är påslagen, att företagen inte längre är i samma koncern eller att organisationsnumret inte finns i NONOX. Kontrollera med mottagaren, eller skicka fakturan med e-post eller via Peppol.

Under **Levererade koncernfakturor** ser du alla fakturor företaget har försökt leverera, med mottagarens organisationsnummer, status, tid och vem som levererade. Ni ser bara om fakturan kom fram, inte hur mottagaren hanterar den.

## Så här gör du ett motsvarande utkast

Ett lån, en ränta, ett koncernbidrag eller en beslutad utdelning mellan koncernföretag har ingen faktura, men ska bokföras i båda företagen.

1. Bokför verifikationen som vanligt i ditt företag och öppna den.
2. Under **Koncernföretag**: välj **Skapa motsvarande utkast**.
3. Välj **Koncernföretag**. Du ser bara koncernföretag där du själv får skapa verifikationer.
4. Välj **Mellanhavande i det här företaget**, till exempel **Lån till koncernföretag**. Under fältet står vad det blir hos motparten.
5. Kontrollera **Datum**, **Serie hos motparten** och **Beskrivning**.
6. Kontrollera **Konto hos motparten** på varje rad. NONOX vänder debet och kredit och föreslår motpartens konto för de vanligaste kontona för koncernföretag, till exempel 2360 för ett lån som är 1320 hos er. Andra konton får samma nummer; byt dem om motparten använder ett annat konto. Vid ränte- och utdelningskonton står **Kontrollera kontot hos motparten**, eftersom de inte har något givet motkonto.
7. Välj **Skapa utkastet** och bekräfta.

![Skapa motsvarande utkast med raderna för ett lån till dotterbolaget.](/bilder/manual/bokforing/motsvarande-utkast.webp)

Raderna och beloppen är verifikationens och kan inte ändras i formuläret. Finns ett konto, kostnadsställe eller projekt inte hos motparten säger NONOX vilken rad det gäller, och inget utkast görs.

Utkastet hamnar under **Bokföring → Verifikationer** i det andra företaget. Det bokförs inte förrän någon där bokför det, med företagets vanliga regler för räkenskapsår, låsta perioder och numrering.

### Motpartens verifikation

Varje företag ser sina egna par under **Motsvarande utkast** på sidan Koncernmarkeringar och på verifikationen. I företaget som fick utkastet står din verifikation som **Motpartens verifikation**, med räkenskapsåret, till exempel A12 (2026). I ditt företag står den tom tills du vet numret: välj **Ange motpartens verifikation** på verifikationen och skriv serie och nummer, till exempel A12.

Har det andra företaget tagit bort utkastet kan du göra ett nytt.

## Bra att veta

- Att leverera kräver att du får skicka fakturor. Att göra ett motsvarande utkast kräver att du får skapa verifikationer i båda företagen.
- Leveranser, mottagning och utkast skrivs i [behandlingshistoriken](/manual/bokforing/behandlingshistorik) i respektive företag, med id:n och inga namn. Mottagarens historik visar att en koncernfaktura kom, inte vem hos er som levererade den.
- En användare som är begränsad till vissa kostnadsställen eller projekt kan inte leverera fakturor eller göra motsvarande utkast.
- NONOX ger inga råd om koncernbidrag, utdelning eller lån mellan bolag. Det avgör ni, eller er redovisningskonsult.

## Läs också

- [Koncernmarkeringar](/manual/bokforing/koncernmarkeringar)
- [Inkorgen](/manual/leverantorer/inkorgen)
- [Skicka en faktura](/manual/fakturering/skicka-en-faktura)
