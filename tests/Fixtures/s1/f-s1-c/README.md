# F-S1-c: Fiktiva Småverkstaden AB (K3, mindre företag, no cash flow statement)

Status: ready for checking (written 2026-11-07 by the S1-P8 builder, **not** by the
independent Fixture author; the Checker should re-derive every figure by hand).

Fixture for spec item **S1 K3 annual report** (`docs/specs/nummer-1-spec.md`, Part 3,
"F-S1-c: company that falls below cash-flow requirement thresholds, proving the
statement is optional") and ADR-059 A.5 (packages S1-P8 and S1-P2).

Everything here is synthetic. The company and `559999-3606` are invented (559999
series, passes Luhn, used by no one).

## Files

| File | Content |
|---|---|
| `company.json` | Company, one fiscal year 2026 (K3), the size figures of 2024 and 2025 as a person enters them (the years before the first NONOX year), accounting policies (`cash_flow_statement` = `none`), accounts used |
| `opening_balances.json` | Balances at 2026-01-01 (debit positive) |
| `vouchers.json` | 21 balanced vouchers for 2026 (yearly summaries) |
| `expected.json` | Size class, year result, total assets, no cash flow statement with `none`, and the voluntary indirect statement if the policy were `indirect` |

## What it proves

1. **Size (S1-R9, S1-R9.1).** FY2026 is measured on 2024 and 2025: 5 and 6 employees,
   total assets 2,0 and 2,17 MSEK, net turnover 7,0 and 7,5 MSEK. No threshold is
   exceeded, so the company is a **mindre företag**.
2. **No statement (S1-R2).** ÅRL 2 kap. 1 § andra stycket requires a kassaflödesanalys
   only of a större företag (and a bostadsrättsförening); K3 1.4 kommentar: "Andra
   företag behöver därför inte tillämpa kapitel 7". With the policy `none` NONOX
   produces no statement and refuses nothing.
3. **A voluntary statement follows chapter 7 (K3 7.1).** With the policy `indirect` the
   statement in `expected.json` → `voluntary_cash_flow_statement` is produced, built
   and classified exactly as for a större företag.

## The year 2026 (kronor)

| Item | Amount | Accounts |
|---|---:|---|
| Sales incl. 25 % moms | 10 000 000 (8 000 000 + 2 000 000) | 1510 / 3001, 2611 |
| Customer payments | 9 900 000 | 1930 / 1510 |
| Materials incl. moms | 3 750 000 | 4010, 2640 / 2440 |
| Supplier payments | 3 700 000 | 2440 / 1930 |
| Rent incl. moms, paid at once | 750 000 | 5010, 2640 / 1930 |
| Wages 2 400 000, contributions 31,42 % = 754 080, PAYE 30 % | | 7210, 7510 / 2710, 2731, 1930 |
| PAYE and contributions paid (Jan–Nov, and December 2025 on 12 Jan) | 1 351 240 + 70 000 | |
| Equipment bought 1 June incl. moms | 250 000 | 1220, 2640 / 1930 |
| VAT settled and paid for the year; December 2025 VAT paid 12 Jan | 1 050 000; 100 000 | 2611, 2640, 2650 |
| Depreciation equipment | 160 000 | 7832 / 1229 |
| Interest paid | 20 000 | 8410 / 1930 |
| Amortisation | 100 000 | 2350 / 1930 |
| AGM 20 May: 2025 result 400 000 to 2091, dividend 150 000, paid 29 May | | 2099, 2091, 2898 |
| Remaining 2025 tax 50 000 and F-skatt 120 000 paid | 170 000 | 2512 / 1930 |
| Inventory increase | 50 000 | 1410 / 4910 |
| Income tax 20,6 % × 1 115 920 | 229 879,52 | 8910 / 2512 |

Resultat efter finansiella poster 1 115 920; årets resultat 886 040,48. Total assets
at 2026-12-31: 2 868 760.

## Voluntary cash flow statement 2026 (indirect)

| | Amount | Arithmetic |
|---|---:|---|
| Resultat efter finansiella poster | 1 115 920 | |
| Justering: avskrivningar | 160 000 | 7832 |
| Betald inkomstskatt | −170 000 | −229 879,52 + (109 879,52 − 50 000) on 2512 |
| Före förändring av rörelsekapital | 1 105 920 | |
| Förändring av varulager | −50 000 | 1410: 300 000 → 350 000 |
| Förändring av rörelsefordringar | −100 000 | 1510: 500 000 → 600 000 |
| Förändring av rörelseskulder | 2 840 | 2440 +50 000, 2650 −100 000, 2710 +30 000, 2731 +22 840 |
| **Löpande verksamheten** | **958 760** | |
| Förvärv av materiella anläggningstillgångar | −200 000 | 1220 (the moms goes through 2640) |
| **Investeringsverksamheten** | **−200 000** | |
| Amortering av lån | −100 000 | 2350 |
| Utbetald utdelning | −150 000 | |
| **Finansieringsverksamheten** | **−250 000** | |
| **Årets kassaflöde** | **508 760** | |
| Likvida medel vid årets början / slut | 970 000 / 1 478 760 | 1930 |

Supplementary: interest paid 20 000, interest received 0.

## Self-check (2026-11-07)

A throwaway script in the session scratchpad (not committed) built the files and
asserted: every voucher balances; the opening balances sum to zero; resultat efter
finansiella poster is 1 115 920 and the tax is 20,6 % of it in whole öre; the statement
reconciles to the change in 1930 to the öre.
