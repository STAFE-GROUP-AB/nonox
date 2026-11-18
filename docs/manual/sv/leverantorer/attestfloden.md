---
beskrivning: Bestäm vem som attesterar leverantörsfakturor, betalfiler, inköpsorder, verifikationer och kreditfakturor, i flera steg, från ett belopp eller per kostnadsställe, och välj ersättare.
ordning: 45
roller: [owner, admin]
rutter: [approvals.policies]
sokord: [attestflöde, attestordning, godkännandeflöde, flerstegsattest, beloppsgräns, attesträtt, ersättare, delegering, på uppdrag av, kostnadsställeansvarig]
---

# Attestflöden

Utan att du gör något attesteras en leverantörsfaktura av en person med rätt att attestera, och en betalfil godkänns av en person med rätt att godkänna betalningar. Det är **standardattesten**, och den beskrivs i [Attest](/manual/leverantorer/attest). Behöver ni mer – till exempel att stora inköp ska godkännas av två personer, eller att den som ansvarar för ett kostnadsställe ska se fakturorna som bokas där – lägger du upp ett eget attestflöde.

![Attestflöden med ett flöde i två steg för inköp från 20 000 kr, standardattesten och ersättare.](/bilder/manual/leverantorer/attestfloden.webp)

## Så här lägger du upp ett attestflöde

1. Gå till **Inköp → Attestflöden**.
2. Klicka på **Nytt flöde för leverantörsfakturor**, **Nytt flöde för inköpsorder**, **Nytt flöde för betalfiler**, **Nytt flöde för manuella verifikationer** eller **Nytt flöde för kreditfakturor**. Flödet för inköpsorder finns när Lager är påslaget.
3. Skriv ett **Namn**, till exempel "Inköp från 20 000 kr", och en **Ordning**. Flöden prövas i ordning, lägst nummer först.
4. Välj för steg 1 **Vem godkänner**: **Alla med en behörighet**, **Utvalda personer** eller **Ägaren av kostnadsstället eller projektet**.
5. Ange **Antal som ska godkänna**. Två betyder att två olika personer måste godkänna steget.
6. Fyll i **Villkor (lämna tomt för alltid)** om steget bara ska gälla ibland, till exempel **Belopp från och med** 20 000.
7. Klicka på **Lägg till steg** för nästa steg, och sedan på **Spara flödet**.

Flödet syns direkt i listan med sina steg. Varje gång du sparar får flödet en ny version.

## Så avgörs vilket flöde som gäller

- Ett flöde gäller en faktura eller betalfil när minst ett av stegen gäller den. Steg vars villkor inte stämmer hoppas över.
- Gäller flera flöden används det med lägst **Ordning**.
- Gäller inget flöde används standardattesten, precis som förut.
- För leverantörsfakturor och inköpsorder kan villkoren vara belopp, kostnadsställe, projekt, leverantör och kontointervall. På en inköpsorder är det raderna utan artikel som har konto, kostnadsställe och projekt. För betalfiler kan villkoret bara vara ett belopp, eftersom en fil innehåller många fakturor.
- Beloppet är fakturans belopp att betala i kronor. En kreditnota prövas på sin storlek.
- För manuella verifikationer och kreditfakturor kan villkoren vara belopp, kostnadsställe, projekt och kontointervall, men inte leverantör. Utan ett sådant flöde behöver de ingen attest alls; se [Attest av verifikationer och kreditfakturor](/manual/bokforing/attest-av-verifikationer).

## Attestera i ett flöde

På fakturan visar kortet **Attestflöde** vilket flöde som gäller, stegen och vem som har godkänt. Den som får godkänna nuvarande steg ser knappen **Godkänn steg 1 av 2** (eller **Attestera fakturan** när flödet bara har ett steg). Fakturan blir **Attesterad, att betala** först när det sista steget är godkänt.

Samma person kan godkänna flera steg i samma flöde, till exempel både som kostnadsställets ägare i steg 1 och som administratör i steg 2. Vill ni att två olika personer ska ha sett fakturan lägger du det i ett och samma steg med **Antal som ska godkänna** 2, eller väljer olika personer i stegen.

![En leverantörsfaktura i ett attestflöde i två steg, där första steget är godkänt.](/bilder/manual/leverantorer/attestflode-pa-fakturan.webp)

En inköpsorder attesteras på ordern under **Lager → Inköpsorder**, och knappen heter **Attestera ordern**. Behörigheten som styr är rätten att skicka inköpsorder, det vill säga rollen **Ägare**, **Administratör** eller **Attestant**. Ordern kan skickas till leverantören först när sista steget är godkänt, och en ändrad order eller en ny version attesteras på nytt. Standardattesten för inköpsorder är attestgränsen under **Lager → Inställningar**. Se [Inköpsorder](/manual/lager/inkopsorder).

Betalfiler fungerar på samma sätt under **Inköp → Betalfiler**: där står hur långt filen har kommit, och knappen **Godkänn betalningen** visas för den som får godkänna nästa steg.

### Avvisa

Stämmer något inte klickar du på **Avvisa**, skriver gärna ett skäl och klickar på **Ja, avvisa**. Fakturan går tillbaka till den som registrerade den. Den kan sedan be leverantören om en kreditnota eller rätta det som var fel och klicka på **Skicka till attest igen**. Då börjar flödet om från första steget. Standardattesten har ingen avvisning, som tidigare.

## Någon annan måste godkänna

Kräver företaget att någon annan attesterar (se [Attest](/manual/leverantorer/attest)) gäller det i varje steg i varje flöde: den som registrerade fakturan, skapade betalfilen eller skapade inköpsordern kan aldrig godkänna den. Ett flöde kan inte stänga av kravet. Kryssrutan **Den som förberedde underlaget får inte godkänna** gör att kravet gäller i ett steg även om företaget har stängt av det.

## Vem kan vara attestant?

Ett flöde kan bara begränsa vem som attesterar, aldrig ge någon ny rätt. Utvalda personer, ägare av kostnadsställen och projekt och ersättare måste själva ha rätt att attestera leverantörsfakturor (för fakturor) eller att godkänna betalningar (för betalfiler), det vill säga rollen **Ägare**, **Administratör** eller **Attestant**. Det kontrolleras både när flödet sparas och när någon godkänner. Ett steg för ägaren kan bara kräva ett godkännande.

## Ägare av kostnadsställen och projekt

Ett steg av typen **Ägaren av kostnadsstället eller projektet** godkänns av den som äger kostnadsstället eller projektet som fakturan är konterad på. Välj ägare under **Ägare av kostnadsställen och projekt** längst ned på sidan. Har fakturan inget kostnadsställe eller projekt, eller saknar det en ägare, godkänns steget av den som har attesträtt, så att ingen faktura fastnar.

## Ersättare

Under **Ersättare** kan du välja vem som godkänner i ditt ställe medan du är borta:

1. Välj **Ersättare**.
2. Fyll i **Från och med** och **Till och med**.
3. Klicka på **Lägg till ersättare**.

Ersättaren kan godkänna de steg som pekar ut dig som person eller som ägare av ett kostnadsställe eller projekt. Beslutet sparas med "på uppdrag av" och ditt namn. Steg som gäller **Alla med en behörighet** kan inte lämnas över – där kan alla andra med behörigheten godkänna. Klicka på **Återkalla** för att avsluta en ersättning i förtid. Alla medlemmar kan välja sin egen ersättare; ägare och administratörer kan välja åt andra.

## Ändra eller ta bort ett flöde

Klicka på **Ändra** eller **Ta bort** vid flödet. En faktura eller betalfil som redan är på väg genom ett flöde följer flödet som det såg ut när attesten började, även om du ändrar eller tar bort det. Nya fakturor och filer följer flödet som det ser ut nu.

## Det här händer i bokföringen

Attestflöden bokför ingenting. De styr bara vem som får godkänna innan något kan betalas. Varje beslut sparas med person, tid, steg och eventuell ersättare, kan inte ändras eller tas bort, och skrivs i behandlingshistoriken.

## Vanliga frågor

**Vem får lägga upp attestflöden?**
Ägare och administratörer. Alla medlemmar kan se flödena och välja sin egen ersättare.

**Ändras något för oss när funktionen kommer?**
Nej. Så länge ni inte lägger upp ett eget flöde attesterar ni precis som förut.

**En person som ska godkänna har slutat.**
Den som inte längre är medlem i företaget kan inte godkänna. Ändra flödet så att någon annan godkänner steget. En ägare eller administratör kan alltid avvisa en faktura eller betalfil som är på väg, i vilket steg som helst; skickas den sedan till attest igen följer den det ändrade flödet.

## Läs också

- [Att godkänna](/manual/leverantorer/att-godkanna)
- [Attest av verifikationer och kreditfakturor](/manual/bokforing/attest-av-verifikationer)
- [Attest](/manual/leverantorer/attest)
- [Betalfiler till banken](/manual/leverantorer/betalfiler)
- [Inköpsorder](/manual/lager/inkopsorder)
- [Roller och behörigheter](/manual/kom-igang/roller-och-behorigheter)
