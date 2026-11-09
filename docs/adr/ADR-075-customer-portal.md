# ADR-075: Customer portal (kundportal)

Status: Proposed
Date: 2026-11-08
Relates to: ADR-000 (passwordless sign-in), ADR-011 (invoicing, quotes and orders),
ADR-015 (incoming payments), ADR-017 (module framework), ADR-023 (inbox), ADR-028 (tasks and
notices), ADR-029 (passkeys), ADR-030 (branding), ADR-043 (CRM and GDPR), ADR-045 (encrypted
chat), ADR-046 (signing pages), ADR-050 (Peppol), ADR-055 (payment initiation), ADR-068 (read
access log), ADR-071 §9 (customer ordering), AGENTS.md §1.3, §1.4, §4, §10, §11.4.
Rule sheet: `docs/specs/rules/kundportal.md`.

## Context

The roadmap item `kundportal` promises: "Dina kunder loggar in och ser sina fakturor, betalar
dem, laddar ner underlag och skickar frågor till dig." ADR-071 §9 already assumes the portal as
the place where business customers will later place orders.

What NONOX has today (read in the code on this branch):

- **Invoices** (`Invoice`, `InvoiceRow`, `InvoicePayment`, `InvoiceReminder`): statuses DRAFT,
  ISSUED ("Obetald"), PAID, CREDITED, WRITTEN_OFF; credit invoices (`CreditInvoice`); reminders
  with fee (`SendReminder`); payments registered by hand or matched from camt.054 by OCR
  (`RegisterPayment`, `IncomingPaymentMatcher`). The OCR number comes from `Ocr::forInvoiceNumber`
  at issue. The PDF (`InvoicePdf`, dompdf) is **rendered on demand**, not stored; it shows the
  company's bankgiro, plusgiro, IBAN/BIC (foreign currency) and the OCR number.
- **Delivery**: `SendInvoice` e-mails the PDF as an attachment (`InvoiceMail`), within
  `MailQuota` `documents`; a test company's invoices are never e-mailed. Peppol delivery is
  ADR-050. **There is no public link to an invoice and no public invoice view.** The only signed
  URLs are the company invitation, the firm logo on the login page and the migration runner.
- **Quotes and orders**: `SalesDocument` (QUOTE, ORDER), statuses OPEN, ACCEPTED, INVOICED,
  CANCELLED, sent by `SendSalesDocument` (`sent_at`, `sent_to`).
- **External pages**: the CRM signing page `/signera/{token}` (ADR-046): a 256-bit token stored as
  SHA-256, lookup inside `CompanyScope::bypass`, 404 for unknown and 410 for used links, its own
  CSP, `no-store`, `no-referrer`, noindex, rate limits, evidence bound to the PDF's SHA-256 and a
  `QuoteFingerprint`. It lives in the CRM module.
- **Customers** (`Customer`): name, org or personal number (hidden, encrypted), e-mail, phone,
  address, payment terms, currency, `invoice_delivery`, `peppol_id`. No contacts table. Contacts
  exist only in CRM (`Crm\Person` linked through `AccountPerson` with a `PersonRole`, one being
  `INVOICE_RECIPIENT`; `Crm\Account.customer_id` links to the customer), each with a recorded
  legal basis.
- **Sign-in**: e-mail one-time codes (`SendLoginCode`, `VerifyLoginCode`, `login_codes` keyed
  by e-mail only; 8 digits, 10 minutes, 5 attempts, a 60 s cooldown, caps per address, client
  address and globally), passkeys as a second step for sensitive data (ADR-029), sessions ended
  after 12 hours absolute (`EndExpiredSessions`) and `SESSION_LIFETIME` idle.
- **Employee portal (Min sida, `/anstalld`)**: employees are invited as ordinary NONOX users with
  the role `employee` (`InviteEmployeeToPortal`). That model is **not** reused here (§2).
- **Encrypted chat** (ADR-045): two signed-in NONOX users, nothing stored, deleted after 30
  minutes idle. **Inbox** (ADR-023): documents to be booked, with its own security pipeline.
- **Branding** (ADR-030 §3): documents the company issues always carry the company's own brand
  (`Brand::for`), never the accounting firm's.
- **Payments by card or Swish**: none. The till module maps a Swish payment method to an account
  (`PosModule`), which records money taken at a till; it does not take payments. ADR-055 (payment
  initiation) is about the company paying its suppliers, is Proposed, and nothing is built.

## Decision

Proposed by the architect 2026-11-08, for the owner's decision on the questions at the end and
the lawyer's answers in the rule sheet.

### 1. Placement and permissions

- A new optional module **`portal`** ("Kundportal"), `app/Modules/Portal`, switched on per company
  (ADR-017). No dependency: invoicing is core. CRM is optional (§3). Switching it off closes every
  portal page for that company at once (the contacts' pages answer as for an unknown address,
  §8) and keeps the data.
- New permissions, which exist only while the module is on:
  - `customer-portal:view`: see who has access, the questions and the portal events;
  - `customer-portal:manage`: invite, disable and remove contacts, change portal settings;
  - `customer-portal:reply`: answer questions.
- Owner and admin get all. Accountant and bookkeeper get all three (they run invoicing). Approver
  gets none. Auditor gets `customer-portal:view`.
- ADR-071's `customer-orders:review` is added by the ordering setting (§11), not by this module.

### 2. Who logs in: portal identities and contacts, never NONOX users

A portal contact is a person at the customer, not a member of the company. Making them NONOX
users (as Min sida does for employees) would put outsiders in `team_user`, in Jetstream's
company switcher, in role checks and in the user pruning, and one mistake in a role would give
a customer the company's books. So the portal has its own tables and guard.

```
portal_identities                          -- global, like users; not company-scoped
  id, email (unique, lower-case), name, created_at, last_login_at, anonymised_at

customer_portal_contacts                   -- BelongsToCompany
  id, company_id, customer_id FK customers, portal_identity_id FK portal_identities
  crm_person_id null                       -- link when invited from CRM
  status enum INVITED | ACTIVE | DISABLED
  can_accept_quotes bool default true, can_order bool default false (ADR-071, §11)
  invited_by, invited_at, accepted_at, disabled_by, disabled_at, last_seen_at
  unique (company_id, customer_id, portal_identity_id)

customer_portal_invitations                -- BelongsToCompany
  id, company_id, contact_id, token_sha256 unique, expires_at (14 days), used_at, revoked_at
```

- **One person, many portals.** The same e-mail can be a contact for several customers in the
  same company (a bookkeeper at a group) and for customers of many companies. One identity, one
  sign-in, then a choice of portal (§6). An identity carries no company data.
- **Separate guard `portal`**, provider `portal_identities`. A person who is also a NONOX user
  with the same e-mail has two independent logins: signing in to one never signs in to the other.
- **Separate session cookie** for `/kundportal`, so ending a portal session can never end the
  same browser's NONOX session and the reverse (`EndExpiredSessions` invalidates the whole
  session). How the cookie name is switched per path must be read in the installed Laravel
  source before building (`TODO(verify-laravel)`, not a legal item).
- **Login codes get an audience.** `login_codes` gains `audience` (WEB, PORTAL); a code issued
  for one is never accepted by the other. `SendLoginCode` and `VerifyLoginCode` take the audience;
  "has an account" for the caps means an ACTIVE or INVITED portal contact for PORTAL.
- **Private persons as customers.** For a customer that is a natural person (personal number,
  or a sole trader), only the customer's own e-mail can be invited, as one contact, because the
  invoices may carry the buyer's personal number (ROT/RUT) and other personal details.
- **Passkeys are not required** for portal contacts in v1 (owner question O3).

### 3. Invitation

- From the customer card ("Kundportal" panel): "Bjud in" with name and e-mail. The panel offers
  the customer's own e-mail and, when CRM is on, the account's persons, `INVOICE_RECIPIENT` first;
  choosing a CRM person stores `crm_person_id`. A CRM person marked "Kontakta inte" cannot be
  invited. A bulk action "Bjud in fakturamottagarna" is out of scope for v1.
- The invitation e-mail is sent through the app's mail, in the company's brand, with the company
  as Reply-To, within `MailQuota` `invitations`, never for a test company (marked, not sent, as
  `SendInvoice` does). It says who invites, what the portal shows, that it is optional, and links
  to the company's privacy notice for the portal (§12).
- The link carries a 256-bit token (stored as SHA-256, as in ADR-046), valid 14 days, one use.
  Opening it does **not** sign anyone in: the person must also enter a code sent to the invited
  address, so a forwarded mail alone gives nothing. The code proves control of the mailbox;
  accepting sets the contact ACTIVE and creates or reuses the identity.
- Inviting again revokes the earlier link. Disabling a contact ends its sessions on the next
  request (§8) and revokes open links.
- Audit: `customer_portal.contact_invited`, `_accepted`, `_disabled`, `_removed`: ids only.

### 4. What a contact sees

All lists are server-side paginated with a fixed number of queries (query-count test). Only the
contact's own customer in the chosen company, and only:

| Part | Content | Rules |
|---|---|---|
| Översikt | Open amount, overdue amount, next due date, open quotes, unanswered replies | Computed from the lists below |
| Fakturor | Number, dates, due date, amount, remaining amount, currency, status: Obetald, Förfallen, Delvis betald, Betald, Krediterad | Never DRAFT. Issued within the company's history setting (default 24 months, at least every open invoice). WRITTEN_OFF: owner question O5, hidden until decided |
| Faktura | Rows, VAT per rate, totals, payments (date and amount, no bank details of the payer), linked credit notes, reminders, PDF | Credit invoices shown both on their own and on the original |
| Påminnelser | Reminder date, fee, interest as stated on the reminder | Read from `InvoiceReminder`; nothing recomputed |
| Kontoutdrag | Open items on a chosen date, as a page and a PDF | New, read-only, computed from invoices and payments; not a document the company has issued, and says so |
| Offerter och ordrar | Sent quotes and orders (`sent_at` set), status, PDF | Accepting quotes: §7. Orders are read-only in v1 |
| Betala | Payment details per invoice (§5) | |
| Frågor | Threads with the company (§6) | |
| Mina uppgifter | Name, e-mail, portals, a JSON extract, "Ta bort min inloggning" | §12 |

- **PDFs.** The invoice PDF is rendered as today, from the issued invoice. The company's address,
  bank details and logo come from the company as it is now, so a PDF fetched later can differ
  from the one e-mailed. The portal labels it "Kopia av fakturan". Whether the issued PDF should
  be stored instead is question L6 and owner question O6.
- **Personal numbers.** The portal pages never show a personal number in clear: a ROT/RUT
  buyer's number is masked as on the internal pages. The PDF is the document already sent to
  that customer and is shown as issued.
- **Attachments the company chooses to share** (delivery notes, time reports) are out of scope
  for v1: invoices have no attachments today.
- **Peppol and paper customers** see their invoices too: the portal is a view, not a delivery
  channel, and it never replaces the invoice delivery the customer chose.

### 5. Paying

- **v1, what exists:** per open invoice the remaining amount, due date, the OCR number, the
  company's bankgiro and/or plusgiro, and IBAN/BIC for foreign currency, each with a copy button.
  Payments come in as today (camt.054 or by hand) and the portal shows them when booked.
  A sentence says when payments usually show up ("Betalningen syns här när den har kommit in
  till oss, normalt inom några bankdagar.").
- **"Jag har betalat"**: the contact can mark an invoice as paid on a date. It books nothing, is
  shown to the company as a notice on the invoice and in the questions list, and the portal shows
  "Du har meddelat att den är betald" until the payment arrives. Owner question O4.
- **Card and Swish: not in v1, and nothing exists to reuse.** A later ADR chooses a payment
  provider, behind a `PaymentProvider` driver interface reserved now (no driver shipped), with
  these fixed rules:
  - the company has its own agreement with the provider and is the merchant; NONOX never holds,
    routes or settles money;
  - a payment confirmed by the provider becomes an incoming payment registered through the same
    action as today (`RegisterPayment`), with the provider's reference, under the company's own
    accounts, and the company's books show the fee;
  - NONOX does not initiate payments from the payer's bank account (a payment initiation
    service in PSD2 terms). That would need authorisation or a licensed provider, as ADR-055
    describes for the opposite direction.
  - The rule sheet's PSD2 questions (L9, L10) must be answered before that ADR.
- **QR codes** for bank or Swish payment on the portal page: owner question O7 (the formats have
  not been read for this ADR).

### 6. Questions to the company (messages)

- **Not the chat (ADR-045):** it is for two signed-in NONOX users, keeps nothing and deletes
  itself after 30 minutes. A customer's question about an invoice, and the answer, must be kept,
  since they can concern a dispute.
- **Not the inbox (ADR-023):** the inbox is for documents to be booked, with interpretation,
  approval and booking retention. Documents a customer sends **as an order specification**
  (ADR-071 §9) do go to the inbox, through its security pipeline.
- **New, small:** `customer_portal_threads` (company, customer, contact, subject type and id:
  an invoice, a quote or none, status OPEN or CLOSED, kind QUESTION or DISPUTE) and
  `customer_portal_messages` (thread, author: contact or user, body ≤ 4 000 characters, created
  at), both `BelongsToCompany`. Attachments in v1: PDF, JPEG and PNG up to 10 MB, 5 per message,
  through the same checks as the inbox (type by content, not name; size; no active content),
  stored on the private disk.
- "Jag bestrider fakturan" opens a DISPUTE thread on the invoice; the company sees the invoice
  flagged. Whether a disputed invoice must stop reminders is question L8; until answered, NONOX
  only shows a warning before a reminder is sent on a disputed invoice.
- A new message creates a notice for the users with `customer-portal:reply` (ADR-028), and
  optionally a task. A reply e-mails the contact "Du har fått svar från {Företaget}" with a link,
  **never the content**, so nothing about the invoice sits in an outside mailbox unless the
  company chooses to send e-mail itself.
- Audit: `customer_portal.thread_opened`, `_closed`, `_dispute_opened`: ids only, never text.

### 7. Accepting quotes

- A contact with `can_accept_quotes` can accept or decline a sent, open quote. Accepting is a
  simple electronic signature by a signed-in contact: a checkbox "Jag har läst och godkänner
  offerten", the name typed, bound to the PDF's SHA-256 and the `QuoteFingerprint`, the same
  evidence and signing certificate as ADR-046, and the same follow-up (order, invoice).
- To avoid two implementations, the evidence part of ADR-046 (fingerprint, document hash,
  certificate, `SignAtLink` rules) moves from the CRM module to a core `app/Domain/Signing` that
  both the CRM link and the portal call. The CRM page stays in CRM.
- A quote that has an open CRM signing link and is accepted in the portal revokes the link under
  the same row lock, and the reverse.
- The signer is identified by the portal session (e-mail code) instead of a link; whether that
  changes anything about evidential value is in L7 (as in ADR-046).

### 8. Security

| Item | Handling |
|---|---|
| Enumeration | The login form always answers "Om adressen har tillgång till en kundportal har vi skickat en kod." Same response and status. Unlike `/logga-in`, no code goes to an address without a contact, so the response must not wait for the mail, or the time would tell: the code mail is sent from a queued job (not `defer()`, which killed PHP-FPM on 2026-11-04), with a timing test. No self-registration and no "request access" form |
| Codes | `login_codes` with `audience = PORTAL`; 8 digits, 10 minutes, 5 attempts, the existing cooldown and caps, counted separately from WEB |
| Rate limits | Login form and code: as `/logga-in`. Pages: 120 requests a minute per identity; downloads 30 a minute; message posts 10 a minute and 100 a day per identity; invitation link 30 a minute per IP |
| Session | Own cookie (§2); regenerated at sign-in; idle 30 minutes, absolute 12 hours; "Logga ut" ends it; sessions valid only from `sessions_valid_from` on the identity |
| Every request | The contact is ACTIVE, the customer active and not anonymised, the company not archived, the module on. Otherwise the session ends and the page answers as for an unknown portal |
| Tenancy | Every portal query goes through a `PortalContext` holding company id **and** customer id; `CurrentCompany` is set from it. Any id outside it answers 404, also within the same company. No route takes a company id or customer id from the request |
| Portal address | Each company gets a random portal key (16 characters, not the team id), used only in addresses and to pick the brand before sign-in |
| Documents | Streamed from the server with `Cache-Control: no-store`, `Content-Disposition: attachment` for downloads, never a public storage URL |
| Headers | As the signing page: own CSP without inline script, `frame-ancestors 'none'`, `form-action 'self'`, `Referrer-Policy: no-referrer` on token pages, `X-Robots-Tag: noindex`; `/kundportal` disallowed in robots.txt |
| CSRF, XSS | Laravel's token on every form; Blade escaping; message bodies shown as plain text |
| Audit log | Invitations, acceptance, disable, removal, quote acceptance and decline, disputes, "Ta bort min inloggning": ids and enums only |
| Portal events | `customer_portal_events` (append-only, ids only): SIGNED_IN, VIEWED_INVOICE, DOWNLOADED_PDF, VIEWED_STATEMENT. Shown to the company on the invoice ("Öppnad i kundportalen 12 nov") because it matters for reminders and disputes. No IP or user agent per event: they are on the sign-in event only |
| Read access log (ADR-068) | A contact reading their own customer's documents is not a staff read and is not written to `access_logs`. Company users opening a contact's details are, with purpose `CUSTOMER_ADMINISTRATION`, like the customer card |

### 9. Branding

- The portal is the company's channel to its customers, so it carries the **company's own brand**
  (`Brand::for`: logo, colours through `Brand::readable`/`accentText` for 4.5:1), the same rule as
  the documents in ADR-030 §3. The accounting firm's brand is never shown in the portal.
- Before sign-in the brand is chosen only from the portal key in an invitation or a link the
  company published. The plain `/kundportal` page is neutral NONOX, since nothing tells it which
  company the visitor wants.
- After sign-in with several portals, the chooser lists each company with its logo.
- Whether the footer says "Kundportalen drivs med NONOX" is owner question O8.

### 10. Swedish URLs

Portal (guard `portal`, own session cookie, route names English under `portal.`):

| Path | Page |
|---|---|
| `/kundportal` | Sign in (neutral) |
| `/kundportal/kod` | Enter the code |
| `/kundportal/inbjudan/{token}` | Accept an invitation |
| `/kundportal/valj` | Choose a portal |
| `/kundportal/{portalnyckel}` | Översikt |
| `/kundportal/{portalnyckel}/fakturor`, `/fakturor/{faktura}`, `/fakturor/{faktura}/pdf` | Invoices |
| `/kundportal/{portalnyckel}/kontoutdrag`, `/kontoutdrag/pdf` | Statement |
| `/kundportal/{portalnyckel}/offerter`, `/offerter/{dokument}` | Quotes and orders |
| `/kundportal/{portalnyckel}/fragor`, `/fragor/ny`, `/fragor/{trad}` | Questions |
| `/kundportal/mina-uppgifter`, `/kundportal/logga-ut` | Own data, sign out |

The company's side: the "Kundportal" panel on the customer card, `/kunder/portal` (contacts and
settings) and `/kunder/portal/fragor` (questions). `/kunder/{account}` in CRM takes numbers only,
so the paths do not collide.

### 11. Customer ordering (ADR-071 §9) as a later setting

- A portal setting `ordering`, off by default, available only when ADR-071's ordering is built.
  It adds `customer-orders:review`, the contact flag `can_order`, and two portal pages
  (`/kundportal/{portalnyckel}/bestall`, `/bestallningar`).
- Everything ADR-071 §9 and §10 decide applies unchanged: the assortment and prices per customer,
  specifications to the inbox, the RECEIVED status and review queue, confirmation only after
  review, and no delivery estimate shown that the company has not confirmed.
- Nothing in v1 depends on it: the portal tables already have `can_order`, and the navigation is
  built from a list the setting can add to.

### 12. GDPR

The company is the controller for its customers' contacts; NONOX (the hosted service) is its
processor under the DPA. Each company can turn the portal on or off.

- **Legal basis.** The brief said contract, art. 6.1 b. That fits only where the contact is
  itself party to the contract: a private person or a sole trader buying in their own name.
  Art. 6.1 b requires a contract "to which the data subject is party", and IMY: "Det här gäller
  bara avtal som den registrerade har ingått eller planerar att ingå." A contact person at a
  limited company is not party to the company's contract, so for them the basis is the company's
  legitimate interest (art. 6.1 f) in performing its contract with the customer, with an
  assessment in the manual as CRM has (ADR-043). The contact can object (art. 21) with
  "Ta bort min inloggning". Question L1 confirms this.
- **Information (art. 13).** The invitation and a portal privacy page state the company as
  controller, purpose, basis, what is kept, how long, and the rights, from a template the company
  can complete. Question L2.
- **Minimisation (art. 5.1 c).** An identity holds an e-mail and a name. No phone, no personal
  number, no IP on page events.
- **Retention (art. 5.1 e), defaults for `lagringstider.md`:**
  - unused invitation: 14 days, as company invitations;
  - a contact with no sign-in for 12 months gets an e-mail; 30 days later the contact is removed;
    the identity is deleted when it has no contacts left (owner question O9 on the period);
  - a DISABLED contact: removed after 30 days;
  - portal events: 24 months;
  - questions and messages: 24 months after the thread was closed, unless the thread is a
    DISPUTE or the company marks it as kept; question L5 decides when messages are
    räkenskapsinformation;
  - quote acceptances: as ADR-046 (seven years where they support an order or invoice);
  - audit-log entries: as the audit log (ids only).
- **Rights.** Access and portability: "Mina uppgifter" gives a JSON extract (identity, contacts
  per company, own messages, events), and the company's register extract for the customer
  includes the contacts. Rectification: the contact edits the name; the e-mail is changed by the
  company (it is the sign-in). Erasure: "Ta bort min inloggning" removes the contact in every
  portal or one; the identity goes when none is left; messages keep their text with the author
  shown as "Borttagen kontakt" until their own retention ends, because the company may need them
  for a claim (art. 17.3 e), question L4.
- **Security (art. 32)** is §8. A personal data breach in the portal follows
  `docs/gdpr/incidentrutin.md`.
- Documents to update when built: `registerforteckning.md` (B, as processor: portal contacts),
  `lagringstider.md`, the privacy policy and DPA in `docs/juridik/` (one version bump at merge),
  `docs/gdpr/README.md` (rights), and the manual.

### 13. Out of scope

- card, Swish or any other payment taken in the portal (§5, a later ADR);
- payment initiation from the payer's bank;
- contacts inviting colleagues, or the customer managing its own users;
- sending e-invoices or changing the delivery method from the portal;
- the encrypted chat for customers;
- documents shared by the company other than invoices, credit notes, reminders, statements and
  quotes;
- an English portal (the customer has no language field today, owner question O10);
- a native app; the portal is a responsive web page (installable later, ADR-031);
- an API for the portal (the company's API already covers invoices).

## Build steps (tests first in each)

1. **Rule sheet answered.** L1, L2, L4, L5 answered by a lawyer before step 2 merges to `main`
   in a form that is on for any company.
2. **Identity, guard and sign-in.** Tables, `portal` guard, own cookie, `audience` on
   `login_codes`, invitation with token plus code, `PortalContext`. Tests first: enumeration (same
   response for unknown, invited, active, disabled), caps per audience, a WEB code refused on the
   portal and the reverse, idle and absolute session end, disabled contact and module off end the
   session, the tenancy suite (another customer in the same company → 404, another company →
   404, a contact of two customers sees each only in its own portal), one person in two
   companies, no route reads a company or customer id from input.
3. **Invoices, credit notes, reminders, statement, payment details.** Query-count tests with a
   short and a long history; ROT/RUT masking; DRAFT never visible; history limit; test company.
4. **Questions.** Threads, messages, attachments through the inbox checks, notices, reply mail
   without content, dispute flag and reminder warning.
5. **Quote acceptance.** Extract `app/Domain/Signing` with ADR-046's tests passing unchanged
   first, then the portal acceptance and the cross-revocation.
6. **GDPR.** "Mina uppgifter", removal, pruning in `privacy:prune`, register extract.
7. **Docs.** Manual articles with `rutter`, changelog, landing feature card with a real
   screenshot, `Features.php`, a comparison row in `docs/migration/jamforelse.json` (competitor
   values only from vendors' own pages), README, `docs/gdpr`, `docs/juridik`.
8. **Later, own ADRs or steps:** ordering (§11, after ADR-071's purchase orders and bills of
   materials), payment provider (§5), QR codes (O7).

Each step: builder with tests first, cold reviewer (correctness, security, tenancy, a11y, and a
lawyer pass for GDPR), Lead merges.

## Consequences

- Outsiders get a login to NONOX for the first time without being members of a company. The
  separate identity table, guard and cookie keep them out of everything Jetstream, roles and
  tenancy assume about users, at the cost of a second, small authentication path to maintain.
- `login_codes` gains a column; the existing sign-in keeps its behaviour with `audience = WEB`.
- The signing evidence moves from CRM to core; CRM's signing page keeps working on top of it.
- Invoices gain "opened in the portal" events, useful when a customer says they never got one.
- No money moves through NONOX. Paying in v1 is the bank details and OCR the invoice already
  carries, shown better.

## Open questions for the owner

- **O1** Name: "Kundportal" in the app and "Kundportalen" in the mails, or another name?
- **O2** Price: included in every plan, or part of a paid tier?
- **O3** Passkeys for portal contacts: optional now, required never, or required for some
  companies?
- **O4** "Jag har betalat" (§5): keep it, or does it create more questions than it answers?
- **O5** Written-off invoices (kundförlust): hidden, shown as unpaid, or shown as closed?
- **O6** Store the issued invoice PDF at issue (bytes and SHA-256) so the portal always shows
  exactly what was sent? It costs storage and changes `IssueInvoice`; L6 asks whether the law
  requires it.
- **O7** QR codes for paying by bank app or Swish: wanted in v1, if the formats can be verified?
- **O8** "Drivs med NONOX" in the portal footer, or nothing?
- **O9** Inactive contacts: removed after 12 months without sign-in plus 30 days' notice, or
  another period?
- **O10** An English portal when a customer language field exists?
- **O11** Bulk invitation of every customer's invoice recipient from CRM in v1, or later?

## Addendum 2026-11-08: Lead decisions on the owner questions

Decided by Lead 2026-11-08 under the owner's mandate, open for the consultant's review. The
owner may change any of them.

- **O1 Name:** "Kundportal".
- **O2 Price:** included in every plan, following the owner's principle for ADR-070: open
  source, part of the hosted annual price, and switched on per company as a module.
- **O3 Passkeys:** not in the first version. Portal contacts use the e-mail code only.
- **O4 "Jag har betalat":** kept. It books nothing, and the invoice page says it is the
  customer's own note.
- **O5 Written-off invoices:** hidden until the owner decides.
- **O6 Issued PDF:** stored at issue. The portal and later copies show exactly the PDF that
  was sent. This also answers L6 in the safe direction. Earlier invoices keep today's
  behaviour and are labelled as re-rendered.
- **O7 QR codes:** a later step, not the first version.
- **O8 "Drivs med NONOX":** a small footer line, on by default, that the company can switch
  off.
- **O9 Inactivity:** accepted: removed after 12 months without login, with 30 days' notice.
- **O10 Language:** Swedish first. English follows through the normal translation files.
- **O11 Bulk invitation:** a later step, after the first version.

The first build covers the ADR's build steps 1–6. The legal questions L1–L12 stay open, and
the code keeps them as `TODO(legal-verify)`. The legitimate-interest assessment template
(L1) and the privacy information (L2) ship as drafts marked for the lawyer's review.

## Addendum 2026-11-08: signing moved to core (as built, build step 5a)

Built first, on Lead's request, so that the work-order module (ADR-074) can build on the same
signing domain.

- `app/Domain/Signing` holds the shared evidence: `Signable` (the interface any document
  implements: type, key, company, title, PDF, fingerprint, still open, acceptance text,
  required module, last valid day, notice destination, who may send, what happens on signing),
  `SignableTypes` (a registry: type → the signing request column that holds the key and how to
  find, and lock, the document), `SigningToken`, `QuoteFingerprint`, `SignatureImage`,
  `SigningStatus`, `SigningEventType`, `SigningUnavailable`, `SigningPdfs` (certificate and
  acceptance MAC), `SignedDocuments`, `SigningFollowUp` with `SigningHook`/`SigningHooks`.
- Actions `App\Actions\Signing\SendForSigning`, `SignAtLink`, `PruneSigningLinks`; models
  `App\Models\SigningRequest`, `SigningEvent` (tables keep their `crm_` names); the public page
  `/signera/{token}` (route names `signing.*`), the company's file route
  `/kunder/signeringar/{id}/{fil}` and the Livewire panel `signing.panel` are registered by
  `App\Providers\SigningServiceProvider`, not by CRM.
- The quote (`SalesDocument`) is registered by the core and needs no module: a quote is sent
  and signed whether or not CRM is on, and becomes an order as before. The agreement is
  registered by CRM and needs CRM (`signingModule()`), so its links close while CRM is off, as
  before. CRM adds its steps (win the chosen deal, a done note on the account, notices to the
  deal's and agreement's owners) through `CrmSigningHook`, only while CRM is on. Choosing a deal
  is refused while CRM is off.
- A later type (the work order) registers itself with `SignableTypes::GENERIC_COLUMN`: its key is
  kept in the new `signable_id` column, which the evidence trigger protects like the others.
- Unsigned links are pruned a year after they closed by the new `signing:prune` (every company,
  04:15), as well as by `crm:agreements`.
- Behaviour change, deliberate: ADR-046 closed every link while CRM was off. Quote links now stay
  usable; the CRM test that asserted the old rule now asserts the new one.

## Addendum 2026-11-08: as built (build steps 1–6)

Built on `feat/customer-portal` after the signing move above. Module `app/Modules/Portal`.

### What the guard and the session rely on (read in the installed Laravel 13.39 source)

- **Guard.** `config/auth.php` adds `guards.portal` (session driver, provider `portal_identities`,
  model `PortalIdentity`) without repeating `web`: `Illuminate\Foundation\Bootstrap\LoadConfiguration::mergeableOptions()`
  merges the `guards`, `providers` and `passwords` keys of the app's `auth.php` with the framework's
  defaults. Checked by running `config('auth.guards')`, which lists `web`, `portal` and `sanctum`.
  `SessionGuard` keeps its user under `login_{name}_{sha1(class)}`, so the two guards never share a
  login even in one store. `PortalIdentity` has no remember token (`getRememberTokenName()` is empty,
  so `SessionGuard::logout()` cycles nothing) and no password.
- **The default guard stays `web`.** The database session handler writes `user_id` from the default
  guard at save time (`DatabaseSessionHandler::userId()`), and `CurrentCompany` resolves from
  `Auth::user()`. The portal never calls `Auth::shouldUse('portal')`; it always names its guard.
  Portal sessions therefore never appear among a NONOX user's browser sessions.
- **Own cookie.** `StartSession::getSession()` takes the store from `SessionManager::driver()` and
  reads the id from the cookie named by the store's `getName()`; `addCookieToResponse()` writes that
  name with `session.path`. `SessionManager::buildSession()` names the store from `session.cookie`, and
  the database handler takes `session.lifetime` when built. The built driver is cached
  (`Manager::$drivers`), as are the container's `session.store`, the guards (`AuthManager::$guards`)
  and the redirector (`RoutingServiceProvider::registerRedirector()` injects the store). So
  `UsePortalSession`, a global middleware prepended ahead of the web group, sets `session.cookie`
  `nonox_kundportal`, `session.path` `/kundportal` and `session.lifetime` 30 for `/kundportal*`, and
  forgets the drivers, `session.store`, the guards and the redirector when it switches. A request
  starts fresh in production; the switch back matters only where one process serves both (tests).
  The XSRF cookie follows `session.path`, so the portal's has path `/kundportal`.
- **No Livewire in the portal.** Livewire posts to `/livewire/update`, which would carry the NONOX
  cookie, and the portal's CSP allows no inline script. The portal pages are controllers and Blade
  forms with `resources/js/portal.js` (copy buttons, one submit per form, confirmations).

### Design choices made while building

- **Portal identity and contacts**: identities are global (e-mail and name); a contact links an
  identity to one customer of one company. A person with several contacts chooses a portal at
  `/kundportal/valj`; the session keeps the chosen contact, and `ResolvePortal` sets `PortalContext`
  and `CurrentCompany` from it, never from the request. A contact of two customers in one company
  sees each only in its own portal. A disabled contact, an inactive or restricted customer, an
  archived company or the module off answers 404; the session ends when the person has no portal left.
- **Login codes**: `login_codes.audience` (WEB, PORTAL). `SendLoginCode` and `VerifyLoginCode` take
  the audience; the portal's caps and lock use their own keys. The sign-in form always dispatches
  `SendPortalLoginCode` (queued) and answers the same; only the job decides, and a code goes only to an
  address with an ACTIVE contact (or, for an invitation, to the invited address). The code mail
  carries a company's brand only when the person has a portal in that company.
- **Invitation**: a 256-bit token stored as SHA-256 (`SigningToken`), 14 days, once; the link page
  shows the address masked and sends a code there; only the code accepts. Inviting again revokes
  earlier links. An invited contact who never accepts is removed 14 days after the link ran out.
- **Private customers**: a customer with a personal number can only have its own address, as one
  login. Personal numbers are never shown on portal pages.
- **The stored issued PDF** (O6): `invoice_documents` with SHA-256, written once by the queued job
  `StoreIssuedInvoicePdf` after `IssueInvoice` commits, never updated (trigger). E-mail, reminders,
  download, API, Peppol, archive and portal all use `IssuedInvoicePdf::get()`. The test suite turns
  the storing off (`NONOX_STORE_ISSUED_INVOICE_PDFS=false` in `phpunit.xml`) so its thousands of
  issued invoices are not rendered twice; the tests of the feature turn it on.
- **Quote acceptance** goes through the core `App\Actions\Signing\SignInSession`, which records a
  `SigningRequest` with `channel` PORTAL and `portal_contact_id`: the exact PDF and its SHA-256, the
  fingerprint the page showed (must still match), typed name, confirmation text, time, IP and browser,
  a certificate that says it was accepted in the portal, and the same follow-up (`FollowUpSigning`:
  order, CRM hook, notices, copies). Open links for the quote are revoked first under the same row
  locks; a quote signed at a link cannot be accepted in the portal, and a link for a quote accepted in
  the portal answers 410. Declining keeps no IP address.
- **Questions**: threads and messages, attachments PDF/JPEG/PNG ≤ 10 MB, five per message, checked by
  content (finfo) and through the inbox's `AttachmentScreen`. A notice goes to users with
  `customer-portal:reply`; the reply mail has a link, never the text. A dispute flags the invoice
  (warning on the invoice's portal panel; reminders are not stopped, L8).
- **Events**: `customer_portal_events`, append-only (trigger allows only the contact id to be cleared
  when a contact is removed); IP and browser on SIGNED_IN only, recorded at the first entry into a
  portal in a session.
- **Retention**: `portal:prune` daily at 04:20, per company, touching only the portal's tables.
- **Gate until the lawyer has answered**: `PortalModule::canEnable()` refuses unless
  `NONOX_CUSTOMER_PORTAL=true` (build step 1: L1, L2, L4, L5 before it is on for any company). The
  roadmap item `kundportal` is "Pågår" and `Features` lists it as coming.
- **Not built in this step**: card or Swish payments, QR codes (O7), bulk invitation (O11), ordering
  (§11), passkeys for contacts (O3), an English portal (O10), a landing feature card and a comparison
  row (the module is not on for customers yet), and the read access log (ADR-068 is not built).

The `TODO(legal-verify)` items L1–L8 and L12 are in the code where they apply; L9–L11 concern later
steps. The drafts for the lawyer are in `docs/juridik/kundportal-utkast.md`.

## Addendum 2026-11-08: review fixes (cold review of 2772c563)

- **Merging needs `npm run build`.** `resources/js/portal.js` is a new Vite entry; without a build the
  portal pages fail on the missing manifest entry. The README's server section says so too.
- **P2-1 The stored PDF is the sent PDF.** `IssueInvoice` sets `invoices.stores_issued_pdf`; for such
  an invoice `IssuedInvoicePdf::get()` stores the PDF itself when the queued job has not run yet
  (under the unique key, in a savepoint), so an e-mail sent straight after issuing, a recurring
  invoice issued and sent in one go, and an API issue-then-send all send the stored bytes. The job
  only warms the store. Tested with `Queue::fake()` on `SendInvoice` and `RunRecurringInvoice`.
- **P2-2** The company opens a customer's files at `/kunder/portal/fragor/{trad}/bilagor/{bilaga}`
  (module on, `customer-portal:view`, company-scoped, download with nosniff), and its replies can
  carry files, screened like the customer's.
- **P2-3 [Lead]** "Får godkänna offerter" is chosen at invitation and can be changed on the contact
  row (`customer-portal:manage`, audited `customer_portal.contact_quote_right_changed`). The default
  is yes for a private customer and no for a contact at a legal person. Existing contacts keep their
  value (the module is still gated).
- **P2-4 [Lead]** `PortalModule::canEnable()` refuses when the default queue connection uses the sync
  driver, since the sign-in's queued code mail is what keeps the response from telling who has a
  portal. No `defer()` and no `dispatchAfterResponse()`. In the README's server section.
- **P3** The document's row is locked before its pending links in `SignInSession`; "Jag har betalat"
  notifies `customer-portal:reply` holders and is listed on Frågor från kunder; the reminders card
  warns about an open dispute for anyone with `invoices:view`; attachments are all checked before any
  is written, written inside the transaction and deleted if it fails; nested or failed uploads are
  refused with a message; downloads use `HeaderUtils::makeDisposition` with an ASCII fallback; the
  invoice page's "as issued" label checks the stored file's hash; a stored PDF can be deleted only
  with its company (trigger); removing one login or the company changing a contact's address sets
  `sessions_valid_from` (the person's other sessions end; the company's change moves the contact to
  the new address's identity); removing a login clears IP address and browser on that contact's
  sign-in events [Lead] (the events trigger allows only that); notices go to permission holders in
  one query (`PortalRecipients`); the privacy page and a branded entry answer 404 while the portal is
  closed. The CRM account lookup in a quote's acceptance text moved out of `SalesDocument` into
  `CrmSigningHook` (`ResolvesSigningParty`, asked through `SigningHooks::party()`).

## Addendum 2026-11-09: re-check of 0a3a2d0a

- **Changing a contact's address [Lead]** no longer moves the contact: the new address gets a new
  contact (same customer, status and quote right; an INVITED one is invited again at the new
  address), and the old contact is removed as "Ta bort min inloggning" removes it (IP address and
  browser cleared on its events, messages shown as "Borttagen kontakt"). Only the old contact's
  portal closes for the previous person; their identity's other portals and sessions are untouched.
  Tested: the successor's extract holds nothing of the predecessor's. The customer's threads stay the
  customer's and are seen by its contacts, as before.
- **Lock order**: `SignAtLink::sign()`/`decline()` now lock the document before the signing request,
  like `SignInSession` and `SendForSigning`, and all their transactions retry a deadlock
  (`DB::transaction($callback, 3)`).
- **The invoice page's label** uses `IssuedInvoicePdf::isStored()`: no rendering and no storing on a
  view; the stored file's hash is checked once and remembered (the row never changes).
