# ADR-051: Företagsguiden, a public and personal guide to running a company

- Status: Proposed
- Date: 2026-11-04
- Task: stage 1 of Företagsguiden: the foundation and the start plan

## Context

The manual (ADR-036) explains how NONOX works. New company owners also need
to know how running a company works: how to register it, what VAT and
F-skatt mean, which dates matter, what the law requires of them and how much
of what they sell is not their money. Today they find that on many
authorities' sites, in their own order, and often too late.

A guide like this is only useful if it is right. Rules about tax, VAT and
company law change by year and differ by legal form and industry, and a
guide that invents or rounds a rule does harm. It also has to be honest
about what has been checked and by whom.

## Decision

1. **Content is Markdown in the repository**, like the manual:
   `docs/foretagsguiden/sv/avsnitt.yaml` names the sections,
   `docs/foretagsguiden/sv/<avsnitt>/<artikel>.md` holds one article each,
   and `docs/foretagsguiden/startplan.json` holds the start plan's steps.
   The contract for writers is `docs/foretagsguiden/SKRIVREGLER.md`.
2. **Every article has primary sources.** Front matter `kallor` lists at
   least one source on an allow-list of authorities and law texts
   (Skatteverket, Bolagsverket, verksamt.se, riksdagen.se, BFN and others in
   SKRIVREGLER.md, subdomains included, https only). `GuideValidator`
   enforces the allow-list, the required fields, the 200-character
   description, and that every legal form, industry and route an article or
   a step names exists, and that every step points at a written article. A
   test runs it against the real content, so content that breaks the rules
   cannot be merged. The allow-list in code is tested against SKRIVREGLER.md.
3. **Beta until a person has reviewed it.** Every public page and the start
   plan say: "Företagsguiden är i beta. Texterna är kontrollerade mot
   källorna men ännu inte granskade av en redovisningskonsult." `granskad`
   is the date an agent last checked the article against its sources and is
   shown as "Senast kontrollerad"; `mansklig_granskning` stays empty until a
   human accountant or lawyer has approved it. Open questions
   (`legal_verify`) are shown in a calm "Det här kontrollerar vi just nu"
   box, and the sentences they touch tell the reader to check.
4. **Public pages** at `/foretagsguiden`, `/foretagsguiden/<avsnitt>` and
   `/foretagsguiden/<avsnitt>/<artikel>` (`GuideController`, route names
   `guide.*`), in the manual's design language and rendered with the
   manual's Markdown (`ManualMarkdown`). Public while the public pages are
   on (`app.landing.enabled`), like the manual; a signed-in user can read
   them either way, because the start plan and the tips link to them. Listed
   in the sitemap, the public header's "Kom igång" menu and the footer.
   The start page is a product page, not a table of contents: a search
   over titles, descriptions and `sokord` (`?sok=`, on the server, ignoring
   case and diacritics), a "Vad driver du för företag?" picker for legal
   form and industry, the start plan for that choice as a numbered timeline
   (steps with a condition carry a tag such as "Om du är momsregistrerad"),
   the questions founders ask (`GuideQuestions`, each pointing at a written
   article, checked by a test), the sections and the stages still to come,
   labelled "Kommer" without dates. The choice is the query string
   (`?bolagsform=AB&bransch=cafe`), so the page is rendered on the server,
   works without scripts, can be shared, and the article links carry it.
   Amount columns in the guide's tables are right-aligned with tabular
   numbers (`GuideTables`); the manual's tables are left alone.
5. **A personal part for signed-in users**:
   - **Min startplan** (`/foretagsguiden/min-startplan`, `guide.plan`): the
     steps of the start plan that apply to the current company, by legal
     form, industry and condition (`vat_registered`, `not_vat_registered`,
     `employer_registered`), with progress, "Läs mer" and "Gör det i NONOX".
     Steps are shown in groups (Registrering, Pengar och skatt, Bokföring i
     NONOX, Avtal och skydd) by a mapping in code keyed by the step key
     (`StepGroups`), with "Fler steg" for a step that is not mapped, so the
     content format of startplan.json stays as it is. Steps are numbered in
     the order the groups show them, here and on the public timeline.
     The address is reserved three times: the validator refuses a section
     called `min-startplan`, the section and article routes refuse it as
     the section segment (with or without an article after it), and the
     controller answers 404 for it.
   - **Progress** is `company_guide_steps` (`CompanyGuideStep`, company
     scoped, unique per company and step key). The step key in
     startplan.json is stable forever.
   - **Who may tick a step**: members with `company:update` (owner and
     administrator). Everyone else in the company sees the plan read-only.
     The start plan is about the company itself (registration, F-skatt,
     VAT, employer registration), which is what `company:update` governs;
     bookkeepers and auditors should not mark the company's obligations as
     done. The actions `CompleteGuideStep` and `UncompleteGuideStep` audit
     `guide.step_completed` / `guide.step_reopened` with the step key only.
   - **Att göra**: a core todo provider (`GuideTodos`) shows "Fortsätt din
     startplan (X av Y klara)" to those who may tick steps, until every step
     is done or the company turns the guide off.
   - **Tips**: `<x-guide.tips />` at the foot of every page of the app shows
     up to three articles whose `rutter` include the page's route and that
     are written for the company's legal form and industry. The user can
     hide the tips for the current company ("Dölj tips", stored with
     `User::setPanelHidden('guide', …)` like the overview's "Kom igång"), and
     show them again from Min startplan.
6. **Company fields**: `teams.industry` (`App\Domain\Company\Industry`, with
   "Annan bransch"; every other case maps to a page in docs/branscher),
   `teams.started_on` and `teams.guide_enabled` (default on). The industry
   is asked for, optionally, when a company is created, and it and "Visa
   Företagsguiden" can be changed in the **Företagsguiden** section of the
   company's details page; both are audited with the existing
   `company.created` / `company.updated` entries. `started_on` is a column
   only: nothing reads it yet, so no form asks for it (data minimisation).
   Stage 2 (Mina datum) will ask for it when it uses it.
7. **Core, not a module.** The guide is not a feature a company buys or
   turns on: it is how NONOX explains a new company's obligations, it links
   to every part of the core, and its tips sit in the app layout. A company
   that does not want it turns it off with `guide_enabled`; there are no
   permissions to grant and no data to export, which is what the module
   framework (ADR-017) is for.
8. **Cheap to show.** The content is files, read once and cached in the
   file store under a hash of the files (as the manual), so the tips add no
   database query to a page, and Min startplan reads the company's progress
   with a fixed number of queries whatever it holds. Översikt's cached
   "Att göra" carries the guide's content version (`Guide::version()`) in
   its key, so an edited startplan.json gives a new count at once.

## Privacy

- The industry describes the company. For an aktiebolag or a förening it
  is company data; for an enskild firma, or a natural person who owns a
  handels- or kommanditbolag, it is also personal data about the owner
  (GDPR art. 4.1), processed for the company like the rest of its
  bookkeeping and recorded in docs/gdpr/registerforteckning.md and
  lagringstider.md. The privacy policy needs no change: it already covers
  the companies' data that NONOX processes on their behalf.
- Progress stores the company, the step key, the time and the user id of
  who ticked the step. No names or free text. Every tick and reopening is
  also an audit log entry, like every action: it holds the step key, and
  the audit log records who and when. The user id is set to null if the
  user is deleted.
- Hiding the tips is a per-user, per-company flag in `users.hidden_panels`,
  as the overview's panel already is.

## Stages

1. **Foundation and start plan** (this ADR): content rules and validation,
   public pages, Min startplan, the todo, tips, company fields.
2. **Mina datum**: a deadline engine for the company's own dates (VAT,
   employer declarations, income tax, annual report), a calendar and
   reminders (built; see the addendum below). The rules turned out to
   have no yearly parameters (REGLER.md §12).
3. **The VAT and tax set-aside box**: how much of what has been sold is VAT
   and preliminary tax, from the bookkeeping.
4. **Industry tracks**: deeper steps per industry (café, restaurant,
   crafts and others), with the industry pages.
5. **Growth**: hiring the first employee, changing legal form, financing.

## Consequences

- Writers get a strict contract; a missing source or a wrong route turns
  the suite red, which is deliberate.
- The guide's correctness depends on the review in stage 1's content work
  and on a human review before Beta is lifted. Until then the Beta notice
  stays on every page.
- The tip panel and the todo are visible on every company until it turns
  the guide off; the "Dölj tips" control keeps it from becoming noise for
  experienced users.

## Addendum: stage 2, Mina datum (2026-11-04)

### Decision

1. **A deadline engine in core** (`app/Domain/Deadlines`). `CompanyDeadlines::upcoming(company, from, months = 15)`
   asks one provider per kind of date (`Contracts\DeadlineProvider`) and
   sorts the answers. Each answer is a `Deadline`: a stable key (type and
   period, for example `vat:2026-09-30`), the type, a title, the date, the
   period, why it applies to this company, where to do it in NONOX, the
   guide article, the source, and two flags: `isRecommendation` (product
   advice, not law) and `legalVerify` (a rule that is not settled, shown as
   "Kontrollera datumet hos Skatteverket"). Nothing is stored: the dates
   are computed from the company's settings and fiscal years every time.
2. **The rules are `docs/foretagsguiden/datum/REGLER.md`**, written and
   checked against the law and the authorities by the content side, and
   implemented exactly, with its §11 test vectors as tests:
   - VAT keeps `VatDeadlines` (the spec verified it); a year ending on a
     day the law does not name counts as ending on the next named one (SFL
     3 kap. 5 §), so nothing is flagged.
   - Periodisk sammanställning only for periods with invoiced EU sales.
   - The employer declaration is computed from the law (SFL 26 kap. 26,
     30, 32 §§, 62 kap. 3 §), not from Skatteverket's yearly list, so it
     works for years the payroll rules do not cover yet and without the
     payroll module; a large employer gets the payment day and the
     declaration day as two dates. The payroll module's `AgiDeadlines` uses
     the same rule (`AgiDates`), with Skatteverket's list as a cross-check,
     so its AGI page has a date for every year and shows the payment day.
   - Preliminary tax monthly from the second month of the beskattningsår,
     flagged, because NONOX cannot know the decision; a top-up payment as a
     recommendation.
   - Income tax return (a handelsbolag's särskilda uppgifter on the same
     dates), årsstämma counted to the same day number (lag (1930:173) 1 §),
     the seven-month limit for the annual report (unmoved and flagged:
     whether Bolagsverket moves it past a weekend is open, §0.1) and "Gör
     bokslutet" as a recommendation.
   - Every date that the law lets move moves to the next working day,
     counting midsommarafton, julafton and nyårsafton (`Workdays`).
   No rule has a yearly parameter (REGLER.md §12), so there is no rules
   data file; the constants name their paragraph.
3. **Modules contribute through `Modules\Contracts\ProvidesDeadlines`**,
   like todos, asked only while the module is on. No module needs it
   today: the employer declaration is law, not payroll data.
4. **The page** is `/foretagsguiden/mina-datum` (`guide.dates`); the
   addresses `mina-datum` and `kalender` are reserved under the guide like
   `min-startplan` (`Guide::RESERVED`, the validator, both routes and the
   controller). It shows two weeks back and 15 months ahead.
5. **The calendar link** is a per-user, per-company ICS feed at
   `/foretagsguiden/kalender/{token}.ics`, the same pattern as the CRM's
   (ADR-049): a 48-character token of which only the SHA-256 is stored,
   shown once, replaced by a new link, revoked with "Stäng av länken" and
   when the user leaves the company, deleted with the account. A wrong,
   revoked or orphaned link, an archived company and a company that has
   turned the guide off all answer 404; misses are throttled harder. All-day
   events with a UID stable per company and deadline key, alarms 7 days and
   1 day before, no personal data beyond the company's name.
6. **Reminders**: two channels, both off when `guide_enabled` is off.
   - "Att göra": dates within 14 days (`DeadlineTodos`), except VAT and the
     employer declaration, which already have items that also know whether
     the period is reported.
   - In-app notices (the bell): `guide:send-deadline-reminders` runs every
     morning and sends 30 days before the income tax return and the annual
     report, 7 days before, and on the last working day before (REGLER.md
     §10), to the owner and members with `company:update` or
     `fiscal-years:manage`; recommendations get none. Each is recorded in
     `deadline_reminders`, unique per user, deadline, date and kind, so
     nothing is sent twice; a missed day is caught up while the date is
     ahead. The daily task digest e-mail was not used: it is opt-in, per
     assigned task, and sends nothing to users without tasks, so a
     database notice reaches the right people with less machinery. E-mail
     reminders can follow when the notification preferences allow it.
7. **Översikt** shows "Nästa datum", the next three dates, cached with the
   overview (its key carries `Guide::version()`), in a fixed number of
   queries.
8. **After the legal recheck (2026-11-04)**: a VAT or AGI period that is
   reported in NONOX (a VAT report; a submitted, accepted or corrected AGI
   file) gets no reminder and is shown as "Redovisad" (`DeadlineCompletion`);
   the page shows the next six months, all 15 with `?alla=1` (a plain link);
   a legal person's beskattningsår for preliminary tax follows SFL 3 kap.
   5 §; an ideell förening or stiftelse gets the earlier 33 § VAT date,
   flagged, with the 33 b § date named; EU trade also counts box 22
   (flagged in code); a handelsbolag gets the question whether a legal
   person is a partner, as a flagged recommendation; recommendations never
   fall on a non-working day; the authority to check with is named per date.
   **Not handled, by decision**: e-mail reminders; a setting for a
   legal-person partner or for an auditor ("skicka årsredovisningen till
   revisorn"); kontrolluppgifter (KU31, KU20); the first F-skatt month and
   other decisions that apply to one company. The manual article lists them
   under "Mina datum visar inte".
9. **After the code review**: the AGI group has one source, the company
   (`AgiDates::isLarge`: VAT registered and turnover above 40 million
   kronor, SFL 26 kap. 32 §), read by Mina datum, the feed, reminders,
   preliminary tax, the AGI page and Mina företag, tested as a matrix. The
   payroll setting `agi_deadline_group` is kept but only shown; a stored
   large group the company does not support gets a notice on the AGI page
   until someone acknowledges it, which aligns the setting (no silent
   change). Mina datum shows a friendly page and makes no link while the
   guide is off; the reminder command walks companies in chunks and writes
   record and notice in one transaction; Översikt leaves reported periods
   out; a large employer's payment date is never "done" by the declaration;
   the AGI link needs the payroll module; one active link per user and
   company (partial unique index); the feed has an ETag; stopped links are
   pruned after 30 days.
10. **The start date stays hidden.** REGLER.md needs `started_on` only for a
   hint about the first F-skatt payment, which the engine does not compute
   (it shows the series from the fiscal year's second month, flagged). The
   column remains for a later stage.
11. **The page teaches (2026-11-04, after the owner's own use).** An AB with
   quarterly VAT read the monthly F-skatt rows as VAT. The engine was right;
   the page did not explain. Now: "Ditt år i korthet" (`YearAtAGlance`) gives
   one line per kind of date with its frequency, the setting that decides it
   and the next date; every row has a kind chip (`DeadlineCategory`: icon and
   word, one colour per kind) and says payment, declaration or both
   (`DeadlineAction`); titles start with the verb ("Betala F-skatt för …",
   "Deklarera och betala moms för …") and calendar events and reminders are
   prefixed with the kind ("Moms: …"); "Vad är det här?" on the first row of
   each kind; a flag or a reason that repeats in a series is shown once (the
   F-skatt decision note moves to Ditt år and the first month); `?visa=`
   filters by kind as GET links that combine with `?alla=1`; a "Så läser du
   sidan" panel remembered closed per user (`hidden_panels`). The explaining
   texts live in `DeadlineCategory` and `YearAtAGlance` only, with facts from
   REGLER.md and the viktiga-datum articles. Filters run in PHP over one
   engine call, so every filter makes the same queries.

### Privacy

The calendar link is personal data about the user who made it (a hash of
the token, when it was made and last used); the reminders record which user
was told about which deadline and when. Both are deleted with the account
and with the company, and recorded in docs/gdpr/registerforteckning.md and
lagringstider.md. The feed itself holds only the company's name and its
dates.

## Addendum: stage 3, Momsbufferten (2026-11-05)

### Decision

1. **"Pengar som inte är dina"** answers how much of the money in the bank
   is not the company's, from the books only. `App\Domain\Guide\MoneyNotYours`
   reads balances from posted vouchers dated up to today plus opening
   balances: bank and cash 1900–1999 (as Översikt's key figure), the VAT
   accounts the settlement moves to 2650 (`VatBoxes::isSettled`, "ännu inte
   redovisad"), 2650 itself ("redovisad men inte betald"), 2710/2730/2731 for
   an employer, and 1630. A fiscal year without opening balances yet is read
   from the latest year that has them, so the money is there before the
   year-end rollover. An account the chart lacks is left out, never shown as 0.
   Read only: it books, changes and logs nothing.
2. **Kvar efter moms och skatter** = bank + skattekontot − VAT to pay −
   employer liabilities − coming preliminary tax. VAT to get back is shown
   but not counted, because it is not in the bank yet; money already on the
   tax account pays the taxes, and a debt there adds to them.
3. **Debiterad preliminärskatt is never computed.** It is not in the books
   until it is booked, and only Skatteverket's decision states it. The
   company may enter the monthly amount (`teams.preliminary_tax_monthly_ore`,
   nullable, audited with the company details); then the payments from today
   up to the next VAT date are counted, dated by the deadline engine.
4. **Per declaration**, Mina datum and the card show the VAT of each period
   whose date is ahead, computed as the VAT report does (`VatReturn`, box 49),
   "hittills" for a running period, rounded to hundreds ("ca").
5. **Where and who**: a card on Översikt (cached with the overview, a fixed
   number of queries) and a section on Mina datum (`#momsbufferten`) with
   "Så räknar vi" listing every account and balance, the date of the figures,
   the last posted voucher and the note that unbooked invoices and receipts
   are not included. Only with Företagsguiden on, and only for users with
   `reports:view`. When the VAT to pay is more than the bank balance, a calm
   note links `moms-och-skatt/satt-undan-pengar`.
