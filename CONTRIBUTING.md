# Bidra till NONOX

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

## Versioner och releaser

Varje version taggas `vMAJOR.MINOR.PATCH` enligt [semantisk versionering](https://semver.org) och publiceras som en [release](https://github.com/STAFE-GROUP-AB/nonox/releases) med en beskrivning av vad som ändrats. Releaser skapas av projektets team.

## Licens

Genom att bidra godkänner du att ditt bidrag licensieras under [AGPL-3.0](LICENSE), samma licens som resten av projektet.
