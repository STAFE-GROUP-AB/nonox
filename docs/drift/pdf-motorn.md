# PDF-motorn på den driftade tjänsten

För den som driftar NONOX-tjänsten. Beslut: [ADR-046, tillägg 2026-11-18](../adr/ADR-046-crm-signing.md) (gap 5, ägarbeslut 3, 4 och 22, paket S5). Tekniken: [tools/pdf-engine/README.md](../../tools/pdf-engine/README.md). Stegen för den som kör NONOX själv: [NONOX i egen drift](../juridik/egen-drift.md#pdf-motorn-för-signering-valfri).

> **Inget i den här filen är gjort på servern.** Varje steg som installerar något eller ändrar servern är märkt **Kräver ägarens godkännande** och görs först när ägaren har sagt ja till just det steget. Regeln att aldrig köra `composer` eller `npm` på en server gäller också `pip`: ägaren väljer ett av alternativen i steg 2 nedan.

## Läge i dag

- Motorn ingår i koden från paket S5 men är **inte installerad** på den driftade tjänsten. Med standardvärdet `NONOX_PDF_ENGINE=auto` och ingen Python-miljö väljer NONOX `NullPdfEngine`: signering fungerar som förut, och skärmarna för fält och försegling (S6, S10) säger "Kräver PDF-motorn".
- Ingenting i S5 använder motorn i ett kundflöde ännu. S6 (fält) och S10 (försegling) gör det, och S10–S12 slås inte på för den driftade tjänsten förrän frågan om betrodda tjänster i gap 5 är besvarad.

## Steg för att slå på motorn

| # | Steg | Vem | Läge |
|---|---|---|---|
| 1 | Python 3.11+ och `python3-venv` på servern (Ubuntu 24.04: `sudo apt install python3 python3-venv`, ger 3.12). Kontrollera först med `python3 --version`; paketet finns ofta redan. | Driftansvarig | **Kräver ägarens godkännande** (installerar ett systempaket) |
| 2 | Python-miljön med de låsta paketen, **utanför** release-mapparna (de byts vid varje driftsättning), till exempel `/home/forge/nonox-pdf-engine/venv`. Två alternativ för ägaren: **(a)** kör en gång på servern `python3 -m venv …` och `…/bin/pip install --require-hashes --only-binary=:all: -r tools/pdf-engine/requirements.txt` (pip hämtar bara de filer vars SHA-256 står i låsfilen), eller **(b)** bygg samma miljö lokalt på en maskin med samma OS, Python-version och processorarkitektur som servern och kopiera upp mappen (inga paket hämtas på servern). | Driftansvarig | **Kräver ägarens godkännande** (a: pip på servern; b: kopiering av en färdig miljö) |
| 3 | Typsnitten: `scripts/fetch-signing-fonts.sh /home/forge/nonox-pdf-engine/fonts` (laddar ner Noto Sans och Noto Sans CJK, cirka 35 MB, och kontrollerar varje fils SHA-256). Kan också köras lokalt och kopieras upp. | Driftansvarig | **Kräver ägarens godkännande** |
| 4 | `.env` på servern: `NONOX_PDF_ENGINE_PYTHON=/home/forge/nonox-pdf-engine/venv/bin/python`, `NONOX_PDF_ENGINE_FONTS=/home/forge/nonox-pdf-engine/fonts`. Låt `NONOX_PDF_ENGINE` vara `auto`. Rör inte filens rättigheter. | Driftansvarig | **Kräver ägarens godkännande** |
| 5 | `php artisan config:cache` och `php artisan signing:engine-doctor` på servern. Avslutskod 0 = motorn fungerar, 1 = vald men fungerar inte, 2 = inte installerad. | Driftansvarig | Tillåtet (`artisan` på servern), efter steg 1–4 |
| 6 | Kontrollera HQ → Integrationer → "PDF-motor för signering": "Fungerar", versionerna och typsnitten. | Ägaren | — |

Ångra: ta bort raderna i steg 4 (eller sätt `NONOX_PDF_ENGINE=none`), kör `config:cache`. Ingen data påverkas; motorn sparar ingenting själv.

## Vid driftsättning

- Driftsättningsskriptet behöver **inte** ändras för S5. Miljön ligger utanför release-mapparna och överlever varje driftsättning.
- När `tools/pdf-engine/requirements.txt` ändras i en ny version: kör om steg 2 (samma alternativ som ägaren valde) och därefter `signing:engine-doctor`. Doktorn varnar så länge de installerade versionerna inte är de låsta. **Kräver ägarens godkännande** varje gång.
- Om ägaren senare vill att driftsättningen installerar om miljön automatiskt är det ett eget beslut (det vore pip på servern vid varje driftsättning).

## Städning

- `signing:prune-engine-work` körs varje timme av schemaläggaren (som redan körs på tjänsten) och tar bort arbetsmappar äldre än en timme. Inget att installera.

## Övervakning

- Lägg gärna `php artisan signing:engine-doctor --quick` i en övervakningskörning: avslutskod 1 betyder att motorn är vald men inte svarar. Statusen i HQ cachas i tio minuter och uppdateras av doktorn.
- Motorn skriver aldrig dokumentinnehåll, sökvägar eller nycklar i loggen, och dess felutskrift (stderr) loggas inte.

## Säkerhet

- Motorn körs ett anrop i taget per fil, med en tidsgräns på 60 sekunder och en minnesgräns på 2 GB adressrymd, i en privat tillfällig mapp under `storage/app/pdf-engine-work` som tas bort efter varje anrop. Om PHP dör mitt i ett anrop blir mappen kvar; `signing:prune-engine-work` (schemalagd varje timme) tar bort mappar som är äldre än en timme. Raden finns i `docs/gdpr/lagringstider.md`.
- Miljövariablerna töms innan motorn startar, så den får inte `APP_KEY`, databaslösenordet eller e-postnycklarna som miljövariabler. **Men den körs som samma användare i operativsystemet som PHP och kan i princip läsa samma filer**, till exempel `.env` och `storage`. Skyddet i dag är att koden är vår egen och biblioteken låsta med kontrollsummor. En egen användare eller en sandlåda i operativsystemet (bubblewrap eller Landlock) är en möjlig framtida förbättring och ett eget beslut.
- Utgående trafik: inga nätverksanrop i S5. Vid försegling med tidsstämpel (S10) bara till adresserna i `NONOX_SIGNING_TIMESTAMP_URLS`; NONOX vägrar alla andra, och motorn följer inga omdirigeringar. Brandväggen kan därför tillåta utgående trafik bara till de servrarna när S10 slås på. Med långtidsvalidering (S11, följer S10) hämtar motorn också spärruppgifter (OCSP, spärrlistor) och utfärdarcertifikat från de adresser som certifikaten i filen anger, bara publika adresser och utan omdirigeringar; brandväggen måste då släppa fram dem, eller så stannar dokumenten på B-T. `signing:timestamp` körs varje natt 02.40 och gör ingenting så länge försegling är avstängd.
