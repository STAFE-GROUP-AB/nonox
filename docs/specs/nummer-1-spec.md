# NONOX: specification for the lead agent and its swarm

Version 1, 2026-11-07. Owner: Andreas Kviby (product owner).
Companion to `NONOX-agent-prompt-nummer-1.md`. If the two conflict on scope or order, this spec wins. If either conflicts with `AGENTS.md`, `AGENTS.md` wins and you stop and ask.

Stored in the repository as `docs/specs/nummer-1-spec.md` on the first run (2026-11-07). The status of each item lives in `docs/status/nummer-1.md`; file ownership in `docs/specs/ownership.md`.

---

## Part 0. How to read this spec

- **S** items are statutory reporting: S1 K3, S2 group accounts. They come first.
- **F** items are the feature gaps Swedish companies report, numbered as the product owner's list: F1, F2, F3, F4, F5, F6, F7, F8, F10, F11.
- **F9 (sustainability and CSRD data) is out of scope.** Do not build it, design for it, or mention it publicly.
- Every item has: goal, users, scope, out of scope, rules to verify, data, behaviour, UI, permissions, tests, acceptance criteria, dependencies and swarm assignment.
- Names of classes, tables and routes are **proposals**. Match the conventions you find in the code. Reuse what exists before adding anything.
- Every legal or accounting rule in this spec is a **pointer for research, not a fact**. Your researcher verifies it against the primary source before any code depends on it. Where this spec and a primary source disagree, the source wins, and you note it in the ADR.

### Build order

| Order | Item | Depends on |
|---|---|---|
| 1 | S1 K3 | none |
| 2 | S2 Group accounts | S1 gate for stage 2 |
| 3 | F3 Filing with authorities | Moms stage 1 |
| 4 | F1 Connected flows | none |
| 5 | F2 Agreements in payroll | F1 shift chain |
| 6 | F4 Cash forecast | F3 tax account helps, not required |
| 7 | F5 Numbers in plain Swedish | none |
| 8 | F6 CRM tied to the ledger | F1 lead-to-cash chain |
| 9 | F7 CRM that fills itself | F8 retention rules |
| 10 | F8 GDPR in practice | none, but must land before F7 merges |
| 11 | F10 Export and portability | none |
| 12 | F11 AI prepares, a person approves | F1, F6, F7 produce the proposals |

Items may run in parallel when their file ownership does not overlap (Part 1.4). F8 and F10 are good parallel work while S1 and S2 occupy the accounting core.

---

## Part 1. The swarm

### 1.1 Roles

| Role | Count | Does | Never does |
|---|---|---|---|
| **Lead** | 1 | Plans, assigns, owns order and file ownership, merges, reports | Writes feature code itself, except to unblock a merge |
| **Researcher** | 1–2 | Reads primary sources, writes a rule sheet with URL, quote, date read and confidence | Writes production code |
| **Architect** | 1 | Writes ADRs and data models from rule sheets, decides boundaries | Merges without Lead |
| **Builder** | 1 per item in flight | Writes failing tests, then code, in its own worktree | Touches files owned by another builder |
| **Fixture author** | 1 | Builds realistic Swedish fixture companies and expected outputs, independent of the builder | Reads the builder's implementation before fixtures are frozen |
| **Reviewer** | 1 per diff | Reviews diffs cold: correctness, security, tenancy, accessibility, N+1, GDPR, marketing law | Has seen the implementation before review |
| **Accounting checker** | 1 | Checks outputs against rule sheets and fixtures, produces the checklist for the human accountant | Marks anything legally verified |
| **Docs writer** | 1 | Updates manual, Företagsguiden, roadmap, changelog, comparison data | Publishes claims before the item's gate is green |
| **Truth auditor** | 1, always on | Runs Track A: every public claim is backed by code and a green gate | Approves its own changes |

One agent may hold several roles over time, but never builder and reviewer for the same diff, and never builder and fixture author for the same item.

### 1.2 Hand-off contracts

Each hand-off is a file in the repo. A role starts only when its input file exists and is marked `ready`.

| From | To | File | Must contain |
|---|---|---|---|
| Researcher | Architect | `docs/specs/rules/<item>.md` | Each rule: id, statement, source URL, exact quote, date read, confidence (high/medium/low), open questions |
| Architect | Builder | `docs/adr/ADR-<n>-<slug>.md` | Context, decision, options rejected, data model, rule ids it implements, test plan |
| Fixture author | Builder, Checker | `tests/Fixtures/<item>/` + `expected.json` | Input data, expected outputs, which rule ids each fixture proves |
| Builder | Reviewer | Pull request | Linked ADR, rule ids, test list, screenshots for UI, migration notes |
| Reviewer | Builder | PR review | Findings with severity, file, line, failure scenario |
| Checker | Lead, human | `docs/status/granskning/<item>.md` | Checklist per fixture, pass/fail, items needing a human accountant |
| Lead | Human | `docs/status/manskliga-beslut.md` | Question, options, recommendation, what it blocks, deadline |

### 1.3 Branches and worktrees

- One worktree per builder, one test database per worktree, `PGTZ=UTC`.
- Branch name: `spec/<item>/<package>`, for example `spec/s1/k3-notes`.
- Packages are small: one migration set, one action set, or one screen. Aim for diffs a reviewer can read in one sitting.

### 1.4 File ownership

The Lead keeps `docs/specs/ownership.md` with one row per path prefix and the item that owns it. A builder needing a file owned by another item asks the Lead, who either sequences the work or splits the file.

Shared hot spots that need the Lead's explicit approval to change:

- Voucher posting and immutability (`PostVoucher` and related).
- Tenancy (`BelongsToCompany`, Jetstream team scoping, policies).
- The audit log (`Audit::record`).
- The module framework and `Features.php`.
- `AnnualReportDocument`.

### 1.5 Definition of done (every package)

1. ADR accepted by the Lead, and by the human where Part 3 requires it.
2. Failing test written first, now green.
3. Full checks from QUICKSTART §4 green.
4. Independent review: no open finding of medium severity or higher.
5. Fixtures for the rule ids touched pass.
6. Manual and changelog updated. No public claim before the item gate.
7. `docs/status/nummer-1.md` updated with evidence.

### 1.6 Escalation

Stop the package and record a human decision when:

- A rule sheet has a low-confidence rule that the code would depend on.
- Two primary sources disagree.
- The work needs an agreement, a production key, payment, or contact with an authority or vendor.
- A change would weaken immutability, tenancy, the audit log or a test.

Then continue with the next unblocked package.

---

## Part 2. Shared rules for every item

- **Money:** integer öre or a decimal type with fixed scale, never floats. Rounding rules come from the rule sheet.
- **Dates:** store UTC, show Europe/Stockholm. Fiscal years can differ from calendar years and can be shortened or extended.
- **Immutability:** posted vouchers are never edited. Corrections are new vouchers that reference the original.
- **Tenancy:** every query is scoped to a company. Cross-company features (S2, agency views) prove in tests that a user never sees a company they are not a member of.
- **Audit:** every write that changes accounting, payroll, personal data or filing state calls `Audit::record`.
- **Language:** UI and manual in Swedish. Code, ADRs and specs in English.
- **Accessibility:** WCAG 2.1 AA for every screen, keyboard paths tested.
- **Performance:** no N+1 queries. Reports on a company with 50 000 vouchers in one year render in under 3 seconds on the reference setup, or the ADR explains why not.
- **AI:** optional, own-key or EU-hosted, never posts, sends, files or pays without a recorded human approval (F11).
- **Labels:** anything depending on a provider or not yet checked by a human accountant carries a Beta label.

---

## Part 3. Statutory reporting

### S1. K3 annual report

**Goal.** A company that applies or must apply K3 can produce a complete K3 annual report from NONOX, as PDF first, with the same figures source as K2.

**Users.** Company owner, accounting firm consultant, auditor (read access).

**Scope**

1. Framework choice per fiscal year: K2 or K3, with a change log.
2. K3 statements: income statement, balance sheet, cash flow statement where required, changes in equity where required, notes, accounting principles, management report.
3. K3 accounting areas the ADR selects, likely including:
   - component depreciation of tangible fixed assets,
   - deferred tax on temporary differences,
   - financial instruments at cost or fair value as permitted,
   - leasing, with the legal-entity exception,
   - provisions and contingent liabilities,
   - revenue recognition for service and construction contracts,
   - capitalised development costs and the related restricted reserve.
4. Transition from K2 to K3, with comparative figures and opening balance adjustments.
5. A K3 notes library with conditional notes driven by data, not free text only.

**Out of scope.** iXBRL for K3 (Track E), IFRS, listed companies, group accounts (S2).

**Rules to verify (Researcher)**

| Id | Pointer | Primary source to check |
|---|---|---|
| S1-R1 | Which companies may choose K2 and which must use K3 | BFNAR 2016:10, BFNAR 2012:1, ÅRL 1 kap 3 § |
| S1-R2 | When a cash flow statement is required | ÅRL 2 kap 1 §, BFNAR 2012:1 kap 7 |
| S1-R3 | Component depreciation requirements | BFNAR 2012:1 kap 17 |
| S1-R4 | Deferred tax, including what a legal entity may skip | BFNAR 2012:1 kap 29 |
| S1-R5 | Leasing for lessees in a legal entity | BFNAR 2012:1 kap 20 |
| S1-R6 | Financial instruments options | BFNAR 2012:1 kap 11 and 12 |
| S1-R7 | First-time application and transition | BFNAR 2012:1 kap 35 |
| S1-R8 | Required notes per company size | ÅRL 5 kap, BFNAR 2012:1 |
| S1-R9 | Larger company definition and thresholds | ÅRL 1 kap 3 § |
| S1-R10 | Capitalised development costs and fund for development expenses | ÅRL 4 kap, BFNAR 2012:1 kap 18 |

**Data (proposal)**

- `fiscal_years.framework` enum `k2|k3`, with `framework_changed_at` and reason.
- `asset_components`: asset id, name, cost, useful life, depreciation method, residual value, start date.
- `deferred_tax_items`: fiscal year, basis account, book value, tax value, temporary difference, rate, computed deferred tax, source.
- `lease_contracts`: classification, start, term, payments, legal-entity exception flag.
- `k3_notes`: note type, fiscal year, structured data JSON, generated text, override text, override reason.
- Rates and thresholds as verified data rows with source URL, quote and status, never as constants.

**Behaviour**

1. Choosing K3 for a year shows what changes and which data is missing, before anything is saved.
2. Component depreciation produces proposed vouchers per period. A person posts them.
3. Deferred tax is computed at closing as a proposal with a calculation sheet. A person posts it.
4. The cash flow statement uses the indirect method, built from ledger movements and classified accounts. Unclassified accounts block the report with a list.
5. The report generator reads one figures source shared with K2 and with Track E later.
6. Notes are generated from data. A user can override the text, and the report shows that an override exists.
7. The K2-to-K3 transition wizard produces adjusted opening balances as proposals, with a reconciliation sheet.

**UI**

- Bokslut → Regelverk: choice, consequences, missing data list.
- Anläggningar → Komponenter: per asset.
- Bokslut → Uppskjuten skatt: calculation sheet and proposed voucher.
- Årsredovisning: K3 preview with page view, note list, override markers, PDF export.

**Permissions.** Owner and admin can change framework. Accounting consultant role can prepare everything. Read-only auditor role can view and export.

**Tests**

- Unit tests for each rule id.
- Fixture companies (Fixture author):
  - F-S1-a: mid-size company, 30 employees, buildings with components, a finance lease, deferred tax.
  - F-S1-b: K2-to-K3 transition with prior-year comparatives.
  - F-S1-c: company that falls below cash-flow requirement thresholds, proving the statement is optional.
- Regression: every existing K2 fixture produces byte-identical output.

**Acceptance criteria**

- All three fixtures produce statements matching `expected.json` to the öre.
- Balance sheet balances, cash flow reconciles to change in cash.
- No K2 output changed.
- Accounting checker's checklist is ready for a human accountant.

**Swarm assignment.** Researcher 1, Architect, Builder S1-A (framework, components, leasing), Builder S1-B (deferred tax, cash flow), Builder S1-C (notes and report), Fixture author, Reviewer, Checker, Docs writer.

**Human decisions.** Approve the K3 ADR. Name a human accountant for review.

---

### S2. Group accounts

**Goal.** Owners of small groups see consolidated figures (stage 1), and groups that must or choose to prepare statutory group accounts can do so under K3 (stage 2).

**Users.** Group owner, parent company board, accounting firm.

**Stage 1: group overview (internal reporting)**

Scope:

1. Create a group: parent and subsidiaries, ownership percentage, acquisition date, voting rights.
2. Mark counterparties as group companies, so intercompany vouchers are tagged.
3. Consolidated income statement and balance sheet for any period.
4. Elimination suggestions: intercompany sales and purchases, receivables and payables, loans, interest, dividends, group contributions.
5. Every consolidated report is labelled "Intern koncernrapport, inte en koncernredovisning enligt ÅRL".

**Stage 2: statutory group accounts**

Scope:

1. Acquisition analysis at acquisition date: cost, identifiable net assets, goodwill or negative goodwill, untaxed reserves split into equity and deferred tax.
2. Goodwill amortisation over its useful life.
3. Non-controlling interests in equity and profit.
4. Full elimination of intercompany items and unrealised internal profit in inventory and fixed assets, where material.
5. Group cash flow statement where required.
6. Group notes and the group parts of the management report.
7. PDF of group accounts together with the parent company's annual report.

Out of scope for both stages: foreign subsidiaries in other currencies (record as a later ADR), associated companies with the equity method in stage 2's first release unless the ADR shows it is needed, IFRS.

**Rules to verify**

| Id | Pointer | Primary source to check |
|---|---|---|
| S2-R1 | Duty to prepare group accounts and size thresholds | ÅRL 7 kap 1–3 §§ |
| S2-R2 | Voluntary group accounts and framework | ÅRL 7 kap, BFNAR 2012:1 |
| S2-R3 | K2 cannot be used for group accounts | BFNAR 2016:10, BFNAR 2012:1 kap 1 |
| S2-R4 | Consolidation method and acquisition analysis | BFNAR 2012:1 kap 9 and 19 |
| S2-R5 | Goodwill useful life and amortisation | BFNAR 2012:1 kap 19 |
| S2-R6 | Untaxed reserves in the group | BFNAR 2012:1 kap 9, 29 |
| S2-R7 | Group contributions, accounting treatment | BFNAR 2012:1 kap 35 or the relevant chapter |
| S2-R8 | Non-controlling interests | BFNAR 2012:1 kap 9 |
| S2-R9 | Filing of group accounts with Bolagsverket | ÅRL 8 kap, Bolagsverket guidance |

**Data (proposal)**

- `groups`: name, parent company id, reporting currency.
- `group_memberships`: group, company, ownership %, voting %, acquired at, disposed at.
- `group_account_maps`: maps each company's accounts to a group chart of accounts.
- `intercompany_tags`: voucher row id, counterparty company id.
- `eliminations`: group, period, type, amount, source rows, status `suggested|accepted|manual`.
- `acquisition_analyses`: group membership id, cost, net assets, untaxed reserves, deferred tax, goodwill, useful life, approved by.
- Group-level adjustments live in a separate group ledger. They never write into a subsidiary's books.

**Behaviour**

1. A user can create a group only from companies where they hold an owner or admin role in every company.
2. Consolidation runs per period, reads each company's closed or preliminary figures, applies the account map, sums, then applies accepted eliminations and group adjustments.
3. Elimination suggestions match tagged intercompany rows across companies. Mismatches are shown with both sides and the difference.
4. Stage 2 adds acquisition analysis, goodwill schedule, non-controlling interests and the group report.
5. Consolidation is reproducible: the same inputs always give the same output, with a stored run log.

**UI**

- Koncern → Översikt: companies, ownership, status per company period.
- Koncern → Elimineringar: suggestions, mismatches, accept, manual entries.
- Koncern → Rapporter: consolidated income statement and balance sheet.
- Koncern → Förvärvsanalys (stage 2).
- Koncern → Koncernredovisning (stage 2): preview and PDF.

**Permissions.** A group view is visible only to users who are members of every company in the group, or who have an explicit group role that the ADR defines. Tests prove a group role never grants access to a single company's ledger.

**Tests**

- F-S2-a: parent and two wholly owned subsidiaries, intercompany sales, a loan with interest, a group contribution. Eliminations net to zero.
- F-S2-b: one subsidiary acquired mid-year at 80 %, goodwill, non-controlling interest.
- F-S2-c: intercompany mismatch, the report shows the difference and blocks stage 2 output.
- Tenancy tests: users with partial membership see nothing at group level.

**Acceptance criteria**

- Stage 1: F-S2-a consolidates correctly and is labelled internal.
- Stage 2: all fixtures match `expected.json`, group balance sheet balances, equity reconciliation is complete.
- Checker's checklist ready for a human accountant.

**Swarm assignment.** Researcher, Architect, Builder S2-A (group model and tenancy), Builder S2-B (consolidation and eliminations), Builder S2-C (stage 2 analysis and report), Fixture author, Reviewer with a tenancy focus, Checker, Docs writer.

**Human decisions.** Approve the stage 2 ADR. Accountant review of fixtures. Decide whether foreign subsidiaries are needed.

**Order.** Stage 1 may start in parallel with S1. Stage 2 code may merge only after the S1 gate is green.

---

## Part 4. Feature gaps

### F1. Connected flows: no figure typed twice

**Goal.** Data entered once flows through every later step, with visible status.

**Chains**

| Chain | Steps |
|---|---|
| Shift to ledger | Schedule → clock-in or approved time → staff ledger → payroll → payslip → AGI → posted salary → payment file |
| Lead to cash | Lead → deal → quote → order → invoice → Peppol or mail → payment → bank match → posted payment |
| POS to ledger | POS daily sales → daily draft voucher → bank deposit match → posted |
| Purchase to pay | Supplier invoice (Peppol, mail, scan) → approval → posting → payment file → bank match |

**Scope**

1. A chain view per object: where it is, what is next, who must act, what blocks it.
2. "Create next document from this one" for every step, carrying all data forward.
3. Back-links: every document shows its source and its successors.
4. A report "Manuella inmatningar" listing every field typed by a person that already existed elsewhere in NONOX, by screen and frequency.
5. POS: keep Zettle, research other common restaurant and retail systems' official APIs in an ADR, and build the next one the product owner picks.

**Out of scope.** Payment initiation, new POS hardware.

**Data.** A `document_links` table (source type and id, target type and id, relation) or the equivalent existing mechanism. A `chain_status` read model per chain instance.

**Behaviour**

- Status is derived from documents, never typed.
- A blocked step names the reason and the role who can fix it.
- Changing a source after a successor exists shows a warning and a proposed correction, never a silent change.

**UI.** A compact chain strip on every document page. A "Mina flöden" list filtered by role.

**Tests.** End-to-end for each chain with fixtures for a café, a shop and a tradesperson. A test that fails if a new screen asks for a value that already exists on a linked document, for the screens listed in the ADR.

**Acceptance criteria.** Each chain runs end to end in CI. The manual entries report exists and the top ten entries are removed or justified.

**Swarm.** Architect, Builder F1-A (link model and chain view), Builder F1-B (lead to cash), Builder F1-C (POS), Fixture author, Reviewer.

---

### F2. Collective agreements built into payroll

**Goal.** The agreement's rules are applied automatically from schedule to payslip, with each rule's source visible.

**Scope**

1. Rule packs per agreement and version, starting with Gröna Riks and Detaljhandelsavtalet.
2. Unsocial hours supplements, overtime, minimum wages by experience and age, holiday pay, sick pay, notice rules that affect pay, and breaks, as the rule sheet defines them.
3. Staff ledger (personalliggare) integrated with schedule and clock-in, with Skatteverket's requirements per industry.
4. Holiday calculation per Semesterlagen and the agreement's deviations.
5. Close the gaps listed in `docs/manual/sv/avtal/det-har-gor-avtal-inte-annu.md`, ordered by customer impact.
6. Operator-verified rule packs: a pack has status `draft|verified-by-operator|retired`, with who verified it and when.
7. Pension and insurance: export to Fora and Collectum after researching their official interfaces.

**Rules to verify.** Each agreement text and wage tables from the parties' official publications. Semesterlagen. Skatteverket's staff ledger rules for restaurants, construction, hair and beauty and the other covered industries.

**Data.** Rule packs as versioned data with source references per rule. Calculation traces stored with each payslip row.

**Behaviour.** Every payslip row shows which rule produced it. A changed rule pack never changes a posted payroll. A new pack version applies from its effective date.

**Tests.** F2 fixtures: a café with evening and weekend staff, a shop with Sunday and holiday work, young employees, a sick period, holiday pay at year end. Each expected payslip is computed by the fixture author from the agreement text.

**Acceptance.** All fixtures match to the öre. The gap list is shorter, with each remaining gap shown in the manual.

**Swarm.** Researcher (agreements), Architect, Builder F2-A (engine), Builder F2-B (staff ledger), Builder F2-C (Fora and Collectum), Fixture author, Reviewer, Checker.

**Human decisions.** Who signs off a rule pack as operator-verified, and the liability wording around it.

---

### F3. Direct filing with authorities

**Goal.** VAT, AGI, the tax account and customer events run without manual file uploads, within the limits of agreements the human signs.

**Scope.** Track B of the master prompt, stages 1 to 4:

1. Moms: finish end to end in the test service, boxes 06, 07, 08 and 37 mapped from primary sources.
2. AGI: upload XML, poll the check result, review link, period status, receipts. Support sole traders with staff.
3. Tax account: read balance and transactions, reconcile to account 1630, propose postings for a person to confirm.
4. Customer events: bring Skatteverket messages into Mina datum and the overview.
5. A deadline calendar per company, derived from its VAT period, employer status and fiscal year.

**Out of scope.** Production keys, real filings, passing Skatteverket content through NONOX Connect before the ADR-054 question is answered.

**Tests.** Contract tests against recorded fixtures. A test-service run log for each stage.

**Acceptance.** Each stage works in the test service, with privacy and processing documents updated.

**Swarm.** Builder F3-A (moms and AGI), Builder F3-B (tax account and events), Reviewer with a security focus, Docs writer.

**Human decisions.** Skatteverket production agreement and the Connect question.

---

### F4. Cash forecast

**Goal.** The owner sees, 13 weeks ahead, whether cash covers payroll, VAT and bills, and what drives the answer.

**Inputs**

- Bank balance (bank read when enabled, otherwise the ledger's bank accounts).
- Open customer invoices with expected payment date from each customer's history.
- Open supplier invoices by due date.
- Scheduled payroll, employer contributions and preliminary tax from payroll and AGI.
- VAT payable from the open VAT period and its due date.
- Recurring costs detected from the last 12 months, shown as suggestions the user confirms.
- Manual items and scenarios added by the user.

**Behaviour**

1. Weekly buckets, 13 weeks, with an opening and closing balance per week.
2. Each figure has a label: booked, expected or assumed.
3. A threshold set by the user, and the first week below it highlighted with its main drivers.
4. Scenarios: copy the base, change items, compare. Scenarios never write to the ledger.
5. Expected payment dates use the customer's median delay over the last 12 months, falling back to the due date.

**Data.** `forecast_items` (source type and id, date, amount, label), `forecast_scenarios`, `forecast_settings`.

**UI.** Översikt → Likviditet: a bar or line chart with the threshold line, a table of drivers, scenario switcher.

**Tests.** Fixtures with known invoices, payroll and VAT that produce a known forecast. A test that no forecast action writes a voucher.

**Acceptance.** Fixture forecasts match `expected.json`. Every number is traceable to its source item.

**Swarm.** Architect, Builder F4, Fixture author, Reviewer.

---

### F5. Numbers in plain Swedish

**Goal.** The owner understands why results changed without calling a consultant.

**Scope**

1. Period comparison: month, quarter, year to date, same period last year.
2. Short Swedish sentences for the biggest drivers of change in revenue, gross margin, personnel costs, other costs, result and cash.
3. A rule engine first: thresholds, top movers by account group, ratios such as personnel cost share of revenue.
4. Optional AI wording on top of the rule output, which may only rephrase, never add figures.
5. Each sentence links to the accounts and vouchers behind it.

**Out of scope.** Tax, legal or investment advice. Benchmarks against other companies until a data source and consent model exist.

**Behaviour.** If AI is on, the system checks that every number in the AI text exists in the rule output. Any mismatch discards the AI text and shows the rule text.

**Tests.** Fixtures with designed changes produce the expected sentences. A test injects an AI response with an invented number and proves it is discarded.

**Acceptance.** All sentences trace to account totals. The invented-number test passes.

**Swarm.** Architect, Builder F5, Fixture author, Reviewer.

---

### F6. CRM tied to the ledger

**Goal.** Sellers and owners see on each customer what is invoiced, paid, overdue and profitable.

**Scope**

1. Customer card: invoices, payment status, overdue amount, average days to pay, last payment.
2. Customer profitability: invoiced revenue minus costs tagged to the customer or its projects, per period.
3. A warning on new deals and quotes for customers with overdue invoices over a threshold.
4. Pipeline value next to expected cash in F4, as a separate labelled line.
5. Role settings decide whether sellers see amounts, profitability or only status.

**Data.** Reuse the existing customer and CRM models. Add a link from CRM contact or company to the ledger customer where it is missing.

**Tests.** CRM figures equal ledger figures for fixture customers. Role tests for each visibility level.

**Acceptance.** No difference between CRM and ledger in fixtures. Role tests pass.

**Swarm.** Builder F6, Reviewer with a permissions focus.

---

### F7. CRM that fills itself

**Goal.** Contacts, activity and company data stay current without manual entry.

**Scope**

1. Mail: log messages sent and received through the CRM mailbox on the right contact and deal, building on ADR-048.
2. Calendar: log meetings from a connected calendar after the user connects it, matched by attendee address.
3. Company data: fill organisation number, name, address, industry code and status from open sources where their terms allow. Research Bolagsverket and SCB terms in an ADR.
4. Deals with no activity for N days are flagged and appear in F11's approval inbox as a follow-up proposal.
5. Duplicate detection for contacts and companies, with a merge flow that keeps history.

**Out of scope.** Scraping sites whose terms forbid it. Buying contact lists. Reading a user's whole mailbox outside the CRM address.

**Rules to verify.** GDPR basis for storing contact activity, marketing law for follow-ups to sole traders, the data sources' terms of use.

**Behaviour.** Only metadata and the message body needed for the CRM are stored, under F8's retention rules. A user can unlink any logged item.

**Tests.** Matching, duplicates, merge, tenancy, retention.

**Acceptance.** Tests pass, processing record updated, F8 retention applies to all CRM data.

**Swarm.** Researcher (terms and law), Builder F7-A (mail and calendar), Builder F7-B (company data and duplicates), Reviewer with a GDPR focus.

**Human decisions.** Calendar and mail provider terms. Which external data sources to use.

---

### F8. GDPR in practice

**Goal.** Companies can follow GDPR without spreadsheets, while keeping accounting records as the law requires.

**Scope**

1. Retention rules per data type: CRM contacts, leads, employees, applicants, staff ledger entries, logs. Each rule has a legal basis and a period.
2. Automatic deletion or anonymisation when the period ends, with a preview list a person approves.
3. Accounting records are never deleted before the Bookkeeping Act's retention period ends. A person's data inside them is kept, and access is restricted.
4. Register extract: one action that collects a person's data across all tables and produces a readable PDF and a machine-readable JSON.
5. Access log: who opened which person's data and when, in the audit log.
6. Deletion request flow: shows what can be deleted, what must be kept and why, and records the answer sent.
7. A processing record (`registerförteckning`) generated from the configured data types.

**Rules to verify.** GDPR articles 15, 17, 30. Bokföringslagen 7 kap on retention. Staff ledger retention. Any industry rules found by the researcher.

**Data.** `retention_rules`, `retention_runs`, `subject_requests`, and a registry of every table and column holding personal data, kept in code and checked by a test.

**Tests.** A test fails if a migration adds a column that looks like personal data without registering it. Retention never touches accounting records too early. An extract fixture covers every registered table.

**Acceptance.** All tests pass. A sample extract and a sample processing record are attached to the PR.

**Swarm.** Researcher, Architect, Builder F8, Reviewer with a GDPR focus, Docs writer.

---

### F10. Export and portability

**Goal.** A customer can leave NONOX at any time with all data, in open formats, and restore it elsewhere.

**Scope**

1. Full company export: SIE4 per fiscal year, CSV per register, PDF archive of vouchers with attachments, payroll history, CRM data, and a JSON dump with a published schema.
2. Export runs as a background job, is encrypted, and is available for download for a limited time with a log entry.
3. Import into a fresh NONOX installation from the JSON dump, including attachments.
4. The manual, pricing page and terms link to the export description.

**Out of scope.** Export into a specific competitor's proprietary format.

**Tests.** Round trip: export a fixture company, import into an empty installation, compare ledgers, registers and attachment hashes.

**Acceptance.** The round trip is identical. Export of a company with 50 000 vouchers completes within the time the ADR sets.

**Swarm.** Builder F10, Reviewer with a security focus, Docs writer.

---

### F11. AI prepares, a person approves

**Goal.** Routine work is prepared automatically, and a person approves it in one place.

**Scope**

1. An approval inbox per user and role, listing proposals from every source:
   - postings and bank matches,
   - payroll deviations against the schedule and agreement,
   - payment reminders,
   - CRM follow-ups from F7,
   - eliminations from S2,
   - K3 proposals from S1 (depreciation, deferred tax).
2. Each proposal shows its source, the reason, a confidence level, and exactly what will change.
3. Actions: approve, edit then approve, reject with a reason, snooze.
4. Bulk approval only for proposals of the same type with high confidence, and never for filings or payments.
5. Learning from rejections is stored per company as rules a person can see and delete.
6. Three modes per company: no AI, own key, EU-hosted. The inbox works with rule-based proposals in every mode.

**Out of scope.** Autonomous posting, sending, filing or payment.

**Rules to verify.** EU AI Act classification for each proposal type. GDPR for any personal data sent to an AI provider.

**Data.** `proposals` (type, source, payload, confidence, status, created by, decided by, decided at), with every decision in the audit log.

**Tests.** A test proves that no proposal type can write to the ledger, send mail, file or pay without a decision record. A test proves bulk approval rejects excluded types.

**Acceptance.** All proposal sources from F1, F6, F7, S1 and S2 appear in the inbox. Tests pass.

**Swarm.** Architect, Builder F11-A (inbox and model), Builder F11-B (adapters per source), Reviewer with a security and AI focus, Docs writer.

---

## Part 5. Reporting and gates

### 5.1 Item gates

An item is done when:

1. Every package meets the definition of done.
2. Every rule id has a test and a fixture.
3. The checker's checklist exists, and items needing a human accountant are listed.
4. The truth auditor confirms which public claims are now allowed.

### 5.2 Status file

After each merged package, append to `docs/status/nummer-1.md`:

```text
Item and package:
Status: done / blocked / partial
Evidence: tests, CI run, fixtures, test-service log
Rule ids covered:
Public claim now allowed:
Public claim still not allowed:
Open legal-verify items:
Human decisions needed:
Next package:
```

### 5.3 Weekly summary to the product owner

Every week, the Lead writes `docs/status/vecka-<yyyy-ww>.md` in Swedish, at most one page:

- Done this week, with evidence.
- Blocked, and the human decision that unblocks it.
- Next week's packages.
- Risks that changed.

Report failures plainly. Do not call anything finished without evidence.

---

## Part 6. Start now

1. Copy this spec to `docs/specs/nummer-1-spec.md` and create `docs/specs/ownership.md`.
2. Verify the status table in the master prompt against the code.
3. Start the truth auditor on Track A.
4. Start Researcher on S1 rules and S2 rules in parallel.
5. Start the fixture author on F-S1-a and F-S2-a.
6. Start one builder on F8 or F10, since neither touches the accounting core.
7. Reply with: what differs from the status table, the first three S1 packages, the first S2 stage 1 package, and any blocker that needs a human answer.
