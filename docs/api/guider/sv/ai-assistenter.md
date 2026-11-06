# AI-assistenter (MCP)

NONOX har en MCP-server, så att en AI-assistent (till exempel Claude, ChatGPT, Cursor eller VS Code) kan läsa bokföringen och förbereda verifikationer och fakturor. MCP, Model Context Protocol, är det sätt som assistenterna anropar andra program på. Servern ligger ovanpå NONOX API och använder samma nycklar, samma behörigheter och samma gränser som API:t. Den är i **beta**.

> [!IMPORTANT]
> En AI-assistent kan bara **läsa** och **skapa utkast**. Den kan inte bokföra, rätta, skicka, kreditera, betala, attestera, ändra eller ta bort något. Varje utkast väntar i NONOX tills en person har granskat det och bokfört eller skickat det.

## Adress

```
POST https://nonox.app/api/mcp
```

Strömmande HTTP enligt MCP, ett JSON-RPC-meddelande per anrop, svar som JSON. Protokollversionerna 2025-03-26, 2025-06-18, 2025-11-25 (med `initialize`) och 2026-07-28 stöds. Kör du NONOX själv är adressen `https://<din server>/api/mcp`.

## En egen nyckel för assistenten

Skapa en [API-nyckel](api-nycklar.md) som bara assistenten använder. Den får bara ha de här behörigheterna, och bara de du behöver:

| Behörighet | Ger assistenten |
|---|---|
| `vouchers:view` | Kontoplan, räkenskapsår, verifikationsserier och verifikationer. |
| `vouchers:create` | Skapa utkast till verifikationer. |
| `reports:view` | Saldobalans, huvudbok, balansrapport och resultatrapport. |
| `invoices:view` | Kunder, artiklar och kundfakturor. |
| `invoices:create` | Skapa utkast till kundfakturor. |
| `supplier-invoices:create` | Läsa leverantörer och leverantörsfakturor. |

En nyckel eller app som har någon **annan** behörighet, till exempel `vouchers:post`, `invoices:send`, `payments:create`, `export:sie` eller `vat:prepare`, nekas med `403 mcp_scope_not_allowed`. Svaret räknar upp vilka behörigheter som är för mycket (`scopes_not_allowed`). Så kan nyckeln som assistenten har inte bokföra, skicka eller betala genom REST-API:t heller.

Det gäller också allt annat i [Behörigheter](behorigheter.md): nyckeln gäller ett företag, och den får aldrig göra mer än personen som skapade den får göra i företaget just nu. En OAuth-token från en godkänd app fungerar på samma sätt.

## Ansluta en klient

Skicka nyckeln som `Authorization: Bearer`. I Claude Code:

```bash
claude mcp add --transport http nonox https://nonox.app/api/mcp \
  --header "Authorization: Bearer $NONOX_TOKEN"
```

I en klient som läser en JSON-fil (till exempel Cursor eller VS Code):

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

Prova med curl:

```bash
curl https://nonox.app/api/mcp \
  -H "Authorization: Bearer $NONOX_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

Klienter som bara kan logga in med OAuth och dynamisk registrering av appar (RFC 7591) kan inte ansluta än; NONOX registrerar appar själv (se [Autentisering](autentisering.md)).

## Verktygen

Varje verktyg kör ett anrop i REST-API:t precis som det är, med dess behörighet, regler och svar. Ett läsverktyg ger därför samma data som anropet i [API-referensen](/utvecklare/referens). `tools/list` visar bara de verktyg som nyckeln får använda just nu.

| Verktyg | Gör | REST-anrop |
|---|---|---|
| `get_company` | Företaget och nyckelns behörigheter. | `GET /company` |
| `list_accounts` | Kontoplanen. | `GET /accounts` |
| `list_fiscal_years` | Räkenskapsåren. | `GET /fiscal-years` |
| `list_voucher_series` | Verifikationsserierna. | `GET /voucher-series` |
| `list_vouchers`, `get_voucher` | Verifikationer med rader. | `GET /vouchers`, `GET /vouchers/{id}` |
| `trial_balance`, `general_ledger`, `balance_sheet`, `income_statement` | Rapporterna. | `GET /reports/…` |
| `list_customers`, `get_customer`, `list_articles` | Kunder och artiklar. | `GET /customers`, `GET /articles` |
| `list_invoices`, `get_invoice` | Kundfakturor. | `GET /invoices` |
| `list_suppliers`, `get_supplier`, `list_supplier_invoices`, `get_supplier_invoice` | Leverantörer och leverantörsfakturor. | `GET /suppliers`, `GET /supplier-invoices` |
| `create_draft_voucher` | Ett utkast till verifikation. | `POST /vouchers` |
| `create_draft_invoice` | Ett utkast till kundfaktura. | `POST /invoices` |

Belopp är heltal i öre, datum skrivs `ÅÅÅÅ-MM-DD`, och listor har markör (`cursor`) som i [Sidindelning och synk](sidindelning.md).

Här finns inget som bokför, rättar, skickar, krediterar, betalar, attesterar, ändrar eller tar bort, ingen SIE-fil och ingenting om moms till Skatteverket, arbetsgivardeklarationer, skattekontot eller meddelanden från Skatteverket. Ett anrop till ett verktyg som inte finns, eller som nyckeln inte får använda, svarar "not found".

## Utkast väntar på en person

Ett utkastverktyg tar `idempotency_key`, som skickas som `Idempotency-Key` i REST-API:t ([Idempotens](idempotens.md)): samma nyckel och samma innehåll ger det första utkastet igen, inte ett till. Svaret innehåller utkastet och:

```json
"approval": {
  "required": true,
  "status": "DRAFT",
  "message": "Utkastet är inte bokfört. En person måste granska och bokföra det i NONOX.",
  "review_url": "https://nonox.app/verifikationer/<id>/andra"
}
```

Ett utkast till verifikation har inget nummer och är inte bokfört; det kan sparas obalanserat men inte bokföras förrän det balanserar. Ett utkast till faktura har inget nummer, bokförs inte och mejlas inte.

## Fel

Ett fel i ett verktyg kommer som ett verktygsfel (`isError: true`) med samma problem+json som REST-API:t ger, så att assistenten kan läsa `code`, `detail` och `errors` och rätta sig. Fel som gäller själva anslutningen kommer som HTTP: `401 unauthenticated`, `403 mcp_scope_not_allowed`, `404` när NONOX API är avstängt för företaget, `429 rate_limited`. Se [Fel](fel.md).

## Gränser

Varje MCP-meddelande räknas mot API:ts gränser per nyckel och per företag ([Gränser för anrop](granser.md)), och dessutom högst 60 MCP-meddelanden per nyckel och minut. Ett meddelande får vara högst 256 kB (annars `413 payload_too_large`) och JSON högst 32 nivåer djup (annars `400 invalid_json`). Ett arkiverat företag kan inte användas genom MCP.

## Behandlingshistorik

Varje verktygsanrop skrivs i företagets behandlingshistorik: vilket verktyg, nyckelns id, status, felkod och id för ett skapat utkast. Aldrig texter, namn eller belopp.
