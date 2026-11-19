# ADR-046: CRM stage 3, signing, quote templates and the agreement register

- Status: **Implemented, waiting for the owner's review** (2026-11-04)
- Amendment 2026-11-18 "best in class": **Accepted** (owner 2026-11-18: all recommended defaults, except decision 16: no BankID driver for now; see the end of this file)
- Builds on: ADR-043 (CRM, stage 3 of its plan), ADR-011 (quotes, orders,
  invoices, recurring invoices), ADR-002 (PDF), ADR-032 (API, webhooks),
  ADR-017 (modules), ADR-027 (Att göra)
- Branch: `feat/crm-stage-3-signing`

## Context

The owner's brief for stage 3: "Quote templates with articles and prices
from the article register. Signing, not BankID, a signature pad. A signed
quote becomes an order and then an invoice. Agreement register with renewal
dates and reminders." ADR-043 said that this stage introduces the first
public page where an outsider signs a document, and that it gets its own
security and legal review: a simple electronic signature under eIDAS, a
document hash, an audit trail.

## Decision

Everything lives in the CRM module (`app/Modules/Crm`, key `crm`); with
the module off nothing of it shows, and every signing link answers that it
is not valid. Nothing here posts to the ledger: a signed quote becomes an
order (and optionally a draft invoice) through the invoicing core's own
`ProgressSalesDocument`, exactly as when a user presses the button.

### Data model

All company-scoped (`BelongsToCompany`; tenancy in
`tests/Tenancy/CrmSigningTenancyTest.php` and `TenancyIsolationTest`).

- **`crm_quote_templates`** (name, intro, terms, validity days, archived)
  and **`crm_quote_template_rows`** (article or free row, quantity, unit,
  discount; a free row keeps its own price and VAT rate).
- **`crm_agreements`**: account, title, kind (`AgreementKind`), start, end
  (null: until further notice), `notice_months`, `auto_renew` and
  `renewal_months`, `value_ore` per `value_period`, `recurring_invoice_id`,
  owner, `body_text`, notes, the uploaded PDF (`document_path`, name,
  SHA-256), `terminated_on`, `signed_at`, `reminded_for_end_date`.
- **`crm_signing_requests`**: one link. Document (quote or agreement),
  the deal to win, the SHA-256 of the token (unique), the e-mail address,
  status `PENDING`/`SIGNED`/`DECLINED`/`REVOKED` (expired is derived from
  `expires_at`), the exact PDF (`document_path`, `document_sha256`), a
  content fingerprint (`content_hash`), `create_invoice`, and the evidence:
  signer name and title, method (`DRAWN`/`TYPED`), the drawn signature as
  an encrypted base64 PNG, time, IP address, user agent, the signing
  certificate (path and SHA-256), the decline time and reason, the
  revocation time, and the follow-up (order, invoice, `followed_up_at`).
- **`crm_signing_events`**: the trail: `SENT`, `OPENED`, `SIGNED`,
  `DECLINED`, `EXPIRED`, `REVOKED`, with the user for internal acts and the
  IP address and browser only for the signer's own acts.

A PostgreSQL trigger refuses any change to a signed or declined request's
evidence (only the follow-up columns can be filled once); another refuses
every update of a signing event. Deletion is allowed so the company's
cascades and the pruning of unsigned links work.

### Sending (`SendForSigning`)

- A quote needs `invoices:create` and `invoices:send` and must be open:
  signing turns it into an order (what `invoices:create` allows) and sending
  is what `invoices:send` allows today (`SendSalesDocument`). The brief named
  only `invoices:create`; the role that may send a quote is the one that may
  send it for signing. A seller (role `sales`) has neither and cannot make
  quotes today, so cannot send them for signing either (consistent with
  ADR-043). An agreement needs `crm:manage`, so a seller can.
- The PDF is rendered once (quote: `SalesDocumentPdf`; agreement: the
  uploaded PDF, or one generated from its fields and text) and stored on
  the private disk with its SHA-256. The public page serves those bytes;
  nothing is re-rendered later.
- The token is 32 bytes from `random_bytes` (256 bits), base64url, 43
  characters. Only its SHA-256 is stored. The URL goes only to the e-mail
  (queued `SigningRequestMail`, ids only in the job, the company's name as
  sender name and its address as Reply-To, a Swedish preview text) and is
  never returned by the screen or the API. The queue payload holds the URL
  until the job runs.
- Expiry: the end of the chosen day, else of the quote's last valid day,
  else 30 days; never more than 90 days ahead.
- One open link per document: sending again revokes the earlier one.
- The open deal to win is chosen when sending (preselected when the
  account has exactly one open deal). Open deals had no link to quotes
  before, so the request carries it.
- A test company mails nothing (ADR-032). Mail quota as for quotes.

### The public page (`/signera/{token}`, `SigningPageController`)

- Outside `module:crm` and without login, in the `web` group for the
  session and CSRF token. Rate-limited per IP: 60 a minute, 10 form posts
  a minute.
- Lookup by the token's hash alone, inside `CompanyScope::bypass` with a
  comment, then `hash_equals` on the stored hash. A malformed or unknown
  token, a company with CRM off or archived: one message, 404, nothing
  about any document. An expired, revoked, signed or declined link: a
  plain Swedish message, 410 (expiry writes `EXPIRED` once).
- Headers: `X-Robots-Tag: noindex, nofollow, noarchive`, robots and
  referrer meta tags, `Cache-Control: no-store`, `Referrer-Policy:
  no-referrer` (the token is in the address; `SecurityHeaders` now keeps a
  page's own Referrer-Policy), and its own CSP: scripts from NONOX only and
  no inline script, `frame-ancestors 'none'`, `form-action 'self'`,
  `base-uri 'none'`. `/signera/` is disallowed in robots.txt.
- The page shows the company, the quote's rows, VAT per rate, rounding,
  total, validity and message (or the agreement's terms), the SHA-256 and
  the PDF (an iframe on wide screens, a link everywhere).
- The signature pad (`resources/js/signing.js`, model in
  `signature-pad-core.js`, Node tests) is about a hundred lines on a
  canvas with Pointer Events (mouse, touch, stylus, coalesced events),
  `touch-action: none`, undo and clear. No package. The keyboard
  alternative is "Skriv mitt namn": the name typed again must equal the
  name field (WCAG 2.1.1). A checkbox "Jag har läst och godkänner
  offerten/avtalet" is required. Double submit: the button is disabled in
  the browser, and the server locks the row.

### Signing (`SignAtLink::sign`)

- Under a row lock: the link must still be usable; the posted
  `document_sha256` must equal the stored one and the stored file must
  still hash to it (the signature binds to the exact bytes shown); the
  quote must still be open and its fingerprint (customer, dates, texts,
  totals, every row) equal the one taken when it was sent, or the
  agreement unsigned and unchanged (terms and uploaded PDF). A changed
  document revokes the link (`REVOKED`, audited `DOCUMENT_CHANGED`) and
  the page says a new link is needed. So the order is always what the
  signer saw.
- A drawn signature must be a `data:image/png;base64` URL of at most
  200 kB with a real PNG header and at most 2400 × 1200 pixels
  (`SignatureImage`); SVG and anything else is refused. Name and title at
  most 120 characters without control characters; everything is escaped
  where shown (page, panel, PDF, mail).
- Kept: name, title, method, signature, time, IP address (45 characters),
  user agent (500), and a `SIGNED` event with the IP and browser.
- The signing certificate (`Signeringsbevis`, Dompdf) records the
  document, its SHA-256, the address the link went to, the sender, the
  signer, time, method, IP, browser, the signature and the events. It is
  stored with its own SHA-256.
- **Deviation from the brief ("the original document plus a signature
  page"):** the signed PDF is never changed. Merging a page into it needs
  a PDF library NONOX does not have (FPDI or similar), and keeping the
  original bytes is stronger evidence: anyone can recompute the hash that
  the certificate states. The two files travel together (mail, download).
- Audit: `crm.signing_sent`, `_revoked`, `_signed` (method),
  `_declined` (whether a reason was given), `_followed_up` (order,
  invoice, skipped steps): ids only, never names, addresses or IPs.
- `QuoteSigned` (after commit) feeds the webhook `quote.signed`.

### After signing (`FollowUpSigning` job, `SigningFollowUp`)

Queued after commit with the company id, so the signer's page answers at
once; idempotent through `followed_up_at` under a row lock. It acts as the
person who sent the link (or, if they have left the company, its owner),
with that person's own rights:

1. The quote becomes an order (`ProgressSalesDocument::toOrder`), and a
   draft invoice from the order when the sender chose so per quote.
2. The chosen open deal is won through `WinDeal` with the quote, order and
   invoice linked (its existing rules apply).
3. A done CRM note "Offert N signerades" (or "Avtalet ”…” signerades")
   on the account; it counts as contact for pruning.
4. A notice (`SigningNotice`, database) to the sender, the deal's owner
   and the agreement's owner, naming the document, not the signer; it
   opens Offerter or Avtal through the company switch.
5. `SignedDocumentMail` with the PDF and the certificate to the signer
   and to the company's address (else the sender's).

A step the actor may not take (for example a sender who lost
`invoices:create`) is skipped and audited, never the signature itself; the
notice then says to finish by hand. A declined link logs a note with the
reason and notifies the same people; the quote stays open.

### Quote templates

`SaveQuoteTemplate` (and archive), `CreateQuoteFromTemplate`, page
`/kunder/offertmallar` (`invoices:create`, module on). An article row keeps
no price: the quote takes the article's name, unit, price and VAT rate of
that day through `SalesRowInput`, and an article archived since is refused.
Intro and terms (900 characters each) become the quote's message (the
quote has one message field, 2 000 characters, printed on the PDF). "Ny
offert från mall" is on Offerter, the account card and an open deal's card
(for those with `invoices:create`).

### Agreement register

- Pages `/kunder/avtal` (filters in the query string: running, ending
  within 30–365 days, ended, all; search on title or customer),
  `/kunder/avtal/nytt?konto=…`, `/kunder/avtal/{id}`, and the tab **Avtal**
  on the account card. `crm:view` reads, `crm:manage` writes.
- The notice deadline is the end date less the notice period; without a
  notice period the end date. Status: upcoming, running, terminated
  (`terminated_on` set: it ends with the period and does not renew),
  ended.
- `crm:agreements`, daily at 05:50 for every company with CRM on, not
  archived (`RunAgreementReminders`): renews a renewing agreement whose
  period has passed (the end date moves by the term until it is ahead);
  makes one CRM task per period for the agreement's owner (else the
  account's, else the company owner; members only) when the deadline is
  within `agreement_reminder_days` (company setting, default 60, 7–365),
  due 09:00 on the deadline (or today if it has passed), idempotent through
  `reminded_for_end_date` under a row lock; and prunes unsigned links a year
  after they closed. The reminder does not count as contact.
- Att göra: agreements whose notice deadline is within the setting (the
  user's own; all for `crm:settings`).
- A signed agreement's terms (dates, notice, renewal, value, text, PDF) no
  longer change; owner, notes, the recurring invoice and termination can.
- The linked recurring invoice must be the account's customer's, and is
  read and set only with `invoices:view`.
- A prospect with a signed agreement is not erased on request and not
  pruned (the contract is evidence, GDPR art. 17(3)(e)); any agreement
  keeps a prospect from pruning.
- **Deviation:** "a generated agreement from a template" is built as a PDF
  generated from the agreement's own fields and its free text. Separate
  agreement templates are not built; the quote templates cover quotes.

### API (Connect v1.7.0) and webhook

`GET`/`POST /quotes/{quote}/signing` (`invoices:view`; sending needs
`invoices:create+invoices:send`), `GET`/`POST /crm/agreements`,
`GET`/`PATCH /crm/agreements/{agreement}` (`terminated_on` terminates),
`GET`/`POST /crm/agreements/{agreement}/signing`. Same Actions. The link,
the signer's IP, browser and signature are never returned. Webhook
`quote.signed` (`invoices:view`): ids, time, method and the PDF's hash, no
personal data.

## Security review (done in this branch)

| Item | Handling |
|---|---|
| Token entropy and storage | 256 bits from the OS CSPRNG; SHA-256 stored, unique index; the link exists only in the e-mail (and the queue until sent) |
| Timing-safe lookup | Indexed equality on the hash of a 256-bit secret (an attacker cannot steer the hash), then `hash_equals` |
| Replay, one-time use | Status checked and changed under `SELECT … FOR UPDATE`; signed, declined and revoked links answer 410; double submit tested |
| Binding to the PDF | The posted hash must equal the stored one and the stored bytes; the document's contents must equal the fingerprint taken at sending |
| XSS | Blade escaping everywhere, control characters refused in names, signature only a checked PNG; tested with `<script>` as name |
| Signature size | 200 kB, 2400 × 1200, PNG header and `getimagesizefromstring`; the field itself at most 300 000 characters |
| CSRF | Laravel's token on both public forms (`web` group) |
| Clickjacking | `frame-ancestors 'none'` on the page |
| Indexing, leakage | noindex header and meta, robots.txt, no-store, `no-referrer` |
| CSP | `script-src 'self'` without inline script on the public page |
| Tenancy | Token lookup only; every internal page, component and API route answers 404 for another company's ids (tests) |
| Logging | The audit log gets ids only; nothing personal is written to the application log |
| Rate limits | 60 views and 10 posts a minute per IP; mail quota per company |

## GDPR and law

- The company is the controller for the signer's data; NONOX is its
  processor (the DPA text is updated; the bump of LegalPageController::VERSION/EFFECTIVE is left for the merge, since parallel branches collide there and a test pins today's effective date). Register of processing,
  retention and the manual updated. The page tells the signer what is kept
  and who is responsible.
- Retention: a signed quote or agreement and its evidence follow the
  record they support; where it is the basis of an order or invoice, or an
  agreement of particular importance to a business event, it is
  räkenskapsinformation (BFL 1 kap. 2 § första stycket 6 p., 7 kap. 2 §) and kept seven years.
  Unsigned links (declined, revoked, expired) are deleted with their PDF a
  year after they closed.
- `TODO(legal-verify)`: the evidential value of a simple electronic
  signature with a drawn or typed signature for quotes and agreements
  (eIDAS art. 25(1), the general freedom of form in Swedish contract law);
  which agreements need more (a qualified signature or paper).
- `TODO(legal-verify)`: whether storing the signer's IP address and
  browser is proportionate (art. 5(1)(c)), and the retention of the
  evidence for agreements that are not räkenskapsinformation.
- `TODO(legal-verify)`: the year for unsigned links, and whether a
  declined link's reason should be kept shorter.
- `TODO(legal-verify)`: whether BFL 1 kap. 2 § första stycket 6 p. makes
  signed agreements räkenskapsinformation in general, or only some.

## Consequences

- A signing link can be opened by anyone who has the e-mail: it proves
  control of the mailbox at the time, not identity. The manual says so and
  suggests BankID-based signing outside NONOX where identity matters.
- A quote cannot be changed and still signed with an old link; the seller
  sends a new link.
- Open items: agreement templates; revoking a link over the API; a second
  reminder closer to the deadline; the register extracts (`/registerutdrag`)
  do not yet list agreements or a signer's evidence: until then the company
  answers a signer's access request from the signing panel and the
  certificate.

## Review round (2026-11-04)

- **A signed quote is fixed.** `SaveSalesDocument` refuses to change a quote
  with a signed request ("Offerten är signerad och kan inte ändras."), after
  locking the quote row, so it orders against the signing (which locks the
  request, then the quote). `SendForSigning` refuses a signed quote, and the
  panel offers no new link. The follow-up locks the quote and compares its
  fingerprint with the one signed before `toOrder`; a mismatch makes no
  order (`CHANGED_AFTER_SIGNING`) and the notice says to check the quote
  against the signed PDF. Rejected: refusing in `SalesDocumentPolicy::update`,
  since the follow-up's own `toOrder` goes through that policy.
- **Erasure keeps the evidence.** A customer with a signed agreement counts
  as used in `EraseRegisterEntry` and is anonymised, never deleted (a signed
  quote already counts, through the quote). When an account is deleted (a
  prospect, or a never-booked customer), its agreements' and their links'
  PDFs are deleted after commit (`SigningFiles`); the customer observer now
  deletes accounts one by one so the event runs.
- **Information to the signer** (art. 13/14) on the page: what is kept, the
  controller with organisation number and e-mail, purpose and legal basis,
  retention, NONOX as processor, the rights and IMY. A decline keeps no IP
  address or browser on its event; the page says the time and the reason
  are kept at most a year. The CRM note says only that the recipient
  declined; the reason stays on the request.
- **The exact confirmation** is stored (`acceptance_text`) and quoted in the
  certificate: for a company "… och har rätt att godkänna den för :customer"
  (agreements "… ingå det för :account"), for a private person the short
  form.
- **The signer's copy** is queued from `SignAtLink::sign` after commit,
  independent of the order. A failing follow-up never turns the signer's
  page into an error (logged with the exception class only);
  `FollowUpSigning::failed()` tells the sender to make the order by hand,
  and the panel shows "Signerad – ordern är inte skapad".
- **The trigger** now also protects the company, document type, quote and
  agreement ids, document name, opened and revoked times, the invoice choice,
  the creation time, the confirmation text and a set `followed_up_at`; only
  the deal and the sender may change (both are set to null when deleted).
- Smaller: the OPENED event reads "Länken hämtades första gången"; the
  link mail's Reply-To sentence only when the company has an address; the
  last day to sign cannot be after the quote's validity date; the manual
  names agreements with form requirements (jordabalken 4 kap. 1 §).
- Not done: stopping the link mail when the link is revoked before the
  queue sends it (the job would need to re-check; the link then answers
  410 anyway). The DPA text is updated; `LegalPageController` is bumped once
  at the final merge.

### Second review round (2026-11-04)

- **No visitor IP in the audit log.** `Audit::withoutRequest()` writes
  entries without the request's IP address and user agent; the public
  signing routes run inside it (`WithoutAuditRequest` middleware), so
  `crm.signing_signed`, `crm.signing_declined`, `crm.signing_revoked` from a
  stale document, and the follow-up's entries when the queue runs in the
  request (order, deal) keep neither. The signer's IP stays only as evidence
  on the request and its SIGNED event.
- **The information text** names the controller with organisation number
  and e-mail only when the company has them.
- **The confirmation** is rendered once on the page and posted with an HMAC
  (`SigningPdfs::acceptanceMac`, app key, bound to the request id); the
  server stores the posted text only when the MAC matches, so a rename
  between showing and signing cannot change it. A quote customer without a
  CRM account gets the long form when the customer register shows a legal
  person (an organisation number and no personal number).


## Amendment 2026-11-18: best in class

- Status: **Proposed** (2026-11-18). No feature code ships with this
  amendment. It is built in the packages S1–S21 at the end, in the stated
  waves, once the owner has answered the decisions below (or Lead has applied
  the recommended defaults under the owner's mandate).
- Queue: item 27 in `docs/status/nummer-1.md` (`signering-toppklass`).
- Builds on: this ADR, ADR-075 §7 and its addendum "signing moved to core",
  ADR-074 (ÄTA signing, on-site codes), ADR-002 (PDF), ADR-017 (modules),
  ADR-019/ADR-030 (branding), ADR-020 (naming other programs), ADR-032/ADR-064
  (API and Connect), ADR-034 and ADR-050 (pluggable drivers), ADR-043 (GDPR in
  CRM), ADR-079 (database roles), ADR-081 E0 (per-package translation files).
- Format: follows ADR-037's "Amendment 2026-11-17: best in class" and ADR-081.

### Context

The owner decided on 2026-11-18 that NONOX signing shall be as good as an
open-source e-signing product that was used as a reference: every feature and
idea that would make NONOX signing better, but not the service itself. No code
or text is copied from it, and it is never named in the repository
(AGENTS.md §1.1). This amendment describes functionality only, written from
eIDAS, ETSI and IETF standards, Swedish law and NONOX's own code.

The reference's feature list, as the owner reported it, maps to the twelve
gaps in the queue item. NONOX also closes the three gaps the reference lists
for itself: parallel signing (gap 1), bulk send (gap 8), and API and webhooks
(S19).

A survey of the code on `7f0b001d` (2026-11-18) found a solid single-signer
engine in core. Each gap starts with what exists, by file path, so that no
package rebuilds it.

#### The engine as it stands (referred to by every gap)

- **Domain** `app/Domain/Signing/`: `Signable` (the interface a document
  implements), `SignableTypes` (registry: type → key column → finder),
  `SigningHook`/`SigningHooks` (module follow-up), `ResolvesSigningParty`,
  `SigningToken` (256-bit, base64url, SHA-256 at rest), `QuoteFingerprint`,
  `SignatureImage` (PNG checks), `SigningStatus` (`PENDING`, `SIGNED`,
  `DECLINED`, `REVOKED`, derived `EXPIRED`), `SigningEventType` (`SENT`,
  `OPENED`, `SIGNED`, `DECLINED`, `EXPIRED`, `REVOKED`), `SigningPdfs`
  (Signeringsbevis with dompdf, acceptance text and its HMAC),
  `SignedDocuments`, `SigningFollowUp`, `SigningUnavailable`.
- **Actions** `app/Actions/Signing/`: `SendForSigning` (one open link per
  document, expiry at most 90 days), `SignAtLink` (row lock, hash binding,
  fingerprint check, evidence), `SignInSession` (portal channel `PORTAL`,
  ADR-075 §7), `PruneSigningLinks`, `RevokeQuoteLinksClosedWithCrm`.
- **HTTP** `app/Http/Controllers/Signing/SigningPageController.php` (the public
  page `/signera/{token}`, its own CSP, noindex, no-store, no-referrer) and
  `SigningFileController.php` (`/kunder/signeringar/{id}/{fil}`);
  `app/Providers/SigningServiceProvider.php` (registry, rate limiters
  `crm-signing` 60/min and `crm-signing-submit` 10/min per IP, routes,
  `WithoutAuditRequest`).
- **Models** `app/Models/SigningRequest.php`, `SigningEvent.php` (the tables
  keep the names `crm_signing_requests`, `crm_signing_events`).
- **Jobs, mail, events:** `app/Jobs/FollowUpSigning.php`,
  `app/Mail/SigningRequestMail.php`, `SignedDocumentMail.php`,
  `app/Events/QuoteSigned.php`, `app/Notifications/SigningNotice.php`,
  `app/Console/Commands/PruneSigningLinksCommand.php` (`signing:prune`).
- **UI:** `app/Livewire/Signing/SigningPanel.php`
  (`resources/views/livewire/signing/panel.blade.php`), the public views in
  `resources/views/signing/` (`page`, `head`, `closed`, `certificate`), the
  mails in `resources/views/mail/signing/`, the pad
  `resources/js/signing.js` with its model `resources/js/signature-pad-core.js`.
- **Hooks:** `app/Modules/Crm/Support/CrmSigningHook.php` (agreement type,
  deal won, note, notices), `app/Modules/Crm/Support/SigningFiles.php`,
  `app/Modules/WorkOrders/Support/WorkOrdersSigningHook.php` and
  `app/Modules/WorkOrders/Actions/SignChangeOrder.php` (ÄTA by link, and on
  site with a six-digit code to the customer's e-mail, five tries, fifteen
  minutes).
- **API and webhook:**
  `app/Modules/Connect/Http/Controllers/Api/V1/CrmSigningController.php`,
  webhook `quote.signed` (`app/Modules/Connect/Webhooks/WebhookEvents.php`).
- **Migrations and triggers:**
  `app/Modules/Crm/database/migrations/2026_11_25_130000_create_crm_signing_tables.php`
  (`crm_signing_requests_protect`, `crm_signing_events_append_only`),
  `2026_11_25_140000_harden_crm_signing_evidence.php` (`acceptance_text`,
  wider protection), `2026_12_31_100300_delete_signing_events_only_with_their_request.php`,
  `2026_12_31_100400_never_delete_signed_signing_requests.php`;
  `database/migrations/2026_12_20_100000_add_signable_id_to_signing_requests.php`
  (the generic key column); the portal migration
  `app/Modules/Portal/database/migrations/2026_12_20_120000_create_customer_portal_tables.php`
  (`channel`, `crm_signing_requests_protect_channel`); the ÄTA code table
  `app/Modules/WorkOrders/database/migrations/2026_12_19_120000_create_change_order_on_site_codes_table.php`.
- **Tests:** `tests/Feature/Crm/SigningTest.php` (26),
  `tests/Feature/Crm/SigningApiTest.php` (5),
  `tests/Feature/Signing/SigningCoreTest.php` (5),
  `tests/Tenancy/CrmSigningTenancyTest.php` (5),
  `tests/Feature/WorkOrders/ChangeOrderSigningTest.php` (8), the portal tests,
  `tests/js/signature-pad.test.mjs`.
- **Docs:** `docs/manual/sv/crm/signering.md`, `avtalsregister.md`,
  `offertmallar.md`, `docs/manual/sv/fakturering/ata.md`,
  `arbetsorder-pa-plats.md`, `kundportal.md`;
  `docs/gdpr/registerforteckning.md` (the signing paragraph),
  `docs/gdpr/lagringstider.md` (rows for signed and unsigned links).

Already covered from the reference list: download before signing (the PDF
link on the page), decline with a reason, signing links hashed at rest, rate
limits, branding with name, logo and colour (`App\Support\Branding\Brand`),
and an audit certificate with IP address and browser (without a check method,
since there is none yet).

### Principles that apply to every gap

1. **Evidence is immutable.** Nothing that a signer did, saw or was shown is
   ever updated. Every new evidence table gets a PostgreSQL trigger in the
   style of `crm_signing_requests_protect`: updates refused once the row is
   final, deletes refused before the retention date except by the company
   cascade (`pg_trigger_depth() > 1` and the company row gone, as in
   `2026_12_31_100400`). Erasure is deleting a whole envelope after its
   retention, never editing part of it.
2. **The original bytes survive.** The PDF that was shown is never rewritten.
   Field values, the certificate and the seal are added as **incremental
   updates**, so the first revision of the finished file is byte for byte the
   PDF that was sent, and its SHA-256 (in the certificate) can still be
   recomputed by anyone. The original is also kept as its own file. This keeps
   the intent of this ADR's deviation "the signed PDF is never changed".
3. **One engine, many documents.** Quotes, agreements, ÄTA, portal acceptance
   and standalone PDFs all go through `app/Domain/Signing` and `Signable`. No
   document type gets its own signing code. The existing single-signer flows
   keep their behaviour and their tests unchanged unless a package says so.
4. **Honest levels.** NONOX by itself gives a simple electronic signature
   (eIDAS art. 3(10)) and an integrity seal. It never states "advanced" or
   "qualified" unless a configured provider produced that signature, and then
   it states the provider's claim, not its own.
5. **Tenancy.** Every new company-scoped table has `company_id` → `teams`,
   `BelongsToCompany`, indexes leading with `company_id`, a tenancy test
   (another company's id → 404), and a decision in
   `App\Support\Database\DatabaseGrants`. The only lookups across companies are
   by a token hash (as today) and by a document hash on the public Verify page,
   each inside `CompanyScope::bypass` with a comment naming this amendment.
6. **Additive migrations only:** new tables, new nullable columns, new
   indexes, and replaced trigger functions that only widen what may be set
   once from null. No drops, no type changes. New tables use the `signing_`
   prefix; the two existing tables keep their `crm_` names (renaming would
   rewrite the triggers and every reference for nothing).
7. **No N+1.** The envelope list, the envelope page, the signer page with
   fields and the reminder run load in a fixed number of queries, proven by a
   query-count test with one and with ten recipients and many fields.
8. **Audit log** entries hold ids, enums and counts only, never names, e-mail
   addresses, IP addresses, field values or document titles (QUICKSTART §6).
   The public routes keep running inside `WithoutAuditRequest`.
9. **A person decides what is sent.** Templates, bulk send and the API create
   envelopes; nothing is sent by a rule except the reminders a sender switched
   on.

## Gap 1: several signers in a set order, copy recipients, parallel signing

### What exists

One `crm_signing_requests` row is one recipient with one link.
`SendForSigning` revokes the earlier open link when a document is sent again,
so a document has at most one signer. There are no copy recipients; the
company's own copy goes to the company address in `SigningFollowUp`. The
follow-up (`FollowUpSigning`, `SigningHook::signed`) runs when that one request
is signed.

### Scope

- A new **envelope** holds one document, up to **10 signers** and up to **10
  copy recipients**.
- **Order:** each signer has a step number 1–10. Signers with the same step
  sign **in parallel**; a step opens when every signer of the step before has
  signed. "In order" is steps 1, 2, 3; "everyone at once" is everyone on step
  1. This closes the reference's own gap "parallel signing".
- A link (token) is issued and mailed only when the signer's step opens.
- **Decline** by any signer ends the envelope (`DECLINED`): every open link is
  revoked; the sender and the signers who already signed are told (without the
  reason, which stays with the sender, as today).
- **Void:** the sender (or `signing:manage`) can withdraw an envelope that is
  out; open links answer 410 with "Dokumentet har dragits tillbaka".
- **Completion:** when the last signer signs, the envelope is `COMPLETED`, the
  document is sealed and sent to everyone (gap 5), and then the document
  type's follow-up runs once (a quote becomes an order, CRM wins the deal, the
  ÄTA is approved). A quote with two signers becomes an order only when both
  have signed.
- **Copy recipients** get no link. They get the sealed PDF and the certificate
  at completion, and, if the sender ticks it, a notice at sending.
- **One document per envelope**, as in the reference.
- The existing flows keep their single-signer screens: `SigningPanel` sends a
  one-signer envelope exactly as today; "Fler undertecknare" opens the send
  wizard of gap 10 for the same document. The portal (`SignInSession`) and the
  on-site ÄTA flow stay one-signer channels and create one-signer envelopes.

### Data model

- **`signing_envelopes`** (uuid, company-scoped): `document_type` and the
  document key in the same columns the request uses today
  (`sales_document_id`, `agreement_id`, `signable_id`), `title` (snapshot),
  `status` (`DRAFT`, `OUT`, `COMPLETED`, `DECLINED`, `VOIDED`, `EXPIRED`),
  `current_step`, `document_path`, `document_name`, `document_sha256`,
  `content_hash` (the fingerprint), `page_count`, `deadline_at`,
  `reminder_every_days` (null, 1, 2, 3, 7), `reminders_sent`,
  `last_reminded_at`, `language` (`sv`, `en`), `confidential` (gap 10),
  `template_id` (gap 8), `created_by`, `sent_at`, `completed_at`, `voided_at`,
  `voided_by`, `chain_head` (gap 4), `sealed_path`, `sealed_sha256`,
  `seal_level` (gap 5), `retention_until`, `retention_hold` (gap 11),
  `followed_up_at`.
- **`crm_signing_requests` becomes the signer row**: nullable `envelope_id`,
  `step` (1–10, default 1), `role_key` (gap 8), `language`, `verification`
  (gap 3), the consent columns (gap 7). Existing columns and evidence stay.
  The protect trigger is replaced so that `envelope_id`, `step` and `role_key`
  may be set once from null on a signed row (for the backfill) and never
  changed after.
- **Backfill** (in the S1 migration, inserting rows only): one envelope per
  existing request, status derived from the request, `envelope_id` set. So
  there is one code path, and existing links keep working.
- **`signing_link_tokens`** (company-scoped): `signing_request_id`,
  `token_hash` (unique), `issued_at`, `reason` (`SENT`, `REMINDER`,
  `RESENT`), `revoked_at`. A reminder issues a new token and mails it while
  the earlier one stays valid until the deadline, so no link is ever stored
  readable. Rejected: keeping the link encrypted for reminders, as the ÄTA
  on-site row does. Lookup checks this table and, for older rows, the
  request's own `token_hash`. Revoking a signer revokes all its tokens.
- **`signing_copy_recipients`** (company-scoped): `envelope_id`, `email`,
  `name`, `language`, `notified_at`, `sent_copy_at`.
- `SigningHook` gains `completed(Company, SigningEnvelope, ?User, ...)`; an
  adapter calls the old `signed()` for one-signer envelopes, so CRM and
  WorkOrders need no change in S1.
- At most 10 signer rows and 10 copy rows per envelope: checked in the action
  under the envelope's row lock, and by a trigger that counts rows.

### Permissions

Sending, reminding, changing the deadline and voiding an envelope need the
document type's own sending rights (`Signable::authorizeSigning`): a quote
`invoices:create` + `invoices:send`, an agreement `crm:manage`, an ÄTA the
work-order permission, a standalone document `signing:send` (gap 10).
Voiding someone else's envelope needs `signing:manage`.

### Security

- Step gating is enforced on the server: no token is issued for a signer whose
  step is not open, and steps are fixed by trigger once the envelope is out.
- Completion and the follow-up run under the envelope row lock (lock order:
  envelope, then request, then document), idempotent through `followed_up_at`.

### Legal items

- `TODO(legal-verify)`: whether earlier signers must be told when a later
  signer declines (default: yes, without the reason).

### Out of scope

Several documents per envelope; signers added after sending; delegating a
signing step to another person; witnesses as a separate legal category (a
witness is a signer with the role "Vittne").

### As built in S1 (2026-11-18)

- Migration `database/migrations/2027_01_25_100000_create_signing_envelopes.php`:
  `signing_envelopes`, `signing_link_tokens`, `signing_copy_recipients`, and
  `envelope_id` + `step` on `crm_signing_requests`. Only the columns S1 uses;
  the later gaps add theirs. Backfill as specified (verified on a copy with a
  signed, a declined, a revoked and a pending request: every existing column,
  `updated_at` included, unchanged).
- **Deviation: the protect trigger is not replaced.** The existing evidence
  triggers stay exactly as they are; a new trigger
  `crm_signing_requests_protect_envelope` lets `envelope_id` be set once from
  null and keeps `step` fixed. `role_key` comes with gap 8. Counting triggers
  hold the ten-signer and ten-copy limits; `signing_envelopes_protect` keeps
  the document fixed and a finished envelope fixed except `followed_up_at`
  (once); a completed envelope is never deleted except by the company
  cascade.
- **Deviation: lock order.** Every path takes the document's row first (as
  signing always has), then the signer's request, then the envelope and its
  other signer rows, rather than "envelope, request, document": changing the
  order of the existing one-signer paths would risk deadlocks between old and
  new paths. Since every path holds the document first, two parallel signers
  of one envelope sign one after the other.
- A one-signer envelope keeps its token on the request, as before, and writes
  no new audit entries; tokens for envelopes with several signers live in
  `signing_link_tokens` (the request's own `token_hash` then holds an
  unguessable value nobody holds, as the portal already does).
- The panel's "Fler undertecknare" asks for an e-mail address and a step per
  signer; a signer's name is still what the signer types when signing.
  Several signers are offered for quotes and agreements; the portal and ÄTA
  stay one-signer channels and make one-signer envelopes.
- `signing:manage` does not exist yet (gap 10, S7): in S1 withdrawing an
  envelope needs the document's own sending rights, as revoking a link did.
- Quotes: while an envelope with several signers is out and someone has
  signed, the quote cannot be changed (`SignedDocuments::isQuoteSigned`); a
  declined or withdrawn envelope, or one past its last day, frees it again.
  The customer portal refuses acceptance while such an envelope is out.
- Review round (2026-11-18): copy recipients and the other signers get the
  document and a **summary** (`SigningPdfs::envelopeSummary`: names,
  titles, times, methods, the SHA-256), never another signer's e-mail, IP
  address, browser or drawn signature (GDPR art. 5.1 c); the company gets the
  full certificates, and each signer their own. The signing page says so, and
  the NOTICE and COPY mails carry a short art. 14 text
  (`TODO(legal-verify)`). Every signer gets the COPY at completion. The
  revocation paths lock the document first. Triggers also refuse a return to
  DRAFT, a COMPLETED envelope with an unsigned signer, deleting a completed
  envelope's copy recipients, and changing `created_by` on a finished
  envelope other than to null. The next step's links and the copies count
  against the mail quota without ever refusing (`MailQuota::count`).

## Gap 2: fields in the PDF per signer

### What exists

None. A signer gives name, title, the drawn or typed signature and ticks the
confirmation; nothing is placed in the PDF. The quote and agreement page show
the PDF in an iframe on wide screens and as a link.

### Scope

- Field types: **signature, initials, date, name, text, number, checkbox,
  dropdown, radio**. Each is assigned to one signer and has a page, a
  rectangle, a label, **required or optional**, and type rules: text max
  length (at most 500), number min, max and decimals, date (sv-SE
  `ÅÅÅÅ-MM-DD`, filled with the signing date and not editable), dropdown and
  radio options (at most 30, 100 characters each), a radio group key.
- **Earlier signers' fields stay visible and locked:** after each signer, the
  values are stamped into the PDF as a new incremental revision (principle
  2); the next signer is shown that revision. Later signers' fields show as
  empty outlines with the role name.
- The signer page shows the pages as images with HTML inputs over the field
  rectangles, plus a field list for keyboard and screen-reader users: each
  field a labelled form control in reading order (WCAG 2.2 AA, 2.1.1, 1.3.1),
  and "Nästa obligatoriska fält". Every field is validated on the server.
- **Initials** use the same pad and typed alternative as the signature, sized
  for a small box.
- Quotes and agreements without placed fields keep today's page (the form
  below the document). A standalone document needs at least one signature
  field per signer.
- The field editor (for the sender, gaps 8 and 10): drag fields onto the
  rendered pages, assign them to a signer or role, set required and options;
  the keyboard alternative is a form with page number and position presets.

### Data model

- **`signing_fields`** (company-scoped): `envelope_id` or `template_id`,
  `signing_request_id` (or `role_key` on a template), `type`, `page`, `x`,
  `y`, `width`, `height` (integers in 1/100 PDF points from the lower left of
  the page's crop box, after rotation), `label`, `required`, `options` (jsonb),
  `group_key`, `rules` (jsonb), `position`. Frozen when the envelope goes out
  (the trigger refuses updates once the envelope is not `DRAFT`).
- **`signing_field_values`** (company-scoped): `field_id`,
  `signing_request_id`, `value` (text, at most 500 characters), `image`
  (encrypted PNG for signature and initials), `filled_at`. Written once, in
  the transaction that signs; the trigger refuses every update and refuses
  delete except with the envelope at retention (principle 1). Values are part
  of the signer's evidence and of the hash chain (gap 4).
- **`signing_document_versions`** (company-scoped, append-only):
  `envelope_id`, `kind` (`ORIGINAL`, `AFTER_SIGNER`, `SEALED`,
  `TIMESTAMPED`), `signing_request_id` (for `AFTER_SIGNER`), `path`, `sha256`
  (indexed), `bytes`, `created_at`. Every file a signer was shown or received
  is here, so every one of them can be verified (gap 6).

### Security

- Values are validated against the field's type and rules on the server; a
  dropdown or radio value must be one of the options; control characters are
  refused; everything is escaped where shown (page, PDF stamp, mail).
- A signer may post values only for fields assigned to them; others are
  ignored and recorded as an event `FIELD_REJECTED` (ids only).
- Page images are rendered on the server by the engine (gap 5) and served from
  the same origin behind the token, `Cache-Control: no-store`. The public
  page keeps `script-src 'self'` with no inline script and needs no PDF
  viewer, so its CSP is not widened. Rejected: PDF.js on the public page,
  which needs a worker and, in current versions, `wasm-unsafe-eval`.
- The editor (internal pages) may use PDF.js (Apache-2.0), bundled by Vite and
  loaded from NONOX only, with `worker-src 'self'`.

### Legal items

- `TODO(legal-verify)`: whether initials on each page add evidential value in
  Swedish practice, or should be described only as a convenience.

### Out of scope

Formulas between fields, conditional fields, fields added by the signer,
fields the sender fills in for a signer, AcroForm fields that stay
interactive in the final PDF (values are flattened).

## Gap 3: checking the signer (access code or e-mail code)

### What exists

The link proves control of the mailbox only (this ADR, Consequences). The ÄTA
on-site flow already sends a six-digit code to the customer's e-mail
(`change_order_on_site_codes`: code hash, five tries, fifteen minutes;
`SignChangeOrder::verifyOnSite`). The portal identifies a contact by its own
e-mail code session.

### Scope

Per signer, the sender chooses a check (`verification`):

- **`NONE`**: the link alone. Today's behaviour; the default for quotes and
  agreements (owner decision 9).
- **`EMAIL_CODE`**: before the document is shown, a six-digit code goes to the
  signer's address. It protects against a forwarded or leaked link. The
  default for standalone documents.
- **`ACCESS_CODE`**: the sender types a code of at least 6 characters and
  gives it to the signer another way (phone, in person). NONOX never mails it,
  and the screen says so.
- **`PROVIDER`**: a pluggable provider (BankID, a CSC API v2 service), gap 12.

The check is recorded on the request (method, time), as an event in the
chain, and on the certificate per signer. A signer locked out by failed
attempts sees "Kontakta avsändaren"; the sender is told and can send a new
code or link.

### Data model

- **`signing_verification_codes`** (company-scoped): `signing_request_id`,
  `code_mac` (HMAC-SHA256 with the app key over the request id and the code;
  a plain SHA-256 of six digits is reversed by brute force from a database
  copy), `expires_at`, `attempts`, `verified_at`, `sent_at`.
- On `crm_signing_requests`: `verification` (the enum above),
  `access_code_hash` (`Hash::make`, since a sender's code has low entropy),
  `verified_at`, `verification_failures`, `locked_at`. Protected by the
  trigger once signed.
- The ÄTA on-site flow moves onto the same service in S3 without behaviour
  change; its own table stays for its existing rows and evidence.

### Security

- Code: six digits from `random_int`, valid 10 minutes, 5 attempts per code,
  at most 3 codes an hour per signer, a new code only after 60 seconds. The
  send runs behind a cache lock and reuses a still-valid code inside the
  cooldown, so a double click never sends two mails (the owner's standing rule
  for login codes); the button is disabled in the browser while sending.
- Access code: 5 failed attempts lock the signer; compared with
  `Hash::check`; never logged, shown again or returned by the API.
- A passed check lives in the session for 30 minutes, bound to the request id
  and the token hash; a new browser needs a new check.
- Throttles: per IP (as today) and per signer (`signing-verify`, 10 a minute);
  `hash_equals` on every comparison.

### Legal items

- `TODO(legal-verify)`: that an e-mail code or an access code does not change
  the classification (it does not make the signature advanced under art. 26);
  the manual says only that it reduces the risk of a forwarded link.

### Out of scope

SMS codes (NONOX has no SMS; ADR-037 keeps SMS out too); identity questions.

### As built in S3 (2026-11-18)

- Migrations `database/migrations/2027_03_02_100000_create_signing_verification_codes.php`
  (the request columns as specified, `signing_verification_codes` with a
  `channel` LINK/ON_SITE and `max_attempts`, and two triggers) and
  `app/Modules/WorkOrders/database/migrations/2027_03_02_100100_link_on_site_codes_to_verification_codes.php`.
  `crm_signing_requests_protect_verification` keeps `verification` and
  `access_code_hash` fixed from the insert and every check column fixed once
  signed or declined; `signing_verification_codes_protect` lets a code row
  change only its attempts (upwards) and `verified_at` (once). The existing
  evidence triggers are untouched. A check constraint ties `access_code_hash`
  to `ACCESS_CODE`; `PROVIDER` is allowed by the constraint but not offered.
- Code: `app/Domain/Signing/Verification/` (`SignerCheck`, `SignerChecks`,
  `SignerPass`, `CheckRefused`), `App\Models\SigningVerificationCode`,
  `App\Mail\SigningCodeMail`, the check page `resources/views/signing/check.blade.php`,
  routes `signing.code` (`POST /signera/{token}/kod`) and `signing.verify`
  (`POST /signera/{token}/kontroll`) behind the limiter `signing-verify`
  (10 a minute per link and 10 per IP address). The panel offers
  "Kontroll av undertecknaren" per signer and "Lås upp".
- **Limits chosen:** a code is valid 10 minutes, 5 attempts, used once, and
  only the newest code of a signer counts; 3 codes an hour; a second "Skicka
  kod" within 60 seconds reuses the code already sent (cache lock plus the
  signer's row lock; no second mail). A signer is locked out after **10**
  failed e-mail codes (two codes' worth) or **5** failed access codes. The
  owner's general rule names "N failures"; these numbers are the builder's
  default under the mandate.
- **No code on GET.** The page asks the signer to press "Skicka kod", so a
  mail program or link scanner that fetches the link sends nothing.
- **The check page shows only the sender's name,** not the document's title.
  The document, signing and declining are refused without a pass, in the
  controller (per browser, `SignerPass`) and again in `SignAtLink` under the
  row lock (`verified_at` set, not locked), whatever path calls it. A pass
  lives in the session 30 minutes, bound to the request id and the token's
  hash; the session id is regenerated when it is granted.
- **Lock order:** document, signer row, code row, envelope (the chain), as
  every signing path since S1. A failed attempt is counted and chained in its
  own committed transaction; the error is thrown after.
- **Events:** `CODE_SENT`, `VERIFIED`, `VERIFY_FAILED`, `LOCKED` as gap 4 lists
  them, plus **`UNLOCKED`** (with the user id) for the sender's unlock, which
  gap 4 did not list. Payloads hold the channel, the method, the code row's id
  and counts; never a code, and no IP address or browser (as `OPENED`).
  `SENT` carries the chosen method when it is not `NONE`. The API's per-link
  `events` list keeps showing only the six signer steps
  (`SigningEventType::isSignerStep` is now a whitelist), so the OpenAPI
  contract is unchanged; the API still sends with `NONE` (S19 adds the field).
- **`verified_at`** is the latest passed check (a new browser checks again),
  frozen once the signer signs or declines. The certificate prints the method
  and that time, and says the signature is still a simple electronic one; the
  envelope summary names the method per signer.
- **Lockout notice:** an in-app notice to the sender and the document's own
  recipients, naming the document only (as the other signing notices), and an
  audit entry `signing.locked`; the unlock writes `signing.unlocked`.
- **The code mail** is sent at once after the commit, never queued (the code
  must not sit in a queue table), and counted against the mail quota without
  refusing. A send that fails tells the signer to try again in a minute.
- **ÄTA on site:** a new on-site code is a `signing_verification_codes` row on
  the `ON_SITE` channel with the same HMAC (`SignChangeOrder::codeHash` now
  delegates to `SignerChecks::mac`), its `CODE_SENT`, `VERIFIED` and
  `VERIFY_FAILED` events in the ÄTA envelope's chain, and the on-site row
  points at it (`verification_code_id`) and mirrors the attempts. Behaviour is
  unchanged: 15 minutes, 5 tries, no lock of the signer row, the link
  forgotten after the fifth wrong code or expiry. Rows from before S3 are
  checked against their own `code_hash`, as before. A code for a link that a
  newer one replaced records no event (its envelope's chain is closed).
- **Review round (2026-11-18):**
  - **Weak access codes** are refused: digits only and shorter than 8, a short
    list of common codes (case and spaces ignored), and anything over 72
    bytes (`strlen`), since bcrypt reads only the first 72 bytes; a typed code
    over 72 bytes is refused before `Hash::check` and not counted. The panel
    has "Föreslå kod" (ten characters from an alphabet without 0/O and 1/l/I,
    about 57 bits) and its hint advises against personal and phone numbers.
  - `OPENED` is recorded only once the check has passed (the document is shown
    then); the check page itself records nothing.
  - An on-site `VERIFIED` names the method `ON_SITE`; `CODE_SENT` reads "En
    engångskod skickades till undertecknarens e-post", true for both channels.
  - A code whose mail fails after the commit is spent (its attempts set to the
    maximum; the trigger allows attempts to grow), so the next "Skicka kod"
    sends a new one instead of answering that one was just sent. A cache lock
    that cannot be had answers "Koden kunde inte skickas just nu".
  - **Test companies** (ADR-032) send no mail, so an e-mail code cannot reach
    the signer. Chosen: the check page says so ("Det här är ett testföretag i
    NONOX …") and "Skicka kod" refuses with the same text; the check is not
    skipped, so a test company behaves like a real one. An access code works.
  - **Known limits, accepted:** whoever holds a link can lock its signer out by
    typing wrong codes on purpose (harassment, not a breach: nothing is shown,
    the sender is told and can unlock or send a new link). The per-IP
    throttle is shared by everyone behind one NAT address (an office or a
    mobile carrier), so a busy office can hit it; the per-link throttle and
    the counts in the database are what bound guessing. bcrypt's 72-byte limit
    is handled by the cap above.

## Gap 4: a hash-chained audit trail

### What exists

`crm_signing_events` is append-only by trigger
(`crm_signing_events_append_only`) and deletable only with its request
(`2026_12_31_100300`). There is no hash chain; the voucher chain
(`App\Domain\Accounting\LedgerHash`, ADR-007) and the dump chain
(`App\Support\Portability\DumpChainVerifier`) show the pattern.

### Scope

- Every event of an envelope is chained: `hash = sha256(previous_hash ||
  canonical_json(event))`. The first event's `previous_hash` is the original
  document's SHA-256, so the chain is bound to the document.
- New event types: `CREATED`, `STEP_OPENED`, `CODE_SENT`, `VERIFIED`,
  `VERIFY_FAILED`, `LOCKED`, `CONSENTED`, `DOWNLOADED`, `FIELDS_FILLED`,
  `FIELD_REJECTED`, `REMINDED`, `DEADLINE_CHANGED`, `VOIDED`, `COMPLETED`,
  `SEALED`, `TIMESTAMPED`, `COPY_SENT`, `CHAIN_STARTED`.
- The chain head is stored on the envelope at completion, printed on the
  certificate, embedded in the sealed PDF (gap 5) and written to the audit log
  (`signing.completed` with ids and the head hash), which is itself
  append-only. Deleting a whole envelope is therefore visible in the audit log.
- `php artisan signing:verify {company?}` recomputes every chain, every file
  hash in `signing_document_versions` against the stored bytes, and that every
  completed envelope has its audit entry. It runs nightly like `ledger:verify`;
  the result shows on the envelope page and in Inställningar → Signering.

### Data model

New nullable columns on `crm_signing_events`: `envelope_id`, `sequence`,
`previous_hash`, `hash`, `payload` (jsonb: ids, enums, field ids and the
hashes of field values, never the values). Unique (`envelope_id`,
`sequence`). Older events have no hash; a backfilled envelope's first new
event is `CHAIN_STARTED`, which names the count of earlier events. The event
trigger stays append-only; inserting an event takes the envelope's row lock, so
`sequence` has no gaps.

### Security

The canonical JSON (sorted keys, UTC timestamps with microseconds, no floats)
lives in one class with golden tests. A test changes a stored event by raw SQL
in the test database, with the trigger disabled, and proves that
`signing:verify` reports the break.

### Out of scope

Anchoring chain heads outside NONOX (for example a public transparency log).
The timestamp of gap 5 already gives an independent time.

### As built in S2 (2026-11-18)

- Migration `database/migrations/2027_02_03_100000_chain_signing_events.php`:
  the event columns as specified, `chain_head` and `chain_sequence` on
  `signing_envelopes`, and `signing_document_versions` (append-only, deleted
  only by the cascade from its envelope, signer row or company). The event
  column is widened from 10 to 20 characters for `CHAIN_STARTED`; a longer
  varchar limit is a catalogue change in PostgreSQL that rewrites no row.
  `crm_signing_events_append_only` and `crm_signing_events_delete_with_request`
  are untouched.
- Code: `app/Domain/Signing/Chain/` (`CanonicalJson`, `EventHash`,
  `SigningChain`, `SigningChainBackfill`, `ChainVerifier`) and
  `VerifySigningChainsCommand` (`signing:verify {company?}`, nightly 03:25).
  Every `SigningEvent` write goes through `SigningChain::record`.
- **Insert trigger** `crm_signing_events_chain`: an event of an envelope must
  be the next sequence, link to the previous hash (the document's SHA-256 for
  the first) and belong to its signer row's envelope; once an envelope's
  chain has started, an unchained event for it is refused. The app computes
  the hash; the trigger checks the links. `signing_envelopes_protect_chain`
  sets the head once from null.
- **Deviation: no foreign key on `crm_signing_events.envelope_id`.** Events go
  with their signer row (the evidence delete trigger); a second cascade path
  from the envelope could reach an event while its row still exists, and be
  refused. The insert trigger checks the envelope instead.
- **Times in whole seconds.** `crm_signing_events.created_at` is
  `timestamptz(0)`, which rounds. Chained events are written cut to the
  second, so the canonical JSON (UTC, six fraction digits as specified) is of
  what is stored.
- **Evidence in the chain:** SIGNED and DECLINED carry `evidence`, a SHA-256
  of the signer row's evidence columns (name, title, method, signature image,
  acceptance text, times, IP address, browser, document hash, decline
  reason); the values stay on the row. `signing:verify` recomputes it.
- **Envelope events:** `COMPLETED` (payload: the number of signers) and
  `VOIDED` (payload: the reason code) are recorded on the signer row that
  caused them; a decline ends with the signer's own `DECLINED`. Then the head
  goes on the envelope and to the audit log as `signing.completed`,
  `signing.declined` or `signing.voided` (ids, status, sequence, head). The
  other new event types of this gap come with the packages that cause them
  (S3, S4, S6, S9, S10, S11, S13).
- **Backfill (in the migration, insert only):** an `ORIGINAL` version per
  envelope (size empty: the file is not read), and a `CHAIN_STARTED` event
  whose payload counts and digests the earlier events **per signer row**, on
  a signed row where there is one. A finished envelope gets its head and an
  audit entry `signing.chain_backfilled`. An envelope still out continues its
  chain from there. Verified in a test that every earlier event and request
  row is byte for byte unchanged, and that a second run adds nothing.
- **Pruning and the chain:** `signing:prune` deletes a declined or revoked
  signer row of an envelope that keeps a signed one; its events go with it.
  Their sequence numbers and hashes are first written to the audit log
  (`signing.events_pruned`), so the chain still links and `signing:verify`
  tells a pruned signer from a removed one. The prune also no longer deletes
  the envelope's shared PDF while another signer row still uses it (a defect
  in S1: pruning a declined signer deleted the document of the signer who
  had signed).
- **Status in the UI:** the signing panel shows, under **Händelser**, the
  result of the latest `signing.verified` for the envelope and its head.
  Inställningar → Signering does not exist yet (S7/S16); the status goes
  there when it does. The head is not yet printed on the Signeringsbevis,
  which is made per signer before the envelope finishes; S10 (the sealed
  PDF) and S12 (Verify page) carry it.
- **Review round (2026-11-18):**
  - **Chain counter.** `signing_envelopes.next_sequence` and `last_hash`
    (migration `2027_02_03_100100_count_signing_chain_sequences.php`) are kept
    by the insert trigger only (`pg_trigger_depth`, never backwards). The next
    sequence and previous hash come from them, never from `max(sequence)` of
    the remaining rows, so a pruned signer's events never free a number. The
    trigger also checks the company of the signer row and the envelope, and
    refuses any event once the envelope's head is set. The backfill moved into
    this migration and calls `SigningChainBackfill` on purpose: one canonical
    JSON and one hash shared with `signing:verify`; a frozen copy could drift
    and raise false alarms. It runs with `SET LOCAL lock_timeout = '5s'`.
  - **Daylight saving.** `SigningEvent` and `SigningRequest` write their times
    with the offset (`Y-m-d H:i:sP`), so the repeated autumn hour stores the
    instant that was hashed (event time, and `signed_at`/`declined_at` in the
    evidence digest).
  - **Verifier.** Every envelope is checked up to its counter, also one
    without signer rows: a head or a moved counter without a chain, a missing
    link not named in `signing.events_pruned`, a counter that is not the last
    link, and an event after the head are reported. Pruned links carry their
    event type, so a pruned `CHAIN_STARTED` counts as started. Versions of an
    envelope without signers are not read (their PDF went with the last
    signer). Signer rows are read without the drawn signature, which is
    decrypted in chunks for the evidence check only.
  - **API.** The per-link `events` list of the NONOX API shows only the six
    signer steps; `CHAIN_STARTED`, `COMPLETED` and `VOIDED` are filtered out,
    so the contract in `docs/api/openapi.yaml` is unchanged.
  - **Canonical JSON, precisely:** PHP `json_encode` with
    `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, so U+2028 and U+2029
    stay escaped as `\u2028` and `\u2029` while other characters are written
    as UTF-8; strings are not normalised (no NFC), so the bytes as stored are
    hashed; `CanonicalJson::seconds()` truncates to the second (as Eloquent
    writes the signer row's times), `time()` keeps six fraction digits.
- **Deploy note:** between the migration and the new code going live, an old
  worker's unchained insert for an envelope whose chain has started is
  refused by the trigger, and that signing transaction rolls back (nothing is
  half-saved); the signer retries.

## Gap 5: the finished PDF sealed with PAdES and a timestamp, mailed to everyone

### What exists

`SigningPdfs::certificate` renders the Signeringsbevis with dompdf;
`SignedDocumentMail` sends the unchanged PDF and the certificate as two files
to the signer and the company. Nothing is sealed or timestamped. This ADR's
first round rejected merging pages for lack of a PDF library.

### Scope

- At completion, the **finished file** is the last revision (original plus
  stamped fields) with the certificate pages appended, as incremental updates,
  and then **sealed** with a PAdES signature (ETSI EN 319 142-1, baseline
  profiles) by a seal certificate, as a further incremental update. The
  certificate is also kept as its own PDF, as today.
- **Levels** (`seal_level`): `B_B` (signature only), `B_T` (plus a signature
  timestamp from an RFC 3161 server), `B_LT` (plus a DSS dictionary with the
  certificates and revocation data), `B_LTA` (plus a document timestamp over
  the DSS). The level reached depends on the configuration; without a
  timestamp server the seal is `B_B`. The screen and the certificate state the
  level reached, never a higher one.
- **Seal certificate:** by default an instance **self-signed** seal created by
  `php artisan signing:seal-certificate` (the key outside the web root, its
  path in config); or the **company's own** certificate, uploaded as PKCS#12
  under Inställningar → Signering (`signing:settings`). The manual says
  plainly that PDF readers show a self-signed seal as of unknown validity and
  that the Verify page (gap 6) is the check that needs no trust list.
- **Long-term validation:** a nightly `signing:timestamp` adds a new document
  timestamp to `B_LTA` files when the last timestamp's TSA certificate is
  within 12 months of expiry, for files still within retention. Each new file
  is a new row in `signing_document_versions`, so earlier copies still verify.
- **The sealed PDF is mailed to everyone:** every signer, every copy
  recipient and the company (as today: the company's address, else the
  sender's), each in their language, with the certificate. A file above 15 MB
  goes as a link instead (its own token, valid 30 days, hashed at rest).
- **Scripts:** stamped text, the certificate and the mails render Latin,
  Greek, Cyrillic and CJK correctly. Fonts: Noto Sans and Noto Sans CJK (SIL
  Open Font License 1.1, which allows embedding and redistribution), fetched by
  a script with pinned SHA-256 (like `scripts/fetch-xbrl-taxonomy.sh`) instead
  of being committed, and subset when embedded. Golden tests with Greek,
  Cyrillic, Chinese, Japanese and Korean names.
- dompdf's `DejaVu Sans` covers Latin, Greek and Cyrillic, not CJK. S20 checks
  in the installed dompdf 3.1.6 source whether a font-family list falls back
  per glyph; if it does not, the certificate template splits text into script
  runs and sets the font per run.

### The PDF library choice

What is installed (read in `composer.json`, `composer.lock` and `vendor/`):

| Library | Licence | In NONOX | Can it do the job? |
|---|---|---|---|
| `dompdf/dompdf` 3.1.6 | LGPL-2.1 | direct (ADR-002) | Renders HTML to new PDFs (quotes, certificate). Cannot open, stamp or sign an existing PDF. Stays for what it does. |
| `smalot/pdfparser` 2.13.1 | LGPL-3.0 | direct | Reads text and metadata, cannot write. Useful for a text alternative of a standalone PDF and for upload checks. |
| `phpseclib/phpseclib` 4.0.2 | MIT | indirect (required by `laravel/passport`) | Has `File\CMS\SignedData` with `addESSSigner` (ESS signing-certificate-v2), signed and unsigned attributes, and ASN.1 maps for `TimeStampToken` and `TSTInfo` (read in `vendor/phpseclib/phpseclib/phpseclib/File/`). It could build the CMS a PAdES signature needs, but it has no PDF writer. Not relied on; kept as the fallback for a pure-PHP CMS step, and then required directly. |
| Browsershot | MIT | not installed | ADR-002 chose not to need Chrome on the server. It makes new PDFs only; no help here. |

Candidates for overlay, flattening and signing an existing PDF:

| Candidate | Licence | Verdict |
|---|---|---|
| **pyHanko** (Python) | MIT | **Chosen.** Writes incremental updates only (the original revision survives), stamps text and images, signs PAdES B-B to B-LTA, has an RFC 3161 client, DSS and document timestamps, external ("interrupted") signing for remote providers, and a validator for self-tests. |
| **pypdfium2** (Python binding of PDFium) | Apache-2.0 / BSD-3-Clause | **Chosen** for page geometry and the page images of the signer page and the editor. |
| fontTools, uharfbuzz (used by pyHanko for subsetting and shaping) | MIT, Apache-2.0 | With pyHanko, for CJK. |
| PDF.js | Apache-2.0 | **Chosen** for the internal field editor only. |
| Apache PDFBox (Java) | Apache-2.0 | Good, but needs a JVM on every server; rejected as the default. |
| EU DSS (Java, the European Commission's library) | LGPL-2.1 | The reference for PAdES validation, but needs a JVM. Optional in CI as a second validator of the golden files, never at runtime. |
| setasign FPDI + TCPDF | MIT + LGPL-3.0 | Rejected: importing pages rewrites the document (the original bytes are lost); the free FPDI parser does not read PDF 1.5 compressed cross-reference streams according to its vendor (S5 confirms in its repository); TCPDF signs a PKCS#7 without the PAdES baseline attributes or LTV. |
| tecnickcom tc-lib-pdf | LGPL-3.0 | Rejected: no mature import together with PAdES LTV. |
| mPDF | GPL-2.0-only | Excluded: GPL-2.0-only cannot be combined with AGPL-3.0. |
| A PDF incremental writer written in PHP | AGPL (own) | Rejected for now: real uploads use cross-reference streams, object streams and odd structures; a writer that strict validators accept is a project of its own. |

**Decision (owner decision 3):** a **PDF engine** behind an interface
`App\Domain\Signing\Pdf\PdfEngine` (`inspect`, `renderPages`, `stamp`,
`appendPages`, `seal`, `timestamp`, `validate`). The implementation
`SidecarPdfEngine` calls a small NONOX-owned Python program in
`tools/pdf-engine/` (AGPL, built on pyHanko and pypdfium2, pinned versions with
hashes in a lock file) through `Illuminate\Support\Facades\Process`, with a
timeout, a memory limit and no shell. A `FakePdfEngine` serves the test suite.
`php artisan signing:engine-doctor` checks an installation.

Relied on: the installed PHP packages as read above. For the Python and Java
libraries the architect relied on their published documentation; S5 verifies
the current versions, licences and the named capabilities in each project's
own repository before adding them, and records that in its PR.

**Without the engine** (a self-hoster who does not install Python), signing
works as today (the unchanged PDF and the certificate) plus every gap that
needs no PDF work (several signers, checks, the chain, consent, reminders,
templates without fields, the Verify page against the original's hash). The
screens that need the engine (fields, sealing) say "Kräver PDF-motorn" and link
to the self-hosting guide. ADR-002's promise (pure PHP for invoices) holds.

### Timestamp server

- RFC 3161 Time-Stamp Protocol over HTTP(S) POST
  (`application/timestamp-query`), a SHA-256 message imprint, a nonce, and
  `certReq` true. Configuration only, nothing hard-coded:
  `signing.timestamp.urls` (an ordered list; the first that answers wins),
  optional basic-auth credentials and client certificate, `policy_oid`,
  `timeout`. Instance-level only (`config/nonox.php` from env), never per
  company, so no company can point the server at an internal address (SSRF).
- If no server answers, the queue retries sealing for up to 24 hours and the
  envelope shows "Förseglas". After that it is sealed at the level reached and
  the sender is told.
- Candidates, best first:
  1. **A qualified time-stamp service on the EU trust list** (eIDAS art. 42).
     A qualified electronic time stamp carries the presumption of accurate time
     and integrity (art. 41(2)). Needs a contract; the best evidence.
  2. **A free public time-stamp service of a large certificate authority**
     (those used for code signing): widely trusted by PDF readers, good uptime,
     no contract, not qualified. `TODO(legal-verify)`: their terms of use for
     document timestamps at volume.
  3. **A free community service** without an SLA: development and tests only.
  The recommended production setup is option 1 as soon as a contract exists,
  with option 2 as the interim and as the fallback URL. A timestamp request
  carries only a hash, so no personal data leaves NONOX.

### Data model

- **`signing_seal_certificates`** (company-scoped, for companies' own
  certificates; the instance seal is a key file named in config, not a row):
  `subject`, `issuer`, `serial`, `not_before`, `not_after`,
  `fingerprint_sha256`, `pkcs12` (encrypted cast), `password` (encrypted cast),
  `uploaded_by`, `retired_at`. Never returned by a screen or the API after
  upload; replaced, never edited.
- On the envelope: `seal_level`, `sealed_path`, `sealed_sha256`,
  `seal_certificate_fingerprint`, `sealed_at`, `last_timestamp_at`.

### Security

- Seal keys: the PKCS#12 and its password are decrypted only in the job and
  handed to the engine on standard input, never written to a readable file or
  logged; the instance key file is `0600` outside the web root. Expiry within
  60 days raises a to-do for `signing:settings`.
- The engine runs as a subprocess with a 60-second timeout and a memory limit,
  without network access except to the configured timestamp servers and the
  revocation addresses in the certificates (documented for self-hosters).
- Page rendering caps the pixels per page and the pages per document (gap 10).

### Legal items

- `TODO(legal-verify)`: **trust service.** eIDAS art. 3(16) counts the
  creation, verification and validation of electronic seals and time stamps,
  and the preservation of seals, as trust services. Whether NONOX sealing
  customers' documents and re-timestamping them makes the operator a
  (non-qualified) trust service provider with duties under art. 19 (security,
  breach notification) and supervision by PTS. **This gates S10, S11 and S12
  on the hosted service.**
- `TODO(legal-verify)`: a company's own seal certificate attests the company's
  origin and the document's integrity, not the signer's will. The certificate
  and the manual must not present it as the signer's signature.

## Gap 6: a public Verify page

### What exists

None. Anyone can recompute the SHA-256 on the certificate, but there is no page
to check it against.

### Scope

- `/kontrollera` (Swedish URL, AGENTS.md §11.4): the visitor chooses a PDF; the
  browser computes its SHA-256 with Web Crypto (`crypto.subtle.digest`) and
  posts **only the 64 hex characters**. The file never leaves the device, and
  the page says so in Swedish and English.
- The answer is found or not found. Found shows the company's name, the
  document title, which version it is (original, after signer n, sealed,
  re-timestamped), the completion time, the number of signers and their check
  methods, the seal level, and whether the chain verified at the last nightly
  run. No names, e-mail addresses or IP addresses (owner decision 11).
- The certificate carries a QR code (`bacon/bacon-qr-code`, already
  installed) and a short link to `/kontrollera` with the hash in the fragment
  (`#sha256=…`), which the page reads in the browser; it never travels in a
  URL to the server.
- It answers for completed, declined and voided envelopes (each state
  stated), for archived companies and with the module off: evidence must stay
  checkable. A deleted envelope (past retention) is "not found".

### Security

- Lookup inside `CompanyScope::bypass` (with a comment naming this amendment)
  on the indexed `signing_document_versions.sha256`: one query, the same
  response time for found and not found within noise.
- Throttle `signing-verify-page`: 20 checks a minute per IP. The body must be
  exactly 64 lowercase hex characters; nothing else is accepted. No hash is
  logged together with an IP address.
- CSP as on the signing page (`script-src 'self'`, no inline script,
  `connect-src 'self'`, `frame-ancestors 'none'`). A test asserts that the
  form's file input has no `name` and that the POST body holds only the hash.

### Legal items

- `TODO(legal-verify)`: whether the page is "validation" in the sense of eIDAS
  art. 3(16)(a). It checks only that a file is byte-identical to one NONOX
  kept; the wording is "kontrollera att filen är oförändrad", never "validera
  underskriften".

## Gap 7: versioned consent to electronic records

### What exists

The page shows what is kept and who is responsible (art. 13/14, review round
2026-11-04), and the exact confirmation the signer ticks is stored
(`acceptance_text` with an HMAC). There is no separate consent to doing the
transaction electronically.

### Scope

- A short text "Samtycke till elektroniska handlingar", versioned like the
  legal pages: `docs/juridik/elektroniska-handlingar.md` with a version and an
  effective date, a constant in one class (as `LegalPageController::VERSION`),
  in Swedish and English.
- Shown once per signer and envelope before the document, as one checkbox
  "Jag godkänner att handlingen skrivs under elektroniskt". Declining it ends
  with "Kontakta avsändaren om du vill skriva under på annat sätt" and tells
  the sender.
- Stored on the request: `consent_version`, `consent_text_sha256`,
  `consent_at`; event `CONSENTED` in the chain; printed on the certificate.

### Legal items

- `TODO(legal-verify)`: Swedish law has no general consent requirement for
  electronic signatures. Whether the step is useful evidence, and whether
  consumer contracts at a distance (lag (2005:59) om distansavtal och avtal
  utanför affärslokaler) need more about a durable medium.

## Gap 8: templates with roles, and bulk send

### What exists

Quote templates (`crm_quote_templates`, `SaveQuoteTemplate`,
`CreateQuoteFromTemplate`, `/kunder/offertmallar`) build quotes, not signing
setups. Agreement templates were left open in this ADR's Consequences.

### Scope

- A **signing template** holds a PDF (standalone) or a document type (quote,
  agreement), **roles** instead of names ("Kund", "Säljare", "Vittne"), each
  role's step, check and fields (gap 2), and defaults for deadline, reminders,
  copy recipients, language and confidentiality.
- Sending from a template maps each role to a person (name, e-mail; for the
  company's own role a member is suggested); the fields follow their role.
- A template's PDF and fields can change; an envelope copies them when it goes
  out, so earlier envelopes are unaffected. A used template is archived, never
  deleted.
- **Bulk send** (the reference's own gap): one template and a CSV with one row
  per envelope (columns per role: `Kund namn`, `Kund e-post`, …); a preview of
  the first rows and every row's validation; then up to 500 envelopes queued,
  each with its own trail, within the company's mail quota.

### Data model

`signing_templates` (company-scoped: name, `document_type`, PDF path and
SHA-256, page count, defaults, `archived_at`, `created_by`),
`signing_template_roles` (`template_id`, `key`, `label`, `step`,
`verification`), the fields in `signing_fields` with `template_id` and
`role_key`; `signing_bulk_sends` (company-scoped: template, status, counts,
`created_by`; the uploaded CSV is deleted when the run finishes).

### Out of scope

Sharing templates between companies or from the firm workspace (ADR-028), a
later step; a public template gallery.

## Gap 9: deadline and reminders

### What exists

Expiry at the end of a chosen day, else the quote's last valid day, else 30
days, never more than 90 (`SendForSigning`). No reminders; "a second reminder
closer to the deadline" is an open item in this ADR's Consequences.

### Scope

- A deadline of **3–90 days** from sending (default 30, owner decision 14),
  never after a quote's validity date. **Changeable while out** by the sender,
  shorter or longer within the same bounds counted from today; event
  `DEADLINE_CHANGED`; the open signers are told the new date.
- **Automatic reminders every 1, 2, 3 or 7 days** (default 3, owner decision
  13) to signers whose step is open, stopping at the deadline, at most 10 per
  signer; without an interval, one reminder two days before the deadline.
  `signing:remind` runs hourly, sends only between 08 and 18 Stockholm time,
  and is idempotent through `last_reminded_at` under the row lock.
- **Manual reminder** ("Påminn nu") per signer, at most once an hour.
- A reminder mails a fresh token (gap 1, `signing_link_tokens`).
- At the deadline the envelope becomes `EXPIRED` (one event), open links answer
  410, and the sender is told.

### Out of scope

Reminders by SMS; a deadline per signer.

## Gap 10: standalone documents, any PDF, as a module

### What exists

Only registered types can be signed: the quote (core), the agreement (CRM; an
uploaded PDF or one generated from its fields), the ÄTA (WorkOrders). The
generic key column `signable_id` (2026-12-20 migration) is ready for a new
type. There is no list of all signings.

### Scope

- **The engine stays in core** (ADR-075 §7): envelopes, several signers,
  checks, the chain, consent, reminders, sealing, the Verify page and retention
  apply to every document type, with or without any module. The engine's
  settings (retention, seal certificate, reminders, defaults, providers) live
  under Inställningar → Signering (`signing:settings`).
- **A new module `signing`, "Signering"** (marketed as NONOX Signering,
  `app/Modules/Signing`, ADR-017) adds what a company wants for its own
  documents, with or without CRM:
  - **Standalone documents** (`Signable` type `DOCUMENT`, key in
    `signable_id`): upload any PDF, give it a title, optionally link it to a
    customer or supplier (read only with that register's view permission).
  - The **envelope list** `/signering` (filters in the query string: out,
    completed, declined, expired, voided; search on title), the envelope page
    `/signering/{id}` with the timeline, each signer's status, the files,
    remind, change deadline and void, and the send wizard `/signering/ny`
    (document → signers and steps → checks → fields → deadline and reminders →
    review → send).
  - Templates and bulk send (gap 8) and the field editor (gap 2).
  - **Confidential envelopes** (owner decision 20): visible only to the sender
    and `signing:manage`, for employment contracts and similar.
- With the module off, open links of standalone envelopes answer as any link
  whose module is off; completed ones stay readable with `signing:view` as an
  archive permission; the Verify page keeps answering.
- The CRM, quote, agreement, ÄTA and portal hooks keep working unchanged. The
  envelope list shows their envelopes too, each only to users who may view the
  underlying document.

### Data model

`signing_documents` (company-scoped): `title`, `path`, `name`, `sha256`,
`bytes`, `page_count`, `customer_id`, `supplier_id` (both nullable),
`uploaded_by`, `archived_at`. Registered in `SignableTypes` with
`GENERIC_COLUMN`. Its fingerprint is the file's SHA-256: the file never
changes; a new file is a new document.

### Permissions

In the style of AGENTS.md §4. The module's permissions exist only while it is
on (ADR-017); `signing:settings` and `signing:privacy` are core, since they
govern the core engine.

| Permission | What it allows | owner | admin | accountant | bookkeeper | approver | auditor | sales (CRM) |
|---|---|---|---|---|---|---|---|---|
| `signing:view` (module) | the envelope list for standalone documents, their files and certificates | ✓ | ✓ | ✓ | ✓ | | ✓ | ✓ |
| `signing:send` (module) | upload, send, remind, change deadline, void one's own | ✓ | ✓ | ✓ | ✓ | | | ✓ |
| `signing:manage` (module) | templates, bulk send, void anyone's, see confidential envelopes | ✓ | ✓ | ✓ | | | | |
| `signing:settings` (core) | retention, seal certificate, providers, defaults | ✓ | ✓ | | | | | |
| `signing:privacy` (core) | a signer's register extract and erasure | ✓ | ✓ | | | | | |

The `approver` gets nothing; the `field_worker` (ADR-074) keeps only its
on-site flow. Envelopes of other types follow their document's permissions
(`invoices:view` for quotes, `crm:view` for agreements, the work-order
permission for ÄTA). The new strings go into AGENTS.md §4's list by a
human-approved edit when S7 merges, as in ADR-081. API abilities map one to one
(`signing:view`, `signing:send`).

### Security (uploads)

- PDF only: the `%PDF-` header, MIME `application/pdf`, at most 20 MB and 100
  pages (owner decision 22), not encrypted or password-protected, inspected by
  the engine. A PDF with JavaScript, launch actions or embedded files is
  refused with a plain message (rejected: stripping them, which rewrites the
  bytes). So is a PDF with actions that send data out or open another file
  or medium (`/SubmitForm`, `/ImportData`, `/GoToR`, `/GoToE`) or with
  `/RichMedia` (Lead decision in S5's review, 2026-11-18).
- Stored on the private disk under a random name; served with
  `Content-Type: application/pdf`, `X-Content-Type-Options: nosniff`, a
  `Content-Disposition` with a sanitised name, and a `sandbox` CSP on the file
  response shown in the iframe.
- Tenancy: every route answers 404 for another company's envelope, document,
  template or bulk send (tenancy suite).

## Gap 11: retention, a signer's register extract and erasure

### What exists

Signed requests are never deleted (trigger); unsigned links are deleted with
their PDF a year after they closed (`signing:prune`, `crm:agreements`). Signed
quotes and agreements follow their record (BFL where they are
räkenskapsinformation). `EraseRegisterEntry` keeps a customer with a signed
agreement. The register extracts do not list a signer's evidence (an open item
in Consequences).

### Scope

- **Retention per company and class** (Inställningar → Signering):
  - documents that are räkenskapsinformation (a signed quote that became an
    order or invoice; an agreement of importance to a business event): 7 years
    after the end of the calendar year in which the fiscal year ended (BFL 7
    kap. 2 §), not shortenable;
  - other signed documents (standalone, agreements that are not
    räkenskapsinformation): by default 10 years after completion, settable
    from 7 to 25 years (owner decision 12);
  - unsigned (declined, voided, expired): 12 months after closing, as today.
  - `retention_until` is set at completion and shown on the envelope page.
- `signing:prune` deletes envelopes past `retention_until` with their files,
  versions, fields, values and events. The delete trigger is replaced to allow
  deleting a signed envelope only once `retention_until` has passed. A legal
  hold (`retention_hold`, `signing:settings`) stops pruning.
- **Register extract** for a signer (`signing:privacy`): search by exact
  e-mail address across the company's envelopes; the result as JSON with the
  PDFs and certificates the person signed, through the existing
  `App\Support\Privacy\DataSubjectExport`.
- **Erasure:** an envelope still out is voided and deleted; an unsigned one is
  deleted; a signed one within retention is refused with the legal basis
  stated (art. 17(3)(b) for räkenskapsinformation, art. 17(3)(e) for the
  evidence of a contract), and the refusal is recorded. Never a partial edit.
- Personal numbers from a provider (gap 12) use the `encrypted` cast, are shown
  masked, never reach the audit log, and are deleted with the envelope.

### GDPR

- The company is the controller for signer data; NONOX is its processor. The
  DPA, `docs/gdpr/registerforteckning.md` (the signing paragraph widened from
  CRM to every document type, the checks, the consent, the provider data),
  `docs/gdpr/lagringstider.md` (the classes above) and the privacy policy are
  updated in S16, with one `LegalPageController` bump at the merge.
- Legal basis: a contract or steps before one (art. 6(1)(b)) where the signer
  is the party; legitimate interest (art. 6(1)(f)) in evidence where the signer
  acts for a company; legal obligation (art. 6(1)(c), BFL) for
  räkenskapsinformation. A standalone document can hold anything (an
  employment contract): the company chooses the purpose, and the manual tells
  it to use confidential envelopes and to keep special categories out of
  field values.
- Sub-processors: a timestamp server receives only a hash (no personal data);
  a signing provider (gap 12) receives the signer's identity data and is named
  in `docs/juridik/underbitraden.md` before it is switched on.

### Legal items

- `TODO(legal-verify)`: the 10-year default (the general limitation period in
  preskriptionslagen (1981:130) 2 §) against art. 5(1)(e).
- `TODO(legal-verify)`: whether a BankID signing provider is a processor or an
  independent controller.

## Gap 12: advanced and qualified signatures through a pluggable provider

### What exists

None. The manual advises BankID signing outside NONOX where identity matters.
The driver pattern exists: `App\Modules\Banking\Contracts\BankDataProvider`
(ADR-034, keys `enable_banking` and `fake`) and
`App\Modules\Peppol\Contracts\PeppolAccessPoint` (ADR-050).

### Scope

- A contract `App\Domain\Signing\Providers\SignatureProvider` with `key()`,
  `claimedLevel()` (`SES`, `AES`, `QES`, as the provider states it under its
  agreement and the EU trust list), `start()` (a redirect, or a QR and
  autostart session), `collect()`, `evidence()`, and a registry like the
  banking one. Drivers:
  - **`fake`** for tests.
  - **`csc`**: a remote signing service with the Cloud Signature Consortium
    API v2 (OAuth2 credential authorisation, `credentials/info`,
    `signatures/signHash`). NONOX computes the PAdES signature's digest with the
    engine's external signing, the signer authorises it at the provider, and
    the returned signature and certificate chain become **the signer's own
    PAdES signature** in their revision. With a qualified certificate on a
    qualified device at a QTSP, that is a qualified electronic signature.
  - **`bankid`**: Swedish BankID (the relying-party API's sign call, with the
    title and a short hash as visible data and the revision's SHA-256 as
    non-visible data), an animated QR code (`bacon/bacon-qr-code`) and
    same-device start. The completion data (BankID's signature and the OCSP
    response) is stored encrypted as evidence; the name and the masked personal
    number appear on the certificate; the NONOX seal then covers the document.
    BankID's signature is over BankID's own data, not a PAdES signature in the
    PDF; how that is classified is a legal item.
- A provider is configured per company (`signing:settings`) with its
  credentials encrypted. A company-supplied base URL passes the same address
  checks as webhooks (`App\Modules\Connect\Webhooks\WebhookAddress`,
  `UnsafeWebhookAddress`) against SSRF. Provider costs are charged through
  NONOX Connect at cost plus 10 % (the owner's Connect pricing).
- The agreements with BankID (through a bank or a reseller) and with a QTSP
  are signed by the operator company, like the other external agreements; the
  driver is built and stays switched off in `/hq` until then.
- A later candidate, not built: the EU Digital Identity Wallet under the eIDAS
  amendment (EU) 2024/1183, through the same contract.

### Legal level (eIDAS and its Swedish complement)

- **What NONOX can claim by itself:** a **simple electronic signature** (eIDAS
  art. 3(10)) with integrity evidence. Art. 25(1): it may not be denied legal
  effect or admissibility as evidence solely because it is electronic or not
  advanced or qualified. With the seal, the document's integrity since
  completion is protected by an electronic seal of the operator or of the
  company (art. 3(24)–(27)), which is not the signer's signature.
- **What needs a provider:** an **advanced electronic signature** (art. 26:
  uniquely linked to the signer, capable of identifying them, created with
  data under their sole control, linked so that any later change is
  detectable) needs an identity and a key the signer controls. A **qualified
  electronic signature** (art. 3(12); art. 25(2): the equivalent of a
  handwritten signature) needs a qualified certificate and a qualified
  signature creation device from a QTSP. An e-mail code or an access code
  meets neither.
- **Sweden:** lag (2016:561) med kompletterande bestämmelser till EU:s
  förordning om elektronisk identifiering och betrodda tjänster, and förordning
  (2016:576); PTS supervises trust service providers. `TODO(legal-verify)`:
  confirm both citations and whether either adds anything for NONOX.
- `TODO(legal-verify)`: whether a BankID signature as described (BankID's
  signature over the document hash, plus the NONOX seal) is an advanced
  electronic signature of the document.
- `TODO(legal-verify)`: which documents have form requirements that no NONOX
  signature meets (for example jordabalken 4 kap. 1 § for a property purchase,
  which the manual already names, and ärvdabalken 10 kap. 1 § for a will), and
  whether some require a qualified signature. The standalone upload shows a
  short warning with the list.
- `TODO(legal-verify)`: whether the signing page for consumers falls under lag
  (2023:254) om vissa produkters och tjänsters tillgänglighet (the European
  Accessibility Act); NONOX targets WCAG 2.2 AA in any case.

## Marketing: what may and may not be claimed

May be said, once built and true on the hosted service:

- "Enkel elektronisk underskrift enligt eIDAS, med kontroll med kod per e-post
  eller åtkomstkod."
- "Flera undertecknare i ordning eller samtidigt, fält i PDF:en, mallar med
  roller, påminnelser och massutskick."
- "Den färdiga PDF:en förseglas (PAdES) och tidsstämplas", only at the level
  actually configured; "långtidsvalidering" only when B-LTA is produced.
- "Kontrollera ett dokument utan att ladda upp det: bara filens kontrollsumma
  skickas."
- "Behandlingshistorik med hashkedja", "öppen källkod (AGPL)", and "drivs i
  EU/EES" as the legal pages already say.
- "Avancerad eller kvalificerad underskrift med BankID eller en kvalificerad
  tjänst", only for a provider that is switched on, with the provider's own
  claim, and never before the legal items above are answered.

Must not be said:

- "Juridiskt bindande" without qualification, "rättssäker", "lika säker som
  BankID", "ersätter BankID", "100 % säker", "eIDAS-certifierad", "godkänd
  av" an authority, "avancerad" or "kvalificerad" for NONOX's own signing, or
  "betrodd tjänst"/"tillitstjänst" before the trust-service item is answered.
- The reference product's name, or "som X" or "ett alternativ till X" for it
  (AGENTS.md §1.1). Comparison rows in `docs/migration/jamforelse.json` name
  only programs NONOX may name under ADR-020, with values from the vendor's
  own pages, else `unknown`.
- Marknadsföringslagen (2008:486) 10 § applies to every claim; the launch
  sweep (S21) gets a legal review of every public text.

## What stays out

Several documents in one envelope; delegating or reassigning a signing step;
SMS; custom domains per company (owner decision 18: NONOX has no TLS
automation today; an instance-level signing host is supported instead);
hardware security modules and PKCS#11 for seal keys (a provider can cover
that); payments on the signing page; video identification; in-person signing
on a member's device for standalone documents (the ÄTA on-site flow stays);
right-to-left scripts; signing in the scanner app.

## Translations

Per ADR-081 E0, each package writes its English strings to
`lang/packages/<package-key>/en.json` (for example
`lang/packages/s01-envelopes/en.json`); Swedish is the source language. E0 is
not merged on `7f0b001d` (`lang/packages/` does not exist), so S1 depends on
E0. If E0 has not merged when S1 starts, Lead builds E0 first as ADR-081
specifies it, not a second copy of it.

## Owner decisions (answer in one go)

Until the owner answers, Lead builds on the recommended default under the
owner's mandate of 2026-11-18. The owner may overturn any default before the
package that depends on it merges.

| # | Question | Recommended default | Reason |
|---|---|---|---|
| 1 | Engine in core, plus a new module "Signering" (`signing`) for standalone PDFs, templates and bulk send? | Yes | Quotes and ÄTA keep working without modules (ADR-075 §7); companies without CRM get standalone signing |
| 2 | Price of the module | Included like the other modules; provider fees (BankID, QES) through NONOX Connect at cost + 10 % | As every module; only third-party costs are charged |
| 3 | PDF engine: a Python sidecar with pyHanko and pypdfium2 | Yes, optional for self-hosters | The only AGPL-compatible way found to stamp and seal any PDF with PAdES up to B-LTA while keeping the original bytes |
| 4 | What works without the engine? | Today's signing plus every gap that needs no PDF work; fields and seal say "Kräver PDF-motorn" | Honest, and keeps ADR-002's pure-PHP base |
| 5 | Seal certificate on nonox.app | Self-signed instance seal at launch; an organisation seal certificate (ideally qualified) for the operator company later; companies may upload their own | Works now; a trusted seal costs money and waits for the trust-service answer |
| 6 | Timestamp server on nonox.app | A public CA time-stamp service as interim (after a terms check); a qualified one from the EU trust list once contracted; both as config URLs | The best evidence with a contract, no blocker without one |
| 7 | Limits | 10 signers and 10 copy recipients per envelope | Matches the reference and keeps the screens simple |
| 8 | Parallel signing | Yes: signers with the same step number sign at the same time | Closes the reference's own gap at almost no cost |
| 9 | Default signer check | Quotes and agreements: none (as today); standalone documents: e-mail code | No change to existing flows; standalone documents travel further |
| 10 | Consent step | Yes: one short versioned text per signer and envelope | Cheap evidence, versioned like the legal pages |
| 11 | What the Verify page shows | Company, title, version, completion time, number of signers, check methods, seal level; no names or addresses | Enough to trust the file, nothing more |
| 12 | Retention of signed documents that are not räkenskapsinformation | 10 years after completion, settable 7–25 | The general limitation period; the BFL classes stay at 7 years from year-end |
| 13 | Reminder default | Every 3 days, at most 10, during business hours | Useful without nagging |
| 14 | Deadline default | 30 days (3–90), changeable while out | Today's default, with the reference's range |
| 15 | Bulk send limit | 500 envelopes per run, within the mail quota | Protects deliverability and the queue |
| 16 | BankID driver | **Owner 2026-11-18: not now.** S18 is deferred; S17's provider framework is still built so BankID can be added later without rework | A simple electronic signature is legally valid for the documents NONOX signs (eIDAS art. 25(1)); BankID only adds evidential weight |
| 17 | CSC API v2 driver | Build it generically (S17); no QTSP contracted yet | Opens qualified signatures without binding NONOX to one provider |
| 18 | Custom domain per company | Not now; an instance-level signing host only | No TLS automation exists; self-hosters already have their own domain |
| 19 | Certificate language | A bilingual Swedish/English certificate; page and mails in each signer's language (sv/en) | Foreign signers are common; one certificate for all |
| 20 | Confidential envelopes | Yes, visible only to the sender and `signing:manage` | Employment contracts and similar |
| 21 | Quotes with several signers | Opt-in through "Fler undertecknare"; the order is made when all have signed | No change for today's one-signer quotes |
| 22 | Upload limits | 20 MB and 100 pages per PDF | Keeps rendering and mail sizes sane |
| 23 | API and webhooks now | Yes (S19): envelopes, recipients, fields, send, remind, void, files; `signing.*` webhooks | The reference's third gap; the same Actions as the UI |
| 24 | Order of build | The waves below; the engine (S1–S4) before the PDF work (S5–S11) | The most value with the fewest dependencies first |

## Build packages

Each package ships alone: migration(s), actions, policy, tests (feature,
tenancy, immutability of the evidence, query count), Livewire UI, the manual
article(s) with real screenshots from demo data, a changelog entry, its own
translation file (`lang/packages/<key>/en.json`), `Features.php` and the
landing card when user-visible, README, `docs/systemdokumentation/README.md`
where the quote-to-order flow or the evidence changes, OpenAPI when the API
changes, `docs/gdpr/*` where personal data changes, and demo data. Sized for
one builder agent each.

"Model": **standard** for ordinary CRUD and UI work, **heavy** for evidence,
cryptography, PDF signing, legal or security-critical logic. "Review":
**technical**, or **technical + legal** where law applies.

| # | Package | Gap | Depends on | Model | Review | Main files (conflict surface) |
|---|---|---|---|---|---|---|
| S1 | Envelopes: `signing_envelopes`, signer columns and backfill, steps (in order and parallel), copy recipients, `signing_link_tokens`, decline ends the envelope, void, completion, envelope-aware hooks with the one-signer adapter | 1 | ADR-081 E0 | heavy | technical + legal | `SendForSigning`, `SignAtLink`, `SignInSession`, `SigningFollowUp`, `SigningHook(s)`, `SigningRequest`, protect triggers, `SigningPageController`, CRM and WorkOrders hooks (adapter only) |
| S2 | Hash chain: event columns, canonical JSON, chained inserts, `signing_document_versions`, nightly `signing:verify`, audit entry with the head | 4 | S1 | heavy | technical + legal | `SigningEvent`, new `app/Domain/Signing/Chain/*`, scheduler, envelope page status |
| S3 | Signer checks: e-mail code and access code, `signing_verification_codes`, throttles, lockout and notice; the ÄTA on-site code moved onto the service | 3 | S1 | heavy | technical | `SigningPageController`, new verification actions, `SignChangeOrder` |
| S4 | Consent to electronic records: text and version, the step on the page, evidence columns, certificate line | 7 | S1, S2 | standard | technical + legal | `docs/juridik/elektroniska-handlingar.md`, signing page, `certificate.blade.php` |
| S5 | PDF engine: `PdfEngine` contract, `tools/pdf-engine/` (pyHanko, pypdfium2, pinned), `SidecarPdfEngine`, `FakePdfEngine`, `signing:engine-doctor`, font fetch script (Noto incl. CJK), CI install, self-hosting guide | 5 | — | heavy | technical | new `app/Domain/Signing/Pdf/*`, `tools/pdf-engine/`, `scripts/`, CI workflow, `docs/juridik/egen-drift.md` |
| S6 | Fields core: `signing_fields`, `signing_field_values` with triggers, signer page with page images and fields, validation per type, initials, a stamped revision per signer, earlier signers' values visible and locked | 2 | S1, S2, S5 | heavy | technical | signing page and `signing.js`, `SignAtLink`, new field actions |
| S7 | Module "Signering": `SigningModule`, permissions, `signing_documents` and the `DOCUMENT` type, upload checks, the `/signering` list and envelope page, send wizard (without the field step), confidential envelopes | 10 | S1, S3 | standard | technical + legal | new `app/Modules/Signing/*`, module registry, `SigningPanel` ("Fler undertecknare") |
| S8 | Field editor: PDF.js on internal pages, drag and keyboard placement, the wizard's field step | 2 | S6, S7 | standard | technical | `app/Modules/Signing/Livewire/*`, `resources/js/signing-editor.js`, Vite config |
| S9 | Deadline and reminders: 3–90 days, change while out, automatic and manual reminders, `signing:remind`, expiry event | 9 | S1 | standard | technical | new reminder action and command, envelope page, mails |
| S10 | PAdES seal: certificate pages appended, seal B-B/B-T, RFC 3161 configuration, the instance self-signed seal command, company PKCS#12 upload, expiry to-do | 5 | S2, S5, S6 | heavy | technical + legal | `app/Domain/Signing/Pdf/*`, `signing_seal_certificates`, settings page, `config/nonox.php` |
| S11 | Long-term validation: DSS (B-LT), document timestamp (B-LTA), nightly `signing:timestamp` | 5 | S10 | heavy | technical + legal | engine, scheduler, `signing_document_versions` |
| S12 | Verify page `/kontrollera`: Web Crypto hash, lookup, throttles, CSP, QR code and link on the certificate | 6 | S2 (S10 for sealed versions) | standard | technical + legal | new controller and view, `resources/js/verify.js`, `certificate.blade.php` |
| S13 | Completion mail to everyone: signers, copy recipients and company, per language, a link with its own token for large files | 5 | S1 (S10 for sealed files) | standard | technical | `SignedDocumentMail`, `SigningFollowUp`, mail views |
| S14 | Templates with roles: `signing_templates`, roles, fields per role, send from a template, quote and agreement templates for signing | 8 | S7, S8 | standard | technical | module pages, `signing_fields` (template side) |
| S15 | Bulk send: CSV import with preview and validation, `signing_bulk_sends`, queued envelopes, quota | 8 | S14 | standard | technical + legal | module pages, queue job, mail quota |
| S16 | Retention, register extract and erasure: classes and settings, `retention_until`, legal hold, pruning with the replaced delete trigger, `DataSubjectExport` and erasure, GDPR and DPA texts | 11 | S1, S2 | heavy | technical + legal | `PruneSigningLinks`, delete trigger, `DataSubjectExport`, `EraseRegisterEntry`, `docs/gdpr/*`, `docs/juridik/*` |
| S17 | Provider framework and the CSC API v2 driver (+ fake): contract, registry, per-company encrypted configuration, SSRF checks, external signing through the engine | 12 | S3, S10 | heavy | technical + legal | new `app/Domain/Signing/Providers/*`, settings page, engine |
| S18 | **Deferred (owner 2026-11-18).** BankID driver: sign with QR code and same-device start, collect, encrypted evidence, masked personal number on the certificate; off in /hq until the agreement | 12 | S17 | heavy | technical + legal | `Providers/BankId/*`, signing page, certificate, `/hq` switch |
| S19 | API and webhooks: Connect endpoints for envelopes, recipients, fields, documents and templates; idempotency keys; `signing.recipient_signed`, `signing.completed`, `signing.declined`, `signing.expired`; OpenAPI and the API changelog | 1, 8, 10 | S7, S14 | standard | technical | `app/Modules/Connect/Http/Controllers/Api/V1/*`, `WebhookEvents`, `docs/api/openapi.yaml` |
| S20 | Branding and scripts: `Brand` on the page, mails and certificate; the instance signing host; the bilingual certificate; CJK in dompdf (script runs if needed); golden tests for Greek, Cyrillic and CJK | 5 | S5 | standard | technical | `SigningPdfs`, `certificate.blade.php`, mail views, `config/nonox.php` |
| S21 | Launch sweep: manual section `docs/manual/sv/signering/`, landing, `Features.php`, rows in `docs/migration/jamforelse.json`, README, a legal review of every public text, this amendment to "Accepted, implemented" | all | S1–S20 (S18 may launch later) | standard | technical + legal | public pages, `docs/migration`, this ADR |

**Parallel waves** (different files; the shared hotspots `andringslogg.json`
and `Features.php` are merged by keeping both sides, newest changelog entry
first; translations do not conflict after E0):

- **Wave 0:** ADR-081 E0, if not merged.
- **Wave A:** S1 and S5 in parallel (S5 touches no signing code).
- **Wave B:** S2, S3, S9 (after S1) and S20 (after S5) in parallel. S2 and S3
  both add events and touch `SigningPageController`: merge S2 first; S3
  rebases.
- **Wave C:** S4 (after S2), S6 (after S2, S5), S7 (after S3), S13 and S16
  (after S2). S4 and S6 both edit the signing page: S4 first. S7 and S16 both
  touch the settings page: S7 first.
- **Wave D:** S8 (after S6, S7), S10 (after S6), S12 (after S2; its sealed
  versions after S10).
- **Wave E:** S11 (after S10), S14 (after S8), S17 (after S10).
- **Wave F:** S15 (after S14), S18 (after S17), S19 (after S14).
- **Wave G:** S21.

Packages that change evidence, cryptography or the legal level (S1, S2, S4,
S10, S11, S12, S16, S17, S18) get the independent legal and security review of
QUICKSTART §7 before merge. S10–S12 are not switched on for the hosted service
before the trust-service item in gap 5 is answered.

### Consequences

- This ADR's open items are closed by S19 (revoking over the API), S9 (a
  reminder near the deadline), S14 (agreement templates) and S16 (register
  extracts list a signer's evidence).
- This ADR's deviation "the signed PDF is never changed" is refined: the
  original bytes stay as their own file and as the first revision of the
  finished file, and the stamped and sealed versions are added beside them.
- New dependencies outside Composer: Python 3 with the engine's pinned
  requirements (optional per installation) and the Noto fonts fetched by
  script. Both are documented in the self-hosting guide; their licences (MIT,
  Apache-2.0, BSD-3-Clause, SIL OFL 1.1) are justified in S5's PR.
- `docs/gdpr/registerforteckning.md` and `lagringstider.md` change in S3, S4,
  S16 and S18. The systemdokumentation changes in S1 (a quote with several
  signers becomes an order only when all have signed) and S2.

### S5 as built (2026-11-18): the PDF engine

- **Verified before use** (PyPI metadata and the installed wheels'
  metadata): pyHanko 0.38.1 (MIT), pypdfium2 5.14.0 (Apache-2.0 or
  BSD-3-Clause; PDFium BSD-3-Clause), and 23 transitive packages under MIT,
  MIT-0, MIT-CMU, BSD-3-Clause, Apache-2.0, PSF-2.0 and MPL-2.0 (certifi), all
  compatible with AGPL-3.0. The list, and every library call relied on, is in
  `tools/pdf-engine/README.md`. Since pyHanko 0.37 its HTTP timestamp client
  is built on aiohttp, not requests. The lock (`tools/pdf-engine/requirements.txt`)
  pins every package with its SHA-256 hashes and was compiled with a two-week
  cooling period (`pip --uploaded-prior-to`); installs use `--require-hashes
  --only-binary=:all:`.
- **FPDI confirmed:** the free FPDI parser throws
  `CrossReferenceException::COMPRESSED_XREF` ("probably uses a compression
  technique which is not supported by the free parser") on a cross-reference
  stream (`src/PdfParser/CrossReference/CrossReference.php` in
  setasign/FPDI, master, read 2026-11-18). The rejection stands.
- **Contract:** `App\Domain\Signing\Pdf\PdfEngine` with the seven operations
  named above, `isInstalled()` and `health()`; drivers `SidecarPdfEngine`,
  `NullPdfEngine` (no helper: every operation throws "not installed") and
  `FakePdfEngine` (tests). `nonox.signing.pdf_engine.driver` is `auto` by
  default. The limits of decision 22 are constants in `PdfLimits`, enforced
  in PHP before the helper starts and again in the helper.
- **Process:** an argument list `[python, -I, script]` through
  `Illuminate\Support\Facades\Process`, JSON on stdin and stdout, a private
  `0700` work directory per call (inputs copied in, outputs copied out, then
  removed), an emptied environment, a 60-second wall-clock timeout, and
  `RLIMIT_AS`/`RLIMIT_CPU` set by the helper itself where the OS allows it.
  Every write is checked to start with the input's exact bytes, by the
  helper and again in PHP against the size and hash recorded when the input
  was copied in. Inputs are checked and copied from one open handle; stdout
  and stderr are capped while the helper runs (2 MB, 64 KB). Work
  directories left behind by a dead PHP process are removed hourly
  (`signing:prune-engine-work`). Timestamp URLs must be in
  `nonox.signing.timestamp.urls`, and the helper follows no redirects. The
  helper runs as the same OS user as PHP; an OS sandbox (bubblewrap,
  Landlock) is a possible later step.
- **Seal levels in S5:** B-B and B-T; B-LT and B-LTA are S11. The helper's
  `validate` uses no trust roots and fetches nothing.
- **Fonts:** Noto Sans 2.015 and Noto Sans CJK 2.004 (SIL OFL 1.1), fetched by
  `scripts/fetch-signing-fonts.sh` with pinned SHA-256; the helper picks the
  CJK font for text with Han, kana or Hangul. Per-script font runs inside one
  text are S20's.
- **Status:** `signing:engine-doctor` (exit 0 working, 1 failing, 2 not
  installed) and a card in /hq → Integrationer, cached ten minutes; the
  `<x-signing.requires-pdf-engine>` component shows "Kräver PDF-motorn" with
  a link to the self-hosting guide. Install steps: `docs/juridik/egen-drift.md`;
  the hosted service's runbook, every step awaiting the owner's approval:
  `docs/drift/pdf-motorn.md`.

### S20 as built (2026-11-18): branding and scripts

- **Brand is presentation only.** `Brand::for($company)` was already on the
  signing page (logo, colour bar, button) and the certificate (ADR-030: the
  client's own brand, never the firm's). S20 adds the colour bar to the
  envelope and completion mails too (all three signing mails now carry it;
  a logo is not put in mails, since inline images are unreliable in mail
  programs). Nothing a brand sets reaches the evidence digest, the event
  chain or the text of the certificate; a test compares the S2 evidence
  digest, the chain head and the extracted certificate text with and without
  a colour and a logo.
- **Signing host.** `nonox.signing.host` (`NONOX_SIGNING_HOST`), a plain host
  name; anything else is ignored. `SigningHost::link()` builds every signing
  link from it (the scheme follows `APP_URL`). `SigningHostOnly`, first in the
  web group, answers a request to that host with the signing pages (`/signera`,
  assets, `/up`) and 404 for everything else, and forces the root URL so the
  form posts back to the same host. The application's own address keeps
  serving the signing pages, so links already sent keep working. No custom
  domain per company (decision 18). The operator points DNS and a TLS
  certificate for the host at the application; that is outside NONOX.
- **Bilingual certificate (decision 19).** The certificate and the summary show
  every label as "Svenska / English" (`SigningPdfs::bilingual`,
  `bilingualOf`, `bilingualBlock`), independent of the viewer's locale; a text
  that is the same in both (a name, a number) is shown once; the signer's
  confirmation text is quoted once, as stored. English strings are in
  `lang/packages/signing-s20/en.json` and the existing `lang/en.json`. The
  signing page and the mails stay in Swedish; per-signer language of the page
  and mails is not part of S20.
- **CJK in dompdf.** Checked in the installed dompdf 3.1.6: a `font-family`
  list is resolved once per text node (whether the family exists), not per
  glyph, so there is no fallback. `PdfScripts::prepare()` therefore splits the
  text nodes of the finished HTML (never tags, attributes, `style`, `title`)
  into runs of Han, kana, Hangul and CJK punctuation, and wraps each in a span
  with the CJK font; Latin, Greek and Cyrillic stay in DejaVu Sans, which
  covers them (golden tests with Greek and Cyrillic names).
- **The font, and why it is not the S5 file.** dompdf embeds fonts as
  TrueType and cannot embed the CFF-based `NotoSansCJKsc-*.otf` that the PDF
  engine uses (an embedded subset that poppler reports as invalid). S20 uses
  Noto Sans CJK SC 2.004 **variable TrueType**, `NotoSansCJKsc-VF.ttf`
  (36 144 788 bytes, SHA-256 `990c807e…561d21f`), SIL OFL 1.1, a pan-CJK file
  with Han, kana and Hangul. It is fetched by
  `scripts/fetch-signing-cjk-font.sh` (pinned, fails closed, runs only where
  CJK names occur: the S5 script does not download it) into the same fonts
  directory, and is never committed. dompdf draws a variable font at its
  default instance, which in this file is the thinnest weight; CJK names
  therefore look lighter than the Latin text around them. That is a known,
  accepted limit: a static pan-CJK TrueType font under an open licence does
  not exist in the Noto repositories.
- **Degrading.** Without the file each CJK run is drawn as `[…]` and a
  bilingual notice is added to the certificate: the stored name and the
  checksums are unchanged and the notice says so. Broken glyphs never appear.
  The application never downloads the font or runs the script itself (review
  decision); an operator who needs CJK names runs the script by hand, and the
  owner forbids installing anything on the hosted server.
  dompdf copies the font into its own cache (`storage/app/pdf-font-cache`)
  once; the first CJK certificate takes about two seconds longer.
- **Not covered:** right-to-left scripts and Thai/Indic shaping (dompdf does
  no complex-script shaping); they are listed as out of scope above.

### S9 as built (2026-11-18): deadline and reminders

- Migration `database/migrations/2027_02_10_100000_add_signing_deadline_and_reminders.php`
  (additive): `signing_envelopes.auto_reminders` (default false, so an
  envelope that is already out is never reminded by a rule nobody switched
  on), `reminder_interval_days` (1, 2, 3, 7 or null) and `expired_at`;
  `crm_signing_requests.reminder_count` and `last_reminded_at`. Two triggers:
  `signing_envelopes_protect_deadline` (the expiry time is set once and only
  on an envelope that is out; an expired or finished envelope keeps its
  deadline and reminder settings) and `crm_signing_requests_protect_reminders`
  (reminders only grow; a signed or declined signer's trail is fixed). No new
  table, so no new grant decision.
- **Deadline:** `SigningDeadlines::resolve` (used by `SendForSigning` and by
  `ChangeSigningDeadline`): 3–90 days from today, the end of that day in
  Stockholm time, never after a quote's last valid day. A quote valid for fewer
  than three more days may use its own last day, the earliest that can be
  chosen. A change is allowed only while the envelope is out and not past its
  deadline; one `DEADLINE_CHANGED` event with the old and the new time (with
  offset), the envelope's and every open signer's expiry follow, and the
  signers whose step is open are mailed the new date (no link; the next
  reminder carries a fresh one).
- **Reminders:** the action layer defaults to **off** (principle 9): only the
  signing panel sends `auto_reminders` true, with its tick box on by default (every
  3 days, owner decision 13); the API and the ÄTA flow never switch it on; `reminder_days` is 1, 2, 3 or 7, or
  `single` for one reminder two days before the deadline. `signing:remind`
  (hourly at :41, `SigningReminders::run`) reminds signers on an open step, at
  most 10 per signer by rule, between 08:00 and 18:00 Stockholm time and never
  at or after the deadline. "Påminn nu" is once an hour per signer and counts toward the same cap of 10
  (after 10 it is refused with a message). Each reminder is one
  transaction that locks the document, the signer's row and the envelope (the
  order of every signing path), re-reads and re-checks under the locks, issues a
  fresh token in `signing_link_tokens` (reason `REMINDER`; earlier links stay
  valid until the deadline, so a mail that arrives late never locks a signer
  out), stores `last_reminded_at`, records `REMINDER_SENT` in the chain and the
  audit log (ids, kind and count only), and queues the mail after the commit.
  Idempotent: the stored marker plus the row lock, and a cache lock per
  company in the command. A reminder counts toward the `documents` mail quota
  with `MailQuota::count` and is never refused, as decided for S1.
- **Expiry:** the same run lets an envelope past its deadline expire: one
  `EXPIRED` event on one open signer's row (not another if a signer's page
  already recorded it; `SignAtLink::noteExpired` now also looks at the whole
  envelope), `expired_at` set once, an audit entry `signing.expired`, and an
  in-app notice to the sender. **Deviation:** the stored status stays `OUT`
  (the derived status is `EXPIRED`, as in S1) and the chain is not closed with a
  head, because a resend or a late withdrawal of an expired envelope must still
  be able to append its own events; a stored `EXPIRED` status would have
  touched `SignedDocuments`, the status check and every `isFinished` caller.
- `DEADLINE_CHANGED` and `REMINDER_SENT` are not signer steps, so the
  NONOX API's per-link `events` list is unchanged. Times are stored with their
  offset on `SigningEnvelope`, `SigningRequest` and `SigningLinkToken`.
- UI: Signering panel: **Påminnelser** with **Hur ofta** when sending,
  **Påminn nu**, **Ändra sista dag** (**Spara ny sista dag**), a reminder note
  and the count of reminders per signer. Manual: `docs/manual/sv/crm/signering.md`.
- A reminder's token does not revoke earlier tokens (all stay valid to the deadline). `SigningReminderMail` carries the URL like `SigningRequestMail` does. `SigningRequest` already writes times with offset.
- Open items: reminders by SMS and a deadline per signer stay out of scope;
  a reminder for an envelope sent through the API waits for S19.

### S6 as built (2026-11-18): fields core

- Migration `database/migrations/2027_03_09_100000_create_signing_fields.php`:
  `signing_fields` and `signing_field_values` as gap 2 specifies, with two
  additions on the field: `page_width` and `page_height` (1/100 PDF points,
  from the engine's `inspect` at sending), so a field's box over the page
  image needs no other row; a check constraint keeps every rectangle inside
  its page. `template_id` and `role_key` come with S14. Grants:
  `signing_fields` NoDelete, `signing_field_values` AppendOnly.
- **When fields are placed.** The envelope is created `OUT` (S1), so "frozen
  once not `DRAFT`" became: the insert trigger allows a field while the
  envelope is `DRAFT`, or `OUT` while no link token
  (`signing_link_tokens`) and no event (`crm_signing_events`) exists for any
  of its signer rows, so no signer can have been shown the document.
  `SendForSigning` takes an optional `fields` list
  (`SigningFields::validate`/`measure`/`place`), with the signer named by
  index (0 for one signer; the index in `signers`, kept in input order), and
  creates every signer row and places the fields before any link is issued.
  The PDF is made and measured by the engine (`measure`, inside the company's
  context) before the mail quota is taken and outside the transaction, and
  every field is checked against the pages there (`checkGeometry`: the page
  exists, is not rotated, holds the whole box), so a refused send costs no
  quota; the transaction stores those same bytes only if the locked
  document's fingerprint is unchanged. Updates are refused unless the
  envelope is `DRAFT`; deletes unless `DRAFT` or by the cascade. **The
  `DRAFT` delete branch is unreachable under the NoDelete grant**: S8, which
  edits fields of a draft, must either change the grant of `signing_fields`
  to ReadWrite or delete through a `SECURITY DEFINER` function. A field's
  signer row must be of the same envelope and company (trigger). The field
  editor (S8) and the wizard (S7) call the same action.
- **Values.** Written in `SignAtLink::sign`'s transaction, after the
  existing checks and before the signer row turns `SIGNED`
  (`FillSigningFields`): the trigger accepts a value only for the field's own
  signer while that signer is `PENDING`, and never an update; deletes only by
  the cascade. A date is the signing date in Stockholm time and a name the
  name the signer gives (neither can be typed); a signature field takes the
  drawn signature or the typed name; initials take a drawn image (the same
  PNG checks as the signature) or typed initials (1–10 letters; typed
  initials also count when nothing was drawn, as on a page without
  JavaScript); a text has
  `max_length` ≤ 500 and no control or format characters (the bidirectional
  overrides and isolates U+202A–202E and U+2066–2069 among them; the
  zero-width joiner U+200D, which some scripts need, is allowed). **Emoji are
  refused** in every stamped value ("Emoji och liknande symboler kan inte
  skrivas i dokumentet."): Noto Sans and Noto Sans CJK have no emoji glyphs,
  so they would print as empty boxes. A number has `decimals`
  (0–6) and `min`/`max` as exact decimal strings (compared with bcmath, a
  decimal comma accepted, stored with a point); a checkbox is `1` or absent;
  a dropdown value must be an option. **A radio field is one button**: its
  single option is its value and buttons with the same `group_key` are one
  choice (at most 30, one signer); the signer posts `radio[group] = field id`.
  Only filled-in values are stored, so an unticked box has no row.
- **Text fits its box.** A value is stamped inside its field's rectangle:
  the text stamp carries the box (`StampText` `boxWidth`/`boxHeight`, the
  helper's `box`), the engine draws it as a form XObject whose BBox is the box
  (PDF clips a form XObject to its BBox) and shrinks it to fit
  (`InnerScaling.SHRINK_TO_FIT`), and NONOX picks the font size so it fits,
  down to 6 points (`TextFit`, a conservative per-character width estimate).
  A text, number or dropdown value wider than the box at 6 points is refused
  with "Texten får inte plats i fältet"; placing a text field caps
  `max_length` at what fits at 6 points, and a dropdown option that does not
  fit is refused when the field is placed. What NONOX fills in (a date, a
  name, a typed signature or typed initials) is never refused at signing,
  since the signer could not change it: instead the box is checked when it
  is placed (a date box must hold `0000-00-00` at 6 points, a name or
  signature box at least 30 ordinary characters, an initials box `MM`), and
  a longer name is shrunk to 6 points and clipped. Boxes are at most 2 000
  points wide and high and positions at most 10 000 points, the stamp's own
  bounds, so an oversized field is a validation error. The `box` keyword and
  `SHRINK_TO_FIT` are not yet verified against an installed pyHanko (no
  engine may be installed here); `tools/pdf-engine/README.md` says so.
- **Rejected values.** Anything posted for a field that is not the signer's
  own is ignored and recorded as `FIELD_REJECTED` with the envelope's field
  ids posted (at most 50) and a count of other keys. The event is part of the
  signing transaction, so it is kept only when the signing goes through, not
  for an attempt that is refused. Tests prove a signer can never write
  another signer's field (action and trigger).
- **The revision per signer.** `FieldStamper` stamps the values through
  `PdfEngine::stamp` onto the latest version. It reads the shown version's
  bytes once, refuses the signing (the link answers as gone) when they no
  longer have the version's SHA-256, and checks that the new file starts with
  those exact bytes. **A byte prefix proves that the earlier revision can be
  recovered from the new file, not that its pages look the same**: an
  incremental update can draw over or hide content, which is why the stamp
  only adds content inside the fields' boxes and why each version is kept and
  hashed on its own. The file is a new
  `signing_document_versions` row `AFTER_SIGNER` with the signer's id. A
  signer who filled in nothing stampable adds no version; their own copy is
  then the version their `FIELDS_FILLED` event names (`version_sha256`), not
  whatever is latest when the mail is built. The signer must
  have been shown the latest version (`revision_sha256`, checked under the
  locks); a parallel signer who signed in between makes the page ask for a
  reload. A file written by a transaction that rolls back is removed
  (`DB::afterRollBack`, read in the installed framework's
  `ManagesTransactions` and `DatabaseTransactionsManager`). The
  signer row's `document_sha256` (their evidence) stays the original's.
- **Chain.** `FIELDS_FILLED` (before `SIGNED`) carries each value's hash
  (`FieldStamper::valueHash`: field id, value, the image's SHA-256, the time
  to the second), the shown version's and the new version's SHA-256; never a
  value. The S2 evidence digest of `SIGNED` is unchanged. `signing:verify`
  gains the code `FIELDS`: every stored value against its named hash, and
  every named value present.
- **The signer page.** With fields, the iframe gives way to the pages as PNG
  images (`/signera/{token}/sida/{n}`, route `signing.page`, only after the
  check, `no-store`, `nosniff`, its own `default-src 'none'`), rendered once
  per version by `PdfEngine::renderPages` in a queued job
  (`RenderSigningPages`, with `company_id`, dispatched after commit when an
  envelope with fields is sent and after each `AFTER_SIGNER` stamp; timeout
  300 seconds, a lock per company and version that lives 600 seconds and is
  tried with `get()`, never waited for, and a job that finds it taken is put
  back for 30 seconds) and kept on the private disk under
  `crm-signing/{company}/sidor/{sha256}/` with a manifest. A request only
  reads the manifest: until it exists the page says "Sidorna kan inte visas
  som bilder just nu" and lists the fields, and asks for the render again at
  most every five minutes. The page's CSP is unchanged. Page images have their own limiter `signing-pages` (300 a minute
  per link, 600 per IP address), since up to 100 pages load as the signer
  scrolls. **Deviation:** the boxes over the image are `<label>`s (hidden from
  assistive technology) that move focus to the field's control; the controls
  themselves are the list under each page, in reading order, joined to the
  form with `form="signera-formular"`, so keyboard and screen-reader users use
  one set of controls and the page works without JavaScript. "Nästa
  obligatoriska fält" is a link to the first required field, and with
  JavaScript moves to the next empty one (`resources/js/signing-fields-core.js`,
  Node tests). Earlier signers' values are in the image and listed as
  "ifyllt av Undertecknare 1"; later signers' fields are dashed boxes named
  by number only (roles come with S14). The PDF link serves the version the
  signer is shown (their own `AFTER_SIGNER` once signed). The finished
  document that goes to the parties is the version with every signer's
  values: `SigningFields::finalVersion` (the last `AFTER_SIGNER`, else the
  PDF that was sent) in `SigningEnvelopeMail` and the company's file link
  (`SigningFileController`), and `SignerFields::shownVersion` in a signer's
  own `SignedDocumentMail`. Envelopes without
  fields keep today's page exactly; the page runs a fixed number of queries
  (test with 2 signers × 1 field and 10 × 12).
- **Limits chosen:** 200 fields per envelope, boxes at least 5 × 5 points.
  Fields are refused on a rotated page (`/Rotate` ≠ 0); the crop box's origin
  is taken as the page's lower left corner. Both are left for S8 with the
  engine's geometry. The prune deletes the rows first and the files after
  the commit, and removes a version's page images only when no version of
  another envelope is the same file: those of the original with its PDF, and
  the `AFTER_SIGNER` files and images of an envelope that is pruned. In
  practice an envelope with an `AFTER_SIGNER` version has a signed signer and
  is never pruned today, so its values, versions and images stay until
  S16's retention, also when the envelope never completed (row in
  `docs/gdpr/lagringstider.md`).
- **Defect found and fixed:** every signer of an envelope shares the
  document's path, so the second signer's Signeringsbevis was written to the
  first one's file name and overwrote it (S1). `signing:verify` reported
  `FILE_CHANGED`. A certificate whose name is taken now gets the signer row's
  id in its name (a test signs with two signers and finds two certificate
  paths and a clean `signing:verify`). **Operator note:** an envelope with
  several signers that was signed before this fix has lost every certificate
  but the last signer's; `signing:verify` reports `FILE_CHANGED` for it. The
  evidence itself (the signer rows and the chain) is intact, and each lost
  certificate can be made again from it (`SigningPdfs::certificate`); the
  stored `signed_document_sha256` will not match the new file, so record the
  re-creation in the behandlingshistorik rather than changing the row.
- No screenshot of the field page: the PDF engine is not installed on the
  machine that takes the manual's images, and the owner forbids installing it;
  with the test engine the pages would be blank. The manual describes the page
  in text (`docs/manual/sv/crm/signering.md`, "När dokumentet har fält").
