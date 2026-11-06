# ADR-034: NONOX Banking (open banking, account information)

- Status: Proposed
- Date: 2026-11-01
- Task: NONOX Banking module (`banking`)

## Context

Bank reconciliation (Bokföring → Bankavstämning) and incoming payments
(camt.054, ADR-015) need the bank's own data. Today the user uploads a file
or types the statement balance. PSD2 lets a company give a licensed provider
read access to its accounts (account information service, AIS), approved in
the bank with BankID. NONOX can then fetch transactions and balances itself.

Research (2026-11-01): Enable Banking as the primary provider
(<https://enablebanking.com/docs/>, Swedish notes
<https://enablebanking.com/docs/markets/se/>), Mastercard Open Finance
(formerly Aiia) as fallback. Sources read for the driver: Enable Banking
*API reference* and *Quick start*, read 2026-11-01.

Payment initiation is out of scope. Supplier payments stay pain.001 files
(ADR-014) approved in NONOX and signed in the bank.

## Decisions

### Provider-agnostic driver

`App\Modules\Banking\Contracts\BankDataProvider`:

| Method | Purpose |
|---|---|
| `institutions(country)` | Banks, PSU types (business/personal), longest consent in days |
| `startAuthorisation(institution, psuType, validUntil, state, redirectUrl)` | URL of the bank's own approval (BankID) |
| `completeAuthorisation(code)` | Session id, consent end, accounts |
| `consentStatus(sessionId)` | Active / pending / expired / revoked |
| `balances(sessionId, accountId)` | Balances by ISO 20022 type (CLBD, ITBD, …) |
| `transactions(sessionId, accountId, from, continuation)` | Booked transactions, paged |
| `revoke(sessionId)` | End the consent at the provider (and the bank where possible) |

Data methods throw `ConsentEnded` when the bank no longer accepts the
consent and `ProviderFailed` otherwise. Messages never contain tokens,
session ids or account data.

One driver per installation (`services.banking.driver`): the contract with
the provider is the operator's. Each connection stores the driver key it was
made with. Drivers:

- **`enable_banking`**: `EnableBankingProvider`. Base URL
  `https://api.enablebanking.com`. Every request carries a JWT, RS256, header
  `typ` JWT, `alg` RS256, `kid` = application id; payload `iss`
  `enablebanking.com`, `aud` `api.enablebanking.com`, `iat`, `exp` (one hour;
  the API rejects more than 24 h). Signed with `openssl_sign`, so no JWT
  package (`firebase/php-jwt` and `lcobucci/jwt` are not in composer.lock;
  none added). Application id and the path to the private key come from
  `ENABLE_BANKING_APPLICATION_ID` and `ENABLE_BANKING_PRIVATE_KEY_PATH`; the
  `.pem` lives outside the repository. Endpoints: `GET /aspsps?country=SE&service=AIS`
  (`maximum_consent_validity` in seconds → days), `POST /auth` (`access.valid_until`,
  `aspsp{name,country}`, `state`, `redirect_url`, `psu_type`, `language` sv),
  `POST /sessions` (`code` → `session_id`, `accounts[uid, account_id.iban, name,
  currency, identification_hash]`, `access.valid_until`), `GET /sessions/{id}`
  (`AUTHORIZED`, `EXPIRED`, `REVOKED`, `CLOSED`, …), `DELETE /sessions/{id}`,
  `GET /accounts/{uid}/balances`, `GET /accounts/{uid}/transactions`
  (`date_from`, `transaction_status=BOOK`, `continuation_key`). Amounts are
  decimal strings with `credit_debit_indicator` CRDT/DBIT; they are read into
  signed öre without floating point. Error codes `EXPIRED_SESSION`,
  `CLOSED_SESSION`, `REVOKED_SESSION` mean the consent ended. No PSU headers
  are sent: a bank that lists `required_psu_headers` takes all or none, and a
  scheduled fetch has no user present.
- **`fake`**: `FakeBankDataProvider`, a pretend bank ("Demobanken") whose
  approval page is in NONOX itself (`/bank/testbank`). Default locally and in
  tests, refused in production. Its data is the same on every fetch, which
  makes idempotency visible in a demo.

Mastercard Open Finance can be added as a third driver behind the same
interface; nothing outside `Providers/` knows which provider is used.

### Data model (module migrations, all company-scoped)

- `bank_connections`: provider, bank, psu type, **session id encrypted**
  (`encrypted` cast, hidden, cleared when the consent ends), status ACTIVE /
  EXPIRED / REVOKED, `valid_until`, who connected and revoked, last fetch,
  last error (safe text), `reminded_at`.
- `bank_feed_accounts`: provider account id and IBAN **encrypted**, last four
  digits for display, the provider's `identification_hash` (stable across
  consents), currency, the ledger account it maps to (`account_id`, 1900–1999,
  one bank account per ledger account), how far it has been fetched.
- `bank_feed_transactions`: signed amount in öre, booking and value date,
  counterparty, message, structured reference (OCR), the bank's ids, and a
  **fingerprint** unique per account. Append-only by model and trigger; only
  the match (`voucher_row_id`, by whom, when) and the incoming payment it
  fed (`bank_transaction_id`) may change.
- `bank_feed_balances`: one reading per account, day and balance type,
  replaced by a later fetch the same day.
- `bank_transactions.bank_file_import_id` became nullable: a credit from the
  feed becomes an incoming payment there, sharing the unique reference per
  company with camt.054 files, so the same payment is never booked twice
  whichever way it arrives.

### Flow

1. Bank → Koppla en bank: choose bank and account type. `ConnectBank::start`
   checks `banking:manage` and the module, asks for at most
   `min(maximum_consent_validity, 180)` days, and puts the flow (random
   48-character state, company, user, provider, bank, expiry 15 minutes) in
   the **session**, never in the URL.
2. The bank's approval with BankID.
3. `/bank/klar` (behind `module:banking`, `throttle:banking-callback` 10 per
   minute per user): the flow is **pulled** from the session first (single
   use), then the state is compared in constant time, and company, user and
   expiry must match. Then `POST /sessions`, and the connection and its
   accounts are saved. A renewed consent for the same account
   (`identification_hash`) takes over its ledger account and fetch position,
   and the old connection is revoked.
4. The user maps each account to a ledger account. **Only mapped accounts
   are fetched** (data minimisation).
5. `bank:fetch` runs hourly and queues `FetchBankDataJob(companyId,
   connectionId)` for each active connection that is due by the company's
   setting (1–4 a day; PSD2 RTS art. 36(5)(b) lets a bank cap fetches
   without the user present at four a day). The job sets `CurrentCompany`
   and checks the module in `handle()`. A manual "Hämta nu" is limited to
   one per 15 minutes. A cache lock stops two fetches of one connection.
6. `FetchBankData`: first fetch 90 days back, later ones from five days
   before the last fetch (late bookings). Fingerprint = SHA-256 of the bank's
   transaction id (else entry reference), else of date, amount, currency,
   counterparty, message, reference and the occurrence among equal ones that
   day, so two equal card payments stay two and a refetch stores nothing new.
7. A credit in the account's currency that pays an unpaid invoice
   (`IncomingPaymentMatcher`, ADR-015) or carries an OCR number becomes an
   incoming payment with the invoice suggested, shown under Inbetalningar →
   Från banken. A person books it with `BookIncomingPayments` (series C).
8. `MatchBankTransactions` proposes, for each unmatched transaction, the
   posted row on the mapped ledger account with the same signed amount and
   the closest date within seven days, each row once. A person with
   `banking:view` and `vouchers:post` confirms (one by one or all proposals on
   the page) or removes a match.
9. Bankavstämning: when the module is on and the ledger account is mapped,
   the page shows the bank's balance for the date (exact if reported that
   day, otherwise worked out from the nearest reported balance and the
   transactions in between, and labelled so). "Använd bankens saldo" fills in
   the statement balance and marks as outstanding the rows from the feed's
   first day that no transaction booked by the date is matched to. The
   person checks and saves through the existing `BankReconciliations::save`.

**NONOX Banking never creates or posts a voucher.** Every booking goes
through the existing actions after a person confirms.

### Consent lifecycle

- `valid_until` from the provider (else what was asked for).
- `bank:remind-consent` daily at 07:10: once per consent, by the company's
  setting (default on, 14 days before), a Swedish mail to everyone with
  `banking:manage`, audited. Översikt shows "Förnya bankkopplingen till …"
  with the date, and "Koppla … igen" after it has expired.
- A fetch that gets `ConsentEnded` marks the connection EXPIRED, forgets the
  session id and audits `banking.consent_ended`; nothing fails.
- Revoking (`banking:manage`, works while the module is off too) calls the
  provider's DELETE, then marks REVOKED and forgets the session id even if the
  provider could not be reached. Fetched data stays.

### Module

Key `banking`, off by default, enabled only when the installation's driver
is configured (blocker otherwise). Permissions `banking:view` (owner, admin,
accountant, bookkeeper, auditor) and `banking:manage` (owner, admin,
accountant). Settings in `company_modules.settings`: `fetches_per_day`
(2, 1–4), `reconsent_reminders` (true), `reconsent_reminder_days` (14, 1–60).
Turning it off stops fetching and hides the pages (404); active consents are
named in the warning, and data is never deleted.

### Security

- Session ids, provider account ids and IBANs encrypted at rest and hidden
  from serialisation; none of them, nor the code or state, is logged or put
  in the audit log. Audit entries: `banking.connect_started`,
  `banking.connected`, `banking.account_mapped`, `banking.fetched`,
  `banking.fetch_failed`, `banking.consent_ended`, `banking.revoked`,
  `banking.matched`, `banking.unmatched`, `banking.reconsent_reminder`.
- Tenancy: every model uses `BelongsToCompany` and is in
  `tests/Tenancy/TenancyIsolationTest.php`; another company's connection
  answers 404.
- The callback is CSRF-safe through the session-bound single-use state, and
  rate limited.
- The fake driver is refused in production.

### Retention

Fetched transactions are what the bank reported and may be used as underlag
for a reconciliation; they are kept like räkenskapsinformation (7 years
after the end of the calendar year, BFL 7 kap.) and never deleted by the
module. Balances are readings and kept with them. A connection's personal
data (who connected) follows the company. `TODO(legal-verify)`: whether
transactions fetched through AIS are räkenskapsinformation in themselves,
and whether they must go into the archive package (`export:archive`); they
are not included yet.

## Open (TODO)

- `TODO(legal-verify)`: whether Stafe, operating the hosted NONOX and holding
  the Enable Banking contract, must register with Finansinspektionen as an
  agent of the licensed AIS provider (or needs its own registration as an
  account information service provider under lag (2010:751) om
  betaltjänster), or whether the company acts as the provider's direct
  customer. Asked in `docs/juridik/fragor-till-jurist.md`. Until answered the
  hosted service does not offer the module (no credentials configured).
- `TODO(legal-verify)`: Enable Banking as a subprocessor (data processing
  agreement, transfer outside the EU/EEA), listed in
  `docs/juridik/underbitraden.md` and `docs/gdpr/`.
- Handelsbanken business accounts need an open-banking power of attorney in
  the bank; Enable Banking's research notes call it "API Corporate" and
  mention a PSU-Corporate-ID. The Enable Banking reference does not document
  a corporate id field on `POST /auth`, so none is sent; to verify against
  the sandbox before going live. Nordea has separate brands (Nordea, Nordea
  Corporate); the bank list shows them as Enable Banking names them.
- Länsförsäkringar requires the user's personal number (and business id for
  business accounts) before authorisation; `psu_id` is not sent yet.
- Verify against Enable Banking's sandbox with a real application id: the
  redirect parameters (`code`, `state`, `error`), the exact error body
  format, and the session statuses.
- Accounts in other currencies than the company's cannot be mapped yet, so
  they are not fetched.
- The archive package does not include fetched transactions yet.

## Addendum 2026-11-06: launch hardening

Before the module is offered on a hosted installation (it is dormant until
the operator has an agreement with Enable Banking and the legal questions
above are answered), its failure modes were reviewed.

- **Kill switch.** `services.banking.paused` (`NONOX_BANKING_PAUSED`):
  `true` pauses every provider, a comma list pauses the providers named
  (`enable_banking`). The keys stay. While paused nothing is sent to the
  provider: `bank:fetch` queues nothing, `FetchBankData` answers `paused`
  without marking the connection, connecting and the bank's callback are
  refused with a Swedish message, the bank page shows the message and hides
  **Koppla en bank**, **Hämta nu** and **Koppla igen med BankID**, the
  module cannot be turned on, and no renewal reminder is sent (it goes once
  the pause ends). Revoking still works, locally only
  (`closed_at_provider: false`). `BankDataProviders::make()` refuses a
  paused provider as a last guard. Matching, reconciliation and the data
  already fetched are not affected. Parsed by `App\Support\Integrations\ProviderPause`.
- **Retryable failures.** `ProviderFailed` carries `retryable`: no answer,
  time-outs, 429 and 5xx are retryable; other 4xx are not. A failed fetch
  counts `consecutive_failures` and sets `next_attempt_at` 15 minutes,
  1, 4 and 12 hours and then 24 hours ahead; `bank:fetch` skips a
  connection until then (it hammers neither the provider nor the bank's cap
  of four fetches a day without the user present). `FetchBankDataJob` has
  three tries and releases itself for the same pause on a retryable
  failure. Fetching stays idempotent (fingerprints), so retries never store
  a transaction twice. A success resets both columns. After three failures
  in a row (`BankConnection::FAILURES_TO_TELL`) the bank page and Översikt
  ("Hämtningen från … fungerar inte") say so in Swedish.
  `TODO(provider-verify)`: whether a request that Enable Banking answers
  with 5xx still counts against the bank's daily cap.
- **Consent expiry.** `ExpireBankConsents` marks an active connection whose
  `valid_until` has passed as EXPIRED, forgets the session id and audits
  `banking.consent_ended` with `reason: valid_until`, without asking the
  provider. It runs in `bank:fetch` (hourly) and `bank:remind-consent`
  (daily), also while paused.
- **Logging.** A failed fetch is logged as `Banking: a fetch failed.` with
  company id, connection id, provider, retryable and the failure count
  only; never a session id, an account or a response body. Commands print
  company ids, not names.
- **Monitoring.** `App\Support\Integrations\IntegrationHealth`, shown in
  /hq → Integrationer and by `php artisan integrations:status` (exit code 1
  when something needs attention, `--json` for a monitoring cron): active
  connections, retrying, failing, consents ending within 14 days, the last
  successful fetch, and whether the provider is configured or paused.
  Counts only, across companies, in one query.
- Go-live checklist: `docs/status/lansering-bank.md`. Payment initiation
  stays out of scope; the options are in ADR-055 (proposed).
