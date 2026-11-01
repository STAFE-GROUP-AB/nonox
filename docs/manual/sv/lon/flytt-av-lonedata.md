---
beskrivning: Byter ni lönesystem mitt i året? Läs in anställda, belopp hittills i år, semestersaldon och gamla lönebesked innan den första lönen räknas i NONOX.
ordning: 25
roller: [owner, payroll_admin]
modul: payroll
rutter: [payroll.migration]
sokord: [byta lönesystem, flytta lön, importera anställda, hittills i år, ingående semestersaldo, gamla lönebesked, löneflytt]
---

# Flytta lönedata från ett annat lönesystem

Kommer ni från ett annat lönesystem mitt i ett kalenderår behöver NONOX veta vad som redan har hänt under året: hur mycket lön, skatt och avgifter varje anställd har haft, och hur mycket semester de har. Annars blir skatten på engångsbelopp, gränser för arbetsgivaravgifter och semestersaldona fel.

Flytten görs under **Lön → Flytt av lönedata** i fem steg, med CSV-filer. Börjar ni med lön i NONOX vid ett årsskifte och har inga semestersaldon att föra över kan ni hoppa över flytten.

> [!IMPORTANT]
> Medan flytten pågår kan ingen lönekörning beräknas. Det är med flit: den första lönen i NONOX ska räknas med årets rätta belopp.

Bokföringen för året flyttas för sig, som SIE-fil. Se [SIE](/manual/bokforing/sie) och avsnittet [Flytta till NONOX](/manual/flytta).

## Så här startar du flytten

1. Öppna **Lön → Flytt av lönedata**.
2. Skriv **Året som beloppen gäller**, normalt innevarande år.
3. Klicka på **Starta flytten**.

## Filerna

Alla filer är semikolonseparerade CSV med kolumnnamnen på första raden. Klicka på **Hämta mall** vid varje steg för att få en fil med rätt kolumner. Belopp skrivs i kronor, till exempel 32 000,00.

En fil läses in bara när alla rader är rätt. Är något fel får du veta vilken rad och vad; rätta filen och läs in den igen.

### 1. Anställda

En rad per anställd. Obligatoriska kolumner: `anstallningsnummer`, `fornamn`, `efternamn`, `personnummer`, `anstalld_fran`, `anstallningsform`, `loneform`, `lon`, `skattetabell`, `kolumn`. Frivilliga: `epost`, `clearingnummer`, `kontonummer`, `sysselsattningsgrad`, `timmar_per_vecka`, `befattning`.

Använd samma anställningsnummer som i det tidigare systemet. De andra filerna hänvisar till det.

### 2. Hittills i år

Bruttolön, skatt, avgiftsunderlag och arbetsgivaravgifter för året till och med den sista lönen i det tidigare systemet. Obligatoriska kolumner: `anstallningsnummer`, `bruttolon`, `skatt`, `avgiftsunderlag`, `arbetsgivaravgifter`. Frivilliga: `formaner`, `engangsbelopp`.

Beloppen används för skatt på engångsbelopp, för arbetsgivaravgifter och i rapporterna för året.

### 3. Semester

Saldot för det semesterår som pågår. Kolumner: `anstallningsnummer`, `semesterar_start`, `intjanade_dagar`, `uttagna_dagar`, `sparade_dagar`, och för procentregeln den frivilliga `semesterlonegrundande_lon`.

### 4. Gamla lönebesked

Ladda upp lönebeskeden från det tidigare systemet som PDF, döpta `anställningsnummer_ÅÅÅÅ-MM.pdf`, och klicka på **Spara lönebeskeden**. De sparas med kontrollsumma i Lönearkivet och visas för den anställda på Min sida under **Lönebesked från det tidigare lönesystemet**.

Lönebesked ska sparas lika länge som annan räkenskapsinformation. Ladda därför upp dem även om ni inte använder Min sida.

### 5. Avstämning mot bokföringen

NONOX jämför den inlästa skatten och de inlästa arbetsgivaravgifterna med bokföringen för året: 2710 i kredit och 7510 netto. En krona per anställd i skillnad godtas för öresavrundning.

- **Beloppen stämmer med bokföringen.** Klicka på **Flytten är klar**.
- **Beloppen stämmer inte med bokföringen.** Läs in bokföringen för året som SIE-fil om du inte har gjort det, eller rätta filen med beloppen hittills i år.

När flytten är klar kan du göra den första lönekörningen.

## Vanliga frågor

**Var hittar jag uppgifterna i det gamla systemet?**
Oftast i en rapport över ackumulerade belopp per anställd och i en semesterrapport. Har du svårt att få ut dem, fråga det gamla systemets support. Mer stöd finns i avsnittet [Flytta till NONOX](/manual/flytta).

**Kan jag läsa in samma fil igen?**
Ja, efter rättning. En fil läses in bara när alla rader är rätt.

**Vi har redan gjort en lönekörning i NONOX i år. Kan vi starta en flytt nu?**
Flytten är tänkt att göras före den första lönekörningen. Har ni redan räknat lön, prata med oss eller er redovisningskonsult innan ni startar en flytt.

## Läs också

- [Flytta till NONOX](/manual/flytta)
- [SIE](/manual/bokforing/sie)
- [Anställda](/manual/lon/anstallda)
- [Semester](/manual/lon/semester)
