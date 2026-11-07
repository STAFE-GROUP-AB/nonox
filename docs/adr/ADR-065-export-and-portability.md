# ADR-065: Export and portability (full-company JSON dump)

Status: Proposed
Date: 2026-11-07
Spec: `docs/specs/nummer-1-spec.md`, F10

## Context

A customer must be able to leave NONOX at any time with all of their data in open formats and
restore it in another NONOX installation (spec F10). AGENTS.md section 0 promises "zero lock-in:
everything importable and exportable (SIE4, CSV, PDF, API)". SIE4 carries the ledger but nothing
else: not customers, invoices, attachments, payroll or CRM, and not NONOX's own hash chain.

## What exists today

| Scope item (F10) | Exists | Where | Gap |
|---|---|---|---|
| 1. SIE4 per fiscal year | yes | `app/Support/Sie`, `ExportSie` | none for the ledger |
| 1. CSV per register | partly | report CSV in `ArchivePackageBuilder` (`ReportTable`), `Csv` | no CSV for customers, suppliers, articles, CRM, payroll |
| 1. PDF archive of vouchers with attachments | yes, per fiscal year | `ArchivePackageBuilder`, `CreateArchivePackage`, `BuildArchivePackage` job, `ArchivePackage` model, `ArchivePackageController` | one year at a time; reports, invoices, VAT, payslips, audit CSV, system documentation, manifest with SHA-256 |
| 1. Payroll history | in the archive package (payslips, AGI), permission gated | `ArchivePackageBuilder::payroll` | no machine-readable form |
| 1. CRM data | no (only per-person `DataSubjectExport`) | `app/Support/Privacy/DataSubjectExport.php` | whole-company CRM export missing |
| 1. JSON dump with published schema | no | | this ADR |
| 2. Background job, encrypted, time-limited download, log entry | job and download exist for the archive package; no encryption at rest, no expiry | `BuildArchivePackage`, `ArchivePackage` | encryption, expiry, audit on download |
| 3. Import into a fresh installation | SIE4 import only | `ImportSie` | JSON import missing |
| 4. Manual, pricing, terms link | no | | docs package, after the feature exists |

The archive package is a human-readable legal archive (BFL 7 kap, readable without NONOX). The
JSON dump is a different artefact: machine-readable, whole company, all years, restorable.
They are complementary and share the job/storage pattern, not the code.

## Decision

### 1. One JSON dump, versioned, with a published JSON Schema

- A dump is a directory (later zipped): `dump.json`, and `attachments/<sha256>` (one file per
  distinct content, so a file shared by several vouchers is stored once).
- `dump.json` has `format: "nonox-dump"`, `schema_version` (semver; a major bump means an older
  importer must refuse), `generated_at`, `counts` and one top-level array per entity.
- The schema is published at `docs/portability/nonox-dump.schema.json` (JSON Schema draft 2020-12)
  and is the contract. The writer is tested against it; a test fails if a registered table gains
  a column that is neither exported nor explicitly listed as excluded.
- Package order and tables in scope, by package:
  1. Accounting core: company, fiscal years, voucher number counters, accounts, cost centres,
     projects, voucher series, vouchers with rows, attachments (metadata + files), customers,
     suppliers.
  2. Invoicing and payables: articles, invoices with rows and payments, reminders, supplier
     invoices and payments, number series.
  3. Opening balances, accruals, assets, VAT reports, annual reports, tax data.
  4. Module data behind the module switch: payroll, CRM, inventory, planning, agreements.
- Money is integer öre as JSON numbers. Dates are `YYYY-MM-DD` strings. Voucher `posted_at` is
  `YYYY-MM-DD HH:MM:SS` in Europe/Stockholm (the form the hash covers). UUIDs stay strings.

### 2. Identifiers and the hash chain

This is the hard part and it was found by reading `LedgerHash::canonicalJson`: the voucher hash
covers database ids (`company_id`, `fiscal_year_id`, `posted_by`, `cost_center_id`, `project_id`)
and the voucher uuid. A different installation will assign different ids, so the chain cannot be
copied byte for byte.

- The dump carries **source ids as they were** (bigints as numbers, voucher uuids as strings),
  plus every field the hash covers, plus `hash` and `previous_hash` and the fiscal year's
  `ledger_head_hash`. `App\Support\Portability\DumpChainVerifier` recomputes the source hash from
  the dump alone, with no database.
- **What verification proves, and what it does not.** It proves *internal consistency*: the
  vouchers, rows and links in this file agree with the hashes in this file. It does **not** prove
  *authenticity*. The hash is unkeyed SHA-256 and the head hash travels in the same file, so
  anyone who can edit the dump can rebuild a consistent chain. Authenticity would need a
  signature by the exporting installation, which is not part of this design. The ADR, the
  manual and the importer's report must say "consistent", never "unaltered". What the importer
  does record is what it verified (see section 5), and the reconciliation report compares the
  result with the dump's own counts and trial balance. The verifier also reports posted vouchers
  whose fiscal year is not in the dump, unknown statuses and missing keys as problems, so a
  malformed dump is refused, not skipped.
- **Raw user ids are exported** (`posted_by`, `created_by`, `closed_by`, `uploaded_by`) as numbers
  without names. `posted_by` is covered by the hash, so it cannot be left out without breaking
  verification; the others are kept for the same history. A bare number identifies nobody
  outside the source installation.
- Importing remaps ids: every entity gets a new local id, kept in an in-memory map
  `source id -> new id` per entity type. Voucher uuids are kept (they are not installation
  specific and other records point at them).
- Because the covered ids change, the importer **re-seals** the chain with the same algorithm
  (`LedgerHash`) over the new ids, in the original `posted_at, id` order, exactly as `ImportSie`
  does today. The source hashes are not discarded: each imported voucher stores
  `imported_source_hash` (new column in the import package) so the old and new chains can be
  compared and the provenance is documented in the audit log and the system documentation.
  Number, series, date, rows and `posted_at` are never changed by import.
- Rejected: preserving ids verbatim (insert with explicit ids, reset sequences). It would keep
  the chain byte-identical, but only works if `company_id`, user ids, cost centre ids and so on
  are free in the target, and it ties the format to one installation's id space.

### 3. Personal numbers and other encrypted columns

Encrypted columns (`customers.personal_number`, and later employee and CRM fields) are stored
encrypted with the installation's `APP_KEY`, which another installation does not have. The dump
therefore has to carry them decrypted or lose them.

- Decision: they are exported **decrypted, only inside the encrypted package**. The writer's
  `includePersonalNumbers` flag is **false by default**; only the export job, which always
  encrypts the whole package, sets it to true. A plain writer call (tests, tooling, a future CLI
  for an operator) cannot leak them by accident.
- The flag also covers `org_number` where it is a personal number: the company's own when its
  legal form is EF, and a customer's or supplier's when the number is a valid personnummer
  (`PersonalNumber::looksLike`: ten digits, Luhn, a month in the third and fourth digits). A
  sole trader's organisation number is the owner's personnummer.
- **Free text is not filtered.** Voucher and row descriptions, attachment names and customer
  names can contain personal data. The dump is a copy of the books, and the books contain them
  (BFL retention applies); the protection is the encrypted package, not redaction.
- The importer encrypts them again with the target's key through the model cast.
- The same rule covers every later field in the registry maintained by F8
  (`app/Support/Privacy/**`): exported only with the flag on.

### 4. Job, encryption at rest, download, audit (package 2)

- `ExportCompanyData` action authorises (`export:archive`, owner and admin only; accountant and
  auditor may not export payroll or CRM without their module permission), writes a
  `data_exports` row (`company_id`, `requested_by`, `status`, `expires_at`, `sha256`, `size`) and
  dispatches `BuildCompanyExport` with `company_id` explicitly and `CurrentCompany::run`.
- The job builds the directory, validates `dump.json` against the schema, zips, and encrypts the
  zip with a per-export random key using libsodium `crypto_secretstream_xchacha20poly1305`
  (chunked, so 2 GB files do not need memory). The key is shown to the requester **once** as a
  passphrase-style string and is stored only as a verifier; it is not recoverable by the
  operator. Rejected: encrypting with `APP_KEY`; it would make the package unreadable elsewhere
  and tie the operator to every export.
- The working package directory is created by the job under
  `storage/app/private/exports/<company id>/<export uuid>/` with mode 0700 (files 0600), never
  below `public/`, and is deleted as soon as the encrypted file exists, and on any failure (the
  writer removes the whole directory, including a half-written `dump.json` or `.part` file, if it
  throws). The writer refuses a directory that already has content.
- The encrypted file lives on the private disk. Download needs a signed URL, a signed-in user
  with the permission, and the export being unexpired (default 7 days). A pruning command
  deletes expired files and keeps the row. Request, completion, every download and expiry write
  an audit entry containing ids and counts only.

### 5. Import into a fresh installation (package 4)

- Entry point is an artisan command and a first-run screen, never an action reachable inside a
  working company. The importer refuses unless **the target is a company with no posted voucher,
  no invoice and no fiscal year** (a company just created and not initialised), checked under a
  lock. It creates a **new** company; it never merges into an existing one, and never into one
  that has postings. This follows from AGENTS.md section 1.2: nothing posted may change.
- Order: verify format and `schema_version`, validate against the schema, verify each year's
  source chain with `DumpChainVerifier`, verify attachment files against their SHA-256, then one
  database transaction per fiscal year: master data, drafts, then posted vouchers.
- Order per voucher: insert the voucher as a **draft**, insert its **rows**, then **UPDATE it to
  POSTED** with number, `posted_at`, `posted_by` and the re-sealed hash. The database trigger
  `vouchers_check_balance_on_post` validates balance at that update, and `vouchers_protect_posted`
  blocks every later change. It compares `to_jsonb` of the row, so **every new column that
  records provenance (the imported flag, the source hash, the source user id) must be set before
  the update to POSTED**; afterwards they are protected like the rest. Inserting a row that is
  already POSTED is not used. Update and delete remain impossible afterwards.
- Imported vouchers are marked: `source` keeps the original value (for example `INVOICE`), and a
  separate `imported_at` / `imported_from_dump` flag says the voucher arrived by dump import, so
  a reader can tell original postings from restored ones.
- The import writes one append-only **audit entry** holding the SHA-256 of `dump.json`, the
  importing user, the schema version, and for every fiscal year the **source head hash** and the
  new head hash. That records what was verified and against what.
- Users are not in the dump. `posted_by` and `created_by` have to point at a user in the target,
  so they are set to the importing owner, **openly, not silently**: the source user id is kept in
  `imported_source_user_id` (set before posting) and the voucher history shows "Bokförd av
  (källsystemets användare 1234), importerad av <name> <date>". Product-owner decision **F10-1**
  was taken on 2026-11-07 (see "Decision F10-1" below).
- The report after import compares counts, trial balance per year, voucher numbers per series
  and attachment hashes with the dump's `counts`. A mismatch rolls back and nothing is kept.

## Follow-ups from other packages (2026-11-07)

- Package 3 (annual reports) must add these to the dump, or list them as excluded with a reason:
  `company_accounting_policies` (S1-P3), `prior_year_size_figures` and
  `annual_reports.average_employees_fte` (S1-P2).
- `fiscal_years.reporting_framework` and its three change columns are already in the dump
  (S1-P1, schema 1.1.0). The field is optional in the schema, so a 1.0.0 dump stays valid. The
  importer gives every year of a 1.0.0 dump the company's `reporting_framework`.

## Decision F10-1 (2026-11-07)

The owner delegated the decision to the Lead, who accepted the design in section 2 and in the
import section above: verify the source chain, re-seal it over the new ids, keep
`imported_source_hash` and `imported_source_user_id`, and never change number, series, date,
rows or `posted_at`. The ground is BFL 7 kap. 6 §, read on riksdagen.se 2026-11-07 (text up to
SFS 2024:342). It lets the source be destroyed only if the transfer carries no risk "att
räkenskapsinformationen förändras eller försvinner". The hash chain is NONOX's own integrity
mechanism, not something the law requires, so re-sealing it is allowed. What the law does
require is that the content does not change, and the reconciliation report shows that: counts,
trial balance per year, voucher numbers per series and attachment hashes all match.

The approval has three conditions:

1. The import package is not merged until an independent security review passes, as the
   recommendation said.
2. The import receipt and the manual tell the user to keep the dump file, together with its
   SHA-256 from the audit entry, for the retention period of BFL 7 kap. 2 §. Keeping it is
   what lets the transfer be checked afterwards. NONOX does not delete the dump on the user's
   behalf.
3. The voucher history shows the source user id and the importing user, openly, as section 5
   says. That gives the traceability BFL 5 kap. 5 § asks of a correction, even though an
   import is not a correction.

## Options rejected

- **SIE4 as the only portable format.** Lossless for the ledger only.
- **One huge `dump.json` loaded into memory.** The writer streams, element by element.
- **JSON Lines.** Easier to stream, harder to publish a schema for and to read by hand.
  Streaming is achieved with a normal JSON document written incrementally.
- **Database-level dump (pg_dump).** Ties the customer to our schema and to the module set, and
  contains other tenants' data in a shared database.
- **Competitor-specific format.** Out of scope in the spec.

## Test plan

- Schema: the written dump validates against the published schema; a deliberately broken dump
  does not.
- Tenancy: another company's customers, vouchers and attachments never appear in the dump.
- Hash: number, hash and previous hash of a posted voucher survive, and `DumpChainVerifier`
  reproduces every hash from the dump alone.
- Queries: the query count is the same for 10 and 100 vouchers (chunked eager loading).
- Attachments: stored once per SHA-256, content verified, missing file reported not hidden.
- Personal numbers absent by default, present only when asked.
- Round trip (packages 4): export a fixture company, import into an empty installation, compare
  ledgers, registers and attachment hashes; `ledger:verify` on the result.
- Refusal: import into a company with postings fails and changes nothing.

## Performance target

A company with **50 000 vouchers** (about 150 000 rows, 20 000 attachments of 200 KB) exports in
**under 5 minutes** and **under 256 MB of PHP memory** on the production worker, in chunks of
500 vouchers: three queries per chunk (vouchers, rows, attachments), constant memory. Import of
the same dump targets under 20 minutes. Measured and recorded in this ADR when package 4 lands.

## Packages

1. JSON dump writer and JSON Schema for the accounting core (export only, no UI). **This PR.**
2. Export job, libsodium encryption, signed time-limited download, audit entries, expiry pruning,
   `data_exports` table and the settings screen.
3. Extend the dump: invoicing, payables, assets, VAT and annual reports; then module data
   (payroll, CRM, inventory, planning, agreements) behind module permissions; CSV per register.
4. Importer for a fresh installation with re-sealing and the reconciliation report; round trip
   test; `ledger:verify` after import.
5. Docs: manual article, link from pricing page and terms, systemdokumentation, changelog.

## Consequences

- A published, versioned format that outside tools can read.
- Every new company-scoped table must be added to the dump or listed as excluded with a reason,
  enforced by a test (package 3).
- Open legal question: whether payroll and CRM personal data may be handed to the controller in
  one file by an accountant acting for a client. `TODO(legal-verify)` before package 3.
