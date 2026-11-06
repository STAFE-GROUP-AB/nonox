---
beskrivning: Registrera inköp av varor och tjänster från andra EU-länder, tjänster från länder utanför EU och inköp med omvänd betalningsskyldighet i Sverige, så att momsen hamnar i rätt rutor.
ordning: 80
roller: [owner, admin, accountant, bookkeeper]
sokord: [eu-inköp, omvänd betalningsskyldighet, omvänd skattskyldighet, reverse charge, byggmoms, utländsk leverantör, förvärvsmoms, ruta 20, ruta 21, ruta 22, ruta 30, 2645, 2647]
---

# Inköp från utlandet och omvänd betalningsskyldighet

Ibland är det du som köpare, inte leverantören, som ska redovisa momsen. Det gäller till exempel när du köper varor eller tjänster från ett företag i ett annat EU-land, tjänster från ett land utanför EU, och vissa inköp i Sverige med omvänd betalningsskyldighet, som byggtjänster. Fakturan har då ingen svensk moms, och du räknar fram och redovisar momsen själv.

NONOX gör det åt dig när du väljer rätt alternativ under **Vem redovisar momsen?** på fakturan.

![Ett inköp av tjänster från ett företag i ett annat EU-land, där du själv redovisar momsen.](/bilder/manual/leverantorer/utlandsk-valuta.webp)

## Alternativen

| Välj | Används för |
|---|---|
| **Svensk faktura med moms** | Den vanliga fakturan där leverantören tar ut moms. |
| **Varor från ett annat EU-land** | Varor från ett momsregistrerat företag i ett annat EU-land. |
| **Tjänster från ett annat EU-land** | Tjänster från ett företag i ett annat EU-land. |
| **Tjänster från ett land utanför EU** | Till exempel en programvarutjänst från ett företag utanför EU. |
| **Varor från ett land utanför EU (import)** | Varor från till exempel Kina eller USA. Fakturan bokförs utan moms; momsen bokför du från Tullverkets tullräkning. Läs [Importmoms från tullräkning](/manual/moms/importmoms). |
| **Varor i Sverige, omvänd betalningsskyldighet** | Svenska inköp av varor där köparen ska redovisa momsen. |
| **Tjänster i Sverige, omvänd betalningsskyldighet (till exempel bygg)** | Till exempel byggtjänster från en underentreprenör. |

Vilket alternativ som gäller för ett visst inköp avgör momsreglerna, inte NONOX. Är du osäker, fråga din redovisningskonsult eller läs Skatteverkets vägledning.

## Så här gör du

1. Registrera fakturan som vanligt: **Inköp → Leverantörsfakturor → Ny leverantörsfaktura**.
2. Under **Vem redovisar momsen?**, välj ett av alternativen ovan.
3. Fyll i **Att betala, inkl. moms** – det fakturan säger, alltså utan svensk moms. Fältet **Varav moms** försvinner.
4. Under **Kontering** väljer du i stället för konto en **Momssats** per rad: 25 %, 12 % eller 6 %. Använd den sats som hade gällt om inköpet gjorts med svensk moms.
5. Under **Moms du redovisar själv** visas momsen NONOX räknat fram.
6. Registrera fakturan.

> [!TIP]
> Kontrollera leverantörens momsregistreringsnummer i VIES innan du köper med omvänd betalningsskyldighet från ett annat EU-land. Läs [Leverantörsregistret](/manual/leverantorer/leverantorsregistret).

## Det här händer i bokföringen

Kostnaden bokförs på det BAS-konto som hör till inköpstypen och momssatsen, så att beloppet hamnar i rätt ruta i momsdeklarationen. Momsen bokförs både som utgående och ingående moms. Med full avdragsrätt kostar den alltså inget, men den syns i deklarationen.

| Inköp | Kostnadskonto (25/12/6 %) | Ruta |
|---|---|---|
| Varor från annat EU-land | 4515 / 4516 / 4517 | 20 |
| Tjänster från annat EU-land | 4535 / 4536 / 4537 | 21 |
| Tjänster utanför EU | 4531 / 4532 / 4533 | 22 |
| Varor i Sverige, omvänd | 4415 / 4416 / 4417 | 23 |
| Tjänster i Sverige, omvänd | 4425 / 4426 / 4427 | 24 |

Momsen:

- **Utgående moms** i kredit på 2614 (25 %), 2624 (12 %) eller 2634 (6 %) – rutorna 30, 31 och 32.
- **Ingående moms** i debet på 2645 Beräknad ingående moms på förvärv från utlandet för inköp från utlandet, och på 2647 för omvänd betalningsskyldighet i Sverige – ruta 48.
- **Skulden** till leverantören, beloppet på fakturan, i kredit på 2440.

Saknas något av kontona i kontoplanen läggs det till med sitt BAS-namn. Verifikationen blir som vanligt en verifikation i serie D (fakturametoden) eller bokförs vid betalning (kontantmetoden).

Undantag: **varor från ett annat EU-land** bokförs alltid när fakturan registreras, även med kontantmetoden. Momsen på sådana inköp ska redovisas för den månad fakturan är utfärdad (mervärdesskattelagen 7 kap. 26 och 40 §§). När du betalar flyttas bara beloppet från 2440 till banken.

## Det här går inte än

- **Anläggningstillgång från ett sådant inköp.** Raderna bokförs på inköpskonton, så formuläret föreslår ingen tillgång i anläggningsregistret.

## Vanliga frågor

**Fakturan är i euro.**
Välj valuta på fakturan; det fungerar tillsammans med alla alternativen ovan. Läs [Fakturor i utländsk valuta](/manual/leverantorer/utlandsk-valuta).

**Fakturan har både varor och tjänster.**
Registrera fakturan med det alternativ som gäller det mesta, eller dela upp den på två registreringar om beloppen ska till olika rutor. Fråga din redovisningskonsult om du är osäker.

**Hur ser jag att det blev rätt?**
Öppna momsdeklarationen för perioden och klicka på **Visa konton** vid rutan. Läs [Momsdeklarationen](/manual/moms/momsdeklaration).

## Läs också

- [Omvänd betalningsskyldighet](/manual/moms/omvand-betalningsskyldighet)
- [Importmoms från tullräkning](/manual/moms/importmoms)
- [Hur momsen hamnar i rätt ruta](/manual/moms/moms-och-konton)
- [Kontrollera momsnummer i VIES](/manual/moms/vies)
