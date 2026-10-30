# NONOX Ekonomisystem

**Open Source Svenskt Bokföringssystem**

Ett modernt, transparent och korrekt bokföringssystem byggt specifikt för svenska små och medelstora aktiebolag.

## 🇸🇪 Om Projektet

NONOX Ekonomisystem är ett fristående open source-system för bokföring, fakturering, moms och bokslut. Du kör det själv, äger all data och kan alltid ta med dig allt ut igen (SIE4, CSV, PDF och API).

### Målsättning

- **Transparent**: All affärslogik är öppen och granskningsbar
- **Korrekt**: Uppfyller bokföringslagen (BFL) och god redovisningssed
- **Spårbar**: Komplett audit trail för alla åtgärder
- **Deterministisk**: Inga "magiska" automatiseringar

### Teknisk Stack (TALL)

- **T**ailwindCSS - Modern CSS framework
- **A**lpine.js - Lightweight JavaScript framework
- **L**aravel 13 - PHP framework
- **L**ivewire - Reactive Laravel components

Med **Laravel Jetstream** för användare och företag, och **PostgreSQL** som databas.

## 📋 Funktioner

Systemet byggs en del i taget. Det här finns i dag:

**Bokföring**
- ✅ Företag med organisationsnummer, momsuppgifter och bankgiro. Flera företag per användare, med roller (ägare, administratör, redovisningskonsult, bokförare, attestant, revisor).
- ✅ Kontoplan enligt BAS 2026 (K1, K2/K3), räkenskapsår och verifikationsserier.
- ✅ Verifikationer med obruten numrering, utkast, rättelser, konteringsmallar och underlag (kvitton som PDF eller bild).
- ✅ Kostnadsställen och projekt per rad, till exempel en per restaurang.
- ✅ Bokförda verifikationer kan aldrig ändras; databasen själv vägrar. En kedja av kontrollsummor avslöjar ändringar i efterhand och kontrolleras varje natt.
- ✅ Rapporter: saldobalans, resultatrapport, balansrapport, huvudbok och verifikationslista, per period och kostnadsställe. Excel (CSV) och utskrift/PDF.
- ✅ Momsdeklaration med fil till Skatteverket, bokföring av momsen och låsning av perioden.
- ✅ Årsskifte: utgående balanser blir ingående balanser för nästa år.
- ✅ SIE4: export av varje räkenskapsår och inläsning i två steg med avstämning mot filen. Så flyttar du in bokföringen från ditt gamla program.
- ✅ Behandlingshistorik över allt som görs.

**Fakturering**
- ✅ Kund-, artikel- och leverantörsregister under en egen flik, Register, med pris, moms och intäktskonto. Per kund visas vad den fakturerats i år och vad som är obetalt, per artikel vad som sålts i år.
- ✅ Nyckeltal för företagaren överst under varje flik och på översikten: fakturerat i månaden, obetalt hos kunder och förfallet, order att fakturera, resultat hittills i år, pengar på bank och kassa och moms att betala. Var och en visas bara för roller som får se underlaget.
- ✅ Fakturor med OCR-nummer, PDF och utskick via e-post. De bokförs automatiskt, enligt fakturametoden eller kontantmetoden.
- ✅ Inbetalningar, även delbetalningar, kreditfakturor och påminnelser via e-post.
- ✅ Läs in bankens fil med inbetalningar (ISO 20022 camt.054, ersätter BgMax). Fakturorna föreslås efter OCR-numret och bokförs med ett klick. Samma inbetalning bokförs aldrig två gånger.
- ✅ Offerter och order (till exempel catering) som blir fakturor utan att något skrivs två gånger.
- ✅ Leverantörsfakturor: ladda upp fakturan som PDF eller bild, kontera per kostnadsställe (till exempel per restaurang), registrera, attestera och registrera betalningen. Fakturan blir underlag till verifikationen. Den som registrerar får inte attestera själv, om inte företaget bara är en person.

**På väg** (i den här ordningen)
- 🚧 EU- och exportfakturor.
- ✅ Betalfiler till banken (ISO 20022 pain.001) för attesterade leverantörsfakturor, i dag för Handelsbanken och Nordea. Betalningarna bokförs när banken har betalat.
- 🚧 Fler banker, automatisk avstämning mot bankens svar, leverantörsfakturor från utlandet och kreditnotor.
- 🚧 Lön, med timlöner, OB och personalliggare för restauranger. Förslaget och frågorna som först måste granskas juridiskt finns i [ADR-012](docs/adr/ADR-012-payroll.md).
- 🚧 Bokslut, anläggningsregister och årsredovisning.

Hela planen och alla regler finns i [AGENTS.md](AGENTS.md).

### Juridiska krav (Sverige)

Systemet byggs för att uppfylla bokföringslagen (BFL), BFNAR 2013:2 (systemdokumentation och behandlingshistorik), Skatteverkets momsregler och kravet att spara räkenskapsinformation i sju år. Hur systemet faktiskt fungerar beskrivs i [systemdokumentationen](docs/systemdokumentation/README.md). Frågor som ännu inte är juridiskt kontrollerade är markerade med `TODO(legal-verify)` och listade där.

## 🚀 Installation

Den här guiden förutsätter inga förkunskaper. Följ stegen uppifrån och ned; varje steg säger vad du ska se när det har gått rätt.

### Vad finns i appen i dag?

Var medveten om var projektet står innan du installerar:

- ✅ **Fungerar:** inloggning med engångskod via e-post, guiden som skapar ditt företag, bokföring, rapporter, moms, årsskifte, SIE-import och -export, fakturering och leverantörsfakturor. Se listan under Funktioner ovan.
- 🚧 **Inte byggt ännu:** lön och bokslut.

### 1. Installera verktygen (en gång per dator)

Du behöver PHP 8.4 eller senare, Composer, Node.js 20 eller senare samt PostgreSQL 16 eller senare.

**Enklast på Mac och Windows: [Laravel Herd](https://herd.laravel.com)**

1. Ladda ned och installera Herd. Då får du PHP, Composer och Node.js på en gång.
2. Du behöver även PostgreSQL:
   - **Herd Pro:** öppna Herd → *Services* → *+* → välj **PostgreSQL** → starta tjänsten. Notera **porten** som står bredvid (oftast `5432`).
   - **Gratisversionen av Herd:** installera [DBngin](https://dbngin.com) (gratis), skapa en PostgreSQL-tjänst och tryck *Start*. Användarnamnet och porten står i DBngin.

**Linux eller utan Herd:** installera `php` (8.4+, med tilläggen `pdo_pgsql`, `mbstring`, `xml`, `curl`, `zip`, `bcmath`, `intl`), [Composer](https://getcomposer.org/download/), [Node.js](https://nodejs.org) och `postgresql` via din pakethanterare.

Kontrollera i Terminalen att allt finns:

```bash
php -v        # ska visa 8.4 eller högre
composer -V
node -v       # ska visa v20 eller högre
psql --version  # ska visa 16 eller högre
```

### 2. Hämta koden

Med Herd: lägg projektet i mappen `~/Herd` så får det automatiskt adressen `http://oss.test`.

```bash
cd ~/Herd                                   # utan Herd: valfri mapp
git clone https://github.com/STAFE-GROUP-AB/OSS.git oss
cd oss
```

> Mappnamnet styr adressen i Herd: mappen `oss` blir `http://oss.test`.

### 3. Skapa databasen

Först behöver du veta vilket **användarnamn** din PostgreSQL har:

- **Herd Pro:** oftast samma som ditt användarnamn på datorn. Kör `whoami` i Terminalen så ser du det.
- **DBngin, Docker och Linux:** oftast `postgres`.

Skapa sedan databasen (byt `DITT_ANVÄNDARNAMN` mot det du fick fram ovan):

```bash
psql -h 127.0.0.1 -p 5432 -U DITT_ANVÄNDARNAMN -d postgres -c "CREATE DATABASE nonox;"
```

Svaret `CREATE DATABASE` betyder att det gick bra. Byt `5432` mot porten från steg 1 om den är en annan.

Föredrar du ett grafiskt program går det lika bra att skapa databasen `nonox` i TablePlus, Postico eller liknande.

> Osäker? Hoppa vidare. Startsidan i steg 5 kontrollerar databasen och skriver ut exakt vilket kommando som saknas.

### 4. Installera appen

```bash
composer install
cp .env.example .env
```

Öppna nu filen `.env` i en textredigerare och fyll i ditt användarnamn från steg 3 på raden `DB_USERNAME`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432        # ändra om din PostgreSQL har en annan port
DB_DATABASE=nonox
DB_USERNAME=postgres  # byt mot ditt användarnamn från steg 3
DB_PASSWORD=        # fyll i om din PostgreSQL har lösenord
```

Kör sedan installationen. Ett kommando gör resten:

```bash
composer setup
```

Det skapar applikationsnyckeln, bygger alla tabeller och bygger webbsidans utseende. Det tar någon minut första gången. Allt har gått rätt om det sista som skrivs ut är `✓ built in …`.

### 5. Öppna appen

- **Med Herd:** gå till **http://oss.test** i webbläsaren. Klart, ingen server behöver startas.
- **Utan Herd:** kör `php artisan serve` och gå till **http://localhost:8000**. Låt Terminalfönstret vara öppet så länge du använder appen. Ändra också `APP_URL=http://localhost:8000` i `.env`.

Första gången möts du av en **guide i tre steg**:

1. **Ditt företag:** namn, organisationsnummer, företagsform, moms och när räkenskapsåret börjar.
2. **Du:** ditt namn och din e-postadress. Det är den adressen du loggar in med.
3. **Bekräfta e-posten** med en kod. På din egen dator fylls koden i automatiskt.

Sedan är du inne i ditt företag. Saknas något tekniskt (till exempel databasen) förklarar startsidan i stället vad som är fel och visar exakt en rad att klistra in i Terminalen.

> Guiden går bara att använda på en lokal installation (`APP_ENV=local`) som inte har några användare ännu. På en server kan ingen utomstående alltså ta över en nyinstallerad version.

### Så loggar du in

Det finns inga lösenord. Du skriver din e-postadress, får en kod med sex siffror i mejlet och skriver in den. Är adressen ny skapas ett konto, och du får sedan skapa ditt företag på sidan *Kom igång*.

**Lokalt är det ett klick.** När `APP_ENV=local` och `OTP_AUTOFILL=true` (standard i `.env.example`) är e-postfältet redan ifyllt med din adress, och koden fylls i och kontrolleras automatiskt.

Vill du logga in med en annan adress lokalt hamnar koden i loggfilen, eftersom `MAIL_MAILER=log`:

```bash
grep "Subject: Din inloggningskod" storage/logs/laravel.log | tail -1
```

> ⚠️ Sätt aldrig `APP_ENV=local` på en server som nås från internet. På en server behöver du riktig e-post (`MAIL_MAILER=smtp` med uppgifter från din e-postleverantör), annars kommer koderna aldrig fram.

### Uppdatera till senaste versionen

```bash
git pull
composer install
php artisan migrate
npm install && npm run build
```

### Felsökning

| Felmeddelande | Orsak och lösning |
|---|---|
| `Connection refused` | PostgreSQL är inte startat, eller fel port i `.env`. Starta PostgreSQL i Herd/DBngin och kontrollera `DB_PORT`. |
| `database "nonox" does not exist` | Databasen är inte skapad. Gör steg 3. |
| `role "…" does not exist` | Fel användarnamn. Ändra `DB_USERNAME` i `.env` (se steg 3). |
| `password authentication failed` | Fel lösenord. Fyll i `DB_PASSWORD` i `.env`. |
| `Please provide a valid cache path` | Mapparna under `storage/` saknas. Kör `mkdir -p storage/framework/{cache/data,sessions,views} storage/logs` och försök igen. |
| `Vite manifest not found` | CSS/JS är inte byggt. Kör `npm install && npm run build`. |
| `oss.test` öppnar inte | Projektet ligger inte i en mapp som Herd bevakar. Flytta det till `~/Herd`, eller kör `herd link oss` i projektmappen. |
| Sidan visar bara ett fel utan detaljer | Läs `storage/logs/laravel.log`, där står det verkliga felet. |
| Något är konstigt efter en uppdatering | Kör `php artisan optimize:clear`. |

## 📚 Dokumentation

- [Systemdokumentation](docs/systemdokumentation/README.md) – hur bokföringen fungerar, som bokföringslagen kräver. Uppdateras med varje ändring.
- [Beslut (ADR)](docs/adr/) – varför systemet är byggt som det är: verifikationer, SIE, kostnadsställen, rapporter och moms.
- [AGENTS.md](AGENTS.md) – specifikationen och reglerna för alla som bidrar, människor som AI.

## 🔒 Säkerhet

- Rollbaserad åtkomst per företag. Ett företag ser aldrig ett annat företags data; det testas för varje tabell
- Inloggning med engångskod via e-post, inga lösenord. Koderna sparas bara som hashvärden, gäller i 10 minuter, kan användas en gång och spärras efter 5 felaktiga försök
- Audit trail för alla kritiska operationer
- Ingen hårdkodad affärslogik i frontend

## 🛠️ Utveckling

### Köra tester

Testerna körs med [Pest](https://pestphp.com) mot en **egen** PostgreSQL-databas, `nonox_test`, så att dina riktiga data aldrig rörs. Varje test körs i en transaktion som rullas tillbaka efteråt.

Skapa testdatabasen och bygg tabellerna en gång:

```bash
psql -h 127.0.0.1 -p 5432 -U DITT_ANVÄNDARNAMN -d postgres -c "CREATE DATABASE nonox_test;"
DB_DATABASE=nonox_test php artisan migrate --force
```

Kör `DB_DATABASE=nonox_test php artisan migrate --force` igen när nya migrationer har tillkommit.

### Kvalitetskontroll

Samma tre kontroller som körs i GitHub Actions vid varje push:

```bash
composer check      # alla tre i följd
composer lint       # kodstil (Pint)
composer analyse    # statisk analys (Larastan, nivå 8)
composer test       # tester (Pest)
npm test            # fakturaradernas uträkning i webbläsaren (Node)
```

Rätta kodstilen automatiskt med `./vendor/bin/pint`.

### Bygga Assets

```bash
# Utveckling (watch mode)
npm run dev

# Produktion
npm run build
```

## 📖 Principer

### Grundprinciper

1. **Deterministisk** - Samma input ger alltid samma output
2. **Testbar** - All affärslogik ska kunna testas
3. **Dokumenterad** - Tydlig dokumentation för alla funktioner
4. **Append-only** - Dataändringar som påverkar bokföring raderas aldrig

### Vad Systemet INTE Är

Medvetet utanför scope:

- ❌ ERP-system
- ❌ CRM
- ❌ Lagerhantering
- ❌ Kassasystem
- ❌ Inkasso

## 🤝 Bidra

Vi välkomnar bidrag! Läs [AGENTS.md](AGENTS.md) först: där står hur koden ska skrivas, vilka regler som aldrig får brytas (till exempel att en bokförd verifikation aldrig ändras) och vad som ska vara klart innan något slås ihop. Kör `composer check` innan du skickar en pull request.

Lägg aldrig riktiga företagsuppgifter, personnummer, nycklar eller `.env` i ett bidrag. Testfiler ska vara påhittade.

### Utvecklingsfilosofi

> **Bygg tråkigt. Bygg korrekt. Bygg transparent.**

## 📄 Licens

Detta projekt är licensierat under **GNU Affero General Public License v3.0 (AGPL-3.0)**, se filen [LICENSE](LICENSE).

Kort sagt: du får använda, ändra och dela systemet fritt. Kör du en ändrad version som en tjänst åt andra måste du göra din källkod tillgänglig för dem på samma villkor.

## 🙏 Tack

Utvecklat med passion för öppen källkod och korrekt bokföring.

---

**STAFE Group AB**  
Bygger transparenta bokföringssystem för svenska företag.
