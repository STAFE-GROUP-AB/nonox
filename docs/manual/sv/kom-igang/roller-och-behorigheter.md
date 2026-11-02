---
beskrivning: De sex rollerna i ett företag – ägare, administratör, redovisningskonsult, bokförare, attestant och revisor – vad var och en får göra, och regeln om att två personer ska vara inblandade i attest.
ordning: 50
sokord: [roll, behörighet, rättigheter, åtkomst, ägare, administratör, redovisningskonsult, bokförare, attestant, revisor, löneadministratör, dualitet, fyraögonsprincipen]
---

# Roller och behörigheter

Varje person i ett företag har en roll. Rollen avgör vad personen ser och får göra, både i menyn och i varje knapp. Samma person kan ha olika roller i olika företag, till exempel ägare i sitt eget bolag och redovisningskonsult hos en kund.

![Rollerna att välja mellan när du lägger till en person i företaget.](/bilder/manual/kom-igang/roller-och-behorigheter.webp)

## De sex rollerna

| Roll | Får göra |
|---|---|
| **Ägare** | Allt, även att arkivera företaget, överlåta ägarskapet och kräva passkey för alla. Den som skapade företaget är ägare. Ett företag har alltid exakt en ägare. |
| **Administratör** | Allt utom att arkivera företaget och överlåta ägarskapet. Hanterar personer, inställningar och moduler. |
| **Redovisningskonsult** | Sköter hela bokföringen: verifikationer, kontoplan, räkenskapsår, låsning av perioder, bokslut, moms, rapporter, kund- och leverantörsfakturor, SIE-filer och behandlingshistoriken. Kan inte ändra företagets inställningar eller bjuda in personer, och kan inte attestera. |
| **Bokförare** | Bokför och rättar verifikationer, skriver och skickar kundfakturor, registrerar leverantörsfakturor och förbereder betalningar, och ser rapporterna. Kan inte låsa perioder, göra bokslut eller momsdeklarationen. |
| **Attestant** | Attesterar leverantörsfakturor och godkänner betalfiler. Kan läsa fakturor och verifikationer, men inte bokföra. |
| **Revisor** | Kan läsa allt och exportera SIE-filer och arkivpaket, men inte bokföra eller ändra något. Ser behandlingshistoriken. |

> [!NOTE]
> Varje artikel i manualen säger överst vilka roller den gäller för. Saknar du en knapp som beskrivs, kontrollera din roll under **Inställningar för företaget** eller fråga en ägare eller administratör.

## Roller i moduler

När företaget har slagit på en modul kan det finnas fler roller att välja mellan när du bjuder in någon. De visas bara när modulen är på.

- **Löneadministratör** (modulen Lön) sköter lönen och är den som ser orsaken till frånvaro, till exempel sjukdom. Inte ens ägaren ser den uppgiften utan den här rollen.
- **Anställd** (modulen Lön) har bara tillgång till Min sida: lönebesked, sjukanmälan, semesteransökan och utlägg. Se [För anställda](/manual/personal).
- **Personalansvarig** (modulen Personalliggare) sköter personalliggaren, lokalerna och kioskerna.
- **Personal i personalliggaren** (modulen Personalliggare) kan checka in och ut. Se [Checka in och ut](/manual/personal/checka-in-och-ut).

Modulerna ger också de vanliga rollerna behörigheter. En redovisningskonsult kan till exempel bokföra lönekörningar, och en bokförare kan arbeta i inkorgen. Vem som får hantera en modul står i modulens eget avsnitt i manualen.

## Två personer i attesten

Som standard kräver NONOX att **någon annan** än den som registrerade en leverantörsfaktura attesterar den, och att någon annan än den som skapade en betalfil godkänner den. Det skyddar både företaget och den som registrerar: ingen kan ensam både lägga in och godkänna en betalning.

Är ni bara en person i företaget går det inte. Då kan en ägare eller administratör stänga av regeln under **Inköp → Leverantörsfakturor**, i kortet **Attest**, med **Tillåt att samma person registrerar och attesterar (för företag med en person)**. Med **Kräv att någon annan attesterar** slås den på igen. Varje ändring sparas i behandlingshistoriken. Läs mer i [Attest](/manual/leverantorer/attest).

Lönen har en motsvarande inställning, **Den som beräknar får inte attestera**, bland lönens inställningar.

## Passkey för känsliga uppgifter

Den som kan se sjukfrånvaro, lönebesked eller fullständiga personnummer måste bekräfta inloggningen med en passkey. Det följer av rollen: i praktiken ägaren, administratören, redovisningskonsulten, revisorn och löneadministratören i ett företag med lön, och ägaren, administratören och den personalansvarige i ett företag med personalliggare. Ägaren kan dessutom kräva passkey för alla. Se [Passkeys och inloggningar](/manual/installningar/passkeys-och-inloggningar).

## Så här ändrar du någons roll

1. Klicka på företagets namn högst upp i menyn och välj **Inställningar för företaget**.
2. Under **Personer i företaget** klickar du på rollen bredvid personens namn.
3. Välj den nya rollen och klicka på **Spara**.

Bara ägare och administratörer kan ändra roller. Ändringen gäller direkt och sparas i behandlingshistoriken.

## Vanliga frågor

**Vilken roll ska min redovisningskonsult ha?**
**Redovisningskonsult** om hen ska sköta bokföringen och bokslutet. Ska hen också bjuda in personer och ändra inställningar behövs **Administratör**.

**Vilken roll ska vår revisor ha?**
**Revisor**. Då kan hen läsa och exportera allt utan att kunna ändra något.

**Kan det finnas två ägare?**
Nej, men ägaren kan [överlåta ägarskapet](/manual/installningar/agarskap-och-arkivering) till någon annan i företaget och blir då själv administratör.

## Läs också

- [Bjud in en användare](/manual/kom-igang/bjud-in-en-anvandare)
- [Attest](/manual/leverantorer/attest)
- [Passkeys och inloggningar](/manual/installningar/passkeys-och-inloggningar)
- [Ordlista: Attest](/manual/ordlista/ordlista#attest)
