# ADR-023: NONOX Inbox

Status: accepted, 2026-11-02

## Context

Companies get their daily reports from cash registers, supplier invoices and
receipts by e-mail. Separate integrations for every cash register do not
scale. The owner's specification ("Nonox Inbox", 2026-10-31) asks for one
e-mail address per company that keeps the original as underlag (BFL) and
turns it into a proposal that a person approves, with NONOX AI when the
company has it and rule-based readers and a manual queue otherwise.

## Decisions

### Architecture

- The inbox is a module (`app/Modules/Inbox`, ADR-017) with the permissions
  `inbox:view`, `inbox:manage` and `inbox:settings`. Owner, admin and
  accountant get all three, bookkeeper view and manage, auditor view. It is
  turned on and off under Företagsinställningar → Moduler like Lön and NONOX
  AI. Turning it off keeps every message; the address stops receiving.
- The specification's "lokal Core" is this NONOX application, self-hosted or
  on NONOX CLOUD (nonox.app), and "Connect" is NONOX CLOUD. **There is no
  relay in v1** between a cloud queue and a separate local Core. The
  requirement "the cloud queues encrypted and deletes when Core has fetched"
  becomes: received mail is stored encrypted at rest (the private disk is
  encrypted, `EncryptedAdapter`), the raw webhook request is not kept beyond
  the stored `.eml`, and nothing is forwarded anywhere else.

### Addresses

- **Per company:** `{slug}@in.nonox.app` (`NONOX_INBOX_DOMAIN`, default
  `in.nonox.app`). The slug is the company name in lower-case ASCII with a
  random suffix of eight characters, unique, and the owner can replace it;
  the old address then stops working at once. Plus-aliases `+kvitto`,
  `+faktura` and `+kassa` give a hint of the type. An accounting firm gets
  one address per client automatically, since every company is its own team.
- **Shared:** `mejl@in.nonox.app` (`NONOX_INBOX_SHARED_LOCAL`, default
  `mejl`), for what people forward from their own mailbox. A company is a
  candidate only when its allowlist has the sender's exact address AND the
  owner or a member signs in with that address. An allowlist entry alone
  never routes it, and domains never do: any company can type any address
  or domain, so that let one company take another's mail (security review
  2026-11-01). Suppliers and cash registers use the company's own address.
  - exactly one candidate: it goes there;
  - none: dropped and logged, no bounce, nothing that reveals anything;
  - several (someone who is a member of several companies, such as a firm's
    accountant): held
    as an `UnassignedMessage` that belongs to no company until a member with
    `inbox:manage` of one of the candidates claims it. It is never assigned
    by guess. Only sender, subject and date are shown before the claim.
    Unclaimed mail is removed after 90 days.

### Receiving

- **Cloud:** a Mailgun inbound route forwards the raw MIME to
  `POST /webhooks/inkorg/mailgun/mime` (`body-mime`). The request is trusted
  only with Mailgun's signature: HMAC-SHA256 of timestamp and token with
  `services.mailgun.webhook_signing_key`, a timestamp at most five minutes
  old, and a token not seen before (cache). Unknown recipients, other
  domains and companies without the module get 200 and nothing is kept, so
  the endpoint cannot be used to probe for addresses. A company over its
  hourly quota gets 429, so Mailgun delivers again later. Trimming of the
  request's strings is switched off for the webhook, so the `.eml` is stored
  byte for byte.
- **Self-hosted:** `inbox:fetch {company?}` (scheduled every five minutes)
  polls an IMAP mailbox set in the module settings. The password is stored
  encrypted (`imap_secret`) and masked in the audit log like the AI keys.
  The PHP imap extension is not part of PHP 8.4, so the pure-PHP
  **webklex/php-imap** (MIT) is used behind the `MailboxClient` interface;
  tests use a fake and never reach a server. Messages are read without being
  marked as seen (the folder is opened with EXAMINE); the last UID taken is
  remembered.
- **IMAP hardening (security review 2026-11-01).** The fetch runs from inside
  the hosting network, so the settings must not become a way to probe it:
  - `ImapHost` refuses a server name that is malformed or a number in
    disguise (`2130706433`, `0177.0.0.1`, `0x7f.1`), that does not resolve,
    or where *any* address it resolves to is loopback, private, link-local,
    shared (100.64/10), reserved, documentation, multicast, IPv4-mapped,
    NAT64 or 6to4 (PHP's `FILTER_FLAG_GLOBAL_RANGE` plus multicast and the
    IPv6 prefixes that carry an IPv4 address). It runs when the settings are
    saved and again right before every connection, and the client connects
    to the vetted IP with the name as TLS `peer_name`/SNI, so DNS rebinding
    between check and connect does not work. A self-hoster with a mail
    server on a private network sets `NONOX_INBOX_IMAP_ALLOW_PRIVATE=true`
    (`services.inbox.imap_allow_private`, default off).
  - Only ports 143 (STARTTLS) and 993 (TLS), always with a verified
    certificate; plain IMAP is gone. Settings saved earlier with `tls` or
    `none` are read as `ssl`.
  - Why a fetch failed is logged on the server; the settings show only a
    general reason (`refused` or `failed`), since the text of a connection
    error tells what answers at an address.
  - The size of each message (`RFC822.SIZE`) is asked before it is
    downloaded; one larger than `NONOX_INBOX_MAX_MESSAGE_KB` is skipped, its
    UID passed and `inbox.dropped` audited.
  - Residual risk: the server the company chose can still lie about a
    message's size and send more; the 25 MB check on the downloaded message
    still applies, but the bytes are read first. With
    `imap_allow_private` on, anyone with `inbox:settings` can make NONOX
    connect to private addresses on ports 143/993 with TLS.
- **MIME:** **zbateson/mail-mime-parser** (BSD-2-Clause) parses headers,
  multiparts and transfer encodings; symfony/mime, already installed, only
  builds messages. The `.eml` and every attachment are stored unaltered on
  the encrypted private disk with their SHA-256. HTML is never rendered;
  only its text is kept.
- **XLSX:** read with ext-zip and SimpleXML (`App\Support\Spreadsheet\XlsxReader`,
  first sheet, at most 20 MB unpacked) instead of phpoffice/phpspreadsheet:
  cash register exports are one simple sheet, and no package is needed.

### Security before any reading

- **Allowlist** (`inbox_allowed_senders`, exact addresses or `@domain`) per
  company, kept under the module settings and from the quarantine's "Godkänn
  avsändaren". "Known sender" means "on the allowlist". A sender not on it is
  quarantined: nothing is interpreted or booked before a person allows the
  sender, which releases their waiting mail. The allowlist is its own table,
  not module settings, because the shared address must look it up across
  companies.
- **SPF/DKIM/DMARC:** in the cloud Mailgun's results (`X-Mailgun-Spf`,
  `X-Mailgun-Dkim-Check-Result`, spam flag `X-Mailgun-Sflag`) and any
  `Authentication-Results`; from IMAP the topmost `Authentication-Results`,
  or the one with the configured authserv-id
  (`NONOX_INBOX_IMAP_AUTHSERV_ID`), since a sender can add such headers
  further down. A message fails when DMARC fails, or when DMARC gave no answer
  and neither SPF nor DKIM passed. **A failed message is quarantined even
  from an allowlisted sender**, since a From address is easily forged. A
  person may release it after review; it is then never booked automatically
  and cannot be replied to.
- **Viruses:** with a clamd socket (`NONOX_INBOX_CLAMD_SOCKET`) every
  attachment is scanned (INSTREAM); a scanner that cannot be reached holds the
  message. Dangerous types are always blocked: programs, scripts, Office
  files with macros, HTML and SVG, and archives with such files or that
  cannot be looked into. A blocked attachment is kept as evidence and never
  served. The same checks (`AttachmentScreen`) apply to the invoice image
  embedded in a Peppol file; a blocked one gives the warning
  `BLOCKED_ATTACHMENT` and never becomes the invoice file. **TODO: enable ClamAV on nonox.app in production.**
- **Serving:** PDFs and pictures inline with `Content-Security-Policy:
  sandbox` and `nosniff`; everything else, the `.eml` included, only as a
  download with a neutral type. The same applies to underlag the inbox
  links to vouchers. Since 2026-11-01 every stored file NONOX serves
  (voucher underlag, supplier invoice files, outlay receipts, imported
  payslips) carries the same sandbox policy (`App\Support\Files\InlineFile`),
  with `frame-ancestors 'self'` so NONOX's own pages can still frame it.
- **Fraud warnings:** a bankgiro, plusgiro or IBAN on an invoice that differs
  from the supplier register for a known supplier, and a sender domain that
  looks like a known one (Levenshtein ≤ 2, homoglyphs and punycode, the same
  name under another top-level domain, or the known domain with something
  appended). **A warning always blocks automatic booking**, and a person must
  tick that they have checked it before approving.
- **Duplicates:** an attachment whose SHA-256 has arrived before, is already
  underlag on a voucher or a registered supplier invoice's file, and invoice
  number + amount + supplier, give a warning. The exact same bytes twice are
  taken only once (a retried delivery).
- **Limits:** `WorkQuota` kind `inbox_mail` (500 an hour per company) and a
  maximum message size (`NONOX_INBOX_MAX_MESSAGE_KB`, 25 MB).

### Interpretation

Order: SIE4 → Peppol/UBL → reports from cash registers (template) → NONOX
AI → manual queue.

- **SIE4** attachments are read with the existing `SieReader` and
  `SieVoucher` into voucher proposals (at most 200; a larger file belongs in
  Importera SIE).
- **Peppol BIS Billing 3.0 / UBL 2.1** invoices and credit notes are read by
  `App\Support\Peppol\UblInvoiceParser` behind `InvoiceDocumentParser`
  (no DOCTYPE, no network), the supplier matched on organisation or VAT
  number, the invoice image embedded in the XML kept as an attachment.
- **Reports from cash registers** (CSV with any separator and decimal
  style, Windows-1252 or UTF-8, XLSX, or a plain text printout) are read into
  labelled amounts; the sorted labels are the layout's fingerprint. A
  template for the same sender and fingerprint maps them without AI.
- **NONOX AI** (the company's own key, the AI module unchanged in its model
  settings) is asked only when no template matches and the company allows it
  in the inbox settings: `InboxClassifierAgent` for what a PDF or picture
  is (an address alias skips it), the AI module's `SupplierInvoiceAgent` and
  `VoucherAgent`, and `DailyReportAgent`, which says what each amount on a
  daily report is (sales per rate 25/12/6/0, VAT, card, Swish, cash, gift
  card, tips, rounding) and its account from the company's chart with a
  reason and a confidence. **The voucher is built by NONOX from the amounts
  and the mapping (`DailyReportVoucher`)**, never taken from the AI: it
  checks debit = credit and that the VAT at each rate agrees with the sales
  before the proposal counts as ready. The inbox calls the AI with no user
  (`AnalyzeDocument::inboxDocument`); the company enabled it.
- Everything else goes to the **manual queue**, with the attachment beside a
  form for a voucher or a supplier invoice; a report the AI did not read gets
  a mapping form, and the mapping a person makes is learned the same way.

### Approval, templates and automatic booking

- A proposal is a draft inside the inbox. Approving runs the system's own
  actions in the person's name: `SaveDraftVoucher` + `PostVoucher`, or
  `SaveSupplierInvoice` + `RegisterSupplierInvoice`, so numbering, the hash
  chain, immutability and permissions are unchanged. "Spara som utkast"
  only saves the drafts. The `.eml` and every attachment are linked to each
  voucher as underlag (`AttachToVoucher::linkStoredFile`); nothing is
  copied.
- Approving saves a **template** for the sender: for a report the mapping of
  its labels, for a Peppol invoice the account per VAT rate. The next
  document with the same sender and fingerprint is read with it and **no AI
  call** is made.
- Per template a person may turn on **automatic booking**. It books only on an
  exact match, a balanced voucher, no warnings, an allowlisted sender whose
  mail passed authentication, and only while the person who turned it on may
  still post; it books in that person's name and is audited as
  `inbox.auto_booked`. **AI never books by itself.**

### Workspace and notifications

- `/inkorg` with the tabs Att granska, Utkast klara, Bokfört and Avvisat,
  server-side pagination, search and tab in the query string, the attachment
  on the left and the proposal on the right. Posting asks for a confirmation;
  `Ctrl/Cmd+Enter` approves, `j`/`k` move, `g` allows the sender, `x`
  rejects, `?` lists the shortcuts.
- Replies to the sender go through the ordinary mailer within `MailQuota`
  and are audited; only allowlisted, authenticated senders can be answered.
- The menu shows how many messages wait. Optional e-mail when something
  needs review (at most once an hour) and a daily summary (`inbox:daily-summary`,
  07:00), both without contents.

### Retention

- A message that led to bookkeeping is kept with it (BFL 7 kap.). Rejected
  messages are removed after 7 days, quarantined messages nobody handled and
  unclaimed shared-address mail after 90 days (`inbox:prune`), and spam from
  senders outside the allowlist is never stored (owner's decision 2026-11-01,
  #16). Mail from an allowed sender is always kept, in quarantine when it is
  flagged, so a supplier is never lost to a false alarm. TODO(legal-verify):
  whether a rejected document can still be räkenskapsinformation.

## Consequences

- Two packages: zbateson/mail-mime-parser (BSD-2-Clause, bringing
  zbateson/mb-wrapper and zbateson/stream-decorators, BSD-2-Clause, and
  php-di/php-di with php-di/invoker, MIT) and webklex/php-imap (MIT,
  bringing symfony/polyfill-iconv, MIT; its other dependencies were already
  installed). All are AGPL-compatible.
- Mailgun becomes a receiver of inbound mail for nonox.app as well as a sender
  (docs/juridik/underbitraden.md).
- PDF daily reports are read from their text layer with smalot/pdfparser
  (LGPL-3.0, AGPL-compatible; added 2026-11-01), at most 5 MB and 10 pages,
  so templates apply to PDF as well as CSV, XLSX and text. A PDF is read as a
  report without AI only when the sender's template knows its layout or it
  was sent to +kassa; otherwise NONOX AI classifies it and maps the fields
  from the text, or a person starts it as a daily report by hand. Scanned
  PDFs without text still need NONOX AI or a person.
- Going live needs the Mailgun route and DNS described in
  `docs/inkorg-installation.md`.
