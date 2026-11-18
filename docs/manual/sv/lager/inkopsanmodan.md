---
beskrivning: Be om varor och tjänster med en inköpsanmodan, låt en attestant godkänna den och låt en inköpare lägga de godkända raderna på en inköpsorder.
ordning: 26
roller: [owner, admin, accountant, bookkeeper, approver, auditor, stock_clerk]
modul: inventory
rutter: [inventory.requisitions, inventory.requisitions.create, inventory.requisitions.show, inventory.requisitions.edit, inventory.requisitions.convert]
sokord: [inköpsanmodan, inköpsbegäran, rekvisition, beställningsunderlag, önskemål om inköp, be om inköp, godkänna inköp, attest inköp]
---

# Inköpsanmodan

En inköpsanmodan är en förfrågan om att företaget ska köpa något. Du skriver vad du behöver, en attestant godkänner det, och en inköpare gör de godkända raderna till en inköpsorder. Då slipper den som beställer veta vem leverantören är, och den som betalar får se vad som ska köpas innan det är beställt.

![En inköpsanmodan som väntar på attest, med rader både med artikel och i fritext.](/bilder/manual/lager/inkopsanmodan.webp)

En inköpsanmodan är en förfrågan och inte en beställning. Den bokför ingenting och binder inte företaget till något. Kostnaden bokförs som vanligt med leverantörsfakturan.

## Så här skriver du en inköpsanmodan

1. Gå till **Beställningar → Inköpsanmodan** och klicka på **Ny inköpsanmodan**.
2. Fyll i **Behövs senast** och **Skäl**. Skriv vad som behövs och varför, men inga uppgifter om personer.
3. Lägg till en rad per sak. Välj en **Artikel**, eller lämna den tom och skriv en **Beskrivning** med egna ord.
4. Skriv **Antal** och välj **Enhet**. **Estimerat à-pris** får du lämna tomt om du inte vet.
5. Det som inte är en lagerförd artikel behöver ett **Konto**, så att kostnaden hamnar rätt när fakturan kommer. Du kan också ange **Kostnadsställe**, **Projekt** och **Önskad leverantör**.
6. Klicka på **Spara utkast** och sedan på **Skicka till attest** när anmodan är klar.

Anmodan får status **Utkast** tills du skickar den. Så länge den är ett utkast, eller har blivit avvisad, kan du ändra den med **Ändra**.

## Så här attesterar du en inköpsanmodan

1. Öppna anmodan under **Beställningar → Inköpsanmodan**. De som väntar på attest har status **Väntar på attest**.
2. Kontrollera raderna, beloppet och skälet.
3. Klicka på **Attestera anmodan**.

Stämmer något inte klickar du på **Avvisa**, skriver gärna ett skäl och klickar på **Ja, avvisa**. Anmodan går då tillbaka till den som skrev den, som kan **Ändra** den eller klicka på **Skicka till attest igen**. Skälet syns på anmodan.

Utan att du gör något attesteras en anmodan av en person med rätt att skicka inköpsorder, det vill säga rollen **Ägare**, **Administratör** eller **Attestant**. Kräver företaget att någon annan attesterar (se [Attest](/manual/leverantorer/attest)) kan den som skrev eller senast ändrade anmodan inte attestera den själv. Är du ensam i företaget kan ägaren stänga av kravet under **Inköp → Leverantörsfakturor**, i rutan **Attest**, med knappen **Tillåt att samma person registrerar och attesterar (för företag med en person)**.

Behöver ni mer, till exempel att en anmodan över ett visst belopp ska godkännas av två personer, lägger en ägare eller administratör upp ett flöde under **Inköp → Attestflöden** med **Nytt flöde för inköpsanmodan**. Beloppet i villkoren är den uppskattade summan exkl. moms. Har någon rad inget pris vet ingen vad anmodan kostar: då anses en nedre gräns (**Belopp från och med**) uppnådd, så anmodan går till attest, och en övre gräns (**Belopp till och med**) anses inte uppfylld. Utan eget flöde ändras inget. Se [Attestflöden](/manual/leverantorer/attestfloden).

> [!IMPORTANT]
> Din attest gäller den version av anmodan som du hade öppen. Tar den som skrev anmodan tillbaka den och ändrar den medan du läser, får du ett meddelande när du försöker attestera, och du behöver öppna den på nytt. Det gör att ingen attesterar något annat än det hen har sett.

## Så här tar du tillbaka eller makulerar en anmodan

- Väntar anmodan på attest kan du klicka på **Ta tillbaka**. Den blir ett utkast som du kan ändra och skicka igen, och ingen kan då attestera den som den var.
- Klicka på **Makulera** om anmodan inte behövs längre. Numret finns kvar, men anmodan gäller inte. Har någon rad redan blivit en inköpsorder går den inte att makulera.

## Så här gör du en inköpsorder av godkända anmodan

Det här gör en inköpare, det vill säga någon som får skriva inköpsorder.

1. Gå till **Beställningar → Inköpsanmodan** och klicka på **Beställ**. Siffran visar hur många rader som väntar.
2. Välj **Leverantör**. Du ser de godkända raderna som inte har beställts. Rader som är avsedda för en annan leverantör visas inte.
3. Kryssa i raderna som ska beställas, eller klicka på **Välj alla**. Rader från flera anmodan kan gå på samma order.
4. För en rad som inte är en lagerartikel kan du fylla i **Konto** och **à-pris** om anmodan saknar dem. Lämnar du **à-pris** tomt för en lagerartikel används artikelns inköpspris.
5. Klicka på **Skapa inköpsorder av valda rader**.

![Beställ-sidan med godkända rader att lägga på en ny inköpsorder till en leverantör.](/bilder/manual/lager/inkopsanmodan-bestall.webp)

Raderna blir en ny inköpsorder som utkast, med dig som referens, och anmodan visar vilken order varje rad blev. Ordern går sedan sin vanliga väg: den attesteras om företaget har en attestgräns eller ett eget flöde, och skickas till leverantören. Se [Inköpsorder](/manual/lager/inkopsorder). En anmodan blir **Omvandlad till order** när alla dess rader är beställda.

Tas en rad bort från ordern medan den är ett utkast, eller makuleras ordern, blir raden i anmodan ledig igen och kan beställas på nytt. Det gäller också en order som redan har skickats: makuleras den (vilket kräver att inget har tagits emot) blir raderna lediga, och du behöver själv meddela leverantören.

## Vem får göra vad

| Roll | Skriva egna anmodan | Se alla anmodan | Attestera | Beställa |
|---|---|---|---|---|
| **Ägare** | Ja | Ja | Ja | Ja |
| **Administratör** | Ja | Ja | Ja | Ja |
| **Redovisningskonsult** | Nej | Ja | Nej | Nej |
| **Bokförare** | Ja | Ja | Nej | Ja |
| **Attestant** | Nej | Ja | Ja | Nej |
| **Revisor** | Nej | Ja | Nej | Nej |
| **Lagerpersonal** | Ja | Nej | Nej | Nej |

Den som skriver en anmodan ser alltid sin egen. En bokförare kan också ändra, ta tillbaka och makulera andras anmodan, och blir då den som senast ändrade den.

## Bra att veta

- Namnet på den som skrev anmodan sparas så länge anmodan finns, och syns för alla som får se anmodan. Skriv därför inga personuppgifter i **Skäl** eller **Beskrivning**.
- Behandlingshistoriken visar nummer, antal rader och vem som gjorde vad, aldrig texten du skrev.
- Företaget avgör själv hur länge en anmodan sparas. NONOX tar inte bort några automatiskt. Kontrollera med din revisor om anmodan räknas som räkenskapsinformation.

## Läs också

- [Inköpsorder](/manual/lager/inkopsorder)
- [Attestflöden](/manual/leverantorer/attestfloden)
- [Roller och behörigheter](/manual/lager/roller-och-behorigheter)
