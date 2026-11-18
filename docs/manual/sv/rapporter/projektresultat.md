---
beskrivning: Se intäkter, kostnader, täckningsbidrag och marginal för varje projekt, för hela projektet eller ett räkenskapsår, mot budget och kalkyl, och raderna bakom varje konto.
ordning: 62
roller: [owner, admin, accountant, bookkeeper, auditor, unit_manager]
rutter: [projects.index, projects.show]
sokord: [projektresultat, projektuppföljning, projektkalkyl, täckningsbidrag, TB, marginal, efterkalkyl, projektbudget, kontraktssumma, projektredovisning]
---

# Projektresultat

Projektresultatet visar hur det går för varje projekt: vad det har dragit in, vad det har kostat och vad som blir kvar. Siffrorna kommer från de bokförda raderna där du har valt projektet, i verifikationer, leverantörsfakturor och kundfakturor. Inget bokförs här; sidan läser bara.

![Projektresultat för alla projekt, med intäkter, kostnader, täckningsbidrag och marginal.](/bilder/manual/rapporter/projektresultat-lista.webp)

## Så här ser du alla projekt

1. Gå till **Bokföring → Projektresultat**.
2. Välj **Status** om du bara vill se till exempel de pågående projekten.

Tabellen visar **Intäkter**, **Kostnader**, **Täckningsbidrag** och **Marginal** för hela projektets tid, alla räkenskapsår tillsammans. Får du se budgeten visas också **Budget, täckningsbidrag**. Klicka på ett projekt för att öppna dess sida.

Du når också ett projekts sida från projektregistret under **Bokföring → Projekt**, med **Projektresultat** på projektets rad.

## Så här läser du ett projekt

![Ett projekts resultat mot budget och kalkyl, med raderna bakom ett konto öppna.](/bilder/manual/rapporter/projektresultat.webp)

1. Öppna projektet från **Projektresultat** eller från projektregistret.
2. Välj **Period**: **Hela projektet** eller ett räkenskapsår.
3. Klicka på ett konto i tabellen för att se raderna bakom beloppet. Klicka igen, eller på **Stäng**, för att dölja dem.

Överst står projektets status, kund, projektledare, prisform, datum och kostnadsställe från projektregistret. Under dem visas fyra nyckeltal: **Intäkter**, **Kostnader**, **Täckningsbidrag** och **Marginal**.

### Grupperna

Kontona är grupperade efter sina nummer i kontoplanen:

| Grupp | Konton |
|---|---|
| Intäkter | 3000–3999 |
| Material och varor | 4000–4399 och 4700–4999 |
| Underentreprenader och externa kostnader | 4400–4699 och 5000–6999, till exempel inköp med omvänd betalningsskyldighet och tjänster från andra EU-länder |
| Personal | 7000–7699 |
| **Täckningsbidrag** | intäkterna minus de tre kostnadsgrupperna |
| Avskrivningar och finansiella poster | 7700–8998, även finansiella intäkter som ränta |
| **Projektresultat** | täckningsbidraget med avskrivningarna och de finansiella posterna |

Intäkter visas positiva och kostnader negativa, som i [resultatrapporten](/manual/rapporter/resultatrapport). Finansiella intäkter räknas inte som intäkter, precis som i resultatrapporten. **Kostnader** bland nyckeltalen är kostnaderna på konto 4000–7699, och **Marginal** är täckningsbidraget i procent av intäkterna.

### Mot budgeten

Kolumnerna **Budget**, **Avvikelse** och **Avvikelse %** visar budgetens rader med projektet, i den budget som används i rapporterna för varje räkenskapsår. För hela projektet läggs budgetarna för alla år ihop. **Avvikelse** är utfall minus budget, så ett positivt tal är alltid bättre än budgeten. Se [Budget](/manual/rapporter/budget) om hur du lägger upp en budget per projekt.

### Mot kalkylen

När du har valt **Hela projektet** jämför kortet **Mot kalkylen** utfallet med det som står i projektregistret:

- **Kontraktssumma**, hur stor del av den som är bokförd som intäkt (**Intäktsfört**) och vad som är **Kvar att intäktsföra**.
- **Beräknad total kostnad**, hur stor del som är **Förbrukat** och vad som är **Kvar enligt kalkylen**. Har kostnaderna gått över kalkylen visas andelen i en annan färg.
- **Beräknat täckningsbidrag** och **Beräknad marginal**: kontraktssumman minus den beräknade kostnaden.

Kontraktssumman och den beräknade totala kostnaden fyller du i under **Uppgifter** i projektregistret, se [Kostnadsställen och projekt](/manual/bokforing/kostnadsstallen-och-projekt).

### Per månad

Har du valt ett räkenskapsår visas också tabellen **Per månad**, med varje grupp, täckningsbidraget och projektresultatet månad för månad.

### Raderna bakom ett konto

Raderna visas med **Datum**, **Verifikation**, **Text** och **Belopp**, äldst först. Verifikationens nummer är en länk till verifikationen. Har du valt ett räkenskapsår finns också **Öppna i huvudboken**, som öppnar kontoanalysen för kontot och projektet. Sidan visar högst 200 rader per konto; resten finns i huvudboken.

> [!NOTE]
> Rader som är bokförda utan projekt räknas inte. Bokför du till exempel en leverantörsfaktura utan att välja projektet syns kostnaden inte här, bara i företagets resultatrapport.

## Om du är begränsad till vissa enheter

Är du begränsad till vissa kostnadsställen och projekt, till exempel som **Enhetschef**, ser du bara de projekt du är begränsad till. Ett annat projekt svarar som om det inte fanns. Personalkostnaderna på konto 7000–7699 visas inte alls, varken utfall eller budget, och oavsett om lönen är bokförd i lönemodulen, för hand eller läst in från en SIE-fil. På ett projekt med en enda anställd vore de den personens lön. Täckningsbidraget och resultatet blir därför högre än i bokföringen. I raderna bakom ett konto visas bara radens egen text för en verifikation du inte kan öppna, och numret utan länk. Se [Behörighet per enhet](/manual/installningar/behorighet-per-enhet).

## Vanliga frågor

**Varför stämmer inte intäkterna med det jag har fakturerat?**
Intäkterna är det som är bokfört på intäktskonton med projektet. Med kontantmetoden bokförs intäkten först när kunden betalar. En faktura där projektet inte är valt på raderna räknas inte.

**Räknas pågående arbeten?**
Nej, sidan visar det som är bokfört. Upparbetat men inte fakturerat värde bokförs i bokslutet, se [Pågående projekt](/manual/bokslut/pagaende-projekt). Bokför du förslaget syns det här från balansdagen, och återföringen dagen efter.

## Läs också

- [Kostnadsställen och projekt](/manual/bokforing/kostnadsstallen-och-projekt)
- [Kostnadsställe och projekt på fakturor](/manual/fakturering/kostnadsstalle-och-projekt-pa-fakturor)
- [Faktureringsplan för projekt](/manual/fakturering/faktureringsplan-for-projekt) – längre ner på projektets sida.
- [Budget mot utfall](/manual/rapporter/budget-mot-utfall)
- [Kontoanalys och huvudbok](/manual/rapporter/kontoanalys-och-huvudbok)
