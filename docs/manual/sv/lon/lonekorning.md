---
beskrivning: Månadens lön steg för steg – skapa lönekörningen, lägg in timmar, tillägg och frånvaro, beräkna, granska lönebeskeden och attestera.
ordning: 40
roller: [owner, admin, payroll_admin]
modul: payroll
rutter: [payroll.runs.index, payroll.runs.show, payroll.payslips.pdf]
sokord: [lönekörning, köra lön, beräkna lön, attestera lön, lönespecifikation, lönebesked, löneberäkning]
---

# Gör en lönekörning

En lönekörning är månadens lön för alla anställda på en gång. Du skapar den, lägger in det som är speciellt för månaden, låter NONOX räkna, granskar lönebeskeden och attesterar. Därefter hämtar du lönefilen till banken och bokför lönen.

Lönekörningen går igenom de här stegen, och statusen syns överst på sidan:

**Utkast** → **Beräknad, väntar på attest** → **Attesterad** → **Utbetald** → **Bokförd**

## Så här skapar du lönekörningen

1. Öppna **Lön → Lönekörningar**.
2. Välj **Månad** under **Ny lönekörning**.
3. Klicka på **Skapa lönekörning**.

Utbetalningsdagen hämtas från löneinställningarna och flyttas till fredagen före om den infaller en helg. Varje månad kan bara ha en lönekörning, och en månad i ett avslutat löneår kan inte få någon.

## Så här lägger du in månadens timmar och tillägg

Under **Timmar och tillägg** lägger du in allt som inte är fast månadslön: timmar för timanställda, övertid, OB, bonus, förmåner, traktamente, förskott och liknande.

1. Välj **Anställd**.
2. Välj **Löneart**, till exempel **Timlön**, **Övertid**, **OB-tillägg** eller **Bonus**.
3. Skriv **Antal timmar** eller **eller belopp**, inte båda.
4. Klicka på **Lägg till**.

Raderna visas under formuläret. Klicka på **×** för att ta bort en rad. Timmar utan belopp prissätts med anställningens timlön.

> [!TIP]
> Fältet **Antal timmar** används för alla lönearter som räknas i antal: dagar för traktamente, mil för milersättning och antal måltider för kostförmån. Se [Förmåner, traktamente och milersättning](/manual/lon/formaner-traktamente-och-milersattning).

Timanställdas timmar kan också komma av sig själva, från godkända tidrapporter och från personalliggaren, eller från en fil från ert schemasystem. Se [Timlön och tidrapporter](/manual/lon/timlon-och-tidrapporter) och [Löneunderlag från schemasystem](/manual/lon/loneunderlag-fran-schemasystem).

## Så här registrerar du frånvaro

Under **Frånvaro** registrerar du sjukdagar och ledighet som inte redan kommit in som ansökan från Min sida.

1. Välj **Anställd** och **Orsak**: **Sjuk**, **Semester**, **Vård av barn**, **Föräldraledig**, **Tjänstledig utan lön**, **Ledig med lön** eller **Annan frånvaro**.
2. Välj **Från** och **Till och med**. Datumen måste ligga i lönekörningens månad.
3. Skriv **Timmar per dag** som personen skulle ha arbetat.
4. Klicka på **Registrera**.

Frånvaro över ett månadsskifte registreras i varje månads lönekörning. Mer om hur sjuklön och avdrag räknas i [Frånvaro, sjuklön och karensavdrag](/manual/lon/franvaro-och-sjuklon).

## Så här beräknar du och granskar

1. Klicka på **Beräkna**.
2. Kan lönekörningen inte beräknas visas en ruta med **Det här måste lösas innan lönekörningen kan beräknas**. Åtgärda varje punkt och klicka på **Beräkna** igen.
3. När beräkningen är klar visas **Lönebesked**, en rad per anställd med **Brutto**, **Skatt**, **Netto** och **Arbetsgivaravgift**.
4. Klicka på en rad för att se alla lönerader, hur skatten räknades (tabell, kolumn och inkomst) och eventuella varningar.
5. Klicka på **Förhandsgranska** för att se lönebeskedet som PDF, märkt som utkast.

NONOX räknar alla anställda eller ingen. Stoppas en enda person ändras ingenting, så du kan aldrig attestera en halv lönekörning.

Ändrar du något efter beräkningen (en ny rad, en frånvaro, en godkänd ansökan) går lönekörningen tillbaka till utkast, och du klickar på **Beräkna igen**.

### Vanliga skäl till att beräkningen stoppas

- **Regler är inte kontrollerade för utbetalningsdagen.** Klicka på **Kontrollera reglerna**. Se [Regler och skattetabeller](/manual/lon/regler-och-skattetabeller).
- **Skattetabell saknas** för en anställd. Kontrollera tabell och kolumn under **Lön → Anställda**.
- **Frånvaron saknar timmar per dag.**
- **Ingen är anställd under perioden.**
- **Flytten av lönedata är inte klar.** Se [Flytta lönedata](/manual/lon/flytt-av-lonedata).
- **Kollektivavtalets version är inte kontrollerad**, om modulen Avtal är på. Se [Versioner och kontroll](/manual/avtal/versioner-och-kontroll).

### Varningar på lönebeskedet

Varningar stoppar inte lönen men ska läsas. Exempel:

- Sjukperioden är längre än arbetsgivarperioden. Anmäl sjukfallet till Försäkringskassan.
- Semesterdagar saknar betald semester och räknas som obetald semester.
- Nettolönen blev negativ. Beloppet förs över som en fordran på den anställda till nästa lön.
- Lönen är under kollektivavtalets minimilön (med modulen Avtal).

## Så här attesterar du

1. Kontrollera lönebeskeden.
2. Klicka på **Attestera**.
3. Läs texten **Lönekörningen låses och kan inte ändras. Fel rättas i nästa lönekörning.** och klicka på **Ja, attestera**.

Att attestera betyder att godkänna lönen för utbetalning. Se [attest i ordlistan](/manual/ordlista/ordlista#attest). Ägaren, administratören och löneadministratören kan attestera.

Är **Den som beräknar får inte attestera** på i löneinställningarna kan den som klickade på **Beräkna** inte attestera. Du ser då texten **Någon annan än du behöver attestera, eftersom du beräknade lönekörningen.** Är du ensam i företaget kan ägaren stänga av kravet under **Företagsinställningar → Moduler → Lön → Inställningar**.

> [!WARNING]
> En attesterad lönekörning kan inte ändras eller tas bort, varken i NONOX eller direkt i databasen. Lönebeskeden blir synliga för de anställda på Min sida. Fel rättas i en senare lönekörning.

Före attesten kan du klicka på **Öppna igen** för att gå tillbaka till utkast.

## Efter attesten

Nästa steg är att betala ut och bokföra lönen. Det beskrivs i [Lönefil till banken och bokföring av lönen](/manual/lon/lonefil-och-bokforing). Sedan skapar du [arbetsgivardeklarationen](/manual/lon/arbetsgivardeklaration) för utbetalningsmånaden.

## Vanliga frågor

**Jag glömde en övertid förra månaden. Hur rättar jag?**
Lägg in den i nästa månads lönekörning under **Timmar och tillägg**. Har en anställd fått en ny lön från ett datum som redan är utbetalt, lägg in den under **Lönerevision** på den anställda; då räknar NONOX ut skillnaden som retroaktiv lön i nästa lönekörning.

**Kan jag göra en extra lönekörning mitt i månaden?**
Nej, NONOX har en lönekörning per månad. Det som ska betalas ut läggs in i månadens lönekörning eller i nästa.

**Varför ser jag bara "Frånvaro" och inte orsaken?**
Orsaken till frånvaro, till exempel sjukdom, är en känslig personuppgift. Den visas bara för den som har behörighet att se känsliga löneuppgifter, normalt löneadministratören.

**Vad står på lönebeskedet?**
Arbetsgivare och anställd, period och utbetalningsdag, alla lönerader, bruttolön, preliminär skatt med tabell och kolumn, nettolön och bankkonto (maskerat), belopp hittills i år, semestersaldot och vad arbetsgivaren betalar utöver lönen.

## Läs också

- [Lönefil till banken och bokföring av lönen](/manual/lon/lonefil-och-bokforing)
- [Timlön och tidrapporter](/manual/lon/timlon-och-tidrapporter)
- [Frånvaro, sjuklön och karensavdrag](/manual/lon/franvaro-och-sjuklon)
- [Arbetsgivardeklaration (AGI)](/manual/lon/arbetsgivardeklaration)
