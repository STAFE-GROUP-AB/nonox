# Samtycke till elektroniska handlingar

Version 1.0 · gäller från 2026-11-19

> **Utkast.** Texten är inte granskad av jurist. En jurist ska granska den innan den används mot kunder. Frågorna står under "Öppna frågor" längst ned.

<!-- TODO(legal-verify): svensk rätt har inget allmänt krav på samtycke för elektroniska underskrifter. Bekräfta att steget är ett användbart bevis, och om avtal med konsumenter på distans (lag (2005:59) om distansavtal och avtal utanför affärslokaler) kräver mer om varaktigt medium än en kopia med e-post. Se docs/adr/ADR-046-crm-signing.md, gap 7. -->

När någon ska skriva under en handling via en länk från NONOX visas den här texten först, en gång per undertecknare och handling, efter en eventuell kontroll med kod och innan själva handlingen visas. Undertecknaren kryssar i en ruta för att fortsätta, eller väljer att inte skriva under elektroniskt.

Texten visas på svenska eller engelska, på det språk sidan har. Det som sparas är versionen, en SHA-256-kontrollsumma av exakt den text som visades och tidpunkten. Kontrollsumman räknas fram av styckena och meningen vid rutan, i den ordning de står nedan, åtskilda av en tom rad.

## Texten på svenska

Handlingen skickas, visas och skrivs under elektroniskt. Du skriver under med en enkel elektronisk underskrift på nästa sida, och du får en kopia av den underskrivna handlingen med e-post. Spara kopian: den är ditt exemplar.

Du behöver inte skriva under elektroniskt. Vill du skriva under på annat sätt, till exempel på papper, kontaktar du avsändaren. Samtycket gäller bara den här handlingen och betyder inte att du godkänner innehållet; det gör du först när du skriver under.

Rutan: **Jag godkänner att handlingen skrivs under elektroniskt.**

## The text in English

The document is sent, shown and signed electronically. You sign with a simple electronic signature on the next page, and you receive a copy of the signed document by e-mail. Keep the copy: it is your own copy.

You do not have to sign electronically. If you want to sign in another way, for example on paper, contact the sender. The consent applies to this document only and does not mean that you accept its content; you do that only when you sign.

The box: **I agree that the document is signed electronically.**

## Om undertecknaren inte vill skriva under elektroniskt

Sidan säger "Kontakta avsändaren om du vill skriva under på annat sätt", och avsändaren får ett meddelande i NONOX. Länken ändras inte: undertecknaren kan öppna den igen och godkänna så länge den gäller, och avsändaren kan avbryta signeringen och skriva under på annat sätt. Undertecknaren får inga påminnelser, varken automatiska eller manuella, förrän hen har godkänt.

## Vad samtycket är och inte är

Samtycket gäller formen: att handlingen hanteras och skrivs under elektroniskt. Det är inte ett samtycke till behandling av personuppgifter enligt dataskyddsförordningen. Uppgifterna om svaret behandlas på grund av berättigat intresse (artikel 6.1 f), för att kunna visa hur handlingen skrevs under, och artikel 7.3 om att återkalla ett samtycke gäller därför inte dem. Den som inte vill skriva under elektroniskt kan alltid låta bli och kontakta avsändaren.

En enkel elektronisk underskrift passar inte för handlingar som enligt lag ska skrivas under på papper eller i en särskild form, till exempel köp och gåva av fast egendom (jordabalken 4 kap. 1 och 29 §§), testamente och äktenskapsförord.

## Vad som sparas och hur länge

Version, textens kontrollsumma och tidpunkt sparas på undertecknarens rad, tillsammans med övriga bevis om signeringen, och tas bort med dem. Händelsen läggs i signeringens händelsekedja och står på signeringsbeviset. Inget av det kan ändras i efterhand.

## Versioner

| Version | Gäller från | Ändring |
|---|---|---|
| 1.0 | 2026-11-19 | Första versionen. |

En ny formulering blir en ny version, med ny kontrollsumma. Tidigare versioner finns kvar i den här filens historik, så att en sparad kontrollsumma alltid kan knytas till sin text.

## Öppna frågor

- Är steget ett användbart bevis, när svensk rätt inte kräver samtycke till elektronisk underskrift?
- Räcker en kopia med e-post som varaktigt medium vid avtal med konsumenter på distans?
