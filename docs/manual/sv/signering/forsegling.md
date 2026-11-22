---
beskrivning: Försegla signerade dokument med en elektronisk stämpel (PAdES) och en tidsstämpel, och ladda upp företagets eget förseglingscertifikat.
ordning: 35
roller: [owner, admin]
sokord: [försegling, försegla, stämpel, elektronisk stämpel, sigill, PAdES, tidsstämpel, certifikat, förseglingscertifikat, p12, pfx, PKCS#12, äkthet, oförändrad, integritet]
---

# Försegling av signerade dokument

När alla har signerat kan NONOX försegla dokumentet innan kopiorna skickas. Försegling betyder att filen får en elektronisk stämpel (PAdES) som visar att filen inte har ändrats sedan den förseglades och vilket certifikat som förseglade den. Det gäller alla signeringar: offerter, avtal, ÄTA och egna dokument.

Stämpeln är **inte undertecknarnas underskrift**. Varje underskrift är fortfarande en enkel elektronisk underskrift. Stämpeln skyddar filen efter att signeringen är klar.

![Försegling av signerade dokument under Inställningar för företaget → Signering.](/bilder/manual/signering/forsegling.webp)

> [!IMPORTANT]
> Försegling kräver PDF-motorn och är avstängd tills den slås på för installationen. I den driftade tjänsten på nonox.app är den avstängd tills en jurist har svarat på frågan om betrodda tjänster enligt EU:s förordning om elektronisk identifiering (eIDAS). Kör du NONOX på egen server slår du på den själv, se [egen drift](/egen-drift).

## Vad som händer när alla har signerat

1. NONOX lägger till en sida sist i dokumentet med en sammanställning av signeringen: dokumentets kontrollsumma, undertecknarnas namn, när och hur de skrev under, och vem som förseglade filen. Sidan har inga e-postadresser, IP-adresser eller webbläsare.
2. Hela filen förseglas. Dokumentet som signerades finns kvar oförändrat som den första versionen i filen, så dess kontrollsumma stämmer fortfarande.
3. Kopiorna skickas med den förseglade filen till undertecknarna, kopiemottagarna och företaget. Kopiorna väntar tills förseglingen är klar.

På sidan för signeringen, under **Signering → Alla signeringar**, visar rutan **Försegling** om dokumentet förseglas, är förseglat eller inte kunde förseglas, och med vilken nivå. Filen som du laddar ner därifrån är den förseglade.

## Tidsstämpel

Har installationen en tidsstämpelserver får förseglingen en tidsstämpel som visar när den gjordes (nivån **PAdES B-T**). Utan tidsstämpelserver blir nivån **PAdES B-B**, och tiden i förseglingen är bara serverns egen klocka.

Svarar ingen tidsstämpelserver försöker NONOX igen i upp till ett dygn. Under tiden står det **Förseglas** på signeringen och kopiorna väntar. Efter ett dygn förseglas dokumentet utan tidsstämpel, kopiorna skickas och den som skickade dokumentet får ett meddelande.

Går förseglingen inte alls, till exempel för att certifikatet inte går att läsa, skickas kopiorna utan försegling och den som skickade dokumentet får ett meddelande. Signeringen gäller ändå.

## Vilket certifikat som används

Under **Inställningar för företaget → Signering** visar rutan **Försegling av signerade dokument** vilket certifikat som används:

- **Företagets eget certifikat**, om företaget har laddat upp ett som fortfarande gäller.
- Annars **Installationens certifikat**, som den som driver installationen har skapat.

Installationens certifikat är oftast självsignerat. Då visar PDF-läsare förseglingen som en underskrift med okänd giltighet. Det betyder inte att filen har ändrats, bara att läsaren inte känner igen den som utfärdade certifikatet.

## Så här laddar du upp företagets eget certifikat

1. Skaffa ett förseglingscertifikat för organisationen (en elektronisk stämpel) som en PKCS#12-fil, `.p12` eller `.pfx`, med den privata nyckeln och ett lösenord.
2. Öppna **Inställningar för företaget → Signering**.
3. Under **Ladda upp företagets eget certifikat** väljer du filen under **Certifikatfil (.p12 eller .pfx)** och skriver lösenordet under **Certifikatets lösenord**.
4. Tryck på **Ladda upp certifikatet**.

NONOX kontrollerar att lösenordet stämmer, att filen har både certifikatet och den privata nyckeln, att nyckeln är tillräckligt stark, att certifikatet får användas för stämplar och att det gäller just nu. Certifikatet sparas krypterat. Nyckeln och lösenordet visas aldrig igen, och filen sparas inte någon annanstans.

Laddar du upp ett nytt certifikat slutar det gamla användas, och dess nyckel tas bort. Med **Sluta använda certifikatet** tar du bort företagets certifikat; då används installationens certifikat igen.

> [!NOTE]
> Bara ägare och administratörer kan ladda upp och ta bort certifikatet.

## När certifikatet går ut

60 dagar innan företagets certifikat går ut får den som laddade upp det, annars ägaren, en uppgift under **Att göra**: **Förseglingscertifikatet går ut**. Ladda upp ett nytt innan dess. Har certifikatet gått ut förseglas dokumenten med installationens certifikat i stället.

## Läs också

- [Alla signeringar](/manual/signering/alla-signeringar)
- [Bevarande och radering av signeringar](/manual/signering/bevarande-och-radering)
- [Signera offerter och avtal](/manual/crm/signering)
