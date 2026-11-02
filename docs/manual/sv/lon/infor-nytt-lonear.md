---
beskrivning: Så förbereder du lönen inför ett nytt år – nya regler, nya skattetabeller och årets första lönekörning i januari.
ordning: 35
roller: [owner, admin, payroll_admin]
modul: payroll
sokord: [nytt löneår, inför 2027, januarilön, decemberlön i januari, nya skattetabeller, nya regler, årsskifte lön, skattetabellerna är inte inlästa]
---

# Inför ett nytt löneår

Varje år ändras en del av satserna som lönen räknas med, och Skatteverket ger ut nya skattetabeller. NONOX räknar alltid med värdena för **året då lönen betalas ut**. Lön för december som betalas i januari räknas alltså med det nya årets regler och skattetabeller.

Gör stegen nedan i december, eller senast innan du räknar årets första lön.

## Steg för steg

1. Öppna **Lön → Regler**. Överst står **Inför löneåret** med nästa utbetalningsår. Från oktober visas nästa år; du kan byta år i **Utbetalningsår**.
2. Läs raden om **Skattetabeller**. Står det **Inte inlästa ännu** har tabellerna för året inte lästs in. Se avsnittet om skattetabellerna nedan.
3. Gå igenom listan med regler. Varje regel har ett av fyra lägen:
   - **Kontrollerad** – klar.
   - **Ej kontrollerad** – värdet finns men ingen hos er har jämfört det med källan.
   - **Inte publicerad ännu** – myndigheten har inte gett ut värdet för året. Raden säger vilken myndighet och när värdet väntas.
   - **Saknas** – det finns inget värde från det datum som står på raden.
4. Klicka på **Kontrollera** vid en regel som inte är kontrollerad. Läs vad källan säger, öppna källan och jämför värdet.
5. Stämmer värdet: kryssa i rutan och klicka på **Markera som kontrollerad**. Ditt namn sparas, och kontrollen syns i behandlingshistoriken.
6. Kom tillbaka när en regel som inte var publicerad har kommit. Nya värden kommer med uppdateringar av NONOX och läses in samma natt som uppdateringen är installerad.
7. När allt är klart står det **Allt är klart för lönekörningar med utbetalning** och året. Räkna då årets första lön som vanligt.

> [!NOTE]
> En regel som stoppar en lönekörning är bara en regel som just den körningen behöver. En regel för till exempel bilförmån behöver vara kontrollerad först när någon har bilförmån.

## Skattetabellerna

Skatteverket tar fram skattetabellerna för nästa år i december. Tabellerna gäller alla företag i en NONOX-installation och läses in av den som driftar installationen, inte av varje företag.

Saknas tabellerna för året stoppas lönekörningen innan något räknas, med beskedet **Skattetabellerna för 2027 är inte inlästa ännu** (med rätt år). Ingen lön räknas med förra årets tabeller.

Driftar ni NONOX själva läses tabellerna in med ett kommando, antingen direkt från Skatteverkets öppna data eller från filen som laddas ner från Skatteverkets sida om skattetabeller:

```
php artisan payroll:import-tax-tables 2027 --download
php artisan payroll:import-tax-tables 2027 allmanna-tabeller-manad.txt
```

Tabellerna kontrolleras innan de sparas, och ett år läses bara in en gång.

## Den första lönen i januari

- Lön för december som betalas ut i januari räknas med januaris regler och tabeller, eftersom utbetalningsdagen styr.
- Kontrollera att tabell och kolumn på varje anställd stämmer med det nya årets skattebesked.
- Har någon fyllt år så att arbetsgivaravgiften ändras räknar NONOX om det automatiskt från födelseåret.
- Stoppas körningen: läs beskedet. Det säger vilka regler som inte är kontrollerade, eller att skattetabellerna saknas.

## Vanliga frågor

**Varför kan jag inte kontrollera alla regler i december?**
Några värden beslutas eller publiceras sent, till exempel bilförmånens ränta som bygger på statslåneräntan i slutet av november. De står som **Inte publicerad ännu** tills de har kommit.

**Gäller det gamla värdet tills det nya kommer?**
Nej. NONOX använder aldrig förra årets värde för ett nytt år. Hellre en stoppad lönekörning än en lön med fel siffror.

**Vi betalar lön för december redan i december. Påverkar det oss?**
Nej, den räknas med årets värden. Det nya året gäller från den första lönen som betalas ut i januari.

## Läs också

- [Regler och skattetabeller](/manual/lon/regler-och-skattetabeller)
- [Gör en lönekörning](/manual/lon/lonekorning)
- [Rapporter och löneårsskifte](/manual/lon/rapporter-och-lonearsskifte)
