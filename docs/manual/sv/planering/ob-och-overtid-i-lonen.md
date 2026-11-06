---
beskrivning: Hur attesterad tid blir tidrapporter i lönen – timlön, OB uppdelad enligt kollektivavtalets tider, och övertid och mertid per dag eller per beräkningsperiod enligt avtalet.
ordning: 90
roller: [owner, admin, payroll_admin]
modul: planning
sokord: [OB, OB-tillägg, obekväm arbetstid, övertid, mertid, övertidsart, övertid i anslutning, fridag, löneunderlag, tidrapport, timlön, månadslön, storhelg, övertidstillägg, avrundning, beräkningsperiod, semesterlönegrundande]
---

# OB och övertid i lönen

När du skickar attesterade dagar till lönen blir de godkända tidrapporter, en per dag och löneart. Lönekörningen för dagens månad tar med dem när du klickar på **Beräkna**, på samma sätt som tidrapporter från personalliggaren eller från en fil. Planering säger hur mycket tid av vilket slag. Vad tiden kostar räknar lönen.

I kolumnen **Till lönen** på [Tid att attestera](/manual/planering/tid-att-attestera) ser du före attesten vilka lönearter och hur många timmar dagen blir.

![Attesterad tid med OB enligt kollektivavtalet uppdelad per OB-art. Beloppen är demodata.](/bilder/manual/planering/attest-restaurang.webp)

## Timanställda

All faktisk tid blir lönearten Timlön, planerad eller inte. På lönebeskedet blir det **Timlön enligt tidrapport**, med timmarna gånger timlönen. Till det kommer OB, se nedan.

Arbetade personen längre än schemat betalas tiden som timlön.

Ger avtalet timanställda ett övertids- eller mertidstillägg, som Gröna Riks och Detaljhandelns avtal, räknar Planering det per beräkningsperiod på samma sätt som för månadsavlönade, se nedan. Tillägget skickas på avtalets löneart för bara tillägget, till exempel **Övertidstillägg timavlönad, två första timmarna**, eftersom timlönen redan betalar timmen. I Gröna Riks och Detaljhandeln delas tillägget upp per övertidsart, se avsnittet Övertidsarter enligt avtalet nedan. Utan kollektivavtal ger lagen inget tillägg, och tiden betalas bara som timlön.

## Månadsavlönade

Månadslönen betalar redan den planerade tiden, så bara det som skiljer sig blir rader i lönen:

- **OB**, se nedan.
- **Övertid och mertid** räknas per kalendervecka, eller per beräkningsperiod, se nedan.
- **Kortare tid** dras inte av här, varken en dag eller en vecka. Ska den dras av registrerar du frånvaro i lönen, där sjuklön och avdrag räknas. Se [Frånvaro, sjuklön och karensavdrag](/manual/lon/franvaro-och-sjuklon).

### Övertid och mertid per period

NONOX lägger ihop den attesterade tiden för veckan, eller perioden, och jämför den med anställningens veckoarbetstid gånger antalet veckor. Saknas veckoarbetstiden på anställningen används den ordinarie veckan i arbetstidsreglerna gånger sysselsättningsgraden.

- För den som arbetar **deltid** är tiden upp till heltid mertid, och tiden därutöver övertid.
- För den som arbetar **heltid** är all tid över veckoarbetstiden övertid.
- **Ledighet räknas som arbetad tid.** En dag med godkänd frånvaro eller uttagen komptid på schemalagd tid räknas som om den schemalagda tiden var arbetad (arbetstidslagen 7 § andra stycket). Är någon ledig en dag och arbetar två timmar längre en annan dag blir det alltså två timmar övertid.
- Ingår övertid i lönen enligt anställningen skickas ingen övertid.

En vecka räknas först när den är slut och alla dess dagar är attesterade. Till dess står det på veckans sista dag att veckan väntar, till exempel **Veckan: hittills 90 min utöver schemat. Övertid och mertid räknas mot veckoarbetstiden när hela veckan är attesterad – väntar på hela veckan.**

När veckan är klar skickas övertiden och mertiden med veckans sista dag, på lönearterna du har valt: **Löneart för övertid (månadslön, heltid)** och **Löneart för mertid (månadslön, deltid)**. Har du inte valt någon löneart skickas tiden inte, utan visas som en avvikelse som du lägger in i lönekörningen själv. För Gröna Riks och Detaljhandeln delas tiden i stället upp per övertidsart, se nästa avsnitt.

### Övertidsarter enligt avtalet

Gröna Riks och Detaljhandelns avtal betalar övertid och mertid olika beroende på när tiden ligger. För anställda med de avtalen delar NONOX upp veckans eller periodens övertid och mertid per art och skickar varje art på avtalets egen löneart. Det gäller när **Löneart för övertid (månadslön, heltid)** (eller för mertid) är tom eller en av avtalets lönearter. Har du valt en egen löneart, till exempel **Övertid**, skickas all tid på den som förut.

Varje tillfälle utanför schemat är antingen **i anslutning** till ett pass (det börjar där passet slutar eller slutar där det börjar) eller inte. En dag utan planerat pass är en fridag.

| | Gröna Riks (§ 12) | Detaljhandeln (§ 9) |
|---|---|---|
| De två första timmarna i anslutning per dag, före och efter passet sammanlagt | a) 35 % (45 % före 1 april 2026) | 35 % (50 % före 1 april 2026) |
| Tid i anslutning därutöver | b) 70 % | 70 % |
| Tid som inte ansluter, och en fridag måndag–fredag | c) 90 % | 70 % |
| Måndag–fredag efter 20.00 | som ovan | 70 % |
| Lördag och jul-, nyårs- och midsommarafton efter 12.00, sön- och helgdag, fridag som är en lördag eller afton | som ovan | 100 % |

Procenten är tillägget på timlönen; för månadsavlönade betalas timlönen också för timmen. Mertid för deltid får samma arter från den 1 april 2026. Före det har avtalen inget mertidstillägg, och mertiden går på den löneart du har valt för mertid.

- **Fridag i Detaljhandeln.** Den som arbetar heltid och gör övertid en fridag får ersättning för minst tre timmar (§ 9.1). Arbetar personen en timme läggs två timmar till på samma art, med timlön och tillägg, och dagen får en anteckning om det. En fridag har bara en art: 70 % en vardag och 100 % en lördag, afton, söndag eller helgdag. Avtalet anger ingen annan sats för minimitiden. Minimitiden ger ingen komptid; välj **Betala ut** för att få den.
- **Satsen för dagen tiden arbetades.** Övertiden skickas med veckans sista dag, men varje timme betalas med satsen och komptidsfaktorn som gällde den dag den arbetades. En vecka från 30 mars till 5 april 2026 betalar alltså en övertidstimme den 31 mars med 45 % (Gröna Riks) eller 50 % (Detaljhandeln) och en timme den 1 april med 35 %.
- **När veckan nettas.** Är en dag kortare och en annan längre blir veckans övertid mindre än tiden utanför schemat. Avtalen säger inte vilka timmar som då är övertid. NONOX räknar de högst ersatta arterna först, och det kan ge mer än avtalet kräver. Dagen får anteckningen **Avtalet säger inte vilka timmar som då är övertid. NONOX har räknat de högst ersatta arterna först, vilket kan ge mer än avtalet kräver.** Vill ni betala de lägre arterna, ändra raderna i lönekörningen.
- **OB och övertid på samma tid i Detaljhandeln.** Avtalet ger bara den procentuellt högsta ersättningen. OB:n räknas på dagen som vanligt; när veckan räknas drar NONOX tillbaka den lägre för de minuterna. Klockan 19–20 en vardag, när de två första övertidstimmarna är använda, ger övertid 70 % mot OB 50 %, så OB:n dras tillbaka. Klockan 18.15–20 under de två första övertidstimmarna ger OB 50 % mot övertid 35 %, så övertidstillägget dras tillbaka och OB:n gäller. Avdraget syns som en minusrad på samma löneart, och dagen får en anteckning som börjar med datumet och **min övertid eller mertid låg på OB-tid**. Ingår OB i lönen dras ingenting. Tas övertiden ut som komptid betalas OB:n ändå. Är bara en del av ett tillfälle övertid efter att veckan nettats, är en dag inte skickad till lönen eller saknas lönearten, dras inget tillbaka och båda betalas; dagen säger det.
- **Tid som inte ligger utanför schemat**, till exempel ledighet som räknas som arbetad tid, har ingen art. Den går på den löneart du har valt.
- **Komptid** räknas per art med avtalets faktor: i Gröna Riks 1,5 timmar för a och b och 2 timmar för c; i Detaljhandeln 1 timme 21 minuter för 35 % (1,5 timmar för 50 % före 1 april 2026), 1 timme 45 minuter för 70 % och 2 timmar för 100 %.

På veckans sista dag står en rad per art, till exempel **Veckan: 120 min övertid, två första timmarna i anslutning enligt Gröna Riks (GR#§ 12 p. 1.2 s. 19; p. 2.2 s. 20) skickas med den här dagen (GR_OT_A).**

### Övertid per dag

Vissa avtal räknar övertid per dag, mot dagens ordinarie arbetstid, i stället för netto över veckan. Det gäller Visita–Unionen och Handelns tjänstemannaavtal. Då jämförs varje dag med schemat för dagen. En längre dag ger övertid även om en annan dag i veckan var kortare. Dagen får en anteckning med källan i avtalet.

### Avrundning per dag eller tillfälle

Ger avtalet en avrundningsregel för övertid avrundas tiden per dag eller per tillfälle, aldrig på periodens summa:

- **Visita–Unionen:** varje påbörjad halvtimme per dag räknas som hel. Tre dagar med 20 minuter övertid blir 3 × 30 = 90 minuter, inte 60.
- **Handelns tjänstemannaavtal:** bara hela halvtimmar per dag räknas. 50 minuter en dag blir 30.
- **Gröna Riks:** en förlängning på högst 10 minuter är inte övertid. Längre tillfällen avrundas uppåt till påbörjad halvtimme. Ett tillfälle är tid utanför schemat, till exempel före passet eller efter det.

### Utan kollektivavtal: högst fyra veckor

Utan kollektivavtal får arbetstiden räknas i genomsnitt över högst fyra veckor (arbetstidslagen 5 § andra stycket). Har du ställt in en längre beräkningsperiod räknar NONOX ändå högst fyra veckor åt gången för den som inte har något avtal, och dagen får en anteckning om det. Med avtal gäller avtalets gräns. I Gröna Riks är den 16 veckor för månadsavlönade och 8 veckor för timavlönade.

### Längre period än en vecka

Har ni ett rullande schema kan övertiden räknas över hela schemats längd i stället för varje vecka. Ställ in **Beräkningsperiod för övertid och mertid (veckor)** under **Planering → Inställningar**. Se [Rullande schema och beräkningsperiod](/manual/planering/rullande-schema).

### Pengar eller komptid

På veckans eller periodens sista dag väljer du **Betala ut** eller **Komptid**. Med komptid blir tiden ett saldo i lönen i stället för en tidrapport, med avtalets faktor, till exempel 1,5 timmar per övertidstimme. Se [Komptid](/manual/lon/komptid).

> [!IMPORTANT]
> Reglerna för ledighet, beräkningsperiod och avrundning bygger på en AI-utredning av lagen och avtalen den 2 november 2026, och övertidsarterna på avtalstexterna. Juristgranskning återstår, bland annat för hur arterna fördelas när veckan nettas. Övertidsarterna finns bara för Gröna Riks och Detaljhandeln; för andra avtal går periodens övertid på den löneart du har valt. Helgdagar utan planerat pass räknas inte som arbetad tid. Kontrollera mot ert avtal, eller fråga er redovisningskonsult.

### Semesterlön på övertid

Övertid och utbetald komptid räknas in i semesterlönegrundande lön enligt semesterlagen 16 b §. Har avtalet sagt att semesterlönen redan ingår i övertidsersättningen, som Visita–Unionen och Handelns tjänstemannaavtal, räknas avtalets övertid inte in. Se [Semester](/manual/lon/semester).

## OB

OB räknas från de faktiska klockslagen. NONOX delar upp tiden i minuter per OB-typ enligt OB-tiderna i den kontrollerade versionen av personens kollektivavtal, med helgdagarna och de aftnar avtalet använder. Ett pass över midnatt delas upp per dag, och en natt när klockan ställs om är en timme kortare eller längre.

För att OB ska räknas krävs att:

- modulen [Avtal](/manual/avtal) är på,
- anställningen är knuten till ett avtal,
- avtalets version för dagen är kontrollerad.

Har anställningen ett avtal utan kontrollerad version stoppas dagen med avvikelsen **Anställningen följer ett kollektivavtal utan kontrollerad version för dagen, så OB kan inte räknas.** Kontrollera versionen under **Lön → Avtal**. Se [Versioner och kontroll](/manual/avtal/versioner-och-kontroll).

OB-tiden blir tidrapporter på avtalets OB-lönearter, och lönen prissätter dem med avtalets sats för dagen. Se [OB och övertid enligt avtalet](/manual/avtal/ob-och-overtid).

Har avtalet en avrundningsregel för OB avrundas minuterna per pass och OB-typ. I Gröna Riks och Visita–Unionen räknas varje påbörjad halvtimme som hel, så tio minuter OB blir trettio. Dagen får en anteckning om det, med källan i avtalet.

### Utan kollektivavtal

Har personen inget avtal i NONOX, eller är Avtal avstängd, räknar Planering ingen OB. Ingår OB i lönen enligt anställningen räknas ingen OB heller.

Ligger tiden på kvällen, natten, en helg eller en helgdag får dagen då en anteckning, **Ingen OB räknas: inget kollektivavtal i Avtal. Lägg in OB i lönekörningen om ni ger det.** eller **Ingen OB räknas: OB ingår i lönen enligt anställningen.** Ger ni OB lägger du in den i lönekörningen som vanligt. Anteckningen är bara information och stoppar inte attesten.

### Rasten och OB

Personalliggaren vet inte när rasten togs. Därför räknas OB på hela passet, rasten inräknad, och dagen får en anteckning om det. Låg rasten på OB-tid delar du upp dagen i två delar under **Ändra** på Tid att attestera, med rasten mellan dem.

## Bra att veta

- En löneart som inte finns i lönen gör att dagen inte skickas. NONOX säger vilken.
- En dag som redan finns i en annan tidrapport skickas inte en gång till.
- Rättelser efter att lönen är attesterad läggs i nästa lönekörning.

## Läs också

- [Tid att attestera](/manual/planering/tid-att-attestera)
- [Timlön och tidrapporter](/manual/lon/timlon-och-tidrapporter)
- [OB och övertid enligt avtalet](/manual/avtal/ob-och-overtid)
- [Avtal, lönegrupp och branschvana per anställd](/manual/avtal/avtal-per-anstalld)
- [Komptid](/manual/lon/komptid)
- [Sjuklön med OB enligt schemat](/manual/avtal/sjuklon-med-ob)
