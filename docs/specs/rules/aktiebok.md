# Rule sheet: ADR-070 share register and boardroom (aktiebok och styrelserum)

Researcher pass, 2026-11-08. Input for the builder of ADR-070. Not legal advice; the open
questions at the end go to a human lawyer before any `unverified` flag in
`database/data/boardroom/rules.json` is cleared.

## Sources actually read

| Id | Source | Version read | How |
|---|---|---|---|
| ABL | Aktiebolagslag (2005:551) — https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/aktiebolagslag-2005551_sfs-2005-551/ | Consolidated "Ändrad: t.o.m. SFS 2026:783" | Full text from `https://data.riksdagen.se/dokument/sfs-2005-551.text` (Regeringskansliet/Lagrummet), read locally |
| ABF | Aktiebolagsförordning (2005:559) — `https://data.riksdagen.se/dokument/sfs-2005-559.text` | t.o.m. SFS 2026:1833 | Full text |
| ÅRL | Årsredovisningslag (1995:1554) — `https://data.riksdagen.se/dokument/sfs-1995-1554.text` | t.o.m. SFS 2026:780 | Full text, 8 kap. 3 § only |
| DSL | Lag (2018:218) med kompletterande bestämmelser till EU:s dataskyddsförordning — `https://data.riksdagen.se/dokument/sfs-2018-218.text` | current | 3 kap. 10 § only |
| SOU | SOU 2026:26 *Digitala verktyg inom bolagsrätten* — https://regeringen.se/contentassets/3dc359ad60b0456aa99ded7d01621811/sou_2026_26_pdf-a_webb.pdf | Delbetänkande, a **proposal, not law** | PDF converted to text, Sammanfattning and §§ on sole owner and 15 working days |
| IMY | IMY remissvar on SOU 2026:26, dnr IMY-2026-9380, 2026-06-26 — https://www.imy.se/globalassets/dokument/remissvar/2026/digitala-verktyg-inom-bolagsratten.pdf | — | PDF converted to text |

**Fetches that failed (gaps not filled from memory):**

- Every bolagsverket.se page and PDF (aktiebok page, "Ändra bolagsordning i aktiebolag", "Ändra
  styrelse …", fee pages, form 864) returned a human-verification CAPTCHA to WebFetch and
  `curl` failed (HTTP 000). Nothing in this sheet is taken from Bolagsverket's pages. Fees are
  taken from ABF 3 kap. 1 § instead. Bolagsverket's own guidance (e-service names, the
  aktiebok template, processing times) is **unverified** and must be read by a human.
- Konkurrensverket's letter on aktieböcker (dnr 24-0181) returned HTTP 403.
- IMY's general page on personnummer did not contain the text; DSL 3 kap. 10 § was read
  directly instead.

Note on two amendments in the consolidated text with a future start date: ABL 7:40 and 4:47
get new wording from 2026-12-05 (SFS 2026:783); the changes concern public/MTF-traded
companies (7:59 a, MTF platforms) and do not change the rules below for a private AB.

## Classification used

- **HARD** — NONOX can enforce it in code (validation, block, constraint).
- **ADVICE** — NONOX computes or shows it, the user decides (dates, reminders, warnings).
- **OUT** — out of scope for ADR-070 (avstämningsbolag, public listed AB, etc.).

---

## A. The register itself (ABL 5 kap., private AB that is not avstämningsbolag)

**AB-R1 Duty and purpose.** Every AB has a share register. It is the basis for exercising
shareholder rights and for judging ownership.
- ABL 5 kap. 1 §: "I ett aktiebolag skall det finnas en aktiebok."
- Class: ADVICE (module is optional per ADR-070 §1; NONOX cannot force a company to use it).
- Test hint: none (documentation).

**AB-R2 Form.** The register is kept by automated processing; a non-avstämningsbolag may also
keep it in a bound book or a secure loose-leaf/card system.
- ABL 5 kap. 2 §: "Aktieboken skall föras med automatiserad behandling."
- Class: HARD (NONOX is automated processing; that satisfies the form rule).
- Note: NONOX keeping the register is lawful in form. If a company also keeps a paper book,
  the two may diverge — that is the risk ADR-070 §1 already names.

**AB-R3 Content per share (the core data model).** For a company that is not avstämningsbolag
the register must hold, for each share:
1. the share's number;
2. the owner's name, **and personnummer, organisationsnummer or other identification number**,
   and postal address;
3. the share class, if the company has more than one class;
4. **whether a share certificate (aktiebrev) has been issued**;
5. where relevant, that the share is subject to a förbehåll under 4 kap. 6 § (omvandling),
   8 § (samtycke), 18 § (förköp), 27 § (hembud) or 20 kap. 31 § (inlösen).
Shares are listed in number order.
- ABL 5 kap. 5 §: "aktieägarnas namn och personnummer, organisationsnummer eller annat identifieringsnummer samt postadress"; "Aktierna skall tas upp i nummerföljd."
- Class: HARD (required fields; ordering of the printed register).
- Test hints:
  - a natural-person holder without personnummer AND without another identification number
    is rejected; a foreign person with a passport/foreign id number is accepted ("annat
    identifieringsnummer");
  - a legal-entity holder requires an organisationsnummer or other id;
  - postal address is required for every holder;
  - with one class only, the class column may be omitted in output; with A and B classes,
    every share range must carry a class;
  - every share range carries `share_certificate_issued` (bool);
  - a print of shares 1–500 is sorted by share number, not by holder name.

**AB-R4 Rights holders (testamentary usufruct etc.).** Where 4 kap. 43 § applies (a person who
by will has the right of use or the yield of a share together with the right to represent
it), both the owner and the rights holder are entered with name, id number and postal
address, plus what applies to the right; changes or termination are noted when shown.
Further: a god man for a future owner (FB 11:3 p.5) and a fund manager in place of
unit-holders are entered as described.
- ABL 5 kap. 6 §: "ska såväl aktieägaren som rättighetsinnehavaren föras in i aktieboken".
- Class: HARD for the data model (a share range may carry an optional rights holder and a
  note); the legal assessment is the user's.
- Test hint: a TRANSFER event cannot silently drop an existing rights-holder note; the note
  must be ended by its own event.

**AB-R5 Dated entries.** Every entry and annotation is dated, unless the time follows from
other available material.
- ABL 5 kap. 9 § third stycke: "Införingar och anteckningar i aktieboken skall dateras".
- Class: HARD — every `share_events` row has an entry date (`recorded_at`) in addition to the
  legal `effective_date`. Keep both; they differ (e.g. a transfer agreed 2026-10-01, entered
  2026-10-05).
- Test hint: an event without `recorded_at` cannot be stored.

**AB-R6 Entering an acquirer.** When someone shows a share certificate or otherwise proves
the acquisition, the board (or someone it authorised) shall **without delay** enter them as
owner; changes reported by a shareholder are also noted without delay. If a certificate
exists, the entry date is written on the certificate.
- ABL 5 kap. 9 §: "skall styrelsen eller den som styrelsen har bemyndigat genast föra in denne".
- Class: HARD for the authorisation (only `share-register:manage`); ADVICE for "genast"
  (NONOX can show the age of a pending transfer, not enforce speed).
- Test hint: a user without `share-register:manage` gets 403 on TRANSFER; the PDF for a
  certificate-holding range reminds the user to annotate the certificate.

**AB-R7 Pre-emption and redemption notes.** A notice of intended transfer under a förköps-
förbehåll, a claim to exercise förköp, and a court action about förköp must each be noted in
the register with the date of the notice. The company must on request certify such notes for
the shareholder's shares.
- ABL 4 kap. 20 § and 21 §: "skall genast antecknas i aktieboken med uppgift om dagen för anmälan"; 4 kap. 23 § (intyg).
- Class: HARD (event kinds for these notes) — **missing from ADR-070** (see §G).
- Test hint: a `PRE_EMPTION_NOTICE` dated 2027-02-10 appears on the printed register for the
  affected range until a later event resolves it.

**AB-R8 Who is responsible.** The board is responsible for keeping, preserving and making the
register available.
- ABL 5 kap. 7 §: "styrelsen ansvarig för att aktieboken förs, bevaras och hålls tillgänglig".
- Class: ADVICE — the UI must not suggest NONOX or the accounting firm takes over the
  board's responsibility (ADR-070 owner decision 2: the firm may keep it *on behalf of* the
  client; responsibility stays with the board).

**AB-R9 When the register starts.** The register is drawn up as soon as all founders have
signed the memorandum of association; subscribed shares are entered at once.
- ABL 5 kap. 8 §: "Aktieboken skall upprättas så snart samtliga stiftare har undertecknat stiftelseurkunden."
- Class: ADVICE (ADR-070's "without delay" wording is slightly off; the trigger is the
  founders' signature). A FORMATION event's effective date should be the stiftelseurkund
  date, which may be earlier than registration at Bolagsverket.
- Test hint: FORMATION dated 2026-03-02 with company registered 2026-03-20 is valid.

**AB-R10 Register at a given date must equal the event fold.** (ADR-070 §2 design; supported
by 5:1 purpose and 5:5 per-share content.) No share may be held by two owners at once, every
share lies inside the issued ranges.
- Class: HARD (design invariant, not a statute quote).
- Test hints: shares 1–1000 issued; transfer 501–1000 A→B on 2027-01-15; register at
  2027-01-14 shows A 1–1000; at 2027-01-15 shows A 1–500, B 501–1000; a transfer of
  900–1100 is rejected (1001–1100 not issued); a second transfer of 600–700 from A after
  2027-01-15 is rejected (A no longer holds them).

**AB-R11 The company can hold its own shares.** Fractional surplus shares at a split pass to
the company on registration; a company can also acquire own shares (19 kap.).
- ABL 4 kap. 50 §: "övergår överskjutande aktier i bolagets ägo".
- Class: HARD — the holder model must allow "the company itself" as holder.
- Test hint: a reverse split 3:1 of 1000 shares leaves holder X with 334 old → 111 new + 1
  surplus old share that moves to the company.

## B. Retention (question 2)

**AB-R12 Retention of the register and of removed data.**
- The register is kept **as long as the company exists and at least ten years after it is
  dissolved**.
- If kept by automated processing, **data removed from the register must be kept for at
  least ten years** (from removal). It may be kept in readable or machine-readable form.
- When moving from a paper register to automated processing, the old paper register is kept
  at least ten years after all shares were entered in the new one.
- ABL 5 kap. 3 §: "bevaras så länge bolaget består och under minst tio år efter bolagets upplösning"; "uppgifter som har tagits bort ur aktieboken bevaras i minst tio år".
- Class: HARD. The append-only event log already exceeds this (nothing is removed). The
  rule matters for (a) GDPR erasure requests — refuse erasure of register data within the
  period; (b) company archiving/deletion (AGENTS.md §3.5) — the register must survive in the
  archive package for ≥10 years after dissolution.
- Test hints: holder X superseded on 2026-11-08 → personal data of X in that event may not be
  erased before 2036-11-08; a company dissolved 2030-06-30 keeps its register to at least
  2040-06-30. Note this is **longer than BFL's 7 years** for räkenskapsinformation — the
  retention date for register data must not be derived from the fiscal-year rule.

## C. Public access and personal numbers (questions 1 and 3)

**AB-R13 Access for anyone, at the company.** In a non-avstämningsbolag the register shall be
kept available **at the company for everyone who wants to see it**. If it is kept by
automated processing, the company shall give anyone who asks the opportunity **to see, at the
company, a current print-out or other current representation** of the register.
- ABL 5 kap. 10 §: "hållas tillgänglig hos bolaget för alla som vill ta del av den".
- Class: ADVICE + HARD feature: NONOX must be able to produce a **current** (today's date)
  full representation on demand. ADR-070's "5 kap. 15 §" reference is wrong — 5:15 is
  rösträttsregistrering in avstämningsbolag; the correct paragraph is 5:10.

**Answer to question 1 (content, personnummer).** Yes — for a non-avstämningsbolag the law
requires the register to contain each owner's **personnummer, organisationsnummer or other
identification number** (5:5 p.2). Personnummer is one of three alternatives, so for a
Swedish natural person in practice the personnummer; for a person without one, another id
number. The full field list is AB-R3.

**Answer to question 3 (copy for "var och en").** For a **non-avstämningsbolag** the law
gives anyone a right to **inspect at the company** ("ta del av … hos bolaget"); it does **not**
give a right to receive a copy. The right to a print-out "mot ersättning" ("Var och en som
begär det har rätt att mot ersättning … få en aktuell utskrift") exists only for
**avstämningsbolag** (5:19 second stycke), which also may omit holders of ≤500 shares
(5:19 third stycke). Consequences:
- What the person inspects is "aktieboken" — the law makes no exception for personnummer in
  the inspection right of 5:10. Showing a version without personnummer for inspection is
  therefore **not clearly compliant**; whether a masked view satisfies 5:10 is open
  (lawyer question L1).
- Handing over a copy is voluntary for a non-avstämningsbolag. When the company does hand
  over a copy, data minimisation (GDPR art. 5.1 c) and DSL 3 kap. 10 § ("endast när det är
  klart motiverat") argue for a copy **without** personnummer unless there is a reason.
- SOU 2026:26 (proposal only, aktiebok parts proposed in force 2027-07-01) concerns **only
  avstämningsbolag** and leaves the question of limiting personnummer/addresses in digital
  copies outside its remit ("Den frågan ligger dock utanför uppdragets ram"). IMY (2026-06-26)
  agrees that limiting public personal data in digital provision "behöver analyseras vidare".
  So there is **no legal rule today** that allows or requires masking for either kind of AB.

**AB-R14 Two print versions (refines ADR-070 §6).**
- Version "Fullständig (för granskning hos bolaget)": all 5:5 fields incl. personnummer,
  dated today, every share in number order. Used for 5:10 inspection and for 7:28 at a
  general meeting (AB-R27).
- Version "Utdrag utan personnummer (för utlämning)": same, personnummer replaced by birth
  date or omitted; organisationsnummer kept (not personal data for an AB — IMY, see sources).
- Class: HARD feature, ADVICE wording. Both prints log who printed which version (ADR-068).
- UI text must not say the masked copy fulfils the 5:10 duty.
- Test hints: print at 2027-03-31 equals the register fold at 2027-03-31; masked print of a
  natural person shows no 10/12-digit personnummer string (regex test on the PDF text);
  org numbers still present.

**AB-R15 GDPR controller and basis.** The company is controller for the register's personal
data. Processing personnummer needs to be clearly justified (DSL 3:10); here the law itself
requires the field, which is the justification for storing it, not for spreading it.
- ABL 5 kap. 4 §: "Bolaget är personuppgiftsansvarigt för den behandling … som förandet av aktieboken innebär."
- DSL 3 kap. 10 §: "får behandlas utan samtycke endast när det är klart motiverat".
- Class: HARD (encrypted cast for personnummer, as ADR-070 says; personnummer never in the
  audit log payload, never in a URL, never in a notification text).
- NONOX (hosted) is a processor for the company. Record this in
  `docs/gdpr/registerforteckning.md`. Legal basis art. 6.1 c, as ADR-070 states — confirmed
  in principle by SOU 2026:26 ("nödvändig för att fullgöra en rättslig förpliktelse").

**AB-R16 Share certificate content (if NONOX prints one).** A certificate lists, among other
things, company name, org number and category, and the owner's name and personnummer/org
number/other id.
- ABL 6 kap. 2 §. A company issues certificates only if a shareholder asks (6 kap. 1 §).
- Class: OUT for v1 (ADR-070 does not print certificates) but the register must record
  whether one was issued (AB-R3 p.4).

## D. General meetings (question 4)

**AB-R17 Annual general meeting deadline.** Within six months from the end of each fiscal
year the shareholders hold an annual meeting where the board presents the annual report and
the audit report.
- ABL 7 kap. 10 §: "Inom sex månader från utgången av varje räkenskapsår ska aktieägarna hålla".
- Class: ADVICE (reminder in Mina datum; `DeadlineType::AnnualMeeting` already says "Inom sex
  månader efter räkenskapsårets slut", consistent).
- Test hints: FY 2026-01-01–2026-12-31 → last day 2027-06-30; FY 2026-07-01–2027-06-30 → last
  day 2027-12-31; FY ending 2027-04-30 → 2027-10-30 or 2027-10-31? (month-end counting is
  lawyer question L5; until then show 2027-10-30 as the conservative date).

**AB-R18 Mandatory AGM decisions.** The AGM decides on (1) adoption of income statement and
balance sheet, (2) allocation of profit or loss under the adopted balance sheet, (3)
discharge of the board members and the CEO, (4) other matters required by law or articles.
- ABL 7 kap. 11 §: "om fastställelse av resultaträkningen och balansräkningen".
- Class: HARD for the pre-filled agenda (ADR-070 §4 matches) — items 1–3 cannot be removed
  from an AGM agenda template; ADVICE otherwise. Board fees are decided by the stämma
  (8 kap. 23 a §) — a common extra item.
- Test hint: AGM agenda created from `YearEndClosing` contains three numbered items in that
  order plus "val" items only if the board/auditor term ends (AB-R33).

**AB-R19 Documents available before the AGM.** The board keeps the annual report and audit
report available for shareholders **at least two weeks before** the AGM and sends copies free
of charge to any shareholder who asks and gives a postal address.
- ABL 7 kap. 25 §: "under minst två veckor närmast före årsstämman".
- Class: ADVICE. Test hint: AGM 2027-05-20 → documents available no later than 2027-05-06.

**AB-R20 Notice period — AGM (ordinarie stämma).** Notice issued **no earlier than six weeks
and no later than four weeks** before the meeting. A private AB's articles may allow notice
**later, but no later than two weeks** before. Not for public AB.
- ABL 7 kap. 18 §: "tidigast sex veckor och senast fyra veckor före bolagsstämman".
- Class: ADVICE (rules.json values, unverified until lawyer confirms day-counting L4).
- Test hints: meeting 2027-05-20 → earliest 2027-04-08, latest 2027-04-22; with the articles
  clause `notice_min_weeks = 2` → latest 2027-05-06. A notice dated 2027-04-07 is flagged
  "för tidigt"; 2027-04-23 flagged "för sent" (without clause).

**AB-R21 Notice period — extra stämma that will consider an articles amendment.** Same as
AGM: 6 weeks earliest, 4 weeks latest; private AB articles may allow down to 2 weeks.
- ABL 7 kap. 19 §: "tidigast sex veckor och senast fyra veckor före bolagsstämman".
- Class: ADVICE. Test hint: an extra meeting whose agenda has an item flagged
  `amends_articles = true` uses 6/4 weeks, else AB-R22.

**AB-R22 Notice period — other extra stämma.** No earlier than six weeks, no later than
**two weeks** before.
- ABL 7 kap. 20 §: "tidigast sex veckor och senast två veckor före bolagsstämman".
- Class: ADVICE. Test hint: meeting 2027-09-15 → earliest 2027-08-04, latest 2027-09-01.

**AB-R23 Continued meeting.** A meeting may decide to continue on a later day. If the AGM's
items 1–3 are deferred (by decision or at the request of owners of ≥1/10 of all shares), the
continued meeting is held **4–8 weeks** later, no further deferral. If items 1 or 2 are
deferred, the board reports this for registration **within four weeks**. A continued meeting
4 weeks or more after the first day needs a new notice with the extra-meeting periods.
- ABL 7 kap. 14 § ("minst fyra veckor och högst åtta veckor därefter"), 7 kap. 21 §.
- Class: ADVICE; the reporting duty raises a Mina datum reminder (Bolagsverket).
- Test hint: AGM 2027-05-20 defers adoption → continued AGM between 2027-06-17 and
  2027-07-15; registration reminder due 2027-06-17.

**AB-R24 Extra meeting on demand.** The board must call an extra meeting if an auditor or
owners of ≥1/10 of all shares request it in writing; notice within **two weeks** of receipt.
- ABL 7 kap. 13 §: "Kallelsen skall i så fall utfärdas inom två veckor".
- Class: ADVICE. HARD part: NONOX can compute "≥ 1/10 of all shares" from the register.
- Test hint: 10 000 shares, request from holders of 1 000 → qualifies; 999 → does not;
  received 2027-03-01 → notice by 2027-03-15.

**AB-R25 Shareholder's right to add an item.** A written request reaching the board no later
than one week before the earliest notice date must be put on the agenda (or later if still
possible).
- ABL 7 kap. 16 §: "senast en vecka före den tidpunkt då … kallelse tidigast får utfärdas".
- Class: ADVICE. Test hint: AGM 2027-05-20 → earliest notice 2027-04-08 → request deadline
  2027-04-01.

**AB-R26 Notice method (allowed ways).** Shareholders are called **in the way the articles
state** (the articles must state it, 3 kap. 1 § p.9). In addition, notice must be **sent by
post** to every shareholder with a known postal address when (1) an AGM is held at another
time than the articles say, or (2) the meeting will consider an articles change under
7 kap. 43–45 §§ (restrictions on transfer, changes to rights between shares, etc.),
liquidation, the liquidator's final accounts, or ending a liquidation. Public AB have extra
rules (PoIT + national daily, 7 kap. 56 §) — OUT.
- ABL 7 kap. 23 §: "på det sätt som anges i bolagsordningen. Kallelse ska även skickas med post".
- Class: HARD data requirement (store the articles' notice method as structured text);
  ADVICE (warn when the agenda triggers the post requirement).
- NONOX must not send a notice by e-mail unless the stored articles allow e-mail; it should
  show the stored method next to the send button.
- Test hint: agenda item `kind = ARTICLES_43_45` → banner "Kallelsen ska också skickas med
  post"; an articles method of "brev" → e-mail send disabled, PDF print offered.

**AB-R27 Notice content.** Time and place (or how to take part digitally), the conditions
under 7:2 for participating, a proposed agenda with **numbered** items, the main content of
every proposal except minor matters, and for an articles change always the main content.
Special content rules apply to issues, dividends, reductions etc. (7:24 list).
- ABL 7 kap. 24 §: "Ärendena ska vara numrerade."
- Class: HARD for numbered agenda and time/place present; ADVICE for "huvudsakliga innehåll".
- The agenda numbering may not be changed at the meeting (7 kap. 31 §) — HARD: a signed
  notice locks item numbers; the minutes reuse them.

**AB-R28 Place of meeting.** At the board's seat unless the articles allow another place in
Sweden or digital, or extraordinary circumstances.
- ABL 7 kap. 15 §. Class: ADVICE (show seat from company data; warn if place differs and no
  articles clause is stored).

**AB-R29 Register at the meeting.** At the meeting the **whole** register is kept available
to shareholders; for an automated register, a print-out of the whole register **as of the day
of the meeting**.
- ABL 7 kap. 28 § second stycke: "Framställningen ska avse förhållandena på dagen för stämman."
- Class: HARD feature — "Aktiebok för stämma" print = full version (AB-R14) dated the meeting
  date; attached to the meeting record.
- Right to attend = entered in the register on the meeting day (7 kap. 2 §) — the voting list
  is pre-filled from the register at that date.

**AB-R30 Voting list.** A list of present shareholders, proxies and advisers with shares and
votes each represents, approved by the meeting; postal voters count as present.
- ABL 7 kap. 29 §. Class: HARD feature (computed from register + attendance, votes from
  `share_classes.votes_per_share`). Proxies need a written, signed and dated proxy (7 kap.
  3 §) — store as an attachment.
- Test hint: A-shares 10 votes, B-shares 1 vote; holder with 100 A + 50 B → 1 050 votes.

**AB-R31 Articles amendment majority.** Valid with ≥2/3 of both votes cast and shares
represented, unless 7:43–45 require more (e.g. unanimity of those present representing ≥9/10
of all shares when transfer restrictions are introduced).
- ABL 7 kap. 42–45 §§. Class: ADVICE (NONOX may compute the threshold, the meeting decides).
- Test hint: represented 900 shares/1 400 votes, yes 600/950 → 2/3 of shares = 600 ✓, 2/3 of
  votes = 933.3 → 950 ✓ → valid under 42 §.

## E. Minutes (question 6)

**AB-R32 General meeting minutes — content and checking.** The chair ensures minutes are
kept, recording date and place (or that it was digital) and the decisions; for votes, what
was proposed and the outcome. The voting list is included or attached. Signed by the
minute-taker; **checked (justeras) by the chair** (if not the minute-taker) **and at least one
person appointed by the meeting**. No checker is needed if chair and/or minute-taker alone
represent all shares.
- ABL 7 kap. 48 §: "justeras av ordföranden … och av minst en justerare som bolagsstämman har utsett".
- Class: HARD (status `checked` requires the chair + ≥1 justerare, unless the
  sole-owner exception applies, computed from the voting list).
- ADR-070 cites 7:49 for this; the content and checking rule is **7:48**.
- Test hints: sole owner is chair and minute-taker → can sign with no justerare; two owners
  50/50, owner A chair, owner B minute-taker → no justerare needed (together all shares);
  chair + minute-taker together 60 % → one justerare required.

**AB-R33 General meeting minutes — availability and storage.** **No later than two weeks
after** the meeting the minutes are kept available at the company for shareholders; a copy is
sent to a shareholder who asks and gives a postal address. Minutes are stored securely.
- ABL 7 kap. 49 §: "Senast två veckor efter bolagsstämman ska protokollet hållas tillgängligt".
- Class: ADVICE (reminder: meeting 2027-05-20 → minutes ready by 2027-06-03) + HARD (secure
  storage: locked PDF + hash, ADR-070 §4).

**AB-R34 Board minutes.** Minutes are kept at every board meeting, recording the decisions;
signed by the minute-taker, checked by the chair (if not minute-taker) and, if the board has
several members, also by a member the board appoints. Members and the CEO may have a
dissenting opinion recorded. Board minutes are kept **in number order** and stored securely.
In a one-shareholder company, agreements between owner and company (other than ordinary
business on usual terms) are recorded in or attached to the board minutes.
- ABL 8 kap. 24 § ("justeras även av en ledamot som utses av styrelsen"), 8 kap. 25 §, 8 kap. 26 § ("föras i nummerföljd").
- Class: HARD — board minutes get a gapless sequence number per company (like vouchers; no
  reuse after a draft is deleted? → decide: number on signing, not on draft, to stay gapless);
  a single-member board needs no second checker; a "reservation" field per member.
- **Missing from ADR-070**: numbering (8:26), dissent (8:24 2nd st.), sole-owner agreements
  (8:25).
- Test hints: three signed board minutes → numbers 1, 2, 3; a deleted draft does not consume a
  number; board of one → status `checked` without a second checker.

**AB-R35 Board quorum and majority (for the minutes UI).** Quorum: more than half of all
members (or higher per articles), conflicted members count as absent; decisions need more
than half of those present, chair has a casting vote; if the board is not full, the yes-votes
must exceed one third of all members.
- ABL 8 kap. 21–23 §§. Class: ADVICE (warn, never block).
- Test hint: board of 5, 3 present, one conflicted → not quorate (2 of 5).

**AB-R36 Signing minutes electronically — answer to question 6.** The law has a general rule:
a document under ABL that must be **signed** may, unless otherwise stated, be signed with an
**advanced** electronic signature (eIDAS art. 3).
- ABL 1 kap. 13 §: "får … undertecknas med en sådan avancerad elektronisk underskrift".
- Minutes must be "undertecknas" (7:48, 8:24). So the law expressly accepts an **advanced**
  electronic signature. It says nothing about a **simple** one. ADR-046's name-signature flow
  is described in ADR-046 itself as a simple electronic signature.
- Conclusion: **a simple electronic signature is not shown to be sufficient** for the
  minute-taker's signature on general-meeting or board minutes. Whether "justeras" is also
  "undertecknas" in the sense of 1:13 is not stated (lawyer question L2).
- Class: HARD recommendation for the builder: do not label a minute "Signerat" on a
  simple signature. Either (a) support an advanced signature (e.g. a BankID-based signing
  service — needs its own ADR and agreement), or (b) let the user upload a scanned or
  externally signed PDF and mark it signed, keeping the NONOX name-signature only as
  "Godkänt i NONOX" (approval, not signature).
- Test hint: a minute whose only signature is an ADR-046 name-signature cannot reach status
  `signed`; it can reach `approved`.

## F. Reporting to Bolagsverket (question 5)

All "genast" = without delay. SOU 2026:26 notes the EU directive's maximum of 15 working days
for change notifications and finds that the AB rule "genast" already meets it (it proposes
the 15-day figure only for handelsbolag). Use "genast" in text; a reminder due date of
**15 working days** may be shown as an outer limit (ADVICE, lawyer question L6).

**AB-R37 Board, CEO, deputies, chair, signatories — must be reported.** The company reports
for registration who is appointed board member, deputy, chair, CEO, deputy CEO and special
service-of-process recipient, and who signs for the company and how. The report contains
postal address and **personnummer** (or birth date) of these persons. Reported first at
formation and then **without delay** when anything changes. The person concerned may also
report, and may report their own resignation.
- ABL 8 kap. 43 §, 44 §: "därefter genast när ett förhållande … har ändrats".
- Class: ADVICE (Mina datum reminder, Bolagsverket category).

**AB-R38 Board change takes effect only on receipt.** Changes in the board's composition take
effect **from the time the notification reached Bolagsverket**, or a later date in the
decision. Same for resignation (8:14 2nd st.). CEO appointment/departure: same (8 kap. 33 §).
Authorised signatory and its revocation: same (8 kap. 38 §).
- ABL 8 kap. 13 §: "har dock verkan först från den tidpunkt då anmälan om ändringen kom in till Bolagsverket".
- Class: HARD for NONOX's own data: a board/CEO/signatory change decided in a minute is
  stored as `decided_on` + `effective_from` = **the date the user enters as "anmälan inkom
  till Bolagsverket"** (or the later decision date); until then the old composition is the
  current one. NONOX must not show the new member as board member from the meeting date.
- Note: it is receipt, not registration, for the board (ADR-070 says "on registration").
- Test hint: AGM 2027-05-20 elects X; notification received 2027-05-27 → board on 2027-05-26
  excludes X, on 2027-05-27 includes X.

**AB-R39 Board member term.** A board member's term runs to the end of the first AGM held
after the year in which they were appointed, unless the articles set a longer term (at most
to the end of the AGM in the fourth fiscal year after appointment).
- ABL 8 kap. 13 §: "gäller till slutet av den första årsstämma som hålls efter det år".
- Class: ADVICE — the AGM agenda proposes "val av styrelse" when terms end. (ADR-070 does
  not mention terms; missing.)
- Test hint: appointed at AGM 2026-05-20 → term ends at the end of the AGM held in 2027.

**AB-R40 Auditor — must be reported.** The company reports who is appointed auditor (not if
Bolagsverket appointed them), with postal address and personnummer/birth date, or for an audit
firm its org number and principal auditor. First at formation, then **without delay** after
any change. An auditor who leaves early must report it himself/herself without delay, with a
copy to the board.
- ABL 9 kap. 47 §, 48 §, 23 §. Class: ADVICE.
- 9 kap. has **no** rule that the auditor's appointment takes effect only on receipt or
  registration (unlike 8:13). Lawyer question L7.
- Auditor term: to the end of the first AGM after the year of appointment unless the articles
  say longer (max 4th fiscal year) — 9 kap. 21 §. ADVICE.
- A private AB may have no auditor if the articles say so and the size thresholds are not
  exceeded (9 kap. 1 §) — affects whether the AGM agenda proposes "val av revisor".

**AB-R41 Articles of association — must be reported, effect only on registration.** A
decision to amend the articles is reported for registration **without delay** and **may not be
executed until it has been registered** (except 27 kap. 8 §).
- ABL 3 kap. 5 §: "får … inte verkställas förrän det har registrerats".
- Class: HARD for NONOX's data: a new articles version is `registered_on` = date entered by the
  user; until then NONOX keeps using the old version (notice method, share classes, limits,
  board size). ADVICE: reminder.
- Test hint: stämma 2027-05-20 adopts new articles changing notice method; a notice generated
  on 2027-06-01 with no registration date still uses the old method.

**AB-R42 Share capital changes and the register.**
- **New issue (nyemission):** allotted shares are entered in the register **at once after
  allotment** (13 kap. 18 §); the board reports the decision for registration **within six
  months** of the decision (13 kap. 27 §); the capital increase is fixed by registration
  (13 kap. 29 §); if not reported in time or refused, the decision lapses and payments are
  repaid (13 kap. 30 § → 17 §).
- **Bonus issue (fondemission):** reported **without delay**; capital is increased when
  registered; **after** registration the new shares are entered (12 kap. 10 §).
- **Split / reverse split:** reported without delay; the number of shares changes **when
  registered**; then the register is updated at once (4 kap. 49 §). In a non-avstämningsbolag
  the decision is sent at once to shareholders with known address unless all were
  represented (4 kap. 48 §).
- **Reduction with cancellation (indragning):** cancelled shares are struck from the register
  at once when the reduction has been carried out (20 kap. 21 §).
- **Conversion of share class (omvandling):** completed when registered and noted in the
  register (4 kap. 6 §).
- ABL quotes: 13:18 "Tilldelade aktier ska genast föras in i aktieboken."; 12:10 "Aktiekapitalet är ökat när beslutet har registrerats."; 4:49 "Antalet aktier ändras när beslutet registreras."
- Class: HARD — events need a `registered_on` where the law ties effect to registration:
  SPLIT, REVERSE_SPLIT, BONUS_ISSUE, CONVERSION take `effective_date` = registration date;
  NEW_ISSUE takes effect in the register at allotment but carries a status
  `pending_registration` and a deadline (decision + 6 months); a lapsed issue is reversed with
  a `NEW_ISSUE_LAPSED` (or CORRECTION) event. ADVICE: reminders.
- Test hints: NEW_ISSUE decided 2027-02-01, allotted 2027-02-20 → register at 2027-02-20 shows
  the new shares flagged "ej registrerad"; reminder due 2027-08-01; SPLIT decided 2027-03-10,
  registered 2027-03-24 → register at 2027-03-23 shows old count, at 2027-03-24 new count; the
  2081 comparison (ADR-070 §3) uses only registered capital.

**AB-R43 Annual report filing after the AGM.** Certified copies of the annual report and audit
report must reach Bolagsverket **within one month** after the meeting adopted the balance
sheet and income statement, with a certificate of adoption and the profit decision.
- ÅRL 8 kap. 3 § p.1: "inom en månad efter det att bolagsstämman fastställde balansräkningen".
- Class: ADVICE — existing `DeadlineType::AnnualReport` should be computed from the AGM date
  recorded in the boardroom module when present. Test hint: AGM 2027-05-20 → 2027-06-20
  (a Sunday; weekend shifting is lawyer question L4).

**AB-R44 Fees (ABF 3 kap. 1 §, in force per SFS 2025:343).**
- Change of board member, deputy, chair, CEO, deputy CEO, signatory, service recipient,
  **auditor**: **1 200 kr**, or **1 000 kr** if filed electronically with an electronic
  signature (p.7).
- Any other registration notice (e.g. **articles amendment**): **1 200 kr / 1 000 kr** (p.8).
- Change of company name: **2 400 kr / 2 200 kr** (p.2).
- No fee: changed postal address, an auditor's own resignation, dividend decision (3 kap. 3 §).
- ABF 3 kap. 1 § p.7: "1 200 kronor, eller, om en sådan anmälan överförs elektroniskt … 1 000 kronor".
- Class: ADVICE (shown in the reminder; never hard-coded without a source date — store with
  `source: "ABF 3:1, SFS 2025:343"` and `verified_on`).
- Note: the fee rule is per notification; whether several changes in one e-service filing cost
  one fee was not verified (Bolagsverket pages blocked) — L8.

**AB-R45 No filing by NONOX.** Consistent with the law: a notification is signed by a board
member or the CEO (ABF 1 kap. 2 §), electronically with an electronic signature (ABF 1 kap.
2 a §). NONOX files nothing (ADR-070 §5) — confirmed as the right default.

**AB-R46 Proposed: sole-owner reporting (not law).** SOU 2026:26 proposes that an AB whose
shares are all owned by one natural or legal person must report that owner (name, personnummer
or birth date, or org number) for registration, triggered at the latest by a change in the
register; proposed entry into force 2028-07-31.
- SOU 2026:26 p. 53 (proposed ABF wording) and p. 86: "anmälningsskyldigheten i vart fall bör inträda vid ändring i aktieboken".
- Class: OUT now; record in the roadmap — when enacted, a TRANSFER that leaves one holder
  of all shares should raise a Bolagsverket reminder.

## G. What ADR-070 gets wrong or misses (question 7)

1. **Wrong paragraph numbers in the Legal frame table:** content is ABL 5 kap. **5 §** (not
   2–3 §§; 5:2 is form, 5:3 retention); public access is 5 kap. **10 §** (not 15 §; 5:15 is
   rösträttsregistrering in avstämningsbolag); "duty to keep" is 5 kap. 1 § + 8 § (the trigger is
   the founders' signature, not "without delay"); minutes content/checking is 7 kap. **48 §**
   (7:49 is availability and storage); board reporting duty is 8 kap. **43–44 §§** (8:13 is the
   effect-on-receipt rule); auditor reporting is 9 kap. **47–48 §§**; articles registration is
   3 kap. **5 §**.
2. **Retention wording:** not just "superseded entries ten years" — the whole register is
   kept while the company exists **plus ten years after dissolution**, and removed data ten
   years (5:3). This is longer than BFL's retention and must be modelled separately.
3. **Content gaps:** the data model lacks *share certificate issued* (5:5 p.4), *rights
   holders* (5:6), *entry date* separate from effective date (5:9), förköp/hembud notes
   (4:20–22, 4:23 certificate on request), and the company itself as holder (4:50, 19 kap.).
4. **Event kinds missing:** `BONUS_ISSUE` (fondemission — differs from NEW_ISSUE in timing),
   `CONVERSION` (omvandling 4:6), `PRE_EMPTION_NOTICE`/`PRE_EMPTION_CLAIM`/`PRE_EMPTION_ACTION`
   (4:20–22), `RIGHTS_HOLDER_NOTE` (5:6), `CERTIFICATE_ISSUED`, `NEW_ISSUE_LAPSED` (13:30). Also
   *address/identity change* of an existing holder (5:9 2nd st.) — today it would have to be a
   CORRECTION, which is wrong (it is not a mistake).
5. **Effect on registration:** SPLIT, REVERSE_SPLIT, BONUS_ISSUE, CONVERSION change the
   register only from registration; NEW_ISSUE enters shares at allotment but can lapse (AB-R42).
   ADR-070 has no `registered_on` field.
6. **Board changes take effect on receipt by Bolagsverket, not on registration** (8:13); articles
   amendments may not be executed until registered (3:5). The ADR's "some take effect only on
   registration" needs this split.
7. **§6 public access:** for a non-avstämningsbolag the duty is to let anyone **inspect at the
   company**, not to hand out a copy. The paid print-out right ("mot ersättning") exists only
   for avstämningsbolag (5:19). The masked PDF is a voluntary courtesy copy, not the legal
   fulfilment.
8. **§4 signatures:** ABL 1:13 accepts an **advanced** electronic signature; a simple one (ADR-046)
   is not shown to suffice. Recommend "approved in NONOX" + external/advanced signature (AB-R36).
9. **Board minutes rules missing:** number order (8:26), dissent (8:24), sole-owner
   agreements (8:25), quorum/majority checks (8:21–22).
10. **Meeting rules missing:** notice method comes from the articles + mandatory post cases
   (7:23); documents available two weeks before AGM (7:25); register at the meeting dated the
   meeting day (7:28); voting list (7:29); continued meeting and its four-week registration
   duty (7:14); extra meeting on demand within two weeks (7:13); terms of board and auditor
   (8:13, 9:21) driving AGM "val" items; annual report filing one month after adoption (ÅRL 8:3).
11. **Articles as structured data:** the notice method, the private-AB "two weeks" clause, share
   classes and votes, förbehåll, min/max capital and number of shares, board size, auditor
   (or none) — the module needs a versioned `articles` record with `registered_on`.
12. **2081 check (§3):** compare with **registered** share capital; unregistered new issues
   make a temporary difference that is not an error (account 2082 "ej registrerat aktiekapital"
   is the usual BAS account — BAS usage, not law; accounting-checker should confirm).
13. **Existing code note:** `Deadline.php` maps `AnnualMeeting` to authority "Bolagsverket".
   The AGM itself is not filed with Bolagsverket; only its consequences are (annual report,
   board changes). Consider relabelling — UI wording, not a legal rule.
14. **Pending law:** SOU 2026:26 (sole-owner reporting from 2028-07-31; digital register copies
   for avstämningsbolag from 2027-07-01) should go on the watch list (Regelkoll).

## H. Proposed `rules.json` values (all `verified: false` until L1–L8 are answered)

```json
{
  "agm_deadline_months_after_fy_end": {"value": 6, "source": "ABL 7:10"},
  "notice_agm":          {"earliest_weeks": 6, "latest_weeks": 4, "articles_may_shorten_latest_to_weeks": 2, "source": "ABL 7:18"},
  "notice_extra_articles": {"earliest_weeks": 6, "latest_weeks": 4, "articles_may_shorten_latest_to_weeks": 2, "source": "ABL 7:19"},
  "notice_extra_other":  {"earliest_weeks": 6, "latest_weeks": 2, "source": "ABL 7:20"},
  "item_request_weeks_before_earliest_notice": {"value": 1, "source": "ABL 7:16"},
  "extra_meeting_on_demand_notice_within_weeks": {"value": 2, "share_fraction": "1/10", "source": "ABL 7:13"},
  "agm_documents_available_weeks_before": {"value": 2, "source": "ABL 7:25"},
  "continued_agm_weeks": {"min": 4, "max": 8, "registration_within_weeks": 4, "source": "ABL 7:14"},
  "minutes_available_weeks_after": {"value": 2, "source": "ABL 7:49"},
  "annual_report_filing_months_after_adoption": {"value": 1, "source": "ÅRL 8:3"},
  "new_issue_registration_months": {"value": 6, "source": "ABL 13:27"},
  "register_retention_years_after_dissolution": {"value": 10, "source": "ABL 5:3"},
  "removed_data_retention_years": {"value": 10, "source": "ABL 5:3"},
  "fees_sek": {"officer_or_auditor_change": {"paper": 1200, "e_service": 1000}, "other_registration": {"paper": 1200, "e_service": 1000}, "name_change": {"paper": 2400, "e_service": 2200}, "source": "ABF 3:1 (SFS 2025:343)"}
}
```

## Open questions for a human lawyer

- **L1** Does a print shown for inspection under ABL 5:10 have to include personnummer, or may
  a non-avstämningsbolag show a version with birth date only (GDPR art. 5.1 c, DSL 3:10)? And
  when the company voluntarily hands out a copy, may it omit addresses too?
- **L2** Does ABL 1:13 (advanced e-signature) also cover the *justering* of minutes under 7:48
  and 8:24, and is a simple electronic signature (typed name + e-mail OTP, ADR-046) ever
  enough for minutes in a private AB? Is a BankID signature an advanced signature for this
  purpose?
- **L3** Is a "paper meeting" (beslut per capsulam signed by all shareholders without a
  meeting) acceptable for a private AB under 7:1, and if so, which notice and minute rules
  apply? (Not addressed in the text read; common in practice for sole owners.)
- **L4** How are "tidigast sex veckor och senast fyra veckor före" counted (is the meeting day
  day 0; same weekday rule?), and does lag (1930:173) om beräkning av lagstadgad tid shift a
  deadline that falls on a weekend for 7:18–20, 7:49 and ÅRL 8:3?
- **L5** "Inom sex månader från utgången av räkenskapsåret" when the year ends on a month end
  (e.g. 30 April → 30 or 31 October?).
- **L6** Is 15 working days a safe outer limit to show for "genast" in ABL 8:44, 9:48, 3:5,
  given SOU 2026:26's reading?
- **L7** Does an auditor's appointment take effect at the decision or on registration (9 kap.
  has no rule like 8:13)?
- **L8** Bolagsverket fees and practice when several changes go in one e-service filing; the
  current e-service name and URL to link from reminders (Bolagsverket pages could not be
  fetched by the researcher).
- **L9** May an accounting firm, acting as processor, keep the register on the board's behalf
  without a written authorisation under 5:9 ("den som styrelsen har bemyndigat")? Should NONOX
  require an uploaded board decision before a firm user can enter transfers?
- **L10** For an existing company starting to use NONOX: is entering the current state as one
  `FORMATION`/opening event enough, given that 5:3 requires the old (paper) register to be kept
  ten years after all shares were entered in the new one? The UI should tell the user to keep
  the old book.
