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
- **Du ansvarar för driften.** Det gäller servern, säkerhetsuppdateringar av programmet och av allt det körs på, krypteringsnyckeln (`APP_KEY`), säkerhetskopior och att du kan läsa tillbaka dem. Skriptet `scripts/restore-test.sh` återställer en kopia i en tillfällig databas och kontrollerar den, se [säkerhetskopiering](https://github.com/STAFE-GROUP-AB/nonox/blob/main/docs/gdpr/sakerhetskopiering.md#så-testar-du-din-egen-installation). Förlorar du nyckeln går krypterade filer och personnummer inte att läsa.
- **Du ansvarar för personuppgifterna.** Ditt företag är personuppgiftsansvarigt och behöver själv till exempel registerförteckning, eventuella konsekvensbedömningar och avtal med de leverantörer du använder (webbhotell, e-post, AI-leverantör). Mallarna i [docs/gdpr](https://github.com/STAFE-GROUP-AB/nonox/tree/main/docs/gdpr) får användas fritt.
- **Du ansvarar för bokföringen**, precis som i den driftade tjänsten och i alla bokföringsprogram. Räkenskapsinformationen ska sparas i sju år enligt bokföringslagen, även om du slutar använda NONOX. Arkivpaketet går att läsa utan NONOX.
- **Ändrar du programmet och låter andra använda det över nätet** måste du göra din ändrade källkod tillgänglig för dem på samma villkor (AGPL-3.0, punkt 13).
- **Vi får inga uppgifter från din installation.** Programmet skickar ingenting till oss. Det som skickas någon annanstans är det du själv slår på, till exempel e-post via din e-postleverantör eller underlag till den AI-leverantör vars nyckel du lägger in.

## Säkerhet

Vår [säkerhetsgranskning]({sakerhet}) gäller programmets källkod och, för området Drift och säkerhetskopior, den driftade tjänsten. Hur säker din egen installation är beror på hur du driftar den. Hittar du en säkerhetsbrist i programmet, rapportera den enligt [SECURITY.md](https://github.com/STAFE-GROUP-AB/nonox/blob/main/SECURITY.md). Säkerhetsrättelser publiceras som nya versioner under [Releaser](https://github.com/STAFE-GROUP-AB/nonox/releases); uppdatera när en ny version kommer. Varje release har en förteckning över programvarukomponenterna (SBOM) som bilaga, så att du kan se vilka paket som ingår och bevaka sårbarheter i dem.

## Säkerhetskontakt och driftstatus

Din installation har en egen säkerhetspolicy på `/sakerhetspolicy`, en egen `/.well-known/security.txt` och en egen statussida på `/status`. Ange vart sårbarhetsrapporter ska skickas under HQ → Inställningar → Säkerhetskontakt, eller med miljövariabeln `NONOX_SECURITY_EMAIL`. Saknas en kontakt publiceras ingen security.txt. Statussidan visar bara det som din installation kan veta om sig själv; säkerhetskopior visas först när dina egna skript har rapporterat dem, se [säkerhetskopiering](https://github.com/STAFE-GROUP-AB/nonox/blob/main/docs/gdpr/sakerhetskopiering.md#statussidan).

## Hjälp

Frågor om egen drift ställs i [diskussionerna på GitHub](https://github.com/STAFE-GROUP-AB/nonox/discussions). Vill du att vi tar över driften finns den [driftade tjänsten]({webbplats}#priser).

<!-- TODO(legal-verify): att ansvarsfriskrivningen i AGPL-3.0 punkt 15–16 håller enligt svensk rätt mot näringsidkare, och om något mer behöver sägas när Stafe också säljer den driftade tjänsten (jämför Cyber Resilience Act, frågan om tillverkare eller förvaltare av öppen källkod). -->
