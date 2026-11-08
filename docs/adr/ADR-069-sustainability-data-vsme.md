# ADR-069: Sustainability data for large customers (CSRD value chain, VS/VSME)

Status: Proposed
Date: 2026-11-08
Relates to: ADR-011 (invoicing), ADR-012 (payroll), ADR-013 (supplier invoices), ADR-017 (module
framework), ADR-020 (naming other programs), ADR-023 (inbox), ADR-033 (collective agreements),
ADR-058/059 (annual report, size figures), AGENTS.md §1.4, §5

## Context

### The problem small companies have

A NONOX customer with five to fifty employees sells to a large company or a bank. The large
company reports under the Corporate Sustainability Reporting Directive (CSRD) and sends its
suppliers a questionnaire: energy use, greenhouse-gas emissions, number of employees by gender,
accidents, collective agreements. The small company has the raw material for many of these
answers in its bookkeeping and payroll, but not in a form it can send, and it does not know which
questions it is obliged to answer.

### Where the law stands (November 2026)

Everything below was checked against EUR-Lex on 2026-11-08. The area moved fast in 2025–2026 and
national transposition is still under way; see Open questions.

- **CSRD scope after Omnibus I.** Directive (EU) 2026/470 ("Omnibus I", OJ 26 February 2026, in
  force 18 March 2026) amends the Accounting Directive 2013/34/EU. Mandatory sustainability
  reporting now applies to undertakings that exceed both "a net turnover of EUR 450 000 000 and an
  average number of 1 000 employees" (new art. 19a(1); same thresholds in art. 1(3)). Member
  States must transpose the CSRD amendments "by 19 March 2027" (art. 5(1) of the amending
  directive). No NONOX customer is realistically in scope itself.
- **The value-chain cap.** The amended directive protects undertakings in a reporting company's
  value chain with on average not more than 1 000 employees ("protected undertakings"). A
  reporting undertaking "shall not require protected undertakings to provide information
  exceeding" the content of the voluntary standard, and the protected undertaking has "the right to
  decline to provide information exceeding" it (arts. 19a(3) and 29a(3) as amended); the reporting
  standards themselves must not require that information to be obtained (art. 29b(4)).
- **The voluntary standard (VS, formerly VSME).** Art. 29ca required the Commission to adopt
  standards for voluntary use "by 19 July 2026", based on Commission Recommendation (EU) 2025/1710
  (the VSME recommendation of 30 July 2025). It did so in **Commission Delegated Regulation (EU)
  2026/1560** (adopted as C(2026) 5011 on 3 July 2026, OJ 21 September 2026, in force the third day
  after publication). Article 3 makes the standard the basis of the cap and states that "The value
  chain cap shall only comprise the datapoints specified in Annex II". The cap (art. 3) applies to
  financial years beginning on or after **1 January 2027**. Recital 5: the 2025 recommendation stops
  producing legal effects when the regulation enters into force.
- **Structure of the standard** (Annex I): a **Basic Module** B1–B11 (basis for preparation;
  practices and policies; energy and GHG emissions; pollution; biodiversity; water; resource use and
  waste; workforce characteristics; health and safety; remuneration, collective bargaining and
  training; corruption and bribery convictions) and a **Comprehensive Module** C1–C9 that can only
  be applied on top of Basic. Several datapoints are voluntary for undertakings with 10 or fewer
  employees. An "if applicable" principle lets the undertaking omit what does not apply.
- **What is inside the cap** (Annex II, as read from EUR-Lex): from Basic, B1 (module choice,
  individual/consolidated basis, general information), B3 total energy consumption in MWh and
  "Estimated absolute gross greenhouse gas (GHG) emissions", B6 total water withdrawal, B7 circular
  economy principles, total waste (hazardous / non-hazardous) and share diverted to reuse or
  recycling, B8 employees by contract type and by gender, B9 number and rate of recordable
  work-related accidents, B10 minimum wage, collective-bargaining coverage and average training
  hours; from Comprehensive, C1 products/markets/business relationships, C5 turnover rate, C6
  code of conduct and complaints mechanism, C7 confirmed human-rights incidents.
  `TODO(legal-verify)`: Annex II's column layout (≤ 10 vs. > 10 employees) could not be read
  reliably from the HTML; confirm per datapoint against the PDF of the OJ.
- **B3 in detail.** Energy in MWh (with a renewable / non-renewable split for electricity and fuels
  where obtainable); gross Scope 1 and location-based Scope 2 emissions in tCO2e following the GHG
  Protocol Corporate Standard; the standard speaks of *estimated* emissions, so estimates are
  expected. Scope 3 is optional and sector-dependent; it is not inside the cap.
- **EFRAG tooling.** EFRAG publishes a VS Digital Template (version 2.0.0, XLSX), an XBRL taxonomy
  (entry point 2026-10-16) and an Excel-to-XBRL converter whose source is MIT-licensed, all
  published 4 November 2026; EFRAG says older VSME versions "should not be used" and that it will
  maintain the ecosystem at least until the end of June 2027. The page states no licence for the
  taxonomy itself. `TODO(legal-verify)`: the taxonomy's licence before NONOX ships an XBRL export.
- **Sweden.** The Omnibus changes are being implemented in årsredovisningslagen (SOU 2026:27
  *Lättnader i kraven på hållbarhetsrapportering*, a lagrådsremiss, and SFS 2026:626 amending ÅRL,
  21 May 2026). We did not confirm how, or whether, the value-chain cap is written into Swedish
  law; as a directive provision addressed to reporting undertakings, it binds the large customer,
  not the NONOX customer. `TODO(legal-verify)`: the Swedish provisions and dates.

**What this means for a NONOX customer:** it has **no reporting duty** of its own. It *may* answer
its customer's questionnaire, and from financial year 2027 the large customer may not *require*
more than the Annex II datapoints. The useful product is therefore: help the company answer the
VS Basic Module (and the capped Comprehensive datapoints) correctly, from data it already has,
and tell it plainly what it may decline.

### What NONOX already has

| VS datapoint | Source in NONOX | Quality |
|---|---|---|
| B1 general information (legal form, NACE/SNI, turnover, balance sheet total, employees, country, sites) | `teams` (§3.3 fields), SNI on the company where set, annual report figures (`annual_reports.average_employees`, `average_employees_fte`, ADR-058/059), saldobalans | measured (bookkeeping) |
| B3 energy (electricity, heating, fuel) | supplier invoices on BAS accounts for electricity, heating and fuel (`supplier_invoice_rows.account_id`, `amount_ore`); **no quantity field today** (`database/migrations/*create_supplier_invoices*`: rows have account, description, amount, dimensions) | amounts measured; kWh / litres missing |
| B3 business travel, freight (Scope 3, optional) | BAS accounts for tickets, car hire, freight | amounts only |
| B8 employees by contract type | `employments.employment_type` (TILLSVIDARE vs. fixed-term kinds), `employment_rate_bp`, start/end dates (payroll module) | measured |
| B8 employees by gender | **not stored**; the payroll module has no gender field | missing |
| B9 accidents | not recorded; sick-pay periods (`sick_pay_periods`) do not say whether an absence was work-related | missing (manual) |
| B9 rate (needs hours worked) | time reports (`app/Modules/Payroll/Models/TimeReport.php`), schedules, `weekly_hours` | measured for hourly staff, scheduled for salaried |
| B10 minimum wage, collective agreement coverage | collective agreements module (ADR-033) links employments to an agreement | measured where the module is used |
| B10 training hours | not recorded | missing (manual) |
| B11 corruption convictions | not recorded | manual yes/no |
| B4–B7 pollution, biodiversity, water, waste | water and waste-collection costs on BAS accounts; no quantities | amounts only (manual quantities) |

Note on BAS: the seeded K2 template (`database/data/bas/2026/k2.json`) contains only common accounts
(e.g. 5010 Lokalhyra, 5611 Drivmedel för personbilar, 5800/5810 Resekostnader/Biljetter). The
mapping in §2 refers to the standard BAS plan, which users add to as needed (AGENTS.md §6.1).
`TODO(legal-verify)`: the exact BAS 2026 account numbers and names used in the mapping below.

## Decision

### 1. A module, `sustainability` ("Hållbarhetsdata"), off by default

Built on the module framework (ADR-017), key `sustainability`, permissions
`sustainability:view` and `sustainability:manage`. Owner, admin and accountant get both; auditor
gets `:view`. UI under Rapporter → **Hållbarhetsdata** (`/rapporter/hallbarhetsdata`). It is a
reporting aid, not part of the bookkeeping: it never posts vouchers and never changes
räkenskapsinformation.

### 2. Every figure carries its provenance: measured, entered, or estimated

The core rule, and the reason the module is credible: **each number in the report is one of
three kinds, and the kind is printed next to it** in the UI, the PDF and the export.

| Kind | Swedish label | Meaning | Example |
|---|---|---|---|
| `MEASURED` | Uppmätt (ur bokföring/lön) | Computed from posted vouchers, invoices or payroll without assumptions | number of permanent employees on 31 December |
| `ENTERED` | Angiven av företaget | Typed by a user, with who and when (audit log, ids only) | 12 400 kWh from the electricity bill; 1 recordable accident |
| `ESTIMATED` | Uppskattad | Derived by a stated method from an amount or another figure, with the factor and its source shown | 3.1 tCO2e Scope 2 from 12 400 kWh × grid factor; 0.8 tCO2e from 9 200 kr of train tickets |

An estimated figure always shows its **method, factor, factor source and year**, and the report
summary shows the share of each total that is estimated. Nothing is rounded into looking
measured.

### 3. Energy and emissions (B3)

- **Quantities first.** Supplier-invoice rows get optional `quantity` and `unit` (kWh, MWh, litre,
  m³, kg) and an `energy_carrier` (electricity, district heating, district cooling, diesel,
  petrol, HVO, E85, natural gas, LPG, heating oil, wood pellets, other). The fields show only on
  rows booked to energy and fuel accounts (mapping below) and only when the module is on. The
  inbox interpretation (ADR-023, optional AI) may *suggest* the kWh from the invoice text; a human
  confirms, as for every interpreted field. Card and receipt purchases of fuel (expenses in
  payroll, cash vouchers) get the same optional quantity on the voucher row's `quantity` (AGENTS.md
  §6.4 already has `voucher_rows.quantity`).
- **Account mapping** (company-editable, seeded from BAS groups): electricity and heating in
  premises (BAS 50xx/51xx, e.g. El för belysning, Värme), vehicle fuel (56xx, e.g. 5611 Drivmedel
  för personbilar), freight (57xx), travel (58xx). Mapping is to a *category*, never a factor.
- **Scope 1** = fuels the company burns (vehicles it owns or leases, own boilers) × combustion
  factors. **Scope 2 (location-based)** = purchased electricity and district heating × a grid or
  network factor. Factors live in versioned data files under `database/data/emission-factors/{year}/`
  with source URL, publication date and licence per row, in the same way as BAS and tax tables.
  Candidate sources: Naturvårdsverket's emission factors and heating values for fuels ("Beräkna
  direkta utsläpp från förbränning"); for electricity and district heating a Swedish
  location-based average and, where the company enters it, its own supplier's or district-heating
  network's published value. `TODO(legal-verify)`: which Swedish source to use for the
  location-based electricity factor and district-heating factors, and the licence of each data set
  for redistribution in an AGPL repository. Until a factor is verified the module shows the energy
  in MWh and **no** tCO2e for that carrier, rather than a guessed factor.
- **Amount-only fallback.** If a company has only kronor for electricity, the module can estimate
  kWh from an average price, but marks the result `ESTIMATED` twice-derived ("uppskattad från
  belopp") and recommends entering the kWh from the bill. Off by default; the company turns it on
  per category.
- **Scope 3 (optional, outside the cap):** spend-based estimates for business travel, freight and
  purchased goods from amounts × a spend-based factor per category, clearly labelled as rough.
  Deferred to phase 4 and only with a factor source whose licence allows redistribution.
  `TODO(legal-verify)`: candidate data sets and licences.
- **Renewable share:** only from what the company enters (e.g. a guarantee-of-origin electricity
  contract). NONOX never infers "renewable" from a supplier's name.
- **GHG intensity** (where asked, e.g. per million kronor of turnover) is computed from the
  estimated total and the measured net turnover and labelled `ESTIMATED`.

### 4. Workforce (B8, B9, B10, C5)

- **Headcount by contract type** (B8): measured from employments active at the reporting date, or
  average over the year, as the company chooses (the standard allows headcount or FTE); permanent =
  `TILLSVIDARE` (and `PROVANSTALLNING` until it ends, `TODO(legal-verify)` whether a probationary
  employment counts as permanent in VS terms), temporary = the fixed-term kinds.
- **By gender** (B8): NONOX does **not** add a gender field to employees and does **not** derive
  gender from the personal number's second-to-last digit. The digit encodes legal sex as
  registered, not gender; using it for a new purpose needs its own compatibility assessment
  (art. 5.1 b, 6.4) and would put a new personal-data category into the module. Instead the
  company enters the aggregate counts (women / men / other / not disclosed) on the report, marked
  `ENTERED`. `TODO(legal-verify)`: whether an optional, employee-reported gender field (self-service
  on Min sida, "vill inte uppge" by default) is acceptable later, and its legal basis.
- **Turnover rate** (C5): measured from employment end dates.
- **Recordable work-related accidents** (B9): a small register in the module (date, whether
  fatal, lost days, whether reported to Försäkringskassan/AFA, employee optional) or simply an
  entered count for the year. Because a per-person entry is health data (art. 9), the default is the
  aggregate count; the per-person register needs `payroll:view-sensitive`, is encrypted, and is
  logged under ADR-068. The rate is computed with hours worked (time reports for hourly staff,
  scheduled hours otherwise, labelled accordingly) using the formula in the standard.
  `TODO(legal-verify)`: the B9 rate formula and denominator in Annex I of Regulation 2026/1560, and
  whether "recordable" maps to the Swedish duty to report work injuries (arbetsmiljöförordningen
  2 §, Arbetsmiljöverket) or to arbetsskadeanmälan under socialförsäkringsbalken 42 kap.
- **Minimum wage and collective bargaining** (B10): Sweden has no statutory minimum wage. Coverage
  is measured from employments linked to a collective agreement (ADR-033); the minimum-wage answer
  is `ENTERED` with a manual note that wage floors come from collective agreements.
  `TODO(legal-verify)`: how "applicable minimum wage" in B10 is to be answered in a country without
  a statutory minimum wage.
- **Gender pay gap**: not offered in v1 (VS lets undertakings below 150 employees omit it, and it
  needs gender per person).
- **Training hours** (B10): entered.

### 5. The report and the export

- **VS Basic Module report**, with option A (Basic) as default; option B adds only the capped
  Comprehensive datapoints NONOX can support (C1 entered text, C5 measured, C6 yes/no). Every B
  disclosure is present; those NONOX has no data for are marked "Ej tillämpligt" or "Inte angett",
  with the "if applicable" principle explained. B2, B4, B5, B6, B7, B11 are entered text or numbers.
- **"Vad får kunden kräva?"** panel: from financial year 2027 the large customer may not require
  more than the Annex II datapoints; a list of the questions inside the cap, and a short Swedish
  template for politely declining the rest, citing the directive article. `TODO(legal-verify)`:
  the template text, and the date from which it may be used (the cap applies to the reporting
  undertaking's financial years beginning on or after 1 January 2027).
- **Outputs**: PDF (own design), CSV, and in phase 3 the **EFRAG VS Digital Template** (XLSX)
  filled in, which the company can run through EFRAG's converter, and later direct XBRL if the
  taxonomy licence allows. Always the latest EFRAG version; older versions are refused.
- **Period**: a financial year of the company (the standard reports per financial year).
- **Locking**: a report can be marked *Fastställd* with a snapshot (JSON with every figure, kind,
  method and factor version) so that what was sent can be shown later. A snapshot is not
  räkenskapsinformation and can be deleted by the company.

### 6. What we say, and do not say, publicly

The marketing-law risk is real: Marknadsföringslagen (2008:486) protects traders as well as
consumers against misleading claims (5, 10 §§), and Directive (EU) 2024/825 (empowering consumers
for the green transition), applicable from 27 September 2026, blacklists generic environmental
claims that the trader cannot substantiate and claims of neutrality based on offsetting. The latter
is a B2C directive, but NONOX also sells to sole traders and we choose to apply its standard in all
our communication. Rules for the landing page, manual, changelog and comparison pages:

- Describe what the module **does**, never what it **achieves**: "Sammanställer hållbarhetsdata
  enligt EU:s frivilliga standard (VS/VSME) från din bokföring och lön" is fine; "gör ditt företag
  hållbart", "klimatsmart", "grön bokföring", "klimatneutral" or "CSRD-säkrad" are not.
- Always say that emissions are **estimates** and that NONOX does not verify or assure them; NONOX
  is not an auditor or assurance provider and the report is the company's own statement.
- Never claim that NONOX itself, its hosting, or its customers are climate-neutral or sustainable.
- Name the standard and the regulation by number; do not say "CSRD-compliant" (the customer has no
  CSRD duty, and compliance is a property of the large company's report).
- Comparisons with other programs (ADR-020) only with verifiable facts from the vendors' own pages.
  `TODO(legal-verify)`: a marketing-law review of the landing text before launch.

### 7. Phases

| Phase | Content | Size |
|---|---|---|
| 1 | Module skeleton, account mapping, workforce B8/C5 measured, B1 from company and annual-report data, entered fields for all other B disclosures, provenance kinds, PDF and CSV, manual, "Vad får kunden kräva?" panel | M (5–7 days incl. review) |
| 2 | Quantity, unit and energy carrier on supplier-invoice and voucher rows; energy in MWh (B3) measured from entered quantities; inbox suggestion of kWh | M (4–5 days) |
| 3 | Verified emission-factor data files and Scope 1 / location-based Scope 2 estimates with full method disclosure; accident register; B9 rate; EFRAG digital template export | M–L (6–8 days, gated on the factor-source and licence questions) |
| 4 | Optional spend-based Scope 3 for travel and freight; report snapshot comparison year over year | S–M (3–4 days, gated on a licensed factor set) |

Each phase follows QUICKSTART §5: changelog, manual with screenshots, landing feature card only
when live, `lang/en.json`, tests (including a tenancy test for every new model and a query-count
test for the report: the report runs a fixed number of grouped queries regardless of the number of
invoices or employees).

## Alternatives considered

- **Full Comprehensive Module or ESRS.** Rejected: NONOX customers are protected undertakings; most
  Comprehensive datapoints (transition plans, climate risks, human-rights policies) are judgement,
  not data we hold, and outside the cap.
- **Spend-based emissions for everything, from day one.** Rejected as the default: it produces
  confident-looking numbers from kronor, which is exactly the kind of claim §6 warns against, and
  it is not what B3 asks for (Scope 1 and 2 are better estimated from quantities). Kept as an
  explicit, labelled fallback.
- **Integrate a third-party carbon-accounting API.** Rejected for now: a new sub-processor receiving
  bookkeeping data, a paid dependency, and factors we cannot show or version. May return as a
  NONOX Connect integration (ADR-064) if a customer asks.
- **A gender field on every employee.** Rejected for v1 (§4): a new personal-data category for a
  voluntary report, when an aggregate count is enough.
- **No module, just a manual article with the template.** Rejected: the value is in pulling the
  measured figures out of bookkeeping and payroll, which a manual cannot do.

## Consequences

- Small companies can answer a large customer's questionnaire from their own data, know which
  questions they may decline from 2027, and show how every figure was produced.
- Supplier-invoice and voucher rows gain optional quantity fields; they are off unless the module
  is on and never affect the ledger.
- NONOX takes on maintaining emission-factor data files yearly, like tax tables, with a source and
  licence per row; until verified, the module shows energy without emissions.
- A small new store of health-related data (accidents) if the per-person register is used; it falls
  under ADR-068's read log and the payroll DPIA (`docs/gdpr/konsekvensbedomning-lon.md`), which is
  updated in phase 3, together with `registerforteckning.md` and `lagringstider.md`.
- Public wording is constrained (§6) and reviewed before launch.

## Open questions (`TODO(legal-verify)` unless noted)

1. How and when Sweden implements the Omnibus I CSRD changes and the value-chain cap (SOU 2026:27,
   lagrådsremiss, SFS 2026:626 and any later SFS); whether a Swedish rule adds anything for
   protected undertakings.
2. Annex II of Regulation 2026/1560: the exact datapoints per size column (≤ 10 / > 10 employees),
   confirmed from the OJ PDF.
3. The B9 accident-rate formula and how "recordable work-related accident" maps to Swedish
   reporting duties.
4. How B10's "applicable minimum wage" is answered in Sweden.
5. Whether probationary employment counts as permanent for B8.
6. Emission-factor sources for Swedish electricity (location-based) and district heating, and the
   redistribution licence of each factor data set (Naturvårdsverket, others) and of the EFRAG XBRL
   taxonomy.
7. Whether an optional self-reported gender field is acceptable later, and on what legal basis.
8. Marketing-law review of all public texts about the module (MFL, Directive 2024/825 standard).
9. (Owner decision) Whether the module is part of the base price or a paid module.

## Sources

- Directive (EU) 2026/470 (Omnibus I), amending Directives 2013/34/EU, 2022/2464 and others, OJ
  26 February 2026: https://eur-lex.europa.eu/eli/dir/2026/470/oj/eng
- Commission Delegated Regulation (EU) 2026/1560 establishing sustainability reporting standards
  for voluntary use (VS), OJ L 21 September 2026: https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=OJ%3AL_202601560
- Commission Delegated Regulation C(2026) 5011 final (adopted text, 3 July 2026):
  https://ec.europa.eu/finance/docs/level-2-measures/csrd-delegated-act-2026-5011_en.pdf
- Commission Recommendation (EU) 2025/1710 on a voluntary sustainability reporting standard for
  SMEs (VSME), 30 July 2025: https://eur-lex.europa.eu/eli/reco/2025/1710/oj/eng
- EFRAG, VS Digital Template and XBRL Taxonomy (version 2.0.0, 4 November 2026):
  https://www.efrag.org/en/vsme-digital-template-and-xbrl-taxonomy
- Directive (EU) 2024/825 (empowering consumers for the green transition), arts. 1 and 4, Annex I
  points 4a and 4c: https://eur-lex.europa.eu/eli/dir/2024/825/oj/eng
- Marknadsföringslagen (2008:486) 5 and 10 §§ (not fetched for this ADR; `TODO(legal-verify)`).
- SOU 2026:27 *Lättnader i kraven på hållbarhetsrapportering* and SFS 2026:626 (found via search,
  primary text not read; `TODO(legal-verify)`).
- Naturvårdsverket, *Beräkna direkta utsläpp från förbränning* (emission factors and heating values
  for fuels): https://www.naturvardsverket.se/vagledning-och-stod/luft-och-klimat/berakna-klimatpaverkan/berakna-direkta-utslapp-fran-forbranning/
- GHG Protocol Corporate Accounting and Reporting Standard (referenced by B3; not fetched).
- Repository: `app/Modules/Payroll/database/migrations/2026_10_30_170000_create_payroll_tables.php`
  (employees, employments), `app/Modules/Payroll/Models/TimeReport.php`,
  `app/Models/AnnualReport.php`, `database/data/bas/2026/k2.json`, `docs/adr/ADR-017-module-framework.md`,
  `docs/adr/ADR-020-naming-other-programs-on-public-pages.md`, `docs/adr/ADR-033-nonox-avtal.md`, `docs/adr/ADR-023-inbox.md`.
