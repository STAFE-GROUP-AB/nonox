# ADR-053: NE-bilaga and Inkomstdeklaration 4

- Status: Proposed (legal and accounting review before merge)
- Date: 2026-11-05
- Related: ADR-010 (INK2), ADR-051 (Företagsguiden), research in the PR

## Context

NONOX filed only INK2 (limited companies and ekonomiska föreningar). Sole
traders are the largest group of new companies, and Företagsguiden leads
them into NONOX; they declare the business on the NE-bilaga with their own
INK1. Handelsbolag and kommanditbolag file Inkomstdeklaration 4 (INK4R,
INK4S, one INK4DU per partner). The income tax page showed "Inte för den
här företagsformen" for both.

Skatteverket's field tables for beskattningsperiod 2026P4 ("Nyheter from
beskattningsperiod 2026P4", Teknisk information om filöverföring) give the
codes: NE_SKV2161-14-02-26-02, INK4R_SKV2004-33-02-26-01,
INK4S_SKV2004-33-01-26-06 and INK4DU_SKV2004-33-01-26-01.

## Decision

1. **Same pattern as INK2.** `Ne`/`NeCodes` and `Ink4`/`Ink4Codes` in
   `app/Domain/IncomeTax`, built on `AnnualAccounts`, the BAS ranges in code
   (BAS's SRU coupling tables for NE, 2023, and INK4, 2024; `TODO(legal-verify)`
   for later editions), written with the existing `SruFile`. A period whose
   field table is not checked is refused. `income_tax_returns` is now one row
   per fiscal year **and form** (INK2, NE, INK4), because NE codes overlap
   INK2 codes.
2. **NE from the books**: B1–B16, R1–R11. R1/R2 split by the account's VAT box
   (ruta 42 is exempt); a company not registered for VAT puts all sales in R2.
   8000–8989 counted by each account's sign (income R4, cost R8). B10 is
   assets less liabilities, as the form defines it.
3. **NONOX calculates, the owner confirms.** The owner enters the
   adjustments and the inputs the law needs and NONOX cannot know
   (kapitalunderlag for räntefördelning and expansionsfond, the fund at year
   start, last year's egenavgifter). NONOX computes räntefördelning (IL 33
   kap. 3–5 §§: positive capped at R29, the unused part saved in 8011;
   negative only below −500 000 kr), the cap for periodiseringsfond (30 % of
   R33, IL 30 kap. 6 §), the cap for expansionsfond (IL 34 kap. 5–6 §§:
   125,94 %), and the schablonavdrag for egenavgifter (IL 16 kap. 30 §, of R42,
   by year of birth; 20 % passive). A cap is rounded down; an entered R43
   replaces the calculation. Nothing is booked.
4. **Year parameters as data**: `database/data/income-tax/{year}.json`, every
   value with source URL and quote, `verified` per year. A year without a
   verified file blocks the calculations and the NE file, and the page says
   so, as payroll does for its rules.
5. **Personal data, minimum.** The NE identity is the owner's twelve-digit
   personnummer; NONOX asks only for the century of birth (which also gives
   the year of birth for the schablon), stored encrypted in
   `income_tax_returns.personal`. INK4 partners are stored per fiscal year in
   `income_tax_partners` (name, BAS block, share, identity encrypted,
   Luhn-checked). The audit log records field codes and counts, never amounts,
   names or numbers. A natural person's number never reaches the browser: the
   page shows it masked and keeps the stored value unless a new one is typed
   (rows refer to the stored partner by id, within the company). Partners of a
   closed fiscal year are locked, so they stay as filed. Each partner has a
   register extract (`/registerutdrag/delagare/{id}`).
   Positions are 1–4, because BAS has equity blocks for four partners
   (2010–2049); a company with more partners files INK4 in Skatteverket's
   e-service.
6. **INK4**: INK4R from the books with the partners' equity (opening, net
   withdrawals or contributions as the remainder, result, closing), with a
   warning when the remainder differs from the partners' x1–x3 and x7–x8
   accounts; INK4S with the entered adjustments and 8022 proposed from 74xx;
   one INK4DU block per partner with the share of the surplus (rounded down,
   remainder to the last partner), contributions and withdrawals. Files only
   when the shares add up to 100 %.
7. **Honest limits**: partners file N3A or N3B themselves; NONOX does not
   build them. The INK4 first page cannot be sent by file transfer. The NE is
   filed by importing BLANKETTER.SRU into the INK1 e-service (not a
   filöverföring of an INK1 with only business data, which cannot be
   completed later).

## Consequences

- Three forms on one page, chosen by legal form; the INK2 flow is unchanged.
- Each new inkomstår needs its parameter file checked before NE files can be
  made.
- Open `TODO(legal-verify)`: the BAS ranges for 2025/2026, the rounding, the
  underlag for the schablonavdrag, sales of a company not registered for VAT,
  owners born 1937 or earlier, 8105 in INK4DU, and the INK4R short-term
  liability ranges.
