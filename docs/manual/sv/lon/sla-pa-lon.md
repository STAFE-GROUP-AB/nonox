---
beskrivning: Slå på lönemodulen för företaget, gör löneinställningarna och följ checklistan Kom igång fram till den första lönekörningen.
ordning: 10
roller: [owner, admin, payroll_admin]
modul: payroll
rutter: [payroll.index]
sokord: [aktivera lön, lönemodul, löneinställningar, utbetalningsdag, kom igång med lön]
---

# Slå på lönemodulen och kom igång

Lön är en modul som varje företag slår på när det behöver den. Har du flera företag i NONOX bestämmer du för vart och ett. När modulen är på får du menyn **Lön** och en checklista som leder dig fram till den första lönekörningen.

![Kom igång med lön: stegen innan den första lönekörningen.](/bilder/manual/lon/sla-pa-lon.webp)

## Så här slår du på Lön

1. Öppna **Företagsinställningar** och fliken **Moduler**.
2. Välj **Slå på** på kortet **Lön** och bekräfta.
3. Klicka på **Inställningar** på kortet för att göra löneinställningarna (se nedan).
4. Öppna **Lön → Kom igång** och gå igenom stegen i tur och ordning.

Det är ägaren och administratören som kan slå på moduler. Löneinställningarna kan ändras av ägaren, administratören och löneadministratören.

> [!NOTE]
> När modulen slås på lägger NONOX till verifikationsserien **L** (Lön), systemets lönearter och arbetsschemat **Heltid 40 h mån–fre**. Inget du redan har ändrat skrivs över om du slår av och på modulen igen.

## Checklistan Kom igång

Under **Lön → Kom igång** visar NONOX stegen fram till den första lönen. Ett steg får en bock när det är klart.

| Steg | Vad du gör |
|---|---|
| **Löneinställningar** | Utbetalningsdag, semesterregel och hur lönen bokförs. |
| **Arbetsgivare hos Skatteverket** | Företaget behöver vara registrerat som arbetsgivare innan den första lönen betalas ut. Kryssa i **Företaget är registrerat som arbetsgivare** under **Företagsinställningar**. |
| **Anställda** | Lägg upp de anställda med skattetabell, bankkonto och anställningsform. Se [Anställda](/manual/lon/anstallda). |
| **Flytt av lönedata** | Bara om ni kommer från ett annat lönesystem mitt i året. Se [Flytta lönedata](/manual/lon/flytt-av-lonedata). |
| **Regler och skattetabeller** | Kontrollera årets satser mot källan. Se [Regler och skattetabeller](/manual/lon/regler-och-skattetabeller). |
| **Första lönekörningen** | Beräkna, kontrollera och attestera. Se [Gör en lönekörning](/manual/lon/lonekorning). |
| **Arbetsgivardeklaration** | Skapa filen och lämna in den hos Skatteverket. Se [Arbetsgivardeklaration](/manual/lon/arbetsgivardeklaration). |

## Löneinställningarna

Inställningarna finns under **Företagsinställningar → Moduler → Lön → Inställningar**. Spara med **Spara löneinställningarna**.

- **Utbetalningsdag** – dagen i månaden lönen betalas ut, 1–28. Infaller den på en lördag eller söndag flyttas den till fredagen före. Röda dagar mitt i veckan flyttas inte automatiskt, så kontrollera datumet när du skapar lönekörningen.
- **Delmånad räknas på** – hur en månadslön delas när någon börjar eller slutar mitt i månaden: på **Arbetsschema** (schemalagda arbetsdagar, utan helgdagar) eller på **Kalenderdagar**.
- **Semesteråret börjar månad** – semesterlagens huvudregel är april. Se [Semester](/manual/lon/semester).
- **Semesterregel** – **Sammalöneregeln** eller **Procentregeln**, som förval för alla anställda. Du kan välja en annan regel på en enskild anställd.
- **Semesterlöneskuld bokförs** – **Varje månad** (med varje lönekörning) eller **Vid bokslut**.
- **Dagsvärde i semesterlöneskulden, % av månadslönen** – används för att värdera skulden under sammalöneregeln. Semesterlagen anger inget dagsvärde; kontrollera vad ert kollektivavtal säger.
- **Semesterdagar för deltid** – om bara schemalagda arbetsdagar räknas (färre dagar för den som arbetar färre dagar i veckan) eller alla vardagar.
- **Skatt på semesterersättning med varje lön** – enligt skattetabell som lönen, eller som engångsbelopp.
- **Den som beräknar får inte attestera** – på från början. Den som räknar lönen kan då inte också godkänna den. Är du ensam i företaget behöver du stänga av den här kontrollen.
- **Kostnadsställe och projekt från anställningen** – lönekostnaden bokförs på anställningens kostnadsställe.
- **Självservice för anställda** – om de anställda kan använda Min sida. Se [Min sida för de anställda](/manual/lon/min-sida-for-anstallda).
- **Deklarationsdag för arbetsgivardeklarationen** – visar vilken grupp företaget hör till. Den styrs av företagets inställning för moms och omsättning, som du ändrar under företagets uppgifter.

Längst ner finns **Löneunderlag från schemasystem**, där du kopplar koder från ett schema- eller tidsystem till lönearter. Se [Löneunderlag från schemasystem](/manual/lon/loneunderlag-fran-schemasystem).

> [!NOTE]
> Några val i listan påverkar inte lönen ännu: **Löneperiod** (lönen räknas för innevarande månad), **Avrundning av nettolön**, **Betalsätt** (lönefilen är alltid en betalfil till banken), **Lönebesked** (avisering via e-post) och **Arbetsgivardeklaration** (filen lämnas alltid in för hand hos Skatteverket). Vi tar bort eller bygger klart dem; tills dess kan du lämna dem som de är.

## Konton i kontoplanen

Lönen bokförs på BAS-konton, till exempel 7010 för löner, 2710 för personalskatt, 2730 och 7510 för arbetsgivaravgifter och 2920 för semesterlöneskuld. Saknas något av kontona i er kontoplan visar lönekörningen det, med knappen **Lägg till kontona**. Se [Kontoplan](/manual/bokforing/kontoplan).

## Vanliga frågor

**Kostar det något att slå på Lön?**
Modulen är en del av NONOX. Att slå på och av den tar aldrig bort några uppgifter.

**Vi är ett aktiebolag med bara ägaren som anställd. Behöver vi lönemodulen?**
Ja, om ägaren ska ta ut lön. Tänk på att stänga av **Den som beräknar får inte attestera** om du är ensam, annars kan du inte attestera din egen lönekörning.

**Vad händer om vi stänger av modulen igen?**
Läs [Stäng av lönemodulen och Lönearkivet](/manual/lon/stang-av-lon-och-lonearkivet).

## Läs också

- [Moduler](/manual/installningar/moduler)
- [Anställda](/manual/lon/anstallda)
- [Regler och skattetabeller](/manual/lon/regler-och-skattetabeller)
- [Gör en lönekörning](/manual/lon/lonekorning)
