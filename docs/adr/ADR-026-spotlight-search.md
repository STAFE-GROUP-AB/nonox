# ADR-026: Spotlight search

- Status: Accepted
- Date: 2026-11-01

## Context

The owner asked for one search that reaches everything in the system and is
better than any other program's: "Sök eller gå till … ⌘K" in the top bar.
Records live in many tables, some in optional modules, and every user sees a
different part of them by role (§4) and by company (§1.3). Some fields are
personal data that must never be searched or shown (personal numbers,
encrypted at rest).

## Decisions

### Shape

- **Providers.** `App\Support\Search\Contracts\SearchProvider` is one kind of
  thing that can be found. It authorises the user (`authorize()`), answers a
  parsed query with at most five hits and a capped count (`search()`), looks
  up one record for "Senast öppnade" (`find()`), offers "Att göra" shortcuts
  (`todo()`) and names its detail routes (`detailRoutes()`).
  `SearchRegistry` holds them all; `for($context)` returns those whose module
  is on for the company and that authorise the user.
- **Core providers** (`app/Support/Search/Providers`): pages (the navigation
  tree via `Navigation::pages()`, plus profile, company settings and the
  reports by name), create actions (`SearchActions` registry), the user's
  other companies, vouchers, customer invoices, supplier invoices, quotes,
  orders, accounts, customers, suppliers, articles, fixed assets, accruals,
  projects and cost centres.
- **Module providers** are registered by the module's own service provider
  with `callAfterResolving(SearchRegistry::class, …)` and return the module
  key from `module()`: Lön (employees, payslips, actions "Ny lönekörning",
  "Lägg till anställd"), Personalliggare (people), Inkorg (messages). A module
  that is off finds nothing. The `Module` contract is unchanged, so modules
  in progress elsewhere are not affected; a new module adds a provider class
  and two lines in its service provider.
- **Parsing.** `QueryParser` turns the field into a `SearchQuery`: a type word
  first ("faktura", "lev", "konto", "kund", "ver", "lön", …), a status
  ("obetald", "förfallna", "betalda", "utkast", "att attestera"), an amount
  ("8 750,00", "8750 kr", "> 10000 kr", "över 5 000", "1000-2000 kr"), a date
  ("2026-10-14", "14 okt", "14/10", "oktober", "okt 2025"; a month without a
  year is the latest one that has begun), a voucher reference ("A47",
  "A 47"), a bare number (also read as kronor, at a lower rank). Each filter
  is a chip that knows the words it came from. A provider that cannot answer
  a filter (an amount on customers) is not asked.
- **Ranking.** Exact number or identifier 1000, organisation number 950,
  personal-number suffix 900, exact name 800, prefix 600, word prefix 500,
  contains 400, the bare number read as an amount 300, trigram similarity
  100–300; ties go to the most recent record. Groups keep a fixed order
  except that a group with an exact hit moves to the top in the browser.
- **Streaming.** `POST /sok` returns newline-delimited JSON: a `meta` line
  (the chips), one `group` line per provider as soon as it has answered
  (in-memory providers first, then the kinds the user asked for), and a
  `done` line with totals and milliseconds. The browser shows groups as they
  arrive and keeps the previous results until the first new group, so the
  list does not blink. Typing is debounced 120 ms and a new keystroke aborts
  the request in flight.
- **Indexes.** `pg_trgm` and `btree_gin` (both trusted contrib extensions)
  with GIN indexes `(company_id, column gin_trgm_ops)` on the searched text
  columns, and btree indexes for numbers, OCR, amounts and dates
  (`2026_11_06_110000_add_search_indexes` and one migration per module).
  Built `CONCURRENTLY` outside a transaction. Text is matched with
  `ILIKE '%…%'` (all words must match) or pg_trgm word similarity (`word <% column`) for
  words of four characters or more, which forgives a typo.

### Tenancy and permissions

- Every provider runs inside `CurrentCompany::run($company)` (CompanyScope)
  and also filters `company_id` explicitly. Every provider authorises the
  user with the existing policies (`viewAny`) or the same permission its
  list page asks for; actions carry their permission; module providers
  require the module to be on.
- "Mina företag" lists only `User::companies()`; choosing one posts to the
  existing `PUT /aktuellt-foretag`, which checks membership again.
- Recent items store type and id only from the client; the title is looked
  up through the provider, inside the company and with its checks, so a
  foreign id is never remembered. They are listed back only for providers
  the user may still use.
- Tests: `tests/Tenancy/SpotlightTenancyTest.php` (company B is never found by
  name, number, OCR, amount, organisation number, date or personal-number
  digits; foreign ids are not remembered; foreign companies are not offered)
  and `RecentItem` in the tenancy isolation suite.

### Personal data

- Personal numbers are never searched in SQL, indexed or shown. A user with
  `payroll:view-sensitive` (employees) or `staff-ledger:view-sensitive`
  (staff ledger) who types exactly four digits gets the people whose number
  ends with them; the numbers of that company's people are decrypted in
  memory for the comparison (at most 5 000) and the result only says
  "Personnummer slutar på 1234". Others get no such match.
- The query is sent in a POST body, never in a URL, so it stays out of web
  server access logs; it is not logged by the application. The encrypted mail
  text of the inbox is not searched.
- `recent_items` keeps titles (which may be names) per user and company, at
  most 50 rows; they go when the user or the company is deleted.

### Recent and suggestions

- `recent_items` (company, user, type, record id, title snapshot, url, icon,
  opened_at). Recorded when a result is opened from the spotlight
  (`POST /sok/senaste`) and when a detail page named by a provider's
  `detailRoutes()` is visited (`RemembersRecentItems`, after the response).
  Listing costs one query.
- "Att göra": drafts to post, overdue customer invoices, supplier invoices to
  approve and to pay, payroll requests, inbox messages to review; one count
  query each, only for those with the permission.

### Performance

Measured on `nonox_search`: two companies with 100 000 posted vouchers each
(200 000 voucher rows each), 5 000 customers and 20 000 invoices in the
searched company, PostgreSQL 17 on an M-series Mac. See the numbers below.

Server time for the whole search (every provider, in process, nine runs,
median / slowest), and the number of SQL statements:

| Typed | Median | Max | SQL | What it found |
|---|---|---|---|---|
| `8 750,00` | 3.2 ms | 3.9 ms | 8 | vouchers, invoices, supplier invoices with that amount |
| `> 10000 kr` | 4.2 ms | 4.8 ms | 10 | 99+ vouchers, 99+ invoices |
| `oktober` | 5.0 ms | 5.6 ms | 11 | vouchers, invoices, supplier invoices in October |
| `obetald` | 3.8 ms | 4.2 ms | 8 | unpaid customer and supplier invoices |
| `faktura 1029` | 0.9 ms | 1.1 ms | 3 | the invoice |
| `konto 1930` | 0.8 ms | 0.9 ms | 3 | the account |
| `50047` (voucher number, also an amount) | 28 ms | 33 ms | 19 | the voucher, an invoice |
| `elverket` / `elverkte` (typo) | 31 ms | 33 ms | 20 | vouchers, invoices, supplier invoices, customer, supplier |
| `restaurang norr` | 50 ms | 51 ms | 21 | 78 customers, 99+ invoices |
| `försäljning` (in 12 500 voucher texts) | 81 ms | 83 ms | 22 | pages, 99+ vouchers, accounts |

- Every query is under the 150 ms target; the worst case is a word that
  occurs in one voucher text of eight, where PostgreSQL rightly prefers a
  parallel sequential scan (46 ms) over the trigram index. Rare words, names,
  numbers, amounts and dates use the indexes (`EXPLAIN ANALYZE`: bitmap index
  scans on `voucher_rows_search_debit/credit`, `vouchers_company_id_voucher_date_index`,
  `invoices_company_id_number_unique`).
- The measurement found that `voucher_rows` had no index on `voucher_id`:
  every voucher's total (`withSum`) scanned the company's rows, 8 ms per
  voucher. `voucher_rows_search_voucher` fixes it (search: "> 10000 kr" 95 →
  4 ms) and helps every page that sums a voucher.
- About 14 ms and 15 statements of every search are fixed costs: the pages of
  the navigation tree (with their counters), permissions and module state.
  The count is the same however many records match
  (`it keeps the number of queries independent of the number of records`).
- In the browser the first group is drawn before the slowest provider has
  answered.

## Consequences

- PostgreSQL needs the contrib extensions `pg_trgm` and `btree_gin`
  (standard in every distribution's PostgreSQL package and on managed
  services). Self-hosting docs: install `postgresql-contrib` where it is a
  separate package.
- A new searchable kind is one provider class; nothing in the dialog
  changes. Register pages for "Gå till" by adding them to the navigation tree
  and create actions to `SearchActions`.
- Lists that had no detail page open from search through `?redigera=<id>`
  and `?ny=1` (`OpensFormFromLink`): customers, suppliers, articles,
  employees and staff-ledger people.
