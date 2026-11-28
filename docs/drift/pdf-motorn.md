# PDF-motorn på den driftade tjänsten

För den som driftar NONOX-tjänsten. Beslut: [ADR-046, tillägg 2026-11-18](../adr/ADR-046-crm-signing.md) (gap 5, ägarbeslut 3, 4 och 22, paket S5). Tekniken: [tools/pdf-engine/README.md](../../tools/pdf-engine/README.md). Stegen för den som kör NONOX själv: [NONOX i egen drift](../juridik/egen-drift.md#pdf-motorn-för-signering-valfri).

> **Gjort på nonox.app 2026-11-28** på ägarens uppdrag ("sätt detta själv och lös allt bara"): steg 1–5 nedan, sandlådan med bubblewrap (steg 1–4 i avsnittet om sandlådan, med AppArmor-profilen) och lösenord på Redis. `signing:engine-doctor` gav "Sandlåda: på (bubblewrap)" och "OK: PDF-motorn fungerar", med alla självtester gröna. Steg 2 gjordes med alternativ (a), pip på servern med kontrollsummor, efter ägarens uttryckliga ja; regeln att inte köra `composer`, `npm` eller `pip` på servern gäller fortfarande för allt annat. Försegling, långtidsvalidering och kontrollsidan är fortfarande avstängda tills juristfrågan om eIDAS är besvarad (ADR-046).

## Läge i dag

- Motorn är installerad på den driftade tjänsten: Python-miljön i `/home/forge/nonox-pdf-engine/venv` (Python 3.12.3, pyHanko 0.38.1, pypdfium2 5.14.0), typsnitten i `/home/forge/nonox-pdf-engine/fonts`, och den körs i bubblewrap. En kopia av `.env` före ändringen ligger bredvid filen (`.env.bak-20261128-pdf-engine`).
- Fält och stämplar (S6) kan användas. Försegling (S10–S12) slås inte på i den driftade tjänsten förrän frågan om betrodda tjänster i gap 5 är besvarad.

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
- Miljövariablerna töms innan motorn startar, så den får inte `APP_KEY`, databaslösenordet eller e-postnycklarna som miljövariabler. **Utan sandlåda körs motorn som samma användare i operativsystemet som PHP och kan i princip läsa samma filer**, till exempel `.env` och `storage`. Skyddet då är att koden är vår egen och biblioteken låsta med kontrollsummor. Med sandlådan nedan ser motorn inte de filerna alls.
- Utgående trafik: inga nätverksanrop i S5. Vid försegling med tidsstämpel (S10) bara till adresserna i `NONOX_SIGNING_TIMESTAMP_URLS`; NONOX vägrar alla andra, och motorn följer inga omdirigeringar. Brandväggen kan därför tillåta utgående trafik bara till de servrarna när S10 slås på. Med långtidsvalidering (S11, följer S10) hämtar motorn också spärruppgifter (OCSP, spärrlistor) och utfärdarcertifikat från de adresser som certifikaten i filen anger, bara publika adresser och utan omdirigeringar; brandväggen måste då släppa fram dem, eller så stannar dokumenten på B-T. `signing:timestamp` körs varje natt 02.40 och gör ingenting så länge försegling är avstängd.

### Sandlåda i operativsystemet (valfri)

Motorn kan startas i bubblewrap (`bwrap`, Linux). Den ser då bara systembibliotek (skrivskyddat), sin egen Python-miljö, skriptet (mappen `tools/pdf-engine`, skrivskyddad) och typsnitten (skrivskyddat) och det enskilda anropets arbetsmapp (skrivbar). Resten av applikationens mapp, `storage/` och `.env` finns inte i sandlådan. NONOX vägrar starta motorn (och motorn räknas som icke fungerande) om en skrivskyddad sökväg är, eller innehåller, applikationens mapp, lagringsmappen, `.env` eller en hemkatalog; flytta då Python-miljön eller typsnitten. De operationer som hämtar tidsstämplar och spärruppgifter (försegling med tidsstämpel, `signing:timestamp`, långtidsvalidering) körs med nätverk, och då kan motorn också nå tjänster på 127.0.0.1 (till exempel Redis eller databasen). Sådana tjänster måste därför kräva lösenord eller åtkomstlistor (ACL); sandlådan skyddar filerna, inte lokala nätverkstjänster. Alla andra operationer (granskning, rendering, stämpling, tillägg av sidor, förberedelse av extern signatur, försegling utan tidsstämpel, självtest) körs utan nätverk alls.

1. **Kräver ägarens godkännande:** installera paketet på servern, `sudo apt install bubblewrap`.
2. Lägg i `.env`:
   ```
   NONOX_PDF_ENGINE_SANDBOX=bwrap
   NONOX_PDF_ENGINE_BWRAP=/usr/bin/bwrap
   ```
   och läs om konfigurationen.
3. Kör `php artisan signing:engine-doctor`. Raden "Sandlåda: på (bubblewrap)" och "OK: PDF-motorn fungerar" ska stå där; HQ > Integrationer visar samma sak. Är bubblewrap påslaget men saknas eller startar inte, räknas motorn som icke fungerande. NONOX kör aldrig motorn utan sandlåda när den är påslagen.
4. **Kräv sandlådan för försegling:** `NONOX_PDF_ENGINE_REQUIRE_SANDBOX=true`. Då går det inte att slå på försegling i HQ om motorn inte körs i sandlådan, och försegling och långtidsvalidering görs inte alls om `NONOX_PDF_ENGINE_SANDBOX` inte är `bwrap`; en väntande försegling avbryts då med orsaken "PDF-motorn körs inte i en sandlåda". Är sandlådan påslagen men bubblewrap fungerar inte, behandlas det som ett motorfel. Den driftade tjänsten ska ha detta på innan försegling slås på.

**Ubuntu 24.04 och senare** begränsar vanliga användares användarnamnrymder via AppArmor (`kernel.apparmor_restrict_unprivileged_userns=1`), så att `bwrap` inte startar. På nonox.app var felet "loopback: Failed RTM_NEWADDR". Lösningen som används där (provad 2026-11-28) är en AppArmor-profil bara för `bwrap`; systeminställningen för hela servern är orörd:

```bash
sudo tee /etc/apparmor.d/bwrap >/dev/null <<'PROFILE'
abi <abi/4.0>,
include <tunables/global>

profile bwrap /usr/bin/bwrap flags=(unconfined) {
  userns,
  include if exists <local/bwrap>
}
PROFILE
sudo apparmor_parser -r /etc/apparmor.d/bwrap
bwrap --ro-bind / / --unshare-all true && echo OK
```

Ångra: `sudo apparmor_parser -R /etc/apparmor.d/bwrap` och ta bort filen.

**Python 3.12 (Ubuntu 24.04)** behöver `typing-extensions` för aiohttp; låsfilen har den sedan 2026-11-28 med villkoret `python_version < "3.13"`. Utan den vägrade `pip install --require-hashes` att installera. Paketet `python3.12-venv` måste finnas för att `python3 -m venv` ska fungera.

**Redis kräver lösenord** på nonox.app sedan 2026-11-28 (`requirepass` i `/etc/redis/redis.conf`, samma värde som `REDIS_PASSWORD` i `.env`). Redis delas med andra sajter på samma server; alla deras `.env` fick samma lösenord samtidigt, och kopior före ändringen heter `.env.bak-20261128-redis`. Byts lösenordet måste alla sajterna byta samtidigt, följt av `config:cache` där konfigurationen är cachad och omstart av köarbetarna.
