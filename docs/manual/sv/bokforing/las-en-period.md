---
beskrivning: Lås bokföringen till och med ett datum, till exempel när momsen är deklarerad, så att inget kan bokföras i perioden i efterhand.
ordning: 130
roller: [owner, admin, accountant]
sokord: [låsa period, periodlåsning, stänga månad, låst period, lås bokföringen, spärra period]
---

# Lås en period

När en period är klar – momsen är deklarerad, månaden är avstämd – låser du den. Då kan inget längre bokföras med ett datum i perioden, varken för hand eller automatiskt från fakturor, betalningar eller lön. Siffrorna du har lämnat till Skatteverket står alltså kvar som de var.

I NONOX låser du bokföringen *till och med ett datum*. Allt på eller före datumet är låst, allt efter är öppet.

![Under momsdeklarationen kan perioden låsas när momsen är bokförd.](/bilder/manual/bokforing/las-en-period.webp)

## Så här låser du en period

Du låser från momsdeklarationen, där perioden redan är vald:

1. Gå till **Bokföring → Moms**.
2. Välj räkenskapsår och den period som är klar.
3. Längst ned, under **Lås perioden**, välj **Lås till och med …** med periodens sista dag.
4. Välj **Ja, lås perioden**.

Du får beskedet **Bokföringen är låst till och med …**, och samma text står sedan på sidan.

> [!WARNING]
> En låsning går inte att låsa upp. Låsningsdatumet kan bara flyttas framåt, aldrig bakåt.

När ett räkenskapsår stängs under **Bokföring → Bokslut** låses bokföringen dessutom automatiskt till och med årets sista dag. Se [Bokslut och årsredovisning](/manual/bokslut).

## Det här händer i bokföringen

Allt som bokförs i NONOX kontrolleras mot låsningen i samma ögonblick som det bokförs:

- en verifikation du bokför för hand,
- kund- och leverantörsfakturor, inbetalningar och utbetalningar,
- lön, moms, periodiseringar och avskrivningar,
- en [rättelse](/manual/bokforing/ratta-en-verifikation),
- verifikationer från en [SIE-fil](/manual/bokforing/sie).

Har något ett datum i den låsta perioden stoppas det med **Perioden till och med … är låst. Välj ett senare datum.** Ingenting bokförs. Ett utkast kan fortfarande sparas, men inte bokföras förrän det har fått ett senare datum.

Låsningen skrivs i [behandlingshistoriken](/manual/bokforing/behandlingshistorik) med datumet före och efter.

## Vanliga frågor

**Jag hittade ett fel i en låst period. Vad gör jag?**
Rätta det med en verifikation som har ett datum efter den låsta perioden, till exempel i nästa månad. Har felet påverkat en momsdeklaration som redan är inlämnad behöver den också rättas hos Skatteverket; fråga din redovisningskonsult hur.

**En faktura kom in sent och hör till en låst månad.**
Bokför den med ett datum i den öppna perioden. Vilket datum som är rätt bokföringsmässigt är en fråga för din redovisningskonsult.

**Vårt företag är inte momsregistrerat. Hur låser vi?**
Låsningen görs i dag från momsdeklarationen eller när räkenskapsåret stängs. Ett företag som inte är momsregistrerat låser alltså när året stängs under **Bokföring → Bokslut**.

**Vem får låsa?**
Ägare, administratörer och redovisningskonsulter. Bokförare kan inte låsa perioder.

## Läs också

- [Momsdeklarationen](/manual/moms/momsdeklaration)
- [Räkenskapsår](/manual/bokforing/rakenskapsar)
- [Rätta en verifikation](/manual/bokforing/ratta-en-verifikation)
- [Bokslut och årsredovisning](/manual/bokslut)
