---
beskrivning: Stäm av varje rad på en leverantörsfaktura mot inköpsordern och det som har tagits emot, med toleranser och godkännande av avvikelser före attesten.
ordning: 28
roller: [owner, admin, accountant, bookkeeper, approver, auditor]
modul: inventory
rutter: [inventory.settings, payables.invoices.show]
sokord: [trevägsmatchning, avstämning inköp, matchning faktura order, tolerans, prisavvikelse, antalsavvikelse, three-way match, fakturakontroll, attest inköpsorder]
---

# Avstämning mot inköpsorder

När en leverantörsfaktura är kopplad till en inköpsorder stämmer NONOX av fakturan rad för rad: det som beställdes, det som har tagits emot och det som fakturan tar betalt för. Varje rad får ett resultat mot företagets toleranser, och resultatet sparas. Avstämningen bokför ingenting och attesterar ingenting. Den visar var fakturan avviker, så att den som attesterar vet vad hen godkänner.

![Leverantörsfakturan med avstämningen per rad: speglarna är fakturerade till ett högre pris än i ordern, och frakten ligger inom beloppsgränsen.](/bilder/manual/lager/avstamning-per-rad.webp)

## Så här stämmer du av en faktura

1. Registrera fakturan under **Leverantörsfakturor** och öppna den.
2. Under **Inköpsorder** väljer du ordern i **Koppla till inköpsorder** och klickar på **Koppla**. Se [Inköpsorder](/manual/lager/inkopsorder).
3. Under **Fördela fakturans rader** väljer du för varje fakturarad vilken **Orderrad** den betalar, och skriver **Antal** i orderradens enhet. Lämnar du antalet tomt används det mottagna antalet, eller det beställda om ingenting har tagits emot.
4. Klicka på **Spara fördelningen**. Resultatet syns under **Avstämning per rad**.

**Föreslå efter belopp** fyller i de rader vars belopp är exakt lika med värdet av det som har tagits emot, eller av det som beställdes, på en enda orderrad. Kontrollera förslaget innan du går vidare.

Rader som frakt och öresavrundning behöver ingen orderrad. Låt **Orderrad** stå på **Ingen orderrad**.

## Vad avstämningen jämför

För varje orderrad som fakturarader är fördelade på visar NONOX:

- **Beställt**: antalet och värdet på orderraden.
- **Mottaget**: det som har tagits emot i inleveranser. En rad utan artikel räknas som mottagen när leveransen är bekräftad. Har andra fakturor redan tagit betalt för raden står det som **Fakturerat tidigare**.
- **Fakturerat**: antalet du angav och beloppet på fakturaraderna.
- **Avvikelse**: hur många fler än som finns kvar att fakturera fakturan tar betalt för, och hur mycket högre beloppet är än orderpriset för det fakturerade antalet.

Raden står som **Utanför toleransen** när fakturan tar betalt för fler än som har tagits emot, när priset är högre än toleransen tillåter, när ingenting har tagits emot eller bekräftats, eller när ordern är makulerad. En fakturarad utan orderrad står som **Utan orderrad**, om inte alla sådana rader tillsammans ryms inom beloppsgränsen. Då står de som **Inom toleransen**. En faktura som tar betalt för mindre, eller till ett lägre pris, visas men godkänns.

Avstämningen görs om när du kopplar eller tar bort en order, sparar fördelningen, öppnar fakturan och när någon attesterar. Ändras ingenting sparas ingen ny avstämning. När fakturan är attesterad ändras avstämningen inte längre, och det som stod när den attesterades finns kvar.

## Toleranser

Toleranserna ställer du in under **Lager → Inställningar**, under **Avstämning mot inköpsorder**:

- **Tolerans för antal (%)**: hur många procent fler än som har tagits emot fakturan får ta betalt för. Från början 0 %.
- **Tolerans för pris (%)** och **Tolerans i kronor**: hur mycket högre än orderpriset beloppet får vara. Det som är störst av procenten och beloppet gäller. Från början 2 % eller 50 kr.
- **Stoppa attesten vid avvikelser utanför toleranserna**: från början urkryssad.

Inställningarna styr attesten, så bara ägare och administratörer kan ändra dem.

![Toleranserna för avstämningen under Lager → Inställningar.](/bilder/manual/lager/avstamning-installningar.webp)

## Attesten och avvikelser

Är rutan urkryssad visar attestkortet att fakturan avviker, men fakturan kan attesteras ändå. Att den attesterades med avvikelser skrivs i behandlingshistoriken.

Är rutan ikryssad kan fakturan attesteras först när varje avvikelse är godkänd:

1. Klicka på **Godkänn avvikelsen** på raden.
2. Skriv i **Skäl till att avvikelsen godkänns** varför raden kan betalas, till exempel att priset är höjt enligt avtal. Skriv inte uppgifter om personer.
3. Klicka på **Godkänn avvikelsen**. Raden står som **Godkänd avvikelse** med ditt namn och skälet.

Bara den som får attestera fakturan just nu kan godkänna en avvikelse: den som har rätt att attestera, eller i ett eget attestflöde den som får besluta i det aktuella steget. Den som inte får attestera kan inte heller ta bort kopplingen till ordern medan fakturan avviker. Kräver företaget att någon annan attesterar (se [Attest och betalning](/manual/leverantorer/attest)) kan varken den som registrerade fakturan eller den som fördelade raderna godkänna avvikelser eller attestera fakturan. Det gäller också i ett eget [attestflöde](/manual/leverantorer/attestfloden), i varje steg.

> [!NOTE]
> Ett godkännande gäller den avstämning du såg. Kommer det in fler varor eller ändras fördelningen görs en ny avstämning, och en avvikelse som fortfarande finns behöver godkännas igen. Ändras avstämningen medan du har fakturan öppen ber NONOX dig ladda om sidan innan du attesterar.

## Läs också

- [Inköpsorder](/manual/lager/inkopsorder) – beställ och koppla ordern till fakturan.
- [Inleverans](/manual/lager/inleverans) – ta emot varor.
- [Attest och betalning](/manual/leverantorer/attest) – attestera leverantörsfakturan.
