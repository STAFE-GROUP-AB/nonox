---
beskrivning: Låt ett kassasystem eller en redovisningsbyrå lämna SIE-filer till företaget över SFTP. Filen granskas och väntar på att någon bekräftar den.
ordning: 45
roller: [owner, admin]
modul: connect
rutter: [connect.index]
sokord: [SFTP, SSH, SSH-nyckel, SIE-fil, kassasystem, filöverföring, ladda upp SIE, redovisningsbyrå, nyckel, fingeravtryck]
---

# SFTP för SIE-filer

Vissa program skickar bokföringen som en fil i stället för att anropa ett API. Ett kassasystem kan till exempel lägga en SIE-fil med dagens försäljning varje natt. Med SFTP får företaget en egen mapp dit programmet kan lämna sina SIE-filer.

Ingenting bokförs av sig självt. Varje fil granskas på samma sätt som när du laddar upp den under **Importera SIE**, och väntar sedan på att någon läser rapporten och bekräftar.

## Innan du börjar

- Modulen **NONOX API** måste vara påslagen. Se [NONOX API](/manual/installningar/nonox-connect).
- Du behöver vara ägare eller administratör och själv få läsa in SIE-filer.
- Du behöver en passkey för att lägga till nycklar. Se [Passkeys och inloggningar](/manual/installningar/passkeys-och-inloggningar).
- Den som driftar NONOX måste ha satt upp SFTP-servern. Står det att SFTP inte är uppsatt på servern går det inte att slå på.

## Slå på SFTP

1. Öppna **Anslutningar** i menyn.
2. Gå till **SFTP för SIE-filer** och klicka på **Slå på SFTP**.
3. Nu syns **Server**, **Port** och **Användarnamn**. Ge dem till den som sköter det andra programmet.

Du blir **ansvarig för filerna som kommer in**. Filerna granskas i ditt namn. Förlorar du rätten att läsa in SIE-filer slutar SFTP att fungera tills någon annan slår på det igen.

## Lägga till en nyckel

Programmet loggar in med en SSH-nyckel, aldrig med lösenord. Den som sköter programmet skapar nyckeln och skickar dig den **publika** delen. Den börjar med `ssh-ed25519` eller `ssh-rsa`.

1. Skriv ett **Namn**, till exempel "Kassasystemet".
2. Klistra in nyckeln under **Publik nyckel**.
3. Klicka på **Lägg till med passkey** och bekräfta.
4. Jämför fingeravtrycket som visas (det börjar med `SHA256:`) med det som programmet har. Är de olika, ta bort nyckeln.

> [!WARNING]
> Ta aldrig emot en privat nyckel. Får du en text som innehåller PRIVATE KEY, be om den publika i stället. NONOX tar bara emot ed25519 eller RSA med minst 3072 bitar.

Klicka på **Ta bort** vid en nyckel för att stänga ute den. Det gäller från nästa inloggning.

## Begränsa vilka adresser som får ansluta

Har det andra programmet en fast IP-adress kan du låsa SFTP till den.

1. Skriv en adress eller ett intervall per rad under **Tillåtna IP-adresser**, till exempel `192.0.2.10` eller `192.0.2.0/24`.
2. Klicka på **Spara med passkey** och bekräfta.

En tom lista släpper in alla adresser.

## När en fil kommer in

1. Programmet lägger filen i mappen `/in`.
2. NONOX kontrollerar att det är en SIE-fil och granskar den. Filen tas sedan bort ur mappen.
3. Du och ägaren får en notis.
4. Under **Filer som har kommit in** ser du filen. Klicka på **Läs rapporten**.
5. Läs rapporten under **Importera SIE** och läs in filen om allt stämmer. Se [SIE-filer](/manual/bokforing/sie).

Står det **Inte mottagen** ser du skälet under, till exempel att filen inte var en SIE-fil. Be den som skickade den att rätta filen och skicka igen.

## Lägga ut en fil att hämta

Klicka på **Lägg SIE-fil för innevarande år i /ut**. Då hamnar en SIE4-fil för räkenskapsåret i mappen `/ut`, där programmet kan hämta den. Filen tas bort efter en vecka.

## Stänga av

Klicka på **Stäng av SFTP**. Ingen kan logga in förrän du slår på det igen. Nycklarna sparas. Stänger du av hela NONOX API tas också nycklarna bort.

## Bra att veta

- En fil får vara högst 50 MB. Företaget kan ta emot högst 30 SIE-filer i timmen, tillsammans med det som laddas upp i webbläsaren.
- Bara filer som slutar på `.se`, `.si` eller `.sie` tas emot.
- Allt som händer med SFTP sparas i behandlingshistoriken: påslagning, nycklar, inloggningar och filer.
- SFTP-servern är SFTPGo, fri programvara under AGPL-3.0. Länken till dess källkod finns längst ner i rutan.

## Läs också

- [NONOX API](/manual/installningar/nonox-connect)
- [SIE-filer](/manual/bokforing/sie)
- [Utvecklare: SFTP för SIE-filer](/utvecklare/sftp)
