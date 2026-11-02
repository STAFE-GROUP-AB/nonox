---
beskrivning: Knyt varje anställning till avtalet, välj lönegrupp och branschvana, ange om OB och övertid ingår i lönen, och se minimilönen som gäller.
ordning: 30
roller: [owner, payroll_admin]
modul: agreements
sokord: [lönegrupp, branschvana, erfarenhet, minimilön, ungdomslön, OB ingår i lönen, övertid ingår, komptid, avtal anställd]
---

# Avtal, lönegrupp och branschvana per anställd

När företagets avtal är valt anger du för varje anställning vilket avtal den omfattas av och vilken lönegrupp personen tillhör. Det görs under **Lön → Avtal**, i kortet **Anställda**.

Tabellen visar varje anställd med **Avtal och lönegrupp**, **Branschvana från** och den **Minimilön** som gäller den här månaden, med personens ålder.

![Varje anställd får avtal, lönegrupp och branschvana.](/bilder/manual/avtal/valj-avtal.webp)

## Så här anger du avtalsuppgifterna

1. Öppna **Lön → Avtal** och leta upp den anställda under **Anställda**.
2. Klicka på **Ändra** på raden.
3. Välj **Avtal**, eller **Inte omfattad av avtal** för den som står utanför avtalet.
4. Välj **Lönegrupp**. Listan visar avtalets lönegrupper med deras beskrivning, till exempel *Lönegrupp 2 – Yrkesarbete*.
5. Välj **Branschvana från**, den dag erfarenheten i branschen räknas från enligt avtalets regel.
6. Välj **Övertid** och **OB** (se nedan). Lämna **Enligt avtalet** om inget annat är avtalat.
7. Klicka på **Spara**.

> [!TIP]
> Har ni många anställda och deras anställningsavtal som PDF kan NONOX AI läsa dem och föreslå avtal, lönegrupp och branschvana för alla på en gång. Se [NONOX AI föreslår avtal och lönegrupp](/manual/avtal/nonox-ai).

## Lönegrupp

Lönegruppen avgör vilken minimilön som gäller. Vilken grupp en anställd hör till står i avtalet, ofta beskrivet efter arbetsuppgifter och kvalifikationer. Är du osäker, fråga arbetsgivarorganisationen eller facket.

Vissa avtal har en egen grupp för ungdomslön som bara gäller upp till en viss ålder. När personen har passerat åldern får lönekörningen en varning om att byta lönegrupp.

## Branschvana

Många avtal har högre minimilön ju längre erfarenhet personen har i branschen. NONOX räknar erfarenheten från datumet i **Branschvana från**. Avtalen har ofta egna regler för vad som räknas som ett års erfarenhet (till exempel ett visst antal timmar); den bedömningen gör du när du väljer datumet.

Åldern räknar NONOX själv från födelsedatumet i personnumret, och tiden i företaget från anställningens startdag.

## Övertid och OB som ingår i lönen

Ibland är det skriftligt avtalat att övertid eller OB ingår i lönen, till exempel för en chef.

- **Övertid**: **Enligt avtalet**, **Betalas ut**, **Tas ut som komptid** eller **Ingår i lönen (skriftligt avtal)**.
- **OB**: **Enligt avtalet**, **Betalas ut** eller **Ingår i lönen (skriftligt avtal)**.

Står det **Ingår i lönen** stoppas lönekörningen om någon lägger in timmar med avtalets OB- eller övertidsart för personen, så att de inte betalas två gånger. En rad med ett belopp som du har skrivit själv stoppas inte; den betalas som den är.

> [!NOTE]
> **Tas ut som komptid** sparas på anställningen, men NONOX för inget komptidssaldo ännu. Håll reda på komptiden själv tills vidare.

## Minimilönen och varningen

Varje gång lönen beräknas jämför NONOX personens lön med avtalets minimilön. Jämförelsen görs för lönegruppen, åldern, branschvanan och tiden i företaget, i det lönesteg som gäller vid periodens slut. Den högsta minimilön personen uppfyller villkoren för gäller.

Lönekörningen får en varning med källan om:

- lönen är under minimilönen,
- lönegrupp saknas på anställningen,
- lönegruppen inte finns i avtalet,
- ungdomslönen gäller en ålder personen har passerat.

Varningen stoppar inte lönen, och NONOX ändrar aldrig lönen själv. Ska lönen höjas gör du det under **Lön → Anställda**, med **Lönerevision**. Se [Lönerevision, slutlön och arbetsgivarintyg](/manual/lon/lonerevision-och-slutlon).

Varningen kan stängas av: **Företagsinställningar → Moduler → Avtal → Inställningar**, **Varna när lönen är under avtalets minimilön**. Vi rekommenderar att ha den på.

## Vanliga frågor

**Raden visar "Versionen är inte kontrollerad".**
Avtalets version för den här månaden är inte kontrollerad. Lönekörningen stoppas för personen tills den är det. Se [Versioner och kontroll](/manual/avtal/versioner-och-kontroll).

**Var ändrar jag befattning och veckoarbetstid?**
Under **Lön → Anställda**. Veckoarbetstiden behövs för att räkna timlön enligt avtalet för månadsavlönade.

**Minimilönen gäller heltid. Vad händer med deltid?**
NONOX jämför månadslönen i **Lön, kronor** på den anställda, som är lönen vid heltid, med avtalets minimilön. Sysselsättningsgraden påverkar alltså inte jämförelsen. För timavlönade jämförs timlönen med avtalets minimitimlön. Har avtalet bara minimilön per timme för en månadsavlönad grupp, eller tvärtom, kan jämförelsen inte göras.

## Läs också

- [Välj avtal för företaget](/manual/avtal/valj-avtal)
- [OB och övertid enligt avtalet](/manual/avtal/ob-och-overtid)
- [NONOX AI föreslår avtal och lönegrupp](/manual/avtal/nonox-ai)
- [Anställda](/manual/lon/anstallda)
