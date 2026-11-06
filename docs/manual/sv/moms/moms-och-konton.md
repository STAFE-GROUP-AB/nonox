---
beskrivning: NONOX har inga separata momskoder. Momsen följer kontona enligt BAS, och här ser du vilka konton som blir vilken ruta i momsdeklarationen.
ordning: 30
roller: [owner, admin, accountant]
sokord: [ruta 05, ruta 06, ruta 07, ruta 08, ruta 37, uttag, vinstmarginalbeskattning, VMB, trepartshandel, 3990, underlag, momskod, momskoder, momskonton, momsrutor, 2611, 2621, 2631, 2641, 3740, vilande moms, 2618, 2648, bas-konton, momssats, 25 procent, 12 procent, 6 procent]
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

### Försäljning utan moms på ett momskonto

En försäljning kan inte vara momspliktig och samtidigt sakna moms. Bokförs försäljning på 3001–3003 eller ett annat konto för ruta 05 på en verifikation som inte har någon utgående moms alls, räknas den till **ruta 42** som momsfri försäljning. Det gäller till exempel en fakturarad med 0 % moms på konto 3001. Omföringar mellan intäktskonton, till exempel från 3001 till 3990, räknas fortfarande efter kontot.

När du bokför för hand, lägger upp en artikel eller skriver en faktura varnar NONOX om kontot och momssatsen inte passar ihop.

> [!NOTE]
> Enligt granskning 2026-11-02, juristgranskning återstår (AUD-MOMS-4, #62).

## Uttag, vinstmarginal och uthyrning – ruta 06, 07 och 08

| Konton | Vad | Ruta |
|---|---|---|
| 3401–3403 | Egna uttag momspliktiga 25 / 12 / 6 %, moms på 2612 / 2622 / 2632 | 06 |
| 2616 / 2626 / 2636 | Utgående moms VMB 25 / 12 / 6 % | 07 |
| 3913 | Frivilligt momspliktiga hyresintäkter, moms på 2613 | 08 |

- **Ruta 06** är värdet av uttagen utan moms, till exempel varor ägaren tar ut ur företaget.
- **Ruta 07** är beskattningsunderlaget vid vinstmarginalbeskattning, alltså marginalen utan moms, inte försäljningspriset. NONOX räknar fram det ur momsen på 2616, 2626 och 2636: 800 kr i moms på 2616 ger 3 200 kr i ruta 07. Försäljningen på 3211 och 3212 räknas inte till ruta 05.
- **Ruta 08** är hyran utan moms för lokaler där du valt frivillig beskattning. Övriga momspliktiga hyresintäkter på 3914 räknas till ruta 05.

Momsen på kontona räknas som vanligt till ruta 10, 11 och 12.

> [!NOTE]
> Enligt granskning 2026-11-02, juristgranskning återstår (AUD-MOMS-7, #64).

## Inköp och ingående moms

Ingående moms på 2640–2647 och 2649 räknas till **ruta 48**, Ingående moms att dra av. En vanlig svensk leverantörsfaktura bokför momsen på 2641. Konto 2648 Vilande ingående moms räknas inte med, se nedan.

## Försäljning utan svensk moms

| Konto | Vad | Ruta |
|---|---|---|
| 3108 | Varor till företag i annat EU-land | 35 |
| 3105 | Export av varor utanför EU | 36 |
| 3107, kopplat till ruta 38 | Mellanmans försäljning vid trepartshandel | 38 |
| Eget konto | Mellanmans inköp vid trepartshandel | 37 |
| 3308 | Tjänster till företag i annat EU-land | 39 |
| 3305 | Tjänster till kund utanför EU | 40 |
| 3231 | Försäljning där köparen är betalningsskyldig i Sverige (byggtjänster) | 41 |
| 3004 | Övrig försäljning | 42 |

Fakturor till kunder i andra länder bokförs på de här kontona när du väljer typ av försäljning på fakturan.

BAS har inget konto för **ruta 37** och **ruta 38**, mellanmans inköp och försäljning av varor vid trepartshandel. Lägg upp ett eget kostnadskonto, till exempel 4519, och koppla det till ruta 37 under [Kontoplan](/manual/bokforing/kontoplan) med **Momsruta**. Samma sätt fungerar för andra egna konton som ska till en viss ruta.

För försäljningen i ruta 38 använder NONOX konto 3107. Det läggs upp och kopplas till ruta 38 första gången du skapar en faktura för trepartshandel. Bokför du trepartshandel för hand på ett annat konto, koppla det kontot till ruta 38.

## Inköp där du redovisar momsen

| Konton (25/12/6 %) | Vad | Ruta |
|---|---|---|
| 4075–4077 | Handelsvaror från annat EU-land | 20 |
| 4515–4517 | Varor från annat EU-land | 20 |
| 4535–4537 | Tjänster från annat EU-land | 21 |
| 4531–4533 | Tjänster från land utanför EU | 22 |
| 4065–4067 | Handelsvaror i Sverige, omvänd betalningsskyldighet | 23 |
| 4415–4417 | Varor i Sverige, omvänd betalningsskyldighet | 23 |
| 4425–4427 | Tjänster i Sverige, omvänd betalningsskyldighet | 24 |
| 2614 / 2624 / 2634 | Utgående moms på inköpen ovan | 30 / 31 / 32 |

Läs [Inköp från utlandet](/manual/leverantorer/inkop-fran-utlandet).

## Import

| Konton | Vad | Ruta |
|---|---|---|
| 4085–4087 | Beskattningsunderlag vid import av handelsvaror (25 / 12 / 6 %) | 50 |
| 4545–4547 | Beskattningsunderlag vid import av råvaror och material (25 / 12 / 6 %) | 50 |
| 2615 / 2625 / 2635 | Utgående moms på import 25 / 12 / 6 % | 60 / 61 / 62 |
| 2645 | Ingående moms på importen | 48 |

Underlaget är tullvärdet från Tullverkets tullräkning, inte leverantörens faktura. Läs [Importmoms från tullräkning](/manual/moms/importmoms).

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
- Ett eget konto inom ett intervall, till exempel 3010, kommer med i samma ruta som BAS-kontona runt det. Ett konto du har kopplat till en ruta under **Momsruta** i kontoplanen räknas till den rutan i stället.
- Bokför du moms på ett konto utanför intervallen kommer den inte med i deklarationen. Använd **Visa konton** på momsdeklarationen för att kontrollera.

> [!NOTE]
> Kopplingen mellan konton och rutor följer BAS-kontoplanens momskonton men är ännu inte juridiskt kontrollerad mot Skatteverkets rutdefinitioner. Kontrollera med din redovisningskonsult om företaget har uttag, vinstmarginalbeskattning, frivilligt beskattad uthyrning eller trepartshandel.

## Läs också

- [Momsdeklarationen](/manual/moms/momsdeklaration)
- [Kontoplan](/manual/bokforing/kontoplan)
- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
