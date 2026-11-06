# API keys

An API key is for a company's **own** scripts and integrations: an export job, a link from a web shop you run yourselves, a spreadsheet that fetches balances. If a program used by many companies is connecting, use [OAuth](autentisering.md) instead, so each company approves it itself.

## Create a key

An owner or administrator creates the key under **Anslutningar** (Connections) in NONOX and chooses:

| | |
|---|---|
| Name | So you can see what the key is for, for example "Export to BI". |
| Scopes | Only those needed. You can only grant scopes you hold yourself. See [Scopes](behorigheter.md). |
| Expiry date | Required. At most a year ahead on nonox.app (configurable when self-hosting). |
| IP addresses | Optional. Addresses or networks (CIDR, for example `203.0.113.0/24`) the key may be used from. |

Creating a key needs a fresh confirmation with a **passkey**. The key looks like this and is shown **once**:

```
nnx_8Kq2vT0bX7mYc4LhR1sWd9FzJ3nPa6Ue5GtV2kQoMwB8yHrC
```

NONOX keeps only a SHA-256 fingerprint of the key, so it cannot be shown again, not even by us. If you lose it: create a new one and revoke the old one. The company's owner is e-mailed about every new key (without the key itself).

## Using the key

Send it as a bearer token, exactly like an OAuth token:

```bash
curl https://nonox.app/api/v1/vouchers?status=POSTED \
  -H "Authorization: Bearer $NONOX_TOKEN"
```

Keep the key in an environment variable or a secrets manager, never in code or git. It works only against `/api/v1`, never against NONOX's ordinary pages.

## What the key may do

The key acts as **the person who created it**, with the intersection of two things on every call:

1. the key's scopes, and
2. what the person may do in the company right now.

When the person's role changes, the key's access shrinks from the next call. When the person leaves the company, the key stops working (`401`). An archived company is read-only: writes answer `403 company_archived`.

## Follow up and revoke

The list under **Anslutningar** shows when each key was last used and from which address. A key can be revoked at once, and the next call answers `401`. Every key created or revoked, and every write, is in the company's audit trail.

Turning NONOX API off for the company revokes every key, after a warning that has to be confirmed.

## Security in short

- The key has about 285 bits of randomness and a prefix (`nnx_`) that makes it easy to find should it leak into a code base.
- It belongs to exactly one company. Another company's id answers `404`.
- Outside its IP list it answers `403 ip_not_allowed`, even when the key is otherwise valid.
- It always has an expiry date. Plan the renewal in good time.
