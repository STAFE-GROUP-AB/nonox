# Egen drift

API:t finns i varje NONOX, också det du driftar själv, och det kostar ingenting. Allt i guiderna gäller; byt bara `https://nonox.app` mot din egen adress.

## Adresserna

| | Adress |
|---|---|
| API | `https://<din-server>/api/v1` |
| Godkännande (OAuth) | `https://<din-server>/anslut` |
| Token | `https://<din-server>/oauth/token` |
| Metadata | `https://<din-server>/.well-known/oauth-authorization-server` |
| Den här dokumentationen | `https://<din-server>/utvecklare` |

OpenAPI-filen har en server med variabeln `host` för just det här, så att verktyg som Postman kan peka om alla anrop på en gång. Installationen som visar den här sidan har:

<!-- nonox:this-installation -->

## Nycklar för signering

OAuth-token signeras med en RSA-nyckel. Skapa nycklarna en gång:

```bash
php artisan passport:keys
```

De hamnar i `storage/oauth-private.key` och `storage/oauth-public.key` och ska aldrig in i git. Du kan också ange dem i miljövariablerna `PASSPORT_PRIVATE_KEY` och `PASSPORT_PUBLIC_KEY`. Utan nycklar går modulen inte att slå på. Byts nycklarna blir alla utfärdade access tokens ogiltiga; apparna förnyar med sin refresh token och märker inget mer.

## Registrera appar

```bash
php artisan connect:app "Exempelappen" https://app.example/callback
php artisan connect:app "Exempelappen mobil" https://app.example/callback --public
```

Se [Autentisering](autentisering.md#registrera-appen) för vad kommandot skriver ut och reglerna för omdirigeringsadresser.

## Gränser och livslängder

Alla gränser ställs in i `.env`. Tabellen visar vad den här installationen använder:

<!-- nonox:limits -->

Access tokens gäller aldrig mer än 15 minuter, hur högt värdet än sätts.

## Underhåll

`php artisan connect:prune` tar bort utgångna idempotensposter, koder och tokens. Den körs varje natt av schemaläggaren, så det enda som krävs är att schemaläggaren är igång (`php artisan schedule:run` varje minut).

## Bakom en proxy

Gränser per IP-adress och IP-listor bygger på besökarens adress. Kör du NONOX bakom en lastbalanserare eller Cloudflare, se till att Laravel litar på proxyn (`TrustProxies`), annars ser alla anrop ut att komma från samma adress.

## Mer

- [README: installation](https://github.com/STAFE-GROUP-AB/nonox#installation)
- [ADR-032](https://github.com/STAFE-GROUP-AB/nonox/blob/main/docs/adr/ADR-032-public-api-and-nonox-connect.md): besluten och säkerhetsmodellen bakom API:t.
