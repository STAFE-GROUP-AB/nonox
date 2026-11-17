---
beskrivning: Budget för personalkostnaden per vecka, för hela företaget eller en avdelning, som ett belopp eller en andel av prognosen. Schemat varnar när den beräknade kostnaden närmar sig budgeten.
ordning: 106
roller: [owner, admin, payroll_admin, scheduler]
modul: planning
rutter: [planning.budget]
sokord: [budget, personalbudget, lönebudget, personalkostnad, lönekostnad, budget per vecka, över budget, andel av försäljningen]
---

# Budget för personalkostnad

Här sätter du hur mycket personalen får kosta en vecka, för hela företaget eller för en avdelning. Schemat visar sedan veckans beräknade kostnad mot budgeten medan du planerar, och varnar när kostnaden närmar sig eller går över den. Varningen stoppar aldrig något: du kan alltid spara och publicera pass.

Sidan finns under **Planering → Budget**.

![Budget för personalkostnad med åtta veckor, budgeten, den beräknade kostnaden och läget för varje vecka.](/bilder/manual/planering/budget.webp)

## Så här kommer du igång

1. Gå till **Planering → Budget**.
2. Välj under **Gäller** om budgeten ska gälla **Hela företaget** eller en avdelning. Arkiverade avdelningar finns inte i listan, och en budget på en avdelning som har arkiverats räknas inte längre.
3. Under **Sätt budget** väljer du **Från vecka** och, om budgeten ska gälla flera veckor i rad, **Till vecka**. Välj en dag i veckan, så gäller budgeten hela den veckan.
4. Välj **Typ av budget**: **Belopp per vecka** eller **Andel av prognosen**.
5. Skriv **Belopp i kronor** eller **Andel i procent** och klicka på **Spara budget**.

Det som redan finns för veckorna ersätts. Vill du ändra en enstaka vecka klickar du på **Ändra** i tabellen; formuläret fylls då i med veckans budget.

## Belopp eller andel av prognosen

- **Belopp per vecka** är ett fast belopp i kronor, till exempel 25 000 kr för veckans lönekostnad.
- **Andel av prognosen** är en procent av veckans prognos för försäljningen, till exempel 30 %. Prognosen är densamma som under [Bemanningsbehov](/manual/planering/bemanningsbehov): snittet av samma veckodag de senaste veckorna i bokföringen, med era egna ändringar av enskilda dagar. För en avdelning med kostnadsställe räknas avdelningens egen försäljning, annars hela företagets.

Har bara några av veckans dagar en prognos räknas budgeten på dem, och den jämförs bara med kostnaden för passen de dagarna. Ligger det pass på en dag utan prognos står det under budgeten, till exempel "Passen 1 dag utan prognos (2 160,85 kr) räknas inte mot budgeten." En dag utan både prognos och pass, till exempel en dag ni har stängt, räknas inte som en lucka. Har veckan ingen prognos alls blir budgeten inget belopp, och läget visas som **Inget belopp**.

## Vad budgeten jämförs med

**Beräknad kostnad** är summan av den uppskattade lönekostnaden för veckans pass, samma siffra som i schemat. Den räknas på samma sätt: timlön (månadslön räknad per timme), OB enligt kollektivavtalet och, när reglerna är kontrollerade, semesterersättning och arbetsgivaravgifter. Saknas någon del står en stjärna (*). Ett pass räknas till den vecka det börjar. Utkast räknas med, inställda pass inte. Ett öppet pass har ingen kostnad förrän någon har tagit det.

Kostnaden är alltid en uppskattning. Lönen räknas i lönekörningen.

För **Hela företaget** jämförs budgeten med alla veckans pass. Har hela företaget ingen egen budget men avdelningarna har det, visas summan av avdelningarnas budgetar mot kostnaden för passen i de avdelningarna, som **Summan av avdelningarnas budgetar**.

## Varningarna

**Läge** visar för varje vecka:

- **Inom budget** när kostnaden är under gränsen för varningen.
- **Nära budget** (gul) från gränsen, som standard 95 % av budgeten.
- **Över budget** (röd) från 100 %.

Gränsen ändrar du under **Varna från**, mellan 50 och 99,99 %. Vid 100 % varnas det alltid.

I schemat syns raden **Budget vecka** längst ned under veckans summa, med kostnaden mot varje budget och en varning när den är nära eller över. När du sparar, flyttar eller kopierar ett pass så att veckans kostnad blir nära eller över budgeten står varningen i meddelandet som visas, och likaså när du publicerar en vecka som är nära eller över. Passet sparas ändå.

![Schemats veckosumma med raden Budget vecka och en varning för en vecka nära budgeten.](/bilder/manual/planering/schema-budget.webp)

## Kopiera en veckas budget framåt

Under **Kopiera en veckas budget framåt** väljer du **Vecka att kopiera** och **Antal veckor framåt** och klickar på **Kopiera**. Hela företagets och alla avdelningars budget för veckan kopieras. En vecka som redan har en budget behåller den.

**Ta bort** i tabellen tar bort budgeten för en vecka. Passen påverkas inte.

## Vem får göra vad

Alla som ser schemat ser budgeten och varningarna. Budgeten sätts, kopieras och tas bort av **Ägare**, **Administratör** och **Löneadministratör**. En **Schemaläggare** ser budgeten men kan inte ändra den. Se [Roller och behörigheter](/manual/planering/roller-och-behorigheter).

I [Schema över företag](/manual/planering/schema-over-foretag) visas varje företags budget för veckan bredvid den beräknade kostnaden. Där går den bara att läsa; budgeten ändrar du i företaget.

## Läs också

- [Bemanningsbehov](/manual/planering/bemanningsbehov) – prognosen som en andel av budgeten räknas på.
- [Lönekostnad och varningar](/manual/planering/lonekostnad-och-varningar) – vad den uppskattade kostnaden räknas på.
- [Bemanning och kostnad](/manual/planering/bemanning-och-kostnad) – personalkostnaden mot försäljningen i bokföringen.
