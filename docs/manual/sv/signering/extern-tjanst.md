---
beskrivning: Låt en undertecknare skriva under hos en extern tjänst för underskrift med sitt eget certifikat, och lägg in företagets tjänst under Inställningar för företaget.
ordning: 37
roller: [owner, admin]
sokord: [extern tjänst, tjänst för underskrift, certifikat, avancerad underskrift, kvalificerad underskrift, eIDAS, CSC, Cloud Signature Consortium, fjärrsignering, PAdES, betrodd tjänst, QES, AES]
---

# Underskrift hos extern tjänst

En undertecknare kan skriva under ett dokument hos en extern tjänst för underskrift som företaget har avtal med, i stället för med en namnteckning i NONOX. Undertecknaren loggar in hos tjänsten och skriver under med sitt eget certifikat, och underskriften läggs in i den signerade PDF:en (PAdES). Det kan passa när ni behöver en avancerad eller kvalificerad elektronisk underskrift och har avtal med en tjänst som ger det.

NONOX följer Cloud Signature Consortiums API, version 2 (CSC). Tjänsten måste stödja inloggning med OAuth 2.0 och underskrift av en kontrollsumma.

![Underskrift hos extern tjänst under Inställningar för företaget → Signering.](/bilder/manual/signering/extern-tjanst.webp)

> [!IMPORTANT]
> Underskrift hos extern tjänst kräver PDF-motorn och är avstängd tills den slås på för installationen. I den driftade tjänsten på nonox.app är den avstängd tills driftbolaget har avtal med en tjänst och en jurist har svarat på frågorna om avancerade och kvalificerade underskrifter enligt EU:s förordning om elektronisk identifiering (eIDAS). Kör du NONOX på egen server slår du på den själv, se [egen drift](/egen-drift).

## Så här lägger du in företagets tjänst

1. Öppna **Inställningar för företaget → Signering**.
2. Under **Underskrift hos extern tjänst**, kopiera returadressen och registrera den hos tjänsten. Tjänsten skickar tillbaka undertecknaren dit.
3. Under **Lägg till en tjänst**, fyll i **Namn för undertecknarna**, **Tjänstens adress (slutar med /csc/v2)**, **Klient-id** och **Klienthemlighet**. Uppgifterna får företaget från tjänsten.
4. Välj **Nivå som tjänsten anger i ert avtal**: enkel, avancerad eller kvalificerad elektronisk underskrift. Uppgiften sparas för företaget och visas inte för undertecknare eller mottagare.
5. Klicka på **Lägg till tjänsten**. NONOX frågar tjänsten innan den sparas.

Klienthemligheten sparas krypterad och visas aldrig igen. Vill du ändra något lägger du till tjänsten på nytt och klickar på **Sluta använda tjänsten** på den gamla.

## Så här skickar du ett dokument

När du väljer **Kontroll av undertecknaren** för en undertecknare finns tjänsten som **Underskrift hos** följt av namnet. Välj den för de undertecknare som ska skriva under där.

En undertecknare som skriver under hos tjänsten kan inte ha egna fält i dokumentet, eftersom underskriften ska vara det sista i undertecknarens version av filen. Andra undertecknare i samma dokument kan ha fält.

## Det här ser undertecknaren

1. Undertecknaren öppnar länken, godkänner att handlingen skrivs under elektroniskt och läser dokumentet som vanligt.
2. Undertecknaren ser en **Dokumentkod** (till exempel 1A2B 3C4D), fyller i namn och bekräftelse och klickar på **Fortsätt till** följt av tjänstens namn. Samma kod skickas till tjänsten, så att undertecknaren kan kontrollera att det är rätt dokument som skrivs under där.
3. Hos tjänsten loggar undertecknaren in och väljer sitt certifikat. Sedan kommer undertecknaren tillbaka till NONOX en kort stund.
4. Undertecknaren skickas till tjänsten igen och godkänner underskriften av just det här dokumentet.
5. NONOX kontrollerar underskriften, lägger in den i PDF:en och visar **Tack! Dokumentet är signerat.**

Avbryter undertecknaren hos tjänsten, eller stämmer något inte, sparas ingenting och undertecknaren kan börja om från dokumentet.

## Bra att veta

- **NONOX skickar aldrig dokumentet till tjänsten**, bara en kontrollsumma som räknats fram ur det. Tjänsten skriver under kontrollsumman, och NONOX lägger in underskriften i filen.
- **NONOX anger ingen nivå för underskriften och kontrollerar den inte.** Den nivå du väljer i inställningarna sparas som företagets uppgift och visas inte för undertecknare eller mottagare. Vilken nivå en underskrift faktiskt har beror på tjänsten, certifikatet och ert avtal med tjänsten.
- **Signeringsbeviset** visar namnet ni har gett tjänsten, tjänstens eget namn och certifikatets namn, utfärdare och serienummer. Underskriften hos tjänsten är en separat underskrift i PDF:en och kan kontrolleras i en PDF-läsare.
- **NONOX sparar** certifikatets namn, utfärdare, serienummer, kontrollsumma och giltighetstid som bevis, tillsammans med signeringen. Hela certifikatet finns i den signerade PDF:en.
- **Certifikatet kan innehålla undertecknarens personnummer** och följer med den signerade PDF:en till alla mottagare. Företaget ansvarar för att det är motiverat (3 kap. 10 § dataskyddslagen).
- **Tjänsten är ett eget företag.** Företaget ingår avtalet med tjänsten, ansvarar för valet av tjänst och ska reda ut tjänstens roll enligt dataskyddsförordningen (personuppgiftsbiträde eller självständigt ansvarig) i avtalet. Kostnader för tjänsten betalar företaget direkt till tjänsten.
- **Slutar ni använda en tjänst** kan undertecknare som redan har fått en länk med den inte skriva under. Sidan ber dem kontakta dig, och du kan skicka dokumentet på nytt med en annan kontroll.
- **Över API:t** går det inte att välja underskrift hos extern tjänst; det görs bara i appen.

> [!NOTE]
> Vissa avtal har formkrav som ingen elektronisk underskrift uppfyller, till exempel köp av fast egendom. Fråga din jurist om du är osäker på vilken underskrift ett avtal behöver.

## Läs också

- [Skicka ett dokument för signering](/manual/signering/skicka-dokument)
- [Försegling av signerade dokument](/manual/signering/forsegling)
- [Alla signeringar](/manual/signering/alla-signeringar)
- [Bevarande och radering av signeringar](/manual/signering/bevarande-och-radering)
