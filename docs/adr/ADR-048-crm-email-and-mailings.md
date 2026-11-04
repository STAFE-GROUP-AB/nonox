# ADR-048: CRM stage 5, e-mail and mailings

- Status: **Accepted, implemented** (2026-11-04), to be shown to the owner before stage 6
- Builds on: ADR-043 (CRM; its GDPR rules on legal basis, Kontakta inte,
  the suppression list and restriction are binding here), ADR-023 (Inbox:
  per-company address, Mailgun inbound, IMAP, authentication),
  ADR-030 (branding), ADR-032 (test companies send nothing)

## Context

The owner's brief for stage 5: "Two-way e-mail sync with automatic logging
on the right customer. Templates and simple newsletters to segments, with
statistics on clicks. Web forms that create leads directly." ADR-043 placed
logging by BCC/forward, templates, newsletters to segments with click
statistics and unsubscribe, and web forms in stage 5; full two-way Gmail
and Outlook sync needs app registrations and belongs to stage 6.

The owner's decision (ADR-043, decision 2): **newsletters count clicks
only.** No tracking pixel, opens are not measured, so no consent under
LEK 9 kap. 28 § is asked for.

## Decision

All of it lives in the CRM module (`app/Modules/Crm`), with the module's
tables and the module's switch. Nothing is sent or received while CRM is
off.

### 1. Logging e-mail through the +crm address

- **Address.** The inbox's per-company address with the alias `+crm`
  (`{slug}+crm@in.nonox.app`; `InboxAddress::CRM_ALIAS`). Chosen over a
  separate local part so a company has one address to learn and one slug
  to replace; replacing the inbox slug replaces this address too (shown on
  the page). The alias is routed to the CRM before the inbox's own checks,
  so CRM logging works in the cloud **without the Inbox module**; the
  company's `inbox_mailboxes` row only holds the slug.
- **Self-hosted (IMAP).** The inbox's fetcher (`FetchImapMail`) hands a
  message to the CRM when it was sent to the mailbox's own `+crm` address
  (`bokforing+crm@firma.se` for the mailbox `bokforing@firma.se`).
  `CrmCopy` reads the envelope headers the receiving server adds
  (Delivered-To, X-Original-To, Envelope-To), since a BCC is in neither To
  nor Cc, then To/Cc. Self-hosted logging therefore needs the Inbox module
  with IMAP and a mail server that delivers plus addresses to the mailbox.
- **Who may log.** Only mail whose From is the sign-in address of a member
  with `crm:manage`, and that passes the inbox's SPF/DKIM/DMARC evaluation
  (`MailAuthentication`, not failed, not spam). Everything else is dropped
  without a bounce and audited (`crm.email_dropped`, reason only). The
  address may be known to suppliers (it is the inbox's), so this is what
  keeps strangers from writing on a customer's timeline. A user who mails
  from another address than the one they sign in with cannot log (open
  item: alias addresses per user).
- **Direction and parties.** A BCC is outbound; the other party is To and
  Cc. A forward is read as its original: an attached `message/rfc822`, or
  the header block of an inline forward (Swedish and English separators
  from the common clients); inbound unless the original's sender is a
  member. Members' addresses, the company's e-mail and anything on the
  inbox domain are never the other party.
- **Matching** on exact addresses only (people's and accounts' e-mail,
  case-insensitive), never on domains: a webmail domain or a group's
  shared domain would put mail on the wrong customer. At most ten accounts
  per mail. One EMAIL activity per matched account, done at the mail's
  date, subject prefixed `→`/`←`, body = a 500-character excerpt
  (`CrmMailReader::excerpt`: quoted lines and the signature removed,
  control characters stripped, whitespace collapsed). The activity resets
  the pruning clock like any contact.
- **Stored.** `crm_emails`: subject, excerpt, and the full plain-text body
  and the addresses **encrypted** (Laravel's encrypted cast, like the
  inbox's encrypted storage), the forwarder, the date, a SHA-256 of the raw
  message (a retried delivery is taken once). HTML is never rendered.
  **Attachments are not kept**, only their names: data minimisation, no
  malware surface, and the original stays in the sender's own mailbox. The
  `.eml` itself is not stored either (unlike the inbox, the CRM keeps no
  underlag).
- **Kontakta inte (stage 1 rules applied):**
  - a matched person with a **restriction** (art. 18), or a matched
    customer with restricted processing: the mail is **not stored at all**,
    dropped and audited with the reason `restricted` (second review round;
    storing new data about a restricted person is not "storage" of what
    was already kept);
  - an **objection** (art. 21): mail *from* the person is their contact
    with us and is logged; mail *to* them is contact by us, so it is not
    logged as an e-mail but HELD in "Ej kopplade", where it can only be
    logged as a **note** ("Mejl skickat: …") or removed;
  - no matched account: UNMATCHED.
- **"Ej kopplade"** (`/kunder/mejl`, `crm:manage`): link to an account
  (and a person there), create a prospect and a person from one of the
  mail's addresses with a chosen legal basis, or remove. Removed after 30
  days if nobody handles it (`crm:prune-mail`).
- **Erasure.** Erasing a person deletes the e-mails on their activities
  and the e-mail they were matched to first (`crm_emails.person_id`,
  cascade); an anonymised customer and an erased prospect lose theirs too;
  the register extract lists them in full.

### 2. Templates and "Mejla med mall"

- `crm_email_templates` (name, subject, plain-text body), written with
  `crm:settings`, used by everyone with `crm:manage` on the account card
  and the person page. Merge fields in curly brackets: `{förnamn}`,
  `{efternamn}`, `{namn}`, `{företag}`, `{säljare}`, `{säljarens_epost}`,
  `{mitt_företag}`; an unknown field stays as written so a typo shows.
- **Sender identity.** From is the service's own `MAIL_FROM_ADDRESS` with
  the company's name as display name; Reply-To is the sender's sign-in
  address. NONOX never puts a company's own domain in From: the hosted
  service can only sign (SPF/DKIM) for its own sending domain, and a From
  it cannot sign would fail DMARC and be spoofing. A self-hosted NONOX
  sends with its own mail configuration. Every message says who sent it and
  where a reply goes.
- Refused for a person marked Kontakta inte, an address on the suppression
  list (objections), a customer that is restricted, inactive or
  anonymised, and without an address. A person who **unsubscribed from
  mailings** can still get a mail that is not marketing (an answer about an
  order) when the sender ticks that it is not marketing. Within the hourly
  `MailQuota`; a test company logs but never sends.
- Logged as a done EMAIL activity through `LogActivity` (its guards apply).

### 3. Segments and mailings

- **Segments** (`crm_segments`, `crm:settings`): saved filters, AND across
  filters and OR within one: account status, kind, tags, responsible
  seller, SNI prefix, city, the person's role at the account, and the
  person's legal basis. The people are found when a mailing is counted or
  started, never stored on the segment.
- **Recipients are people**, not accounts' generic addresses: the legal
  basis is per person.
- **Who may get a mailing** (`MarketingEligibility`, in this order, the
  first reason shown in the composer with a count per reason):
  1. an address, an active person;
  2. not restricted (art. 18), no objection (art. 21);
  3. not unsubscribed, not on the suppression list for any reason, not
     bounced;
  4. at least one segment account whose customer is not restricted,
     inactive or anonymised;
  5. a ground for e-mail marketing under marknadsföringslagen 19–20 §§:
     a dated **consent to e-mail marketing** (`marketing_consent_at`,
     second review round; the general CRM basis "consent" may cover only
     being contacted); or a contact at a **legal person** (account kind
     Företag with an organisation number whose third digit is 2 or more,
     and not group 6, enkla bolag: 19 § protects natural persons, so a B2B
     contact in their professional role can be reached on legitimate
     interest, with the opt-out in every message under 20 §); a sole
     trader is a natural person, and an account without an organisation
     number cannot be shown to be a legal person: both need consent; or
     the **customer exception** (19 § second paragraph) when the
     composer's box is ticked, only for a person at a customer whose
     address is the customer register's e-mail for that customer, or who
     carries the dated mark "Kund, 19 § andra stycket"; the sender
     confirms the mailing is about the company's own similar products and
     the person could say no at collection;
  6. one message per address.
- **Compose** in Markdown (league/commonmark through `Str::markdown`, raw
  HTML stripped, unsafe links dropped; merge values are escaped so a name
  can never become a link), preview in a sandboxed frame, "Skicka test
  till mig" (no tracking, no token). Rendered in the company's colour and
  name with a footer carrying the company's legal name, organisation
  number, **postal address** and e-mail, and the unsubscribe link
  (marknadsföringslagen 20 §, e-handelslagen 9 §). A mailing cannot start
  without the company's postal address. No images are loaded from
  anywhere.
- **Send** (`crm:mailings`, owner and admin only: mass marketing in the
  company's name is decided by those who answer for it; a company gives it
  to others by changing their role). Starting fixes the recipients
  (`crm_mailing_recipients`, at most 20 000 per mailing) and the links.
  Now or scheduled. `crm:send-mailings` runs every minute: scheduled
  mailings whose time has come start, and each sending mailing gets one
  batch on the queue (`SendMailingBatch`, company id in the job, a cache
  lock per company). A batch sends at most 50 and keeps to the company's
  caps (`services.crm.mailing_hourly_cap` 500, `mailing_daily_cap` 2 000).
  Each person is checked again right before their message: who
  unsubscribed, objected or bounced since is skipped. Cancel stops what is
  left. A test company sends nothing.
- **Headers.** `List-Unsubscribe: <https://…/utskick/avregistrera/{token}>`
  and `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058, which
  the large mailbox providers require for bulk mail);
  `X-Mailgun-Track`, `-Opens`, `-Clicks: no`, so Mailgun never adds a pixel
  or rewrites links even if tracking is on for the domain;
  `X-Mailgun-Variables` with the recipient id for the event webhook.
- **Tokens and links** (second review round). A recipient's personal
  token is an HMAC of the mailing id and the person id with a key derived
  from `APP_KEY`; only its SHA-256 is stored, and it is used **only** for
  unsubscribing (the link and `List-Unsubscribe`), never for statistics.
  A **click link names no one**: `/utskick/lank/{link}/{sig}` with an HMAC
  of the link id, the same address for every recipient, so a click
  cannot be tied to a person (LEK 9 kap. 28 §, ePrivacy art. 5(3), EDPB
  Guidelines 2/2023 on tracked URLs). The redirect only ever goes to the
  URL stored for that link (no open redirect). Signatures are also checked
  under keys from `APP_PREVIOUS_KEYS`.
- **Public endpoints outside the web middleware** (no session, no cookie,
  no CSRF; a mail client's one-click POST cannot carry a token): a GET of
  the unsubscribe link only shows a page with one button (mail scanners
  open links); the POST unsubscribes. Throttled per IP.
- **Unsubscribe** = objection to direct marketing (art. 21(3)) for
  mailings only: the person gets `marketing_opt_out_at` and the address
  goes on the suppression list with the reason **MARKETING**. Such an entry
  blocks mailings (and asks for the "not marketing" confirmation on a
  template mail) but does **not** mark anyone Kontakta inte for other
  contact: `SuppressionList::match()` (used by Kontakta inte, win-back and
  the operator sync) leaves MARKETING out, `marketingBlocked()` reads every
  entry. A stronger reason replaces MARKETING on the same hash; withdrawing
  a Kontakta inte objection never removes a MARKETING entry. A new consent
  through a web form does not lift it either (see 4).
- **Statistics** per mailing: recipients, sent, delivered and bounced
  (from Mailgun's events), clicks, unsubscribed, and the clicks per link.
  "Klick räknas per länk. NONOX vet inte vem som klickade, och öppningar
  mäts inte." There is no per-recipient click data anywhere, the register
  extract included.
- **Events** (`POST /webhooks/utskick/mailgun`, JSON, Mailgun's signature
  with the same key, age and replay checks as the inbox): `delivered`;
  `failed` with severity `permanent` (a bounce: the recipient is BOUNCED
  and the person's address gets `email_bounced_at`, cleared when the
  address changes); `complained` (handled as an unsubscribe and counted).
  The event's address must be the recipient's.
- **Automatic pause** (`MailingPause`): more than 0.3 % complaints or more
  than 5 % hard bounces of the messages sent in the last 30 days pause the
  company's mailings (no batch, no start). The owner and administrators
  get an e-mail, the operator company (ADR-044) a note on the company's
  account in its CRM. Someone with `crm:mailings` resumes on the
  Utskick page; audited and noted for the operator.
- **Retention** (`crm:prune-mail`, daily): 90 days after the mailing
  finished, a recipient's address, person and account give way to the
  suppression list's keyed hash of the address (`email_hash`): a stub that
  keeps the unsubscribe link working (marknadsföringslagen 20 §) without
  naming anyone. After three years the stub goes; the mailing keeps its
  counts. Erasing a person (or a prospect, or anonymising a customer)
  removes the address from their recipient rows at once.

### 4. Web forms

- `crm_forms` (`crm:settings`): heading; name and e-mail always; phone,
  company, organisation number and message each off, optional or
  required; the text beside an **optional, unticked newsletter box**
  (versioned: a changed text gets the next version); a thank-you text; the
  owner of new leads; tags; the sites that may frame it; open or closed.
  There is **no required consent box** (second review round): the form
  says ":company använder uppgifterna för att svara på din förfrågan" and
  links to the company's own privacy policy, a company-level CRM setting
  ("Länk till er integritetspolicy") that must be set before a form can be
  saved or a mailing sent.
- **Public page** `/formular/{token}` (32 random characters), outside the
  web middleware: no session, no cookie, nothing loaded from elsewhere, a
  CSP with `default-src 'none'`, a nonce for the one inline script, and
  `frame-ancestors` set to the company's listed https origins (none: the
  hosted page only). Embedding is an iframe plus an optional inline
  snippet that fits the height through `postMessage` (checked against the
  NONOX origin and the form's token); no script is loaded from NONOX into
  the company's site, so no CORS is needed.
- **Spam** without third-party scripts (no reCAPTCHA): a honeypot field, a
  signed time stamp (a form posted within 3 seconds or after a day is not
  taken), and limits of 5 per IP and form per 10 minutes, 60 per form and
  200 per company per hour. Caught submissions get the same thank-you page
  and nothing is stored.
- **A new address** gives a prospect (kind Företag with the company name
  and a valid legal person's organisation number, never a personal
  number; else Privatperson) and a contact person. Without the box:
  legitimate interest, "Kontaktförfrågan via webbformuläret …", and no
  e-mail marketing unless another ground applies. With the box ticked:
  consent dated today and `marketing_consent_at`. `crm_form_submissions`
  keeps the box's text and version, whether it was ticked, the privacy
  link and the time (art. 7.1). Someone on the suppression list for an
  objection is flagged Kontakta inte at once, as in `SavePerson`.
- **An address the CRM already has** gives no duplicate; the person's
  legal basis and pruning clock are **never changed** (anyone can type
  anyone's address, no double opt-in). A ticked box sets
  `marketing_consent_at` unless the person unsubscribed, objected or is
  restricted. For a person marked Kontakta inte only the submission is
  kept: no task, nothing on their timeline.
- Otherwise a TASK "Webbformulär: …" for the form's owner (else the
  company's owner), due the next morning at 09:00, with the message and
  the phone, and an e-mail to that person naming only the form. A form
  with submissions can only be closed, never deleted: the submissions are
  the proof of consent.

### Permissions

- `crm:view`: see "E-post" and the logged mail in full; see templates.
- `crm:manage`: handle "Ej kopplade", log, send a template.
- `crm:settings`: templates, segments, web forms.
- `crm:mailings` (new): compose, test, start, schedule and cancel
  mailings. Owner and admin. Accountant and bookkeeper keep their three
  CRM permissions; the seller and the auditor do not get it.

### Audit

`crm.email_received`/`_dropped`/`_linked`/`_dismissed`/`_sent`,
`crm.template_*`, `crm.segment_*`, `crm.mailing_*` (`_started` with the
recipient count and the exclusions per reason), `crm.unsubscribed`,
`crm.mailing_bounced`, `crm.form_*`, `crm.form_submitted`: ids, statuses,
counts and reasons only, never an address, a name, a subject or a text.

### Review round (2026-11-04)

An independent review of the first version found, and this round fixed:

- A web form overwrote an existing person's documented legal basis with
  consent; now it never changes it (see 4).
- A mailing cancelled while a batch was running could end as Sent, and
  rows already picked were sent after the cancel. Each recipient is now
  claimed with a conditional update (QUEUED → SENT) right before its
  message, and only a mailing still SENDING becomes SENT.
- Withdrawing a Kontakta inte objection deleted the shared hash even when
  the person had also unsubscribed; the e-mail hash now goes back to the
  reason MARKETING instead.
- The one-click unsubscribe POST had a limit of 60 per minute per IP;
  mailbox providers post from shared addresses, so it is now 1 200.
- Recipient rows were deleted after 13 months, breaking old unsubscribe
  links; they are kept as stubs.
- `{företag}` could name another company the person is at; the recipient
  now keeps the account that put them in the segment.
- The hourly and daily caps are per company but the batch lock was per
  mailing; the lock is now per company.
- Click links failed after an `APP_KEY` rotation; signatures are also
  checked under keys derived from `APP_PREVIOUS_KEYS`.

## Second review round (2026-11-04)

The coordinator's review of the first version; every finding was checked
in the code and fixed:

1. **Clicks identify no one**: links carry no recipient token; counts per
   link and per mailing only; the per-recipient click table and columns
   are dropped (see 3).
2. **No remote images**: a Markdown image is shown as its alt text, and a
   merge field inside a link or image address is refused when the mailing
   is saved ("Fält som {namn} kan inte stå i en länkadress.").
3. **Web form consent** reworked (see 4), with `marketing_consent_at` on
   the person, settable in the person form, which MarketingEligibility now
   requires for consent-based recipients instead of the general CRM legal
   basis "consent".
4. **Erasure** removes the address from the person's (or prospect's or
   anonymised customer's) recipient rows in the same transaction.
5. **Restricted** persons and customers: logged mail is dropped, not
   stored.
6. **"Mejla med mall"** footer: "… Vill du inte få fler erbjudanden från
   oss, svara på mejlet så slutar vi."; when MarketingEligibility says
   NeedsConsent the sender must tick "Mejlet är inte reklam (till exempel
   ett svar på en fråga, en offert kunden bett om eller information om en
   order)"; the panel states every condition of the customer exception.
7. **Customer exception** applies only to a person whose address is the
   customer register's e-mail for that customer, or who carries the dated
   mark "Kund, 19 § andra stycket" (person form); the composer box stays
   as the confirmation.
8. **A mailer of its own** (`CrmMailer`): `NONOX_CRM_SMTP_*` defines the
   mailer `crm` (or `NONOX_CRM_MAILER` names one), `NONOX_CRM_MAIL_FROM`
   the From address on a domain of its own such as `utskick.nonox.app`;
   the display name is ":company via NONOX". On the hosted service
   (`NONOX_HOSTED`) mailings and template mail are off until it is
   configured, with a message in the UI; self-hosted falls back to the
   default mailer. Automatic pause on complaints and bounces (see 3).
9. **Legal texts** updated: the DPA (purposes, categories of data subjects
   and data), the sub-processor list (Mailgun's CRM purposes), the terms
   (the customer's responsibility for marketing law, NONOX may pause
   mailings), the art. 14 template in the manual, and the mailing footer
   ("Du kan när som helst invända och avregistrera dig. Så behandlar vi
   personuppgifter: …"). The legal page version is bumped once at the
   final merge.

Also: an existing address's form submission leaves `last_activity_at`
alone; stubs instead of rows (see 3); "Avregistrera från utskick" on the
person page (`UnsubscribePerson`: the unsubscribe without Kontakta inte);
`isLegalPerson` excludes group 6 (enkla bolag); starting a mailing only
counts, and `PrepareMailing` writes the recipients in bulk on the queue
(new status Förbereds); the composer counts with one query of plain rows
(`MarketingCandidates`), and each batch re-checks its recipients with one
query; tenancy tests for every public endpoint; `?visa=` of waiting mail
needs `crm:manage`; the event webhook allows 6 000 requests a minute.

## Final re-check (2026-11-04)

- A bare address that GitHub-flavoured Markdown links by itself
  (`https://x.se/?u={namn}`, `www.x.se/{namn}`) is also refused with a
  merge field in it.
- **Owner decision (a)**: a ticked newsletter box from an address the CRM
  already has is kept only on the submission (`marketing_consent = true`).
  The person page shows "Anmälde sig till nyhetsbrev via formulär :date –
  bekräfta"; someone with `crm:manage` confirms it
  (`ConfirmMarketingConsent`, audited with ids), which sets
  `marketing_consent_at` to the submission's time. New people keep the
  behaviour above.
- **Owner decision (b)**: a company lifts a **complaint** pause at most
  once per rolling 30 days. A second complaint pause in that window is
  marked HQ-only and listed under HQ → Inställningar → Pausade utskick,
  where an HQ admin lifts it with a passkey step (audited for the instance
  as `hq.crm_mailings_resumed`, noted for the operator). A **bounce**
  pause the company can always lift.
- The pause needs at least 3 complaints, or 10 hard bounces, besides the
  rate, so one complaint never pauses a small sender.
- On the hosted service the CRM mailer counts as configured only with
  both a transport and `NONOX_CRM_MAIL_FROM`.
- A batch re-hashes a recipient's unsubscribe token when `APP_KEY` was
  rotated after the mailing was prepared, so the link in every message
  works.

## What a company can and cannot do (deliverability)

- On the hosted service CRM mail goes through its own Mailgun domain
  (for example `utskick.nonox.app`), separate from the domain that sends
  invoices, login codes and reminders, so a complaint about one company's
  newsletter cannot hurt the service's transactional mail. From is that
  domain with ":company via NONOX" as the name; replies go to the company
  (mailings) or the sender (template mail) through Reply-To. A company
  cannot send from its own domain in the cloud in this stage.
- NONOX sends over SMTP (no Mailgun API transport is installed), so the
  `X-Mailgun-Track*` and `X-Mailgun-Variables` headers are read by Mailgun
  as sending options. With an API transport they would have to be set as
  the API's options (`o:tracking`, `v:` variables) instead.
- Self-hosted NONOX uses its own `MAIL_*` configuration (or the
  `NONOX_CRM_*` mailer); the operator is responsible for SPF, DKIM and
  DMARC for the From it configures.
- Self-hosted logging over IMAP trusts the topmost Authentication-Results
  header, or the one with `NONOX_INBOX_IMAP_AUTHSERV_ID`: the company's
  mail server must add one, or every message to `+crm` is dropped as
  unauthenticated (ADR-023).
- Hourly and daily caps per company, the automatic pause, and the per-hour
  `MailQuota` for template mail protect the sending domain's reputation.

## Owner actions needed (hosted)

1. **A sending domain for CRM mail**: in Mailgun (EU) add a domain such as
   `utskick.nonox.app`; add the DNS records Mailgun gives (SPF `TXT`, the
   DKIM `TXT`, the `CNAME` for tracking can be skipped since tracking is
   off) and a DMARC record (`_dmarc.utskick.nonox.app TXT "v=DMARC1;
   p=quarantine; rua=…"`); verify the domain.
2. **SMTP credentials** for that domain (Mailgun → Domain settings → SMTP
   credentials), then set `NONOX_CRM_SMTP_HOST=smtp.eu.mailgun.org`,
   `NONOX_CRM_SMTP_PORT=587`, `NONOX_CRM_SMTP_USERNAME`,
   `NONOX_CRM_SMTP_PASSWORD`, `NONOX_CRM_SMTP_SCHEME=smtp` and
   `NONOX_CRM_MAIL_FROM=utskick@utskick.nonox.app`. Until then mailings and
   template mail stay off on nonox.app.
3. **Tracking off** for that domain (open and click tracking; the messages
   also say so per message).
4. **Events**: Mailgun → Sending → Webhooks for that domain: add
   `https://nonox.app/webhooks/utskick/mailgun` for **Delivered**,
   **Permanent failure** and **Spam complaints**. The signing key is the
   account's HTTP webhook signing key, the existing
   `MAILGUN_WEBHOOK_SIGNING_KEY`.
5. **Inbound for +crm**: nothing new, as long as the inbox route is a
   catch-all `.*@in.nonox.app` forwarding to `/webhooks/inkorg/mailgun/mime`.
6. **Scheduler and queue**: `crm:send-mailings` every minute and
   `crm:prune-mail` at 04:50 through the scheduler; `PrepareMailing` and
   the batches run on the queue worker.
7. Optionally tune `NONOX_CRM_MAILING_HOURLY_CAP` and
   `NONOX_CRM_MAILING_DAILY_CAP`.

## Deviations from the brief, with reasons

- **No two-way sync** (Gmail/Outlook): stage 6, as ADR-043 says.
- **Attachments are not stored** in logged mail (see 1).
- **Recipients are people only**, not accounts' generic addresses.
- **Markdown, not a block editor**: no package, no client-side editor,
  and the text is what is audited and previewed.
- **No double opt-in** on web forms; instead a form never lifts an
  unsubscribe or an objection. A confirmation mail is an open item.
- **No API** for stage 5 in this round (Connect stays at v1.6.0).

## TODO(legal-verify)

- The B2B reading of marknadsföringslagen 19 § for named contact people at
  legal persons (their work addresses), and whether the legitimate-interest
  balancing of stage 1 covers newsletters.
- The customer exception's conditions that NONOX cannot check (address got
  "in connection with a sale", "own similar products"); left to the person
  who ticks the box.
- Whether counting clicks per link (no recipient in the link) is outside
  LEK 9 kap. 28 § / ePrivacy 5(3); NONOX stores nothing on the device and
  can tie no click to anyone.
- Retention: 30 days for unhandled logged mail, 90 days for recipients'
  addresses, three years for recipient stubs, and whether a logged e-mail
  should follow the activity's or the person's retention.
- Whether consent through a web form without double opt-in is provable
  enough (art. 7.1), and the template consent text.
- Whether a template mail to a person who unsubscribed and is marked "not
  marketing" needs more than the sender's confirmation.

## Addendum: held web form submissions (2026-11-04, security review)

The per-form (60 an hour) and per-company (200 an hour) caps on web forms
are shared by everyone, so a flood used to silently drop real leads with the
spam. Now only the per-address limit (5 in ten minutes, IPv6 counted per /64)
drops a submission. A submission over a shared cap that passes validation is
**held**: a `crm_form_submissions` row with `quarantined_at` and what was
typed in `held_payload` (encrypted), with the consent text, version, link and
time as shown. No person, account, task or mail is created. The forms screen
lists held submissions under "Anmälningar att granska"; someone with
`crm:settings` accepts one (it is then taken exactly as a normal submission,
with the consent dated when the form was sent, and the row becomes the
ordinary proof) or deletes it. Unreviewed held submissions are removed after
14 days by `crm:prune-mail`; at most 500 are held per company, beyond that a
submission is dropped and logged. Those with `crm:settings` get an "Att göra"
row while anything is held, and a database notice (`FormHeldNotice`) on the
first held submission of a day and on the first dropped one of a day. An
accepted submission keeps the consent version and the form name the sender
saw (stored with it), in the legal basis note and the `crm.form_submitted`
audit, even if the form changed since. Accepting or deleting one already
handled answers with a toast. The audit log records
`crm.form_submission_held`, `_accepted` and `_discarded` with ids only.
TODO(legal-verify): the 14-day period.

## Addendum: explaining segments in the app (2026-11-04)

The owner found segments hard to understand. The segment page now opens
with a "Så fungerar segment" box (`x-crm::segment-guide`, open while there
are no segments or while one is edited): a segment is a saved filter, not
a list, evaluated when the mailing is started (**Ja, skicka**, also for a
scheduled mailing; `SendMailing::prepare` fixes the recipients then, and an
unsubscribe after that is still honoured), three worked examples, and who
is always left out and why (no e-mail, Kontakta inte or restricted
processing, unsubscribed, suppressed or bounced, and natural persons
without consent under marketing law 19 §). The mailing list and the
composer say the same in one sentence each and link to the segment page.
No behaviour changed.

## Consequences

- New tables: `crm_emails`, `crm_email_templates`, `crm_segments`,
  `crm_mailings`, `crm_mailing_links`, `crm_mailing_recipients`,
  `crm_forms`, `crm_form_submissions` (`crm_mailing_clicks` was dropped in
  the second review round); new columns
  `crm_mailing_recipients.account_id` (review round),
  `crm_people.marketing_opt_out_at`, `email_bounced_at`,
  `crm_activities.email_id`. All company-scoped with tenancy tests.
- The inbox routes one more alias; its own behaviour is unchanged.
- New columns in the second round: `crm_people.marketing_consent_at`,
  `customer_exception_at`, `crm_form_submissions.marketing_consent`,
  `crm_mailing_recipients.email_hash`, `complained_at`,
  `crm_mailings.clicks`, `complaint_count`.
- No new package.
