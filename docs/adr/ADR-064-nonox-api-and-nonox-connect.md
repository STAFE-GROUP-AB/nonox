# ADR-064: NONOX API and NONOX Connect

- Status: Accepted (2026-11-06, owner decision 8)
- Relates to: ADR-032 (public API and NONOX Connect), ADR-050 (Peppol), ADR-034 (open banking), ADR-054 (Skatteverket APIs), ADR-063 (MCP server), ADR-017 (modules)

## Context

ADR-032 named the public API module "NONOX Connect". Three kinds of connections
now cost money at the provider, per message or per account: Peppol through
Qvalia, Skatteverket's APIs, and open banking through Enable Banking. The
promise "alla moduler ingår" must stay true, so these must not be hidden inside
the module price, and the name "Connect" is better spent on them than on the
free developer API.

## Decisions

### 1. The module is renamed NONOX API (user-facing only)

The module that holds API keys, OAuth apps, webhooks, SFTP for SIE files and the
MCP server is called **NONOX API** in every user-facing text: views, Livewire
messages, `lang/en.json`, the manual, the landing page, the README, the
developer portal, the OpenAPI description and the guides.

Unchanged on purpose: the module key `connect`, the namespace
`App\Modules\Connect`, route names, config keys (`services.connect`,
`NONOX_CONNECT_*`), table names and the CLI command `connect:app`. Renaming
them would need migrations and a coordinated change of every issued
credential's configuration and gives users nothing. Historical changelog
entries and older ADRs keep the old name; ADR-032 and ADR-063 read "NONOX
Connect" as the module now called NONOX API.

### 2. NONOX Connect becomes a separate add-on

NONOX Connect groups connections that the provider bills for:

| Connection | Provider | Today |
|---|---|---|
| Peppol send and receive | Qvalia | ADR-050 |
| Skatteverket APIs | Skatteverket | ADR-054 |
| Open banking | Enable Banking | ADR-034 |
| Later connections | to be decided | |

It is **not** a module. "Alla moduler ingår" stays true for modules; NONOX
Connect is a clearly separate add-on that the owner turns on per company.

### 3. Price: the provider's cost plus 10 %

- The price is the provider's price to us plus a 10 % markup.
- It is shown openly with the provider price and the markup as two separate
  lines, per connection.
- No amounts are fixed in this ADR. Until each provider's list price is
  verified, texts say "enligt leverantörens prislista".
- A connection is never billed before the owner has turned it on and seen the
  price.

### 4. Announcement

The change is announced with terms v1.6: 60 days' notice, effective
**2027-01-11**. It is described on the landing page as coming with 1.0.
Existing module prices are not changed.

## Open (TODO(legal-verify))

- Qvalia's price model and list price for Peppol (per message, per month?).
- Enable Banking's price per connected account or per call.
- Skatteverket: whether its APIs are billed at all (ADR-054 open question).
- Wording in the terms v1.6 and the privacy policy for the add-on (owned by the legal texts).
- Billing mechanics: how provider invoices are passed on, VAT treatment of the pass-through and the markup.
- Whether a monthly cap is wanted (alternative from decision 8, not chosen).

## Consequences

- Users see "NONOX API" in the module list; developers who bookmarked docs are unaffected (routes unchanged).
- The word "Connect" in code (`connect`, `ConnectModule`) now means NONOX API; a comment in `ConnectModule` and this ADR say so.
- Building the add-on itself (switch, price display, billing) is a later task.
