# ADR-008: SIE4 and opening balances

- Status: Accepted (round 1: export; round 2: import)
- Date: 2026-10-29
- Task: T-1.4

## Source

Everything here is built from the official specification, *SIE filformat,
utgåva 4C, 2025-08-06* (SIE-gruppen), not from memory or from other
programs' files. Section numbers below refer to it.

## Decisions

### Format layer (`app/Support/Sie`)

- **Codepage 437** (§5.8). The table was generated from iconv's CP437 and is
  tested to be lossless for every byte 0x80–0xFE. Characters the codepage
  lacks get a stand-in (en dash → `-`, `€` → `EUR`, curly quotes → straight)
  or `?`. Control characters become spaces, because §5.7 forbids them inside a
  field. A non-breaking space is written as a plain space, not as byte 0xFF.
- **Reading UTF-8 anyway.** Some programs write UTF-8 despite `#FORMAT PC8`.
  A line that is valid UTF-8 and contains non-ASCII bytes is read as UTF-8;
  a CP437 line of Swedish text is practically never valid UTF-8. The reader
  counts such lines so the import report can mention it.
- **Streaming.** `SieReader` reads one line at a time and yields one record
  at a time, so a 100 MB file never has to fit in memory (`AGENTS.md` §9.2).
  `SieWriter` writes straight to a stream, and the export reads vouchers in
  chunks.
- **Amounts** go to and from whole öre by string handling, never via a float
  (§5.9). More than 16 digits of kronor is refused (§7.6).
- **#KSUMMA** (§10) is standard CRC-32 (polynomial EDB88320, preset FFFFFFFF,
  inverted at the end), identical to PHP's `crc32b`. It covers labels and
  field contents in CP437, not the whitespace between fields, the quotes
  around them, the braces, or the backslash in `\"`. The value is written in
  decimal, as in the specification's example. A negative (signed 32-bit)
  value is accepted on import. Lines read as UTF-8 are re-encoded to CP437
  before they are summed.
- **Writing fields.** Numbers and code-like values (letters, digits, `.`,
  `-`) are written bare. Names and texts are always quoted, and empty trailing
  fields are left out (§12). SIE escapes only quotes, so a backslash at the
  end of a text, or right before a quote, is written as `/`. Otherwise it
  would be read back as an escaped quote.

### Export (SIE 4E)

- One file per fiscal year. It contains `#FLAGGA 0`, `#KSUMMA`, `#FORMAT`,
  `#SIETYP 4`, `#PROGRAM`, `#GEN`, `#ORGNR`, `#FNAMN`, `#ADRESS`, `#FTYP`,
  `#RAR 0/-1`, `#KPTYP`, `#VALUTA`, every account (`#KONTO` and `#KTYP`;
  `#SRU` where set), `#IB`/`#UB` for balance-sheet accounts and `#RES` for
  the income statement for year 0 and −1, and every posted voucher (`#VER`
  with `#TRANS`), series by series in number order (§11 #VER.7). Zero
  balances are left out (§5.17).
- Drafts are not bookkeeping and are never exported. Corrected vouchers and
  their corrections both are, because both are posted.
- `#FNR` is left out. Our internal id means nothing to another program.
- `#PSALDO`/`#PBUDGET` are optional in 4E and not written. Dimensions
  (`#DIM`, `#OBJEKT`, object lists) are written as described in ADR-009.
- `#VER` regdatum is the posting date and sign is the name of the person who
  posted.
- Permission `export:sie` (owner, admin, accountant, auditor). Every export is
  written to the audit log.

### Opening balances (`opening_balances`)

Opening balances (ingående balanser) are stored per fiscal year and account,
in signed öre (debit positive), exactly as SIE `#IB`. They are **not**
vouchers in series I:

- SIE keeps `#IB` apart from `#VER`. Storing them the same way makes the
  export and the import lossless both ways. An IB voucher would come back
  from another program as a voucher, and the balance would be counted twice.
- UB = IB + the year's posted movements. Reports (T-1.5) will read them the
  same way.
- Year-end rollover (T-1.5) and the SIE import (round 2) write them. They can
  change only while their fiscal year is open. The model refuses, and a
  PostgreSQL trigger refuses again, any insert, update or delete for a closed
  year. TRUNCATE is refused like the rest of the ledger.

### Import (two steps)

- **Step 1, check.** The uploaded file (at most 50 MB, a plain form post so
  Livewire's temporary upload limit does not apply) is saved privately under
  `sie-import/{company}/` with its SHA-256. `SieImportAnalyser` reads it once,
  streaming, and changes nothing. The report (`sie_imports.report`) lists what
  would happen and every reason the file cannot be read in.
- **Blockers** (nothing can be confirmed): a wrong or missing control sum
  (§10: a file with an opening `#KSUMMA` and no closing one must be refused),
  lines the reader could not read, `#SIETYP` other than 4, another company's
  organisation number, no `#RAR 0`, a voucher that does not balance, has
  fewer than two rows, has no amounts or is dated outside the year, the same
  number twice in a series, a voucher already read in (same series and
  number in the same year, or the same file), a closed fiscal year, a year
  that overlaps one here with other dates or is not next to the years here,
  a period lock covering the file's dates, differing opening balances, a
  series code longer than three characters.
- **Warnings** (shown, do not stop): no control sum, UTF-8 lines, no
  organisation number, account names that differ (ours are kept),
  deactivated accounts in use (read in anyway: this is history), vouchers
  already posted in the year (the file's are added after them), texts cut
  to 255 characters, objects in dimensions other than 1 and 6 (dropped; see
  ADR-009 for cost centres and projects).
- **Step 2, confirm.** `ConfirmSieImport` queues `RunSieImport` with the
  company id (§6.0). The job runs once. `ImportSie` locks the company row,
  checks the file again (the bookkeeping may have changed since step 1) and
  does everything in **one transaction**:
  - creates the fiscal year if needed, directly before the earliest or after
    the latest year (both audited, with `source: sie_import`);
  - creates only the accounts the file **uses** (vouchers, `#IB`, `#UB`,
    `#RES`), with the file's name, `#KTYP` and `#SRU`; unused `#KONTO` are
    counted, not added;
  - creates missing series as "Inläst serie X";
  - writes `#IB 0` to `opening_balances`; `#IB/#UB/#RES -1` are ignored
    (that year is imported from its own file);
  - posts every voucher with `source = IMPORT_SIE`, posted by the importing
    user, chained into the year's hash chain exactly as `PostVoucher` does.
    The database checks the balance again.
- **Numbering.** A series keeps the file's numbers only when it has no posted
  vouchers in the year here and the file's numbers are exactly 1..n. Otherwise
  the vouchers get the next numbers here, in the file's order. Either way the
  numbering stays gapless (§1.2), and `imported_vouchers` records the file's
  series, number, registration date (`regdatum`) and signature for every
  voucher. That is the mapping table of `AGENTS.md` §9.2, and what links
  attachments by series and number later (§14.1 step 4).
- **#RTRANS / #BTRANS** (§11). An added row is part of the voucher; the
  identical `#TRANS` that follows it is skipped. A removed row is not part
  of the voucher. Both are kept in `voucher_row_history` with their date,
  text, quantity, signature and position.
- **Reconciliation inside the transaction.** After posting, every account's
  UB (balance sheet) and result (income statement) is compared with the
  file's `#UB 0` and `#RES 0`, and the voucher count with the file's. Any
  difference rolls the whole import back and the differences are shown.
  Posted vouchers can never be removed, so a wrong import must never be
  committed.
- **Append-only.** `imported_vouchers` and `voucher_row_history` are part of
  the räkenskapsinformation: the models refuse changes, and PostgreSQL
  triggers refuse UPDATE, DELETE and TRUNCATE.
- **Audit.** `sie.analysed` per uploaded file and `sie.imported` per import
  (file, SHA-256, year, voucher count, accounts created). One entry per
  import rather than one `voucher.posted` per voucher; `imported_vouchers`
  holds the per-voucher record.
- **Export of imported vouchers.** `regdatum` and `sign` come from
  `imported_vouchers`, and the row history is written back as `#RTRANS`
  (followed by its `#TRANS`) and `#BTRANS` in place. Export → import into a
  fresh company → export gives the same file apart from the identification
  lines (`#PROGRAM`, `#GEN`, `#ORGNR`, `#FNAMN`, `#ADRESS`, `#FTYP`,
  `#KSUMMA`); a test proves this for two years.
- Permission `import:sie` (owner, admin, accountant).
- **Progress** is written to the cache, not the database: the import runs in
  one transaction, and rows written inside it are invisible to the page
  polling for progress.

## Consequences

- The golden file `tests/Fixtures/sie/export-2026.se` pins the export byte
  for byte. It includes the full K2 chart of accounts, so a change to the BAS
  template changes the file. Regenerate it with
  `UPDATE_SIE_FIXTURES=1 vendor/bin/pest tests/Feature/Accounting/SieExportTest.php`
  and review the diff.
- Series I keeps its name ("Ingående balanser/import"). Imported vouchers
  keep their own series code instead; a series that does not exist here is
  created.
- Files of a real company are never committed (`AGENTS.md` §15.6). The
  import fixture `tests/Fixtures/sie/import-2026.se` is synthetic.
- With `QUEUE_CONNECTION=sync` the import runs inside the request that
  confirms it. A large file needs a queue worker in production.
