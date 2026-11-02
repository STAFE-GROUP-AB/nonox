---
beskrivning: Kostförmån, bilförmån och andra förmåner, traktamente och milersättning i lönekörningen – vad som är skattefritt och hur det bokförs.
ordning: 110
roller: [owner, payroll_admin]
modul: payroll
sokord: [förmån, kostförmån, personalmat, bilförmån, drivmedelsförmån, traktamente, milersättning, bilersättning, reseersättning, egen betalning förmån]
---

# Förmåner, traktamente och milersättning

Förmåner, traktamenten och milersättning läggs in i lönekörningen som rader under **Timmar och tillägg**, med respektive löneart. NONOX hämtar Skatteverkets belopp från reglerna och räknar ut vad som är skattepliktigt och vad som är skattefritt.

![En lönekörning med kostförmån och milersättning. Beloppen är demodata.](/bilder/manual/lon/formaner-traktamente-och-milersattning.webp)

## Förmåner

En förmån är något den anställda får i stället för pengar, till exempel fri mat eller tjänstebil. Förmånen betalas inte ut, men den beskattas och ger arbetsgivaravgift.

| Löneart | Så här lägger du in den |
|---|---|
| **Kostförmån, hel dag** | Antal dagar i **Antal timmar, mil eller dagar**. Värdet hämtas från reglerna. |
| **Kostförmån, lunch eller middag** | Antal måltider i **Antal timmar, mil eller dagar**. |
| **Kostförmån, frukost** | Antal frukostar i **Antal timmar, mil eller dagar**. |
| **Bilförmån** | Förmånsvärdet för månaden i **eller belopp**. |
| **Drivmedelsförmån** | Förmånsvärdet för månaden i **eller belopp**. |
| **Övrig förmån** | Förmånsvärdet i **eller belopp**. |

Betalar den anställda något för förmånen, till exempel för personalmaten, lägg in lönearten **Egen betalning för förmån** med beloppet. Det dras från nettolönen och minskar förmånsvärdet med lika mycket, men aldrig under noll.

> [!IMPORTANT]
> NONOX räknar inte ut bilförmånens värde åt dig. Räkna fram det med Skatteverkets beräkningstjänst och skriv in månadens värde. Är du osäker på vad som är en förmån, fråga er redovisningskonsult eller Skatteverket.

### Det här händer i bokföringen

Förmånsvärdet bokförs inte som en egen kostnad. Den verkliga kostnaden bokfördes när företaget köpte maten eller betalade bilen; lönen lägger bara till skatten och arbetsgivaravgiften på förmånen (arbetsgivaravgiften på 7510). Den anställdas egen betalning bokförs mot 2890.

## Traktamente

Traktamente är ersättning för ökade levnadskostnader vid tjänsteresa med övernattning. NONOX hanterar traktamente inom Sverige.

| Löneart | Så här lägger du in den |
|---|---|
| **Traktamente, hel dag** | Antal hela dagar i **Antal timmar, mil eller dagar**. |
| **Traktamente, halv dag** | Antal halva dagar. |
| **Nattraktamente** | Antal nätter. |

NONOX betalar Skatteverkets skattefria belopp per dag från reglerna. Traktamentet betalas med nettolönen och bokförs på 7321 Skattefria traktamenten, Sverige.

> [!IMPORTANT]
> Villkoren för skattefritt traktamente (övernattning, avstånd från bostaden och arbetsplatsen, minskning efter tre månader och efter två år, avdrag för fria måltider) kontrollerar inte NONOX. Det är ditt ansvar att bara lägga in dagar som uppfyller villkoren. Traktamente vid resa utomlands stöds inte ännu.

## Milersättning

Milersättning betalas när den anställda kör sin egen bil i tjänsten.

1. Välj lönearten **Milersättning, egen bil**.
2. Skriv antal mil i **Antal timmar, mil eller dagar**.
3. Betalar ni mer än det skattefria beloppet: skriv antingen hela ersättningen i **eller belopp**, eller ersättningen per mil i **Ersättning per mil eller dag (frivilligt)**.
4. Klicka på **Lägg till**.

NONOX betalar Skatteverkets skattefria belopp per mil från reglerna. Ersättningen betalas med nettolönen och bokförs på 7331 Skattefria bilersättningar.

När du bara skriver in antal mil betalar NONOX det skattefria beloppet. Skriver du också ett belopp eller en ersättning per mil som är högre, eller kommer raden från en lönefil med ett högre pris per mil (se [Löneunderlag från schemasystem](/manual/lon/loneunderlag-fran-schemasystem)), räknar NONOX ut den skattepliktiga delen som en egen rad, **Milersättning, skattepliktig del**, som beskattas och ger arbetsgivaravgift (bokförs på 7332). Samma sak gäller traktamente över det skattefria beloppet (7322).

Exempel: 40 mil och 1 500 kronor. Skattefritt är 40 × 25 kronor = 1 000 kronor. Resten, 500 kronor, är lön.

Milersättning och traktamente går inte att lägga in med bara ett belopp, eftersom det skattefria beloppet räknas per mil eller dag. Skriv alltid antalet.

## I arbetsgivardeklarationen

Förmåner redovisas per anställd i arbetsgivardeklarationen. Har en anställd fått milersättning eller traktamente markeras det också, som Skatteverket kräver. Se [Arbetsgivardeklaration (AGI)](/manual/lon/arbetsgivardeklaration).

## Vanliga frågor

**Kan vi ge friskvårdsbidrag eller gåvor?**
Gränserna för skattefria gåvor och friskvård finns som regler, men det finns ingen egen löneart för dem i lönen ännu. Fråga er redovisningskonsult hur ni ska hantera dem.

**Varför står det timmar, mil eller dagar i samma fält?**
Fältet används för antalet i alla lönearter. Skriv det antal lönearten räknar i: timmar, mil, dagar eller måltider.

**Hur betalar jag tillbaka ett utlägg?**
Det sker automatiskt när utlägget är godkänt. Se [Utlägg med kvitto](/manual/lon/utlagg).

## Läs också

- [Gör en lönekörning](/manual/lon/lonekorning)
- [Utlägg med kvitto](/manual/lon/utlagg)
- [Regler och skattetabeller](/manual/lon/regler-och-skattetabeller)
- [Arbetsgivardeklaration (AGI)](/manual/lon/arbetsgivardeklaration)
