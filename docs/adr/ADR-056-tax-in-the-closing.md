# ADR-056: The year's income tax as a confirmed proposal in the closing

- Status: Proposed (Beta; nothing here is legally verified, the `TODO(legal-verify)` items below are open)
- Date: 2026-11-06
- Related: ADR-010 (reports, VAT, year-end, INK2), ADR-053 (NE and INK4), ADR-007 (vouchers and immutability)

## Context

The closing (Bokföring → Bokslut) booked the year's income tax from an
amount the user typed (`YearEndClosing::bookTax`), with a hint of 20,6 % of
the book result. The legal audit (AUD-BOK-1) noted that nothing computed
the tax from the taxable income, and that the typed amount was never checked
against INK2S 4.15. INK2 (`Ink2`) already turns the books and the
adjustments a person enters into the taxable surplus; the schablonintäkt on
periodiseringsfonder (field 7654) is proposed from the opening balances of
BAS 2110–2149.

Track D.1: compute the tax of a limited company as a proposal, show it line
by line with sources, and book it only when a person confirms it.

## Primary sources (read 2026-11-06)

- Inkomstskattelag (1999:1229), as amended up to SFS 2026:1393,
  https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/inkomstskattelag-19991229_sfs-1999-1229/
  - 65 kap. 10 §: 20,6 % for juridiska personer (Lag 2018:1206); the
    transitional rule 11 to Lag 2018:1206: 21,4 % for beskattningsår starting
    2019-01-01 to 2020-12-31.
  - 1 kap. 7 §: the taxable income of a juridisk person is rounded down to
    whole tens of kronor.
  - 30 kap. 3 §: the allocation must be made in the books; 4 §: each year's
    allocation is its own fund; 5 §: at most 25 % of the surplus before the
    deduction; 7 §: reversed at the latest the sixth beskattningsår after,
    the earliest deduction first; 1 § andra stycket: not for
    privatbostadsföretag and investmentföretag.
  - 16 kap. 2 § (representation), 9 kap. 8 § (interest on taxes under SFL
    65 kap.), 9 kap. 9 § (fines and sanctions), 8 kap. 7 § (tax-free
    interest on repaid tax).
- Skatteverket, Periodiseringsfond (aktiebolag),
  https://www.skatteverket.se/foretag/drivaforetag/foretagsformer/aktiebolag/periodiseringsfond:
  the basis is the taxable surplus after last year's deficit and the
  reversal of earlier funds, before this year's allocation; the oldest fund
  is reversed first; the schablonintäkt is not booked.
- BAS kontoplan 2026,
  https://www.bas.se/wp-content/uploads/2026/04/BAS_kontoplan_2026_v2.pdf:
  2120–2127 Periodiseringsfond 2020–2027 and 2129 Periodiseringsfond 2019
  (the last digit is the year), 2130–2139 the "nr 2" funds, 8811
  Avsättning, 8819 Återföring, 8910, 2512, 6072, 6982, 6992, 8314, 8423.

## Decision

1. **Rules as data.** `database/data/corporate-tax/rules.json` holds the
   rates by the start of the fiscal year, the rounding, the fund share and
   reversal period, the BAS accounts and the accounts that propose INK2S
   adjustments, each with source URL, quote, read date and status
   `researched`. `CorporateTaxRules` reads it. A year whose start matches no
   rate gets no proposal (before 2019 today).
2. **INK2 stays the one calculation of the taxable income.** `Ink2` now also
   proposes field 7653 from 6072, 6982, 6992 and 8423 and field 7754 from
   8314; an entered amount, 0 included, replaces the proposal as for the
   other proposed fields. `Ink2::proposedTaxOre()` gives the tax on the
   surplus as the books stand.
3. **`TaxProposal`** (`app/Domain/Closing`) adds the periodiseringsfond:
   the funds at the start of the year by BAS account, their year from the
   account's last digit (the latest year up to the fiscal year's end year),
   funds six years old or more proposed for reversal, a person's reversal
   spread oldest first, the basis (INK2 taxable + reversal), the maximum
   allocation (25 %, rounded down to whole kronor), the person's
   allocation (0 by default), the taxable surplus, the rounding to tens and
   the tax in öre (the rounded income × the rate). Lines for the page carry
   the INK2S field and where each amount comes from.
4. **Never booked without a person.** `YearEndClosing::confirmTaxProposal`
   is called only from the confirm button. The page sends the tax amount it
   showed; the action recomputes and refuses when it differs ("the books
   changed"). One voucher in series A on the last day, source `CLOSING`,
   through `BookVoucher` (SaveDraftVoucher + PostVoucher): fund account
   against 8819 for the reversal, 8811 against 2120 + digit (2130 + digit
   when the first already has a balance) for the allocation, 8910 against
   2512 for the tax. Missing accounts are added with BAS names and an
   `account.created` audit entry. Zero tax without fund rows records `TAX`
   with amount 0 and no voucher, as before.
5. **Once.** A `TAX` year-end entry blocks a second confirmation and the
   manual booking, as before. A wrong voucher is corrected with a correcting
   voucher (immutability, ADR-007); the closing step itself is not repeated.
6. **Guards.** No proposal is booked on top of tax already on 8900–8989; the
   fund part is left out when a fund change is already booked in the year;
   the reversal is at most the funds with a known year; the allocation at
   most the maximum. Funds on 2110–2119 or 2140–2149 have no year and are
   left to a person, with a warning.
7. **Legal forms.** Aktiebolag and ekonomisk förening get the proposal (both
   juridiska personer under IL 65 kap. 10 §, both file INK2). Enskild firma,
   handelsbolag and kommanditbolag get an informational note: the tax is
   taken from the owners, nothing is booked in the company. Ideell förening
   and stiftelse keep the manual amount (their own rules, IL 7 kap. and the
   grundavdrag in 1 kap. 7 §).
8. **Permissions** as the rest of the closing: `fiscal-years:manage` (owner,
   administratör, redovisningskonsult). Bokförare, attestant and revisor
   cannot open the page or confirm.
9. **Audit.** `closing.tax_proposal_confirmed` with tax öre, rate, taxable
   and rounded kronor, reversal and allocation kronor, the fund account and
   the voucher reference; no names or free text.

## Consequences

- The tax booked in the closing and INK2S 4.15 come from the same numbers;
  the adjustments a person enters on the income tax page reach the closing.
- The 7653/7754 proposals change the INK2 return for companies that use
  those accounts; an entered amount still wins.
- Beta: the page says the rules are not reviewed by a lawyer and that the
  person answers for the amounts.

## TODO(legal-verify)

- That the BAS fund account's year is the calendar year in which the fiscal
  year of the deduction ends, and that "the sixth beskattningsår after" may
  be counted in calendar years of the end date when a year was shorter or
  longer than twelve months.
- That 6072, 6982, 6992 and 8423 hold only costs that may not be deducted,
  and 8314 only income that is not taxable (8423 may also hold interest that
  is deductible).
- Counting the six beskattningsår from the earliest fiscal year in NONOX
  that ends in the fund's year; calendar years when that year is missing.
- Rounding of the maximum allocation down to whole kronor; whether the
  booked tax should be the öre-exact rounded income × rate or a tax rounded
  to whole kronor.
- The privatbostadsföretag and investmentföretag answer is the person's own
  assessment (IL 2 kap. 17 § "till klart övervägande del"); NONOX does not
  check it against the books.
- Ekonomiska föreningar with special rules (e.g. kooperativa
  avdragsrätten for utdelning, IL 39 kap.) are not handled.
- Earlier rates than 2019 are not in the data.

## Addendum 2026-11-06: tax review

An independent review against the law text confirmed eight findings, all
fixed with tests first:

1. **Privatbostadsföretag and investmentföretag** (IL 2 kap. 17 §, 39 kap.
   14 and 25 §§, 30 kap. 1 § andra stycket). A bostadsrättsförening got a
   proposal on its whole book result. Now the person first answers, per
   fiscal year, whether the company is a privatbostadsföretag or an
   investmentföretag, stored as a closing check (`tax_ordinary`,
   `tax_private_housing`, `tax_investment`; no migration) and audited as
   `closing.tax_status_answered`. Unanswered, or either "yes", gives no
   proposal and no fund fields; the manual amount stays, with a sentence
   citing 39 kap. 25 § or 14 §. Applies to AB and ekonomisk förening alike.
2. **Mandatory reversal** (30 kap. 7 §): a reversal below what is due is
   refused. The due amount no longer drops to zero when a fund change is
   booked by hand; what was already debited on the fund account in the year
   is subtracted instead, and the rest must be booked by hand.
3. **Missing schablonintäkt** (30 kap. 6 a §): with funds at the start of the
   year and no statslåneränta in `TaxAllocationReserves::RATES` and nothing
   entered in field 7654, confirmation is blocked. No rate was added.
4. **Six beskattningsår**: counted in the company's fiscal years after the
   earliest one ending in the fund's year when it is in NONOX; calendar
   years otherwise.
5. **Confirmation dialog** is built from the voucher rows (reversal, allocation,
   tax with amounts and accounts), says when no voucher is created, and says
   the step cannot be done again.
6. Manual wording: "Har skatten ändrats sedan beräkningen visades".
7. **Stale amount**: the reversal and allocation fields update live
   (debounced) and the confirm button is disabled while they are unsynced.
8. **Öre residue**: a fund reversed in full is reversed to the öre, so its
   account ends at zero.
