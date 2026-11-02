---
beskrivning: Vad den uppskattade kostnaden per pass räknas på, vad stjärnan betyder, och reglerna för vilotid, rast och arbetstid som varnar i schemat.
ordning: 50
roller: [owner, admin, payroll_admin, scheduler]
modul: planning
sokord: [lönekostnad, personalkostnad, kostnad per pass, uppskattning, dygnsvila, veckovila, rast, arbetstidslagen, övertid, mertid, minderåriga, varning]
---

# Lönekostnad och varningar

Medan du planerar visar schemat vad varje pass ungefär kostar, och varnar när ett pass bryter mot reglerna för vila och arbetstid. Båda är hjälpmedel. Kostnaden är en uppskattning, och varningarna stoppar aldrig ett pass.

![Schemat per avdelning med kostnaden på varje pass och varningar som märken.](/bilder/manual/planering/schema-avdelningar.webp)

## Vad kostnaden räknas på

Kostnaden under varje pass är en uppskattning av vad passet kostar företaget:

- **Lönen:** den arbetade tiden, alltså passet minus rasten, gånger personens timlön. För den som har månadslön räknar NONOX om lönen till en timlön med anställningens veckoarbetstid.
- **OB:** minuterna på OB-tid gånger kollektivavtalets sats, när personen har ett avtal i [Avtal](/manual/avtal) med en kontrollerad version.
- **Semesterersättning och arbetsgivaravgifter:** bara när lönens regler för dem är kontrollerade.

Det NONOX inte kan räkna gissas inte. Då står en stjärna efter beloppet, och när du öppnar passet står det vad som saknas, till exempel **Arbetsgivaravgifter: ingen kontrollerad regel.**, **Timlön saknas på anställningen.** eller **OB: avtalsversionen är inte kontrollerad.** Semestertillägg för månadslön ingår inte i uppskattningen.

Ett öppet pass har ingen kostnad förrän någon har passet.

> [!IMPORTANT]
> Kostnaden i schemat är aldrig lön. Lönen räknas alltid i lönekörningen, med de regler som gäller där.

### När lönerna ändras

Kostnaden räknas när passet sparas. Har en lön eller en regel ändrats sedan dess, klicka på **Räkna om** i schemat, så räknas passen om med dagens löner och regler.

## Summan per dag

Längst ner i schemat står för varje dag:

- **Tid** – planerade timmar.
- **Kostnad** – summan av passens uppskattade kostnad. En stjärna betyder att någon del saknas för något pass.
- **Försäljning** – bokförda intäkter på försäljningskontona den dagen. Finns ingen försäljning bokförd än visas **≈** och snittet för samma veckodag de fyra veckorna före.
- **Personalkostnad** – kostnaden i procent av försäljningen, eller av snittet när dagen inte har någon bokförd försäljning.

Vilka konton som räknas som försäljning ställer du in med **Försäljning från konto** och **till och med konto**. Standard är 3000–3799. Se [Bemanning och kostnad](/manual/planering/bemanning-och-kostnad) för hela perioden.

## Arbetstidsreglerna

NONOX kontrollerar varje persons pass mot företagets arbetstidsregler och visar en varning med **!** på passet. Öppna passet för att läsa varningen och vilken regel den kommer från.

Reglerna är förifyllda med värden ur arbetstidslagen (1982:673), med paragrafen som källa. Ett kollektivavtal kan ge andra värden, så du kan ändra värdena och stänga av reglerna under **Planering → Inställningar**, **Arbetstidsregler**.

| Regel | Förifyllt värde | Källa som står i NONOX |
|---|---|---|
| **Dygnsvila (minuter)** | 660 | Arbetstidslagen (1982:673) 13 § |
| **Veckovila (minuter)** | 2160 | Arbetstidslagen (1982:673) 14 § |
| **Längsta arbete utan rast (minuter)** | 300 | Arbetstidslagen (1982:673) 15 § |
| **Ordinarie arbetstid per vecka (timmar)** | 40 | Arbetstidslagen (1982:673) 5 § |
| **Sammanlagd arbetstid per vecka (timmar)** | 48 | Arbetstidslagen (1982:673) 10 b § |
| **Allmän övertid på fyra veckor (timmar)** | 48 | Arbetstidslagen (1982:673) 8 § |
| **Mertid över anställningens veckoarbetstid** | inget eget värde | Arbetstidslagen (1982:673) 10 § |
| **Allmän övertid per kalenderår (timmar)** | 200 | Arbetstidslagen (1982:673) 8 § |
| **Allmän mertid per kalenderår (timmar)** | 200 | Arbetstidslagen (1982:673) 10 § |

De två gränserna per kalenderår är märkta **Kontrolleras inte i schemat ännu**. De bygger på arbetad tid, så de sparas som värden men ger inga varningar, och de har ingen egen på- och avknapp.

> [!WARNING]
> Värdena är inte kontrollerade av en jurist. Kontrollera dem mot den gällande lagtexten och ert kollektivavtal, eller med er redovisningskonsult, innan ni förlitar er på varningarna. Att schemat inte varnar betyder inte att det följer lagen eller avtalet.

### Så räknar kontrollerna

Kontrollerna är förenklade, och det är bra att veta hur:

- **Dygnsvila** är tiden mellan två pass i följd.
- **Veckovila** och timmarna per vecka räknas per kalendervecka, från måndag klockan 00.
- **Rast:** var i passet rasten ligger planeras inte, så ett långt pass med någon rast alls klarar kontrollen.
- **Övertid på fyra veckor** är timmarna över den ordinarie veckan, i de fyra kalenderveckorna till och med passets vecka.
- **Mertid:** för den som arbetar deltid varnar NONOX när veckan är planerad över anställningens veckoarbetstid. Regeln heter **Mertid över anställningens veckoarbetstid** och slås av och på för sig.
- **Gränserna per kalenderår** kontrolleras inte i schemat, eftersom de bygger på arbetad tid.

Två pass för samma person som överlappar ger också en varning, liksom ett pass på en tid personen har sagt inte passar. Se [Tillgänglighet](/manual/planering/tillganglighet).

## Minderåriga

Kryssa för **Varna för pass som inte passar anställda under 18 år** under **Minderåriga** i inställningarna. Åldern räknas från personnumret, på passets dag. Då varnar NONOX för pass som är längre än gränsen per dag eller vecka, som ligger på kvällen eller natten, eller som ger för kort dygnsvila, med egna gränser för under 16 och under 18 år.

Gränserna är data att kontrollera mot Arbetsmiljöverkets föreskrifter om minderårigas arbetsmiljö (AFS 2023:2) och ert avtal. De är inte kontrollerade av en jurist, och de varnar men stoppar inget. Fråga er redovisningskonsult eller Arbetsmiljöverket om ni är osäkra.

## Läs också

- [Schemat](/manual/planering/schemat)
- [Kom igång och inställningar](/manual/planering/kom-igang)
- [Bemanning och kostnad](/manual/planering/bemanning-och-kostnad)
- [Regler och skattetabeller](/manual/lon/regler-och-skattetabeller) – där lönens regler kontrolleras.
