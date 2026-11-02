# SFTP för SIE-filer

Vissa program lämnar hellre en fil än anropar ett API: ett kassasystem som skriver en SIE-fil varje natt, eller en redovisningsbyrås verktyg som tar fram bokföringen för en kund. För dem kan varje företag i NONOX få en egen SFTP-mapp. Filen som lämnas där granskas som en vanlig SIE-inläsning och väntar sedan på att en person i företaget läser rapporten och bekräftar. Ingenting bokförs automatiskt. Funktionen finns sedan version 1.3.0 av API:t.

## Så kommer du igång

1. En ägare eller administratör i företaget slår på **SFTP** under **Anslutningar** i NONOX. Den som slår på det blir ansvarig för filerna som kommer in och måste själv få läsa in SIE-filer.
2. Skapa ett nyckelpar för ditt program. Använd ed25519:

   ```bash
   ssh-keygen -t ed25519 -f nonox_sftp -C "kassasystemet"
   ```

   RSA går också, med minst 3072 bitar (`ssh-keygen -t rsa -b 4096`). DSA, ECDSA, certifikat och nycklar för säkerhetsnycklar (`sk-…`) tas inte emot.
3. Skicka **bara den publika nyckeln** (`nonox_sftp.pub`) till personen i företaget. Den privata nyckeln lämnar aldrig ditt program.
4. Personen lägger till nyckeln under **Anslutningar → SFTP för SIE-filer** och bekräftar med passkey. NONOX visar nyckelns fingeravtryck (`SHA256:…`); jämför det med ditt eget:

   ```bash
   ssh-keygen -lf nonox_sftp.pub
   ```

5. Du får server, port och användarnamn från samma sida. Användarnamnet börjar med `nnx` och är slumpat; det säger ingenting om företaget.

## Ansluta

```bash
sftp -i nonox_sftp -P 2022 nnxabc123def456gh@sftp.example.se
sftp> cd /in
sftp> put SIE4_20260601.se
```

Jämför serverns fingeravtryck första gången du ansluter. Står det på sidan i NONOX ska det stämma; annars fråga den som driftar installationen.

Bara inloggning med nyckel fungerar. Lösenord, tangentbordsinloggning, FTP, WebDAV och webbklienten är avstängda för de här användarna. Har företaget fyllt i tillåtna IP-adresser släpps bara de in.

## Mapparna

| Mapp | Vad du får göra |
|---|---|
| `/` | Lista |
| `/in` | Lista och ladda upp `.se`, `.si` och `.sie`. Inte skriva över, döpa om eller ta bort. |
| `/ut` | Lista och ladda ner filer som NONOX har lagt där |

Ladda upp under ett nytt namn varje gång, till exempel med datum och tid i namnet. En fil med samma namn som en som redan finns kan inte skrivas över.

## Gränser

- Högst 50 MB per fil, samma gräns som i webbläsaren.
- Högst 30 SIE-filer i timmen per företag, tillsammans med uppladdningar i webbläsaren. Fil nummer 31 avvisas och du får ett fel tillbaka.
- Högst två samtidiga anslutningar per användare.

## Vad som händer med filen

1. När uppladdningen är klar frågar SFTP-servern NONOX om filen får tas emot. Ligger den på fel ställe, har fel filändelse, är för stor eller har företaget stängt av SFTP svarar NONOX nej. Då tar servern bort filen och ditt program får ett fel.
2. NONOX läser filen i bakgrunden. Den måste börja med `#FLAGGA` och vara en textfil. Sedan granskas den precis som när någon laddar upp den i webbläsaren: SIE-typ 4, rätt organisationsnummer, kontrollsumma, att verifikationerna balanserar, att räkenskapsåret finns, att perioden inte är låst och att filen inte redan har lästs in.
3. Filen tas alltid bort ur `/in` efteråt. En krypterad kopia sparas med SIE-inläsningen.
4. Den ansvariga personen och ägaren får en notis. Under **Importera SIE** finns rapporten, och först när en person bekräftar läses verifikationerna in.

Kom ihåg att filen är ett underlag i bokföringen. Skicka den bara till rätt företag.

## Webhooken `sie.uploaded`

Vill ditt program veta hur det gick kan företaget lägga till en [webhook](webhooks.md) för `sie.uploaded`. Den skickas när filen har granskats:

```json
{
  "type": "sie.uploaded",
  "data": {
    "upload_id": "9b2f4c6e-8a1d-4e3f-b5c7-d9e1f3a5b7c9",
    "status": "analysed",
    "size": 18432,
    "sha256": "3a7bd3e2…",
    "sie_import_id": 118,
    "can_be_confirmed": true,
    "vouchers": 31,
    "blockers": 0
  }
}
```

`status` är `analysed` (väntar på bekräftelse) eller `rejected` (togs inte emot). `can_be_confirmed` är `false` när rapporten visar fel som måste rättas först. Filnamnet skickas inte med. Jämför `sha256` med din egen fil för att veta vilken det gäller.

## Hämta filer från `/ut`

En ägare eller administratör kan lägga en SIE4-fil för innevarande räkenskapsår i `/ut` med en knapp under Anslutningar. Hämta den med `get`. Filen tas bort efter sju dagar.
