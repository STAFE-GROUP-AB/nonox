# F-S1-a: Fiktiva Komponentverkstaden AB (K3, större företag)

Status: ready (frozen 2026-11-07)

Fixture for spec item **S1 K3 annual report** (`docs/specs/nummer-1-spec.md`, Part 3).
Written by the Fixture author from the rules and the spec, **independently of any
implementation** (no code under `app/Domain/AnnualReport/**` or `app/Domain/Group/**`
was read). Builders make their output match `expected.json` to the öre.
Rule ids follow the spec and the S1 rule sheet (`docs/specs/rules/s1.md`, researcher
branch, read 2026-11-07), which corrected three points adopted here: no general
legal-entity exemption from deferred tax, 20.29 as a company-wide policy, and the cash
flow method as a choice.

Everything here is synthetic. The company, its owner, the property, the lessor and the
organisation numbers are fictional. `559999-6013` (company) and `559999-6039` (owner)
are test numbers already used elsewhere in the repository's tests; they pass Luhn.
Nothing here describes the company that may hold such a number.

## Files

| File | Content |
|---|---|
| `company.json` | Company, fiscal years 2025 and 2026 (both K3), size data, accounting policies, chart of accounts used (with a flag for accounts missing from the repo's `database/data/bas/2026/k3.json` seed) |
| `opening_balances.json` | Balances at 2025-01-01, and the derived balances at 2026-01-01 |
| `vouchers.json` | 301 balanced vouchers: 150 for 2025, 151 for 2026 (monthly summaries, quarterly loan entries, annual events, year-end entries) |
| `assets.json` | Building B1 split into four components plus land; machines M1 and M2 |
| `leases.json` | Lease L1 with the classification evidence |
| `tax_values.json` | Tax rate, statslåneränta, tax values of the building, preliminary tax |
| `expected.json` | Income statement, balance sheet, cash flow statement (indirect), changes in equity, component schedule, deferred tax, current tax, tax note, lease note, size test |

Conventions: amounts are integer öre. Opening balances: debit positive, credit negative.
Income statement: income positive, costs negative. Balance sheet: every line positive as
presented. Cash flow: inflow positive. Keys beginning with `_` are specifications of a
line, not printed rows. Vouchers that a K3 implementation should be able to **propose**
itself carry `proposal_kind` (`component_depreciation`, `depreciation`, `current_tax`,
`deferred_tax`); they are in the ledger so that it is complete. A test of a proposal can
drop those vouchers and compare the proposal with them.

## The company

A manufacturer of steel components with about 30 employees, owning its factory. Owned
100 % by Fiktiv Ägare Holding AB. Calendar fiscal year. Has applied K3 since it was
founded, so this fixture has **no transition** (that is F-S1-b). Net turnover 90 MSEK
(2025) and 96 MSEK (2026); total assets about 46–48 MSEK.

Month by month (identical every month within a year, which makes the arithmetic easy to
follow):

| Item | 2025 per month | 2026 per month | Accounts |
|---|---:|---:|---|
| Sales, invoiced last day, paid the 25th next month | 7 500 000 + 25 % moms | 8 000 000 + 25 % moms | 1510 / 3001, 2611 |
| Materials, invoiced last day, paid next month | 4 200 000 + moms | 4 400 000 + moms | 4010, 2640 / 2440 |
| Subcontracting (legoarbete) | 950 000 + moms | 1 000 000 + moms | 4600 |
| Other external costs, paid by bank the 15th | 455 000 (430 000 with moms, 25 000 without) | same | 5170, 5220, 5310, 5460, 5910, 6072, 6310, 6540, 6550, 6570 |
| Wages (7010 / 7210 / 7220) | 690 000 / 390 000 / 70 000 = 1 150 000 | 720 000 / 405 000 / 75 000 = 1 200 000 | PAYE 30 % to 2710 |
| Employer contributions 31,42 % | 361 330 | 377 040 | 7510 / 2731 |
| Preliminary F-skatt | 30 000 | 60 000 | 2512 |
| Building depreciation (4 component rows) | 37 000 | 37 000 | 7820 / 1119 |
| Machine depreciation | 80 000 | 80 000 (+20 000 from July) | 7831 / 1219 |

The tax account (1630) is used as a pass-through: on the 12th the bank pays the previous
month's PAYE, employer contributions and VAT plus this month's F-skatt, so 1630 is zero
at every month end (simplification: in reality large VAT payers pay on the 26th).
Quarterly: interest 1 % of the loan at the start of the quarter, amortisation 300 000.
Annual events: AGM 15 May (dividend 1 200 000 in 2025, 1 500 000 in 2026, paid 29 May);
remaining prior-year tax paid 12 May; machine M2 bought 1 July 2026 for 2 400 000 +
600 000 moms. Year end: inventory count, vacation-pay accrual, reclassification of next
year's amortisation, periodiseringsfond, current tax, deferred tax.

## Calculations

### Component depreciation (S1-R3)

K3 17.4 requires a split into significant components when their consumption differs
materially; 17.13 depreciates each separately. Land is not depreciated. Building B1,
acquired 2015-01-01, account 1111 / 1119 / 7820; land on 1130.

| Component | Cost | Life | Per year | Per month | Acc. 2024-12-31 (10 y) | Acc. 2025-12-31 | Acc. 2026-12-31 | Book 2026-12-31 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Stomme | 14 400 000 | 80 y | 180 000 | 15 000 | 1 800 000 | 1 980 000 | 2 160 000 | 12 240 000 |
| Tak | 2 160 000 | 30 y | 72 000 | 6 000 | 720 000 | 792 000 | 864 000 | 1 296 000 |
| Fasad | 1 920 000 | 40 y | 48 000 | 4 000 | 480 000 | 528 000 | 576 000 | 1 344 000 |
| Installationer | 3 600 000 | 25 y | 144 000 | 12 000 | 1 440 000 | 1 584 000 | 1 728 000 | 1 872 000 |
| **Building** | **22 080 000** | | **444 000** | **37 000** | **4 440 000** | **4 884 000** | **5 328 000** | **16 752 000** |
| Mark | 4 000 000 | — | 0 | 0 | — | — | — | 4 000 000 |

Byggnader och mark: 2024-12-31 = 22 080 000 − 4 440 000 + 4 000 000 = 21 640 000;
2025-12-31 = 21 196 000; 2026-12-31 = 22 080 000 − 5 328 000 + 4 000 000 = **20 752 000**.
(A single 50-year life on the whole building would give 441 600 per year, so the split
matters: 2 400 kr per year more depreciation.)

Machines (1210 / 1219 / 7831), linear, no residual value: M1 9 600 000 over 10 years from
2021-01-01 = 960 000 per year (acc. 3 840 000 at 2024-12-31, 4 800 000 at 2025-12-31,
5 760 000 at 2026-12-31). M2 2 400 000 over 10 years from 2026-07-01 = 20 000 × 6 =
120 000 in 2026. Book value 2025-12-31 = 4 800 000; 2026-12-31 = 9 600 000 + 2 400 000 −
5 880 000 = **6 120 000**. Total depreciation 2025 = 444 000 + 960 000 = 1 404 000;
2026 = 444 000 + 1 080 000 = **1 524 000**.

### Finance lease under the legal-entity exception (S1-R5)

Lease L1: a CNC machine from Fiktiv Leasingfinans AB, 2025-01-01 to 2029-12-31, 60
monthly payments of 25 000 + moms in arrears, purchase option 1 000 at the end, fair value
1 350 000 at the start, economic life 84 months. The payments discounted at the
implicit rate (0,3542 % per month, about 4,25 % a year) equal the fair value; the purchase
option is a bargain. Under **K3 20.3/20.5 the lease is classified as finansiellt**.

The company **applies the legal-entity exception, K3 20.29** ("I juridisk person får alla
finansiella leasingavtal redovisas som operationella leasingavtal"). This is a
**company-wide policy, not a per-contract flag** ("alla", S1-R5): it is recorded once in
`company.json` as `accounting_policies.leasing = operational_in_legal_entity`, and every
finance lease of the company follows it; `leases.json` only refers to that policy. So:
no asset, no liability; the expense is 12 × 25 000 = **300 000** per year on 5220 in both
years, straight-line (K3 20.13). Because the company is a större företag, the K3 20.14
operating-lease note applies (S1-R5.1). Future minimum lease payments (payments + the
option):

| | Inom ett år | 1–5 år | Senare än 5 år |
|---|---:|---:|---:|
| 2025-12-31 (48 left) | 300 000 | 900 000 + 1 000 = 901 000 | 0 |
| 2026-12-31 (36 left) | 300 000 | 600 000 + 1 000 = 601 000 | 0 |

### Deferred tax (S1-R4)

K3 has **no general legal-entity exemption from deferred tax** (S1-R4). 29.19: a
deferred tax liability for every taxable temporary difference, in its own balance-sheet
line; 29.31: classified as an avsättning; 29.22: the change goes to the income
statement; 29.25: not discounted (S1-R4.4, S1-R4.6). The only legal-entity rule is 29.37:
deferred tax on **obeskattade reserver** is not shown separately, so the
periodiseringsfonder stay gross and the appropriation stays gross (S1-R4, S1-R4.2 c).
Deferred tax on the building must therefore still be recognised. The only temporary
difference is the building (S1-R4.1; land and machines have tax value = book value).
Rate 20,6 % (S1-R4.3). As a större företag the company gives the ÅRL 5:36 note on the
amount and its change, which need not cover untaxed reserves (S1-R4.7).

Tax value of the building: tax acquisition value 22 080 000 (same as book), tax
depreciation 4 % a year = 883 200 (see assumption A3).

| Date | Book value building (excl. land) | Tax value | Temporary difference | × 20,6 % = liability (2240) |
|---|---:|---:|---:|---:|
| 2024-12-31 | 22 080 000 − 4 440 000 = 17 640 000 | 22 080 000 − 10 × 883 200 = 13 248 000 | 4 392 000 | 904 752,00 |
| 2025-12-31 | 17 196 000 | 12 364 800 | 4 831 200 | 995 227,20 |
| 2026-12-31 | 16 752 000 | 11 481 600 | 5 270 400 | **1 085 702,40** |

Change booked each year (8940 / 2240): 995 227,20 − 904 752,00 = 1 085 702,40 −
995 227,20 = **90 475,20** (= 439 200 more tax depreciation than book depreciation ×
20,6 %).

Not recognised (29.37), for information only: 20,6 % of the periodiseringsfonder is
309 000 (2024), 432 600 (2025), 638 600 (2026).

### Current tax

Rate 20,6 % (IL 65 kap. 10 §). Taxable income rounded down to whole tiotal kronor (IL 1
kap. 7 §). Schablonintäkt on periodiseringsfonder held at the start of the year =
funds × statslåneränta 30 November the year before (IL 30 kap. 6 a §, no 72 % factor,
per ADR-010). Periodiseringsfond at most 25 % of the surplus before the allocation (IL 30
kap. 5 §). Tax is booked as an estimate on 2512 (the account also receives the F-skatt
payments, so its balance is what remains to pay).

| | 2025 | 2026 |
|---|---:|---:|
| Resultat efter finansiella poster | 3 072 330 | 5 411 810 |
| + Representation, ej avdragsgill (6072) | 24 000 | 24 000 |
| + Book depreciation building (not deductible) | 444 000 | 444 000 |
| − Tax depreciation building 4 % | −883 200 | −883 200 |
| + Schablonintäkt | 1 500 000 × 1,96 % = 29 400 | 2 100 000 × 2,55 % = 53 550 |
| = Surplus before periodiseringsfond | 2 686 530 | 5 050 160 |
| 25 % cap | 671 632,50 | 1 262 540 |
| − Periodiseringsfond (2125 / 2126) | −600 000 | −1 000 000 |
| = Taxable income (rounded to 10 kr) | 2 086 530 | 4 050 160 |
| Tax 20,6 % (8910) | **429 825,18** | **834 332,96** |
| Preliminary F-skatt paid | 360 000 | 720 000 |
| Remaining on 2512 at year end | 69 825,18 | **114 332,96** |

### Income statement (kostnadsslagsindelad)

| | 2026 | 2025 | Arithmetic 2026 |
|---|---:|---:|---|
| Nettoomsättning | 96 000 000 | 90 000 000 | 12 × 8 000 000 |
| Råvaror och förnödenheter | −64 200 000 | −61 400 000 | −12 × (4 400 000 + 1 000 000) + 600 000 inventory increase (4910) |
| Övriga externa kostnader | −5 460 000 | −5 460 000 | −12 × 455 000 |
| Personalkostnader | −18 990 190 | −18 201 670 | −(14 400 000 + 4 524 480 + 50 000 + 15 710) |
| Av- och nedskrivningar | −1 524 000 | −1 404 000 | above |
| **Rörelseresultat** | **5 825 810** | **3 534 330** | |
| Räntekostnader | −414 000 | −462 000 | 1 % × (10,8 + 10,5 + 10,2 + 9,9 MSEK); 2025: 12,0/11,7/11,4/11,1 |
| **Resultat efter finansiella poster** | **5 411 810** | **3 072 330** | |
| Bokslutsdispositioner (periodiseringsfond) | −1 000 000 | −600 000 | |
| Resultat före skatt | 4 411 810 | 2 472 330 | |
| Skatt på årets resultat | −924 808,16 | −520 300,38 | −834 332,96 − 90 475,20 |
| **Årets resultat** | **3 487 001,84** | **1 952 029,62** | |

Personnel 2025: 13 800 000 + 4 335 960 + vacation accrual 50 000 + 15 710 = 18 201 670.
Vacation accrual: 2920 goes 1 400 000 → 1 450 000 → 1 500 000; 31,42 % on each 50 000
increase = 15 710 (7519 / 2940). Inventory 1410: 6 000 000 → 6 400 000 → 7 000 000.

Tax note: aktuell skatt 834 332,96 (2025: 429 825,18), uppskjuten skatt 90 475,20 (90 475,20).

### Balance sheet

| | 2026-12-31 | 2025-12-31 | 2024-12-31 |
|---|---:|---:|---:|
| Byggnader och mark | 20 752 000 | 21 196 000 | 21 640 000 |
| Maskiner och andra tekniska anläggningar | 6 120 000 | 4 800 000 | 5 760 000 |
| **Summa anläggningstillgångar** | **26 872 000** | **25 996 000** | **27 400 000** |
| Råvaror och förnödenheter | 7 000 000 | 6 400 000 | 6 000 000 |
| Kundfordringar (December invoices incl. moms) | 10 000 000 | 9 375 000 | 9 375 000 |
| Kassa och bank | 4 274 444,82 | 3 982 040 | 3 000 000 |
| **Summa omsättningstillgångar** | **21 274 444,82** | **19 757 040** | **18 375 000** |
| **Summa tillgångar** | **48 146 444,82** | **45 753 040** | **45 775 000** |
| Aktiekapital | 500 000 | 500 000 | 500 000 |
| Balanserad vinst | 20 258 567,62 | 19 806 538 | 18 606 538 |
| Årets resultat | 3 487 001,84 | 1 952 029,62 | 2 400 000 |
| **Summa eget kapital** | **24 245 569,46** | **22 258 567,62** | **21 506 538** |
| Periodiseringsfonder (obeskattade reserver) | 3 100 000 | 2 100 000 | 1 500 000 |
| Uppskjuten skatteskuld (avsättningar) | 1 085 702,40 | 995 227,20 | 904 752 |
| Skulder till kreditinstitut, långfristiga (2350) | 8 400 000 | 9 600 000 | 10 800 000 |
| Skulder till kreditinstitut, kortfristiga (2410) | 1 200 000 | 1 200 000 | 1 200 000 |
| Leverantörsskulder | 6 750 000 | 6 437 500 | 6 437 500 |
| Skatteskulder (2512) | 114 332,96 | 69 825,18 | 400 000 |
| Övriga skulder (2650 + 2710 + 2731) | 1 279 540 | 1 186 330 | 1 186 330 |
| Upplupna kostnader (2920 + 2940) | 1 971 300 | 1 905 590 | 1 839 880 |
| **Summa eget kapital och skulder** | **48 146 444,82** | **45 753 040** | **45 775 000** |

Övriga skulder 2026: VAT December 2 000 000 − (1 100 000 + 250 000 + 107 500) = 542 500;
PAYE 360 000; contributions 377 040. Upplupna 2026: 1 500 000 + 31,42 % × 1 500 000 =
471 300. The bank loan is split by booking next year's 1 200 000 amortisation to 2410 at
each year end (assumption A6).

### Changes in equity (K3 6.3)

| 2026 | Aktiekapital | Balanserat resultat | Årets resultat | Summa |
|---|---:|---:|---:|---:|
| Belopp vid årets ingång | 500 000 | 19 806 538 | 1 952 029,62 | 22 258 567,62 |
| Omföring av föregående års resultat (årsstämma) | | 1 952 029,62 | −1 952 029,62 | 0 |
| Utdelning (transaktion med ägare) | | −1 500 000 | | −1 500 000 |
| Årets resultat | | | 3 487 001,84 | 3 487 001,84 |
| **Belopp vid årets utgång** | **500 000** | **20 258 567,62** | **3 487 001,84** | **24 245 569,46** |

2025: 21 506 538 + 0 − 1 200 000 + 1 952 029,62 = 22 258 567,62 (omföring 2 400 000).

### Cash flow statement, indirect method (S1-R2, S1-R2.1)

K3 7.8 lets the company choose the direct or the indirect method; this fixture uses the
**indirect** method as a choice (7.9). Dividends paid are financing (7.16); income tax
paid is shown separately under operating (7.17); likvida medel = 1930 only.

| | 2026 | 2025 | Arithmetic 2026 |
|---|---:|---:|---|
| Resultat efter finansiella poster | 5 411 810 | 3 072 330 | |
| Justering: avskrivningar | 1 524 000 | 1 404 000 | |
| Betald inkomstskatt | −789 825,18 | −760 000 | −(69 825,18 + 834 332,96 − 114 332,96); 2025: −(400 000 + 429 825,18 − 69 825,18) |
| Kassaflöde före förändring av rörelsekapital | 6 145 984,82 | 3 716 330 | |
| Förändring av varulager | −600 000 | −400 000 | |
| Förändring av rörelsefordringar | −625 000 | 0 | 10 000 000 − 9 375 000 |
| Förändring av rörelseskulder | 471 420 | 65 710 | (6 750 000 + 1 279 540 + 1 971 300) − (6 437 500 + 1 186 330 + 1 905 590) |
| **Kassaflöde från den löpande verksamheten** | **5 392 404,82** | **3 382 040** | |
| Förvärv av materiella anläggningstillgångar | −2 400 000 | 0 | machine M2 (the moms goes through the VAT account) |
| **Kassaflöde från investeringsverksamheten** | **−2 400 000** | **0** | |
| Amortering av lån | −1 200 000 | −1 200 000 | |
| Utbetald utdelning | −1 500 000 | −1 200 000 | |
| **Kassaflöde från finansieringsverksamheten** | **−2 700 000** | **−2 400 000** | |
| **Årets kassaflöde** | **292 404,82** | **982 040** | |
| Likvida medel vid årets början | 3 982 040 | 3 000 000 | |
| Likvida medel vid årets slut | 4 274 444,82 | 3 982 040 | = 1930 |

Interest (K3 7.14, inflow positive like the rest of the statement): betald ränta −414 000
(−462 000), erhållen ränta 0.

### Size test (S1-R9, S1-R9.1, S1-R9.2, S1-R9.3, S1-R2)

ÅRL 1 kap. 3 § p. 4: större företag if more than one of > 50 employees, > 40 MSEK total
assets, > 80 MSEK net turnover, in each of the two latest years. S1-R9.1: "the two
latest" are the two years **immediately preceding** the reporting year. So FY2026 is
measured on 2024 and 2025, FY2025 on 2023 and 2024.

| Year | Employees | Total assets | Net turnover |
|---|---:|---:|---:|
| 2023 (assumption) | 30 | 45 000 000 | 88 000 000 |
| 2024 | 30 | 45 775 000 | 90 000 000 (assumption) |
| 2025 | 30 | 45 753 040 | 90 000 000 |
| 2026 | 30 | 48 146 444,82 | 96 000 000 |

Employees fail every year; total assets and net turnover pass every year, so the **same
two criteria** are met in both measured years (the stricter reading of S1-R9's open
question also holds). The company is a **större företag** in 2025 and 2026, and ÅRL 2
kap. 1 § requires the **cash flow statement** (S1-R2). The equity statement would be
required even for a mindre AB (ÅRL 6:2, K3 6.3, S1-R8.3). Note: ADR-059 currently refuses större företag
in its stage F; this fixture is the target for when that refusal is lifted, and it also
serves as the "refused with the reason" case before that.

## Rule ids proved

| Figure | Rule ids |
|---|---|
| Size classification, cash flow statement present | S1-R9, S1-R9.1, S1-R9.2, S1-R9.3, S1-R2 |
| Cash flow statement content (indirect, tax and dividends placed) | S1-R2.1 |
| Component schedule, 7820 rows per component, land undepreciated, Byggnader och mark | S1-R3, S1-R3.2, S1-R3.3, S1-R3.4, S1-R3.7 |
| Deferred tax liability 2240 and 8940, untaxed reserves left gross, no legal-entity exemption for the building | S1-R4, S1-R4.1, S1-R4.2, S1-R4.3, S1-R4.4, S1-R4.6, S1-R4.7 |
| Lease classified finansiellt, recognised operationellt by company policy, 5220 expense, 20.14 lease note | S1-R5, S1-R5.1 |
| Changes in equity with owner transactions and transfers separate | S1-R8.3 |
| Financial instruments at cost (only bank, receivables and loans; nothing at fair value) | S1-R6 (policy `chapter_11`; nothing to measure) |
| Notes: tax split, lease payments, average employees 30, components and lives in the principles note | S1-R8 (större-företag notes apply, S1-R9.3) |
| Not exercised here: transition (S1-R7, F-S1-b), K2 eligibility (S1-R1), development costs (S1-R10) | — |

## Assumptions for the accounting checker

Each is marked **assumption**; the figures follow from them and change if they change.

- **A1 assumption**: 2024 net turnover 90 MSEK, 2023 figures (30 employees, 45 MSEK
  total assets, 88 MSEK turnover), and 2024 monthly levels equal to 2025's. Only used for
  the size test and for what is paid in January 2025.
- **A2 assumption**: component lives (stomme 80, tak 30, fasad 40, installationer 25
  years) and the 2015 cost split are judgement inputs, not rules.
- **A3 assumption**: tax depreciation of the building at 4 % a year of 22 080 000
  (industribyggnad). The fixture gives the tax values directly in `tax_values.json`, so
  only the narrative depends on the rate.
- **A4 assumption**: machines use räkenskapsenlig avskrivning with tax value equal to
  book value (no överavskrivningar, no 2150), so no temporary difference.
- **A5 assumption**: K3 20.29 is applied as a company-wide policy covering all finance
  leases (the rule sheet rates "all, not some" medium); the classification of L1 itself
  (implicit rate, bargain option) is a judgement.
  The lease note includes the 1 000 kr purchase option in the 1–5 year band.
- **A6 assumption**: next year's amortisation is shown as short-term by booking it to
  2410 at the year end (a voucher), not by a presentation-only split.
- **A7 assumption**: indirect method chosen (K3 7.8 allows either); the statement starts
  from "resultat efter finansiella poster", classifies interest paid in operating
  activities (7.15 option) and treats the tax account and VAT as operating items.
- **A8 assumption**: the year-end rollover puts the prior year's result on 2099 in the
  opening balances, and the AGM voucher moves it to 2091 and 2898.
- **A9 assumption**: schablonintäkt uses the statslåneränta 1,96 % (30 Nov 2024) and
  2,55 % (30 Nov 2025) without the 72 % factor (ADR-010); rates are "researched", not
  legally verified.
- **A10 assumption**: the booked current tax is 20,6 % of the taxable income in öre
  (the taxable income is a multiple of 10 kr, so the product is whole öre); no rounding
  of the tax itself.
- **A11 assumption**: employer contributions 31,42 % on all wages (no employee in a
  reduced-rate group), PAYE a flat 30 %, no pension costs; the tax account is a
  pass-through and every tax-account item is paid on the 12th of the next month.
- **A12 assumption**: accounts missing from the repo's BAS seed (1111, 1119, 1130, 1410,
  2124–2126, 2240, 2410, 2512, 2898, 4600, 4910, 5170, 5220, 5310, 7290, 7519, 7820,
  7831, 8940) are used with the BAS 2026 names as remembered; the checker should confirm
  the names against the BAS 2026 chart (number ranges match `Ink2Codes`/rules data).

## Self-check results (2026-11-07)

A throwaway script in the session scratchpad (not committed) re-read only these JSON
files and checked: every voucher balances (302 at the time; 301 after the change below); no row has both a debit and a credit;
voucher dates lie in their fiscal year; opening balances sum to zero; IB 2026 equals UB
2025 with the result moved to 2099; the result recomputed from the ledger equals
`expected.json` both years; the balance sheet balances at 2024-12-31, 2025-12-31 and
2026-12-31; total assets equal the sum of asset accounts; the cash flow statement
reconciles to the change in 1930 both years; the equity statement closes to the balance
sheet both years; deferred tax = 20,6 % × temporary difference at each date and equals
2240 in the ledger; the 7820 rows per component equal the schedule; the building and land
book value equals 1111 + 1119 + 1130; the current tax equals 20,6 % of the taxable income;
the periodiseringsfond is within the 25 % cap; the size test holds. **27 of 27 checks
passed.**

## Changes after freezing

- **2026-11-07, approved by the Lead (S1-P8 review).** Machine M2 is booked as paid
  directly from the bank on 1 July 2026 (1210 2 400 000 and 2640 600 000 against 1930
  3 000 000) instead of a supplier invoice on 2440 followed by a payment voucher on 20
  July (series E). The cash flow statement does not accept an acquisition against 2440
  unless the payables ledger links the invoice to its payment, and the fixture's manual
  vouchers carry no such link. The payment voucher was removed (2026 now has 151
  vouchers), and series E of 2026 is renumbered 1–24 without a gap. Amounts, VAT and the acquisition date are unchanged, and so is every figure
  in `expected.json` at the year end.
- **2026-11-07, approved by the Lead.** `tillaggsupplysning_betald_ranta` is negative
  (−41 400 000 and −46 200 000 öre), as the file's own convention "inflow positive,
  outflow negative" requires.
