---
beskrivning: Koncernens resultaträkning och balansräkning från en sparad konsolidering, med elimineringarna konto för konto, som CSV och PDF. Ett internt underlag, inte en koncernredovisning.
ordning: 84
roller: [owner, admin, accountant, bookkeeper, auditor]
rutter: [group.reports]
sokord: [koncern, koncernrapport, koncernrapporter, koncernens resultaträkning, koncernens balansräkning, intern koncernrapport, eliminering, csv, pdf, exportera, skriv ut]
---

# Koncernrapporter

På **Mina företag → Koncernrapporter** visar du en intern sammanställd resultaträkning och balansräkning för koncernen från en sparad körning i körloggen, och hämtar dem som CSV eller PDF. Rapporterna räknar ingenting nytt: de visar exakt det som [konsolideringen](/manual/rapporter/konsolidering) räknade fram, med de [elimineringar](/manual/rapporter/elimineringar) som körningen drog.

![Koncernrapporter med resultaträkningen i tre kolumner: Företagen, Elimineringar och Koncernen.](/bilder/manual/rapporter/koncernrapporter.webp)

> [!IMPORTANT]
> Sidan, utskriften, CSV-filen och PDF-filen säger alla **Intern koncernrapport, inte en koncernredovisning enligt ÅRL**. I PDF-filen står det på varje sida, i CSV-filen på första och sista raden, och filnamnen börjar med `intern-koncernrapport`. NONOX gör ingen förvärvsanalys och räknar inte fram goodwill, minoritetsandelar, internvinster eller uppskjuten skatt, och rapporterna har ingen kassaflödesanalys, inga noter och ingen förvaltningsberättelse. Det du själv för in som eliminering i koncernjournalen kommer med. Rapporterna är ett internt underlag, och ingenting bokförs i något av företagen. Kontrollera med din redovisningskonsult eller revisor om koncernen behöver en koncernredovisning.

## Så här visar du en rapport

1. Gå till **Mina företag → Koncernrapporter**.
2. Välj **Koncern** om du ser fler än en.
3. Välj **Körning**. Förvald är den senaste körningen. Listan visar de 20 senaste med nummer, period och när de kördes.

Finns ingen körning ännu står det **Ingen körning ännu**. Gör då en körning under **Konsolidering** först; **Till Konsolidering** tar dig dit.

Rapporterna visar körningen som den var när den gjordes. Har någon bokfört eller godkänt en eliminering sedan dess, kör du en ny konsolidering för att få med det.

## Vad kolumnerna betyder

Får du se rapporter i alla företag som körningen läste, visar varje konto tre kolumner:

- **Företagen** är företagens egen bokföring för de dagar de ingår i koncernen, summerad efter kontomappningen.
- **Elimineringar** är raderna i koncernjournalen som körningen drog för samma period.
- **Koncernen** är summan av de två, och det är körningens siffror.

Ett konto som är helt eliminerat, till exempel en fordran på ett annat företag i koncernen, visas med beloppet under **Företagen**, samma belopp med motsatt tecken under **Elimineringar** och noll under **Koncernen**.

Under rubriken **Elimineringar per slag** står hur mycket som är eliminerat per slag, till exempel **Försäljning och inköp**, efter återföringar.

Överst står körningens nummer, när den gjordes, av vem och en **Kontrollsumma**. **Preliminär** betyder att minst ett av företagen inte har låst perioden eller stängt räkenskapsåret, så att siffrorna kan ändras. **Låsta perioder** betyder att alla har låst.

## Vem som ser vad

Vad du ser avgörs för varje körning av de företag som **körningen läste**, inte av vilka företag som ingår i koncernen i dag.

- Får du se rapporter i **alla** företag som körningen läste, ser du de tre kolumnerna.
- Har du fått rollen att läsa koncernens sammanställning och är medlem i moderföretaget, ser du bara **Koncernen**, vilka företag som ingår och elimineringarna per slag. För ett slag som bara gäller ett par av företagen visas antalet poster i stället för beloppet. Ett konto som är eliminerat till noll visas inte. En körning visas bara när minst två företag ingår under hela perioden med sin balansräkning.
- Har körningen läst ett företag som du inte får se rapporter i, till exempel ett företag som har lämnat koncernen sedan dess, och du inte har rollen, visas körningen inte alls. Sidan säger **Körningen visas inte**, och filerna går inte att hämta.
- Alla andra ser inte koncernen. Sidan säger **Du ser ingen koncern**.

## Hämta och skriv ut

- **Ladda ner CSV** ger en fil som Excel öppnar direkt. Första och sista raden är märkningen. Sedan följer koncernen, perioden, körningen, status, företagen som ingår och kontrollsummorna för indata och resultat, och därefter resultaträkningen, balansräkningen och elimineringarna per slag med samma kolumner som du ser på sidan.
- **Ladda ner PDF** ger samma innehåll som en PDF-fil i A4, med märkningen överst och längst ner på varje sida.
- **Skriv ut** skriver ut sidan utan menyer och val. Märkningen följer med.

## Om kontrollsummorna inte stämmer

Står det **Körningen eller koncernjournalen stämmer inte med sina kontrollsummor. Använd inte siffrorna och kontakta den som driver NONOX.** har något som är sparat ändrats utanför NONOX, eller så räknar rapporten fel. Det ska aldrig hända. Använd inte siffrorna och kontakta den som driver NONOX.

## Bra att veta

- Beloppen summeras konto för konto över företagen, som är olika juridiska personer. En skattefordran i ett företag kan därför minska en skatteskuld i ett annat. I företagens egna redovisningar kvittas sådana belopp inte.
- Att hämta en fil ändrar ingenting och skrivs inte i behandlingshistoriken, precis som när du hämtar ett företags egna rapporter.

<!-- TODO(legal-verify): ÅRL 7 kap. 1–3 §§ duty and small-group exemption; no thresholds stated until verified. -->

## Läs också

- [Konsolidering](/manual/rapporter/konsolidering)
- [Elimineringar](/manual/rapporter/elimineringar)
- [Koncernstruktur](/manual/rapporter/koncernstruktur)
