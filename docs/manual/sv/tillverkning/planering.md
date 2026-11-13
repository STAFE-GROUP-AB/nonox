---
beskrivning: Se när ordrarna i verkstaden troligen blir klara, vad som händer med leveranserna när någon är borta eller en maskin står still, och pröva lösningar innan du bestämmer.
ordning: 40
roller: [owner, admin, accountant, auditor, scheduler]
modul: manufacturing
rutter: [manufacturing.planning.timeline, manufacturing.planning.orders, manufacturing.planning.resources, manufacturing.planning.calendar, manufacturing.planning.deviations, manufacturing.planning.scenario, manufacturing.planning.setup]
sokord: [planering, produktionsplanering, beläggning, gantt, tidslinje, leveransdatum, prognos, avvikelse, frånvaro, flaskhals, kapacitet, övertid]
---

# Planering

Planeringen lägger ut arbetsstegen i tillverkningsordrarna på personerna och arbetsstationerna i verkstaden. Den visar när varje order troligen blir klar, jämför med datumet ni har lovat kunden och visar vad som händer med leveranserna när något ändras, till exempel när någon är sjuk eller en maskin går sönder.

![Tidslinjen med ordrarna, arbetsstegen som staplar och utlovat datum som en röd markering.](/bilder/manual/tillverkning/planering.webp)

Du hittar planeringen under **Tillverkning → Planering**. Överst finns flikarna **Tidslinje**, **Ordrar**, **Resurser**, **Kalender**, **Avvikelser**, **Tänk om** och **Inställningar**.

## Så här kommer du igång

1. Gå till **Tillverkning → Planering** och välj **Inställningar**.
2. Under **Personer och kompetenser** lägger du upp en kompetens för varje sorts arbete, till exempel Kapning och Lackering, och väljer **Ändra** på varje person i verkstaden för att ge dem deras kompetenser. Personerna är företagets anställda i Lön.
3. Under **Arbetsstationer** väljer du **Ny arbetsstation** för det som bara finns en av, till exempel en lackbox, och kryssar i **Arbetssteg som utförs här**.
4. Under **Arbetstid och horisont** kontrollerar du företagets arbetsdagar och arbetstider.
5. Gå till **Tillverkning → Arbetssteg** och välj **Kräver kompetens** och, där det behövs, **Väntetid efter, timmar** på varje steg. Se [Arbetssteg](/manual/tillverkning/arbetssteg).
6. Frisläpp tillverkningsordrarna. Planen räknas fram av sig själv.

## Hur prognosen räknas

Planeringen tar ordrarna i tur och ordning: först de du har gett en egen prioritet, sedan efter utlovat datum. Varje arbetssteg läggs på den person med rätt kompetens som blir klar först, och på en arbetsstation om steget kräver det. Ett steg börjar tidigast när steget före är klart och väntetiden efter det har gått, till exempel torkning efter lackering. Det första steget börjar inte innan materialet finns: i lager, eller det datum en skickad inköpsorder väntas.

Den beräknade leveransdagen är första arbetsdagen efter att det sista steget är klart, plus en eventuell leveransbuffert.

Färgerna säger hur det går mot det utlovade datumet:

- **I tid** – minst en arbetsdag före.
- **På gränsen** – samma dag som utlovat, eller när materialdatumet bara är antaget.
- **Försenad** – efter utlovat datum, eller efter horisonten.
- **Inget lovat datum** – ordern har inget datum att jämföra med.

> [!NOTE]
> Planen är beräknad, inte bekräftad. Den lägger arbetet i tur och ordning och söker ingen bästa lösning, ställtider mellan ordrar räknas inte, och ingenting beräknas efter horisonten. Minuterna i stycklistorna blir aldrig bättre än de minuter ni har angett.

Planen räknas om av sig själv när något ändras: frånvaro, en ny tid för en inköpsorder, en inleverans, en ändrad order, ett stopp i en arbetsstation. Sidorna uppdateras av sig själva när en ny plan finns. Den som planerar kan också välja **Beräkna nu**.

## Ordrar

**Ordrar** visar varje order i planen med kund, **Utlovat**, **Prognos**, **Skillnad** i arbetsdagar, **Status** och vad ordern **Väntar på**: material, kapacitet eller inget.

![Ordrarna med utlovat datum, prognos, skillnad i arbetsdagar och vad de väntar på.](/bilder/manual/tillverkning/planering-ordrar.webp)

Välj **Visa steg** för att se vem som gör vad och när. Den som planerar kan här:

- välja **Lås här** så att steget ligger kvar där det ligger,
- välja en person under **Flytta till** och sedan **Flytta**,
- dela steget med en person under **Dela med**, med antal minuter, och välja **Dela**,
- skriva en ny tid under **Återstår, minuter** när ett steg tar längre tid,
- ge ordern en **Prioritet** och välja **Spara prioritet**. Lägst tal kommer först.

Låst arbete flyttas aldrig. Om personen inte kan göra det, till exempel för att hen är borta, blir det en avvikelse i stället för att planen tyst flyttar arbetet.

## Resurser

**Resurser** visar varje person och arbetsstation per dag: bokat arbete mot arbetstid. En person som är borta visas som **Frånvarande**, oavsett varför.

![Personer och arbetsstationer per dag med bokat arbete, arbetstid och frånvaro.](/bilder/manual/tillverkning/planering-resurser.webp)

> [!IMPORTANT]
> Planeringen vet aldrig varför någon är borta. Sjukdom, semester och ledighet ser likadana ut överallt i planeringen, även i avvikelserna. Orsaken finns bara i Lön, för dem som får se den där.

## Kalender

**Kalender** visar beräknade leveranser och när arbetet med varje order blir klart, per **Månad** eller **Vecka**. **Leverans TO-3** står på den beräknade leveransdagen. **Arbetet klart TO-3 14:30** står på dagen då det sista arbetssteget blir klart. Har steget en väntetid efter sig, till exempel torkning efter lackering, står **väntetid till** och klockslaget under, och leveransen räknas från när väntetiden är slut.

![En vecka i kalendern med två beräknade leveranser, när arbetet blir klart och väntetiden efter.](/bilder/manual/tillverkning/planering-kalender.webp)

## Avvikelser

**Avvikelser** visar det som har flyttat planen och vad det gör med varje leverans: **Prognos före**, **Prognos efter** och **Ändring** i arbetsdagar. En avvikelse som inte flyttade någon leverans stängs av sig själv.

![En avvikelse med de ordrar som flyttas och hur många arbetsdagar.](/bilder/manual/tillverkning/planering-avvikelser.webp)

Välj **Visa förslag** för att se vad som kan hjälpa: flytta ett steg till en annan person, övertid, dela ett steg eller lägga ordern först. Varje förslag är beräknat innan det visas, och ett förslag som inte hjälper står som det. Välj **Tillämpa** för att använda ett förslag, eller **Markera hanterad** eller **Avfärda**.

> [!NOTE]
> Kostnaden för övertid räknas på arbetsstegets timkostnad, inte på övertidsersättningen. Kontrollera arbetstidsreglerna innan du bestämmer övertid.

## Nytt leveransdatum till kunden

1. Välj **Nytt datum till kunden** på en order i avvikelsen.
2. Välj **Leveransdatum** och **Bekräfta datum**. Datumet sparas som bekräftat på kundordern.
3. Läs meddelandet och kontrollera **Mottagare**.
4. Välj **Skicka till kunden**, eller **Skicka inte nu**.

Kunden får ingenting förrän du har valt **Skicka till kunden**. Meddelandet går från företagets namn med företagets e-post som svarsadress och säger aldrig varför datumet ändrades.

## Tänk om

Under **Tänk om** prövar du ändringar utan att röra planen: övertid, flytta eller dela ett arbetssteg, eller ändra prioritet. Lägg till ändringarna, välj **Räkna** och jämför **Utan ändringar** med **Med ändringar**. **Tillämpa** sparar ändringarna och räknar om planen. Ett scenario som inte tillämpas försvinner efter sju dagar.

## Inställningar

Under **Arbetstid och horisont** finns:

- **Arbetsdagar**, **Dagen börjar**, **Dagen slutar** och rasten. Det är arbetstiden för den som inte har en egen arbetsvecka, och det avgör vilka dagar som är leveransdagar.
- **Horisont, veckor** – hur långt fram planen räknar, högst 52 veckor.
- **Leveransbuffert, arbetsdagar** – tid för packning och frakt efter att arbetet är klart.
- **Fryst fönster, arbetsdagar** – arbete som redan ligger inom fönstret flyttas inte när planen räknas om.
- **Ledtid för material, dagar** – för material som inte är beställt, när leverantören saknar historik.

Under **Arbetsstationer** registrerar du också ett stopp med **Registrera stopp** och avslutar det med **I drift igen nu**.

## Vem ser vad

Alla som ser Tillverkning ser planeringen. Ägare och administratörer planerar: låser, flyttar, delar, prioriterar, tillämpar förslag och hanterar avvikelser. Schemaläggare i Planering kan också planera. Bara ägare och administratörer bekräftar ett leveransdatum till kunden och skickar meddelandet. Redovisningskonsulter och revisorer ser planen men ändrar den inte.

> [!IMPORTANT]
> Ni som arbetsgivare ansvarar för att informera och vid behov förhandla med de anställda innan ni börjar planera arbetet per person, och för att övertid och mertid följer arbetstidslagen och ert kollektivavtal. NONOX föreslår övertid men kontrollerar inte reglerna åt er.
