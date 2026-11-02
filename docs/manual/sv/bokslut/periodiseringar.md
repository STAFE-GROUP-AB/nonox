---
beskrivning: Fördela en förutbetald kostnad eller intäkt över månaderna, och boka upplupna kostnader och intäkter som faktureras senare – med ett klick per månad.
ordning: 20
roller: [owner, admin, accountant]
rutter: [accounting.accruals]
sokord: [periodisering, periodisera, förutbetald kostnad, förutbetald intäkt, upplupen kostnad, upplupen intäkt, 1790, 2990, interimsfordran, interimsskuld, återföring]
---

# Periodiseringar

En periodisering ser till att en kostnad eller intäkt hamnar i den månad den hör till, inte i den månad fakturan kom. Betalar du en årsförsäkring i december hör bara en tolftedel till december. Kommer elräkningen för december först i januari hör den ändå till december.

Du lägger in periodiseringen en gång, och sedan bokför NONOX varje månad med ett klick. Sidan finns under **Bokföring → Periodiseringar**.

![Periodiseringarna och månadens bokföring av dem.](/bilder/manual/bokslut/periodiseringar.webp)

## De fyra slagen

| Slag | Exempel | Så bokförs det |
|---|---|---|
| **Förutbetald kostnad** | En årsförsäkring betald i förväg | Ligger på ett balanskonto (förslag 1790) och fördelas jämnt över månaderna till kostnadskontot. |
| **Förutbetald intäkt** | Ett årsabonnemang fakturerat i förväg | Ligger på ett balanskonto (förslag 2990) och fördelas jämnt över månaderna till intäktskontot. |
| **Upplupen kostnad** | El för december som faktureras i januari | Bokförs den sista dagen i månaden mot ett balanskonto (förslag 2990) och återförs den första dagen i nästa månad. |
| **Upplupen intäkt** | Utfört arbete som faktureras senare | Bokförs den sista dagen i månaden mot ett balanskonto (förslag 1790) och återförs den första dagen i nästa månad. |

## Så här lägger du in en periodisering

1. Gå till **Bokföring → Periodiseringar** och klicka på **Ny periodisering**.
2. Välj **Slag**.
3. Skriv **Benämning**, till exempel "Företagsförsäkring 2027".
4. Skriv **Belopp exkl. moms, kr**.
5. Välj **Första månad** och, för förutbetalda poster, **Antal månader**.
6. Skriv **Kostnadskonto** eller **Intäktskonto**, och kontrollera **Balanskonto**.
7. Välj **Kostnadsställe** om ni använder sådana.
8. För en förutbetald post: är fakturan bokförd direkt på kostnads- eller intäktskontot, kryssa i **Fakturan är bokförd direkt på resultatkontot. Flytta först hela beloppet till balanskontot.** Lämna tomt om fakturan redan är bokförd på balanskontot.
9. Klicka på **Spara**.

Under **Fördelning** ser du hur mycket som bokförs varje månad, och vilka månader som redan är bokförda.

## Så här bokför du månadens periodiseringar

1. Gå till **Bokföring → Periodiseringar**.
2. Under **Månadens periodiseringar**, välj **Månad**. Rutan visar vad som ska bokföras: flytt till balanskonto, månadens del, upplupet och återföring.
3. Klicka på **Bokför periodiseringar**.

Gör det en gång i månaden, gärna innan du tar fram rapporterna för månaden. Glömmer du en månad tas den igen nästa gång.

## Det här händer i bokföringen

Varje körning bokför högst två verifikationer i serie A:

- **Den första dagen i månaden**: återföringen av förra månadens upplupna poster.
- **Den sista dagen i månaden**: månadens del av förutbetalda poster, månadens nya upplupna poster och en eventuell flytt till balanskontot.

Fördelningen räknas så att summan alltid blir exakt beloppet: för varje månad räknas hur mycket som ska vara fördelat vid månadens slut, och skillnaden mot det som redan är bokfört bokförs. Varje steg sparas, så samma steg kan aldrig bokföras två gånger.

## Ändra eller ta bort

- Innan något är bokfört kan du ändra allt, eller ta bort periodiseringen med **Ta bort**.
- När en månad är bokförd kan bara benämning och anteckning ändras, och periodiseringen kan inte tas bort.

Färdiga periodiseringar flyttas från **Pågående** till **Klara**.

## Periodiseringar och bokslutet

Bokslutets checklista kontrollerar att alla periodiseringar är bokförda till och med årets sista månad. Läs [Checklistan för bokslutet](/manual/bokslut/checklistan).

> [!NOTE]
> NONOX varken kräver eller hindrar en periodisering. Vilka poster som måste periodiseras, och om ett mindre företag får avstå under en viss beloppsgräns, avgör regelverket (K2 eller K3) – fråga din redovisningskonsult.

## Läs också

- [Anläggningsregistret och avskrivningar](/manual/bokslut/anlaggningstillgangar)
- [Checklistan för bokslutet](/manual/bokslut/checklistan)
- [Bokför en verifikation](/manual/bokforing/bokfor-en-verifikation)
