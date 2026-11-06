# Lansering: Peppol e-faktura

Modulen Peppol e-faktura ([ADR-050](../adr/ADR-050-peppol.md), tillägg 2026-11-06) är byggd och vilande. På nonox.app finns ingen Peppol-operatör (accesspunkt) inställd, så modulen går inte att slå på. Den här sidan beskriver exakt vad som måste vara på plats, hur modulen slås på och kontrolleras, och hur den stängs av i en nödsituation.

NONOX är inte själv en accesspunkt. Sändning och mottagning går genom en certifierad Peppol-operatör; den första och enda riktiga drivrutinen är **Qvalia** (Partner API).

## 1. Det som en människa måste göra först

1. **Partneravtal med Qvalia Group AB** ("Embedded Peppol"/partner) för installationen. Ger ett partner-id (`partnerRegNo`) och API-nycklar för sandlåda och produktion. Tecknas av den som driftar installationen (för nonox.app enligt [externa avtal](manskliga-beslut.md)).
2. **Personuppgiftsbiträdesavtal (DPA)** med Qvalia, och besked om operatörens roll för sin egen kundkännedom (KYC). Lägg sedan in Qvalia i tabellen i `docs/juridik/underbitraden.md` (raden är förberedd i en kommentar), uppdatera `docs/gdpr/registerforteckning.md` och höj `LegalPageController::VERSION` och `EFFECTIVE` (en höjning per driftsättning; 30 dagars besked till kunderna enligt kommentaren i filen).
3. **Prova drivrutinen i Qvalias sandlåda** (`https://api-test.qvalia.com`) och stäng punkterna under `TODO(provider-verify)` i avsnitt 5. Anteckna resultatet i ADR-050.
4. **Per företag**: Qvalia skapar ett underkonto och gör sin kundkännedom (KYC) på företaget. Först därefter kopplar den som driftar NONOX företaget (avsnitt 3).

## 2. Nycklar och miljövariabler

Sätts i serverns `.env` (via Forge, aldrig i repot):

| Variabel | Värde | Kommentar |
|---|---|---|
| `NONOX_PEPPOL_DRIVER` | `qvalia` | Tom betyder logg-drivrutinen lokalt (inget lämnar datorn) och ingen accesspunkt i övrigt. `log` vägras i produktion. |
| `QVALIA_PARTNER_REG_NO` | partner-id | Från Qvalia. |
| `QVALIA_API_KEY` | API-nyckel | Sandlådans nyckel först, sedan produktionens. |
| `QVALIA_API_URL` | `https://api.qvalia.com` | Standard är sandlådan `https://api-test.qvalia.com`; byt först när sandlådan är provad. |
| `QVALIA_WEBHOOK_SECRET` | tom | Bara för en webhook som har prenumererats för hand. `peppol:link` sparar varje kontos egen nyckel krypterad. |
| `NONOX_PEPPOL_MAX_DOCUMENT_KB` | `102400` | Större mottagna dokument ligger kvar hos operatören och larmar. |
| `NONOX_PEPPOL_PAUSED` | tom | Nödstoppet, se avsnitt 4. |

Efter ändringen: `php artisan config:cache` på servern.

Webhookens adress, som `peppol:link` prenumererar för varje konto: `APP_URL/webhooks/peppol/qvalia` (för nonox.app `https://nonox.app/webhooks/peppol/qvalia`). Den ska vara nåbar utifrån; Cloudflare får inte blockera POST utan cookies.

Schemaläggaren måste köra `peppol:sync` (var femtonde minut) och kön `TransmitPeppolDocument`, `HandlePeppolEvent` och `InterpretInboxMessageJob`.

## 3. Checklista för att slå på och kontrollera

1. `php artisan integrations:status` visar under PEPPOL `driver qvalia`, `configured yes`, `paused no`. Samma sak i /hq → **Integrationer**.
2. Använd ett **eget testföretag** (inte en kund), med eget organisationsnummer som Qvalia har gjort KYC på.
3. Slå på **Inkorg** och **Peppol e-faktura** under **Inställningar för företaget → Moduler**. Kontrollera **Företagets Peppol-ID** (0007:organisationsnummer) och tryck **Spara**.
4. Koppla företaget: `php artisan peppol:link {företagets id} {Qvalias accountRegNo}`. Kommandot ska svara `Webhook subscribed.`. Under **Konto hos Peppol-operatören** står nu *Kopplat*.
5. **Sändning**: skapa en faktura till en kund med känt Peppol-ID (t.ex. Qvalias testmottagare i sandlådan), tryck **Kontrollera mottagaren** och sedan **Skicka via Peppol**. Statusen ska gå *I kö* → *Skickad till Peppol* → *Levererad*.
6. **Mottagning**: kryssa i **Ta emot leverantörsfakturor via Peppol**, godkänn registreringen och tryck **Registrera som mottagare**. När statusen är *Registrerat i Peppol*: be Qvalia (eller ett annat testkonto) skicka en e-faktura. Den ska komma till **Inkorg** inom en kvart (direkt via webhooken), med XML-filen som original och ett förslag till leverantörsfaktura.
7. Kontrollera att samma e-faktura skickad två gånger blir ett meddelande, och att öresavrundningen står som en rad på 3740.
8. Kontrollera loggen: inga Peppol-ID, inga dokument, inga API-nycklar. Kontrollera behandlingshistoriken (`invoice.peppol_queued`, `peppol.received`).
9. Stäng av mottagningen igen och kontrollera att registreringen tas bort ur Peppols adressbok.
10. Först därefter: slå på för en pilotkund, och låt spåret för publika sidor ändra `Features::peppol` från "kommer snart" till live, med en riktig skärmbild och en neutral jämförelserad (ADR-050, Consequences).

## 4. Nödstopp (kill switch)

Sätt i `.env` och kör `php artisan config:cache`:

```
NONOX_PEPPOL_PAUSED=true     # pausar alla accesspunkter
NONOX_PEPPOL_PAUSED=qvalia   # pausar bara Qvalia
```

Nycklarna ligger kvar. Medan pausen gäller:

- ingenting skickas till operatören och ingenting hämtas: `peppol:sync` gör ingenting, köade sändningar stannar som *I kö* med *Väntar: Peppol är tillfälligt avstängt …*, händelser från webhooken hanteras inte;
- webhooken svarar `503` med `Retry-After: 900`, så att operatören skickar händelsen igen senare;
- mottagna e-fakturor ligger kvar hos operatören; fönstret för kontrollen flyttas inte, så ingenting hoppas över;
- **Skicka via Peppol**, **Kontrollera mottagaren**, registrering, avregistrering och `peppol:link` vägras med texten *Peppol är tillfälligt avstängt av den som driftar NONOX. …*;
- modulen kan inte slås på för fler företag;
- ingenting markeras som avvisat på grund av pausen.

Slå på igen: ta bort värdet (`NONOX_PEPPOL_PAUSED=`) och kör `php artisan config:cache`. Nästa `peppol:sync` köar först de sändningar som väntade, en gång var, med samma dokument och samma idempotensnyckel, och hämtar sedan det som kommit under pausen.

## 5. Driftstörningar och övervakning

- Sändning: 429, 5xx och tidsgränser försöks igen efter 1, 5, 15, 60 minuter och 3 timmar (sex försök), med samma idempotensnyckel. Ett avvisat dokument misslyckas direkt. Användaren ser en svensk text under statusen, aldrig operatörens felmeddelande.
- Mottagning: en hämtning som misslyckas försöks igen av jobbet, och `peppol:sync` tar dokumentet vid nästa kontroll. Samma bytes tas emot en gång.
- `php artisan integrations:status` avslutas med felkod 1 när något behöver åtgärdas: sändningar i kö mer än en timme, kopplade företag som inte har kontrollerats den senaste timmen, händelser som inte gick att hantera, eller pausen.
- Loggrader: `Peppol: a sending failed.` (företags-id, sändnings-id, drivrutin, om felet går att försöka igen, antal försök), `Peppol webhook refused.` (drivrutin och skäl), `Peppol: a document for another participant was refused.` (företags-id och operatörens referens). Inga Peppol-ID och inga dokument.

Att prova i Qvalias sandlåda innan produktion (`TODO(provider-verify)` i `QvaliaAccessPoint`):

- formatet på deltagar-id i sökvägarna för uppslag och registrering;
- värdena i `docTypes` vid registrering, och att ett visitkort utan organisationsnummer godtas för en GLN-registrering (enskild firma);
- statussökvägen och statusfältet under partnerprefixet;
- att ett mottaget dokument som hämtas med `Accept: application/xml` är UBL-filen exakt som den togs emot (drivrutinen skickar nu bara `application/xml`);
- webhookens fält (`eventId`, `eventType`, `accountRegNo`, `integrationId`, `documentType`, `direction`) mot testfixturerna i `tests/Fixtures/peppol/qvalia/`, som är byggda efter dokumentationen och inte inspelade från tjänsten.

## 6. Öppna juridiska frågor

Från ADR-050 och [frågor till jurist](../juridik/fragor-till-jurist.md):

- `TODO(legal-verify)`: är en avvisad (bestridd) mottagen e-faktura fortfarande räkenskapsinformation, och när får den gallras?
- `TODO(legal-verify)`: behöver översättningen av den mottagna XML-filen till en leverantörsfaktura beskrivas enligt BFNAR 2013:2 utöver systemdokumentationen (avsnitt 5e.1)? Originalet sparas oförändrat.
- `TODO(legal-verify)`: formuleringen av mottagarens samtycke till registrering i Peppols adressbok.
- `TODO(legal-verify)`: behövs någon anmälan enligt BFL 7 kap. 3 a § för att operatören (i Sverige) förvarar en kopia? Det bör inte behövas eftersom NONOX själv sparar originalet.
- `TODO(legal-verify)`: en skattejurist bekräftar den slutliga texten för trepartshandel (artikel 141, omvänd betalningsskyldighet).
- Operatörens roll (biträde eller personuppgiftsansvarig för sin kundkännedom) och DPA.
- Bekräfta hänvisningarna till BFL 7 kap. 1 § tredje stycket och ML 17 kap. 30–31 §§ för den mottagna och den skickade XML-filen.

## 7. Kända begränsningar

- Ett GLN (0088) kan inte kontrolleras mot sin ägare i NONOX; operatörens kundkännedom stoppar en felaktig registrering.
- En självhostad installation behöver ett eget avtal med en Peppol-operatör, eller en egen drivrutin bakom samma gränssnitt.
