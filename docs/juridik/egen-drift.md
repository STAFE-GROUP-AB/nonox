# NONOX i egen drift

Version {version} · gäller från {datum}

NONOX finns på två sätt, och ansvaret skiljer sig åt.

| | **Driftad tjänst på {webbplats}** | **Egen drift** |
|---|---|---|
| Vem kör programmet | {foretag} | Du, på din egen server, ditt webbhotell eller din dator |
| Avtal | [Allmänna villkor]({villkor}) och [personuppgiftsbiträdesavtal]({pub}) | Licensen [AGPL-3.0](https://www.gnu.org/licenses/agpl-3.0.html), inget avtal med oss |
| Drift, uppdateringar och säkerhetskopior | Vi | Du |
| Säkerheten på servern | Vi | Du |
| Vem är personuppgiftsansvarig | Ditt företag, och vi är personuppgiftsbiträde | Ditt företag. Vi är inte biträde och tar inte emot några uppgifter |
| Pris | Enligt {webbplats}#priser | Gratis |

## När du kör NONOX själv

- **Programmet tillhandahålls i befintligt skick, utan garanti.** Det följer av licensen AGPL-3.0, punkt 15 och 16. Vi ansvarar inte för skador som uppstår när programmet körs i någon annans drift.
- **Du ansvarar för driften.** Det gäller servern, säkerhetsuppdateringar av programmet och av allt det körs på, krypteringsnyckeln (`APP_KEY`) och nyckeln till aktiebokens hashkedja (`NONOX_REGISTER_HASH_KEY`, som aldrig får bytas), säkerhetskopior och att du kan läsa tillbaka dem. Skriptet `scripts/restore-test.sh` återställer en kopia i en tillfällig databas och kontrollerar den, se [säkerhetskopiering](https://github.com/STAFE-GROUP-AB/nonox/blob/main/docs/gdpr/sakerhetskopiering.md#så-testar-du-din-egen-installation). Förlorar du nyckeln går krypterade filer och personnummer inte att läsa.
- **Du ansvarar för personuppgifterna.** Ditt företag är personuppgiftsansvarigt och behöver själv till exempel registerförteckning, eventuella konsekvensbedömningar och avtal med de leverantörer du använder (webbhotell, e-post, AI-leverantör). Mallarna i [docs/gdpr](https://github.com/STAFE-GROUP-AB/nonox/tree/main/docs/gdpr) får användas fritt.
- **Du ansvarar för bokföringen**, precis som i den driftade tjänsten och i alla bokföringsprogram. Räkenskapsinformationen ska sparas i sju år enligt bokföringslagen, även om du slutar använda NONOX. Arkivpaketet går att läsa utan NONOX.
- **Ändrar du programmet och låter andra använda det över nätet** måste du göra din ändrade källkod tillgänglig för dem på samma villkor (AGPL-3.0, punkt 13).
- **Vi får inga uppgifter från din installation.** Programmet skickar ingenting till oss. Det som skickas någon annanstans är det du själv slår på, till exempel e-post via din e-postleverantör eller underlag till den AI-leverantör vars nyckel du lägger in.

## Säkerhet

**Kör inte programmet som databasens ägare.** Bokförda verifikationer skyddas av triggers i databasen, men en databasanvändare som äger tabellerna kan stänga av dem. Ge därför programmet en egen roll som inte äger något och låt bara migreringarna köras som ägaren. Skript och steg finns i [databasroller](https://github.com/STAFE-GROUP-AB/nonox/blob/main/docs/drift/databasroller.md); `php artisan nonox:doctor` visar om din installation kör som ägaren eller som superanvändare. Utan bytet fungerar allt som förut med en enda användare, men då skyddar triggerna bara mot misstag i programmet, inte mot kod som körs med programmets databasanvändare.

Vår [säkerhetsgranskning]({sakerhet}) gäller programmets källkod och, för området Drift och säkerhetskopior, den driftade tjänsten. Hur säker din egen installation är beror på hur du driftar den. Hittar du en säkerhetsbrist i programmet, rapportera den enligt [SECURITY.md](https://github.com/STAFE-GROUP-AB/nonox/blob/main/SECURITY.md). Säkerhetsrättelser publiceras som nya versioner under [Releaser](https://github.com/STAFE-GROUP-AB/nonox/releases); uppdatera när en ny version kommer. Varje release har en förteckning över programvarukomponenterna (SBOM) som bilaga, så att du kan se vilka paket som ingår och bevaka sårbarheter i dem.

## PDF-motorn för signering (valfri)

Signering fungerar utan extra programvara: kunden skriver under på en länk och får den oförändrade PDF:en och ett signeringsbevis. För det som skriver i en befintlig PDF behövs **PDF-motorn**, ett litet hjälpprogram i Python som följer med NONOX i mappen `tools/pdf-engine`. Det gäller fält som undertecknaren fyller i direkt i PDF:en, signeringsbeviset som sidor i den färdiga filen och försegling med tidsstämpel. Saknas motorn står det "Kräver PDF-motorn" vid de funktionerna, och allt annat fungerar som vanligt.

Motorn bygger på de öppna biblioteken pyHanko (MIT) och pypdfium2 (Apache-2.0/BSD-3-Clause). Alla versioner är låsta med kontrollsummor, och licenserna finns i `tools/pdf-engine/README.md`. Så installerar du den:

1. Installera Python 3.11 eller senare på servern. Ubuntu 24.04 har 3.12: `sudo apt install python3 python3-venv`.
2. Skapa en egen Python-miljö **utanför** mappen som uppdateras vid varje driftsättning, till exempel `python3 -m venv /srv/nonox-pdf-engine`. Kör den som samma användare som PHP.
3. Installera de låsta versionerna: `/srv/nonox-pdf-engine/bin/pip install --require-hashes --only-binary=:all: -r tools/pdf-engine/requirements.txt`. Med `--require-hashes` installeras bara de filer vars kontrollsummor står i låsfilen.
4. Hämta typsnitten (Noto Sans och Noto Sans CJK, SIL Open Font License 1.1): `scripts/fetch-signing-fonts.sh /srv/nonox-pdf-engine/fonts`. Skriptet kontrollerar varje fils kontrollsumma och avbryter om något inte stämmer.
5. Lägg till i `.env`: `NONOX_PDF_ENGINE_PYTHON=/srv/nonox-pdf-engine/bin/python` och `NONOX_PDF_ENGINE_FONTS=/srv/nonox-pdf-engine/fonts`. `NONOX_PDF_ENGINE=auto` är standard och använder motorn när den finns, `none` stänger av den. Kör sedan `php artisan config:cache` om du cachar konfigurationen.
6. Kontrollera: `php artisan signing:engine-doctor` visar versionerna och kör ett självtest på en genererad PDF. Samma status syns i HQ → Integrationer.

Bra att veta:

- Motorn startas som ett eget program för varje fil, med en tidsgräns på 60 sekunder (`NONOX_PDF_ENGINE_TIMEOUT`) och en minnesgräns på 2 GB adressrymd (`NONOX_PDF_ENGINE_MEMORY_MB`, verkar på Linux). NONOX ger den filerna i en tillfällig mapp som tas bort efteråt; en mapp som blir kvar för att PHP avbröts tas bort efter en timme av `signing:prune-engine-work` (schemalagd varje timme).
- **Vad motorn kan komma åt:** NONOX tömmer miljövariablerna innan motorn startar, så den får inte `APP_KEY`, databaslösenordet eller andra nycklar som miljövariabler. Men den körs som **samma användare i operativsystemet som PHP**, och kan därför i princip läsa samma filer som PHP, till exempel `.env` och lagringsmappen. Skyddet mot det är att motorn är NONOX:s egen kod med låsta, kontrollsummerade bibliotek. Vill du isolera den mer kan du köra den som en egen användare eller i en sandlåda i operativsystemet (till exempel bubblewrap eller Landlock på Linux); det stöds inte färdigt i dag men är en möjlig framtida förbättring.
- En PDF får vara högst 20 MB och 100 sidor. Filer som är krypterade eller innehåller JavaScript, startar andra program eller har bifogade filer inuti sig godtas inte.
- Motorn kontaktar inga servrar, utom de tidsstämpelservrar du själv anger i `NONOX_SIGNING_TIMESTAMP_URLS` när försegling med tidsstämpel finns. NONOX vägrar alla andra adresser, och motorn följer inga omdirigeringar. Utgående trafik behöver alltså bara släppas fram till de adresserna (HTTP eller HTTPS).
- **Kinesiska, japanska och koreanska namn i signeringsbeviset:** kör `scripts/fetch-signing-cjk-font.sh /srv/nonox-pdf-engine/fonts` (Noto Sans CJK SC, SIL Open Font License 1.1, 36 MB, kontrollsumma kontrolleras). Det behövs bara då; utan filen visas sådana tecken som […] och beviset har en notis om det.  NONOX hämtar eller kör aldrig något själv medan programmet går.
- **Egen signeringsadress:** sätt `NONOX_SIGNING_HOST=sign.exempel.se` (bara ett värdnamn) och peka värdnamnet och ett TLS-certifikat mot samma installation. Länkarna i signeringsmejlen använder då den adressen, och den adressen visar bara signeringssidorna. Länkar som redan skickats på programmets egen adress fungerar som förut. Det finns ingen egen adress per företag.
- **Försegling av signerade dokument** (avstängd från början): skapa installationens förseglingscertifikat med `php artisan signing:seal-certificate --organisation="Ditt bolag AB"`. Filen hamnar i `storage/app/signing-seal/` med rättigheten 0600, utanför webbroten (en annan plats anges med `NONOX_SIGNING_SEAL_PKCS12`). Lösenordet läses från `NONOX_SIGNING_SEAL_PASSWORD`; finns det inte skapar kommandot ett och visar det en gång, och då lägger du in det i `.env`. Certifikatet är självsignerat, så PDF-läsare visar förseglingen som en underskrift med okänd giltighet. Ett företag kan ladda upp ett eget certifikat under Inställningar för företaget → Signering. Vill du ha en tidsstämpel anger du en eller flera servrar i `NONOX_SIGNING_TIMESTAMP_URLS` (kommaseparerade, RFC 3161; väntetid per server `NONOX_SIGNING_TIMESTAMP_TIMEOUT`, förvalt 10 sekunder); svarar ingen försöker NONOX igen i upp till ett dygn (`NONOX_SIGNING_SEAL_RETRY_HOURS`, högst 24) och förseglar sedan utan tidsstämpel. Slå på försegling under HQ → Inställningar, **Försegling av signerade dokument**. `signing:seal-check` körs varje timme och påminner om certifikat som snart går ut. Inloggning med användarnamn och lösenord eller klientcertifikat mot tidsstämpelservern finns inte än. Vem som driver en installation som förseglar åt andra kan behöva uppfylla eIDAS-förordningens krav på betrodda tjänster; fråga en jurist innan du förseglar åt andra än dig själv.
- Uppdatera motorn när låsfilen ändras i en ny version av NONOX: kör steg 3 igen. `signing:engine-doctor` varnar om de installerade versionerna inte är de låsta.

## Säkerhetskontakt och driftstatus

Din installation har en egen `/.well-known/security.txt` och en egen statussida på `/status`. En säkerhetspolicy på `/sakerhetspolicy` visas bara om du sätter `NONOX_SECURITY_POLICY_PUBLISHED=true` och har angett företagsnamn; den innehåller då inget löfte om skydd för den som testar. Ange vart sårbarhetsrapporter ska skickas under HQ → Inställningar → Säkerhetskontakt, eller med miljövariabeln `NONOX_SECURITY_EMAIL`. Saknas en kontakt publiceras ingen security.txt. Statussidan visar bara det som din installation kan veta om sig själv; säkerhetskopior visas först när dina egna skript har rapporterat dem, se [säkerhetskopiering](https://github.com/STAFE-GROUP-AB/nonox/blob/main/docs/gdpr/sakerhetskopiering.md#statussidan).

## Hjälp

Frågor om egen drift ställs i [diskussionerna på GitHub](https://github.com/STAFE-GROUP-AB/nonox/discussions). Vill du att vi tar över driften finns den [driftade tjänsten]({webbplats}#priser).

<!-- TODO(legal-verify): att ansvarsfriskrivningen i AGPL-3.0 punkt 15–16 håller enligt svensk rätt mot näringsidkare, och om något mer behöver sägas när Stafe också säljer den driftade tjänsten (jämför Cyber Resilience Act, frågan om tillverkare eller förvaltare av öppen källkod). -->
