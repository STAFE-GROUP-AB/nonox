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

## Så här skriver du en inköpsorder

1. Gå till **Beställningar → Inköpsorder** och klicka på **Ny inköpsorder**.
2. Välj **Leverantör**. Fyll i **Önskat leveransdatum** om du har ett.
3. **Leveransadress** är förifylld med lagrets adress, eller företagets om lagret saknar adress. Ändra den om varorna ska någon annanstans.
4. Lägg till en rad per artikel. Välj **Artikel**, skriv **Antal** och välj **Enhet**. Enheten är förvald till artikelns inköpsenhet.
5. Lämna **À-pris** tomt för att använda artikelns inköpspris, eller skriv leverantörens pris per radens enhet.
6. Klicka på **Spara utkast**.

Bara lagerförda artiklar kan beställas. **Leverantörens art.nr** fylls i från artikeln när leverantören är artikelns leverantör.

### Enheter

Du skriver antalet i den enhet du köper in i, till exempel kubikmeter virke eller förpackningar skruv. Under antalet visar sidan vad det blir i lagerenheten, till exempel **≈ 584,795 lpm**. Det är det talet som lagret ökar med när varorna kommer. Priset gäller radens enhet. Se [Enheter på artiklar](/manual/fakturering/enheter-pa-artiklar).

Blir mängden noll i lagerenheten går raden inte att spara. Ändras mängden mer än en halv procent när den räknas om, visar NONOX en varning med båda talen.

## Så här skickar du ordern

1. Öppna ordern och klicka på **Skicka till leverantören**.
2. Kontrollera PDF:en och **Mottagare**. Mottagaren är förifylld med leverantörens e-postadress.
3. Klicka på **Skicka e-post**. Mejlet går i företagets namn, och svar går till företagets e-postadress.

Vill du skicka ordern på annat sätt skriver du ut PDF:en och klickar på **Utskriven, markera som skickad**. Ordern får status **Skickad** och väntar på varor.

> [!NOTE]
> Har företaget en **Attestgräns för inköpsorder** under **Lager → Inställningar** skickas en order över beloppet bara av ägaren, en administratör eller en attestant. Den som skrev ordern ser en upplysning om det.

## Ändra en skickad order

En skickad order ändras inte. Klicka på **Ny version** i stället. Ordern blir ett utkast med nästa versionsnummer, du ändrar raderna och skickar den igen. Den version som leverantören redan har fått sparas som den var under **Skickade versioner**, med sin PDF. På PDF:en för den nya versionen står att den ersätter tidigare versioner.

En rad där varor redan har tagits emot finns kvar med samma artikel och enhet, och antalet kan inte bli mindre än det som har kommit. Finns det en inleverans som väntar på ordern följer den med den nya versionen: nya rader kommer med, borttagna rader försvinner och antal och pris blir de nya.

## Ta emot varorna

Klicka på **Ta emot varor** på ordern. NONOX gör en inleverans med det som återstår, på artiklarnas vanliga platser och till radens pris omräknat till lagerenheten, och öppnar den under **Lager → Inleveranser**. Du kan också välja ordern under **Ta emot inköpsorder** på sidan Inleveranser. Se [Inleverans](/manual/lager/inleverans).

Kom bara en del står ordern som **Delvis mottagen**. När allt har kommit står den som **Mottagen**.

## Stäm av leverantörsfakturan

När fakturan har registrerats öppnar du den under **Leverantörsfakturor**. Under **Koppla till inköpsorder** väljer du ordern och klickar på **Koppla**. NONOX visar vad som beställdes, vad som har tagits emot och vad som är fakturerat utan moms, och markerar skillnader i antal och pris. Ingenting attesteras automatiskt: du avgör om fakturan stämmer.

Jämförelsen gäller hela ordern. Faktureras en order i flera delar, en faktura per leverans, visar varje faktura en skillnad mot ordern. Kontrollera då att delfakturorna tillsammans stämmer med det som har kommit.

![Leverantörsfakturan med den kopplade inköpsordern och jämförelsen mot det som har tagits emot.](/bilder/manual/lager/inkopsorder-pa-fakturan.webp)

## Makulera

En order där inga varor har tagits emot kan makuleras med **Makulera**. Numret finns kvar. Har ordern skickats behöver du själv meddela leverantören.

## Läs också

- [Inköpsförslag](/manual/lager/inkopsforslag) – låt NONOX föreslå vad som behöver beställas.
- [Inleverans](/manual/lager/inleverans) – ta emot varor.
- [Attest och betalning](/manual/leverantorer/attest) – leverantörsfakturan.
