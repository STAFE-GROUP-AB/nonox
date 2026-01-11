# STAFE OSS Accounting

**Open Source Svenskt Bokföringssystem**

Ett modernt, transparent och korrekt bokföringssystem byggt specifikt för svenska små och medelstora aktiebolag.

## 🇸🇪 Om Projektet

STAFE OSS Accounting är ett fristående open source-system som syftar till att ersätta traditionella bokföringsprogram som Fortnox, Visma, Björn Lundén och Bokio för fakturering och grundbokföring.

### Målsättning

- **Transparent**: All affärslogik är öppen och granskningsbar
- **Korrekt**: Uppfyller bokföringslagen (BFL) och god redovisningssed
- **Spårbar**: Komplett audit trail för alla åtgärder
- **Deterministisk**: Inga "magiska" automatiseringar

### Teknisk Stack (TALL)

- **T**ailwindCSS - Modern CSS framework
- **A**lpine.js - Lightweight JavaScript framework
- **L**aravel 11 - PHP framework
- **L**ivewire - Reactive Laravel components

Med **Laravel Jetstream** för användarväntering, teams och two-factor authentication.

## 📋 Funktioner

### Kärnfunktionalitet

- ✅ **Företagshantering** - Organisationsnummer, momsregistrering, bankuppgifter
- ✅ **Kund- & Leverantörsregister** - Omfattande partshantering
- ✅ **Artikelregister** - Produkter och tjänster med momssatser
- ✅ **Offert → Order → Faktura** - Komplett försäljningsflöde
- ✅ **Dubbel Bokföring** - BAS-kontoplan med verifikationer
- ✅ **Momsrapportering** - Enligt Skatteverkets krav
- ✅ **Import/Export** - SIE 4i format och CSV

### Juridiska Krav (Sverige)

Systemet är utformat för att uppfylla:

- Bokföringslagen (BFL)
- God redovisningssed (BFNAR)
- Momsregler enligt Skatteverket
- Arkiveringskrav (7 år)
- Spårbarhet (audit trail)

## 🚀 Installation

### Krav

- PHP 8.2 eller högre
- Composer
- Node.js & NPM
- SQLite (utveckling) eller PostgreSQL (produktion)

### Steg för Steg

```bash
# Klona repositoryt
git clone https://github.com/STAFE-GROUP-AB/OSS.git
cd OSS

# Installera PHP-beroenden
composer install

# Installera JavaScript-beroenden
npm install

# Kopiera .env-filen
cp .env.example .env

# Generera applikationsnyckel
php artisan key:generate

# Skapa databasen (SQLite)
touch database/database.sqlite

# Kör migrationer
php artisan migrate

# Bygg frontend-assets
npm run build

# Starta utvecklingsservern
php artisan serve
```

Öppna webbläsaren på `http://localhost:8000`

## 📚 Dokumentation

### Datamodell

#### Företag (Company)
- Organisationsnummer
- Momsregistrering
- Räkenskapsår
- Betalningsuppgifter (Bankgiro, Plusgiro, Swish)

#### Parter (Party)
- Kunder och leverantörer
- Organisations-/personnummer
- Betalningsvillkor

#### Artiklar (Item)
- Artikelnummer
- Priser exkl. moms
- Momssatser (25%, 12%, 6%, 0%)
- BAS-kontokoppling

#### Fakturor (Invoice)
- Löpande numrering per år
- OCR-referens
- Skapar bokföringsverifikation automatiskt
- PDF-generering

#### Verifikationer (Voucher)
- Dubbel bokföring (debit = credit)
- Append-only (ingen radering)
- Korrigering via nya verifikationer
- Immutable när godkänd

### BAS-Kontoplan

Systemet använder den standardiserade BAS-kontoplanen för svenska företag.

## 🔒 Säkerhet

- Rollbaserad åtkomst via Laravel Jetstream Teams
- Two-factor authentication stöd
- Audit trail för alla kritiska operationer
- Ingen hårdkodad affärslogik i frontend

## 🛠️ Utveckling

### Köra Tester

```bash
php artisan test
```

### Kodstil

```bash
./vendor/bin/pint
```

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
- ❌ CRM-integration
- ❌ Lönehantering
- ❌ Lagerhantering
- ❌ Årsredovisning
- ❌ BankID-integration
- ❌ Inkasso

## 🤝 Bidra

Vi välkomnar bidrag! Se vår [CONTRIBUTING.md](CONTRIBUTING.md) för riktlinjer.

### Utvecklingsfilosofi

> **Bygg tråkigt. Bygg korrekt. Bygg transparent.**
> 
> Det är så man slår Fortnox.

## 📄 Licens

Detta projekt är licensierat under MIT-licensen - se [LICENSE](LICENSE) filen för detaljer.

## 🙏 Tack

Utvecklat med passion för öppen källkod och korrekt bokföring.

---

**STAFE Group AB**  
Bygger transparenta bokföringssystem för svenska företag.
