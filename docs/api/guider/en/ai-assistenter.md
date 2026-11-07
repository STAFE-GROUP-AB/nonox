# AI assistants (MCP)

> [!IMPORTANT]
> **The MCP server is not available yet.** The address below answers 404 until the privacy policy has been updated to name AI providers as recipients. This page describes how it will work.

NONOX will have an MCP server, so an AI assistant (for example Claude, ChatGPT, Cursor or VS Code) can read the bookkeeping and prepare vouchers and invoices. MCP, the Model Context Protocol, is how assistants call other programs. The server sits on top of NONOX API and uses the same keys, the same scopes and the same limits as the API. It is in **beta**.

> [!IMPORTANT]
> An AI assistant can only **read** and **create drafts**. It cannot post, correct, send, credit, pay, approve, change or delete anything. Every draft waits in NONOX until a person has reviewed it and posted or sent it.

## Address

```
POST https://nonox.app/api/mcp
```

MCP's streamable HTTP, one JSON-RPC message per request, answered as JSON. Protocol versions 2025-03-26, 2025-06-18, 2025-11-25 (with `initialize`) and 2026-07-28 are supported. On a NONOX you run yourself the address is `https://<your server>/api/mcp`.

## A key of its own for the assistant

Create an [API key](api-nycklar.md) that only the assistant uses. It may have only these scopes, and only those you need:

| Scope | Gives the assistant |
|---|---|
| `vouchers:view` | Chart of accounts, fiscal years, voucher series and vouchers. |
| `vouchers:create` | Creating draft vouchers. |
| `reports:view` | Trial balance, general ledger, balance sheet and income statement. |
| `invoices:view` | Customers, articles and customer invoices. |
| `invoices:create` | Creating draft customer invoices. |
| `supplier-invoices:create` | Reading suppliers and supplier invoices. |

A key or app with any **other** scope, for example `vouchers:post`, `invoices:send`, `payments:create`, `export:sie` or `vat:prepare`, is refused with `403 mcp_scope_not_allowed`. The answer lists the scopes that are too much (`scopes_not_allowed`). That way the key the assistant holds cannot post, send or pay through the REST API either.

Everything else in [Scopes](behorigheter.md) applies too: the key belongs to one company, and it never does more than the person who created it may do in the company right now. An OAuth token from an approved app works the same way.

## Connecting a client

Send the key as `Authorization: Bearer`. In Claude Code:

```bash
claude mcp add --transport http nonox https://nonox.app/api/mcp \
  --header "Authorization: Bearer $NONOX_TOKEN"
```

In a client that reads a JSON file (for example Cursor or VS Code):

```json
{
  "mcpServers": {
    "nonox": {
      "url": "https://nonox.app/api/mcp",
      "headers": { "Authorization": "Bearer nnx_..." }
    }
  }
}
```

Try it with curl:

```bash
curl https://nonox.app/api/mcp \
  -H "Authorization: Bearer $NONOX_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

Clients that can only sign in with OAuth and dynamic client registration (RFC 7591) cannot connect yet; NONOX registers apps itself (see [Authentication](autentisering.md)).

## The tools

Each tool runs one REST API call exactly as it is, with its scope, rules and answer. A read tool therefore returns the same data as the call in the [API reference](/utvecklare/referens). `tools/list` shows only the tools the key may use right now.

| Tool | Does | REST call |
|---|---|---|
| `get_company` | The company and the key's scopes. | `GET /company` |
| `list_accounts` | The chart of accounts. | `GET /accounts` |
| `list_fiscal_years` | The fiscal years. | `GET /fiscal-years` |
| `list_voucher_series` | The voucher series. | `GET /voucher-series` |
| `list_vouchers`, `get_voucher` | Vouchers with rows. | `GET /vouchers`, `GET /vouchers/{id}` |
| `trial_balance`, `general_ledger`, `balance_sheet`, `income_statement` | The reports. | `GET /reports/…` |
| `list_customers`, `get_customer`, `list_articles` | Customers and articles. | `GET /customers`, `GET /articles` |
| `list_invoices`, `get_invoice` | Customer invoices. | `GET /invoices` |
| `list_suppliers`, `get_supplier`, `list_supplier_invoices`, `get_supplier_invoice` | Suppliers and supplier invoices. | `GET /suppliers`, `GET /supplier-invoices` |
| `create_draft_voucher` | A draft voucher. | `POST /vouchers` |
| `create_draft_invoice` | A draft customer invoice. | `POST /invoices` |

Amounts are integer öre, dates are `YYYY-MM-DD`, and lists use a cursor as in [Pagination and sync](sidindelning.md).

There is nothing that posts, corrects, sends, credits, pays, approves, changes or deletes, no SIE file, and nothing about VAT returns to Skatteverket, employer declarations, the tax account or messages from Skatteverket. A call to a tool that does not exist, or that the key may not use, answers "not found".

## Drafts wait for a person

A draft tool takes `idempotency_key`, sent as the REST API's `Idempotency-Key` ([Idempotency](idempotens.md)): the same key and input return the first draft again, not another one. The answer holds the draft and:

```json
"approval": {
  "required": true,
  "status": "DRAFT",
  "message": "Utkastet är inte bokfört. En person måste granska och bokföra det i NONOX.",
  "review_url": "https://nonox.app/verifikationer/<id>/andra"
}
```

A draft voucher has no number and is not posted; it may be saved unbalanced but cannot be posted until it balances. A draft invoice has no number, books nothing and is not e-mailed.

## Errors

An error in a tool comes back as a tool error (`isError: true`) with the same problem+json as the REST API, so the assistant can read `code`, `detail` and `errors` and correct itself. Errors about the connection itself come as HTTP: `401 unauthenticated`, `403 mcp_scope_not_allowed`, `404` while NONOX API is off for the company, `429 rate_limited`. See [Errors](fel.md).

## Limits

Every MCP message counts against the API's limits per key and per company ([Rate limits](granser.md)), and on top at most 60 MCP messages per key and minute. A message may be at most 256 kB (otherwise `413 payload_too_large`) and its JSON at most 32 levels deep (otherwise `400 invalid_json`). An archived company cannot be used through MCP.

## Audit trail

Every tool call is written to the company's audit trail: which tool, the key's id, the status, the error code and the id of a created draft. Never texts, names or amounts.
