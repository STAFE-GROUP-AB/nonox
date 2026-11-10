---
beskrivning: Ange hur en artikel räknas, köps och säljs, i styck, meter, kvadrat, kubik eller en egen enhet, och hur enheterna räknas om mellan varandra.
ordning: 25
roller: [owner, admin, accountant, bookkeeper]
rutter: [invoicing.units]
sokord: [enhet, enheter, styck, löpmeter, kvadratmeter, kubikmeter, förpackning, omräkning, lagerenhet, inköpsenhet, försäljningsenhet, trä, virke, skivor, mått]
---

# Enheter på artiklar

Virke köps i kubikmeter, ligger på lagret som löpmeter och används som bitar. En skiva köps per ark och säljs per kvadratmeter. Skruv köps i förpackningar och används styck för styck. Därför har varje artikel tre enheter, och NONOX räknar om mellan dem.

![Företagets enheter: de som finns från början och de ni lagt till själva.](/bilder/manual/fakturering/enheter.webp)

## De tre enheterna

- **Lagerenhet** – det varan räknas, flyttas och värderas i. Saldot i lagret står alltid i den. Välj den enhet ni faktiskt räknar i: för trä löpmeter eller styck, för skivor kvadratmeter eller styck. Kubikmeter är en bra inköps- och visningsenhet men en grov lagerenhet, eftersom saldot räknas i tusendelar av enheten och en tusendel kubikmeter är en liter.
- **Inköpsenhet** – det leverantören säljer i.
- **Försäljningsenhet** – det som skrivs på fakturan. Fältet **Enhet** på fliken **Enkel** är samma sak som försäljningsenheten, för den som bara behöver en enhet.

Lämnar du inköps- eller försäljningsenheten tom är den samma som lagerenheten. En artikel som inte har fått några egna enheter fungerar som förut: dess enhet är både lager-, inköps- och försäljningsenhet.

## Så här anger du enheterna på en artikel

1. Gå till **Register → Artiklar** och tryck på **Ändra** vid artikeln, eller på **Ny artikel**.
2. Öppna fliken **Avancerad**. Under **Enheter** väljer du **Lagerenhet**, **Inköpsenhet** och **Försäljningsenhet**.
3. För trä och skivor: fyll i **Längd (mm)**, **Bredd (mm)** och **Tjocklek (mm)** under **Materialets mått**. Skriv i millimeter med högst tre decimaler, till exempel *45* eller *0,6* för faner.
4. Behövs en omräkning som NONOX inte kan räkna fram, till exempel förpackning till styck, trycker du på **Lägg till omräkning**, skriver hur många som går på en lagerenhet och väljer enheten.
5. Tryck på **Spara**.

NONOX stoppar en inköps- eller försäljningsenhet som inte går att räkna om till lagerenheten och säger vilken omräkning som saknas.

![Artikelns enheter: regel som köps i kubikmeter, ligger i löpmeter och räknas om efter måtten.](/bilder/manual/fakturering/enheter-pa-artikeln.webp)

## Hur omräkningen fungerar

- **Samma slag av enhet räknas om av sig själv.** Meter, löpmeter, centimeter och millimeter hör ihop, liksom kilo och gram, liter och deciliter, kvadratmeter och kvadratdecimeter, kubikmeter och liter, timme och minut.
- **Från materialets mått.** Med längd, bredd och tjocklek räknar NONOX fram omräkningarna mellan löpmeter, kvadratmeter, kubikmeter och styck. En regel på 45 × 95 mm och 4 800 mm är 0,004275 kubikmeter per löpmeter, och en bit är 4,8 löpmeter. Omräkningarna visas under måtten med texten *från måtten* och räknas om när måtten ändras.
- **Egna omräkningar.** För förpackning, par, set och egna enheter anger du själv hur många som går på en lagerenhet. En omräkning du skrivit själv gäller före en som räknats fram.

Omräkningarna är exakta bråk, så ingenting avrundas på vägen. En mängd du skriver in, till exempel 2,5 kubikmeter, räknas om till lagerenheten en gång när den matas in och avrundas då till närmaste tusendel av lagerenheten. Blir resultatet noll avvisas raden med texten *Mängden blir noll i lagerenheten*. Blir ändringen av avrundningen större än 0,5 procent visas en varning med båda mängderna, och raden sparas ändå. Det du skrev sparas bredvid, så raden visas alltid som du skrev den. Mängder skrivs in i dag på receptraderna i Kök. Inköpsorder och stycklistor får samma regler när de byggs.

> [!NOTE]
> Ändrar du en omräkning ändras inga rader som redan är sparade. Den gäller nästa gång du anger en mängd.

## Företagets enheter

Under **Register → Artiklar → Enheter** finns alla enheter: styck, meter, löpmeter, kvadratmeter, kubikmeter, kilo, gram, liter, timme, förpackning, par och set, och de enheter ni lagt till själva. Enheter som bara används när man skriver in mängder, som millimeter, centimeter, deciliter och minut, finns också.

**Så här lägger du till en enhet**

1. Tryck på **Enheter** på sidan **Artiklar** och sedan på **Ny enhet**.
2. Skriv **Enhet** som den ska stå, till exempel *rulle* eller *bunt*, högst tio tecken utan mellanslag, och ett **Namn**.
3. Välj **Vad enheten mäter**. Passar ingen väljer du **Annat**.
4. Välj en **Peppol-kod** om enheten ska ha en särskild kod på e-fakturor. Utan kod skickas enheten som ett.
5. Tryck på **Spara**.

Enhetens namn och Peppol-kod kan ändras senare, men inte själva enheten, eftersom artiklarna skriver ut den. En enhet som används raderas inte. Du kan **Arkivera** den, så föreslås den inte längre, och **Använd igen** när du vill ha tillbaka den. Artiklar som redan har den behåller den.

## Bra att veta

- En artikel som har lagerrörelser eller ingår i recept kan inte byta lagerenhet, eftersom saldona är räknade i den gamla enheten. Skapa en ny artikel i den nya enheten.
- Inköpspris, lagervärde och kostnad räknas per lagerenhet.
- Fakturan, offerten och kundportalen visar bara radens egen enhet. Lagerenheten syns på de interna sidorna.
- Enheten på e-fakturan skickas med sin Peppol-kod, och som ett (C62) när koden saknas. Vilken kod som ska gälla för löpmeter är inte avgjort. Kontrollera det mot Peppol-specifikationen innan du skickar sådana fakturor.
- Ett enhetsnamn på en faktura ska enligt mervärdesskattelagen göra det tydligt vilken mängd och vilken sorts vara som levererats. Kontrollera med din redovisningskonsult att enheten du väljer, till exempel *lpm*, räcker.

## Läs också

- [Artiklar](/manual/fakturering/artiklar)
- [Artiklar i lager](/manual/lager/artiklar)
- [Skapa en faktura](/manual/fakturering/skapa-en-faktura)
