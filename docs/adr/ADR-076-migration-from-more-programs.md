# ADR-076: Moving from more programs: register import, open reskontra and a guide per program

Status: Proposed
Date: 2026-11-08
Relates to: ADR-008 (SIE and opening balances), ADR-011 (invoicing), ADR-013 (supplier
invoices), ADR-017 (module framework), ADR-020 (naming other programs), ADR-023 (inbox),
ADR-043 (CRM and GDPR), ADR-065 (export and portability), AGENTS.md §1.1, §1.2, §1.3, §9.1,
§14, roadmap item `flytt-fran-fler-program`

## Context

Bookkeeping moves from any program that can write SIE4: the two-step import of ADR-008
reconciles every account against the file and refuses a file that does not balance. The
Fortnox importer (`app/Migration/Fortnox`) goes further, but only for Fortnox and only
through their API: it reads SIE per year, customers, suppliers, articles and attachments,
and compares the sums of unpaid invoices with the ledger. The moving guide at `/flytta-hit`
and the per-program notes in `docs/migration/kallor` tell the user what to export, but in
general terms ("export the registers if you can").

What is missing for everyone else:

1. **Registers.** A customer, supplier or article register does not travel in SIE. Today the
   user types them in or, for Fortnox, uses the API.
2. **Open reskontra.** SIE already carries the balance on 1510 and 2440, but not which
   invoices make it up. Without the invoices there is nothing to send reminders about or to
   match a payment against.
3. **Attachments.** Only the Fortnox importer links files to vouchers.
4. **A guide per program** that says, for that program, what to export and where, with the
   file format and the columns. `docs/migration/export-formats.md` (this ADR's research,
   read 2026-11-08) shows how little vendors document: only Spiris lists column names, only
   Bokio documents a bulk receipt export, and for PE Accounting/Kleer, Björn Lundén (as a
   source), Speedledger and Wint nothing usable was found on public pages.

Constraints that shape the decision:

- **Clean-room (AGENTS.md §1.1, ADR-020).** We may name other programs on public pages and
  describe, in our own words, where their export lives. We never copy their help texts,
  column lists presented as their documentation layout, screenshots or UI. We may use a
  column header as a fact about a file format for the sole purpose of reading that file;
  the presets store header names only, and each carries the source URL and the date read.
- **No real exports in the repository.** Fixtures are synthetic files made to look like the
  documented shape (AGENTS.md §13, ADR-008 fixtures rule).
- **Personal data.** Registers hold names and personal numbers of customers and suppliers
  (sole traders). The importer is a new processing of personal data (ADR-043 legal-basis
  rules, AGENTS.md §10).
- **Tenancy and immutability.** Everything is company scoped (§1.3). Imported open invoices
  must not change a posted ledger (§1.2).

## Decision

Proposed. Four deliverables: a generic register importer, an open-reskontra importer with
reconciliation, attachment linking by ZIP, and a data-driven guide per program. API
integrations to other vendors stay out of scope (see "Out of scope").

### 1. Where it lives

A new namespace `app/Migration/Import` (the generic importer, no vendor in code) next to
`app/Migration/Fortnox`. The word "Fortnox" stays confined as today. Other programs' names
appear only in data (presets and guides, §6), never in class names, so ADR-020's "names only
in the migration module and on public pages" holds. It is part of the core product, not an
optional module: moving in is needed before any module is on. Permission `import:registers`
(owner, admin, accountant); the Fortnox permission `import:fortnox` is unchanged.

Entry point: Bokföring, Läs in SIE-fil gets a sibling **Läs in register** (URL
`/bokforing/las-in-register`, Swedish per §11.4). The moving guide links straight to it.

### 2. Generic register importer (customers, suppliers, articles)

One pipeline for the three register kinds, in the same two steps as ADR-008, so the user
learns one pattern.

**Step 0, choose.** The user picks the kind (customers, suppliers, articles) and a source
(a program preset, "Egen fil" or the NONOX template).

**Step 1, read and map (changes nothing).**

- **Input.** CSV and XLSX (first sheet; the user can pick another). TSV and `.txt` with a
  delimiter are accepted as CSV. At most 20 MB / 100 000 rows; larger files are refused with
  a message to split them. Streaming reader for CSV; XLSX via a library with a licence
  compatible with AGPL (to be chosen by ADR comment before the build step, §1.4). The file is
  stored privately under `register-import/{company}/` with its SHA-256.
- **Detection.** Encoding (UTF-8 with or without BOM, otherwise Windows-1252 and CP437 as
  SIE does), delimiter (`;`, `,`, tab), header row, decimal comma. Reading is lenient;
  nothing is guessed silently: what was detected is shown and editable.
- **Mapping.** Each NONOX field gets a source column. Presets (§5) pre-fill the mapping by
  header name; unknown headers fall to "Ignorera". The user can change every assignment and
  **save the mapping as a company preset** ("Mitt format") for next time. A mapping is
  validated before the next step: required fields mapped, no source column used twice
  unless allowed.
- **Value mapping for coded fields.** Presets can carry value maps (for example a source's
  payment-terms code to NONOX days, boolean `True`/`False`, language codes, customer type
  company/private). A code with no map is listed and the user fills it in once.
- **Formula safety.** Cells that start with `=`, `+`, `-`, `@` are read as text, never
  evaluated; XLSX formulas are read as their stored value (the `Csv` support class exists
  for output; this is the input side).

**Validation report** (stored on the import row, like `sie_imports.report`):

- *Blockers* (nothing can be confirmed): no usable header row; a required field unmapped;
  file larger than the limit; a different company's organisation number inside a row marked
  as "Mitt företag" (rare, but a mixed-up export is worth catching).
- *Errors per row* (the row is skipped unless corrected in the preview): empty name; invalid
  organisation or personal number (**Luhn**, AGENTS.md §9.1, with century and hyphen
  stripped as `OrganisationNumber` does); malformed VAT number (country prefix, length);
  malformed e-mail; unknown country code; payment terms out of range; bankgiro/plusgiro that
  fails its check (§9.1) for suppliers; a number that is not an integer where one is required.
- *Warnings* (shown, do not stop): personal number present on a company customer; org number
  missing; same e-mail on several rows; text cut to column length.
- *Duplicates* (see below).
- *Counts*: rows read, to create, to update, to skip, with sample rows.

A preview shows the first 50 mapped rows and every row with an error. Rows can be edited or
excluded in the preview.

**Duplicates and idempotency.** The match key per kind, in order: customers and suppliers by
organisation number or personal number (normalised, Luhn-valid), then by the source's own
number stored in a new `external_ref` (§4), then by exact normalised name plus postal code;
articles by article number, then by name. Rules:

- Matching an **existing** record: default **skip** (never overwrite a record that may carry
  invoices). Per import the user may choose "fyll i tomma fält" (fill empty fields only);
  there is no "overwrite" in v1, because the editing screens exist and a bulk overwrite of
  invoiced customers is not recoverable.
- Matching **another row in the same file**: the later row is reported as duplicate and
  skipped; the user can pick which survives.
- **Re-running the same file** (same SHA-256 into the same company) is refused with a link
  to the earlier import; re-running a corrected file matches on the key above and so creates
  nothing twice. Idempotency is by key, not by file, so an edited re-export is safe.
- NONOX numbers: if the source has a customer/supplier number it is kept when free;
  otherwise the next number in the company's series. Never reused, never renumbered
  silently; a collision is a reported row.

**Step 2, confirm.** `ConfirmRegisterImport` queues `RunRegisterImport` with the company id
(§6.0). The job runs the existing `SaveCustomer`, `SaveSupplier`, `SaveArticle` actions per
row (so validation and audit are the ordinary ones; no second code path for writing a
customer), in chunks inside one transaction per chunk, with progress in the cache like the
SIE import. A chunk that fails rolls back that chunk and the import stops with a precise
message; the earlier chunks stay and the next run is safe because of the duplicate rules.
Audit: one `register.analysed` per file and one `register.imported` per import (file hash,
kind, counts), ids only, no names or numbers, per QUICKSTART §6.

### 3. Open reskontra (unpaid customer and supplier invoices)

The ledger already contains the balance, from the SIE import. The task is to create the
invoices that explain it, **without booking anything again**.

**Input.** A CSV/XLSX with one row per unpaid invoice. Fields: customer/supplier (matched by
number or org number to a register record, or created from the same file), invoice number,
invoice date, due date, currency, total, **remaining unpaid** amount, OCR (customers), VAT
information if the file has it. Presets exist only where a vendor documents a report
(`export-formats.md`: Spiris report, Billecta ageing analysis, Bokio's list; column names
largely undocumented, so mapping is manual). The NONOX template is the fallback for the
many programs with no data export of open invoices (installed Visma programs, BL, Speedledger,
Kleer, Wint: nothing public).

**Reconciliation is the gate.** Before confirming, the import computes the sum of remaining
amounts per side and compares with the **ledger balance on 1510 (customers) and 2440
(suppliers)** at the cut-over date, taken from the SIE-imported books (the same read the
Fortnox `openItems` step uses). Rules, in line with AGENTS.md §14.1 "any mismatch blocks":

- Equal: the import can be confirmed.
- Different: confirm is **blocked**, with the difference, the invoices with remaining amount
  different from total, and the likely causes (unbooked invoices, wrong cut-over date,
  invoice in another currency, VAT-accounts mixed into the sum). The user fixes the file; no
  override in v1.
- Foreign-currency invoices are compared at the ledger's own value (ADR-025); the file must
  carry the currency and the amount in SEK booked, or the row is an error.
- Reconciliation is repeated at confirm time inside the transaction, as ADR-008 does for the
  vouchers, because the books may have changed since the preview.

**What is created.** Invoice and supplier-invoice records with `source = IMPORT_OPEN` and
the remaining amount as an opening amount. They are created **without a booking voucher**:
the ledger side is the SIE opening balance on 1510/2440, which is why the import is only
allowed when that balance exists and covers the invoices. They show in the reskontra,
reminders, aging and payment proposals. Customer invoices get no PDF (we do not own the
original) but can have the source's PDF attached (§4). Imported invoices cannot be edited
or deleted; they can be paid, written off or credited through the ordinary flows.

**The hard part, flagged for the accountant check (owner question 1):** when such an invoice
is paid in NONOX the payment must be booked **against 1510/2440**, not against revenue and
VAT as a normal invoice payment under kontantmetoden would. ADR-013's year-end handling
books kontantmetoden invoices the other way (revenue and VAT at year end, reversed on day one)
because "booking the payment against 1510/2440 would need the payment flow to know about the
year-end entry". Imported open items are exactly that case and need a payment path that
knows the invoice came in with an opening balance. For companies on kontantmetoden the
imported books will have booked the invoice differently from NONOX's convention; the
accounting is ambiguous enough that this ADR does **not** decide it. v1 supports open
reskontra import for **fakturametoden** companies, and for kontantmetoden companies only
after the owner's accountant has answered question 1. `TODO(legal-verify)`: confirm the
treatment of an imported open invoice under kontantmetoden (BFNAR 2013:2 and the VAT
return).

### 4. Attachments by ZIP, and where the links live

- **New field.** `external_ref` (string, nullable, indexed per company and kind) on
  customers, suppliers, articles, invoices and supplier invoices, holding the source
  program's own number. Written only by importers. Migration with the model, up-only, per
  conventions.
- **Attachments for vouchers.** The user uploads a ZIP. Linking rules, in order: (a) a
  mapping file `lank.csv` in the ZIP with `filnamn;serie;nummer;ar`, the safest because it
  does not rely on a vendor naming convention (none is documented); (b) folder structure
  `ÅÅÅÅ/SERIE-NUMMER.ext` (also accepted for the Fortnox-style layout); (c) a manual
  step in the report for files that match neither. The `imported_vouchers` table (series and
  number to our voucher, ADR-008) is what resolves a link, exactly as the Fortnox importer
  does, through the existing `AttachToVoucher` action.
- **Safety.** Extension allowlist (`pdf`, `jpg`, `jpeg`, `png`, `gif`, `webp`, `tif`,
  `tiff`, `heic`), filenames reduced with `basename` and renamed, size limits per file and
  total, zip-bomb and path-traversal checks (entries with `..` or absolute paths are
  rejected), SHA-256 per file, files never executed or served from a guessable path.
  Duplicate files (same hash) in the same voucher are skipped; re-running is idempotent on
  voucher plus hash.
- **Report.** Linked, not linked (with reasons), duplicates, rejected. Nothing is deleted
  from the ZIP's content the user supplied; the ZIP itself is kept 30 days and then
  removed, with the retention stated in `docs/gdpr/lagringstider.md`.
- **Invoice PDFs** (customer invoices as PDF, per the guide) attach to the matching
  imported open invoice by invoice number, and otherwise to the archive area of the customer
  (ADR-065 keeps the archive package readable). Vendors do not document an invoice-PDF
  naming scheme, so the mapping file route applies here as well.

### 5. Presets: data, not code

A preset is a JSON entry in `docs/migration/format-presets.json` (public, versioned, read by
the importer and by the guide pages) with, per program, per register kind:

```
program (slug), kind, source_url, read_date, delimiter_hint, encoding_hint,
headers: [ { header, field, required, value_map? } ],
status: "documented" | "partial" | "undocumented"
```

Rules:

- A column appears in a preset **only if a vendor documents it publicly**, with the URL and
  date. Today that is Spiris customers and suppliers (full lists in `export-formats.md`) and
  Bokio customers (field names for import). Everything else is `undocumented`: the mapping
  screen starts empty and the guide says so.
- The reader matches headers case-insensitively and ignoring spaces and underscores, and
  tolerates both documented spellings and a missing/extra column; **a preset never blocks
  an import**, it only pre-fills the mapping.
- Presets are not claims about the vendor's product beyond "this is what the page said on
  this date". When a vendor changes its format the user falls back to manual mapping; a
  quarterly refresh task (the same as for `jamforelse.json`) keeps `read_date` honest.
- Company-saved presets live in a company-scoped table (`register_import_presets`) with the
  `BelongsToCompany` trait and a tenancy test; they hold header names and field links only,
  no data.

### 6. One moving guide per program, generated from data

- **Data.** `docs/migration/flyttguider.json`: per program a record with `slug`, `name`,
  Swedish text in our own words (steps for SIE4, per register kind, open invoices,
  attachments), `last_checked`, `sources` (URL and date for every step), `status` per
  capability using the same three values as the presets plus the evidence level (`vendor`,
  `third_party`, `unverified`, `none`). `export-formats.md` is the English research that feeds
  it; the JSON is Swedish because the pages are Swedish.
- **Pages.** `/flytta-fran/{program}` (Swedish, e.g. `/flytta-fran/spiris`, `/flytta-fran/bokio`,
  `/flytta-fran/bjorn-lunden`), rendered from the JSON by the existing migration-guide
  controller, linked from `/flytta-hit` and from the matching `/alternativ-till-*` page. The
  existing `kallor/*.md` hand-written notes are folded into the JSON.
- **ADR-020 rules, built into the data.** Plain-text names only; no logos, colours,
  screenshots or UI layouts; no quoted help text; every step carries its source and read
  date; "Anges ej" or "Dokumenteras inte offentligt" where nothing is public, never a guess
  and never a menu path from an unverified source. Weak evidence shows as such ("enligt X:s
  flyttguide, läst 2026-11-08") so the reader can judge. A test fails when a step has no
  source or when the page text contains a competitor logo file or the string of a quoted help
  text blacklist (a short list maintained next to the data).
- **Programs in v1:** Spiris (Visma eEkonomi), Visma Administration/Compact (as "installerade
  Visma-program"), Bokio, Björn Lundén, Speedledger, Kleer (PE Accounting), Wint, Zervant,
  Billecta. Fortnox keeps its existing guide (`leaving-fortnox.md`) and its API route.
  Honest status: for BL, Speedledger, Kleer and Wint most cells will say "Dokumenteras inte
  offentligt" until someone with access reads the program (owner question 4).
- **Honesty in the user flow.** For a program with no documented register export the guide
  says: take SIE4, export what the program lets you as Excel/CSV, map the columns yourself.
  That is a true and useful path; the importer is generic precisely for this.

### 7. Personal data and security

- Registers include sole traders' names, addresses, e-mail, phone, personal numbers.
  Legal basis, retention and register entry follow ADR-043: `docs/gdpr/registerforteckning.md`
  and `lagringstider.md` get a line for "inlästa registerfiler". The uploaded source file and
  ZIP are kept 30 days after a finished import (the audit trail keeps counts and hashes),
  then deleted; a failed or abandoned preview is deleted after 7 days.
- Personal numbers are stored with the existing `encrypted` cast (§10). The preview shows
  them masked except the last four digits.
- Org and personal numbers are validated with Luhn before anything is written (§9.1).
- All rows go through the company scope; the import row, its report and the saved presets are
  `BelongsToCompany` with tenancy tests (another company's id gives 404).
- Uploads are streamed, size-limited, virus-scan-hooked if the instance has a scanner
  (ADR-023 inbox pattern), never executed. XLSX macros and external links are not followed.

### 8. Out of scope

- **API integrations to other vendors** (Spiris, Bokio, Björn Lundén, Wint ...). Each needs
  an agreement, keys and terms review (see the owner's external-agreements memo for Fortnox);
  none is assumed. File and ZIP routes only. A later ADR may add one if a vendor offers a
  public API and its terms allow it.
- Payroll data from other programs (separate, `PAYROLL_MODULE.md`).
- Moving a *whole* ledger from a program with no SIE4 (nothing in this research suggests one).
- Reading `.xcr` (Visma's register file): layout not documented publicly. The guide tells
  Visma Compact users to export the screen list to Excel instead.
- Automatic overwrite/merge of existing customers; deleting anything in the source.
- Reconstructing historical invoices (closed ones) as records: only open items, plus PDFs as
  archive attachments.

## Build steps (tests first, synthetic fixtures)

Each step is one branch/PR, with the failing test written first (AGENTS.md §15). Fixtures are
generated for the repo and look like the documented shape only; no real exports ever
(`tests/Fixtures/import/`: `kunder-spiris-lik.csv`, `leverantorer-spiris-lik.csv`,
`kunder-egen.xlsx`, `kunder-semikolon-windows1252.csv`, `kunder-med-formler.csv`,
`oppna-kundfakturor.csv`, `bilagor.zip` with a traversal entry, etc.).

1. **S1 Reader.** Tests: delimiter and encoding detection (UTF-8, BOM, Windows-1252,
   CP437), XLSX first sheet and chosen sheet, formula cells as text, size/row limits, CSV
   injection on re-export. Code: `RegisterFileReader`.
2. **S2 Presets.** Tests: header matching (case, spaces, underscores), documented vs
   undocumented status, value maps, a preset never blocks, saved company presets with the
   tenancy test. Code: `format-presets.json`, `PresetRepository`, company presets table.
3. **S3 Customer import, steps 1 and 2.** Tests: mapping validation; Luhn org/personal
   numbers valid/invalid; VAT format; duplicates (in file, against existing, by org number,
   by `external_ref`, by name and postal code); skip existing; fill-empty-only; same file
   refused, corrected file idempotent; numbers kept/never reused; chunk failure leaves earlier
   chunks and a safe rerun; query-count test with a short and a long file (no N+1); audit has
   ids only. Code: migration (`external_ref`, import tables), `AnalyseRegisterFile`,
   `ConfirmRegisterImport`, `RunRegisterImport`, Livewire screen, Swedish toasts and
   `lang/en.json`.
4. **S4 Suppliers and articles** on the same pipeline. Tests: bankgiro/plusgiro check,
   IBAN/BIC, default expense account mapping, article units, VAT rate mapping, price in öre
   via `Money` (decimal comma, thousand separators).
5. **S5 Open reskontra.** Tests: reconciliation equals/differs against 1510/2440 with a SIE
   fixture, blocked confirm shows the difference, recheck at confirm, currency rows,
   no voucher created (ledger and hash chain unchanged), imported invoices read-only,
   payment books against 1510/2440 (after the accountant answer, owner question 1), and
   kontantmetoden companies refused until then.
6. **S6 Attachments ZIP.** Tests: mapping file, folder layout, traversal and absolute-path
   entries rejected, extension allowlist, size limits, zip bomb, duplicates by hash,
   idempotent re-run, links via `imported_vouchers`.
7. **S7 Guides.** Tests: every step has a source and a date, no step from an `unverified`
   source is rendered as fact, no logo/screenshot assets, comparison pages unchanged
   (`IndustryPagesTest`), every program page opens, `ChangelogTest`, `ManualTest` (article
   "Läs in register" with `rutter`), `LandingPageTest` untouched unless a feature card is
   added.
8. **S8 Docs.** Changelog entry (Swedish), roadmap status, manual article, GDPR register and
   retention lines, system documentation note (open items without voucher), `jamforelse`
   untouched (no new module), README line.

Definition of done is the repository's (QUICKSTART §5): full suite with `PGTZ=UTC`, Larastan,
Pint, legal-verify items listed in the PR.

## Open owner questions

1. **Open invoices under kontantmetoden.** How should an imported unpaid invoice be treated
   when the company reports by kontantmetoden: payment against 1510/2440 (what the imported
   SIE implies) or against revenue and VAT with an adjusting entry (what NONOX does for its
   own invoices)? Needs the consultant/accountant before S5 for those companies.
2. **Library for XLSX** (licence AGPL-compatible: MIT/BSD/Apache). Preference?
3. **Limits.** Are 20 MB / 100 000 rows and 30-day retention of source files acceptable?
4. **Reading the programs themselves.** Public pages say nothing for Kleer (PE Accounting),
   Speedledger, Wint, Björn Lundén (as a source) and the article columns of Spiris. Can the
   owner or a customer with access check the export menus and send **synthetic** sample
   files (no real data) so presets can be written from fact?
5. **Program list and order.** Is the list in §6 right, and which should be written first
   (suggested: Spiris, Bokio, Zervant, then the rest)? Hogia and Briljant appear in other
   vendors' move pages; add?
6. **Naming on the pages.** ADR-020 allows names on public pages. Is `/flytta-fran/{program}`
   acceptable, or should the guides sit under `/flytta-hit/{program}`?
7. **Evidence rule for the pages.** Should third-party sources ("enligt Spiris flyttguide")
   be shown at all, or should a step only appear when the program's own vendor documents it?
   The ADR proposes showing them labelled; the stricter option leaves most cells empty.
8. **`external_ref` visibility.** Show the source number on the customer card ("Nummer i
   tidigare program") for later matching of reports, or keep it internal?
9. **Legal review** of the comparison-adjacent wording on the new pages before launch
   (ADR-020 `TODO(legal-verify)` on Marknadsföringslagen applies).

## Consequences

- Anyone coming from any program that writes SIE4 can also bring customers, suppliers,
  articles, unpaid invoices and receipts, by file, with a mapping screen that works for
  formats we have never seen.
- The importer promises nothing the evidence does not support. Several programs will show
  "Dokumenteras inte offentligt" until read by someone with access.
- Presets are data that go stale; the cost is a review each quarter, and the failure mode is
  a manual mapping, not a broken import.
- Open-reskontra import stays blocked for kontantmetoden companies until question 1 is
  answered; that is a deliberate limit, not an oversight.
- A new processing of personal data (uploaded registers) with a short retention.

TODO(legal-verify): treatment of imported open invoices under kontantmetoden; marketing-law
review of the per-program pages (ADR-020); retention of uploaded source files (30 days).
