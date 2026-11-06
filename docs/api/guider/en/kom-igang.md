# Getting started

This guide takes you from nothing to a posted voucher through the API. You need a company in NONOX where you are owner or administrator, and a passkey on your account. Try it in a company of your own first; there is no separate sandbox yet.

## 1. Turn on NONOX API

The API is a module and is off by default. Open **Inställningar → Moduler** (Settings → Modules) and turn on **NONOX API**. While the module is off, every call for the company answers `404`, as if the API did not exist.

In the module's settings a company can also limit which apps may connect and which IP addresses may call the API.

## 2. Create an API key

For a script of your own, an API key is the simplest. Open **Anslutningar** (Connections) and create a key with:

- a name, for example "Test script";
- the scopes `vouchers:view`, `vouchers:create` and `vouchers:post`;
- an expiry date (required, at most a year ahead).

Confirm with your passkey. The key starts with `nnx_` and is shown **once**; copy it right away. Keep it in an environment variable so it never ends up in your code:

```bash
export NONOX_TOKEN="nnx_..."
```

If a program used by many companies is connecting, use OAuth instead: [Authentication (OAuth)](autentisering.md).

## 3. See which company the key belongs to

`GET /company` needs no particular scope and answers with the company and the key. It is a good first call to see that everything works.

<!-- nonox:samples:getCompany -->

## 4. Create a draft

A voucher starts as a draft. Amounts are whole öre: `125000` is SEK 1,250.00. The rows are checked as in the app (accounts that exist and are active, debit or credit on each row); the balance is checked when the draft is posted.

Every `POST` needs an `Idempotency-Key`, so a retry after a network error never creates two drafts. More in [Idempotency](idempotens.md).

<!-- nonox:samples:createVoucher -->

The answer is `201 Created` with the draft. Keep `data.id`.

## 5. Post the draft

Posting gives the voucher the next number in its series with no gaps, checks that it balances and that its date is in an open fiscal year outside locked periods, and writes the audit trail. It cannot be undone: a posted voucher can only be corrected with a correcting voucher.

<!-- nonox:samples:postVoucher -->

## 6. Read it back

List posted vouchers, or fetch only what changed since last time with `updated_since`. See [Pagination and sync](sidindelning.md).

<!-- nonox:samples:listVouchers -->

## When something goes wrong

Every error is `application/problem+json` with a stable `code` and a Swedish explanation in `detail`. Always branch on `code`. A key that lacks a scope gets `403 insufficient_scope` with `required_scopes`. Every code is listed in [Errors](fel.md).

## Next steps

- [Scopes](behorigheter.md): which scopes open which endpoints.
- [The API reference](https://nonox.app/utvecklare/referens): every endpoint with parameters, schemas and examples.
- [Rate limits](granser.md): how many calls per minute are allowed.
