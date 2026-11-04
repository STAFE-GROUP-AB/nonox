---
beskrivning: Mejl ni skickar ofta, med fält för namn och företag, som skickas direkt från kundkortet och loggas.
ordning: 44
roller: [owner, admin, accountant, bookkeeper, sales]
modul: crm
rutter: [crm.templates]
sokord: [mejlmall, e-postmall, mall, mejla med mall, sammankoppling, fält]
---

# Mejlmallar

En mejlmall är ett mejl ni skickar ofta, till exempel ett tack efter ett möte. Mallen har fält som byts mot mottagarens uppgifter när mejlet skickas. Du skickar den med **Mejla med mall** på en kunds kort eller en persons sida, och mejlet loggas där.

![Mejlmallar med två mallar.](/bilder/manual/crm/mejlmallar.webp)

## Så här gör du en mall

1. Öppna **CRM → E-post** och sidan **Mejlmallar**, eller gå dit från en kund med **Mejla med mall**.
2. Välj **Ny mall**.
3. Skriv **Namn**, **Ämne** och **Text**. Använd fälten i rutan till höger.
4. Välj **Spara mallen**.

Den som har behörighet att ändra inställningarna i CRM skapar och ändrar mallar. Alla som arbetar i CRM kan använda dem.

## Fälten

| Fält | Byts mot |
|---|---|
| `{förnamn}` | Mottagarens förnamn |
| `{efternamn}` | Mottagarens efternamn |
| `{namn}` | Mottagarens hela namn |
| `{företag}` | Kundens namn |
| `{säljare}` | Ditt namn (den som skickar) |
| `{säljarens_epost}` | Din e-postadress |
| `{mitt_företag}` | Ert företags namn |

Ett fält som är felstavat står kvar som det är, så att du ser det.

## Så här skickar du

1. Öppna kunden eller personen och välj **Mejla med mall**.
2. Välj mottagare under **Till** och en **Mall**. Ändra ämne och text om du vill.
3. Välj **Skicka mejlet**.

Mejlet loggas som en aktivitet av typen **E-post** på kunden.

## Avsändare och svar

Mejlet skickas från NONOX med ert företags namn som avsändare. Svaret går till din egen e-postadress (svarsadress), och mejlet säger själv vem som skickade det. NONOX skickar aldrig i ert företags domän, eftersom tjänsten bara kan intyga att mejl från sin egen domän är äkta. Kör ni NONOX själva används er egen e-postinställning.

## Bra att veta

- Ingen kan mejla en person som är märkt **Kontakta inte**, en adress på spärrlistan eller en kund med begränsad behandling.
- Har mottagaren **avregistrerat sig från utskick** måste du kryssa i att mejlet inte är marknadsföring, till exempel ett svar om en order.
- Är mottagaren en privatperson och mejlet marknadsföring krävs samtycke, eller att personen redan är kund (marknadsföringslagen 19 §).
- Ett testföretag loggar mejlet men skickar det aldrig.

## Läs också

- [Logga mejl](/manual/crm/logga-mejl)
- [Utskick](/manual/crm/utskick)
- [Kontaktpersonens sida](/manual/crm/kontaktpersonens-sida)
