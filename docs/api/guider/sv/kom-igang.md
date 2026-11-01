# Kom igång

Den här guiden tar dig från ingenting till en bokförd verifikation via API:t. Du behöver ett företag i NONOX där du är ägare eller administratör, och en passkey på ditt konto. Testa gärna i ett eget företag först; det finns ännu ingen separat testmiljö.

## 1. Slå på NONOX Connect

API:t är en modul och är avstängt från början. Öppna **Inställningar → Moduler** och slå på **NONOX Connect**. Så länge modulen är avstängd svarar varje anrop för företaget `404`, som om API:t inte fanns.

Under modulens inställningar kan företaget också begränsa vilka appar som får ansluta och vilka IP-adresser som får anropa API:t.

## 2. Skapa en API-nyckel

För ett eget skript är en API-nyckel det enklaste. Öppna **Anslutningar** och skapa en nyckel med:

- ett namn, till exempel "Testskript";
- behörigheterna `vouchers:view`, `vouchers:create` och `vouchers:post`;
- ett slutdatum (obligatoriskt, högst ett år bort).

Bekräfta med din passkey. Nyckeln börjar med `nnx_` och visas **en gång**; kopiera den direkt. Lägg den i en miljövariabel så att den inte hamnar i koden:

```bash
export NONOX_TOKEN="nnx_..."
```

Ska ett program som många företag använder ansluta, använd OAuth i stället: [Autentisering (OAuth)](autentisering.md).

## 3. Se vilket företag nyckeln gäller

`GET /company` kräver ingen särskild behörighet och svarar med företaget och nyckeln. Det är ett bra första anrop för att se att allt fungerar.

<!-- nonox:samples:getCompany -->

## 4. Skapa ett utkast

En verifikation skapas först som utkast. Belopp anges i hela öre: `125000` är 1 250,00 kr. Raderna kontrolleras som i appen (konton som finns och är aktiva, debet eller kredit på varje rad); balansen kontrolleras när utkastet bokförs.

Varje `POST` kräver en `Idempotency-Key`, så att ett nytt försök efter ett nätverksfel aldrig skapar två utkast. Läs mer i [Idempotens](idempotens.md).

<!-- nonox:samples:createVoucher -->

Svaret är `201 Created` med utkastet. Spara `data.id`.

## 5. Bokför utkastet

Bokföringen ger verifikationen nästa nummer i serien utan luckor, kontrollerar att den balanserar och att datumet ligger i ett öppet räkenskapsår utanför låsta perioder, och skriver behandlingshistorik. Det går inte att ångra: en bokförd verifikation kan bara rättas med en rättelseverifikation.

<!-- nonox:samples:postVoucher -->

## 6. Läs tillbaka

Lista bokförda verifikationer, eller hämta bara det som ändrats sedan förra gången med `updated_since`. Se [Sidindelning och synk](sidindelning.md).

<!-- nonox:samples:listVouchers -->

## När något går fel

Alla fel kommer som `application/problem+json` med en stabil `code` och en svensk förklaring i `detail`. Styr alltid på `code`. Saknar nyckeln en behörighet kommer `403 insufficient_scope` med `required_scopes`. Alla koder finns i [Fel](fel.md).

## Nästa steg

- [Behörigheter](behorigheter.md): vilka scopes som öppnar vilka anrop.
- [API-referensen](https://nonox.app/utvecklare/referens): varje anrop med parametrar, scheman och exempel.
- [Gränser för anrop](granser.md): hur många anrop per minut som går.
