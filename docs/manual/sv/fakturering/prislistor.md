---
beskrivning: Gör prislistor för kundgrupper och andra valutor, kampanjer med start och slut, och listor som följer grundpriset med en procent, med antalstrappor och datum.
ordning: 25
roller: [owner, admin, accountant]
rutter: [pricing.lists, pricing.lists.show, pricing.groups]
sokord: [prislista, prislistor, kampanj, kampanjpris, återförsäljare, kundgrupp, mängdrabatt, antalsrabatt, prishöjning, kundpris, valuta, rabatt]
---

# Prislistor

Utan prislistor har varje artikel ett pris som gäller alla. En prislista låter dig ta ett annat pris av vissa kunder, i en annan valuta eller under en kampanj, utan att ändra artikeln. Du kan också låta en lista följa grundpriset minus en procent, så behöver du inte skriva in ett enda pris för hand.

Prislistorna finns under **Register → Prislistor**. Du som bara ska se priserna behöver rollen ägare, administratör, redovisningskonsult, bokförare eller revisor; för att göra och ändra listor behövs ägare, administratör eller redovisningskonsult.

![Prislistorna med typ, valuta, giltighet och antal priser.](/bilder/manual/fakturering/prislistor.webp)

## Så här gör du en prislista

1. Gå till **Register → Prislistor** och tryck på **Ny prislista**.
2. Skriv en **Kod**, till exempel *BYGG*, och ett **Namn**, till exempel *Byggföretag*. Koden är kort och kan inte användas två gånger.
3. Välj **Typ**: **Prislista** för en vanlig lista, **Kampanj** för ett pris som gäller en tid, eller **Kanalprislista** för kassan eller kundportalen. Kassan använder den kanallista du väljer vid kopplingen under **Kassa** (se [Kassa: Zettle](/manual/lager/kassa-zettle)), och etiketterna visar kassans pris. Att kundportalen använder kanallistorna kommer i ett senare steg.
4. Välj **Valuta** och om **Priserna anges** exklusive eller inklusive moms.
5. Fyll i **Gäller från** och **Gäller till och med** om listan bara ska gälla en tid. För en kampanj måste du fylla i båda.
6. Tryck på **Spara**. Öppna sedan listan genom att trycka på dess namn, så kan du lägga in priserna.

Valutan och momsgrunden går inte att ändra när listan har fått priser, för då skulle priserna betyda något annat. Gör i så fall en ny lista.

## Låt listan följa grundpriset

Kryssa i **Låt listan följa ett annat pris** och välj vad den ska följa: **Grundpriset på artikeln** eller en annan prislista med samma valuta. Skriv sedan **Procent**: *−10* ger 10 % lägre pris, *5* ger 5 % högre. Med **Avrundning** väljer du hur det uträknade priset ska hamna: hela kronor, hela och halva kronor, hela tiotal kronor, eller så att det slutar på ,90 eller på 9.

En sådan lista följer med varje gång grundpriset ändras. Ett pris du lägger in i listan för en viss artikel går före regeln för just den artikeln. Avrundningen gäller bara det uträknade priset, aldrig ett pris du har skrivit in själv, och ett pris över noll blir aldrig noll.

## Lägg in priserna

Tryck på namnet på listan, eller på **Priser**, för att öppna den. Tryck på **Nytt pris**.

1. Sök artikeln på nummer eller benämning och välj den.
2. Välj **Enhet**. Lämnar du den tom gäller artikelns egen försäljningsenhet. De andra enheterna är de som går att räkna om till artikelns enhet.
3. Skriv **Från antal** om priset ska gälla från ett visst antal. Lämnar du fältet tomt gäller priset från första enheten.
4. Välj om du vill ange ett **Pris** i listans valuta eller en **Rabatt på ordinarie pris**, och skriv beloppet eller procenten. Ordinarie pris är standardlistans pris för valutan, annars artikelns grundpris; en lista som följer en annan lista tar den listans pris.
5. Fyll i **Gäller från** och **Gäller till och med** om priset bara ska gälla en tid.
6. Tryck på **Spara**.

![Priserna i en lista, med antalstrappa och grundpris bredvid.](/bilder/manual/fakturering/prislista-priser.webp)

**Antalstrappa.** Lägg in flera rader för samma artikel med olika **Från antal**, till exempel 595 kr, 560 kr från 40 och 530 kr från 100. Hela antalet får priset för det högsta steget det når: 120 timmar kostar 530 kr per timme för alla 120, inte bara för de sista 20.

**Prishöjning.** Ett pris som har börjat gälla går inte att ändra, för då skulle historiken skrivas om. Tryck i stället på **Nytt pris från datum** på ett pris som gäller nu. Skriv det nya priset och dagen det börjar gälla. NONOX låter det gamla priset gälla till och med dagen före, så båda finns kvar i listan och du kan se vad varan kostade en viss dag. Det nya priset måste börja i dag eller senare, för ett pris som redan har börjat gälla kan inte avslutas bakåt i tiden.

Två priser för samma artikel, enhet och antal får inte gälla samma dag. Försöker du spara ett som överlappar får du veta vilket pris det krockar med.

**Många priser på en gång.** Ska alla priser i en lista upp med 4 % från nyår använder du [Prisuppdatering](/manual/fakturering/prisuppdatering), som visar varje nytt pris innan något sparas. Under **Exportera:** i listan laddar du ner priserna som **CSV** eller **Excel**, i samma format som prisuppdateringen läser in.

### Ta bort och avsluta priser

Det finns ingen knapp som tar bort ett pris som har använts, eftersom du ska kunna se vad det kostade. I stället gäller:

- Ett pris som inte har börjat gälla kan du **Ändra** eller **Ta bort**.
- Ett pris som gäller nu kan du **Avsluta**. Det gäller till och med i går och finns kvar i historiken. Började det gälla i dag arkiveras det i stället.
- Ett pris som har tagit slut eller arkiverats går inte att ändra. Det syns under **Avslutade** och **Alla**, och på artikelns flik Priser.

Över listan väljer du vilka priser du ser: **Gäller nu och kommande**, **Bara kommande**, **Avslutade** eller **Alla**.

## Kampanjer

En kampanj är en prislista av typen **Kampanj** med ett startdatum och ett slutdatum. Under **Kampanjen gäller för** väljer du kundgrupper, eller inga alls om den ska gälla alla kunder. Vid prissättningen vinner det lägsta priset av kundens lista och kampanjen, så en kund får aldrig ett sämre pris än kampanjens.

En kampanj för alla kunder gäller också i kassan och på prisetiketterna, om ni har en kassa kopplad. Ska kampanjen inte nå konsumenter i butiken, rikta den till en kundgrupp. Säljer ni till konsumenter och visar kampanjpriset som en prissänkning ansvarar ni själva för att ange det tidigare priset enligt reglerna om prisinformation.

I listan över prislistor syns kampanjens läge: **Pågår**, **Kommande** eller **Avslutad**.

## Standardlista för valutan

Kryssa i **Standardlista för valutan** på en vanlig prislista när den ska gälla kunder som inte har någon egen lista, i listans valuta. Det kan bara finnas en standardlista per valuta. Väljer du en ny tas den förra bort som standard. Utan standardlista för kronor gäller artikelns grundpris.

## Kundgrupper

En kundgrupp samlar kunder som ska ha samma priser, till exempel *Återförsäljare*. Under **Register → Prislistor → Kundgrupper** lägger du till en grupp med kod, namn och den prislista gruppens kunder ska få.

![Kundgrupperna med prislista och antal kunder.](/bilder/manual/fakturering/prislistor-kundgrupper.webp)

En grupp som har kunder, eller som en kampanj riktas mot, går inte att arkivera. Flytta kunderna och ändra kampanjerna först, annars skulle de få andra priser utan att någon har valt det.

## Kundens flik Priser

Öppna en kund under **Register → Kunder** och tryck på fliken **Priser**. Där väljer du kundens **Kundgrupp** och en **Egen prislista**. Kundens egen lista går före gruppens. Under **Kundens pris idag** ser du vad varje artikel kostar för just den kunden i dag, vid antal 1, och varifrån priset kommer: kundens prislista, en kampanj, valutans lista eller grundpriset. Är ett pris omräknat från kronor, eller saknas det, står det på raden.

![Kundens flik Priser med grupp, egen lista och dagens pris per artikel.](/bilder/manual/fakturering/prislistor-kund.webp)

## Artikelns flik Priser

Öppna en artikel under **Register → Artiklar** och tryck på fliken **Priser**. Där ser du grundpriset och dess historik, alla listor och kampanjer som prissätter artikeln, nu och framöver, och under **Pris på en dag** vad en kund utan egen lista får ett visst datum och antal.

![Artikelns flik Priser med grundprisets historik och var artikeln prissätts.](/bilder/manual/fakturering/prislistor-artikel.webp)

## Så väljs priset

För en kund väljer NONOX priset i den här ordningen, och det första som passar vinner:

1. Kundens egen prislista, annars kundgruppens, om de gäller den dagen och i rätt valuta. Prissätter den egna listan inte artikeln används gruppens lista.
2. En kampanj som gäller dagen, för alla eller för kundens grupp. Det lägsta priset av listan och kampanjen vinner.
3. Standardlistan för valutan, eller för kronor artikelns grundpris.

Saknas priset i kundens valuta räknas grundpriset om med Riksbankens kurs för dagen, och raden får en varning om att priset är omräknat.

> [!NOTE]
> Fakturor, offerter och order fyller i kundens pris ur listorna när du väljer en artikel, och visar varifrån priset kommer. Läs [Priser på fakturor, offerter och order](/manual/fakturering/priser-pa-raderna). Ett pris på en faktura som redan är utfärdad ändras aldrig av att en prislista ändras.

## Lägsta täckningsgrad

Längst ned på sidan **Prislistor** finns kortet **Lägsta täckningsgrad**. Skriv en procent, till exempel 15, i **Lägsta täckningsgrad (%)** och välj vad som händer under gränsen i **Under täckningsgraden**:

- **Varna bara** visar en varning på raden.
- **Kräv godkännande innan dokumentet skickas** gäller priser och rabatter som skrivs för hand på fakturor, offerter och order. Någon med behörigheten att godkänna priser under lägsta täckningsgrad måste godkänna dem innan dokumentet kan skickas eller skapas. Priser från prislistor och prisavtal varnar bara.

Procenten kan vara från 0,01 till 99. Lämna fältet tomt för att stänga av lägsta täckningsgrad, som den är från början. Att ändra den kräver behörigheten att hantera prislistor. Läs [Priser på fakturor, offerter och order](/manual/fakturering/priser-pa-raderna) för hur det ser ut när du skriver en faktura.

![Kortet Lägsta täckningsgrad längst ned på Prislistor.](/bilder/manual/fakturering/lagsta-tackningsgrad.webp)

## Bra att veta

- Prislistor bokför ingenting. Ett pris når bokföringen först när det står på en faktura som utfärdas.
- Arkivera en lista du inte längre använder med **Arkivera**. En lista som kunder, kundgrupper eller andra listor fortfarande använder arkiveras inte; du får veta vad som ska bytas först. Under **Visa arkiverade** kan du **Återställa** den.
- Priser i en lista inklusive moms är till för konsumentpriser. Den rättsliga bedömningen av hur ett konsumentpris ska visas på faktura och kvitto är inte klar, så kontrollera den med din redovisningskonsult.

## Vanliga frågor

**Måste jag ha prislistor?**
Nej. Utan dem gäller artikelns pris för alla, precis som förut.

**Kan jag ha olika priser i kronor och euro?**
Ja. Gör en lista i euro och låt den vara standardlista för euro. En kund som fakturerar i euro får då de priserna.

**Vad händer med ett pris som redan finns på en faktura när jag ändrar listan?**
Ingenting. Ett pris på en faktura är en kopia och ändras inte i efterhand.

## Läs också

- [Artiklar](/manual/fakturering/artiklar)
- [Kunder](/manual/fakturering/kunder)
- [Faktura i utländsk valuta](/manual/fakturering/faktura-i-utlandsk-valuta)
- [Enheter på artiklar](/manual/fakturering/enheter-pa-artiklar)
