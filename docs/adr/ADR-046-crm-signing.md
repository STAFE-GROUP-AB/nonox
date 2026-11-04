# ADR-046: CRM stage 3, signing, quote templates and the agreement register

- Status: **Implemented, waiting for the owner's review** (2026-11-04)
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
- `TODO(legal-verify)`: the audit log keeps the request's IP address on
  every entry, also `crm.signing_declined`; whether that should be dropped
  for a decline as it is on the signing event.

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

