# F-S2-a: Fiktiva Koncernen (parent and two wholly owned subsidiaries)

Status: ready (frozen 2026-11-07)

Fixture for spec item **S2 Group accounts** (`docs/specs/nummer-1-spec.md`, Part 3),
stage 1 (internal group overview) and stage 2 (statutory group accounts under K3).
Written by the Fixture author from the rules, **independently of any implementation**
(no code under `app/Domain/Group/**` or `app/Domain/AnnualReport/**` was read). Rule ids
follow the spec and the S2 rule sheet (`docs/specs/rules/s2.md`, researcher branch, read
2026-11-07); its corrections C1 (group contributions are K3 6.6, not ch. 35) and C2 (a
group cash flow statement is always part of group accounts) are applied here.

All three companies are fictional. Organisation numbers `559999-3309` (Moder),
`559999-3408` (Produktion) and `559999-3507` (Försäljning) are invented test numbers in
the 559999 series; they pass Luhn and belong to no one.

## Files

| File | Content |
|---|---|
| `group.json` | Members, ownership and voting 100 %, formation dates, cost of shares, account marks for intercompany accounts, the loan, the group contribution, the inventory statement |
| `companies/<key>/company.json` | Company data and chart of accounts used (`moder`, `produktion`, `forsaljning`) |
| `companies/<key>/opening_balances.json` | Balances at 2026-01-01 (debit +, credit −) |
| `companies/<key>/vouchers.json` | The company's own ledger for 2026 (17 / 28 / 21 balanced vouchers). Intercompany rows carry `counterparty` (a company key) and the voucher carries `intercompany` (kind) |
| `expected.json` | Per company RR/BR and tax; intercompany checks; every elimination entry; consolidated RR/BR for stage 1 and stage 2; stage 2 cash flow, group equity presentation, changes in equity; size test |

Amounts are integer öre. Income statement: income positive, costs negative; balance
sheet: lines positive. Elimination rows are written in the **group ledger** only; they
never touch a company's books (spec S2 data rule).

## The group

- **Moder AB** (parent, 1 employee, the CEO): owns 100 % of both subsidiaries, which it
  founded (Produktion 2019, share capital 1 000 000; Försäljning 2021, share capital
  500 000; cost of shares = share capital subscribed, account 1310). Invoices both
  subsidiaries a management fee of 100 000 + moms a month. Lends 5 000 000 to Produktion
  on 2026-01-02 at 5 %.
- **Produktion AB** (15 employees): manufactures; sells 30 000 000 to external customers
  and 12 000 000 to Försäljning (1 000 000 + moms a month, account 3011); buys
  materials 24 000 000; buys a machine for 4 800 000 on 2026-02-01 with the loan.
  Holds a periodiseringsfond of 400 000 from 2025 (2125).
- **Försäljning AB** (4 employees): distributor; buys only from Produktion (4011) against
  confirmed customer orders and delivers directly, so it never holds stock; sells
  18 000 000 externally. Gives a **koncernbidrag of 800 000** to Moder at year end.

Ledgers are annual summaries (one voucher per kind for January–November, then December
separately), because what matters is the year-end intercompany position. Opening tax
account items for 2025 are assumed settled before 2026-01-01 (opening tax liabilities 0).

### Intercompany position at 2026-12-31 (both sides must match)

| Item | Creditor side | Debtor side | Amount |
|---|---|---|---:|
| Goods sold Produktion → Försäljning (year) | Produktion 3011 | Försäljning 4011 | 12 000 000 |
| Management fees (year) | Moder 3021 | Produktion 6591 + Försäljning 6591 | 2 400 000 |
| Interest on loan 5 % × 5 000 000 (accrued, unpaid) | Moder 8250 | Produktion 8410 | 250 000 |
| Koncernbidrag (bokslutsdisposition, K3 6.6) | Moder 8820 | Försäljning 8830 | 800 000 |
| Loan | Moder 1320 | Produktion 2360 | 5 000 000 |
| December goods invoice incl. 25 % moms (unpaid) | Produktion 1560 | Försäljning 2460 | 1 250 000 |
| December fee incl. moms, Produktion (unpaid) | Moder 1560 | Produktion 2460 | 125 000 |
| December fee, Försäljning (paid 2026-12-31) | Moder 1560 | Försäljning 2460 | 0 |
| Accrued interest | Moder 1660 | Produktion 2860 | 250 000 |
| Unpaid koncernbidrag | Moder 1660 | Försäljning 2860 | 800 000 |
| Shares vs equity at formation | Moder 1310 | Produktion 2081 + Försäljning 2081 | 1 500 000 |

No mismatches (that case is F-S2-c). The receivables and payables include moms; the moms
itself is owed to Skatteverket by each company and is **not** eliminated.

## Company figures 2026

| | Moder | Produktion | Försäljning | Sum |
|---|---:|---:|---:|---:|
| Nettoomsättning | 2 400 000 | 42 000 000 | 18 000 000 | 62 400 000 |
| Handelsvaror (4011) | | | −12 000 000 | −12 000 000 |
| Råvaror och förnödenheter (4010, 4910) | | −23 700 000 | | −23 700 000 |
| Övriga externa kostnader | −300 000 | −3 000 000 | −1 800 000 | −5 100 000 |
| Personalkostnader | −1 182 780 | −11 827 800 | −3 154 080 | −16 164 660 |
| Avskrivningar | | −840 000 | | −840 000 |
| Rörelseresultat | 917 220 | 2 632 200 | 1 045 920 | 4 595 340 |
| Ränteintäkt koncernlån (8250) | 250 000 | | | 250 000 |
| Räntekostnad (8410) | | −250 000 | | −250 000 |
| Resultat efter finansiella poster | 1 167 220 | 2 382 200 | 1 045 920 | 4 595 340 |
| Koncernbidrag (bokslutsdisposition) | 800 000 | | −800 000 | 0 |
| Skatt | −405 247,32 | −492 834,40 | −50 659,52 | −948 741,24 |
| **Årets resultat** | **1 561 972,68** | **1 889 365,60** | **195 260,48** | **3 646 598,76** |

Arithmetic: personnel = wages + 31,42 %: Moder 900 000 + 282 780; Produktion 9 000 000 +
2 827 800; Försäljning 2 400 000 + 754 080. Produktion materials 24 000 000 − inventory
increase 300 000 (1 500 000 → 1 800 000) = 23 700 000; depreciation 4 000 000 / 10 +
4 800 000 / 10 × 11/12 = 400 000 + 440 000. External costs: Moder 120 000 + 180 000;
Produktion 1 200 000 fee + 1 200 000 rent + 600 000 el; Försäljning 1 200 000 fee +
360 000 + 240 000.

Tax (20,6 %, taxable income rounded down to 10 kr): Moder 1 967 220 × 20,6 % =
405 247,32; Produktion (2 382 200 + schablonintäkt 400 000 × 2,55 % = 10 200) =
2 392 400 × 20,6 % = 492 834,40; Försäljning 245 920 × 20,6 % = 50 659,52. Preliminary
tax paid 360 000 / 480 000 / 120 000, so Moder owes 45 247,32, Produktion owes
12 834,40, and Försäljning has a **tax receivable of 69 340,48** (its koncernbidrag
lowered its tax).

Balance sheet totals 2026-12-31: Moder 9 703 285; Produktion 16 487 850; Försäljning
4 268 100,48 (each balances; see `expected.json`).

## Eliminations (S2-R4, S2-R4b, S2-R7)

ÅRL 7 kap. 13 §: intra-group receivables, payables and internal profit are eliminated
in the balance sheet; intra-group income and expenses in the income statement.

| Id | Debit | Credit | Amount |
|---|---|---|---:|
| E1 | Produktion 3011 | Försäljning 4011 | 12 000 000 |
| E2 | Moder 3021 | Produktion 6591 1 200 000, Försäljning 6591 1 200 000 | 2 400 000 |
| E3 | Moder 8250 | Produktion 8410 | 250 000 |
| E4 | Moder 8820 | Försäljning 8830 | 800 000 |
| E5 | Produktion 2360 | Moder 1320 | 5 000 000 |
| E6 | Försäljning 2460 | Produktion 1560 | 1 250 000 |
| E7 | Produktion 2460 | Moder 1560 | 125 000 |
| E8 | Produktion 2860 | Moder 1660 | 250 000 |
| E9 | Försäljning 2860 | Moder 1660 | 800 000 |
| E10 | Produktion 2081 1 000 000, Försäljning 2081 500 000 | Moder 1310 | 1 500 000 |

Income statement eliminations: 12 000 000 + 2 400 000 + 250 000 + 800 000 = 15 450 000
debit and credit; **net effect on the group result 0**. Balance sheet eliminations:
5 000 000 + 1 250 000 + 125 000 + 250 000 + 800 000 + 1 500 000 = 8 925 000 debit and
credit. **All rows net to 0.** E10 is also applied to the opening balance sheet
(`E10-IB`). Unrealised internal profit: none (Försäljning holds no stock and nothing
else was sold within the group).

## Consolidated income statement 2026 (stage 1 and stage 2 identical)

| | Sum of companies | Eliminated | Group |
|---|---:|---:|---:|
| Nettoomsättning | 62 400 000 | −14 400 000 (E1, E2) | **48 000 000** |
| Handelsvaror | −12 000 000 | +12 000 000 (E1) | 0 |
| Råvaror och förnödenheter | −23 700 000 | | −23 700 000 |
| Övriga externa kostnader | −5 100 000 | +2 400 000 (E2) | −2 700 000 |
| Personalkostnader | −16 164 660 | | −16 164 660 |
| Avskrivningar | −840 000 | | −840 000 |
| **Rörelseresultat** | 4 595 340 | 0 | **4 595 340** |
| Ränteintäkter / räntekostnader | 250 000 / −250 000 | E3 | 0 |
| Resultat efter finansiella poster | 4 595 340 | | 4 595 340 |
| Koncernbidrag | 0 (800 000 − 800 000) | E4 | 0 |
| Skatt | −948 741,24 | | −948 741,24 |
| **Årets resultat** | 3 646 598,76 | 0 | **3 646 598,76** |

Stage 2: the untaxed reserve did not change in 2026, so there is no deferred tax
movement and the income statement is the same (S2-R6; with a change, it would show as
deferred tax expense in the group).

## Consolidated balance sheet 2026-12-31

| | Stage 1 (internal) | Stage 2 (K3) |
|---|---:|---:|
| Maskiner | 7 160 000 | 7 160 000 |
| Andelar i koncernföretag, koncernfordringar | 0 | 0 |
| Råvaror och förnödenheter | 1 800 000 | 1 800 000 |
| Kundfordringar (3 125 000 + 1 875 000) | 5 000 000 | 5 000 000 |
| Aktuella skattefordringar (Försäljning) | 69 340,48 | 69 340,48 |
| Kassa och bank (2 028 285 + 3 152 850 + 2 323 760) | 7 504 895 | 7 504 895 |
| **Summa tillgångar** | **21 534 235,48** | **21 534 235,48** |
| Aktiekapital (parent only) | 500 000 | 500 000 |
| Balanserat / annat eget kapital (excl. årets resultat) | 13 300 000 | 13 617 600 |
| Årets resultat | 3 646 598,76 | 3 646 598,76 |
| **Summa eget kapital** | **17 446 598,76** | **17 764 198,76** |
| Periodiseringsfonder | 400 000 | 0 |
| Uppskjuten skatteskuld | 0 | 82 400 |
| Leverantörsskulder (external) | 2 500 000 | 2 500 000 |
| Skatteskulder (Moder 45 247,32 + Produktion 12 834,40) | 58 081,72 | 58 081,72 |
| Övriga skulder (moms, personalskatt, avgifter) | 1 129 555 | 1 129 555 |
| **Summa eget kapital och skulder** | **21 534 235,48** | **21 534 235,48** |

Current tax receivables and payables of different companies are **not netted** in the
group (different taxpayers; K3 29.32 needs a legal right of set-off).

Stage 1 is labelled "Intern koncernrapport, inte en koncernredovisning enligt ÅRL"
(ADR-060, S2-R14) and keeps the periodiseringsfond gross.

Stage 2 (S2-R6, K3 4.12, 29.15 comment): Produktion's periodiseringsfond 400 000 is split
into deferred tax 400 000 × 20,6 % = **82 400** and equity 400 000 − 82 400 = **317 600**.
Group equity per K3 4.9 (S2-R12, not split into bundet/fritt): Aktiekapital 500 000;
Övrigt tillskjutet kapital 0; Annat eget kapital inklusive årets resultat
13 617 600 + 3 646 598,76 = **17 264 198,76**; no non-controlling interests.

Opening group balance sheet 2026-01-01: total 14 200 000 (machines 3 200 000, inventory
1 500 000, bank 9 500 000); equity stage 1 13 800 000 + periodiseringsfond 400 000;
stage 2 equity 14 117 600 + deferred tax 82 400. Group equity 2026: 14 117 600 +
3 646 598,76 = 17 764 198,76 (no dividends).

### Group cash flow statement 2026 (stage 2, S2-R4d)

ÅRL 7 kap. 4 § makes the cash flow statement part of every group accounts (spec
correction C2). Indirect method, a choice under K3 7.8.

| | 2026 |
|---|---:|
| Resultat efter finansiella poster | 4 595 340 |
| Avskrivningar | 840 000 |
| Betald inkomstskatt (360 000 + 480 000 + 120 000) | −960 000 |
| Förändring av varulager | −300 000 |
| Förändring av rörelsefordringar (external receivables) | −5 000 000 |
| Förändring av rörelseskulder (2 500 000 + 1 129 555) | 3 629 555 |
| **Kassaflöde från den löpande verksamheten** | **2 804 895** |
| Förvärv av maskin | −4 800 000 |
| Kassaflöde från finansieringsverksamheten (the loan is internal) | 0 |
| **Årets kassaflöde** | **−1 995 105** |
| Likvida medel 9 500 000 → | 7 504 895 |

## Size test (S2-R1, S2-R1c)

Eliminated method: 20 employees (1 + 15 + 4); total assets after elimination
21,5 MSEK; net turnover after elimination 48 MSEK. All far below 50 / 40 MSEK / 80 MSEK
(and the gross 48 / 96 MSEK), so this is a **mindre koncern** with no duty to prepare group
accounts: stage 2 output is **voluntary** (S2-R2). Because the parent prepares group
accounts, it may not use K2 for its own annual report (K2 1.1A c, S2-R3); all three
companies are recorded as K3.

## Rule ids proved

| Figure | Rule ids |
|---|---|
| Size test, voluntary group accounts | S2-R1, S2-R1c, S2-R2 |
| Parent on K3 | S2-R3 |
| E1–E10, consolidated RR/BR, net-zero eliminations, no internal profit | S2-R4, S2-R4b |
| Group cash flow statement | S2-R4d |
| Untaxed reserve split 82 400 / 317 600 | S2-R6 |
| Koncernbidrag as bokslutsdisposition in the companies, eliminated in the group (E4, E9) | S2-R7 |
| Group equity lines, changes in equity | S2-R12 |
| Stage 1 label | S2-R14 |
| Not exercised: goodwill (S2-R5), non-controlling interests (S2-R8), filing (S2-R9), mismatches | F-S2-b, F-S2-c |

## Assumptions for the accounting checker

- **B1 assumption**: subsidiaries founded by the parent are not business combinations, so
  there is no acquisition analysis beyond eliminating the cost of shares against the share
  capital subscribed (no goodwill, no surplus values). The rule sheet's H2 (common control)
  concerns combinations, which this is not, as read here.
- **B2 assumption**: the group contribution is eliminated against itself in the group
  (S2-R7 marks this medium; human decision H6 asks the accountant to confirm on this
  fixture). Both companies report it as a bokslutsdisposition (K3 6.6 first paragraph);
  the IL 35 kap. conditions are assumed met.
- **B3 assumption**: no unrealised internal profit, because Försäljning holds no stock and
  Produktion holds only externally bought raw materials.
- **B4 assumption**: the 5 % interest is a full year (loan from 2026-01-02, day count not
  modelled) and is accrued unpaid at year end.
- **B5 assumption**: current tax receivables and payables of different group companies are
  shown gross in the group.
- **B6 assumption**: opening 2026 tax-account items (PAYE, contributions, VAT, final tax
  2025) are settled before 2026-01-01; the 2024/2025 group figures for the size test are
  far below the limits (not in the fixture).
- **B7 assumption**: first group accounts with no comparatives; whether K3 ch. 35 applies
  is open (S2-R11, H4). The opening group balance sheet is given.
- **B8 assumption**: custom BAS subaccounts 3011, 3021, 4011, 6591 separate intercompany
  amounts so that account marks (ADR-060) work; 8250's exact BAS 2026 name should be
  confirmed (range 8200–8269 per the INK2 mapping).
- **B9 assumption**: Försäljning's purchases for resale (4011) are shown as
  "Handelsvaror"; Produktion's 4010/4910 as "Råvaror och förnödenheter".
- **B10 assumption**: schablonintäkt on Produktion's fund uses 2,55 % (statslåneränta
  30 Nov 2025, ADR-010 without the 72 % factor).

## Self-check results (2026-11-07)

A throwaway script in the session scratchpad (not committed) re-read only these JSON
files and checked: every voucher balances in all three ledgers; opening balances sum to
zero; each company's result recomputed from its ledger equals `expected.json`; each
company's balance sheet balances at both dates; each elimination entry balances; all
elimination rows net to zero and to zero on the group result; every intercompany account
(1310, 1320, 1560, 1660, 2360, 2460, 2860, 3011, 3021, 4011, 6591, 8250, 8820, 8830) is
zero after elimination; every elimination row removes an amount that exists on that side
of that company's ledger; the consolidated trial balance sums to zero; the consolidated
result equals the sum of the company results; stage 1 and stage 2 balance sheets balance
at 2026-01-01 and 2026-12-31; consolidated total assets equal the asset accounts after
elimination plus the gross tax receivable; the stage 2 equity reconciliation is complete;
the untaxed reserve split is 20,6 % / 79,4 %; the group cash flow reconciles to the change
in group cash; the group equity statement closes to the balance sheet. **31 of 31 checks
passed** (58 of 58 across S1 and S2).
