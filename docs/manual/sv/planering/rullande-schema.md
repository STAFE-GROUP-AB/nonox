---
beskrivning: Ett schema som upprepas över flera veckor – lägg ut det med en mall, och räkna övertid och mertid mot hela periodens arbetstid i stället för varje vecka.
ordning: 35
roller: [owner, admin, payroll_admin, scheduler]
modul: planning
sokord: [rullande schema, roterande schema, schemaperiod, beräkningsperiod, snitt, genomsnittlig arbetstid, fyraveckorsschema, flera veckor]
---

# Rullande schema och beräkningsperiod

Ett rullande schema upprepas efter ett antal veckor, till exempel ett fyraveckorsschema där någon arbetar fler timmar vissa veckor och färre andra. I snitt blir det veckoarbetstiden.

## Lägg ut schemat

Skapa schemat som en mall med lika många veckor som schemat har, högst åtta. Lägg sedan ut mallen så många gånger du behöver. Se [Mallar och kopiera veckor](/manual/planering/mallar-och-kopiera-veckor).

## Räkna övertid över perioden

Från början räknar Planering övertid och mertid för månadsavlönade per kalendervecka. Med ett rullande schema skulle en lång vecka då ge övertid, fast en kort vecka senare tar ut den. Ställ därför in beräkningsperioden:

1. Gå till **Planering → Inställningar**.
2. Under **Attest och lön**, ange **Beräkningsperiod för övertid och mertid (veckor)**, till exempel 4.
3. Ange **Första periodens måndag**, den måndag ert schema börjar. Tomt betyder 1 januari 2024.
4. Klicka på **Spara**.

Bara den som får attestera tid kan ändra inställningarna.

Nu jämförs den attesterade tiden under hela perioden med veckoarbetstiden gånger antalet veckor. Med 40 timmar i veckan och fyra veckor är gränsen 160 timmar. Tiden över gränsen blir mertid eller övertid på samma sätt som för en vecka, och skickas med periodens sista dag när hela perioden är attesterad. Se [OB och övertid i lönen](/manual/planering/ob-och-overtid-i-lonen).

## Avtalets längsta period

Kollektivavtalen säger hur lång beräkningsperioden får vara utan ett lokalt avtal, till exempel 26 veckor i Gröna Riks. Är er period längre visar Tid att attestera en avvikelse med avtalets gräns och källan.

> [!IMPORTANT]
> Avtalen har ofta olika gränser för olika anställningsformer, och en längre period kan kräva ett lokalt avtal. Kontrollera vad som gäller för er, eller fråga er redovisningskonsult.

## Sjuklön

Beräkningsperioden används också när karensavdraget räknas för den som har oregelbunden arbetstid. Se [Sjuklön med OB enligt schemat](/manual/avtal/sjuklon-med-ob).

## Läs också

- [Mallar och kopiera veckor](/manual/planering/mallar-och-kopiera-veckor)
- [OB och övertid i lönen](/manual/planering/ob-och-overtid-i-lonen)
- [Komptid](/manual/lon/komptid)
