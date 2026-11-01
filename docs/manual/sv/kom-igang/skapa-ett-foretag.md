---
beskrivning: Så skapar du ditt första företag i NONOX – och fler senare. Vad varje uppgift betyder, vad som förväljs och vad NONOX sätter upp åt dig.
ordning: 20
rutter: [teams.create, onboarding]
sokord: [nytt företag, lägg till företag, starta, registrera företag, organisationsnummer, aktiebolag, enskild firma, Bolagsverket]
---

# Skapa ett företag

Allt i NONOX hör till ett företag: verifikationer, fakturor, kunder och inställningar. Därför är det första du gör efter inloggningen att skapa företaget. Det tar några minuter, och du kan ändra det mesta efteråt.

Har du många företag, till exempel som redovisningskonsult, skapar du dem på samma sätt ett i taget. Varje företag är helt skilt från de andra.

## Innan du börjar

Ha organisationsnumret till hands, och gärna besluten från Skatteverket om moms och F-skatt. Uppgifterna står på registreringsbeviset från Bolagsverket eller på verksamt.se.

## Så här gör du

1. Logga in. Har du inget företag ännu kommer du direkt till **Kom igång**. Har du redan ett företag klickar du på företagets namn högst upp i menyn och väljer **Lägg till ett företag**.
2. Skriv **Organisationsnummer**, med tio siffror, och välj **Företagsform**.
3. Klicka på **Hämta uppgifter** om knappen finns, så fylls namn och adress i från Bolagsverket. Annars skriver du **Företagsnamn**, **Adress (frivilligt)**, **Postnummer** och **Ort** själv.
4. Fyll gärna i **E-post till företaget (frivilligt)**.
5. Under **Bokföring och moms** väljer du när **Räkenskapsåret börjar i** och vilken **Bokföringsmetod** företaget använder.
6. Kryssa i **Företaget är momsregistrerat** om det är det, och välj **Hur ofta redovisar ni moms?**
7. Välj **Regelverk för årsbokslut**.
8. Kryssa i **Företaget har F-skatt** och **Företaget är registrerat som arbetsgivare** om de stämmer.
9. Klicka på **Skapa företaget**.

Du hamnar på **Översikt** i det nya företaget. Där finns listan **Kom igång** som visar vad som är klokt att göra härnäst; se [Översikten](/manual/kom-igang/oversikten).

> [!NOTE]
> På den driftade tjänsten nonox.app finns också en ruta där du godkänner villkoren och personuppgiftsbiträdesavtalet för företaget. Den måste vara ikryssad för att företaget ska skapas.

## Vad uppgifterna betyder

**Organisationsnummer** kontrolleras när du skriver det; den sista siffran är en kontrollsiffra som avslöjar skrivfel. Numret och företagsformen kan inte ändras efteråt, eftersom det då är ett annat företag. Är något fel lägger du till företaget på nytt.

**Företagsform** är aktiebolag, enskild firma, handelsbolag, kommanditbolag, ekonomisk förening, ideell förening eller stiftelse.

**Räkenskapsåret börjar i** är den månad företagets räkenskapsår börjar. För de flesta är det januari. NONOX skapar det första räkenskapsåret åt dig, det som pågår i dag.

**Bokföringsmetod** är [fakturametoden](/manual/ordlista/ordlista#fakturametoden) eller [kontantmetoden](/manual/ordlista/ordlista#kontantmetoden). Den avgör när fakturor bokförs: när de skickas och tas emot, eller först när de betalas.

**Hur ofta redovisar ni moms?** är momsperioden enligt Skatteverkets beslut: **Varje månad**, **Varje kvartal** eller **En gång per år**. Är företaget momsregistrerat räknar NONOX själv ut momsregistreringsnumret från organisationsnumret.

**Regelverk för årsbokslut** är **K1 – förenklat årsbokslut**, **K2 – mindre företag** eller **K3 – huvudregelverket**. Valet styr vilken kontoplan företaget får från början. NONOX kontrollerar inte vilket regelverk ditt företag får använda; fråga din redovisningskonsult om du är osäker.

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

## Hämta uppgifter från Bolagsverket

Knappen **Hämta uppgifter** hämtar namn, adress och företagsform från Bolagsverket med organisationsnumret. Den visas bara om installationen har API-uppgifter från Bolagsverket; kör ditt företag NONOX själv kan den som sköter servern lägga in dem.

- Svaret är ett förslag. Inget sparas förrän du klickar på **Skapa företaget**.
- Har du redan skrivit något som skiljer sig från registret visar NONOX skillnaderna. Välj **Använd registrets uppgifter** eller **Behåll mina**.
- Är företaget avregistrerat, eller pågår konkurs eller likvidation, står det i rutan. Kontrollera då att du har skrivit rätt nummer.

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
