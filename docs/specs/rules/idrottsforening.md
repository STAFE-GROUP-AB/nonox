# Rule sheet: NONOX Förening, the economy of a sports club (önskemål)

Researcher pass, 2026-11-16, for a module not yet scheduled.

- **Status:** a larger owner wish (Önskemål). It is not built while the feature freeze is in force.
- **Not legal advice.** Every rule marked `TODO(legal-verify)` is a working understanding until a lawyer or accountant answers the L-questions at the end. Amounts are 2026 values unless stated.
- **Rule numbering.** Rules are numbered **IF-R1 …** and each cites the source it rests on. A rule without a law, BFN, Skatteverket or RF source is a **NONOX choice** and says so.

## 1. What the module is, and what it is not

**Target customer.** The target is the Swedish ideell idrottsförening: a club in a specialidrottsförbund (SF) under Riksidrottsförbundet (RF).
- The kassör is a volunteer who changes every year or two.
- RF has about 19 000 member clubs. About 10 300 were granted LOK-stöd for 2025 (RF, preliminary report May 2026).

**Goal.** NONOX becomes the sports club's best friend for its economy. The idea, not yet decided, is the price of the existing Företag plan, 995 kr per year.

**In scope: the money and the law around it.**
- Bookkeeping in kassabok form.
- Fee invoicing and payment matching (OCR, Swish).
- Grants (LOK, projektstöd, kommunala stöd).
- Lotteries.
- Payments to leaders, referees and players, with the thresholds.
- Expenses.
- Kiosk and event cash.
- Tax and VAT status.
- The closing, and the annual meeting and audit package.

**Out of scope, on purpose (NONOX choice).**
- NONOX is **not** a member register.
- It does not record attendance (närvaro), plan activities or events, or schedule volunteers (funktionärer).
- It does not lend equipment or run a marketplace.

**How member data reaches NONOX.**
- A club keeps its members in its member system, and NONOX receives what invoicing needs from it.
- The channel is a CSV import or the NONOX API (ADR-064).
- What NONOX receives: payer, guardian, fee category and a member reference.
- What NONOX returns: the payment status ("betald till och med") through the API and webhooks.
- LOK attendance is reported by the club in RF's own service, Idrottsarenan. NONOX imports the **outcome** (the decided amount) and archives the underlag.

**Reuse, with nothing new in the core.**
- **Company model:** the company is `LegalForm::IdeellForening`, which already exists.
- **Already built:** BAS K1 template, vouchers, attachments, archive package, SIE4, recurring invoicing with OCR, reminders, camt.054 import, bank reconciliation, supplier invoices with attest, payment files, cost centres and projects, payroll with AGI, mileage and expenses, NONOX AI receipt reading, roles (`auditor` read-only), ownership transfer and Mina datum.
- **Module key** (ADR-017): `association`.

## 2. Sources actually read

The full researcher notes, with quotes, were kept outside the repository. The primary sources were these:

| Id | Source |
|---|---|
| BFL | Bokföringslag (1999:1078), riksdagen.se open data: 1 kap. 2 §, 2 kap. 2–4 §§, 5 kap. 2 §, 6 kap. 1, 7 §§, 7 kap. |
| K1 | BFN, BFNAR 2010:1 *Förenklat årsbokslut* (last amended BFNAR 2024:6), bfn.se: punkterna 1.1, 6.12, 6.27, 6.48, 6.76–6.77, 7.1–7.4, bilaga 2 |
| K2Å | BFN, BFNAR 2017:3 *Årsbokslut* (amended by BFNAR 2026:1 from fiscal years 2027), bfn.se: 4.9–4.12, 5.27–5.29, 5.41, 16.4, exempel 5 n |
| IL | Inkomstskattelag (1999:1229): 7 kap. 3–11 §§, 8 kap. 3 §, 63 kap. 11 §, 65 kap. 10 § |
| SAL | Socialavgiftslag (2000:980), "Ändrad t.o.m. SFS 2026:493": 2 kap. 14 § (under 1 000 kr), 2 kap. 19 § (idrottsutövare, half a prisbasbelopp), read 2026-11-16 |
| SFL | Skatteförfarandelag (2011:1244): 10 kap. 4 §, 10 kap. 11 §, 11 kap. 20 §, 24 kap. 1 §, 30 kap. 4 §, 33 kap. 3–4 §§, 39 kap. 4–5 §§ |
| ML | Mervärdesskattelag (2023:200): 4 kap. 6 §, 9 kap. 18 §, 10 kap. 28–29, 34, 36 §§, 18 kap. 4 § |
| SpelL | Spellag (2018:1138): 3 kap. 5 §, 6 kap. 2, 9–10 §§, 14 kap. 8 §, 18 kap. 10 §, 19 kap. Spelförordning (2018:1475) 4 kap. 7 § |
| LOKF | Förordning (1999:1177) om statsbidrag till idrottsverksamhet, last amended SFS 2025:20: 3, 6 a–6 d, 7 c, 7 e, 11–12 §§ |
| LOK | RF, *Föreskrifter för statligt lokalt aktivitetsstöd* (Riksidrottsstyrelsen 2025-05-22, in force 2025-07-01): 6, 7, 11, 12, 14 §§ |
| RF-RED | RF, *Idrottens redovisning* handbook v 3.8 and *Idrottens BAS-kontoplan* v 3.3 (February 2026) |
| SKV | Skatteverket pages on ideella föreningar, idrottsersättning, gåvor, traktamente, bilersättning, AGI dates, kassaregister and personalliggare |
| SPI | Spelinspektionen pages and the model redovisningsblankett for registreringslotterier |
| RL | Räntelag (1975:635) 3–4 §§; Lag (1981:739) om ersättning för inkassokostnader 2, 4 §§; Föräldrabalken 9 kap. 1 § |

**Prisbasbelopp (pbb):** 59 200 kr for 2026 and 59 600 kr for 2027 (set by the government, September 2026).

**Amounts are data, never code.**
- Every amount below lives in a dated rule table with `source_url` and `verified_at`, like the payroll rules (ADR-012).
- A year without a verified row blocks the dependent action.

## 3. Rules

### 3.1 Bookkeeping duty and closing

- **IF-R1 Bokföringsskyldighet** (BFL 2 kap. 2–4 §§).
  - **When the duty applies:** an ideell förening must keep books if any of these hold:
    - its assets exceed 1,5 Mkr, measured at market value, with real property at taxeringsvärde;
    - it runs näringsverksamhet;
    - it is a moderföretag.
  - **Kiosk and similar sales:** according to BFN, a kiosk, rentals or paid events can amount to näringsverksamhet.
  - **When it ends:** after three consecutive years below 1,5 Mkr.
  - **System behaviour:** NONOX runs the test every year. When in doubt it says the club is bokföringsskyldig, as BFN advises.
- **IF-R2 One ledger for every club** (NONOX choice).
  - A club that is not bokföringsskyldig still uses real vouchers.
  - The **kassabok screen** (in and out, category, receipt photo) writes ordinary posted vouchers.
  - Immutability (AGENTS.md §1.2) is therefore never relaxed, and the club can grow into the duty without migrating.
- **IF-R3 Closing regime** (BFL 6 kap. 1 §; K1 1.1; K2Å).
  - **K1:** applies if nettoomsättning is normally ≤ 3 Mkr and the club is not a moderföretag. Bidrag, gåvor and medlemsavgifter count toward the 3 Mkr (BFL 1 kap. 2 § andra stycket), so LOK-stöd does too.
  - **Above that:** årsbokslut under BFNAR 2017:3.
  - **When an årsredovisning is required:**
    - for a "större företag";
    - for a moderföretag in a larger group;
    - for a club with **spellicens** and a balansomslutning ≥ 5 Mkr (BFL 6 kap. 1 § 8).
  - **Default threshold check:** `ReportingFrameworkRules` already allows K1 for an ideell förening. Its 3 Mkr message cites BFNAR 2006:1 and must also cite BFNAR 2010:1.
- **IF-R4 The förenklat årsbokslut** (K1 bilaga 2; BFL 6 kap. 7 §).
  - **Form:** the exact layout of bilaga 2, with the income lines Medlemsavgifter, Gåvor, Bidrag, Nettoomsättning and Övriga intäkter.
  - **Deadline:** ready within 6 months of the year end.
  - **Signing:** by the board, with a digital signature and date.
  - **No filing:** nothing goes to Bolagsverket.
  - **Audit:** revision follows from the stadgar, not the law.
- **IF-R5 Inventarier** under half a pbb may be expensed at once in K1 (6.27). The limit is read from data per year.
- **IF-R6 Fiscal year** follows the stadgar. A broken year is allowed. Kontantmetoden can be chosen up to 3 Mkr (BFL 5 kap. 2 §).

### 3.2 Fees

- **IF-R7 A medlemsavgift is never a kundfordran** (K1 6.48 and 7.1; BFNAR 2017:3 exempel 5 n). BFN's reason is that the member "is not bound to pay".
  - **K1:** the fee is income when paid. An unpaid avi creates no voucher.
  - **K2Å:** income is spread linearly over the period, with prepayments on 2972. Unpaid fees are not recognised.
  - **Avgifter with a motprestation are ordinary receivables:** träningsavgift, terminsavgift, deltagaravgift, cup and läger.
  - **The fee types are separate in the data model:**
    - medlemsavgift;
    - tränings-, termins- or deltagaravgift;
    - licens or förbundsavgift collected for the förbund, booked as a liability on 2890 and never as income;
    - serviceavgift.
- **IF-R8 Fee categories and status** (NONOX choice, built on IF-R7).
  - **Categories:** the club defines them (barn, ungdom, vuxen, familj, stödmedlem, and so on), each with a price, a period (kalenderår or säsong) and its fee type.
  - **Status per member reference:** the module keeps **"betald till och med"** and, for licences, "licens betald säsong". Payment events update it, and leaders can see it.
- **IF-R9 Family fee.** One payer and one avi. Each family member gets a row with their own status, and the amount is split between the rows.
- **IF-R10 Minors** (FB 9 kap. 1 §). A minor cannot be made liable to pay, so the invoice goes to the vårdnadshavare as payer.
- **IF-R11 Late payment** (RL).
  - **Påminnelseavgift:** only when the term was agreed when the debt arose. The data model holds a "villkor accepterat"-date. The cap is 60 kr.
  - **Förseningsersättning (450 kr):** blocked against members and guardians.
  - **Dröjsmålsränta:** from the due date only when agreed in advance; reference rate + 8.
  - **Inkasso:** off by default for medlemsavgift and allowed for träningsavgift. `TODO(legal-verify)` L1.
- **IF-R12 Passive members.** A list of members unpaid for two years in a row, as a basis for the board's decision (RF model stadgar 2 kap. 4 §).
  - **NONOX does not end memberships.** It returns the list to the member system.
  - **Write-off:** a written-off training fee is a voucher with an audit trail.
- **IF-R13 Fritidskortet.** Two payments on one invoice (E-hälsomyndigheten plus the guardian) match the same OCR. The money is booked as fee income, not as a grant.

### 3.3 Payments in: OCR and Swish

- **IF-R14 Swish in the bank file** (ISO 20022).
  - **What arrives:** Swish payments arrive in camt.053/054 with the payer's mobile number and the Swish reference. The message text is often there (`Ustrd`).
  - **Matching:** OCR first, then the Swish reference, then the message plus amount. NONOX AI may suggest a match; the kassör confirms.
  - `TODO(legal-verify)` L6: whether every bank carries the message.
- **IF-R15 Swish report import.** The bank's Swish report (CSV) is an alternative source, with a mapping per bank.
- **IF-R16 Swish costs are visible.** The transaction fees (about 1,10–2,50 kr per payment depending on bank and package) are booked from the bank file, so the club sees what Swish costs.

### 3.4 Grants (LOK and others)

- **IF-R17 Grant register.** Each grant is a case with:
  - donor and type (LOK, projektstöd, anläggningsstöd, kommunalt aktivitetsstöd, lokalbidrag, other);
  - decision date and amount;
  - conditions;
  - due date for the redovisning;
  - payment plan (for example 75 % in advance and the rest on report);
  - status: sökt, beviljat, utbetalt, återrapporterat, återkrävt.
  - The decision and the application are räkenskapsinformation (RF-RED), kept 7 years and included in the archive package.
- **IF-R18 LOK facts** (LOKF, LOK). These drive reminders and warnings only. NONOX never calculates eligibility from attendance.
  - Age 6–25 in the calendar year (LOKF 3 §, LOK from 2025-07-01).
  - At least 3 participants and at least 45 minutes; the leader is at least 13.
  - Participants must be members (LOK 6 §).
  - Application periods: 1/1–30/6, due 25 August; 1/7–31/12, due 25 February.
  - Late applications are reduced by 25/50/75 %, and from 1 October and 1 April they are rejected.
  - Payment goes only to the club's own bankgiro or plusgiro.
  - RF may check (LOK 12 §) and reclaim (LOK 14 §).
- **IF-R19 Cap for new clubs** (LOKF 6 d §, from 2026-01-01). A club joined to RF less than two years ago gets at most 1 pbb per calendar year in grants under the förordning. NONOX sums grants against the cap.
- **IF-R20 Blocks on grants** (LOKF 6 c §). Bankruptcy, liquidation, a Kronofogden debt or an unpaid repayment to RF stops all new grants. NONOX registers a repayment as a liability and warns while it is open.
- **IF-R21 Anmälningsplikt** (LOKF 7 e §). A change that affects a running grant creates a task to notify the donor, logged in the audit trail. Examples: a cheaper investment, a sale, or a change of project.
- **IF-R22 Recognition of grants.**
  - **K1 7.3:** the club chooses:
    - (a) income when paid;
    - (b) income in the years the grant concerns.
    - With (b), an unpaid grant is a receivable only if a **decision with an amount** exists before the closing is done. NONOX asks for the decision to be attached.
  - **Conditional grants:** a liability until repayment is unlikely (7.4, 6.77).
  - **K2Å 5.27–5.29:** grants are income in the same year as the costs. NONOX proposes an accrued income on 1790 for the autumn LOK at year end. Unused grants go to "Mottagna ej använda bidrag" (4.11).
  - **Investeringsbidrag** reduce the asset's cost in the fixed-asset register and are never income (K1 6.12/6.22/6.33).
  - **No periodisering below the threshold:** 5 000 kr according to BFN, 7 000 kr in RF-RED. `TODO(legal-verify)` L4.
- **IF-R23 Project accounting.**
  - Earmarked grants run as projects over several years, with a budget per cost type and a report of budget against outcome.
  - **Report package:** all vouchers with attachments, and costs including VAT where the grant requires it (anläggningsstöd).
  - **At the end:** the unused amount is calculated and a repayment proposed.
  - **Warning:** when project money is booked as a grant to another club (RF project rules 5.3).
- **IF-R24 Accounts.**
  - **Suggested accounts from RF's Idrottens BAS-kontoplan:**
    - 3820 statliga bidrag (LOK, RF projects), 3810 kommunala bidrag;
    - 3890 medlemsavgifter, 3015/3055 träningsavgifter;
    - 3210 sponsring, 3310 lotterier;
    - 2972 förutbetalda medlemsavgifter, 2890 skuld till förbund.
  - Shipping RF's plan as a template depends on its licence (`TODO(legal-verify)` L5). Otherwise NONOX ships its own sports template mapped to BAS.
  - The existing `database/data/bas/2026/k1.json` lacks the 38xx accounts.

### 3.5 Payments to people

- **IF-R25 Category per payment line, not per person.** The categories:
  - `IDROTTSUTOVARE`: player, coach, instructor, team leader, referee, linesman, kit manager, competition official;
  - `OVRIGT_ARVODE`: office, kiosk, course leader;
  - `STYRELSEARVODE`;
  - `LON`;
  - `KOSTNADSERSATTNING`, `BILERSATTNING`, `TRAKTAMENTE`, `GAVA`, `PRIS`.
  - Board members, office staff, the kitchen and sellers are not idrottsutövare. A team doctor is not one either (RÅ 2004:88).
- **IF-R26 Taxable from the first krona.** A payment to an idrottsutövare is taxable income for the recipient from the first krona (SKV). There is **no** tax-free threshold for the recipient. The relief applies only to the club:
  - no arbetsgivaravgifter when the payments from the club to that person during the year "inte har uppgått till" half a pbb (SAL 2 kap. 19 §): 29 600 kr for 2026 and 29 800 kr for 2027;
  - no skatteavdrag when the club "rimligen kan anta" the year stays below it (SFL 10 kap. 4 § p. 4). That is a forecast.
  - The club must be allmännyttig under IL 7 kap. 4–6 and 10 §§. If it is not, the category is blocked.
- **IF-R27 Other recipients** (SAL 2 kap. 14 §; SFL 10 kap. 4 § p. 2).
  - Under 1 000 kr per person and year: no fees and no skatteavdrag, but reported from 100 kr.
  - From 1 000 kr: full fees and skatteavdrag, usually 30 % (SFL 11 kap. 20 §). No skatteavdrag against F-skatt (10 kap. 11 §).
- **IF-R28 Threshold watch.**
  - **Running totals:** per club, recipient and year, in two buckets: half a pbb and 1 000 kr.
  - **Forecast:** recurring fees count toward it.
  - **Warning at 80 %:** NONOX warns.
  - **When the limit is reached:** a blocking dialog explains that the whole year becomes fee-liable and that earlier AGI months must be corrected, and proposes the correction (field 131 → 011, or the avräkning field 010).
  - **Per payer:** a referee paid by several clubs counts only toward ours.
- **IF-R29 Reporting.**
  - **Under the threshold:** AGI field 131 **or** KU10 box 031, never both. Styrelsearvode goes in KU10 box 023.
  - **Deadlines:** KU10 by 31 January (SFL 24 kap. 1 §). AGI on the 12th, and on the 17th in January and August.
  - **Reuse:** the payroll AGI generator.
  - **Registration:** a reminder to register as an employer (SKV 4620) when someone without F-skatt passes 1 000 kr, with seasonal registration as an option.
- **IF-R30 Cost compensation** (SKV).
  - **Expenses:** need a receipt.
  - **Car:** 25 kr/mil is tax-free for a tjänsteresa. The excess becomes a salary part automatically. Travel to the home ground or to regular training is taxable.
  - **Traktamente:** 300/150 kr requires an overnight stay at least 50 km from home.
  - **No flat allowance:** a flat "omkostnadsersättning" without receipts is booked as salary, with an explanation.
  - **Payment:** the attester may not attest their own payment. Cash needs a signed receipt.
- **IF-R31 Gifts** (SKV). The limits for employees are julgåva 600 kr, jubileumsgåva 1 800 kr and minnesgåva 15 000 kr. Money and cash-like cards are always taxable. For unpaid volunteers and the board the tax-free status is not confirmed; NONOX warns instead of booking it as tax-free. `TODO(legal-verify)` L7.
- **IF-R32 Personnummer** only for recipients who must be reported.
  - Stored with the `encrypted` cast and visible only with the payroll or finance permission.
  - Legal basis GDPR art. 6.1 c (SFL, BFL).

### 3.6 Lotteries and fundraising

- **IF-R33 Who may run lotteries.** Allmännyttiga ideella föreningar (SpelL 6 kap. 2 §).
- **IF-R34 Lottery register.** Each lottery records:
  - its type:
    - at the club's own event, without registration (3 kap. 5 § 1);
    - registreringslotteri at the kommun (6 kap. 9–10 §§);
    - licence lottery from Spelinspektionen;
    - bingo, which always needs a licence;
  - its kommun, number and validity, contact person and kontrollant;
  - the ticket price and count, and the prize plan.
- **IF-R35 Checks per type.** All are computed from the year's pbb.
  - **Own event:** a ticket costs at most 1/4 000 pbb (14,80 kr). Prizes are goods only, the largest at most 1/6 pbb, and sales stay on the event site.
  - **Registreringslotteri:**
    - prizes are 35–50 % of stakes, and a cash prize is at most 1 pbb;
    - the cap is **33 1/3 pbb per five-year period** (spelförordningen 4 kap. 7 §), not per year;
    - NONOX meters the cap, warns at 80 % and stops at 100 %.
  - **Licence lottery:** at most 50 % prizes. Age limit 18 and a credit ban, so tickets are never invoiced (14 kap. 8 §).
  - **Sales channel:** selling to buyers outside the kommun on social media needs a licence (SPI). NONOX warns when the sales channel is web.
- **IF-R36 Accounting per lottery.**
  - **Own objects:** each lottery is its own project, with stakes, prize purchases, print and the kontrollant fee (suggested 3 % of turnover, or the kommun's taxa).
  - **Prizes:** valued at market value. Donated prizes are recorded separately.
  - **Kontrollant:** the report to the kontrollant is due by 15 February. The kontrollant reports to the kommun by 1 April (18 kap. 10 §).
  - **Separate account:** a separate bank account is needed only when the kommun makes it a condition.
  - **Reporting:** a report in the shape of Spelinspektionen's model form, as PDF.
- **IF-R37 Tax on lotteries.**
  - **VAT:** exempt (ML 10 kap. 34 § 2).
  - **Income tax:** free for the club (IL 7 kap. 3 § andra stycket 1 d or e).
  - **Prizes:** free for the winner (IL 8 kap. 3 §).
  - **Spelskatt:** none on lotteries.
- **IF-R38 Fundraising.**
  - **No permit:** there is no general permit duty.
  - **90-konto:** a voluntary label for fundraising from Svensk Insamlingskontroll. It has **nothing to do with lotteries**, and a normal club does not need one.
    - **Conditions:** K3, an auktoriserad revisor and costs of at most 25 %.
    - **Fees:** 5 000 kr to set up, and a yearly fee of 0,055 % of income (minimum 5 000 kr, maximum 60 000 kr).
    - **In NONOX:** the module has a 90-konto mode with the cost-share figure, but it is not a default.
  - **Every incoming payment is tagged** gåva, bidrag, sponsring (with the motprestation described) or medlemsavgift.

### 3.7 Tax status, VAT, kiosk and events

- **IF-R39 Income tax status** (IL 7 kap. 3–11 §§).
  - **Allmännyttig flag:** a club setting "allmännyttig" (yes, no or unsure, with date and who confirmed) drives tax, VAT and the IF-R26 category.
  - **Tax code per income account or article:**
    - member fee, gift or grant;
    - natural connection (entry fees, cup fees, simple refreshments, simple sports articles to the active members);
    - hävd (lottery at an event, advertising and sponsorship, parties, markets, bingo);
    - lottery under SpelL 6 kap.;
    - taxable business (clothes sold as trade, cleaning or guarding for others, renting to outsiders).
- **IF-R40 Huvudsaklighet and fullföljd.**
  - **Huvudsaklighet meter:** the tax-free share of business income, with a warning below 80 % and an alarm below 75 %, shown over three years (IL 7 kap. 5 §).
  - **Fullföljd meter:** the share of income used for the purpose (7 kap. 7 §). Practice is about 80 %, which is not law. `TODO(legal-verify)` L2.
  - **INK3:** when taxable income exceeds the grundavdrag of 15 000 kr (IL 63 kap. 11 §). Otherwise a särskild uppgift, with a stored exemption decision (SFL 33 kap. 3–4 §§).
- **IF-R41 VAT follows income tax** (ML 4 kap. 6 §).
  - **Tax-free activity:** what is tax-free for an allmännyttig club is outside VAT altogether: no output VAT and no deduction.
  - **Taxable parts:** these use dated rates:
    - sports services 6 %;
    - food 6 % from 2026-04-01 to 2027-12-31, then 12 % (`TODO(legal-verify)` L8);
    - restaurant 12 %;
    - everything else 25 %.
  - **Facilities:** short-term letting of sports facilities is taxable (10 kap. 36 §).
  - **Registration:** NONOX meters the 120 000 kr limit for the current and two previous years (18 kap. 4 §).
  - **Renumbering:** ML 9 kap. is renumbered from 2028-01-01, so VAT codes must not depend on paragraph numbers.
- **IF-R42 Kiosk and events.**
  - **Day cash:** one per kiosk or event, with change float and a split between cash, card and Swish. Cash is booked no later than the next working day (BFL 5 kap. 2 §).
  - **Kassaregister:** not required when the sale is free of income tax (SFL 39 kap. 5 § 2). Otherwise it is required above 4 pbb (236 800 kr for 2026) of taxable cash and card sales. Swish counts as card.
  - **Personalliggare:** required when more than 25 % of the costs concern a café or kiosk. Volunteers must then be recorded too.
  - **What NONOX does:** it calculates both tests. The till itself comes from the existing till integrations (ADR-042); NONOX does not build a till.

### 3.8 Annual meeting, audit and the kassör change

- **IF-R43 Annual meeting package.**
  - **Contents:** the förenklat årsbokslut (IF-R4), budget against outcome per section and team (cost centres), and a grant report per donor (the basis for the grant note).
  - **Text:** a template for the economic part of the verksamhetsberättelse.
  - **Export:** every document with a checksum, as in the archive package.
- **IF-R44 Audit flow.**
  - **Read access:** the revisor has the `auditor` role during the year.
  - **Reminders:** the accounts are due to the auditors one month before the annual meeting, and the revisionsberättelse 14 days before (RF model stadgar).
  - **Storage:** the revisionsberättelse is stored. The "revisormapp" is the archive package plus a checklist.
- **IF-R45 Kassör change** (NONOX choice, built on ownership transfer, ADR-024).
  - **Handover:** the role moves and all history stays.
  - **Questions:** the outgoing kassör answers a short set of questions: where the money is, which agreements, which grants are running, and which lotteries are open. The answers follow to the next kassör.
  - **Bank details:** the registered bankgiro is shown, with a reminder to update it at RF too.
- **IF-R46 Leader role** (NONOX choice). A leader sees and attests only their own cost centre (team): the team's budget, outcome and expenses, and not the whole club.
- **IF-R47 Dates in Mina datum.**
  - The LOK deadlines, project reports, the lottery report, KU10 and AGI, the annual meeting and the auditor dates.
  - Today `YearEndDeadlineProvider` returns no dates for an ideell förening.

### 3.9 Privacy

- **IF-R48 Legal basis per purpose.**
  - Invoicing: the membership agreement.
  - Bookkeeping and grant underlag: legal obligation (BFL, LOK).
  - Räkenskapsinformation is kept 7 years. Payer data that is not räkenskapsinformation is deleted when the member leaves and the debt is settled.
  - Minors' data: NONOX receives only what invoicing the guardian needs.
  - Update `docs/gdpr/registerforteckning.md` and the privacy policy before the build ships (a new category: guardians and minors).
- **IF-R49 Tenancy.** Each club is its own company (AGENTS.md §1.3). Nothing is shared with a förbund without an agreement or a legal basis. A future förbund view shows only aggregated figures without personal data.

## 4. Phases (proposal, NONOX choice)

1. **Grund.**
   - The `association` module, the allmännyttig flag and the bokföringsskyldighet test.
   - The kassabok screen.
   - The sports chart (IF-R24).
   - The förenklat årsbokslut in the bilaga 2 layout.
   - The annual meeting package and auditor reminders.
   - The kassör change.
   - Mina datum.
2. **Avgifter.**
   - Fee categories and fee types (IF-R7–R13).
   - The import from the member system with "betald till och med" back through the API.
   - Swish and OCR matching (IF-R14–R16).
3. **Bidrag.** The grant register, LOK reminders and the cap, recognition per regime, and project accounting with reports (IF-R17–R24).
4. **Ersättningar.**
   - Categories, threshold watch, AGI/KU10 and cost compensation (IF-R25–R32).
   - Expenses from the phone, with NONOX AI and the payment file.
   - The leader role.
5. **Lotterier, kiosk och skatt.** The lottery register with checks and the report, day cash, kassaregister and personalliggare tests, and the tax and VAT meters (IF-R33–R42).

Each phase follows the usual order: rules data → tests → actions → UI → manual and screenshots → landing page and comparison.

## 5. Price idea (owner, 2026-11-16, not decided)

- **Price:** 995 kr per year, the same as the Företag plan, with the module included, unlimited users and no fee per invoice.
- **How that compares:** the bookkeeping offers for clubs published by other vendors cost roughly 2 100–3 300 kr per year, often with a fee per sent invoice.
- **What the price must include to be compelling:** the whole chain:
  - bookkeeping;
  - fee invoicing with OCR and Swish matching;
  - payments to leaders and referees with the thresholds;
  - grants;
  - the annual meeting package;
  - the kassör change.
- **Swish fees** are the bank's and stay outside the price. The club should see them (IF-R16).

## 6. L-questions (TODO(legal-verify))

- **L1.** Can an unpaid medlemsavgift be collected under civil law?
  - BFN says it is not a receivable. RF says the membership is an agreement.
  - The answer affects inkasso and reminders (IF-R11).
- **L2.** Does Skatteverket still read the fullföljdskrav as about 80 % over five years?
- **L3.** Has the proposed raise of the idrottsutövare limit to 1 pbb (Fi2024/01006) been enacted or dropped? The law text, amended up to SFS 2026:493, still says half a pbb.
- **L4.** The periodisering threshold in K1: 5 000 kr (BFN) or 7 000 kr (RF-RED)?
- **L5.** The licence terms of RF's Idrottens BAS-kontoplan, for redistribution in an open-source repository.
- **L6.** Does every bank carry the Swish message in camt.053/054?
- **L7.** Are small gifts to unpaid volunteers and board members tax-free?
- **L8.** Food VAT 6 % from 2026-04-01 to 2027-12-31: confirm the enacted dates.
- **L9.** Kassaregister: what changes from 2027-01-01 under Skatteverket's new föreskrifter?
- **L10.** Does a kiosk at the club's own matches count as servering (12 %) or as sale of food?
- **L11.** Which year's pbb applies to the five-year cap of a registreringslotteri?
- **L12.** Does an idrottsersättning count toward the 1 000 kr limit for the same person paid as a board member?
- **L13.** Does RF, at a check, require proof that the participant's fee was **paid**? LOK 6 § requires membership only.
- **L14.** Insurance: does each förbund's insurance require a paid licence or a paid membership fee?
