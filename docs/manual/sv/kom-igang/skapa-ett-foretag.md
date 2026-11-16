---
beskrivning: Så skapar du ditt första företag i NONOX – och fler senare. Vad varje uppgift betyder, vad som förväljs och vad NONOX sätter upp åt dig.
ordning: 20
rutter: [teams.create, onboarding]
sokord: [nytt företag, lägg till företag, starta, registrera företag, organisationsnummer, aktiebolag, enskild firma, Bolagsverket, företagsregister, hämta uppgifter, K1, K2, K3, regelverk, kalenderår]
---

# Skapa ett företag

Allt i NONOX hör till ett företag: verifikationer, fakturor, kunder och inställningar. Därför är det första du gör efter inloggningen att skapa företaget. Det tar några minuter, och du kan ändra det mesta efteråt.

Har du många företag, till exempel som redovisningskonsult, skapar du dem på samma sätt ett i taget. Varje företag är helt skilt från de andra.

![Formuläret Nytt företag. Namn, adress, företagsform och bransch är ifyllda från företagsregistret när organisationsnumret har skrivits.](/bilder/manual/kom-igang/skapa-ett-foretag.webp)

## Innan du börjar

Ha organisationsnumret till hands, och gärna besluten från Skatteverket om moms och F-skatt. Uppgifterna står på registreringsbeviset från Bolagsverket eller på verksamt.se.

## Så här gör du

1. Logga in. Har du inget företag ännu kommer du direkt till **Kom igång**. Har du redan ett företag klickar du på företagets namn högst upp i menyn och väljer **Lägg till ett företag**.
2. Skriv **Organisationsnummer**, med tio siffror. Är numret ett aktiebolag, en förening eller ett annat företag som finns i NONOX företagsregister fylls namn och adress, och oftast företagsform, i av sig själv när du lämnar fältet. Klicka annars på **Hämta uppgifter** om knappen finns; se [Hämta uppgifter från företagsregistret](#hamta-uppgifter-fran-foretagsregistret). Finns ingen knapp, eller hittas inte numret, väljer du **Företagsform** och skriver **Företagsnamn**, **Adress (frivilligt)**, **Postnummer** och **Ort** själv.
3. Kontrollera uppgifterna som fylldes i. Du kan ändra allt.
4. Fyll gärna i **E-post till företaget (frivilligt)** och **Bransch (frivilligt)**. Har NONOX hämtat uppgifter är branschen redan föreslagen, med texten *Förslag från registret*; ändra den om den inte stämmer. Företagsguiden väljer steg och tips efter branschen, företagsformen och om företaget är momsregistrerat; se [Företagsguiden och Min startplan](/manual/kom-igang/foretagsguiden). Passar ingen bransch väljer du **Annan bransch**.
5. Under **Bokföring och moms** väljer du när **Räkenskapsåret börjar i** och vilken **Bokföringsmetod** företaget använder.
6. Kryssa i **Företaget är momsregistrerat** om det är det, och välj **Hur ofta redovisar ni moms?**
7. Välj **Regelverk för årsbokslut**. Väljer du K1 eller K2 visas villkoren för regelverket. Kryssa i **Företaget uppfyller villkoren ovan** om de stämmer.
8. Kryssa i **Företaget har F-skatt** och **Företaget är registrerat som arbetsgivare** om de stämmer. Om registret vet hur många anställda företaget har står det en rad under rutan, *Förslag från registret*, och rutan är ikryssad eller inte efter det.
9. Klicka på **Skapa företaget**.

Du hamnar på **Översikt** i det nya företaget. Där finns listan **Kom igång** som visar vad som är klokt att göra härnäst; se [Översikten](/manual/kom-igang/oversikten).

> [!NOTE]
> På den driftade tjänsten nonox.app finns också en ruta där du godkänner villkoren och personuppgiftsbiträdesavtalet för företaget. Den måste vara ikryssad för att företaget ska skapas.

## Vad uppgifterna betyder

**Organisationsnummer** kontrolleras när du skriver det; den sista siffran är en kontrollsiffra som avslöjar skrivfel. Numret och företagsformen kan inte ändras efteråt, eftersom det då är ett annat företag. Är något fel lägger du till företaget på nytt.

**Företagsform** är aktiebolag, enskild firma, handelsbolag, kommanditbolag, ekonomisk förening, ideell förening eller stiftelse.

**Räkenskapsåret börjar i** är den månad företagets räkenskapsår börjar. För de flesta är det januari. NONOX skapar det första räkenskapsåret åt dig, det som pågår i dag.

- En **enskild firma** ska enligt bokföringslagen ha kalenderår som räkenskapsår. Räkenskapsåret börjar därför alltid i januari.
- Ett **handelsbolag** eller **kommanditbolag** ska också ha kalenderår om en fysisk person beskattas för hela eller en del av bolagets inkomst. Väljer du en annan månad än januari får du bekräfta att ingen fysisk person beskattas för bolagets inkomst.
- Övriga företag får ha brutet räkenskapsår, till exempel maj–april.

**Bokföringsmetod** är [fakturametoden](/manual/ordlista/ordlista#fakturametoden) eller [kontantmetoden](/manual/ordlista/ordlista#kontantmetoden). Den avgör när fakturor bokförs: när de skickas och tas emot, eller först när de betalas.

**Hur ofta redovisar ni moms?** är momsperioden enligt Skatteverkets beslut: **Varje månad**, **Varje kvartal** eller **En gång per år**. Är företaget momsregistrerat räknar NONOX själv ut momsregistreringsnumret från organisationsnumret.

**Regelverk för årsbokslut** är **K1 – förenklat årsbokslut**, **K2 – mindre företag** eller **K3 – huvudregelverket**. Valet styr vilken kontoplan företaget får från början.

- **K1** kan bara väljas av en enskild firma eller en ideell förening, och bara om nettoomsättningen normalt är högst 3 miljoner kronor per år. För handels- och kommanditbolag går K1 inte att välja ännu, eftersom det inte är kontrollerat.
- **K2** gäller bara mindre företag. Ett företag är större om det under vart och ett av de två senaste räkenskapsåren har haft mer än ett av: fler än 50 anställda i medeltal, en balansomslutning över 40 miljoner kronor och en nettoomsättning över 80 miljoner kronor, eller om det är noterat. För aktiebolag och ekonomiska föreningar visas också vilka andra företag som inte får använda K2 (BFNAR 2016:10 punkt 1.1A–1.1C enligt BFNAR 2025:2, för räkenskapsår som börjar efter den 31 december 2025), till exempel publika aktiebolag, moderföretag i en större koncern, företag med kryptotillgångar och – om företaget inte är mycket litet – företag med en väsentlig uppskjuten skatteskuld eller med byggnader som ger minst 75 procent av nettoomsättningen.
- **K3** kan alla företag använda. För ett mindre aktiebolag tar NONOX fram en förhandsversion (beta) av årsredovisningen, som ska granskas av en redovisningskonsult; se [Årsredovisning enligt K2](/manual/bokslut/arsredovisning#forhandsversion-beta-for-mindre-aktiebolag-med-k3).

NONOX känner bara till företagsformen, inte omsättning, balansomslutning eller antal anställda. Därför stoppar NONOX bara det som företagsformen avgör. Storleken bekräftar du själv genom att kryssa i **Företaget uppfyller villkoren ovan**. Är du osäker, fråga din redovisningskonsult innan du väljer. Väljer du K3 tar NONOX bara fram en förhandsversion (beta) av årsredovisningen, och bara för ett mindre aktiebolag; den är inte granskad av en auktoriserad redovisningskonsult och ska granskas av en redovisningskonsult innan den skrivs under. Bokföring, bokslut och inkomstdeklaration fungerar som vanligt.

> [!NOTE]
> Reglerna om räkenskapsår och regelverk bygger på en genomgång av bokföringslagen och Bokföringsnämndens allmänna råd. En jurist har ännu inte granskat dem.

> [!TIP]
> Osäker på något? Förvalen passar de flesta aktiebolag: räkenskapsår från januari, fakturametoden, moms varje kvartal och K2. Du kan ändra allt utom organisationsnummer och företagsform under **Inställningar för företaget**.

## Det här sätter NONOX upp åt dig

När du klickar på **Skapa företaget** görs allt det här på en gång:

- Du blir företagets **ägare** och får göra allt i det.
- En kontoplan enligt BAS 2026, anpassad efter regelverket du valde. Du kan lägga till fler konton under [Kontoplan](/manual/bokforing/kontoplan).
- Det första [räkenskapsåret](/manual/bokforing/rakenskapsar).
- Verifikationsserierna A Manuell, B Kundfakturor, C Kundinbetalningar, D Leverantörsfakturor, E Leverantörsbetalningar, L Lön, M Moms och I Ingående balanser/import.
- En rad i behandlingshistoriken om att företaget skapades och av vem.

Du arbetar sedan i det nya företaget. Har du flera företag byter du mellan dem i menyn; se [Byt företag](/manual/kom-igang/byt-foretag).

## Hämta uppgifter från företagsregistret

Med organisationsnumret hämtar NONOX namn, adress och oftast företagsform. NONOX letar först i sitt eget företagsregister, som bygger på bearbetade öppna uppgifter från Bolagsverket och SCB. Saknas numret där frågar NONOX Bolagsverket, men bara om installationen har kopplats till Bolagsverket. Enskilda firmor finns inte i NONOX eget register; för en enskild firma fyller du i uppgifterna själv, om inte installationen har kopplats till Bolagsverket. Under uppgifterna står var de kommer från och hur gamla de är, till exempel *Enligt NONOX företagsregister (bearbetade uppgifter från Bolagsverket och SCB), hämtade 4 nov 2026*.

Knappen **Hämta uppgifter** visas när NONOX har ett företagsregister eller har uppgifter för att fråga Bolagsverket. Kör ditt företag NONOX själv och har varken registret eller uppgifter från Bolagsverket finns ingen knapp; den som sköter servern kan lägga in dem.

- Svaret är ett förslag. Inget sparas förrän du klickar på **Skapa företaget**.
- Har du redan skrivit något som skiljer sig från registret visar NONOX skillnaderna. Välj **Använd registrets uppgifter** eller **Behåll mina**.
- Är företaget avregistrerat, eller pågår konkurs eller likvidation, står det i rutan. Kontrollera då att du har skrivit rätt nummer.
- Bara när du skapar ett nytt företag föreslår NONOX också **Bransch**, utifrån företagets branschkod (SNI), och **Företaget är registrerat som arbetsgivare**, utifrån om registret har anställda. Förslagen fylls bara i där du inte redan har valt något, och ändrar du något själv ändras det inte igen. Passar ingen bransch föreslås **Annan bransch**. Uppgiften om anställda finns bara i registret när den som sköter servern har en nyckel från SCB.
- Visar registret ett företag med många anställda och en stor omsättning står det en notis om att företaget kan vara ett större företag som inte får använda K2. NONOX väljer aldrig regelverk åt dig; läs villkoren under **Regelverk för årsbokslut**. Att ingen notis visas betyder inte att företaget får använda K2: registret saknar balansomslutningen och visar bara ett år. Notisen kräver också uppgifter om anställda och omsättning, som bara finns när den som sköter servern har en nyckel från SCB.
- Ett företag kan be att få sina uppgifter borttagna ur registret. Då hittar NONOX inte numret, och du skriver uppgifterna själv.

## Vanliga frågor

**Det står att det redan finns ett företag med organisationsnumret.**
Företaget finns redan i NONOX. Be ägaren att [bjuda in dig](/manual/kom-igang/bjud-in-en-anvandare) i stället. På nonox.app gäller kontrollen bara bland de företag du själv redan tillhör, så ingen annan kan låsa ute ditt företag genom att registrera numret först.

**Jag har fått en inbjudan. Ska jag skapa företaget ändå?**
Nej. Har du en inbjudan visas den överst under **Du är inbjuden** på sidan Kom igång. Klicka på **Gå med** så kommer du in i företaget med den roll du har fått.

**Kan jag ta bort ett företag jag skapat av misstag?**
Ett företag raderas aldrig, men det kan arkiveras så att det bara kan läsas. Se [Ägarskap och arkivering](/manual/installningar/agarskap-och-arkivering).

**Jag har en SIE-fil från mitt gamla program. Kan NONOX skapa företaget från den?**
Ja, indirekt: läser du in en SIE-fil som gäller ett annat organisationsnummer än det företag du arbetar i, erbjuder NONOX att skapa företaget med uppgifterna från filen. Läs mer under [Flytta till NONOX](/manual/flytta).

## Läs också

- [Översikten](/manual/kom-igang/oversikten)
- [Företagets uppgifter](/manual/installningar/foretagets-uppgifter)
- [Bjud in en användare](/manual/kom-igang/bjud-in-en-anvandare)
- [Flytta till NONOX](/manual/flytta)
