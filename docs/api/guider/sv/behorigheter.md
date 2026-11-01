# Behörigheter

Varje app och API-nyckel har en lista med **behörigheter** (scopes). De är samma behörigheter som rollerna i NONOX använder, så en bokförare och en app med `vouchers:post` får bokföra på samma sätt och med samma kontroller.

## Alla behörigheter

Tabellen genereras från OpenAPI-filen. Anropen i högra kolumnen kräver behörigheten.

<!-- nonox:scopes -->

`GET /company` kräver ingen särskild behörighet: varje giltig nyckel eller token får se vilket företag den gäller.

## Vad ett anrop faktiskt får göra

Ett anrop får göra det som **både** nyckelns eller appens behörigheter **och** personen bakom tillåter, i det ögonblicket:

| Personens roll i dag | Nyckelns behörigheter | Får nyckeln bokföra? |
|---|---|---|
| Bokförare | `vouchers:view`, `vouchers:post` | Ja |
| Attestant (får inte bokföra) | `vouchers:view`, `vouchers:post` | Nej, `403 insufficient_scope` |
| Bokförare | `vouchers:view` | Nej, `403 insufficient_scope` |
| Har lämnat företaget | `vouchers:post` | Nej, `401` |

Snittet prövas vid **varje** anrop, i varje kontroll i systemet, inte bara för den behörighet adressen nämner. En ändrad roll gäller alltså från nästa anrop, utan att appen behöver göra något.

## När en behörighet saknas

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

`required_scopes` säger vad som behövs. För en OAuth-app betyder det att personen behöver godkänna appen igen med fler behörigheter; för en API-nyckel att en ny nyckel behöver skapas.

## Be om så lite som möjligt

Be bara om de behörigheter appen faktiskt använder. Personen ser listan på svenska när hen godkänner, och en kort lista är lättare att säga ja till. `vouchers:post` går inte att ångra i bokföringen (bara rätta), så be om den först när appen verkligen bokför.

## Några saker API:t aldrig gör

- Lämnar ut personnummer. En kund har `has_personal_number`; själva numret kan skrivas men aldrig läsas.
- Ändrar eller tar bort en bokförd verifikation. Den kan bara rättas.
- Ger åtkomst till löner, lönebesked eller personalliggare. Det finns inga behörigheter för det.
