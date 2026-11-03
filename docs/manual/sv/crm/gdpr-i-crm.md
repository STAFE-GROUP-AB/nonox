---
beskrivning: Rättslig grund för varje kontaktperson, intresseavvägningen, registerutdrag, radering och gallring av gamla kontakter.
ordning: 50
roller: [owner, admin, accountant, bookkeeper, sales]
modul: crm
sokord: [gdpr, dataskydd, personuppgifter, rättslig grund, berättigat intresse, intresseavvägning, samtycke, radera person, registerutdrag, gallring]
---

# GDPR i CRM

Kontaktpersonerna i CRM är personuppgifter. Företaget är personuppgiftsansvarigt och behöver ha en rättslig grund för varje person, spara uppgifterna så länge de behövs och inte längre, och kunna lämna ut och radera dem när någon ber om det. NONOX hjälper dig med alla fyra.

> [!IMPORTANT]
> Texten om intresseavvägningen, mallen för informationen till personen, den förvalda gallringstiden och hur länge spärrlistan sparas är inte granskade av en jurist än. Stäm av dem med den som ansvarar för dataskyddet hos er, eller med Integritetsskyddsmyndigheten (IMY).

## Rättslig grund för varje person

När du lägger till en person väljer du **Rättslig grund (GDPR)**:

| Grund | Passar när |
|---|---|
| **Avtal** | Personen eller dess arbetsgivare har ett avtal med er, och uppgifterna behövs för avtalet. |
| **Berättigat intresse** | Det vanliga för en kontaktperson på ett företag: ni vill kontakta personen i dess yrkesroll. |
| **Samtycke** | Personen har själv sagt ja. Ange datumet. Ett samtycke kan återkallas när som helst. |

Under **Anteckning om grunden** kan du skriva varför, till exempel var ni träffades.

## Intresseavvägningen

Berättigat intresse kräver att ert intresse väger tyngre än personens intresse av att slippa. För en kontaktperson på ett företag brukar avvägningen se ut så här:

1. **Ert intresse** – att sälja till och ha kontakt med företaget personen arbetar på.
2. **Nödvändigt** – ni sparar bara namn, titel och kontaktuppgifter i jobbet, och vad ni har pratat om.
3. **Personens intresse** – uppgifterna gäller yrkesrollen, inte privatlivet, och personen kan räkna med att bli kontaktad i den rollen.
4. **Slutsats** – ert intresse väger tyngre, så länge ni slutar höra av er när personen ber om det, och gallrar kontakter ni inte längre har nytta av.

Skriv aldrig känsliga uppgifter om en person i en anteckning, till exempel om hälsa eller familj.

## Informera personen

När ni samlar in uppgifter om någon från något annat håll än personen själv, till exempel från en mässa, en kollega eller företagets webbplats, ska personen få veta det (artikel 14). Det ska ske inom en månad, och senast vid första kontakten om ni hör av er tidigare. Skriv datumet i **Informerad den** på personen, så syns det i registerutdraget.

Mall att utgå från (fyll i det som står inom hakparentes):

> Hej [namn]! Vi på [företagets namn, organisationsnummer, kontaktuppgifter] har sparat ditt namn, din titel, dina kontaktuppgifter i jobbet och anteckningar om våra kontakter (vad vi har pratat om). Uppgifterna fick vi från [källa]. Vi gör det för att kunna ha kontakt med dig om [vad ni säljer eller samarbetar om], med stöd av vårt berättigade intresse (artikel 6.1 f i dataskyddsförordningen). Uppgifterna finns hos våra leverantörer av [ekonomisystem och drift, till exempel NONOX och dess driftleverantör] som behandlar dem för vår räkning, och lämnas inte vidare till andra. [Har ni ett dataskyddsombud: Vårt dataskyddsombud når du på [kontaktuppgift].] Vi sparar uppgifterna så länge vi har kontakt, och tar bort dem efter [antal] månader utan kontakt. Du har rätt att få veta vad vi har sparat, få det rättat eller raderat, begära att behandlingen begränsas och att **invända mot behandlingen**, till exempel om du inte vill bli kontaktad. Säg till oss, så slutar vi. Du kan också klaga hos Integritetsskyddsmyndigheten (IMY).

Har ni ett avtal med personen eller dess arbetsgivare, byt meningen om berättigat intresse mot: "Vi behöver uppgifterna för att fullgöra vårt avtal med [företaget] (artikel 6.1 b)." Bygger det på samtycke: "Du har samtyckt den [datum] (artikel 6.1 a). Du kan ta tillbaka ditt samtycke när du vill." Rätten att invända gäller berättigat intresse; vid samtycke är det att ta tillbaka samtycket som gäller.

Mallen är ett förslag. Stäm av den med den som ansvarar för dataskyddet hos er.

## Kontakta inte

Säger personen att den inte vill bli kontaktad (en invändning, artikel 21) eller begär att behandlingen begränsas (artikel 18), kryssar du i **Kontakta inte** på personen och väljer varför. Då:

- syns **Kontakta inte** vid personen på kundens kort, under **Personer** och i **Mina aktiviteter**,
- går det inte att logga nya samtal, möten, e-post eller uppgifter för personen; vid en invändning kan du fortfarande skriva anteckningar (vid begränsad behandling gäller reglerna nedan),
- försvinner knappen **Ring** för personen.

Vid en **invändning** sparas personens e-postadress och telefonnummer på en spärrlista som en kontrollsumma (hash), aldrig som text. Listan finns kvar när personen raderas, och läggs samma person till igen får den **Kontakta inte** direkt (artikel 21.3). Kontrollsumman är en pseudonymisering, inte en anonymisering: den som har nyckeln och en adress kan pröva om adressen finns på listan. Tar ni bort krysset för att personen har ångrat sig försvinner kontrollsummorna, utom de som en annan person med invändning har.

Vid **begränsad behandling** får uppgifterna bara sparas. Det går inte att logga några nya aktiviteter för personen, utom en enda anteckning om begränsningen, räknat från när begränsningen sattes. Befintliga aktiviteter om personen kan inte ändras, bara klarmarkeras, och när begränsningen sätts klarmarkeras personens öppna uppgifter och planerade aktiviteter, så att de försvinner från **Mina aktiviteter**. Ingenting sparas på spärrlistan; raderas personen finns inget kvar.

Uppgifterna om personen får fortfarande rättas med **Ändra uppgifter**. Begränsningen går däremot inte att ta bort där. Den hävs bara med **Häv begränsningen** i rutan om personuppgifter på personens sida, och först när skälet för begränsningen inte längre finns och personen har fått veta det i förväg (artikel 18.3).

Spärrlistan granskas med jämna mellanrum: en rad som inte har granskats på tre år (inställningen **Granska spärrlistan, år** under **CRM → Inställningar**) visas under **Att göra**. Under **CRM → Personer → Spärrlista** väljer du **Behåll** om invändningen fortfarande gäller eller **Ta bort**.
## Registerutdrag

- **En kontaktperson**: öppna personen och tryck på **Ladda ner registerutdrag (JSON)**. Utdraget innehåller personens uppgifter, den rättsliga grunden, vilka företag personen hör till och aktiviteterna om personen.
- **En kund**: registerutdraget för kunden i kundregistret innehåller nu också kundens kort och aktiviteter i CRM.

Läs igenom utdraget innan du skickar det. Anteckningar kan nämna andra personer, och deras uppgifter ska inte lämnas ut (artikel 15.4). Ta bort eller dölj dem först.

Utdraget för en kontaktperson går att hämta även om CRM har stängts av. Då gör ägaren eller en administratör det.
## Radera på begäran

- **En person**: öppna personen och tryck på **Radera enligt GDPR**. Personen tas bort från alla företag. De aktiviteter som är kopplade till personen finns kvar på kundens tidslinje, men utan personens namn, rubrik och text. **Andra aktiviteter som nämner personen i fritext ändras inte.** Formuläret visar hur många aktiviteter som nämner namnet utan att vara kopplade till personen; gå igenom dem och ta bort det som gäller personen för hand.
- **Ett prospekt**: tryck på **Ändra uppgifter** och sedan **Radera enligt GDPR**. Prospektet och dess aktiviteter tas bort.
- **En kund** som anonymiseras i kundregistret blir anonym i CRM också: webbplats, källa, taggar, SNI-kod, orsak till förlust, kopplingar till personer och rubrik och text på aktiviteterna tas bort.
- **En kund** som finns i bokföringen raderas aldrig från CRM. Fakturorna är räkenskapsinformation som ska sparas i sju år enligt Bokföringslagen. Radera eller anonymisera kunden i kundregistret i stället. Se [Personuppgifter](/manual/installningar/personuppgifter).

## Gallring av gamla kontakter

Personer och prospekt som ingen har haft någon aktivitet med på 24 månader raderas av sig själva varje natt. Tiden ändrar du under **CRM → Inställningar** (**Gallra efter, månader utan aktivitet**).

30 dagar innan visas de under **Att göra** på översikten och under **CRM → Personer → Gallras snart**. Vill ni behålla en kontakt räcker det att logga en aktivitet.

Följande gallras aldrig:

- ett prospekt som har blivit kund, eftersom det finns i kundregistret,
- en person som är **Fakturamottagare** hos en kund, eftersom namnet kan stå på fakturorna.

## Läs också

- [Företag och personer](/manual/crm/foretag-och-personer)
- [Personuppgifter](/manual/installningar/personuppgifter)
