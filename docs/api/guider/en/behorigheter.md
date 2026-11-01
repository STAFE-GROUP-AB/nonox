# Scopes

Every app and API key has a list of **scopes**. They are the same permissions NONOX's roles use, so a bookkeeper and an app with `vouchers:post` post in the same way, with the same checks.

## Every scope

The table is generated from the OpenAPI file. The endpoints in the right-hand column need the scope.

<!-- nonox:scopes -->

`GET /company` needs no particular scope: every valid key or token may see which company it belongs to.

## What a call may actually do

A call may do what **both** the key's or app's scopes **and** the person behind it allow, at that moment:

| The person's role today | The key's scopes | May the key post? |
|---|---|---|
| Bookkeeper | `vouchers:view`, `vouchers:post` | Yes |
| Approver (may not post) | `vouchers:view`, `vouchers:post` | No, `403 insufficient_scope` |
| Bookkeeper | `vouchers:view` | No, `403 insufficient_scope` |
| Has left the company | `vouchers:post` | No, `401` |

The intersection is checked on **every** call, in every check in the system, not only for the scope the route names. A changed role therefore counts from the next call, without the app doing anything.

## When a scope is missing

```json
{
  "type": "urn:nonox:problem:insufficient_scope",
  "title": "Forbidden",
  "status": 403,
  "code": "insufficient_scope",
  "detail": "Nyckeln eller appen saknar behörigheten vouchers:post, eller så har personen som gav den inte längre den behörigheten i företaget.",
  "instance": "urn:nonox:request:7f3c2a9e-4b1d-4c8e-9a51-2d6f0e8b3c17",
  "required_scopes": ["vouchers:post"]
}
```

`required_scopes` says what is needed. For an OAuth app it means the person has to approve the app again with more scopes; for an API key, that a new key has to be created.

## Ask for as little as possible

Ask only for the scopes the app actually uses. The person sees the list in Swedish when approving, and a short list is easier to say yes to. `vouchers:post` cannot be undone in the books (only corrected), so ask for it only when the app really posts.

## Things the API never does

- Return personal identity numbers. A customer has `has_personal_number`; the number itself can be written but never read.
- Change or delete a posted voucher. It can only be corrected.
- Give access to payroll, payslips or the staff ledger. There are no scopes for them.
