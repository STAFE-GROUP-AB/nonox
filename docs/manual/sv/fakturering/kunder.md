---
beskrivning: Lägg upp och ändra kunder, hämta namn och adress med organisationsnumret och ställ in betalningsvillkor och momsbehandling per kund.
ordning: 10
roller: [owner, admin, accountant, bookkeeper]
rutter: [invoicing.customers]
sokord: [kundregister, kund, ny kund, betalningsvillkor, kundnummer, Bolagsverket, VIES, hämta uppgifter]
---

# Kunder

Kundregistret är listan över dem du fakturerar. Uppgifterna du skriver här hamnar på fakturan, och kundens betalningsvillkor bestämmer förfallodagen. Du hittar registret under **Register → Kunder**.

![Kundregistret med fakturerat i år och obetalt per kund.](/manual/bilder/fakturering/kunder.webp)

## Så här lägger du till en kund

1. Gå till **Register → Kunder** och tryck på **Ny kund**. Rubriken visar vilket kundnummer kunden får, till exempel *Ny kund 1001*. Numret delas ut automatiskt i nummerordning.
2. Skriv kundens **Organisationsnummer** om det är ett företag. När du lämnar fältet hämtar NONOX namn och adress av sig själv, om namnet är tomt och numret stämmer (se nästa avsnitt).
3. Fyll i **Namn**. Det är det enda fältet som måste vara ifyllt, förutom betalningsvillkoret.
4. Är kunden en privatperson fyller du i **Personnummer (privatperson)** i stället för organisationsnummer. Personnumret behövs för ROT- och RUT-avdrag.
5. Skriv **E-post för fakturor**. Dit föreslås fakturan gå när du skickar den.
6. Kontrollera **Betalningsvillkor, dagar**. Det är 30 dagar från början och kan vara mellan 0 och 365.
7. Fyll i adress, **Postnummer**, **Ort** och **Land (landskod, t.ex. SE, DE, US)**. Landet skrivs som en kod med två bokstäver.
8. Välj under **Försäljning utomlands** om kunden finns i ett annat land, och bocka i **Omvänd betalningsskyldighet för byggtjänster** om kunden är ett byggföretag som själv redovisar momsen. Läs mer längre ned.
9. Tryck på **Spara**. Du ser meddelandet *Kund 1001 Café Hörnet är tillagd.*

## Hämta uppgifter med organisationsnumret

Om NONOX är kopplat till Bolagsverkets register syns rutan *Hämta namn och adress från …* under personnummerfältet, med knappen **Hämta uppgifter**.

- Tryck på **Hämta uppgifter** för att hämta namn, adress, postnummer och ort. NONOX kontrollerar först att numret stämmer.
- Har du redan skrivit något som skiljer sig från registret ändras det inte av sig självt. Du får se skillnaderna och väljer **Använd registrets uppgifter** eller **Behåll mina**.
- Är företaget avregistrerat, eller pågår en konkurs eller likvidation, står det med röd text. Kontrollera då att du har rätt nummer innan du fakturerar.
- Inget sparas förrän du trycker på **Spara**.

> [!NOTE]
> Hämtningen kräver att den som driftar NONOX har lagt in API-uppgifter från Bolagsverket. Saknas de syns inte rutan alls, och du skriver uppgifterna själv.

## Momsbehandling per kund

Det du väljer här följer med till varje ny faktura till kunden, också fakturor som skapas från en offert, en order eller ett avtal. Du kan ändra det på den enskilda fakturan innan den skapas.

- **Försäljning utomlands**: välj vilken sorts försäljning det är, till exempel *Varor till företag i annat EU-land* eller *Export av varor utanför EU*. Fakturorna blir då utan svensk moms och bokförs på rätt konto. Läs [Försäljning till andra EU-länder och export](/manual/fakturering/forsaljning-till-utlandet).
- **Omvänd betalningsskyldighet för byggtjänster**: fakturorna blir utan moms. Läs [Omvänd betalningsskyldighet för byggtjänster](/manual/fakturering/omvand-betalningsskyldighet-bygg).

Du kan inte välja båda samtidigt på en faktura.

## Kontrollera ett utländskt momsnummer i VIES

När en kund har ett momsregistreringsnummer som inte börjar på SE, och kunden är sparad, visas knappen **Kontrollera i VIES**. Den frågar EU:s register om numret är giltigt. Resultatet sparas med datum och, om VIES lämnar ett, ett konsultationsnummer, så att du kan visa att du kontrollerade numret.

> [!IMPORTANT]
> Har du ändrat numret måste du spara kunden först. Annars får du beskedet *Spara kunden först, så kontrolleras det sparade numret.* Var numret ogiltigt vid den senaste kontrollen stoppar NONOX fakturor utan moms till kunden tills numret har kontrollerats igen.

## Listan över kunder

Listan visar kundnummer, namn, organisationsnummer, e-post och ort. Till höger ser du vad kunden har fakturerats i år (exklusive moms, utan utkast) och vad som är **Obetalt** just nu. Sök på namn, kundnummer, organisationsnummer eller e-post i sökfältet.

Tryck på **Ändra** för att öppna en kund. Ändringar gäller nya fakturor. En faktura som redan är skapad behåller kunduppgifterna som de var när den skapades.

## Bra att veta

- **Kunder tas inte bort.** En kund som finns på en bokförd faktura är en del av räkenskapsinformationen. Om en privatperson ber att bli raderad använder du **Radera enligt GDPR**: kunden raderas om den aldrig har fakturerats, annars anonymiseras uppgifterna i registret medan fakturorna behåller sina.
- **Begränsa behandling** gör att kunden inte kan användas på nya offerter och fakturor tills du väljer **Häv begränsad behandling**. Kunden får märket *Begränsad behandling* i listan.
- **Ladda ner registerutdrag (JSON)** ger alla uppgifter om kunden, när en person begär ut sina uppgifter enligt GDPR.
- Leveranssätt, språk och valuta per kund finns inte. Fakturorna skickas på svenska i kronor, som PDF med e-post.

## Vanliga frågor

**Kan jag välja kundnumret själv?**
Nej. Numret delas ut automatiskt i nummerordning när kunden sparas.

**Varför får jag "Organisationsnumret stämmer inte"?**
NONOX kontrollerar numrets kontrollsiffra. Ett felskrivet nummer upptäcks direkt. Kontrollera siffrorna mot kundens egna papper.

**Jag ändrade kundens adress, men den gamla står kvar på fakturan.**
Fakturor som redan är skapade ändras aldrig. Den nya adressen kommer med på nästa faktura.

## Läs också

- [Skapa en faktura](/manual/fakturering/skapa-en-faktura)
- [Artiklar](/manual/fakturering/artiklar)
- [ROT- och RUT-avdrag](/manual/fakturering/rot-och-rut)
- [Obetalda fakturor och kundreskontran](/manual/fakturering/obetalda-fakturor)
