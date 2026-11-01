# ADR-030: Accounting-firm branding for clients

Status: proposed, 2026-11-01

## Context

The owner (2026-11-01): "De som är redovisningsbyråer och har fler företag ska
kunna ställa in sin logga som i dag, men den ska visas på alla
självservicesidor som byggs, så att det blir redovisningsbyråns färger och
logga som deras kunder ser."

Every company already has its own brand under Inställningar → Varumärke
(`UpdateCompanyBranding`, `Brand`): a logo and two colours on its invoices,
quotes, orders and payslips, on Min sida and as the app's accent colour
(`Brand::interfacePalette`, readable on white at WCAG 2.2 AA). ADR-028 §10
derives "the firm" inside a client company from the Redovisningskonsult role
(`accountant`). There is no notion of a firm as a company of its own, and no
link between a client and its firm.

## Decisions

### 1. A firm is a company marked as one

`teams.is_accounting_firm` (default false), set by the **owner** of the
company on the Varumärke tab ("Vi är en redovisningsbyrå"). The firm's brand
is the firm company's ordinary brand (logo and colours under Varumärke); there
is no second set of colours.

`teams.firm_brand_for_clients` (default **true**): "Visa byråns varumärke för
våra kunder". Off, the firm's clients keep seeing their own brand while the
links stay.

A company that stops being a firm loses all its client links (each audited in
both companies). A firm cannot itself show another firm's brand.

### 2. A client links one firm; the client's owner chooses

`teams.accounting_firm_id` (FK to `teams`, `nullOnDelete`, check constraint
`<> id`), with `accounting_firm_linked_at` and `accounting_firm_linked_by`.

**Flow (chosen as the simplest secure one):** the client's **owner** picks
"Vår redovisningsbyrå" from a list of eligible firms. A firm is eligible for
a client when it is marked as a firm, is not archived, and **a person who has
the Redovisningskonsult role in the client also owns or belongs to the firm**
(`AccountingFirms::eligibleFor`). That shared consultant is what ties the two
together; a firm with no one in the client is never listed and a link to it is
refused with the same message as an unknown id (no existence leak).

Rejected: the consultant proposes and the client's owner confirms. It needs a
pending state, a notification and an expiry for the same result: the owner
still has to act, and the consultant is already the condition for the list.
It can be added later on top of the same column if firms ask for it.

**Removal:** the client's owner removes the link on the client's Varumärke
tab, the firm's owner removes a client on the firm's Varumärke tab ("Kunder
som har valt er"). A link is also removed automatically when no shared
consultant remains (a member is removed or leaves, or a role changes:
`ReconcileFirmLinks`, from `TeamMemberRemoved` and `UpdateTeamMemberRole`).

**Audit:** `company.accounting_firm_linked` / `company.accounting_firm_unlinked`
in the client, `company.firm_client_linked` / `company.firm_client_unlinked`
in the firm (with `by`: `client`, `firm`, `no_consultant` or
`firm_disabled`), and `company.accounting_firm_settings_changed` in the firm.

**No access by itself.** The link is branding only. The firm, its owner and
its staff get nothing in the client from it: access still comes only from a
membership in the client (AGENTS.md §1.3). The firm's owner sees the names of
the companies that chose the firm, and nothing else of them; a client sees
only its own firm's name.

Owner only, both sides (`ownsTeam`), not a permission string: the choice
decides what every member and employee of the company sees, like the
passkey requirement (ADR-029).

### 3. Who sees what (precedence)

On every **self-service and client-facing** surface, a client company whose
linked firm is still a firm, not archived and has "Visa byråns varumärke"
on, is shown in the **firm's** logo and colours to the client side. Otherwise
the company's own brand, exactly as before.

Client side = the owner and every member whose role is not
Redovisningskonsult, and the employees on Min sida. Members with the
Redovisningskonsult role are the firm (ADR-028 §10) and keep the company's
own colours there, so a consultant can tell clients apart while working in
them; in their own firm company and on Mina företag they see the firm's own
brand (or NONOX's) as before.

| Surface | Client side sees |
|---|---|
| App shell (sidebar, phone bar, accent colour) | Firm's logo in a white card "i samarbete med {Byrån}" above the company card, the firm's logo in the phone bar, the firm's colour as the app's accent |
| Min sida (`/anstalld`) | Firm's logo, "{Byrån} · för {Kunden}", the firm's colour |
| Task pages (`/uppgifter`) and notices (`/notiser`) | The app shell above (they use it) |
| Login code email | Firm block (logo inline, "{Byrån} · för {Kunden}") when the code goes to the invited address of a pending invitation the visitor followed, or to an existing user who is on the client side of their current company |
| Task digest email | Firm block when every task in it is in a company that shows the user the same firm |
| Invitation emails (company and Min sida) | Firm block, unless the invitation is for the Redovisningskonsult role |
| Absence decision email (to the employee) | Firm block |
| Login page reached from an invitation | Firm logo (short-lived signed address) and "{Byrån} · för {Kunden}", only from the signed invitation link the visitor followed, never from a typed address |

**Not branded with the firm, on purpose:** the documents the client company
issues — **invoices, quotes, orders, reminders, payslips**, their emails and
PDFs — always carry the **client's own** brand (`Brand::for`), because they
are the client's documents sent in the client's name. The absence-request
email to payroll staff and other staff-facing mails stay as they were.

### 4. Contrast

The firm's colours go through the same rules as the company's own: the app
palette from `Brand::palette` (shade 500 ≥ 3:1, 600 and 700 ≥ 4.5:1 against
white), and text drawn in the brand colour on white (Min sida, the email
block, the login page) uses `Brand::readable` / `Brand::accentText`, which
darkens the colour until it reaches 4.5:1. A pale or yellow firm colour can
therefore never make text unreadable.

### 5. No query per request

`FirmBranding` caches each firm's brand (name, colours, logo path, or "not a
firm showing its brand") per firm id for a day, `brand:firm:{id}`, read with
`Cache::many` and filled with one query for the misses. The client's own row
already holds `accounting_firm_id`, so a change of link needs no
invalidation; a change to the firm (name, colours, logo, firm flag, toggle,
archiving) forgets the firm's entry from `Company::saved`. The viewer's role
comes from the memberships already loaded with the user's companies, so the
app shell costs no extra query when cached.

The logo of the firm is served through the client
(`/foretag/{client}/byrans-logotyp`, members of the client only, and
`/varumarke/{client}/byrans-logotyp` with a 30-minute signature for the login
page). It is never addressed by the firm's id.

## Consequences

- `teams` gets five columns; forward migration only.
- The firm's name is visible to the members and employees of the client
  (that is the point), and the clients' names to the firm's owner and in the
  firm's audit log.
- Self-hosters without firms see no change.

## Open questions

- Should a firm be able to set a separate "client-facing" logo (for example a
  white logo for dark backgrounds) instead of its invoice logo?
- Should the client's own documents be able to show "Bokföring: {Byrån}" in
  the footer? Not done: documents stay the client's alone.
- Should the consultant-proposes flow be added for firms that onboard many
  clients at once?
