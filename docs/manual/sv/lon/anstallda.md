---
beskrivning: Lägg upp en anställd med personnummer, skattetabell, bankkonto och anställningsvillkor – månadslön eller timlön.
ordning: 20
roller: [owner, payroll_admin]
modul: payroll
rutter: [payroll.employees]
sokord: [personal, anställd, personalkort, skattetabell, kolumn, jämkning, jämkningsbeslut, SINK, bankkonto lön, timanställd, månadslön, sysselsättningsgrad]
---

# Anställda

Varje person som ska få lön läggs upp under **Lön → Anställda**. Där samlas allt lönekörningen behöver veta: vem personen är, hur hen ska beskattas, vart lönen ska betalas och vilka villkor anställningen har.

![De anställda med lön, befattning och anställningsform.](/bilder/manual/lon/anstallda.webp)

## Så här lägger du upp en anställd

1. Öppna **Lön → Anställda** och klicka på **Ny anställd**.
2. Fyll i **Förnamn**, **Efternamn** och **Personnummer** (tolv siffror, ÅÅÅÅMMDD-NNNN).
3. Fyll i **E-post** om personen ska kunna logga in på Min sida, och adressen.
4. Fyll i **Clearingnummer** och **Kontonummer för lön**.
5. Välj **Skatt** och fyll i **Skattetabell** och **Kolumn** från personens skattebesked.
6. Fyll i anställningen: **Anställd från**, **Anställningsform**, **Befattning**, **Lön per** (**Månad** eller **Timme**), **Lön, kronor** och **Sysselsättningsgrad, %**.
7. Fyll i **Arbetstid, timmar per vecka**. Den behövs för att räkna frånvaro och sjuklön.
8. Välj semesterregel, semesterdagar och eventuell tjänstepension (se nedan).
9. Klicka på **Spara**. Personen får nästa lediga anställningsnummer.

Det är ägaren och löneadministratören som lägger upp och ändrar anställda.

## Personuppgifterna skyddas

Personnummer och bankkonto sparas krypterade. I listan och i formuläret visas personnumret maskerat, och kontonumret bara med de fyra sista siffrorna. När du ändrar en anställd är fälten för personnummer och bankkonto tomma: lämnar du dem tomma behålls det som redan är sparat.

Behandlingshistoriken visar *att* personnummer eller bankkonto har ändrats, men aldrig själva uppgifterna.

## Skatt

Under **Skatt** väljer du ett av tre alternativ:

- **Bosatt i Sverige (skattetabell)** – skatten dras enligt Skatteverkets skattetabell. **Skattetabell** och **Kolumn** måste fyllas i; de står på personens skattebesked. NONOX gissar aldrig tabellen.
- **Bosatt utomlands, SINK** och **Bosatt utomlands, A-SINK** – särskild inkomstskatt med en fast procentsats enligt reglerna. Ingen tabell behövs.

Är du osäker på vilken skatt som gäller, fråga Skatteverket eller er redovisningskonsult.

## Jämkning

Har den anställda fått ett beslut om jämkning från Skatteverket ska företaget följa det senast två veckor efter att ni fick det. Fyll i beslutet under **Jämkning** på personalkortet:

1. Välj vad beslutet säger under **Beslut**:
   - **Skatteavdrag med en procentsats** – fyll i **Procent**, till exempel 20.
   - **Skatteavdrag enligt en annan tabell och kolumn** – fyll i tabellen och kolumnen som står i beslutet.
   - **Skatteavdrag med ett fast belopp per utbetalning** – fyll i beloppet.
2. Fyll i **Gäller från**, den första utbetalningsdagen som beslutet ska gälla. Står det ett slutdatum i beslutet fyller du i **Gäller till och med**.
3. Klicka på **Spara**.

Lönekörningen följer beslutet för utbetalningar mellan de två dagarna. Före och efter används skattetabellen igen. På lönebeskedet står det att skatten är dragen enligt jämkning.

Procentsatsen gäller hela den skattepliktiga lönen, även engångsbelopp som bonus. Välj **Inget beslut om jämkning** och spara när beslutet inte längre ska användas.

> [!NOTE]
> Skatteverkets information till arbetsgivare beskriver jämkning som en procentsats. De två andra valen finns för beslut som är skrivna på annat sätt; kontrollera med Skatteverket om du är osäker.

> [!IMPORTANT]
> Skattetabellerna läses in i NONOX från Skatteverkets fil. Finns inte tabellen och kolumnen för året stoppas lönekörningen för den personen. Se [Regler och skattetabeller](/manual/lon/regler-och-skattetabeller).

## Månadslön eller timlön

- **Månad**: lönen i **Lön, kronor** är månadslönen vid heltid. NONOX räknar med **Sysselsättningsgrad, %**, så en månadslön på 30 000 kronor och 75 % ger 22 500 kronor. Börjar eller slutar personen mitt i månaden delas lönen enligt löneinställningarna.
- **Timme**: lönen i **Lön, kronor** är timlönen. Lönen räknas från de timmar som läggs in i lönekörningen eller kommer från godkända tidrapporter. Se [Timlön och tidrapporter](/manual/lon/timlon-och-tidrapporter).

**Anställningsform** kan vara **Tillsvidare**, **Allmän visstid**, **Vikariat**, **Säsong**, **Provanställning** eller **Timanställd**. **Kostnadsställe (frivilligt)** gör att lönekostnaden bokförs på det kostnadsstället; skriv kostnadsställets kod.

## Semester

- **Semesterregel**: lämna **Som i löneinställningarna** eller välj **Sammalöneregeln** eller **Procentregeln** för just den här personen.
- **Semesterdagar per år**: minst 25 enligt semesterlagen, fler om avtalet eller anställningsavtalet ger det.
- **Betala semesterersättning med varje lön i stället för betalda semesterdagar**: bara för korta anställningar eller när kollektivavtalet tillåter det, vanligt för timanställda. Personen får då ingen betald semester utan ersättningen med varje lön.

Mer i [Semester](/manual/lon/semester).

## Bankkonto och skydd mot bedrägeri

För en ny anställd sparas bankkontot direkt. Ändrar du kontot på en anställd som redan finns, väntar det nya kontot på bekräftelse:

1. Den som ändrade kontot sparar som vanligt.
2. En gul ruta visar **Nytt bankkonto ****1234 väntar på bekräftelse**.
3. En annan person som får hantera personal öppnar den anställda och klickar på **Bekräfta**, eller på **Ta bort ändringen** om ändringen var fel.

Medan ändringen väntar kan ingen lönefil skapas för lönekörningen. Det gör att ingen ensam kan styra om någons lön till ett annat konto.

## Koppla en inloggning

**Inloggning (frivilligt)** kopplar personen till en användare som redan är medlem i företaget. Oftast är det enklare att klicka på **Bjud in till Min sida** i listan. Se [Min sida för de anställda](/manual/lon/min-sida-for-anstallda).

## Pensionsplaner

Längst ner på sidan finns **Pensionsplaner**. Där lägger du upp företagets tjänstepension och väljer den sedan under **Tjänstepension** på de anställda som ska ha den. Se [Arbetsgivaravgifter och tjänstepension](/manual/lon/arbetsgivaravgifter-och-pension).

## Listan över anställda

Listan visar anställningsnummer, namn, maskerat personnummer, befattning, lön och hur många semesterdagar personen har kvar. Har personen ingen inloggning ser du **Bjud in till Min sida**. Klicka på **Ändra** för att öppna personen; där finns också **Lönerevision** och **Anställningens slut**.

Den som har behörighet att se känsliga löneuppgifter ser dessutom **Ladda ner registerutdrag (JSON)**, som används när personen begär ut sina uppgifter enligt GDPR.

## Vanliga frågor

**Var hittar jag skattetabell och kolumn?**
På den anställdes skattebesked, som personen får från Skatteverket. Fråga personen om det.

**Ändrar jag lönen direkt i formuläret eller under Lönerevision?**
Använd **Lönerevision** när lönen höjs från ett visst datum. Då sparas lönehistoriken, och månader som redan är utbetalda rättas automatiskt med retroaktiv lön. Ändrar du lönen direkt i formuläret används den nya lönen från och med innevarande månad, utan någon retroaktiv rättning.

**Hur tar jag bort en anställd?**
Avsluta anställningen och använd vid behov **Radera enligt GDPR**. Se [Lönerevision, slutlön och arbetsgivarintyg](/manual/lon/lonerevision-och-slutlon).

**Var anger jag kollektivavtal och lönegrupp?**
Under **Lön → Avtal**, när modulen Avtal är på. Se [Kollektivavtal](/manual/avtal).

## Läs också

- [Gör en lönekörning](/manual/lon/lonekorning)
- [Lönerevision, slutlön och arbetsgivarintyg](/manual/lon/lonerevision-och-slutlon)
- [Min sida för de anställda](/manual/lon/min-sida-for-anstallda)
- [Semester](/manual/lon/semester)
