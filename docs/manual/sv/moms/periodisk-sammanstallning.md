---
beskrivning: Ta fram den periodiska sammanställningen över försäljning till företag i andra EU-länder och ladda ner filen till Skatteverkets Filöverföring.
ordning: 50
roller: [owner, admin, accountant]
rutter: [vat.euReports]
sokord: [periodisk sammanställning, eu-försäljning, kvartalsrapport eu, skv 5740, filöverföring, trepartshandel, ruta 35, ruta 39, eu sales list, kvartal, 500 000 kr, leveransdatum]
---

# Periodisk sammanställning

Säljer företaget varor eller tjänster utan moms till företag i andra EU-länder ska det lämna en periodisk sammanställning till Skatteverket: vad varje köpare har köpt, per momsregistreringsnummer. NONOX räknar fram den ur fakturorna och gör filen åt dig.

Sidan finns under **Bokföring → EU-rapporter**.

![Den periodiska sammanställningen med köparens momsregistreringsnummer och beloppen.](/bilder/manual/moms/periodisk-sammanstallning.webp)

## Så här gör du

1. Gå till **Bokföring → EU-rapporter**.
2. Under **Periodisk sammanställning**, välj **Period**: en månad eller ett kvartal. Föregående månad är förvald. Läs **Månad eller kvartal** nedan.
3. Granska tabellen: **Köparens momsreg.nr**, **Varor**, **Trepartshandel**, **Tjänster** och **Summa, kronor**.
4. Jämför summorna med rutorna 35, 38 och 39 i momsdeklarationen för samma period.
5. Fyll i **Ansvarig person**, **Telefon** och, om du vill, **E-post (frivillig)**.
6. Klicka på **Ladda ner fil**.
7. Lämna filen i Skatteverkets e-tjänst Filöverföring.

Sidan säger när sammanställningen ska vara inne. Kontrollera datumet hos Skatteverket om du är osäker.

## Månad eller kvartal

- **Varor** redovisas per månad. Det gäller också trepartshandel.
- **Tjänster** redovisas per kvartal.
- Säljer företaget både varor och tjänster redovisas allt per månad.

Varor får redovisas per kvartal bara om Skatteverket har beslutat det. Beslutet gäller så länge varorna är värda högst 500 000 kronor per kvartal, både i det aktuella kvartalet och i vart och ett av de fyra kvartalen före.

Har företaget ett sådant beslut, bocka i **Skatteverket har beslutat att periodisk sammanställning för varor får lämnas per kvartal** under [Företagets uppgifter](/manual/installningar/foretagets-uppgifter) och fyll i **Gäller från och med**. Beslutet gäller från början av kvartalet efter det att det fattades.

Väljer du ett kvartal med varor utan beslut, eller när varorna har varit värda mer än 500 000 kronor i något av kvartalen, visar sidan en varning och filen går inte att ladda ner. Välj en månad i stället.

Har företaget bara tjänster och du väljer en månad visar sidan en påminnelse: tjänster lämnas per kvartal om inte Skatteverket har beslutat att företaget ska lämna per månad.

## Vad som räknas med

- Fakturor där **Försäljning utomlands** är **Varor till företag i annat EU-land**, **Tjänster till företag i annat EU-land** eller **Trepartshandel inom EU, som mellanman**.
- Bara fakturor som är skapade, inte utkast. Kreditfakturor minskar beloppen.
- Varor och trepartshandel räknas till perioden för fakturadatumet.
- Tjänster räknas till perioden när de utfördes: fakturans **Leveransdatum** om du har fyllt i det, annars fakturadatumet. En tjänst som utfördes i mars men fakturerades i april hamnar alltså i mars.
- En kreditfaktura räknas till perioden för sitt eget fakturadatum.
- Beloppen är i hela kronor.
- En försäljning du har bokfört för hand med en verifikation, till exempel på 3108 eller 3308, kommer inte med – den har inget momsregistreringsnummer för köparen. Lägg till den i Skatteverkets e-tjänst.

Vilken typ av försäljning en faktura är väljer du på kunden eller fakturan. Läs [Kunder](/manual/fakturering/kunder) och [Skapa en faktura](/manual/fakturering/skapa-en-faktura).

För att en faktura ska bli momsfri EU-försäljning krävs att kunden har ett annat EU-land än Sverige och ett momsregistreringsnummer från det landet, och att ditt företag har ett eget. Var den senaste [VIES-kontrollen](/manual/moms/vies) av kundens nummer ogiltig går fakturan inte att skapa.

## Om knappen inte går att använda

Står det **Fyll i företagets momsregistreringsnummer under Företagsuppgifter för att kunna skapa filen.** saknar företaget ett momsregistreringsnummer i rätt form: SE och tolv siffror. I filen skrivs numret som tolv siffror utan SE, som i Skatteverkets exempel. Det bildas av organisationsnumret när du väljer en momsperiod under **Momsredovisning**. Läs [Momsperiod och bokföringsmetod](/manual/moms/momsperiod-och-bokforingsmetod).

## Bra att veta

- Varje nedladdning av filen skrivs i behandlingshistoriken.
- Sidan visar de senaste 18 månaderna och 6 kvartalen.

> [!NOTE]
> Reglerna om period och kvartal bygger på en granskning av skatteförfarandelagen och mervärdesskattelagen den 2 november 2026. Juristgranskning återstår. Fråga din redovisningskonsult om en tjänst pågår över flera perioder eller betalas i förskott, eller om varor faktureras mer än en månad efter leveransen.

## Läs också

- [OSS – försäljning till privatpersoner i andra EU-länder](/manual/moms/oss)
- [Kontrollera momsnummer i VIES](/manual/moms/vies)
- [Momsdeklarationen](/manual/moms/momsdeklaration)
