---
beskrivning: Lägg in inventarier, datorer, maskiner och bilar i anläggningsregistret, och bokför avskrivningarna enligt plan med ett klick i månaden.
ordning: 30
roller: [owner, admin, accountant]
rutter: [accounting.fixedAssets]
sokord: [anläggningsregister, anläggningstillgång, inventarier, avskrivning, avskrivningsplan, planenlig avskrivning, nyttjandeperiod, restvärde, 1220, 1229, 7832, bokfört värde]
---

# Anläggningsregistret och avskrivningar

En maskin, en dator eller en bil som används i flera år kostnadsförs inte på en gång. Den bokförs som en tillgång och skrivs av – kostnaden fördelas – över de år den används. Anläggningsregistret håller ordning på varje tillgång och bokför avskrivningarna åt dig varje månad.

Registret finns under **Bokföring → Anläggningar**.

![Anläggningsregistret med bokfört värde och månadens avskrivning.](/manual/bilder/bokslut/anlaggningstillgangar.webp)

## Så fungerar det

- **Inköpet** bokförs som vanligt när tillgången köps, på ett tillgångskonto som 1220 Inventarier och verktyg. Det kan du göra med en leverantörsfaktura eller en verifikation – och då kan tillgången skapas i registret samtidigt. Läs [Anläggningstillgång direkt från inköpet](/manual/bokslut/tillgang-fran-inkop).
- **Registret** bokför avskrivningarna, en gång i månaden.
- **Försäljning eller utrangering** bokförs också från registret. Läs [Sälja eller utrangera en tillgång](/manual/bokslut/salja-eller-utrangera).

## Så här lägger du in en tillgång

1. Gå till **Bokföring → Anläggningar** och klicka på **Ny tillgång**.
2. Skriv **Benämning**, till exempel "Espressomaskin".
3. Välj **Slag**: **Inventarier och verktyg**, **Datorer**, **Maskiner och andra tekniska anläggningar** eller **Bilar och andra transportmedel**. Kontona och en nyttjandeperiod fylls i efter slaget.
4. Fyll i **Anskaffningsdag** och **Anskaffningsvärde, kr** – priset utan moms.
5. Fyll i **Restvärde, kr** – det tillgången väntas vara värd när den är avskriven. Oftast 0.
6. Fyll i **Nyttjandeperiod, månader** – hur länge den ska skrivas av – och **Första avskrivningsmånad**.
7. Kontrollera **Tillgångskonto**, **Ack. avskrivningar** och **Avskrivningskonto**, och välj **Kostnadsställe** om ni använder sådana.
8. Flyttar du en tillgång hit från ett annat program, fyll i **Redan avskrivet, kr**.
9. Klicka på **Spara**.

Nyttjandeperioden är företagets egen bedömning av hur länge tillgången används. Förslaget är bara en utgångspunkt.

### Kontona

| Slag | Tillgång | Ackumulerade avskrivningar | Avskrivning |
|---|---|---|---|
| Inventarier och verktyg | 1220 | 1229 | 7832 |
| Datorer | 1250 | 1259 | 7835 |
| Maskiner och andra tekniska anläggningar | 1210 | 1219 | 7831 |
| Bilar och andra transportmedel | 1240 | 1249 | 7834 |

Kontona kan ändras per tillgång. Saknas något i kontoplanen visar registret vilka och knappen **Lägg till kontona**.

## Så här bokför du månadens avskrivningar

1. Gå till **Bokföring → Anläggningar**.
2. Under **Månadens avskrivning**, välj **Månad**. Förra månaden är förvald. Rutan visar vad som ska skrivas av per tillgång.
3. Klicka på **Bokför avskrivningar**.

Står det **Inget att skriva av för …** är allt redan bokfört, eller så har ingen tillgång börjat skrivas av.

## Det här händer i bokföringen

Avskrivningen bokförs som en verifikation i serie A den sista dagen i månaden: avskrivningskontot i debet och kontot för ackumulerade avskrivningar i kredit, per tillgång.

Avskrivningen är **linjär enligt plan**: det som ska skrivas av – anskaffningsvärdet minus restvärdet – fördelas jämnt över nyttjandeperioden. För varje månad räknas hur mycket som ska vara avskrivet vid månadens slut, och skillnaden mot det som redan är avskrivet bokförs. Därför blir summan exakt, och en missad månad tas igen nästa gång.

En månads avskrivning för en tillgång bokförs bara en gång.

## Registret

Listan visar för varje tillgång **Anskaffningsvärde**, **Avskrivet** och **Bokfört värde**, och om den är **Fullt avskriven** eller **Avyttrad**. Klicka på en tillgång för att se **Avskrivningsplan** månad för månad. Skapades tillgången från ett inköp står det **Anskaffad med verifikation** med en länk.

När en månad har bokförts för en tillgång kan bara benämning och anteckning ändras.

## Avskrivningarna och bokslutet

Bokslutets checklista kontrollerar att alla tillgångar är avskrivna till och med årets sista månad. Läs [Checklistan för bokslutet](/manual/bokslut/checklistan). Noten om anläggningstillgångar i årsredovisningen hämtar sina siffror från bokföringen.

> [!NOTE]
> Registret följer bara avskrivningsplanen. Skattemässiga avskrivningar av inventarier och överavskrivningar hanteras inte. Fråga din redovisningskonsult om företaget ska skriva av mer skattemässigt än enligt plan.

## Vem får använda registret?

Den som får hantera räkenskapsår: **Ägare**, **Administratör** och **Redovisningskonsult**.

## Läs också

- [Anläggningstillgång direkt från inköpet](/manual/bokslut/tillgang-fran-inkop)
- [Sälja eller utrangera en tillgång](/manual/bokslut/salja-eller-utrangera)
- [Årsredovisning enligt K2](/manual/bokslut/arsredovisning)
