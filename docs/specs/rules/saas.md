# Rule sheet: ADR-080 NONOX SaaS and Stripe (återkommande intäkter)

Researcher pass, 2026-11-09. Input for the builder of ADR-080. Not legal advice: every rule
marked `TODO(legal-verify)` is a working understanding, not a hard rule, until a human lawyer
or accountant has answered the L-questions at the end.

Rules are numbered **SA-R1 …**. Each cites the source it rests on. A rule without a law,
BFN or Skatteverket source is a **NONOX choice** and says so.

**Clean room (AGENTS.md §1.1).** A competitor launched a similar feature shortly before this
pass. Its product, screens, help texts and marketing were **not** opened, searched for or read.
The metric definitions below are written from general industry usage and from first
principles, and every choice is argued here. Stripe's own analytics product (its dashboard
metrics pages) was also left unread on purpose; only Stripe's **API reference and developer
guides** were read, because they describe the data source, not a competing UI.

## Sources actually read

| Id | Source | Version read | How |
|---|---|---|---|
| ML | Mervärdesskattelag (2023:200) — https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/mervardesskattelag-2023200_sfs-2023-200/ | "Ändrad: t.o.m. SFS 2026:1025" | Full text from `https://data.riksdagen.se/dokument/sfs-2023-200.text`, read locally: 6 kap. 33–35, 56–59, 62–64 §§; 7 kap. 4, 6, 7 §§; 10 kap. 33 §; 16 kap. 5, 9 §§; 17 kap. 10, 11, 14, 24 §§ |
| BFL | Bokföringslag (1999:1078) — `https://data.riksdagen.se/dokument/sfs-1999-1078.text` | "Ändrad: t.o.m. SFS 2024:342" | 1 kap. 2 § p. 8; 5 kap. 1, 2, 6–9 §§; 7 kap. 1, 2, 3 a §§ |
| ÅRL | Årsredovisningslag (1995:1554) — `https://data.riksdagen.se/dokument/sfs-1995-1554.text` | "Ändrad: t.o.m. SFS 2026:780" | 2 kap. 4 § första stycket 4 (periodiseringsprincipen); 5 kap. 8 § |
| K2 | BFN, *Vägledning Årsredovisning i mindre företag (K2), BFNAR 2016:10* — https://www.bfn.se/wp-content/uploads/vl16-10-k2ar-kons2025.pdf | "Uppdaterad 2025-06-16" (incl. BFNAR 2025:2) | PDF converted to text: punkterna 2.4, 2.4A, 2.4B with kommentar; 6.6, 6.8, 6.11, 6.26; exempel 6 k (gymkort) |
| K3 | BFN, *Vägledning Årsredovisning och koncernredovisning (K3), BFNAR 2012:1* — https://www.bfn.se/wp-content/uploads/vl12-1-k3-kons20251215.pdf | "Uppdaterad 2025-12-15" (incl. BFNAR 2025:3) | PDF to text: punkterna 23.7, 23.9–23.13, 23.26 |
| BFN-BOK | BFN, *Vägledning Bokföring, BFNAR 2013:2* — https://www.bfn.se/wp-content/uploads/vl13-2-bokforing.pdf | "Uppdaterad 2024-09-16" | PDF to text: kapitel 5 (verifikationer), exempel 2.3 "Bokföring i sammandrag i en nätbutik" |
| BAS | BAS-kontogruppen, *Kontoplan BAS 2026* — https://www.bas.se/wp-content/uploads/2026/04/BAS_kontoplan_2026_v2.pdf | "v 1.1" (file name v2) | PDF to text: 1510, 1686, 1930, 2611, 2614, 2645, 2650, 2670, 2970–2979, 2990, 3001, 3305, 3308, 3740, 3960, 4531, 4535, 6570, 7960 |
| SKV-TV | Skatteverket, *Tröskelvärde vid försäljning till icke beskattningsbara personer i andra EU-länder* — https://www.skatteverket.se/skatter/mervardesskattmoms/troskelvarden.4.18e1b10334ebe8bc80005545.html | read 2026-11-09 | WebFetch (page shows no date) |
| SKV-EU | Skatteverket, *Sälja tjänster till andra EU-länder* — https://www.skatteverket.se/4.76a43be412206334b89800010696.html | read 2026-11-09 | WebFetch (page shows no date) |
| ST-WH | Stripe, *Receive Stripe events in your webhook endpoint* — https://docs.stripe.com/webhooks | read 2026-11-09 | WebFetch |
| ST-KEY | Stripe, *API keys* — https://docs.stripe.com/keys | read 2026-11-09 | WebFetch |
| ST-RAK | Stripe, *Restricted API keys* — https://docs.stripe.com/keys/restricted-api-keys | read 2026-11-09 | WebFetch |
| ST-APP | Stripe, *Restricted API key authentication* (Stripe Apps) — https://docs.stripe.com/stripe-apps/api-authentication/rak | read 2026-11-09 | WebFetch |
| ST-OAUTH | Stripe, *Connect OAuth reference* — https://docs.stripe.com/connect/oauth-reference | read 2026-11-09 | WebFetch |
| ST-OAUTH2 | Stripe, *OAuth changes for platform-controlled Standard accounts* — https://docs.stripe.com/connect/oauth-changes-for-standard-platforms | read 2026-11-09 | WebFetch |
| ST-RL | Stripe, *Rate limits* — https://docs.stripe.com/rate-limits | read 2026-11-09 | WebFetch |
| ST-PAG | Stripe, *Pagination* — https://docs.stripe.com/api/pagination | read 2026-11-09 | WebFetch |
| ST-BT | Stripe, *The Balance Transaction object* — https://docs.stripe.com/api/balance_transactions/object | read 2026-11-09 | WebFetch |
| ST-PO | Stripe, *Payout reconciliation* — https://docs.stripe.com/payouts/reconciliation | read 2026-11-09 | WebFetch |
| ST-SUB | Stripe, *The Subscription object* — https://docs.stripe.com/api/subscriptions/object | read 2026-11-09 | WebFetch |
| ST-INV | Stripe, *The Invoice object* — https://docs.stripe.com/api/invoices/object | read 2026-11-09 | WebFetch |
| ST-CN | Stripe, *The Credit Note object* — https://docs.stripe.com/api/credit_notes/object | read 2026-11-09 | WebFetch |
| ST-DSP | Stripe, *The Dispute object* — https://docs.stripe.com/api/disputes/object | read 2026-11-09 | WebFetch |
| ST-TAX | Stripe Support, *Global Taxation of Stripe fees* — https://support.stripe.com/questions/global-taxation-of-stripe-fees | read 2026-11-09 | WebFetch |

**Method note.** The law texts and the BFN guidance were read in full text locally; the
quotes below are from those texts. Skatteverket's and Stripe's pages were read through a fetch
tool that returns converted text; a human re-reading each SKV and ST quote against the live page
before the builder starts is item **L0**.

**Not read (gaps not filled from memory):**

- Skatteverket's rättslig vägledning on payment services and card acquiring under ML 10 kap.
  33 §. A search only surfaced a third-party mirror of a Skatteverket ställningstagande dated
  2017-10-27 on card payments; it was **not** read on skatteverket.se and is **not** relied on
  (see SA-R31 and L1).
- Skatteverket's page on selling services to customers outside the EU (the fetched page did not
  contain it). SA-R36 rests on ML 6 kap. alone.
- Skatteverket's OSS pages for the union scheme beyond the threshold page. OSS mechanics are
  already covered by ADR-010 (read by that ADR's author on 2026-11-02).
- Stripe's Customers, Charges, Refunds and Payouts object pages individually (their fields are
  described where they appear inside ST-BT, ST-PO, ST-INV, ST-CN and ST-DSP). Stripe's tax
  invoice document for fees was not seen (it sits in a logged-in Dashboard).
- Stripe's price list. The owner's premise that Stripe charges nothing for API access is
  consistent with ST-KEY and ST-RL (no fee is mentioned for keys or API calls), but was not
  checked against a price page.

## What already exists in NONOX (mapped 2026-11-09)

- **Avtalsfakturering**: `App\Models\RecurringInvoice` (`recurring_invoices`): customer,
  `interval_months` ∈ {1, 3, 6, 12}, `invoice_day`, `next_invoice_date`, `end_date`,
  `status` (`AgreementStatus` ACTIVE / PAUSED / ENDED), `net_ore`, `total_ore`; rows
  `RecurringInvoiceRow` with article, quantity, unit price, `discount_bp`, `vat_rate`, account.
  `RunRecurringInvoice` creates invoices with `invoices.recurring_invoice_id`;
  `SaveRecurringInvoice` audits `agreement.created` / `agreement.updated`; status changes audit
  `agreement.status_changed`. **There is no effective-dated history:** a change overwrites the
  row, a pause or end has no "from" date other than `end_date`, and `currency` is not on the
  agreement (it comes from the customer when the invoice is made).
- **CRM avtalsregister** (ADR-046): `App\Modules\Crm\Models\Agreement` with `kind`
  (`SUBSCRIPTION` among others), `start_date`, `end_date`, `notice_months`, `auto_renew`,
  `value_ore` per `value_period` (MONTH / QUARTER / YEAR / TOTAL), `recurring_invoice_id`,
  `terminated_on`. It belongs to a CRM account, which may link to a `customer_id`.
- **Kollektivavtal** (`app/Modules/Agreements`, ADR-033) is unrelated (collective agreements
  for payroll) and is not a data source.
- **Invoices**: `Invoice` (`kind`, `credits_invoice_id`, `credited_ore`, `invoice_currency`,
  `exchange_rate_micros`, `foreign_*_minor`, `foreign_sale`, `reverse_charge`,
  `swedish_vat_under_threshold`, `recurring_invoice_id`). **Invoice rows have no service period**
  (`InvoiceRow` has no from/to date).
- **Foreign sales and OSS**: `ForeignSale` (EU_GOODS, EU_SERVICES, EXPORT_*, EU_CONSUMER_OSS),
  `OssThreshold`, `OssRates::VAT_ACCOUNT = 2619`, `OssReport` (ADR-010, ADR-011).
- **Periodiseringar**: `Accrual` / `AccrualPosting` between a balance account (1790, 2990) and a
  result account, spread over months.
- **Currency**: Riksbanken rates (`RiksbankRates`), 3960/7960 (ADR-025).
- **Bank**: camt.054 incoming payments (ADR-015); open banking feeds, matching and
  bankavstämning (ADR-034).
- **Payment intermediary precedent**: the till integrations (ADR-042) book card settlement on
  1680 with fees on 6570 and note that "BAS has 1580 for card receivables". **BAS 2026 has no
  1580**; card receivables are **1686 Fordringar för kontokort och kuponger** (BAS, read
  2026-11-09). See L6.
- **Connect add-on** (ADR-064 §2–3): provider price plus 10 %, shown as two lines; nothing of the
  billing is built yet.

## A. Metric definitions (industry conventions, NONOX's choices)

**SA-R0. These are conventions, not law.** No Swedish statute, BFN rule or Skatteverket text
defines MRR, ARR, churn, NRR, cohorts, ARPA, LTV or CAC. They are management metrics. They are
never used for the annual report, the VAT return or the income tax return, and the module says
so on every screen (`Nyckeltal för styrning, inte bokföring`). Where conventions differ, NONOX
chooses one, writes the choice on the page next to the figure, and keeps it the same for every
company. *Source: absence of any rule in ML, BFL, ÅRL, K2, K3, BFN-BOK as read; NONOX choice.*

### Units

**SA-R1. Customer = NONOX customer.** The unit counted in "kunder", logo churn, ARPA and cohorts
is a `customers` row (the billing party). Several contracts or Stripe subscriptions of one
customer add up; several Stripe customers linked to one NONOX customer are one customer.
*NONOX choice: it is the party the company invoices and the unit the ledger already has.*

**SA-R2. Revenue line.** A revenue line is one recurring priced item of one contract: a
`RecurringInvoiceRow`, a CRM agreement's value (only when no recurring invoice is linked), or a
Stripe subscription item. *NONOX choice.*

### MRR

**SA-R3. MRR is contracted, recurring, net of VAT, at an instant.** A customer's MRR at a moment
*t* is the sum, over its revenue lines in force at *t*, of the line's recurring amount
**excluding VAT**, after recurring discounts, normalised to one month. Company MRR for a month is
the MRR at the **last day of that month, 23:59:59 Europe/Stockholm**. *NONOX choice: an
end-of-month instant makes movements additive and reproducible; VAT is a liability, not revenue
(ML 8 kap., beskattningsunderlaget excludes the tax).*

**SA-R4. Normalisation.** Monthly amount = amount per billing period ÷ number of months in the
period: monthly ÷ 1, quarterly ÷ 3, half-yearly ÷ 6, annual ÷ 12, any `interval_months` *n* ÷ *n*.
Stripe `recurring.interval` = `month` × `interval_count` *k* → ÷ *k*; `year` × *k* → ÷ 12*k*;
`week` and `day` intervals → amount × 52 ÷ 12 per week, × 365 ÷ 12 per day (stated on the page).
Division in integer öre, half up, **per revenue line**, so a customer's MRR is the sum of
rounded lines and drill-down always adds up. *Source for interval fields: ST-SUB
(`items.data.price.recurring.interval`, `interval_count`). NONOX choice for the arithmetic,
consistent with AGENTS.md §6.3 (Money, HalfUp).*

**SA-R5. Discounts.** A discount that applies to every future period (row `discount_bp`, a
Stripe coupon with `duration=forever`) lowers MRR. A time-limited discount lowers MRR **only
while it applies**; its end shows as **Utökning** (expansion) in the month it ends. A one-off
discount on a single invoice does not change MRR. *NONOX choice: MRR shows what the customer is
committed to pay now; showing list price would overstate revenue.*

**SA-R6. Trials.** A line in a free trial (Stripe status `trialing`, ST-SUB) has MRR 0. Conversion
to paid is **Ny** (new) in the month it converts, not in the month the trial started. A paid
trial counts with its paid amount. *NONOX choice.*

**SA-R7. One-off fees are not MRR.** Set-up fees, one-off invoice rows, hourly work, and invoices
not tied to a recurring contract are excluded. They appear in **Intäkter i perioden**
(recognised revenue, SA-R20) but never in MRR. *NONOX choice.*

**SA-R8. Usage-based lines.** A metered line (Stripe `usage_type=metered`, ST-SUB) has no fixed
amount. Default: **excluded from MRR**, shown as its own line "Rörlig återkommande intäkt"
(trailing three-month average of invoiced usage). Owner question **O3**. *NONOX choice.*

**SA-R9. Pause.** A paused contract (NONOX `PAUSED`; Stripe `status=paused`, ST-SUB) has MRR 0.
The month it pauses shows **Paus**, the month it resumes **Återupptagen**. A pause is not logo
churn. A pause longer than 12 months is re-classified as churn in the 13th month (shown as such,
never rewriting earlier months). Stripe's `pause_collection` (collection paused, status
unchanged) does **not** change MRR. *Sources: ST-SUB on the difference between `paused` and
`pause_collection`; the 12-month cut-off is a NONOX choice, owner question O4.*

**SA-R10. Unpaid and past due.** A contract stays in MRR while invoices are overdue
(`past_due`), until the contract ends or the subscription becomes `canceled` or `unpaid`
(ST-SUB). A customer loss written off (`written_off_ore`, #57) does not by itself end a
contract. *NONOX choice: MRR measures the contract; credit loss is a bookkeeping event.*

**SA-R11. Credit notes.** A credit invoice changes MRR **only if the contract changes**. A credit
of a past invoice (goodwill, error, dispute) leaves MRR unchanged and reduces recognised revenue
(SA-R20) in the period of the credit. A credit that comes with a lowered price or an ended
contract is a contract change and moves MRR from the change's effective date. When the source
cannot tell (an imported Stripe credit note), the credit is shown under **Att granska** and does
not move MRR until a person classifies it. *Sources: Stripe's credit note `reason` values
`duplicate`, `fraudulent`, `order_change`, `product_unsatisfactory` (ST-CN) carry no
"contract changed" meaning; NONOX choice.*

**SA-R12. Foreign currency.** A line is kept in its own currency (minor units). Company MRR is
in the base currency (SEK) at **Riksbanken's mid rate for the last banking day of the month**
(the existing `RiksbankRates`). The month's bridge shows **Valutaeffekt** separately: last
month's lines revalued at this month's rate minus last month's MRR. No other movement contains a
currency effect. *Source for the rate service: ADR-025. NONOX choice for the month-end rate and
the separate line.*

**SA-R13. ARR = MRR × 12.** ARR is the run rate of the month's MRR, never the sum of annual
invoices and never a forecast. *NONOX choice; the label says "årstakt".*

### Movements, churn, reactivation

**SA-R14. Movement categories, per customer per month.** Comparing a customer's MRR at the end
of month *m−1* (*a*) and *m* (*b*), in the line currency first and then in SEK at month *m*'s
rate:

| Category (sv) | Condition |
|---|---|
| **Ny** | *a* = 0, *b* > 0, the customer has never had MRR before |
| **Återaktivering** | *a* = 0, *b* > 0, the customer had MRR in some earlier month and was not paused (SA-R15) |
| **Återupptagen** | *a* = 0, *b* > 0, the customer's contracts were paused (SA-R9) |
| **Utökning** | *a* > 0, *b* > *a* |
| **Minskning** | *a* > 0, 0 < *b* < *a* |
| **Paus** | *a* > 0, *b* = 0, every ending line was paused |
| **Churn** | *a* > 0, *b* = 0, otherwise |
| **Valutaeffekt** | SA-R12 |
| **Rättelse** | SA-R22 |

The bridge always holds: MRR(*m*) = MRR(*m−1*) + Ny + Återaktivering + Återupptagen + Utökning
− Minskning − Paus − Churn ± Valutaeffekt ± Rättelse. A test proves it for every month of every
fixture. *NONOX choice; the identity is what makes every figure explainable.*

**SA-R15. When a customer counts as churned.** Churn is dated to the **first month-end at which
the customer has no MRR**, which is the end of the last period the customer has paid or is
bound to pay for. A cancellation notice (uppsägning) is **not** churn: it is shown as
**Uppsagd MRR** (scheduled churn) with the date it will take effect. NONOX sources: the
agreement's `end_date`, CRM `terminated_on` + notice/end date; Stripe `cancel_at`,
`cancel_at_period_end` + the item's `current_period_end`, `ended_at` (ST-SUB; note Stripe's own
remark that `canceled_at` is the time of the request, not the end of the period). *NONOX choice:
the customer pays to the end of the period, so revenue does not stop at the notice.*

**SA-R16. Reactivation.** A customer with MRR 0 at one or more month-ends who gets MRR again is
**Återaktivering**, never **Ny**, however long the gap; it keeps its original cohort (SA-R18).
*NONOX choice: counting a returning customer as new would overstate acquisition.*

**SA-R17. Churn rates.** For month *m*, with *S* = MRR at end of *m−1* and *C* = customers with
MRR > 0 at end of *m−1*:

- **Kundchurn (logo churn)** = customers with category Churn in *m* ÷ *C*.
- **Brutto intäktschurn (gross revenue churn)** = (Minskning + Churn) ÷ *S*.
- **Netto intäktschurn (net revenue churn)** = (Minskning + Churn − Utökning) ÷ *S*. It can be
  negative, and the page then says "negativ churn".
- Paus, Återaktivering, Återupptagen, Ny, Valutaeffekt and Rättelse are **outside** all three
  rates.
- Quarterly and yearly figures are computed from the period's movements over the opening MRR of
  the period, **not** by adding monthly rates; the page shows the formula.

*NONOX choice.*

**SA-R18. NRR and GRR (12 months).** For month *m*, let *K* = customers with MRR > 0 at end of
*m−12*, and *M₀*(k) their MRR then, *M₁*(k) their MRR at end of *m* (0 if gone), both in SEK at
**month *m*'s rate** (so currency moves do not count as retention):

- **NRR** = Σ *M₁*(k) ÷ Σ *M₀*(k).
- **GRR** = Σ min(*M₁*(k), *M₀*(k)) ÷ Σ *M₀*(k). GRR ≤ 100 % by construction.
- Customers who became customers after *m−12* are outside both.

*NONOX choice; the constant-rate rule is stated on the page.*

**SA-R19. Cohorts.** A customer's cohort is the month of its first MRR > 0 (after a trial, the
conversion month). The cohort table shows, per cohort and month since start, (a) the share of
customers with MRR > 0 and (b) the cohort's MRR as a share of its MRR in month 0, at constant
currency (SA-R18). Reactivated customers stay in their first cohort. *NONOX choice.*

**SA-R19a. ARPA** = MRR ÷ customers with MRR > 0 at the same month-end. Never divided by
contracts or by Stripe customers. *NONOX choice.*

**SA-R19b. LTV** = ARPA × bruttomarginal ÷ monthly gross revenue churn, with the churn the
**trailing 12-month average** (or since the first month if shorter). The gross margin is a
company setting with **no default**; without it the page shows "intäkts-LTV" (margin 100 %) and
says so. With zero churn in the window, LTV is not shown ("kan inte beräknas när ingen intäkt har
förlorats"). *NONOX choice: a guessed margin would make the figure look more exact than it is.*

**SA-R19c. CAC** = sales and marketing cost in the period ÷ customers with category **Ny** in the
period. The cost is the sum of accounts and/or cost centres the company **chooses** (no default;
the BAS groups vary too much between companies). **CAC payback** = CAC ÷ (ARPA of the new
customers × margin). Without a choice, CAC is not shown. *NONOX choice; see owner question O7.*

### Recognised revenue (bridge to the books)

**SA-R20. Intäkter i perioden.** Next to MRR the module shows recognised recurring revenue per
month: each invoice row of a recurring contract spread linearly over its service period
(SA-R24). This is the figure that should match the books after periodisation; the difference
between MRR and recognised revenue (one-off fees, credits, part months, usage) is shown as a
reconciliation, never hidden. *NONOX choice, aligned with K2 6.11 / K3 23.26 (SA-R24).*

### Reproducibility

**SA-R21. Every figure drills down.** Every number on every page opens a list of the customers
and the documents (agreement version, invoice, credit invoice, Stripe subscription/invoice id)
that make it up, and the list adds up to the figure to the öre. *NONOX choice; AGENTS.md
§11.3.*

**SA-R22. Closed months are immutable; corrections are separate.** Each month's snapshot is
closed (frozen) on a set day. A later-known change effective in a closed month — a back-dated
contract change, a cancellation registered late, a Stripe event that arrived late — does **not**
rewrite the closed month. It is recorded as **Rättelse** in the first open month, carrying the
affected month, the customer, the amount and the source; the page can show "som rapporterat"
and "med rättelser" side by side. *NONOX choice, by analogy with BFL 5 kap. 9 § and AGENTS.md
§1.2 (corrections by a new entry, never by editing).*

## B. Accounting and VAT

### Revenue recognition for subscriptions paid in advance

**SA-R23. The accruals principle.** "Intäkter och kostnader som är hänförliga till
räkenskapsåret ska tas med oavsett tidpunkten för betalningen." *ÅRL 2 kap. 4 § första stycket
4.*

**SA-R24. K2: a subscription is recognised linearly.** K2 6.6: "Endast den del av inkomsten som
hör till det räkenskapsår för vilket årsredovisningen upprättas är en intäkt det
räkenskapsåret". K2 6.11: an assignment with an undetermined number of activities over an agreed
period "ska redovisas som intäkt linjärt över den överenskomna tidsperioden", unless one activity
is dominant (over 70 % of the income, kommentar). K2 6.26: income from a "standardiserad tjänst"
is recognised "linjärt över den period då tjänsten utförs"; a fixed "royalty och licens" that is
independent of use "linjärt över avtalets löptid". K2 exempel 6 k (an annual gym card): the
unearned part is "en förutbetald intäkt" and is shown in "Upplupna kostnader och förutbetalda
intäkter". *Source: K2.* Which of 6.11 or 6.26 applies to a given SaaS contract is the company's
judgement; both give linear recognition over the service period, so NONOX proposes linear and
the company confirms. `TODO(legal-verify)`: L5.

**SA-R25. K2 simplification below 7 000 kr, limited by materiality.** K2 2.4: income and
expenses "som var för sig understiger 7 000 kronor" need not be periodised; 2.4A: a received
advance below 7 000 kr may be recognised as income. K2 2.4B: not if the application "medför en
väsentlig påverkan på företagets intäkter, kostnader eller ställning". The kommentar names
**annual subscriptions** as the example: if most contracts below 7 000 kr were signed late in
the year, "Företaget måste då periodisera inkomsterna från abonnemangen". The test is per
invoice or contract ("beloppet på varje enskild faktura, avtal eller liknande handling"), split
invoices are summed, and the choice must be applied consistently between years. *Source: K2.*
**NONOX rule:** the module never decides that the simplification applies. It shows the
unperiodised advance at year end (total and per contract) so the company can make the 2.4B
judgement, and proposes periodisation by default. `TODO(legal-verify)`: L5.

**SA-R26. K3: linear for undetermined activities; separate parts.** K3 23.26: "En intäkt från
ett tjänsteuppdrag med ett obestämt antal aktiviteter under en bestämd tidsperiod ska redovisas
linjärt över denna tidsperiod, om inte någon annan metod bättre återspeglar
färdigställandegraden." K3 23.7: a transaction is split when needed and the parts are separately
identifiable (example in the kommentar: service and maintenance included in a product price is
periodised). K3 has no 7 000-kr rule (the figure appears in K3 only in an unrelated example).
*Source: K3.* A set-up fee bundled with a subscription is a K3 23.7 question for the company;
NONOX does not split automatically. `TODO(legal-verify)`: L5.

**SA-R27. Accounts.** BAS 2026: **2970 Förutbetalda intäkter** (sub-accounts 2971 hyresintäkter,
2972 medlemsavgifter, **2979 Övriga förutbetalda intäkter**); **2990** "Övriga upplupna kostnader
och förutbetalda intäkter". The existing accrual feature uses 2990. **NONOX proposal:** subscription
periodisations default to **2979** (created in the company's chart when first needed, as ADR-016
does for missing accounts), the company may choose 2970 or 2990. *Source: BAS.*

**SA-R28. VAT is not periodised.** Periodisation moves revenue between periods; it does not
move the VAT. Output VAT is due when the supply is made (ML 7 kap. 4 §) or, for an advance
payment, when it is received (ML 7 kap. 7 §); an invoice for an advance must be issued (ML 17
kap. 14 §). The periodisation voucher therefore touches only the revenue account and 2979.
*Source: ML.* Note for continuous services to an EU business: ML 7 kap. 6 § sets the tax point at
31 December each year when the service runs over a year without invoices or payments in the
period; Stripe subscriptions always invoice, so this rarely applies.

### Bookkeeping of Stripe collections and payouts

**SA-R29. A clearing account between sale and bank.** Money collected by Stripe is not in the
company's bank account until a payout. The receivable from Stripe is a short-term receivable.
BAS 2026 has **1686 Fordringar för kontokort och kuponger** under 1680 Andra kortfristiga
fordringar; there is no 1580 in BAS 2026. **NONOX proposal:** a clearing account per Stripe
connection, default **1686** (company may choose another 168x), so that:
- a charge/payment debits 1686 and credits 1510 (fakturametoden: the invoice is already booked)
  or the revenue and VAT accounts (kontantmetoden or a receipt without an invoice);
- each Stripe fee debits the fee account and credits 1686;
- a refund credits 1686 against the customer or a credit invoice;
- a payout debits the bank account (1930) and credits 1686;
- after a payout, 1686 per connection equals Stripe's balance not yet paid out, and the module
  reconciles them. *Source for the account: BAS. Booking pattern: NONOX choice.*
`TODO(legal-verify)`: L6.

**SA-R30. Fees.** BAS 2026 **6570 Bankkostnader** is the default fee account (same as ADR-042's
till fees). Stripe reports fees per balance transaction (`fee`, `fee_details`) and also as their
own transactions of type `stripe_fee`, `stripe_fx_fee`, `tax_fee` (ST-BT). **Rule:** NONOX books
fees from the balance transactions, each fee type mapped to an account and a VAT treatment
(SA-R31), so the sum of `net` of a payout's balance transactions equals the payout (ST-PO). A
payout that does not reconcile to the öre is never booked. *Sources: ST-BT (`net` = `amount` −
`fee`), ST-PO.*

**SA-R31. VAT on Stripe's own fees.** Stripe states that it "supplies payment processing and other
services (such as Billing, Radar, and Tax) from our establishment in Ireland" and "does not charge
VAT on Stripe fees", except for account holders in Ireland, Norway, Switzerland/Liechtenstein and
the UAE; Sweden is not an exception (ST-TAX). Monthly "Tax invoices for Stripe fees" are in the
Dashboard's Documents section, issued by the 10th of the following month (ST-TAX). Swedish law:

- A service to a taxable person established in Sweden is supplied in Sweden (ML 6 kap. 33 §), and
  when the supplier is not established here the buyer is liable for the VAT (ML 16 kap. 9 §):
  purchase account 4535 (box 21), output VAT 2614 (box 30), input VAT 2645 (box 48) — the
  existing supplier-invoice reverse-charge booking (ADR-016).
- But "bank- och finansieringstjänster" are exempt (ML 10 kap. 33 §). Whether Stripe's payment
  processing fee is an exempt payment service and whether Billing, Radar, Tax and FX fees are
  taxable is **not settled by the text read**. If exempt, there is no reverse charge on that
  fee; whether the exempt purchase is reported anywhere in the VAT return is part of L1.

**Rule until L1 is answered:** each fee type carries a VAT treatment the company must choose
(`OMVAND_SKATTSKYLDIGHET` or `UNDANTAGEN`) before the first fee voucher; NONOX sets no silent
default and the page links the question. `TODO(legal-verify)`: L1.

**SA-R32. Disputes and currency conversion.** A dispute withdraws and may reinstate funds via
up to two balance transactions (ST-DSP); each is booked through the clearing account like a
refund (withdrawal) or a payment (reinstatement), and any dispute fee as a fee. When Stripe
converts currency, the balance transaction carries `exchange_rate` (ST-BT); the difference
between the invoice's booked SEK amount and the SEK received goes to 3960/7960 (ADR-025).
*Sources: ST-DSP, ST-BT, ADR-025.*

**SA-R33. Timing.** "Kontanta in- och utbetalningar ska bokföras senast påföljande arbetsdag.
Andra affärshändelser ska bokföras så snart det kan ske." A company with net turnover normally at
most three million kronor may wait until payment (kontantmetoden), with all unpaid receivables
and debts booked at year end. *BFL 5 kap. 2 §.* NONOX creates **draft** vouchers daily (charges,
refunds, fees) and per payout; it never posts them (the person posts, as in ADR-042).

### VAT on the company's own sales collected through Stripe

**SA-R34. Swedish customers.** A service to a taxable person established in Sweden, or to a
consumer when the seller is established in Sweden, is supplied in Sweden (ML 6 kap. 33, 35 §§).
Swedish VAT applies: 2611/2621/2631, boxes 05 and 10–12 (SKV-EU, consumer part; ADR-010). *Sources:
ML, SKV-EU.*

**SA-R35. EU business customers.** A service to a taxable person established in another EU
country is supplied there (ML 6 kap. 33 § read with 34 §): no Swedish VAT; the buyer accounts
for it. SKV-EU: if the buyer "har ett giltigt VAT-nummer i ett annat EU-land och meddelar dig
numret" that is normally enough; the sale is reported in a **periodisk sammanställning** and in
box **39**. BAS account **3308** Försäljning tjänster till annat EU-land. The existing
`ForeignSale::EuServices`. *Sources: ML, SKV-EU, BAS.* Stripe marks such a customer with
`customer_tax_exempt = reverse` and carries `customer_tax_ids` on the invoice (ST-INV); NONOX
reads them but **classifies with its own rules** and flags any disagreement (SA-R38).

**SA-R36. EU consumers.** Electronic services to a non-taxable person are supplied where the
buyer is (ML 6 kap. 56 §; examples in 57 §: "programvara och uppdatering av denna",
"databasåtkomst" and more), **unless** the seller is established only in Sweden and its total
such sales to other EU countries are at most **99 680 kronor** this and last calendar year (ML 6
kap. 62 §), in which case Swedish VAT applies; above the threshold the buyer country's VAT
applies from the sale that crosses it (6 kap. 63 §), and the seller may opt for the buyer's
country anyway (6 kap. 64 §). SKV-TV: "Tröskelvärdet är 10 000 euro (99 680 kronor)" and covers
"telekommunikationstjänster, radio- och tv-sändningar och elektroniska tjänster". Above it, the
company reports through OSS (ML 22 kap.), already built (ADR-010, `OssThreshold`,
`OssReport`). BAS 2026 has **2670 Utgående moms på försäljning inom EU, OSS**; NONOX's OSS code
books on **2619**, which is not in BAS 2026 (see L4). *Sources: ML, SKV-TV, BAS.*
Whether a given SaaS product is an "elektronisk tjänst" is the company's classification; NONOX
asks once per product. `TODO(legal-verify)`: L3.

**SA-R37. Non-EU customers.** A business customer established outside the EU: supplied abroad
(ML 6 kap. 33 § read with 34 §), no Swedish VAT, box 40, BAS **3305** (`ForeignSale::
ExportServices`). A consumer outside the EU: ML 6 kap. 58–59 §§ move certain services abroad
(59 § p. 3 includes "databehandling och tillhandahållande av information"), but electronic
services as such are not listed in 59 §; otherwise the main rule 6 kap. 35 § (Swedish VAT)
applies. **Which applies to SaaS for a non-EU consumer is open.** Until answered, NONOX applies
Swedish VAT to non-EU consumers and shows a warning. `TODO(legal-verify)`: L3.

**SA-R38. Stripe's tax result is input, not truth.** Stripe can compute tax on invoices
(`automatic_tax`, `total_taxes`, ST-INV). NONOX recomputes the expected VAT per invoice from the
customer's country, the VAT number and the threshold (SA-R34–37) and refuses to book an invoice
whose Stripe tax differs by more than one öre per rate until a person resolves it. *NONOX
choice; AGENTS.md §1.4.*

### Underlag (verifikationer) and archive

**SA-R39. Received information is the verifikation.** "För varje affärshändelse ska det finnas en
verifikation. Om företaget har tagit emot en uppgift om affärshändelsen … ska denna uppgift …
användas som verifikation." *BFL 5 kap. 6 §.* BFN-BOK exempel 2.3 (a web shop paid through a
payment service): the data per sale fetched from the payment provider "är mottagna uppgifter som
enligt 5 kap. 6 § första stycket BFL ska användas som verifikation", must be kept in electronic
form (7 kap. 1 § BFL), may be summarised per day, and a hänvisningsverifikation may point to them.
**NONOX rules:**
- Stripe's monthly tax invoice for fees (ST-TAX) is the underlag for fee vouchers; until the PDF
  is uploaded, the fee voucher carries the balance transactions and the payout report as
  underlag and a note that the invoice is missing.
- Each charge, refund, dispute and payout voucher carries the balance transactions it was built
  from, stored as received (JSON with SHA-256), plus the Stripe invoice PDF where there is one.
- A daily summary voucher lists the Stripe ids it contains, so the link from voucher to source
  "utan svårighet" can be established (BFL 5 kap. 7 § andra stycket).
*Sources: BFL, BFN-BOK.*

**SA-R40. Stripe invoices to end customers.** A Stripe-generated invoice to the company's
customer is the company's own sales invoice issued in its name by a third person (ML 17 kap.
10–11 §§ allow "förvärvaren eller en tredje person"). It must carry the ML 17 kap. 24 § content,
including "ett löpnummer baserat på en eller flera serier, som unikt identifierar fakturan" and
the seller's VAT number. Stripe's `number` is unique per account (ST-INV). Whether the Stripe
numbering can be one of the company's series next to NONOX's own, and whether NONOX should then
import such invoices as issued invoices rather than create its own, is open.
`TODO(legal-verify)`: L2.

**SA-R41. Keep as received, seven years, in Sweden or under 3 a §.** Electronic documents
received "ska bevaras i det format och med det innehåll de hade när de kom till företaget"
(BFL 7 kap. 1 §), until the seventh year after the end of the calendar year in which the fiscal
year ended, in Sweden (7 kap. 2 §), or in another EU country under the conditions of 7 kap. 3 a §
(notification to Skatteverket, immediate electronic access, immediate printout in Sweden).
Stripe's Dashboard is **not** the company's archive. NONOX stores the raw objects and PDFs it
used in its own storage, hashed, within the archive package (AGENTS.md §10). *Source: BFL.*

## C. Stripe API facts used by the design

**SA-R42. Keys.** Stripe has publishable (`pk_`), restricted (`rk_`), secret (`sk_`) and
organisation keys. A restricted key "can do only what you give it permission to do"; for each
resource the permission is **None**, **Read** or **Write** ("The default value for all
permissions is None"); "Write permissions imply read permissions". Live restricted keys start
with `rk_live_`. Stripe's table lists third-party sharing of a secret key as "Dangerous" and of a
restricted key as "Safer: you hand out only the access the third party needs". A live key you
create yourself cannot be revealed again; keys can be rotated (old and new both work up to 7
days) or expired; access policies can restrict a key by IP, ASN or country. *Sources: ST-KEY,
ST-RAK.*

**SA-R43. Stripe Apps with RAK authentication.** A Stripe App can declare its permissions; the
user installs it and generates a restricted key "provisioned with the proper permissions" and
"provide[s] the key to your site". The method cannot be changed after the app is uploaded.
*Source: ST-APP.*

**SA-R44. Connect OAuth.** `GET https://connect.stripe.com/oauth/authorize` with `scope`
`read_write` or `read_only`; but "`read_only` can only be specified for extensions", "Only
Extensions can use `read_only`", and since June 2021 platforms using `read_write` cannot connect
to Standard accounts controlled by another platform. `access_token` and `refresh_token` in the
token response are marked **(Deprecated)**: the platform uses its own secret key with the
`Stripe-Account` header. Revocation: `POST https://connect.stripe.com/oauth/deauthorize`.
*Sources: ST-OAUTH, ST-OAUTH2.*

**SA-R45. Objects read.**
- **Subscriptions**: `status` ∈ `incomplete`, `incomplete_expired`, `trialing`, `active`,
  `past_due`, `canceled`, `unpaid`, `paused`; `items.data[].price.recurring.{interval,
  interval_count, usage_type}`, `unit_amount`, `quantity`, item `current_period_start/end`;
  `discounts`; `trial_start/end`; `cancel_at`, `cancel_at_period_end`, `canceled_at`
  ("reflect[s] the time of the most recent update request, not the end of the subscription
  period"), `ended_at`; `pause_collection`; `currency`; `customer`. (ST-SUB)
- **Invoices**: `number`, `status` ∈ `draft`, `open`, `paid`, `uncollectible`, `void`;
  `currency`, `total`, `total_excluding_tax`, `total_taxes`, `customer_address`,
  `customer_tax_ids`, `customer_tax_exempt` ∈ `exempt`, `none`, `reverse` (frozen at
  finalisation); `lines` with line `period` ("Use the line item period … to get the service
  period"); `billing_reason`; `status_transitions`; `invoice_pdf`; `pre_/post_payment_credit_notes_amount`. (ST-INV)
- **Credit notes**: `invoice`, `type` ∈ `pre_payment`, `post_payment`, `mixed`; `status` ∈
  `issued`, `void`; `total_excluding_tax`, `refunds`, `out_of_band_amount`, `reason`,
  `effective_at`, `pdf`. (ST-CN)
- **Balance transactions**: `amount`, `fee`, `fee_details`, `net` (= `amount` − `fee`),
  `currency`, `exchange_rate`, `type` (incl. `charge`, `payment`, `refund`, `payout`,
  `stripe_fee`, `stripe_fx_fee`, `tax_fee`, `adjustment`), `reporting_category`, `source`,
  `available_on`, `status`. (ST-BT)
- **Payouts**: list balance transactions with `payout=po_…` (expand `data.source`) to see what a
  payout contains; the `payout.reconciliation_completed` event signals it is ready; for
  **manual** payouts "Stripe can't identify which transactions are included". (ST-PO)
- **Disputes**: `amount`, `charge`, `status` (incl. `won`, `lost`), `balance_transactions`
  ("zero, one, or two"). (ST-DSP)
- Customers, charges and refunds are read through the `source` of balance transactions and the
  `customer` of subscriptions and invoices; their object pages were not read (gap).

**SA-R46. Webhooks.** Signed with HMAC-SHA256 in the `Stripe-Signature` header: `t=` timestamp
and `v1=` signature(s) over `"{t}.{raw body}"` with the endpoint's `whsec_` secret; "ignore all
schemes that aren't `v1`"; compare in constant time; the libraries' default tolerance is 5
minutes and a tolerance of 0 must not be used; the raw body must not be altered. Stripe retries
live events "for up to three days with an exponential back off"; it "doesn't guarantee the
delivery of events in the order that they're generated" and endpoints "might occasionally receive
the same event more than once" — dedupe on event id, and do not use `created` for ordering. Return
2xx quickly and process in a queue. "Thin" events (recommended) carry only a reference; the object
is fetched. *Source: ST-WH.*

**SA-R47. Rate limits and pagination.** Live mode: 100 requests per second globally, 25 per
second per endpoint unless noted, 20 per second for Search; 429 with a `Stripe-Rate-Limited-Reason`
header; retry with exponential backoff and jitter; concurrency limit 50 in-progress requests
in live mode. Read requests are allocated "an average of 500 per transaction" over 30 days, with a
minimum of 10 000 per month. List endpoints paginate with `limit` (1–100, default 10),
`starting_after`, `ending_before` and `has_more`, newest first. *Sources: ST-RL, ST-PAG.*

## Synthetic fixtures (numbers only, no real data)

- **F1 normalisation**: monthly 299 kr (MRR 299,00), quarterly 897 kr (299,00), annual 3 000 kr
  (250,00), annual 1 000 kr (83,33), quarterly 100 kr (33,33); company MRR 964,66; ARR 11 575,92.
- **F2 bridge**: end of January 10 000,00; February: Ny 1 200,00, Utökning 300,00, Minskning
  150,00, Churn 500,00, Valutaeffekt +12,34 → 10 862,34; the identity holds to the öre.
- **F3 churn timing**: annual contract 12 000 kr 2026-03-01–2027-02-28, notice given 2026-06-10:
  Uppsagd MRR 1 000,00 from June; Churn 1 000,00 in **March 2027**, not June 2026.
- **F4 reactivation**: MRR Jan–Mar, 0 Apr–Jun, again in Jul → Återaktivering in July, cohort
  January.
- **F5 back-dated change**: March closed with a customer at 500,00; in April a price cut to
  400,00 effective 1 March is registered → March unchanged, April shows Rättelse −100,00 (affects
  March) and April MRR 400,00.
- **F6 payout**: charges 1 000,00 + 2 500,00, fees 25,00 + 51,25 (`stripe_fee`), refund −200,00;
  payout 3 223,75 = Σ net; clearing account 1686 is 0 after the payout voucher.
- **F7 currency**: EUR line 100,00 EUR at 11,20 and next month 11,10 → Valutaeffekt −10,00 kr, no
  Minskning.
- **F8 K2 year end**: annual 6 000 kr invoiced 2026-12-01 for Dec–Nov → revenue 500 kr in 2026,
  5 500 kr on 2979; the page lists it under "under 7 000 kr — kräver ditt ställningstagande
  enligt K2 2.4B".

## Open questions for a human lawyer or accountant

- **L0** Re-read every SKV and ST quote above against the live page (fetch tool conversions).
- **L1** Stripe's fees to a Swedish company: is the payment processing fee an exempt "bank- och
  finansieringstjänst" (ML 10 kap. 33 §) or a taxable service under reverse charge (6 kap. 33 §,
  16 kap. 9 §; boxes 21, 30, 48)? Are Billing, Radar, Tax, currency conversion and dispute fees
  treated differently? If exempt, is anything reported in the VAT return? Which Skatteverket
  ställningstagande governs (the 2017-10-27 one was only seen in a mirror)?
- **L2** Is a Stripe-generated invoice in the company's name a valid ML 17 kap. invoice issued by
  a third person, can Stripe's numbering be one of the company's series alongside NONOX's own
  (17 kap. 24 § p. 2), and should NONOX import it as an issued invoice instead of creating one?
- **L3** Place of supply of SaaS to a consumer outside the EU (ML 6 kap. 35 § vs 58–59 §§, in
  particular 59 § p. 3), and confirmation that a typical SaaS subscription is an "elektronisk
  tjänst" under 6 kap. 57 § for the EU-consumer rule.
- **L4** OSS output VAT account: BAS 2026 has 2670 "Utgående moms på försäljning inom EU, OSS";
  NONOX's existing code (ADR-010, `OssRates::VAT_ACCOUNT`) books on 2619, which is not in BAS
  2026. Should NONOX move to 2670 (and how, for companies that already booked on 2619)? This is
  outside ADR-080 but affects it.
- **L5** K2 2.4/2.4A/2.4B and K3 23.7/23.26 for SaaS: may NONOX offer the 7 000-kr
  simplification at all for a company whose main income is subscriptions, given the kommentar's
  explicit annual-subscription example? Is K2 6.11 or 6.26 the right reference for a SaaS
  licence, and is linear recognition right in both?
- **L6** Clearing account for Stripe balances: 1686 "Fordringar för kontokort och kuponger" or
  another 168x; and ADR-042's reference to 1580, which BAS 2026 no longer has.
- **L7** Daily summary vouchers for card and wallet payments received online: does BFL 5 kap.
  6 § third stycket ("gemensam verifikation … vid försäljning … mot kontant betalning") cover
  them, or only BFN-BOK exempel 2.3's "bokföring i sammandrag" with one verifikation per sale?
- **L8** Archive location: is keeping Stripe-received documents only inside NONOX's storage
  (hosted service in the EU) enough under BFL 7 kap. 3 a §, and who notifies Skatteverket of the
  place of storage — the company, or the hosted service on its behalf?
