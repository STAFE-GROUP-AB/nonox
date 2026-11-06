# API-nycklar

En API-nyckel är till för ett företags **egna** skript och integrationer: ett exportjobb, en koppling från en webbutik ni själva driver, ett kalkylark som hämtar saldon. Ska ett program som många företag använder ansluta, använd [OAuth](autentisering.md) i stället, så att varje företag godkänner det själv.

## Skapa en nyckel

En ägare eller administratör skapar nyckeln under **Anslutningar** i NONOX och väljer:

| | |
|---|---|
| Namn | Så att ni ser vad nyckeln används till, till exempel "Export till BI". |
| Behörigheter | Bara de som behövs. Du kan bara ge behörigheter du själv har. Se [Behörigheter](behorigheter.md). |
| Slutdatum | Obligatoriskt. Högst ett år bort på nonox.app (inställbart för den som driftar själv). |
| IP-adresser | Valfritt. Adresser eller nät (CIDR, till exempel `203.0.113.0/24`) som nyckeln får användas från. |

Att skapa en nyckel kräver en färsk bekräftelse med **passkey**. Nyckeln ser ut så här och visas **en gång**:

```
nnx_8Kq2vT0bX7mYc4LhR1sWd9FzJ3nPa6Ue5GtV2kQoMwB8yHrC
```

NONOX sparar bara ett SHA-256-avtryck av nyckeln, så den kan inte visas igen, inte ens av oss. Tappar du bort den: skapa en ny och återkalla den gamla. Företagets ägare får ett mejl om varje ny nyckel (utan själva nyckeln).

## Använda nyckeln

Skicka den som en bearer token, precis som en OAuth-token:

```bash
curl https://nonox.app/api/v1/vouchers?status=POSTED \
  -H "Authorization: Bearer $NONOX_TOKEN"
```

Lägg nyckeln i en miljövariabel eller en hemlighetshanterare, aldrig i koden eller i git. Den fungerar bara mot `/api/v1`, aldrig mot vanliga sidor i NONOX.

## Vad nyckeln får göra

Nyckeln agerar som **personen som skapade den**, med snittet av två saker vid varje anrop:

1. nyckelns behörigheter, och
2. vad personen får göra i företaget just nu.

Får personen en annan roll minskar nyckelns åtkomst från nästa anrop. Lämnar personen företaget slutar nyckeln fungera (`401`). Ett arkiverat företag kan bara läsas: skrivande anrop ger `403 company_archived`.

## Följa upp och återkalla

Listan under **Anslutningar** visar för varje nyckel när den senast användes och från vilken adress. En nyckel kan återkallas direkt, och då ger nästa anrop `401`. Varje nyckel som skapas eller återkallas, och varje skrivande anrop, står i företagets behandlingshistorik.

Stängs NONOX API av för företaget återkallas alla nycklar, efter en varning som måste bekräftas.

## Säkerhet i korthet

- Nyckeln har cirka 285 bitar slump och ett prefix (`nnx_`) som gör den lätt att hitta om den skulle läcka i en kodbas.
- Den är bunden till exakt ett företag. Ett annat företags id ger `404`.
- Utanför IP-listan ger den `403 ip_not_allowed`, också om nyckeln i övrigt är giltig.
- Den har alltid ett slutdatum. Planera förnyelsen i god tid.
