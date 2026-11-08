# ADR-066: Ask your figures (Siffror i klartext)

Status: Proposed
Date: 2026-11-08
Relates to: AGENTS.md §1.3, §4, §6.6, §11; ADR-006 (audit log), ADR-009
(cost centres and projects), ADR-010 (reports), ADR-017 (modules), ADR-018
(NONOX AI), ADR-027 (overview), ADR-063 (MCP server)

## Context

An owner looks at the income statement, sees that October was worse than
September, and wants to know why in plain Swedish: "Varför sjönk
marginalen i oktober?". Today NONOX can show every figure needed for the
answer, but the owner has to find and compare them.

What exists today:

- **Ledger reports.** `App\Domain\Accounting\Reports\LedgerReports` builds
  Saldobalans, Resultatrapport, Balansrapport, Huvudbok/Kontoanalys and
  Verifikationslista from a `LedgerQuery` (one fiscal year, a date range,
  optionally one cost centre or project, optionally without VAT
  settlements). Each report runs a fixed number of queries.
  `incomeStatement()` already takes a comparison query, and
  `previousPeriod()` builds one. The income statement groups accounts by
  `LedgerReports::INCOME_GROUPS` (3000-3999 revenue, 4000-4999 goods,
  5000-6999 external costs, 7000-7699 personnel and so on) with the
  subtotals in `INCOME_SUBTOTALS`.
- **Overview figures.** `App\Domain\Overview\Statistics` (ADR-027) reads
  revenue and costs per month this year and last, the largest customers
  (from `invoices` joined to `customers`, only for users with
  `invoices:view`) and the largest cost accounts, in seven queries.
  `App\Domain\Insights\BusinessKpis` shows key figures per permission.
- **NONOX AI.** `App\Modules\Ai\Actions\AnalyzeDocument` (ADR-018) calls the
  company's own provider (OpenAI, Anthropic or Google, through the Laravel
  AI SDK, the company's own key and model choice), checks `ai:use`, and
  writes `ai.document_analyzed` with kind, provider, model and tokens, never
  the content. `AnalyzeDocument::text()` sends text with a caller-given
  context and no chart of accounts; NONOX Avtal uses it with personal data
  masked first (`App\Modules\Agreements\Support\PersonalDataMasker`,
  `AgreementAi`). Every agent today *reads documents*; none writes prose
  about the ledger.
- **MCP server.** An external assistant can already read the reports through
  `trial_balance`, `income_statement` and friends (ADR-063), with
  `reports:view`. Whatever that assistant says about the figures is outside
  NONOX's control.
- **Limits.** `App\Support\WorkQuota` caps heavy work per company and hour.
  AI calls have no quota of their own today; the company pays its provider.
- **GDPR documents.** `docs/juridik/underbitraden.md` lists the company's
  chosen AI provider for "tolkning av kvitton och leverantörsfakturor";
  `docs/gdpr/registerforteckning.md` says the AI provider is a recipient
  only when the company chooses it.

The risk this ADR must design out: a language model asked "why did the
margin drop?" will happily invent a reason and a figure. In bookkeeping, an
invented figure that looks plausible is worse than no answer.

## Decision

### 1. The answer is computed, the language model only phrases it

The explanation has two layers, and only the second one may use AI:

1. **A figure set, computed by deterministic code from the posted ledger.**
   A new domain service `App\Domain\Insights\VarianceAnalysis` takes a
   *question* (metric, period, comparison period, breakdown) and returns a
   `FigureSet`: a list of figures, each with a stable id (`F1`, `F2` ...), a
   Swedish label, an integer value (öre, or basis points for ratios), a
   kind (`amount`, `ratio`, `count`), the period it belongs to, and a
   **drill-down** (the `LedgerQuery` parameters plus account range and
   dimension filters that reproduce it in Kontoanalys or
   Verifikationslista). Nothing in the figure set is estimated.
2. **Prose, optional.** When NONOX AI is on, the figure set goes to an agent
   (`ExplainFiguresAgent`) that writes a few Swedish sentences *about the
   figures it was handed*. When AI is off, unavailable, over quota or its
   answer fails validation, the page shows layer 1 alone: the question as
   understood, a waterfall of the change, a ranked list of drivers and the
   table, with no prose.

### 2. The decomposition

- **Metrics (v1):** revenue (Rörelsens intäkter), gross profit and gross
  margin (revenue minus 4000-4999, over revenue), operating result and
  operating margin (Rörelseresultat over revenue), result after financial
  items, and any single `INCOME_GROUPS` group. "Marginal" alone is
  ambiguous; the page asks which margin is meant (chips: bruttomarginal,
  rörelsemarginal) and remembers the choice per user. These definitions are
  presentation choices, not legal definitions; the page shows the formula.
- **Periods:** a month, a quarter, the fiscal year to date, or a free date
  range, compared with the previous period of the same length or the same
  period a year earlier. A comparison period outside any fiscal year in
  NONOX is reported as "inga jämförelsesiffror", never filled in. Periods
  across two fiscal years use one `LedgerQuery` per fiscal year.
- **Amount metrics** decompose exactly: Δ total = Σ Δ per group = Σ Δ per
  account. Integer öre, so the parts always add up.
- **Ratio metrics** decompose by group share of revenue: with margin
  m = 1 − Σ cᵍ / R, the contribution of group g is −(cᵍ₁/R₁ − cᵍ₀/R₀), and the
  contributions add up to Δm exactly before rounding. Ratios are computed in
  basis points with `RoundingMode::HalfUp` (the `Money` rules, §6.3); any
  rounding residual is shown as its own line "Avrundning", so the parts
  shown always add up to the total shown.
- **Breakdowns,** each a second step from a group or account:
  - account (always);
  - cost centre and project (ADR-009), from `voucher_rows`; rows without
    the dimension are one bucket "Utan kostnadsställe";
  - customer, for revenue: only rows on vouchers with `source = INVOICE`
    whose source invoice has a customer. Everything else (manual vouchers,
    SIE imports, daily reports) is one bucket "Utan kund", so the customer
    split still adds up to the revenue figure. Requires `invoices:view`, as
    `Statistics::topCustomers()` does today;
  - supplier, for costs: rows on vouchers with `source = SUPPLIER_INVOICE`,
    with the rest as "Utan leverantör". Requires the same rights as seeing
    payables (`SupplierInvoicePolicy::canSeePayables`).
  - **Never per employee.** Personnel costs (7000-7699) are shown per
    account and per cost centre only, never per person, whatever the
    user's rights. Payroll detail belongs in the payroll module with its
    own access rules (ADR-012, ADR-021).
- **Drivers:** the figures are ranked by absolute contribution; the top
  eight drivers are named, the rest summed as "Övrigt". The cut-off is a
  constant, not a judgement.
- **Caveats are figures too.** The figure set carries deterministic facts
  that change how the numbers should be read: unposted drafts dated in the
  period (count and sum), a month whose accrual run (`Accruals::run`) or
  depreciation has not been booked, a period not yet locked, VAT settlement
  vouchers excluded, vouchers posted after the comparison was last viewed.
  Each is shown with its own drill-down. NONOX states these; it never
  infers a cause such as "a customer left".
- **Query budget:** a fixed number of queries per question, independent of
  the number of vouchers, proven by a query-count test with a short and a
  long ledger (AGENTS.md, N+1 rule).

### 3. Understanding the question without AI

v1 does not send the owner's free text anywhere. The page has a question
field and pickers (metric, period, comparison, breakdown). A small
deterministic Swedish parser fills the pickers from the text ("oktober",
"Q3", "i år", "jämfört med förra året", "marginal", "intäkter",
"kostnader", "kostnadsställe", "kund"). What it understood is shown as
editable chips *before* anything is computed. Text it cannot parse leaves
the pickers at their defaults (the last closed month against the month
before) and says so. A later phase may let the AI turn free text into the
same structured question (§9, phase 5), with the same chip confirmation.

### 4. How the model is held to the figures

- The agent receives only the figure set, each figure with its id and
  NONOX's own sv-SE formatted value ("1 234 567 kr", "−3,2
  procentenheter"), plus the structured question.
- It must answer with structured output (the SDK's schema, as the existing
  agents do): a list of at most six sentences, each a template with
  placeholders `{F7}` and a list of the figure ids it uses.
- **NONOX, not the model, writes every number.** NONOX substitutes each
  placeholder with the figure's formatted value and makes each one a link
  to its drill-down.
- **Validation before display.** An answer is rejected, and layer 1 shown
  alone with a short note, when a sentence contains any digit outside a
  placeholder; refers to an id not in the figure set; uses a word for a
  number ("hälften", "dubbelt", "en tredjedel") from a deny-list; names a
  customer, supplier, cost centre or project that was not in the figure set;
  or exceeds the length limit. Period names are placeholders too (`{P1}`
  "oktober 2026"), so the year needs no digits in the prose.
- The prose is labelled "Formulerat av NONOX AI utifrån siffrorna nedan.
  Kontrollera mot underlaget." and shown above the table it describes,
  never instead of it.

### 5. What is sent to the AI provider

Only what the sentences need, and no free text a user typed:

- the structured question (metric, period labels, comparison, breakdown);
- the figures: ids, Swedish labels, formatted values, ranking;
- for accounts: the account number and the **BAS template name** for that
  number (`database/data/bas/2026/k1|k2|k3.json`), or the group label when
  the number is not in the template; never the company's own account name,
  which can contain anything ("Lån Anna"); NONOX shows the company's own
  name on screen;
- **customers, suppliers, cost centres and projects are pseudonymised** as
  "Kund A", "Leverantör B", "Kostnadsställe C"; the mapping stays on the
  server and NONOX puts the real names into the rendered answer;
- never voucher descriptions, invoice texts, attachments, personal
  numbers, employee data or the company's name and organisation number.

GDPR consequences:

- Pseudonymised labels and aggregated amounts are the minimum the task
  needs. For most companies the figures are business data, not personal
  data. For a sole trader (enskild firma), the company's result is
  information about an identifiable natural person even without a name.
  TODO(legal-verify): whether aggregated ledger figures of an enskild firma,
  sent without name or organisation number, are personal data under GDPR
  Art. 4(1), and whether pseudonymisation is enough for the provider to
  receive them as the company's processor.
- The AI provider is already listed as the company's chosen recipient.
  Before phase 4 ships: extend the purpose in
  `docs/juridik/underbitraden.md` ("förklaring av rapporter"),
  `docs/gdpr/registerforteckning.md`, `docs/juridik/ai.md` and the privacy
  policy, with a `LegalPageController::VERSION` bump (QUICKSTART §5).
- The feature has its own switch in the AI settings
  (`explain_figures`, default off), like `read_employee_receipts`, so
  turning NONOX AI on for receipts does not silently start sending ledger
  figures.

### 6. Tenancy and permissions

- Everything runs in `CurrentCompany` with the company's own fiscal years;
  `VarianceAnalysis` takes the company explicitly and is covered by a
  tenancy test (another company's fiscal year or cost centre id → 404).
- `reports:view` is required for the page and every figure. Customer and
  supplier breakdowns additionally need the rights listed in §2. Prose
  additionally needs `ai:use` and the module switch.
- A drill-down link opens the existing report with the same filters, so it
  passes the existing report policies; it cannot show more than the
  report would.

### 7. Cost and quota

- The company pays its own provider (ADR-018); NONOX offers no shared key.
- One question = one AI call, with a bounded prompt (at most eight drivers
  per level, two levels), so the cost per answer is small and predictable.
  The page shows the provider and model used.
- New `WorkQuota` kind `ai_explain`, per company and hour (proposed 60).
  Over the limit the page still shows the figures, without prose.
- Answers are cached for the user by a SHA-256 of the figure set and the
  model: the same figures asked again within 24 hours cost nothing, and any
  new posting changes the figure set and therefore the key.
- The figure set without prose costs no AI call at all and has no quota
  beyond normal page limits.

### 8. Audit

`Audit::record('insights.figures_explained', ...)` with the metric, period
and comparison keys, breakdown, the figure set's SHA-256, whether prose was
asked for, provider, model, input and output tokens, and the validation
outcome (`ok`, `rejected:<rule>`, `ai_off`, `quota`, `provider_error`).
Never the question text, the figures, names or the prose (ADR-006 rule:
ids, field names and enums only). Viewing the figure set without prose is
not audited, like viewing a report.

### 9. Build plan

| Phase | Content | Size |
|---|---|---|
| 1 | `VarianceAnalysis` + `FigureSet` for amount and ratio metrics per group and account, both comparison kinds, rounding residual line, caveats; unit tests that parts add up for random ledgers; query-count test; tenancy test | M (3-4 days) |
| 2 | Page "Siffror i klartext" at `/rapporter/forklara` (Swedish URL), pickers, deterministic question parser with chips, waterfall chart, driver list, drill-down links to Kontoanalys/Verifikationslista; works with AI off; manual article, changelog, landing card | M (3-4 days) |
| 3 | Breakdowns by cost centre, project, customer (`invoices:view`) and supplier; "Utan ..." buckets; tests per permission | S (2 days) |
| 4 | `ExplainFiguresAgent`, placeholder protocol, validator with fallback, pseudonymisation, BAS-name labels, `explain_figures` switch, `ai_explain` quota, cache, audit; legal documents updated and legal review before release; AI Act disclosure in the UI | M (3-4 days + review) |
| 5 | Optional: AI turns free text into the structured question (chips confirmed by the user before computing); a read-only MCP tool returning the figure set (needs an ADR-063 addendum) | S (2 days) |

## Alternatives considered

- **Let the model read the reports and answer freely** (as an external
  assistant can over MCP today). Rejected: nothing stops it from inventing
  a figure or a cause, and nothing traces its numbers to vouchers.
- **Let the model write SQL or call report tools in a loop.** Rejected:
  unbounded queries and cost, a much larger prompt-injection surface, and
  numbers still come from the model's arithmetic.
- **Let the model write numbers and check them afterwards against the
  figure set.** Rejected in favour of placeholders: matching free-form
  numbers ("ca 1,2 miljoner", "drygt 30 %") is fragile; if the model never
  writes a number there is nothing to match.
- **Template prose without AI for everyone.** Kept as a fallback idea but
  not in v1: the driver list already says what a template sentence would,
  and rigid sentences read worse than the table.
- **Send real customer names.** Rejected: names are not needed to phrase
  the answer, and a customer can be a natural person.

## Consequences

- A question is answerable with AI switched off; AI only adds wording.
- Every number on the page links to the vouchers behind it.
- A second kind of content (ledger figures, not documents) goes to the AI
  provider, behind its own switch, with legal documents updated first.
- Answers depend on how the company books: customer and supplier
  breakdowns are only as complete as the invoice-linked vouchers, and the
  "Utan kund" bucket makes that visible rather than hiding it.
- `INCOME_GROUPS` becomes a shared contract between the income statement
  and the explanations; changing it changes both.

## Open questions

1. TODO(legal-verify): whether aggregated, pseudonymised figures of an
   enskild firma are personal data, and what that means for sending them
   to a provider outside the EU/EES (GDPR chapter V).
2. TODO(legal-verify): whether the question field makes this an AI system
   "intended to interact directly with natural persons" under AI Act
   Art. 50(1) (ADR-018 concluded there was no conversational interface),
   and whether the generated sentences need marking under Art. 50(2). The
   design labels the prose as AI-written either way.
3. TODO(legal-verify): whether an AI-written explanation shown to an
   accounting firm's client can be read as advice the firm is responsible
   for, and whether the page needs a statement that it is not advice.
4. Owner decision: the default metric for "marginal" (bruttomarginal or
   rörelsemarginal) and the default comparison (previous month or same
   month last year).
5. Owner decision: the `ai_explain` quota (60 per hour proposed) and the
   cache time (24 hours proposed).
6. Should cached prose be kept as history the user can return to (a
   company-scoped table with a retention period), or only cached? v1
   proposes cache only.
7. Should accounting firms (ADR-028) get the figure set across clients in
   the firm workspace? Not in scope; it would run per company and never
   mix companies in one prompt.
