---
beskrivning: Slå på körjournalen, låt de anställda skicka in sina resor som milersättning och godkänn dem, så betalas de med lönen och kryssas i arbetsgivardeklarationen.
ordning: 125
roller: [owner, payroll_admin]
modul: payroll
rutter: [payroll.requests]
sokord: [körjournal, milersättning, bilersättning, resor i tjänsten, förmånsbil, egen bil, ruta 050, tjänsteresa]
---

# Körjournal och milersättning

Med körjournalen skriver de anställda själva in sina resor i tjänsten på Min sida och skickar in en månad i taget som milersättning. Du godkänner den under **Lön → Ansökningar**, och nästa lönekörning betalar den med rätt lönearter. NONOX läser aldrig någons position: resorna skrivs in för hand.

## Slå på körjournalen

Körjournalen är avstängd från början. Öppna **Inställningar för företaget**, kryssa i **Använd körjournal** och spara. Stänger du av den finns allt kvar, men sidorna syns inte.

Lägg sedan in företagets bilar under **Bokföring → Körjournal** med märke, registreringsnummer och drivmedel. En bil som finns under **Lön → Förmånsbilar** med samma registreringsnummer räknas som den anställdas förmånsbil; en bil utan bilförmån räknas som en poolbil.

## Inställningar för milersättning

Under löneinställningarna finns fyra val för körjournalen:

| Inställning | Vad den gör |
|---|---|
| **Körjournal: milersättning för egen bil, kronor per mil** | Tomt betyder Skatteverkets skattefria belopp, 25 kronor per mil 2026. Ett högre belopp delas: det skattefria utan skatt, resten som lön. |
| **Körjournal: milersättning för förmånsbil, kronor per mil** | Tomt betyder Skatteverkets belopp, 12 kronor per mil, eller 9,50 kronor för en bil som bara går på el. |
| **Körjournal: ersätt resor till och från arbetet** | Av från början. Resor mellan bostaden och arbetsplatsen är privata, så betalar företaget för dem blir det alltid lön med skatt och arbetsgivaravgifter. |
| **Körjournal: sista dag i månaden att skicka in resor** | En påminnelse på Min sida, inget stopp. |

## Så här godkänner du en milersättning

1. Öppna **Lön → Ansökningar**. Milersättningen står under **Utlägg och milersättning att godkänna**, med en rad per resa: datum, bil, typ, varifrån och vart, ärende och sträcka.
2. Kontrollera att resorna är tjänsteresor och att sträckorna verkar rimliga.
3. Välj **Godkänn milersättningen**, eller **Avslå** med ett skäl som den anställda ser. Avslagna resor kan den anställda rätta och skicka igen.

Du väljer inget konto: milersättningen bokförs med lönen. Den som har skickat in milersättningen kan inte godkänna den själv när **Den som beräknar får inte attestera** är påslaget.

## I lönen och arbetsgivardeklarationen

| Resa | Löneart | Arbetsgivardeklaration |
|---|---|---|
| Tjänsteresa i egen bil | Milersättning, egen bil (konto 7331) | Kryss i ruta 050 |
| Tjänsteresa i förmånsbil när den anställda har betalat allt drivmedel | Milersättning, förmånsbil, eller Milersättning, förmånsbil (el) (konto 7331) | Kryss i ruta 050 |
| Belopp över Skatteverkets belopp, resor till och från arbetet, och förmånsbil när företaget har betalat drivmedel | Milersättning, skattepliktig del (konto 7332) | Lön i ruta 011 |

Den anställda kryssar i att hen har betalat allt drivmedel när resorna med företagets bil skickas in. Har hen inte gjort det, eller finns en drivmedelsförmån för månaden i lönen, kan resorna bara betalas som lön. En laddhybrid som laddats på arbetsplatsen ger skattefri milersättning för resor från den 1 juli 2026.

När lönekörningen bokförs bifogas körjournalen för varje milersättning som en PDF till lönens verifikation.

## År 2027

Skatteverkets belopp för egen bil, 25 kronor per mil, står i inkomstskattelagen och gäller även 2027. Beloppen för förmånsbil publicerar Skatteverket för varje år. Tills de finns kan en anställd skicka in resor från 2027, men lönekörningen med dem stoppas tills regeln är publicerad och kontrollerad under **Lön → Regler**.

## En betald resa som var fel

En betald resa kan inte ändras. Den anställda väljer **Rätta** på resan och skriver varför. Då dras lika lång sträcka av nästa gång resorna skickas in, och den rätta resan läggs in som en ny resa.

## Läs också

- [Förmånsbilar](/manual/lon/formansbilar)
- [Utlägg med kvitto](/manual/lon/utlagg)
- [Körjournal för de anställda](/manual/personal/korjournal)
