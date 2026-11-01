# AI i NONOX

Senast uppdaterad 2026-11-01.

NONOX AI är en modul som läser underlag och fyller i förslag. Den är avstängd tills ert företag själv slår på den.

## Vad AI:n gör

| Funktion | Vad AI:n läser | Vad den föreslår |
|---|---|---|
| Leverantörsfaktura | Fakturan (PDF eller bild) | Leverantör, fakturanummer, datum, belopp, moms, betalningsuppgifter och konton |
| Verifikation från underlag | Kvitto eller annat underlag | En hel verifikation med konton ur er kontoplan |
| Utlägg från anställda | Kvittot som den anställda skickar från Min sida, bara om ni särskilt har valt det | Datum, säljare, belopp, moms och kostnadskonto |
| Inkorgen | Dokument som kommit med e-post, bara om ni valt AI i inkorgens inställningar och ingen mall redan känner igen dokumentet | Vad dokumentet är, och för dagsrapporter från kassasystem vad varje belopp är och vilket konto det hör till |

## Vad AI:n inte gör

- **Den sparar, bokför och betalar aldrig något.** Den fyller i ett formulär. En person hos er kontrollerar, rättar och sparar, precis som när uppgifterna skrivs in för hand.
- Den fattar inga beslut om personer. Om ett utlägg ska betalas tillbaka beslutar den som godkänner utlägget.
- Den föreslår bara konton som finns aktiva i er kontoplan. Den väljer bara en leverantör som redan finns i ert register med samma organisationsnummer, bankgiro eller plusgiro.
- Automatisk bokföring från inkorgen bygger alltid på en mall som en person har godkänt och slagit på, aldrig på ett AI-svar.

## Vad AI:n kan göra fel

AI:n kan läsa fel och ändå låta säker. Kontrollera alltid mot underlaget, särskilt:
- belopp och moms, när kvittot är suddigt, avklippt eller har flera momssatser,
- datum, när flera datum står på dokumentet,
- konto, eftersom AI:n gissar utifrån vad som köpts,
- momsbehandling vid köp från utlandet och omvänd skattskyldighet,
- bankgiro, plusgiro och IBAN: jämför med leverantörens tidigare fakturor,
- att dokumentet är rätt sorts dokument, till exempel en obetald faktura som skickats in som utlägg.

Ni ansvarar för er bokföring, oavsett om uppgifterna kommer från AI eller skrivs in för hand (bokföringslagen).

**Utlägg:** AI:n läser bara det som står på kvittot och bedömer aldrig inköpet eller den anställda, till exempel om något ser privat ut. Den antecknar bara det den inte kunde läsa, som ett oläsligt belopp, en utländsk valuta eller moms som saknas. Det är ni som bedömer utlägget, utifrån kvittot och era egna regler.

## Vilka uppgifter som går vart

| | |
|---|---|
| **Vem som får underlaget** | Den AI-leverantör ni valt: OpenAI, Anthropic eller Google. |
| **Med vilken nyckel** | Ert företags egen API-nyckel och ert eget konto hos leverantören. Ni betalar användningen direkt till leverantören. |
| **Vad som skickas** | Underlaget (PDF eller bild) och er kontoplan. För dagsrapporter skickas rapportens rubriker och belopp. Inga andra uppgifter ur NONOX skickas, till exempel inte e-postens avsändare eller ämne. |
| **Var** | Leverantören kan behandla uppgifterna utanför EU/EES. Vad leverantören får göra med dem styrs av ert avtal med leverantören. Läs leverantörens villkor innan ni slår på modulen. |
| **Vad som sparas i NONOX** | Förslaget, och för utlägg AI:ns anteckningar. Behandlingshistoriken visar att en analys gjorts, med leverantör, modell och antal token, men aldrig innehållet. |
| **Er nyckel** | Lagras krypterad och visas aldrig igen. Bara de fyra sista tecknen visas. |

Underlag innehåller ofta personuppgifter, till exempel namn på kvitton. Ert företag är personuppgiftsansvarigt för att skicka dem till AI-leverantören. Se punkt 6 i [personuppgiftsbiträdesavtalet](personuppgiftsbitradesavtal.md) och [konsekvensbedömningen för AI](../gdpr/konsekvensbedomning-ai.md).

## Anställda

När ni har valt att AI ska läsa anställdas kvitton står det på Min sida innan kvittot skickas. Där står också att kvittot skickas till er AI-leverantör och att den som godkänner kontrollerar uppgifterna. Ni bör även informera de anställda i er egen personalinformation.

## Stänga av

Ni kan när som helst stänga av modulen under Företagsinställningar → Moduler, eller välja bort AI för anställdas kvitton eller för inkorgen. Redan sparade förslag och uppgifter ligger kvar i NONOX. Det som redan har skickats till leverantören påverkas inte av att ni stänger av modulen.

<!-- TODO(legal-verify): texten ska granskas av jurist, särskilt rollfördelningen mellan kunden och AI-leverantören och klassningen enligt AI-förordningen (se docs/adr/ADR-018-nonox-ai.md). -->
