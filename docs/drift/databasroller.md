# Databasroller: byte till separata användare

Körbok för att flytta en NONOX-databas från en enda databasanvändare till tre roller
([ADR-079](../adr/ADR-079-third-party-modules.md) §6, etapp 2). Skriven för den driftade
tjänsten, men samma steg gäller en egen installation.

**Varför.** Den användare som NONOX kör som äger i dag tabellerna. Den kan då stänga av,
ta bort eller ersätta triggerna som gör en bokförd verifikation oföränderlig, ändra den
och räkna om kontrollsummorna så att `ledger:verify` inte märker något
(`tests/Feature/Security/TriggerBypassGapTest.php` visar det). Efter bytet kör koden som
en roll som inte äger något och inte kan ändra tabeller, triggers eller funktioner
(`tests/Feature/Security/DatabaseRolesTest.php` visar att varje sådant försök nekas).
Migreringarna körs som en annan användare, som bara används vid driftsättning.

| Roll | Används till | Får |
|---|---|---|
| `nonox_owner` | Bara migreringar (`php artisan migrate --database=pgsql_owner`) | Äger alla tabeller, triggers och funktioner |
| `nonox_app` | Programmet: webben, kön och schemalagda jobb | Läsa, lägga till, ändra och ta bort per tabell enligt besluten i `App\Support\Database\DatabaseGrants`; aldrig ändra en tabell, aldrig TRUNCATE; tabeller som bara får växa (behandlingshistoriken med flera) bara läsa och lägga till |
| `nonox_readonly` | Rapporter och säkerhetskopior | Bara läsa |

**Underhållsfönster.** Räkna med **30 minuter**. Själva ändringarna i databasen tar under
en minut (ägarbytet låser varje tabell ett ögonblick), resten är säkerhetskopia,
kontroller och en testinloggning. Sajten är i underhållsläge under tiden och kön och
schemaläggaren står stilla. Välj en tid utanför kontorstid och inte natten då
säkerhetskopian och `ledger:verify` körs (03.15–03.30).

**Förutsättningar.** NONOX-versionen som innehåller `database/roles/` och kommandot
`nonox:doctor` är driftsatt. Du har en inloggning till PostgreSQL som superanvändare
(på en Forge-server: `sudo -u postgres psql`). Lösenord skrivs aldrig i en fil i
repot och aldrig i den här körboken.

Exemplen förutsätter att databasen heter `nonox` och att den nuvarande användaren
heter `nonox`. Byt namn där det behövs.

## 1. Kontrollera nuläget (läser bara)

Ingenting ändras i det här steget.

```bash
cd /home/forge/nonox.app/current      # eller där NONOX ligger
php artisan nonox:doctor               # förväntat nu: varningar om ägare och triggerfunktioner
```

```sql
-- psql -d nonox som superanvändare
SELECT current_user;

-- Vem äger tabellerna, och är den superanvändare?
SELECT tableowner, count(*) FROM pg_tables WHERE schemaname = 'public' GROUP BY 1;
SELECT rolname, rolsuper, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname = 'nonox';

-- Äger samma användare något i andra databaser? (avgör REASSIGN OWNED eller skriptet i steg 4)
SELECT datname, pg_get_userbyid(datdba) AS owner FROM pg_database WHERE datdba = 'nonox'::regrole;

-- Finns rollerna redan?
SELECT rolname FROM pg_roles WHERE rolname LIKE 'nonox\_%';

-- Pågår något långt? (ägarbytet väntar på pågående transaktioner)
SELECT pid, state, now() - xact_start AS age, left(query, 80) FROM pg_stat_activity WHERE datname = 'nonox';
```

Skriv ned svaren. Fortsätt bara om tabellerna har en enda ägare. Är ägaren en
superanvändare som också används till annat (till exempel `postgres` eller `forge`),
använd skriptet i steg 4b och aldrig `REASSIGN OWNED`.

## 2. Ändra driftsättningsskriptet först

I Forge, sajtens *Deploy Script*: byt raden

```bash
php artisan migrate --force
```

mot

```bash
php artisan migrate --force --database=pgsql_owner
```

Det fungerar redan innan bytet: utan `DB_MIGRATION_USERNAME` är `pgsql_owner` samma
användare som förut. Efter bytet vägrar `php artisan migrate` utan `--database=pgsql_owner`
att köra, med ett felmeddelande som säger exakt det, så en glömd rad stoppar
driftsättningen i stället för att köra migreringar som fel användare.

## 3. Säkerhetskopia och underhållsläge

```bash
php artisan down
# Stoppa kön (Forge: Background processes → NONOX queue → Stop) och pausa schemaläggaren.
/home/forge/nonox-backup.sh            # eller pg_dump -Fc nonox > före-rollbyte.dump
```

Kontrollera att kopian finns och har en rimlig storlek innan du går vidare.

## 4. Skapa rollerna och flytta ägarskapet

```bash
sudo -u postgres psql -d nonox -v ON_ERROR_STOP=1 < database/roles/roles.sql
```

Skriptet skapar rollerna utan inloggning och utan lösenord, och går att köra flera gånger.
Ge sedan inloggning och lösenord interaktivt, så att inget lösenord hamnar i en fil
eller i skalets historik:

```sql
-- sudo -u postgres psql -d nonox
ALTER ROLE nonox_owner LOGIN;
\password nonox_owner
ALTER ROLE nonox_app LOGIN;
\password nonox_app
-- Bara om säkerhetskopian ska använda läsrollen:
ALTER ROLE nonox_readonly LOGIN;
\password nonox_readonly
```

### 4a. Den gamla användaren används bara av NONOX (vanligast)

```sql
BEGIN;
REASSIGN OWNED BY nonox TO nonox_owner;
COMMIT;
```

`REASSIGN OWNED` flyttar allt som `nonox` äger i den här databasen till `nonox_owner`,
och dessutom databaser som `nonox` äger (steg 1 visade vilka). Det ändrar inga data.

### 4b. Den gamla användaren är en superanvändare eller äger annat

```bash
sudo -u postgres psql -d nonox -v ON_ERROR_STOP=1 < database/roles/transfer-ownership.sql
```

Skriptet flyttar bara tabeller, sekvenser, vyer, funktioner och typer i schemat `public`
som inte hör till ett tillägg (`pg_trgm`, `btree_gin`).

## 5. Behörigheter

```bash
sudo -u postgres psql -d nonox -v ON_ERROR_STOP=1 < database/roles/grants.sql
```

`grants.sql` genereras från besluten i `App\Support\Database\DatabaseGrants` och ger
`nonox_app` exakt det varje tabell behöver. Efter bytet görs samma sak automatiskt efter
varje `migrate`, så en ny tabell får sina behörigheter direkt. En tabell utan beslut får
ingen åtkomst alls för appen (och ett test i CI fäller den innan den kommer så långt).

Kontrollera:

```sql
SELECT tableowner, count(*) FROM pg_tables WHERE schemaname = 'public' GROUP BY 1;   -- bara nonox_owner
SELECT has_table_privilege('nonox_app', 'audit_logs', 'UPDATE');                     -- f
SELECT has_table_privilege('nonox_app', 'vouchers', 'TRUNCATE');                     -- f
```

## 6. Ändra .env

I Forge, sajtens *Environment*:

```dotenv
DB_USERNAME=nonox_app
DB_PASSWORD=<lösenordet för nonox_app>
DB_MIGRATION_USERNAME=nonox_owner
DB_MIGRATION_PASSWORD=<lösenordet för nonox_owner>
```

Spara den gamla `.env` först (`cp .env .env.bak-före-rollbyte`). Kör sedan:

```bash
php artisan config:cache
php artisan migrate --force --database=pgsql_owner    # "Nothing to migrate" och behörigheterna läggs på igen
```

Säkerhetskopian (`nonox-backup.sh`) läser databasuppgifterna ur `.env`. Efter bytet
dumpar den som `nonox_app`, som får läsa alla tabeller, så den fortsätter att fungera.
Vill du att den använder `nonox_readonly` i stället: ge den rollen inloggning (steg 4)
och låt skriptet använda den. Återställningstestet (`scripts/restore-test.sh`) återställer
med `--no-owner --no-acl` och påverkas inte.

## 7. Verifiera

```bash
php artisan nonox:doctor        # förväntat: "OK: rollen äger inga tabeller, är inte superanvändare och kan inte ändra triggerna."
php artisan ledger:verify       # förväntat: OK för varje företag
php artisan up
# Starta kön och schemaläggaren igen.
```

Logga sedan in på riktigt (e-postkod), öppna en verifikation, spara ett utkast och ta bort
det, och kontrollera att kön skickar e-post (inloggningskoden är ett bra prov). Titta i
`storage/logs/laravel.log` efter `permission denied` under den närmaste timmen och efter
nattens körningar. Ett sådant fel betyder att en tabell saknar en behörighet som koden
behöver: lägg till den i `DatabaseGrants`, driftsätt, och rollbacka under tiden om det
hindrar arbetet.

## 8. Rollback

Allt går att backa utan att röra data.

1. `php artisan down`, stoppa kön.
2. Återställ `.env` (`cp .env.bak-före-rollbyte .env`) och kör `php artisan config:cache`.
   Raden `--database=pgsql_owner` i driftsättningsskriptet kan stå kvar; utan
   `DB_MIGRATION_USERNAME` är det samma användare.
3. Lämna tillbaka ägarskapet:

   ```sql
   -- sudo -u postgres psql -d nonox
   BEGIN;
   REASSIGN OWNED BY nonox_owner TO nonox;
   COMMIT;
   ```

4. `php artisan nonox:doctor` visar åter varningarna för en enda användare, och appen kör
   som förut. `php artisan up`, starta kön.

Rollerna kan stå kvar utan inloggning (`ALTER ROLE nonox_app NOLOGIN;` och samma för
`nonox_owner`) tills nästa försök. Säkerhetskopian från steg 3 är den sista utvägen och
behövs bara om något annat än behörigheter har gått fel.

## Egen drift

En egen installation gör samma steg. Är databasen ny: skapa databasen, kör `roles.sql`,
ge `nonox_owner` och `nonox_app` inloggning och lösenord, sätt `.env` enligt steg 6 och
kör `php artisan migrate --force --database=pgsql_owner`. Tabellerna skapas då av
`nonox_owner` och får sina behörigheter direkt. Kör `php artisan nonox:doctor` efter
varje uppgradering.

Utan `DB_MIGRATION_USERNAME` fungerar allt som förut med en användare, och
`nonox:doctor` varnar för det.
