# ADR-059: K3 annual report (BFNAR 2012:1)

- Status: Proposed
- Date: 2026-11-06
- Task: Track F.1 start (research and decision; nothing built yet)
- Builds on: ADR-010 (reports, year-end, the K2 annual report, K-regelverk per
  legal form, "Annual report amounts in whole kronor"), ADR-058 (digital
  annual report, K2 iXBRL), ADR-056 (tax in the closing), AGENTS.md §1.2
  (immutability), §6.4, §7 Phase 3

Enligt AI-utredning 2026-11-06, juristgranskning återstår. Every rule below is
quoted from a primary source that was read; anything not read is marked
`TODO(legal-verify)` or `TODO(provider-verify)`.

## Context

`companies.reporting_framework` can be `K1`, `K2` or `K3`
(`App\Domain\Company\ReportingFramework`). `ReportingFrameworkRules` refuses
K1 for most legal forms and shows size conditions for K1 and K2; "K3 can
always be used" and needs no confirmation (ADR-010).

But NONOX only builds a **K2** annual report. `AnnualReportDocument::supports()`
checks the legal form (AB, ekonomisk förening), never the framework, and the
controller, the page and the iXBRL writer do the same. A company that has
chosen K3 today gets a document whose note 1 says "Årsredovisningen har
upprättats i enlighet med årsredovisningslagen och Bokföringsnämndens
allmänna råd (BFNAR 2016:10) om årsredovisning i mindre företag"
(`resources/views/annual-report/document.blade.php`), and an iXBRL file tagged
with the K2 taxonomy. That statement is false for that company, and K3 1.6
forbids the opposite claim unless K3 is applied in full (below). This ADR fixes
that first (F.1) and then plans a real K3 report.

Why now: from fiscal years beginning after 2025-12-31, BFNAR 2025:2 closes K2 to
several kinds of small company (K2 1.1A–1.1C, below). Real-estate companies
and companies with a material deferred tax liability, which until now could use
K2, must move to K3. Calendar-year companies hit this with the 2026 accounts,
filed in 2027.

## Sources read (2026-11-06)

Downloaded files were kept in their own directories in the session scratchpad
and only read as text (`pdftotext`); nothing in them was executed.

### BFN

- **K3: Vägledning "Årsredovisning och koncernredovisning (K3)", Vägledning
  till BFNAR 2012:1, "Uppdaterad 2025-12-15"**,
  https://www.bfn.se/wp-content/uploads/vl12-1-k3-kons20251215.pdf (358 pages;
  the consolidated text includes BFNAR 2025:3 and 2025:4).
- **Which version applies** (https://www.bfn.se/redovisningsregler/vagledningar/k-regelverk/,
  page updated 2026-09-07): K3 `vl12-1-k3-kons2024.pdf` for fiscal years begun
  2024-07-01 – 2025-12-31; `vl12-1-k3-kons20251215.pdf` for fiscal years begun
  after 2025-12-31. Same split for K2 (`vl16-10-k2ar-kons2024.pdf`,
  `vl16-10-k2ar-kons2025.pdf`). No K2-vs-K3 comparison document is published
  there; there is a table "Information om vad som gäller för olika företag vid
  upprättande av årsbokslut, årsredovisning och koncernredovisning" (not read).
- **K2: Vägledning BFNAR 2016:10, kons. 2025**,
  https://www.bfn.se/wp-content/uploads/vl16-10-k2ar-kons2025.pdf (413 pages),
  for the comparison and for 1.1–1.1C.
- **Transition rules in the K3 text** ("Ikraftträdande- och
  övergångsbestämmelser", p. 326): BFNAR 2025:3 "gäller från och med den 16 juni
  2025 och ska tillämpas första gången för räkenskapsår som inleds efter den 31
  december 2025" (newly started companies with an extended year that ends
  2026-12-31 or later: already from years begun after 2025-06-30). Point 2: a
  company that leaves K2 because of BFNAR 2025:2 "får tillämpa bestämmelserna i
  kapitel 35 utan den begränsning som följer av punkt 35.1". Point 3 (BFNAR
  2025:4) concerns share-based payments only. Point 3 was the subject of BFN's
  remiss Dnr 2025:107 of 2025-11-20
  (https://www.bfn.se/wp-content/uploads/remissbrev-forslag-overgangsbest-k3-2025.pdf,
  read); the consolidated text of 2025-12-15 contains it as decided.
- **Announced work, not in force:** BFN procurement Dnr 2026:90 (2026-06-03) for
  a consultant to evaluate and possibly update K3 chapters 11 and 12 (financial
  instruments), "upphandlingsunderlag-konsultuppdrag-dnr-2026-90-kapitel-11-och-12-i-k3.pdf"
  on bfn.se (found by search, **not read**). FAR Online reports changes to
  BFNAR 2017:3 (årsbokslut) decided in June 2026 (secondary, not read). There is
  **no "K3 2026" in force** beyond BFNAR 2025:3/2025:4; NONOX targets the
  2025-12-15 consolidation for fiscal years begun after 2025-12-31.
  `TODO(legal-verify)`: re-check bfn.se "Beslutade redovisningsregler" before
  each F stage.

### The law

- **ÅRL (1995:1554)**, riksdagen.se, "ändrad t.o.m. SFS 2026:780",
  https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/arsredovisningslag-19951554_sfs-1995-1554/:
  - 1 kap. 3 § första stycket 4: a **större företag** is one whose securities are
    traded on a regulated market, or that meets more than one of: "medelantalet
    anställda i företaget har under vart och ett av de två senaste
    räkenskapsåren uppgått till mer än 50", balance sheet total "mer än 40
    miljoner kronor", net turnover "mer än 80 miljoner kronor" (each for both of
    the two latest years). Every other company is a mindre företag (punkt 5).
  - 2 kap. 1 §: "I årsredovisningen för ett större företag eller en
    bostadsrättsförening ska det även ingå en kassaflödesanalys."
  - 5 kap. 1 §: "Mindre och större företag ska i noter lämna upplysningar enligt
    4-24 §§."
  - 3 kap. 5 § fjärde stycket (quoted in K3 ch. 35): comparatives need not be
    restated after a change of principles by a mindre företag "under
    förutsättning att upplysningar om den bristande jämförbarheten lämnas i
    noterna".

### The taxonomy (taxonomier.se)

- Overview https://www.taxonomier.se/taxonomier-info-final.html: "Årsredovisning
  för aktiebolag enligt K3-regelverket" 2021-10-31 and 2020-12-01, published;
  "Årsredovisning K3, Övriga företagsformer": status "okänd" ("Ingen planerad
  aktivitet"), so **there is no K3 taxonomy for ekonomiska föreningar**.
  Koncernredovisning K3 exists separately (2021-10-31).
- **K3 AB 2021-10-31** (https://www.taxonomier.se/taxonomier-k3-2021.html):
  "2021-10-31 / FINAL"; revisions 2021-12-10, 2022-05-24, 2023-01-31,
  2023-06-01, 2024-09-12, **2024-11-20** ("fem nya begrepp"), **2026-09-09**
  (changes in the K2 rules and RedR 1, BAS 2026). Nine entry points, among
  them:
  - `risbs` (kostnadsslagsindelad resultaträkning, full balance sheet):
    `http://xbrl.taxonomier.se/se/fr/gaap/k3/risbs/2021-10-31/se-k3-risbs-2021-10-31.xsd`
  - `rfibs` (funktionsindelad): `…/gaap/k3/rfibs/2021-10-31/se-k3-rfibs-2021-10-31.xsd`
  - `risab`, `raibs`, `raiab`, `rfiab`, `rafbs`, `rafab`, `full`.
  Note the path: K3 is `gaap/k3/…`, K2 is `gaap/k2-all/ab/…` (ADR-058).
- Checked in the package already downloaded for ADR-058
  (`taxonomi-paket-2026-08-31.zip`, SHA-256 `47e56bca…e559`): `risbs`
  2021-10-31 imports the forms `fcd`, `fdr`, `fscie`, `fisbn`, `fbs`, **`fcf`
  (kassaflödesanalys)**, `fdisc` (notes) and `fsign`. `fbs` holds
  `UppskjutenSkatteskuld`, `UppskjutenSkattefordran`,
  `BalanseradeUtgifterUtvecklingsarbetenLiknandeArbeten`, `FondUtvecklingsutgifter`,
  `PagaendeArbetenAnnansRakning…`, and, like K2, only the separate
  `MaskinerAndraTekniskaAnlaggningar` and `InventarierVerktygInstallationer`
  (no combined line). `fdisc` holds `NotUppskjutenSkatt`, a set of
  `Redovisningsprinciper…` concepts and `NotFinansiellaLeasingavtalLeasetagare`.
  The BAS 2026 reference linkbase (`se-gen-base-rbas2026-2021-10-31-ref-bas.xml`)
  ties `UppskjutenSkatteskuld` to BAS **224x**, `UppskjutenSkattefordran` to
  **137x** and `SkattAretsResultat` to **891x–894x**.
- Which version Bolagsverket accepts for which fiscal year:
  `TODO(provider-verify)` (ADR-058's unread "Gällande servicespecifikationer").

## 1. What differs between K2 (aktiebolag) and K3 for a small NONOX customer

"Juridisk person" below means the company's own annual report (not a group
report). K3 is written for the group report and has special rules for the
juridisk person; these are what a NONOX customer uses.

### 1.0 K3 is all or nothing

- K3 1.4: "Detta allmänna råd ska tillämpas i sin helhet." 1.6: "Tillämpas
  detta allmänna råd inte i sin helhet får företaget inte ange att den
  finansiella rapporten är upprättad enligt det allmänna rådet." 8.3: the notes
  must say that the company applies ÅRL and K3.
- Consequence for NONOX: a K3 report may not be produced by printing K2 figures
  under a K3 heading. Each valuation difference below must either be handled
  by data in NONOX or be confirmed by the user as "not applicable / handled
  outside NONOX", and the report is refused while a required confirmation is
  missing.

### 1.1 Valuation (needs bookkeeping, not only presentation)

| Area | K2 (BFNAR 2016:10, kons. 2025) | K3 (BFNAR 2012:1, kons. 2025-12-15), juridisk person |
|---|---|---|
| **Components** | 10.21 "Varje anläggningstillgång ska skrivas av för sig"; 10.22: parts are written off together, except "Mark, markinventarier respektive övriga markanläggningar, byggnad, byggnadsinventarier och tillkommande utgifter" are separate units. | 17.4: "Förväntas skillnaden i förbrukningen av en materiell anläggningstillgångs betydande komponenter vara väsentlig, ska tillgången delas upp på dessa." Comment: a building's "stomme, stammar, fasad, tak"; land is separated. 17.13: each component is depreciated separately. 17.5: replacing a component is capitalised. No juridisk-person exception. |
| **Small assets** | (K2 rules not compared here) | 17.24: in juridisk person, assets "av mindre värde" or with a life of "högst tre år" may be expensed if the same deduction is made under IL. |
| **Deferred tax** | Chapter 1 comment: K2 contains a "förbud mot att redovisa uppskjuten skatt"; 16.4 forbids an avsättning for it; 11.5 forbids a deferred tax asset. | 29.19: recognise "en uppskjuten skatteskuld för alla skattepliktiga temporära skillnader", a deferred tax asset for deductible differences and for "skattemässiga underskottsavdrag", "i egna poster i balansräkningen". 29.37: in juridisk person the deferred tax on **obeskattade reserver** "ska … inte särredovisas"; untaxed reserves stay gross. |
| **Leasing** | "Ett leasingavtal redovisas alltid som ett operationellt leasingavtal." | 20.3 classifies financial vs operational; **20.29: "I juridisk person får alla finansiella leasingavtal redovisas som operationella leasingavtal."** |
| **Internally developed intangibles** | 10.4: "En egenupparbetad immateriell anläggningstillgång får inte redovisas som tillgång"; expensed. | 18.7: choose the kostnadsföringsmodellen or the aktiveringsmodellen, consistently for all; 18.8 expensing; 18.9–18.12 capitalising development (not research). 18.30: the juridisk person may expense even if the group capitalises. |
| **Financial instruments** | (K2 ch. 11, acquisition cost) | Ch. 11 (acquisition cost) or ch. 12 (fair value per ÅRL 4 kap. 14 a–14 e §§); 11.3 allows a change from 11 to 12. Ch. 11/12 are under review (Dnr 2026:90, not in force). |
| **Provisions** | K2 20.2 f lists "Avsättning för ett informellt åtagande" among items that may not be recognised. | 21.4–21.6: a provision for a legal **or informal** obligation; 21.9: "till nuvärdet av de framtida betalningar"; 21.12 re-assessed every balance date; 21.21 a juridisk-person relief for restoration costs (not read in full). |
| **Fixed-price contracts** | 6.15: a huvudregel (in step with completion) or an alternativregel, the same method for all; switching from the main rule only "om det finns särskilda skäl". | 23.18: **successiv vinstavräkning** when the outcome can be estimated reliably. 23.31: in juridisk person, for "pågående arbeten i branscher som avses i 17 kap. 23 § inkomstskattelagen", the income may be recognised "senast när arbetet väsentligen är fullgjort (färdigställandemetoden)". 23.17: time-and-material income "i takt med att arbete utförs", "faktureringstidpunkten [styr] inte". |
| **Borrowing costs** | (not compared) | 25.3: expensed, unless the company chooses to include them in the acquisition cost (ÅRL 4 kap. 3 § fjärde stycket); 25.7 juridisk person may expense even if the group capitalises. |
| **First application** | — | Ch. 35: an **ingångsbalansräkning** at the "tidpunkt för övergång" (35.5–35.7) with retroactive application (35.8); differences "redovisas direkt mot eget kapital i ingångsbalansräkningen" (35.11). For a mindre företag that does not restate comparatives, the transition date is "första dagen på det räkenskapsår som detta allmänna råd tillämpas" (35.7). |

### 1.2 Presentation (generated from the books)

- **Kassaflödesanalys.** ÅRL 2 kap. 1 §: only större företag (and
  bostadsrättsföreningar). K3 1.4 comment: "endast större företag och
  bostadsrättsföreningar … ska presentera en kassaflödesanalys. Andra företag
  behöver därför inte tillämpa kapitel 7"; 3.10 comment: it "behöver däremot
  inte ingå" for others. **K3 does not require it of a mindre företag.**
  (K2 ch. 21 allows a voluntary one.) The K3 taxonomy has the `fcf` form.
- **Order.** 3.10: "förvaltningsberättelse, resultaträkning, balansräkning,
  eventuell kassaflödesanalys och noter"; an equity statement in its own
  report goes after the balance sheet.
- **Equity statement.** ÅRL 6 kap. 2 § andra stycket (as K2). K3 6.3: show the
  change "i varje post i eget kapital" and separately the year's result,
  "Effekterna av retroaktiv tillämpning och effekten av rättelse av fel",
  changes recognised directly in equity "med varje slag av förändring separat
  specificerad", transactions with owners "med tillskott, utdelningar och andra
  värdeöverföringar separat angivna", and "Omföringar mellan poster inom eget
  kapital". This is stricter than NONOX's residual "Övriga förändringar" row
  (ADR-010 #90).
- **Multi-year overview.** K3 has no fixed table for a mindre företag: 3.7
  comment on ÅRL 6 kap. 1 §: the overview "bör … utformas så att utvecklingen
  över en längre tid (normalt fyra till fem år) framgår", "i verbal form eller
  med nyckeltal". 35.3A: a first-time adopter that restates the overview says so.
  NONOX's existing four-year table can be reused.
- **General information.** 3.6: former name, whether it is a group or a single
  company, balance date and year, "Redovisningsvaluta", "Den mätenhet med vilken
  beloppen är angivna" (BFNAR 2025:3).

### 1.3 Notes

- 8.2 order: "Redovisningsprinciper och värderingsprinciper", "Uppskattningar
  och bedömningar", notes to items (ÅRL 5 kap. 3 §), "Övriga noter".
- 8.3: state that ÅRL and K3 are applied, and the chosen principles relevant to
  understanding the report; the comment lists, among others, intäktsredovisning,
  anläggningstillgångar, aktivering av låneutgifter, internt upparbetade
  immateriella tillgångar, pågående arbeten, finansiella instrument, leasing,
  varulager, "skatter, inklusive uppskjuten skatt", avsättningar, foreign
  currency (8.3A), offentliga bidrag.
- 8.4/8.5 (judgements, sources of estimation uncertainty) and 3.5
  (comparatives in all notes) apply to **större företag** only. Many chapters
  add notes only for större företag (e.g. 20.12/20.14 leasing, 21.16–21.18
  provisions, 23.30 pågående uppdrag). 23.29 (revenue) applies to all
  (content not read in full).
- Deferred tax note: 29.37 comment: "Upplysning enligt 5 kap. 36 § ÅRL behöver
  inte lämnas för uppskjutna skatteförpliktelser som är hänförliga till
  obeskattade reserver."
- `TODO(legal-verify)`: the complete list of notes required of a mindre
  företag under ÅRL 5 kap. 4–24 §§ and K3 chapters, compared with what NONOX's
  K2 report prints; K3 ch. 8 was read only through 8.5 and the headings.

### 1.4 Förvaltningsberättelse

K3 3.7 requires information on "verksamhetens art och inriktning", "viktiga
förändringar i verksamheten", "viktiga externa faktorer", "speciella
omständigheter", "ägare som har mer än tio procent av antalet andelar eller
rösterna", "komplementär i kommanditbolag" and "väsentliga förändringar i
ägarstrukturen". 3.9 and others apply to större företag (R&D, environment).
3.14: the disposition proposal concerns the sum of free equity (as today).
New input fields: owners over 10 %, changes in ownership, external factors.

## 2. Scope by size

| Company | Rule | NONOX |
|---|---|---|
| Större företag (ÅRL 1 kap. 3 § p. 4), parent of a större koncern, publikt AB | Must use K3 (K3 1.3 comment: "Följande företag ska alltid tillämpa det allmänna rådet"). | **Refused in F.** Needs kassaflödesanalys, 3.5 comparatives in every note, 8.4/8.5, the större-företag notes and in practice a mandatory auditor. Later stage, own ADR. |
| Mindre företag closed out of K2 by 1.1A (publikt AB, parent of a större koncern, parent of a mindre koncern **that prepares group accounts**, natural persons, bostadsrättsföreningar, foreign branches, share-based payments, convertibles and similar, crypto assets) | Must use K3 (or K1/other where relevant). | AB without group accounts: **in scope**. Group accounts (koncernredovisning, `k3k` taxonomy): out of scope. |
| Mindre företag closed out of K2 by **1.1B** (a material deferred tax liability; buildings generating ≥ 75 % of net turnover), unless 1.1C applies (at most one of: > 3 employees, balance sheet > 1,5 MSEK, net turnover > 3 MSEK, each for both of the last two years; or K2 used last year and "normalt inte omfattas") | Must use K3 from fiscal years begun after 2025-12-31 (BFNAR 2025:2 point 1). | **Primary target.** Typical: a small property company (fastighetsbolag) and a company whose assets are worth more in the books than for tax. |
| Mindre företag that may use K2 but chooses K3 | May choose (K3 1.3: applies to all companies under ÅRL unless they chose K2). | **In scope.** |
| Ekonomisk förening on K3 | May/must as above. | PDF only, later; **no iXBRL** (no K3 taxonomy for "Övriga företagsformer"). Refused in F.2–F.4. |

**Decision.** F supports **mindre aktiebolag, årsredovisning in the juridisk
person, no group accounts, kostnadsslagsindelad income statement, full
balance sheet**, with the juridisk-person reliefs taken where K3 offers them
(20.29 leasing as operational, 18.30 expense development, 25.7 expense
borrowing costs, 29.37 untaxed reserves gross, 23.31 färdigställandemetoden
where IL allows). Reason: these reliefs are exactly what keeps the K3 books of
a small company close to the tax-driven books NONOX already keeps, so most
differences become presentation plus three bookkeeping areas (components,
deferred tax, the transition adjustment). Större företag are refused with a
Swedish message naming the reason, as ADR-058 refuses ESEF companies.

Choices the company makes are stored as **K3 accounting policies** per company
(data, below), and a choice NONOX does not support yet (aktiveringsmodellen,
capitalised borrowing costs, financial leasing in the balance sheet, ch. 12
fair value, successiv vinstavräkning computed by NONOX) refuses the report
until supported, rather than being silently ignored (1.4/1.6).

Also found, outside K3 but in the same code: `ReportingFrameworkRules::conditions()`
for K2 lists only the size and publikt-AB/större-koncern conditions; since
BFNAR 2025:2 the user is confirming an incomplete list (1.1A c–i, 1.1B, 1.1C
are missing). Fixed in F.1.

## 3. Model changes without breaking immutability

Rule for all of F: **nothing posted is changed.** Every K3 difference that
changes the books becomes a *proposal* a person with `vouchers:post` reviews
and posts as a new voucher through `PostVoucher`, like ADR-056's tax proposal
and the existing `FixedAssetProposal`. Presentation never writes.

### 3.1 Presentation only (from the books and `annual_reports`)

- K3 report document: own `K3Schedule` (BAS ranges, adding 137x deferred tax
  asset and 224x deferred tax liability as their own lines, verified against
  the rbas2026 linkbase), own Blade view, own note set, own `K3Taxonomy` map.
- Accounting-principles note from the stored policies (3.3) and the fixed asset
  register (components and lives).
- Equity statement per K3 6.3: rows for transition/retroactive effects,
  shareholder contributions, dividends, and transfers between equity items,
  read from voucher sources (`CLOSING`, `DISPOSITION`, the transition voucher)
  and accounts; a residual left after that blocks the report instead of being
  printed as "Övriga förändringar" (`TODO(legal-verify)`: whether a residual
  row is ever acceptable).
- Förvaltningsberättelse fields (owners > 10 %, ownership changes, external
  factors) as new nullable columns on `annual_reports`; the K2 report ignores
  them.
- Optional kassaflödesanalys (indirect method from balance changes) is **not**
  in F; it belongs with större företag.

### 3.2 New bookkeeping, as confirmed proposals

1. **Components (17.4, 17.13).** The fixed asset register gets an optional
   parent: `fixed_assets.parent_id` (null for a whole asset) so a building is a
   parent with components (stomme, tak, fasad, stammar …) each with its own
   `useful_life_months`, and land as a non-depreciated component. Depreciation
   already runs per row; a parent is never depreciated itself. The register
   today has only Equipment, Computers, Machinery, Vehicles: a Buildings and a
   Land category are added (BAS 11xx; `TODO(legal-verify)`: accounts and
   ranges against the BAS 2026 chart). Splitting an existing whole asset into
   components produces a proposal (reclassification between the asset's own
   accounts is not needed when all components use the same account; when they
   do not, a reclassification voucher) — never an edit of past depreciation
   vouchers. For a K2 company the field stays null and nothing changes.
2. **Deferred tax (29.19, 29.37).** A `DeferredTaxProposal` in the closing
   (next to ADR-056's tax): per temporary difference the person enters or
   confirms carrying value and tax value (NONOX can suggest buildings
   (component depreciation vs tax depreciation) and tax-loss carry-forwards from
   INK2; untaxed reserves are excluded by 29.37), times the corporate tax rate
   from `CorporateTaxRules`; the proposal is the change since the last balance
   date, booked 89xx (within 891x–894x per the linkbase) against 224x/137x.
   `TODO(legal-verify)`: the exact BAS accounts, 29.20–29.31 (recognition
   exceptions, valuation of a deferred tax asset, "högst det belopp som …"),
   discounting not allowed?
3. **Transition (ch. 35).** On the first K3 year a `K3TransitionProposal`
   lists the adjustments at the transition date (deferred tax opening balance,
   component split effects, provisions for informal obligations if any) with
   the counter entry "direkt mot eget kapital" (35.11), posted as **one new
   voucher dated the first day of the first K3 year** (35.7 for a mindre
   företag not restating comparatives), with a new voucher source
   `K3_TRANSITION` (it is not a `CORRECTION` of an error) (audit-logged; systemdokumentation
   updated). The K2 years and their reports stay as they are; the K3 report
   prints the note on lacking comparability (ÅRL 3 kap. 5 § fjärde stycket).
   `TODO(legal-verify)`: whether the transition voucher may lie in the new year
   or must be an IB adjustment; equity account for the difference (2090/2091?).
4. **Provisions (21).** No calculation in NONOX: the person books them as
   ordinary vouchers; the report asks for a confirmation that informal
   obligations have been considered.
5. **Fixed-price contracts (23).** NONOX has no project-progress data. The
   company must confirm its method (färdigställandemetoden under 23.31 where
   allowed, or successiv vinstavräkning booked by the person as accrual
   vouchers). Support for computing it is out of F.

### 3.3 K3 policies as data

`company_accounting_policies` (company-scoped, `BelongsToCompany`, tenancy
test): one row per policy key and fiscal year from which it applies, e.g.
`leasing` = `operational_in_legal_entity` (20.29), `intangibles` = `expense`
(18.8/18.30), `borrowing_costs` = `expense` (25.3/25.7), `financial_instruments`
= `chapter_11`, `fixed_price_contracts` = `completed_contract_23_31` |
`percentage_of_completion`, `components` = `applied`. Changing a policy for a
year that has a filed or adopted report is refused (K3 ch. 10 change of
principle is a later stage). The report's note 1 is built from these rows; a
missing row blocks the report with the question to answer.

## 4. K2 output stays unchanged

- **Before any K3 code**, F.1 commits golden files for the K2 report from the
  existing synthetic fixtures (`tests/Feature/AnnualReport`): the rendered HTML
  of `AnnualReportDocument::html()` and the iXBRL XHTML of
  `K2AnnualReportIxbrl::render()` for a profitable company, the loss-making
  öre fixture of ADR-058's review, and a company with notes and pledged assets.
  The tests compare **byte for byte**. Dompdf's PDF carries a creation date, so
  the PDF is compared by its extracted text plus page count unless the
  timestamp can be fixed (`TODO`: verify Dompdf's date source).
- K3 is a separate path, chosen by `reporting_framework`: `K3AnnualReportDocument`,
  `K3Schedule`, `K3Taxonomy`, `K3AnnualReportIxbrl`, own views. Shared code
  (`PrintedFigures`, `InlineXbrlWriter`, `AnnualAccounts` ledger readers) may only
  gain parameters with K2 defaults; the golden files guard that.
- **iXBRL for K3:** K3 AB 2021-10-31 (revision 2026-09-09 in the 2026-08-31
  package) entry point `risbs`
  `http://xbrl.taxonomier.se/se/fr/gaap/k3/risbs/2021-10-31/se-k3-risbs-2021-10-31.xsd`
  plus the fastställelseintyg `coa/rplc/2020-12-01` (as ADR-058). The same
  `BOLAGSVERKET_TAXONOMY_DIR` check verifies every K3 concept. The split of
  Maskiner och inventarier is needed here too (no combined concept in K3 `fbs`).
  `TODO(provider-verify)`: that Bolagsverket accepts K3 2021-10-31 for fiscal
  years begun after 2025-12-31.

## 5. Stage plan

- **F.1 Guard and golden files** (no K3 output). `AnnualReportDocument::supports()`
  and `K2AnnualReportIxbrl` refuse a company whose framework is not K2 with a
  Swedish message ("NONOX gör ännu ingen årsredovisning enligt K3 …"); the
  annual report page explains it. K2 golden files committed. K2 conditions text
  in `ReportingFrameworkRules` extended with BFNAR 2025:2 1.1A–1.1C (wording
  reviewed by the jurist). Changelog, manual, systemdokumentation. *Gate:* full
  suite green, jurist review of the texts.
- **F.2 K3 policies and presentation.** `company_accounting_policies`,
  `K3Schedule`, the K3 PDF (förvaltningsberättelse per 3.7, RR, BR with deferred
  tax lines, equity statement per 6.3, notes per ch. 8 for mindre företag),
  refusal list for unsupported policies, större företag and ekonomiska
  föreningar. Beta. *Gate:* an authorised accountant (human) checks a synthetic
  K3 report against the accountant checklist below; legal review of the note
  texts; K2 golden files unchanged.
- **F.3 Bookkeeping differences.** Components in the fixed asset register,
  `DeferredTaxProposal`, `K3TransitionProposal` with the new voucher source;
  immutability and tenancy suites extended; systemdokumentation updated (new
  source, new proposal flows). *Gate:* the accountant reviews proposals on a
  synthetic property company switching from K2 to K3 (2025 → 2026); ledger
  verify green.
- **F.4 K3 iXBRL.** `K3Taxonomy` map, `K3AnnualReportIxbrl`, taxonomy check,
  PDF-equals-iXBRL test as in ADR-058; filing through ADR-058's E.3–E.5 flow
  once that exists. *Gate:* every printed line tagged or refused; Bolagsverket
  `kontrollera` in test (after ADR-058 E.3).

## Human decisions

1. Confirm the scope: mindre AB in juridisk person only; större företag,
   koncernredovisning and ekonomiska föreningar later (or never).
2. Which K3 features first in F.3: proposal is components + deferred tax +
   transition (what the 1.1B companies need); aktiveringsmodellen, successiv
   vinstavräkning computed by NONOX, capitalised borrowing costs and financial
   leasing in the balance sheet stay refused.
3. An **accountant checklist** written and signed off by a human (authorised
   or approved accountant) before F.2 ships: per K3 chapter, "applies / does
   not apply / handled outside NONOX", used both as the review list and as the
   in-app confirmations.
4. Whether to tell existing K2 companies (on the overview or the closing
   checklist) to re-check K2 1.1B before closing 2026, and with what wording.

## Open TODO(legal-verify)

- K2 1.1B/1.1C: what "väsentlig" deferred tax means in practice; whether NONOX
  may suggest (never decide) that a company is caught, e.g. buildings with ≥ 75 %
  of net turnover.
- The full note list for a mindre företag under K3 and ÅRL 5 kap. (ch. 8 read
  only to 8.5); 23.29 revenue disclosures; ch. 17–18 note content (17.23, 18.28).
- 29.20–29.31: recognition exceptions and valuation of deferred tax assets;
  the corporate tax rate to use; BAS accounts for deferred tax expense (89xx).
- 21.21: the juridisk-person relief for restoration costs (not read in full).
- 23.31: which industries 17 kap. 23 § IL covers (IL not read).
- Ch. 35 for a company that used K3 before (35.1 limitation, lifted by BFNAR
  2025:3 point 2 only for companies forced off K2); 35.12–35.30 mandatory and
  voluntary exceptions (not read in full).
- Where the transition difference is booked (date, equity account) and whether
  the first K3 year's opening balance may differ from the last K2 year's UB.
- Whether a "Övriga förändringar" residual row is ever acceptable under 6.3.
- Status of BFN's review of K3 ch. 11–12 (Dnr 2026:90) and of BFNAR 2017:3
  changes; re-check bfn.se before every stage.
- `TODO(provider-verify)`: K3 taxonomy version accepted by Bolagsverket per
  fiscal year; K3-specific requirements in Tillämpningsanvisning 1.8.
- ÅRL thresholds were read as 50 / 40 MSEK / 80 MSEK (SFS 2026:780); re-check
  if a change of the EU size criteria is implemented.

## Consequences

- From F.1 a K3 company gets no false K2 report; it gets a clear refusal until
  F.2 ships.
- K2 output is frozen by golden files; any change to shared code that alters it
  turns the suite red.
- Posted vouchers are never changed: every K3 bookkeeping difference is a new,
  confirmed voucher with an audit entry.
- New company-scoped tables (`company_accounting_policies`) and new fixed asset
  fields need tenancy tests, systemdokumentation and manual updates in their
  stage.

## Addendum 2026-11-06: Stage F.1 built

Enligt AI-utredning 2026-11-06, juristgranskning återstår.

- **Golden files first.** `tests/Feature/AnnualReport/AnnualReportGoldenTest.php`
  renders the K2 document (`AnnualReportDocument::html()`) and the K2 iXBRL
  (`K2AnnualReportIxbrl::render()`) for two synthetic aktiebolag and compares
  them byte for byte with `tests/Fixtures/annual-report/`:
  `k2-profitable.{html,xhtml}` (two years, a building on a bank loan, entered
  notes, pledged assets, contingent liability, events after the year,
  fastställelseintyg) and `k2-loss.{html,xhtml}` (loss, öre, no previous
  year). Name, organisation number, user and clock are fixed; the taxonomy
  check is off (it only refuses, it never changes the output). The files were
  written before any F.1 change and passed unchanged after it. They are
  rewritten only with `NONOX_UPDATE_GOLDEN=1`, and the diff must be reviewed.
  The PDF is not frozen (Dompdf's creation date; `TODO` from §4 stands).
- **Guard.** `AnnualReportDocument::supports()` now means "legal form AB or
  ekonomisk förening **and** `reporting_framework` K2". The legal-form test
  alone is `supportsLegalForm()`, used by `IncomeTaxForms` and
  `IncomeTaxReturns` so that a K3 aktiebolag keeps its INK2.
  `AnnualReportDocument::refusal()` gives the Swedish reason. The controller
  keeps its existing pattern: preview, PDF and iXBRL answer **403** for an
  unsupported company (as for an enskild firma before; the company is the
  user's own, so its existence is not hidden). `K2AnnualReportIxbrl::render()`
  refuses on its own as well (`IxbrlRefused`), `SaveAnnualReport` refuses with
  the reason, and the page shows an empty state:
  - title "Årsredovisning enligt K3 finns inte ännu" (K1: "Inte för det här
    regelverket");
  - "NONOX tar ännu inte fram årsredovisningar enligt K3 (BFNAR 2012:1).
    Företaget har valt K3, och en årsredovisning enligt K2 skulle inte stämma
    med det regelverket." (K1: "NONOX tar fram årsredovisningar enligt K2.
    Företaget har valt K1, som inte används för en årsredovisning.");
  - "Bokföringen, bokslutet och inkomstdeklarationen fungerar som vanligt.
    Årsredovisningen upprättar du i ett program som stöder regelverket, eller
    med hjälp av en redovisningskonsult: ladda ner årets SIE-fil under
    Räkenskapsår och läs in den där.";
  - "Har företaget valt regelverk av misstag, och uppfyller det villkoren för
    K2, kan en ägare eller administratör ändra regelverket under Inställningar
    för företaget."
- **Other K2 output checked.** The archive package contains no annual report
  at all (SIE, ledger reports, underlag, invoices, VAT, payroll, audit log,
  systemdokumentation), so nothing false is packed; a test asserts it for a
  K3 company. The closing checklist cites K2 and K3 side by side (currency
  revaluation) and produces no K2-only statement. INK2 has no framework field
  in NONOX. The landing and comparison pages already say "årsredovisning
  enligt K2".
- **K2 conditions.** `ReportingFrameworkRules::conditions()` for K2 now returns
  paragraphs (blank line between, shown with `white-space: pre-line`) and, for
  an aktiebolag or an ekonomisk förening, adds summaries of BFNAR 2016:10
  punkt 1.1A (with "publika aktiebolag" for AB and "bostadsrättsföreningar och
  bostadsföreningar" for an ekonomisk förening; fysiska personer and dödsbon
  are not offered K2 årsredovisning in NONOX anyway), 1.1B, 1.1C (both
  paragraphs) and the effective date of BFNAR 2025:2 point 1, including the
  extended first year rule. Source: the consolidated K2 guide 2025, pp. 15–16
  and 332, read 2026-11-06. Other legal forms keep only the size condition
  (`TODO(legal-verify)` in the class). The confirmation flow is unchanged;
  companies that confirmed K2 earlier are **not** asked again — that is human
  decision 4 above, recorded in `docs/status/manskliga-beslut.md`.
- **Review fixes (same day).** The effective-date paragraph now says the
  2025:2 wording applies to fiscal years beginning after 2025-12-31 (or an
  extended year per transition point 1) and that an older wording, not shown,
  applies to earlier years (the forms do not know the fiscal year;
  `TODO(legal-verify)`: the pre-2026 wording). 1.1C follows the source's
  wording and adds the commentary's "Med normalt avses mer än något eller
  några enstaka år". The confirmation checkbox no longer suggests K3 when
  unsure: "Företaget uppfyller villkoren ovan. Är du osäker, fråga din
  redovisningskonsult innan du väljer. Väljer du K3 tar NONOX ännu inte fram
  årsredovisningen; bokföring, bokslut och inkomstdeklaration fungerar som
  vanligt." "En ägare eller administratör" in the empty state was checked
  against `company:update` (owner: all permissions; admin: all but
  `company:archive`; accountant does not have it).
- **Still open from F.1's gate:** jurist review of the condition texts and the
  refusal texts.
