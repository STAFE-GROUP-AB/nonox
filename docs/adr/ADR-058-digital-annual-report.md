# ADR-058: Digital annual report to Bolagsverket (K2 iXBRL)

- Status: Proposed
- Date: 2026-11-06
- Task: Track E.1 (research and decision; nothing built yet)
- Builds on: ADR-010 (reports, year-end, the K2 annual report and its
  fastställelseintyg), ADR-002 (PDF), ADR-054 (the Skatteverket pattern: keys,
  pilot users, Beta), AGENTS.md §7 Phase 3 ("K2 annual report → PDF + iXBRL",
  `TODO(legal-verify)`: current taxonomy version)

## Context

NONOX builds a K2 annual report for aktiebolag and ekonomiska föreningar
(`App\Domain\AnnualReport\AnnualReportDocument`): förvaltningsberättelse with
the multi-year overview, changes in equity and the disposition, the income
statement (kostnadsslagsindelad, full), the balance sheet (full), notes,
signatures and a fastställelseintyg page. It is rendered as HTML and as PDF
(Dompdf). The figures come from `AnnualAccounts` (ledger per BAS range through
`K2Schedule`) and the texts from the `annual_reports` row.

Today the user prints the PDF, has it signed, and files a paper copy. Bolagsverket
also receives the annual report digitally, as Inline XBRL, through a service
that software providers connect to. This ADR records what that service and the
Swedish taxonomies require, and decides how NONOX will produce, check and hand
over the file.

## Sources and what they say (read 2026-11-06)

Primary sources only. Bolagsverket's own pages and PDFs on bolagsverket.se
answer automated requests with a captcha (as noted in ADR-010 on 2026-11-02),
so the documents marked *(not read)* below were found by search but not read;
every fact that rests on them is marked `TODO(provider-verify)`.

### The law

- **ÅRL (1995:1554)**, riksdagen.se, ändrad t.o.m. SFS 2026:780
  (https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/arsredovisningslag-19951554_sfs-1995-1554/):
  - 2 kap. 7 §: the annual report of an aktiebolag "ska skrivas under av
    samtliga styrelseledamöter" and the managing director; if it is drawn up
    in electronic form it "ska den undertecknas med en sådan avancerad
    elektronisk underskrift" as defined in eIDAS (EU 910/2014) art. 3.
  - 8 kap. 3 §: certified copies "ska ha kommit in till
    registreringsmyndigheten inom en månad" of the adoption; "På kopian av
    årsredovisningen ska en styrelseledamot eller den verkställande direktören
    intyga att balansräkningen" and the income statement have been adopted;
    "På samma sätt ska även revisionsberättelsen … offentliggöras"; "Med en
    bestyrkt kopia jämställs … ett elektroniskt original."
  - 8 kap. 3 a §: "Handlingar som avses i 3 § får överföras elektroniskt till
    registreringsmyndigheten." "Ett intyg om fastställelse … får undertecknas
    med en elektronisk underskrift." The government or the authority it
    appoints issues rules on the transfer, the bestyrkande and the signature.
  - 8 kap. 3 b §: only companies that must prepare a sustainability report
    must use the uniform (ESEF) format. Digital filing is otherwise **allowed,
    not required**.
- **BOLFS 2018:1** (Bolagsverkets föreskrifter om elektronisk ingivning av
  årsredovisningshandlingar för aktiebolag, consolidated through BOLFS 2024:1;
  read on lagen.nu, https://lagen.nu/bolfs/2018:1; Bolagsverket's PDF
  https://bolagsverket.se/download/18.6535432417e0f20712756ea2/1643114950352/bolfs-2018-1.pdf
  *(not read)*):
  - 2 §: "En årsredovisning och en koncernredovisning ska ges in som en
    bestyrkt elektronisk avskrift"; the revisionsberättelse likewise; "Ett
    bevis om fastställelse (fastställelseintyg) ska ges in som ett elektroniskt
    original." An electronic copy reproduces the content without showing the
    original's appearance.
  - 3 §: the fastställelseintyg and the electronic bestyrkande "ska vara
    undertecknat med en sådan avancerad elektronisk underskrift" (eIDAS art. 3).
  - 5 §: documents may be filed electronically only through Bolagsverket's
    e-services, its "elektroniska mottagningstjänster för
    årsredovisningshandlingar", and under its technical requirements.
  - 4 § was not shown on lagen.nu. `TODO(legal-verify)`: read 4 § and BOLFS
    2024:1 in Bolagsverket's own text.
- **Mandatory digital filing is a proposal, not law.** The Justice Ministry's
  promemoria "Digital ingivning av årsredovisningar"
  (https://www.regeringen.se/contentassets/d80917262ab14de5bbff617fcbda2b2c/digital-ingivning-av-arsredovisningar.pdf,
  *(not read in full)*) proposed it for K2/K3 aktiebolag from 2026-01-01;
  SOU 2026:26 "Digitala verktyg inom bolagsrätten" (https://lagen.nu/sou/2026:26,
  first part read) builds on machine-readable filing with ÅRL changes from
  2028-07-31. No such rule is in force in the ÅRL text read above.
  `TODO(legal-verify)`: follow the bill; it would make this feature necessary
  rather than convenient.

### The taxonomies (taxonomier.se, published by TaxSam Företag: Bolagsverket, BFN, Finansinspektionen, Skatteverket, SCB, with FAR)

- Overview, status FINAL (https://www.taxonomier.se/taxonomier-info-final.html):
  - **Årsredovisning K2, Aktiebolag: version 2024-09-12** (older: 2021-10-31).
    Also K2 for ekonomiska föreningar, HB/KB and filialer, each 2024-09-12.
  - Årsredovisning K3, Aktiebolag: 2021-10-31 (revised 2024-11-20; the news
    of 2024-12-03 says it is in Bolagsverket's service "efter den 6 december 2024").
  - **Fastställelseintyg (COMP), aktiebolag: 2020-12-01**, entry points
    `http://xbrl.taxonomier.se/se/fr/gaap/coa/rplc/2020-12-01/se-coa-rplc-2020-12-01.xsd`
    (no group) and `…/coa/rcplc/2020-12-01/…` (group)
    (https://www.taxonomier.se/taxonomier-fi-2020.html). The page says the
    intyg "finns på årsredovisningen": it is tagged inside the same document.
  - **Revisionsberättelse (AUDIT): 2020-12-01** (revised 2026-08-31).
- K2 AB 2024-09-12 (https://www.taxonomier.se/taxonomier-k2-ab-20240912.html):
  "Årsredovisning för aktiebolag enligt K2-regelverket", revised 2025-03-12
  and 2026-09-09 ("ändringar i K-regelverken" and BAS 2026; the files are
  named `rev20260831`). Five entry points by presentation form:
  - `risbs` resultaträkning och balansräkning (full/full):
    `http://xbrl.taxonomier.se/se/fr/gaap/k2-all/ab/risbs/2024-09-12/se-k2-ab-risbs-2024-09-12.xsd`
  - `risab` full income statement, abridged balance sheet; `raibs` abridged
    income statement, full balance sheet; `raiab` both abridged; `full` all forms.
  - The entry points are by presentation form, not "med/utan
    revisionsberättelse": the revisionsberättelse is its own taxonomy, and
    whether it is in the same file is chosen at submission (below).
  - Documentation: content model as HTML and Excel, the equity-change model,
    a tuple model, and lists of differences between versions (links on the page).
- Package: all FINAL taxonomies in one Taxonomy Packages 1.0 ZIP,
  https://xbrl.taxonomier.se/taxonomi-paket/taxonomi-paket-2026-08-31.zip
  (6.3 MB, 104 MB unpacked, 1 344 files; downloaded once for this ADR into the
  session scratchpad, SHA-256
  `47e56bca68c052b751ed8e0759fa9b04a6fa09b1d2b47ae2df4187d0ea2fe559`). What it
  showed:
  - The concepts live in `se-gen-base 2021-10-31` (2 985 names), for example
    `Nettoomsattning`, `Rorelseresultat`, `AretsResultat` (duration, credit),
    `Leverantorsskulder` (instant, credit), `MedelantaletAnstallda`,
    `Periodiseringsfonder`, `Checkrakningskredit`, and **both**
    `MaskinerInventarier` and the separate `MaskinerAndraTekniskaAnlaggningar`
    and `InventarierVerktygInstallationer`.
  - `se-comp-base 2020-12-01` holds the fastställelseintyg: `Faststallelseintyg`,
    `FaststallelseResultatBalansrakning`, `Arsstamma`,
    `ArsstammaResultatDispositionGodkannaStyrelsensForslag`,
    `ArsstammaResultatDispositionBeslutstext`, `IntygandeOriginalInnehall`,
    `UnderskriftFastallelseintygDatum`,
    `UnderskriftFaststallelseintygForetradareTilltalsnamn`/`…Efternamn`/`…Foretradarroll`,
    `UnderskriftFaststallelseintygElektroniskt`.
  - **BAS reference linkbases** (`ref-bas`, `rbas2024`, `rbas2026`) tie
    concepts to BAS accounts (e.g. `OvrigaFordringarKortfristiga` → BAS 164x).
    This is an official BAS–K2 link that ADR-010's open
    `TODO(legal-verify)` on `K2Schedule` ranges can be checked against.
- Inline XBRL examples (https://www.taxonomier.se/tillampning.html): nine
  tagging examples (.xhtml + extracted .xbrl, updated 2024-02-14) on monetary
  values, percentages, dates, the `sign` attribute ("ska tillämpas för att
  säkerställa att det visuellt presenterade värdet tolkas likadant") and the
  `format` attribute. They "förtydligar Bolagsverkets tillämpningsanvisningar".
- **Licence** (https://www.taxonomier.se/copyright.html, XBRL International's
  standard terms): the taxonomy "may be copied and furnished to others"; every
  copy and derivative work must carry the copyright notice and "A link or URL
  to the original taxonomy"; "this taxonomy itself may not be modified in any
  way"; the names of XBRL International / XBRL Sweden "may NOT be used in
  advertising or publicity"; patents are the user's own risk. The files in the
  ZIP carry no notice of their own.

### Bolagsverket's service

- **API specification, read**: "API för inlämning av digitala
  årsredovisningar", **version 2.1**, Swagger 2.0,
  https://media.bolagsverket.se/diar/services/2.1/lamnaInArsredovisning-2.1.yaml
  (and `.html`). Licence field: "Offentlig handling enligt
  Tryckfrihetsförordningen 2 Kap 3 §". Host `api.bolagsverket.se`, base path
  `/lamna-in-arsredovisning/v2.1`. Three operations:
  1. `POST /skapa-inlamningtoken` with `pnr` (12 digits, "person som
     handlingen ska lagras på") and `orgnr` (10 digits) → `token` (uuid),
     `avtalstext` ("Text att visa för slutanvändaren") and `avtalstextAndrad`.
     404 "Personnummer saknas".
  2. `POST /kontrollera/{token}` with `handling` → `utfall[]` of
     `kod`/`text`/`typ` (info, warn, error) and technical detail.
     "Användaren av programvaran bör upplysas om dessa eventuella utfall.
     Årsredovisningen kan dock lämnas in till eget utrymme oavsett utfall."
  3. `POST /inlamning/{token}` with `undertecknare` (personnummer),
     `epostadresser` (told there is a report to sign), `kvittensepostadresser`
     (told when it is signed and sent), `notifieringEpostadresser` (e.g. the
     auditor, told a revisionsberättelse is expected) and `handling` → 200
     "inlämnad till eget utrymme för senare underskrift via webben", with
     `handlingsinfo` (`dokumentlangd`, `idnummer` "ska inte visas för
     användare", `sha256checksumma`) and a `url`.
  - `handling.fil`: base64 (`format: byte`), "T.ex. årsredovisning i
    iXBRL-format. Filen ska ha charset UTF-8." `handling.typ`:
    `arsredovisning_komplett` (with the revisionsberättelse in the same file,
    or none required), `arsredovisning_kompletteras` (the revisionsberättelse
    comes as a separate file), `revisionsberattelse`.
  - The API never signs: "årsredovisningen ska skrivas under senare via webben".
- **Documents found but not read** (captcha), on
  https://bolagsverket.se/apierochoppnadata/lamnaforetagsinformation/apifordigitalinlamningavarsredovisningochrevisionsberattelse/tekniskdokumentationfordigitalinlamningavarsredovisning.5937.html:
  Tekniskt ramverk 1.2, Teknisk guide 3.5, Anslutningsanvisning 1.7,
  Aviseringar och filformat 2.2, Tillämpningsanvisning för årsredovisningar i
  iXBRL-format 1.8; "Gällande servicespecifikationer" (…5938.html, listing
  inlämning 2.1 among others). Search snippets of those documents say, and
  this is **unverified**:
  - calls go over TLS with an approved **client certificate**, and the
    provider tells Bolagsverket the certificate's serial number;
    firewall openings for the provider's public IP;
  - the acceptance environment is `api-accept2.bolagsverket.se`; there is a
    test environment with static test data;
  - no agreement is needed to start in the "testbank"; a signed agreement
    opens the full test environment; test files are reviewed before production
    access is granted (with a queue at the time of writing);
  - the signer signs the fastställelseintyg with e-legitimation (BankID) in
    Bolagsverket's web service; Bolagsverket lists the providers whose
    software supports digital filing.
- A provider's user guide (Björn Lundén,
  https://support.bjornlunden.se/guide/digital-inlamning-av-arsredovisning,
  secondary): Bolagsverket e-mails the signer, who logs in with BankID and
  signs the fastställelseintyg; "Först när fastställelseintyget är signerat
  anses årsredovisningen ha lämnats in"; the eget utrymme is waiting storage
  that Bolagsverket cannot see until signing; both the annual report and a
  separately uploaded revisionsberättelse must be there before signing; the
  provider runs a check of the representative against Bolagsverket's register
  before upload (an API not in the 2.1 specification).

## Decision

### 1. One source of figures

`AnnualReportDocument::data()` stays the only place the report's content is
assembled. The PDF view and a new iXBRL view are two renderings of the same
array; the iXBRL writer never reads the ledger or `K2Schedule` itself. A test
asserts that every amount printed in the PDF HTML is the same number as the
tagged fact (same key, same öre), so the two cannot drift.

### 2. The iXBRL document

- `App\Support\Ixbrl\` (AGENTS.md §9.3): a writer that renders one XHTML 1.1
  document, UTF-8, well-formed XML, with `ix:header` (hidden facts, contexts,
  units) and the visible report. No external CSS, fonts, scripts or images:
  the styles are inline and a logo, if any, is a `data:` URI.
  `TODO(provider-verify)`: the size limit, allowed elements and image rules in
  Tillämpningsanvisning 1.8.
- Contexts: the entity is the organisation number (scheme per the
  tillämpningsanvisning, `TODO(provider-verify)`); `duration` contexts for the
  year and the previous year, `instant` contexts for both balance dates (and
  the opening date where the equity change needs it). Unit `iso4217:SEK`;
  `xbrli:pure` for percentages (soliditet); a unit for the employee count per
  the taxonomy.
- Amounts: integer öre in NONOX → `ix:nonFraction` in kronor as printed, with
  `decimals="0"` or the precision the report shows (`TODO(provider-verify)`:
  whole kronor or öre), `format` per the examples, and `sign="-"` where the
  printed value and the concept's balance type differ (the examples' rule).
- Texts (business description, events, comments, notes) as
  `ix:nonNumeric`, with escaping of user text.
- The fastställelseintyg is tagged in the same document with
  `se-comp-base 2020-12-01` concepts, from `AnnualReportDocument::certificate()`.
- Entry point: **K2 AB 2024-09-12 `risbs`** (NONOX prints the full income
  statement and full balance sheet) plus the fastställelseintyg entry point
  `coa/rplc/2020-12-01`. Which taxonomy version Bolagsverket accepts for which
  fiscal year: `TODO(provider-verify)` in "Gällande servicespecifikationer";
  the version is a constant per fiscal-year range, never guessed at runtime.
- Only **aktiebolag** in the first version. BOLFS 2018:1 and the API speak of
  aktiebolag; K2 taxonomies for ekonomiska föreningar exist, but
  `TODO(provider-verify)`: whether Bolagsverket's service takes them. K3 is out
  of scope (NONOX makes no K3 report). Companies that must use ESEF (8 kap. 3 b §)
  are refused.

### 3. Mapping NONOX's report model to taxonomy concepts

- A hand-written map in code, `App\Domain\AnnualReport\K2Taxonomy`: one entry
  per `K2Schedule` line key, subtotal, note figure and text field → concept
  QName and period type. The map is the only place concept names appear.
- Where the taxonomy is finer than NONOX (it has `MaskinerAndraTekniskaAnlaggningar`
  and `InventarierVerktygInstallationer` beside `MaskinerInventarier`), the map
  uses the concept that matches what the report prints; splitting the line is
  ADR-010's open question, not this ADR's.
- A unit test reads the taxonomy's BAS reference linkbase (when the taxonomy is
  present, see 4) and reports every BAS range in `K2Schedule` whose accounts
  the taxonomy assigns to another concept. It answers part of ADR-010's
  `TODO(legal-verify)` on the ranges; differences are reviewed, not
  auto-corrected.
- A line, note or text the map lacks makes the iXBRL export refuse with a
  Swedish message naming it. NONOX never files a report whose printed content
  is not fully tagged as required (`TODO(provider-verify)`: which items the
  tillämpningsanvisning requires to be tagged).

### 4. Validation in tests

- **The taxonomy is not committed.** Its licence allows copying with the
  notice and a link, so committing would be lawful, but it is 104 MB, it may
  not be modified, and its own files carry no notice. Following the existing
  `SKATTEVERKET_AGI_SCHEMA_DIR` pattern:
  - `config/services.php` `bolagsverket.taxonomy_dir`
    (`BOLAGSVERKET_TAXONOMY_DIR`): an unpacked package downloaded by the
    operator from taxonomier.se. When set, every generated file is checked
    against it before it is offered.
  - A pinned download script outside the app (URL + SHA-256 above) fills a
    cache for CI; the taxonomy tests are skipped with a visible reason when the
    directory is missing, and CI sets it.
  - The concept QNames in `K2Taxonomy` are identifiers, not copied text.
    `TODO(legal-verify)`: confirm that a map of QNames is not a "derivative
    work" that must carry the copyright notice; if it is, add the notice and
    the link in the file header.
- **What the tests check without the taxonomy:** the document is well-formed
  XHTML and valid against the iXBRL 1.1 schema structure we use; every fact has
  a context and unit; no amount differs from `data()`; the balance sheet
  balances; synthetic fixtures only (`tests/Fixtures/ixbrl/`), never real
  company data.
- **With the taxonomy:** every QName exists in the entry point's DTS, has the
  right period type, and monetary facts carry a monetary unit.
- **Full XBRL validation:** Arelle (open source, Apache-2.0, AGPL-compatible) as
  an optional CI job run as an external command against the generated file;
  not a runtime dependency. `TODO(provider-verify)`: whether Bolagsverket
  publishes its own validator or test files beyond the API's `kontrollera`.
- **Authoritative check:** Bolagsverket's `POST /kontrollera/{token}` in the
  test environment (stage E.3). Its codes and texts are shown as Bolagsverket
  wrote them.

### 5. Signing

Two different signatures, kept apart:

- **The original annual report** (ÅRL 2 kap. 7 §) is signed by the board and
  the managing director. On paper this stays as today (the PDF). Signed
  electronically it needs an **advanced** electronic signature. NONOX's
  signature pad (ADR-046) is a simple electronic signature and must not be
  offered for this. Digital signing of the original by every board member is
  a separate later decision (a signing provider; human decision below). The
  digital filing does not depend on it: filing sends an avskrift either way.
- **The fastställelseintyg and the bestyrkande** (8 kap. 3 §, BOLFS 2018:1
  2–3 §§) are signed by one board member or the managing director with an
  advanced electronic signature **in Bolagsverket's own web service**, after
  NONOX has placed the file in that person's eget utrymme. NONOX does not sign
  them and holds no signing key. (`TODO(provider-verify)`: confirm in the
  Teknisk guide that this is the only way through the API; the spec says the
  report "ska skrivas under senare via webben".)
- The fields the intyg needs already exist on `annual_reports`
  (`adoption_date`, `adoption_decision`, `certifier_name`, `certifier_role`).
  The upload needs the certifier's personnummer (`pnr`, `undertecknare`) and
  e-mail: a new encrypted column, entered per filing, deleted when the filing
  is done (§10 GDPR; erasure and register of processing updated in the same
  change).

### 6. Submission, behind keys (the ADR-054 pattern)

- `config/services.php` `bolagsverket`: `environment` (`test` or
  `production`), client certificate and key paths (and passphrase), and
  `pilot_users` (`BOLAGSVERKET_PILOT_USERS`, as ADR-054's addendum). Without a
  certificate nothing is offered; the iXBRL file can still be downloaded.
- `BolagsverketClient`: TLS with the client certificate, the three calls of
  API 2.1, errors as `BolagsverketFailed` with Bolagsverket's `text` for the
  user. Base URLs per environment: production `api.bolagsverket.se`;
  acceptance `api-accept2.bolagsverket.se` `TODO(provider-verify)`.
- Flow on **Bokslut → Årsredovisning**, permission `annual-report:submit`
  (new; owner, admin, accountant), only after the adoption date is entered:
  1. *Skapa fil* (download the iXBRL avskrift; always available).
  2. *Låt Bolagsverket kontrollera* → `skapa-inlamningtoken` + `kontrollera`;
     show `avtalstext` and require the user to accept it, storing the
     `avtalstextAndrad` date they accepted.
  3. *Lägg i eget utrymme* (confirmation dialog in plain Swedish: this is not
     filing; the certifier must sign at Bolagsverket within the month of
     8 kap. 3 §) → `inlamning` with `typ` `arsredovisning_komplett` or, when
     the company has an auditor and the revisionsberättelse comes from the
     auditor's software, `arsredovisning_kompletteras` with the auditor in
     `notifieringEpostadresser`.
- `annual_report_submissions` (company-scoped, `BelongsToCompany`, tenancy
  test): environment, typ, the SHA-256 NONOX computed, Bolagsverket's
  `sha256checksumma` and `dokumentlangd` (they must match, else the result is
  flagged), the check outcome, the accepted avtalstext date, timestamps. Never
  the personnummer and never `idnummer` shown to users. Audit:
  `annual_report.checked` / `annual_report.uploaded` with ids only.
- Nothing is booked and the report is not locked by an upload; changing the
  report after upload shows that the uploaded file is outdated.
- "Filed" is never claimed by NONOX: the status is "I eget utrymme, väntar på
  underskrift" until a later stage can read Bolagsverket's events
  (hämta årsredovisningshändelser, a separate API, not decided here).

### 7. Stage plan

- **E.2 Generate K2 iXBRL** (no external calls): `K2Taxonomy` map, the
  `App\Support\Ixbrl` writer, the XHTML view from `AnnualReportDocument::data()`,
  download button, synthetic fixtures, the PDF-equals-iXBRL test,
  systemdokumentation and manual updated. Beta label.
- **E.3 Validate**: `BOLAGSVERKET_TAXONOMY_DIR` checks, the BAS-reference
  report against `K2Schedule`, optional Arelle CI job; then
  `kontrollera` against Bolagsverket's test environment once the certificate
  and test access exist (human step).
- **E.4 Signing**: the certifier's personnummer/e-mail handling, the
  avtalstext acceptance, the Swedish texts explaining that signing happens at
  Bolagsverket; optionally (separate decision) advanced e-signing of the
  original by the board through a chosen provider.
- **E.5 Submit**: `inlamning` to eget utrymme behind certificate + pilot users,
  test environment first; production only after Bolagsverket has approved
  NONOX's test files and the agreement is signed. Legal and code review before
  deploy, Beta until a human accountant has filed a real report through it.

## Open questions

Each is marked in code where it applies.

- `TODO(provider-verify)`: everything in Tekniskt ramverk 1.2, Teknisk guide
  3.5, Anslutningsanvisning 1.7, Tillämpningsanvisning 1.8 and "Gällande
  servicespecifikationer": certificate issuer and type, firewall, test and
  acceptance hosts, the agreement text, file size limit, allowed XHTML,
  required tagging, entity identifier scheme, decimals, accepted taxonomy
  versions per fiscal year, error codes. Someone must open them in a browser
  (captcha) and record the versions here.
- `TODO(provider-verify)`: the representative check (behörighet) API that
  providers run before upload, and the events API for the filing status.
- `TODO(provider-verify)`: whether Bolagsverket takes ekonomiska föreningar
  through this service.
- `TODO(legal-verify)`: BOLFS 2018:1 4 § and BOLFS 2024:1 in Bolagsverket's text.
- `TODO(legal-verify)`: whether filing digitally costs a fee (none found).
- `TODO(legal-verify)`: when a company must have an auditor (ABL 9 kap.), so
  NONOX can ask for `kompletteras` and the auditor's e-mail; NONOX does not
  produce the revisionsberättelse.
- `TODO(legal-verify)`: whether the QName map needs the taxonomy's copyright
  notice; that NONOX's public pages may say "lämna in digitalt" without naming
  XBRL Sweden or XBRL International (the licence forbids their names in
  publicity).
- `TODO(legal-verify)`: the status of mandatory digital filing (promemoria,
  SOU 2026:26).
- `TODO(legal-verify)`: GDPR: the certifier's personnummer sent to
  Bolagsverket (legal basis, retention, register of processing, privacy
  policy, legal version bump) before E.4 ships.

## Human decisions

1. **Agreement with Bolagsverket** for the reception service, signed by the
   operator company's firmatecknare (AI Reports Sweden AB, per the external
   agreements list), and the testbank/test-file review.
2. **Client certificate**: which issuer and who holds the private key on the
   hosted service (Bolagsverket's requirements are unread).
3. **Signing provider** for an advanced electronic signature of the original
   annual report by the board, or a decision that the original stays on paper
   (the filing works either way).
4. Whether self-hosted installations may use the service with their own
   agreement and certificate (as with Skatteverket), and what `.env.example`
   tells them.

## Consequences

- The PDF and the iXBRL file always show the same figures.
- Until E.5 is approved, users can download the iXBRL file but cannot file it
  from NONOX; the paper route stays.
- The taxonomy version must be followed: when taxonomier.se publishes a new
  FINAL revision or Bolagsverket changes its accepted list, the stage owner
  records the version and date here before the next deploy.
- No new runtime package is required for E.2; Arelle runs only in CI, as an
  external tool.

## Addendum: Stage E.2 built (2026-11-06)

What was built, and where it deviates from the decision above.

- **Code.** `App\Domain\AnnualReport\K2Taxonomy` (the map and the concepts'
  period type, balance and kind), `App\Support\Ixbrl\InlineXbrlWriter`
  (facts, contexts, units, ix:header), `Period`, `IxbrlRefused`,
  `TaxonomyConcepts` (the check against an unpacked package) and
  `K2AnnualReportIxbrl` (renders `annual-report/ixbrl-body.blade.php` from
  `AnnualReportDocument::data()` inside `annual-report/ixbrl.blade.php`).
  Download: `GET /arsredovisning/{fiscalYear}/ixbrl`
  (`accounting.annualReport.ixbrl`), the same permission as the PDF
  (`fiscal-years:manage`), aktiebolag only, labelled Beta. Nothing is
  booked, stored or sent; no external call.
- **One source.** `data()` gained only what the file needs and the PDF
  ignores: `previousYear`, `start`/`end` on each multi-year row and `kind`
  on each pledged row. A test parses both documents and asserts that the
  amount cells read the same, in the same order, that each holds exactly one
  `ix:nonFraction`, and that each fact equals the öre in `data()`.
- **Mapping coverage.** Read from the presentation and calculation
  linkbases of `risbs` 2024-09-12 and `coa/fplc` 2020-12-01 in the package.
  Income statement: 21 of 21 lines and subtotals mapped. Balance sheet: 29 of
  33 lines (machinery, reserve, long_group, group_liabilities are not), all 8
  section sums and both totals. Multi-year: 3 of 3. Equity changes: every
  row and column except the reserve column and the "Övriga förändringar"
  row. Disposition: 4 of 4. Fixed asset notes: buildings and improvements
  (2 of 5 lines; intangible, machinery and construction in progress are
  not). Pledged assets 5 of 5 kinds plus the sum; contingent liabilities;
  average employees; the texts except group commitments (ÅRL 5 kap. 16 §
  has no K2 concept). A printed line or note without a concept refuses the
  whole file with a Swedish message naming it ("Balansräkning: Maskiner och
  inventarier"); so does an empty "Allmänt om verksamheten".
- **Maskiner och inventarier (decided: refuse).** `MaskinerInventarier`
  exists in se-gen-base but no K2 linkbase uses it (only INK3); the K2
  presentation has only the separate `MaskinerAndraTekniskaAnlaggningar` and
  `InventarierVerktygInstallationer`. Tagging NONOX's combined line with a
  concept outside the K2 presentation would file a report Bolagsverket's
  validation is likely to question, so a report with that line is refused
  until ADR-010's open question (splitting the line) is decided. Most small
  companies with equipment are therefore refused today; that is the honest
  state, and it is said on the page and in the manual.
- **Signs and decimals.** Whole kronor as printed (`PrintedFigures`, as the PDF; see the review below),
  `decimals="0"`, `format="ixt:num-comma-decimal"` (TR4 2020-02-12). The
  minus sign stands outside the tag; the fact is positive in the direction of
  its concept's balance (a cost printed −25 000 is the fact 25 000) and
  `sign="-"` marks the opposite. Concepts without a balance ("Minskning av …"
  in the equity and fixed asset notes) carry the printed sign. Soliditet is a
  ratio (`scale="-2"`, 45,3 → 0.453); medelantalet anställda uses unit
  `xbrli:pure`. All `TODO(provider-verify)` against tillämpningsanvisning 1.8.
- **Contexts.** Entity scheme `http://www.bolagsverket.se` with the ten
  digits of the organisation number (`TODO(provider-verify)`); one duration
  context per year shown and one instant per balance date, ids from the dates
  (`d2027-01-01_2027-12-31`, `i2027-12-31`); only contexts and units that a
  fact uses are written. Without a previous fiscal year in NONOX the
  comparison column of the balance sheet (opening balances) is an instant at
  the day before the year.
- **Hidden facts.** The value lists of se-cd-base (language Swedish, seat in
  Sweden, SEK, whole kronor) and "Styrelsen avger följande årsredovisning",
  and the calculation subtotals the report does not print
  (RorelseintakterLagerforandringarMm, Rorelsekostnader, FinansiellaPoster,
  MateriellaAnlaggningstillgangar, KortfristigaFordringar), each the sum of
  its printed lines as tagged.
- **Fastställelseintyg (deviation).** `FaststallelseResultatBalansrakning`
  and `IntygandeOriginalInnehall` are restricted in se-comp-base to fixed
  texts; the file prints exactly those (not the PDF's own sentence, which
  stays in the PDF). `Arsstamma` is the adoption date. The three are written
  only when the adoption date is entered, dated at the balance date
  (`TODO(provider-verify)`). The meeting's decision is printed but not
  tagged: whether the meeting approved the board's proposal is not recorded
  in NONOX, and the schema's `ArsstammaResultatDisposition…` facts need it
  (`TODO(provider-verify)`; E.4). The signer's name, role, date and
  `UnderskriftFaststallelseintygElektroniskt` are never written: the
  certificate is signed in Bolagsverket's service. The annual report's own
  signatures are printed, untagged (the taxonomy's signature tuple needs
  first and last names apart, which NONOX does not keep); only the signing
  date is tagged (`UndertecknandeArsredovisningDatum`).
- **Taxonomy check.** `services.bolagsverket.taxonomy_dir`
  (`BOLAGSVERKET_TAXONOMY_DIR`) in `.env.example`. When set, every file is
  checked before it is offered: the two entry points exist, every concept
  exists with the period type, balance and type the map says, and the value
  list members exist. `phpunit.xml` sets it empty without `force`, so a
  developer's `.env` never leaks into tests while a command-line value still
  runs the taxonomy test (run 2026-11-06 against the 2026-08-31 package:
  every concept agrees). CI does not download the package yet (the pinned
  download script is E.3 work), so there the test is skipped with its reason.
- **Not done in E.2:** Arelle validation, the BAS reference report against
  `K2Schedule`, ekonomiska föreningar, the representative check,
  submission. Public pages keep the feature as coming (`Features::SOON`).

### Review of E.2 (2026-11-06)

An independent review confirmed five findings; all are fixed.

1. **Calculation relationships.** Each amount was cut to kronor on its own,
   so printed totals could miss their lines by a krona. Now
   `PrintedFigures` rounds every line half away from zero and builds every
   sum from the rounded lines, for the PDF and the file alike; the balance
   sheet's rounding difference goes to Övriga skulder (ADR-010 addendum
   "Annual report amounts in whole kronor"). A test with öre amounts checks
   every fisbn/fbs calculation relationship present, from a table written in
   the test.
2. **Control characters.** User text passes `InlineXbrlWriter::xmlText`
   (drops what XML 1.0 cannot carry), the whole body once more, and the
   finished file must load with `DOMDocument::loadXML` or the download is
   refused.
3. **Test oracle.** A loss-making fixture with negative equity and öre
   amounts is checked against a hand-written table of facts, and every
   printed `ix:nonFraction` against a hand-written balance/direction table:
   (minus outside the tag) XOR `sign="-"` is true exactly when the concept's
   balance runs against the statement.
4. **Contingent liability without an amount.** Only its text is tagged, and
   neither the PDF nor the file prints a 0 for it.
5. **Wording.** The page says the file is "avsedd för digital inlämning".

## Addendum: Stage E.3 (CI validation) (2026-11-06)

The part of E.3 that needs no agreement with Bolagsverket. The authoritative check
against `POST /kontrollera/{token}` in their test environment remains open (below).

- **Pinned download.** `scripts/fetch-xbrl-taxonomy.sh` downloads
  `https://xbrl.taxonomier.se/taxonomi-paket/taxonomi-paket-2026-08-31.zip`, checks
  SHA-256 `47e56bca68c052b751ed8e0759fa9b04a6fa09b1d2b47ae2df4187d0ea2fe559`
  (full hash; the prefix and suffix match §1), refuses archive entries with absolute or
  `..` paths, unpacks into a cache directory outside the repository
  (`$NONOX_TAXONOMY_CACHE`, else `~/.cache/nonox-xbrl-taxonomy/<sha>/`, or the first
  argument) and prints the folder to use as `BOLAGSVERKET_TAXONOMY_DIR`. A mismatch or an
  unexpected layout exits non-zero and leaves nothing in the cache. The verified zip stays
  next to the folder as `package.zip` (Arelle needs the zip). The package is read, never
  executed. Moving to a new taxonomy version means changing `VERSION` and `SHA256` in the
  script, recording the date here, and rerunning the taxonomy test.
- **CI, job `tests`.** The cache (`actions/cache`, key = hash of the script) plus a step
  "Fetch XBRL taxonomy (pinned, SHA-256 verified)" run before Pest. A named step
  "iXBRL annual report against the XBRL taxonomy" runs
  `IxbrlAnnualReportTest.php` with `BOLAGSVERKET_TAXONOMY_DIR` set, and the full Pest step
  has it set too, so the taxonomy test is no longer skipped in CI. Verified locally against the
  real package: 8 tests, 1 161 assertions, green.
- **CI, job `ixbrl-arelle`** (blocking since the defects below were fixed; at first non-blocking with `continue-on-error: true`). Installs
  `arelle-release==2.47.4` into a venv and runs `scripts/validate-ixbrl-arelle.sh` on
  `tests/Fixtures/annual-report/*.xhtml` (the golden files the Pest suite already pins):
  `--validate --calc=c10d --utr --plugins=inlineXbrlDocumentSet --workOffline` with the
  taxonomy package. Arelle exits 0 even when validation fails, so the script counts `ERROR`
  lines. Result format: one log per file, lines `LEVEL [messageCode] message | file line`,
  uploaded as the artifact `arelle-logs`. It is non-blocking because the first run found
  real defects (below); remove `continue-on-error` when the fixtures validate clean.
- **First Arelle run (2.47.4, 2026-11-06), taxonomy 2026-08-31.** No taxonomy,
  calculation, context, unit or UTR error in either file: every concept resolved, the
  entry points loaded, `--calc=c10d` found no inconsistency. All findings are in the XHTML
  shell:
  1. `<html ... lang="sv">`: the XHTML schema Arelle uses rejects `lang` next to
     `xml:lang` ("attribute 'lang' is not allowed", both files).
  2. `<tr class="">`: `@class([...])` in `ixbrl-body.blade.php` (equity table row) prints an
     empty class when the condition is false; Arelle: "Attribute class must not be empty"
     (10 rows in `k2-profitable`, 5 in `k2-loss`).
  3. `<table class="signatures"><tbody></tbody></table>` with no signatories (`k2-loss`):
     "tbody: Missing child element(s). Expected is ( tr )".
  These are fixed in the generator, not suppressed; the Arelle job then turns blocking.
- **Fixed (2026-11-06).** `<html>` carries only `xml:lang`; a table row gets `class="sum"`
  or no class attribute at all; the signatures table is left out when there are no
  signatories. Pest checks the generated XHTML for all three (`IxbrlAnnualReportTest`,
  `IxbrlRoundingTest`). The golden files were regenerated with `NONOX_UPDATE_GOLDEN=1`; their
  diff is exactly these three changes (the PDF HTML fixtures are unchanged). Second Arelle
  run (2.47.4, same flags, taxonomy 2026-08-31): `k2-loss` 0 errors, `k2-profitable` 0 errors,
  no warnings, only the INFO lines for loading and validation. `continue-on-error` is removed
  from `ixbrl-arelle`, so an Arelle error now fails CI.

### Still open for E.3

- The `kontrollera` call against Bolagsverket's test environment (needs the API agreement
  and test credentials, `TODO(provider-verify)`): its codes and texts are shown as written.
- Whether Bolagsverket accepts the document as one iXBRL file with the
  fastställelseintyg, or wants them separate (E.4).
