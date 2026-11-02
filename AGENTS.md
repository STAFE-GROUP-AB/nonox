# AGENTS.md — Open Source Swedish Accounting & Finance System

> Master instructions for every AI agent working in this repository.
> Read this file **in full** before starting any task. If a task conflicts with this file, this file wins — stop and ask the human.

Project name: **NONOX Ekonomisystem**
License: **AGPL-3.0** (dual licensing may be added later — do not add other license headers)
Primary language of UI: **Swedish (sv)**, secondary: English (en). **Everything a user sees is Swedish, including every URL** (see §11.4).
Language of code, comments, commits, docs: **English**. Swedish accounting terms are kept in Swedish where they are domain terms (see Glossary §16).

---

## 0. Mission

Build a complete, open-source, self-hostable bookkeeping and finance system for Swedish companies that covers the **core functionality expected from a modern Swedish cloud accounting system** (bokföring, fakturering, leverantörsreskontra, moms, bokslut, årsredovisning, later lön), with:

1. **Full legal compliance** with Bokföringslagen (BFL), BFNAR 2013:2 and Skatteverket's requirements.
2. **Multi-company by design** — one user can manage many companies. Each Jetstream *Team* **is** a *Company*.
3. **Zero lock-in** — everything importable and exportable (SIE4, CSV, PDF, API).
4. **A first-class migration path away from Fortnox** (automated importer + written guide).

---

## 1. Non-negotiable rules (read twice)

### 1.1 Clean-room / IP
- We replicate **functionality**, never **expression**.
- **NEVER** copy Fortnox's (or any competitor's) name, logo, UI layout pixel-for-pixel, icons, colours, help texts, error messages, marketing copy or source code.
- **NEVER** scrape competitor UIs for design reference. Specs are written from accounting law, BAS, SIE and Skatteverket documentation — not from screenshots.
- The word "Fortnox" may appear **only** in the migration module (`app/Migration/Fortnox`, docs/migration) as a descriptive reference to the data source.
  Exception (ADR-020): the public pages (landing page, moving guide, comparison, "alternativ till" pages) may name other programs as plain-text, verifiable comparisons, with data kept in `docs/migration`.
- Our UI must have its **own** visual identity (see §11).

### 1.2 Accounting integrity
- A **posted voucher (bokförd verifikation) is immutable**. No UPDATE, no DELETE — ever. Enforce at **three** levels: Policy, Model (`updating`/`deleting` events throw), and Database (PostgreSQL trigger).
- Corrections are made **only** via a new correcting voucher (rättelseverifikation) referencing the original.
- Every voucher **must balance**: sum(debit) = sum(credit), validated in code AND by a DB constraint/trigger at posting.
- Voucher numbers are **gapless, sequential per series per fiscal year**, assigned **at posting**, inside a DB transaction with row lock.
- Money is stored as **integer öre (`bigint`)**. **NEVER float/double/decimal-as-float.** Use the `Money` value object (§6.3).
- Locked periods cannot receive postings.
- All bookkeeping actions are written to an append-only **audit log** (behandlingshistorik).

### 1.3 Multi-tenant isolation
- Every domain row belongs to exactly one company (`company_id`).
- **No query may ever return another company's data.** Cross-tenant leakage is a P0 bug.
- Every new model with company data MUST use the `BelongsToCompany` trait and have a tenancy isolation test.

### 1.4 Working rules for agents
- Do not invent legal rules. If a regulation detail is uncertain, write `// TODO(legal-verify): <question>` and list it in the PR description.
- Do not add packages without justification in the PR (licence must be AGPL-compatible: MIT, BSD, Apache-2.0, LGPL, GPL-3, AGPL-3).
- Do not change anything in §1 without a human-approved ADR.

---

## 2. Tech stack

| Layer | Choice | Notes |
|---|---|---|
| Language | PHP 8.4+ | strict_types everywhere |
| Framework | Laravel 13.x (latest stable) | |
| Auth / Teams | Laravel Jetstream 5.x, **Livewire stack, teams enabled** | Teams = Companies (§3) |
| Auth backend | Fortify + Sanctum (from Jetstream) | **Passwordless**: every user logs in with a one-time code sent by email (OTP). No passwords, no 2FA requirement (ADR-000) |
| UI | Livewire 4, Alpine.js, Tailwind CSS 4 | Jetstream 5 ships Livewire 3/Tailwind 3 — upgrading is task T-0.3 |
| DB | **PostgreSQL 16+** only | triggers, constraints, `numeric` not used for money |
| Queue | Redis + Laravel Horizon | |
| Storage | S3-compatible (MinIO for self-host) | attachments, archives |
| PDF | Browsershot or DomPDF (decide via ADR-002) | invoices, reports |
| Search | Postgres full-text first; Scout/Meilisearch later | |
| Tests | Pest 3+, Larastan level 8, Pint | |
| API | REST, Sanctum tokens, versioned `/api/v1` | OpenAPI spec generated |
| Local dev | Laravel Sail or Herd + Docker Postgres | |
| CI | GitHub Actions: pint, larastan, pest, tenancy suite | |

Verify the latest stable versions at project start and record them in `docs/adr/ADR-001-stack.md`.

---

## 3. Teams = Companies (Jetstream adaptation)

### 3.1 Approach
Keep Jetstream's tables to avoid fighting the package. Rename **in the domain and UI**, not in the schema.

- Tables stay: `teams`, `team_user`, `team_invitations`, `users.current_team_id`.
- Create `App\Models\Company extends Laravel\Jetstream\Team` with `protected $table = 'teams';`
- Create `App\Models\CompanyMembership extends Laravel\Jetstream\Membership` (`$table = 'team_user'`).
- Register in `JetstreamServiceProvider::boot()`:
  ```php
  Jetstream::useTeamModel(Company::class);
  Jetstream::useMembershipModel(CompanyMembership::class);
  ```
- In ALL domain tables the FK is named `company_id` → `foreignId('company_id')->constrained('teams')->cascadeOnDelete()` (company deletion is soft-only in practice, see 3.5).
- On `User`, add the alias helpers `currentCompany()`, `companies()`, `switchCompany()` that delegate to Jetstream's team methods.

### 3.2 Disable personal teams
- Remove personal-team creation from `App\Actions\Fortify\CreateNewUser`.
- `personal_team` is always `false`.
- New users without a company are redirected by `EnsureUserHasCompany` middleware to `/kom-igang` (create a company or accept an invitation).
- Guard all Jetstream views/components against `currentTeam === null`.

### 3.3 Company fields (migration adding columns to `teams`)
```
org_number            string(11)   required, unique per instance, validated with Luhn (§9.1)
legal_form            enum         AB | EF | HB | KB | EK_FORENING | IDEELL_FORENING | STIFTELSE
legal_name            string
vat_number            string null  SE + orgnr(10) + 01
address_line1/2, postal_code, city, country (ISO-2, default SE)
email, phone, website
fiscal_year_start_month  tinyint 1-12 (default 1)
accounting_method     enum  FAKTURAMETODEN | KONTANTMETODEN
vat_period            enum  MONTHLY | QUARTERLY | YEARLY | NONE
vat_registered        bool
reporting_framework   enum  K1 | K2 | K3
bas_version           string  e.g. "BAS 2026"
f_skatt               bool
employer_registered   bool
base_currency         char(3) default SEK
bankgiro, plusgiro, iban, bic  null
logo_path             null
locked_until_date     date null   (period lock)
archived_at           timestamp null
```

### 3.4 Company creation flow (`App\Actions\Jetstream\CreateTeam` → rename class to `CreateCompany`)
On creation, inside one DB transaction:
1. Create company with validated fields.
2. Attach creator as `owner`.
3. Seed chart of accounts from the selected BAS template (§6.1).
4. Create the first fiscal year.
5. Create default voucher series (§6.2).
6. Create default VAT codes (§8).
7. Create default number series for invoices and customers.
8. Write an audit log entry.

### 3.5 Company switching & deletion
- Company switcher in the top navigation (search when >5 companies).
- Switching changes `current_team_id` and writes an audit entry.
- **Companies are never hard-deleted** while they have postings within the retention period (§10). "Delete" = archive (read-only) + export the archive package.

### 3.6 UI wording
Publish Jetstream's views and language files. Every occurrence of "Team" becomes **"Företag"** (sv) / **"Company"** (en). No user-facing "team" anywhere.

---

## 4. Roles & permissions

Defined in `JetstreamServiceProvider` via `Jetstream::role()`:

| Role key | sv label | Purpose |
|---|---|---|
| `owner` | Ägare | Everything incl. delete/archive company, billing |
| `admin` | Administratör | Everything except archive company and transfer ownership |
| `accountant` | Redovisningskonsult | Full bookkeeping, closing, reports, VAT, SIE |
| `bookkeeper` | Bokförare | Create/post vouchers, invoices, supplier invoices; no period lock or closing |
| `approver` | Attestant | Approve supplier invoices and payments only |
| `auditor` | Revisor | Read-only everything + export; cannot post |

Permission strings (use these exact keys):
```
company:update  company:archive  members:manage
accounts:manage  fiscal-years:manage  periods:lock
vouchers:create  vouchers:post  vouchers:correct  vouchers:view
invoices:create  invoices:send  invoices:credit  invoices:view
supplier-invoices:create  supplier-invoices:approve  payments:create  payments:approve
vat:prepare  vat:submit
reports:view  export:sie  export:archive  import:sie  import:fortnox
api-tokens:manage  settings:manage  audit-log:view
```
- All authorization goes through Policies that check **(a)** membership in the company **and (b)** permission via `$user->hasTeamPermission($company, 'perm')`.
- Segregation of duties: a user may not approve a payment they created (configurable per company, default ON).

---

## 5. Directory & code conventions

```
app/
  Actions/{Domain}/          # single-purpose actions: PostVoucher, CreateInvoice...
  Domain/{Domain}/           # value objects, enums, domain services, rules
    Accounting/  Invoicing/  Payables/  Vat/  Closing/  Payroll/
  Models/                    # Eloquent models
  Livewire/{Domain}/         # Livewire components (thin — call Actions)
  Http/Controllers/Api/V1/   # API controllers (thin — call Actions)
  Policies/
  Support/
    Money/                   # Money value object, formatting
    Sie/                     # SIE4 parser + writer
    Tenancy/                 # BelongsToCompany, CompanyScope, CurrentCompany
    Audit/
  Migration/Fortnox/         # Fortnox importer (the ONLY place "Fortnox" appears in code)
database/migrations/
docs/
  adr/                       # architecture decision records
  systemdokumentation/       # required by BFNAR 2013:2 — keep updated!
  migration/leaving-fortnox.md
tests/
  Feature/{Domain}/  Unit/  Tenancy/  Fixtures/sie/
```

Rules:
- `declare(strict_types=1);` in every PHP file.
- Business logic lives in **Actions** and **Domain** services — never in Livewire components or controllers.
- Actions are invokable, typed, transactional (`DB::transaction`) when writing.
- Enums are PHP backed enums.
- No `Model::unguard()`. Use `$fillable`.
- No raw SQL unless in migrations or explicitly justified (trigger definitions, reports).
- Dates: `CarbonImmutable` only. Timezone `Europe/Stockholm`. Accounting dates are `date` (no time).

---

## 6. Core domain model

### 6.0 Tenancy primitives
- `BelongsToCompany` trait: adds `company()` relation, global `CompanyScope` filtering on `CurrentCompany::id()`, and auto-fills `company_id` on create.
- `CurrentCompany` service resolves from: authenticated user's `current_team_id` (web), token-bound company (API), or explicit context in jobs.
- **Queued jobs** must receive `company_id` explicitly and call `CurrentCompany::set()` in `handle()`. Never rely on session in jobs.
- Running queries without scope requires `CompanyScope::bypass(fn () => ...)` and a code comment explaining why.

### 6.1 Chart of accounts
```
accounts
  id, company_id, number (int 1000-9999), name, type enum(ASSET, LIABILITY_EQUITY, REVENUE, EXPENSE)
  vat_code_id null, sru_code string null, is_active bool, requires_cost_center bool, requires_project bool
  unique(company_id, number)
```
- Type derived from the first digit by default (1=asset, 2=equity/liabilities, 3=revenue, 4-8=expenses/financial, 9=internal) — overridable.
- BAS templates live in `database/data/bas/{version}/{k1|k2|k3}.json`. Seed the commonly used accounts only; users can add the rest.
- `TODO(legal-verify)`: check BAS licence terms for redistribution of the full kontoplan in an open-source repo before shipping the complete list.
- An account with postings cannot be deleted, only deactivated.

### 6.2 Fiscal years, periods, voucher series
```
fiscal_years      id, company_id, start_date, end_date, status enum(OPEN, CLOSED), closed_at, closed_by
                  — length 12 months normally; a first or changed year may be shorter (no minimum) or at most 18 months (BFL 3 kap. 3 §); natural persons (EF) use the calendar year (3 kap. 1 §)
periods           derived (monthly), lock via companies.locked_until_date + per-period lock table
voucher_series    id, company_id, code (A, B, ...), name, is_default
voucher_number_counters  company_id, fiscal_year_id, series_id, next_number  (row-locked at posting)
```
Default series: **A** Manuell, **B** Kundfakturor, **C** Kundinbetalningar, **D** Leverantörsfakturor, **E** Leverantörsbetalningar, **L** Lön, **M** Moms, **I** Ingående balanser/import.

### 6.3 Money
`App\Support\Money\Money` — immutable, holds `int $ore` and `string $currency`.
- Arithmetic returns new instances. Division and percentages use explicit rounding (`RoundingMode::HalfUp` by default, configurable for öresavrundning).
- Formatting sv-SE: `1 234,50 kr`. Input parsing accepts `1234,50`, `1 234,50`, `1234.50`.
- Eloquent cast `MoneyCast` for `*_ore` columns.

### 6.4 Vouchers (verifikationer)
```
vouchers
  id (uuid), company_id, fiscal_year_id, series_id, number int null (null while draft)
  status enum(DRAFT, POSTED)
  voucher_date date, description string(255), source enum(MANUAL, INVOICE, PAYMENT, SUPPLIER_INVOICE, VAT, PAYROLL, IMPORT_SIE, IMPORT_FORTNOX, CLOSING, CORRECTION)
  source_type/source_id (morph, null)
  corrects_voucher_id null, corrected_by_voucher_id null
  posted_at, posted_by, created_by
  hash char(64) null, previous_hash char(64) null
  unique(company_id, fiscal_year_id, series_id, number)

voucher_rows
  id, voucher_id, company_id, account_id, debit_ore bigint default 0, credit_ore bigint default 0
  description null, cost_center_id null, project_id null, quantity null
  CHECK (debit_ore >= 0 AND credit_ore >= 0 AND NOT (debit_ore > 0 AND credit_ore > 0))

voucher_attachments
  id, voucher_id, company_id, file_path, original_name, mime, size, sha256, uploaded_by
```
**PostVoucher action** (the most important code in the system):
1. Lock counter row `SELECT ... FOR UPDATE`.
2. Validate: ≥ 2 rows, balance = 0, date inside an OPEN fiscal year, date not in a locked period, all accounts active, required dimensions present.
3. Assign number = next_number; increment counter.
4. Compute `hash = sha256(previous_hash || canonical_json(voucher + rows))` where `previous_hash` = hash of last posted voucher in the same company + fiscal year (ordered by posted_at, id).
5. Set status POSTED, posted_at, posted_by.
6. Write audit log.
7. Commit. All or nothing.

**DB trigger** on `vouchers` and `voucher_rows`: reject UPDATE/DELETE when the voucher is POSTED (allow only setting `corrected_by_voucher_id` once).

**CorrectVoucher action**: creates a new voucher that reverses (and optionally re-books) the original, links both ways, requires a reason text.

**Integrity check command**: `php artisan ledger:verify {company}` recomputes the hash chain and balances; it runs nightly and the result is shown in the UI.

### 6.5 Dimensions
`cost_centers` (kostnadsställen) and `projects`, both company-scoped, used on voucher rows, mapped to SIE `#DIM 1` / `#DIM 6`.

### 6.6 Audit log
`audit_logs`: id, company_id null, user_id null, action, subject_type, subject_id, payload jsonb, ip, user_agent, created_at. Append-only (DB trigger blocks UPDATE/DELETE). Viewable with `audit-log:view`.

---

## 7. Modules & phases

Each phase ships vertically: migrations → actions → policies → Livewire UI → API → tests → docs.

### Phase 0 — Foundation
- T-0.1 Laravel 13 + Jetstream (Livewire, teams) + Postgres + Pest + Larastan + Pint + CI
- T-0.2 Teams→Companies adaptation (§3), roles (§4), onboarding, company switcher
- T-0.3 Upgrade Jetstream views to Livewire 4 + Tailwind 4; verify all Jetstream features (email OTP login, sessions, API tokens, invitations)
- T-0.4 Tenancy primitives + tenancy test harness (§6.0, §13.2)
- T-0.5 Money, audit log, i18n (sv default), design system base (§11)

### Phase 1 — Core bookkeeping (MVP)
- Chart of accounts CRUD + BAS seeding
- Fiscal years, period locking
- Voucher entry UI (keyboard-first, §11.2), drafts, posting, corrections, attachments (drag & drop, PDF/image preview)
- Ledger reports: Verifikationslista, Huvudbok, Balansrapport, Resultatrapport, Saldobalans, Kontoanalys — period filters, comparison with previous year, PDF + CSV export
- **SIE4 import & export** (§9.2) — round-trip must be lossless
- VAT report (§8) — calculation + PDF + export file
- Year-end rollover: compute closing balances → opening balances (ingående balanser) into the new year
- `ledger:verify` and nightly integrity check
- Systemdokumentation v1 (§10)

**MVP acceptance criteria**
- A company can be created, a full year of manual bookkeeping entered, VAT reported, the year closed, and a new year opened with correct IB.
- SIE4 export → import into a fresh company → identical saldobalans and verifikationslista.
- Zero cross-tenant leaks in the tenancy suite.
- Posted vouchers cannot be altered via UI, API, Eloquent or raw SQL.

### Phase 2 — Invoicing & payables
**Customers (kundregister)**: number series, name, org/personal number, VAT number, addresses, delivery address, payment terms, invoice delivery method (PDF email / Peppol / print), language, currency, default revenue account, reverse charge flag.

**Articles (artiklar)**: number, name, unit, price ex VAT, VAT rate, revenue account per VAT type (Sweden / EU / export), type (goods/service), active.

**Customer invoices (kundfakturor)**
- Lifecycle: DRAFT → SENT/BOOKED → PARTIALLY_PAID → PAID / CREDITED / WRITTEN_OFF
- Rows: article or free text, quantity, unit price, discount %, VAT rate, account
- OCR number generation with Luhn check digit (§9.1), optional length check digit
- Öresavrundning configurable
- Booking on send (fakturametoden) or on payment (kontantmetoden)
- Credit invoice (full/partial) linked to original
- Reminders (påminnelser) with fee and dröjsmålsränta (Räntelagen: reference rate + 8 percentage points — `TODO(legal-verify)`)
- Reverse charge / EU / export texts on invoice as required by Mervärdesskattelagen
- PDF invoice template (own design), email delivery with tracking
- Offers (offerter) and orders (ordrar) convertible to invoices
- Recurring invoices (avtalsfakturering)

**Payments in**: manual registration, bank file import (camt.054 / BgMax), auto-matching by OCR, overpayments, currency differences.

**Suppliers & supplier invoices (leverantörsreskontra)**
- Supplier register (bankgiro, plusgiro, IBAN/BIC, default expense account)
- Inbox: upload/email-in of PDF invoices → interpretation (OCR/AI extraction as an optional module, human confirms)
- Approval flow (attest) with one or more approvers
- Booking on registration (fakturametoden) or payment (kontantmetoden)
- Payment proposals → payment file (ISO 20022 pain.001; Bankgirot LB as legacy option) → mark paid on confirmation (camt.054)

**Peppol**: send/receive Peppol BIS Billing 3.0 via a pluggable access-point driver interface (no hard dependency on one provider).

### Phase 3 — Closing, reporting & authorities
- Accruals (periodiseringar), prepaid/accrued templates
- Fixed asset register (anläggningsregister): acquisition, depreciation plans (linear), automatic monthly depreciation vouchers, disposal
- Bank reconciliation (avstämning) per account
- Year-end closing checklist & closing vouchers (resultatdisposition, tax)
- K2 annual report (årsredovisning) generator → PDF + iXBRL for digital submission to Bolagsverket (`TODO(legal-verify)`: current taxonomy version)
- SRU export for INK2 / INK4 / NE
- Bank connections via a pluggable open-banking driver interface (PSD2 AIS) — read transactions only
- Multi-currency (exchange rates from Riksbanken, realised/unrealised FX differences)
- Dashboard: liquidity, revenue/expense trends, open receivables/payables, VAT due dates

### Phase 4 — Payroll (lön)
- Employees, employment terms, salary types, tax tables (Skatteverket), employer contributions (arbetsgivaravgifter incl. reduced rates by age), vacation (semesterlagen), sick pay (karensavdrag)
- Payslips, salary payment file, salary vouchers in series L
- AGI (arbetsgivardeklaration på individnivå) file/API submission
- **Payroll is specified in `PAYROLL_MODULE.md`** (an optional module, built on the module framework). Every rate, threshold and table is data marked `TODO(legal-verify)` until verified; a run without a verified rule is blocked.

### Out of scope until explicitly added
Inventory/warehouse, time reporting, point of sale, CRM. Design the API so these can be separate modules later.

---

## 8. VAT (moms)

### 8.1 VAT codes
`vat_codes`: company_id, code, name, rate (basis points: 2500, 1200, 600, 0), type enum(SALE_SE, SALE_EU_GOODS, SALE_EU_SERVICES, EXPORT, PURCHASE_SE, PURCHASE_EU_GOODS, PURCHASE_EU_SERVICES, PURCHASE_NON_EU_SERVICES, REVERSE_CHARGE_SE, IMPORT, EXEMPT), report_boxes json.

Default accounts (BAS): 2611/2621/2631 utgående moms 25/12/6, 2614/2624/2634 utgående moms omvänd skattskyldighet, 2615 import, 2640/2641 ingående moms, 2645 beräknad ingående moms utländska förvärv, 2650 redovisningskonto för moms.

### 8.2 VAT return boxes (momsdeklaration)
Map to the Skatteverket form boxes:
- **A** Momspliktig försäljning: 05, 06, 07, 08
- **B** Utgående moms på försäljning: 10 (25 %), 11 (12 %), 12 (6 %)
- **C** Momspliktiga inköp vid omvänd skattskyldighet: 20, 21, 22, 23, 24
- **D** Utgående moms på inköp i C: 30, 31, 32
- **E** Försäljning m.m. undantagen från moms: 35, 36, 37, 38, 39, 40, 41, 42
- **F** Ingående moms: 48
- **G** Moms att betala eller få tillbaka: 49
- **H** Import: 50, utgående moms på import 60, 61, 62

`TODO(legal-verify)`: confirm box set and the current electronic submission format/API before building submission. v1 delivers: calculated report, drill-down to vouchers per box, PDF, export file, and a **VAT settlement voucher** (moms → 2650) in series M.

Also: periodisk sammanställning (EU sales list) export for SALE_EU_* customers.

---

## 9. Formats & algorithms

### 9.1 Luhn (mod 10)
Shared `Luhn` class used for: organisation numbers (10 digits, strip century and hyphen), personal numbers, OCR references, bankgiro/plusgiro validation. Fully unit-tested with known valid/invalid samples.

### 9.2 SIE4 (`app/Support/Sie`)
- Encoding: **CP437 (PC8)** — convert to/from UTF-8. Line endings CRLF on export, accept any on import.
- Support at minimum these records:
  `#FLAGGA #FORMAT #SIETYP #PROGRAM #GEN #FNAMN #FNR #ORGNR #ADRESS #RAR #TAXAR #KPTYP #VALUTA #KONTO #KTYP #SRU #DIM #UNDERDIM #OBJEKT #IB #UB #OIB #OUB #RES #PSALDO #PBUDGET #VER #TRANS #RTRANS #BTRANS #KSUMMA`
- Parser: tokenizer that handles quoted strings with escaped quotes, `{}` object lists, optional fields. Streaming — must handle 100 MB files without loading everything into memory.
- Import is **two-step**: parse → validation report (accounts to create, series mapping, balance check per year, duplicates) → user confirms → import inside a job, with progress.
- Imported vouchers become POSTED vouchers with `source = IMPORT_SIE`, keeping original series and numbers where possible (mapping table if conflicting).
- `#RTRANS/#BTRANS` (added/removed rows) are preserved in a history table for traceability.
- `#KSUMMA` checksum: verify if present on import; write on export.
- Golden-file tests in `tests/Fixtures/sie/` (create synthetic test files; never commit real company data).

### 9.3 Other formats
camt.053/camt.054 (bank), pain.001 (payments), BgMax (legacy), Peppol BIS Billing 3.0, iXBRL, SRU. Each lives in `app/Support/{Format}` behind an interface with fixtures and tests.

---

## 10. Compliance: retention, documentation, archive

- **Retention**: räkenskapsinformation is kept for 7 years after the end of the calendar year in which the fiscal year ended (BFL 7 kap). The system blocks deletion within that period and shows a "deletable from" date per fiscal year.
- **Systemdokumentation & behandlingshistorik** (BFNAR 2013:2): maintain `docs/systemdokumentation/` describing data model, voucher flow, numbering, correction method, integrity checks, backup and archive. **Every PR that changes accounting behaviour must update it.**
- **Archive package** (`export:archive`): a ZIP per company and fiscal year containing SIE4, all reports as PDF, all vouchers with attachments, invoices as PDF, audit log as CSV/JSON, the systemdokumentation version, and a manifest with SHA-256 for every file. It must be readable without our software.
- Backups: documented strategy for self-hosters (pg_dump + object storage), and a restore test procedure.
- Personal data (GDPR): customer/employee personal numbers encrypted at rest (`encrypted` cast); data export per data subject; erasure blocked where BFL retention applies (document the legal basis).

---

## 11. UX & design

### 11.1 Identity
- Own design language. Neutral, calm, data-dense. Suggested: Inter or IBM Plex Sans, tabular numerals (`font-variant-numeric: tabular-nums`), a single accent colour defined as a design token. **Must not resemble any competitor.**
- Light and dark mode. WCAG 2.2 AA. Fully keyboard navigable.
- Amounts right-aligned, sv-SE formatting, negatives in a distinct colour and with a minus sign.

### 11.2 Voucher entry (the heart of the UI)
- Keyboard-first grid: Tab/Enter moves through account → debit → credit → description; account autocomplete by number OR name; new row automatically created.
- A live balance indicator; the post button is disabled until balanced.
- Quick templates (konteringsmallar), e.g. "Inköp med 25 % moms" auto-splits net/VAT.
- Attachment panel with preview side-by-side.
- `Ctrl/Cmd+Enter` posts, `Esc` cancels, `?` shows shortcuts.

### 11.3 General
- Livewire components small and focused; use `wire:model.blur` for heavy forms; lazy-load reports.
- All lists: server-side pagination, sorting, filters persisted in the URL query string.
- Empty states explain what to do next.
- Every destructive or irreversible action (posting, locking a period, closing a year) has a confirmation dialog stating the consequence in plain Swedish.

### 11.4 Swedish URLs
- The system is built for Swedish companies: **every user-facing URL is Swedish** (`/logga-in`, `/foretag/nytt`, `/kom-igang`, `/fakturor`, `/verifikationer`).
- Lowercase ASCII, words separated by hyphens. Write å and ä as `a` and ö as `o` (`/foretag`, `/forsaljning`), so links stay readable when copied.
- Route **names** and code stay English (`route('teams.show')`, `InvoiceController`); only the path is Swedish.
- Vendor routes with English paths (Jetstream, Fortify) are switched off and re-registered under Swedish paths with the same route names.
- Purely technical endpoints that users never see (`/up`, `/livewire/*`, `/api/v1/*`) may stay as they are.

---

## 12. API

- `/api/v1`, Sanctum tokens **scoped to one company** + abilities mapped from §4 permissions.
- Resources: companies (read), accounts, fiscal-years, vouchers (create draft, post, correct, read), customers, articles, invoices, payments, suppliers, supplier-invoices, vat-reports, reports, sie (import/export), attachments.
- Idempotency-Key header required on all POSTs that create financial records.
- Rate limiting per token. Webhooks for: voucher.posted, invoice.sent, invoice.paid, supplier_invoice.approved.
- OpenAPI 3.1 spec generated and published in `docs/api/`.
- API uses the same Actions as the UI — never duplicate logic.

---

## 13. Testing

### 13.1 Requirements
- Every Action: feature tests for happy path + each validation rule.
- Every Policy: allow/deny per role.
- Money, Luhn, SIE parser/writer, VAT calculation: unit tests with edge cases (rounding, zero-rated, reverse charge, credit notes, negative amounts).
- Property-style tests: random balanced vouchers always post; random unbalanced ones never do.
- Coverage target: ≥ 90 % on `app/Domain`, `app/Actions`, `app/Support`.

### 13.2 Tenancy suite (`tests/Tenancy`)
Mandatory for every company-scoped model:
- Create data in Company A and Company B.
- As a user of A, assert B's records are invisible via Eloquent, Livewire components, API endpoints, exports and reports.
- Assert direct ID access to B's records returns 404 (not 403 — do not reveal existence).
- Assert jobs dispatched for A never touch B.

### 13.3 Immutability suite
Attempt to update/delete a posted voucher and its rows via Eloquent, query builder, raw SQL and API — all must fail.

---

## 14. Fortnox migration module (`app/Migration/Fortnox`)

### 14.1 Automated importer
- Connect via Fortnox API (OAuth2 authorization code flow; the user authorises their own Fortnox account). Credentials are stored encrypted, scoped to one company, and deleted after migration completes.
- `TODO(legal-verify)`: review Fortnox developer/integration terms before release; verify current API version, endpoints and rate limits against official docs — do not assume.
- Import order (each step resumable, idempotent, with progress and a log):
  1. Company info & fiscal years
  2. Chart of accounts, cost centers, projects
  3. **SIE4 per fiscal year** (primary source for the ledger — preferred over voucher-by-voucher import)
  4. Voucher attachments, linked to the imported vouchers by series + number
  5. Customers, suppliers, articles
  6. Open customer invoices & supplier invoices (reskontra) + PDFs of all historical invoices
  7. Invoice number series continuation (next numbers)
- **Reconciliation report** after import: saldobalans per year compared with the source, open receivables/payables totals compared, counts of vouchers/attachments/invoices compared. Any mismatch blocks "Migration complete".
- Fallback: manual mode where the user uploads SIE4 files + CSV exports + a ZIP of attachments.

### 14.2 Written guide (`docs/migration/leaving-fortnox.md`, also shown in-app in Swedish)
Must cover:
1. Choose a cut-over date (preferably right after a VAT period has been reported)
2. Export SIE4 for **all** fiscal years
3. Export registers (customers, suppliers, articles)
4. Download **all attachments/underlag** and invoice PDFs — explain the 7-year retention obligation
5. Export open reskontra items
6. Run the importer or the manual import
7. Reconcile (checklist)
8. Update payment details on invoices (OCR, bankgiro), inform customers if needed
9. Keep the old system read-only or keep the verified archive package until the retention period ends — **only then** terminate the subscription
10. FAQ: mid-year switch, VAT period in progress, payroll history, e-invoicing addresses

---

## 15. Agent workflow

1. **Before coding**: read this file, the relevant ADRs and `docs/systemdokumentation/`. Restate the task, list affected models/actions/tests, and flag any `TODO(legal-verify)` items.
2. **One ticket = one branch = one PR.** Branch: `feat/<phase>-<short-name>`, `fix/...`, `chore/...`.
3. **Order of work**: migration → model + casts + trait → Action(s) + validation → Policy → tests (write failing tests first) → Livewire UI → API endpoint → docs.
4. **Definition of Done**:
   - [ ] `pint --test`, `larastan` level 8, `pest` all green
   - [ ] Tenancy tests added for new company-scoped models
   - [ ] Immutability preserved (if touching vouchers)
   - [ ] Swedish + English translations added
   - [ ] systemdokumentation updated if accounting behaviour changed
   - [ ] OpenAPI updated if API changed
   - [ ] PR description: what, why, screenshots for UI, legal-verify list, migration notes
5. **Commits**: Conventional Commits (`feat(vouchers): post voucher with gapless numbering`).
6. **Never** commit real company data, personal numbers, API keys or `.env`.
7. When uncertain about **accounting or legal behaviour** → stop, write the question in the PR, and implement nothing speculative.
8. When uncertain about **technical design** → write a short ADR proposal in `docs/adr/` and continue with the simplest option that is easy to change.

---

## 16. Glossary (sv → en / code name)

| Swedish | English / code |
|---|---|
| Bokföring | Bookkeeping |
| Verifikation | Voucher (`Voucher`) |
| Verifikationsserie | Voucher series (`VoucherSeries`) |
| Kontoplan | Chart of accounts (`Account`) |
| Räkenskapsår | Fiscal year (`FiscalYear`) |
| Ingående / utgående balans (IB/UB) | Opening / closing balance |
| Huvudbok | General ledger |
| Saldobalans | Trial balance |
| Balansrapport / Resultatrapport | Balance sheet / Income statement |
| Rättelseverifikation | Correcting voucher |
| Behandlingshistorik | Audit trail (`AuditLog`) |
| Systemdokumentation | System documentation |
| Kundfaktura / Leverantörsfaktura | Customer invoice / Supplier invoice |
| Kundreskontra / Leverantörsreskontra | Accounts receivable / payable |
| Attest | Approval |
| Moms / momsdeklaration | VAT / VAT return |
| Omvänd skattskyldighet | Reverse charge |
| Periodisering | Accrual |
| Anläggningsregister | Fixed asset register |
| Avskrivning | Depreciation |
| Bokslut / Årsredovisning | Year-end closing / Annual report |
| Kostnadsställe | Cost center |
| Öresavrundning | Rounding to whole kronor |
| Arbetsgivardeklaration (AGI) | Employer declaration |
| Företag | Company (= Jetstream Team) |

---

## 17. First tasks for agents (start here)

1. **T-0.1** Scaffold: Laravel 13, Jetstream Livewire with teams, Postgres, Pest, Larastan, Pint, GitHub Actions CI. Write ADR-001 with exact versions.
2. **T-0.2** Implement §3 (Company model, personal teams disabled, onboarding, switcher, wording) + §4 roles. Tests.
3. **T-0.4** Tenancy primitives + tenancy test harness with one sample model.
4. **T-0.5** `Money`, `Luhn`, `AuditLog` (with append-only trigger). Unit tests.
5. **T-1.1** Accounts + BAS seeding + fiscal years + voucher series.
6. **T-1.2** Vouchers: draft, `PostVoucher` (§6.4), immutability triggers, `CorrectVoucher`, `ledger:verify`. Full immutability + tenancy suites.
7. **T-1.3** Voucher entry UI (§11.2).
8. **T-1.4** SIE4 writer, then parser + two-step import, with round-trip golden tests.
9. **T-1.5** Reports, VAT report, year-end rollover.
10. **T-1.6** systemdokumentation v1 + archive package export.

Report back after each task with: what was built, test results, open legal-verify items, and the proposed next task.
