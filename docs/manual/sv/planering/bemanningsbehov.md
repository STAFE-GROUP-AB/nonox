---
beskrivning: Prognos för försäljningen och hur många som behöver jobba, från bokföringen, kassan eller en egen timprofil och era bemanningsmål. Behovet visas i schemat.
ordning: 105
roller: [owner, admin, payroll_admin, scheduler]
modul: planning
rutter: [planning.demand]
sokord: [bemanningsbehov, prognos, försäljningsprognos, bemanningsmål, försäljning per timme, underbemannat, överbemannat, timprofil, minsta bemanning]
---

# Bemanningsbehov

Här ser du hur mycket ni troligen säljer de kommande dagarna och hur många som behöver jobba för det. Prognosen räknas från de bokförda dagarna, och behovet från era egna bemanningsmål: hur mycket försäljning en bemannad timme ska bära och hur många som minst ska vara på plats. Behovet visas sedan i schemats dagvy, i veckans summa och under **Bemanning och kostnad**, mot de pass ni har lagt.

Sidan finns under **Planering → Bemanningsbehov**.

![Bemanningsbehov med prognosen för två veckor, bemanningsmålen och timprofilen.](/bilder/manual/planering/bemanningsbehov.webp)

Allt räknas med fasta regler som du kan räkna efter för hand. Ingen AI väljer något, och samma siffror ger alltid samma behov.

## Så här kommer du igång

1. Gå till **Planering → Bemanningsbehov**.
2. Välj under **Gäller** om målen ska gälla **Hela företaget** eller en avdelning.
3. Klicka på **Nytt mål**. Välj **Veckodag** (eller **Alla dagar**), tiden **Från** och **Till**, och fyll i **Försäljning per bemannad timme**, **Minsta bemanning** eller båda. Välj **Gäller från** och klicka på **Spara**.
4. Fyll gärna i en **Timprofil (egen uppskattning)** för de dagar ni har öppet, om ingen kassa är kopplad. Utan den visas behovet bara per dag.
5. Öppna schemat i dagvyn och se behovet per timme ovanför passen.

## Prognosen

Prognosen för en dag är snittet av samma veckodag de senaste sex veckorna i bokföringen. Antalet veckor ändrar du under **Antal veckor i snittet**, mellan 1 och 26.

- Helgdagar och aftnar räknas inte med i snittet, och dagar utan försäljning hoppas över. En vecka som hoppas över ersätts inte med en äldre.
- En dag längre fram räknas från de senaste veckorna som redan är bokförda. Dagens försäljning är inte färdig och räknas inte.
- En helgdag eller afton, till exempel midsommarafton, får försäljningen samma helgdag förra året. Finns den inte i bokföringen får dagen ingen prognos.
- Under **Prognos de kommande två veckorna** står vilka dagar snittet räknades på.

Prognosen räknar inte med trender, säsong eller väder. Vet ni att en dag blir annorlunda ändrar ni den själva.

### Varifrån försäljningen kommer

Dagens summa kommer alltid från bokföringen: bokförda verifikationer på försäljningskontona, utan moms, per verifikationsdatum. En verifikation har ett datum men ingen tid, så bokföringen ger bara försäljningen per dag.

Fördelningen över timmarna kommer från, i den här ordningen:

1. **Kassan.** En kopplad kassa ger fördelningen från kvittonas tid, utan moms och utan dricks. Returer drar av. Kassan används bara för fördelningen; summan är bokföringens.
2. **Timprofil (egen uppskattning).** Er egen uppskattning av hur dagen fördelar sig, i procent per timme. Den visas alltid som en egen uppskattning.
3. **Ingen.** Utan kassa och timprofil står det **Bara per dag** och behovet räknas för hela dagen.

> [!NOTE]
> Har en avdelning inget kostnadsställe räknas avdelningens behov på hela företagets försäljning. Det står på sidan. När avdelningen har ett kostnadsställe och försäljningen bokförs på det används avdelningens egen försäljning.

## Bemanningsmål

Ett bemanningsmål gäller en veckodag eller alla dagar, en tid och från ett datum.

- **Försäljning per bemannad timme** är hur mycket en person som jobbar en timme ska bära, i kronor utan moms.
- **Minsta bemanning** är hur många som alltid ska vara på plats, till exempel två vid stängning.

Behovet en timme är det högsta av minsta bemanningen och timmens försäljning delad med målet, avrundat uppåt. Säljer ni 6 000 kr mellan 12 och 13 och målet är 1 000 kr behövs sex personer den timmen.

Utan fördelning per timme är behovet för dagen försäljningen delad med målet, avrundat uppåt till hela timmar, men aldrig mindre än minsta bemanningen räknad över målets timmar.

> [!TIP]
> Gäller flera mål samma timme används det med senast **Gäller från**. Ett mål för en viss veckodag går före ett för **Alla dagar**. Tider över midnatt lägger du in som två mål, till exempel 18:00–24:00 och 00:00–01:00.

## Ändra prognosen för en dag

Under **Ändra prognosen för en dag** skriver du in **Datum**, **Försäljning** och **Anledning**, till exempel en konsert i närheten, och klickar på **Spara ändringen**. Din siffra ersätter prognosen och visas som **ändrad**, med den uträknade prognosen bredvid. Sparar du samma dag igen ersätts ändringen. **Ta bort** gör att dagen följer prognosen igen.

## Timprofil (egen uppskattning)

1. Välj veckodag.
2. Skriv hur stor del av dagens försäljning som kommer varje timme, i procent. Tomma rutor är 0 %.
3. Kontrollera att **Summa** blir 100 % och klicka på **Spara timprofilen**.

Töm alla rutor och spara för att ta bort timprofilen för dagen.

## Kassor och underlag

Under **Kassor och underlag** väljer du för varje kopplad kassa om den används för **Hela företaget**, för en avdelning eller **Används inte**, och klickar på **Spara**. Hur du kopplar en kassa står i [Kassa: Zettle](/manual/lager/kassa-zettle) och [Kassa: Onslip](/manual/lager/kassa-onslip).

## Behovet i schemat

I schemats dagvy finns raden **Behov per timme** ovanför passen. Varje timme visar bemannat mot behov, till exempel 2/3:

- **Röd** betyder underbemannat: färre bemannade än behovet.
- **Gul** betyder överbemannat: minst en person mer än behovet.
- **Grön** betyder bemannat enligt behovet.

Bemannat är tiden personer har pass den timmen. Rasten dras inte av eftersom schemat inte vet när den tas, och öppna pass räknas inte förrän någon har dem. Utan fördelning per timme heter raden **Minsta bemanning** och visar bara den.

Under raden står prognosen, hur den räknades, fördelningen och behovet för dagen i timmar. I veckovyn står **Behov** i summan för varje dag, med en pil när dagen är under- eller överbemannad. Under [Bemanning och kostnad](/manual/planering/bemanning-och-kostnad) finns kolumnerna **Behov** och **Prognos** per dag och vecka.

![Dagvyn med behovet per timme ovanför passen.](/bilder/manual/planering/schema-dag-behov.webp)

Varningarna stoppar ingenting. Du bestämmer själv hur schemat ska se ut.

> [!IMPORTANT]
> Är företaget bundet av kollektivavtal kan det vara en viktigare förändring att börja planera efter bemanningsmål. Då kan arbetsgivaren behöva förhandla med facket innan, enligt 11 § medbestämmandelagen. NONOX avgör inte det åt er.

## Läs också

- [Schemat](/manual/planering/schemat)
- [Bemanning och kostnad](/manual/planering/bemanning-och-kostnad)
- [Kom igång och inställningar](/manual/planering/kom-igang) – vilka konton som räknas som försäljning.
