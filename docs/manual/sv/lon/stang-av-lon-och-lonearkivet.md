---
beskrivning: Vad som händer när ni stänger av lönemodulen, vad som hindrar det och hur ni når lönebesked, arbetsgivardeklarationer och rapporter i Lönearkivet.
ordning: 180
roller: [owner, admin, auditor, payroll_admin]
modul: payroll
rutter: [payroll.archive, payroll.archive.reports]
sokord: [stänga av lön, avaktivera lön, lönearkiv, gamla lönebesked, sluta med lön, inga anställda längre]
---

# Stäng av lönemodulen och Lönearkivet

Har företaget inte längre några anställda kan ni stänga av lönemodulen. Ingenting tas bort: lönekörningar, lönebesked, arbetsgivardeklarationer och rapporter finns kvar i **Lönearkivet** så länge bokföringslagen kräver det, och lönens verifikationer finns kvar i bokföringen som vanligt.

![En lönekörning som är beräknad och väntar på attest.](/bilder/manual/lon/lonekorning-pagaende.webp)

## Det här hindrar att modulen stängs av

Modulen kan inte stängas av så länge något är halvfärdigt:

- En lönekörning är attesterad men inte bokförd.
- En månad med utbetald lön saknar inlämnad arbetsgivardeklaration.
- Ett godkänt utlägg som en lönekörning har tagit med är inte utbetalt.

NONOX visar vad som återstår. Gör klart det och försök igen. Lönekörningar som är utkast hindrar inte; de finns kvar som de är.

## Så här stänger du av Lön

1. Öppna **Företagsinställningar → Moduler**.
2. Välj **Stäng av** på kortet **Lön**.
3. Läs varningarna och skriv **Lön** för att bekräfta.

Varningarna berättar vad som händer:

- Anställda som bara har rollen Anställd kommer inte längre åt företaget. De får beskedet att Min sida är stängd.
- Semestersaldon och semesterlöneskuld finns kvar i bokföringen.
- Lönekörningar, lönebesked, arbetsgivardeklarationer och rapporter finns kvar i Lönearkivet för den som har behörighet.

> [!NOTE]
> Har modulen Avtal varit på måste den stängas av först, eftersom den bygger på Lön.

## Lönearkivet

När Lön är avstängd visas **Lönearkiv** i menyn för den som har behörighet: ägaren, revisorn, redovisningskonsulten, administratören och löneadministratören. Menyn visas bara om företaget har attesterad lön att visa.

- **Lönearkiv → Körningar och deklarationer**: alla attesterade lönekörningar med lönebesked som PDF, lönebesked från ett tidigare lönesystem och arbetsgivardeklarationernas filer.
- **Lönearkiv → Rapporter**: de sammanställda lönerapporterna.

Allt i arkivet är bara att läsa. Inget kan ändras eller läggas till.

## Slå på Lön igen

Slår ni på modulen igen kommer allt tillbaka som det var: anställda, inställningar, regler och lönekörningar. Inget skrivs över.

## Vanliga frågor

**En tidigare anställd behöver ett gammalt lönebesked.**
Öppna **Lönearkiv → Körningar och deklarationer**, leta upp lönekörningen och hämta lönebeskedet som PDF.

**Hur länge finns uppgifterna kvar?**
Så länge bokföringslagen kräver att räkenskapsinformationen sparas. Läs mer om arkivering under [Arkivpaket](/manual/bokslut/arkivpaket).

## Läs också

- [Moduler](/manual/installningar/moduler)
- [Min sida för de anställda](/manual/lon/min-sida-for-anstallda)
- [Rapporter och löneårets slut](/manual/lon/rapporter-och-lonearsskifte)
- [Arkivpaket](/manual/bokslut/arkivpaket)
