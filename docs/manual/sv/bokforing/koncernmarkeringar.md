---
beskrivning: Markera kunder och leverantörer som är koncernföretag och vilka bokförda rader som är mellanhavanden inom koncernen. Markeringarna ändrar aldrig en verifikation.
ordning: 115
roller: [owner, admin, accountant, bookkeeper]
rutter: [accounting.intercompany]
sokord: [koncern, koncernföretag, koncerninterna, koncernbidrag, koncernlån, moderbolag, dotterbolag, systerbolag, motpart, eliminering, holdingbolag]
---

# Koncernmarkeringar

Ingår företaget i en koncern, till exempel med ett holdingbolag och några dotterbolag, har bolagen fordringar, skulder, försäljning, lån och räntor mellan sig. På **Bokföring → Koncernmarkeringar** talar du om vilka kunder och leverantörer som är andra företag i koncernen, och vilka bokförda rader som är sådana mellanhavanden. Markeringarna behövs för att koncernens siffror ska kunna sammanställas utan att samma belopp räknas två gånger.

Menyvalet ligger under **Inställningar** i bokföringsmenyn.

![Koncernmarkeringar med ett systerbolag bland kunderna och holdingbolaget bland leverantörerna.](/bilder/manual/bokforing/koncernmarkeringar.webp)

> [!IMPORTANT]
> Markeringarna ligger **bredvid** bokföringen. De ändrar aldrig en verifikation, en rad, ett belopp eller numreringen, och [kontrollen av bokföringen](/manual/bokforing/kontroll-av-bokforingen) påverkas inte. Ingenting skrivs i ett annat företags bokföring.

## Så här markerar du en kund eller leverantör som koncernföretag

1. Gå till **Bokföring → Koncernmarkeringar**.
2. Under **Koncernföretag bland kunder och leverantörer**: välj **Kund** eller **Leverantör** i **Kund eller leverantör**.
3. Sök på namn, nummer eller organisationsnummer och välj i listan.
4. Kontrollera **Organisationsnummer**. Det är förifyllt från registret och är det andra koncernföretagets nummer.
5. Välj **Markera som koncernföretag**.

Det andra företaget anges alltid med sitt organisationsnummer, även när det också använder NONOX. Ett koncernföretag är en juridisk person, så ett personnummer går inte att ange. Företagets eget organisationsnummer går inte heller.

Äger företaget andelar i ett bolag som finns i kund- eller leverantörsregistret visar sidan det under **Förslag från företagets innehav**. Välj förslaget så fylls formuläret i, och markera sedan som vanligt.

## Så här markerar du raderna på fakturor och betalningar

1. Under **Uppdatera koncernmarkeringar**: välj **Visa vad som ändras**.
2. Läs hur många rader som får en ny markering, ändras eller får sin markering borttagen.
3. Välj **Uppdatera koncernmarkeringar**.

NONOX markerar raderna på bokförda kundfakturor, inbetalningar, leverantörsfakturor och utbetalningar till och från de markerade kunderna och leverantörerna:

| Kund eller leverantör | Konton | Typ |
|---|---|---|
| Kund | 1500–1599 och 1660–1669 | **Fordran på koncernföretag** |
| Kund | 3000–3999, utom 3740–3749 och 3960–3969 | **Försäljning till koncernföretag** |
| Kund | 8300–8399 | **Ränteintäkt från koncernföretag** |
| Leverantör | 2400–2499 och 2860–2869 | **Skuld till koncernföretag** |
| Leverantör | 4000–7999, utom 7960–7969 | **Inköp från koncernföretag** |
| Leverantör | 8400–8499 | **Räntekostnad till koncernföretag** |

Moms, bank, öresavrundning, kursdifferenser och andra konton markeras inte.

Rättar du en sådan verifikation följer rättelsen med: de vända raderna får samma typ och koncernföretag som raderna de vänder, så att de tar ut varandra, och nya rader i rättelsen får typ efter tabellen ovan. Har du markerat en manuell verifikation per konto följer en rättelse av den inte med av sig själv: markera kontot på nytt för perioden.

Försäljning av inventarier och andra anläggningstillgångar till ett koncernföretag, och förskott, markeras inte av NONOX. Markera dem på kontot, se nästa avsnitt.

Tar du bort en markering av en kund eller leverantör, eller ändrar dess organisationsnummer, följer raderna med nästa gång du uppdaterar. Rader som du har markerat eller ändrat själv lämnas som de är.

## Så här markerar du rader på ett konto

Lån, räntor, utdelningar och koncernbidrag bokför du oftast själv med en verifikation. Dem markerar du per konto och period.

1. Under **Markera rader på ett konto**: skriv **Konto**, till exempel kontot för lånet från moderbolaget.
2. Välj **Från och med** och **Till och med**.
3. Skriv **Koncernföretagets organisationsnummer**. Du kan välja bland de koncernföretag sidan redan känner till.
4. Välj **Vad raderna är**. NONOX föreslår en typ när kontot är ett av BAS-kontona för koncernföretag, eller när du har markerat kontot i [Koncernöversikt](/manual/rapporter/koncernoversikt). Kontrollera förslaget.
5. Välj **Visa raderna**. Du ser hur många bokförda rader det gäller, hur många som redan har en markering och summorna i debet och kredit.
6. Välj **Markera raderna**.

Rader som redan har en markering behåller den. Med **Ta bort markeringarna i urvalet** tar du bort markeringarna på alla rader i urvalet, även sådana du har satt själv; sidan frågar först. Utkast markeras aldrig, bara bokförda verifikationer.

Väljer du ett bank-, moms- eller kursdifferenskonto varnar sidan, eftersom sådana rader sällan är ett mellanhavande inom koncernen i sig.

Typerna är:

| Typ | Exempel |
|---|---|
| **Försäljning till koncernföretag** och **Inköp från koncernföretag** | Tjänster och varor mellan bolagen |
| **Fordran på koncernföretag** och **Skuld till koncernföretag** | Obetalda fakturor, kortfristiga mellanhavanden |
| **Lån till koncernföretag** och **Lån från koncernföretag** | Ett lån från moderbolaget |
| **Ränteintäkt från koncernföretag** och **Räntekostnad till koncernföretag** | Räntan på lånet |
| **Utdelning från koncernföretag** och **Beslutad utdelning till koncernföretag** | Utdelning från ett dotterbolag |
| **Mottaget koncernbidrag** och **Lämnat koncernbidrag** | Koncernbidrag mellan bolagen |
| **Andelar i koncernföretag** och **Eget kapital i dotterföretag** | Aktierna i dotterbolaget och dess eget kapital |

## Markerade rader

Under **Markerade rader per koncernföretag** ser du antal rader och summorna i debet och kredit per koncernföretag och typ. Under **Markerade rader** finns varje rad med verifikation, datum, konto och belopp. Du filtrerar på **Koncernföretag** och **Typ**, byter typ på en rad i listan eller tar bort markeringen med **Ta bort**. I kolumnen **Markerad** står om markeringen kom **Från kund eller leverantör**, **Från kontots markering** eller **Manuellt**.

## Koncernfakturor och motsvarande utkast

Längre ned på sidan finns **Ta emot koncernfakturor**, **Levererade koncernfakturor** och **Motsvarande utkast**: vilka koncernföretag som får leverera fakturor till företagets inkorg, fakturor företaget har levererat till andra koncernföretag, och verifikationer som har ett motsvarande utkast hos ett koncernföretag. Läs [Koncernfakturor och motsvarande utkast](/manual/bokforing/koncernfakturor).

## Bra att veta

- Alla som ser verifikationer ser sidan. Ägare, administratörer, redovisningskonsulter och bokförare kan ändra markeringarna. Revisorer och attestanter kan bara läsa.
- Varje ändring skrivs i [behandlingshistoriken](/manual/bokforing/behandlingshistorik).
- En markering går att ändra även i en låst period eller ett stängt räkenskapsår, eftersom den inte är en del av bokföringen. Sidan visar först hur många rader som ligger där, och behandlingshistoriken visar det för varje ändring.
- Varje borttagning frågar först och säger vad som händer.
- NONOX bedömer inte om ett företag är ett koncernföretag enligt årsredovisningslagen, och ger inga råd om koncernbidrag. Det avgör du, eller din redovisningskonsult.

## Läs också

- [Koncernöversikt](/manual/rapporter/koncernoversikt)
- [Koncernfakturor och motsvarande utkast](/manual/bokforing/koncernfakturor)
- [Kontoplan](/manual/bokforing/kontoplan)
- [Behandlingshistorik](/manual/bokforing/behandlingshistorik)
