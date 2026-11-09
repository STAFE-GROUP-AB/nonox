# F-S1-b: Fiktiva Övergångsbolaget AB (K2 2025 → K3 2026, mindre företag)

Status: ready for checking (written 2026-11-08 by the S1-P12 builder, **not** by the
independent Fixture author; the Checker should re-derive every figure by hand).
Revised 2026-11-09 after the independent accounting check
(`docs/specs/rules/f-s1-b-accounting-check.md`): T4's facts now support the 35.27 relief,
T1 has the kind `BUILDING_COMPONENTS`, the netted presentation (29.32) is added, the line
label follows ÅRL ("Avsättningar för skatter"), and the reason for leaving K2 is stated.
No amount changed.

Fixture for spec item **S1 K3 annual report** (`docs/specs/nummer-1-spec.md`, Part 3,
"F-S1-b: K2-to-K3 transition with prior-year comparatives") and ADR-059 A.4/A.5
(package S1-P12). Rule ids are those of the S1 rule sheet (`docs/specs/rules/s1.md`).

Everything here is synthetic. The company, the building and every amount are invented.
`559999-6062` is a test organisation number already in the repository's synthetic
registry test data; it passes Luhn. Nothing here describes a company that may hold it.

## Which branch of S1-R7.1 this fixture tests

**Comparatives are not restated** (`comparatives_restated_at_transition` = `no`). The
company is a mindre företag that uses ÅRL 3 kap. 5 § fjärde stycket, so (K3 35.7) the
**transition date is 2026-01-01**, the first day of its first K3 year. The 2025 figures
stay K2 figures and the notes say that comparability with 2025 is lacking. The branch
with restated comparatives (transition date 2025-01-01) is not in this fixture; NONOX
refuses it for now (ADR-059 A.2.3, "`no` first").

## Files

| File | Content |
|---|---|
| `company.json` | Company, fiscal years 2025 (K2) and 2026 (K3), the 2026 policies, the building in the register with its component split, the four transition items a person enters, accounts used |
| `opening_balances.json` | Balances at 2025-01-01 and at 2026-01-01 (debit positive) |
| `vouchers.json` | 11 balanced vouchers for 2025 (yearly summaries) and the transition voucher of 2026-01-01 (`proposal_kind` `k3_transition`, dropped by a test and compared with NONOX's proposal) |
| `expected.json` | The last K2 year, the transition items, the voucher, the reconciliation sheet, the component split and the disclosures |

Conventions: integer öre in the JSON, kronor in this README. Opening balances: debit
positive. Equity effect: a decrease is negative.

## The company

An aktiebolag that owns its workshop building. It has applied K2 and moves to K3 from
2026 (`left_k2_reason` = `forced_2025_2`, `k3_applied_before` = `no`). Size: 5 MSEK
net turnover, 8 MSEK total assets, 3 employees: a mindre företag by a wide margin.

**Why it must leave K2.** K2 1.1B a (BFNAR 2025:2): the company has a deferred tax
liability that is judged väsentlig, 247 200 on the building (T1 below), assessed on the
2025 annual report. The 1.1C exception does not save it: its first paragraph needs at most
one of the three criteria to be exceeded, and in 2025 two are (balance sheet total
7 942 960 > 1,5 MSEK and net turnover 5 000 000 > 3 MSEK; 3 employees is not "mer än 3").
So `left_k2_reason` = `forced_2025_2`. Whether 247 200 is "väsentlig" is the person's
judgement (human decision H2: NONOX only shows the figure). Chapter 35 is allowed anyway,
because K3 was not applied before (35.1).

The equipment (1220/1229) was taken over on 2023-01-01 in an underprisöverlåtelse from a
company that has since been liquidated. The company's own return deducts the booked
depreciation (80 000 a year), but the tax value it took over at the transfer is shown only
in the transferor's tax records, which no longer exist (see T4).

### Opening balances 2025-01-01 (kronor)

| Account | | Debit | Credit |
|---|---|---:|---:|
| 1110 | Byggnader (cost 2016-01-01) | 6 000 000 | |
| 1119 | Ack. avskrivningar byggnader (9 × 120 000) | | 1 080 000 |
| 1130 | Mark | 1 000 000 | |
| 1220 | Inventarier (cost 2023-01-01) | 400 000 | |
| 1229 | Ack. avskrivningar inventarier (2 × 80 000) | | 160 000 |
| 1930 | Bank | 1 200 000 | |
| 2081 | Aktiekapital | | 100 000 |
| 2091 | Balanserad vinst | | 3 260 000 |
| 2099 | Årets resultat 2024 | | 300 000 |
| 2350 | Banklån | | 3 500 000 |
| 2440 | Leverantörsskulder | | 200 000 |
| | **Sum** | **8 600 000** | **8 600 000** |

### The year 2025 under K2 (vouchers A1–A11)

| Voucher | Event | Rows (kronor) |
|---|---|---|
| A1 2025-05-20 | 2024 result to retained earnings | 2099 D 300 000 / 2091 C 300 000 |
| A2 | Sales, paid | 1930 D 6 250 000 / 3001 C 5 000 000, 2611 C 1 250 000 |
| A3 | Materials, paid | 4010 D 2 000 000, 2640 D 500 000 / 1930 C 2 500 000 |
| A4 | Wages and contributions (31,42 % × 1 200 000 = 377 040), paid | 7210 D 1 200 000, 7510 D 377 040 / 1930 C 1 577 040 |
| A5 | VAT settled and paid: 1 250 000 − 500 000 = 750 000 | 2611 D 1 250 000 / 2640 C 500 000, 1930 C 750 000 |
| A6 | Interest | 8410 D 140 000 / 1930 C 140 000 |
| A7 | Amortisation | 2350 D 500 000 / 1930 C 500 000 |
| A8 | Building depreciation, K2 straight line 2 % × 6 000 000 | 7820 D 120 000 / 1119 C 120 000 |
| A9 | Equipment depreciation 20 % × 400 000 | 7832 D 80 000 / 1229 C 80 000 |
| A10 | Income tax (below) | 8910 D 198 369,76 / 2512 C 198 369,76 |
| A11 | The year's result to 2099 (as YearEndClosing books it) | 8999 D 884 590,24 / 2099 C 884 590,24 |

Result before tax: 5 000 000 − 2 000 000 − 1 200 000 − 377 040 − 120 000 − 80 000 −
140 000 = **1 082 960**.

Taxable income: the building is depreciated by 4 % a year for tax (240 000) but by 2 % in
the books (120 000) (`TODO(legal-verify)`: the 4 % tax rate for a workshop building is this
fixture's premise; Skatteverket's percentage list was not read). For the equipment the
return deducts the booked 80 000, so no adjustment is made for it in 2025 (its tax value
taken over in 2023 is unknown, see T4). 1 082 960 + 120 000 − 240 000 =
**962 960**. Tax 20,6 % × 962 960 = **198 369,76** (962 960 × 206 = 198 369 760 thousandths
of a krona). Year's result 1 082 960 − 198 369,76 = **884 590,24**.

Bank 2025-12-31: 1 200 000 + 6 250 000 − 2 500 000 − 1 577 040 − 750 000 − 140 000 −
500 000 = **1 982 960**.

### Balances 2026-01-01 = closing balances 2025 (kronor)

| Account | | Debit | Credit |
|---|---|---:|---:|
| 1110 | Byggnader | 6 000 000 | |
| 1119 | Ack. avskrivningar byggnader (1 080 000 + 120 000) | | 1 200 000 |
| 1130 | Mark | 1 000 000 | |
| 1220 | Inventarier | 400 000 | |
| 1229 | Ack. avskrivningar inventarier (160 000 + 80 000) | | 240 000 |
| 1930 | Bank | 1 982 960 | |
| 2081 | Aktiekapital | | 100 000 |
| 2091 | Balanserad vinst (3 260 000 + 300 000) | | 3 560 000 |
| 2099 | Årets resultat 2025 | | 884 590,24 |
| 2350 | Banklån (3 500 000 − 500 000) | | 3 000 000 |
| 2440 | Leverantörsskulder | | 200 000 |
| 2512 | Beräknad inkomstskatt | | 198 369,76 |
| | **Sum** | **9 382 960** | **9 382 960** |

Total assets (balansomslutning) 2025-12-31: 6 000 000 − 1 200 000 + 1 000 000 + 400 000 −
240 000 + 1 982 960 = **7 942 960**. Equity: 100 000 + 3 560 000 + 884 590,24 =
**4 544 590,24**. IB 2026 equals UB 2025 on every account (S1-R7.7, H7: IB stays equal to
the previous UB; the transition is a voucher, not an opening balance change).

## The transition on 2026-01-01

Rate: 20,6 % (IL 65 kap. 10 §, the rate for a beskattningsår beginning 2026-01-01; K3
29.25). Chapter 35 is allowed: K3 was not applied before (35.1); the company is also
forced off K2 (BFNAR 2025:3 transitional provision 2, S1-R7.6).

| Item | What | Carrying | Tax value | Difference | Deferred tax | Equity effect |
|---|---|---:|---:|---:|---:|---:|
| T1 | Building, accounts 1110–1119: 6 000 000 − 1 200 000 | 4 800 000 | 3 600 000 | 1 200 000 taxable | 20,6 % × 1 200 000 = **247 200,00** liability (2240) | −247 200,00 |
| T2 | Provision for warranty work beyond the contracts, an informal obligation (K3 21.4); not recognised under K2 | | | | | −150 000,00 (2220) |
| T3 | Deferred tax on T2: a liability of 150 000 with tax value 0 | 150 000 | 0 | 150 000 deductible | 20,6 % × 150 000 = 30 900,00, recognised **30 900,00** (with a reason) asset (1370) | +30 900,00 |
| T4 | Equipment taken over in a 2023 underprisöverlåtelse, accounts 1220–1229: 400 000 − 240 000 | 160 000 | not obtainable: the tax value taken over is only in the liquidated transferor's records, which are gone | — | **35.27 relief used, with that reason**: nothing recognised, disclosed (35.31) | 0 |
| | **Total** | | | | | **−366 300,00** |

Tax value of the building: 6 000 000 − 10 years × 240 000 = 3 600 000 (the 4 % premise
above). Its land (1130) has the same carrying amount and tax value (1 000 000), so no item.
T1 has the kind `BUILDING_COMPONENTS` (ADR-059 A.2.6), read from accounts 1110–1119.

### The transition voucher (source `K3_TRANSITION`, dated 2026-01-01, against 2091)

| Account | Debit | Credit |
|---|---:|---:|
| 2091 Balanserad vinst eller förlust | 247 200,00 | |
| 2240 Avsättningar för uppskjutna skatter | | 247 200,00 |
| 2091 Balanserad vinst eller förlust | 150 000,00 | |
| 2220 Avsättningar för garantier | | 150 000,00 |
| 1370 Uppskjuten skattefordran | 30 900,00 | |
| 2091 Balanserad vinst eller förlust | | 30 900,00 |
| **Sum** | **428 100,00** | **428 100,00** |

Net on 2091: −247 200 − 150 000 + 30 900 = **−366 300,00**.

### Reconciliation sheet (balance sheet lines that change)

| Line | K2, 2025-12-31 | Transition | K3, 2026-01-01 |
|---|---:|---:|---:|
| Uppskjuten skattefordran | 0 | 30 900,00 | 30 900,00 |
| Balanserad vinst eller förlust | 3 560 000,00 | −366 300,00 | 3 193 700,00 |
| Avsättningar för skatter | 0 | 247 200,00 | 247 200,00 |
| Övriga avsättningar | 0 | 150 000,00 | 150 000,00 |
| **Eget kapital** | **4 544 590,24** | **−366 300,00** | **4 178 290,24** |
| **Balansomslutning** | **7 942 960,00** | **30 900,00** | **7 973 860,00** |

The sheet is **gross**: kvittningsrätt ej bekräftad. ÅRL 2 kap. 4 § p. 6 forbids
offsetting, and K3 29.32 allows netting a deferred tax asset against a deferred tax
liability only when the company has a legal right of set-off. The person has not confirmed
it here. (The deferred tax liability is shown under the ÅRL item "Avsättningar för
skatter".)

If the person confirms the right of set-off ("Kvittningsrätt för uppskjuten skatt finns"),
only the presentation changes, never the voucher:

| Line | K2, 2025-12-31 | Transition | K3, 2026-01-01 |
|---|---:|---:|---:|
| Balanserad vinst eller förlust | 3 560 000,00 | −366 300,00 | 3 193 700,00 |
| Avsättningar för skatter | 0 | 247 200 − 30 900 = 216 300,00 | 216 300,00 |
| Övriga avsättningar | 0 | 150 000,00 | 150 000,00 |
| **Eget kapital** | **4 544 590,24** | **−366 300,00** | **4 178 290,24** |
| **Balansomslutning** | **7 942 960,00** | **0** | **7 942 960,00** |

No deferred tax asset line is shown, and the balance sheet total stays the K2 total.

Check of the K3 side: assets 7 942 960 + 30 900 = 7 973 860; equity and liabilities
4 178 290,24 + 247 200 + 150 000 + 3 000 000 + 200 000 + 198 369,76 = 7 973 860.

### Component split (no equity difference, S1-R7.2 a, K3 35.18)

The building is registered whole (cost 6 000 000, 600 months from 2016-01, written off
before the register 1 200 000 = 120 months × 10 000) and split on 2026-01-01. NONOX
allocates the accumulated depreciation in proportion to cost (`TODO(legal-verify)`: 35.18
gives no allocation key; the cost-proportional key is NONOX's own, and it treats every
component as 20 % written off whatever its life):

| Component | Cost | Accumulated | Carrying | Remaining life | Total life | A month |
|---|---:|---:|---:|---:|---:|---:|
| Stomme | 3 600 000 | 1 200 000 × 3,6/6 = 720 000 | 2 880 000 | 720 | 840 | 2 880 000 / 720 = 4 000 |
| Tak | 1 200 000 | 240 000 | 960 000 | 240 | 360 | 960 000 / 240 = 4 000 |
| Installationer | 1 200 000 | 240 000 | 960 000 | 192 | 312 | 960 000 / 192 = 5 000 |
| **Sum** | **6 000 000** | **1 200 000** | **4 800 000** | | | **13 000** |

Carrying amount before the split 4 800 000 = after the split 4 800 000: **no equity
difference**. Every component keeps 1110/1119, so **no voucher** either. The total life is
the remaining life plus the 120 months already gone. Depreciation 2026: 12 × 13 000 =
**156 000** (K2 would have been 120 000); the difference is prospective, not a transition
item. T1 reads 1110–1119 and is the same whether or not the building is split.

### Disclosures (S1-R7.1, S1-R7.5)

- K3 is applied for the first time (35.31).
- Comparatives are not restated; comparability with 2025 is lacking (35.7, ÅRL 3 kap. 5 §
  fjärde stycket).
- Relief used: 35.27, for "Inventarier övertagna vid underprisöverlåtelse 2023", with the
  person's reason (35.31).
- Deferred tax shown gross; the right of set-off (29.32) is not confirmed.
- Items not restated under 35.30: none.
- Changed principles (35.33): deferred tax (K3 chapter 29), provisions for informal
  obligations (K3 chapter 21), component depreciation of buildings (K3 17.4).
- Effect on equity at 2026-01-01: −366 300,00.

## Self-check (2026-11-08)

A throwaway script in the session scratchpad (not committed) built the three ledger files
and asserted: every voucher balances; both opening balance dates sum to zero; the closing
balance of 2025 (IB 2025 + A1–A11) equals the 2026-01-01 balances on every balance sheet
account, and the income statement accounts net to zero after A11. The transition figures
above were computed by hand and re-checked against the voucher in `vouchers.json`.
