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
| **Räkenskapsåret börjar** | Den första dagen i företagets räkenskapsår. En enskild firma ska ha januari. |
| **Bokföringsmetod** | [Fakturametoden](/manual/ordlista/ordlista#fakturametoden) eller [kontantmetoden](/manual/ordlista/ordlista#kontantmetoden): om kund- och leverantörsfakturor bokförs när de skickas och tas emot, eller när de betalas. |
| **Regelverk för årsbokslut** | K1, K2 eller K3. Styr bland annat [årsredovisningen](/manual/bokslut/arsredovisning). Vilka företag som får välja vad står i [Skapa ett företag](/manual/kom-igang/skapa-ett-foretag#vad-uppgifterna-betyder). |
| **Företaget har F-skatt** | Står på fakturorna. |
| **Företaget är registrerat som arbetsgivare** | Att företaget har anställda och lämnar arbetsgivardeklaration. |
| **Omsättningen är över 40 miljoner kronor om året** | Ger andra deklarationsdagar för momsen. |
| **Har ansökt om beskattning i köparens land (OSS) eller är registrerat för OSS** | Bocka i om företaget har valt att ta ut köparlandets moms vid försäljning till privatpersoner i andra EU-länder, och fyll i **Från och med**. Annars tas svensk moms ut tills försäljningen går över 99 680 kronor om året. Läs [OSS](/manual/moms/oss). |

Uppgifterna ska stämma med Skatteverkets beslut. Är du osäker, fråga din redovisningskonsult eller titta i besluten.

### Regelverk och räkenskapsår kontrolleras när du ändrar dem

- Byter du till K1 eller K2 visas villkoren för regelverket. Kryssa i **Företaget uppfyller villkoren** innan du sparar. K1 går bara att välja för enskilda firmor och ideella föreningar.
- En enskild firma kan inte byta till en annan startmånad än januari. Ett handels- eller kommanditbolag som byter till en annan månad får bekräfta att ingen fysisk person beskattas för bolagets inkomst.
- Ser ett val som redan är sparat fel ut, till exempel K1 för ett aktiebolag eller en enskild firma med brutet räkenskapsår, visas en gul varning överst. NONOX ändrar ingenting själv och stoppar inte att du sparar annat. Kontrollera med din redovisningskonsult och ändra om det behövs.

> [!IMPORTANT]
> Att ändra **Räkenskapsåret börjar** flyttar inga räkenskapsår som redan finns. Har företaget fått ett nytt räkenskapsår av Skatteverket och Bolagsverket ändrar du också datumen för det pågående eller nästa räkenskapsår under **Bokföring → Räkenskapsår**. Se [Räkenskapsår](/manual/bokforing/rakenskapsar).

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
