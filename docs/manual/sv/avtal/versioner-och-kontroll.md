---
beskrivning: Avtalets värden kommer i versioner med källa. Så här granskar du en version, kontrollerar den mot parternas text och använder avtalskalendern.
ordning: 40
roller: [owner, payroll_admin]
modul: agreements
rutter: [agreements.versions]
sokord: [avtalsversion, kontrollera avtal, verifiera avtal, källa avtal, lönesteg, avtalskalender, storhelg, aftnar, lönekörningen stoppas avtal]
---

# Versioner och kontroll av avtalets värden

Ett kollektivavtal gäller en avtalsperiod, och lönerna höjs ofta i steg under perioden. I NONOX finns avtalets värden som **versioner**: en version per avtal, variant och avtalsperiod, med alla minimilöner, OB- och övertidssatser och lönesteg för perioden. Varje värde visar sin källa – dokument och sida i parternas publicerade text.

En version används i lönen först när en namngiven person har jämfört den med källorna. Det är samma princip som för lönens regler; se [Regler och skattetabeller](/manual/lon/regler-och-skattetabeller).

![Avtalsversionerna med giltighet och kontroll. I demon är en version markerad som demodata.](/bilder/manual/avtal/versioner-och-kontroll.webp)

## Så här granskar du en version

1. Öppna **Lön → Avtal** och fliken **Versioner och kalender**.
2. Tabellen visar varje version: **Avtal**, **Variant**, **Gäller** och **Kontrollerad**.
3. Klicka på avtalets namn för att visa värdena.

Du ser då:

- **Minimilöner** per lönegrupp, **Per månad** eller **Per timme**, med villkor som ålder, månader i branschen och månader i företaget, och lönesteget med datum.
- **OB, övertid och andra tillägg**, med när de gäller och satsen, till exempel ett belopp per timme eller en procent av timlönen.
- **Det här räknar NONOX inte ännu** – avtalets delar som inte är data än. Läs den listan; den talar om vad du själv behöver hålla koll på.
- **Källor** – länkar till parternas egna dokument, med datum då de hämtades.

## Så här kontrollerar du en version

1. Klicka på **Kontrollera** i kolumnen **Kontrollerad**.
2. Öppna varje källa i listan.
3. Jämför varje värde i versionen med sidan som anges. Visa värdena genom att klicka på avtalets namn.
4. Stämmer allt: kryssa i **Jag har jämfört värdena med källorna och tar ansvar för att de stämmer för vårt företag**.
5. Klicka på **Markera som kontrollerad**.

Ditt namn och datumet visas sedan i kolumnen **Kontrollerad**. Det är ägaren och löneadministratören som kan kontrollera versioner.

> [!WARNING]
> Markera aldrig en version som kontrollerad utan att ha jämfört värdena. Hittar du ett fel: markera den inte, och kontakta oss. En kontrollerad version ändras aldrig; en rättelse blir en ny version.

En version kan också vara kontrollerad för hela installationen. Då visas den personens namn, och du behöver inte göra något.

### Versioner som inte kan kontrolleras

En version med värden som bara finns i en sekundär källa visas som **Kan inte kontrolleras** med texten **Värden saknar primär källa.** Den kan aldrig användas i lönen. Så är det i dag för Frisöravtalet, vars text för 2025–2027 inte är publik. Ange lönen på raderna själv tills en version med källa finns.

## När lönekörningen stoppas

För varje anställning som är knuten till ett avtal kräver lönekörningen att:

- en kontrollerad version gäller för periodens första och sista dag och för utbetalningsdagen,
- företaget är bundet av avtalet under perioden.

Annars stoppas lönekörningen med till exempel beskedet att det inte finns någon kontrollerad version av avtalet för ett datum. NONOX faller aldrig tillbaka på en äldre version eller på allmänna regler.

Lösningen är nästan alltid att kontrollera versionen för den nya perioden. När ett nytt avtal har tecknats behöver en ny version komma från NONOX; finns den inte än, ta ut anställningen ur avtalet tillfälligt (**Inte omfattad av avtal**) och kontrollera minimilön, OB och övertid själv tills den finns.

## Avtalskalendern

Under värdena visar **Avtalskalender** årets helgdagar enligt lag, aftnar som midsommar-, jul- och nyårsafton, och avtalets dagtyper (till exempel storhelg) med klockslag. Välj **År** för att se ett annat år.

Kalendern används för att kontrollera rader med en dagtyp: läggs en storhelgsrad in på en dag som inte är storhelg enligt avtalet får lönekörningen en varning.

## Vanliga frågor

**Lönerna höjdes den 1 april. Behöver jag kontrollera en ny version?**
Nej, inte om höjningen är ett lönesteg inom samma avtalsperiod. Stegen finns redan i versionen, med datum, och NONOX använder det steg som gäller. Det du kontrollerar är att stegen stämmer med källan.

**Varför ska just vi kontrollera, när NONOX har tagit fram värdena?**
För att lönen är ert ansvar som arbetsgivare, och ett fel i en avtalssats märks först när någon har fått fel lön. Kontrollen görs en gång per version, inte varje månad.

## Läs också

- [Välj avtal för företaget](/manual/avtal/valj-avtal)
- [OB och övertid enligt avtalet](/manual/avtal/ob-och-overtid)
- [Regler och skattetabeller](/manual/lon/regler-och-skattetabeller)
- [Det här gör Avtal inte ännu](/manual/avtal/det-har-gor-avtal-inte-annu)
