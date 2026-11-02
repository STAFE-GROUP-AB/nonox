---
beskrivning: Huvudboken visar varje rad på varje konto med löpande saldo. Klicka på ett konto i vilken rapport som helst för att få kontoanalysen för just det kontot.
ordning: 40
roller: [owner, admin, accountant, bookkeeper, auditor]
sokord: [huvudbok, kontoanalys, kontoutdrag, kontospecifikation, transaktioner på konto, löpande saldo, specifikation]
---

# Kontoanalys och huvudbok

[Huvudboken](/manual/ordlista/ordlista#huvudbok) visar allt som har hänt på kontona, rad för rad, med saldot efter varje rad. Kontoanalysen är huvudboken för ett enda konto. Det är här du tar reda på *varför* ett konto har det saldo det har – vilka verifikationer som bygger upp beloppet, och var en felbokning finns.

![Kontoanalysen för konto 1930 med varje transaktion och löpande saldo.](/bilder/manual/rapporter/kontoanalys-och-huvudbok.webp)

## Från vilken rapport som helst till kontoanalysen

Varje konto i rapporterna är en länk. Välj kontot i **Saldobalans**, **Resultatrapport**, **Balansrapport**, **Huvudbok** eller **Verifikationslista**, så öppnas kontoanalysen för det kontot med:

- samma räkenskapsår,
- samma period (**Från och med** och **Till och med**),
- samma kostnadsställe och projekt, om du har valt något.

Siffran du klickade på är alltså exakt summan av raderna du ser. Från kontoanalysen går du vidare till varje verifikation genom att välja dess nummer, och därifrån till underlaget.

Även kontona på en verifikations sida är länkar. De öppnar kontot i huvudboken för hela det räkenskapsår verifikationen tillhör.

> [!TIP]
> Så här hittar du en konstig siffra snabbt: öppna resultatrapporten för månaden, välj kontot som ser fel ut, och läs raderna i kontoanalysen. Den rad som inte hör hemma där brukar synas direkt.

## Så här tar du fram en kontoanalys för hand

1. Gå till **Bokföring → Rapporter** och välj **Huvudbok**.
2. Skriv kontonumret i **Bara ett konto (frivilligt)**, till exempel `1930`.
3. Välj år och period.

Rubriken blir **Kontoanalys för konto 1930**. Vill du se alla konton igen väljer du **Visa alla konton**.

## Så läser du huvudboken och kontoanalysen

För varje konto finns:

- **Ingående saldo** – saldot när perioden börjar.
- En rad per bokförd rad på kontot, i datumordning, med **Datum**, **Ver** (verifikationens nummer, klickbart), **Text**, **Debet**, **Kredit** och **Saldo** efter raden.
- **Utgående saldo** – saldot när perioden slutar.

Som **Text** visas radens egen text om den har en, annars verifikationens text. **Saldo** räknas som debet minus kredit, så ett konto med saldo i kredit – en skuld, en intäkt – visas med minustecken.

Utan valt konto visar huvudboken alla konton som har ett saldo eller har rört sig under perioden, ett efter ett. Kontonas namn är då länkar till respektive kontoanalys.

## Exempel: stäm av företagskontot

Exempelbolaget AB vill kontrollera att bokföringen stämmer med banken för mars.

1. Öppna **Saldobalans** med **Till och med** satt till 31 mars.
2. Välj 1930 Företagskonto/checkkonto/affärskonto.
3. Kontoanalysen visar varje in- och utbetalning i mars. Jämför **Utgående saldo** med kontoutdraget och raderna med bankens transaktioner.

För en löpande avstämning med bankens saldo finns också [Bankavstämning](/manual/bank/bankavstamning).

## Bra att veta

- Bara bokförda verifikationer visas, inte utkast.
- Med ett kostnadsställe eller projekt valt visas bara rader med det kostnadsstället eller projektet, och ingående saldo räknas som noll.
- Står det **Konto … finns inte i kontoplanen.** har du skrivit ett nummer som inte finns. Kontrollera numret under **Bokföring → Kontoplan**.
- Kontoanalysen kan laddas ner som CSV och skrivas ut som PDF precis som de andra rapporterna. Se [Skriv ut och exportera](/manual/rapporter/skriv-ut-och-exportera).
- När du bokför en ny verifikation ser du kontots saldo direkt i verifikationen, i kolumnen **Kontosaldo**. Se [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation).

## Läs också

- [Saldobalansen](/manual/rapporter/saldobalans)
- [Verifikationslistan](/manual/rapporter/verifikationslista)
- [Filter och jämförelser](/manual/rapporter/filter-och-jamforelse)
- [Hitta och läsa verifikationer](/manual/bokforing/verifikationer)
