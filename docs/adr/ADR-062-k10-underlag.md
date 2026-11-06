# ADR-062: K10 underlag for the owners of a fåmansföretag

- Status: Proposed (Beta; nothing here is legally verified, the `TODO(legal-verify)` items below are open)
- Date: 2026-11-06
- Related: ADR-010 (INK2), ADR-012 (payroll), ADR-053 (NE and INK4), ADR-056 (tax in the closing)

## Context

The owners of an aktiebolag where they or their family work usually hold
kvalificerade andelar (IL 57 kap. 4 §) and file blankett K10 with their own
Inkomstdeklaration 1. Much of K10 comes from the company's books: the cash
pay to the employees (the löneunderlag), the owner's own pay and the
dividend. The rest only the owner knows: the omkostnadsbelopp, the saved
space from last year and holdings in other companies.

The 3:12 rules were reformed by SFS 2025:1361, first applied for
beskattningsår starting after 2025-12-31 (övergångsbestämmelse 2). For an
owner, a natural person, the beskattningsår is the calendar year, so K10
for inkomstår 2026 (filed 2027) is the first under the new rules. Track D.3:
build the underlag from the books per owner, as a worksheet and, if the
field codes are sourced, an SRU file.

## Primary sources (read 2026-11-06)

- Inkomstskattelag (1999:1229), consolidated up to SFS 2026:1393,
  https://data.riksdagen.se/dokument/sfs-1999-1229.text:
  - 57 kap. 10 §: "Gränsbelopp beräknas för kvalificerade andelar och är
    summan av 1. årets gränsbelopp, och 2. sparat utdelningsutrymme."
    10 a § (förenklingsregeln) is repealed.
  - 11 §: årets gränsbelopp is "1. ett belopp som motsvarar fyra
    inkomstbasbelopp (grundbeloppet) fördelat med lika belopp på andelarna i
    företaget, 2. … ett lönebaserat utrymme beräknat enligt 16–19 a §§, och
    3. ränta på omkostnadsbeloppet"; "Ränta på omkostnadsbeloppet beräknas …
    på den del av andelarnas sammanlagda omkostnadsbelopp enligt 12 § som
    överstiger 100 000 kronor. Räntesatsen uppgår till statslåneräntan ökad
    med nio procentenheter." "Årets gränsbelopp beräknas vid årets ingång
    och tillgodoräknas den som äger andelen vid denna tidpunkt."
  - 11 a §: "Den som äger andelar i flera företag får vid beräkningen av
    årets gränsbelopp inte tillgodoräkna sig mer än ett grundbelopp", shared
    "i förhållande till storleken på andelsinnehaven i företagen".
  - 12 §: the omkostnadsbelopp as if the share were sold at the start of the
    year, reduced by tillskott not made to add capital permanently.
    12 a § (uplift of saved space) is repealed.
  - 13 §: the difference when the dividend is below the gränsbelopp is
    carried forward as sparat utdelningsutrymme, and the whole gränsbelopp
    when there is no dividend. No uplift.
  - 16 §: "Det lönebaserade utrymmet är 50 procent av den del av delägarens
    andel av löneunderlaget som överstiger åtta inkomstbasbelopp"; spouses
    at the start of the year compute it jointly and share it by shares; it
    "får inte överstiga ett belopp som motsvarar 50 gånger sådan kontant
    ersättning som avses i 17 § första stycket och som delägaren eller någon
    närstående har fått från företaget och dess dotterföretag under året
    före beskattningsåret". The old lönekrav and the 4 % kapitalandelskrav
    (old 19 §) are repealed.
  - 16 a §: shares owned part of the year before count only pay paid out
    while they were owned.
  - 17 §: the löneunderlag is "kontant ersättning som hos arbetstagaren ska
    tas upp i inkomstslaget tjänst och som under året före beskattningsåret
    har lämnats till arbetstagarna i företaget och i dess dotterföretag",
    not "kostnadsersättning" and not amounts taxed as tjänst under 57 kap.
    17 a § (AIF-owned) and 18 § (pay covered by state wage grants) are left
    out; 19 a § limits handelsbolag subsidiaries.
  - 8 §: statslåneräntan at the end of November the year before, at least
    0 %; 9 §: the inkomstbasbelopp of the year before.
  - 20 §: dividend above the gränsbelopp in tjänst, up to it "till två
    tredjedelar i inkomstslaget kapital"; 20 a §: at most 90
    inkomstbasbelopp for the beskattningsår in tjänst for the owner and the
    närståendekrets together.
  - Övergångsbestämmelser 2025:1361: point 2 (first applied for
    beskattningsår starting after 2025-12-31); point 5 (57 kap. 3–5 §§ new
    wording from beskattningsår starting after 2026-12-31); point 6 (the
    repealed 12 § andra stycket, 21 § andra stycket and 25–35 §§ still apply
    for beskattningsår starting before 2029-01-01 — the capital gain rules,
    not built here).
  - 41 kap. 8 § and 42 kap. 12 §: a dividend is taken up when it can be
    disposed of.
- Skatteverket, "Ändrade regler för delägare i fåmansföretag inför
  inkomstdeklarationen 2027",
  https://www.skatteverket.se/foretag/drivaforetag/foretagsformer/famansforetag/andradereglerinforinkomstdeklarationen2027.4.4a54dc8b19aa6175a152359.html:
  "För inkomstår 2026 använder du alltså inkomstbasbeloppet för 2025 som är
  80 600 kronor. Grundbeloppet för inkomståret 2026 är 322 400 kronor";
  "det schablonmässiga avdraget 644 800 kronor"; statslåneräntan
  30 november 2025 2,55 %; "Tidigare behövde du ett andelsinnehav på 4
  procent (kapitalandelskravet) samtidigt som du eller en närstående tog ut
  en viss lägsta kontant ersättning (lönekravet). De här kraven har tagits
  bort." Its worked examples (Gunnar and Rita, Ismail, Tove, Agnes and
  Birger, Amy and Gedion, Helle, Valter) are the test fixtures.
- Skatteverket, Belopp och procent 2026,
  https://www.skatteverket.se/privat/skatter/beloppochprocent/2026.4.1522bf3f19aea8075ba21.html:
  inkomstbasbelopp 2026 83 400 kr (for 20 a §), statslåneräntan 2,55 %.
- Skatteverket, Teknisk information om filöverföring,
  https://skatteverket.se/foretag/inkomstdeklaration/forredovisningsbyraer/tekniskinformationomfiloverforing.4.13948c0e18e810bfa0cca8.html,
  "_Nyheter_from_beskattningsperiod_2026P4.zip" (ändrad 2026-10-07): field
  table K10_SKV2110-39-04-26-01.xls and the form image K10M-1/2/3-39-2026P4.
  The edition adds 4535, 4554, 4558, 4567, 4585–4588, 4736, 4747 and removes
  the förenklingsregel/huvudregel fields. The printed form says "1.1
  Grundbelopp; 322 400 kr x din ägarandel", "1.3 … (omkostnadsbelopp − 100
  000) x 11,55 %" and "2.3 Inkomst av tjänst (max 7 506 000 kr)".
- BAS kontoplan 2026,
  https://www.bas.se/wp-content/uploads/2026/04/BAS_kontoplan_2026_v2.pdf:
  groups 70 and 72 (löner), 73 (kostnadsersättningar och förmåner), 2898
  Outtagen vinstutdelning, 3988 Erhållna offentliga bidrag för personal.

## Decision

1. **Rules as data.** `database/data/k10/{year}.json` holds every amount and
   percentage with source URL, quote and status `researched`. `K10Parameters`
   gives none for a year without a file or with an unsourced value, and the
   page then blocks the calculation and points to Skatteverket. Only 2026
   exists; K10 for inkomstår 2025 (old rules) is not built.
2. **`K10`** (`app/Domain/IncomeTax`) is the form's arithmetic, point by
   point in whole kronor: A (1.1–1.6), B (2.1–2.10) when there is a dividend,
   D (4.1–4.13). The grundbelopp is shared by 11 a § as Skatteverket's Tove
   example shows (`own / (own + other holdings)` when the holdings sum above
   100 %). The 50× cap uses the highest cash pay of the owner, the spouse
   among the owners, or an entered närstående.
3. **`K10Books`** proposes from the books, in one ledger query plus one for
   the fiscal years (two more with payroll): the löneunderlag of the year
   before from payroll runs paid that year (taxable lines, not benefits and
   not ALLOWANCE_TAXABLE) when the payroll module has them, otherwise from
   the BAS salary accounts (7000–7018, 7030–7038, 7080–7089, 7200–7218,
   7220–7228, 7230–7238, 7240, 7280–7289; not the accrual accounts and not
   group 73); an owner's own pay from the linked payroll employee; the
   dividend as credits on 2898 in the inkomstår, split by shares. Wage grants
   (18 §) are entered and subtracted; credits on 3988 give a warning.
4. **`K10Sheet`** joins the proposals, what was entered (which always wins)
   and the blockers: not an aktiebolag, no parameters, the shares not
   confirmed as qualified, the company's shares missing, shares bought in
   the inkomstår, shares transferred in the year, spouses with different
   acquisition dates.
5. **Storage.** `k10_years` (company, inkomstår, all shares, wage grants,
   entered dividend, the confirmation) and `k10_owners` (name, optional
   personnummer encrypted and hidden, payroll link, spouse link both ways,
   shares, acquisition date, holdings elsewhere, omkostnadsbelopp, saved
   space, own and närstående pay, overrides, transferred flag). Both use
   `BelongsToCompany`. Not räkenskapsinformation: an owner can be deleted.
6. **Output.** A worksheet page and PDF per owner (DomPDF, as ADR-002), and
   an SRU file (`K10-2026P4`, #IDENTITET the owner's personnummer, 4530 the
   company as 16 + orgnr, as INK4 writes an Orgnr_Id_O) only when the
   period's field table is checked (`K10Codes::VERIFIED_PERIODS`) and the
   personnummer is stored. Zero amounts are left out; a negative 4.11 is
   left out with 4.12/4.13. INFO.SRU names the company as medieleverantör.
7. **Permissions.** `fiscal-years:manage` (owner, administratör,
   redovisningskonsult), like the income tax page. Linking a payroll
   employee additionally needs `payroll:view`, because it shows that
   person's pay.
8. **Audit.** `k10.year_saved`, `k10.owner_saved` (field names only),
   `k10.owner_deleted`, `k10.owners_copied`, `k10.worksheet_exported`,
   `k10.sru_exported`; never names, numbers or amounts.
9. **Beta.** The page, the worksheet and the PDF say it is a proposal, not
   reviewed by a lawyer, and that the owner files and answers for K10.

## Consequences

- An owner gets the gränsbelopp, the split of the dividend and the saved
  space for next year from the same numbers as the books, and an SRU file
  for Filöverföring.
- Each new inkomstår needs its parameter file and a field table check.
- Not built: section C (sales, capital gain, the transitional old rules of
  21 § andra stycket and 25–35 §§), 1.5/2.5 transfers, K10A, K13, several
  share classes, särskilt kvalificerade andelar (4535), the index and
  kapitalunderlag rules (4558), subsidiaries' organisation numbers
  (4564–4567).

## TODO(legal-verify)

- Rounding: the gränsbelopp parts are rounded down to whole kronor, 2.8 to
  the nearest krona; neither the law nor the form says how.
- That the BAS accounts above hold only kontant ersättning (17 §); that
  taxable traktamente and bilersättning are kostnadsersättning left out by
  17 §; that 731x kontanta extraersättningar may be left out.
- That pay booked on the salary accounts in the year equals pay "lämnats"
  in the year; the page warns that it may differ.
- Whether the 50× cap in 16 § tredje stycket is the highest single person's
  pay (Skatteverket's Amy example) or the sum of the owner's and the
  närståendes pay.
- That a dividend can be disposed of (42 kap. 12 §) on the date the decision
  is booked on 2898.
- Whether Numeriskt_B accepts a negative 4586, and whether 4530 should be
  ten digits or 16 + ten (NONOX writes 16 + ten as for INK4).
- Whether the e-tjänst Inkomstdeklaration 1 imports a K10 SRU file, or only
  Filöverföring takes it.
- The legal basis for the company to keep its owners' K10 data, and whether
  it should be pruned after a time (docs/gdpr).

## Addendum 2026-11-06: tax review

An independent review confirmed the rules and Skatteverket's examples; the
findings were fixed with tests first:

1. **Payroll or ledger.** Payroll replaced the ledger for the whole year as
   soon as one run existed, so a company that moved its payroll into NONOX
   in the autumn got only the autumn's pay. Both are now computed; payroll
   is proposed only when a run is paid in January of the year before (or
   the ledger has no pay), otherwise the ledger. Whenever both exist and
   differ, the page shows both figures, and a later first payroll payment
   is named because the owner's own pay from payroll is then partial.
2. **Shares held all year and topped up during the year before** (16 a §,
   K10 p. 4.1–4.4 and 4.5–4.8, "flera separata beräkningar"): refused with a
   pointer to Skatteverket rather than modelled as tranches
   (`k10_owners.topped_up_previous_year`, additive migration).
3. **Copying last year's owners** no longer copies the omkostnadsbelopp (it
   is the value at the new year's start, 12 §); a carried-over saved space
   is flagged (`saved_space_carried`) and shown as "förs över från förra
   årets underlag" until a person changes the amount.
4. **Erasure**: deleting an owner checks only the permission, so it works
   after a change of legal form and for a year without parameters.
5. **Permission on every request**: the page checks `fiscal-years:manage`
   in render and editOwner as well as mount (LivewireCompanyGuard already
   answers 404 when the role changes mid-page); `K10Worksheets::sheets()`
   takes the user and checks it.
6. **7240 Styrelsearvoden** is no longer in the löneunderlag: 17 § counts
   pay to "arbetstagarna". TODO(legal-verify): board fees to board members
   who are also employees.
