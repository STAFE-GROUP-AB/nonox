---
beskrivning: Flytta lönen från ett tidigare lönesystem – anställda, belopp hittills i år, semestersaldon och gamla lönebesked – innan den första lönen räknas i NONOX.
ordning: 70
roller: [owner]
modul: payroll
sokord: [flytta lön, lönedata, löneimport, hittills i år, ackumulatorer, semestersaldo, gamla lönebesked, Fortnox Lön, Visma Lön, byta lönesystem]
---

# Flytta lönen

Har ni anställda läser du in lönedata från det tidigare lönesystemet innan den första lönen räknas i NONOX. Då blir skatt på engångsbelopp, arbetsgivaravgifter och semester rätt resten av året, och de anställda hittar sina gamla lönebesked på Min sida.

Det görs under **Lön → Flytt av lönedata**, med en tom mall för varje steg. Modulen **Lön** måste vara påslagen; se [Moduler](/manual/installningar/moduler). Flytten görs av ägaren eller en löneadministratör.

> [!IMPORTANT]
> Medan flytten pågår kan ingen lönekörning räknas. Läs också in bokföringen för året som SIE-fil först, eftersom lönedatan stäms av mot den.

![Flytt av lönedata från ett annat lönesystem.](/bilder/manual/flytta/flytta-lonen.webp)

## Om filerna

Filerna är semikolonseparerade CSV-filer med kolumnnamnen på första raden. Klicka på **Hämta mall** vid varje steg för att få en tom mall. Belopp skrivs i kronor, till exempel 32 000,00. En fil läses in bara när alla rader är rätt, och samma fil kan läsas in igen efter rättning.

## Så här gör du

1. Gå till **Lön → Flytt av lönedata** och klicka på **Starta flytten**. Ange **Året som beloppen gäller**.
2. **1. Anställda:** fyll i mallen med en rad per anställd: anställningsnummer, namn, personnummer (tolv siffror), anställd från, anställningsform, lönform (månadslön eller timlön), lön, skattetabell och kolumn, och gärna e-post, clearingnummer och kontonummer. Anställningsnumret används av de andra filerna. Klicka på **Läs in**.
3. **2. Hittills i år:** för varje anställd bruttolön, skatt, avgiftsunderlag och arbetsgivaravgifter för året till och med den sista lönen i det tidigare systemet, och förmåner och engångsbelopp om sådana finns. Klicka på **Läs in**.
4. **3. Semester:** saldot för det semesterår som pågår, med semesterårets första dag, intjänade, uttagna och sparade dagar, och den semesterlönegrundande lönen för den som har procentregeln. Klicka på **Läs in**.
5. **4. Gamla lönebesked:** döp lönebeskeden som PDF till `anställningsnummer_ÅÅÅÅ-MM.pdf`, till exempel `17_2026-03.pdf`, och klicka på **Spara lönebeskeden**. De hamnar i lönearkivet och på den anställdes Min sida.
6. **5. Avstämning mot bokföringen:** NONOX jämför skatt och arbetsgivaravgifter hittills i år med bokföringen för året, konto 2710 (kredit) och 7510 (netto). Står det **Beloppen stämmer med bokföringen** kan flytten markeras som klar.

När flytten är klar står det **Flytten är klar** och datumet, och du kan räkna den första lönen. Se [Lönekörning](/manual/lon/lonekorning).

## Om beloppen inte stämmer

Står det **Beloppen stämmer inte med bokföringen**, kontrollera att:

- bokföringen för hela året fram till flyttdagen är inläst som SIE-fil,
- filen med beloppen hittills i år gäller rätt år och har med alla anställda,
- lönen för den sista månaden i det gamla systemet är bokförd.

Rätta filen och läs in den igen.

## Arbetsgivardeklarationer

Arbetsgivardeklarationer för månader som betalades ut i det tidigare systemet rättas där eller direkt hos Skatteverket. NONOX deklarerar från den första lönen som betalas ut här.

## Var hittar jag uppgifterna?

Uppgifterna finns oftast i personallistor, årssummor eller ackumulatorer per anställd och semesterrapporter i det tidigare lönesystemet. Kommer du från Fortnox Lön finns de i de årssummor eller ackumulatorer Fortnox Lön visar per anställd. Spara lönebeskeden som PDF; de ska sparas lika länge som annan räkenskapsinformation.

## Läs också

- [Anställda](/manual/lon/anstallda)
- [Lönekörning](/manual/lon/lonekorning)
- [Byta mitt i året](/manual/flytta/byta-mitt-i-aret)
- [Stäm av](/manual/flytta/stam-av)
