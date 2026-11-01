# Konsekvensbedömning: inkorgen (kort version, artikel 35)

Senast uppdaterad 2026-11-01. Version 0.1 (utkast).

**Det här är en kort mall.** Företaget som slår på inkorgen är personuppgiftsansvarigt för meddelandena (artikel 35.1). Stafe Development AB är personuppgiftsbiträde i den driftade tjänsten och bistår (artikel 28.3 f). Fält inom hakparentes fylls i av företaget. Teknisk beskrivning finns i [ADR-023](../adr/ADR-023-inbox.md).

TODO(legal-verify): om inkorgen kräver en konsekvensbedömning. Den behandlar okänt innehåll från tredje man och kan automatiskt bokföra med en mall. Artikel 35.3 a kräver en bedömning bara vid beslut med rättsliga följder för fysiska personer, och bokföring är inte ett sådant beslut. Mallen finns ändå för att visa riskerna.

## 1. Behandlingen

| | |
|---|---|
| **Ändamål** | Ta emot underlag (leverantörsfakturor, kvitton, dagsrapporter från kassasystem) med e-post, spara originalet som räkenskapsinformation och ta fram ett förslag till bokföring som en person godkänner. |
| **Rättslig grund** | Rättslig förpliktelse att bokföra och bevara räkenskapsinformation (artikel 6.1 c, bokföringslagen (1999:1078) 5 kap. om verifikationer och 7 kap. om arkivering), och berättigat intresse av en enkel mottagning (artikel 6.1 f). [Företagets intresseavvägning.] |
| **Registrerade** | Avsändare (leverantörer, kassasystem, egen personal som vidarebefordrar) och tredje man som nämns i meddelanden och bilagor. |
| **Uppgifter** | Avsändarens namn och adress, ämne, datum, meddelandets text (krypterad i databasen), originalet som `.eml` och bilagorna (krypterade i vila). Innehållet styrs av avsändaren och kan innehålla vilka personuppgifter som helst, även känsliga. |

## 2. Risker och åtgärder i systemet

| Risk | Åtgärd |
|---|---|
| Okända avsändare skickar personuppgifter eller skadlig kod | En avsändare som inte finns på företagets lista över godkända avsändare hamnar i karantän, och inget tolkas förrän en person godkänner avsändaren. Mejl som inte klarar SPF/DKIM/DMARC hamnar i karantän även från godkända avsändare. Farliga bilagor (program, skript, Office-filer med makron, HTML, SVG) spärras alltid. Virusskanning med ClamAV när den är konfigurerad. **Den är ännu inte påslagen i den driftade tjänsten.** |
| Mejl hamnar hos fel företag | Den gemensamma adressen skickar bara vidare när exakt ett företag har avsändarens adress på sin lista och avsändaren själv är medlem i företaget. Domäner styr aldrig. Matchar flera företag lagras mejlet utan företag, och bara avsändare, ämne och datum visas för de möjliga företagen. |
| Läckage i vila | Meddelandets text krypteras i databasen, och `.eml` och bilagor krypteras på disken (`EncryptedAdapter`). HTML visas aldrig. Filer visas i en sandlåda. |
| Felaktig bokföring | AI bokför aldrig. Automatisk bokföring kräver en mall som en person har slagit på, exakt matchning, en balanserad verifikation, inga varningar och en godkänd och autentiserad avsändare. Bokföringen sker i den personens namn och loggas. |
| Bedrägeri (ändrat bankgiro, liknande domän) | Varning som alltid stoppar automatisk bokföring och måste bockas av. |
| Uppgifter sparas för länge | Avvisade meddelanden gallras efter 90 dagar, och mejl till den gemensamma adressen som ingen hämtat efter 30 dagar (`inbox:prune`). Det som bokförts sparas som räkenskapsinformation. |
| Uppgifter till AI-leverantör | Bara om företaget har slagit på NONOX AI och valt det i inkorgens inställningar, se [konsekvensbedömningen för AI](konsekvensbedomning-ai.md). |

## 3. Kvarstående risker

- **Information till avsändare och tredje man (artikel 14).** De som nämns i ett mejl får ingen information från NONOX. TODO(legal-verify): om undantaget i artikel 14.5 b (oproportionerlig ansträngning) eller 14.5 c (lagstadgad bokföring) räcker, och vad företaget ska skriva i sin integritetspolicy.
- **Gallring av avvisade meddelanden.** Det är inte klarlagt om ett avvisat underlag kan vara räkenskapsinformation (BFL 5 kap. 7 §). TODO(legal-verify).
- **Uppgiftsminimering vid den gemensamma adressen.** Avsändare och ämne visas för flera möjliga företag innan någon hämtar mejlet. TODO(legal-verify).
- **Virusskanning** är inte påslagen i den driftade tjänsten.

## 4. Slutsats

[Företagets bedömning, namn, datum, nästa översyn.]
