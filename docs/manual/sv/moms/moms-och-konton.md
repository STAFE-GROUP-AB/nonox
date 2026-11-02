---
beskrivning: NONOX har inga separata momskoder. Momsen följer kontona enligt BAS, och här ser du vilka konton som blir vilken ruta i momsdeklarationen.
ordning: 30
roller: [owner, admin, accountant]
sokord: [ruta 05, 3990, underlag, momskod, momskoder, momskonton, momsrutor, 2611, 2621, 2631, 2641, 3740, vilande moms, 2618, 2648, bas-konton, momssats, 25 procent, 12 procent, 6 procent]
---

# Hur momsen hamnar i rätt ruta

I en del program väljer man en momskod på varje rad. I NONOX följer momsen i stället kontot: en försäljning med 25 % moms bokför sin moms på 2611, och momsdeklarationen läser 2611 till ruta 10. Fakturor, leverantörsfakturor och dagsrapporter väljer rätt konto åt dig. Bokför du en verifikation för hand är det kontot du väljer som avgör rutan.

![Momsdeklarationen ruta för ruta, med länkar till kontona bakom.](/bilder/manual/moms/momsdeklaration.webp)

## Försäljning och utgående moms

| Momssats | Konto för momsen (fakturor) | Ruta |
|---|---|---|
| 25 % | 2611 | 10 |
| 12 % | 2621 | 11 |
| 6 % | 2631 | 12 |

Momspliktig försäljning på 3000–3099 (utom 3004) och 3914 räknas till **ruta 05**.

Säljer du något momspliktigt på ett annat intäktskonto, till exempel 3990 Övriga ersättningar och intäkter eller ett konto för fakturerade kostnader, räknas det också till ruta 05. NONOX räknar då ut underlaget från momsen på samma verifikation: 250 kr på 2611 betyder 1 000 kr i underlag. Bara så mycket som momsen räcker till tas med, så ett bidrag på samma verifikation hamnar inte i ruta 05.

Finns det utgående moms på försäljning som inget intäktskonto bär underlaget för visar momsdeklarationen en varning med beloppet. Det händer till exempel när du säljer en inventarie: priset bokförs mot tillgångskontot och bara vinsten på ett intäktskonto. Lägg då till underlaget i ruta 05 när du lämnar deklarationen.

> [!NOTE]
> Enligt granskning 2026-11-02, juristgranskning återstår (AUD-MOMS-3, #61).

## Inköp och ingående moms

Ingående moms på 2640–2647 och 2649 räknas till **ruta 48**, Ingående moms att dra av. En vanlig svensk leverantörsfaktura bokför momsen på 2641. Konto 2648 Vilande ingående moms räknas inte med, se nedan.

## Försäljning utan svensk moms

| Konto | Vad | Ruta |
|---|---|---|
| 3108 | Varor till företag i annat EU-land | 35 |
| 3105 | Export av varor utanför EU | 36 |
| 3107 | Mellanmans försäljning vid trepartshandel | 38 |
| 3308 | Tjänster till företag i annat EU-land | 39 |
| 3305 | Tjänster till kund utanför EU | 40 |
| 3231 | Försäljning där köparen är betalningsskyldig i Sverige (byggtjänster) | 41 |
| 3004 | Övrig försäljning | 42 |

Fakturor till kunder i andra länder bokförs på de här kontona när du väljer typ av försäljning på fakturan.

## Inköp där du redovisar momsen

| Konton (25/12/6 %) | Vad | Ruta |
|---|---|---|
| 4515–4517 | Varor från annat EU-land | 20 |
| 4535–4537 | Tjänster från annat EU-land | 21 |
| 4531–4533 | Tjänster från land utanför EU | 22 |
| 4415–4417 | Varor i Sverige, omvänd betalningsskyldighet | 23 |
| 4425–4427 | Tjänster i Sverige, omvänd betalningsskyldighet | 24 |
| 2614 / 2624 / 2634 | Utgående moms på inköpen ovan | 30 / 31 / 32 |

Läs [Inköp från utlandet](/manual/leverantorer/inkop-fran-utlandet).

## Import

| Konton | Vad | Ruta |
|---|---|---|
| 4545–4547 | Beskattningsunderlag vid import | 50 |
| 2615 / 2625 / 2635 | Utgående moms på import 25 / 12 / 6 % | 60 / 61 / 62 |

Import av varor från länder utanför EU görs inte automatiskt från leverantörsfakturor ännu. Bokför du import för hand på kontona ovan kommer den med i rutorna.

## Ruta 49

Ruta 49 räknas fram: utgående moms (rutorna 10–12, 30–32 och 60–62) minus ingående moms (ruta 48).

## Konto 2619 – OSS

Moms på försäljning till privatpersoner i andra EU-länder bokförs på 2619 när den redovisas via OSS. Under tröskelvärdet 99 680 kronor får sådan försäljning svensk moms och bokförs som vanlig försäljning. Den räknas inte in i någon ruta och flyttas inte till 2650 när momsen bokförs, eftersom den redovisas i OSS-deklarationen. Läs [OSS](/manual/moms/oss).

## Vilande moms – 2618, 2628, 2638 och 2648

Moms som ännu inte ska redovisas kan parkeras på ett konto för vilande moms: utgående moms på 2618, 2628 och 2638 (25, 12 och 6 %) och ingående moms på 2648. Det används till exempel när momsen ska redovisas först när kunden betalar eller när fakturan betalas.

Kontona hör inte till någon ruta. Momsdeklarationen tar inte med dem, och när du bokför momsen flyttas de inte till 2650 – saldot ligger kvar. Sidan Moms visar dem under **Momskonton som inte ingår i deklarationen**, så att du ser att det finns vilande moms. När momsen ska redovisas flyttar du beloppet till det vanliga kontot, till exempel från 2618 till 2611, med en verifikation daterad i den perioden. Då kommer det med i den periodens deklaration.

Samma sak gäller andra momskonton mellan 2610 och 2649 som inte hör till någon ruta: de visas som en varning och lämnas orörda.

> [!NOTE]
> Enligt granskning 2026-11-02, juristgranskning återstår (AUD-MOMS-1, #58).

## Bra att veta

- Försäljning räknas som kredit minus debet, inköp och ingående moms som debet minus kredit. En kreditfaktura minskar alltså rutan.
- Ett eget konto inom ett intervall, till exempel 3010, kommer med i samma ruta som BAS-kontona runt det.
- Bokför du moms på ett konto utanför intervallen kommer den inte med i deklarationen. Använd **Visa konton** på momsdeklarationen för att kontrollera.

> [!NOTE]
> Kopplingen mellan konton och rutor följer BAS-kontoplanens momskonton men är ännu inte juridiskt kontrollerad mot Skatteverkets rutdefinitioner. Rutorna 06, 07, 08 och 37 har inga konton ännu. Kontrollera med din redovisningskonsult om företaget har sådana affärer.

## Läs också

- [Momsdeklarationen](/manual/moms/momsdeklaration)
- [Kontoplan](/manual/bokforing/kontoplan)
- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
