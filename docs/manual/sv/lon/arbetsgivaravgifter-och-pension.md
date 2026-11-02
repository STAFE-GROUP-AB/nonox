---
beskrivning: Hur NONOX räknar arbetsgivaravgifter efter ålder, med den tillfälligt sänkta avgiften för unga, och hur du lägger upp tjänstepension med särskild löneskatt.
ordning: 130
roller: [owner, payroll_admin]
modul: payroll
sokord: [arbetsgivaravgift, sociala avgifter, ungdomsrabatt, sänkt arbetsgivaravgift unga, äldre, tjänstepension, pensionsplan, ITP, SAF-LO, särskild löneskatt, regionalt stöd, stödområde]
---

# Arbetsgivaravgifter och tjänstepension

Utöver lönen betalar arbetsgivaren arbetsgivaravgifter till Skatteverket och, för många anställda, tjänstepension. Båda räknas automatiskt i lönekörningen och syns på lönebeskedet under **Arbetsgivaren betalar också**, så att den anställda ser vad lönen kostar totalt.

![Pensionsrapporten med premie och särskild löneskatt. Beloppen är demodata.](/bilder/manual/lon/arbetsgivaravgifter-och-pension.webp)

![Lönejournalen med bruttolön, skatt och arbetsgivaravgift per anställd. Beloppen är demodata.](/bilder/manual/lon/rapporter-och-lonearsskifte.webp)

## Arbetsgivaravgifter

Arbetsgivaravgiften räknas på avgiftsunderlaget: kontant lön och förmåner. Skattefria ersättningar som traktamente och milersättning inom Skatteverkets belopp, och utlägg, ger ingen avgift.

Vilken avgift som gäller beror på den anställdas ålder, räknad från födelseåret i personnumret:

- **Full avgift** för de flesta.
- **Lägre avgift för äldre**, där bara en del av avgiften betalas, och ingen avgift alls för de äldsta.
- **Sänkt avgift för unga** – en tillfällig nedsättning för anställda födda vissa år, upp till ett visst belopp per månad. Den gäller under den tid riksdagen har bestämt.

NONOX väljer avgiften av sig själv utifrån födelseåret och utbetalningsdagen. Nedsättningen för unga gäller bara upp till månadstaket; lön över taket får full avgift. Taket gäller allt som betalas ut till personen under en kalendermånad. Får personen två löner samma månad, till exempel en sen lön och månadens lön, delar de på taket: den som attesteras först använder det först, och den andra får bara det som är kvar.

Har en annan lönekörning för samma person attesterats efter att din körning beräknades kan avgifterna i din körning vara fel. NONOX säger då **Beräkna lönekörningen igen innan du attesterar**. Klicka på **Beräkna** och attestera sedan.

Ingen avgift tas ut så länge en persons ersättning under året stannar under 1 000 kronor, Skatteverkets gräns för avgiftsplikt. När årets ersättning når gränsen tas avgift på hela årets ersättning, även på det som betalades ut tidigare utan avgift. Det läggs på den lön som tar personen över gränsen.

Exempel: en timanställd får 600 kronor i mars och 600 kronor i april. Mars ger ingen avgift. I april är årets ersättning 1 200 kronor, och aprillönen får avgift på hela 1 200 kronor.

I arbetsgivardeklarationen redovisas lön utan avgift i en egen ruta (131). Den månad gränsen passeras läggs det tidigare beloppet till i ruta 011 och anges också i ruta 010. Se [Arbetsgivardeklaration](/manual/lon/arbetsgivardeklaration).

> [!IMPORTANT]
> Procentsatser, födelseår, månadstak och datum står under **Lön → Regler**, med länk till Skatteverket, och används först när de är kontrollerade. NONOX skriver inga siffror i koden. Se [Regler och skattetabeller](/manual/lon/regler-och-skattetabeller).

> [!NOTE]
> En del räknar NONOX inte ännu: regionalt stöd (avdrag för företag i vissa delar av landet) dras inte av. Prata med er redovisningskonsult om det gäller er.

### Det här händer i bokföringen

Arbetsgivaravgifterna bokförs med lönen: 7510 Arbetsgivaravgifter i debet och 2730 Lagstadgade sociala avgifter och särskild löneskatt i kredit. När Skatteverket drar dem från skattekontot bokför du det från lönekörningen. Se [Lönefil till banken och bokföring av lönen](/manual/lon/lonefil-och-bokforing).

## Regionalt stöd

Har företaget ett fast driftställe i stödområdet (delar av Norrbotten, Västerbotten, Jämtland, Västernorrland, Gävleborg, Dalarna och Värmland) kan det få göra avdrag från arbetsgivaravgifterna enligt lagen om regionalt stöd (2001:1170). Vissa verksamheter får inte avdraget, till exempel finans och försäkring, transport, stål och kol, kommuner och statliga myndigheter.

1. Öppna **Moduler** och löneinställningarna.
2. Kryssa i **Regionalt stöd (nedsatta arbetsgivaravgifter i stödområdet)**.
3. Klicka på **Spara löneinställningarna**.

Lönekörningen drar då 10 procent av avgiftsunderlaget från arbetsgivaravgifterna:

- Högst 7 100 kronor per månad för hela företaget, sammanlagt för alla lönekörningar som betalas ut i månaden.
- Avgifterna blir aldrig lägre än ålderspensionsavgiften (10,21 procent).
- Inget avdrag för den som var 67 år eller äldre vid årets början.

Exempel: en månadslön på 32 000 kronor ger 10 054,40 kronor i arbetsgivaravgift. Avdraget är 3 200 kronor, så avgiften blir 6 854,40 kronor. Bokföringen får den lägre avgiften, och i arbetsgivardeklarationen står underlaget i ruta 471 och avdraget i ruta 476.

> [!IMPORTANT]
> Inställningen gäller alla anställda. Kryssa bara i den om alla arbetar vid driftstället i stödområdet och företaget uppfyller villkoren. Kontrollera med Skatteverket eller er redovisningskonsult om ni är osäkra.

## Tjänstepension

Tjänstepension är en pensionspremie som arbetsgivaren betalar utöver lönen, ofta för att ett kollektivavtal kräver det. I NONOX lägger du upp en **pensionsplan** och väljer den på de anställda som ska ha den.

En pensionsplan har två procentsatser: en på lönen upp till ett tak på 7,5 inkomstbasbelopp om året, och en på lönen över taket. Satserna är era, från kollektivavtalet eller företagets egen policy.

### Så här lägger du upp en pensionsplan

1. Öppna **Lön → Anställda** och scrolla ner till **Pensionsplaner**.
2. Klicka på **Ny pensionsplan**.
3. Skriv **Namn**, till exempel avtalets eller försäkringsbolagets namn.
4. Välj **Mall**: **Som ITP1 (tjänstemän)**, **Som SAF-LO (arbetare)** eller **Egen**. Mallen fyller i förslag på procentsatser.
5. Kontrollera och rätta **Procent av lönen upp till taket** och **Procent av lönen över taket** mot ert avtal.
6. Klicka på **Spara planen**.
7. Öppna varje anställd som ska ha planen och välj den under **Tjänstepension**.

> [!WARNING]
> Mallarnas procentsatser är bara förslag. Kontrollera alltid satserna mot ert kollektivavtal eller er pensionsleverantör innan den första lönen.

### Det här räknas och bokförs

För varje lönebesked räknar NONOX premien och den särskilda löneskatten på premien. Båda är arbetsgivarens kostnader: de syns på lönebeskedet för information men ändrar inte bruttolön eller nettolön.

- Premien bokförs 7410 Pensionsförsäkringspremier mot 2740 Avtalade sociala avgifter.
- Den särskilda löneskatten bokförs 7530 Särskild löneskatt mot 2514 Beräknad särskild löneskatt på pensionskostnader. Den betalas med företagets egen preliminärskatt, inte via arbetsgivardeklarationen.

Under **Lön → Rapporter → Pension** ser du premien per pensionsplan och månad, som underlag för fakturan från pensionsbolaget.

> [!NOTE]
> NONOX skickar inga uppgifter till pensionsbolag eller försäkringsbolag. Du rapporterar lönerna till dem på det sätt ert avtal säger.

## Vanliga frågor

**Varför fick en 19-åring lägre avgift i vissa månader men inte andra?**
Den sänkta avgiften för unga gäller bara under den period som regeln anger och bara upp till månadstaket. Kontrollera regeln **Sänkt arbetsgivaravgift för unga** under **Lön → Regler**.

**Vi har avtalsförsäkringar via kollektivavtalet. Räknar NONOX dem?**
Inte ännu. Se [Det här gör Avtal inte ännu](/manual/avtal/det-har-gor-avtal-inte-annu).

## Läs också

- [Regler och skattetabeller](/manual/lon/regler-och-skattetabeller)
- [Anställda](/manual/lon/anstallda)
- [Rapporter och löneårets slut](/manual/lon/rapporter-och-lonearsskifte)
- [Arbetsgivardeklaration (AGI)](/manual/lon/arbetsgivardeklaration)
