# ADR-054: Skatteverkets API:er (momsdeklaration, arbetsgivardeklaration, skattekonto, kundhändelser)

- Status: Accepted (stage 1: momsdeklaration; stage 2: arbetsgivardeklaration; stage 3: skattekonto; stage 4: kundhändelser, see the addenda)
- Date: 2026-11-05

## Context

A Swedish bookkeeping system is expected to hand the VAT return and the
employer declaration to Skatteverket without files, and to show the tax
account. Skatteverket offers this as partner APIs to software companies that
sign an agreement. Until now NONOX produced files (the eSKD file for VAT, the
AGI XML) that the user uploaded by hand.

The operator's registration (the "Komplett testtjänst" first) holds the
scopes `momsdeklaration`, `agd`, `agdredovisningperiod`, `ska` and
`kundhandelser` under one key set.

## Facts the design rests on

Primary sources only: skatteverket.se (pages on ACG, CCG, access tokens, API
keys, error codes), and the developer portal's service descriptions, general
terms and RAML specifications, read 2026-11-05:
Momsdeklaration RAML 1.0.25 (library 1.0.38), Tjänstebeskrivning 1.0
dokumentversion 1.6, Allmänna villkor 1.0 (from 2025-11-20); AGD inlämning
RAML 1.7.7; AGD hantera redovisningsperiod RAML 1.2.8; Skattekonto RAML 2.1.0;
Kundhändelser RAML 4.0.5.

- **Two key pairs.** The OAuth2 client id and secret are used only at the
  authorization server; the API gateway client id and secret go as headers
  (`client_id`, `client_secret`) on every API call, with the bearer token and
  a correlation id of at most 36 characters. The correlation header's name
  differs per API (`skv_client_correlation_id` for Momsdeklaration,
  `SKV-client_correlationid` for AGD inlämning and Skattekonto v2), and
  Skatteverket blocks calls with headers a service does not list.
- **Sign-in.** Authorization code grant with e-legitimation ("per"):
  `https://peroauth2(.test).skatteverket.se/oauth2/v1/per/{authorize,token}`.
  `response_type=code` only; the code lives five minutes; the token endpoint
  takes a form-encoded body; an access token lives 3600 seconds and the per
  flow gives a refresh token that lives 65 minutes. Tokens are reused for
  their whole life: Skatteverket caps how many valid tokens an identity holds.
- **Who acts for whom.** The person's identity comes from the sign-in; the
  company is in the path (12 digits). Skatteverket checks the person's role
  in its register of representatives on every call (403 otherwise). For VAT
  drafts: behörig firmatecknare, deklarationsombud, "Momsdeklaration, ombud"
  or representative for an enkelt bolag or partrederi.
- **Momsdeklaration.** `POST kontrollera/{redovisare}/{period}` checks
  without saving; `POST utkast/{redovisare}/{period}?lock=true` saves and
  locks and returns `signeringsLank` (show it only when `last` is true);
  `GET inlamnat/…` gives the receipt; `GET beslutat/…` the decided amounts.
  The period is the last month as ÅÅÅÅMM. One field per box, whole kronor,
  `summaMoms` (box 49) required. Check results are OK, WARNING or ERROR with
  Skatteverket's codes and texts. A draft is removed 30 days after it was
  loaded unless someone has worked on it or signed it (Tjänstebeskrivning
  2.2). A locked draft must be unlocked (`DELETE las/…`) before a new one is
  saved; 409 while it is being signed. The return is filed only when an authorised person signs it at
  Skatteverket; the API cannot sign.
- **Terms that bind the software** (Allmänna villkor, all five APIs): the
  software company must be registered, VAT-registered, approved for F-skatt
  and free of tax debts; personal data from Skatteverket is received only in
  the EU/EEA; Skatteverket's logo is not used; the user is told that loading
  a draft is not filing, the outcome of every operation and the meaning of
  errors, the purpose of the signing link, where the draft can be changed,
  and that Skatteverket audit-logs every call.

## Decision

### Shared foundation

- `config/services.php` `skatteverket`: environment (`test` or
  `production`) and the four keys. Without the keys nothing is offered.
- `SkatteverketAuth`: the per sign-in. It asks only for the scopes of the
  stages that are built (`Skatteverket::SCOPES`: `momsdeklaration`, and since stage 2 `agd` and `agdredovisningperiod`);
  each stage adds its own (GDPR art. 5.1 c, Allmänna villkor 4.2). A person's tokens are stored in
  `skatteverket_tokens`, one row per user, encrypted, never logged, refreshed
  once when the access token has run out and the refresh token still lives,
  deleted when neither lives, and pruned nightly by `privacy:prune`. The
  sign-in is personal, not a company's: the same person can act for every
  company Skatteverket lets them represent.
- `SkatteverketAuthController`: `/anslutningar/skatteverket` starts the
  flow, `/anslutningar/skatteverket/klar` finishes it (the redirect URI
  registered with Skatteverket). The flow lives in the session, works once,
  belongs to the person who started it and lasts ten minutes. It only ever
  returns to a path inside NONOX.
- `SkatteverketClient`: the gateway call with the headers above; an error is
  `SkatteverketFailed` with Skatteverket's own text for the user
  (`meddelandeTillAnvandare`) when there is one, else a Swedish text per
  status. A 401 ends the person's sign-in.

### Stage 1: momsdeklaration (this change)

- On **Bokföring → Moms**, a panel under the return: sign in with BankID,
  **Låt Skatteverket kontrollera** (vat:prepare), **Skicka till
  Skatteverket** (vat:submit, with a confirmation), **Skriv under hos
  Skatteverket** (the signing link, opened in a new tab, only a link to a
  skatteverket.se host), **Hämta status från Skatteverket** (receipt and
  decided amounts, compared with what NONOX sent).
- The boxes come from the same `VatReturn::calculate` the page shows; the
  mapping box → field is `MomsPayload`.
- `vat_submissions` (company-scoped) keeps the boxes sent, the check result,
  the signing link, the receipt number and the decided boxes. Never who
  signed: Skatteverket's `inlamnadAv` is not stored.
- Nothing is booked by sending; the VAT is booked as before.

### Later stages

2. **Arbetsgivardeklaration**: upload the AGI XML the payroll module already
   builds (`POST underlag`, poll `kontrollresultat`), then
   `skapaGranskningsunderlag` for the link to review and sign on Mina sidor;
   the period's status, events and receipts from "hantera
   redovisningsperiod".
3. **Skattekonto**: balance and transactions (`saldo`, `transaktioner`),
   reconciled against account 1630, and payment blocks. The
   "huvudmäns saldo och transaktioner" API (scope `skahmst`, files through
   E-transport, organisation certificate only) is for accounting firms and is
   a separate decision.
4. **Kundhändelser**: Skatteverket's messages and requests about the
   company, into Mina datum and the overview (built; see the addendum).
5. **NONOX Connect**: only once Skatteverket has answered the question
   below. Until then NONOX Connect passes on no content from Skatteverket's
   APIs, neither reads nor webhooks.

## Open questions (must be answered before production)

- `TODO(legal-verify)`: whether NONOX Connect may pass content from
  Skatteverket's APIs (receipts, decided amounts, check results) to a
  third-party app the customer has connected, and whether such an app may
  trigger calls on the customer's behalf, or whether either is use for
  another purpose than "att hantera momsdeklarationen" (Allmänna villkor
  4.2 and 8.1). Ask Skatteverket (icc@skatteverket.se). Until answered,
  Connect gets nothing from these APIs.
- No production keys may be set on the hosted service (nonox.app) before
  this is done. Test keys may be set there only together with
  `SKATTEVERKET_PILOT_USERS` (addendum 2026-11-06): without that list, real
  users see the panel.
- `TODO(legal-verify)`: the privacy policy and the register of processing
  must describe the sign-in with Skatteverket, the personal data sent (a sole
  trader's personnummer is the company identifier) and received, before the
  production keys are set (legal version bump and notice).
- `TODO(provider-verify)`: the scope separator (a space, as OAuth 2.0) and the
  refresh request (OAuth 2.0 form): confirm in the test service.
- `TODO(legal-verify)`: a ten-digit personnummer stored with "-" for a sole
  trader aged 100 or more is read a century too late.
- Production needs the agreement signed by AI Reports Sweden AB's
  firmatecknare on Mina sidor; the production keys come by e-mail and SMS.

## Consequences

- Personal data from Skatteverket may only be received in a technical
  environment physically inside the EU/EEA (Allmänna villkor 4.2): an
  installation using these APIs must run there. The hosted service runs in
  Stockholm; self-hosters are told so in `.env.example`.
- Continued use counts as accepting changed terms (Allmänna villkor 11.2).
  The operator follows Skatteverket's change policy: when the RAML version
  or the terms on the developer portal change, the stage owner re-reads them
  and records the version in this ADR before the next deploy.

- The eSKD file stays as the way for installations without an agreement.
- A self-hosted installation needs its own agreement with Skatteverket; the
  keys are the operator's and never shared.
- Every stage ships behind the same rule: shown only when the keys are set,
  legal and code review before deploy, Beta until a human accountant has
  checked a real return through the test service.

## Addendum 2026-11-06: pilot users

Skatteverket's test service refused the local redirect URI although it was
registered, so the operator tries the test service on the hosted service.
`services.skatteverket.pilot_users` (`SKATTEVERKET_PILOT_USERS`, comma-separated
e-mail addresses) limits the integration to those users: the panel, the
sign-in routes and every action check `Skatteverket::isAvailableTo($user)`.
An empty list means everyone with the right permission, as before. On
nonox.app the list holds only the operator's own address while test keys are
set; the test service receives fixed test identities and files nothing.

## Addendum 2026-11-06: stage 2, arbetsgivardeklaration

Read for this stage (the primary sources): AGD inlämning RAML 1.7.7 with
library 1.0.13, Tjänstebeskrivning Arbetsgivardeklaration inlämning v1.7
dokumentversion 1.23 (2025-10-17), Allmänna villkor Arbetsgivardeklaration
inlämning 1.0 (from 2018-10-25); AGD hantera redovisningsperiod RAML 1.2.8
with library 1.0.26, Tjänstebeskrivning v1.2 dokumentversion 1.11
(2025-10-14), Allmänna villkor 1.0 (from 2020-10-01).

Facts:

- **Headers.** Inlämning's traits name the correlation header
  `SKV-client_correlationid`; hantera redovisningsperiod's traits name it
  `skv_client_correlation_id` (like Momsdeklaration). Each call sends only its
  own. POST operations require `Content-Type` (`application/xml` for the
  upload, `application/json` otherwise); GET and DELETE carry none.
- **Flow.** `POST underlag` takes the AGI XML and answers `inlamningId`;
  Skatteverket checks asynchronously (3 seconds for up to 10 individuppgifter,
  up to 12 minutes for the largest files). `GET underlag/{id}/kontrollresultat`
  answers `PROCESSING`, `DONE_SUCCESS` (saved in the own space),
  `DONE_FAILED` (errors: save with `POST …/spara` or cancel with
  `DELETE underlag/{id}`; cleared after 24 hours otherwise) or
  `DONE_REJECTED` (nothing saved). A saved upload is removed with
  `DELETE arbetsgivare/{ag}/redovisningsperioder/{period}/inlamningar/{id}`.
  Only the person who made the upload can fetch its result, save, cancel or
  remove it. `POST …/skapaGranskningsunderlag` answers the deep link to Mina
  sidor with `tillstand` UNLOCKED or LOCKED_FOR_SIGNING, or 409 with
  RECEIVING, CALCULATING, INCORRECT_DATA (with a link to the error report),
  SIGNING or SIGNED (the service description adds the last two and also
  spells INCORRRECT_DATA once; both spellings are read).
- **Hantera redovisningsperiod.** `grunddata` (state of the own space, signed,
  deadlines, counts), `handelser` (kept at most 60 days, with the person who
  caused each event) and `kvittenser` (one per signing, with `signeradAv`).
  Its errors carry only `message`, so NONOX shows its own Swedish text.
- **Terms** (Allmänna villkor inlämning 8, hantera redovisningsperiod 8):
  tell the user that the API only reads data into the own space and that
  the obligation is not fulfilled by it; that review and filing happen in
  the e-service Arbetsgivardeklaration on Mina sidor with e-legitimation;
  clearly when data was and was not saved, and why; the meaning of errors;
  the purpose of the deep link; and that Skatteverket audit-logs the calls and
  keeps the logs up to 5 years.

Decisions:

- The file is the one `AgiSubmissions::generate` already builds and stores
  with its checksum; there is no second builder. Only a file still marked
  "Skapad, inte inlämnad" can be sent, and its checksum is checked first.
  The file download stays for installations without an agreement.
- **The check result is fetched with a button, not a job.** Every call needs
  the uploader's own token (Skatteverket lets only the uploader fetch the
  result), which lives about an hour and belongs to the person, not the
  company; a queued job would have to carry a person's sign-in. The
  processing times (seconds for a normal company) make a button enough.
- **NONOX never locks the period** (`lasPeriod` left out). The review link
  works on an unlocked period, and a corrected file can always be read in;
  `las`/`lasUpp` are therefore not used. A period locked elsewhere answers
  400 (kod 33) and Skatteverket's text is shown.
- **Replacing a draft** (revised after review). Before a new file is read
  in, the period's live state is read (`grunddata`): LOCKED,
  LOCKED_FOR_SIGNING or SIGNING stops the upload with a Swedish reason.
  The person's own earlier upload still marked as being checked is asked
  about first (404 marks it removed; still PROCESSING stops the upload).
  Nothing earlier is removed when the file is sent: only once the new
  upload is saved (its check result DONE_SUCCESS, or "spara ändå") are the
  person's own earlier unsigned uploads cancelled or removed, after a new
  live check. When the period is signed (`signerad`, `omprovningIniterad`
  or SIGNED) or locked, or a receipt signed after an upload is known, the
  earlier upload is never removed: the new file corrects a filed
  declaration. A removal Skatteverket refuses does not undo the new upload;
  the toast says so. A refused new file leaves the earlier draft as it was.
- **Only the uploader acts on an upload** (Tjänstebeskrivning 1.23, 4.1.1):
  check result, save, cancel, remove and the review link are refused for
  another person's upload without a call, and the panel shows who sent it
  (the NONOX user's name, read from `users`, not stored anew) and hides the
  actions. Another person's upload never blocks a new one; the person is
  told it remains in the own space and can be removed on Mina sidor. A
  check result answered 404 (kod 32, also after the 24-hour clean-up)
  marks the upload removed.
- **Errors** (Allmänna villkor 8 a d, 8 b): a refused upload says
  "Ingenting har sparats i företagets eget utrymme hos Skatteverket",
  Skatteverket's text and a Swedish explanation for kod 20, 27, 30, 31, 32,
  33, 38, 40 and HTTP 413, 415, 429 and 5xx (`AgdErrors`). A timeout or 504
  on the upload says the file may have arrived and to look on Mina sidor or
  fetch the status before sending again. Schema validation texts are marked
  Formatfel with a Swedish explanation. The audit-log notice has the same
  prominence as the text that loading is not filing.
- **The review link** is shown only with a skatteverket.se link and
  `tillstand` UNLOCKED or LOCKED_FOR_SIGNING.
- **What is kept** (`agi_uploads`, company-scoped, tenancy-tested): the
  upload id, statuses, counts of errors and warnings, the review link (or
  error report link, only skatteverket.se hosts, `SkatteverketLink`), the
  period's state, number of receipts and the latest receipt's id and time,
  and the user's id. Never a personnummer, a name, the contacts, who caused
  an event or who signed (`signeradAv`). Skatteverket's error texts and the
  events are shown when fetched and not stored, because error texts can quote
  the file.
- **Permissions.** Uploading, saving, removing and the review link need
  `payroll:agi-submit`; the period's status also `payroll:archive-view`.
  Everything needs the payroll module on and `Skatteverket::isAvailableTo`.
- **Scopes** (revised after review, GDPR art. 5.1 c). `agd` and
  `agdredovisningperiod` are added to `Skatteverket::SCOPES`, but a sign-in
  asks only for the scopes of the service it starts from: the query value
  `tjanst` is mapped through a fixed allow-list (`Skatteverket::SERVICES`:
  `moms` → `momsdeklaration`, `arbetsgivardeklaration` → `agd` and
  `agdredovisningperiod`; anything else is `moms`). One token is kept per
  person, so a person who still holds a usable sign-in is asked for the
  union of its scopes and the new service's, and the new token replaces the
  old. The stored scope is Skatteverket's answer, or the scopes asked for
  when the answer names none; a refresh keeps the stored scope when its
  answer names none. A stored sign-in whose scope lacks the API's scope
  counts as not signed in for that API, and a 403 naming a scope ends the
  sign-in; the person signs in again.
- **Read-only for the archive role.** `payroll:archive-view` may read the
  period's status, but nothing is written to `agi_uploads` or the audit log
  for it. The panel's Skatteverket answers are `#[Locked]` Livewire state.
- Nothing is booked; accounting behaviour is unchanged, so the
  systemdokumentation is not changed.
- `TODO(provider-verify)`: whether removing a saved upload and reading in a
  new one is how Skatteverket expects a draft to be replaced, or whether a new
  upload replaces individuppgifter with the same specification number on its
  own.
- `TODO(provider-verify)`: the inlämning RAML documentation says the test and
  production services require OAuth 2.0 with an organisation certificate,
  while its `securedBy` and the change log (1.5) list e-legitimation ("per").
  NONOX uses the per flow as in stage 1; confirm in the test service.
- `TODO(provider-verify)`: whether `handelsetext` comes as a key
  (`andradeUppgifterSparade`, RAML example) or as the Swedish sentence
  (Tjänstebeskrivning 8.2); NONOX shows what comes.
- `TODO(provider-verify)`: whether a token's `scope` answer names the
  granted scopes separated by spaces (OAuth 2.0).

## Addendum 2026-11-06: stage 3, skattekonto

Read for this stage (the primary sources): Skattekonto RAML 2.1.0
(`skattekonto-extern.raml`, trait `traceable_extern`, schemas and examples),
Tjänstebeskrivning Skattekonto Partner API, tjänstens version 2.1,
dokumentversion 0.9.2 (2025-10-24), Allmänna villkor för Skatteverkets API
Skattekonto version 1.0, the test data "Skattekontot via API – Testfall och
test data" (2025-08-15) and the example file of Skattekontoutdrag 1.2 (for
the transaction texts only; that file belongs to the separate huvudmän API).

Facts:

- **Paths and headers.** `GET /beskattning/skattekonto/v2/skattekonton/{omfragad}/`
  `saldo` (optional `datum`, from 1 January seven years back up to when the
  account was last updated), `transaktioner` (optional `datumFrom`; without it
  18 months back, never more than 915 days), `utbetalningssparrar` and
  `utbetalningskonto`. `omfragad` is the twelve-digit identifier (`Redovisare`).
  The headers are `Accept`, `Authorization`, `client_id`, `client_secret` and
  the correlation header `SKV-client_correlationid` (trait and Tjänstebeskrivning
  5.3). Scope `ska`.
- **Answers.** Whole kronor; a minus sign is a debt. `saldoSkatteverket` and
  `saldoKronofogden`, preliminary interest for both, `ocrNummer`,
  `nastaAvstamningsdatum`, `senastUppdaterad`, `informationstext`. Earlier
  transactions always carry `transaktionsidentitet`; coming ones lack it for
  debited preliminary tax. Errors carry `felkod` and `felmeddelande` (not
  `meddelandeTillAnvandare`): 1 Felaktigt organisationsnummer (400),
  2 Felaktigt datum (400), 3 Registrerat skattekonto saknas (404), 4 Internt
  fel (500), 5 Skattekontot är stängt (503), 6 Auktorisation saknas,
  ombudsbehörighet saknas (403, in the Tjänstebeskrivning but not among the
  RAML examples). 429 has no body. The service has a window 03.00–05.00.
- **Terms** (Allmänna villkor 5, 7.1, 7.3, 8): personal data received only in
  the EU/EEA; use only for "bokföring, redovisning och uppföljning", by a
  principal or someone with authority from the principal; after delivery the
  company is controller and the software company its processor; the end user is
  told that Skatteverket audit-logs every request and keeps the logs up to
  5 years to prevent and investigate security incidents.

Decisions:

- `ska` is added to `Skatteverket::SCOPES` and the service `skattekonto` to
  `Skatteverket::SERVICES`, with the same per-service sign-in as stage 2.
- **Utbetalningskonto is not used.** It returns the bank account Skatteverket
  pays refunds to, which reconciling 1630 does not need (GDPR art. 5.1 c,
  Allmänna villkor 7.1).
- **The page** is Bokföring → Skattekontot (`/skattekontot`,
  `accounting.taxAccount`), next to Bankavstämning, Beta, shown only with
  `Skatteverket::isAvailableTo`. Reading needs `reports:view`; booking also
  `vouchers:post` (and SaveDraftVoucher's `vouchers:create`).
- **Nothing from Skatteverket is stored when it is read.** The answer is
  `#[Locked]` Livewire state for the page. Only a booked transaction is kept:
  `tax_account_postings` (company-scoped, tenancy-tested) holds Skatteverket's
  transaction id, the date, the amount in öre, the counter account and the
  confirming user's id, one row in force per company and transaction (a
  partial unique index), so a transaction is never booked twice. Rows are
  append-only (model events and a PostgreSQL trigger, like `audit_logs`);
  the only change allowed is setting `superseded_at` once (and the user id
  becoming null when the user is deleted). When the voucher of a booking has
  been corrected, the transaction counts as missing again with a note naming
  the corrected voucher, and booking it again marks the earlier row
  superseded in the same transaction.
- **Reconciliation.** 1630 on the reconciliation day (opening balance plus
  posted vouchers, as the bank reconciliation) against `saldoSkatteverket`;
  with today's date Skatteverket's latest balance is compared with 1630 on the
  day of `senastUppdaterad`. Matching is conservative: booked from the tax
  account (by id), else one voucher's net 1630 movement with exactly the same
  amount on the transaction day or the interest day, else a day's remaining
  transactions that together equal exactly one voucher that day (the employer
  declaration is often booked as one row while the tax account shows tax and
  contributions apart). Each voucher is used once; vouchers left over are
  shown as "Bokfört på 1630 men inte på skattekontot". A corrected voucher is
  never used, nor a correction whose 1630 movement only reverses it. Kronofogden's
  transactions and transactions without an amount are never proposed.
- **Booking.** A person chooses the counter account and confirms; the
  transaction is read again from Skatteverket and must still be missing. One
  voucher in series A (manual bookkeeping, as no series is reserved for the
  tax account), dated `transaktionsdatum`, 1630 against the account, through
  `BookVoucher` (SaveDraftVoucher + PostVoucher), source MANUAL with
  source_type/source_id pointing at the posting; audit
  `tax_account.transaction_booked` with ids, amounts and accounts only.
- **Proposals** only for texts that Skatteverket's own examples show:
  "Inbetalning bokförd …" → 1930, "Moms <månad> <år>" → 2650,
  "Arbetsgivaravgift <månad> <år>" → 2730 or 2731 (below), "Avdragen skatt <månad> <år>"
  → 2710 (without a month the same text is a person's own final tax),
  and for legal persons only "Debiterad preliminärskatt" → 2518 and
  "(Korrigerad) intäktsränta" → 8314. Kostnadsränta has no proposal: no
  source shows its text yet. A proposal is preselected only when the account
  is active in the company's chart (2518 is not in the seeded charts);
  otherwise the page names the usual BAS account and the person chooses.
  Employer contributions (revised after review): the payroll module books the
  liability on 2730 (`SalaryVoucher::CONTRIBUTIONS_LIABILITY`) while the
  seeded charts hold 2731, so proposing 2731 would leave 2730 uncleared.
  NONOX proposes 2730 when payroll is on and 2730 is in the chart; else the
  one of 2730 and 2731 in the chart; with both, the one with a credit balance
  on the reconciliation day, and none when the balances do not tell them
  apart.
- **Rate limit.** A person fetches at most five times a minute (each fetch is
  three calls; Tjänstebeskrivning 2.3 limits calls per second and consumer).
- The year-end checklist's point "Skattekontot är avstämt" links to the page
  with the year's last day, for a person the integration is offered to.
- The systemdokumentation is updated (0.108): confirmed postings are
  accounting behaviour.

Open:

- `TODO(legal-verify)`: whether the tax account statement must be kept as
  verifikation in addition to the API data (BFL 5 kap. 6–7 §§); the manual
  tells the user to keep it.
- `TODO(legal-verify)`: how preliminary tax and interest on the tax account
  are booked for a sole trader or a partnership (no proposal until then), and
  that interest income on the tax account is tax-free (8314).
- `TODO(legal-verify)`: whether a debt handed to Kronofogden stays on 1630;
  NONOX compares 1630 with Skatteverket's part only.
- `TODO(provider-verify)`: the exact text of kostnadsränta in the test service.
- `TODO(provider-verify)`: whether `saldo?datum=` is the balance at the end
  of that day; whether `senastUppdaterad` comes with "Z" (schema) or without
  (Tjänstebeskrivning); without a zone it is read as Swedish time.
- Test service: the person signing in for Skattekonto is 199008252398 (test
  data 2025-08-15), not the one for moms and AGD; the test companies per case
  are in that document.

## Addendum 2026-11-06: stage 4, kundhändelser

Read for this stage (the primary sources): Kundhändelser RAML 4.0.5
(`foretagochorganisationer-kundhandelser.raml`, traits
`icc-standard-request` and `icc-standard-errors`, schemas `common.json`,
`kundhandelse_external_request.json` and
`kundhandelse_external_response_schema.json`; the request and response
examples are commented out in this version), Tjänstebeskrivning
Kundhändelser via Partner API, tjänstens version 4.0, dokumentversion 1.1
(2025-10-24), Allmänna villkor för Skatteverkets API Kundhändelser version
1.0 and the agreement template. The separate API "Mina ärenden
kundhändelser" (its own agreement and terms) is not used.

Facts:

- **Path and headers.** One operation, `POST
  /foretagochorganisationer/kundhandelser/v4/hamta`. The trait lists
  `Content-Type`, `Accept`, `client_id`, `client_secret`, the correlation
  header `skv_client_correlation_id` (as Momsdeklaration, not as AGD
  inlämning or Skattekonto) and `Authorization`. `Accept-Language` is in the
  service description (5.2) but not in the RAML, so it is not sent. Scope
  `kundhandelser`.
- **Body.** `{kund: {identifierare, typ}}` with the twelve-digit identifier
  (`Redovisare`, "16" and the number for a legal person) and typ
  `Organisationsnummer`. Without `startDatum` Skatteverket answers two years
  back, without `slutDatum` up to now, and without `kundhandelseTyper` every
  type the person may see. `anvandare` must not be sent: Skatteverket takes
  the person from the sign-in.
- **Answer.** `metadata` (the kund, `anvandare` = the person's personnummer,
  version, roles) and `kundhandelser[]`, each with the required
  `kundhandelseId`, `kundhandelseTyp`, `producent`, `tidpunkt` (RFC 3339),
  `rubrik`, `beskrivning`, `sprak`, `producentarendetKraverKundatgard` (the
  company "has the ball") and `producentarendetKlart`, and the optional
  `referenser` (`etjanst`: a string, `{url, lanktext}` or a list of either)
  and `utokadInformation` (`forfallodatum`, `aviseringsmeddelande` and
  others). Skatteverket removes an event after two years (2.2.3).
- **Errors** carry only `{"message": …}`; 503 is plain text (5.4): 403
  "Ombudsbehörighet (ombudsroll) saknas för efterfrågad Huvudman." when the
  person is not the company's representative; 429 too many calls; 500, 503
  and 504 "Anropa igen efter 1 min.".
- **Terms** (Allmänna villkor Kundhändelser): the API is for legal persons
  (1.1); when the recipient and the end user are not the same person, the
  recipient must make the events available to the end user without delay
  and in full (5.1); personal data only in the EU/EEA (5.2); a principal may
  let a representative that is the software company fetch on a schedule
  (5.3); use only to get feedback on the principal's matters with
  Skatteverket (7.1); the content can be personal data and secret (7.3);
  after delivery the recipient is controller and the software company the
  end user's processor (7.4); tell the end user the conditions, and highlight
  that Skatteverket audit-logs the calls and keeps the logs 5 years (8).

Decisions:

- `kundhandelser` is added to `Skatteverket::SCOPES` and the service
  `kundhandelser` to `Skatteverket::SERVICES`, with the per-service sign-in
  of stage 2.
- **Legal persons only.** A company whose number is a personnummer (a sole
  trader) gets a notice and no call: the terms cover legal persons, and a
  personnummer would bring the owner's private matters (income tax and
  others) into the company for everyone with access to see.
- **Fetched on a person's click, never in the background.** 5.3 allows
  scheduled fetching, but NONOX's sign-in is the person's own and lives about
  an hour (stage 2, AGD check result). At most five fetches a minute per
  person. Every fetch asks for everything (no dates, no types), so the
  stored copy is complete.
- **Stored, because the events must reach the end user in full** (revised below: per fetcher).
  `skatteverket_events` (company-scoped, `BelongsToCompany`,
  tenancy-tested), unique per company and `kundhandelseId`, upserted on each
  fetch: id, type, producer, time, heading and description in full (escaped,
  never shortened below 20 000 characters), language, the two flags, the due
  date, links that go to a skatteverket.se host (`SkatteverketLink`, at most
  ten), when it was fetched, and when and by whom it was marked handled. Not
  kept: `metadata.anvandare` (a personnummer), the roles, the case and
  consumer references, `aviseringsmeddelande`, `kundamne` and the other
  extended fields. An event no longer in Skatteverket's answer stays until it
  is pruned. `privacy:prune` deletes events two years after their time (or,
  without a time, two years after the last fetch), as Skatteverket does.
- **Where.** A section "Från Skatteverket" (`#fran-skatteverket`) on Mina
  datum (`App\Livewire\Guide\SkatteverketEvents`), also when Företagsguiden
  is off: events waiting for the company first, then the ten latest others
  and "Visa alla händelser". Översikt shows a card with the number of events
  waiting for the company when there are any (one count query, not cached so
  marking one handled shows at once). The events are not deadlines: the
  engine stays computed from the company's settings (ADR-051), and a due
  date is shown on the event.
- **Permissions** (revised below: only the fetcher sees and marks). Reading and fetching need `reports:view`; marking an
  event handled or not handled needs `tasks:manage` (owner, admin,
  accountant, bookkeeper), so the auditor reads only. Everything needs
  `Skatteverket::isAvailableTo`; for anyone else the section renders nothing
  and Översikt counts nothing. Marking tells Skatteverket nothing.
- **Scope handling.** A sign-in without `kundhandelser` counts as not signed
  in for this service: the section asks the person to sign in again, and the
  new sign-in asks for the union with the scopes the person still holds. A
  403 naming a scope keeps the sign-in (it still serves the other services);
  only a 401 ends it.
- **Audit log**: `skatteverket.events_fetched` with counts (fetched, new,
  awaiting) and `skatteverket.event_handled` / `skatteverket.event_reopened`
  with the row id; never the texts.
- **Disclosure** on the section: fetched only on a click; shown in full; use
  only for feedback on the company's matters, by a representative, checked by
  Skatteverket; stored for the company and removed after two years; may hold
  personal data; and, emphasised, Skatteverket's audit logging and its five
  years. Beta, and a notice in the test environment.
- Nothing is booked; accounting behaviour is unchanged, so the
  systemdokumentation is not changed. NONOX Connect gets nothing (open
  question above).

Open:

- `TODO(legal-verify)`: whether Skatteverket allows events one person
  fetched under their own authority to be shared with the company's other
  users (secrecy in 27 kap. OSL, Allmänna villkor 2, 5.1, 7.3–7.4). Until
  answered, the decision below holds: only the fetcher sees them. Also the
  legal basis recorded in the register of processing, and who is recipient
  and controller when an employee fetches for the company (7.4).
- `TODO(legal-verify)`: whether storing the events for two years fits
  Allmänna villkor 7.1 (the purpose) and GDPR art. 5.1 e, or whether a
  shorter period is better.
- `TODO(provider-verify)`: the request and response examples are commented
  out in RAML 4.0.5; the fixture is written to the schema. Confirm the shape
  (and that `tidpunkt` carries a zone) in the test service.
- `TODO(provider-verify)`: whether a gateway refusal for a missing scope
  comes as `error` (as AGD) or as `message`; NONOX reads both.
- `TODO(provider-verify)`: whether `typ` is accepted as
  "Organisationsnummer" (schema enum) where the service description writes
  "organisationsnummer".

### Revised after review (2026-11-06)

- **Only the fetcher sees the events.** Allmänna villkor 2 defines the end
  user as the authorised representative who makes the request, 5.1 asks for
  the content to be made available to that end user, and 7.3 says it can be
  secret. The first version showed stored events to everyone with
  `reports:view`; now every row has `fetched_by` (users, nullOnDelete), the
  unique key is (company, fetcher, event id), and an event two people
  fetched is two rows, each with its own handled mark. The list, the full
  text, Översikt's count and marking all read the person's own rows; anyone
  else's id is not found (404). Other users see "Händelser hämtas och visas
  för den som själv har loggat in hos Skatteverket" and fetch their own.
  Marking now needs `reports:view` and the row being one's own (the auditor
  can mark their own copy); `tasks:manage` is no longer used. A row whose
  fetcher's account is deleted is pruned nightly.
- **Extended fields are kept** as content the end user reads with the event
  (Tjänstebeskrivning 4.1.2.5): `kopplingsId`, `skatteslag`, `kundamne`
  (string or list), `korrespondensReferens` and `aviseringsmeddelande`.
  `foretagsform` is the company's own data and is not kept.
- **Waiting, by chain.** An event waits for the company only when it asks
  for action, is not itself closed (`producentarendetKlart`), is not marked
  handled, and no later event of the same person has the same `kopplingsId`
  (the service description's example: "Lämna in deklaration …" followed by
  "… inlämnad" with the same id). Events without a `kopplingsId` stand alone.
  A superseded request says "En senare händelse i samma ärende har kommit."
- **Links outside skatteverket.se** are kept and shown in full as text,
  never clickable, instead of being dropped, so the event is shown in full.
- **NUL bytes** are removed from every text Skatteverket sends (PostgreSQL
  refuses them).
- Migration `2026_12_09_100100_add_fetcher_and_details_to_skatteverket_events`
  (additive; the table was not yet deployed).

