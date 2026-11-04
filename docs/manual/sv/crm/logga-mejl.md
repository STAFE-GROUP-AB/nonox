---
beskrivning: Skicka en kopia eller vidarebefordra ett mejl till företagets CRM-adress, så hamnar det på rätt kund och person.
ordning: 42
roller: [owner, admin, accountant, bookkeeper, sales]
modul: crm
rutter: [crm.emails]
sokord: [mejl, e-post, logga mejl, hemlig kopia, bcc, vidarebefordra, ej kopplade, mejlhistorik]
---

# Logga mejl

Mejl du skickar och får i ditt vanliga mejlprogram kan hamna på rätt kund i CRM. Skicka en hemlig kopia till företagets CRM-adress när du mejlar en kund, eller vidarebefordra ett mejl du har fått. NONOX letar upp kunden och personen med samma e-postadress och lägger mejlet i tidslinjen.

![Sidan E-post med företagets CRM-adress och två mejl som väntar under Ej kopplade.](/bilder/manual/crm/logga-mejl.webp)

## Så här gör du

1. Öppna **CRM → E-post** och kopiera adressen under **Logga mejl i CRM**. Den slutar på `+crm@…`.
2. Spara adressen som kontakt i ditt mejlprogram, till exempel som "CRM".
3. När du mejlar en kund: lägg CRM-adressen som **hemlig kopia** (Bcc).
4. När du har fått ett mejl från en kund: **vidarebefordra** det till CRM-adressen.

Mejlet syns sedan på kundens kort och på personens sida som en aktivitet av typen **E-post**, med ämnet och början av texten. **Visa hela mejlet** visar hela texten.

> [!IMPORTANT]
> Skicka från samma adress som du loggar in i NONOX med. Mejl från andra adresser, och mejl som inte går att verifiera som äkta från avsändarens domän, tas inte emot. Det hindrar att någon utomstående skriver i era kunders historik.

## Så hittar NONOX rätt kund

- En hemlig kopia räknas som **utgående**. Mottagarna (Till och Kopia) jämförs med e-postadresserna på personer och kunder.
- Ett vidarebefordrat mejl räknas som det ursprungliga mejlet: **inkommande** från kunden, med kundens adress som avsändare. Både vanlig vidarebefordran och "vidarebefordra som bilaga" fungerar.
- Bara hela e-postadressen jämförs, aldrig domänen. Era egna adresser räknas aldrig som kunden.
- Ett mejl kan hamna på flera kunder om flera mottagare finns i CRM.

## Ej kopplade

Mejl där ingen adress finns i CRM väntar under **Ej kopplade**. Där kan du:

- **Koppla** mejlet till en kund, och välja en person hos kunden.
- **Skapa prospekt** av en av adresserna i mejlet. Ange företagets och personens namn och den rättsliga grunden.
- **Ta bort** mejlet. Inget av det sparas.

Det som ingen hanterar tas bort efter 30 dagar.

## Kontakta inte och begränsning

- Ett mejl **från** en person som har bett att inte bli kontaktad loggas som vanligt. Det är personens kontakt med er.
- Ett mejl **till** en sådan person loggas inte som ett mejl. Det väntar under **Ej kopplade** med märket Kontakta inte och kan bara loggas som en anteckning, eller tas bort.
- Gäller mejlet en person med **begränsad behandling**, eller en kund med begränsad behandling, sparas det inte alls.

Se [GDPR i CRM](/manual/crm/gdpr-i-crm).

## Bra att veta

- **Bara texten sparas**, och den sparas krypterad. Bilagor sparas inte, bara deras namn. Originalet finns kvar i ditt eget mejlprogram.
- Adressen är inkorgens adress med `+crm`. Byter ägaren inkorgens adress byter också CRM-adressen. Inkorgsmodulen behöver inte vara på för att logga mejl i NONOX molntjänst.
- Kör ni NONOX själva och hämtar inkorgen med IMAP: skicka till brevlådans egen adress med `+crm` (till exempel `bokforing+crm@er-doman.se`). Er e-postserver måste leverera plusadresser till samma brevlåda och lägga till en rubrik Authentication-Results med resultatet av SPF, DKIM och DMARC. Annars tas mejlen inte emot.
- Raderas en person raderas de loggade mejlen om personen också.

## Läs också

- [Mejlmallar](/manual/crm/mejlmallar)
- [Aktiviteter och nästa steg](/manual/crm/aktiviteter-och-nasta-steg)
- [Kontaktpersonens sida](/manual/crm/kontaktpersonens-sida)
- [GDPR i CRM](/manual/crm/gdpr-i-crm)
