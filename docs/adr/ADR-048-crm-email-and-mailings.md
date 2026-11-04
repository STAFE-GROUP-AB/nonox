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
    customer with restricted processing: status RESTRICTED, stored only,
    on no timeline, not shown;
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
     consent with a date; or a contact at a **legal person** (account kind
     Företag with an organisation number whose third digit is 2 or more:
     19 § protects natural persons, so a B2B contact in their professional
     role can be reached on legitimate interest, with the opt-out in every
     message under 20 §); a sole trader is a natural person, and an
     account without an organisation number cannot be shown to be a legal
     person: both need consent; or the **customer exception** (19 § second
     paragraph) when the composer's box is ticked: the person is at an
     account that is a customer, and the sender confirms the address was
     got at a sale, the mailing is about the company's own similar
     products and the person could say no at collection;
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
- **Tokens.** A recipient's token is an HMAC of the recipient id with a key
  derived from `APP_KEY`; only its SHA-256 is stored, so a database leak
  gives no working link. A click link also carries an HMAC over the token
  and the link id, and the redirect only ever goes to the URL stored for
  that link of that recipient's mailing (no open redirect).
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
  (from Mailgun's events), unique clickers, unsubscribed, and per link the
  clicks and unique clickers. **Never per person**: who clicked is not
  shown anywhere (it is kept per recipient only to count unique clicks,
  and goes with the recipient row).
- **Events** (`POST /webhooks/utskick/mailgun`, JSON, Mailgun's signature
  with the same key, age and replay checks as the inbox): `delivered`;
  `failed` with severity `permanent` (a bounce: the recipient is BOUNCED
  and the person's address gets `email_bounced_at`, cleared when the
  address changes); `complained` (handled as an unsubscribe). The event's
  address must be the recipient's.
- **Retention** (`crm:prune-mail`, daily): a recipient's address 90 days
  after the mailing finished, the click marks after 13 months; the
  mailing keeps its counts. The row stays as a stub (token hash, person
  id, account id) so the unsubscribe link in any message keeps working
  (marknadsföringslagen 20 §).

### 4. Web forms

- `crm_forms` (`crm:settings`): heading; name, e-mail and the consent box
  always; phone, company, organisation number and message each off,
  optional or required; the company's **own consent text** (versioned: a
  changed text gets the next version) and an https link to its **own
  privacy policy**; a thank-you text; the owner of new leads; tags; the
  sites that may frame it; open or closed.
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
  number; else Privatperson), a contact person with legal basis **consent
  dated today** and a note naming the form and the text version, and
  `crm_form_submissions` with the consent text exactly as shown, its
  version, the privacy link and the time (art. 7.1). Someone on the
  suppression list for an objection is flagged Kontakta inte at once, as
  in `SavePerson`.
- **An address the CRM already has** gives no duplicate, and the person's
  legal basis is **never changed** by a form (review round): anyone can
  type anyone's address into one and there is no double opt-in in this
  stage, so a form neither replaces a documented basis nor lifts an
  unsubscribe, an objection or a restriction. The submission is kept as
  evidence; a person may change the basis by hand.
- Either way a TASK "Webbformulär: …" for the form's owner (else the
  company's owner), due the next morning at 09:00, with the message and the
  phone, and an e-mail to that person naming only the form. A form with
  submissions can only be closed, never deleted: the submissions are the
  proof of consent.

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

## What a company can and cannot do (deliverability)

- In the cloud mail goes through NONOX's Mailgun domain: SPF and DKIM pass
  for that domain, and From is that domain with the company's name. A
  company **cannot** send mailings from its own domain in the cloud in
  this stage; replies go to the company (mailings) or the sender (template
  mail) through Reply-To.
- Self-hosted NONOX uses its own `MAIL_*` configuration; the operator is
  responsible for SPF, DKIM and DMARC for the From it configures.
- Hourly and daily caps per company, and the per-hour `MailQuota` for
  template mail, protect the sending domain's reputation.

## Owner actions needed (hosted)

1. **Inbound for +crm**: nothing new if the inbox route is in place
   (`docs/inkorg-installation.md`): the existing Mailgun route that
   forwards `.*@in.nonox.app` to `/webhooks/inkorg/mailgun/mime` already
   carries `{slug}+crm@in.nonox.app`. Check that the route's filter is a
   catch-all for the domain, not a list of addresses.
2. **Events**: Mailgun → Sending → Webhooks for the sending domain: add
   `https://nonox.app/webhooks/utskick/mailgun` for **Delivered**,
   **Permanent failure** and **Spam complaints**. The signing key is the
   existing `MAILGUN_WEBHOOK_SIGNING_KEY`.
3. **Tracking off**: confirm open and click tracking are off for the
   sending domain (the per-message headers switch them off either way).
4. **Scheduler and queue**: `crm:send-mailings` runs every minute and
   `crm:prune-mail` daily at 04:50 through the existing scheduler; mailing
   batches run on the queue worker.
5. Optionally tune `NONOX_CRM_MAILING_HOURLY_CAP` and
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
- Whether per-recipient click counting through redirect links falls under
  LEK 9 kap. 28 § / ePrivacy art. 5(3) (EDPB Guidelines 2/2023 mention
  tracked URLs); NONOX stores nothing on the recipient's device (no cookie
  on the redirect) and never shows clicks per person.
- Retention: 30 days for unhandled logged mail, 90 days for recipients'
  addresses, 13 months for recipient rows, and whether a logged e-mail
  should follow the activity's or the person's retention.
- Whether consent through a web form without double opt-in is provable
  enough (art. 7.1), and the template consent text.
- Whether a template mail to a person who unsubscribed and is marked "not
  marketing" needs more than the sender's confirmation.

## Consequences

- New tables: `crm_emails`, `crm_email_templates`, `crm_segments`,
  `crm_mailings`, `crm_mailing_links`, `crm_mailing_recipients`,
  `crm_mailing_clicks`, `crm_forms`, `crm_form_submissions`; new columns
  `crm_mailing_recipients.account_id` (review round),
  `crm_people.marketing_opt_out_at`, `email_bounced_at`,
  `crm_activities.email_id`. All company-scoped with tenancy tests.
- The inbox routes one more alias; its own behaviour is unchanged.
- No new package.
