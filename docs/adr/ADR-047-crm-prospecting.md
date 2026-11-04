# ADR-047: CRM stage 4 — prospecting from open data

- Status: **Accepted, implemented** (2026-11-04)
- Date: 2026-11-04
- Builds on: ADR-043 (CRM, stage 4 in its list), ADR-035 (company lookup
  from Bolagsverket), ADR-017 (module framework), ADR-038 (instance-level
  tables such as the waiting list)

## Context

The owner's brief (2026-11-04): "A ready company database with data from
Bolagsverket, SCB and credit reporting companies — the system must not ship
as an empty shell. Filters on industry (SNI), town, number of employees,
turnover, company form and registration date; search for companies similar
to your dream customers; call lists directly in the system. Look-alike
search without AI: same SNI code, size class and region as your best
customers. Monitoring of existing customers: new CEO, changed turnover,
move, bankruptcy and payment remarks."

The owner's decision (ADR-043, 2026-11-03): **open data first** (Bolagsverket
and SCB); credit information later, through a replaceable driver, once a
contract with a credit reporting company exists. ADR-043 says stage 4 "is
researched before it is designed". This ADR records the research first and
the design after it.

## Research (2026-11-04)

Everything below was read from the publishers' own material on 2026-11-04,
unless marked otherwise. Bolagsverket's web pages answer automated requests
with a human-verification page (as in ADR-035), so their facts come from the
files themselves, the search engines' copies of the pages, and SCB's pages,
which describe the same high-value datasets.

### Legal basis for free data

- Commission Implementing Regulation (EU) 2023/138 on high-value datasets
  (<https://eur-lex.europa.eu/eli/reg_impl/2023/138/oj>): "companies and
  company ownership" is one of six categories; the data must be free,
  machine-readable, available through an API **and as bulk download**, and
  licensed under CC BY 4.0 or something less restrictive (CC0 or equivalent)
  (Art. 4). Sweden implements it in lagen (2022:818) om den offentliga
  sektorns tillgängliggörande av data.
- Bolagsverket and SCB launched the datasets together on 2025-02-03 (press
  release "Avgiftsfri data när Bolagsverket och SCB lanserar värdefulla
  datamängder",
  <https://bolagsverket.se/apierochoppnadata/nyheterochreleaser/2025/avgiftsfridatanarbolagsverketochscblanserarvardefulladatamangder.5516.html>):
  free of charge "via ett API-gränssnitt eller via avsedda filer".
- A government decision of 2025 made SCB's whole company register free
  from 2025-06-26 (<https://www.regeringen.se/pressmeddelanden/2025/05/scbs-dataregister-blir-avgiftsfritt-och-oppet/>,
  and SCB's page below: "SCB tar inte längre ut avgifter för uppgifter ur
  företagsregistret").

### Source 1: the bulk files (no key, no agreement)

Bolagsverket hosts two weekly files on
`https://vardefulla-datamangder.bolagsverket.se/` (an S3-compatible bucket;
the bucket listing shows both). Checked with HEAD requests and by reading the
first 200 kB of each zip (the header and a few rows, never stored):

| File | Size | Last modified | Content |
|---|---|---|---|
| `scb/scb_bulkfil.zip` | 71 MB | 2026-11-02 | One `.txt`, tab-separated, ISO-8859-1, header row. SCB's part. |
| `bolagsverket/bolagsverket_bulkfil.zip` | 252 MB (≈1 GB unpacked) | 2026-11-02 | One `.txt`, `;`-separated with quoted fields (descriptions span lines), UTF-8. Bolagsverket's part. |

- **SCB file columns** (read from the header): `ForAndrTyp COAdress
  Foretagsnamn FtgStat Gatuadress JEStat JurForm Namn Ng1 … Ng5 PeOrgNr
  PostNr PostOrt RegDatKtid Reklamsparrtyp`, each with an `m…` twin (a
  change marker). `PeOrgNr` is twelve digits: `16` + the organisation number
  for a legal person, the personal number for a natural person (SCB's
  variable description below). `Ng1`–`Ng5` are SNI 2025 codes, `Ng1` the
  main one. `JurForm` is SCB's two-digit legal-form code, `FtgStat` 0/1/9
  (never/is/no longer active by SCB's criteria: VAT, employer or F-tax).
  In the sample `Reklamsparrtyp` took the values 1 and 2.
  About 1.8 million rows (a third-party archive, not verified by us).
- **Bolagsverket file columns**: `organisationsidentitet; namnskyddslopnummer;
  registreringsland; organisationsnamn; organisationsform;
  avregistreringsdatum; avregistreringsorsak;
  pagandeAvvecklingsEllerOmstruktureringsforfarande; registreringsdatum;
  verksamhetsbeskrivning; postadress`. Parts are packed with `$` and lists
  with `|`, e.g. an identity `<10 digits>$ORGNR-IDORG`, a proceeding
  `|KK-AVOMFO$<date>` (KK konkurs, LI likvidation; the same codes as the API
  in ADR-035), forms such as `AB-ORGFO`, `E-ORGFO`, `I-ORGFO`.
- **Not in the bulk files**: number of employees, turnover, municipality and
  county codes, phone, e-mail, the phone and e-mail opt-outs, board, CEO,
  owners, credit rating, payment remarks.
- **Licence**: not stated on a page we could read. Under the regulation the
  data is CC BY 4.0 or more permissive; NONOX names both sources wherever
  register data is shown. `TODO(legal-verify)`: the exact licence text and
  attribution wording on Bolagsverket's "Nedladdningsbara filer" page
  (<https://bolagsverket.se/apierochoppnadata/hamtaforetagsinformation/nedladdningsbarafiler.2517.html>).

### Source 2: SCB's company register API (free, needs an API key)

From SCB's pages "Avgiftsfria uppgifter i företagsregistret"
(<https://www.scb.se/vara-tjanster/bestall-data-och-statistik/foretagsregistret/avgiftsfria-uppgifter-i-foretagsregistret/>),
"Värdefulla datamängder – grundläggande företagsinformation"
(<https://www.scb.se/vara-tjanster/bestall-data-och-statistik/foretagsregistret/vardefulla-datamangder--grundlaggande-foretagsinformation/>),
the variable description
(<https://www.scb.se/contentassets/8a8eb5c3d45f461ea93482f8e8d4de4f/variabelbeskrivning-afr_261009.pdf>)
and the published OpenAPI document (`https://apiafr.scb.se/swagger/v1/swagger.json`,
"SCB:s allmänna företagsregister API" v1):

- **Cost and access**: free. A user registers in Registreringsportalen
  (<https://registreraafr.scb.se/>) with BankID, accepts the terms and gets
  a personal API key by e-mail, usually the same day. The key is personal
  and must not be shared. Sent as `X-API-Key`. Every endpoint answered
  401 "Invalid or missing API key" without one (checked).
- **Limits**: at most 5 000 rows per call (`limit`, cursor pagination with
  `cursorId`/`nextCursorId`/`hasMore`), at most 5 calls per second per key
  (temporary block above that, HTTP 429 with `Retry-After`). Not available
  for 30 minutes from 04:00 each night. Updated nightly except Saturday
  night; no history and no change feed (the change service is a separate
  paid "aviseringstjänst").
- **Content** (`GET /v1/juridiskaenheter/full`): `peOrgNr, orgNr, namn,
  postAdress{gatuAdress, coAdress, postNr, postOrt}, kommunSate, lanSate,
  aeAnt, anstKl, ftgStat, jurform, reklamSparrTyp, telefonSparrTyp,
  epostSparrTyp, startDat, slutDat, regDat, tel, epost, omsAr, omsKl, agKat,
  foretagsnamn, arbGivStat, momsStat, fSkattStat, bolStat, privPubl, sektor,
  naringsgrenar[{rangordning, naringsgren, andelProcent, avdelningsKod}]`,
  plus filtered lists per SNI, municipality, county, employee class,
  turnover class and legal form, and code tables with Swedish names.
- **Size classes** (variable description): employees `anstKl` 0 unknown,
  1 = 0, 2 = 1–4, 3 = 5–9, 4 = 10–19, 5 = 20–49, 6 = 50–99, 7 = 100–199,
  8 = 200–499, 9 = 500–999, 10 = 1 000–1 499 … 16 = 10 000-; turnover
  `omsKl` 0 = under 1 tkr, 1 = 1–499 tkr, 2 = 500–999 tkr, 3 = 1–4.9 Mkr,
  4 = 5–9.9 Mkr, 5 = 10–19.9 Mkr, 6 = 20–49.9 Mkr, 7 = 50–99.9 Mkr,
  8 = 100–499.9 Mkr, 9 = 0.5–1 Gkr, 10 = 1–5 Gkr, 11 = 5–10 Gkr, 12 = over
  10 Gkr. Both are given only as classes because the exact figures are
  confidential.
- **Status at Bolagsverket** `bolStat`: 0 normal, 20 konkurs inledd, 32
  likvidation beslutad, 80 företagsrekonstruktion inledd, and about fifty
  more codes.
- **Opt-outs**: `reklamSparrTyp` (3 accepts, 4 has opted out of postal
  advertising), `telefonSparrTyp` (1 none, 2 telemarketing opt-out,
  3 NIX-Telefon), `epostSparrTyp` (1 none, 2 opted out). SCB: "Det tillhör
  därmed god marknadsföringssed att inte kontakta dessa på de sätt som de
  avsagt sig."
- **Terms of reuse** for data fetched with a key: not on a page we could
  read without registering. `TODO(legal-verify)`: whether the terms allow
  an instance operator to show the data to its customers, and the
  attribution they ask for.

### Source 3: Bolagsverket's API (ADR-035)

The free "API för värdefulla datamängder" answers one organisation number per
call (no search, no list) and needs a client id and secret after a customer
registration. It is the lookup of ADR-035 and is not a way to load a
register. Bolagsverket's paid "Företagsinformation" API has board,
signatories and more, by agreement and per call.

### SNI 2025 names

SCB publishes the SNI 2025 classification as a spreadsheet
(<https://www.scb.se/globalassets/sni-2025.xlsx>, from
<https://www.scb.se/dokumentation/klassifikationer-och-standarder/standard-for-svensk-naringsgrensindelning-sni/sni-2025/>):
22 sections, 87 divisions (two digits) and 835 detailed codes (five digits).
The codes and Swedish names are copied into `database/data/sni/sni-2025.json`
with the source named in the file. It is a public statistical classification,
not company or personal data. `TODO(legal-verify)`: SCB's terms for reusing
the classification text (SCB's material is generally free to reuse with
attribution).

### What is personal data

- **Sole traders** (enskild näringsidkare, SCB `JurForm` 10) are natural
  persons: the identity is the personal number and the name usually the
  owner's. About a third of SCB's file (a third-party count: ~634 000 rows
  with a personal-number identity). Using them for prospecting is direct
  marketing to private persons (GDPR art. 6.1 f with art. 21(2–3), art. 14
  information, marketing law 19 § for unsolicited contact and NIX).
- **Oskiftade dödsbon** (91) are identified by a deceased person's personal
  number and named after them; **enkla bolag** (21) and **partrederier** (22)
  are not legal persons and are often named after the partners.
- **Handelsbolag and kommanditbolag** are legal persons, but their names
  often contain the partners' names; a company e-mail or phone can be a
  person's own (`fornamn@…`, a mobile number).
- **Board members, CEO, signatories** are personal data and are not in any
  free source.

## Decision

### What is built now, without contracts or money

1. **An instance-level company register** (`company_registry`), loaded from
   **the SCB bulk file** (no key) and enriched from **the Bolagsverket bulk
   file** (no key). An instance operator that registers for **the SCB API
   key** (free, BankID) turns on a third source that adds employee and
   turnover size classes, municipality and county, phone, e-mail, the phone
   and e-mail opt-outs and the status at Bolagsverket. This driver is built
   and tested but **dormant** until `SCB_AFR_API_KEY` is set.
2. **Prospect search**, **look-alike search**, **call lists** and
   **monitoring** in the CRM, on the register.
3. **A credit-data driver interface** with a null driver only. Payment
   remarks, credit rating, the exact turnover and CEO changes need a paid
   source (a credit reporting company, or Bolagsverket's paid API); the UI
   says which signals need it.

### The register is reference data, not tenant data

`company_registry` has no `company_id` and no `BelongsToCompany`, like the
waiting list (ADR-038) and the instance settings: it is public data held by
the instance operator and readable by **every CRM user** (`crm:view`) of
every company on the instance. A company's own prospects, call lists and
notices are tenant data and company-scoped.

- **Only legal persons are loaded.** Rows whose identity is not `16` + an
  organisation number, and the legal forms 10 (natural persons), 21 (enkla
  bolag), 22 (partrederier) and 91 (oskiftade dödsbon), are skipped and only
  counted. Bolagsverket rows with a personal number are ignored. So the
  register holds no personal numbers and no sole traders, and prospecting
  is to legal persons only. A sole trader can still be a prospect, added by
  hand by a user who has a reason (ADR-043's rules apply).
- **No people are imported.** No board, CEO or signatory names; the free
  sources do not have them, and data minimisation would argue against them
  anyway. Contact people are added by the seller, with ADR-043's legal basis
  and art. 14 information.
- **Opt-outs are honoured by default.** Search and look-alike hide companies
  with a postal advertising opt-out (both files and the API carry it) unless
  the user ticks "Visa företag med reklamspärr"; call lists are built without
  companies that have a telemarketing or NIX opt-out (API only) and show the
  opt-out on the card. A company whose register phone or e-mail is on the
  company's suppression list (ADR-043) is not added.
- **Source and time per row**: `sources` (which files/APIs have filled the
  row), `fetched_at` (SCB part) and `bolagsverket_fetched_at`, and every page
  that shows register data names the sources and the date of the data.

### Import (`registry:import`)

`php artisan registry:import {source} {--file=} {--force} {--resume}`, with
`source` one of `scb-bulk`, `bolagsverket-bulk`, `scb-api`.

- **Streams**: the zip is downloaded to `storage/app/registry/` with
  `Http::sink` and read line by line from `ZipArchive::getStream`
  (`fgetcsv` for Bolagsverket's quoted multi-line fields), converted to
  UTF-8, mapped to a `RegistryRow`, and written 1 000 rows at a time with
  one `INSERT … ON CONFLICT (org_number) DO UPDATE` per chunk. Memory stays
  flat for 1.8 million rows.
- **Idempotent**: running it twice gives the same rows; an unchanged file
  (same `ETag`) is skipped unless `--force`.
- **Resumable**: `company_registry_imports` records each run, its position
  (rows read, or the API cursor) after every chunk, and its result. With
  `--resume`, an unfinished run of the same source and file continues from
  its position.
- **Gone rows**: after a complete SCB run, rows not seen in it get
  `gone_at`; search hides them.
- **Changes for monitoring** are written only for organisation numbers that
  some company watches (the CRM accounts with an organisation number), as
  `company_registry_changes`: field, old and new value, time and source. The
  first load writes none. Kept 400 days (`registry:import` prunes them).
- **Schedule**: weekly (Monday 03:30 SCB file, 04:40 Bolagsverket file;
  the API at 05:00, after its nightly 04:00–04:30 window, when a key is
  set), only when
  `COMPANY_REGISTER_IMPORT=true`. Off by default: a self-hosted instance
  chooses to load 1.8 million rows. The hosted instance turns it on.
- **Rate limits**: the API driver keeps under 5 calls per second and waits
  out a 429's `Retry-After`; the files are two downloads a week.

### Prospect search (`/kunder/prospektera`, `crm.prospecting`)

Filters, kept in the query string: SNI (main code; a division picks every
code in it, with SCB's names), town (postal town) and postal-code prefix,
municipality and county (when the API has filled them), legal form,
registration date from–to, employee and turnover size class (when filled),
active only (default on: active at SCB, not deregistered, no bankruptcy,
liquidation or reorganisation), hide postal opt-outs (default on), hide
companies already in the CRM. At least one filter is needed. 25 per page, a
fixed number of queries (tested). **Lägg till som prospekt** for one row, for
the ticked rows, or for all hits (at most 500), through `SaveAccount` with
source "Företagsregistret"; an account with the same organisation number is
not duplicated.

### Look-alike search (`/kunder/prospektera?lage=liknande`)

No AI. The user's best customers are the CRM's customers by invoiced value
in the last 12 months (the same sums as `AccountFigures::sales`), top 20,
the first five preselected. Each chosen customer found in the register is a
**profile**: its SNI (the whole five-digit code, or the two-digit division),
its employee size class (± one class) and its county (or, without county
data, its town). A register company matches a profile when it meets every
criterion the user has ticked; the result is the union over profiles,
excluding the CRM's own accounts, and each hit says which customer it
resembles and on what. Criteria whose data is missing are shown greyed with
the reason ("Storleksklass kräver SCB:s API-nyckel").

### Call lists (`/kunder/ringlistor`)

`crm_call_lists` (name, assigned seller, made by, criteria, archived) and
`crm_call_list_items` (account, position, status PENDING/DONE/SKIPPED,
attempts, last outcome, the call activity). Built from the prospect search
(ticked rows or all hits) or from the CRM's own accounts (status, town,
tag). Working a list shows one company at a time: phone (`tel:`), the
contact people (not those marked Kontakta inte), the last activities and
the register facts; one tap logs the call with its outcome through
`LogActivity` (and so the Kontakta inte rules), with an optional note and
next step. "Inget svar" and "Lämnade meddelande" put the company back at the
end of the list until three attempts. Progress is shown as done of total.
Company-scoped; `crm:view` sees lists, `crm:manage` builds and works them.

### Monitoring (`crm:registry-watch`)

Daily at 06:10, for companies with CRM on and the setting **Bevaka kunder i
företagsregistret** on (default on): every change in
`company_registry_changes` for the organisation number of one of the
company's accounts that the company has not been told about gives **one
task "Ändring hos kunden"** per account and run, for the account's owner
(else the company owner), due 09:00, listing what changed; a
`crm_registry_notices` row per change makes it idempotent. Watched: name,
address and town, legal form, SNI, active status, deregistration,
bankruptcy/liquidation/reorganisation, and with the API key also employee
and turnover class, municipality and status at Bolagsverket. Like the
win-back reminder, the task does not reset the pruning clock. **Not
watched** (need the paid driver): new CEO or board, exact turnover, payment
remarks, credit rating; the settings page says so.

### Permissions

`crm:view` searches the register, sees call lists and the register facts on
an account; `crm:manage` adds prospects and builds and works call lists;
`crm:settings` turns monitoring on or off. The register itself is readable
by any CRM user of any company: it is public data.

### GDPR and register of processing

- **The instance register** (`company_registry`): the instance operator is
  controller for the residual personal data in it (names of HB/KB that
  contain a partner's name, company phone numbers and e-mail addresses that
  may be a person's own). Purpose: offering a register of companies for
  B2B prospecting; legal basis art. 6.1 f; source public registers (art. 14
  (2)(f) information in the privacy policy); kept while the company is in the
  source, removed when gone. `TODO(legal-verify)`: whether this residual
  data makes the operator a controller with an art. 14 duty that is met by
  the privacy policy (art. 14(5)(b), disproportionate effort, public source).
- **A company's prospects, call lists and notices**: the company is
  controller and NONOX processor, as for the rest of the CRM (ADR-043,
  registerförteckning B). A prospect added from the register is a legal
  person; contact people are added by hand with a legal basis and an
  "Informerad den" date, and the manual's art. 14 template applies within a
  month.
- **Marketing law**: telemarketing to companies is allowed, but opting out
  is respected (SCB's opt-out codes; NIX-Telefon appears in the API's
  code list). `TODO(legal-verify)`: whether a company's NIX or telemarketing
  opt-out in SCB's register binds a seller under marknadsföringslagen and
  good marketing practice, and whether a postal opt-out should also hide a
  company from call lists.

## Implementation notes (2026-11-04)

- Code: `app/Support/CompanyRegistry/Import` (sources, `RegistryWriter`),
  `app/Console/Commands/RegistryImportCommand.php`, models
  `RegistryCompany`/`RegistryImport`/`RegistryChange`, `Sni` and
  `RegistryCodes`; in the CRM module `ProspectSearch`, `LookAlike`,
  `AddProspects`, `CreateCallList`, `WorkCallList`,
  `CreateRegistryNotices`, `crm:registry-watch`, the pages `ProspectingPage`,
  `CallListsPage`, `CallListPage`, and the `x-crm::registry-facts` card on the
  account card and the call list. `App\Support\CreditData` holds the
  interface and the null driver.
- A completing source (Bolagsverket's file) updates rows with one
  `UPDATE … FROM (VALUES …)` per chunk: an upsert would check the NOT NULL
  name of the new row before it sees the conflict.
- The bulk file's opt-out column takes 1 and 2 (2 read as an opt-out), the
  API's 3 and 4 (4). `TODO(legal-verify)`: SCB's code list for the bulk
  file's `Reklamsparrtyp`.
- The SCB file's own registration date (`RegDatKtid`, the date in SCB's
  register) is written only when a row is created; Bolagsverket's date
  replaces it.
- A suppression-list match is checked on the register's phone and e-mail,
  which only the API provides; without the key the check has nothing to
  match.
- The privacy policy (`docs/juridik/`) is not changed in this branch: the
  register's residual personal data and art. 14(5)(b) are for the lawyer
  first (see GDPR above), and a policy change bumps the legal version.
- Tests: `tests/Feature/Crm/RegistryImportTest.php` (synthetic fixtures in
  `tests/Fixtures/registry/`), `ProspectingTest.php`,
  `tests/Tenancy/CrmProspectingTenancyTest.php`.

## Consequences

- An instance with `COMPANY_REGISTER_IMPORT=true` has a register of about
  1.2 million legal persons a week after deploy, with SNI, address, town,
  legal form, registration date and status; without the SCB key, no size
  classes, municipality or county, phone or e-mail.
- The register adds a few hundred MB to the database (estimate: 1.2 million
  rows with indexes).
- Payment remarks, CEO changes and credit ratings wait for a contract; the
  interface (`App\Support\CreditData\CreditData`) is in place.

## Owner actions

1. **Turn the weekly import on** on the hosted instance: set
   `COMPANY_REGISTER_IMPORT=true`, then run once
   `php artisan registry:import scb-bulk` and
   `php artisan registry:import bolagsverket-bulk` (each a few minutes; the
   second only updates rows the first created).
2. **SCB API key** (free): register at <https://registreraafr.scb.se/> with
   BankID as Stafe Development AB, accept the terms, wait for the key by
   e-mail, set `SCB_AFR_API_KEY=<key>` in the server's `.env` (never in the
   repository), and run `php artisan registry:import scb-api` once (about
   400 calls at 5 000 rows). Read the terms for the questions above.
3. **Credit data**: when a contract exists, implement `CreditData` for the
   provider and set `CREDIT_DATA_DRIVER`.
4. Confirm the `TODO(legal-verify)` items above with the lawyer.
