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
5. **A personal part for signed-in users**:
   - **Min startplan** (`/foretagsguiden/min-startplan`, `guide.plan`): the
     steps of the start plan that apply to the current company, by legal
     form, industry and condition (`vat_registered`, `not_vat_registered`,
     `employer_registered`), with progress, "Läs mer" and "Gör det i NONOX".
     The address is reserved: no section may be called `min-startplan`, and
     the route is registered before the section route.
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
   `teams.started_on` and `teams.guide_enabled` (default on). Industry and
   start date are asked for, optionally, when a company is created and can
   be changed with "Visa Företagsguiden" on the company's details page. All
   three are audited with the existing `company.created` /
   `company.updated` entries.
7. **Core, not a module.** The guide is not a feature a company buys or
   turns on: it is how NONOX explains a new company's obligations, it links
   to every part of the core, and its tips sit in the app layout. A company
   that does not want it turns it off with `guide_enabled`; there are no
   permissions to grant and no data to export, which is what the module
   framework (ADR-017) is for.
8. **Cheap to show.** The content is files, read once and cached in the
   file store under a hash of the files (as the manual), so the tips add no
   database query to a page, and Min startplan reads the company's progress
   with a fixed number of queries whatever it holds.

## Privacy

- The industry and the start date are company data, not personal data:
  they describe the business, like the legal form.
- Progress stores the company, the step key, the time and the user id of
  who ticked the step. No names or free text, and the audit entry holds the
  step key only. The user id is set to null if the user is deleted.
- Hiding the tips is a per-user, per-company flag in `users.hidden_panels`,
  as the overview's panel already is.

## Stages

1. **Foundation and start plan** (this ADR): content rules and validation,
   public pages, Min startplan, the todo, tips, company fields.
2. **Mina datum**: a deadline engine for the company's own dates (VAT,
   employer declarations, income tax, annual report), a calendar and
   reminders. Amounts and thresholds that change by year move into data
   with a validity year.
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
