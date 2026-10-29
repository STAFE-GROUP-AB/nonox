# ADR-007: Vouchers, posting and the ledger chain

- Status: Accepted
- Date: 2026-10-29
- Task: T-1.2

## Decision

Vouchers follow `AGENTS.md` §6.4, with one deliberate deviation in how the
hash chain is ordered.

### Immutability at three levels (§1.2)

| Level | Where | What it refuses |
|---|---|---|
| Policy | `VoucherPolicy` | update, delete and post of a posted voucher, for every role |
| Model | `Voucher`, `VoucherRow` events | changing or deleting a posted voucher; adding, changing or deleting its rows |
| Database | triggers in `2026_10_29_160000_create_vouchers_tables` | the same, through the query builder and raw SQL, plus `TRUNCATE` |

The only change allowed to a posted voucher is setting
`corrected_by_voucher_id` once, from `NULL`. The trigger compares the whole
row except that column and `updated_at`.

### Balance, in code and in the database

`PostVoucher` checks at least two rows and debit = credit > 0. A trigger checks
the same when a voucher becomes `POSTED`, so a voucher can never be marked as
posted without balancing, even with raw SQL. A `CHECK` constraint keeps every
row one-sided and non-negative.

### Posting

`PostVoucher` runs in one transaction:

1. Lock the fiscal year row (`SELECT ... FOR UPDATE`). This serialises all
   posting in the year, so the number counter and the hash chain cannot race.
2. Validate: rows, balance, open fiscal year, period not locked
   (`companies.locked_until_date`), active accounts, required dimensions.
3. Take `next_number` from `voucher_number_counters` (locked, then
   incremented). A failed posting rolls back, so no number is lost.
4. Compute the hash and set `POSTED`, `posted_at` (whole seconds) and
   `posted_by`.
5. Store the hash as the fiscal year's `ledger_head_hash`.
6. Write the audit log.

### Deviation: the chain is ordered by its links, not by time

§6.4 orders the chain by `(posted_at, id)`. With random UUIDs and second
precision, two vouchers posted in the same second could be ordered differently
on verification than when they were posted, which would report a broken chain
that is not broken. Instead:

- each voucher stores `previous_hash`, the hash of the voucher posted before
  it in the same fiscal year;
- the fiscal year stores `ledger_head_hash`, the newest hash, updated under
  the same row lock;
- `ledger:verify` walks the chain from the voucher without `previous_hash`,
  following the links, and checks that it reaches every posted voucher and
  ends at `ledger_head_hash`.

The hash formula is the one in §6.4:
`sha256(previous_hash || canonical_json(voucher + rows))`, with sorted keys,
rows in id order and the account number rather than its id.

### Corrections

`CorrectVoucher` posts a new voucher (source `CORRECTION`, same series) that
reverses every row of the original, optionally adds re-booking rows, and links
both ways. A reason is required. A voucher can be corrected once.

### Integrity check

`php artisan ledger:verify {company|--all}` checks the chain, the balance of
every posted voucher, and gapless numbering from 1 per series and fiscal year.
Results are written to the audit log as `ledger.verified`. It runs nightly at
03:15. Showing the result in the UI comes with the voucher pages (T-1.3).

## Not yet

- Dimension tables (cost centres, projects, §6.5): the row columns exist, and
  the posting check for required dimensions is in place.
- Period locking UI and the per-period lock table.

## Update: attachments (T-1.3)

- `voucher_attachments` stores underlag privately (`storage/app/private`) under
  generated names, with the MIME type detected from the bytes (`finfo`), never
  from the name. Only PDF, JPEG, PNG and WebP up to 10 MB.
- A trigger rejects every UPDATE, and DELETE once the voucher is posted.
  Underlag may be added after posting (`TODO(legal-verify)`), never removed.
- `ledger:verify` checks that every attachment of a posted voucher still exists
  and matches its SHA-256.

