# Rule sheet: ADR-074 work orders and change orders (arbetsorder och ÄTA)

Researcher pass, 2026-11-08. Input for the architect and builders of ADR-074 (roadmap item
`arbetsorder-och-ata`). Not legal advice. Every rule marked `TODO(legal-verify)` stays
unverified until a human lawyer or accountant has answered the matching L-question at the
end. Nothing here may be built as a HARD rule while its L-question is open; build it as
ADVICE (a warning) until then.

## Sources actually read

Law texts were downloaded as consolidated text from Regeringskansliet/Lagrummet
(`https://data.riksdagen.se/dokument/sfs-<år>-<nr>.text`) and read locally on 2026-11-08.

| Id | Source | Version read | How |
|---|---|---|---|
| KtjL | Konsumenttjänstlag (1985:716) — https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/konsumenttjanstlag-1985716_sfs-1985-716/ | "Ändrad: t.o.m. SFS 2010:1006" | Full text, 1–8 §§, 36–45 §§ |
| ML | Mervärdesskattelag (2023:200) — https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/mervardesskattelag-2023200_sfs-2023-200/ | t.o.m. SFS 2026:1025 | 7 kap. 7, 8, 14, 16, 24 §§; 16 kap. 13 §; 17 kap. 11, 14, 16, 24 §§ |
| IL | Inkomstskattelag (1999:1229) — https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/inkomstskattelag-19991229_sfs-1999-1229/ | t.o.m. SFS 2026:1393 | 67 kap. 11–19 §§ |
| HFL | Lag (2009:194) om förfarandet vid skattereduktion för hushållsarbete — https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/lag-2009194-om-forfarandet-vid-skattereduktion_sfs-2009-194/ | t.o.m. SFS 2025:324 | 5–9 a §§ |
| SFL | Skatteförfarandelag (2011:1244) — https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/skatteforfarandelag-20111244_sfs-2011-1244/ | t.o.m. SFS 2026:1305 | 7 kap. 2 a §; 39 kap. 2 § (definitions), 11 a–12 §§ |
| BFL | Bokföringslag (1999:1078) — https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/bokforingslag-19991078_sfs-1999-1078/ | t.o.m. SFS 2024:342 | 1 kap. 2 § p. 8–9; 5 kap. 7 § |
| DAL | Lag (2005:59) om distansavtal och avtal utanför affärslokaler — https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/lag-200559-om-distansavtal-och-avtal-utanfor_sfs-2005-59/ | t.o.m. SFS 2026:1018 | 1 kap. 2 §; 2 kap. 1 a, 5, 10–12 §§ |
| K2 | BFNAR 2016:10, BFN's consolidated vägledning — https://www.bfn.se/wp-content/uploads/vl16-10-k2ar-kons2025.pdf | t.o.m. BFNAR 2025:2 | Chapter 6, points 6.8–6.24 with commentary; ÅRL 4 kap. 10 § as quoted there |
| K3 | BFNAR 2012:1, BFN's consolidated vägledning — https://www.bfn.se/wp-content/uploads/vl12-1-k3-kons20251215.pdf | t.o.m. BFNAR 2025:4 | Chapter 23, points 23.10–23.18, 23.31 |
| PBB | Förordning (2025:872) om prisbasbelopp för år 2026, as reported by the government and SCB — https://regeringen.se/pressmeddelanden/2025/09/prisbasbelopp-for-2026-faststallt/ | — | Search result only, the SFS text itself not opened |
| SKV-ROT | Skatteverket, "Så fungerar rotavdraget" (företag) — https://www.skatteverket.se/foretag/skatterochavdrag/rotochrut/safungerarrotavdraget.4.2ef18e6a125660db8b080002709.html | read 2026-11-08, no date on page | WebFetch summary with quoted sentences |
| SKV-RR | Skatteverket, "Rot och rut" (företag) — https://www.skatteverket.se/foretag/skatterochavdrag/rotochrut.4.2ef18e6a125660db8b080002674.html | read 2026-11-08 | WebFetch summary |
| SKV-OB | Skatteverket, "Omvänd betalningsskyldighet inom byggsektorn" — https://www.skatteverket.se/foretag/moms/saljavarorochtjanster/omvandbetalningsskyldighetinombyggsektorn.4.47eb30f51122b1aaad28000545.html | read 2026-11-08, no date on page | WebFetch summary with quoted sentences |
| HF17-guide | Byggföretagen, "Ifyllnadshjälp Hantverkarformuläret 17" — https://byggforetagen.se/app/uploads/2020/01/ifyllnadshjalp-hantverkarformularet-17.pdf | 2020 upload | PDF to text, sections D, E, G, H |
| BF-ROT | Byggföretagen, "Entreprenörens checklista för ROT-avdrag" 2025-05-12 — https://byggforetagen.se/app/uploads/2025/05/Checklist_ROT_2025_0512.pdf | 2025-05-12 | PDF to text |

**Not read (gaps not filled from memory):**

- The standard contracts AB 04, ABT 06, ABS 18 and Hantverkarformuläret 17 themselves. They
  are copyrighted and sold (Svensk Byggtjänst). Nothing below quotes or paraphrases their
  clauses. What is said about them comes only from the public fill-in guide (HF17-guide) and
  the ROT checklist (BF-ROT).
- Skatteverket's rättsliga vägledning on byggtjänster (which SNI codes count, mixed supplies)
  and on rot/rut a conto payments across a year-end. Only the public pages above were read.
- Skatteverket's föreskrifter on personalliggare in construction (SKVFS) and the technical
  requirements for the ledger equipment.
- The 2027 amendments to SFL 39 kap. (prop. 2025/26:282). The consolidated SFL text read
  still carries the definitions as amended by Lag (2023:208). The construction rules below do
  not depend on the 2027 change.
- BAS 2026 account texts. The account numbers below are taken from NONOX's own INK2/INK4
  mapping (`app/Domain/IncomeTax/Ink2Codes.php`) and existing code, not from BAS. NONOX's
  BAS templates (`database/data/bas/2026/*.json`) contain none of 1470, 1620, 2420, 2450.
  An independent quote check (2026-11-08) found that BAS 2026 itself does contain 1470, 1620,
  2420 and 2450, which partly answers L12; their exact texts and the change account remain open.

## Classification used

- **HARD**: NONOX can enforce it in code (validation, block, constraint).
- **ADVICE**: NONOX computes or shows it, the user decides (warnings, reminders, proposals).
- **OUT**: out of scope for ADR-074.

## What NONOX already has (mapped 2026-11-08, so the rules can point at it)

| Area | Where | Relevant facts |
|---|---|---|
| Quotes, orders | `SalesDocument` (`QUOTE`/`ORDER`), `SalesDocumentStatus` OPEN/ACCEPTED/INVOICED/CANCELLED, ADR-011 | Rows like invoice rows; no `is_labour`, no project, no ROT on quotes/orders |
| Signing | CRM module, ADR-046: `crm_signing_requests`, `/signera/{token}`, document SHA-256, signing certificate, immutable evidence trigger | Lives inside `module:crm`; with CRM off every link is invalid |
| ROT/RUT | `App\Domain\Invoicing\TaxReduction`, `invoices.tax_reduction`, `tax_reduction_ore`, `property_designation`, `invoice_rows.is_labour`, 1513, 2890 | Only under fakturametoden (`IssueInvoice::ensureTaxReduction`); ROT needs a property designation; rates 30 % ROT / 50 % RUT from 2026-01-01; ceilings 50 000 / 75 000 kr; year of payment decides; no claim file, the user claims in Skatteverket's e-service; the PDF prints labour incl. VAT and property, **not** the buyer's personnummer and **not** the number of hours |
| Reverse charge, construction | `customers.reverse_charge_construction`, `invoices.reverse_charge`, `InvoiceBooking::REVERSE_CHARGE_CONSTRUCTION` (3231, TODO legal-verify), PDF text "Omvänd betalningsskyldighet för byggtjänster" with both VAT numbers; buyer side 2647/2617 per ADR-016 | The flag applies to **every** row of the invoice |
| Personal numbers | `customers.personal_number` encrypted cast, `$hidden` | Already handled for ROT buyers |
| Projects, cost centres | `projects` (code, name, active), ADR-009; on `voucher_rows`, `supplier_invoice_rows.project_id`, payroll `time_reports.project_id`, `employments.project_id` | Not on invoices, invoice rows or sales documents |
| Time | Payroll `TimeReport` (date, hours, pay code, project), Planning shifts and attest (ADR-037), staff ledger `TimeProposal` (ADR-022) | No billable time anywhere |
| Material | Articles with `purchase_price_ore`, `default_supplier_id`, `vat_treatment`, stock, movements (ADR-039); supplier invoice rows with project | Nothing re-invoices a supplier invoice row |
| Staff ledger | `app/Modules/StaffLedger`, `BusinessType` = restaurant, vehicle service, food/tobacco wholesale, beauty care, laundry, other | **Construction is not built** ("ID06, byggarbetsplatsanmälan is v2", ADR-022). The roadmap text "personalliggaren finns redan" is true only for the 2027 industries |
| Year-end | INK2/INK4 codes map 1470–1479, 1620–1629, 2420–2429, 2450–2459 | No pågående-arbeten step in the closing |

---

## A. Consumer law: change and additional work (KtjL)

**AO-R1 Scope.** KtjL applies to services a business performs for a consumer on movable
property, real property, buildings and other fixed things, and to storage; småhusentreprenad
(new build or extension of a one- or two-dwelling house) has extra rules in 51–61 §§.
A consumer is "en fysisk person som handlar huvudsakligen för ändamål som faller utanför
näringsverksamhet".
- KtjL 1 §, 1 a §.
- Class: ADVICE. NONOX cannot know the customer's purpose. A customer without an
  organisation or VAT number is treated as a consumer for these warnings, as `FullInvoice`
  already treats such customers as private persons. `TODO(legal-verify)` (L1).
- Test hint: a work order for a customer with an organisation number shows no KtjL warnings;
  a customer with only a personal number does.

**AO-R2 Terms worse than the law do not bind the consumer.**
- KtjL 3 §: "Avtalsvillkor som i jämförelse med bestämmelserna i denna lag är till nackdel
  för konsumenten är utan verkan mot denne, om inte annat anges i lagen."
- Class: ADVICE. NONOX must not ship default ÄTA terms that waive consumer rights. Any
  default text NONOX offers for consumer ÄTA documents goes through the lawyer (L2).

**AO-R3 Duty to notify and ask before additional work (tilläggsarbete).** When a need for
work arises that because of its connection with the job should be done at the same time
(tilläggsarbete), the business must inform the consumer and ask for instructions.
- KtjL 8 § första stycket: "skall näringsidkaren underrätta konsumenten och begära dennes
  anvisningar."
- Class: HARD in the flow: an ÄTA for a consumer customer is created as a request to the
  customer first; "performed" before "approved" is possible only through AO-R4.
- Note: the law sets **no form requirement** (no "skriftligen"). Written, signed approval is
  NONOX's evidence choice, supported by HF17-guide section G ("Det är viktigt att ni skriver
  ned vad som ska gälla beträffande omfattning, tid och pris").
- Test hints: an ÄTA cannot reach APPROVED without a recorded customer act (signature, or a
  user recording an oral approval with time, channel and who approved); an oral approval is
  stored as such and shown as weaker evidence.

**AO-R4 When additional work may be done without instructions.** If the consumer cannot be
reached or no instructions come within reasonable time, the business **may** do the work
(1) if its price is insignificant or low compared with the agreed price, or (2) if there are
special reasons to assume the consumer wants it done. It **must** do work that cannot be
postponed without risk of serious damage, if instructions cannot be obtained or the consumer
asks for it.
- KtjL 8 § andra och tredje stycket.
- Class: ADVICE. NONOX records the case, it does not judge it: an ÄTA can be marked
  "utfört utan kundens besked" only with one of three reasons (low price, presumed wish,
  urgent to avoid serious damage), at least one logged contact attempt (time, channel), and a
  free-text justification. The customer is then informed in the same way as for a request.
- `TODO(legal-verify)` (L3): what "obetydligt" and "lågt i förhållande till priset" mean in
  numbers; NONOX must not suggest a percentage.

**AO-R5 Right to a price supplement for additional work.** The business has a right to a
price supplement for additional work done in accordance with 8 §, or when the job became
more expensive because of circumstances attributable to the consumer that it could not
foresee. The size follows 36 §.
- KtjL 38 §.
- Class: ADVICE. An ÄTA carries its basis (`TILLAGGSARBETE_8`, `KUNDENS_FORHALLANDEN_38_2`,
  `ANDRING_BESTALLD` for a change the customer asks for). The basis is shown on the document
  and kept.

**AO-R6 Price when none is agreed; the 15 % limit on an approximate price.** Without an agreed
price the consumer pays what is reasonable. If the business gave an approximate price
(ungefärlig prisuppgift), it may not be exceeded by more than 15 % unless another limit was
agreed or a supplement under 38 § applies.
- KtjL 36 §: "får det uppgivna priset dock inte överskridas med mer än 15 procent, såvida
  inte någon annan prisgräns har avtalats eller näringsidkaren har rätt till pristillägg
  enligt 38 §."
- Class: ADVICE (warning, never block). A work order priced as "ungefärligt pris" stores the
  amount including VAT; its invoice basis warns when invoiced main-contract work exceeds
  115 % of it, with approved ÄTA amounts (38 §) shown separately and not counted against the
  limit.
- HF17-guide says the approximate price may "normalt" be exceeded by 15 %, and by 25 % for
  ground and demolition work. That 25 % is the standard contract's own figure, not the law.
  `TODO(legal-verify)` (L4): whether 25 % in HF17 is "annan prisgräns har avtalats" and valid
  against 3 §. NONOX uses 15 % unless the user enters another agreed limit.
- Test hint (fixture F3).

**AO-R7 Duty to warn when the price may become much higher.** If after the start it turns out
that the price may become considerably higher than the consumer could expect, the business
must inform the consumer and ask for instructions, and otherwise stop.
- KtjL 6 § andra och tredje stycket; consequence 7 §.
- Class: ADVICE. The same warning as AO-R6 is raised early from the work order's own
  recorded time and material ("upparbetat hittills"), not only at invoicing. The text does not
  say what "betydligt högre" is (L3).

**AO-R8 Specified invoice on request.** On the consumer's request the business must issue a
specified invoice that lets the consumer judge the kind and extent of the work, and, where
the price is not fixed, how it was calculated. Until such a requested invoice has arrived,
the consumer need not pay.
- KtjL 40 §, 41 § andra stycket.
- Class: HARD for the invoice basis: an invoice from a work order on löpande räkning can
  always be produced with hours per day, hourly rate, material per row and ÄTA per number
  ("specificerat underlag" as an attachment). ADVICE: the due date warning when the customer
  asked for one.

**AO-R9 Payment in advance only by agreement.** Payment before the service is performed follows
the agreement; if agreed advance payment is not made, the business may suspend the work.
- KtjL 41 § första stycket ("Om inte annat följer av avtalet ... betala vid anfordran sedan
  näringsidkaren har utfört tjänsten"), 45 §.
- HF17-guide section H: "Förskottsbetalning kräver särskild överenskommelse och kan föras in
  i en betalningsplan."
- Class: ADVICE. An a conto plan on a consumer work order asks the user to confirm that it
  is agreed with the customer (the plan is part of what the customer approves, D below).

**AO-R10 Off-premises agreements (possible, unresolved).** A contract made while business
and consumer are both present somewhere other than the business's premises is an "avtal
utanför affärslokaler". For those, information must be given in a document or durable
medium, and a copy or confirmation given after the agreement; the consumer has a 14-day
right of withdrawal (10 §, not read in full). Exceptions include contracts on the
construction of a building (1 a § p. 2), contracts under 400 kr (1 a § andra stycket), an
urgent repair at the consumer's home at the consumer's own request (11 § p. 13), and a
repair service already performed where the consumer asked for the visit and agreed to the
start (11 § p. 14).
- DAL 1 kap. 2 §, 2 kap. 1 a §, 5 §, 10–12 §§.
- Class: unresolved. An ÄTA agreed in the customer's home looks like an off-premises
  agreement on its face, but it may be an amendment of the original contract rather than a
  new one. `TODO(legal-verify)` (L5). Until answered: NONOX always sends the approved ÄTA as a
  PDF to the customer's e-mail (that is the "copy or confirmation" in durable form either
  way), and adds no withdrawal text of its own.

**AO-R11 Småhusentreprenad.** New build or extension of a one- or two-dwelling house has
special rules (51–61 §§: e.g. final inspection, 53 §).
- KtjL 1 § andra stycket, 51–61 §§ (headings read, content not analysed).
- Class: OUT for v1. A work order can be marked "småhusentreprenad" for information only.

## B. ROT and RUT on work orders and ÄTA

**AO-R12 Levels in 2026.** The reduction is 50 % of the basis for RUT work (67 kap. 13 §)
and 30 % for other household work, i.e. ROT (13 a, 13 b §§); total at most 75 000 kr a year,
ROT at most 50 000 kr of that. The payout to the business may be at most the amount the
buyer paid for RUT and three sevenths of it for ROT.
- IL 67 kap. 19 § (Lag 2025:322): "50 procent ... 13 § och 30 procent av underlaget till den
  del det avser annat hushållsarbete"; "högst 75 000 kronor"; "högst 50 000 kronor".
- HFL 7 § (Lag 2025:324): "tre sjundedelar av det belopp som köparen har betalat".
- SKV-RR (2026-11-08): ROT "30 procent av arbetskostnaden" and "50 000 kronor per person och
  år"; RUT "50 procent"; together "högst 75 000 kronor".
- The temporary 50 % ROT for payments 12 May–31 Dec 2025 (prop. 2024/25:156) has ended. This
  matches `TaxReduction::RATES` (ROT 3000, RUT 5000 from 2026-01-01) and `CEILINGS`.
- Class: HARD, already built. ADR-074 adds nothing new here; the work order reuses
  `TaxReduction`. The rate is still marked `TODO(legal-verify)` in code and stays so.

**AO-R13 Only labour counts; material, equipment and travel do not.**
- IL 67 kap. 18 § andra stycket: "I underlaget räknas inte utgifter för material,
  utrustning och resor in." 11 a §: the expense is the charged amount for the work
  including VAT.
- SKV-ROT: labour, material and other costs must be kept apart, "eftersom rotavdraget
  endast gäller för arbetskostnaden".
- BF-ROT: if the business does not charge for travel to and from the customer, Skatteverket
  considers it part of the labour cost "och kan då betala ut ett lägre belopp än du ansökt
  om".
- Class: HARD. Time entries of kind labour become `is_labour` rows; material entries,
  machine hire and travel/kilometre entries never do. A travel entry is its own row kind.
  ADVICE: a ROT work order with labour but no travel row shows BF-ROT's warning text in
  NONOX's own words.

**AO-R14 Hours must be documented, also at a fixed price.** The claim to Skatteverket must
contain what the work was and "antalet arbetade timmar".
- HFL 9 § p. 3–4 (Lag 2020:1069): charged amounts for household work, material and other
  separately, paid amount and day, "vad hushållsarbetet avsett och antalet arbetade timmar".
  HFL 9 § p. 4 has an exception: the hours are not required for the RUT services in IL 67
  kap. 13 § p. 12–13. ROT is unaffected. NONOX requires the hours for every ROT and RUT
  work order (the stricter rule) until the exception is mapped to RUT kinds of work.
- SKV-ROT: "Du måste dokumentera hur många timmar du har arbetat och vilken typ av arbete du
  har gjort." Applies also at a fixed price.
- Class: HARD. A ROT/RUT work order cannot produce an invoice basis without recorded hours
  for the labour it invoices, fixed price included. The invoice basis carries the hours and
  the kind of work so the user can fill in the claim. Today NONOX keeps neither on the
  invoice (gap).
- Test hints: fixed-price ROT order with 0 h recorded → refused with a Swedish message; the
  hours on the invoice basis equal the sum of non-cancelled labour entries invoiced.

**AO-R15 What the ROT invoice shows.** Skatteverket says the invoice should show the total and
the deduction (computed including VAT), the buyer's personnummer, the property designation or,
for a bostadsrätt, the association's organisation number and the apartment number ("fyra
siffror"), the kind of work, and that the business holds F-skatt.
- SKV-ROT (quoted sentences, 2026-11-08). HFL 9 a § requires the property or apartment
  designation and the association's organisation number in the **claim**.
- Class: ADVICE for the invoice content, since SKV-ROT is guidance ("bör" on the RUT page;
  the ROT page lists items without a statute). HARD already built: property designation for
  ROT (`IssueInvoice::ensureTaxReduction`).
- Gaps found in today's invoice: no personnummer printed, no separate apartment number field,
  no F-skatt statement checked. `TODO(legal-verify)` (L6): must the buyer's personnummer be
  on the invoice, or is it enough in the claim (data minimisation)?

**AO-R16 A conto, advance and part payment under ROT.** "Dra av rotavdraget på varje enskild
faktura om din kund vill betala i förskott, med a conto eller delbetala." If payment is made
before a year-end, the work must be performed by 31 January the year after the payment.
- SKV-ROT. HFL 8 §: the claim may be made "efter det att hushållsarbetet har utförts och
  betalats", and must reach Skatteverket by 31 January the year after payment.
- BF-ROT: the customer should pay only a part of the invoiced labour at each payment.
- Class: HARD: every a conto invoice of a ROT work order carries its own deduction on its own
  labour (the existing per-invoice model). ADVICE: a warning when an a conto invoice is paid
  in one year and the work order is not done by 31 January next year.
  `TODO(legal-verify)` (L7): the year-end rule's exact source in Skatteverket's rättsliga
  vägledning.

**AO-R17 ÄTA under ROT.** No source read treats ÄTA specially. The general rules apply: the
ÄTA's labour qualifies only if it is itself ROT/RUT work on the same home and buyer (IL 67
kap. 13–13 c §§); work paid by insurance does not (13 c § p. 2); the buyer's yearly ceiling
counts all invoices.
- Class: ADVICE. An ÄTA inherits the work order's tax reduction type and property by default,
  can opt out ("ingen ROT på detta ÄTA", e.g. an insurance part), and cannot switch to
  another property. The ceiling check stays in `TaxReduction::allowed` at invoicing.
- `TODO(legal-verify)` (L8): may one invoice mix ROT rows for the main work and non-ROT rows
  for an ÄTA (insurance-paid)? NONOX today marks labour per row, so it is technically possible.

**AO-R18 Electronic payment.** ROT and RUT require the buyer's payment to be electronic,
through a payment service provider with sender, recipient, amount and time.
- IL 67 kap. 15 a §. SKV-ROT: cash, cheque and gift cards are not accepted.
- Class: ADVICE. NONOX's payment registration does not record the payment method. A work
  order invoice with ROT warns when a payment is registered as cash (if a method field is
  later added). Out of ADR-074's build; noted as an existing gap.

**AO-R19 Who may be the buyer; related persons.** The person doing the work may not be the
buyer or a person close to the buyer (15 § andra stycket); the business must hold F-skatt when
the agreement is made or payment is made (16 § p. 1). SKV-RR: "Rotavdrag gäller inte när du
utför arbeten åt familj eller släkt."
- Class: OUT (NONOX cannot know family relations); the work order's ROT checklist lists it.

## C. VAT for construction services

**AO-R20 Reverse charge between construction businesses.** For the services listed (ground
work, building and construction work, building installations, finishing, hire of
construction machines with operator, building cleaning, and staffing for these) the buyer
pays the VAT if the buyer is a taxable person that provides such services "inte bara
tillfälligt", or resells them to such a person.
- ML 16 kap. 13 §. SKV-OB: a construction business selling to others, "till exempel till
  privatpersoner, ska ta ut moms av köparen"; "Om det i en byggtjänst ingår material så räknas
  allt som en byggtjänst"; if unsure, the seller should "fråga köparen om denna säljer
  byggtjänster".
- Class: HARD as already built: the customer's `reverse_charge_construction` flag sets the
  invoice. ADR-074: a work order and every ÄTA on it inherit the customer's flag; an ÄTA
  never has its own VAT treatment, since it is part of the same service to the same buyer.
- HARD: ROT/RUT and reverse charge are mutually exclusive on a work order (ROT buyers are
  natural persons; reverse charge buyers are construction businesses).
- ADVICE: the material on a reverse-charge work order is invoiced without VAT with the rest
  (SKV-OB: material in a construction service is part of it).
- `TODO(legal-verify)` (L9): an invoice that mixes reverse-charge construction work with
  services that are not construction services (e.g. pure goods sale, design work). Today a
  reverse-charge invoice books every row on 3231.
- Test hints (fixture F4, F5).

**AO-R21 Invoice text under reverse charge.** The invoice must carry the buyer's VAT number and
the words "omvänd betalningsskyldighet".
- ML 17 kap. 24 § p. 4 and p. 14. SKV-OB.
- Class: HARD, already built (PDF prints both VAT numbers and the text).

**AO-R22 Invoice deadline for construction services.** An invoice for construction services,
or goods delivered with them, must be issued no later than the end of the second calendar
month after the month the services were performed.
- ML 17 kap. 16 §: "senast vid utgången av den andra kalendermånaden efter den månad då
  tjänsterna tillhandahållits".
- ML 7 kap. 8 §: the taxable event for construction services is when the invoice is issued;
  without an invoice in that time it follows 4 or 7 §.
- ML 17 kap. 11 § p. 3: an invoice must be issued also for construction services to a
  private person.
- Class: ADVICE. The work order list flags uninvoiced time and material older than the
  deadline month, per month of work ("Ofakturerat arbete från mars måste faktureras senast
  31 maj"). NONOX does not know which services are construction services; the flag applies to
  work orders the user marks as construction (`is_construction`, default on in the module).
- Test hint (fixture F6).

**AO-R23 VAT period for construction services and a conto.** Output VAT on construction
services is reported for the period in which the invoice was issued; a prepayment or a conto
received without an invoice is reported for the period it was received; at the latest for
the period covering the second calendar month after the month of performance. Does not apply
when VAT is reported under the cash method (7 kap. 16 §).
- ML 7 kap. 24 §; ML 7 kap. 14 § p. 2. Buyer side (who is liable): ML 16 kap. 13 §; the buyer's deduction period is `TODO(legal-verify)` (an earlier draft cited "13 kap. 37 §", which does not exist: chapter 13 ends at 36 §, corrected 2026-11-08 after an independent quote check).
- ML 17 kap. 14 §: an invoice must be issued for a prepayment or a conto payment.
- Class: HARD for NONOX's flow: an a conto amount is always invoiced (an a conto invoice),
  never only registered as a payment. Under fakturametoden this already reports VAT on the
  invoice. `TODO(legal-verify)` (L10): kontantmetoden companies and construction a conto
  payments (7 kap. 16 § vs 24 §).

**AO-R24 Content of an a conto invoice.** The date of the prepayment or a conto payment, when
it differs from the invoice date, belongs on the invoice.
- ML 17 kap. 24 § p. 8.
- Class: HARD where NONOX knows it (the invoice already has a delivery date field; an a conto
  invoice leaves it empty or sets the payment date).

## D. What a written ÄTA approval should contain

No statute prescribes the content of an ÄTA approval between a business and a consumer
(AO-R3). The list below is assembled from the sources and NONOX's evidence needs; each line
names why.

| Field | Why |
|---|---|
| ÄTA number, unique per work order, and the work order's number and customer | identification; BFL 5 kap. 7 § (a voucher must be traceable to the documents behind it) |
| Description of the change or additional work (scope) | HF17-guide G: "omfattning"; KtjL 8 § |
| Basis: change ordered by customer / tilläggsarbete (8 §) / customer circumstances (38 § p. 2) | KtjL 38 § |
| Price form: fixed price, or löpande räkning at the hourly rates and material mark-up stated, or approximate price with its upper limit | HF17-guide G and D; KtjL 36 § |
| Amounts: labour, material, other (travel, machines), VAT, total; for ROT also the expected deduction and what the customer pays | IL 67 kap. 18 §; SKV-ROT; the customer must see the effect of ROT |
| Effect on time (new completion date or "ingen påverkan") | HF17-guide G: "tid" |
| ROT/RUT applies yes/no, property designation | AO-R17 |
| VAT treatment (reverse charge text when it applies) | AO-R20 |
| Payment: with the next invoice, or in the payment plan | AO-R9 |
| The document's SHA-256, date sent, expiry | ADR-046 evidence |
| Approval: who (name, and for a business their title), how (drawn, typed, oral recorded by user), when, IP and user agent for link signing | ADR-046 evidence |
| For "utfört utan kundens besked": the reason under KtjL 8 §, the contact attempts and the justification | AO-R4 |

**AO-R25 The approved ÄTA is kept as räkenskapsinformation.** Agreements and documents of
particular importance for the company's financial position are räkenskapsinformation, kept
for 7 years (BFL 7 kap.). An approved ÄTA is the basis of an invoice row.
- BFL 1 kap. 2 § p. 9 b: "avtal och andra handlingar av särskild betydelse för att belysa
  verksamhetens ekonomiska förhållanden"; 5 kap. 7 §.
- Class: HARD. An approved or declined ÄTA, its PDF and its evidence are immutable (trigger),
  are not deleted while the retention period runs, and go into the archive package (§10 of
  AGENTS.md). `TODO(legal-verify)` (L11): whether ÄTA documents count under p. 9 b or only as
  supporting documents under 5 kap. 7 §; the retention outcome is the same.

## E. Personalliggare in construction

**AO-R26 Who must keep it.** Whoever carries on construction business (byggverksamhet) on a
construction site where the client (byggherre) has provided equipment must keep an
electronic staff ledger documenting identification of the business and, continuously, the
persons working. People who only load or unload briefly are not recorded.
- SFL 39 kap. 11 a §; definitions in 39 kap. 2 § ("byggverksamhet", "byggarbetsplats").

**AO-R27 The client's duty and its threshold.** The byggherre must provide the equipment, but
not until the total cost of construction on the site can be assumed to exceed four
prisbasbelopp, and never when the byggherre is a natural person who does not order the work
in business. The byggherre must register the site with Skatteverket before work starts
(byggarbetsplatsanmälan, 7 kap. 2 a §). The duties can be moved to a contractor by written
agreement (39 kap. 11 c §).
- SFL 39 kap. 11 b §, 11 c §, 7 kap. 2 a §.
- Prisbasbelopp 2026: 59 200 kr (PBB), so four prisbasbelopp = 236 800 kr. 2027: 59 600 kr
  according to SCB and press reports (not read in SFS) `TODO(legal-verify)`.
- Class: ADVICE. A work order whose customer is a business or association and whose expected
  value passes four prisbasbelopp shows "Kan kräva personalliggare på byggarbetsplatsen";
  a work order for a private person (not in business) shows nothing about the client's duty.

**AO-R28 Availability.** The byggherre keeps the ledger available to Skatteverket on the site;
each business keeps its own ledger available to Skatteverket and the byggherre.
- SFL 39 kap. 12 § andra stycket.

**AO-R29 What NONOX has.** NONOX's staff ledger does not support construction
(`BusinessType` has no construction case; ADR-022: "Construction (ID06,
byggarbetsplatsanmälan) is v2"). Skatteverket publishes an XML schema for the construction
ledger (ADR-022 notes v1.1).
- Class: OUT for ADR-074. The work order may link to a staff ledger location when
  construction support exists. The staff ledger's attendance is never billable time and is
  never turned into billable time automatically (it records presence, not work on an order).

## F. Year-end: ongoing work (pågående arbeten)

**AO-R30 Löpande räkning (K2).** A job is on löpande räkning only if the income rests
"uteslutande eller så gott som uteslutande" on an agreed fee per time unit, the actual time,
and actual expenses. Revenue is recognised as work is performed and material delivered or
used, valued at the agreed price; unbilled performed work is revenue at the balance sheet
date (asset: "Upparbetad men ej fakturerad intäkt"); amounts billed before work is performed
are a liability ("Förskott från kunder").
- K2 6.10, 6.13, 6.14 with commentary: "utförda arbeten som inte är fakturerade på
  balansdagen ska redovisas som intäkt".
- K2 6.10 commentary: pay per painted square metre is **not** löpande räkning.
- K3 23.11, 23.17: same principle ("faktureringstidpunkten inte styr tidpunkten för
  intäktsredovisning").
- Class: ADVICE. NONOX computes, per work order on löpande räkning, recorded-not-invoiced
  time and material at agreed prices on the balance sheet date, and invoiced amounts ahead
  of performed work, and proposes an adjustment voucher. The user decides; it is never
  booked automatically. Accounts 1620 and 2420 come from the INK2 mapping and are
  `TODO(legal-verify)` against BAS (L12).

**AO-R31 Fixed price (K2): one method for all, main rule or alternative rule.** A fixed-price
job is every job that is not commission-based, löpande räkning, or an indefinite number of
activities over a period (K2 6.12). A company uses either the main rule (percentage of
completion when income, degree of completion and costs can be estimated reliably; 6.16–6.17)
or the alternative rule (revenue when the work is essentially complete; 6.22), the same for
all fixed-price jobs (6.15). Under the alternative rule, costs that could be capitalised as
own-manufactured stock are a positive adjustment item while the job is open (6.23; for
entreprenad, 97 % per IL 17 kap. 27 §, per the commentary). A probable loss is expensed at once
(6.19). Switching from the main to the alternative rule needs special reasons (6.15).
- K2 6.12, 6.15–6.24; ÅRL 4 kap. 10 § as quoted in K2.
- Class: ADVICE. NONOX stores the company's choice (`fixed_price_method`: MAIN, ALTERNATIVE,
  NOT_SET) and per open fixed-price work order shows: contract sum including approved ÄTA,
  costs recorded, invoiced, and (main rule) a degree of completion from costs. It proposes,
  never books. `TODO(legal-verify)` (L13).

**AO-R32 ÄTA and the classification of the job.** K2: tilläggsbeställningar in a fixed-price job
that could be löpande räkning on their own are "i normalfallet" counted into the fixed-price
job (6.12 commentary, 6.8 tredje stycket). A supplement is the same kind of job if the
contracts together are the same kind; if it is so different that the original would have been
another kind, it is two jobs (6.8 commentary, with the electrician example). K3 23.16: an
added job is separate if it differs considerably in design, technology or function, or its
price was agreed without regard to the original price.
- Class: ADVICE for the year-end proposal: an ÄTA on a fixed-price work order belongs to that
  work order's year-end figures by default, also when the ÄTA itself is priced per hour. The
  user can split it off as its own work order with its own price form.
- Test hint (fixture F7b).

**AO-R33 K3 färdigställandemetoden for legal persons.** A legal person in an industry covered
by IL 17 kap. 23 § may recognise income from jobs under IL 17 kap. 27 § when the work is
essentially complete, if it treats all such jobs the same way.
- K3 23.31 (BFNAR 2012:5).
- Class: ADVICE, as AO-R31. K3 companies get the same figures; the method choice adds
  `COMPLETION_METHOD_K3`.

**AO-R34 K1 (enskild firma).** Not read. `TODO(legal-verify)` (L13).

## G. Invoicing on account (a conto)

**AO-R35 An a conto invoice is an invoice.** See AO-R23, AO-R24 (VAT) and AO-R16 (ROT).
Accounting: amounts billed ahead of performed work are "Förskott från kunder" under K2 6.14
(löpande räkning) and part of "Fakturerad men ej upparbetad intäkt" under 6.21 (fixed price,
main rule).
- Class: HARD for the flow (an a conto plan produces ordinary draft invoices through the
  existing invoicing core; issued invoices keep their immutability). ADVICE for the year-end
  split (AO-R30, AO-R31). NONOX does not change how an issued invoice is booked; the year-end
  adjustment is a separate proposal.

**AO-R36 The final invoice deducts what was invoiced on account.** No statute read on the
form. Practice: the final invoice shows the total work and subtracts the a conto invoices by
number. In NONOX each a conto invoice already carried its own VAT and ROT; the final invoice
therefore invoices only the remainder as rows of its own (no negative a conto row that would
reverse VAT or ROT). `TODO(legal-verify)` (L14).

## H. Standard contracts (only what is public)

**AO-R37 Which contract, and the copyright limit.** AB 04 and ABT 06 are the general
conditions for construction contracts between businesses (execution contract and design-and-
build contract); ABS 18 is for consumer contracts on new build or extension of small houses;
Hantverkarformuläret 17 is for consumer repair and renovation, published by Konsumentverket,
Villaägarnas Riksförbund and Byggföretagen (search result, not verified at the source). They
all contain sections on changes and additional work. NONOX neither reproduces nor paraphrases
their clauses, and never claims a document "är enligt AB 04". A work order can name the
standard contract that applies (free choice from a list plus "annat"), and the ÄTA document
prints that name only.
- Sources: HF17-guide (public); BF-ROT. Clause content: not read (copyright, sold).
- Class: ADVICE. `TODO(legal-verify)` (L15): whether naming the contracts in a pick list is
  fine (trademark/copyright) and whether NONOX's ÄTA PDF may be used as the "särskilda
  formulär" HF17-guide mentions.

## I. GDPR touch points (for ADR-074 §GDPR)

**AO-R38** The ROT buyer's personnummer is already stored encrypted and hidden
(`customers.personal_number`, `encrypted` cast, `$hidden`). A work order does not copy it.
Field users (role with only `work-orders:report`) never see it.

**AO-R39** New personal data in a work order: the site address of a private person, access
notes (door codes, key handover), photos taken on site (may show a home's interior or
people), and time per worker. Access notes are the most sensitive operationally and are
removed when the order is closed. Photos are räkenskapsinformation only when attached to an
invoice basis or an ÄTA. `TODO(legal-verify)` (L16): legal basis per category and retention.

---

## Synthetic fixtures

All companies, persons, numbers and properties are invented. Organisation and personal
numbers are generated in tests with a valid Luhn digit; none appear here.

**F1 ROT ÄTA, consumer, fixed main price.** Exempelbygg AB (fakturametoden, VAT 25 %).
Customer: private person, ROT, property "Exempelstad Ekbacken 3:14". Main work order:
bathroom, fixed price 80 000 kr incl. VAT, of which labour 50 000 kr incl. VAT, invoiced
earlier in 2026, paid 2026. ÄTA 1 (customer asks for a new floor drain): 4 h × 600 kr = 2 400
kr labour ex VAT (3 000 incl.), material 1 200 kr ex VAT (1 500 incl.), approved by link
signature before work.
- Expected: main invoice ROT 15 000 kr. ÄTA invoice: labour row `is_labour`, 3 000 incl. VAT,
  ROT 900 kr (30 %, rounded down to whole kronor), material row not labour. Customer pays
  3 000 − 900 + 1 500 = 3 600 kr. Room left after both: 50 000 − 15 900 = 34 100 kr.
  Invoice basis states 4 hours and "byte av golvbrunn".

**F2 ÄTA without the customer's answer.** Same customer is unreachable; leaking pipe found
behind a wall; work done to avoid water damage, 1,5 h.
- Expected: ÄTA status "Utfört utan kundens besked", reason "akut, allvarlig skada (8 §
  tredje stycket)", two contact attempts logged (phone 09:12, e-mail 09:20), justification
  text required; the customer gets the ÄTA PDF by e-mail; it can still be invoiced.
  Without a logged attempt: refused.

**F3 Approximate price 15 %.** Consumer work order on löpande räkning with "ungefärligt pris
40 000 kr inkl. moms". Recorded and invoiced main work reaches 46 500 kr; an approved ÄTA
adds 5 000 kr.
- Expected: warning at > 46 000 kr main work (115 %), ÄTA shown separately and not counted;
  no block.

**F4 Reverse charge ÄTA, subcontractor.** Underentreprenören Exempel AB invoices a main
contractor flagged `reverse_charge_construction`. Work order on löpande räkning: 20 h ×
550 kr; ÄTA 2: extra wall, fixed 8 000 kr ex VAT including material.
- Expected: one invoice, no VAT on any row, text "Omvänd betalningsskyldighet", both VAT
  numbers; ÄTA row books like the rest (3231 today); material included without VAT.
  An attempt to set ROT on this work order: refused.

**F5 Housing association customer.** A bostadsrättsförening (organisation number, not a
construction business) orders stairwell repair, ÄTA for extra lighting.
- Expected: VAT 25 % charged; no ROT offered (buyer not a natural person); KtjL warnings off.

**F6 Construction invoice deadline.** Time recorded 3–28 March 2026 on a construction work
order, not invoiced.
- Expected: from 1 May 2026 the list flags "måste faktureras senast 2026-05-31".

**F7a Year-end, löpande räkning.** At 2026-12-31 a work order has 12 h × 650 kr = 7 800 kr
and material at agreed price 2 000 kr recorded but not invoiced; an a conto invoice of
20 000 kr ex VAT issued in December covers work planned for January.
- Expected proposal: debit "Upparbetad men ej fakturerad intäkt" 9 800, credit revenue
  9 800; and move 20 000 from revenue to "Förskott från kunder"; both reversed on
  2027-01-01. Nothing booked until a user posts it.

**F7b Year-end, fixed price with hourly ÄTA.** Fixed price 300 000 kr ex VAT, ÄTA on
löpande räkning 12 000 kr performed, costs recorded 180 000 kr of estimated 240 000 kr total
(ÄTA costs included), invoiced 200 000 kr. Company uses the main rule.
- Expected: ÄTA counted into the fixed-price job (AO-R32); degree of completion 75 %; income
  to date 0,75 × 312 000 = 234 000; invoiced 200 000 → proposal "Upparbetad men ej fakturerad
  intäkt" 34 000. Under the alternative rule: no revenue; adjustment item for capitalisable
  costs instead.

**F8 Personalliggare hint.** Customer: a business; expected value 300 000 kr in 2026 →
"Kan kräva personalliggare" (> 236 800 kr). Same value for a private person → no hint.

**F9 ROT a conto across a year-end.** A conto invoice with ROT paid 2026-12-20; the work
order is not done on 2027-01-31.
- Expected: warning on 2027-02-01 on the work order and the invoice.

**F10 ROT on a fixed price with no hours.** Fixed-price ROT work order, no time entries →
"Skapa fakturaunderlag" refused: "Registrera arbetade timmar. Skatteverket kräver antalet
timmar även vid fast pris."

## L-questions (for the lawyer or accountant)

| # | Question | Rules | Default until answered |
|---|---|---|---|
| L1 | Can NONOX treat a customer without organisation/VAT number as a consumer for KtjL warnings? | AO-R1 | yes, warnings only |
| L2 | Default ÄTA terms text for consumers: what may NONOX ship that does not fall foul of KtjL 3 §? | AO-R2 | none shipped; user writes own terms |
| L3 | Any guidance in Allmänna reklamationsnämnden practice on "obetydligt", "lågt", "betydligt högre" (KtjL 6, 8 §§)? | AO-R4, AO-R7 | no number suggested |
| L4 | Is HF17's 25 % for ground/demolition an agreed "annan prisgräns" valid against KtjL 3 §? | AO-R6 | 15 % unless user enters a limit |
| L5 | Is an ÄTA agreed in the consumer's home an "avtal utanför affärslokaler" (DAL) with information duty and 14-day withdrawal, or an amendment of the original contract? Does 2 kap. 11 § p. 13/14 cover typical urgent ÄTA? | AO-R10 | PDF copy always sent; no withdrawal text |
| L6 | Must the ROT invoice show the buyer's personnummer (SKV-ROT says so) or is the claim enough? | AO-R15 | not printed (as today) |
| L7 | Exact source for "work performed by 31 January after a year-end payment" for ROT a conto | AO-R16 | warning only |
| L8 | May one invoice mix ROT labour and non-ROT labour (insurance-paid ÄTA)? | AO-R17 | allowed per row, warning shown |
| L9 | Mixed reverse-charge and non-construction rows on one invoice | AO-R20 | not allowed: separate invoices |
| L10 | Construction a conto under kontantmetoden (ML 7 kap. 16 § vs 24 §) | AO-R23 | a conto plan unavailable under kontantmetoden (ROT already requires fakturametoden) |
| L11 | Approved ÄTA: räkenskapsinformation under BFL 1:2 p. 9 b or a supporting document? | AO-R25 | kept 7 years either way |
| L12 | BAS 2026 accounts for upparbetad ej fakturerad intäkt, förskott från kunder, fakturerad ej upparbetad intäkt, pågående arbeten, and the change account | AO-R30, AO-R31 | 1620, 2420, 2450, 1470 (present in BAS 2026 per the quote check; texts and the change account unverified) |
| L13 | K2/K3/K1: which of the year-end proposals are right per framework; K1 rules | AO-R31, AO-R33, AO-R34 | proposals for K2 and K3 only; K1 shows figures without a proposal |
| L14 | Form of the final invoice after a conto invoices with VAT and ROT | AO-R36 | remainder rows only |
| L15 | Naming AB 04/ABT 06/ABS 18/HF17 in a pick list; using NONOX's ÄTA PDF in their place | AO-R37 | names only, no clause text |
| L16 | Legal basis and retention for site address, access notes, photos and per-worker time on work orders | AO-R39 | access notes deleted at close; photos kept with the order |
