# Konsekvensbedömning: NONOX AI (kort version, artikel 35)

Senast uppdaterad 2026-11-01. Version 0.1 (utkast).

**Det här är en kort mall.** Företaget som slår på NONOX AI är personuppgiftsansvarigt (artikel 35.1). Stafe Development AB är personuppgiftsbiträde i den driftade tjänsten och bistår (artikel 28.3 f). Beskrivning för kunder finns i [AI i NONOX](../juridik/ai.md). Den tekniska beskrivningen och klassningen enligt AI-förordningen finns i [ADR-018](../adr/ADR-018-nonox-ai.md).

Artikel 35.1 nämner särskilt behandling som använder ny teknik. AI-tolkning av underlag med personuppgifter, som kan skickas utanför EU/EES, är därför skäl nog att göra en bedömning.

## 1. Behandlingen

| | |
|---|---|
| **Ändamål** | Läsa ett underlag och fylla i ett förslag till leverantörsfaktura, verifikation, utlägg eller bokföring av en dagsrapport. En person kontrollerar och sparar. |
| **Rättslig grund** | Berättigat intresse av effektivare bokföring (artikel 6.1 f). [Företagets intresseavvägning.] Underlaget är räkenskapsinformation som företaget ändå måste behandla (bokföringslagen 5 kap.). |
| **Registrerade** | Personer på underlagen: leverantörers kontaktpersoner, säljare på kvitton, anställda som lämnar in utlägg, och andra som nämns. |
| **Mottagare** | Den AI-leverantör företaget valt (OpenAI, Anthropic eller Google), med företagets egen API-nyckel och enligt företagets eget avtal med leverantören. Leverantören kan finnas utanför EU/EES. |
| **Vad som skickas** | Underlaget (PDF eller bild) och företagets kontoplan. För dagsrapporter skickas rapportens rubriker och belopp. Inget annat ur tjänsten skickas. |
| **Vad som sparas** | Svaret sparas som förslag. För utlägg sparas AI:ns anteckningar på utlägget. Behandlingshistoriken sparar typ av analys, leverantör, modell och antal token, aldrig innehållet. |

## 2. Risker och åtgärder i systemet

| Risk | Åtgärd |
|---|---|
| Uppgifter lämnar EU/EES | Modulen är avstängd tills företaget slår på den, väljer leverantör och lägger in en egen nyckel. Inställningssidan säger att underlaget skickas till leverantören under leverantörens villkor. |
| Anställdas kvitton skickas utan att de vet om det | Anställdas kvitton läses bara om företaget särskilt har valt det (`read_employee_receipts`). Att slå på AI räcker inte. Den anställda får veta det på Min sida innan kvittot skickas. |
| Felaktiga förslag leder till felaktig bokföring | AI:n sparar och bokför aldrig. Konton som saknas i kontoplanen tas bort. En leverantör väljs bara när den redan finns i registret: med samma organisationsnummer, momsregistreringsnummer, bankgiro, plusgiro, IBAN eller e-postdomän (inte för gratistjänster som Gmail), eller samma namn utan bolagsform. Ett liknande namn föreslås bara ("Menade du …?"), och en person väljer att koppla. För dagsrapporter bygger NONOX själv verifikationen och kontrollerar att debet är lika med kredit och att momsen stämmer. |
| AI:n bedömer anställda | Sedan 2026-11-01 säger instruktionen för utlägg att modellen bara ska läsa det kvittot visar och aldrig bedöma inköpet eller personen: inte om det ser privat, tillåtet eller lämpligt ut. Anteckningar gäller bara det som inte gick att läsa (ett oläsligt belopp, utländsk valuta, moms som saknas). Den som godkänner utlägget gör bedömningen ([ADR-018](../adr/ADR-018-nonox-ai.md)). |
| Nyckeln läcker | Nyckeln lagras krypterad och visas aldrig igen, bara de fyra sista tecknen. |

## 3. Kvarstående risker

- **Anteckningar om anställdas inköp.** Bedömningen är borttagen ur instruktionen (2026-11-01). En språkmodell kan ändå skriva något värderande i en anteckning; anteckningen är bara ett förslag och sparas på utlägget. Anteckningar från före ändringen finns kvar på de utlägg de skrevs för. TODO(legal-verify): om kvittoläsaren nu faller utanför bilaga III punkt 4 i AI-förordningen.
- **Rollkedjan och överföringen.** Det är oklart om AI-leverantören är företagets eget biträde när företagets egen nyckel används, och om leverantörens villkor räcker för överföringen till tredje land (artikel 28 och 44). TODO(legal-verify).
- **Leverantörens användning av uppgifterna.** Om en leverantör får använda innehållet för egna ändamål styrs av företagets avtal med leverantören, inte av NONOX. [Företaget kontrollerar sitt avtal.]
- **Information till de registrerade.** [Hur företaget informerar leverantörer och andra som finns på underlagen.]

## 3a. Avtal: NONOX AI fyller i avtalsuppgifter (ADR-033 §6)

Tillägg 2026-11-01 för modulen Avtal. Gäller bara när både NONOX AI och Avtal är påslagna och användaren har `ai:use` och `agreements:manage`.

| | |
|---|---|
| **Ändamål** | Läsa företagets hängavtal eller medlemsbekräftelse och de anställdas anställningsavtal, och föreslå vilket kollektivavtal som gäller och varje anställnings avtal, lönegrupp, befattning, anställningsform, veckoarbetstid och branschvana. En person godkänner, ändrar eller avvisar varje förslag. |
| **Registrerade** | Anställda vars anställningsavtal eller lönebesked laddas upp, och personer som nämns i företagets dokument. |
| **Vad som skickas** | Bara dokumentets text, sida för sida, med personnummer, bankuppgifter, adresser, e-postadresser och telefonnummer dolda innan den skickas. Därtill listan över avtal NONOX har data för, företagets bolagsform och, för anställningar, de avtal företaget är bundet av med avtalets lönegrupper. Ingen fil och ingen kontoplan skickas. |
| **Vad som sparas** | Dokumentet krypterat på den privata disken. Varje förslag med citat (krypterat) och sida, och om citatet hittades i dokumentet. Behandlingshistoriken sparar agent, antal förslag, antal dolda uppgifter, leverantör, modell och antal token, aldrig innehållet. |

| Risk | Åtgärd |
|---|---|
| Fler uppgifter än nödvändigt lämnar tjänsten | Maskering före sändning; bara text. Ålder och minimilön räknas av NONOX ur födelsedatumet i registret, inte av AI:n. |
| AI:n bestämmer om villkoren i en anställning | AI:n föreslår bara fakta som står i dokumentet, med citat och sida. Inget förs in utan en person. "Godkänn alla" gäller bara förslag vars citat finns ordagrant i dokumentet. Avtalets belopp och satser kommer bara ur kontrollerad avtalsdata, aldrig från AI:n. Lön och startdatum ändras inte härifrån. |
| AI:n bedömer den anställda | Instruktionerna förbjuder bedömning av personen eller arbetet; agenterna läser bara dokumentet. |
| Felaktig lönegrupp | Förslaget visar vilken beskrivning i avtalet som matchade, och det som är registrerat i dag står bredvid. Minimilönekontrollen varnar på lönekörningen. |

Kvarstående: maskeringen är mönsterbaserad och kan missa en ovanligt skriven uppgift (till exempel en adress utan rubrik). TODO(legal-verify): om förslag om lönegrupp är högrisk enligt AI-förordningens bilaga III punkt 4 b trots att en person beslutar varje fält, och vad det i så fall kräver från 2 december 2027 (ADR-018, avsnittet om AI-förordningen).

## 3b. Avtal: NONOX AI läser lokala avtal (LocalAgreementAgent, ADR-033 §6)

Tillägg 2026-11-02. Samma villkor som 3a: bara när NONOX AI och Avtal är påslagna och användaren har `ai:use` och `agreements:manage`.

| | |
|---|---|
| **Ändamål** | Läsa ett lokalt avtal mellan företaget och facket och föreslå var det avviker från det centrala avtalet (till exempel en annan OB-sats, en annan provision eller en annan beräkningsperiod), uttryckt i de avvikelser NONOX kan spara. En person godkänner, ändrar eller avvisar varje förslag, och en person med `agreements:verify` kontrollerar varje avvikelse mot det lokala avtalet innan lönen använder den. |
| **Registrerade** | Personer som nämns i det lokala avtalet (företrädare för arbetsgivaren och klubben, ibland namngivna anställda). Ett lokalt avtal gäller normalt kollektivet, inte enskilda. |
| **Vad som skickas** | Bara dokumentets text med samma maskering som i 3a. Därtill de avtal företaget är bundet av med avtalets koder och NONOX egna korta beskrivningar av dem, och listan över avvikelser NONOX kan spara. **Avtalets belopp och satser skickas inte.** Ingen fil skickas. |
| **Vad som sparas** | Dokumentet krypterat på den privata disken. Varje förslag med citat (krypterat) och sida. En godkänd avvikelse sparas på företagets avtalsbindning (`collective_agreements.overrides`) med värde, sida, vem som lade in och vem som kontrollerade den, men utan citat. Behandlingshistoriken sparar agent, antal förslag och antal dolda uppgifter, aldrig innehållet. |

| Risk | Åtgärd |
|---|---|
| Fler uppgifter än nödvändigt lämnar tjänsten | Maskering före sändning; bara text; avtalets egna värden skickas inte. |
| AI:n ändrar lönen | Ett förslag ändrar ingenting förrän en person godkänner det, och en godkänd avvikelse används i lönekörningen först när en person med `agreements:verify` har kontrollerat den. Förslag som inte passar en tillåten avvikelse eller ett avtal företaget är bundet av sparas inte alls. Lönebeskedet visar när en sats kommer från det lokala avtalet. |
| Fel läsning av ett belopp | Citat och sida visas bredvid förslaget; NONOX visar vad som gäller i dag ("Centrala avtalet" eller den tidigare avvikelsen). Avvikelsen kontrolleras en gång till innan den används. |

Kvarstående: samma maskeringsrisk som i 3a. AI:n föreslår villkor för kollektivet, inte för en enskild anställd; frågan om AI-förordningens bilaga III punkt 4 b (TODO(legal-verify), 3a) gäller även här.

## 4. Slutsats

[Företagets bedömning, namn, datum, nästa översyn.]
