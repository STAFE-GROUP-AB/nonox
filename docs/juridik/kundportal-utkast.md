# Kundportalen: utkast till intresseavvägning och information (för jurist)

**UTKAST – inte granskat av jurist.** Skrivet 2026-11-08 som underlag för frågorna L1 och L2 i
`docs/specs/rules/kundportal.md` (ADR-075). Modulen kan inte slås på i den driftade tjänsten förrän
L1, L2, L4 och L5 är besvarade (`NONOX_CUSTOMER_PORTAL` är av). Inget här är juridisk rådgivning.

## A. Mall: intresseavvägning (artikel 6.1 f) för kontaktpersoner hos kunder som är juridiska personer

TODO(legal-verify): L1 — är artikel 6.1 f rätt grund för kontaktpersoner hos juridiska personer, och
6.1 b för privatpersoner och enskilda näringsidkare som själva är kunden? Vad ska bedömningen
innehålla? IMY:s sida om intresseavvägning är inte läst för det här utkastet.

Företaget fyller i mallen och sparar den i sin egen dokumentation.

1. **Det berättigade intresset.** [Företaget] har ett intresse av att fullgöra sitt avtal med
   [kunden] smidigt och säkert: att kundens kontaktperson kan se fakturor, betalningsuppgifter och
   offerter, godkänna offerter och ställa frågor utan att uppgifterna skickas runt med e-post.
2. **Nödvändighet.** Bara e-postadress och namn sparas om kontaktpersonen, och vad hen gör i
   portalen (inloggning med IP-adress och webbläsare; öppnade fakturor utan IP-adress). Ingen
   telefon, inget personnummer. Uppgifterna kan inte nås med mindre: e-postadressen är inloggningen.
3. **Den registrerades intressen.** Kontaktpersonen förväntar sig att kunna få kundens fakturor i
   sin roll. Portalen är frivillig; fakturorna kommer som förut. Personen kan när som helst ta bort
   sin inloggning (invändning enligt artikel 21) och ladda ner sina uppgifter.
4. **Skyddsåtgärder.** Inloggning med engångskod; egen inloggning skild från NONOX; bara den egna
   kundens uppgifter; sessioner som slutar efter 30 minuter utan aktivitet och 12 timmar; gallring
   enligt `docs/gdpr/lagringstider.md`.
5. **Slutsats.** [Fylls i av företaget.]

## B. Information enligt artikel 13 (sidan "Så hanteras dina personuppgifter" och inbjudan)

TODO(legal-verify): L2 — vad ska informationen innehålla, och får inbjudan länka till en sida som
företaget fyller i? Artikel 13 är inte citerad i regelbladet.

Texten visas på `/kundportal/{portalnyckel}/integritet` (vyn `portal::portal.privacy`), och inbjudan
länkar dit. Den innehåller i dag:

- vem som är personuppgiftsansvarig (företaget, med organisationsnummer och e-post) och att NONOX är
  personuppgiftsbiträde;
- ändamålet och den rättsliga grunden (avtal för en kund som själv är privatperson eller enskild
  näringsidkare, berättigat intresse för en kontaktperson hos en juridisk person);
- vilka uppgifter som sparas;
- lagringstiderna (inbjudan 14 dagar, inaktiv inloggning 12 månader + 30 dagar, händelser 24
  månader, frågor 24 månader efter avslut, offerter som avtalet);
- rättigheterna (tillgång och dataportabilitet med JSON-utdraget, rättelse av namnet, radering
  med "Ta bort min inloggning", invändning, begränsning, klagomål till IMY);
- en egen text som företaget kan lägga till under **Kundportal → Inställningar**.

Saknas enligt utkastet: kontaktuppgifter till ett dataskyddsombud (om företaget har ett), mottagare
(underbiträden enligt `docs/juridik/underbitraden.md`), och om uppgifter förs till tredje land.

## C. Övriga öppna frågor

L3–L12 står i `docs/specs/rules/kundportal.md` och som `TODO(legal-verify)` i koden.
