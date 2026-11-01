---
beskrivning: Ladda upp hängavtal och anställningsavtal, låt NONOX AI föreslå avtal, lönegrupp och branschvana för all personal, och godkänn varje förslag själv.
ordning: 60
roller: [owner, payroll_admin]
modul: agreements
rutter: [agreements.ai]
sokord: [AI kollektivavtal, analysera anställningsavtal, analysera all personal, föreslå lönegrupp, AI lön, läsa avtal]
---

# NONOX AI föreslår avtal och lönegrupp

Att ställa in avtalet för varje anställd tar tid: vilket avtal, vilken lönegrupp, sedan när personen har branschvana, hur många timmar i veckan. Med NONOX AI kan du ladda upp företagets och de anställdas dokument och låta AI:n läsa dem och föreslå uppgifterna, med citat och sida ur dokumentet. Du går igenom förslagen och godkänner, ändrar eller avvisar vart och ett.

> [!IMPORTANT]
> AI:n föreslår, du bestämmer. Inget förs in förrän en person har godkänt det. Avtalets belopp och satser kommer aldrig från AI:n, bara från den kontrollerade avtalsdatan. Ålder och minimilön räknar NONOX själv från födelsedatumet. AI:n bedömer aldrig en person.

## Förutsättningar

- Modulerna **NONOX AI** och **Avtal** är på. Se [NONOX AI](/manual/installningar/nonox-ai).
- Du har behörighet att använda NONOX AI och att hantera avtal (ägaren och löneadministratören har det normalt).

Då visas fliken **NONOX AI** under **Lön → Avtal**.

## Vad som skickas till AI-leverantören

NONOX AI läser bara texten i dokumentet, sida för sida. Personnummer, bankuppgifter, adresser, e-postadresser och telefonnummer döljs innan texten skickas till er AI-leverantör. Dokumentet måste därför vara en PDF med text (inte en inskannad bild) eller en textfil, högst 5 MB. Dokumenten sparas krypterade.

Varje analys skrivs i behandlingshistoriken, med vilket dokument och hur många förslag det gav, men utan dokumentets innehåll.

## Så här får du förslag för företaget

1. Öppna **Lön → Avtal** och fliken **NONOX AI**.
2. Under **Ladda upp dokument**: lämna **Gäller** på **Företaget**.
3. Välj **Dokument**: **Hängavtal**, **Medlemsbekräftelse från arbetsgivarorganisation**, **Lönebesked eller lönerapport** eller **Annat dokument**.
4. Välj filen och klicka på **Ladda upp**.
5. Klicka på **Låt NONOX AI läsa** vid dokumentet under **Företagets dokument**.

AI:n föreslår vilket avtal och vilken variant företaget är bundet av, med meningen som visar det.

## Så här får du förslag för all personal

1. Ladda upp ett dokument per anställd: välj personen under **Gäller**, välj **Anställningsavtal** eller **Lönebesked** och klicka på **Ladda upp**.
2. När dokumenten finns under **Anställdas dokument**: klicka på **Analysera all personal**.

AI:n läser varje dokument som inte är läst och föreslår för varje anställd: avtal, lönegrupp, befattning, anställningsform, veckoarbetstid och branschvana, och eventuella avvikelser från avtalet. Lön, löneform och startdatum visas också, men bara för jämförelse; de ändras under **Lön → Anställda**.

## Så här granskar du förslagen

Förslagen samlas under **Förslag att granska**, en rad per uppgift med kolumnerna **Uppgift**, **Förslag**, **Registrerat i dag** och **Ur dokumentet** (citatet och sidan).

1. Jämför förslaget med citatet och med det som står i dag.
2. Klicka på **Godkänn** för att föra in uppgiften.
3. Klicka på **Ändra** om förslaget är nästan rätt, rätta värdet och klicka på **Spara och för in**.
4. Klicka på **Avvisa** om förslaget är fel.

För företagets avtal måste du ange **Bundet som** och **Bundet från** innan du kan spara med **Spara och för in**.

### Godkänna många på en gång

- **Godkänn alla för den här personen som stämmer med dokumentet** godkänner en persons förslag.
- **Godkänn alla som stämmer med dokumentet** godkänner alla förslag.

Båda gäller bara förslag vars citat hittades ordagrant i dokumentet. Står det **Citatet hittades inte i dokumentet.** på ett förslag måste det godkännas för sig, efter att du har kontrollerat det mot dokumentet.

> [!TIP]
> Godkänn företagets avtal först. Ett förslag om avtal eller lönegrupp på en anställd kan bara föras in när företaget är bundet av avtalet.

## Ta bort ett dokument

Klicka på **Ta bort** vid dokumentet och bekräfta. Dokumentet tas bort med de förslag som inte är hanterade. Uppgifter du redan har godkänt finns kvar.

## Vanliga frågor

**Vilken AI-leverantör används?**
Den som företaget har valt under NONOX AI, med företagets egen nyckel. Se [NONOX AI](/manual/installningar/nonox-ai).

**Kan AI:n läsa ett lokalt avtal?**
Inte ännu. Lokala avtal stöds inte i Avtal. Se [Det här gör Avtal inte ännu](/manual/avtal/det-har-gor-avtal-inte-annu).

**AI:n hittade inget avtal i dokumentet.**
Kontrollera att dokumentet har text som går att markera, och att det verkligen nämner avtalet. Annars väljer du avtalet för hand. Se [Välj avtal för företaget](/manual/avtal/valj-avtal).

## Läs också

- [Avtal, lönegrupp och branschvana per anställd](/manual/avtal/avtal-per-anstalld)
- [Välj avtal för företaget](/manual/avtal/valj-avtal)
- [NONOX AI](/manual/installningar/nonox-ai)
