---
beskrivning: Vad serierna A, B, C, D, E, I, L och M betyder, vilken som används när, och hur den obrutna numreringen fungerar.
ordning: 90
rutter: [accounting.voucherSeries]
sokord: [serie, verifikationsserie, verifikationsnummer, löpnummer, numrering, nummerserie]
---

# Verifikationsserier

Varje bokförd verifikation får ett nummer i en serie: A1, A2, A3 och så vidare. Serien visar varifrån verifikationen kommer – en manuell verifikation, en kundfaktura, en löneutbetalning. Du ser företagets serier under **Bokföring → Verifikationsserier**.

![Verifikationsserierna, från A Manuell till M Moms.](/bilder/manual/bokforing/verifikationsserier.webp)

## Serierna och vad som bokförs i dem

Varje företag får de här serierna när det skapas:

| Serie | Namn | Vad som hamnar här |
|---|---|---|
| **A** | Manuell | Det du bokför själv under **Verifikationer**, och dessutom periodiseringar, avskrivningar och bokslutets verifikationer. Märkt **Används för manuella verifikationer**. |
| **B** | Kundfakturor | Kundfakturor och kreditfakturor när de bokförs. |
| **C** | Kundinbetalningar | Inbetalningar från kunder. |
| **D** | Leverantörsfakturor | Registrerade leverantörsfakturor. |
| **E** | Leverantörsbetalningar | Betalningar av leverantörsfakturor. |
| **I** | Ingående balanser/import | Avsedd för ingående balanser och import. |
| **L** | Lön | Lönekörningar och utlägg, när lönemodulen används. |
| **M** | Moms | Momsens avräkning när du bokför momsen för en period. |

En [rättelse](/manual/bokforing/ratta-en-verifikation) bokförs alltid i samma serie som den verifikation den rättar.

## Så fungerar numreringen

- Numret sätts när verifikationen **bokförs**, inte när den skapas. Ett utkast har inget nummer.
- Numreringen börjar om på 1 i varje serie för varje räkenskapsår.
- Den är obruten: inga hål och inga dubbletter. Två personer som bokför samtidigt kan aldrig få samma nummer, och eftersom en bokförd verifikation aldrig tas bort uppstår inga hål i efterhand.
- Verifikationens fullständiga namn är serie och nummer, till exempel A12 eller B7.

Bokföringslagen kräver en obruten numrering inom varje serie, och den nattliga [kontrollen av bokföringen](/manual/bokforing/kontroll-av-bokforingen) kontrollerar den.

## Välj serie när du bokför

I en ny verifikation väljer du serie under **Serie**. Den står på **A – Manuell** från början, och det är rätt för nästan allt du bokför för hand. Välj en annan serie bara om du har en tydlig anledning, till exempel om din redovisningskonsult vill ha en viss sorts verifikationer samlade.

## Serier från en SIE-fil

När du [läser in en SIE-fil](/manual/bokforing/sie) behåller verifikationerna sina serier. Finns en serie i filen som inte finns här skapas den, och rapporten före inläsningen säger det med **serien skapas**. Den syns sedan i listan här.

## Vanliga frågor

**Kan jag lägga till eller byta namn på en serie?**
Inte i appen. Serierna skapas när företaget skapas, och nya serier kan bara komma till genom en SIE-inläsning.

**Varför fick min verifikation nummer 1 fast jag har bokfört mycket?**
Den ligger i ett nytt räkenskapsår eller i en serie som inte har använts förut i året. Numreringen räknas per serie och år.

**Jag tog bort ett utkast. Blir det ett hål i numreringen?**
Nej. Utkast har inga nummer, så inget hål uppstår.

## Läs också

- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
- [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation)
- [Kontroll av bokföringen](/manual/bokforing/kontroll-av-bokforingen)
