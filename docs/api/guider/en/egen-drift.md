# Self-hosting

The API is in every NONOX, including the one you run yourself, and it costs nothing. Everything in the guides applies; just replace `https://nonox.app` with your own address.

## The addresses

| | Address |
|---|---|
| API | `https://<your-server>/api/v1` |
| Authorization (OAuth) | `https://<your-server>/anslut` |
| Token | `https://<your-server>/oauth/token` |
| Metadata | `https://<your-server>/.well-known/oauth-authorization-server` |
| This documentation | `https://<your-server>/utvecklare` |

The OpenAPI file has a server with a `host` variable for exactly this, so tools such as Postman can point every call elsewhere at once. The installation showing this page has:

<!-- nonox:this-installation -->

## Signing keys

OAuth tokens are signed with an RSA key. Create the keys once:

```bash
php artisan passport:keys
```

They end up in `storage/oauth-private.key` and `storage/oauth-public.key` and must never go into git. You can also set them in the environment variables `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY`. Without keys the module cannot be turned on. When the keys change, every issued access token becomes invalid; apps refresh with their refresh token and notice nothing more.

## Register apps

```bash
php artisan connect:app "Example app" https://app.example/callback
php artisan connect:app "Example app mobile" https://app.example/callback --public
```

See [Authentication](autentisering.md#register-the-app) for what the command prints and the rules for redirect URIs.

## Limits and lifetimes

Every limit is set in `.env`. The table shows what this installation uses:

<!-- nonox:limits -->

Access tokens never live longer than 15 minutes, however high the value is set.

## Maintenance

`php artisan connect:prune` removes expired idempotency records, codes and tokens. The scheduler runs it every night, so all that is needed is a running scheduler (`php artisan schedule:run` every minute).

## Behind a proxy

Limits per IP address and IP lists rely on the caller's address. When NONOX runs behind a load balancer or Cloudflare, make sure Laravel trusts the proxy (`TrustProxies`), or every call will seem to come from the same address.

## More

- [README: installation](https://github.com/STAFE-GROUP-AB/nonox#installation) (Swedish)
- [ADR-032](https://github.com/STAFE-GROUP-AB/nonox/blob/main/docs/adr/ADR-032-public-api-and-nonox-connect.md): the decisions and the security model behind the API.
