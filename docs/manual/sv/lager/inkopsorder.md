---
beskrivning: Beställ varor från en leverantör med en inköpsorder, skicka den som PDF, ta emot varorna mot ordern och stäm av leverantörsfakturan.
ordning: 27
roller: [owner, admin, accountant, bookkeeper, approver, auditor]
modul: inventory
rutter: [inventory.purchaseOrders, inventory.purchaseOrders.create, inventory.purchaseOrders.show, inventory.purchaseOrders.edit]
sokord: [inköpsorder, beställning, beställa varor, inköp, leverantörsorder, restorder, orderbekräftelse leverantör, attest inköp]
---

# Inköpsorder

En inköpsorder är det du beställer från en leverantör. Du skriver ordern, ser PDF:en och skickar den med e-post eller skriver ut den. När varorna kommer tar du emot dem mot ordern, och när fakturan kommer jämför NONOX den med det som beställdes och det som kom.

![En inköpsorder som delvis har tagits emot: varje rad i inköpsenheten med lagerenheten under, och hur mycket som har kommit.](/bilder/manual/lager/inkopsorder.webp)

En inköpsorder bokför ingenting. Lagret ökar när varorna tas emot, och kostnaden bokförs som vanligt med leverantörsfakturan. Varorna bokförs alltså bara en gång.

Behöver någon annan än du be om inköpet först kan det börja som en [inköpsanmodan](/manual/lager/inkopsanmodan), som du sedan gör till en order.

## Så här skriver du en inköpsorder

1. Gå till **Beställningar → Inköpsorder** och klicka på **Ny inköpsorder**.
2. Välj **Leverantör**. Fyll i **Önskat leveransdatum** om du har ett.
3. **Leveransadress** är förifylld med lagrets adress, eller företagets om lagret saknar adress. Ändra den om varorna ska någon annanstans.
4. Lägg till en rad per artikel. Välj **Artikel**, skriv **Antal** och välj **Enhet**. Enheten är förvald till artikelns inköpsenhet.
5. Lämna **À-pris** tomt för att använda artikelns inköpspris, eller skriv leverantörens pris per radens enhet.
6. Klicka på **Spara utkast**.

Rader med artikel gäller lagerförda artiklar. **Leverantörens art.nr** fylls i från artikeln när leverantören är artikelns leverantör. Vill du beställa en tjänst eller något som inte läggs i lagret, till exempel hyra av lift eller städning, lägger du en rad utan artikel (se nedan).

### Rader utan artikel

Kryssa i **Utan artikel (tjänst eller förbrukning)** på raden. Då skriver du i stället:

1. **Beskrivning** av det som beställs, till exempel "Hyra av lift, 2 dagar".
2. **Konto**, där kostnaden bokförs när fakturan kommer. Förslagen kommer från företagets kontoplan.
3. **Kostnadsställe** och **Projekt** om kontot kräver dem eller om kostnaden ska följa ett av dem.
4. **Antal**, **Enhet** och **À-pris**. À-priset måste fyllas i, eftersom det inte finns något artikelpris att ta.

En rad utan artikel ger ingen inleverans och påverkar inte lagret. Kontot, kostnadsstället och projektet syns bara för er: leverantörens PDF visar beskrivningen, antalet, enheten och priset.

### Enheter

Du skriver antalet i den enhet du köper in i, till exempel kubikmeter virke eller förpackningar skruv. Under antalet visar sidan vad det blir i lagerenheten, till exempel **≈ 584,795 lpm**. Det är det talet som lagret ökar med när varorna kommer. Priset gäller radens enhet. Se [Enheter på artiklar](/manual/fakturering/enheter-pa-artiklar).

Blir mängden noll i lagerenheten går raden inte att spara. Ändras mängden mer än en halv procent när den räknas om, visar NONOX en varning med båda talen.

## Så här skickar du ordern

1. Öppna ordern och klicka på **Skicka till leverantören**.
2. Kontrollera PDF:en och **Mottagare**. Mottagaren är förifylld med leverantörens e-postadress.
3. Klicka på **Skicka e-post**. Mejlet går i företagets namn, och svar går till företagets e-postadress.

Vill du skicka ordern på annat sätt skriver du ut PDF:en och klickar på **Utskriven, markera som skickad**. Ordern får status **Skickad** och väntar på varor.

> [!NOTE]
> Har företaget en **Attestgräns för inköpsorder** under **Lager → Inställningar** skickas en order över beloppet bara av ägaren, en administratör eller en attestant. Den som skrev ordern ser en upplysning om det. Kräver företaget att någon annan attesterar (se [Attest](/manual/leverantorer/attest)) kan den som skapade en order över gränsen inte skicka den själv.

## Attest av inköpsorder

Utan att du gör något gäller attestgränsen ovan: det är **standardattesten** för inköpsorder. Behöver ni mer, till exempel att en order från 10 000 kr ska godkännas av en person innan den får skickas, lägger en ägare eller administratör upp ett flöde under **Inköp → Attestflöden** med **Nytt flöde för inköpsorder**. Se [Attestflöden](/manual/leverantorer/attestfloden). Flödet syns bara när Lager är påslaget.

Gäller ett flöde en order visar ordern kortet **Attestflöde** med stegen och vem som har godkänt. Den som får godkänna nuvarande steg ser knappen **Attestera ordern**, eller **Godkänn steg 1 av 2** när flödet har flera steg. Knappen **Skicka till leverantören** visas först när sista steget är godkänt.

![En inköpsorder som väntar på attest i ett flöde för order från 10 000 kr, med kortet Attestflöde och knappen Attestera ordern.](/bilder/manual/lager/inkopsorder-attest.webp)

- Den som skapade ordern kan inte attestera den när företaget kräver att någon annan attesterar.
- Stämmer något inte klickar du på **Avvisa**, skriver gärna ett skäl och klickar på **Ja, avvisa**. Ordern går tillbaka till den som skapade den, som kan ändra den eller klicka på **Skicka till attest igen**.
- Sparar du ordern igen efter att den är attesterad, eller gör en ny version av en skickad order, måste ordern attesteras på nytt. Attesten av en version som redan har skickats finns kvar på den versionen under **Skickade versioner**, med namn och datum.
- En order som ett flöde inte gäller, till exempel för att beloppet är för litet, följer standardattesten.

Attest av en inköpsorder bokför ingenting.

## Ändra en skickad order

En skickad order ändras inte. Klicka på **Ny version** i stället. Ordern blir ett utkast med nästa versionsnummer, du ändrar raderna och skickar den igen. Den version som leverantören redan har fått sparas som den var under **Skickade versioner**, med sin PDF. På PDF:en för den nya versionen står att den ersätter tidigare versioner.

En rad där varor redan har tagits emot finns kvar med samma artikel och enhet, och antalet kan inte bli mindre än det som har kommit. Finns det en inleverans som väntar på ordern följer den med den nya versionen: nya rader kommer med, borttagna rader försvinner och antal och pris blir de nya.

## Ta emot varorna

Klicka på **Ta emot varor** på ordern. NONOX gör en inleverans med det som återstår, på artiklarnas vanliga platser och till radens pris omräknat till lagerenheten, och öppnar den under **Lager → Inleveranser**. Du kan också välja ordern under **Ta emot inköpsorder** på sidan Inleveranser. Se [Inleverans](/manual/lager/inleverans).

Kom bara en del står ordern som **Delvis mottagen**. När allt har kommit står den som **Mottagen**.

### Bekräfta leverans av en rad utan artikel

En rad utan artikel tas inte emot i en inleverans. När tjänsten är utförd eller varan har kommit klickar du på **Bekräfta leverans** på raden. Ingenting läggs in i lagret och ingenting bokförs. Är alla rader tagna emot eller bekräftade står ordern som **Mottagen**. Klickade du fel tar du bort bekräftelsen med **Ta bort bekräftelsen**.

![En skickad order med en lagerrad och en rad utan artikel, där leveransen av den senare ännu inte är bekräftad.](/bilder/manual/lager/inkopsorder-utan-artikel.webp)

I jämförelsen med leverantörsfakturan räknas en bekräftad rad utan artikel som levererad till hela sitt värde, och en rad som inte är bekräftad markeras.

## Stäm av leverantörsfakturan

När fakturan har registrerats öppnar du den under **Leverantörsfakturor**. Under **Koppla till inköpsorder** väljer du ordern och klickar på **Koppla**. NONOX visar vad som beställdes, vad som har tagits emot och vad som är fakturerat utan moms, och markerar skillnader i antal och pris. Ingenting attesteras automatiskt: du avgör om fakturan stämmer.

Jämförelsen gäller hela ordern. Faktureras en order i flera delar, en faktura per leverans, visar varje faktura en skillnad mot ordern. Kontrollera då att delfakturorna tillsammans stämmer med det som har kommit.

![Leverantörsfakturan med den kopplade inköpsordern och jämförelsen mot det som har tagits emot.](/bilder/manual/lager/inkopsorder-pa-fakturan.webp)

## Makulera

En order där inga varor har tagits emot och ingen leverans är bekräftad kan makuleras med **Makulera**. Numret finns kvar. Har ordern skickats behöver du själv meddela leverantören.

## Läs också

- [Inköpsförslag](/manual/lager/inkopsforslag) – låt NONOX föreslå vad som behöver beställas.
- [Inleverans](/manual/lager/inleverans) – ta emot varor.
- [Attestflöden](/manual/leverantorer/attestfloden) – lägg upp ett eget flöde för inköpsorder.
- [Attest och betalning](/manual/leverantorer/attest) – leverantörsfakturan.
