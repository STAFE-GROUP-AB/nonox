# Registerförteckning (artikel 30)

Stafe Development AB, del av Stafe Group AB, för tjänsten NONOX på nonox.app. Senast uppdaterad 2026-11-02.

## A. Som personuppgiftsansvarig (art. 30.1)

| Behandling | Ändamål | Registrerade | Uppgifter | Rättslig grund | Mottagare | Tredje land | Lagring |
|---|---|---|---|---|---|---|---|
| Användarkonton och inloggning | Ge tillgång till tjänsten | Användare | Namn, e-post, engångskoder (som hash), IP-adress, sessioner | Avtal, berättigat intresse | Mailgun (e-post) | Nej | Kontot så länge det finns; koder 1 dygn; sessioner 12 h |
| Behandlingshistorik | Bokföringslagens behandlingshistorik, säkerhet | Användare | Användar-id, åtgärd, tid, IP-adress, webbläsare | Rättslig förpliktelse, berättigat intresse | – | Nej | Som räkenskapsinformationen, minst 7 år |
| Kundrelation och fakturering av tjänsten | Avtal och bokföring | Kontaktpersoner hos kunder | Namn, e-post, företagsuppgifter | Avtal, rättslig förpliktelse | – | Nej | 7 år enligt bokföringslagen |
| Webbplatsen | Visa webbplatsen, säkerhet | Besökare | IP-adress i serverloggar, nödvändiga cookies | Berättigat intresse | Vultr (server) | Nej | Serverloggar enligt serverns rotation |

## B. Som personuppgiftsbiträde (art. 30.2)

| Personuppgiftsansvarig | Kategorier av behandling | Underbiträden | Tredje land | Säkerhet |
|---|---|---|---|---|
| Varje företag som använder NONOX | Bokföring, fakturering, leverantörsreskontra, moms, lön (inklusive frånvaro, som kan vara hälsouppgifter), personalliggare (namn, person- eller samordningsnummer, arbetsgivare för inhyrd personal, in- och utcheckningstider per lokal, frivilligt foto med samtycke, avstånd till lokalen vid mobil incheckning; rättslig förpliktelse enligt 39 kap. skatteförfarandelagen, foto på samtycke), arkivering, mottagning av e-post till inkorgen (avsändarens namn och adress, meddelandets text och bilagor, som kan innehålla personuppgifter om avsändaren och andra) | Se [underbiträden](../juridik/underbitraden.md) | Laravel Forge (serveradministration, USA, DPF); AI-leverantör endast om företaget väljer det | Se [README](README.md) |

Inkorgen (modul, ADR-023): e-post till företagets adress eller till den gemensamma adressen lagras krypterad. Mottagande sker via Mailgun (EU-region) i den driftade tjänsten, eller från företagets egen brevlåda (IMAP) när NONOX körs av företaget självt. Meddelanden till den gemensamma adressen som matchar flera företag lagras utan företag tills en medlem hämtar dem; bara avsändare, ämne och datum visas för de möjliga företagen. Med NONOX AI påslaget och valt i inkorgens inställningar skickas underlaget till företagets AI-leverantör.

TODO(legal-verify): granskas av jurist; om visning av avsändare och ämne för flera möjliga företag vid den gemensamma adressen är förenligt med uppgiftsminimering; kompletteras med kategorierna per kund om förteckningen ska lämnas till IMY.
