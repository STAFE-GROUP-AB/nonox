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
| Felaktiga förslag leder till felaktig bokföring | AI:n sparar och bokför aldrig. Konton som saknas i kontoplanen tas bort. En leverantör väljs bara när organisationsnummer, bankgiro eller plusgiro redan finns i registret. För dagsrapporter bygger NONOX själv verifikationen och kontrollerar att debet är lika med kredit och att momsen stämmer. |
| AI:n bedömer anställda | Instruktionen för utlägg ber modellen notera kvitton som ser privata ut, alkohol och representation. Anteckningen visas för den som godkänner utlägget. Det är en bedömning av den anställdas inköp, se kvarstående risker. |
| Nyckeln läcker | Nyckeln lagras krypterad och visas aldrig igen, bara de fyra sista tecknen. |

## 3. Kvarstående risker

- **Anteckningar om anställdas inköp.** En anteckning som "ser privat ut" eller "alkohol" kan påverka hur arbetsgivaren ser på en anställd. Anteckningen sparas på utlägget. Rekommendation: ta bort bedömningen ur instruktionen och låt AI:n bara läsa det som står på kvittot (se ADR-018). Inte genomfört.
- **Rollkedjan och överföringen.** Det är oklart om AI-leverantören är företagets eget biträde när företagets egen nyckel används, och om leverantörens villkor räcker för överföringen till tredje land (artikel 28 och 44). TODO(legal-verify).
- **Leverantörens användning av uppgifterna.** Om en leverantör får använda innehållet för egna ändamål styrs av företagets avtal med leverantören, inte av NONOX. [Företaget kontrollerar sitt avtal.]
- **Information till de registrerade.** [Hur företaget informerar leverantörer och andra som finns på underlagen.]

## 4. Slutsats

[Företagets bedömning, namn, datum, nästa översyn.]
