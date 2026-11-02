---
beskrivning: Låt kassasystemet mejla dagsrapporten (Z-rapporten) till inkorgen. Koppla beloppen till konton en gång, så bokförs nästa rapport från samma kassa utan AI.
ordning: 130
roller: [owner, admin, accountant, bookkeeper]
modul: inbox
sokord: [dagsrapport, z-rapport, kassarapport, kassasystem, dagskassa, försäljning per dag, kortbetalningar, swish, dricks, presentkort, restaurang, butik]
---

# Dagsrapporter från kassasystemet

Har du en butik, ett café eller en restaurang skickar kassasystemet en dagsrapport (Z-rapport) varje dag. Låt kassan mejla den till inkorgen, så blir den en färdig verifikation som du godkänner. Första gången kopplar du rapportens rader till konton; därefter känner NONOX igen rapporten och fyller i allt själv, utan AI.

![En dagsrapport från kassan, tolkad med en sparad mall.](/bilder/manual/leverantorer/inkorgen.webp)

## Vad NONOX kan läsa

- CSV-filer, oavsett avgränsare och decimaltecken,
- Excel-filer (XLSX),
- rapporter som vanlig text,
- PDF-filer med text i. En inskannad PDF utan text kan bara läsas med NONOX AI.

## Så här kommer du igång

1. Lägg in kassasystemets avsändaradress under **Godkända avsändare** i inkorgens inställningar. Läs [Inkorgens adress och godkända avsändare](/manual/leverantorer/inkorgens-installningar).
2. Ställ in kassasystemet så att det mejlar dagsrapporten till företagets adress, gärna med tillägget `+kassa`.
3. När den första rapporten kommer, öppna den under **Inkorg → Att granska**.

## Så här kopplar du rapporten första gången

Rapporten visas som en tabell med **På rapporten** och **Belopp**, och för varje rad väljer du:

1. **Vad det är** – till exempel **Försäljning exkl. moms**, **Försäljning inkl. moms**, **Utgående moms**, **Kort**, **Swish**, **Kontant**, **Presentkort**, **Annat betalsätt**, **Dricks**, **Öresavrundning** eller **Bokförs inte** för summor och rader som inte ska bokföras.
2. **Konto** – kontot i er kontoplan.
3. **Moms %** – för försäljning och moms: 25, 12, 6 eller 0.
4. **D/K** – om beloppet ska i debet eller kredit.

Kontrollera **Rapportens datum**. Under tabellen visas **Verifikationen som bokförs** med summa per momssats.

Har företaget NONOX AI påslaget och tillåtet i inkorgen föreslår AI:n vad varje belopp är och vilket konto det hör till, med en säkerhet i procent. Du kontrollerar förslaget på samma sätt.

När allt stämmer, klicka på **Godkänn och bokför**.

## Nästa rapport

När du godkänner sparas kopplingen som en mall för just den avsändaren och rapportens uppställning. Nästa rapport i samma format kopplas med mallen, utan AI, och hamnar under **Utkast klara**. Du behöver bara kontrollera och godkänna.

Litar du på mallen kan du slå på **Bokför automatiskt** för den. Läs [Inkorgens adress och godkända avsändare](/manual/leverantorer/inkorgens-installningar).

Ändrar kassasystemet sin rapport – nya rader eller andra rubriker – känns den inte igen, och du kopplar den igen. Den nya kopplingen sparas som en ny mall.

## Kontrollerna

Verifikationen byggs alltid av NONOX ur rapportens belopp och kopplingen, aldrig direkt av AI:n. Innan förslaget räknas som klart kontrolleras att:

- debet och kredit är lika – annars står det **Debet och kredit är inte lika. Kontrollera kopplingen.**,
- momsen per momssats stämmer med försäljningen med den satsen,
- varje rad har ett konto som finns i kontoplanen.

## Det här händer i bokföringen

Dagsrapporten blir en verifikation på rapportens datum. Vilka konton försäljning, moms, betalsätt, dricks och öresavrundning bokförs på är företagets eget val i kopplingen. Rapporten och originalmejlet blir underlag till verifikationen.

> [!IMPORTANT]
> Hur dricks som betalats med kort, presentkort och öresavrundning på en dagsrapport ska bokföras är inte kontrollerat av en redovisningskonsult i NONOX. NONOX föreslår bara; bestäm kontona tillsammans med din redovisningskonsult första gången.

## Vanliga frågor

**Kan jag bokföra en dagsrapport utan att ha NONOX AI?**
Ja. Första rapporten kopplar du själv, och därefter läses rapporterna med mallen. AI behövs bara för inskannade rapporter och för att föreslå kopplingen första gången.

**Kassasystemet kan inte mejla.**
Mejla rapporten själv till företagets adress från en adress som står på listan över godkända avsändare. Färdiga kopplingar direkt från kassasystem är på väg.

## Läs också

- [Inkorgen](/manual/leverantorer/inkorgen)
- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
- [Kontoplan](/manual/bokforing/kontoplan)
