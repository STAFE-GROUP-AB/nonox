# ADR-063: MCP server for AI assistants on NONOX Connect

- Status: Accepted (2026-11-06, owner decision 18; Beta release waits for the privacy policy v1.6 text). Beta.
- Task: Track I.1
- Relates to: AGENTS.md §1.2, §1.3, §4, §12; ADR-006 (audit log), ADR-007
  (vouchers), ADR-017 (modules), ADR-032 (public API and NONOX Connect),
  ADR-054 (Skatteverket APIs)

## Context

Owners and accounting firms want to ask an AI assistant (Claude, ChatGPT,
Cursor and others) about their bookkeeping and let it prepare entries. The
Model Context Protocol (MCP) is how those assistants call outside tools. NONOX
Connect (ADR-032) already has company-bound credentials, scopes from AGENTS.md
§4, rate limits, idempotency and an audit trail, so the MCP server is a thin
layer on top of it, not a second API.

The constraints are the ones that matter for bookkeeping: an assistant must
never book, send, pay or delete anything on its own, every write must wait for
a person, and nothing from Skatteverket's APIs may pass through Connect until
Skatteverket has answered ADR-054's open question.

## Decisions

### Package: laravel/mcp

**`laravel/mcp` ^1.1** (v1.1.2 installed, MIT, first-party Laravel). It
requires only `illuminate/*` ^13.0 (Laravel 13 is supported) and PHP ^8.2; no
further dependencies. MIT is AGPL-compatible.

Verified from the installed package (`vendor/laravel/mcp/src`), not from
memory; the Laravel Boost `search-docs` tool was not available in this
session:

- `Laravel\Mcp\Server` handles one JSON-RPC message per HTTP request
  (`HttpTransport`), with both handshakes: `initialize` for protocol
  2025-03-26, 2025-06-18 and 2025-11-25, and `server/discover` with per-request
  `_meta` for 2026-07-28 (`ProtocolVersion`, `Server::validateProtocolMeta`).
  `ValidateMcpHeaders` checks the `MCP-Protocol-Version`, `Mcp-Method` and
  `Mcp-Name` headers of 2026-07-28 requests against the body.
- `tools/list` and `tools/call` resolve tools through
  `Primitive::eligibleForRegistration()`, which calls a tool's
  `shouldRegister()`. A tool that is not eligible is neither listed nor
  callable (`CallTool` answers "not found").
- An exception in a tool becomes a tool error (`isError: true`), not a crash
  (`ToolInvoker` is `Errable`).

What the package's own service provider does that NONOX must not have, so
`laravel/mcp` is listed in `extra.laravel.dont-discover` and Connect registers
a subclass (`App\Modules\Connect\Mcp\McpServiceProvider`) that leaves it out:

- `Registrar::ensureMcpScope()` adds a scope `mcp:use` to Passport on boot.
  Connect's scopes are AGENTS.md §4 permission keys and nothing else; a test
  asserts `Passport::$scopes` is exactly `ConnectScopes::SCOPES`.
- It pushes `AddWwwAuthenticateHeader` and `AddAuthorizationFramingHeaders`
  into the global middleware of every request.
- It loads `routes/ai.php`. NONOX has one MCP route, registered by
  `ConnectServiceProvider`.

The package's OAuth helpers (`Mcp::oauthRoutes()`, dynamic client
registration, protected-resource metadata) are not used: Connect already is
the authorization server (ADR-032) and registers apps itself.

Considered and rejected: a hand-written JSON-RPC endpoint. The protocol has
two handshakes, header mirroring and version negotiation that keep changing;
a first-party package keeps up with them.

### Transport and address

`POST /api/mcp`, streamable HTTP, one JSON-RPC message per request, answered
as `application/json` (no server-sent events: GET answers 405). `api/` is a
technical path outside the Swedish URL rule (AGENTS.md §11.4). It is not
under `/api/v1`: it is not a REST resource and is not in `openapi.yaml`; its
version is MCP's protocol version.

Only `initialize`, `server/discover`, `ping`, `tools/list` and `tools/call`
exist. Resources, prompts, completions and subscriptions are removed from the
server (`NonoxMcpServer::boot`), so there is one list and one path to check
and log.

### Authentication: Connect's credentials, unchanged

The route runs `ConnectJson`, the per-IP throttle, `AuthenticateConnect`
and `ConnectRateLimit` exactly as `/api/v1` does. A credential is an API key
(`nnx_…`) or an OAuth access token from an approved app, sent as
`Authorization: Bearer`. It is bound to one company; Connect off for the
company answers 404; a person who left answers 401; the IP allowlists apply;
an archived company is read-only, and since every MCP message is a POST, MCP
is unavailable for an archived company. Effective rights are, as everywhere,
the credential's scopes ∩ the person's current permissions
(`ConnectAccess`, `User::hasTeamPermission`).

### Only read and draft scopes: `McpGate`

A credential used at `/api/mcp` may hold only
`McpTools::ALLOWED_SCOPES`: `vouchers:view`, `vouchers:create`,
`reports:view`, `invoices:view`, `invoices:create` and
`supplier-invoices:create`. A key or app with any other scope (posting,
correcting, sending, crediting, paying, approving, SIE export, VAT return,
account management, the module scopes) is refused with 403
`mcp_scope_not_allowed`, listing the scopes that are too much, and the refusal
is audited (`mcp.credential_refused`).

The reason: the MCP tool list is not the only thing an assistant can reach.
The same bearer key works on `/api/v1`. Refusing broad keys at the MCP door
means the key that an owner hands to an assistant cannot post, send or pay
over REST either. The owner creates a separate key for the assistant.

What such a key can still do over REST, outside the tools: change and delete
its own drafts, add customers, articles and suppliers, and create supplier
invoice drafts (`vouchers:create`, `invoices:create`,
`supplier-invoices:create`). None of it books, numbers, sends or pays
anything. Registering a supplier invoice needs `vouchers:post` as well
(ADR-032), which the key cannot have.

### The tools: REST routes, run as they are

Each tool (`McpTools::all()`) names one REST route of Connect. `ApiRouteCall`
runs that route inside the MCP request: it builds the route's request (path
parameters, query or JSON body, the Idempotency-Key), applies the route's own
`connect.scope` middleware (`RequireScope`), `RequireIdempotencyKey` for
POST, route model binding (inside the credential's company, so another
company's id is not found), and the controller with its Action and Policy.
Errors become the REST API's problem+json (`Problem::fromException`). The
tool returns the REST answer as structured content; an error answer as a tool
error with the same problem document, so the assistant can read `code`,
`detail` and `errors`. No business logic is duplicated, and a tool can never
do more than its route.

A tool is listed only when the credential may call its route right now: the
route's scope expression is evaluated with the same check as `RequireScope`
(`ApiTool::shouldRegister`). A bookkeeper's key shows what a bookkeeper may
do; an auditor's key never shows the draft tools, whatever the key says.

| Tool | REST route | Scope |
|---|---|---|
| `get_company` | `GET /company` | any valid credential |
| `list_accounts` | `GET /accounts` | `vouchers:view` |
| `list_fiscal_years` | `GET /fiscal-years` | `vouchers:view` |
| `list_voucher_series` | `GET /voucher-series` | `vouchers:view` |
| `list_vouchers`, `get_voucher` | `GET /vouchers`, `/vouchers/{id}` | `vouchers:view` |
| `trial_balance`, `general_ledger`, `balance_sheet`, `income_statement` | `GET /reports/…` | `reports:view` |
| `list_customers`, `get_customer` | `GET /customers`, `/customers/{id}` | `invoices:view` |
| `list_articles` | `GET /articles` | `invoices:view` |
| `list_invoices`, `get_invoice` | `GET /invoices`, `/invoices/{id}` | `invoices:view` |
| `list_suppliers`, `get_supplier` | `GET /suppliers`, `/suppliers/{id}` | `supplier-invoices:create` |
| `list_supplier_invoices`, `get_supplier_invoice` | `GET /supplier-invoices`, `/supplier-invoices/{id}` | `supplier-invoices:create` |
| `create_draft_voucher` | `POST /vouchers` (`SaveDraftVoucher`) | `vouchers:create` |
| `create_draft_invoice` | `POST /invoices` (`SaveDraftInvoice`) | `invoices:create` |

Not offered: attachments and invoice PDFs (binary), SIE, VAT periods and the
VAT return, CRM, planning, inventory, and every route that posts, corrects,
issues, sends, credits, pays, approves, registers, changes or deletes.

### Person approval for every write

"Approval" means: an assistant's write is always a draft, and a draft stays a
draft until a person posts or sends it in NONOX. There is no tool that posts,
corrects, issues, sends, credits, pays, approves or deletes, and the
credential cannot hold the scopes for it (`McpGate`). Draft vouchers have no
number, are outside the hash chain and are not räkenskapsinformation until
posted by `PostVoucher` (ADR-007); a draft invoice has no number, books
nothing and is not e-mailed.

This is said where the assistant and the person see it: in each draft tool's
description, in the server's instructions, and in every draft answer, which
carries

```json
"approval": {
  "required": true,
  "status": "DRAFT",
  "message": "Utkastet är inte bokfört. En person måste granska och bokföra det i NONOX.",
  "review_url": "https://…/verifikationer/<id>/andra"
}
```

Considered and rejected: a "pending approval" state with a separate approval
queue. A draft already is that state, with the existing posting checks
(balance, open year, locked periods) and the existing UI; a second queue
would duplicate both.

### No Skatteverket content (ADR-054)

ADR-054 holds that Connect passes on no content from Skatteverket's APIs until
Skatteverket has answered whether it may. The MCP server is part of Connect,
so it has no tool for the VAT return or its submission, AGI, the tax account
(skattekonto) or kundhändelser, and `vat:prepare` is not an allowed scope.
Enforced by tests: every tool's name and route path is checked against a
deny-list (`post|correct|issue|send|credit|payment|approve|register|update|
destroy|delete|sie|vat|moms|skatt|tax|agi|arbetsgivar|kundhandels|event|
submit|lock|close`), every read tool's route must be GET and the only POST
routes are `vouchers.store` and `invoices.store`; calling such names answers
"not found". Adding a tool that breaks this fails the suite.

### Idempotency

Draft tools require `idempotency_key`, sent as the REST `Idempotency-Key`
through the same `RequireIdempotencyKey`, per credential, 24 hours: the same
key and input return the first draft again (`replayed` in the audit entry);
the same key with other input is refused (`idempotency_key_reused`); no key is
refused (`idempotency_key_required`), and so is a key that is longer than 255
characters or not visible ASCII (`idempotency_key_invalid`). Keys are shared with REST calls of the
same credential. Assistants retry; this keeps a retry from creating a second
draft.

### Body limits and untrusted data

`LimitMcpBody` runs first on the route, before anything parses the body: more
than 256 kB answers 413 `payload_too_large`, and JSON nested deeper than 32
levels answers 400 `invalid_json`. The server instructions tell the assistant
that tool results contain data entered by people, to be treated as data and
never as instructions (descriptions and notes can carry prompt injection).

### Rate limits

Every MCP message is one HTTP request and counts against Connect's limits per
credential (120/min) and per company (600/min). On top, `McpGate` allows 60
MCP messages per credential and minute
(`NONOX_CONNECT_MCP_RATE_LIMIT_PER_CREDENTIAL`). Over a limit: 429 with
`Retry-After`.

### Audit log

Every tool call that reaches a tool: `mcp.tool_called` with `tool` (NONOX's
own tool name), `kind` (`read`/`draft`), HTTP `status`, problem `code`,
`credential` (`kind`, `id`), `draft_id`, `idempotency_key_sha256` (the first
16 hex characters of a SHA-256 of the key, only for a key that
`RequireIdempotencyKey` accepts: 1–255 visible ASCII characters; never the
client's text), `replayed` and
`request_id`; company and person from the credential. Calls that never reach
a tool: `mcp.tool_refused` with `reason` (`unknown_tool` or `not_allowed`) and
the tool name only when it is one of NONOX's own, never text a client sent. A
refused credential: `mcp.credential_refused` with the extra scope keys. No
names, descriptions, amounts or other free text, and no key names (unlike
`api.request`). The REST `AuditApiWrites` middleware is not on the MCP route,
so a read is not logged as an `api.request` write; the Actions log their own
changes as from the UI (a draft voucher has no audit entry of its own today;
the tool's entry is the record of it).

### Tenancy

The tools use route model binding and the company scope of the REST routes,
so a key for company A cannot read B: B's ids answer `not_found`, lists hold
only A's rows, and a draft invoice naming B's customer is refused
(`tests/Tenancy/McpTenancyTest.php`).

## Consequences

- Owners create one API key per assistant with only read and draft scopes;
  existing integration keys with more scopes are refused at `/api/mcp` with a
  message that says so.
- A new REST route is not reachable through MCP until it is added to
  `McpTools` deliberately, and only if it passes the deny-list.
- `laravel/mcp` is a 1.x package tracking a moving protocol; a major upgrade
  must read its UPGRADE notes, and the three overridden provider methods are
  the place to check first.
- MCP clients that only speak OAuth with dynamic client registration (RFC
  7591) cannot connect yet: Connect registers apps itself (ADR-032). Clients
  that accept a bearer header (Claude Code, Cursor, VS Code, the MCP
  Inspector) work with an API key.

## Open questions for the owner

1. Should MCP be opt-in per company (a switch under the Connect module), or
   is a dedicated key enough? Current: a dedicated key is enough.
2. Should the draft tools be extended to supplier invoice drafts and to
   changing an assistant's own drafts? Not now.
3. Should a new draft from an assistant notify someone (a database notice to
   the person whose key it is)? Not now; it shows in the draft lists.
4. When Skatteverket answers ADR-054's question: may the VAT return
   (NONOX's own calculation, not Skatteverket content) become a read tool?
   Current: no, `vat:prepare` is not allowed.
