# Regelverk som berör NONOX

Senast uppdaterad 2026-11-01. Ett register över de regelverk som berör NONOX, med status för varje. Registret är ingen juridisk bedömning. Varje slutsats som inte har granskats av jurist är markerad TODO(legal-verify), och frågorna finns samlade i [frågor till jurist](fragor-till-jurist.md).

Rollerna: **Stafe Development AB** utvecklar NONOX och driftar tjänsten på nonox.app. **Kunden** är det företag som för sin bokföring, lön och personalliggare i NONOX. **Den som kör NONOX själv** har samma ansvar som Stafe har för driften.

| Regelverk | Gäller? | Status |
|---|---|---|
| GDPR | Ja | Grund på plats, flera öppna frågor |
| Dataskyddslagen | Ja | Personnummer motiverade, ej juristgranskat |
| Bokföringslagen och BFNAR 2013:2 | Ja, för kunden; NONOX ska göra det möjligt | Stöd byggt, ej granskat av revisor eller jurist |
| Skatteförfarandelagen 39 kap. (personalliggare) | Ja, för kunder i berörda branscher | Byggt, väntar på Skatteverkets format |
| AI-förordningen | Ja | Klassning gjord som utkast |
| NIS2 och cybersäkerhetslagen | Troligen inte | Beror på koncernens storlek |
| Tillgänglighetslagen (EAA) | Troligen inte | B2B-tjänst, ej utrett för konsumenter |
| Cyberresiliensförordningen (CRA) | Oklart | Beror på rollen för öppen källkod |
| Lagen om elektronisk kommunikation (cookies) | Ja | Bara nödvändiga kakor |
| ViDA och e-fakturering | Kommer | Peppol läses, skickas inte |

## 1. Dataskyddsförordningen (EU) 2016/679, GDPR

**Gäller:** ja. Stafe är personuppgiftsansvarig för konton, inloggning och den egna kundrelationen, och personuppgiftsbiträde för det kunderna lägger in (artikel 28).

**Det här gör NONOX:**
- [Registerförteckning](../gdpr/registerforteckning.md) (artikel 30), [integritetspolicy](integritetspolicy.md) (artikel 13), [personuppgiftsbiträdesavtal](personuppgiftsbitradesavtal.md) och [underbiträden](underbitraden.md) (artikel 28).
- Konsekvensbedömningar för [lön](../gdpr/konsekvensbedomning-lon.md), och mallar för [personalliggare](../gdpr/konsekvensbedomning-personalliggare.md), [inkorg](../gdpr/konsekvensbedomning-inkorg.md) och [AI](../gdpr/konsekvensbedomning-ai.md) (artikel 35).
- [Incidentrutin](../gdpr/incidentrutin.md) (artikel 33–34), [lagringstider och gallring](../gdpr/lagringstider.md) (artikel 5.1 e).
- Registerutdrag, radering, anonymisering och begränsning i tjänsten (artikel 15–18, 20).
- Kryptering av personnummer, bankuppgifter, känsliga fritexter och filer i vila, behörighet per roll, behandlingshistorik och säkerhetsrubriker (artikel 32).

**Det här saknas:**
- Återställningstest på en helt separat server. Varje vecka återställs och kontrolleras kopian i en tillfällig databas, och kopior på annan plats finns ([säkerhetskopiering](../gdpr/sakerhetskopiering.md), artikel 32.1 c och d).
- Namngiven incidentansvarig.
- Bedömning av dataskyddsombud (artikel 37.1 c), se [README](../gdpr/README.md#dataskyddsombud-artikel-37). TODO(legal-verify).
- Bekräftade personuppgiftsbiträdesavtal och DPF-certifiering för varje underbiträde. TODO(legal-verify).
- Juristgranskning av alla texter i `docs/juridik` och `docs/gdpr`.

## 2. Dataskyddslagen (2018:218)

**Gäller:** ja, som komplettering till GDPR.

**Det här gör NONOX:** personnummer behandlas bara där en lagstadgad skyldighet kräver säker identifiering (3 kap. 10 §): lön och AGI, personalliggare, ROT och RUT, och enskilda näringsidkares organisationsnummer. Motiveringen finns i [README](../gdpr/README.md#personnummer).

**Det här saknas:** juristens bekräftelse av att varje användning är "klart motiverad". TODO(legal-verify).

## 3. Bokföringslagen (1999:1078) och BFNAR 2013:2

**Gäller:** bokföringsskyldigheten ligger på kunden. NONOX måste göra det möjligt att uppfylla den.

**Det här gör NONOX:**
- Bokförda verifikationer kan inte ändras eller raderas. Det stoppas på tre nivåer: behörighet, modell och databasregel.
- Rättelse sker med rättelseverifikation.
- Obruten nummerföljd per serie och räkenskapsår.
- Hashkedja och nattlig kontroll (`ledger:verify`).
- Behandlingshistorik som inte kan ändras.
- [Systemdokumentation](../systemdokumentation/README.md) (BFNAR 2013:2).
- Arkivpaket per räkenskapsår som kan läsas utan NONOX.
- SIE4-export.
- Räkenskapsinformation kan inte raderas inom sju år (7 kap. 2 §).

**Det här saknas:**
- Granskning av revisor eller jurist.
- Svar på öppna frågor, bland annat om underlag får läggas till efter bokföring och om en sparad `.eml` uppfyller kraven på hur räkenskapsinformation ska bevaras. Frågorna finns i systemdokumentationens avsnitt 8. TODO(legal-verify).

## 4. Skatteförfarandelagen (2011:1244) 39 kap., personalliggare

**Gäller:** ja, för kunder i branscher med skyldighet att föra personalliggare. Fler branscher omfattas från den 1 januari 2027, och från den 1 juli 2027 ska liggaren kunna överföras elektroniskt vid kontrollbesök (prop. 2025/26:282, enligt [ADR-022](../adr/ADR-022-staff-ledger.md)).

**Det här gör NONOX:**
- Elektronisk liggare där poster aldrig ändras.
- Hashkedja och nattlig kontroll.
- Kiosk som fungerar utan nät.
- Vy för kontrollbesök med export till PDF, CSV och en provisorisk XML.
- Maskerade och krypterade personnummer, och loggad visning.

**Det här saknas:**
- Skatteverkets XML-schema och API för de nya branscherna. Exporten är provisorisk, och överföringen är en attrapp som inte skickar något.
- Verifierad bevarandetid. Två år antas.
- Exakt vilka uppgifter som ska registreras och hur branscherna avgränsas. TODO(legal-verify).

Lönen berör också skatteförfarandelagen: arbetsgivardeklaration på individnivå och skatteavdrag. Öppna frågor om det finns i [ADR-012](../adr/ADR-012-payroll.md) och i systemdokumentationen.

## 5. AI-förordningen (EU) 2024/1689, ändrad genom förordning (EU) 2026/1744

**Gäller:** ja. Stafe är troligen leverantör av AI-systemet NONOX AI, och kunden är troligen tillhandahållare. TODO(legal-verify).

**Det här gör NONOX:**
- Klassning per AI-agent i [ADR-018](../adr/ADR-018-nonox-ai.md).
- Kundinformation i [AI i NONOX](ai.md).
- AI:n sparar och bokför aldrig något själv.
- Anställda informeras innan deras kvitto skickas.
- Kvittoläsaren bedömer inte längre anställdas inköp ("ser privat ut", alkohol, representation); den läser bara det kvittot visar (2026-11-01).
- Behandlingshistoriken loggar varje analys.

**Det här saknas:**
- Juristens bedömning av om kvittoläsaren faller utanför bilaga III punkt 4 nu när bedömningen av anställdas kvitton ("ser privat ut", alkohol, representation) är borttagen (2026-11-01). Enligt sekundära källor gäller fristående högrisksystem enligt bilaga III från den 2 december 2027. Datumet ska kontrolleras mot EUR-Lex. TODO(legal-verify).
- Plan för AI-kunnighet (artikel 4).
- Juristgranskning av roller och datum. TODO(legal-verify).

## 6. NIS2-direktivet (EU) 2022/2555 och cybersäkerhetslagen (2025:1506)

**Gäller:** troligen inte. Lagen och cybersäkerhetsförordningen (2025:1507) gäller från den 15 januari 2026. De omfattar i huvudsak medelstora och större verksamheter i de sektorer som anges i lagen. En molntjänst kan höra till sektorn digital infrastruktur. Storleken räknas enligt EU:s definition av små och medelstora företag, där även partnerföretag och anknutna företag räknas in. Därför avgör Stafe Groups storlek som koncern, inte bara Stafe Development AB:s storlek.

**Det här gör NONOX:** inget särskilt för NIS2. Åtgärderna för GDPR (artikel 32) och [SECURITY.md](../../SECURITY.md) täcker en del av det som lagen kräver.

**Det här saknas:**
- Koncernens storlek och om tjänsten är en molntjänst i lagens mening. TODO(legal-verify).
- Vilken tillsynsmyndighet som gäller enligt förordningen (2025:1507). TODO(legal-verify).

## 7. Tillgänglighetsdirektivet (EU) 2019/882 (EAA) och lagen (2023:254) om vissa produkters och tjänsters tillgänglighet

**Gäller:** troligen inte. Lagen omfattar vissa tjänster till konsumenter. NONOX säljs till företag. Mikroföretag som tillhandahåller tjänster är dessutom undantagna.

**Det här gör NONOX:** målet är WCAG 2.2 nivå AA (AGENTS.md §11.1). En första egen granskning gjordes 2026-11-02 (automatisk kontroll med axe-core av 100 sidor plus manuell granskning av huvudflödena). Resultatet och kända brister står i [granskningsrapporten](../tillganglighet.md) och i den publika [tillgänglighetsredogörelsen](tillganglighet.md) på /tillganglighet.

**Det här saknas:**
- En granskning av någon utomstående och test med användare av hjälpmedel.
- Granskning av de PDF-filer tjänsten skapar.
- Bedömning av om någon del riktar sig till konsumenter, till exempel Min sida för anställda eller den publika webbplatsen. TODO(legal-verify).

## 8. Cyberresiliensförordningen (EU) 2024/2847 (CRA)

**Gäller:** oklart. Förordningen gäller produkter med digitala element som släpps ut på marknaden. Skyldigheten att rapportera aktivt utnyttjade sårbarheter och allvarliga incidenter (artikel 14) gäller från den 11 september 2026. Övriga skyldigheter gäller i huvudsak från den 11 december 2027. Datumen kommer från sekundära källor och ska kontrolleras mot EUR-Lex. En ren SaaS-tjänst omfattas i regel inte. NONOX ges dock också ut som programvara att installera själv, och samma företag tar betalt för den driftade tjänsten. Det är oklart om Stafe då är tillverkare eller förvaltare av programvara med öppen källkod (artikel 24).

**Det här gör NONOX:** [SECURITY.md](../../SECURITY.md) beskriver hur sårbarheter rapporteras. Varje release har en förteckning över programvarukomponenter (SBOM, CycloneDX) som bilaga.

**Det här saknas:**
- Rollbedömning. TODO(legal-verify).
- Om Stafe är tillverkare: en rutin för rapportering inom artikel 14:s tidsfrister och säkerhetsuppdateringar under supportperioden. Förteckningen över programvarukomponenter (SBOM) finns redan.

## 9. Lagen (2022:482) om elektronisk kommunikation, kakor

**Gäller:** ja, för webbplatsen och tjänsten (9 kap. 28 §).

**Det här gör NONOX:** bara nödvändiga kakor. Det finns en informationsruta och sidan /cookies, och typsnitt hämtas från den egna servern.

**Det här saknas:** inget känt. Om analysverktyg eller liknande läggs till krävs samtycke först.

## 10. ViDA, direktiv (EU) 2025/516, och e-fakturering

**Gäller:** kommer att gälla kunderna. ViDA inför digital rapportering och e-faktura för gränsöverskridande B2B-handel inom EU. Medlemsstaterna får redan nu kräva e-faktura för inhemsk handel. TODO(legal-verify): datumen för de olika delarna och om Sverige inför ett inhemskt krav.

**Det här gör NONOX:**
- Läser Peppol BIS Billing 3.0 och UBL-fakturor som kommer till inkorgen.
- Fakturor skickas som PDF.

**Det här saknas:**
- Att skicka och ta emot via Peppol-nätverket (en anslutningspunkt).
- Rapporteringsformatet enligt ViDA.

## Övrigt

Mervärdesskattelagen (2023:200), årsredovisningslagen och semesterlagen med flera berör funktionerna i bokföring, bokslut och lön. Öppna frågor om dem finns i [systemdokumentationen](../systemdokumentation/README.md) (avsnitt 8) och i [frågor till jurist](fragor-till-jurist.md).
