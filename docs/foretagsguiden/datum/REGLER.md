# Mina datum: the rules behind every date

Engineering specification for the deadline engine of Företagsguiden stage 2
(ADR-051, `app/Domain/Deadlines`). One section per `DeadlineType`. Every rule
names the law paragraph and the authority page it was checked against, and
whether that source was read in full or only in part. Anything not settled is
a `TODO(legal-verify)`; the engine shows such a date with `legalVerify = true`
and the article tells the reader to check it with the authority.

Checked 2026-11-04 against:

| Source | Version read | Read |
|---|---|---|
| Skatteförfarandelag (2011:1244), riksdagen.se | t.o.m. SFS 2026:1305 | in full: 3 kap. 4, 5 och 11 §§, 24 kap. 1 §, 26 kap. 26–35 §§, 30 kap., 32 kap., 33 kap. 3–6 och 10 §§, 35 kap., 36 kap., 62 kap. 1–9 §§, 65 kap. 1–9 §§; 15 kap. 8 §; chapter list 15–24 kap. |
| Inkomstskattelag (1999:1229), riksdagen.se | as of 2026-11-04 | 63 kap. 11 § (grundavdrag 15 000 kr for ideella föreningar) |
| Skatteförfarandeförordning (2011:1261), riksdagen.se | t.o.m. SFS 2026:1570 | 6 kap. (inkomstdeklaration, anstånd 20 §) — holds no deadline |
| Lag (1930:173) om beräkning av lagstadgad tid, riksdagen.se | t.o.m. SFS 2026:1659 | in full |
| Årsredovisningslag (1995:1554), riksdagen.se | t.o.m. SFS 2026:780 | 8 kap. 1–6 §§ in full |
| Aktiebolagslag (2005:551), riksdagen.se | t.o.m. SFS 2026:783 | 7 kap. 10 och 18 §§ |
| Lag (2018:672) om ekonomiska föreningar, riksdagen.se | t.o.m. SFS 2025:807 | 6 kap. 9 och 17 §§ |
| Bokföringslag (1999:1078), riksdagen.se | t.o.m. SFS 2024:342 | 3 kap. 1–3 §§, 5 kap. 2 §, 6 kap. 1 § |
| Skatteverket – När ska jag deklarera moms | page as of 2026-11-04 | in full |
| Skatteverket – När ska jag lämna arbetsgivardeklaration | page as of 2026-11-04 | in full (2026 tables) |
| Skatteverket – Deklarera åt ett aktiebolag eller en ekonomisk förening | page as of 2026-11-04 | in full |
| Skatteverket – Deklarera åt ett handelsbolag eller kommanditbolag | page as of 2026-11-04 | dates section |
| Skatteverket – Deklarera enskild näringsverksamhet | page as of 2026-11-04 | in full |
| Skatteverket – Preliminärskatt för företag och föreningar | page as of 2026-11-04 | in full |
| Skatteverket – Periodisk sammanställning för varor och tjänster | page as of 2026-11-04 | in full |
| Skatteverket – Kontrolluppgifter; KU31 | page as of 2026-11-04 | overview (list of KU types); KU31 page in full |
| Bolagsverket – Årsredovisningsguiden för aktiebolag; Förseningsavgifter föreningar | search summary only (pages behind a bot check on 2026-11-04) | summary |

URLs:

- SFL: https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/skatteforfarandelag-20111244_sfs-2011-1244/
- Lag om beräkning av lagstadgad tid: https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/lag-1930173-om-berakning-av-lagstadgad-tid_sfs-1930-173/
- ÅRL: https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/arsredovisningslag-19951554_sfs-1995-1554/
- ABL: https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/aktiebolagslag-2005551_sfs-2005-551/
- EFL: https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/lag-2018672-om-ekonomiska-foreningar_sfs-2018-672/
- BFL: https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/bokforingslag-19991078_sfs-1999-1078/
- Moms: https://www.skatteverket.se/foretag/moms/deklareramoms/narskajagdeklareramoms.4.6d02084411db6e252fe80008988.html
- AGI: https://www.skatteverket.se/foretag/arbetsgivare/lamnaarbetsgivardeklaration/narskajaglamnaarbetsgivardeklaration.4.361dc8c15312eff6fd13c11.html
- AB/ek. förening: https://www.skatteverket.se/foretag/inkomstdeklaration/deklareraatettaktiebolagellerekonomiskforening.4.46ae6b26141980f1e2d1261.html
- HB/KB: https://www.skatteverket.se/foretag/inkomstdeklaration/deklareraatetthandelsbolag.4.309a41aa1672ad0c837988b.html
- Enskild firma: https://www.skatteverket.se/foretag/inkomstdeklaration/deklareraenskildnaringsverksamhet.4.133ff59513d6f9ee2ebf00.html
- Preliminärskatt: https://www.skatteverket.se/foretag/skatterochavdrag/skattekontobetalaochfatillbaka/preliminarskattforforetagochforeningar.4.233f91f71260075abe8800010616.html
- Periodisk sammanställning: https://www.skatteverket.se/foretag/moms/deklareramoms/periodisksammanstallningforvarorochtjanster.4.58d555751259e4d661680001093.html
- Kontrolluppgifter: https://www.skatteverket.se/foretag/skatterochavdrag/kontrolluppgifter.4.7856a2b411550b99fb7800086379.html
- Bolagsverket ÅR-guiden: https://bolagsverket.se/foretag/aktiebolag/arsredovisningforaktiebolag/arsredovisningsguidenforaktiebolag.5550.html

## 0. Shared rules

### 0.1 Moving a date that falls on a non-working day

Lag (1930:173) 2 §: when the last day for an action required by law falls on
a **Sunday, another public holiday, Saturday, midsommarafton, julafton or
nyårsafton**, the action may be taken the next weekday ("nästa vardag").

- Implement one `nextWorkday()` used by every provider: move forward while the
  day is Saturday, Sunday, a holiday under lagen (1989:253) om allmänna
  helgdagar (`App\Domain\Calendar\SwedishHolidays::isHoliday`), or one of the
  three eves (`SwedishHolidays::eves()`: `MIDSOMMARAFTON`, `JULAFTON`,
  `NYARSAFTON`).
- `VatDeadlines` uses the same `Workdays::next`, eves included (changed
  2026-11-04; its days 12, 17, 26, 27 never fall on an eve anyway). The
  Bolagsverket and income tax dates below (for example 31 July, 31 January,
  1 July) need the eves.
- Skatteverket applies the move to payments as well as declarations: its
  published 2026 AGI dates are 19 January (17 January was a Saturday),
  13 April, 13 July, 14 September, 14 December, and its AB table gives
  2 August 2027 for "1 August". Payment of tax counts when it is **booked on
  the tax account** (SFL 62 kap. 2 §), so the engine's reminders must lead
  the date (see §10).
- TODO(legal-verify): Bolagsverket's seven-month limit (ÅRL 8 kap. 6 §) is
  worded as a condition for the fee ("inte har kommit in ... inom sju
  månader"), not as "the last day for an action" (1930:173 2 §). No
  Bolagsverket page, preparatory work or ruling found (2026-11-04) says
  whether the move applies; Bolagsverket's pages could not be read in full
  (bot check). Ask Bolagsverket in writing. Until answered: show the unmoved
  date (conservative) with `legalVerify = true`. The stämma limit (ABL 7 kap.
  10 §) is a limit for holding the meeting and is not moved either.

### 0.2 Counting months

Lag (1930:173) 1 §: a period in months ends on the day with the same number
as the start day; if that day does not exist, the last day of the month.

- "Within six months from the end of the fiscal year" (ABL 7 kap. 10 §) for a
  year ending 31 December → 30 June. For a year ending 30 June → **30
  December**, and the seven-month limit (ÅRL 8 kap. 6 §) → **30 January**.
- Settled (2026-11-04) by the law text: 1930:173 1 § applies whenever a law
  counts time in months ("Då enligt lag eller särskild författning tid skall
  räknas efter vecka, månad eller år") and takes the day with the same number
  as the day the time is counted from; only when that number is missing in
  the end month is it the month's last day. ABL 7 kap. 10 §, EFL 6 kap. 9 §
  and ÅRL 8 kap. 3 § tredje stycket and 6 § all count months from the fiscal
  year's end. Engine: `fiscalYearEnd->addMonthsNoOverflow(n)` (31 August + 6
  → 28/29 February), no flag needed for this point.

### 0.3 Inputs the engine needs

| Input | Where | Notes |
|---|---|---|
| Legal form | `teams.legal_form` | `LegalForm` |
| VAT registered, VAT period | `vat_registered`, `vat_period` | must match Skatteverket's decision |
| Turnover (VAT base) > 40 MSEK | `turnover_over_40_msek` | the law's test is the *beskattningsunderlag exklusive unionsinterna förvärv och import*, estimated for the year (SFL 26 kap. 26 and 30 §§) |
| EU trade in a yearly VAT period | caller (`EuTrade`) | goods between EU countries or reverse-charge services under art. 196 (SFL 26 kap. 33 a § andra stycket) |
| Employer registered | `employer_registered` | |
| Fiscal years | `fiscal_years` | end dates drive income tax, VAT (yearly), stämma, årsredovisning |
| Files electronically | default `true` | NONOX produces files; paper dates are one month earlier where the law has two dates |
| Start date | `teams.started_on` | only for the first F-skatt payment hint (§3.4) |

## 1. Moms (`DeadlineType::Vat`)

Applies when `vat_registered` and `vat_period !== NONE`. One date per
reporting period. Declaration and payment have the **same** date: SFL 62 kap.
3 § första stycket ("ska vara betalda senast den dag då deklarationen ska ha
kommit in"). Skatteverket's page says the same.

| Case | Rule | Law |
|---|---|---|
| Month, base ≤ 40 MSEK | 12th of the **second** month after the period; 17th when that month is January or August | SFL 26 kap. 26 § (column "Punktskattedeklaration"), 27 § |
| Month, base > 40 MSEK | 26th of the month after the period; 27th when that month is December | SFL 26 kap. 30 § |
| Month, ≤ 40 MSEK, on request | the > 40 MSEK dates (26th of the month after) | SFL 26 kap. 28 § — **not handled** |
| Quarter | 12th of the second month after the quarter; 17th in August (Q1 → 12 May, Q2 → 17 August, Q3 → 12 November, Q4 → 12 February) | SFL 26 kap. 26 § |
| Year, sole trader (EF), no EU trade | 12 May the year after | SFL 26 kap. 33 a § |
| Year, EF **with** EU trade | 26th of the second month after the year (26 February) | SFL 26 kap. 33 §, 33 a § andra stycket |
| Year, HB or KB (any trade) | 26th of the second month after the fiscal year; 27th in December | SFL 26 kap. 33 § (33 b § excludes handelsbolag) |
| Year, other legal persons (AB, ek. förening, ideell förening, stiftelse) **with** EU trade, or that do not file an income tax return | as HB | SFL 26 kap. 33 §, 33 b § tredje stycket |
| Year, other legal persons without EU trade | table below | SFL 26 kap. 33 b § |

SFL 26 kap. 33 b §, and Skatteverket's month ranges:

| Fiscal year ends | Law names | Paper | Electronic (month after; 17th in Jan/Aug, 12th in Apr/Dec) |
|---|---|---|---|
| January–April | 30 April | 12 November | 12 December |
| May–June | 30 June | 27 December | 17 January |
| July–August | 31 August | 12 March | 12 April |
| September–December | 31 December | 12 July | 17 August |

**Verdict on `App\Domain\Vat\VatDeadlines`:** every case it handles matches
the law text and Skatteverket's page as read today. Its `TODO(legal-verify)`
points stand:

- The month ranges are law, not only Skatteverket's practice: SFL 3 kap. 5 §
  says that for a legal person whose year ends on another day than 31 Dec,
  30 Apr, 30 Jun or 31 Aug, "beskattningsår" in 26 kap. 33 b § (and 32 kap.
  2 §, 62 kap. 4–5 §§ and 65 kap.) means the next of the periods 1 Jan–31
  Dec, 1 May–30 Apr, 1 Jul–30 Jun, 1 Sep–31 Aug that ends after the actual
  year. So January–April → 30 April, May–June → 30 June, July–August →
  31 August, September–December → 31 December. Flag removed.
- Not handled, keep listed: 26 kap. 28 § (monthly filer who asked for the
  26th), 33 c § (earlier date on request or for special reasons), 33 e §
  (catch-up date after a 33 c decision), 29 § (catch-up after a change of
  period), and dödsbon.
- Byråanstånd for a sole trader with yearly VAT: until **26 June** (SFL 36 kap.
  6 §), but Skatteverket charges kostnadsränta from the day after 12 May even
  then. Show 12 May; mention byråanstånd only as a note.
- The weekend move uses `Workdays::next`, eves included (§0.1).
- Ideell förening and stiftelse: 33 b § applies only when they file an
  income tax return (SFL 30 kap. 4 § p. 2–3; an allmännyttig ideell förening
  with intäkter not above the grundavdrag does not). One that does not file
  falls under 33 § (26th of the second month, 27th in December). NONOX cannot
  know which, so for these two forms show the 33 § date with
  `legalVerify = true` and name the 33 b § date as the alternative when the
  förening files (finding 2026-11-04).
- TODO(legal-verify): does a purchase of services from a supplier outside
  the EU (box 22) count under 33 a § andra stycket p. 2 ("betalningsskyldig
  för i Sverige … i enlighet med … artikel 196")? Art. 196 covers any
  supplier not established in the member state, which reads as yes. Until
  answered, count box 22 for the earlier date or flag the yearly date when
  box 22 has postings. Domestic reverse charge (boxes 24, 41; art. 199) does
  not count.
- A quarter that spans two fiscal years (broken year, quarterly VAT) is one
  period with one date: list it once.

Article: `/foretagsguiden/viktiga-datum/momsdeklarationens-datum`.

## 2. Arbetsgivardeklaration (`DeadlineType::Agi`)

Applies when `employer_registered`. One date per payment month (the month
salaries or benefits were paid), declared the month after. A zero
declaration is required for every month the company is registered as an
employer (Skatteverket's page).

| Case | Declare by | Pay by | Law |
|---|---|---|---|
| Not VAT registered, or VAT base ≤ 40 MSEK | 12th of the month after; 17th in January and August | same day | SFL 26 kap. 26 § (column "Arbetsgivardeklaration"), 32 §; 62 kap. 3 § första stycket |
| VAT base > 40 MSEK | 26th of the month after; 27th in December | **12th of the month after; 17th in January** | SFL 26 kap. 30 §; 62 kap. 3 § andra stycket |
| > 40 MSEK, payments mismanaged | 12th (17th in January), by decision | same | SFL 26 kap. 31 § — not handled (comes from a decision) |

Note that in the large case the **payment is due two weeks before the
declaration**. Mina datum should show two entries for a large employer
("Betala skatteavdrag och arbetsgivaravgifter" on the 12th and "Lämna
arbetsgivardeklarationen" on the 26th), or one entry on the 12th that says
the declaration follows on the 26th.

**Verdict on `AgiDeadlines` and `agi.deadlines` in rules.json:**

- The 2026 list (`turnover_up_to_40_msek`) equals Skatteverket's 2026 table
  date for date, including the moved dates.
- The `TODO(legal-verify)` about the > 40 MSEK day can be closed: the day is
  the 26th by SFL 26 kap. 30 §, and Skatteverket's 2026 table (26 January …
  28 December) agrees. The rule should also carry the December exception
  (27 December): `setDay(26)` lands on Annandag jul and is moved to the 27th
  or later, so the result is right today, but by accident.
- **Gap:** `LARGE` returns the declaration day only. The payment day (12th,
  17th in January, SFL 62 kap. 3 § andra stycket) is missing, and that is the
  date that costs interest.
- **Gap:** `rules.json` 2027 has no `agi.deadlines` value (only "expected
  december 2026"), so `AgiDeadlines::for()` returns null for payment months
  from January 2027. Because the rule is in the law, Mina datum should compute
  it from the rule above plus §0.1 instead of depending on the published list,
  and use the list only as a cross-check when it exists.
- The "large" test is the company's VAT base (26 kap. 30 §). A company that
  is not VAT registered always uses 32 § (the 12th) whatever its turnover.

Article: `/foretagsguiden/viktiga-datum/arbetsgivardeklarationens-datum`.

## 3. Preliminärskatt, F-skatt (`DeadlineType::PreliminaryTax`)

Applies to holders of F-skatt who have a decision on debiterad preliminär
skatt: AB, ekonomisk förening, other legal persons with taxable income, and
sole traders (for whom egenavgifter are included, per Skatteverket). A
handelsbolag or kommanditbolag pays **no** income tax itself; its partners pay
their own preliminary tax. Show it for HB/KB only as a note to the partners.

### 3.1 Rule

SFL 62 kap. 4 §: equal amounts every month, **from the second month of the
beskattningsår to the month after it ends** (twelve payments for a
twelve-month year).

- Due on the **12th**, except the **17th in January and August**.
- For a company whose VAT base is estimated above 40 MSEK, August is the
  **12th** (January stays the 17th).
- Move per §0.1.

### 3.2 Which year

- Legal persons: the beskattningsår is the fiscal year when it ends on
  31 December, 30 April, 30 June or 31 August. An AB with a fiscal year
  May–April pays June … May. A calendar-year AB pays February … January.
- A legal person whose fiscal year ends on another day: for 62 kap. 4 §
  första stycket and 5 § (payment) and 65 kap. 5, 6, 9, 17 and 18 §§
  (interest), beskattningsår means the next of 1 Jan–31 Dec, 1 May–30 Apr,
  1 Jul–30 Jun, 1 Sep–31 Aug that ends after the fiscal year (SFL 3 kap.
  5 §). An AB with fiscal year October–September is treated as a calendar
  year: series February … January, top-up recommendation on 12 February.
  (Natural persons always use the calendar year, 3 kap. 4 § last stycke.)
- Sole traders: calendar year (BFL 3 kap. 1 § andra stycket; a natural
  person's fiscal year is the calendar year), so February … January.

### 3.3 What the engine cannot know

- The **amount** and whether a decision exists. Skatteverket's decision
  states both and the due dates; there is no invoice. The engine shows the
  dates and says "enligt ditt beslut om preliminärskatt".
- Late decisions (62 kap. 5 §): first payment on the due date in the month
  after a decision made by the 20th, otherwise the second month after; a
  decision after the 20th of the year's last month is paid in one go.
- Seasonal businesses (62 kap. 6 §): payments only in some months by
  decision.
- New businesses (62 kap. 7 §): first payment postponed three months on
  request.
- TODO(legal-verify)/product: offer "Vi har inte F-skatt" and "Första
  betalningen enligt beslutet" so the company can switch the series off or
  set its first month; until then show the series from the fiscal year's
  second month with the note above.

### 3.4 Kvarskatt and extra payments (broad terms, a note, not a series)

- Final tax to pay: due on the förfallodag nearest after 90 days from the
  decision (SFL 62 kap. 8 §); förfallodagar in 62 kap. 9 §. The date is in
  the slutskattebesked; Skatteverket publishes dates per year-end group
  (e.g. year ending September–December 2026: 12 mars 2027 per its AB page,
  which mixes years — use the beskedet, not a computed date).
- Kostnadsränta on final tax runs from the **13th of the second month after
  the beskattningsår**; on amounts up to **30 000 kronor** not until the **4th
  of the fifth month** (free through the 3rd; SFL 65 kap. 4 § andra stycket,
  6 §). When the 12th (or the 3rd) is a Saturday or Sunday, interest starts
  on the following Tuesday instead (65 kap. 18 §), so a payment booked on
  the Monday is still interest-free. 65 kap. 18 § covers only Saturday and
  Sunday, not a weekday holiday. Skatteverket's
  AB table follows this (year ending September–December 2026: 12 February
  2027 / 3 May 2027).
- Recommendation (product, not law): a "Fyllnadsinbetalning?" reminder on
  the 12th of the second month after the fiscal year, labelled as advice.

Article: `/foretagsguiden/viktiga-datum/preliminarskatt`.

## 4. Inkomstdeklaration (`DeadlineType::IncomeTaxReturn`)

One date per fiscal year, after it ends.

### 4.1 AB, ekonomisk förening, ideell förening, stiftelse (INK2 / INK3)

SFL 32 kap. 2 §: "andra juridiska personer än dödsbon"; electronic filing
one month later (second stycke).

| Fiscal year ends | Law names | Paper | Electronic |
|---|---|---|---|
| January–April | 30 April | 1 November | 1 December |
| May–June | 30 June | 15 December | 15 January |
| July–August | 31 August | 1 March | 1 April |
| September–December | 31 December | 1 July | 1 August |

- Skatteverket's AB page shows only the electronic date ("Deklarera senast":
  1 december 2026, 15 januari 2027, 1 april 2027, 2 augusti 2027 for years
  ending in 2026), with 1 August 2027 already moved (Sunday). The month
  ranges follow from SFL 3 kap. 5 § (see §1). Show the electronic date;
  mention the paper date.
- A shortened or extended year (omläggning, avveckling) changes the date
  with the year end; the engine uses the actual end date.
- Ideell förening and stiftelse: only when they must file. SFL 30 kap. 4 §:
  an ideell förening meeting IL 7 kap. (allmännyttig) files when its
  intäkter exceeded the grundavdrag of 15 000 kr (IL 63 kap. 11 §, a fixed
  amount); any other ideell förening when taxable intäkter were at least
  200 kr; a family stiftelse (punkt 1) always. A fully tax-exempt förening
  or stiftelse instead files särskilda uppgifter (33 kap. 3 §) on the same
  day (33 kap. 10 §) unless Skatteverket has exempted it (33 kap. 4 §).
  Product (not legal): the engine cannot see the förening's intäkter
  split, so show the date only when the company says it must file.
- Anstånd for legal persons requires synnerliga skäl (SFL 36 kap. 4 §).

### 4.2 Handelsbolag and kommanditbolag (INK4 with INK4R, INK4S, INK4DU)

- Skatteverket's HB page gives the same dates as for AB (1 december 2026,
  15 januari 2027, 1 april 2027, 2 augusti 2027) and says they apply
  "oavsett om du deklarerar digitalt eller på papper".
- Settled (2026-11-04): a svenskt handelsbolag files **no inkomstdeklaration**
  (SFL 30 kap. 4 § punkt 3 excludes it). It files **särskilda uppgifter**
  (33 kap. 6 §), which "ska ha kommit in ... senast den sista dagen för
  inkomstdeklaration enligt 32 kap. 2 §" (33 kap. 10 §). The paper/electronic
  split in 32 kap. 2 § andra stycket is for "den som lämnar
  inkomstdeklaration"; for HB the last day under 32 kap. 2 § is the later
  date, whatever the channel, which is exactly what Skatteverket's page says.
  Show the later date, no flag. (Our reading of 33 kap. 10 §; it matches
  Skatteverket's published dates.)
- A HB with only natural persons as partners has the calendar year
  (BFL 3 kap. 1 §), so in practice 1 August (electronic).
- The partners declare their share themselves (natural persons on NE in
  INK1, 2 May; legal persons on their own INK2).

### 4.3 Sole trader (NE with INK1)

- SFL 32 kap. 1 §: **2 May** the year after the beskattningsår (calendar
  year). Skatteverket's page: "Sista dagen för att deklarera är den 4 maj
  2026" (2 May 2026 was a Saturday). For 2027: 2 May is a Sunday → 3 May.
- Byråanstånd (deklarationshjälp i näringsverksamhet): at most until **15
  June** (SFL 36 kap. 3 §), per Skatteverket's schedule (SFF 6 kap. 20 §).
  Note only; not a computed date.

### 4.4 Not handled

Dödsbon; shell company declarations (27 kap.); tilläggsskattedeklaration
(32 a kap.).

Article: `/foretagsguiden/viktiga-datum/inkomstdeklarationen`.

## 5. Årsstämma (`DeadlineType::AnnualMeeting`)

| Legal form | Rule | Law |
|---|---|---|
| AB | ordinary meeting (årsstämma) **within six months** of the fiscal year's end | ABL 7 kap. 10 § |
| Ekonomisk förening | ordinary föreningsstämma within six months | EFL 6 kap. 9 § |

Related dates the engine can show as steps before the stämma (derived from
the stämma date the company plans, not from the limit):

- Annual report to the auditor at least **six weeks** before the stämma
  (ÅRL 8 kap. 2 §, AB and ek. förening; other companies four months after the
  year end).
- Notice (kallelse) to an ordinary stämma no earlier than six and no later
  than four weeks before; the articles may allow down to two weeks, not in a
  public AB (ABL 7 kap. 18 §; EFL 6 kap. 17 §).

Until the company enters a planned stämma date, show only the limit
(month counting per §0.2) and, as a **recommendation**, "Skicka
årsredovisningen till revisorn" six weeks before the limit when the company
has an auditor.

Article: `/foretagsguiden/viktiga-datum/arsstamma-och-arsredovisning`.

## 6. Årsredovisning till Bolagsverket (`DeadlineType::AnnualReport`)

| Legal form | Rule | Law |
|---|---|---|
| AB | certified copy with fastställelseintyg within **one month after** the stämma adopted the accounts | ÅRL 8 kap. 3 § 1 |
| Ekonomisk förening | same, after the föreningsstämma | ÅRL 8 kap. 3 § 2 |
| HB/KB with a legal person as partner | copies within **six months** of the fiscal year's end | ÅRL 8 kap. 3 § 3; BFL 6 kap. 1 § 3 |
| Stiftelse | six months, to the tillsynsmyndighet (länsstyrelsen) | ÅRL 8 kap. 1 och 3 § 4 — show only as a note |
| EF, HB without legal-person partner | nothing to file with Bolagsverket | BFL 6 kap. 1 och 3 §§ (årsbokslut) |

Förseningsavgift (AB and ek. förening): if the documents have not arrived
within **seven months** of the fiscal year's end (ÅRL 8 kap. 6 §); nine months
when a continued stämma has been notified under ABL 7 kap. 14 § tredje
stycket / EFL 6 kap. 13 § tredje stycket with the fee paid and the auditor's
assurance. Amounts: see `ansvar-och-regler/arsredovisning-och-bokslut`.

Engine: without a stämma date, the hard date shown is the **seven-month
limit** ("Sista dag innan förseningsavgift"), e.g. 31 July for a calendar year
(Bolagsverket's 2026 notice also uses 31 July). With a stämma date: stämma +
one month as well. TODO(legal-verify): §0.1 for this limit (§0.2 is settled).

Same article as §5.

## 7. Periodisk sammanställning (`DeadlineType::EuSalesList`)

Applies to a VAT-registered company that in the period sold goods
transported to another EU country, or services where the EU buyer is liable
under art. 196 (SFL 35 kap. 2 §). **Only for periods with such sales**:
Skatteverket says no list is filed when there were none.

| What | Period | Law |
|---|---|---|
| Goods (or goods and services) | calendar month | SFL 35 kap. 3 § |
| Goods, on request, when goods ≤ 500 000 kr excl. VAT in the current and each of the four previous quarters | calendar quarter | SFL 35 kap. 4 § |
| Services only | calendar quarter | SFL 35 kap. 3 § |
| Services, on request | calendar month | SFL 35 kap. 5 § |

Due (SFL 35 kap. 9 §): **25th of the month after the period** when filed
electronically, **20th** otherwise. Move per §0.1. Not handled: 35 kap. 8 §
catch-up after a change of period.

Engine: show the date for a period when the ledger has EU sales in it (the
VAT report's boxes 35/39 or the customers marked EU), or always when the
company says it trades in the EU; label the 25th.

Article: `/foretagsguiden/viktiga-datum/periodisk-sammanstallning`.

## 8. Kontrolluppgifter (not a `DeadlineType` yet)

- Salaries and benefits reported per payee in the AGI get **no**
  kontrolluppgift (SFL 15 kap. 8 § 3). For an ordinary employer, the yearly
  salary KU is gone.
- Other kontrolluppgifter are due **31 January** the year after (SFL 24 kap.
  1 §). 31 January 2027 is a Sunday → 1 February.
- **KU31** (SFL 19 kap.): an AB or ekonomisk förening that pays its own
  dividend must file it for each natural person or dödsbo that received it;
  not for Swedish legal persons (Skatteverket's KU31 page, read in full
  2026-11-04: "Om ett aktiebolag själv betalar ut utdelningen, ska
  aktiebolaget lämna KU31", "KU31 ska inte lämnas för juridiska personer som
  skattemässigt är hemmahörande i Sverige"). Kupongskatt cases: four months
  after the payout (same page).
- **KU20** (SFL 17 kap.): when the company pays or credits interest to a
  natural person, typically interest on a loan from an owner. Exemptions for
  small amounts are in SKVFS 2013:23 (not read).
- Recommendation: add `DeadlineType::ControlStatement` later, shown when the
  ledger has a dividend payout (account 2898 cleared) or interest paid to a
  person in the year.
- TODO(legal-verify), narrowed: the remaining KU types in SFL 15–23 kap.
  (for example KU80/KU81 payments to or from abroad, KU70 royalty) apply in
  special situations only; nobody has checked which of them a small company
  in each of our industries can trigger. Ask a redovisningskonsult or
  Skatteverket's kontrolluppgifter team.

## 9. Bokslut (`DeadlineType::Closing`, always a recommendation)

There is **no legal date for closing the books**. BFL 6 kap. 1 § says the
year is closed with an årsredovisning (AB, ek. förening, HB with a legal
partner, larger companies), otherwise an årsbokslut (6 kap. 3 §); BFL 5 kap.
2 § requires bookkeeping "så snart det kan ske". The dates that follow from
it are the stämma, the auditor's six weeks and the income tax return.

Recommendation (product advice, `isRecommendation = true`, labelled
"Rekommendation, inte lagkrav"):

- AB / ek. förening: "Gör bokslutet" **three months** after the fiscal year
  ends, or six weeks before a planned stämma minus time for the auditor,
  whichever is earlier.
- Others: "Gör bokslutet" one month before the income tax return date.

## 10. Reminder semantics (product advice, not law)

- Show a date on Mina datum from **45 days** before (the 12th and 26th dates
  of one month then never surprise).
- Remind (e-mail/notification) **7 days** and **1 working day** before.
  For payments, phrase the 7-day reminder as "betala nu": SFL 62 kap. 2 §
  counts a payment when it is booked, and Skatteverket says a bank transfer
  can take up to three bank days.
- Bolagsverket and income tax: an extra reminder **30 days** before.
- After the date: show "Passerat" with a link to
  `/foretagsguiden/viktiga-datum/om-du-missar-ett-datum`; stop reminding.
- Every page and reminder says the date is computed from the company's
  settings and must be checked against Skatteverket's "Viktiga datum" (its
  e-services show the company's own dates).

## 11. Test vectors (2027)

| Case | Date |
|---|---|
| VAT month ≤ 40 MSEK, December 2026 | 2027-02-12 |
| VAT month ≤ 40 MSEK, November 2026 | 2027-01-18 (17 Jan is Sunday) |
| VAT quarter Q4 2026 | 2027-02-12 |
| VAT year, AB calendar 2026, electronic | 2027-08-17 |
| VAT year, EF 2026 | 2027-05-12 |
| VAT year, EF 2026 with EU trade | 2027-02-26 |
| AGI ≤ 40 MSEK, payment month December 2026 | 2027-01-18 (Skatteverket's table) |
| AGI > 40 MSEK, December 2026: pay / declare | 2027-01-18 / 2027-01-26 |
| F-skatt, January 2027 | 2027-01-18 |
| Income tax AB, year 2026, electronic / paper | 2027-08-02 / 2027-07-01 |
| Income tax EF, year 2026 | 2027-05-03 |
| Årsstämma AB, year 2026 | 2027-06-30 |
| Årsredovisning seven-month limit, year 2026 | 2027-07-31 (Saturday; see §0.1) |
| Periodisk sammanställning, January 2027, electronic / paper | 2027-02-25 / 2027-02-22 (20th is Saturday) |
| KU31, dividends 2026 | 2027-02-01 |

Broken fiscal year: AB, fiscal year 2026-05-01 – 2027-04-30, VAT base ≤ 40 MSEK.

| Case | Date |
|---|---|
| VAT year, no EU trade, electronic / paper | 2027-12-13 (12 Dec is Sunday) / 2027-11-12 |
| VAT year, with EU trade (26th, second month after) | 2027-06-28 (26 June is Saturday) |
| F-skatt series | 2026-06-12 … 2027-05-12, twelve payments (17th in Aug 2026 and Jan 2027) |
| Income tax, electronic / paper | 2027-12-01 / 2027-11-01 |
| Årsstämma limit | 2027-10-30 (Saturday; a limit for holding the meeting, not moved — §0.1, §0.2) |
| Årsredovisning seven-month limit | 2027-11-30 (§0.2) |
| Kostnadsränta-free extra payment, > 30 000 / ≤ 30 000 kr | 2027-06-14 (12 June is Saturday; interest from Tuesday 15 June, SFL 65 kap. 18 §) / 2027-09-03 |
| VAT quarter April–June 2027 (spans both fiscal years) | 2027-08-17, listed once |

Other cases (2026-11-04 review):

| Case | Date |
|---|---|
| AB, fiscal year 2025-10-01 – 2026-09-30: top-up recommendation (3 kap. 5 § → calendar year 2026) | 2027-02-12 |
| Ideell förening, calendar 2026, yearly VAT, no income tax return (33 §) | 2027-02-26, `legalVerify` |

TODO(legal-verify) marks in this file: §0.1 (Bolagsverket and the weekend
move), §3.3 (whether the company has F-skatt and its first month — a product
question), §8 (rare KU types). Settled 2026-11-04 and no longer flagged:
§0.2 (month counting, 1930:173 1 §), §1 and §4.1 (month ranges, SFL 3 kap.
5 §), §4.1 (ideell förening filing duty, SFL 30 kap. 4 §), §4.2 (HB date,
SFL 33 kap. 10 §). Show every date that depends on an open mark with
`legalVerify = true`.

## 12. Parameters that change by year

None of the deadline rules above is indexed. The numbers are fixed in law
until the law is amended, so they can be constants with the paragraph
named, not yearly data:

| Parameter | Value | Law |
|---|---|---|
| VAT base limit for the large-company dates | 40 000 000 kr | SFL 26 kap. 26 och 30 §§, 62 kap. 3–4 §§ |
| Interest-free kvarskatt until the 3rd of the fifth month | up to 30 000 kr | SFL 65 kap. 4 § |
| Quarterly periodisk sammanställning for goods | up to 500 000 kr per quarter | SFL 35 kap. 4 § |
| VAT period limits (year / quarter) | 1 000 000 / 40 000 000 kr | SFL 26 kap. 11 och 13 §§ |

What does change by year is **published data**, not rules: Skatteverket's
yearly AGI table (`agi.deadlines`, a cross-check only, §2), the holiday
calendar (computed by `SwedishHolidays`), and the fee amounts in the
articles (förseningsavgift, SFL 48 kap. and ÅRL 8 kap. 6–6 a §§), which the
engine does not use. Re-check this file when SFS amends SFL 26, 32, 35, 62 or
65 kap.
