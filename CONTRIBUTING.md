# Bidra till NONOX

Ny här, eller en AI-agent som ska skriva kod? Börja med [AGENTS.md](AGENTS.md) och [docs/agents/QUICKSTART.md](docs/agents/QUICKSTART.md): reglerna, en karta över repot, kommandona och vad som ska vara gjort innan något är klart.

Tack för att du vill göra NONOX bättre. Alla får skicka in pull requests. Projektets team granskar dem och slår ihop dem.

*In English: everyone is welcome to open issues and pull requests. Code, comments and commits are written in English; the user interface is Swedish. The rules below apply to every contribution.*

## Innan du börjar

1. **Läs [AGENTS.md](AGENTS.md).** Där står reglerna som aldrig får brytas: en bokförd verifikation ändras aldrig, belopp lagras som heltal i öre, inget företag får se ett annat företags data, och mycket mer.
2. **Större ändringar börjar som ett ärende.** Beskriv vad du vill göra och varför, så att vi kan enas om vägen innan du lägger tid på koden.
3. **Säkerhetsbrister rapporteras aldrig i ett öppet ärende.** Följ [SECURITY.md](SECURITY.md).

## Sätt upp projektet

Följ installationsguiden i [README.md](README.md#installation). Du behöver PHP 8.4, Composer, Node 20 och PostgreSQL 16 eller senare.

## Så skriver vi kod

- `declare(strict_types=1);` i varje PHP-fil. Affärslogiken ligger i Actions och domänklasser, aldrig i Livewire-komponenter eller kontroller.
- Allt användaren ser är på svenska, även adresserna (`/fakturor`, `/verifikationer`). Kod, kommentarer och commit-meddelanden är på engelska.
- Varje ny modell med företagsdata använder `BelongsToCompany` och får ett test i `tests/Tenancy`.
- Inga N+1-frågor: antalet databasfrågor på en sida får inte bero på hur många rader den visar.
- Ändrar du hur bokföringen fungerar uppdaterar du [systemdokumentationen](docs/systemdokumentation/README.md) i samma pull request. Bokföringslagen kräver den.
- En ny funktion som användaren ser skrivs in under Funktioner i [README.md](README.md#funktioner) och i `App\Support\Landing\Features`, som de publika sidorna använder. Ett test kontrollerar att de stämmer överens.
- En ny modul med egna poster lägger till en sökleverantör, så att posterna hittas med ⌘K ([ADR-026](docs/adr/ADR-026-spotlight-search.md)).
- Hitta aldrig på juridiska regler. Är du osäker, skriv `TODO(legal-verify): <frågan>` och nämn det i pull requesten.
- Lägg aldrig riktiga företagsuppgifter, personnummer, nycklar eller `.env` i ett bidrag. Testdata ska vara påhittad.

## Innan du skickar pull requesten

```bash
composer check   # kodstil (Pint), statisk analys (Larastan nivå 8) och alla tester (Pest)
npm test         # JavaScript-testerna
```

Allt ska vara grönt. CI kör samma kontroller på varje pull request.

- En pull request gör en sak. Namnge grenen `feat/…`, `fix/…`, `docs/…` eller `chore/…`.
- Commit-meddelanden följer [Conventional Commits](https://www.conventionalcommits.org): `feat(vouchers): post voucher with gapless numbering`.
- Beskriv vad och varför, bifoga skärmbilder för ändringar i gränssnittet och lista eventuella `TODO(legal-verify)`.

## Ändringslogg och färdplan

Den publika ändringsloggen på `/andringslogg` och färdplanen på `/fardplan` är till för dem som aldrig öppnar koden. Håll dem ärliga:

- Varje ändring som levereras (`feat`, `fix`, `perf` eller en säkerhetsrättelse) får en post i `docs/andringslogg/andringslogg.json` i samma pull request. Skriv rubriken och en till tre meningar på vanlig svenska, ur användarens perspektiv. Ange `datum` (ISO 8601 med tidszon, Europe/Stockholm), `typ` (Nytt, Förbättrat, Rättat eller Säkerhet), `omrade` och gärna `version` (den korta commit-hashen). Små ändringar samma dag som hör ihop blir en post.
- Typen `Utredning` är för utredningar, beslut (ADR), specifikationer och granskningar: arbete som ligger bakom programmet men inte själv är en ändring i det.
- `datum` är alltid den verkliga tidpunkten: för en leverans när den gjordes, för en utredning när dokumentet lades till i git (`git log --diff-filter=A --format=%aI -- <fil>`). Datera aldrig bakåt, hitta aldrig på arbete och sätt aldrig en tid som ligger efter commiten. Det som inte går att datera får ingen post.
- `period` (till exempel `"februari–september 2026"`) tillsammans med `"utanfor_koden": true` är bara för verkligt arbete som gjordes utanför koden och vars exakta datum inte finns nedskrivet, som förberedelserna februari–september 2026. Sådana poster har ingen `version`, och `datum` sätts till fasens början och används bara för ordning och fas: sidan visar perioden och "Arbete utanför koden" i stället för datum och klockslag, och RSS-flödet tar inte med dem. Använd aldrig `period` för att fylla en lucka eller för arbete som har en commit; då gäller det verkliga datumet.
- Posterna grupperas efter faserna i `faser` i samma fil (id, namn, beskrivning, `fran`, `till` och `kommande` för ett mål). Varje post måste ligga i en fas; en ny fas läggs till först när den har börjat.
- Skriv aldrig namn på personer eller kunder, hemligheter eller länkar till kodförrådet i posterna.
- Ren städning, tester, kodstil och stavfel i dokumentationen får ingen post.
- När något på färdplanen (`docs/fardplan/fardplan.json`) blir klart tar du bort punkten där. Den syns sedan under Klart nyligen, som läses ur ändringsloggen. Färdplanen tar bara upp större funktioner och önskemål, aldrig buggar eller små rättelser.
- `tests/Feature/ChangelogTest.php` kontrollerar båda filerna och fallerar när den senaste posten är mer än ett dygn äldre än den senaste `feat`- eller `fix`-commiten.

## Versioner och releaser

Varje version taggas `vMAJOR.MINOR.PATCH` enligt [semantisk versionering](https://semver.org) och publiceras som en [release](https://github.com/STAFE-GROUP-AB/nonox/releases) med en beskrivning av vad som ändrats. Releaser skapas av projektets team.

## Licens

Genom att bidra godkänner du att ditt bidrag licensieras under [AGPL-3.0](LICENSE), samma licens som resten av projektet.
