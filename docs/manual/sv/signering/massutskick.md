---
beskrivning: Skicka samma dokument för signering till många på en gång. En mall och en fil med en rad per signering, en kontroll av varje rad före utskicket och ett resultat per rad efteråt.
ordning: 25
roller: [owner, admin, accountant]
modul: signing
rutter: [signing.bulk-sends, signing.bulk-send]
sokord: [massutskick, mass, många, csv, excel, fil, rad per signering, bulk, skicka till många, förhandsgranska, e-postkvot, avtal till alla, medlemsavtal]
---

# Massutskick

Med ett massutskick skickar du samma dokument för signering till många, till exempel ett nytt avtal till alla kunder. Du utgår från en **mall** och en **fil** med en rad per signering. Varje rad blir en egen signering med eget förlopp, som om du hade skickat den för hand från mallen. Det är bara de som får hantera signering som ser **Massutskick** under **Signering**.

![Listan Massutskick.](/bilder/manual/signering/massutskick.webp)

## Förbered mallen och filen

1. Massutskick går bara med en mall för ett eget dokument. Spara en under **Signering → Mallar** med rollerna och fälten du behöver, till exempel **Kund** och **Säljare**.
2. Under **Signering → Massutskick** väljer du mallen under **Mall**. Tryck på **Ladda ner exempelfil för mallen** för en fil med rätt kolumner.
3. Fyll i filen med en rad per signering. Den kan vara CSV eller Excel (XLSX), högst 5 MB och högst 500 rader.

Filen har en rubrikrad och för varje roll i mallen dessa kolumner, där *Roll* är rollens namn i mallen:

| Kolumn | Krävs | Vad den innehåller |
|---|---|---|
| Roll e-post, till exempel **Kund e-post** | Ja | Undertecknarens e-postadress. |
| Roll namn, till exempel **Kund namn** | Nej | Namnet. Det visas bara i förhandsgranskningen. |
| Roll språk, till exempel **Kund språk** | Nej | `sv` eller `en`. Utan värde används mallens språk. |
| Roll åtkomstkod, till exempel **Säljare åtkomstkod** | Bara om rollens kontroll är åtkomstkod | Koden som undertecknaren ska skriva. Samma regler som i guiden. |

Steg, kontroll, fält, sista dag, påminnelser och kopiemottagare kommer från mallen. Kolumner som inte behövs hoppas över.

## Läs in och granska

Tryck på **Läs in och granska** under **Fil med en rad per signering**. Då läser NONOX filen en gång och **tar bort den**. Inget skickas. Du kommer till en sida som visar:

- hur många rader filen har, hur många som **Kan skickas** och hur många som **Skickas inte**
- **De första raderna** som de kommer att skickas, med namn och e-post
- **Kontroll av raderna** med varje rads status och orsaken om raden inte skickas

![Förhandsgranskningen med kontroll av raderna.](/bilder/manual/signering/massutskick-forhandsgranskning.webp)

En rad skickas inte om en e-postadress saknas eller inte är giltig, om språket inte är `sv` eller `en`, om åtkomstkoden är för enkel, om samma e-postadress står på två roller eller om samma undertecknare redan finns på en rad högre upp (en dubblett). Rätta dessa rader i filen och läs in dem i ett eget massutskick. Fler än 500 rader går inte; dela upp filen.

Visa **Bara de som inte skickas** för att se felen. Välj **Ta bort förhandsgranskningen** om du vill börja om. En förhandsgranskning som ingen bekräftat tas bort av sig själv natten efter att den blivit ett dygn gammal.

## Skicka

När raderna ser rätt ut väljer du **Skicka** och bekräftar. Det är ditt beslut: först då skapas signeringarna, och undertecknarna i första steget får sin länk. E-postmeddelandena går inte att ta tillbaka.

NONOX skickar signeringarna i bakgrunden, så du kan lämna sidan. Sidan visar hur många rader som är klara. Varje signering finns också under **Alla signeringar**, och den följer vanliga regler för påminnelser, sista dag och bevarande.

![Resultat per rad efter ett massutskick.](/bilder/manual/signering/massutskick-resultat.webp)

### E-postkvoten

Ett företag kan skicka högst 300 dokument-e-postmeddelanden i timmen. Räcker inte kvoten för hela massutskicket varnar förhandsgranskningen. Utskicket väntar då och fortsätter av sig självt när kvoten släpper, så inga rader misslyckas bara för att kvoten är slut. Du kan välja **Avbryt massutskicket** när som helst. Det som redan skickats ligger kvar, och raderna som återstår skickas inte.

## Resultat per rad

Under **Resultat per rad** ser du för varje rad om signeringen **Skickad** (med länk till **Öppna signeringen**), **Misslyckades** (med orsak) eller **Hoppades över**. Adresserna visas inte här: de tas bort ur raden så fort den är skickad. Behöver du försöka igen med en rad som misslyckades, läs in den i ett nytt massutskick.

## Vad som sparas

- **Filen** sparas inte. Den läses en gång och tas bort.
- **Adresser och koder** i raderna ligger krypterade bara tills raden är skickad eller massutskicket är avslutat. Sedan tas de bort. Ett massutskick som inte kommer vidare avbryts automatiskt efter sju dagar, och uppgifterna i raderna tas då bort.
- **Mallen** måste vara oförändrad från förhandsgranskningen till utskicket. Har den ändrats vägrar NONOX att skicka, eller stoppar ett utskick som pågår, med en förklaring. Ta bort förhandsgranskningen och läs in filen igen.
- **Resultatlistan** (radens nummer, status, orsak och vilken signering den blev) sparas i tolv månader och tas sedan bort. Själva signeringarna bevaras enligt vanliga regler, se *Bevarande och radering*.

## Ditt ansvar

<!-- TODO(legal-verify): om en erbjudandesignering som skickas i massutskick är marknadsföring enligt marknadsföringslagen 19 §. -->

Företaget ansvarar för adresserna i filen: att det har rätt att använda dem för signeringen och att mottagarna får veta vem som behandlar deras uppgifter och varför (dataskyddsförordningen artikel 14). Signeringsmejlet visar företagets namn som avsändare. Massutskick är till för avtal och handlingar som mottagarna väntar sig, inte för att sälja: ett erbjudande som skickas till privatpersoner som inte bett om det kan vara marknadsföring och kräver då samtycke eller att mottagaren redan är kund (marknadsföringslagen 19 §).

## Vem får göra vad

Den som får hantera signering (ägare, administratör och redovisningskonsult) kan göra massutskick och se alla massutskick i företaget. Övriga ser inte **Massutskick**. Varje steg skrivs i behandlingshistoriken, med id och antal men utan adresser.
