---
beskrivning: För företag som följer K3. Lägg upp skillnaderna mellan redovisat och skattemässigt värde, granska den uppskjutna skatten och bokför förändringen i bokslutet.
ordning: 65
roller: [owner, admin, accountant]
rutter: [accounting.closing.deferredTax]
sokord: [uppskjuten skatt, uppskjuten skatteskuld, uppskjuten skattefordran, temporär skillnad, skattemässigt värde, underskottsavdrag, K3, 2240, 1370, 8940, kvittning]
---

# Uppskjuten skatt (K3)

Ett aktiebolag eller en ekonomisk förening som följer K3 redovisar uppskjuten skatt när det redovisade värdet på en tillgång eller skuld skiljer sig från det skattemässiga värdet. Ett vanligt exempel är en byggnad som skrivs av snabbare i deklarationen än i bokföringen. Skillnaden kallas en temporär skillnad, och skatten på den bokförs som en uppskjuten skatteskuld eller skattefordran.

Sidan finns under **Bokföring → Bokslut**, i **2. Bokslutsverifikationer**: klicka på **Gå till uppskjuten skatt**. Länken visas för räkenskapsår som följer K3, i aktiebolag och ekonomiska föreningar. För andra år förklarar sidan varför det inte finns någon uppskjuten skatt att räkna fram. Steget är märkt **Beta**: beräkningen är inte granskad av en redovisningskonsult. Låt din redovisningskonsult granska den.

> [!NOTE]
> För ett mindre aktiebolag vars räkenskapsår följer K3 tar NONOX fram en förhandsversion (beta) av årsredovisningen. Den är inte granskad av en auktoriserad redovisningskonsult, och en redovisningskonsult ska granska den innan den skrivs under. Se [Årsredovisning](/manual/bokslut/arsredovisning#forhandsversion-beta-for-mindre-aktiebolag-med-k3). Uppskjuten skatt bokförs i bokföringen, men årsredovisningen gör du med din redovisningskonsult. Se [Årsredovisning](/manual/bokslut/arsredovisning).

## Så här lägger du upp en post

1. Välj räkenskapsåret överst på sidan.
2. Under **Lägg till en post** väljer du **Sort**: **Byggnad**, **Annan tillgång**, **Skuld** eller **Underskottsavdrag**.
3. Skriv en **Beskrivning**, till exempel ”Fabriksbyggnaden”.
4. Fyll i **Konton, från och till**, till exempel 1110 och 1119 för byggnaden och dess ackumulerade avskrivningar. Det redovisade värdet läses då från kontonas utgående balans. Har du inga konton att peka på skriver du **Redovisat värde, kr** i stället.
5. Skriv **Skattemässigt värde, kr** och välj **Källa för det skattemässiga värdet**: **Eget underlag** eller **Inkomstdeklarationen**. För ett underskottsavdrag skriver du **Underskott att dra av, kr** i stället.
6. Klicka på **Lägg till posten**.

Varje post visas i tabellen med **Redovisat värde**, **Skattemässigt värde**, **Temporär skillnad**, **Slag** (**Skattepliktig** eller **Avdragsgill**) och **Uppskjuten skatt**. Under varje belopp står var det kommer ifrån. Du kan **Ändra** och **Ta bort** en post tills den uppskjutna skatten är bokförd.

> [!IMPORTANT]
> Obeskattade reserver, till exempel periodiseringsfonder på konto 2100–2199, är aldrig en post. I ett aktiebolag redovisas de brutto, utan uppskjuten skatt (K3 punkt 29.37). NONOX nekar konton i det intervallet. Lämna också utanför skillnader på goodwill när den först redovisas och på andelar i dotterföretag – NONOX känner inte igen dem.

## Hur skatten räknas

- **Temporär skillnad** = redovisat värde minus skattemässigt värde.
- För en tillgång ger ett högre redovisat värde en skattepliktig skillnad (en skuld) och ett lägre en avdragsgill (en fordran). För en skuld är det tvärtom. Ett underskottsavdrag är alltid avdragsgillt.
- **Uppskjuten skatt** = skillnaden gånger skattesatsen, avrundad till hela öre. Beloppet nuvärdeberäknas inte.
- Skattesatsen är den som gäller för räkenskapsår som börjar dagen efter balansdagen, i dag 20,6 % för aktiebolag (inkomstskattelagen 65 kap. 10 §). Om NONOX känner till en annan skattesats för ett senare år stoppas förslaget, och du räknar den uppskjutna skatten för hand.

## Nästa år: hämta posterna från föregående år

Ett nytt räkenskapsår börjar utan poster. Har föregående år poster visas knappen **Hämta posterna från föregående år**. Den kopierar posterna med samma konton och skattemässiga värden. Det redovisade värdet räknas om från kontona för det nya året, men det skattemässiga värdet måste du kontrollera själv: varje kopierad post är märkt **Hämtad från föregående år – kontrollera det skattemässiga värdet**, och inget kan bokföras förrän du har kontrollerat och sparat posten. En uppskjuten skattefordran kopieras inte, utan bedöms på nytt varje år.

Har året inga poster men står det uppskjuten skatt på 2240 eller 1370, skulle bokföringen återföra allt. Det går bara om du kryssar i att företaget inte har några temporära skillnader kvar och att skulden och fordran ska återföras helt.

## Uppskjuten skattefordran

NONOX föreslår aldrig en uppskjuten skattefordran. I fältet **Uppskjuten skattefordran att ta upp, kr** skriver du det belopp som ska tas upp, och i **Skäl till att fordran tas upp** varför: vad som övertygande talar för att det kommer skattepliktiga överskott att kvitta mot (K3 punkt 29.29). Lämnar du fältet tomt är fordran 0. Fordran kan aldrig bli större än den beräknade fordran för posten, som visas i tabellen som **fordran, beräknad**.

## Så här bokför du den uppskjutna skatten

1. Bokför först allt annat i året, även avskrivningarna.
2. Granska rutan **Uppskjuten skatt vid balansdagen**: **Uppskjuten skatteskuld, konto 2240**, **Uppskjuten skattefordran, konto 1370**, beloppen vid årets början, **Förändring sedan förra balansdagen** och **Att bokföra på konto 8940 (kostnad positiv)**.
3. Har företaget både en skuld och en fordran, och legal rätt att kvitta dem, kryssar du i **Företaget har legal rätt att kvitta skulden mot fordran, så de redovisas netto (K3 punkt 29.32).** Annars visas de var för sig.
4. Klicka på **Bekräfta och bokför uppskjuten skatt**. Rutan som öppnas anger beloppet, kontot och datumet och att en bokförd verifikation bara kan rättas med en ny. Bekräfta.

Gör det före årets resultat. Har bokföringen, beloppen eller posterna ändrats sedan beräkningen visades bokförs ingenting, och du får granska den igen.

## Det här händer i bokföringen

En verifikation i serie A den sista dagen i räkenskapsåret: förändringen bokförs på 2240 Avsättningar för uppskjutna skatter och 1370 Uppskjuten skattefordran mot 8940 Uppskjuten skatt. Ökar skulden blir det en kostnad, minskar den en intäkt. NONOX bokför bara skillnaden mot det som redan står på kontona, så skuld och fordran blir de belopp du såg. Behövs ingen ändring sparas bekräftelsen utan verifikation.

Efter bekräftelsen är posterna låsta. Blev något fel rättar du verifikationen med en rättelseverifikation.

## Bra att veta

- Den uppskjutna skatt du har bekräftat här räknas inte som skatt som redan är bokförd när NONOX föreslår årets skatt. Annat på 8900–8989, även en rättelse av verifikationen, gör det. Se [Skatt och årets resultat](/manual/bokslut/bokslutsverifikationer).
- Bara en ägare, administratör eller redovisningskonsult når sidan från bokslutet, lägger upp poster och bokför.

## Läs också

- [Skatt och årets resultat](/manual/bokslut/bokslutsverifikationer)
- [Anläggningsregistret och avskrivningar](/manual/bokslut/anlaggningstillgangar)
- [Stäng räkenskapsåret](/manual/bokslut/stang-aret)
- [Årsredovisning](/manual/bokslut/arsredovisning)
