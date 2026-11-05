---
beskrivning: Köper du varor från en leverantör i ett annat EU-land kommer fakturan utan svensk moms. Så redovisar du momsen själv i rutorna 20, 30–32 och 48.
ordning: 290
branscher: [retail, cafe, restaurant, hair_beauty]
rutter: [payables.invoices.index, vat.index]
sokord: [eu-inköp, inköp från eu, unionsinternt förvärv, omvänd betalningsskyldighet, omvänd skattskyldighet, reverse charge, ruta 20, ruta 30, ruta 48, momsregistreringsnummer, VIES, 2614, 2645, 4515]
kallor:
  - titel: Skatteverket – Köpa varor från andra EU-länder
    url: https://www.skatteverket.se/foretag/moms/kopavarorochtjanster/inkopfranandraeulander/kopavarorfranandraeulander.4.3a7aab801183dd6bfd380005738.html
  - titel: Skatteverket – Fylla i momsdeklarationen
    url: https://www.skatteverket.se/foretag/moms/deklareramoms/fyllaimomsdeklarationen.4.3a2a542410ab40a421c80004214.html
  - titel: Riksdagen – Mervärdesskattelag (2023:200)
    url: https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/mervardesskattelag-2023200_sfs-2023-200/
  - titel: Riksdagen – Tullag (2016:253)
    url: https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/tullag-2016253_sfs-2016-253/
granskad: 2026-11-05
mansklig_granskning:
legal_verify:
  - Artikeln gäller ett momsregistrerat företag med momspliktig verksamhet. Ett företag utan sådan verksamhet har andra regler och ett tröskelvärde för inköp. Fråga Skatteverket.
---

# Inköp från EU

En butik som köper kläder från en grossist i Tyskland, ett kafé som beställer kaffe från Italien eller en salong som köper produkter från Danmark får ofta en faktura utan svensk moms. Det betyder inte att inköpet är momsfritt. Det betyder att du själv ska räkna fram och redovisa momsen. Med full avdragsrätt kostar det inget, men det ska synas i momsdeklarationen.

## Det här säger reglerna

När en vara skickas till dig i Sverige från en momsregistrerad säljare i ett annat EU-land är det ett unionsinternt förvärv. Det ska beskattas i Sverige (mervärdesskattelagen 3 kap. 1 §, 5 kap. 22 § och 6 kap. 29 §). Enligt Skatteverket kan säljaren sälja utan moms om du har uppgett ett giltigt svenskt momsregistreringsnummer. Det är då du som köpare som redovisar momsen.

I momsdeklarationen redovisar du:

| Ruta | Vad |
|---|---|
| 20 | Värdet av varorna du köpt från ett annat EU-land |
| 30, 31 eller 32 | Utgående moms på inköpet, med 25, 12 eller 6 procent. Använd den sats varan hade haft om du köpt den i Sverige |
| 48 | Samma moms som ingående moms, om inköpet är till din momspliktiga verksamhet |

Momsen ska redovisas för den period då fakturan är utfärdad, men senast för den period som omfattar den 15:e dagen i månaden efter den månad då förvärvet gjordes, vilket normalt är när varorna levererades (mervärdesskattelagen 7 kap. 12 och 26 §§). Avdraget görs för samma period (7 kap. 40 §). Det gäller även om du annars bokför med kontantmetoden.

Är säljaren inte en beskattningsbar person, till exempel en privatperson, ska du enligt Skatteverket inte räkna fram någon svensk moms på inköpet.

## Ett exempel

Butiken Linden köper tröjor från en grossist i ett annat EU-land för 10 000 kronor. Fakturan har ingen moms. Kläder har 25 procent moms i Sverige. Alla belopp är påhittade.

| Konto | Debet | Kredit | Ruta |
|---|---:|---:|---|
| 4515 Inköp av råvaror och material från annat EU-land, 25 % | 10 000 | | 20 |
| 2645 Beräknad ingående moms på förvärv från utlandet | 2 500 | | 48 |
| 2614 Utgående moms omvänd skattskyldighet, 25 % | | 2 500 | 30 |
| 2440 Leverantörsskulder | | 10 000 | |

Den utgående och den ingående momsen tar ut varandra, så du betalar inget extra. Men båda ska synas.

## Så här gör du

1. Ge leverantören ditt momsregistreringsnummer och kontrollera deras i VIES. Läs [Leverantörsregistret](/manual/leverantorer/leverantorsregistret) och [VIES](/manual/moms/vies).
2. Registrera fakturan i NONOX och välj **Varor från ett annat EU-land** under **Vem redovisar momsen?**. NONOX bokför momsen åt dig enligt exemplet ovan. Läs [Inköp från utlandet](/manual/leverantorer/inkop-fran-utlandet).
3. Välj den momssats varan hade haft i Sverige, till exempel 6 procent för livsmedel 2026.
4. Kontrollera rutorna 20, 30–32 och 48 innan du skickar momsdeklarationen. Läs [Momsdeklarationen](/manual/moms/momsdeklaration).

## Vanliga misstag

- **Att tro att fakturan är momsfri.** Momsen ska redovisas av dig.
- **Fel sats.** Använd den svenska satsen för varan, inte leverantörens lands sats.
- **Att blanda ihop varor och tjänster.** Tjänster från ett annat EU-land redovisas i ruta 21, inte i ruta 20.
- **Fel period med kontantmetoden.** EU-inköp av varor redovisas efter fakturans datum, inte när du betalar.

## Bra att veta

Varor från länder utanför EU är import. Är du momsregistrerad tas importmomsen inte ut av Tullverket. Du redovisar den själv i momsdeklarationen till Skatteverket: underlaget i ruta 50, momsen i ruta 60, 61 eller 62, och avdraget i ruta 48 (tullagen 2 kap. 2 §). Momsen hör till den period då Tullverket ställde ut tullräkningen eller tullkvittot (mervärdesskattelagen 7 kap. 27 §). Import beskrivs inte närmare här.
