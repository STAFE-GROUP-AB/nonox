---
beskrivning: Förslag på elimineringar mellan koncernens företag med båda sidor och differensen, och koncernjournalen med godkända och manuella poster och återföringar. Ett internt underlag.
ordning: 83
roller: [owner, admin, accountant, bookkeeper, auditor]
rutter: [group.eliminations]
sokord: [koncern, eliminering, elimineringar, koncernjournal, koncernintern, mellanhavanden, koncernbidrag, koncernlån, återföring, andelar, aktiekapital, differens]
---

# Elimineringar

På **Mina företag → Elimineringar** ser du vad företagen i en koncern har handlat med varandra under en period: försäljning och inköp, fordringar och skulder, lån, ränta, utdelning och koncernbidrag, och moderföretagets andelar mot dotterföretagens aktiekapital. NONOX visar båda sidorna och om de skiljer sig. Ett förslag där sidorna är lika stora kan du godkänna. Det blir då en post i **koncernjournalen**, och [konsolideringen](/manual/rapporter/konsolidering) av samma period drar bort beloppen, så att samma försäljning inte räknas två gånger.

![Elimineringar med ett godkänt förslag, ett förslag med differens och koncernjournalen.](/bilder/manual/rapporter/elimineringar.webp)

> [!IMPORTANT]
> Sidan säger överst **Intern koncernrapport, inte en koncernredovisning enligt ÅRL**. Elimineringarna förs i koncernens egen journal och bokförs aldrig i något av företagen. De är inte en del av något företags bokföring.

## Vem som ser vad

- Förslagen och koncernjournalen visar enskilda företags belopp. Därför visas de bara för den som får se rapporter i **alla** företag i koncernen.
- **Godkänn**, **Ny manuell post** och **Återför** kan bara den som är ägare eller administratör i alla företag i koncernen använda.
- Har du bara rollen att läsa koncernens sammanställning säger sidan **Elimineringarna visas inte för dig**. Summorna per slag ser du under **Konsolidering**, men bara för ett slag som gäller minst två par av företagen; annars visas antalet poster.

## Var förslagen kommer ifrån

Förslagen bygger på [koncernmarkeringarna](/manual/bokforing/koncernmarkeringar) i varje företag. Har moderföretaget markerat en försäljning till dotterföretaget, och dotterföretaget markerat sitt inköp från moderföretaget, möts de två sidorna i ett förslag:

| Slag | Ena sidan | Andra sidan |
|---|---|---|
| Försäljning och inköp | Försäljning till koncernföretag | Inköp från koncernföretag |
| Fordringar och skulder | Skuld till koncernföretag | Fordran på koncernföretag |
| Lån | Lån från koncernföretag | Lån till koncernföretag |
| Ränta | Ränteintäkt från koncernföretag | Räntekostnad till koncernföretag |
| Utdelning | Utdelning från koncernföretag | Beslutad utdelning till koncernföretag |
| Koncernbidrag | Mottaget koncernbidrag | Lämnat koncernbidrag |

Intäkter och kostnader räknas för de dagar företaget ingår i koncernen under perioden. Fordringar, skulder och lån (konto 1000–2999) föreslås med sina **utgående balanser** på periodens sista dag: alla markerade bokförda verifikationer fram till den dagen räknas, även från tidigare år. Ett lån som gavs för flera år sedan föreslås alltså även om inget har hänt under året.

Ingående balanser har inga koncernmarkeringar. Har ett konto i förslaget en ingående balans som inte kommer från verifikationer bokförda i NONOX, till exempel det första årets ingående balans från ett tidigare program, står det **Kräver granskning: ingående balans utan koncernmarkering**, och förslaget går inte att godkänna. Granska balansen och för in elimineringen som en manuell post.

Har ett företag flyttat sin bokföring till NONOX från ett annat program börjar dess första år med ingående balanser. Koncerninterna fordringar, skulder och lån får då **Kräver granskning**, och du för in elimineringen som en manuell post. Det gäller tills det går att markera den koncerninterna delen av det första årets ingående balans, vilket inte finns ännu.

Är båda sidorna noll står det **Inget att eliminera**.

**Andelar och eget kapital** bygger på balanserna: ägarens konton 1310–1318 (andelar i koncernföretag) mot aktiekapitalet (konto 2081) i det företag den äger. Förslaget går att godkänna bara när ägaren äger ett enda företag i koncernen, äger hela kapitalet, har ägt det sedan företagets första räkenskapsår (har bildat det), företagets bokföring började i NONOX (det första året har inga ingående balanser) och beloppen är lika stora. Annars står det **Kräver förvärvsanalys (steg 2)**: elimineringen behöver en förvärvsanalys, som den interna rapporten inte gör. Du kan föra in den som en manuell post.

## Så här godkänner du ett förslag

1. Gå till **Mina företag → Elimineringar**.
2. Välj **Koncern**, **Från och med** och **Till och med**, och välj **Visa period**. Förvalt är moderföretagets räkenskapsår.
3. Under **Förslag** visar varje rad **Slag**, **Företag**, **Ena sidan**, **Andra sidan**, **Differens** och **Status**. Välj **Konton** för att se kontona på varje sida.
4. Välj **Godkänn** på en rad med status **Matchar**, och sedan **Ja, godkänn**.

Ett meddelande säger vilket nummer posten fick i koncernjournalen. Raden visar därefter **Godkänd, post** och numret. Posten dras från nästa konsolidering av samma period; kör en ny konsolidering för att se siffrorna efter elimineringen.

Har bokföringen eller markeringarna ändrats sedan sidan visades vägrar NONOX att godkänna förslaget och ber dig ladda om sidan och granska det igen. Du godkänner alltså alltid det du har sett.

## När sidorna inte stämmer

Status **Differens** betyder att sidorna är olika stora. Det beror ofta på att en rad bara är markerad i det ena företaget, eller att fakturan är bokförd i olika perioder. En differens kan inte godkännas. Rätta markeringarna eller bokföringen, eller gör en manuell post för det du vill eliminera.

Har beloppen ändrats efter att ett förslag godkändes säger raden **Beloppen har ändrats sedan förslaget godkändes**. Återför posten och godkänn förslaget igen.

## Manuella poster

Det som förslagen inte täcker, till exempel en koncernintern fordran i det första räkenskapsårets ingående balans eller andelar i ett köpt företag, för du in som en manuell post:

1. Under **Ny manuell post**: välj **Slag** och skriv en **Motivering**. Skriv inte personuppgifter i motiveringen.
2. På varje rad: välj **Företag**, skriv **Koncernkonto** och ett belopp i **Debet** eller **Kredit**. **Lägg till rad** ger fler rader.
3. Välj **Spara posten**.

Posten måste balansera, varje rad ska ha ett belopp i antingen debet eller kredit, och företagen måste ingå i konsolideringen för perioden.

## Koncernjournalen

**Koncernjournal för perioden** listar periodens poster med nummer, slag, vem som gjorde dem och när, motiveringen och raderna. Posterna numreras i en följd per koncern och kan inte ändras eller tas bort.

Är en post fel väljer du **Återför**, skriver **Varför återförs posten?** och väljer **Återför posten**. Återföringen blir en ny post med samma rader på motsatt sida, och båda finns kvar. En post kan bara återföras en gång, och en återföring kan inte återföras.

**Kontrollera koncernjournalen** räknar om kontrollsummorna och kontrollerar att numren följer på varandra och att varje post balanserar.

Har ett företag lämnat koncernen finns dess poster kvar. Får du inte längre se det företaget visas bara **Post** och numret, med texten att posten gäller ett företag som du inte har åtkomst till, och du kan inte återföra den.

## Bra att veta

- En godkänd post gäller konsolideringar av exakt samma period. Kör du en konsolidering för ett halvår gäller inte helårets poster.
- En post som gäller ett företag som inte ingår i konsolideringen dras inte, och konsolideringen säger det.
- Internvinster i lager och anläggningstillgångar, goodwill och minoritetsandelar beräknas inte. Kontrollera med din redovisningskonsult eller revisor om koncernen behöver en koncernredovisning.
