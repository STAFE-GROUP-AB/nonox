---
beskrivning: Hur attesterad tid blir tidrapporter i lönen – timlön, OB uppdelad enligt kollektivavtalets tider, och tid utöver schemat för månadsavlönade.
ordning: 90
roller: [owner, admin, payroll_admin]
modul: planning
sokord: [OB, OB-tillägg, obekväm arbetstid, övertid, mertid, löneunderlag, tidrapport, timlön, månadslön, storhelg]
---

# OB och övertid i lönen

När du skickar attesterade dagar till lönen blir de godkända tidrapporter, en per dag och löneart. Lönekörningen för dagens månad tar med dem när du klickar på **Beräkna**, på samma sätt som tidrapporter från personalliggaren eller från en fil. Planering säger hur mycket tid av vilket slag. Vad tiden kostar räknar lönen.

I kolumnen **Till lönen** på [Tid att attestera](/manual/planering/tid-att-attestera) ser du före attesten vilka lönearter och hur många timmar dagen blir.

![Attesterad tid med OB enligt kollektivavtalet uppdelad per OB-art. Beloppen är demodata.](/bilder/manual/planering/attest-restaurang.webp)

## Timanställda

All faktisk tid blir lönearten Timlön, planerad eller inte. På lönebeskedet blir det **Timlön enligt tidrapport**, med timmarna gånger timlönen. Till det kommer OB, se nedan.

Arbetade personen längre än schemat betalas tiden som timlön. Om avtalet ger ett övertids- eller mertidstillägg för den tiden lägger du in det i lönekörningen själv.

## Månadsavlönade

Månadslönen betalar redan den planerade tiden, så bara det som skiljer sig blir rader i lönen:

- **OB**, se nedan.
- **Övertid och mertid** räknas per kalendervecka, måndag till söndag, se nedan.
- **Kortare tid** dras inte av här, varken en dag eller en vecka. Ska den dras av registrerar du frånvaro i lönen, där sjuklön och avdrag räknas. Se [Frånvaro, sjuklön och karensavdrag](/manual/lon/franvaro-och-sjuklon).

### Övertid och mertid per vecka

NONOX lägger ihop den attesterade tiden för veckan och jämför den med anställningens veckoarbetstid. Saknas veckoarbetstiden på anställningen används den ordinarie veckan i arbetstidsreglerna gånger sysselsättningsgraden.

- För den som arbetar **deltid** är tiden upp till heltid mertid, och tiden därutöver övertid.
- För den som arbetar **heltid** är all tid över veckoarbetstiden övertid.
- Ingår övertid i lönen enligt anställningen skickas ingen övertid.

En vecka räknas först när den är slut och alla dess dagar är attesterade. Till dess står det på veckans sista dag att veckan väntar, till exempel **Veckan: hittills 90 min utöver schemat. Övertid och mertid räknas mot veckoarbetstiden när hela veckan är attesterad – väntar på hela veckan.** En enskild dag som är längre än schemat blir alltså ingen övertid i sig.

När veckan är klar skickas övertiden och mertiden med veckans sista dag, på lönearterna du har valt: **Löneart för övertid (månadslön, heltid)** och **Löneart för mertid (månadslön, deltid)**. Har du inte valt någon löneart skickas tiden inte, utan visas som en avvikelse som du lägger in i lönekörningen själv.

> [!IMPORTANT]
> NONOX räknar övertid och mertid per kalendervecka. Arbetstidslagen och kollektivavtalet kan använda en längre beräkningsperiod, räkna frånvaro och helgdagar på ett annat sätt och ha flera slags övertid. Inget av det tillämpas här. Kontrollera mot ert avtal vilken löneart och vilken beräkningsperiod som gäller, eller fråga er redovisningskonsult.

## OB

OB räknas från de faktiska klockslagen. NONOX delar upp tiden i minuter per OB-typ enligt OB-tiderna i den kontrollerade versionen av personens kollektivavtal, med helgdagarna och de aftnar avtalet använder. Ett pass över midnatt delas upp per dag, och en natt när klockan ställs om är en timme kortare eller längre.

För att OB ska räknas krävs att:

- modulen [Avtal](/manual/avtal) är på,
- anställningen är knuten till ett avtal,
- avtalets version för dagen är kontrollerad.

Har anställningen ett avtal utan kontrollerad version stoppas dagen med avvikelsen **Anställningen följer ett kollektivavtal utan kontrollerad version för dagen, så OB kan inte räknas.** Kontrollera versionen under **Lön → Avtal**. Se [Versioner och kontroll](/manual/avtal/versioner-och-kontroll).

OB-tiden blir tidrapporter på avtalets OB-lönearter, och lönen prissätter dem med avtalets sats för dagen. Se [OB och övertid enligt avtalet](/manual/avtal/ob-och-overtid).

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
