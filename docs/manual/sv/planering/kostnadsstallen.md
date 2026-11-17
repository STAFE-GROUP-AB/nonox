---
beskrivning: Lägg avdelningar och pass på kostnadsställen, så att lönen för passen och arbetsgivaravgifterna bokförs där i lönens verifikation.
ordning: 95
roller: [owner, admin, payroll_admin, scheduler]
modul: planning
sokord: [kostnadsställe, kostnadsställen, resultatenhet, avdelning, lönekostnad per avdelning, arbetsgivaravgifter per kostnadsställe, fördela lönekostnad, kontering lön, dimension]
---

# Kostnadsställen på pass

Arbetar samma person både i köket och i baren vill du kanske se lönekostnaden för varje del för sig. Ge avdelningarna ett kostnadsställe, så följer kostnadsstället med passen hela vägen till lönens verifikation: timmarna, OB och arbetsgivaravgifterna bokförs på kostnadsstället där passet låg.

Ett kostnadsställe är en del av verksamheten som du vill följa upp för sig, till exempel en restaurang eller en avdelning. Kostnadsställena lägger du upp under bokföringen, se [Kostnadsställen och projekt](/manual/bokforing/kostnadsstallen-och-projekt).

![En avdelning med kostnadsställe under Planering, Inställningar.](/bilder/manual/planering/kostnadsstallen.webp)

## Så här ger du en avdelning ett kostnadsställe

1. Gå till **Planering → Inställningar**.
2. Klicka på **Ändra** vid avdelningen, eller på **Ny avdelning**.
3. Välj **Kostnadsställe**. Fältet visas när företaget har minst ett kostnadsställe.
4. Klicka på **Spara**.

Avdelningen visar sedan **Kostnadsställe** med kod och namn i listan. Nya pass i avdelningen får kostnadsstället. Pass som redan är planerade behåller sitt.

## Så här lägger du ett enstaka pass på ett annat kostnadsställe

1. Klicka på passet i **Planering → Schema**.
2. Välj ett annat **Kostnadsställe**. Står det **Avdelningens eller anställningens** gäller avdelningens kostnadsställe, och har avdelningen inget gäller anställningens.
3. Klicka på **Spara**.

Flyttar du passet till en annan avdelning utan att ha valt ett eget kostnadsställe får det den nya avdelningens. Att bara byta kostnadsställe på ett publicerat pass gör det inte till ett utkast igen, för personalen ser inte kostnadsstället.

## Det här händer i lönen och bokföringen

- **Skicka till lönen** på [Tid att attestera](/manual/planering/tid-att-attestera) skriver en tidrapport per dag, löneart och kostnadsställe. Har en person pass på två kostnadsställen samma dag delas timlönen efter hur många minuter som var planerade på vart och ett, och OB efter var OB-tiden låg enligt kollektivavtalet: kvällens OB i baren hamnar på barens kostnadsställe, inte på köket där passet låg på förmiddagen. Delningen görs i hundradels timmar, så att summan blir exakt samma timmar som om dagen hade legat på ett kostnadsställe.
- I lönekörningen blir det en rad per löneart och kostnadsställe, till exempel **Timlön enligt tidrapport, kostnadsställe 10**.
- Lönens verifikation i serie L bokför de raderna på sina kostnadsställen. Allt annat, som månadslön, förmåner och frånvaroavdrag, bokförs på anställningens kostnadsställe när **Kostnadsställe och projekt från anställningen** är på i löneinställningarna.
- **Arbetsgivaravgifterna** på 7510 fördelas för varje lönebesked över kostnadsställena efter hur stor del av avgiftsunderlaget som ligger på vart och ett. Skattefria ersättningar räknas inte, och en drivmedelsförmån räknas till marknadsvärdet. Ören som blir över när delarna avrundas går till de största resterna, så att delarna alltid blir exakt lönebeskedets avgift och verifikationen balanserar. Se [Lönefil och bokföring](/manual/lon/lonefil-och-bokforing).
- Rapporten **Kostnadsställen** under **Lön → Rapporter** räknar på samma sätt.

## Bra att veta

- Ett inaktivt kostnadsställe går inte att välja för en avdelning eller ett nytt pass. Pass som redan ligger på det behåller det. Har en avdelning ett kostnadsställe som har blivit inaktivt får nya pass i avdelningen anställningens kostnadsställe.
- En kopia av en vecka behåller passens kostnadsställen. Pass från en mall och ett kopierat enstaka pass får avdelningens.
- Övertid och mertid för en dag bokförs där dagens pass låg, som timlönen. Övertid och mertid som räknas för hela veckan eller beräkningsperioden bokförs på anställningens kostnadsställe.
- Ett pass som redan ligger på avdelningens kostnadsställe behåller det när du ändrar passet, också om kostnadsstället har blivit inaktivt.
- Har varken passet eller avdelningen något kostnadsställe bokförs lönen som förut, på anställningens kostnadsställe.

## Läs också

- [Kom igång och inställningar](/manual/planering/kom-igang)
- [Tid att attestera](/manual/planering/tid-att-attestera)
- [OB och övertid i lönen](/manual/planering/ob-och-overtid-i-lonen)
- [Lönefil och bokföring](/manual/lon/lonefil-och-bokforing)
