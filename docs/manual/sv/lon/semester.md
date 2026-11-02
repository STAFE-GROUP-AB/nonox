---
beskrivning: Semester enligt semesterlagen i NONOX – saldon, sammalöneregeln och procentregeln, semesterersättning, semesterlöneskulden och att stänga semesteråret.
ordning: 100
roller: [owner, admin, payroll_admin]
modul: payroll
rutter: [payroll.vacationYear, payroll.vacationLiability]
sokord: [semester, semesterlön, semestertillägg, semesterersättning, semesterlöneskuld, sparad semester, semesterår, intjänandeår, sammalöneregeln, procentregeln]
---

# Semester

NONOX håller reda på varje anställds semester enligt semesterlagen: hur många betalda dagar personen har tjänat in, hur många som är uttagna, vad semestern ska kosta och vad som händer med dagarna som är kvar när semesteråret tar slut.

![Semesteråret, där sparade dagar sparas eller betalas ut.](/bilder/manual/lon/semester.webp)

## Saldot räknas fram, det lagras inte

Antalet betalda semesterdagar räknas fram ur anställningen, frånvaron och de attesterade lönebeskeden. Det finns inget saldo som kan glida isär från underlaget. Du ser saldot:

- i listan under **Lön → Anställda**, till exempel **18 av 25 semesterdagar kvar t.o.m.** ett datum,
- på lönebeskedet, under **Semester**,
- på den anställdas Min sida.

Dagarna tjänas in under intjänandeåret och tas ut under semesteråret efter. Semesteråret börjar den månad du har valt i löneinställningarna; semesterlagens huvudregel är april.

> [!NOTE]
> Saldot visas bara när semesterreglerna under **Lön → Regler** är kontrollerade. Se [Regler och skattetabeller](/manual/lon/regler-och-skattetabeller).

## Semester i lönekörningen

En anställd ansöker om semester på Min sida, och du godkänner under **Lön → Ansökningar**. Du kan också registrera semester direkt i lönekörningen med orsaken **Semester**. Semesterdagar räknas på vardagar utom helgdagar.

Hur semestern betalas beror på semesterregeln:

- **Sammalöneregeln** (vanlig för månadsavlönade): månadslönen fortsätter som vanligt och varje betald semesterdag ger ett **Semestertillägg**.
- **Procentregeln** (vanlig för timavlönade och de med mycket rörlig lön): varje betald dag ger **Semesterlön** beräknad på intjänandeårets semesterlönegrundande lön. En månadslön minskas för de semestertimmarna.

Övertid och utbetald komptid ingår i den semesterlönegrundande lönen (semesterlagen 16 b §), och räknas som rörlig lön under sammalöneregeln. Säger kollektivavtalet att semesterlönen ingår i övertidsersättningen, som Visita–Unionen och Handelns tjänstemannaavtal, räknas avtalets övertid inte in. Bedömningen bygger på en AI-utredning den 2 november 2026; juristgranskning återstår.

Tar personen ut fler dagar än saldot räcker till blir resten **Obetald semester**, och lönebeskedet får en varning om det.

Procentsatserna och tilläggen står under **Lön → Regler**, med länk till semesterlagen. Ett kollektivavtal kan ge högre belopp än lagen; det räknar NONOX inte ännu (se [Det här gör Avtal inte ännu](/manual/avtal/det-har-gor-avtal-inte-annu)).

## Semesterersättning med varje lön

För korta anställningar, eller när kollektivavtalet tillåter det, kan en anställd få semesterersättning med varje lön i stället för betalda semesterdagar. Kryssa i **Betala semesterersättning med varje lön i stället för betalda semesterdagar** på den anställda. Ersättningen räknas på lönebeskedets semesterlönegrundande lön, och personen får då inga betalda dagar.

I löneinställningarna väljer du om ersättningen ska beskattas enligt skattetabell eller som engångsbelopp.

## Semesterlöneskulden

Semester som är intjänad men inte uttagen är en skuld företaget har till de anställda. Under **Lön → Semesterskuld** ser du skulden en viss dag per anställd: **Dagar kvar**, dagar som **Intjänas nu**, värdet **Per dag**, **Skuld** och **Sociala avgifter**.

- Under sammalöneregeln värderas en dag till företagets dagsvärde (en procent av månadslönen, i löneinställningarna) plus semestertillägget.
- Under procentregeln värderas skulden till procentregelns andel av underlaget.

### Det här händer i bokföringen

Har du valt **Varje månad** under **Semesterlöneskuld bokförs** får varje lönekörnings verifikation i serie L också rader som för skulden till rätt nivå vid periodens slut:

- 7090 Förändring av semesterlöneskuld mot 2920 Upplupna semesterlöner
- 7519 Sociala avgifter för semester- och löneskulder mot 2940 Upplupna lagstadgade sociala och andra avgifter

Har du valt **Vid bokslut** bokförs skulden inte av lönen; då bokför du den själv vid bokslutet, med siffrorna från **Lön → Semesterskuld**.

## Så här stänger du semesteråret

När semesteråret är slut ska dagarna som inte tagits ut antingen sparas eller betalas ut.

1. Öppna **Lön → Semesterår** dagen efter att semesteråret har slutat.
2. Granska tabellen per anställd: **Betalda dagar**, **Uttagna**, **Kvar**.
3. Skriv under **Spara** hur många dagar varje person vill spara. NONOX visar **högst** hur många som får sparas.
4. Kontrollera **Betalas ut** och **Rörlig lön, 12 %**.
5. Klicka på **Stäng semesteråret**.

Semesterlagen tillåter bara att dagar över 20 sparas, och inte ett år då sparade dagar har använts. Sparade dagar ska tas ut inom fem år. Dagar som inte sparas betalas ut.

Utbetalningarna kommer med i nästa lönekörning och beskattas som engångsbelopp. De knyts till den lönekörningen när den attesteras, så att de aldrig betalas två gånger.

> [!WARNING]
> Ett stängt semesterår kan inte ändras. Fråga de anställda hur många dagar de vill spara innan du stänger.

## Vanliga frågor

**En anställd har arbetat färre än fem dagar i veckan. Hur många dagar får hen?**
Det beror på inställningen **Semesterdagar för deltid**. Med förvalet räknas bara schemalagda arbetsdagar, och den som arbetar färre dagar i veckan tjänar in färre semesterdagar men tar också ut färre dagar per ledig vecka.

**Vi kommer från ett annat lönesystem mitt i semesteråret.**
Läs in de ingående semestersaldona under **Lön → Flytt av lönedata**. Se [Flytta lönedata](/manual/lon/flytt-av-lonedata).

**Vad händer med semestern när någon slutar?**
Den sista lönekörningen betalar semesterersättning för dagar som är kvar och semester som tjänats in. Se [Lönerevision, slutlön och arbetsgivarintyg](/manual/lon/lonerevision-och-slutlon).

## Läs också

- [Anställda](/manual/lon/anstallda)
- [Frånvaro, sjuklön och karensavdrag](/manual/lon/franvaro-och-sjuklon)
- [Lönefil till banken och bokföring av lönen](/manual/lon/lonefil-och-bokforing)
- [Rapporter och löneårets slut](/manual/lon/rapporter-och-lonearsskifte)
