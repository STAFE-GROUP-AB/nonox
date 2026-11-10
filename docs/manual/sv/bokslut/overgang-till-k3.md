---
beskrivning: Det första året med K3. Lägg upp skillnaderna mot K2 i ingångsbalansräkningen, stäm av eget kapital och bokför övergången en gång.
ordning: 7
roller: [owner, admin, accountant]
rutter: [accounting.closing.k3Transition]
sokord: [övergång, K2 till K3, förstagångstillämpning, kapitel 35, ingångsbalansräkning, jämförelsetal, jämförbarhet, lättnadsregel, 35.27, uppskjuten skatt, avsättning, 2091]
---

# Övergång till K3

När ett aktiebolag eller en ekonomisk förening går från K2 till K3 ska det första året med K3 börja med en ingångsbalansräkning som följer K3. De skillnader som uppstår mot K2, till exempel uppskjuten skatt eller en avsättning som K2 inte krävde, redovisas direkt mot eget kapital (K3 punkt 35.11). Det görs en gång, i det första året med K3.

Sidan finns under **Bokföring → Bokslut**: klicka på **Gå till övergången till K3**. Länken visas för företagets första räkenskapsår som följer K3, när året innan följde K2. Steget och sidan är märkta **Beta**: övergången är inte granskad av en redovisningskonsult. Låt din redovisningskonsult granska den innan du bokför.

> [!NOTE]
> För ett mindre aktiebolag vars räkenskapsår följer K3 tar NONOX fram en förhandsversion (beta) av årsredovisningen. Den är inte granskad av en auktoriserad redovisningskonsult, och en redovisningskonsult ska granska den innan den skrivs under. Se [Årsredovisning](/manual/bokslut/arsredovisning#forhandsversion-beta-for-mindre-aktiebolag-med-k3). Övergången bokförs i bokföringen, och sidan ger dig avstämningen och texten om jämförbarheten, men årsredovisningen gör du med din redovisningskonsult. Se [Årsredovisning](/manual/bokslut/arsredovisning).

## Innan du börjar

- Välj K3 för året under [Regelverk: K2 eller K3](/manual/bokslut/regelverk) och svara på frågorna om övergången: om jämförelsetalen räknas om, om företaget har tillämpat K3 tidigare och varför företaget lämnar K2.
- NONOX stöder övergången utan omräknade jämförelsetal. Ett mindre företag får låta bli att räkna om dem och upplyser i stället om att jämförbarheten med föregående år är begränsad (K3 punkt 35.7). Övergångsdagen är då första dagen i det första året med K3.
- Stäng det sista året med K2. Övergången utgår från dess utgående balanser, som måste stämma med de ingående balanserna i det nya året.
- Har anläggningsregistret en byggnad delar du upp den i komponenter per övergångsdagen, under [Anläggningsregistret och avskrivningar](/manual/bokslut/anlaggningstillgangar). Byggnadens redovisade värde fördelas på komponenterna och ingenting räknas om bakåt i tiden, så uppdelningen ger ingen skillnad i eget kapital (K3 punkt 35.18).

## Så här lägger du upp en justering

1. Välj räkenskapsåret överst på sidan.
2. Under **Lägg till en justering** väljer du **Sort**: **Uppskjuten skatt**, **Avsättning** eller **Annan justering**.
3. Skriv en **Beskrivning**, till exempel ”Verkstadsbyggnad”.
4. För **Uppskjuten skatt** väljer du vad **Skillnaden gäller**: **Byggnad**, **Annan tillgång**, **Skuld** eller **Underskottsavdrag**. Fyll i **Konton, från och till**, till exempel 1110 och 1119 för en byggnad. Det redovisade värdet läses då från kontonas ingående balans på övergångsdagen. Gäller skillnaden något som inte finns i bokföringen än, till exempel en avsättning som läggs upp vid övergången, skriver du **Redovisat värde enligt K3, kr** i stället. Skriv sedan **Skattemässigt värde, kr**.
5. För en **Avsättning** skriver du **Konto**, ett avsättningskonto mellan 2200 och 2299, och **Belopp, kr**. **Punkt i K3** är frivilligt. Gäller avsättningen en skyldighet att montera ned en tillgång eller återställa en plats kryssar du i det: sidan visar då att det inte kan hanteras här ännu (se nedan).
6. För en **Annan justering** skriver du **Konto**, **Belopp, kr**, väljer om **Kontot ska** **debiteras** eller **krediteras** och anger **Punkt i K3**, till exempel K3 punkt 21.4.
7. Klicka på **Lägg till justeringen**.

Varje justering visas i tabellen med **Redovisat värde**, **Skattemässigt värde**, **Slag** och **Påverkan på eget kapital**. Du kan **Ändra** och **Ta bort** en justering tills övergången är bokförd.

> [!IMPORTANT]
> Vissa omräkningar är förbjudna vid övergången, och NONOX tar inte emot dem: ändrade uppskattningar och bedömningar (K3 punkt 35.15), aktivering av tidigare kostnadsförda utgifter för eget utvecklingsarbete (35.16), omräkning av avskrivningar per komponent bakåt i tiden (35.18) och aktivering av tidigare kostnadsförda låneutgifter (35.20). Därför går det inte att justera konton för immateriella och materiella anläggningstillgångar (1000–1299) här. Obeskattade reserver (2100–2199) justeras inte heller.

## Uppskjuten skatt vid övergången

Den uppskjutna skatten räknas som i bokslutet: den temporära skillnaden gånger skattesatsen för räkenskapsår som börjar på övergångsdagen, i dag 20,6 % för aktiebolag. En uppskjuten skatteskuld bokförs på 2240 och en uppskjuten skattefordran på 1370, båda mot eget kapital. NONOX föreslår aldrig en skattefordran: skriv beloppet i **Uppskjuten skattefordran att ta upp, kr** och skälet i **Skäl till att fordran tas upp**. Se [Uppskjuten skatt (K3)](/manual/bokslut/uppskjuten-skatt).

Kan det skattemässiga värdet inte tas fram utan oskälig kostnad får posten lämnas utan uppskjuten skatt (K3 punkt 35.27). Kryssa då i rutan om att uppgifterna inte kan tas fram och skriv **Skäl till att lättnadsregeln används**. Utan skäl tar NONOX inte emot posten. Att lättnadsregeln används står alltid i texten till årsredovisningen.

> [!NOTE]
> En skyldighet att montera ned eller återställa kan inte hanteras här ännu. En sådan avsättning ska också räknas in i tillgångens anskaffningsvärde, och K3 punkt 35.29 har en egen lättnadsregel för den vid övergången. NONOX tar inte emot den som en justering; ta upp den med din redovisningskonsult.

## Avstämning och upplysningar

- **Avstämning av eget kapital** visar för varje post i balansräkningen som ändras beloppet enligt K2, justeringen och beloppet enligt K3, och sedan **Summa eget kapital** och **Balansomslutning**. Uppskjuten skatteskuld ingår i posten **Avsättningar för skatter**, med raden **varav uppskjuten skatt** under.
- Uppskjuten skattefordran och skatteskuld visas brutto. Har företaget legal rätt att kvitta dem (K3 punkt 29.32) kryssar du i **Kvittningsrätt för uppskjuten skatt finns**; då visas de netto i avstämningen. Verifikationen bokförs alltid brutto. Valet gäller hela året och är detsamma som under [Uppskjuten skatt (K3)](/manual/bokslut/uppskjuten-skatt). Övergången sparar valet när den bokförs, och ändras det senare visar sidan en varning.
- Stämmer saldot på konto 1110–1119 inte med byggnaderna i anläggningsregistret visar sidan en varning: byggnader ska finnas i registret och delas upp i komponenter per övergångsdagen.
- **Komponenter** visar varje byggnad i registret med det redovisade värdet före uppdelningen och summan av komponenterna. Skillnaden i eget kapital är noll.
- **Upplysningar i årsredovisningen** är texten som K3-årsredovisningen ska innehålla: att K3 tillämpas för första gången, att jämförelsetalen inte är omräknade och att jämförbarheten därför är begränsad, vilka redovisningsprinciper som har ändrats, vilka lättnadsregler som har använts och hur mycket eget kapital ändrades.

## Så här bokför du övergången

1. Granska justeringarna, avstämningen och upplysningarna.
2. Kontrollera rutan **Bokföring av övergången**: verifikationens rader och skattesatsen. Står det något i den gula rutan ska det åtgärdas först.
3. Klicka på **Bekräfta och bokför övergången**. Rutan som öppnas anger datumet, hur mycket eget kapital ändras och på vilket konto, och att övergången bara kan bokföras en gång. Bekräfta.

Har justeringarna eller anläggningsregistret ändrats sedan sidan visades bokförs ingenting, och du får granska igen.

> [!WARNING]
> Övergången bokförs bara en gång. K3 får tillämpas för första gången en gång (K3 punkt 35.1); ett företag som har tillämpat K3 tidigare och lämnar K2 av eget val får inte göra en ny övergång. Undantaget är ett företag som inte längre får tillämpa K2 enligt BFNAR 2025:2.

## Det här händer i bokföringen

En verifikation i serie A daterad första dagen i det första året med K3. Varje skillnad bokförs mot 2091 Balanserad vinst eller förlust: en uppskjuten skatteskuld på 2240, en skattefordran på 1370 och en avsättning på sitt konto. Verifikationen är märkt som en övergång till K3, och i förändringarna i eget kapital visas den på raden för effekter av retroaktiv tillämpning.

De ingående balanserna ändras inte. De stämmer fortfarande med det sista K2-årets utgående balanser, och K2-året och dess årsredovisning ändras inte. Ändrar övergången ingenting i bokföringen sparas bekräftelsen utan verifikation.

Efter bokföringen är justeringarna låsta. Blev något fel rättar du verifikationen med en rättelseverifikation.

## Bra att veta

- Bara en ägare, administratör eller redovisningskonsult lägger upp justeringar och bokför övergången. En revisor kan läsa sidan.
- Ett år där övergången är bokförd kan inte bytas tillbaka till K2. Vänd först övergångens verifikation helt med en rättelseverifikation utan ny kontering: då räknas övergången som ogiltig, och året kan bytas tillbaka eller få en ny övergång. En rättelse som bokför om något låter övergången stå kvar.
- När årets uppskjutna skatt bokförs i bokslutet räknas förändringen från ingångsbalansräkningen enligt K3, alltså med övergångens belopp.

## Läs också

- [Regelverk: K2 eller K3](/manual/bokslut/regelverk)
- [Uppskjuten skatt (K3)](/manual/bokslut/uppskjuten-skatt)
- [Anläggningsregistret och avskrivningar](/manual/bokslut/anlaggningstillgangar)
- [Stäng räkenskapsåret](/manual/bokslut/stang-aret)
- [Årsredovisning](/manual/bokslut/arsredovisning)
