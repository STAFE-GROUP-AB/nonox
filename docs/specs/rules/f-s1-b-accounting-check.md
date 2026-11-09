# F-S1-b accounting check (K2 2025 → K3 2026, mindre företag)

Checker: independent accounting check, 2026-11-08. Read cold from branch
`spec/s1/transition`, folder `tests/Fixtures/s1/f-s1-b/` (README, company, opening_balances,
vouchers, expected). The fixture was not edited and nothing was committed.

Every figure was recomputed independently in integer öre with a throwaway script
(`fs1b_recompute.py` in the session scratchpad, not committed). It derives the 2025 figures
from the facts (sales, costs, rates) and not from the fixture's own vouchers, then posts the
vouchers onto the 2025 opening balances and compares the result with the 2026 balances.

## Sources read

| Source | Version read | Points |
|---|---|---|
| BFN, vägledning K3 (BFNAR 2012:1), "Uppdaterad 2025-12-15", bfn.se | local copy of the PDF/text | 35.1–35.11, 35.15, 35.18, 35.27, 35.30, 35.31, 35.33, 35.33A (pp. 283–289); 29.12–29.32, 29.37 (pp. 262–269); 21.4–21.6 (p. 210); transitional provisions BFNAR 2025:3 points 1–2 |
| BFN, vägledning K2 (BFNAR 2016:10), "Uppdaterad 2025-06-16", bfn.se | local copy | 1.1A–1.1C, 16.2 with comment (p. 264), 20.2 f |
| Inkomstskattelagen (1999:1229), riksdagen.se | local copy | 1 kap. 7 §, 16 kap. 3–5 §§, 65 kap. 10 § |
| Årsredovisningslagen (1995:1554), riksdagen.se | local copy | 1 kap. 3 § (size thresholds), 2 kap. 4 § p. 6 (no offsetting), 3 kap. 5 § fjärde stycket |
| BAS 2026 (bas.se, `bas2026.xlsx`) | local copy | 1370, 2091, 2220, 2240 |

Key quotes:

- K3 35.7 second paragraph: "För ett mindre företag som med stöd av 3 kap. 5 § fjärde stycket årsredovisningslagen (1995:1554) inte räknar om jämförelsetalen, är tidpunkten för övergång i stället första dagen på det räkenskapsår som detta allmänna råd tillämpas."
- K3 35.18: "Det redovisade värdet för dessa tillgångar ska, per tidpunkten för övergång, delas upp på betydande komponenter."
- K3 35.27: "… behöver inte redovisas i ingångsbalansräkningen, om uppgifterna inte kan tas fram utan oskälig kostnad."
- K3 21.4 a: "en befintlig legal förpliktelse eller informell förpliktelse till följd av en inträffad händelse"
- K2 16.2 comment: "Informella åtaganden får inte redovisas som skuld eller avsättning."
- K3 29.25: "… enligt de skattesatser och skatteregler som är beslutade före balansdagen. Beloppet får inte nuvärdeberäknas …"
- K3 29.32: "… uppskjutna skattefordringar och uppskjutna skatteskulder ska nettoredovisas endast om företaget har legal rätt till kvittning."
- IL 1 kap. 7 §: "Det återstående beloppet avrundas nedåt till helt tiotal kronor och är den fastställda och den beskattningsbara inkomsten."
- IL 65 kap. 10 §: "För juridiska personer är den statliga inkomstskatten 20,6 procent av den beskattningsbara inkomsten."
- BAS 2026: 1370 Uppskjuten skattefordran; 2091 Balanserad vinst eller förlust; 2220 Avsättningar för garantier; 2240 Avsättningar för uppskjutna skatter.

## Verdicts per figure

### The last K2 year (2025)

| Figure | Fixture | Recomputed | Verdict |
|---|---:|---:|---|
| Employer contributions 31,42 % × 1 200 000 | 377 040,00 | 377 040,00 | CONFIRMED |
| Result before tax | 1 082 960,00 | 1 082 960,00 | CONFIRMED |
| Taxable income (+120 000 book, −240 000 tax depreciation on the building) | 962 960 | 962 960 | CONFIRMED. IL 1:7 rounds a legal person's taxable income down to whole **tens** of kronor (not hundreds, which is the rule for natural persons in 1:5); 962 960 is already a whole ten, so no rounding effect. |
| Tax 20,6 % | 198 369,76 | 198 369,76 | CONFIRMED (IL 65:10) |
| Year's result | 884 590,24 | 884 590,24 | CONFIRMED |
| Bank 2025-12-31 | 1 982 960,00 | 1 982 960,00 | CONFIRMED |
| Equity 2025-12-31 | 4 544 590,24 | 4 544 590,24 | CONFIRMED |
| Balance sheet total 2025-12-31 | 7 942 960,00 | 7 942 960,00 | CONFIRMED |
| Every voucher balances; IB 2025 sums to 0; IB 2025 + A1–A11 = the 2026-01-01 balances on every account; result accounts net to 0 after A11 | — | true | CONFIRMED |
| Size: mindre företag (ÅRL 1:3: >50 employees, >40 MSEK balance sheet total, >80 MSEK turnover) | mindre | mindre | CONFIRMED |

### Transition date and branch

| Point | Verdict |
|---|---|
| Transition date 2026-01-01 for a mindre företag that does not restate comparatives | CONFIRMED (K3 35.7 second paragraph; ÅRL 3:5 fjärde stycket, with a note on the lack of comparability) |
| Chapter 35 allowed (`k3_applied_before` = no) | CONFIRMED (35.1). `forced_2025_2` is also plausible on the fixture's facts: K2 1.1B a (material deferred tax liability) applies because 1.1C first paragraph is not met (two criteria: balance sheet total > 1,5 MSEK and turnover > 3 MSEK; 3 employees is not "mer än 3"), and the 1.1C second-paragraph exception fails because the building difference is permanent. Whether 247 200 is "väsentlig" is a judgement the fixture does not document. It does not matter here, because 35.1 is satisfied anyway. |
| Rate 20,6 % for 2025 and for the 2026 opening balance sheet | CONFIRMED (IL 65:10, unchanged since beskattningsår beginning after 2020-12-31; K3 29.25, decided before the transition date) |

### Transition items

| Item | Fixture | Recomputed | Verdict |
|---|---:|---:|---|
| T1 carrying amount 1110–1119 | 4 800 000 | 6 000 000 − 1 200 000 = 4 800 000 | CONFIRMED |
| T1 tax value (4 % × 6 000 000 × 10 years, 2016–2025) | 3 600 000 | 3 600 000 | CONFIRMED arithmetically. The 4 % tax rate for a workshop building is the fixture's premise; Skatteverket's percentage list was not read (UNVERIFIABLE as a fact, harmless as a premise). |
| T1 taxable temporary difference (asset, carrying > tax value, 29.14) | 1 200 000 | 1 200 000 | CONFIRMED |
| T1 deferred tax liability 2240 | 247 200,00 | 247 200,00 | CONFIRMED. Treatment right: 35.8/35.9 a and 29.19 a require it in the opening balance sheet. The difference comes from later depreciation, not from initial recognition (29.21 covers only goodwill), and it is not an untaxed reserve (29.37 does not apply; building depreciation is not book-conforming). Measuring it on the unsplit K2 carrying amount is right, because 35.18 forbids restating the building. |
| T2 provision 2220 (informal warranty obligation) | 150 000,00 | 150 000,00 | CONFIRMED in treatment. K2 16.2 recognises only legal obligations and forbids informal ones; K3 21.4 a/21.6 requires them. 35.9 a puts it in the opening balance sheet against equity (35.11). This is a change of principle, not a changed estimate, so 35.15 does not block it. The amount itself is the fixture's premise (probable outflow and reliable estimate assumed). |
| T3 deductible difference (liability, carrying 150 000 > tax value 0) | 150 000 | 150 000 | CONFIRMED. 29.18: the payment will be deductible when made, so the tax value is 0. Whether IL 16:3–5 would allow a deduction for an informal obligation is a tax question the fixture settles by the person's assessment; tax value 0 is the cautious reading. |
| T3 deferred tax asset 1370 | 30 900,00 | 30 900,00 | CONFIRMED. 29.19 b; recoverability (29.29) is supported by taxable profits (962 960 in 2025) and by the reversing taxable difference T1. Recognising the full amount is right. |
| T4 equipment, 35.27 relief, nothing recognised | 0 | 0 | Figure CONFIRMED, treatment **WRONG on the fixture's own facts** (see below). |
| Total equity effect | −366 300,00 | −247 200 − 150 000 + 30 900 = −366 300,00 | CONFIRMED |

### Transition voucher

| Point | Verdict |
|---|---|
| Rows 2091 D 247 200 / 2240 C 247 200; 2091 D 150 000 / 2220 C 150 000; 1370 D 30 900 / 2091 C 30 900 | CONFIRMED; the accounts match BAS 2026 |
| Totals 428 100,00 each side | CONFIRMED |
| Net on 2091 −366 300,00 | CONFIRMED |
| A voucher dated 2026-01-01 against 2091, IB 2026 = UB 2025 (H7 a) | Acceptable. 35.11 only says "direkt mot eget kapital i ingångsbalansräkningen". IB + a day-one voucher gives that opening balance sheet, keeps continuity with the adopted K2 balance sheet, and the effect is visible in the equity statement. This is a bookkeeping design choice, not checkable against a source (stays `TODO(legal-verify)` per ADR-059). |

### Reconciliation

| Line | Fixture K3 | Recomputed | Verdict |
|---|---:|---:|---|
| Uppskjuten skattefordran | 30 900,00 | 30 900,00 | CONFIRMED (gross) |
| Balanserad vinst eller förlust | 3 193 700,00 | 3 560 000 − 366 300 = 3 193 700,00 | CONFIRMED |
| Uppskjuten skatteskuld | 247 200,00 | 247 200,00 | CONFIRMED (gross) |
| Övriga avsättningar | 150 000,00 | 150 000,00 | CONFIRMED |
| Equity K3 | 4 178 290,24 | 4 178 290,24 | CONFIRMED |
| Balance sheet total K3 | 7 973 860,00 | 7 973 860,00 gross | CONFIRMED for gross presentation; see open point 2 |

### Component split on 2026-01-01

| Component | Accumulated | Carrying | Per month | Verdict |
|---|---:|---:|---:|---|
| Stomme | 720 000 | 2 880 000 | 2 880 000 / 720 = 4 000 | CONFIRMED |
| Tak | 240 000 | 960 000 | 960 000 / 240 = 4 000 | CONFIRMED |
| Installationer | 240 000 | 960 000 | 960 000 / 192 = 5 000 | CONFIRMED |
| Sum per month / 2026 | | 4 800 000 | 13 000 / 156 000 | CONFIRMED (all divisions exact) |
| No equity difference, no voucher | | | | CONFIRMED (35.18: the carrying amount is split, not restated; every component stays on 1110/1119) |

The cost-proportional allocation of accumulated depreciation is NONOX's allocation key. 35.18 gives none, so it is UNVERIFIABLE against a source, but it is defensible. It means every component is treated as 20 % written off, whatever its life. A full retrospective split would have given the roof about 400 000 accumulated, not 240 000, and 35.18 forbids exactly that. The ADR already keeps the key as `TODO(legal-verify)`.

### Disclosures

First-time application (35.31), comparability lacking (35.7 / ÅRL 3:5 st. 4), relief 35.27 used (35.31), no 35.30 items, changed principles (35.33): deferred tax, provisions, components. CONFIRMED as a list. A reconciliation (35.33A) is required only of a större företag; giving it is voluntary and harmless.

## WRONG

1. **T4: the 35.27 relief contradicts the fixture's own facts.** The README computes the 2025
   taxable income with "the equipment follows the books", which means räkenskapsenlig
   avskrivning. The ledger has no överavskrivningar (no 2150), so the equipment's tax value is its
   book value, 160 000. That value can be read straight from the books at no cost, and the
   temporary difference is 0. The relief reason ("underlaget … finns inte kvar") therefore does
   not fit the case: 35.27 requires that "uppgifterna inte kan tas fram utan oskälig kostnad".
   Correct figure: deferred tax 0 either way (equity effect 0, voucher and totals unchanged).
   Correct treatment on these facts: no relief, tax value 160 000, difference 0, and no 35.27
   line in the disclosures. To keep a relief test, give T4 facts where the tax value really is
   unobtainable. For example: the equipment was taken over in an earlier
   restructuring and the previous owner's tax records are gone, and the 2025 tax computation does
   not claim that the equipment follows the books.

No other figure is wrong.

## Open points (not WRONG)

1. **T1 kind.** `deferred_tax_kind` `OTHER_ASSET` for a building item, while ADR-059 A.2.6 has
   `BUILDING_COMPONENTS`. This has no effect on the figures. Check that it is intended.
2. **Netting of 1370 against 2240 (K3 29.32).** ÅRL 2:4 p. 6 forbids offsetting, and 29.32
   allows netting only with a legal right of set-off. The 29.32 comment says deferred tax assets
   and liabilities are netted where that right exists for current tax. A single Swedish AB that
   pays all its income tax to Skatteverket normally has that right. If the person confirms it,
   the balance sheet shows a deferred tax liability of 216 300,00 and no deferred tax asset line,
   and the K3 total is 7 942 960,00 (unchanged from K2). Gross presentation (the fixture's) is
   lawful only while the right is not confirmed, which is the ADR's default. Recommend that the
   consultant decides, and that the fixture states "set-off not confirmed".
3. **Line label.** ÅRL bilaga 2 calls the item "Avsättningar för skatter", while the
   reconciliation says "Uppskjuten skatteskuld". The amount is unaffected. Align the label with
   the K3 schedule (S1-P6).
4. **`forced_2025_2`.** It is plausible (see the transition-date table), but the fixture does not
   say which K2 1.1A/1.1B point forces the company off K2. Add one sentence (1.1B a, assessed on
   the 2025 annual report).

## Re-check 732be4c3

Checker, 2026-11-09. Read with `git show 732be4c3:tests/Fixtures/s1/f-s1-b/*`, focusing on the
diff from 760a32f5. No amount changed, and I confirm that. Extra sources read: IL 18 kap.
12–19 §§ (inventarier) and 23 kap. 10 § (underprisöverlåtelser), and ÅRL bilaga 2 (balance
sheet items).

### 1. T4 under the 35.27 relief, and the 2025 tax: **still WRONG**

The new facts do not make the tax value unobtainable, and they open a gap in the 2025 tax
computation.

- **The year-end tax value under räkenskapsenlig avskrivning is the balance sheet value.**
  The README says the return deducts the booked 80 000, which is räkenskapsenlig avskrivning
  (IL 18:14: "om avdraget motsvarar avskrivningen i bokslutet"). The base for the next year is
  then "värdet enligt balansräkningen vid räkenskapsenlig avskrivning" (IL 18:13 andra stycket
  1 a). The tax value taken over under IL 23:10 enters only as the acquisition value in 2023,
  and from 2024 the base is the book value. So at 2025-12-31 the tax value is 160 000, plus any
  excess carried under IL 18:19 if a deduction ever exceeded the limit. It can be read from the
  company's own balance sheet. That the transferor's records are lost does not make it
  unobtainable, so 35.27 does not apply.
- **The 2025 deduction of 80 000 is not supported on these facts.**
  - The 30 % rule gives 30 % × 240 000 (balance sheet value at 2024-12-31) = 72 000.
  - Reaching 80 000 needs the 20 % rule (IL 18:17): the tax value may be brought down to
    "anskaffningsvärdet … minskat med en beräknad årlig avskrivning med 20 procent", that is
    acquisition value × 40 % after three years. For 80 000 that requires a tax acquisition value
    of at most 400 000.
  - IL 23:10 changes the acquisition value only when the price is **below** the transferor's
    tax value: "Tillgången anses i sådant fall förvärvad för samma belopp". If the booked cost
    of 400 000 is the price, which the fixture does not say, the tax acquisition value is above
    400 000. The 2025 deduction is then capped at max(72 000; 240 000 − 0,4 × value), which is
    below 80 000.
  - Taxable income 962 960 and tax 198 369,76 therefore hold only if the tax acquisition value
    is at most 400 000. That contradicts the premise that IL 23:10 applied, unless the books
    record the inherited value as cost, in which case the value is known from the company's own
    books.
  - The company would also have needed the inherited value for its own 2023 and 2025 deduction
    limits. Its own tax computations are räkenskapsinformation that must be kept (BFL 7 kap.).
- **Figures unchanged:** carrying amount 160 000, deferred tax 0, equity effect 0. These stay
  CONFIRMED, because the difference is 0 on any lawful reading of the facts above.
- **Recommendation:** in this fixture, drop the relief for T4: tax value 160 000, difference 0,
  no 35.27 line in the disclosures, and remove the underprisöverlåtelse story. 2025 then holds
  exactly as computed, with a tax acquisition value of 400 000, the 20 % rule floor at 160 000
  and the 80 000 deduction allowed. Test the 35.27 mechanism (reason required, disclosed under
  35.31, nothing recognised) in a feature test that does not claim to be a realistic case. A
  credible relief case in a ledger fixture is hard to build for equipment, because a company
  that files returns holds its own tax computations.

### 2. Netting (29.32): CONFIRMED

- Gross, which is the default because set-off is not confirmed: deferred tax asset 30 900,
  Avsättningar för skatter 247 200, total 7 973 860.
- Netted (`reconciliation_set_off`): 247 200 − 30 900 = 216 300 and no asset line. Total
  7 942 960: equity and liabilities are 4 178 290,24 + 216 300 + 150 000 + 3 000 000 + 200 000
  + 198 369,76 = 7 942 960. Equity is 4 178 290,24 in both branches, and the voucher is the
  same.
- The wording "kvittningsrätt ej bekräftad" and the ÅRL 2:4 p. 6 / 29.32 reasoning are right.

### 3. "Avsättningar för skatter": CONFIRMED

ÅRL bilaga 2, C. Avsättningar, item 2 "Avsättningar för skatter". K3 29.31 classifies the
deferred tax liability as an avsättning. The asset has no item of its own in bilaga 2; K3
29.19 (own line) and 29.31 (financial fixed asset) give the line "Uppskjuten skattefordran"
used in the gross sheet. That is right.

### 4. Reason for leaving K2, and the TODO premises

- **forced_2025_2 through 1.1B a: CONFIRMED in substance, with two gaps.**
  1. 1.1C first paragraph speaks of "vart och ett av de två senaste räkenskapsåren". The README
     cites only 2025. The 2024 balance sheet total (7 360 000 at 2024-12-31, from the 2025
     opening balances) also exceeds 1,5 MSEK. 2024 net turnover is not in the fixture: state it
     (> 3 MSEK) or point to the balance sheet total and the turnover for both years.
  2. 1.1C second paragraph is the exception for a company that applied K2 the year before and
     "normalt inte omfattas av punkt 1.1B". It applies on its face here (K2 in 2025). Add one
     sentence saying it fails because the building difference is lasting and growing (4 % for
     tax against 2 % in the books), so the company normally is covered by 1.1B a.

  Neither gap changes a figure, and 35.1 allows chapter 35 anyway.
- **TODO(legal-verify) premises: CONFIRMED.** The 4 % tax depreciation on the building and the
  cost-proportional allocation key for the components (35.18 gives no key) are both marked.
- **T1 kind `BUILDING_COMPONENTS`: CONFIRMED** (matches ADR-059 A.2.6).

### Re-check summary

WRONG: T4. Under räkenskapsenlig avskrivning the year-end tax value is the book value (IL 18:13
andra stycket 1 a), so the 35.27 relief still has no basis. On the new facts the 2025 deduction
of 80 000 is allowed only if the tax acquisition value is at most 400 000 (IL 18:17), which
conflicts with IL 23:10 applying. The amounts in the fixture are unchanged and correct once
the relief and the underprisöverlåtelse premise are dropped.

CONFIRMED: netting branch (216 300; 7 942 960), "Avsättningar för skatter", the T1 kind, the
two TODO premises, and the reason for leaving K2, which needs two additions (both years for
1.1C, and the 1.1C second paragraph).

## Re-check 25abaa80

Checker, 2026-11-09. Read the diff 732be4c3..25abaa80 for `tests/Fixtures/s1/f-s1-b/` and
reran the independent recomputation on the new files. Every assertion passes: the vouchers
balance, the 2025 ledger rolls forward to the 2026 opening balances, and all transition,
reconciliation and component figures are unchanged. `vouchers.json` and
`opening_balances.json` are unchanged; `expected.json` is reformatted, with the same values
except T4 and the disclosures.

1. **T4: CONFIRMED.** The equipment was bought for 400 000 on 2023-01-01 (tax acquisition value
   400 000) and is depreciated räkenskapsenligt by 80 000 a year. The limits hold:
   - 2023: 30 % × 400 000 = 120 000.
   - 2024: 30 % × 320 000 = 96 000.
   - 2025: the 30 % rule gives 30 % × 240 000 = 72 000, so the 20 % rule (IL 18:17) is needed.
     It allows a floor of 400 000 × 40 % = 160 000, so 80 000 is allowed.

   Tax value 160 000 = carrying amount, difference 0, no relief. 2025 taxable income
   962 960, tax 198 369,76. Citation nit, not an error: the README cites "IL 18 kap. 13–14 §§,
   the 20 % rule", but the 20 % rule itself is 18:17. Citing 13, 14 and 17 §§ would be exact.
2. **1.1C table: CONFIRMED.**
   - 2024 balance sheet total 7 360 000, from the assets at 2025-01-01:
     6 000 000 − 1 080 000 + 1 000 000 + 400 000 − 160 000 + 1 200 000.
   - 2025: 7 942 960.
   - Net turnover 4 800 000 (2024, a premise in `prior_year_figures_2024`) and 5 000 000 (2025).
   - 3 employees in both years, which is not "mer än 3".

   Two criteria are exceeded in both years, so the first paragraph is not met.
3. **Second-paragraph sentence: CONFIRMED.** K2 1.1C second paragraph requires the company both
   to have applied K2 the year before and to "normalt inte omfattas av punkt 1.1B". The
   difference grows every year (4 % for tax against 2 % in the books), so the company normally
   is covered and the exception fails. The README's wording about being "caught for a single or
   a few years" is a fair paraphrase of "normalt".
4. **Disclosures: CONFIRMED.** "No relief in 35.21–35.30 is used"; `reliefs_used` and
   `relief_items` are empty. That is consistent with 35.31, which then has nothing to list
   beyond first-time application.

**Verdict: confirmed.** Nothing is wrong. The open points from the first check are resolved,
and the only remaining remark is the citation nit in point 1.
