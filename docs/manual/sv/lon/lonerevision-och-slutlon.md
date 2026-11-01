---
beskrivning: Ge en anställd ny lön från ett datum med retroaktiv lön, avsluta en anställning med slutlön och semesterersättning, och hämta underlag för arbetsgivarintyg.
ordning: 160
roller: [owner, payroll_admin]
modul: payroll
rutter: [payroll.employees.certificate]
sokord: [löneförhöjning, lönerevision, retroaktiv lön, retrolön, slutlön, uppsägning, sista arbetsdag, arbetsgivarintyg, radera anställd, GDPR anställd]
---

# Lönerevision, slutlön och arbetsgivarintyg

Allt det här görs från den anställdas sida: öppna **Lön → Anställda** och klicka på **Ändra** på personen. Under formuläret finns korten **Lönerevision** och **Anställningens slut**.

## Lönerevision: ny lön från ett datum

En ny månadslön gäller alltid från den första dagen i en månad. Har den månaden redan betalats ut med den gamla lönen räknar NONOX ut skillnaden och betalar den som retroaktiv lön i nästa lönekörning.

### Så här ger du en ny lön

1. Öppna den anställda under **Lön → Anställda** och klicka på **Ändra**.
2. Under **Lönerevision**: välj **Ny lön från**, den första dagen i den månad lönen ska gälla från.
3. Skriv den nya **Månadslön**, till exempel 33 500.
4. Skriv gärna **Orsak (frivilligt)**, till exempel Lönerevision 2026.
5. Klicka på **Spara ny lön**.

Lönehistoriken visas överst i kortet. Kortet finns bara för månadsavlönade; en timlön ändrar du direkt i formuläret.

### Så räknas den retroaktiva lönen

Säg att Anna Andersson får ny lön från 1 augusti, men att augusti och september redan är utbetalda. I oktobers lönekörning får hon då en rad **Retroaktiv lön för** augusti och en för september, med skillnaden mellan den nya och den gamla månadslönen.

- Den retroaktiva lönen beskattas som engångsbelopp.
- Beloppet räknas fram på nytt varje gång, så samma skillnad betalas aldrig två gånger.
- Avdrag och semesterlön i de tidigare månaderna räknas inte om. Har personen haft frånvaro i de månaderna, kontrollera beloppet.

## Anställningens slut: slutlön

När någon slutar anger du den sista anställningsdagen. NONOX räknar då slutlönen i lönekörningen för den månaden.

### Så här avslutar du en anställning

1. Öppna den anställda och klicka på **Ändra**.
2. Under **Anställningens slut**: välj **Sista anställningsdag**.
3. Skriv gärna **Orsak (frivilligt)**, till exempel Egen uppsägning.
4. Klicka på **Avsluta anställningen**.
5. Läs texten och klicka på **Ja, avsluta anställningen**.

Lönekörningen för den sista månaden betalar:

- månadslönen till och med den sista dagen,
- semesterersättning för betalda semesterdagar som är kvar och semester som tjänats in under det pågående intjänandeåret, beskattad som engångsbelopp.

Efter den månaden är personen inte med i lönekörningarna.

> [!WARNING]
> Det går inte att ångra ett avslut i NONOX. Den sista dagen kan inte heller ligga i en månad vars lön redan är attesterad. Kontrollera datumet innan du bekräftar.

## Underlag för arbetsgivarintyg

När en anställning slutar behöver personen ofta ett arbetsgivarintyg till a-kassan. NONOX fyller inte i själva intyget, men ger dig underlaget.

1. Öppna den anställda och klicka på **Ändra**.
2. Klicka på **Hämta underlag för arbetsgivarintyg (CSV)** under **Anställningens slut**.

Filen innehåller lön och frånvarodagar per månad för de senaste tretton månaderna. Använd den när du fyller i arbetsgivarintyget. Filen innehåller personuppgifter, och varje uttag loggas.

## Radera en anställd enligt GDPR

Under **Anställningens slut** finns också **Radera enligt GDPR**. Vad som händer beror på om personen har fått lön:

- **Har personen aldrig fått lön** raderas allt.
- **Har personen fått lön** tas kontakt- och bankuppgifter bort direkt. Namn och personnummer finns kvar för lönebesked och arbetsgivardeklarationer så länge bokföringslagen kräver att de sparas. NONOX visar till vilket datum; radera igen efter det datumet.
- **Har lagringstiden gått ut** anonymiseras personen.

## Vanliga frågor

**Kan vi betala ut slutlönen i en extra lönekörning?**
Nej, slutlönen betalas i den ordinarie lönekörningen för månaden då anställningen slutar.

**Hur gör vi med ett förskott på semester som personen inte har tjänat in?**
Det drar NONOX inte av automatiskt. Om ett avdrag ska göras och hur stort det får vara beror på semesterlagen och avtalet; fråga er redovisningskonsult och lägg in avdraget som en rad i lönekörningen.

**Den anställda vill ha ut sina uppgifter.**
Den som har behörighet att se känsliga löneuppgifter kan klicka på **Ladda ner registerutdrag (JSON)** överst på den anställdas sida.

## Läs också

- [Anställda](/manual/lon/anstallda)
- [Semester](/manual/lon/semester)
- [Gör en lönekörning](/manual/lon/lonekorning)
- [Rapporter och löneårets slut](/manual/lon/rapporter-och-lonearsskifte)
