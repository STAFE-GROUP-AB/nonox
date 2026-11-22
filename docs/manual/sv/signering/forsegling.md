---
beskrivning: Försegla signerade dokument med en elektronisk stämpel (PAdES) och en tidsstämpel, och ladda upp företagets eget förseglingscertifikat.
ordning: 35
roller: [owner, admin]
sokord: [försegling, försegla, stämpel, elektronisk stämpel, sigill, PAdES, tidsstämpel, långtidsvalidering, valideringsdata, B-LTA, certifikat, förseglingscertifikat, p12, pfx, PKCS#12, äkthet, oförändrad, integritet]
---

# Försegling av signerade dokument

När alla har signerat kan NONOX försegla dokumentet innan kopiorna skickas. Försegling betyder att filen får en elektronisk stämpel (PAdES) som visar att filen inte har ändrats sedan den förseglades och vilket certifikat som förseglade filen. Vem som står bakom certifikatet visar stämpeln bara om certifikatet är utfärdat av en utfärdare som PDF-läsaren litar på. Det gäller alla signeringar: offerter, avtal, ÄTA och egna dokument.

Stämpeln är **inte undertecknarnas underskrift**. Varje underskrift är fortfarande en enkel elektronisk underskrift. Stämpeln skyddar filen efter att signeringen är klar.

![Försegling av signerade dokument under Inställningar för företaget → Signering.](/bilder/manual/signering/forsegling.webp)

> [!IMPORTANT]
> Försegling kräver PDF-motorn och är avstängd tills den slås på för installationen. I den driftade tjänsten på nonox.app är den avstängd tills en jurist har svarat på frågan om betrodda tjänster enligt EU:s förordning om elektronisk identifiering (eIDAS). Kör du NONOX på egen server slår du på den själv, se [egen drift](/egen-drift).

## Vad som händer när alla har signerat

1. NONOX lägger till en sida sist i dokumentet med en sammanställning av signeringen: dokumentets kontrollsumma, undertecknarnas namn, när och hur de skrev under, och vilket certifikat som förseglade filen. Sidan har inga e-postadresser, IP-adresser eller webbläsare.
2. Hela filen förseglas. Dokumentet som signerades finns kvar oförändrat som den första versionen i filen, så dess kontrollsumma stämmer fortfarande.
3. Kopiorna skickas med den förseglade filen till undertecknarna, kopiemottagarna och företaget. Kopiorna väntar tills förseglingen är klar.

På sidan för signeringen, under **Signering → Alla signeringar**, visar rutan **Försegling** om dokumentet förseglas, är förseglat eller inte kunde förseglas, och med vilken nivå. Filen som du laddar ner därifrån är den förseglade.

## Tidsstämpel

Har installationen en tidsstämpelserver får förseglingen en tidsstämpel som visar när den gjordes (nivån **PAdES B-T**). Utan tidsstämpelserver blir nivån **PAdES B-B**, och tiden i förseglingen är bara serverns egen klocka.

Svarar ingen tidsstämpelserver försöker NONOX igen i upp till en timme (den som driver installationen kan ändra tiden). Under tiden står det **Förseglas** på signeringen och kopiorna väntar. Därefter förseglas dokumentet utan tidsstämpel, kopiorna skickas och den som skickade dokumentet får ett meddelande.

Går förseglingen inte alls, till exempel för att certifikatet inte går att läsa, skickas kopiorna utan försegling och den som skickade dokumentet får ett meddelande. Underskrifterna påverkas inte av att förseglingen saknas.

## Långtidsvalidering

Certifikat och tidsstämplar gäller bara en viss tid. För att förseglingen ska kunna kontrolleras även efter många år gör NONOX två saker med ett dokument som har förseglats med tidsstämpel:

1. Direkt efter förseglingen läggs **valideringsdata** till i filen: certifikaten och uppgifter om att de inte var spärrade (nivån **PAdES B-LT**). Uppgifterna hämtas från de adresser som certifikaten anger. Går de inte att hämta försöker NONOX igen varje natt den första veckan och sedan en gång i veckan.
2. Varje natt kontrollerar NONOX när den senaste tidsstämpeln slutar gälla. Är det mindre än tolv månader kvar läggs en ny tidsstämpel över hela filen (nivån **PAdES B-LTA**), och så vidare så länge dokumentet ska bevaras. Den som driver installationen kan ändra hur lång tid i förväg det görs.

Varje gång blir det en ny version av filen som börjar med den förra versionen oförändrad, så att varje kopia som redan har skickats fortfarande går att kontrollera. Filen som du laddar ner är alltid den senaste versionen.

Långtidsvalideringen görs bara för dokument som fortfarande ska bevaras eller har bevarandespärr, se [bevarande och radering](/manual/signering/bevarande-och-radering). Den förlänger aldrig bevarandetiden. Ett dokument som har förseglats utan tidsstämpel (**PAdES B-B**) får ingen långtidsvalidering.

I rutan **Försegling** på sidan för signeringen står det:

- **Valideringsdata har lagts till i filen (PAdES B-LT)** när valideringsdata finns i filen, och vilket datum den första nya tidsstämpeln behövs senast.
- **Långtidsvalidering (PAdES B-LTA)** när filen har en tidsstämpel över valideringsdata, med datumet för den senaste tidsstämpeln och när nästa behövs senast.
- **SHA-256 för den senaste versionen, som du laddar ner** med den senaste versionens kontrollsumma. Kontrollsumman för den förseglade filen står kvar.
- Att det senaste försöket inte lyckades och varför, om det inte gick. NONOX försöker igen.

> [!NOTE]
> Långtidsvalidering kräver samma sak som försegling: PDF-motorn, och att försegling är påslagen för installationen. Dessutom måste installationen ha en tidsstämpelserver.

## Vilket certifikat som används

Under **Inställningar för företaget → Signering** visar rutan **Försegling av signerade dokument** vilket certifikat som används:

- **Företagets eget certifikat**, om företaget har laddat upp ett som fortfarande gäller.
- Annars **Installationens certifikat**, som den som driver installationen har skapat.

Installationens certifikat är oftast självsignerat. Då visar PDF-läsare förseglingen som en underskrift med okänd giltighet. Det betyder inte att filen har ändrats, bara att läsaren inte känner igen den som utfärdade certifikatet. Sidan i dokumentet säger då också att stämpeln gjordes med certifikatet för den installation av NONOX som avsändaren använder, och att den som driver installationen inte intygar dokumentets innehåll.

NONOX kontrollerar inte namnet i ett certifikat. Stämmer namnet inte med företagets namn eller organisationsnummer visar inställningarna en varning när certifikatet laddas upp, men certifikatet används ändå. I dokumentet står namnet som det är i certifikatet, med texten att det inte är kontrollerat.

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
