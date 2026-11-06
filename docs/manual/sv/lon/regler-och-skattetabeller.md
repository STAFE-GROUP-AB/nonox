---
beskrivning: Varför NONOX stoppar lönekörningen tills årets satser är kontrollerade, och hur du kontrollerar en regel mot Skatteverkets eller lagens källa.
ordning: 30
roller: [owner, admin, payroll_admin]
modul: payroll
rutter: [payroll.rules]
sokord: [lönesatser, arbetsgivaravgift procent, prisbasbelopp, skattetabeller, verifiera regel, kontrollera regel, lönekörningen stoppas]
---

# Regler och skattetabeller

Lönen räknas med en lång rad satser och belopp som ändras varje år: arbetsgivaravgifter, sjuklön och karensavdrag, semestertillägg, förmånsvärden, traktamenten, milersättning, prisbasbelopp och Skatteverkets datum för arbetsgivardeklarationen. I NONOX är var och en av dem en **regel** med ett värde, ett giltighetsdatum och en länk till källan.

NONOX levererar reglerna, men använder dem inte förrän en namngiven person har jämfört värdet med källan och markerat det som kontrollerat. Det är din försäkran om att lönen räknas med rätt siffror, och det är därför en lönekörning kan stoppas med beskedet att regler inte är kontrollerade.

![Reglerna för lön. En regel som inte är kontrollerad stoppar lönekörningen.](/bilder/manual/lon/regler-och-skattetabeller.webp)

![En lönekörning räknas bara med regler som en namngiven person har kontrollerat.](/bilder/manual/lon/lonekorning-pagaende.webp)

## Inför löneåret

Överst under **Lön → Regler** visar **Inför löneåret** om ett helt utbetalningsår är klart: varje regel som kontrollerad, ej kontrollerad, inte publicerad ännu eller saknad, och om årets skattetabeller är inlästa. Från oktober visas nästa år. Du kan kontrollera regler direkt i den listan. Läs mer i [Inför ett nytt löneår](/manual/lon/infor-nytt-lonear).

## Så här kontrollerar du reglerna

1. Öppna **Lön → Regler**.
2. Välj datum i **Gäller den**, normalt utbetalningsdagen för nästa lön. Överst ser du hur många regler som saknas eller inte är kontrollerade för den dagen.
3. Leta upp en rad med **Ej kontrollerad** och klicka på **Kontrollera**.
4. Läs vad **Källan säger** och klicka på **Öppna källan och jämför värdet**. Källan är Skatteverkets, Försäkringskassans eller riksdagens egen sida.
5. Jämför värdet i kolumnen **Värde** med källan.
6. Stämmer det: kryssa i **Jag har jämfört värdet med källan och tar ansvar för att det stämmer för vårt företag** och klicka på **Markera som kontrollerad**.
7. Gör likadant för varje regel som inte är kontrollerad.

Ditt namn och dagens datum visas sedan i kolumnen **Kontrollerad**. Det är ägaren, administratören och löneadministratören som kan kontrollera regler.

> [!WARNING]
> Markera aldrig en regel som kontrollerad utan att ha jämfört den med källan. Stämmer värdet inte med källan: markera den inte, och kontakta oss eller er redovisningskonsult. En kontrollerad regel ändras aldrig; blir ett värde fel rättas det med en ny version med nytt datum.

## När lönekörningen stoppas

Saknas en kontrollerad regel för utbetalningsdagen stoppas beräkningen med beskedet att ett antal regler inte är kontrollerade, och vilka. Klicka på **Kontrollera reglerna** i rutan; du kommer då direkt till **Lön → Regler** med rätt datum valt.

NONOX faller aldrig tillbaka på förra årets värde eller på ett standardvärde. Hellre en stoppad lönekörning än en lön med fel siffror.

Regler kan också vara kontrollerade för hela installationen. Då visas namnet på den som kontrollerade dem, och du behöver inte göra något.

## Vilka regler finns?

Under **Lön → Regler** ser du alla, med namn på svenska och värdet utskrivet. Här är grupperna:

- **Arbetsgivaravgifter** – full avgift, avgiften för äldre, den tillfälligt sänkta avgiften för unga, gränsen för avgift per år och regionalt stöd.
- **Skatt** – SINK och A-SINK, engångsbelopp och avrundning.
- **Basbelopp** – prisbasbelopp och inkomstbasbelopp.
- **Förmåner och ersättningar** – kostförmån, bilförmån, traktamenten och milersättning.
- **Sjuklön** – sjuklönens procent, karensavdraget, arbetsgivarens sjuklöneperiod, återinsjuknande och läkarintyg.
- **Semester** – semesterdagar, semestertillägg, procentregeln, sparad semester och semesterlönegrundande frånvaro.
- **Arbetsgivardeklarationen** – fältkoder, filformat och deklarationsdagarna.

> [!NOTE]
> Regionalt stöd för arbetsgivaravgifter dras av i lönen när inställningen **Regionalt stöd** är ikryssad under Lön → Inställningar: 10 procent av avgiftsunderlaget, inom bolagets månadstak och inte under ålderspensionsavgiften, och inte för den som fyllt 67 vid årets början. Kryssa bara i om företaget uppfyller villkoren i lagen. Osäker: prata med er redovisningskonsult.

## Skattetabellerna

Skatteavdraget räknas med Skatteverkets skattetabeller för året då lönen betalas ut. De läses in i NONOX från Skatteverkets fil eller Skatteverkets öppna data, med kontroll av innehållet, av den som driftar er NONOX-installation. Du väljer bara tabell och kolumn på varje anställd.

Är inga tabeller inlästa för utbetalningsåret stoppas hela lönekörningen med beskedet att skattetabellerna för året inte är inlästa ännu. Skatteverket ger ut nästa års tabeller i december.

Saknas tabellen och kolumnen för en anställd det året stoppas lönekörningen för den personen, med ett besked om att skattetabellen saknas. Kontrollera då att tabell och kolumn på den anställda stämmer med skattebeskedet. Stämmer de, be den som driftar NONOX att läsa in årets tabeller.

Skatteavdraget på lönebeskedet visar vilken tabell, kolumn och inkomst som användes.

## Vanliga frågor

**Måste vi kontrollera reglerna varje år?**
Ja. Nästan alla satser gäller ett kalenderår, så de kommer som nya versioner varje år. Gör det i december eller början av januari, innan årets första lön; se [Inför ett nytt löneår](/manual/lon/infor-nytt-lonear).

**Vi har en redovisningsbyrå. Kan de kontrollera reglerna åt oss?**
Ja, om de har rollen administratör eller löneadministratör i ert företag. Den som kontrollerar tar ansvar för värdet, så det ska vara någon som faktiskt jämför med källan.

**Vad gör jag om en källa inte går att öppna?**
Myndigheterna flyttar ibland sina sidor. Sök upp samma uppgift hos myndigheten och jämför med den. Hör gärna av dig så att vi kan uppdatera länken.

## Läs också

- [Gör en lönekörning](/manual/lon/lonekorning)
- [Anställda](/manual/lon/anstallda)
- [Arbetsgivaravgifter och tjänstepension](/manual/lon/arbetsgivaravgifter-och-pension)
- [Versioner av avtalets värden](/manual/avtal/versioner-och-kontroll)
