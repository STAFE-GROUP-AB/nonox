---
beskrivning: Ändra företagets namn, adress, bankgiro, momsperiod, bokföringsmetod, räkenskapsår och regelverk under Inställningar för företaget.
ordning: 10
roller: [owner, admin]
rutter: [teams.show]
sokord: [företagsinställningar, företagsuppgifter, adress, bankgiro, plusgiro, momsperiod, bokföringsmetod, räkenskapsår, F-skatt, arbetsgivare, K2, K3, ändra företag]
---

# Företagets uppgifter

Företagets uppgifter används på fakturor, i momsdeklarationen, i SIE-filer och i bokslutet. Du fyllde i de viktigaste när du skapade företaget; här ändrar du dem och lägger till det som saknas, till exempel bankgiro.

Alla i företaget kan se uppgifterna, men bara ägare och administratörer kan ändra dem.

![Företagets uppgifter under Inställningar.](/bilder/manual/installningar/foretagets-uppgifter.webp)

## Så här ändrar du uppgifterna

1. Klicka på företagets namn högst upp i menyn och välj **Inställningar för företaget**.
2. Fliken **Uppgifter och personer** är öppen. Ändra det du vill under **Företagsuppgifter**, **Betalning** och **Moms och bokföring**.
3. Klicka på **Spara**.

Ändringen gäller direkt. Vad som ändrades, från vad och till vad, sparas i behandlingshistoriken.

## Företagsuppgifter

**Företagsnamn**, **Adress**, **Postnummer**, **Ort**, **E-post**, **Telefon** och **Webbplats** står på dina fakturor och offerter. Med **Hämta uppgifter** hämtar du namn och adress från Bolagsverket, om knappen finns; se [Skapa ett företag](/manual/kom-igang/skapa-ett-foretag#hamta-uppgifter-fran-bolagsverket).

**Organisationsnummer** och **Företagsform** kan inte ändras, eftersom det då är ett annat företag. Har företaget bytt form, till exempel från enskild firma till aktiebolag, är det ett nytt företag som du [lägger till](/manual/kom-igang/skapa-ett-foretag).

## Betalning

**Bankgiro** och **Plusgiro** är dit kunderna betalar. De står på fakturorna tillsammans med OCR-numret. NONOX kontrollerar numret när du sparar: den sista siffran är en kontrollsiffra som avslöjar skrivfel.

## Moms och bokföring

| Uppgift | Vad den styr |
|---|---|
| **Momsredovisning** | Hur ofta ni redovisar moms: **Varje månad**, **Varje kvartal**, **En gång per år**, eller **Inte momsregistrerad**. Styr perioderna i [momsdeklarationen](/manual/moms/momsdeklaration). Väljer du en period räknar NONOX ut momsregistreringsnumret från organisationsnumret. |
| **Varför företaget inte är momsregistrerat** | Visas när du väljer **Inte momsregistrerad**. **Liten omsättning** (högst 120 000 kronor om året) skriver ut *Undantagen från skatteplikt enligt 18 kap. 4 § mervärdesskattelagen* på fakturorna. **Bara momsfri försäljning** skriver ut att omsättningen är undantagen från moms. **Momsregistreringen är på gång** skriver inte ut någon text, eftersom momsen ska betalas ändå. |
| **Räkenskapsåret börjar** | Den första dagen i företagets räkenskapsår. En enskild firma ska ha januari. |
| **Bokföringsmetod** | [Fakturametoden](/manual/ordlista/ordlista#fakturametoden) eller [kontantmetoden](/manual/ordlista/ordlista#kontantmetoden): om kund- och leverantörsfakturor bokförs när de skickas och tas emot, eller när de betalas. |
| **Regelverk för årsbokslut** | K1, K2 eller K3. Styr bland annat [årsredovisningen](/manual/bokslut/arsredovisning). Valet gäller ett räkenskapsår och alla år efter det; under fältet står vilket år det är. Vilka företag som får välja vad står i [Skapa ett företag](/manual/kom-igang/skapa-ett-foretag#vad-uppgifterna-betyder). Om företaget får använda K2 för ett visst år, och vad ett byte ändrar, ser du under [Regelverk: K2 eller K3](/manual/bokslut/regelverk). |
| **Företaget har F-skatt** | Står på fakturorna. |
| **Debiterad preliminärskatt per månad (frivilligt)** | Beloppet från Skatteverkets beslut om debiterad preliminärskatt. Fyll i det om du vill se kommande betalningar under **Pengar som inte är dina** i [Mina datum](/manual/kom-igang/mina-datum#pengar-som-inte-ar-dina). NONOX räknar aldrig ut det själv. Lämna tomt om du inte vet. |
| **Företaget är registrerat som arbetsgivare** | Att företaget har anställda och lämnar arbetsgivardeklaration. |
| **Beskattningsunderlaget för moms är över 40 miljoner kronor om året** | Ger andra deklarationsdagar för moms och arbetsgivardeklaration. Det som räknas är försäljningen som moms ska tas ut på, utan unionsinterna förvärv och import, beräknad för året. Inte den totala omsättningen: ett företag med mycket momsfri försäljning kan ha hög omsättning men ett lågt beskattningsunderlag. |
| **Har ansökt om beskattning i köparens land (OSS) eller är registrerat för OSS** | Bocka i om företaget har valt att ta ut köparlandets moms vid försäljning till privatpersoner i andra EU-länder, och fyll i **Från och med**. Annars tas svensk moms ut tills försäljningen går över 99 680 kronor om året. Läs [OSS](/manual/moms/oss). |
| **Skatteverket har beslutat att periodisk sammanställning för varor får lämnas per kvartal** | Bocka i bara om Skatteverket har beslutat det, och fyll i **Gäller från och med**. Datumet flyttas till kvartalets första dag. Utan beslut redovisas varor till företag i andra EU-länder per månad. Läs [Periodisk sammanställning](/manual/moms/periodisk-sammanstallning). |

Uppgifterna ska stämma med Skatteverkets beslut. Är du osäker, fråga din redovisningskonsult eller titta i besluten.

### Regelverk och räkenskapsår kontrolleras när du ändrar dem

- Byter du till K1 eller K2 visas villkoren för regelverket. Kryssa i **Företaget uppfyller villkoren ovan** innan du sparar. K1 går bara att välja för enskilda firmor och ideella föreningar.
- För ett aktiebolag och en ekonomisk förening räknar villkoren för K2 också upp de företag som inte får använda K2 enligt Bokföringsnämndens regler (BFNAR 2016:10 punkt 1.1A–1.1C i lydelsen enligt BFNAR 2025:2). De gäller för räkenskapsår som börjar efter den 31 december 2025, och för ett företag som har startat efter den 30 juni 2025 med förlängt räkenskapsår redan för räkenskapsår som börjar efter den 30 juni 2025, om räkenskapsåret ska avslutas den 31 december 2026 eller senare. För tidigare räkenskapsår gäller en äldre lydelse, som NONOX inte visar. Det gäller till exempel publika aktiebolag, moderföretag som upprättar koncernredovisning, företag med kryptotillgångar och – om företaget inte är mycket litet – företag med en väsentlig uppskjuten skatteskuld eller med byggnader som ger minst 75 procent av nettoomsättningen. Ett företag som redan har bekräftat K2 får ingen ny fråga; kontrollera villkoren själv före bokslutet.
- Byter du till K3 tar NONOX inte fram årsredovisningen. Se [Årsredovisning enligt K2](/manual/bokslut/arsredovisning#om-foretaget-anvander-k3).
- Regelverket hör till räkenskapsåret. Byter du regelverk gäller det från det tidigaste öppna räkenskapsåret utan fastställd årsredovisning och alla år efter det. Tidigare år behåller sitt regelverk, så årsredovisningen för ett tidigare K2-år går fortfarande att ta fram.
- Finns det flera sådana år visas **Byt regelverk från och med räkenskapsår**, där du kan välja år. Valet gäller från det året och alla senare öppna år. Texten under regelverket visar vilket år det gäller från.
- Har ett tidigare öppet år ett annat regelverk än de senare, till exempel K2 för 2026 och K3 för 2027, väljer du K3 och året 2026 i **Byt regelverk från och med räkenskapsår** och sparar. Då får 2026 också K3, även om företagets regelverk redan visar K3. Sparar du bara andra uppgifter ändras inget år.
- När du byter regelverk visas fältet **Varför byter företaget regelverk? (frivilligt)**. Det du skriver sparas med räkenskapsåren som byter.
- Ett stängt räkenskapsår och ett år vars årsredovisning har fastställts kan inte byta regelverk, och inte heller ett år som följs av ett sådant år. Finns inget år som kan byta får du ett felmeddelande och ingenting sparas; skapa då först nästa räkenskapsår under [Räkenskapsår](/manual/bokforing/rakenskapsar).
- Bara ägare och administratörer kan byta regelverk.
- En enskild firma kan inte byta till en annan startmånad än januari. Ett handels- eller kommanditbolag som byter till en annan månad får bekräfta att ingen fysisk person beskattas för bolagets inkomst.
- Ser ett val som redan är sparat fel ut, till exempel K1 för ett aktiebolag eller en enskild firma med brutet räkenskapsår, visas en gul varning överst. NONOX ändrar ingenting själv och stoppar inte att du sparar annat. Kontrollera med din redovisningskonsult och ändra om det behövs.

> [!IMPORTANT]
> Att ändra **Räkenskapsåret börjar** flyttar inga räkenskapsår som redan finns. Har företaget fått ett nytt räkenskapsår av Skatteverket och Bolagsverket ändrar du också datumen för det pågående eller nästa räkenskapsår under **Bokföring → Räkenskapsår**. Se [Räkenskapsår](/manual/bokforing/rakenskapsar).

## Företagsguiden

Under **Företagsguiden** väljer du **Bransch**. Branschen avgör, tillsammans med företagsform och moms, vilka steg i startplanen och vilka tips som visas för företaget. Avmarkera **Visa Företagsguiden** om företaget inte vill ha startplanen under Att göra och tipsen längst ner på sidorna; guiden på webbplatsen finns kvar. Klicka på **Spara**. Se [Företagsguiden och Min startplan](/manual/kom-igang/foretagsguiden).

## Personer och inloggning

Längre ner på samma flik finns **Personer i företaget**, där du bjuder in och tar bort personer och ändrar roller. Se [Bjud in en användare](/manual/kom-igang/bjud-in-en-anvandare). Är du ägare finns också **Kräv passkey för alla i företaget**; se [Passkeys och inloggningar](/manual/installningar/passkeys-och-inloggningar).

## De andra flikarna

- **Varumärke** – logga och färger på fakturor och lönebesked. Se [Varumärke](/manual/installningar/varumarke).
- **Moduler** – slå på och av lön, personalliggare, inkorg, bank, AI och API. Se [Moduler](/manual/installningar/moduler).
- **Ägarskap och arkiv** – bara för ägaren. Se [Ägarskap och arkivering](/manual/installningar/agarskap-och-arkivering).

Flikarna syns bara för den som får använda dem.

## Vanliga frågor

**Vi har bytt från kvartalsmoms till månadsmoms. Vad händer med perioden som pågår?**
Ändra **Momsredovisning** när beslutet gäller. Fråga din redovisningskonsult eller Skatteverket vilken period som gäller vid bytet; NONOX räknar perioderna efter det som står i inställningen.

**Kan jag byta bokföringsmetod mitt i året?**
Inställningen går att ändra när som helst, men vilka regler som gäller för ett byte bestäms av bokföringslagen och Skatteverket. Prata med din redovisningskonsult först.

**Var ändrar jag kontoplanen och verifikationsserierna?**
Under **Bokföring**, i gruppen **Inställningar**. Se [Kontoplan](/manual/bokforing/kontoplan).

## Läs också

- [Moduler](/manual/installningar/moduler)
- [Varumärke](/manual/installningar/varumarke)
- [Räkenskapsår](/manual/bokforing/rakenskapsar)
- [Momsdeklaration](/manual/moms/momsdeklaration)
