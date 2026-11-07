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

## Addendum 2026-11-07: spec S1 packages

Enligt AI-utredning 2026-11-07, juristgranskning återstår.

- **Decision S1-A (2026-11-07): accepted.** The owner delegated the decision to the Lead,
  who approved this addendum after checking that the sources are still current: BFN's
  K-regelverk page (updated 2026-09-07) still names `vl12-1-k3-kons20251215` as the K3
  version for fiscal years begun after 2025-12-31 and lists no 2026 amendment to K3.
  The approval covers the design and the package order (A.4). It does not settle the
  human decisions in A.6 (H1–H10), and K3 stays in beta until the named accountant
  (H10) has signed the checklist.

- Author: S1 Architect (AI). Input: the S1 rule sheet `docs/specs/rules/s1.md`
  (branch `spec/s1/rules`, status ready, every quote re-read 2026-11-07), the
  spec `docs/specs/nummer-1-spec.md` Part 1, Part 2 and S1, `docs/specs/ownership.md`,
  and the fixture F-S1-a (branch `spec/fixtures/s1-s2-a`, frozen 2026-11-07).
- Rule ids below are the rule sheet's (S1-R1 … S1-R10 and their sub-rules). A rule
  the sheet rates **low** is not coded without a human decision (spec Part 1.6); the
  decisions are listed in A.6. An independent quote check of the rule sheet was
  still running when this addendum was written: if it changes a rule, the package
  that implements the rule is re-checked before merge.
- No production code is written by this addendum.

### A.1 Reconciliation with the rule sheet

The rule sheet's "Spec corrections" and its notes on this ADR are accepted. Where
this ADR said something else, the text below wins over the earlier sections.

1. **Deferred tax has no general legal-entity exemption** (S1-R4, R4.2, R4.7). K3
   29.19 binds the juridisk person; 29.37 only keeps untaxed reserves gross. §3.2.2
   already said so; the spec's "what a legal entity may skip" is wrong and is not
   built. The deferred tax **note** (ÅRL 5:36, K3 29.34–29.36) is a större-företag
   note only; a mindre AB states the tax principle in the policies note (K3 8.3).
2. **20.29 leasing is a company policy, not a per-contract flag** (S1-R5). "Får"
   and "alla": one policy row `leasing` (A.2.3), applied to every finance lease of
   the company. The spec's `lease_contracts.legal_entity_exception` flag is
   rejected. No lease register is built in S1: with 20.29 every lease is an
   operating lease booked as ordinary vouchers, and the only lease output, the
   20.14 note, is a större-företag note (S1-R5.1, package S1-P13). Choosing
   `classify` (finance leases in the balance sheet) keeps refusing the report, as
   §2 decided.
3. **Cash flow method** (S1-R2, R2.1). K3 7.8 allows the direct or the indirect
   method. NONOX builds the **indirect** method only; that is a product choice
   (7.8 allows it), recorded here, not a legal requirement. §3.1's "kassaflödesanalys
   is not in F" is **withdrawn**: the spec puts it in S1, F-S1-a needs it, and a
   voluntary one must follow chapter 7 in full (K3 7.1). It is required only of a
   större företag (ÅRL 2:1); for a mindre AB it is a policy choice
   (`cash_flow_statement` = `none` | `indirect`), which F-S1-c proves optional.
4. **Components are not restated at transition** (S1-R7.2 a, K3 35.18). §3.2.3 is
   corrected: "component split effects" are **removed** from the transition
   adjustments. At the transition date the carrying amount of an asset is
   allocated to its components; that creates no equity difference and no voucher
   when all components use the asset's own accounts. Depreciation from then on is
   prospective (A.2.4). §3.2.1's "splitting an existing whole asset produces a
   proposal" stays, but the proposal is only a reclassification between accounts
   when a component (land) uses a different account.
5. **The equity statement is required of a mindre AB too** (S1-R8.3; ÅRL 6:2
   andra stycket, K3 6.3). §3.1 already planned it; it is confirmed and becomes its
   own package (S1-P7) with rows (a)–(e) of 6.3. A residual "Övriga förändringar"
   row is a blocking error, not a printed row (rule sheet: treat as blocking). Which
   6.3 row the transition adjustment uses ((b) or (c)) is medium: NONOX uses (b)
   "Effekterna av retroaktiv tillämpning", because the transition is retroactive
   application under 35.8; the accountant checklist asks the human to confirm.
6. **BFNAR 2025:3 transition point 2** (S1-R7.6). A company forced off K2 by the
   BFNAR 2025:2 wording may use chapter 35 even if it applied K3 before. Data: the
   policies `k3_applied_before` and `left_k2_reason` (A.2.3); chapter 35 is offered
   when `k3_applied_before = no`, or `left_k2_reason = forced_2025_2`.
7. **BFNAR 2006:11 for size** (S1-R9.1, R9.2). The two years are the two
   **immediately preceding** the reporting year (GV 6); net turnover is pro-rated
   to 12 months for a short or long year (GV 5 c); the balance sheet total is
   **not** pro-rated; average employees are full-time equivalents (GV 7–10, 1 920
   hours as the simplification). The same measurement serves K2 1.1C (S1-R1.3)
   and the average-employees note (K3 8.6B).
8. **F-S1-a is a större företag.** The fixture (net turnover 90/96 MSEK, total
   assets 45–48 MSEK, both above the thresholds in both measured years) contradicts
   §2's decision to refuse större företag. Resolution: the S1 domain classes
   (size test, components, deferred tax, cash flow, equity statement) are
   size-independent and are proved against F-S1-a's `expected.json` **at figure
   level**. The fileable **document** for a större företag stays refused until
   human decision H1 (A.6) lifts it and S1-P13 adds the ÅRL 5:25–48 notes. F-S1-a
   is also the "refused with the reason" case for the document until then (the
   fixture's README says the same).
9. Smaller points accepted as they stand: K3 1.3 and K2 1.1–1.1C named as the
   sources of S1-R1; ÅRL 4:2 st. 2, 4:4 st. 2, 4:7, 4:8, 3:10 a and K3 18.3A for
   S1-R10; the deferred tax rate rule is K3 29.25 with the number from IL 65:10
   (A.2.6); fixture F-S1-b must state whether comparatives are restated (S1-R7.1).

Still valid from the earlier sections: K3 is all or nothing (1.0); mindre AB in the
juridisk person is the primary target; nothing posted is changed; every bookkeeping
difference is a proposal a person posts; K2 output is frozen by the golden files.

### A.2 Data model

Conventions followed: company-scoped tables use `BelongsToCompany` and get a tenancy
test (another company's id → 404); amounts are `bigint` öre with `MoneyCast`; enums
are PHP backed enums stored as strings; verified rules are JSON data files with a
source URL, quote, status and date read, read by a small rules class, as
`database/data/corporate-tax/rules.json` and `CorporateTaxRules` (ADR-056); person
confirmations per fiscal year are `closing_checks` rows (key, reason, fingerprint),
as the K2 confirmation and the opening-balance confirmation already are; every write
calls `Audit::record` with ids, enums and amounts only, never free text.

#### A.2.1 Reporting framework per fiscal year (decision)

**Decision.** The framework becomes a property of the **fiscal year**:
`fiscal_years.reporting_framework` (string, `K1|K2|K3`, not null), with
`reporting_framework_changed_at`, `reporting_framework_changed_by` (FK users, null
on delete) and `reporting_framework_change_reason` (text, null). The change log is
the audit log (`fiscal_year.framework_changed`, before/after, fiscal year id; the
reason text stays in the column, not in the audit payload).
`teams.reporting_framework` is **kept** and redefined as "the framework of the
company's latest fiscal year" (and the default for the next one), because a dozen
readers use it (chart seeding, setup rules, the API presenter, `Ne`, the settings
form) and none of them needs a year.

**Why.** (1) The law decides per year: K2 1.1B is assessed on the latest completed
annual report, the K2/K3 wording depends on when the year begins (S1-R1.4), and the
transition happens at a year boundary (S1-R7). (2) Today a company that switches to
K3 for 2026 can no longer print or re-render its **2025 K2 report**, because the F.1
guard reads the company column; a per-year framework fixes that. (3) The spec asks
for "framework choice per fiscal year with a change log".

Rejected: a separate `fiscal_year_frameworks` history table (the audit log already
is the history, and a second history invites drift); dropping the company column
(touches a dozen unrelated files for no gain).

**Migration.** Additive: add the columns nullable, backfill every year from its
company's `teams.reporting_framework` in the same migration (one `UPDATE … FROM
teams`), then set not null. No vouchers are touched. A `FiscalYear::creating`
hook fills the framework from the previous year, else the company, so the existing
creators (`CreateCompany`, `CreateNextFiscalYear`, SIE import, the Fortnox
migration, Connect's test company) need no change.

**Writes.** One action, `ChangeReportingFramework(User, FiscalYear, framework,
reason, confirmations)`: permission `company:update` (owner, admin; spec
"Permissions"); refused for a closed year and for a year whose annual report has an
`adoption_date`; updates the year and, when it is the latest year, the company
column, in one transaction with a row lock, then `Audit::record`.
`UpdateCompanyDetails` routes a framework change on the settings page to this action
for the latest open year, so the existing UI keeps working. Invariant test: the
company column equals the latest year's framework.

#### A.2.2 Rules as data

`database/data/annual-report/k3-rules.json`, read by `App\Domain\AnnualReport\K3Rules`
(same shape and API as `CorporateTaxRules`: `rule(key)`, `int(key)`, entries with
`starts_from`/`starts_before` by fiscal-year start, `status`, `read`, `source_url`,
`source_quote`). Status is `researched` until a human signs off. Rows, all from the
rule sheet's "What a builder needs as data":
`larger_company.employees_gt` 50, `.balance_sheet_total_gt_ore` 4 000 000 000,
`.net_turnover_gt_ore` 8 000 000 000, `.criteria_needed` 2; `normal_annual_hours`
1 920; `k2_1_1c.employees_gt` 3, `.balance_sheet_total_gt_ore` 150 000 000,
`.net_turnover_gt_ore` 300 000 000, `.max_criteria_met` 1;
`k2_1_1b.building_turnover_share_min_bp` 7 500; `intangible_dev_default_life_months`
60; `intangible_residual_value_ore` 0; `small_asset_life_max_years` 3;
`k3_version_by_fiscal_year` (`kons2024` / `kons20251215` with the extended-first-year
rule); the deferred tax accounts `deferred_tax.liability_account` 2240,
`.asset_account` 1370, `.expense_account` 8940 (source: the rbas2026 reference
linkbase, §"The taxonomy"; `TODO(legal-verify)` against the BAS 2026 chart, as the
fixture's assumption A12 says). The **corporate tax rate is not duplicated**: deferred
tax reads `CorporateTaxRules` (A.2.6).

#### A.2.3 Accounting policies

`company_accounting_policies` as §3.3 proposed: `id, company_id, key, value,
applies_from (date = start of a fiscal year), set_by, reason (text null),
timestamps`, unique `(company_id, key, applies_from)`. The policy in force for a
year is the row with the latest `applies_from` ≤ the year's start. A write is
refused when any year from `applies_from` on has an adopted annual report (a
change of principle under K3 ch. 10 is a later stage). Keys and values (backed
enums, `App\Domain\AnnualReport\K3PolicyKey` and value enums):

| Key | Values | Rule | Supported in S1 |
|---|---|---|---|
| `financial_instruments` | `chapter_11`, `chapter_12` | S1-R6 | `chapter_11` only; `chapter_12` refuses |
| `intangibles` | `expense`, `capitalise` | S1-R10, K3 18.7/18.30 | `expense` only; `capitalise` refuses |
| `leasing` | `operational_in_legal_entity`, `classify` | S1-R5, K3 20.29 | `operational_in_legal_entity` only |
| `borrowing_costs` | `expense`, `capitalise` | K3 25.3/25.7 (§1.1) | `expense` only |
| `fixed_price_contracts` | `completed_contract_23_31`, `percentage_of_completion`, `none` | §3.2.5 | all three as confirmations; NONOX computes neither |
| `cash_flow_statement` | `none`, `indirect` | S1-R2, R2.1 | both; `none` refused for a större företag |
| `comparatives_restated_at_transition` | `yes`, `no` | S1-R7.1 | `no` first (see S1-P12) |
| `k3_applied_before` | `yes`, `no` | S1-R7, R7.6 | both |
| `left_k2_reason` | `forced_2025_2`, `voluntary` | S1-R7.6 | both |
| `components` | per human decision H4 | S1-R3.1 | added by S1-P4 after H4 |

#### A.2.4 Components in the fixed asset register

As §3.2.1, made precise:

- `AssetCategory` gains `Buildings` (1110/1119/7820) and `Land` (1130, no
  accumulated or expense account). `Land` never depreciates (S1-R3.2): the model
  returns no plan and `FixedAssets::preview()` skips it. Account numbers are
  `TODO(legal-verify)` against BAS 2026 (fixture A12).
- `fixed_assets.parent_id` (nullable self FK, same company). Components exist only
  under an asset of a company whose fiscal year in question is K3; under K2 the
  field stays null (K2 10.21/10.22 treats building and land as separate assets,
  which the new categories already allow).
- Splitting an asset at a date D (first day of a month; the transition date at
  transition): the parent gets the new status `SPLIT` (so the existing `ACTIVE`
  filter stops depreciating it; its booked depreciations stay as they are,
  `fixed_asset_depreciations` is append-only). Each component gets an allocated
  share of the parent's cost (`acquisition_ore`) and of its accumulated
  depreciation at D (`opening_accumulated_ore`), so the shares add up exactly to
  the parent (largest remainder on the last component), and the carrying amount is
  allocated as 35.18 says.
- **Prospective plan.** New column `fixed_assets.depreciation_basis` (`COST`
  default | `CARRYING_AMOUNT`). A component created by a split uses
  `CARRYING_AMOUNT`: its `DepreciationPlan` writes off (cost − opening accumulated
  − residual) over its **remaining** life from D, so no catch-up of earlier months is
  ever booked (35.18 "får inte räknas om retroaktivt"; a changed life is a change of
  estimate under K3 17.17/ch. 10, also prospective). Existing assets keep `COST` and
  behave exactly as today.
- Depreciation start is the month the asset can be taken into use (S1-R3.4), kept
  at NONOX's existing monthly granularity (rule sheet: medium, an ADR line): a month
  is depreciated in full when `depreciation_start` lies in it.
- Component replacement (S1-R3.5): derecognising a component uses the existing
  `FixedAssets::dispose()` on the component (gain 3973 / loss 7973 to be checked
  against building accounts, `TODO(legal-verify)`), and the new component is a new
  child. No new posting path.
- Component depreciation needs **no new proposal flow**: the existing monthly
  depreciation is already a proposal (`preview`) that a person books (`depreciate`),
  one voucher per month through `BookVoucher`. That satisfies spec Behaviour 2.

#### A.2.5 Size figures

The size test (S1-R9) and K2 1.1C (S1-R1.3) need two prior years. For a year kept
in NONOX: net turnover and balance sheet total from `AnnualAccounts`, average
employees from `annual_reports`. `annual_reports.average_employees` is an
**integer**, but the thresholds are strict ("mer än 3", "mer än 50") on a
full-time-equivalent figure, so 3,4 FTE must count as more than 3: a nullable
`annual_reports.average_employees_fte numeric(8,2)` is added and used by the test
when present (the K2 note keeps printing the integer; golden files unchanged). For
a year before the company's first NONOX year: `prior_year_size_figures`
(`company_id, start_date, end_date, average_employees_fte numeric(8,2),
balance_sheet_total_ore, net_turnover_ore, entered_by, timestamps`, unique
`(company_id, start_date)`), entered by a person, audit-logged. Net turnover is
pro-rated by months for a year that is not 12 months (GV 5 c); integer arithmetic,
rounding half up to the öre.

#### A.2.6 Deferred tax

`deferred_tax_items` (company-scoped): `id, company_id, fiscal_year_id, kind
(BUILDING_COMPONENTS | LOSS_CARRY_FORWARD | OTHER_ASSET | OTHER_LIABILITY),
fixed_asset_id null, account_from/account_to null (the balance sheet accounts the
carrying amount is read from), carrying_ore, tax_value_ore, tax_value_source
(ENTERED | INK2 | REGISTER), recognised_ore null (for a deferred tax asset: the
amount a person decided to recognise, with `recognition_reason`), rate_bp null
(snapshot at booking), confirmed_by null, timestamps`. Untaxed reserves (BAS
2100–2199) can never be an item (29.37, S1-R4.2 c); goodwill on initial recognition
is excluded (29.21); no discounting (29.25, S1-R4.4).

`App\Domain\Closing\DeferredTaxProposal` (pattern of `TaxProposal`): per item the
temporary difference (carrying − tax value; sign by asset/liability, S1-R4.1), the
deferred tax at the rate, the total liability (2240) and asset (1370), the change
since the last balance date, and the proposed voucher 8940 against 2240/1370. The
rate is `CorporateTaxRules::rateEntryFor()` of the fiscal year **following** the
balance date, i.e. the rate decided before the balance date for the years in which
the differences reverse (29.25); if `rules.json` ever holds more than one rate for
years after the balance date, the proposal refuses until a human has decided how to
allocate reversals (rule sheet: medium). Booking:
`YearEndClosing::confirmDeferredTax(...)` → a `YearEndEntry` of the new type
`DEFERRED_TAX` and one voucher through `BookVoucher`, series as the tax entry,
source `CLOSING`; liability and asset are shown gross, netted only when the person
confirms the legal right of set-off (29.32, a `closing_checks` key). Changes in
deferred tax go to the income statement (29.22); items recognised in equity are out
of scope (none in S1).

#### A.2.7 Notes, management report and overrides

- Management report fields (S1-R8.4, K3 3.7): nullable columns on `annual_reports`
  — `k3_external_factors`, `k3_special_circumstances`, `k3_ownership_changes`
  (text) and `k3_major_owners` (jsonb: name, organisation number or birth year only
  — no personal identity number — and share of votes and capital). Owners over 10 %
  who are natural persons are personal data: the GDPR register of processing gets a
  line (legal basis: legal obligation, ÅRL 6:1 via K3 3.7), retention follows the
  annual report (räkenskapsinformation). The K2 report ignores the columns.
- Notes are generated from data: `App\Domain\AnnualReport\K3Notes` returns the
  notes whose trigger holds (S1-R8.1 rows 1–27), each with a key, structured data
  and generated Swedish text. An override is stored in
  `annual_report_note_overrides` (`annual_report_id, note_key, text, reason,
  set_by, timestamps`, unique `(annual_report_id, note_key)`); the K3 preview marks
  every overridden note, and the PDF prints the person's text. The structured data
  is never overridden, only the wording.

#### A.2.8 Transition

`K3TransitionProposal` as §3.2.3, corrected by A.1.4: adjustments are the opening
deferred tax (unless the 35.27 relief is confirmed with a reason, disclosed under
35.31), provisions for informal obligations a person enters, and any other
retroactive item a person enters with its K3 reference; **not** component splits.
The booking form (IB adjustment or voucher on the first day, and the equity account)
is human decision H7; a new `VoucherSource::K3Transition` is needed if H7 chooses a
voucher. `vouchers.source` is a PostgreSQL enum check, so that is a migration on the
vouchers table (no trigger change; Lead approval because it touches the vouchers
table).

### A.3 Hot spots that need the Lead's approval

| Package | File | Change | Why it is needed |
|---|---|---|---|
| S1-P1 | `AnnualReportDocument.php` | `supports()` and `refusal()` take an optional `FiscalYear` and read **its** framework (company column when null, so every existing caller behaves as today); callers in `AnnualReportPage`, `AnnualReportController`, `SaveAnnualReport` and `K2AnnualReportIxbrl` pass the year | Without it, a company that moves to K3 for 2026 loses its 2025 K2 report, and a K3 year cannot be routed to the K3 document. K2 HTML/iXBRL unchanged. |
| S1-P4 | `AnnualReportDocument.php` | `depreciationPrinciples()` skips the `Land` category and `SPLIT` parents | Otherwise a K2 company that registers land prints "Mark: 0 år" in its principles note. Golden files have no register rows, so they stay byte-identical; a new K2 unit test covers the case. |
| S1-P6 | `PrintedFigures.php` | `apply()` gains a schedule parameter defaulting to `K2Schedule` (it reads `K2Schedule::INCOME_SUBTOTALS`, `BALANCE` order, `SECTIONS`, `ASSET_SECTIONS` directly), plus the ordered list of equity cells that take a rounding leftover (K2: today's order; K3: never a new "Övriga förändringar" row) | The K3 balance sheet has its own lines (1370 deferred tax asset, 2240 deferred tax liability, the development fund) and K3 6.3 forbids the residual equity row; printing K3 through the same rounding keeps "one figures source" (spec Behaviour 5, §4). |
| S1-P10 | `AnnualReportDocument.php` | `refusal()` no longer refuses a K3 **year** once the K3 document supports it; the K3 refusal reasons move to `K3AnnualReportDocument::refusal()` | Routing; K2 paths unchanged. |
| — | `tests/Fixtures/annual-report/**` | **No change** in any package | Byte-identical K2 output is part of every package's definition of done. |
| S1-P12 (only if H7 chooses a voucher) | `vouchers` table | migration adding `K3_TRANSITION` to the `source` check | New voucher source; no trigger or `PostVoucher` change. |

Files outside the S1 ownership prefixes that packages touch (the Lead adds
ownership rows): new `database/migrations/*`, `app/Models/FiscalYear.php`,
`app/Models/FixedAsset.php`, `app/Actions/Assets/FixedAssets.php`,
`app/Domain/Assets/AssetCategory.php`, `app/Domain/Assets/DepreciationPlan.php`,
`app/Domain/AnnualReport/AnnualAccounts.php` (schedule parameter with K2 default;
not a hot spot but shared), `app/Actions/Company/UpdateCompanyDetails.php`,
`app/Domain/Closing/K2Reconfirmation.php`, `app/Actions/Closing/YearEndClosing.php`,
`app/Models/YearEndEntry.php`, and the Livewire closing, fixed-asset and
annual-report pages.

### A.4 Packages

Each package: branch `spec/s1/<slug>`, own worktree and test database
(`nonox_test_s1_<slug>`), failing tests first, K2 golden test
(`AnnualReportGoldenTest`) green and `tests/Fixtures/annual-report/**` untouched,
full checks of QUICKSTART §4 with `PGTZ=UTC`, independent review, changelog and
manual where a user can notice it, systemdokumentation where accounting behaviour
changes, tenancy test for every new company-scoped table. Unit test names start
with the rule id (`it('S1-R9.1 measures the two years before the reporting year')`).

**The first three packages need no open human decision** and can start in the order
given; P2 and P3 do not depend on P1 and may run in parallel with it.

#### S1-P1 Framework per fiscal year (`spec/s1/framework-per-year`) — first

- Rules: S1-R1.4 (effective date per fiscal-year start), K3 1.4/1.6 (all or
  nothing, §1.0); spec S1 scope 1.
- Files: migration (A.2.1); `app/Models/FiscalYear.php` (cast, `creating` hook);
  `app/Actions/Company/ChangeReportingFramework.php` (new);
  `app/Actions/Company/UpdateCompanyDetails.php` (delegate);
  `app/Domain/Closing/K2Reconfirmation.php` (read the year's framework);
  **hot spot** `AnnualReportDocument::supports()/refusal()` with the year (A.3);
  callers `AnnualReportPage`, `AnnualReportController`, `SaveAnnualReport`,
  `K2AnnualReportIxbrl`.
- Tests first: backfill gives every existing year its company's framework; a new
  year inherits the previous year's; changing the latest open year updates the
  company column, a closed or adopted year is refused, a member without
  `company:update` is refused (per role); the audit entry is written without the
  reason text; a company with 2025 = K2 and 2026 = K3 still renders the 2025 K2
  report **byte-identical** to the same report before the change and is refused for
  2026; tenancy (another company's fiscal year → 404); golden files unchanged.
- Done: the above green; systemdokumentation (framework is per year); manual
  (Inställningar för företaget: the choice applies to the current year).
- Blocks: P10, P11, P12 (all need a year's framework).

#### S1-P2 Size test and K3 rules data (`spec/s1/size-test`) — second

- Rules: S1-R9, R9.1, R9.2, R9.3 (gate), S1-R1.3 first paragraph (K2 1.1C a–c),
  S1-R1.4 (`k3_version_by_fiscal_year`).
- Files: `database/data/annual-report/k3-rules.json`, `app/Domain/AnnualReport/K3Rules.php`,
  `app/Domain/AnnualReport/K3CompanySize.php` (classifier), migration for
  `prior_year_size_figures` and `annual_reports.average_employees_fte`, model with
  `BelongsToCompany`, a small action to enter prior-year figures.
- Behaviour that avoids the open question: the classifier returns `mindre`,
  `större` or **`undetermined`** with the reason. `undetermined` is returned when
  the two readings of "mer än ett" (the same two criteria in both years, or any two
  in each year; S1-R9 open) give different answers, and when fewer than two prior
  years exist and the company is not listed (S1-R9.1 open). `undetermined` blocks
  the K3 document with a Swedish question and never guesses; H8 later turns it into
  an answer. A listed company is out of scope and always `större`.
- Tests first: one unit test per threshold at the boundary (50 vs 50,01 FTE; 40 MSEK
  vs 40 MSEK + 1 öre; strict "mer än"); the two preceding years, not the reporting
  year (GV 6); pro-rating a 6- and an 18-month year's turnover, balance total not
  pro-rated; the 1 920-hour FTE helper; the ambiguous case returns `undetermined`;
  K2 1.1C: at most one criterion → exception applies, the same two in both years →
  exception gone; every data row has source URL, quote, status and date; F-S1-a's
  size test (större for 2025 and 2026 under both readings); tenancy for
  `prior_year_size_figures`; golden files unchanged.
- Done: green; manual paragraph on where the size figures come from.
- Blocks: P8 (cash flow required or optional), P10 (större refusal), P11, P13.

#### S1-P3 Accounting policies (`spec/s1/policies`) — third

- Rules: S1-R5 (company-wide 20.29), S1-R6 (one principle for all instruments, 11→12
  allowed, 12→11 only under 10.2 c/d — S1 offers only `chapter_11`), S1-R10 (model
  chosen consistently; `capitalise` refused), S1-R2 (`cash_flow_statement`), S1-R7.1
  and R7.6 (`comparatives_restated_at_transition`, `k3_applied_before`,
  `left_k2_reason`).
- Files: migration `company_accounting_policies`; model with `BelongsToCompany`;
  `app/Domain/AnnualReport/K3PolicyKey.php` and value enums;
  `app/Domain/AnnualReport/K3Policies.php` (the policy in force for a year, missing
  and unsupported policies); `app/Actions/AnnualReport/SetAccountingPolicy.php`.
- Tests first: the policy in force for a year is the latest `applies_from` ≤ year
  start; a write for a period that has an adopted report is refused; unsupported
  values are listed as refusals with the rule id; `leasing` is one row for the
  company (no per-contract value exists); permissions (owner, admin, accountant may
  prepare; auditor read-only); audit entry without the reason text; tenancy (404);
  golden files unchanged.
- Done: green; systemdokumentation (policies as data). No screen yet (P11).
- Blocks: P8, P9, P10, P11, P12.

#### Later packages (in dependency order)

| Package | Rule ids | Files (main) | Tests first | Depends on / blocked by | Blocks |
|---|---|---|---|---|---|
| **S1-P4** Components (`spec/s1/components`) | S1-R3, R3.2, R3.3, R3.4, R3.5, R3.7, R7.2 a | migration (`parent_id`, `depreciation_basis`, status `SPLIT`); `AssetCategory` (Buildings, Land); `DepreciationPlan` (carrying-amount basis); `FixedAsset`; `FixedAssets` (split, skip land); `app/Domain/Assets/ComponentSplit.php`; Anläggningar → Komponenter on the fixed asset page; **hot spot** `depreciationPrinciples()` (A.3) | split allocates cost and accumulated exactly (largest remainder); no catch-up month after a split; land never depreciated; a `SPLIT` parent gets no depreciation; F-S1-a component schedule (7820 per component, 37 000 per month, Byggnader och mark 20 752 000 at 2026-12-31) to the öre; existing assets' schedules unchanged (`COST` regression); a K2 company with land does not print "0 år"; immutability: booked depreciations untouched by a split | P3. The mechanics need no decision; the `components` policy row and the report's "components considered" confirmation wait for **H4** | P5 (building items), P9, P10, P12 |
| **S1-P5** Deferred tax proposal (`spec/s1/deferred-tax`) | S1-R4, R4.1, R4.2, R4.3, R4.4, R4.6; R4.5 only as "a person enters the recognised asset amount with a reason" | migration `deferred_tax_items`; `app/Domain/Closing/DeferredTaxProposal.php`; `YearEndClosing::confirmDeferredTax`; `YearEndEntry::DEFERRED_TAX`; Bokslut → Uppskjuten skatt | per-item difference and the sign table of 29.14; untaxed reserves cannot be an item; rate from the year after the balance date; F-S1-a: 904 752,00 / 995 227,20 / 1 085 702,40 on 2240 and the change 90 475,20 on 8940 to the öre; a deferred tax asset never above the amount a person recognised; nothing booked without confirmation; tenancy; the booked entry append-only | P4 for building items (manual items work without it). Liability side: no decision. Asset side: **H6**; sign-off of the cross-references: **H5** | P7, P9, P10, P12 |
| **S1-P6** K3 schedule and printed figures (`spec/s1/k3-schedule`) | S1-R4.6 (1370 financial fixed asset, 2240 provision), S1-R10 (development fund as a bound equity line), S1-R8.2 (order) | `app/Domain/AnnualReport/K3Schedule.php`; `AnnualAccounts` schedule parameter (K2 default); **hot spot** `PrintedFigures` schedule parameter (A.3) | F-S1-a K3 income statement and balance sheet to the öre (whole kronor as printed, sums of rounded lines, the sheet balances); K2 output byte-identical; `AnnualAccounts` query count unchanged | none functionally; start after P3 to avoid merge conflicts | P7, P8, P9, P10 |
| **S1-P7** Equity statement K3 6.3 (`spec/s1/equity-statement`) | S1-R8.3, S1-R10 (transfers to and from the development fund, 6.3 e), S1-R7 (row b) | `app/Domain/AnnualReport/K3EquityStatement.php` (rows from voucher sources `CLOSING`/`DISPOSITION`, dividends, shareholder contributions, transition, transfers) | F-S1-a 2025 and 2026 statements to the öre; a residual blocks with the accounts that cause it and never prints "Övriga förändringar"; a rounding leftover goes to an existing row only | P6 | P10 |
| **S1-P8** Cash flow statement, indirect (`spec/s1/cash-flow`) | S1-R2, R2.1 | `app/Domain/AnnualReport/CashFlowStatement.php`, `CashFlowAccounts.php` (likvida medel default 1900–1999 confirmed by the user, overdraft 2330/2480 financing), `CashFlowPrintedFigures.php` | F-S1-a 2025 and 2026 to the öre (taxes paid separately under operating, dividends under financing, interest paid under operating); reconciles to the change in likvida medel; an unclassified account with a movement blocks with a list (spec Behaviour 4); F-S1-c: no statement with policy `none` for a mindre company; `none` refused for a större företag | P2, P3, P6 | P10, P13 |
| **S1-P9** K3 notes library and management report (`spec/s1/k3-notes`) | S1-R8, R8.1 rows 1–27, R8.4, R3.7, R4.7 (policy text only for mindre), R7.5, K3 8.6B | `app/Domain/AnnualReport/K3Notes.php`; migration (A.2.7 columns, `annual_report_note_overrides`); GDPR docs (register line for major owners) | one test per row 1–27: trigger true → note present with its data, false → absent; note 1 states ÅRL and K3 (8.3) and lists the policies in force; an override is stored, printed and marked; overrides change wording only; no större-only note for a mindre company | P3, P4, P5, P6 | P10 |
| **S1-P10** K3 document and preview (`spec/s1/k3-document`) | S1-R8.2, R9.3 (gate), K3 1.4/1.6; refusals for R6 (`chapter_12`), R10 (`capitalise`), R5 (`classify`), större (until H1), ekonomisk förening, `undetermined` size | `app/Domain/AnnualReport/K3AnnualReportDocument.php`, `resources/views/annual-report/k3-document.blade.php`, PDF, the Årsredovisning page (K3 preview, note list, override markers); **hot spot** `refusal()` routing (A.3) | full HTML of a synthetic **mindre** K3 AB (F-S1-b when frozen) against `expected.json`; every refusal reason with its rule id; K2 golden files byte-identical; fixed query count (A.5); accessibility of the preview | P1, P2, P3, P6, P7, P9 (P8 when the policy is `indirect`) | the accountant review (spec gate), P13 |
| **S1-P11** Bokslut → Regelverk (`spec/s1/framework-screen`) | S1-R1, R1.1 (g–i as confirmations), R1.2 b, R1.3, R1.4; S1-R4.8 | the screen; `app/Domain/AnnualReport/K3Eligibility.php`; confirmations as `closing_checks` keys | shows what changes and what is missing **before** saving (spec Behaviour 1); 1.1A a–f from data, g–i asked; 1.1C first paragraph computed from P2; the 75 % building test only from the user's classification of revenue; no suggestion about "väsentlig" deferred tax until **H2**; "normalt" asked with the wording from **H3** | P1, P2, P3; H2 and H3 for two texts (the rest is buildable) | — |
| **S1-P12** K2-to-K3 transition (`spec/s1/transition`) | S1-R7, R7.1, R7.2, R7.3, R7.4, R7.5, R7.6, R7.7 | `app/Domain/AnnualReport/K3TransitionProposal.php`, action, reconciliation sheet, comparability note | F-S1-b (the branch the fixture states) to the öre; no equity difference from a component split; the 35.27 relief only with a reason and disclosed; 35.1 once-only enforced unless `forced_2025_2`; transition booked once; K2 years and reports untouched | **H7** (booking form, equity account); P1, P3, P4, P5 | P10 for a first K3 year |
| **S1-P13** Större-företag statements and notes (`spec/s1/larger-company`) | S1-R9.3, R8 (ÅRL 5:25–48), R4.7 (5:36), R5.1 (20.14), R2.1 notes 7.18–7.20, K3 3.5, 8.4, 8.5 | notes and lifting the refusal in the K3 document | F-S1-a at document level: lease note (300 000; 901 000 → 601 000), tax note, deferred tax note, average employees 30 | **H1**; P8, P9, P10. The full 5:25–48 note list must be read first (rule sheet R8.1 lists what is *not* required of a mindre company, not the full större set) | — |
| **S1-P14** Performance (`spec/s1/performance`) | spec Part 2 | `tests/Performance/K3ReportPerformanceTest.php` (group `performance`) | A.5 | P10 | — |

Not in S1: K3 iXBRL (F.4, spec "Out of scope"); ekonomisk förening on K3; group
accounts (S2); computing successiv vinstavräkning, the capitalisation model,
capitalised borrowing costs, finance leases in the balance sheet and chapter 12
fair value (all refused by policy).

### A.5 Test plan and performance

**Unit tests per rule id** (Pest, `tests/Unit/AnnualReport/K3/…`), named with the
rule id, without a database where the class allows it (`K3CompanySize`,
`DepreciationPlan`, `DeferredTaxProposal` arithmetic, `ComponentSplit` allocation).
Every data row in `k3-rules.json` has a test that it carries source URL, quote,
status and date.

**Fixtures.** A loader in `tests/Support/S1Fixture.php` reads the Fixture author's
JSON (`tests/Fixtures/s1/f-s1-*/`) into a company inside `DatabaseTransactions`
(never `RefreshDatabase`), posting vouchers through `PostVoucher` so the ledger chain
holds. Builders do not edit fixture files; a mismatch goes to the Fixture author and
the Checker.
- **F-S1-a** (frozen, större, no transition): size test (P2), component schedule
  (P4), deferred tax (P5), K3 income statement and balance sheet (P6), equity
  statement (P7), cash flow (P8); document-level notes after H1 (P13). Vouchers with
  `proposal_kind` are dropped and the proposal compared with them.
- **F-S1-b** (K2-to-K3 transition, not yet frozen): P12 and the mindre K3 document
  (P10). The fixture must state whether comparatives are restated (S1-R7.1).
- **F-S1-c** (below the cash-flow requirement, not yet frozen): P8 (no statement with
  `none`; a voluntary one follows chapter 7) and P2 (classified mindre).

**Regression.** `AnnualReportGoldenTest` (K2 HTML and iXBRL byte for byte) runs
unchanged in every package; `tests/Fixtures/annual-report/**` is never rewritten
(`NONOX_UPDATE_GOLDEN` is not used in S1). The existing K2, closing, fixed asset and
income tax suites stay green.

**Tenancy** (`tests/Tenancy`): `company_accounting_policies`,
`prior_year_size_figures`, `deferred_tax_items`, `annual_report_note_overrides`,
components (a child of another company's asset is impossible and 404s), and the K3
preview and PDF routes (another company's fiscal year → 404).

**Immutability.** Splitting an asset leaves `fixed_asset_depreciations` and posted
vouchers unchanged (asserted by row counts and `ledger:verify`); deferred tax and
transition are new vouchers through `PostVoucher`/`BookVoucher`;
`YearEndEntry::DEFERRED_TAX` is append-only like the other types.

**Permissions** per role (spec "Permissions"): owner and admin change the
framework; accountant prepares policies, components, deferred tax and the report;
auditor reads and exports.

**Performance (spec Part 2: 50 000 vouchers in one year in under 3 s).**
- The report reads the ledger with aggregate SQL, never row by row: `AnnualAccounts`
  already makes three grouped queries per year (opening balances, debit and credit
  per account, the goods-for-resale accounts). K3 adds a fixed set: debit and
  credit per account **and voucher source** for the current year (equity statement
  and cash flow, one grouped query), policies (1), size figures (≤ 2), deferred tax
  items (1), the fixed asset register with children eager-loaded (1), note
  overrides (1). The count does not depend on the number of vouchers or assets; a
  query-count test with a short and a long ledger proves it (QUICKSTART §6).
- The grouped ledger query joins `voucher_rows` to `vouchers` filtered on
  `company_id`, `fiscal_year_id` and `status`. The unique index
  `(company_id, fiscal_year_id, series_id, number)` serves the voucher filter;
  `voucher_rows` has no index on `voucher_id`. P14 runs `EXPLAIN (ANALYZE, BUFFERS)`
  on 50 000 vouchers × 4 rows and adds an index on `voucher_rows (voucher_id)` in
  its own migration only if the plan shows a sequential scan dominating; the
  decision and the numbers go into this ADR.
- P14's test seeds 50 000 balanced posted vouchers (200 000 rows) with a bulk insert
  into `nonox_test_s1_performance` inside a transaction and times
  `K3AnnualReportDocument::data()` plus the HTML render (not Dompdf, whose time
  depends on the page count, not on the vouchers). Budget: under 3 s on the
  reference setup (Linux, PostgreSQL 16, as CI). On a developer Mac the test reports
  the time and fails only above 9 s, because Herd on macOS was measured far slower
  per query than the Linux server. The test is in group `performance` and runs in
  CI on `main`, not in every local run.

### A.6 Human decisions S1 depends on

The Lead translates these into `docs/status/manskliga-beslut.md`. Deadlines assume
calendar-year companies closing 2026 in early 2027 (the first year BFNAR 2025:2
forces companies off K2) and the package order above.

| # | Question | Options | Recommendation | Blocks | Deadline |
|---|---|---|---|---|---|
| H1 | Does S1 support the fileable K3 report for a **större** (unlisted) AB, as F-S1-a assumes? (§2 refused it; S1-R9.3) | (a) keep refusing; F-S1-a proves figures only; (b) support it in S1 with the ÅRL 5:25–48 notes after a full read; (c) a later item | (a) now, (b) as S1-P13 once the 5:25–48 list is read and the accountant checklist covers it | S1-P13; F-S1-a document-level acceptance | 2026-11-21 (before P10 starts) |
| H2 | K2 1.1B a: what is a "väsentlig" deferred tax liability, and may NONOX **suggest** (never decide) that a K2 company is caught? (S1-R1.2 a, R4.8) | (a) show the computed deferred tax as information only; (b) also warn above a threshold a human sets; (c) show nothing | (a): information with the K2 1.1B text, no threshold | the deferred-tax line on Bokslut → Regelverk (P11) | 2026-12-05 |
| H3 | K2 1.1C second paragraph: wording of the confirmation "normalt inte omfattas av punkt 1.1B" (S1-R1.3) | (a) quote BFN's commentary ("mer än något eller några enstaka år") in a yes/no confirmation; (b) leave it to the consultant, no question | (a) | one confirmation on P11 | 2026-12-05 |
| H4 | Component policy: when is a component "betydande" and the difference "väsentlig"? (S1-R3.1) | (a) buildings are always split into land and named components, other assets only on user choice; (b) every asset on user choice; (c) a value threshold | (a), with the 17.4 building example as the template | the `components` policy row and the report confirmation (P4, P10) | 2026-11-28 |
| H5 | K3 29.19 refers to "29.21 och 29.22" and 29.25 to "29.28"; the content sits at 29.20/29.21 and 29.26. Confirm these are cross-reference slips that change nothing for a NONOX AB (S1-R4, R4.3) | (a) confirm as slips; (b) ask BFN | (a), noted in the systemdokumentation | sign-off of P5 (not its build) | before P5 merges |
| H6 | Deferred tax **asset** (loss carry-forwards): may NONOX propose an amount, and on what basis after recent losses? (S1-R4.5) | (a) NONOX shows the gross asset and a person enters the recognised amount with a reason (default 0); (b) NONOX proposes the full amount; (c) assets not supported in S1 | (a) | the asset side of P5 | 2026-11-28 |
| H7 | How is the K2-to-K3 transition difference booked, and against which equity account? (S1-R7.7; K3 35.11 is silent) | (a) a new voucher dated the first day of the first K3 year, source `K3_TRANSITION`, against 2091 Balanserad vinst eller förlust; (b) an adjustment of that year's opening balances (IB ≠ last K2 UB, confirmed with the existing ÅRL 2:4 "skillnaden är avsiktlig" check); (c) another account | (a): keeps IB = UB and the hash chain, leaves an audit trail, and shows in equity statement row (b) | S1-P12 entirely | 2026-12-05 |
| H8 | ÅRL 1:3 p. 4 "mer än ett": must it be the **same** two criteria in both years? How is a company with fewer than two prior years classified? (S1-R9, R9.1) | (a) the same two (as BFN reads K2 1.1C); (b) any two each year; new company: mindre unless listed | (a), and "mindre unless listed"; until then `undetermined` blocks only the rare cases where the readings differ | resolving `undetermined` in P2 (P2 itself is not blocked) | 2026-12-19 |
| H9 | Capitalisation model and the development fund: which BAS accounts identify capitalised development (10xx) and the fund (2089?) for the refusal check, and is the capitalisation model ever in scope? (S1-R10.1, R10.3) | (a) refuse by policy only; (b) also refuse when the ledger has balances on accounts a human names | (b), with the accounts confirmed against BAS 2026 by a human | the ledger-based refusal in P10 (the policy refusal works without it) | before P10 merges |
| H10 | Name the human accountant (authorised or approved) who signs the accountant checklist (decision 3 above, spec "Human decisions") | — | — | the Beta gate of P10 | 2026-12-19 |

Earlier decisions in this ADR stand: 1 (scope, now refined by H1), 2 (features in
F.3: components, deferred tax, transition), 3 (accountant checklist, H10) and 4
(telling K2 companies to re-check 1.1B, owner decision 16 already made).

Medium-confidence items settled by this addendum without a human decision (the rule
sheet's list): R1.1 g–i as user confirmations (P11); R2.1 account classification
confirmed by the user, an unclassified account blocks (P8); R3.4 monthly granularity
(A.2.4); R3.6 "mindre värde" — no amount coded, the company's own
`asset_small_value_threshold_ore` stays; R4.1 tax values entered or taken from INK2
or the register, with the source shown per line (A.2.6); R4.6 netting only with a
confirmation (A.2.6); R5 "alla" as one policy (A.2.3); R7.3 "oskälig kostnad" as a
confirmation with a reason (A.2.8); R8.1 rows 1–27 as the minimum, with the unread
K3 chapters (9, 13–16, 19, 21–28, 30–34) as an open `TODO(legal-verify)` for the
accountant checklist; R8.3 the transition in row (b) (A.1.5).

### A.7 Consequences

- A fiscal year carries its own framework; an old K2 report stays printable after a
  switch, and the change is in the audit log.
- Three packages (P1–P3) can start now without any human answer; components (P4),
  the deferred tax liability (P5), the K3 schedule (P6), the equity statement (P7)
  and the cash flow statement (P8) need none for their build either, only for two
  confirmations and the deferred tax asset side.
- The K3 document ships for a mindre AB first; a större AB gets figures only until
  H1. F.1's guard stays until P10 replaces it for K3 years.
- Hot-spot changes are limited to four small parameter-with-K2-default edits in
  `AnnualReportDocument` and `PrintedFigures`, each guarded by the byte-identical
  K2 golden files.
