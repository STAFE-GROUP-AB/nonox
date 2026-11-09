# Databasroller: byte till separata användare

Körbok för att flytta en NONOX-databas från en enda databasanvändare till tre roller
([ADR-079](../adr/ADR-079-third-party-modules.md) §6, etapp 2). Skriven för den driftade
tjänsten (Forge), men samma steg gäller en egen installation, se sist.

**Varför.** Den användare som NONOX kör som äger i dag tabellerna. Den kan då stänga av,
ta bort eller ersätta triggerna som gör en bokförd verifikation oföränderlig, ändra den
och räkna om kontrollsummorna så att `ledger:verify` inte märker något
(`tests/Feature/Security/TriggerBypassGapTest.php` visar det). Efter bytet kör koden som
en roll som inte äger något och inte kan ändra tabeller, triggers eller funktioner, inte
skapa tillfälliga tabeller och inte tömma en tabell
(`tests/Feature/Security/DatabaseRolesTest.php` visar att varje sådant försök nekas).
Migreringarna körs som ägaren, och ägarens inloggning finns aldrig där programmet kan
läsa den: ägaren loggar in utan lösenord, som en egen Unix-användare.

| Roll | Används till | Får |
|---|---|---|
| `nonox_owner` | Bara migreringar, som Unix-användaren `nonox-migrate` | Äger alla tabeller, triggers och funktioner |
| `nonox_app` | Programmet: webben, kön och schemalagda jobb | Läsa, lägga till, ändra och ta bort per tabell enligt besluten i `App\Support\Database\DatabaseGrants`; aldrig ändra en tabell, aldrig TRUNCATE, inga tillfälliga tabeller; tabeller som bara får växa (behandlingshistoriken med flera) bara läsa och lägga till |
| `nonox_readonly` | Säkerhetskopior och rapporter | Bara läsa |

**Underhållsfönster.** Räkna med **45 minuter**. Själva ändringarna i databasen tar under
en minut (ägarbytet låser varje tabell ett ögonblick), resten är förberedelser på
servern, säkerhetskopia, kontroller och en testinloggning. Sajten är i underhållsläge
under steg 4–8 och kön och schemaläggaren står stilla. Steg 0 (egen webbanvändare) görs före, som en egen ändring med eget fönster. Välj en tid utanför kontorstid
och inte natten då säkerhetskopian och `ledger:verify` körs (03.15–03.30).

**Förutsättningar.** NONOX-versionen som innehåller `database/roles/` och kommandot
`nonox:doctor` är driftsatt. Programmet körs som en annan Unix-användare än den som
driftsätter (steg 0). Du har sudo på servern och kan köra `sudo -u postgres psql`.
Inget lösenord skrivs i en fil i repot eller i den här körboken.

Exemplen förutsätter att databasen heter `nonox`, att den nuvarande användaren heter
`nonox`, PostgreSQL 16 med konfigurationen i `/etc/postgresql/16/main/` och sajten i
`/home/forge/nonox.app`. Byt där det behövs.

## 0. Krav: programmet körs inte som den som driftsätter

**Utan det här steget skyddar ägarrollen ingenting.** Kan koden som körs (PHP-FPM, kön,
schemaläggaren) skriva i programmets filer eller i `.env`, kan den skriva en migrering
eller en cachefil och vänta på nästa driftsättning, som kör den som ägaren. Och kör den
som samma användare som har sudo-regeln i steg 2 kan den köra migreringen själv.

Därför:

- PHP-FPM-poolen, köarbetaren och schemaläggaren för sajten körs som en egen användare,
  här `nonox-web`. Den äger bara `storage/` (den delade lagringen) och kan läsa men inte
  skriva koden, `bootstrap/cache/` och `.env`.
- `forge` (eller den som driftsätter) äger koden, `bootstrap/cache/` och `.env` och har
  sudo-regeln i steg 2. `nonox-web` har ingen sudo-regel alls.
- **`forge` kör aldrig `artisan` (eller annan PHP från sajten) i en aktiverad release.**
  En aktiverad release laddar kompilerade vyer ur `storage/`, som `nonox-web` kan skriva,
  så PHP som `forge` kör där kan köra kod som webbanvändaren har lagt dit. Det enda
  undantaget är driftsättningens steg i en ny release som ännu inte är aktiverad: där
  bygger `forge` alla cacher (`config:cache`, `route:cache`, `event:cache`) innan
  releasen aktiveras. Alla kommandon en operatör kör i den aktiverade releasen körs som
  `nonox-web`: `sudo -u nonox-web php artisan …`. Det gäller också schemalagda eller
  manuella jobb som renderar vyer, till exempel `og:images` och `mail:send`.
- Inga POSIX-ACL:er används på sajtens kataloger; rättigheterna är de vanliga
  ägare/grupp/övriga.
- I Forge betyder det en egen PHP-FPM-pool för sajten med `user = nonox-web`, köns
  bakgrundsprocess och det schemalagda jobbet med användaren `nonox-web`, och filrätter
  `go-w` på koden. Forges inbyggda användarisolering låter i stället samma användare både
  köra och driftsätta och räcker därför inte. **Ägaren beslutar vid bytet hur det
  ordnas**; körboken förutsätter att det är gjort.

Kontrollera innan du går vidare:

```bash
ps -o user= -C php-fpm8.4 | sort -u                 # förväntat: root (huvudprocessen) och nonox-web, inte forge
ps -o user=,args= -C php | grep -E 'queue:work|schedule' | sort -u   # förväntat: nonox-web
sudo -u nonox-web test -w /home/forge/nonox.app/current/artisan && echo FEL || echo ok
sudo -u nonox-web test -w /home/forge/nonox.app/.env && echo FEL || echo ok
sudo -u nonox-web test -w /home/forge/nonox.app/current/bootstrap/cache && echo FEL || echo ok
sudo -u nonox-web test -w /home/forge/nonox.app/releases && echo FEL || echo ok
sudo -l -U nonox-web                                  # förväntat: inga regler
command -v getfacl >/dev/null && getfacl -R -s -p /home/forge/nonox.app 2>/dev/null | grep -v '/storage' | head   # förväntat: tomt (inga ACL:er)
```

Skriptet i steg 2 kontrollerar samma sak vid varje driftsättning och vägrar köra om
`nonox-web` kan skriva något i releasen utanför `storage/`, eller någon katalog mellan
releasen och sajtkatalogen.

## 1. Kontrollera nuläget (läser bara)

Ingenting ändras i det här steget.

```bash
cd /home/forge/nonox.app/current
sudo -u nonox-web php artisan nonox:doctor   # förväntat nu: varningar om ägare och triggerfunktioner
grep -E '^DB_(URL|HOST|PORT|DATABASE|USERNAME)=' .env   # använder sajten DB_URL? (se steg 6)
```

```sql
-- sudo -u postgres psql -d nonox
-- Vem äger tabellerna, och är den superanvändare?
SELECT tableowner, count(*) FROM pg_tables WHERE schemaname = 'public' GROUP BY 1;
SELECT rolname, rolsuper, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname = 'nonox';

-- Äger samma användare något i andra databaser? (avgör REASSIGN OWNED eller skriptet i steg 5)
SELECT datname FROM pg_database WHERE datdba = 'nonox'::regrole;

-- Finns rollerna redan?
SELECT rolname FROM pg_roles WHERE rolname LIKE 'nonox\_%';

-- Pågår något långt? (ägarbytet väntar på pågående transaktioner)
SELECT pid, state, now() - xact_start AS age, left(query, 80) FROM pg_stat_activity WHERE datname = 'nonox';
```

Skriv ned svaren. Fortsätt bara om tabellerna har en enda ägare. Är ägaren en
superanvändare som också används till annat (till exempel `postgres` eller `forge`),
använd skriptet i steg 5b och aldrig `REASSIGN OWNED`.

**DB_URL.** Använder sajten `DB_URL` i stället för `DB_HOST`/`DB_PORT` gäller den bara
appens anslutning när rollerna är påslagna. Ägarens anslutning tar då `DB_MIGRATION_URL`
(utan lösenord) eller `DB_MIGRATION_HOST`/`DB_MIGRATION_PORT`; i den här körboken är det
socketkatalogen i steg 6.

## 2. Unix-användaren för migreringarna (förberedelse, sajten är uppe)

Ägaren loggar in med *peer*-autentisering: PostgreSQL litar på att processen körs som
Unix-användaren `nonox-migrate` och släpper bara in den som `nonox_owner`. Då finns inget
ägarlösenord, varken i `.env`, i den cachade konfigurationen eller någon annanstans, och
kod som körs av PHP-FPM (som `nonox-web`) kan inte logga in som ägaren.

```bash
# Användaren, utan inloggning och utan hemkatalog; i gruppen forge för att kunna läsa koden.
sudo adduser --system --group --no-create-home --shell /usr/sbin/nologin nonox-migrate
sudo usermod -aG forge nonox-migrate
ls -ld /home/forge /home/forge/nonox.app      # gruppen forge behöver r-x på vägen dit

# Skriptet som kör migreringarna och sedan behörigheterna, också om migreringen misslyckas.
sudo install -o root -g root -m 0755 /home/forge/nonox.app/current/database/roles/nonox-migrate.sh /usr/local/bin/nonox-migrate

# Den som driftsätter (forge) får köra just det skriptet som nonox-migrate, inget annat.
# Regeln får aldrig ges till nonox-web (steg 0).
echo 'forge ALL=(nonox-migrate) NOPASSWD: /usr/local/bin/nonox-migrate' | sudo tee /etc/sudoers.d/nonox-migrate
sudo chmod 0440 /etc/sudoers.d/nonox-migrate
sudo visudo -cf /etc/sudoers.d/nonox-migrate
```

PostgreSQL: lägg till en rad i `/etc/postgresql/16/main/pg_ident.conf`

```
# MAPNAME        SYSTEM-USERNAME   PG-USERNAME
nonox_migrate    nonox-migrate     nonox_owner
```

och **överst** bland `local`-raderna i `/etc/postgresql/16/main/pg_hba.conf`

```
# TYPE  DATABASE  USER         METHOD
local   nonox     nonox_owner  peer map=nonox_migrate
```

Inga andra rader får släppa in `nonox_owner` (kontrollera att ingen `host`-rad med `all`
gäller den med lösenord: rollen får inget lösenord, så en sådan rad släpper ändå inte in
den). Läs in konfigurationen med `sudo -u postgres psql -c 'SELECT pg_reload_conf()'`.
Rollen finns inte förrän steg 5; raden gör ingenting innan dess.

## 3. Det nya migreringssteget i driftsättningsskriptet (byts i steg 6)

I Forge, sajtens *Deploy Script*: byt raden

```bash
php artisan migrate --force
```

mot

```bash
sudo -u nonox-migrate /usr/local/bin/nonox-migrate "$FORGE_RELEASE_DIRECTORY"
```

och se till att den står **före** `php artisan config:cache` och före aktiveringen av
releasen. Skriptet läser aldrig `bootstrap/cache/` eller `storage/`: Laravels cachade
konfiguration, tjänster, paket, händelser, rutter och kompilerade vyer läggs i en ny
tillfällig katalog som bara `nonox-migrate` äger (`APP_CONFIG_CACHE` med flera och
`VIEW_COMPILED_PATH`), så ingenting som webbanvändaren kan skriva laddas när ägaren kör,
och en gammal cachad konfiguration spelar ingen roll. Skriptet löser upp sökvägen en gång
(`realpath`) och använder bara den upplösta, vägrar köra om `nonox-web` kan skriva i
releasen (utanför `storage/`) eller i någon katalog upp till sajtkatalogen, kör `migrate --force --database=pgsql_owner` och därefter `nonox:db-grants`,
också när migreringen misslyckas, och returnerar migreringens status. Heter
webbanvändaren något annat än `nonox-web`, eller ligger sajten någon annanstans,
ändra standardvärdena för `NONOX_WEB_USER` och `NONOX_SITE_DIR`
överst i den installerade kopian `/usr/local/bin/nonox-migrate` (sudo släpper inte igenom miljön).

Byt raden i steg 6, samtidigt som `.env` ändras: skriptet förutsätter att rollerna är
påslagna och att `nonox-migrate` kan läsa `.env`.

Efter bytet vägrar `php artisan migrate` utan `--database=pgsql_owner` att köra, med ett
felmeddelande som säger det, så en gammal rad stoppar driftsättningen i stället för att
köra migreringar som fel användare.

## 4. Säkerhetskopia och underhållsläge

```bash
sudo -u nonox-web php artisan down
# Stoppa kön (Forge: Background processes → NONOX queue → Stop) och pausa schemaläggaren.
/home/forge/nonox-backup.sh            # eller pg_dump -Fc nonox > /home/forge/nonox-backup/före-rollbyte.dump
```

Kontrollera att kopian finns och har en rimlig storlek innan du går vidare.

## 5. Skapa rollerna och flytta ägarskapet

```bash
cd /home/forge/nonox.app/current
sudo -u postgres psql -d nonox -v ON_ERROR_STOP=1 < database/roles/roles.sql
```

Skriptet skapar rollerna utan inloggning och utan lösenord och går att köra flera gånger.
Det tar också bort rätten att skapa tillfälliga tabeller och stora objekt från alla
(`PUBLIC`) i den här databasen. Ge inloggning:

```sql
-- sudo -u postgres psql -d nonox
ALTER ROLE nonox_owner LOGIN;          -- inget lösenord: bara peer via nonox-migrate
ALTER ROLE nonox_app LOGIN;
\password nonox_app
ALTER ROLE nonox_readonly LOGIN;       -- för säkerhetskopian (steg 6)
\password nonox_readonly
```

### 5a. Den gamla användaren används bara av NONOX (vanligast)

```sql
BEGIN;
REASSIGN OWNED BY nonox TO nonox_owner;
COMMIT;
```

`REASSIGN OWNED` flyttar allt som `nonox` äger i den här databasen till `nonox_owner`,
och dessutom databaser som `nonox` äger (steg 1 visade vilka). Det ändrar inga data.

### 5b. Den gamla användaren är en superanvändare eller äger annat

```bash
sudo -u postgres psql -d nonox -v ON_ERROR_STOP=1 < database/roles/transfer-ownership.sql
```

Skriptet flyttar bara tabeller, sekvenser, vyer, funktioner och typer i schemat `public`
som inte hör till ett tillägg (`pg_trgm`, `btree_gin`).

### 5c. Behörigheter

```bash
sudo -u postgres psql -d nonox -v ON_ERROR_STOP=1 < database/roles/grants.sql
```

`grants.sql` genereras från besluten i `App\Support\Database\DatabaseGrants`, ger
`nonox_app` exakt det varje tabell behöver och låser sökvägen för varje triggerfunktion.
Efter bytet görs samma sak automatiskt efter varje `migrate`. En tabell utan beslut får
ingen åtkomst alls för appen (och ett test i CI fäller den innan den kommer så långt).

```sql
SELECT tableowner, count(*) FROM pg_tables WHERE schemaname = 'public' GROUP BY 1;   -- bara nonox_owner
SELECT has_table_privilege('nonox_app', 'audit_logs', 'UPDATE');                     -- f
SELECT has_database_privilege('nonox_app', 'nonox', 'TEMP');                         -- f
```

## 6. Ändra .env och säkerhetskopian

Spara den gamla `.env` utanför webbanvändarens räckhåll, så att den finns för en rollback
men inte kan läsas av programmet:

```bash
sudo install -o root -g root -m 0600 /home/forge/nonox.app/.env /root/nonox-env-före-rollbyte
```

I Forge, sajtens *Environment*:

```dotenv
DB_USERNAME=nonox_app
DB_PASSWORD=<lösenordet för nonox_app>
DB_MIGRATION_USERNAME=nonox_owner
DB_MIGRATION_HOST=/var/run/postgresql
```

**Inget `DB_MIGRATION_PASSWORD`.** `DB_MIGRATION_USERNAME` står kvar i `.env` så att
programmet vet att rollerna är påslagna (vakten mot `migrate` utan ägaren och
`nonox:doctor` behöver det), men ägaren har inget lösenord som kan hamna där.
`DB_MIGRATION_HOST` är socketkatalogen, som peer-autentiseringen kräver. Använder sajten
`DB_URL`: låt den stå för appen och lägg till `DB_MIGRATION_HOST` som ovan.

`nonox-migrate` och `nonox-web` behöver läsa `.env`, men inte skriva den: ägare `forge`,
läsbar för en grupp som båda är med i och som `forge` inte ger skrivrätt till, till exempel
`sudo groupadd -f nonox-read && sudo usermod -aG nonox-read nonox-migrate && sudo usermod -aG nonox-read nonox-web && sudo chgrp nonox-read /home/forge/nonox.app/.env && sudo chmod 640 /home/forge/nonox.app/.env`
(Forge länkar filen in i varje release).

Byt raden i driftsättningsskriptet enligt steg 3 och kör sedan:

Driftsätt sedan (Forge: *Deploy Now*). Driftsättningen bygger en ny release, kör
migreringssteget som `nonox-migrate` ("Nothing to migrate", behörigheterna läggs på) och
bygger cacherna som `forge` med de nya uppgifterna innan releasen aktiveras. Den gamla
cachade konfigurationen, som har `nonox` som användare, följer inte med. Kör inte
`config:clear` eller `config:cache` för hand i den aktiverade releasen (steg 0); behövs det,
driftsätt i stället.

**Säkerhetskopian** ska dumpa som `nonox_readonly`, som får läsa allt och inget annat.
Låt `nonox-backup.sh` läsa sina uppgifter ur en egen fil, inte ur `.env`, till exempel
`/home/forge/.nonox-backup-pgpass` (`chmod 600`, formatet `127.0.0.1:5432:nonox:nonox_readonly:<lösenord>`)
via `PGPASSFILE`. Återställningstestet (`scripts/restore-test.sh`) återställer med
`--no-owner --no-acl` och påverkas inte.

## 7. Verifiera

```bash
cd /home/forge/nonox.app/current
sudo -u nonox-web php artisan nonox:doctor     # förväntat: "OK: rollen äger inga tabeller, är inte superanvändare och kan inte ändra triggerna."
sudo -u nonox-web php artisan ledger:verify    # förväntat: OK för varje företag
sudo -u nonox-web php artisan up
# Starta kön och schemaläggaren igen.
```

Logga sedan in på riktigt (e-postkod), öppna en verifikation, spara ett utkast och ta bort
det, och kontrollera att kön skickar e-post (inloggningskoden är ett bra prov). Titta i
`storage/logs/laravel.log` efter `permission denied` under den närmaste timmen och efter
nattens körningar. Ett sådant fel betyder att en tabell saknar en behörighet som koden
behöver: lägg till den i `DatabaseGrants`, driftsätt, och rollbacka under tiden om det
hindrar arbetet. Kör också nästa vanliga driftsättning och kontrollera att migreringssteget
går igenom som `nonox-migrate`.

## 8. Städa efter verifieringen

När allt fungerar (efter nattens körningar):

```sql
-- sudo -u postgres psql
ALTER ROLE nonox NOLOGIN;              -- den gamla användaren kan inte längre logga in
```

Rollbackfönstret är en vecka. Ta sedan bort kopian av den gamla `.env`
(`sudo shred -u /root/nonox-env-före-rollbyte`); behövs en rollback efter det, ge `nonox`
ett nytt lösenord i stället.

## 9. Rollback

Allt går att backa utan att röra data.

1. `sudo -u nonox-web php artisan down`, stoppa kön.
2. Lämna tillbaka ägarskapet och rätten att skapa i schemat:

   ```sql
   -- sudo -u postgres psql -d nonox
   BEGIN;
   REASSIGN OWNED BY nonox_owner TO nonox;
   GRANT CREATE, USAGE ON SCHEMA public TO nonox;
   ALTER ROLE nonox LOGIN;
   COMMIT;
   ```

   Tillfälliga tabeller och stora objekt förblir avstängda; NONOX använder inget av dem.

3. Återställ `.env` (`sudo cp /root/nonox-env-före-rollbyte /home/forge/nonox.app/.env && sudo chown forge:nonox-read /home/forge/nonox.app/.env && sudo chmod 640 /home/forge/nonox.app/.env`).
4. Byt tillbaka raden i driftsättningsskriptet till `php artisan migrate --force` och
   driftsätt (*Deploy Now*), så att cacherna byggs om med de gamla uppgifterna.
5. `sudo -u nonox-web php artisan nonox:doctor` visar åter varningarna för en enda
   användare, och appen kör som förut. `sudo -u nonox-web php artisan up`, starta kön.

Rollerna kan stå kvar utan inloggning (`ALTER ROLE nonox_app NOLOGIN;` och samma för
`nonox_owner`) tills nästa försök. Säkerhetskopian från steg 4 är den sista utvägen och
behövs bara om något annat än behörigheter har gått fel.

## Demodatabasen

En separat demodatabas (till exempel för "prova utan att registrera dig") har en egen
databasanvändare som äger den, och den användaren har ingen `CONNECT` på huvuddatabasen
(`REVOKE CONNECT ON DATABASE nonox FROM PUBLIC;` och `GRANT CONNECT ON DATABASE nonox TO nonox_owner, nonox_app, nonox_readonly;`).
Vakten mot `migrate` släpper igenom demoanslutningen bara när den del av NONOX som äger
demon har godkänt den (`DatabaseRoles::approveConnectionsUsing`), och behörigheterna och
triggerfunktionernas sökväg läggs bara på huvuddatabasen.

## Egen drift

En egen installation gör samma steg. Kan du använda peer-autentisering (PostgreSQL på
samma server) är det bäst, som ovan. Annars:

- Ge `nonox_owner` ett lösenord och lägg det i en fil som PHP-FPM-användaren **inte** kan
  läsa, till exempel `/etc/nonox/migrate.env` (ägare din driftsättningsanvändare eller
  root, `chmod 600`), med raden `DB_MIGRATION_PASSWORD=…`. Det får aldrig stå i `.env`.
- Samma åtskillnad som i steg 0 gäller: webbanvändaren kan inte skriva koden,
  `bootstrap/cache/` eller `.env`, och driftanvändaren kör aldrig `artisan` i en
  installation som webbanvändaren kan ha ändrat. Använd `nonox-migrate.sh` med ägarens
  lösenord i miljön (eller dess tillfälliga cachekatalog som förebild), och bygg
  cacherna som driftanvändaren i den nya koden innan den tas i bruk, i ett skal utan
  lösenordet:

  ```bash
  sudo -u <driftanvändare> sh -c 'set -a; . /etc/nonox/migrate.env; set +a; nonox-migrate /sökväg/till/nya/releasen'
  sudo -u <driftanvändare> sh -c 'cd /sökväg/till/nya/releasen && php artisan config:cache && php artisan route:cache && php artisan event:cache'
  # ta den nya releasen i bruk först därefter
  ```

- `sudo -u <webbanvändare> php artisan nonox:doctor` varnar om ägarens lösenord finns i
  programmets konfiguration.

Är databasen ny: skapa databasen, kör `roles.sql`, ge `nonox_owner` och `nonox_app`
inloggning, sätt `.env` enligt steg 6 och migrera som ovan. Tabellerna skapas då av
`nonox_owner` och får sina behörigheter direkt. Kör `nonox:doctor` som webbanvändaren
efter varje uppgradering.

Utan `DB_MIGRATION_USERNAME` fungerar allt som förut med en användare, och
`nonox:doctor` varnar för det.

## Vad rollerna inte skyddar

Rollerna skyddar historiken i databasen mot kod som körs med appens roll, **men bara om
programmet körs som en annan Unix-användare än den som driftsätter och inte kan skriva i
programmets filer eller `.env` (steg 0)**. Utan den åtskillnaden är ägarskyddet verkningslöst:
kod som kan skriva en migrering eller en fil som laddas vid driftsättningen får den körd
som ägaren, och kod som kör som användaren med sudo-regeln kan köra migreringen själv.
Rollerna skyddar inte heller konfidentialiteten: appens roll läser alla företags data
(ADR-079 §6).
